<?php
include($_SERVER['DOCUMENT_ROOT'] . '/config.php');
include('xml_security.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Yanıtı XML olarak gönder
    headerXml();

    // Bu uç yalnızca XML kabul etsin
    requireContentTypeXml();

    // Gövdeden güvenli XML yükle (XXE/DTD kapalı, boyut limiti yok)
    list($xml, $rawXml) = loadXmlFromRequestBodyNoLimit();

    // Kök beklenen <CancelReservation>
    $cancel_reservation_info = $xml;

    $username = (string)$cancel_reservation_info->AgencyUsername;
    $password = (string)$cancel_reservation_info->AgencyPassword;
    $api_key = (string)$cancel_reservation_info->AgencyAPIkey;
    $reservation_id = (int)$cancel_reservation_info->ReservationId;



    // ---- Dahili kullanıcı XML'ini güvenli yükle ----
    $xmlFilePath    = __DIR__ . '/data/info.xml';
    $allowedBaseDir = realpath(__DIR__ . '/data');
    $realPath       = realpath($xmlFilePath);

    if (!$realPath || strpos($realPath, $allowedBaseDir) !== 0 || !is_readable($realPath)) {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<CancelReservation><status>error</status><message>XML file access denied or file not found</message></CancelReservation>";
        exit;
    }
    $xml_data = loadXmlFromFileSafe($realPath);

    //Kullanıcı girişi kontrolü /**********************************/

    // Giriş bilgilerini doğrula
    if (empty($api_key) || empty($username) || empty($password)) {
        echo "<error>Check the username, password or API key</error>";
        exit;
    }

    // API anahtarı format kontrolü
    if (!preg_match('/^[a-zA-Z0-9]{32}$/', $api_key)) {
        echo "<error>Invalid API key format</error>";
        exit;
    }

    // Kullanıcı doğrulama sorgusu
    $found_record = false;
    $agency_id = 0;
    $user_password = "";
    foreach ($xml_data->xml_users->Xml_users as $user) {
        if ((string)$user->api_key == $api_key && (string)$user->username == $username) {

            //agency_id 
            $agency_id = (int)$user->id;
            $user_password = (string)$user->password;
            $found_record = true;
            break;
        }
    }
    if (!$found_record) {
        echo "<error>Invalid user or API key</error>";
        exit;
    }

    // Şifre kontrolü
    if (!password_verify($password, $user_password)) {
        echo "<error>Invalid password</error>";
        exit;
    }


    // Log kaydı fonksiyonu
    function logXmlAccess($baglanti, $xmlFile, $status, $errorMessage = null, $api_key = null, $username = null, $password = null)
    {
        $ip = $_SERVER['REMOTE_ADDR'];
        $maskedPassword = $password ? str_repeat('*', 10) : null;
        $stmt = $baglanti->prepare("INSERT INTO xml_log (xml_dosyasi, ip_adresi, durum, hata_mesaji, api_key, username, raw_password) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssss", $xmlFile, $ip, $status, $errorMessage, $api_key, $username, $maskedPassword);
        $stmt->execute();
        $stmt->close();
    }


    $whichService = "CancelReservationServiceTest";

    try {
        logXmlAccess($baglanti, $whichService, 'SUCCESS', null, $api_key, $username, $password);
    } catch (Exception $e) {
        logXmlAccess($baglanti, $whichService, 'ERROR', $e->getMessage(), $api_key, $username, $password);
    }

    /****************** */

    //Ortak işlem – XML veya FORM fark etmez
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<CancelReservation>";


    $number = [
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth'
    ];

    if ($agency_id === 0) {
        echo "<message>No agency id found</message><status>success</status>";
        echo "</CancelReservation>";
        exit;
    }
    if (empty($reservation_id)) {
        echo "<message>Please enter reservation_id</message><status>success</status>";
        echo "</CancelReservation>";
        exit;
    }


    //Kayıt önceden cancel mı ?
    $cancel_check = false;
    $sqlCheck = "SELECT cancel_reservation FROM test_data WHERE agency_id = ? AND roomcards_group_id = ?";
    $stmtCheck = $baglanti->prepare($sqlCheck);
    $stmtCheck->bind_param("ii", $agency_id, $reservation_id);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result();
    if ($resultCheck->num_rows > 0) {
        while ($row = $resultCheck->fetch_assoc()) {
            if ($row['cancel_reservation'] === 1) {
                $cancel_check = true;
                break;
            }
        }
    }

    if ($cancel_check) {
        echo "<message>This reservation has already been cancelled</message><status>success</status>";
        echo "</CancelReservation>";
        exit;
    }


    $cancel = 1;
    $sql = "UPDATE test_data SET cancel_reservation = ? WHERE agency_id = ? AND roomcards_group_id = ?";
    $stmt = $baglanti->prepare($sql);
    $stmt->bind_param("iii", $cancel, $agency_id, $reservation_id);
    if (!$stmt->execute()) {
        echo "<message>Reservation cancellation failed. Please check.</message><status>success</status>";
        echo "</CancelReservation>";
        exit;
    }
    if ($stmt->affected_rows === 0) {
        echo "<message>No reservations were found to be cancelled. Please check the agency information and reservation id values.</message><status>error</status>";
        echo "</CancelReservation>";
        exit;
    }
    $stmt->close();
    echo "<message>Reservation cancelled successfully</message><status>success</status>";
    echo "</CancelReservation>";
    exit;
}

?>
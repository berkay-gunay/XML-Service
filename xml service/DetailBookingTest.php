<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);

error_reporting(E_ALL);

ini_set('error_log', __DIR__ . '/logs/php_errors.log');

include($_SERVER['DOCUMENT_ROOT'] . '/config.php');
include('xml_security.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Yanıt tipi XML
    headerXml();

    // Bu uç yalnızca XML kabul etsin
    requireContentTypeXml();

    // Gövdeden güvenli XML yükle (XXE/DTD kapalı, boyut limiti yok)
    list($xml, $rawXml) = loadXmlFromRequestBodyNoLimit();

    // Beklenen kök zaten <DetailBooking>
    $booking_detail_info = $xml;

    // Dahili veri XML'ini güvenli yükle (XXE/DTD kapalı)
    $xmlFilePath    = __DIR__ . '/data/info.xml';
    $allowedBaseDir = realpath(__DIR__ . '/data');
    $realPath       = realpath($xmlFilePath);

    if (!$realPath || strpos($realPath, $allowedBaseDir) !== 0 || !is_readable($realPath)) {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<DetailBooking><status>error</status><message>XML file access denied or file not found</message></DetailBooking>";
        exit;
    }
    $xml_data = loadXmlFromFileSafe($realPath);

    //Kullanıcı girişi kontrolü /**********************************/

    $username = (string)$booking_detail_info->AgencyUsername;
    $password = (string)$booking_detail_info->AgencyPassword;
    $api_key = (string)$booking_detail_info->AgencyAPIkey;

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
    $found = false;
    $agency_id = 0;
    $user_password = "";
    foreach ($xml_data->xml_users->Xml_users as $user) {
        if ((string)$user->api_key == $api_key && (string)$user->username == $username) {

            //agency_id 
            $agency_id = (int)$user->id;
            $user_password = (string)$user->password;
            $found = true;
            break;
        }
    }
    if (!$found) {
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


    $whichService = "DetailBookingServiceTest";

    try {
        logXmlAccess($baglanti, $whichService, 'SUCCESS', null, $api_key, $username, $password);
    } catch (Exception $e) {
        logXmlAccess($baglanti, $whichService, 'ERROR', $e->getMessage(), $api_key, $username, $password);
    }

    /****************** */

    //Ortak işlem – XML veya FORM fark etmez
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<DetailBooking>";

    $reservation_id = (int)$booking_detail_info->ReservationId; //burada aslında guest ve reservation tablolarındaki roomcards_group_id'yi alıyoruz
    $number = [
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth'
    ];

    if (empty($reservation_id)) {
        echo "<message>Please enter reservation_id</message><status>error</status>";
        echo "</DetailBooking>";
        exit;
    }
    if (!is_numeric($agency_id) || $agency_id <= 0) {
        echo "<message>Invalid agency_id</message><status>error</status>";
        echo "</DetailBooking>";
        exit;
    }
    if (!is_numeric($reservation_id) || $reservation_id <= 0) {
        echo "<message>Invalid reservation_id</message><status>error</status>";
        echo "</DetailBooking>";
        exit;
    }


    $sql = "
    SELECT 
        test_data.id,
        test_data.reservation_room_type,
        test_data.reservation_date,
        test_data.check_in,
        test_data.check_out,
        test_data.total_price,
        test_data.currency,
        test_data.adults,
        test_data.children,
        test_data.infant,
        test_data.roomcards_group_id,
        test_data.cancel_reservation,
        test_data.first_name,
        test_data.last_name,
        test_data.passport_number,
        test_data.child_age,
        test_data.phone_number,
        test_data.email,
        test_data.adress,
        test_data.note,
		test_data.gender,
        test_data.is_group,
        test_data.reservation_id,            
        test_data.room_id AS room_type_name,    
        test_data.hotel_id AS name       
    FROM 
        test_data 
    WHERE 
        adults IS NOT NULL AND test_data.roomcards_group_id = ? AND test_data.agency_id = ?;
";

    $stmt = $baglanti->prepare($sql);
    if ($stmt === false) {
        die("query preparation error: " . $baglanti->error);
    }
    $stmt->bind_param("ii", $reservation_id, $agency_id);
    $stmt->execute();
    $result = $stmt->get_result();


    if ($result->num_rows > 0) {

        $whichRoom = 1;
        // Rezervasyon bilgilerini listele
        echo "<RoomsInformation>";
        $previousrow = null; //otel bilgilerinde tekrara düşmemek için
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $totalsByCurrency = []; // currency => toplam fiyat
        $cancel_reservation = false;
        //Hotel Info kısmı
        foreach ($rows as $row) {
            if ($row['cancel_reservation'] === 1 && $cancel_reservation === false) {
                $cancel_reservation = true;
            }

            //pensionCode u alıyoruz
            $hotel_name = $row["name"];
            foreach($xml_data->hotels_test->Hotels as $hotel){
                if($hotel_name == (string)$hotel->name){
                    $pension_id = (int)$hotel->pension;
                    break;
                }
            }
            foreach($xml_data->pensions->Pensions as $pension){
                if($pension_id == $pension->ID){
                    $PensionCode = (string)$pension->PensionCode;
                    break;
                }
            }

            if ($row["roomcards_group_id"] === $reservation_id) {
                echo "<{$number[$whichRoom]}Room>";
                echo "<ReservationDate>" . htmlspecialchars($row["reservation_date"]) . "</ReservationDate>";
                echo "<HotelName>" . htmlspecialchars($row["name"]) . "</HotelName>";
                echo "<RoomName>" . htmlspecialchars($row["room_type_name"]) . "</RoomName>";
                echo "<RoomType>" . htmlspecialchars($row["reservation_room_type"]) . "</RoomType>";
                echo "<PensionCode>" . htmlspecialchars($PensionCode) . "</PensionCode>";
                echo "<Checkin>" . htmlspecialchars($row["check_in"]) . "</Checkin>";
                echo "<Checkout>" . htmlspecialchars($row["check_out"]) . "</Checkout>";
                echo "<Price>" . floatval($row["total_price"]) . " " . htmlspecialchars($row["currency"]) . "</Price>";
                echo "<AdultNumber>" . htmlspecialchars($row["adults"]) . "</AdultNumber>";
                echo "<ChildNumber>" . htmlspecialchars(($row["children"] + $row["infant"])) . "</ChildNumber>";
                echo "</{$number[$whichRoom]}Room>"; // Satır kapama

                //TOTAL PRICE hesaplıyoruz
                $currency = $row['currency']; // örn: "EUR", "USD", "TRY"
                $price = floatval($row['total_price']);

                if (!isset($totalsByCurrency[$currency])) {
                    $totalsByCurrency[$currency] = 0;
                }

                $totalsByCurrency[$currency] += $price;
                $whichRoom++;
            }

            $previousrow = $row["reservation_id"];
        }
    } else {
        echo "<message>No record found</message><status>error</status>";
        echo "</DetailBooking>";
        exit;
    }

    //TOTAL PRICE gösteriyoruz
    $priceStrings = [];
    foreach ($totalsByCurrency as $currency => $amount) {
        $formatted = number_format($amount, 2, ',', '.'); // isteğe göre formatla
        $priceStrings[] = $formatted . " " . $currency;
    }
    echo "<TotalPrice>" . implode(' + ', $priceStrings) . "</TotalPrice>";

    echo "</RoomsInformation>";


    $sql2 = "
        SELECT agency_id,reservation_room_type,adults,children,infant,roomcards_group_id,cancel_reservation,first_name,
            last_name,passport_number,child_age,phone_number,email,adress,note, gender, reservation_id      
        FROM 
            test_data  
        WHERE 
            adults IS NULL AND roomcards_group_id = ? AND agency_id = ?;
    ";

    $stmt2 = $baglanti->prepare($sql2);
    if ($stmt2 === false) {
        die("query preparation error: " . $baglanti->error);
    }
    $stmt2->bind_param("ii", $reservation_id, $agency_id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();


    if ($result2->num_rows > 0) {

        echo "<GuestsInformation>";
        //Odaların tablolarının gösterimi
        $adult_count = 1;
        $child_count = 1;
        $phone_number = "";
        $email = "";
        $address = "";
        $note = "";
        $whichRoom = 1;
        $previousResId = null;
        $previousRoomType = null;
        while ($row = $result2->fetch_assoc()) {

            if ($row["reservation_id"] !== $previousResId) {
                // Öncekinden kalma tablo varsa kapat
                if ($previousResId !== null) {
                    echo "<PhoneNumber>" . htmlspecialchars($phone_number) . "</PhoneNumber>";
                    echo "<Email>" . htmlspecialchars($email) . "</Email>";
                    echo "<Address>" . htmlspecialchars($address) . "</Address>";
                    echo "<Notes>" . htmlspecialchars($note) . "</Notes>";
                    echo "</{$number[$whichRoom]}RoomGuests>";
                    $whichRoom++;
                    $adult_count = 1;
                    $child_count = 1;
                }
                echo "<{$number[$whichRoom]}RoomGuests>";
            }

            //echo "<OdaKarti>";
            if ($row["gender"] !== "Child") {
                echo "<{$number[$adult_count]}Adult>";
                echo "<AdultName>" . htmlspecialchars($row["gender"]) . " " . htmlspecialchars($row["first_name"]) . " " . htmlspecialchars($row["last_name"]) . "</AdultName>";
                echo "<AdultPassportNumber>" . htmlspecialchars($row["passport_number"]) . "</AdultPassportNumber>";
                echo "</{$number[$adult_count]}Adult>";
                $phone_number = $row["phone_number"];
                $email = $row["email"];
                $address = $row["adress"];
                $note = $row["note"];
                $adult_count++;
            } else {
                echo "<{$number[$child_count]}Child>";
                echo "<ChildName>" . htmlspecialchars($row["gender"]) . " " . htmlspecialchars($row["first_name"]) . " " . htmlspecialchars($row["last_name"]) . "</ChildName>";
                echo "<ChildAge>" . htmlspecialchars($row["child_age"]) . "</ChildAge>";
                echo "<ChildPassportNumber>" . htmlspecialchars($row["passport_number"]) . "</ChildPassportNumber>";
                echo "</{$number[$child_count]}Child>";
                $child_count++;
            }


            $previousResId = $row["reservation_id"];
            $previousRoomType = $row["reservation_room_type"];
        }
        if ($previousResId !== null) {
            echo "<PhoneNumber>" . htmlspecialchars($phone_number) . "</PhoneNumber>";
            echo "<Email>" . htmlspecialchars($email) . "</Email>";
            echo "<Address>" . htmlspecialchars($address) . "</Address>";
            echo "<Notes>" . htmlspecialchars($note) . "</Notes>";
            echo "</{$number[$whichRoom]}RoomGuests>";
            $whichRoom++;
            $adult_count = 1;
            $child_count = 1;
        }
        echo "</GuestsInformation>";
    } else {
        echo "<message>No record found</message><status>success</status>";
        echo "</DetailBooking>";
        exit;
    }


    if ($cancel_reservation === true) {
        echo "<CancellationStatus>1</CancellationStatus>";
    } else {
        echo "<CancellationStatus>0</CancellationStatus>";
    }
    echo "</DetailBooking>";
    // LOG kısmı 
    // Log klasörü yoksa oluştur

    $logDir = __DIR__ . '/logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Log dosyası yolu
    $logFile = $logDir . '/DetailBookingRequestsTest.txt';

    // Zaman ve IP bilgisi
    $timestamp = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? 'unknown';

    // Log mesajı
    $logMessage = "[$timestamp] IP: $ip | Content-Type: $contentType || Kullanıcı rezervasyon detayı sorgusu yaptı." . PHP_EOL;

    // Log dosyasına yaz
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    exit;
}

?>

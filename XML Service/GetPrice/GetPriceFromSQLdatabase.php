<?php
include($_SERVER['DOCUMENT_ROOT'] . '/config.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Çocuk Yaşları inputları
    if (isset($_POST['ajax'], $_POST['child_number'], $_POST['index']) && $_POST['ajax'] === 'get_child_inputs') {

        $child_number = intval($_POST['child_number']);
        $index = $_POST['index'];

        for ($i = 1; $i <= $child_number; $i++) {

            echo '<div class="form-group col-md-3">
                    <label>' . $i . '.ChildAge</label>
                    <input type="number" class="form-control" id="child_age_' . $i . '" min="0" max="14" name="' . $index . 'ChildAge' . $i . '" required>
              </div>';
        }

        exit;
    }

    function yasAraliktaMi($yas, $aralik)
    { // İki sayı arasında mı ?
        list($min, $max) = explode("-", $aralik);
        $min = floatval(str_replace(',', '.', $min));
        $max = floatval(str_replace(',', '.', $max));
        return $yas >= $min && $yas <= $max;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    $isXml = strpos($contentType, 'text/xml') !== false || strpos($contentType, 'application/xml') !== false;
    $isForm = strpos($contentType, 'application/x-www-form-urlencoded') !== false || strpos($contentType, 'multipart/form-data') !== false;

    if ($isXml) {
        // XML içerik al

        $rawXml = file_get_contents('php://input');
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($rawXml, "SimpleXMLElement", LIBXML_NOENT | LIBXML_NOCDATA | LIBXML_NONET);

        if ($xml === false) {
            echo "<error>XML parse edilemedi</error>";
            exit;
        }

        // XML'den değerleri al
        $room_count = (int)$xml->room_count_select;
        $rooms = $xml->Room;
    } elseif ($isForm  && !isset($_POST['ajax'])) {
        header("Content-Type: text/xml; charset=UTF-8");
        // Formdan gelen POST verisini kullan
        $room_count = intval($_POST['room_count_select']);
        $rooms = [];
        for ($i = 1; $i <= $room_count; $i++) {
            $rooms[] = (object)[
                'HotelId' => $_POST["HotelId_{$i}"],
                'RoomTypeId' => $_POST["RoomTypeId_{$i}"],
                'AdultNumber' => $_POST["AdultNumber_{$i}"],
                'ChildNumber' => $_POST["ChildNumber_{$i}"],
                'StartDate' => $_POST["StartDate_{$i}"],
                'EndDate' => $_POST["EndDate_{$i}"],
                'ChildAge1' => $_POST["{$i}ChildAge1"] ?? null,
                'ChildAge2' => $_POST["{$i}ChildAge2"] ?? null,
                'ChildAge3' => $_POST["{$i}ChildAge3"] ?? null,
                'ChildAge4' => $_POST["{$i}ChildAge4"] ?? null,
            ];
        }
    } else {
        echo "<error>Geçersiz içerik tipi</error>";
        exit;
    }

    // Ortak işlem – XML veya FORM fark etmez
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<GetPrices>";

    $number = [
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth'
    ];

    if ($room_count > 3 || $room_count < 1) {
        echo "<message>Incorrect room_count entered</message><status>error</status>";
        echo "</GetPrices>";
        exit;
    }



    $whichRoom = 1;
    foreach ($rooms as $room) {
        if ($whichRoom > $room_count) {
            break;
        }
        $hotel_id = (int)$room->HotelId;
        $room_type_id = (int)$room->RoomTypeId;
        //$room_type_name = (string)$room->Type;
        $adult_number = (int)$room->AdultNumber;
        $child_number = (int)$room->ChildNumber;
        $start_date = (string)$room->StartDate;
        $end_date = (string)$room->EndDate;
        $start_date = $start_date ? date('Y-m-d', strtotime($start_date)) : null;
        $end_date = $end_date ? date('Y-m-d', strtotime($end_date)) : null;

        // Yaşları al
        $checkError = 0;
        if ($child_number !== 0) {
            $childAges = [];
            for ($j = 1; $j <= $child_number; $j++) {
                $key = "ChildAge$j";
                $childAges[$j] = (int)$room->$key ?? null;
                if ($childAges[$j] > 14) {
                    $checkError++;
                }
            }
        }

        //Yetişkin sayısı kontrol
        if ($adult_number < 1) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect adult number entered</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            $whichRoom++;
            continue;
        }

        //Çocuk sayısı kontrol
        if ($child_number >= 5 || $child_number < 0) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect child number entered</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            $whichRoom++;
            continue;
        }

        //Çocuk Yaşı kontrol
        if ($checkError > 0) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect child age entered</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            $whichRoom++;
            continue;
        }

        // Tarih kontrolü
        if (strtotime($start_date) >= strtotime($end_date)) {
            //echo "Hatalı tarih aralığı.";
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect date range</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            $whichRoom++;
            continue;
        }
        $this_day = date("Y-m-d");
        if (strtotime($start_date) < strtotime(date("Y-m-d"))) {
            // echo "Hatalı tarih girişi";
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect date entry</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            $whichRoom++;
            continue;
        }

        //$room_type_name değerini alıyoruz
        $sql = "SELECT room_name FROM room_type_test WHERE hotel_id = ? AND room_type_id = ? AND max_adult = ? AND max_child = ?";
        $stmt = $baglanti->prepare($sql);
        $stmt->bind_param("iiii", $hotel_id, $room_type_id, $adult_number, $child_number);
        $stmt->execute();
        $stmt->bind_result($room_type_name);
        $stmt->fetch();
        $stmt->close();

        if (!isset($room_type_name)) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>No rooms were found matching the information entered.</message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            $whichRoom++;
            continue;
        }

        $price_policy = 1;

        $totalPrice = 0.0;
        $flag = 0;

        // Fiyatları veritabanından çek
        if ($price_policy === 0) {

            $sql = "SELECT t.rate, r.start_date, r.end_date, y.currency_symbol ,y.currency_name FROM rooms r
            JOIN room_type t ON t.room_type_id = r.room_type AND t.hotel_id = r.hotel_id
            JOIN hotels h ON h.id = r.hotel_id
            JOIN currency y ON h.currency = y.id
            WHERE r.hotel_id=? AND r.room_type =? AND t.room_name=?";
            $stmt = $baglanti->prepare($sql);
            $stmt->bind_param("iis", $hotel_id, $room_type_id, $room_type_name);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            if (!$row) {
                echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>No record found matching the information entered</message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                $whichRoom++;
                continue;
            } else {


                $begining_date = $row['start_date'];
                $finish_date = $row['end_date'];
                $rate = $row['rate'];
                $currency_symbol = $row['currency_symbol'];
                $currency_name = $row['currency_name'];

                $start_date_ts = strtotime($start_date);
                $end_date_ts = strtotime($end_date);
                $start_ts = strtotime($begining_date);
                $end_ts = strtotime($finish_date);



                if (
                    !($start_ts <= $start_date_ts && $start_date_ts <= $end_ts) ||
                    !($start_ts <= $end_date_ts && $end_date_ts <= $end_ts)
                ) {
                    $flag = 1;
                    echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect date range entered</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                    $whichRoom++;
                    continue;
                } else if ($rate === null || empty($rate)) {
                    echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>No prices found for these dates</message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                    $whichRoom++;
                    continue;
                } else {
                    $sqlinfo = "SELECT t.room_name, h.name, n.room_type_name, p.PensionCode
                        FROM room_type t 
                        LEFT JOIN room_type_name n ON n.id = t.room_type_id
                        LEFT JOIN hotels h ON h.id = t.hotel_id
                        LEFT JOIN pensions p ON h.pension = p.ID
                        WHERE t.hotel_id = ? AND t.room_type_id = ? AND t.room_name = ? AND t.min_adult <= ? AND t.max_adult >= ? AND t.max_child >= ?";

                    $stmtinfo = $baglanti->prepare($sqlinfo);
                    $stmtinfo->bind_param("iisiii", $hotel_id, $room_type_id, $room_type_name, $adult_number, $adult_number, $child_number);
                    $stmtinfo->execute();
                    $result = $stmtinfo->get_result();
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                    } else {
                        echo "<{$number[$whichRoom]}RoomGetPriceResponse><message> No record found matching the information entered </message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                        $whichRoom++;
                        continue;
                    }

                    echo "<{$number[$whichRoom]}RoomGetPriceResponse>
                        <Checkin>{$start_date}</Checkin>
                        <Checkout>{$end_date}</Checkout>
                            <HotelName>{$row['name']}</HotelName>
                            <RoomName>{$row['room_type_name']}</RoomName>
                            <RoomType>{$row['room_name']}</RoomType>
                            <PensionCode>{$row['PensionCode']}</PensionCode>
                            <AdultNumber>{$adult_number}</AdultNumber>
                            <ChildNumber>{$child_number}</ChildNumber>";

                    if ($child_number !== 0) {
                        for ($i = 1; $i <= $child_number; $i++) {
                            echo "<{$number[$i]}ChildAge>{$childAges[$i]}</{$number[$i]}ChildAge>";
                        }
                    }

                    $nights = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24);
                    $totalPrice = floatval($nights) * floatval($rate);


                    echo "<TotalPrice>{$totalPrice}</TotalPrice>
                    <CurrencySymbol>{$currency_symbol}</CurrencySymbol>
                    <CurrencyName>{$currency_name}</CurrencyName>
                    <status>success</status>
                    </{$number[$whichRoom]}RoomGetPriceResponse>";
                    $whichRoom++;
                    continue;
                }
            }
        } else {

            //Eğer price_policy === 1 ise 

            // Tarih aralığını çıkaralım
            $dates = [];
            $period = new DatePeriod(
                new DateTime($start_date),
                new DateInterval('P1D'),
                new DateTime($end_date)
            );

            foreach ($period as $date) {
                $dates[$date->format("Y-m-d")] = null;
            }


            if ($child_number === 0) {

                $query = "SELECT a.date, a.price, y.currency_symbol
                FROM amount a
                JOIN contracts c ON a.hotel_id = c.hotel_id AND a.room_type = c.room_id AND a.type = c.type
                JOIN currency y ON c.currency = y.id 
                WHERE a.hotel_id = ? 
                AND a.room_type = ?  
                AND a.type = ? 
                AND a.date >= ? 
                AND a.date < ?";

                $stmt = $baglanti->prepare($query);
                $stmt->bind_param("iisss", $hotel_id, $room_type_id, $room_type_name, $start_date, $end_date);
                $stmt->execute();
                $result = $stmt->get_result();

                // Gelen fiyatları yerleştir
                while ($row = $result->fetch_assoc()) {

                    // İlk satırdaki currency_symbol'u al
                    $currency_symbol = $row['currency_symbol'];

                    // İlk satırı da dates dizisine ekle
                    $dates[$row['date']] = floatval($row['price']);

                    // Diğer satırlar için döngü
                    while ($row = $result->fetch_assoc()) {
                        $dates[$row['date']] = floatval($row['price']);
                    }
                }
            } elseif ($child_number > 0) { //Childpolicy den çekiyoruz


                $query = "SELECT DISTINCT a.date, a.price, y.currency_symbol, y.currency_name, a.child_age_1, a.child_age_2, a.child_age_3, a.child_age_4
                FROM childpolicy_test a
                JOIN contracts_test c ON a.hotel_id = c.hotel_id AND a.room_id = c.room_id AND a.type = c.type
                JOIN currency y ON c.currency = y.id 
                WHERE a.hotel_id = ? 
                AND a.room_id = ?  
                AND a.type = ? 
                AND a.date >= ? 
                AND a.date < ?";

                $stmt = $baglanti->prepare($query);
                $stmt->bind_param("iisss", $hotel_id, $room_type_id, $room_type_name, $start_date, $end_date);
                $stmt->execute();
                $result = $stmt->get_result();
                // Gelen fiyatları yerleştir
                while ($row = $result->fetch_assoc()) {

                    $get_price = 0;
                    //kayıtları sağlıklı bir şekilde alabilmek için yaş kontrolleri (childpolicy için)
                    $child_age_1 = isset($row["child_age_1"]) ? $row["child_age_1"] : null;
                    $child_age_2 = isset($row["child_age_2"]) ? $row["child_age_2"] : null;
                    $child_age_3 = isset($row["child_age_3"]) ? $row["child_age_3"] : null;
                    $child_age_4 = isset($row["child_age_4"]) ? $row["child_age_4"] : null;

                    if ($child_number === 4 && $child_age_4 !== null) {
                        $result_4 = yasAraliktaMi($childAges[4], $child_age_4);
                        $result_3 = yasAraliktaMi($childAges[3], $child_age_3);
                        $result_2 = yasAraliktaMi($childAges[2], $child_age_2);
                        $result_1 = yasAraliktaMi($childAges[1], $child_age_1);

                        if ($result_1 && $result_2 && $result_3 && $result_4) {

                            $get_price = 1;
                        }
                    } elseif ($child_number === 3 && $child_age_3 !== null && $child_age_4 === null) {
                        $result_3 = yasAraliktaMi($childAges[3], $child_age_3);
                        $result_2 = yasAraliktaMi($childAges[2], $child_age_2);
                        $result_1 = yasAraliktaMi($childAges[1], $child_age_1);

                        if ($result_1 && $result_2 && $result_3) {
                            $get_price = 1;
                        }
                    } elseif ($child_number === 2 && $child_age_2 !== null && $child_age_3 === null && $child_age_4 === null) {
                        $result_2 = yasAraliktaMi($childAges[2], $child_age_2);
                        $result_1 = yasAraliktaMi($childAges[1], $child_age_1);

                        if ($result_1 && $result_2) {
                            $get_price = 1;
                        }
                    } elseif ($child_number === 1 && $child_age_1 !== null && $child_age_2 === null && $child_age_3 === null && $child_age_4 === null) {
                        $result_1 = yasAraliktaMi($childAges[1], $child_age_1);

                        if ($result_1) {
                            $get_price = 1;
                        }
                    }


                    if ($get_price === 1) {

                        $currency_symbol = $row['currency_symbol'];
                        $currency_name = $row['currency_name'];
                        $dates[$row['date']] = floatval($row['price']);
                    }
                }
            }

            // Eğer periyod varsa onu da hesaplayacak şekilde fiyatları alıyoruz
            // Şimdi bloklara ayıralım
            $blocks = [];
            $currentPrice = null;
            $blockStart = null;
            $musaitlikYok = false;


            foreach ($dates as $date => $price) {
                if (is_null($price) || empty($price)) {
                    // Müsaitlik yoksa blok kapat
                    if ($blockStart !== null) {
                        $blocks[] = ['start' => $blockStart, 'end' => date('Y-m-d', strtotime($date . ' -1 day')), 'price' => $currentPrice];
                        $blockStart = null;
                        $currentPrice = null;
                    }
                    $musaitlikYok = true;
                    break; // Devamını kontrol etmiyoruz
                }


                if ($price !== $currentPrice) {
                    // Fiyat değiştiyse blok kapat ve yenisini başlat
                    if ($blockStart !== null) {
                        $blocks[] = ['start' => $blockStart, 'end' => date('Y-m-d', strtotime($date . ' -1 day')), 'price' => $currentPrice];
                    }
                    $blockStart = $date;
                    $currentPrice = $price;
                }
            }

            // Son bloğu kapat
            if ($blockStart !== null) {
                $lastDate = array_key_last($dates);
                $blocks[] = ['start' => $blockStart, 'end' => $lastDate, 'price' => $currentPrice];
            }

            $prices = [];
            //$flag = 0;
            if (empty($blocks)) {
                echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>No availability on selected dates</message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                $whichRoom++;
                continue;
                //$flag = 1;
            } else {
                //Girilen bilgileri isim olarak alıyoruz
                $sqlinfo = "SELECT t.room_name, h.name, n.room_type_name, p.PensionCode 
                        FROM room_type_test t 
                        LEFT JOIN room_type_name_test n ON n.id = t.room_type_id
                        LEFT JOIN hotels_test h ON h.id = t.hotel_id
                        LEFT JOIN pensions p ON h.pension = p.ID
                        WHERE t.hotel_id = ? AND t.room_type_id = ? AND t.room_name = ? AND t.min_adult <= ? AND t.max_adult >= ? AND t.max_child >= ?";

                $stmtinfo = $baglanti->prepare($sqlinfo);
                $stmtinfo->bind_param("iisiii", $hotel_id, $room_type_id, $room_type_name, $adult_number, $adult_number, $child_number);
                $stmtinfo->execute();
                $result = $stmtinfo->get_result();
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                } else {
                    echo "<{$number[$whichRoom]}RoomGetPriceResponse><message> No record found matching the information entered </message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                    $whichRoom++;
                    continue;
                }

                if ($musaitlikYok) {
                    echo "<{$number[$whichRoom]}RoomGetPriceResponse><message> There are days with no availability on the entered dates. </message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                    $whichRoom++;
                    continue;
                }

                echo "<{$number[$whichRoom]}RoomGetPriceResponse>
                        <Checkin>{$start_date}</Checkin>
                        <Checkout>{$end_date}</Checkout>
                        <HotelName>{$row['name']}</HotelName>
                        <RoomName>{$row['room_type_name']}</RoomName>
                        <RoomType>{$row['room_name']}</RoomType>
                        <PensionCode>{$row['PensionCode']}</PensionCode>
                        <AdultNumber>{$adult_number}</AdultNumber>
                        <ChildNumber>{$child_number}</ChildNumber>";

                if ($child_number != 0) {
                    for ($i = 1; $i <= $child_number; $i++) {
                        echo "<{$number[$i]}ChildAge>{$childAges[$i]}</{$number[$i]}ChildAge>";
                    }
                }

                foreach ($blocks as $b) {

                    $nights = (strtotime($b['end']) - strtotime($b['start'])) / (60 * 60 * 24) + 1;
                    $totalPrice += floatval($nights) * floatval($b["price"]);
                }


                echo "<TotalPrice>{$totalPrice}</TotalPrice>
                        <CurrencySymbol>{$currency_symbol}</CurrencySymbol>
                        <CurrencyName>{$currency_name}</CurrencyName>
                        <status>success</status>
                        </{$number[$whichRoom]}RoomGetPriceResponse>";
            }
        }

        $whichRoom++;
    }

    echo "</GetPrices>";

    // LOG kısmı 
    // Log klasörü yoksa oluştur

    $logDir = __DIR__ . '/logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Log dosyası yolu
    $logFile = $logDir . '/GetPriceRequests.txt';

    // Zaman ve IP bilgisi
    $timestamp = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? 'unknown';

    // Log mesajı
    $logMessage = "[$timestamp] IP: $ip | Content-Type: $contentType || Kullanıcı fiyat sorgusu yaptı." . PHP_EOL;

    // Log dosyasına yaz
    file_put_contents($logFile, $logMessage, FILE_APPEND);

    exit;
}



?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <title>Get Price</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <!-- Bootstrap 4 CDN -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

    <style>
        .info-box {
            background-color: #f9f9f9;
            border: 1px solid #ddd;
            font-family: Arial, sans-serif;
            padding: 1.5rem;
            border-radius: 5px;
        }

        .info-header {
            background-color: #007bff;
            color: white;
            padding: 0.5rem 1rem;
            font-weight: bold;
            font-size: 1.1rem;
            margin-bottom: 1rem;
            border-radius: 4px 4px 0 0;
        }

        .info-body {
            color: #333;
        }

        .info-body code {
            background-color: #f0f0f0;
            display: block;
            padding: 1rem;
            border-radius: 4px;
            font-family: Consolas, monospace;
            font-size: 0.85rem;
            white-space: pre-wrap;
            margin-bottom: 1rem;
        }

        .info-body .highlight {
            color: #007bff;
        }

        .main-footer {
            background: #f4f6f9;
            color: #444;
            padding: 15px;
            border-top: 1px solid #dee2e6;
            font-size: 14px;
        }
    </style>
</head>

<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-12 mx-auto">
                <div class="card shadow rounded-lg">
                    <div class="card-header bg-primary text-white text-center">
                        <h4 class="mb-0">Get Price</h4>
                    </div>
                    <div class="card-body">

                        <form method="post" target="_blank">
                            <label>How many rooms will you query about?</label>
                            <select id="room-count" class="form-control w-25 mb-3" name="room_count_select">
                                <option value="1" selected>1</option>
                                <option value="2">2</option>
                                <option value="3">3</option>
                            </select>
                            <div id="rooms-wrapper" class="d-flex flex-wrap justify-content-center gap-3"></div>
                            <script type="text/template" id="room-template">
                                <input type="hidden" class="index" data-id="__index__" name="index___index__" value="__index__">
                                <div class="room-form border p-3 m-1 mb-3" style="flex: 0 0 32%; max-width: 32%;">
                                    <h5>Room <span class="room-number"></span></h5>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label>HotelId</label>
                                            <input type="text" class="form-control hotel_id" data-id="__index__" name="HotelId___index__" required>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label>RoomTypeId</label>
                                            <input type="text" class="form-control room_type_id" data-id="__index__" name="RoomTypeId___index__" required>
                                        </div>
                                    </div>
                                    <div class="form-row adult_child_input" data-id="__index__">

                                        <div class="form-group col-md-6">
                                            <label>AdultNumber</label>
                                            <input type="number" class="form-control" name="AdultNumber___index__" min="1" max="10" required>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label>ChildNumber</label>
                                            <input type="number" class="form-control child-number" data-id="__index__" min="0" max="4" name="ChildNumber___index__" required>
                                        </div>

                                    </div>
                                    <div class="form-row">
                                        <div class="form-group col-md-6">
                                            <label>StartDate</label>
                                            <input type="date" class="form-control" name="StartDate___index__" required>
                                        </div>
                                        <div class="form-group col-md-6">
                                            <label>EndDate</label>
                                            <input type="date" class="form-control" name="EndDate___index__" required>
                                        </div>
                                    </div>

                                    <div class="form-row child-inputs" data-id="__index__">

                                    </div>
                                </div>
                            </script>
                            <button type="submit" class="btn btn-success btn-block">Query and View XML</button>
                        </form>

                    </div>
                </div>

            </div>
            <div class="info-box mt-5">
                <div class="info-header">XML</div>
                <div class="info-body">
                    <p>The following is a sample XML request and response. The <span class="highlight">placeholders</span> shown need to be replaced with actual values.</p>
                    <pre><code>
&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;GetPriceRequest&gt;
    &lt;room_count_select&gt;<span class="highlight">int</span>&lt;/room_count_select&gt;
    &lt;Room&gt;
        &lt;HotelId&gt;<span class="highlight">int</span>&lt;/HotelId&gt;
        &lt;RoomTypeId&gt;<span class="highlight">int</span>&lt;/RoomTypeId&gt;
        &lt;AdultNumber&gt;<span class="highlight">int</span>&lt;/AdultNumber&gt;
        &lt;ChildNumber&gt;<span class="highlight">int</span>&lt;/ChildNumber&gt;
        &lt;ChildAge1&gt;<span class="highlight">int</span>&lt;/ChildAge1&gt;
        &lt;ChildAge2&gt;<span class="highlight">int</span>&lt;/ChildAge2&gt;
        &lt;ChildAge3&gt;<span class="highlight">int</span>&lt;/ChildAge3&gt;
        &lt;ChildAge4&gt;<span class="highlight">int</span>&lt;/ChildAge4&gt;
        &lt;StartDate&gt;<span class="highlight">string (YYYY-MM-DD)</span>&lt;/StartDate&gt;
        &lt;EndDate&gt;<span class="highlight">string (YYYY-MM-DD)</span>&lt;/EndDate&gt;
    &lt;/Room&gt;
    &lt;Room&gt;
        &lt;HotelId&gt;<span class="highlight">int</span>&lt;/HotelId&gt;
        &lt;RoomTypeId&gt;<span class="highlight">int</span>&lt;/RoomTypeId&gt;
        &lt;AdultNumber&gt;<span class="highlight">int</span>&lt;/AdultNumber&gt;
        &lt;ChildNumber&gt;<span class="highlight">int</span>&lt;/ChildNumber&gt;
        &lt;StartDate&gt;<span class="highlight">string (YYYY-MM-DD)</span>&lt;/StartDate&gt;
        &lt;EndDate&gt;<span class="highlight">string (YYYY-MM-DD)</span>&lt;/EndDate&gt;
    &lt;/Room&gt;
    ...
&lt;/GetPriceRequest&gt;
                    </code></pre>

                    <pre><code>
&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;GetPrices&gt;
    &lt;firstRoomGetPriceResponse&gt;
        &lt;Checkin&gt;YYYY-MM-DD&lt;/Checkin&gt;
        &lt;Checkout&gt;YYYY-MM-DD&lt;/Checkout&gt;
        &lt;HotelName&gt;string&lt;/HotelName&gt;
        &lt;RoomName&gt;string&lt;/RoomName&gt;
        &lt;RoomType&gt;string&lt;/RoomType&gt;
        &lt;PensionCode&gt;string&lt;/PensionCode&gt;
        &lt;AdultNumber&gt;int&lt;/AdultNumber&gt;
        &lt;ChildNumber&gt;int&lt;/ChildNumber&gt;
        &lt;firstChildAge&gt;int&lt;/firstChildAge&gt;
        &lt;secondChildAge&gt;int&lt;/secondChildAge&gt;
        &lt;TotalPrice&gt;float&lt;/TotalPrice&gt;
        &lt;CurrencySymbol&gt;string&lt;/CurrencySymbol&gt;
        &lt;CurrencyName&gt;string&lt;/CurrencyName&gt;
        &lt;status&gt;success | error&lt;/status&gt;
    &lt;/firstRoomGetPriceResponse&gt;
    &lt;secondRoomGetPriceResponse&gt;
        ...
    &lt;/secondRoomGetPriceResponse&gt;
    ...
&lt;/GetPrices&gt;

                    </code></pre>
                </div>
                <div class="info-header">HTTP POST</div>
                <div class="info-body">
                    <p>The following is a sample HTTP POST request and response. The <span class="highlight">placeholders</span> shown need to be replaced with actual values.</p>

                    <pre><code>POST /includes/xml/GetPrice.php HTTP/1.1
Host: yourdomain.com
Content-Type: application/x-www-form-urlencoded
Content-Length: length

['room_count_select' => <span class="highlight">int</span>&'HotelId_1' => <span class="highlight">int</span>&'RoomTypeId_1' => <span class="highlight">int</span>&'AdultNumber_1' => <span class="highlight">int</span>&'ChildNumber_1' => <span class="highlight">int</span>&'1ChildAge1' => <span class="highlight">int</span>&'StartDate_1' => <span class="highlight">string (YYYY-MM-DD)</span>&'EndDate_1' => <span class="highlight">string (YYYY-MM-DD)</span>
   ...]
        </code></pre>

                    <pre><code>HTTP/1.1 200 OK
Content-Type: text/xml; charset=utf-8
Content-Length: length

&lt;?xml version="1.0" encoding="utf-8"?&gt;
&lt;GetPrices&gt;
    &lt;firstRoomGetPriceResponse&gt;
    &lt;Checkin&gt;string&lt;/Checkin&gt;
    &lt;Checkout&gt;string&lt;/Checkout&gt;
    &lt;HotelName&gt;string&lt;/HotelName&gt;
    &lt;RoomName&gt;string&lt;/RoomName&gt;
    &lt;RoomType&gt;string&lt;/RoomType&gt;
    &lt;PensionCode&gt;string&lt;/PensionCode&gt;
    &lt;AdultNumber&gt;int&lt;/AdultNumber&gt;
    &lt;ChildNumber&gt;int&lt;/ChildNumber&gt;
    &lt;firstChildAge&gt;int&lt;/firstChildAge&gt; <!-- sadece varsa -->
    &lt;TotalPrice&gt;float&lt;/TotalPrice&gt;
    &lt;CurrencySymbol&gt;string&lt;/CurrencySymbol&gt;
    &lt;CurrencyName&gt;string&lt;/CurrencyName&gt;
    &lt;status&gt;success | error&lt;/status&gt;
  &lt;/firstRoomGetPriceResponse&gt;
  ...
&lt;/GetPrices&gt;
        </code></pre>
                </div>
            </div>
        </div>
    </div>

    <script>
        $(document).on('change', '.child-number', function() {
            const index = $(this).data('id');
            const child_number = $('.child-number[data-id="' + index + '"]').val();
            //const index = $(this).data('id');

            if (child_number > 0) {
                $.post('', {
                    ajax: 'get_child_inputs',
                    child_number: child_number,
                    index: index
                }, function(data) {
                    $('.child-inputs[data-id="' + index + '"]').html(data);
                });
            } else {
                $('.child-inputs[data-id="' + index + '"]').html("");
            }

        });

        $(document).ready(function() {
            function generateForms(count) {
                $('#rooms-wrapper').html(''); // Önceki formları temizle
                const template = $('#room-template').html();

                for (let i = 1; i <= count; i++) {
                    let formHTML = template.replace(/__index__/g, i);
                    let $form = $(formHTML);
                    $form.find('.room-number').text(i);
                    $('#rooms-wrapper').append($form);
                }
            }

            $('#room-count').on('change', function() {
                const count = parseInt($(this).val());
                generateForms(count);
            });

            generateForms(1); // Sayfa açıldığında 1 form göster
        });
    </script>

    <footer class="main-footer">
        <strong>Telif hakkı &copy; 2014-2025 <a href="https://mansurbilisim.com" target="_blank">Mansur Bilişim Ltd. Şti.</a></strong>
        Her hakkı saklıdır.
        <div class="float-right d-none d-sm-inline-block">
            <b>Version</b> 1.0.1
        </div>
    </footer>
</body>

</html>
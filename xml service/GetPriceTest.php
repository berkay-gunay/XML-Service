<?php
include($_SERVER['DOCUMENT_ROOT'] . '/config.php');
include('xml_security.php');
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

    // Çıktımız XML olacak (AJAX HTML hariç)
    headerXml();

    if ($isXml) {
        // Yalnızca XML içerik tiplerini kabul et
        requireContentTypeXml();

        // İstek gövdesini güvenli yükle (XXE kapalı, boyut sınırı yok)
        list($xml, $rawXml) = loadXmlFromRequestBodyNoLimit();

        // Alanları al
        if (!isset($xml->Room) || !isset($xml->room_count_select)) {
            echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
            echo "<GetPrices><message>Check rooms and room_count tags</message><status>error</status></GetPrices>";
            exit;
        }
        $room_count = (int)$xml->room_count_select;
        $rooms      = $xml->Room;
    } elseif ($isForm  && !isset($_POST['ajax'])) {
        
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
                'ChildAge1' => isset($_POST["{$i}ChildAge1"]) ? $_POST["{$i}ChildAge1"] : null,
                'ChildAge2' => isset($_POST["{$i}ChildAge2"]) ? $_POST["{$i}ChildAge2"] : null,
                'ChildAge3' => isset($_POST["{$i}ChildAge3"]) ? $_POST["{$i}ChildAge3"] : null,
                'ChildAge4' => isset($_POST["{$i}ChildAge4"]) ? $_POST["{$i}ChildAge4"] : null
            ];
        }
    } else {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<GetPrices><message>Invalid content type</message><status>error</status></GetPrices>";
        exit;
    }

    // ---- Dahili veri XML'ini güvenli yükle ----
    $xmlFilePath    = __DIR__ . '/data/info.xml';
    $allowedBaseDir = realpath(__DIR__ . '/data');
    $realPath       = realpath($xmlFilePath);

    if (!$realPath || strpos($realPath, $allowedBaseDir) !== 0 || !is_readable($realPath)) {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<GetPrices><message>XML file access denied or file not found</message><status>error</status></GetPrices>";
        exit;
    }
    $xml_data = loadXmlFromFileSafe($realPath);

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
        $CheckChildTags = false;
        if ($child_number !== 0) {
            $childAges = [];
            for ($j = 1; $j <= $child_number; $j++) {
                $key = "ChildAge$j";
                $childAges[$j] = (int)$room->$key;
                if (!isset($childAges[$j])) {
                    $CheckChildTags = true;
                }
                if ($childAges[$j] > 14) {
                    $checkError++;
                }
            }
        }


        // Etiket kontrolü
        if (empty($hotel_id) || empty($room_type_id) || empty($adult_number) || empty($child_number) || empty($start_date) || empty($end_date) || $CheckChildTags) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Check that all information is entered completely.</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            $whichRoom++;
            continue;
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
        $room_type_name = "";
        $found_ = false;
        foreach ($xml_data->room_type_test->Room_type as $room_type) {

            if ((int)$room_type->hotel_id == $hotel_id && (int)$room_type->room_type_id == $room_type_id && (int)$room_type->max_adult == $adult_number && (int)$room_type->max_child == $child_number) {
                $room_type_name = (string)$room_type->room_name;
                $found_ = true;

                break;
            }
        }
        if (!$found_) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>No rooms were found matching the information entered.</message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            $whichRoom++;
            continue;
        }



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

            $roomTypes = [];
            foreach ($xml_data->room_type->Room_type as $room_type) {
                $roomTypes[intval($room_type->hotel_id) . "_" . intval($room_type->room_type_id)] = $room_type;
            }
            $Hotels = [];
            foreach ($xml_data->hotels->Hotels as $hotel) {
                $Hotels[intval($hotel->id)] = $hotel;
            }
            $Currency = [];
            foreach ($xml_data->currency->Currency as $currency) {
                $Currency[intval($currency->id)] = $currency;
            }
            $found = false;
            foreach ($xml_data->rooms->Rooms as $room) {
                if (intval($room->hotel_id) == $hotel_id && intval($room->room_type) == $room_type_id) {
                    $key = intval($room_type->hotel_id) . "_" . intval($room_type->room_type_id);

                    if (isset($roomTypes[$key]) && (string)$roomTypes[$key] == $room_type_name) {
                        $begining_date = (string)$room->start_date;
                        $finish_date = (string)$room->end_date;
                        $rate = (int)$roomTypes[$key]->rate;

                        $hotel = $Hotels[$hotel_id];
                        $currency_id = (int)$hotel->currency;

                        $currency_symbol = $Currency[$currency_id]->currency_symbol;
                        $currency_name = $Currency[$currency_id]->currency->name;

                        $start_date_ts = strtotime($start_date);
                        $end_date_ts = strtotime($end_date);
                        $start_ts = strtotime($begining_date);
                        $end_ts = strtotime($finish_date);

                        $found = true; //Koşullara uygun kayıt var mı yok mu kontrolü


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

                            $RoomTypeName = [];
                            foreach ($xml_data->room_type_name->Room_type_name as $room_type_name_) {
                                $RoomTypeName[(int)$room_type_name_->id] = $room_type_name_;
                            }
                            $Hotels = [];
                            foreach ($xml_data->hotels->Hotels as $hotel) {
                                $Hotels[(int)$hotel->id] = $hotel;
                            }
                            $Pensions = [];
                            foreach ($xml_data->pensions->Pensions as $pension) {
                                $Pensions[(int)$pension->ID] = $pension;
                            }
                            $found = false;
                            foreach ($xml_data->room_type->Room_type as $room_type) {
                                if ((int)$room_type->hotel_id == $hotel_id && (int)$room_type->room_type_id == $room_type_id && (string)$room_type->room_name == $room_type_name && (int)$room_type->min_adult <= $adult_number && (int)$room_type->max_adult >= $adult_number && (int)$room_type->max_child >= $child_number) {
                                    //HotelName
                                    $hotel = $Hotels[(int)$room_type->hotel_id];
                                    $hotel_name = $hotel->name;

                                    //RoomName
                                    $room_type_namee = $RoomTypeName[(int)$room_type->room_type_id];
                                    $room_name = (string) $room_type_namee->room_type_name;

                                    //RoomType
                                    $room_type = (string)$room_type->room_name;

                                    //PensionCode
                                    $pension = $Pensions[$hotel->pension];
                                    $pension_code = (int)$pension->PensionCode;

                                    $found = true;

                                    echo "<{$number[$whichRoom]}RoomGetPriceResponse>
                                            <Checkin>" . htmlspecialchars($start_date) . "</Checkin>
                                            <Checkout>" . htmlspecialchars($end_date) . "</Checkout>
                                            <HotelName>" . htmlspecialchars($hotel_name) . "</HotelName>
                                            <RoomName>" . htmlspecialchars($room_name) . "</RoomName>
                                            <RoomType>" . htmlspecialchars($room_type) . "</RoomType>
                                            <PensionCode>" . htmlspecialchars($pension_code) . "</PensionCode>
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
                                    <CurrencySymbol>" . htmlspecialchars($currency_symbol) . "</CurrencySymbol>
                                    <CurrencyName>" . htmlspecialchars($currency_name) . "</CurrencyName>
                                    <status>success</status>
                                    </{$number[$whichRoom]}RoomGetPriceResponse>";
                                    $whichRoom++;
                                    break;
                                }
                            }
                            if (!$found) {
                                echo "<{$number[$whichRoom]}RoomGetPriceResponse><message> No record found matching the information entered </message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                                $whichRoom++;
                                break;
                            }
                        }
                        break;
                    }
                }
            }
            if (!$found) {
                echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>No record found matching the information entered</message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                $whichRoom++;
                continue;
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

                $Contracts = [];
                foreach ($xml_data->contracts->Contracts as $contract) {
                    $Contract[(int)$contract->hotel_id . "_" . (int)$contract->room_id . "_" . (string)$contract->type] = $contract;
                }
                $Currency = [];
                foreach ($xml_data->currency->Currency as $currency) {
                    $Currency[(int)$currency->id] = $currency;
                }
                foreach ($xml_data->amount->Amount as $amount) {
                    $amount_date = date('Y-m-d', strtotime((string)$amount->date));
                    if ((int)$amount->hotel_id == $hotel_id && (int)$amount->room_type == $room_type_id && (string)$amount->type == $room_type_name && $amount_date >= $start_date && $amount_date < $end_date) {
                        $contract = $Contract[(int)$amount->hotel_id . "_" . (int)$amount->room_type . "_" . (string)$amount->type];
                        $currency = $Currency[(int)$contract->currency];

                        $currency_symbol = (string)$currency->currency_symbol;
                        $dates[(string)$amount->date] = floatval($amount->price);
                        break;
                    }
                }
            } elseif ($child_number > 0) { //Childpolicy den çekiyoruz

                $Contracts = [];
                foreach ($xml_data->contracts_test->Contracts as $contract) {
                    $Contracts[(int)$contract->hotel_id . "_" . (int)$contract->room_id . "_" . (string)$contract->type] = $contract;
                }
                $Currency = [];
                foreach ($xml_data->currency->Currency as $currency) {
                    $Currency[(int)$currency->id] = $currency;
                }
                foreach ($xml_data->childpolicy_test->Childpolicy as $childpolicy) {
                    $childpolicy_date = date('Y-m-d', strtotime((string)$childpolicy->date));
                    if ((int) $childpolicy->hotel_id == $hotel_id && (int) $childpolicy->room_id == $room_type_id && (string) $childpolicy->type == $room_type_name && (string)$childpolicy_date >= $start_date && (string)$childpolicy_date < $end_date) {

                        $c1 = isset($childpolicy->child_age_1) ? (string)$childpolicy->child_age_1 : null;
                        $c2 = isset($childpolicy->child_age_2) ? (string)$childpolicy->child_age_2 : null;
                        $c3 = isset($childpolicy->child_age_3) ? (string)$childpolicy->child_age_3 : null;
                        $c4 = isset($childpolicy->child_age_4) ? (string)$childpolicy->child_age_4 : null;


                        $get_price = 0;
                        //kayıtları sağlıklı bir şekilde alabilmek için yaş kontrolleri (childpolicy için)
                        $child_age_1 = isset($c1) ? $c1 : null;
                        $child_age_2 = isset($c2) ? $c2 : null;
                        $child_age_3 = isset($c3) ? $c3 : null;
                        $child_age_4 = isset($c4) ? $c4 : null;



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

                            $contract = $Contracts[(int)$childpolicy->hotel_id . "_" . (int)$childpolicy->room_id . "_" . (string)$childpolicy->type];
                            $currency = $Currency[(int)$contract->currency];

                            $currency_symbol = (string)$currency->currency_symbol;
                            $currency_name = (string)$currency->currency_name;
                            $dates[(string)$childpolicy->date] = floatval($childpolicy->price);
                        }
                        //break;
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
                echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>No record found matching the information entered</message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                $whichRoom++;
                continue;
                //$flag = 1;
            } else {
                if ($musaitlikYok) {
                    echo "<{$number[$whichRoom]}RoomGetPriceResponse><message> No availability on selected dates </message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                    $whichRoom++;
                    continue;
                }
                //Girilen bilgileri isim olarak alıyoruz

                $RoomTypeName = [];
                foreach ($xml_data->room_type_name_test->Room_type_name as $room_type_name__) {
                    $RoomTypeName[(int)$room_type_name__->id] = $room_type_name__;
                }
                $Hotels = [];
                foreach ($xml_data->hotels_test->Hotels as $hotel) {
                    $Hotels[(int)$hotel->id] = $hotel;
                }
                $Pensions = [];
                foreach ($xml_data->pensions->Pensions as $pension) {
                    $Pensions[(int)$pension->ID] = $pension;
                }
                $found = false;
                foreach ($xml_data->room_type_test->Room_type as $room_type) {

                    if (
                        (int)$room_type->hotel_id == $hotel_id && (int)$room_type->room_type_id == $room_type_id && (string)$room_type->room_name == $room_type_name &&
                        (int)$room_type->min_adult <= $adult_number && (int)$room_type->max_adult >= $adult_number && (int)$room_type->max_child >= $child_number
                    ) {
                        $hotel = $Hotels[(int)$room_type->hotel_id];
                        $HotelName = (string)$hotel->name;

                        $room_type_name1 = $RoomTypeName[(int)$room_type->room_type_id];
                        $RoomName = (string)$room_type_name1->room_type_name;

                        $RoomType = (string)$room_type->room_name;

                        $pension = $Pensions[(int)$hotel->pension];
                        $PensionCode = (string)$pension->PensionCode;

                        $found = true;

                        echo "<{$number[$whichRoom]}RoomGetPriceResponse>
                        <Checkin>" . htmlspecialchars($start_date) . "</Checkin>
                        <Checkout>" . htmlspecialchars($end_date) . "</Checkout>
                        <HotelName>" . htmlspecialchars($HotelName) . "</HotelName>
                        <RoomName>" . htmlspecialchars($RoomName) . "</RoomName>
                        <RoomType>" . htmlspecialchars($RoomType) . "</RoomType>
                        <PensionCode>" . htmlspecialchars($PensionCode) . "</PensionCode>
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
                        <CurrencySymbol>" . htmlspecialchars($currency_symbol) . "</CurrencySymbol>
                        <CurrencyName>" . htmlspecialchars($currency_name) . "</CurrencyName>
                        <status>success</status>
                        </{$number[$whichRoom]}RoomGetPriceResponse>";
                        break;
                    }
                }
                if (!$found) {
                    echo "<{$number[$whichRoom]}RoomGetPriceResponse><message> No record found matching the information entered </message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                    $whichRoom++;
                    continue;
                }
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
    $logFile = $logDir . '/GetPriceRequestsTest.txt';

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
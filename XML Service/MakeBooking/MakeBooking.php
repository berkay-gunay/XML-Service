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

    function yasAraliktaMi($yas, $aralik): bool
    { // İki sayı arasında mı ?
        list($min, $max) = explode("-", $aralik);
        $min = floatval(str_replace(',', '.', $min));
        $max = floatval(str_replace(',', '.', $max));
        return $yas >= $min && $yas <= $max;
    }
    function reservationIdDelete($reservationId): void
    {
        global $baglanti;

        $sql = "DELETE FROM reservations_test WHERE roomcards_group_id= ?";
        $stmt = $baglanti->prepare($sql);
        $stmt->bind_param("i", $reservationId);
        $stmt->execute();

        $sql2 = "DELETE FROM guests_test WHERE roomcards_group_id= ?";
        $stmt2 = $baglanti->prepare($sql2);
        $stmt2->bind_param("i", $reservationId);
        $stmt2->execute();

        $sql3 = "DELETE FROM reservation_groups_test WHERE id= ?";
        $stmt3 = $baglanti->prepare($sql3);
        $stmt3->bind_param("i", $reservationId);
        $stmt3->execute();


    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    $isXml = strpos($contentType, 'text/xml') !== false || strpos($contentType, 'application/xml') !== false;
    $isForm = strpos($contentType, 'application/x-www-form-urlencoded') !== false || strpos($contentType, 'multipart/form-data') !== false;

    if ($isXml) {
        // XML içerik al
        $rawXml = file_get_contents('php://input');
        $xml = simplexml_load_string($rawXml);
        if ($xml === false) {
            echo "<error>XML parse edilemedi</error>";
            exit;
        }

        // XML'den değerleri al
        $room_count = (int)$xml->room_count_select;
        $rooms = $xml->Room;
    } elseif ($isForm  && !isset($_POST['ajax'])) {
        //header("Content-Type: text/xml; charset=UTF-8");
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
                'Adults' => $_POST["{$i}Adults"], //firstName_lastName_passport; 
                'Childs' => $_POST["{$i}Childs"], //firstName_lastName_age_passport
                'email' => $_POST["email_{$i}"],
                'phoneNumber' => $_POST["phoneNumber_{$i}"],
                'address' => $_POST["address_{$i}"],
                'notes' => $_POST["notes_{$i}"]
            ];
        }
    } else {
        echo "<error>Geçersiz içerik tipi</error>";
        exit;
    }

    //Ortak işlem – XML veya FORM fark etmez
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<MakeBooking>";

    $number = [
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth'
    ];

    $is_group = 1;
    // 1. Önce reservation_groups tablosuna yeni kayıt ekle
    $baglanti->query("INSERT INTO reservation_groups_test () VALUES ()");
    // 2. Insert edilen grup ID'yi al
    $reservation_group_id = $baglanti->insert_id;

    $whichRoom = 1;
    foreach ($rooms as $room) {
        $hotel_id = (int)$room->HotelId;
        $room_type_id = (int)$room->RoomTypeId;
        //$room_type_name = (string)$room->Type;
        $adult_number = (int)$room->AdultNumber;
        $child_number = (int)$room->ChildNumber;
        $start_date = (string)$room->StartDate;
        $end_date = (string)$room->EndDate;
        $start_date = $start_date ? date('Y-m-d', strtotime($start_date)) : null;
        $end_date = $end_date ? date('Y-m-d', strtotime($end_date)) : null;
        $Adults = (string)$room->Adults; //firstName_lastName_passport; 
        $Childs = (string)$room->Childs; //firstName_lastName_age_passport
        $Email = (string)$room->Email;
        $PhoneNumber = (string)$room->PhoneNumber;
        $Address = (string)$room->Address;
        $Notes = (string)$room->Notes;

        // Adultların bilgilerini ayırıyoruz
        $AdultsInfo = [];
        $getInfo_eachAdult = explode(";", $Adults);
        if ($adult_number != count($getInfo_eachAdult)) {
            echo "hatalı Adult girişi yapılmıştır. Lütfen kontrol ediniz";
            reservationIdDelete($reservation_group_id);
        
            echo "<MakeBooking>";
            exit;
        }
        foreach ($getInfo_eachAdult as $i => $adult) {
            $person = explode("_", $adult);
            if (count($person) === 4) {
                $AdultsInfo[$i + 1] = [
                    "gender"   => (string)$person[0],
                    "name"     => (string)$person[1],
                    "lastname" => (string)$person[2],
                    "passport" => (string)$person[3]
                ];
            } else {
                // Hatalı format varsa atla ya da logla
                echo "hatalı giriş yapılmıştır";
                reservationIdDelete($reservation_group_id);
            
                echo "<MakeBooking>";
                exit;
            }
        }

        //Child bilgilerini ayırıyoruz
        $checkError = 0;
        $num_of_child = 0;
        $num_of_infant = 0;
        $ChildsInfo = [];
        $childAges = [];
        $getInfo_eachChild = explode(";", $Childs);
        if ($child_number != count($getInfo_eachChild)) {
            echo "hatalı Child girişi yapılmıştır. Lütfen kontrol ediniz";
            reservationIdDelete($reservation_group_id);
        
            echo "<MakeBooking>";
            exit;
        }
        foreach ($getInfo_eachChild as $i => $child) {
            $person = explode("_", $child);
            if (count($person) === 4) {
                $ChildsInfo[$i + 1] = [
                    "name"     => (string)$person[0],
                    "lastname" => (string)$person[1],
                    "age" => (int)$person[2],
                    "passport" => (string)$person[3]
                ];
                // child ve infant sayılarını alıyoruz
                $age = (int)$person[2];
                if ($age > 1) {
                    $num_of_child++;
                } else {
                    $num_of_infant++;
                }
                $childAges[$i + 1] = $age ?? null;
                //hatalı yaş girişi var mı
                if ($age > 14) {
                    $checkError++;
                }
            } else {
                // Hatalı format varsa atla ya da logla
                echo "hatalı giriş yapılmıştır";
                reservationIdDelete($reservation_group_id);
            
                echo "<MakeBooking>";
                exit;
            }
        }

        //Giriş kontrolleri
        //Yetişkin sayısı kontrol
        if ($adult_number < 1) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect adult number entered</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            echo "<MakeBooking>";
            reservationIdDelete($reservation_group_id);
        
            exit;
        }

        //Çocuk sayısı kontrol
        if ($child_number >= 5 || $child_number < 0) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect child number entered</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            echo "<MakeBooking>";
            reservationIdDelete($reservation_group_id);
        
            exit;
        }

        //Çocuk Yaşı kontrol
        if ($checkError > 0) {
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect child age entered</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            echo "<MakeBooking>";
            reservationIdDelete($reservation_group_id);
        
            exit;
        }

        // Tarih kontrolü
        if (strtotime($start_date) >= strtotime($end_date)) {
            //echo "Hatalı tarih aralığı.";
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect date range</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            echo "<MakeBooking>";
            reservationIdDelete($reservation_group_id);
        
            exit;
        }
        $this_day = date("Y-m-d");
        if (strtotime($start_date) < strtotime(date("Y-m-d"))) {
            // echo "Hatalı tarih girişi";
            echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>Incorrect date entry</message><status>error</status></{$number[$whichRoom]}RoomGetPriceResponse>";
            echo "<MakeBooking>";
            reservationIdDelete($reservation_group_id);
        
            exit;
        }
        // Email kontrolü
        if (!filter_var($Email, FILTER_VALIDATE_EMAIL)) {
            echo "hatalı email girilmiştir";
            reservationIdDelete($reservation_group_id);
        
            echo "<MakeBooking>";
            exit;
        }



        $room_type_name = "";
        //$room_type_name değerini alıyoruz
        $sql = "SELECT room_name FROM room_type_test WHERE hotel_id = ? AND room_type_id = ? AND max_adult = ? AND max_child = ?";
        $stmt = $baglanti->prepare($sql);
        $stmt->bind_param("iiii", $hotel_id, $room_type_id, $adult_number, $child_number);
        $stmt->execute();
        $stmt->bind_result($room_type_name);
        $stmt->fetch();
        $stmt->close();

        //Odanın kayıt ID sini alıyoruz
        $stmt = $baglanti->prepare("SELECT id FROM rooms_test WHERE hotel_id=? AND room_type=? LIMIT 1");
        $stmt->bind_param("ii", $hotel_id, $room_type_id);
        $stmt->execute();
        $stmt->bind_result($room_id);
        $stmt->fetch();
        $stmt->close();

        //Price ve currency alıyoruz
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
                reservationIdDelete($reservation_group_id);
                echo "</MakeBooking>";
                exit;
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
                    reservationIdDelete($reservation_group_id);
                    echo "</MakeBooking>";
                    exit;
                } else if ($rate === null || empty($rate)) {
                    echo "<{$number[$whichRoom]}RoomGetPriceResponse><message>No prices found for these dates</message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                    reservationIdDelete($reservation_group_id);
                    echo "</MakeBooking>";
                    exit;
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
                        reservationIdDelete($reservation_group_id);
                        echo "</MakeBooking>";
                        exit;
                    }

                    $nights = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24);
                    $totalPrice = floatval($nights) * floatval($rate);


                    //$whichRoom++;
                    //continue;
                }
            }
        } else { //Eğer price_policy === 1 ise 


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
                reservationIdDelete($reservation_group_id);
            
                echo "</MakeBooking>";
                exit;
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
                    reservationIdDelete($reservation_group_id);
                
                    echo "</MakeBooking>";
                    exit;
                }

                if ($musaitlikYok) {
                    echo "<{$number[$whichRoom]}RoomGetPriceResponse><message> There are days with no availability on the entered dates. </message><status>success</status></{$number[$whichRoom]}RoomGetPriceResponse>";
                    reservationIdDelete($reservation_group_id);
                
                    echo "</MakeBooking>";
                    exit;
                }

                foreach ($blocks as $b) {

                    $nights = (strtotime($b['end']) - strtotime($b['start'])) / (60 * 60 * 24) + 1;
                    $totalPrice += floatval($nights) * floatval($b["price"]);
                }
            }
        }

        // ODA KAYDI
        $stmt = $baglanti->prepare("INSERT INTO reservations_test (hotel_id,  room_id, reservation_room_type, check_in, check_out, total_price , currency, adults, children, infant, roomcards_group_id, is_group) VALUES (?, ?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iisssdsiiiii", $hotel_id, $room_id, $room_type_name,  $start_date, $end_date, $totalPrice, $currency_name, $adult_number, $num_of_child, $num_of_infant, $reservation_group_id, $is_group);
        if (!$stmt->execute()) {
            echo "oda kaydı yapılamadı ";
            reservationIdDelete($reservation_group_id);
        
            echo "</MakeBooking>";
            exit;
        }
        $reservation_id = $stmt->insert_id; // eklenen oda kaydının ID'si

        if ($is_group === 1) {
            $is_group = null;
        }

        //Adult veritabanına kayıt
        $i = 1;
        foreach ($AdultsInfo as $adult) {
            //echo "$i . kişi : adı: " . $adult["name"] . "--- soyadı: " . $adult["lastname"] . "--- passportnumber: " . $adult["passport"] . " \n";
            $stmt2 = $baglanti->prepare("INSERT INTO guests_test (reservation_id, gender, first_name, last_name, passport_number, phone_number, email, adress, note, roomcards_group_id, is_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt2->bind_param("issssssssii", $reservation_id, $adult['gender'], $adult["name"], $adult["lastname"], $adult["passport"], $PhoneNumber, $Email, $Address, $Notes, $reservation_group_id, $is_group);
            if (!$stmt2->execute()) {
                echo "adult kaydı yapılamadı ";
                reservationIdDelete($reservation_group_id);
            
                echo "</MakeBooking>";
                exit;
            }
            $i++;
        }

        //Child veritabanına kayıt
        $gender_child = "Child";
        $i = 1;
        foreach ($ChildsInfo as $child) {
            //echo "$i . çocuk : adı: " . $child["name"] . "--- soyadı: " . $child["lastname"] . "--- age: " . $child["age"] . "--- passportnumber: " . $child["passport"] . " \n";
            $stmt3 = $baglanti->prepare("INSERT INTO guests_test (reservation_id, gender, first_name, last_name, passport_number, child_age, roomcards_group_id, is_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt3->bind_param("issssiii", $reservation_id, $gender_child, $child["name"], $child["lastname"], $child["passport"], $child["age"], $reservation_group_id, $is_group);
            if (!$stmt3->execute()) {
                echo "child kaydı yapılamadı ";
                reservationIdDelete($reservation_group_id);
            
                echo "</MakeBooking>";
                exit;
            }
            $i++;
        }

        $whichRoom++;
    }
    echo "Kayıt başarıyla tamamlanmıştır";
    echo "</MakeBooking>";
    exit;
}




?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document</title>
</head>

<body>

</body>

</html>
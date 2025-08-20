<?php
include($_SERVER['DOCUMENT_ROOT'] . '/config.php');
include('xml_security.php');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {


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

        $sql = "DELETE FROM test_data WHERE roomcards_group_id= ?";
        $stmt = $baglanti->prepare($sql);
        $stmt->bind_param("i", $reservationId);
        $stmt->execute();

        $sql3 = "DELETE FROM test_data WHERE id= ?";
        $stmt3 = $baglanti->prepare($sql3);
        $stmt3->bind_param("i", $reservationId);
        $stmt3->execute();
    }
    function isValidAdultFormat($person, &$errorMessage = ''): bool
    {
        if (count($person) !== 4) {
            $errorMessage = 'Adult format must contain gender, name, surname and passport.';
            return false;
        }

        [$gender, $name, $surname, $passport] = $person;

        // Cinsiyet kontrolü
        if (!in_array($gender, ['Mr', 'Mrs', 'Ms'])) {
            $errorMessage = 'Gender must be Mr, Mrs, or Ms.';
            return false;
        }

        // Ad: harf + boşluk, min 2 karakter
        if (!preg_match('/^[\p{L} ]+$/u', $name) || mb_strlen(trim($name)) < 2) {
            $errorMessage = 'Please check adult name';
            return false;
        }

        // Soyad: sadece harf, boşluk yok, min 2 karakter
        if (!preg_match('/^[\p{L}]+$/u', $surname) || mb_strlen($surname) < 2) {
            $errorMessage = 'Please check adult surname';
            return false;
        }

        // Passport: alphanumeric, min 10 karakter
        if (!preg_match('/^[a-zA-Z0-9]{10,}$/', $passport)) {
            $errorMessage = 'Adult Passport must be at least 10 alphanumeric characters (letters and numbers only).';
            return false;
        }

        return true;
    }

    function isValidChildFormat($person, &$errorMessage = ''): bool
    {
        if (count($person) !== 4) {
            $errorMessage = 'Child format should include name, surname, age and passport.';
            return false;
        }

        [$name, $surname, $age, $passport] = $person;

        if (!preg_match('/^[\p{L} ]+$/u', $name) || mb_strlen(trim($name)) < 2) {
            $errorMessage = 'Please check Child name';
            return false;
        }

        if (!preg_match('/^[\p{L}]+$/u', $surname) || mb_strlen($surname) < 2) {
            $errorMessage = 'Please check Child surname';
            return false;
        }

        if (!ctype_digit($age) || (int)$age < 0 || (int)$age > 14) {
            $errorMessage = 'Child age must be a number between 0 and 14.';
            return false;
        }

        if (!preg_match('/^[a-zA-Z0-9]{10,}$/', $passport)) {
            $errorMessage = 'Child passport must be at least 10 alphanumeric characters.';
            return false;
        }

        return true;
    }


    headerXml();              
    requireContentTypeXml();  

    // İstek gövdesinden güvenli XML yükle (boyut limiti yok)
    list($xml, $rawXml) = loadXmlFromRequestBodyNoLimit();

    // XML alanlarını al
    $username   = (string)$xml->AgencyUsername;
    $password   = (string)$xml->AgencyPassword;
    $api_key    = (string)$xml->AgencyAPIkey;
    $room_count = (int)$xml->RoomCountSelect;
    $rooms      = $xml->Room;

    // Dosyadan güvenli XML yükleme (önce path doğrula)
    $xmlFilePath    = __DIR__ . '/data/info.xml';
    $allowedBaseDir = realpath(__DIR__ . '/data');
    $realPath       = realpath($xmlFilePath);

    if (!$realPath || strpos($realPath, $allowedBaseDir) !== 0 || !is_readable($realPath)) {
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<MakeBooking><status>error</status><code>XML_FILE_ACCESS_DENIED</code><message>XML file access denied or file not found</message></MakeBooking>";
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


    $whichService = "MakeBookingServiceTest";

    try {
        logXmlAccess($baglanti, $whichService, 'SUCCESS', null, $api_key, $username, $password);
    } catch (Exception $e) {
        logXmlAccess($baglanti, $whichService, 'ERROR', $e->getMessage(), $api_key, $username, $password);
    }

    /****************** */

    //Ortak işlem – XML veya FORM fark etmez
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<MakeBooking>";

    $number = [
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth'
    ];

    if (empty($room_count)) {
        echo "<message>No room_count entered</message><status>error</status>";
        echo "</MakeBooking>";
        exit;
    }
    if ($room_count > 3 || $room_count < 1) {
        echo "<message>Incorrect room_count entered</message><status>error</status>";
        echo "</MakeBooking>";
        exit;
    }
    if ($agency_id == 0) {
        echo "<message>No agency found</message><status>error</status>";
        echo "</MakeBooking>";
        exit;
    }
    if (empty($rooms)) {
        echo "<message>No room entered</message><status>error</status>";
        echo "</MakeBooking>";
        exit;
    }

    $is_group = 1;
    // 1. Önce reservation_groups tablosuna yeni kayıt ekle
    $baglanti->query("INSERT INTO test_data () VALUES ()");
    // 2. Insert edilen grup ID'yi al
    $reservation_group_id = $baglanti->insert_id;

    $whichRoom = 1;
    foreach ($rooms as $room) {

        if ($whichRoom > $room_count) {
            break;
        }

        $hotel_id = (int)$room->HotelId;
        $room_type_id = (int)$room->RoomTypeId;
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

        if (empty($hotel_id) || empty($room_type_id) || empty($adult_number) || empty($child_number) || empty($start_date) || empty($end_date) || empty($Adults) || empty($Childs) || empty($Email) || empty($PhoneNumber) || empty($Address) || empty($Notes)) {
            echo "<message>Check that all information is entered completely.</message><status>error</status>";
            reservationIdDelete($reservation_group_id);
            echo "</MakeBooking>";
            exit;
        }


        // Adultların bilgilerini ayırıyoruz
        $AdultsInfo = [];
        $getInfo_eachAdult = explode(";", $Adults);
        if ($adult_number != count($getInfo_eachAdult) || count($getInfo_eachAdult) > 8) {
            echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Incorrect Adults entry. Please check.</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
            reservationIdDelete($reservation_group_id);
            echo "</MakeBooking>";
            exit;
        }
        $error = "";
        foreach ($getInfo_eachAdult as $i => $adult) {
            $person = explode("_", $adult);
            if (!isValidAdultFormat($person, $error)) {
                echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>$error</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
                reservationIdDelete($reservation_group_id);
                echo "</MakeBooking>";
                exit;
            }

            $AdultsInfo[$i + 1] = [
                "gender"   => (string)$person[0],
                "name"     => (string)$person[1],
                "lastname" => (string)$person[2],
                "passport" => (string)$person[3]
            ];
        }

        //Child bilgilerini ayırıyoruz
        $error = "";
        $num_of_child = 0;
        $num_of_infant = 0;
        $ChildsInfo = [];
        $childAges = [];
        $getInfo_eachChild = explode(";", $Childs);
        if ($child_number != count($getInfo_eachChild)) {
            echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Incorrect Childs entry. Please check.</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
            reservationIdDelete($reservation_group_id);

            echo "</MakeBooking>";
            exit;
        }
        foreach ($getInfo_eachChild as $i => $child) {
            $person = explode("_", $child);
            if (!isValidChildFormat($person, $error)) {
                echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>$error</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
                reservationIdDelete($reservation_group_id);
                echo "</MakeBooking>";
                exit;
            }
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

        }

        //Giriş kontrolleri
        //Yetişkin sayısı kontrol
        if ($adult_number < 1) {
            echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Incorrect adult number entered</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
            echo "</MakeBooking>";
            reservationIdDelete($reservation_group_id);

            exit;
        }

        //Çocuk sayısı kontrol
        if ($child_number >= 5 || $child_number < 0) {
            echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Incorrect child number entered</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
            echo "</MakeBooking>";
            reservationIdDelete($reservation_group_id);

            exit;
        }

        // Tarih kontrolü
        if (strtotime($start_date) >= strtotime($end_date)) {
            //echo "Hatalı tarih aralığı.";
            echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Incorrect date range</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
            echo "</MakeBooking>";
            reservationIdDelete($reservation_group_id);

            exit;
        }
        $this_day = date("Y-m-d");
        if (strtotime($start_date) < strtotime(date("Y-m-d"))) {
            // echo "Hatalı tarih girişi";
            echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Incorrect date entry</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
            echo "</MakeBooking>";
            reservationIdDelete($reservation_group_id);
            exit;
        }
        // Email kontrolü
        if (!empty($Email) && !filter_var($Email, FILTER_VALIDATE_EMAIL)) {
            echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Incorrect Email entry. Please check.</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
            reservationIdDelete($reservation_group_id);
            echo "</MakeBooking>";
            exit;
        }

        $room_type_name = "";
        //$room_type_name değerini alıyoruz
        foreach ($xml_data->room_type_test->Room_type as $room_type) {
            if ((int)$room_type->hotel_id == $hotel_id && (int)$room_type->room_type_id == $room_type_id && (int)$room_type->max_adult == $adult_number && (int)$room_type->max_child == $child_number) {
                $room_type_name = (string)$room_type->room_name;
                break;
            }
        }

        //Odanın kayıt ID sini alıyoruz
        foreach ($xml_data->rooms_test->Rooms as $room) {
            if ((int)$room->hotel_id == $hotel_id && (int)$room->room_type == $room_type_id) {
                $room_id = (int)$room->id;
                break;
            }
        }

        //Otel adını alıyoruz
        $hotel_name = "";
        foreach ($xml_data->hotels_test->Hotels as $hotel) {
            if ((int)$hotel->id == $hotel_id) {
                $hotel_name = (string)$hotel->name;
                break;
            }
        }

        //Odanın adı
        $room_full_name = "";
        foreach ($xml_data->room_type_name_test->Room_type_name as $room_type_name_) {
            if ((int)$room_type_name_->id == $room_type_id) {
                $room_full_name = (string)$room_type_name_->room_type_name;
                break;
            }
        }


        //Price ve currency alıyoruz
        $price_policy = 1;
        $currency_symbol = "";
        $totalPrice = 0.0;
        $flag = 0;

        // Fiyatları veritabanından çek
        if ($price_policy === 1) {
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

                $Currency = [];
                foreach ($xml_data->currency->Currency as $currency) {
                    $Currency[(int)$currency->id] = $currency;
                }
                $Contracts = [];
                foreach ($xml_data->contracts_test->Contracts as $contract) {
                    $Contracts[(string)$contract->hotel_id . "_" . (string)$contract->room_id . "_" . (string)$contract->type] = $contract;
                }
                foreach ($xml_data->amount->Amount as $amount) {
                    if ((int)$amount->hotel_id == $hotel_id && (int)$amount->room_type == $room_type_id && (string)$amount->type == $room_type_name && (string)$amount->date >= $start_date && (string)$amount->date < $end_date) {

                        $contract = $Contracts[(string)$amount->hotel_id . "_" . (string)$amount->room_type . "_" . (string)$amount->type];

                        $currency = $Currency[(int)$contract->currency];
                        $currency_symbol = (string)$currency->currency_symbol;

                        $dates[(string)$amount->date] = floatval((int)$amount->price);
                    }
                }
            } elseif ($child_number > 0) { //Childpolicy den çekiyoruz

                $Currency = [];
                foreach ($xml_data->currency->Currency as $currency) {
                    $Currency[(int)$currency->id] = $currency;
                }
                $Contracts = [];
                foreach ($xml_data->contracts_test->Contracts as $contract) {
                    $Contracts[(string)$contract->hotel_id . "_" . (string)$contract->room_id . "_" . (string)$contract->type] = $contract;
                }
                foreach ($xml_data->childpolicy_test->Childpolicy as $childpolicy) {

                    if ((int)$childpolicy->hotel_id == $hotel_id && (int)$childpolicy->room_id == $room_type_id && (string)$childpolicy->type == $room_type_name && (string)date("Y-m-d", strtotime((string)$childpolicy->date)) >= $start_date && (string)date("Y-m-d", strtotime((string)$childpolicy->date)) < $end_date) {
                        $get_price = 0;
                        //kayıtları sağlıklı bir şekilde alabilmek için yaş kontrolleri (childpolicy için)
                        $child_age_1 = isset($childpolicy->child_age_1) ? (string)$childpolicy->child_age_1 : null;
                        $child_age_2 = isset($childpolicy->child_age_2) ? (string)$childpolicy->child_age_2 : null;
                        $child_age_3 = isset($childpolicy->child_age_3) ? (string)$childpolicy->child_age_3 : null;
                        $child_age_4 = isset($childpolicy->child_age_4) ? (string)$childpolicy->child_age_4 : null;

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

                            $contract = $Contracts[(string)$childpolicy->hotel_id . "_" . (string)$childpolicy->room_id . "_" . (string)$childpolicy->type];
                            $currency = $Currency[(int)$contract->currency];
                            $currency_symbol = (string)$currency->currency_symbol;
                            $currency_name = (string)$currency->currency_name;
                            $dates[(string)$childpolicy->date] = floatval((float)$childpolicy->price);
                        }
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
                        $blocks[] = ['start' => $blockStart, 'end' => date('Y-m-d', strtotime($date . ' -1 day')), 'price' => (float)$currentPrice];
                        $blockStart = null;
                        $currentPrice = null;
                    }
                    $musaitlikYok = true;
                    break; // Devamını kontrol etmiyoruz
                }


                if ($price !== $currentPrice) {
                    // Fiyat değiştiyse blok kapat ve yenisini başlat
                    if ($blockStart !== null) {
                        $blocks[] = ['start' => $blockStart, 'end' => date('Y-m-d', strtotime($date . ' -1 day')), 'price' => (float)$currentPrice];
                    }
                    $blockStart = $date;
                    $currentPrice = floatval($price);
                }
            }

            // Son bloğu kapat
            if ($blockStart !== null) {
                $lastDate = array_key_last($dates);
                $blocks[] = ['start' => $blockStart, 'end' => $lastDate, 'price' => (float)$currentPrice];
            }

            $prices = [];
            //$flag = 0;
            if (empty($blocks)) {
                echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>If your information is correct, there is no availability on the selected dates.</message><status>success</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
                reservationIdDelete($reservation_group_id);

                echo "</MakeBooking>";
                exit;
                //$flag = 1;
            } else {
                //Girilen bilgileri isim olarak alıyoruz
                $Pensions = [];
                foreach ($xml_data->pensions->Pensions as $pension) {
                    $Pensions[(int)$pension->ID] = $pension;
                }
                $Hotels = [];
                foreach ($xml_data->hotels_test->Hotels as $hotel) {
                    $Hotels[(int)$hotel->id] = $hotel;
                }
                $RoomTypeName = [];
                foreach ($xml_data->room_type_name_test->Room_type_name as $room_type_name_) {
                    $RoomTypeName[(int)$room_type_name_->id] = $room_type_name_;
                }
                $found = false;
                foreach ($xml_data->room_type_test->Room_type as $room_type) {
                    if (
                        (int)$room_type->hotel_id == $hotel_id && (int)$room_type->room_type_id == $room_type_id && (string)$room_type->room_name == $room_type_name &&
                        (int)$room_type->min_adult <= $adult_number && (int)$room_type->max_adult >= $adult_number && (int)$room_type->max_child >= $child_number
                    ) {


                        $found = true;
                    }
                }
                if (!$found) {
                    echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message> No record found matching the information entered </message><status>success</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
                    reservationIdDelete($reservation_group_id);

                    echo "</MakeBooking>";
                    exit;
                }

                if ($musaitlikYok) {
                    echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message> There are days with no availability on the entered dates. </message><status>success</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
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
        $stmt = $baglanti->prepare("INSERT INTO test_data (hotel_id,  room_id, reservation_room_type, check_in, check_out, total_price , currency, adults, children, infant,agency_id, roomcards_group_id, is_group) VALUES (?, ?, ?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssdsiiiiii", $hotel_name, $room_full_name, $room_type_name,  $start_date, $end_date, $totalPrice, $currency_name, $adult_number, $num_of_child, $num_of_infant, $agency_id, $reservation_group_id, $is_group);
        if (!$stmt->execute()) {
            echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Room registration failed</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
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
            $stmt2 = $baglanti->prepare("INSERT INTO test_data (reservation_id, gender, first_name, last_name, passport_number, phone_number, email, adress, note, agency_id, roomcards_group_id, is_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt2->bind_param("issssssssiii", $reservation_id, $adult['gender'], $adult["name"], $adult["lastname"], $adult["passport"], $PhoneNumber, $Email, $Address, $Notes, $agency_id, $reservation_group_id, $is_group);
            if (!$stmt2->execute()) {
                echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Adult registration failed</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
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
            $stmt3 = $baglanti->prepare("INSERT INTO test_data (reservation_id, gender, first_name, last_name, passport_number, child_age, agency_id, roomcards_group_id, is_group) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt3->bind_param("issssiiii", $reservation_id, $gender_child, $child["name"], $child["lastname"], $child["passport"], $child["age"], $agency_id, $reservation_group_id, $is_group);
            if (!$stmt3->execute()) {
                echo "<{$number[$whichRoom]}RoomMakeBookingResponse><message>Child registration failed</message><status>error</status></{$number[$whichRoom]}RoomMakeBookingResponse>";
                reservationIdDelete($reservation_group_id);
                echo "</MakeBooking>";
                exit;
            }
            $i++;
        }

        $whichRoom++;
    }
    echo "<reservation_id>{$reservation_group_id}</reservation_id>
        <message>record saved successfully</message>
        <status>success</status>";
    echo "</MakeBooking>";


    // LOG kısmı 
    // Log klasörü yoksa oluştur

    $logDir = __DIR__ . '/logs';
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    // Log dosyası yolu
    $logFile = $logDir . '/MakeBookingRequestsTest.txt';

    // Zaman ve IP bilgisi
    $timestamp = date("Y-m-d H:i:s");
    $ip = $_SERVER['REMOTE_ADDR'];
    $contentType = $_SERVER['CONTENT_TYPE'] ?? 'unknown';

    // Log mesajı
    $logMessage = "[$timestamp] IP: $ip | Content-Type: $contentType || Kullanıcı rezervasyon kaydı sorgusu yaptı." . PHP_EOL;

    // Log dosyasına yaz
    file_put_contents($logFile, $logMessage, FILE_APPEND);
    exit;
}

?>

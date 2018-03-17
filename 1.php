<?php
  const API_KEY = '140c5a4381a41bddbbefa70ae325bc83';
  const FORECAST_URL = 'http://api.openweathermap.org/data/2.5/forecast';

  const DB = [
    'server' => 'localhost:3306',
    'username' => 'vika',
    'password' => '0000',
    'database' => 'weather'
  ];


  function request ($city) {
    if (!is_string($city)) {
      throw new Exception("Bad request");
    }

    $args = [
      'q' => $city,
      'APPID' => API_KEY,
      'units' => 'metric',
      'lang' => 'ru'
    ];

    $result = @file_get_contents(FORECAST_URL.'?'.http_build_query($args));

    if ($result === FALSE) {
      throw new Exception("Not found");
    }

    return json_decode($result, true);
  }


  function transform ($data) {
    $cityInfo = [
      id => $data[city][id],
      name => $data[city][name],
      lat => $data[city][coord][lat],
      lon => $data[city][coord][lon]
    ];

    $weather = [];
    foreach ($data['list'] as $forecast) {
      $weather[] = [
        dt => $forecast[dt_txt],
        temp => $forecast[main][temp],
        pressure => $forecast[main][pressure],
        humidity => $forecast[main][humidity],
        description => $forecast[weather][0][description],
        icon => $forecast[weather][0][icon],
        clouds => $forecast[clouds][all],
        wind_speed => $forecast[wind][speed],
        wind_deg => $forecast[wind][deg]
      ];
    }

    return [$cityInfo, $weather];
  }


  function getForecast ($city) {
    $db = new mysqli(DB['server'], DB['username'], DB['password'], DB['database']);

    if (mysqli_connect_errno()) {
      printf("Подключение невозможно: %s\n", mysqli_connect_error());
      exit();
    }

    $findCity = $db->prepare("
      SELECT *
      FROM city
      WHERE LOWER(name) LIKE LOWER(?)
      LIMIT 1");

    $cityQuery = '%'.$city.'%';
    $findCity->bind_param('s', $cityQuery);
    $findCity->execute();

    $res = $findCity->get_result();
    $isFind = $res->num_rows !== 0;
    
    if ($isFind) {
      $res->data_seek(0);
      $cityInfo = $res->fetch_assoc();

      $findWeather = $db->prepare("
        SELECT dt, temp, pressure, humidity, description, icon, clouds, wind_speed, wind_deg
        FROM weather
        WHERE city_id = ?");

      $findWeather->bind_param('i', $cityInfo[id]);
      $findWeather->execute();
      $res = $findWeather->get_result();

      $weather = [];
      while ($row = $res->fetch_assoc()) {
        $weather[] = $row;
      }

      $findWeather->close();
    } else {
      $weather = request($city);
      list($cityInfo, $weather) = transform($weather);

      $updateCity = $db->prepare("
        INSERT INTO city (id, name, lat, lon)
        VALUES (?, ?, ?, ?)");

      $updateCity->bind_param('isdd', $cityInfo[id], $cityInfo[name], $cityInfo[lat], $cityInfo[lon]);
      $updateCity->execute();
      $updateCity->close();

      $query = "INSERT INTO weather (city_id, dt, temp, pressure, humidity, description, icon, clouds, wind_speed, wind_deg) VALUES";
      foreach ($weather as $forecast) {
        $query .= " ('".$cityInfo[id]."', '".implode("', '", $forecast)."'),";
      }
      $query = trim($query, ",");
      $insertWeather = $db->query($query);
    }

    $findCity->close();

    $db->close();

    return [$cityInfo, $weather, $isFind];
  }


  $hasForecast = false;
  if (isset($_GET['city']) && is_string($_GET['city'])) {
    $hasForecast = true;
    try {
      list($cityInfo, $weather, $isFind) = getForecast($_GET['city']);
    } catch (Exception $e) {
      $weather = "Не найдено";
    }
  }
?>

<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="ie=edge">
  <title>Прогноз погоды</title>
</head>
<body>

  <form action="" method="GET">
    <input type="search" name="city" value="<?php echo $_GET[city]; ?>">
  </form>

  <?php
    if ($hasForecast) {
      print_r($cityInfo);
	  echo "</br>";
      print_r($weather);
	  echo "</br>";
      echo $isFind ? "Из базы данных" : "Из API";
    } else {
      echo "Enter query";
    }
  ?>

</body>
</html>

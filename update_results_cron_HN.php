<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
// Establecer la zona horaria a Managua
date_default_timezone_set('America/Managua');

// Configuración de Azure SQL Server
$azureSqlConfig = [
    'server' => getenv('AZURE_SQL_SERVER') ?: 'tcp:srvdbcacdev.database.windows.net,1433',
    'database' => getenv('AZURE_SQL_DATABASE') ?: 'dblotocacdev',
    'uid' => getenv('AZURE_SQL_USER') ?: 'LotoAdmin@srvdbcacdev',
    'pwd' => getenv('AZURE_SQL_PASSWORD') ?: 'LotAdmin1.',
    'Encrypt' => true,
    'TrustServerCertificate' => false,
    'CharacterSet' => 'UTF-8'
];

function getSqlConnection() {
    global $azureSqlConfig;

    $connectionInfo = [
        'Database' => $azureSqlConfig['database'],
        'Uid' => $azureSqlConfig['uid'],
        'PWD' => $azureSqlConfig['pwd'],
        'Encrypt' => $azureSqlConfig['Encrypt'],
        'TrustServerCertificate' => $azureSqlConfig['TrustServerCertificate'],
        'CharacterSet' => $azureSqlConfig['CharacterSet']
    ];

    $conn = sqlsrv_connect($azureSqlConfig['server'], $connectionInfo);
    if ($conn === false) {
        throw new Exception('Error al conectar a Azure SQL: ' . print_r(sqlsrv_errors(), true));
    }

    return $conn;
}

// Obtener la hora actual
$hora_actual = date('H:i');

// Definir las horas límite (en formato 24 horas)
$hora_inicio = '11:00';
$hora_fin = '23:00';

// Convertir las horas a formato strtotime para comparar
$inicio = strtotime($hora_inicio);
$fin = strtotime($hora_fin);
$actual = strtotime($hora_actual);

function getLastResults(){
    $games = [
        "Diaria +1"=>[
            "id"=>1,
            "results"=>[0,0],
            "Game"=>0
          ],
        "Jugá Tres"=>[
            "id"=>13,
            "results"=>[0,0,0],
            "Game"=>0
          ],
        "Premia2"=>[
            "id"=>4,
            "results"=>[0,0],
            "Game"=>0
          ],
          "Super Premio"=>[
            "id"=>2,
            "results"=>[0,0,0,0,0,0],
            "Game"=>0
          ],
          "Multi-X-LD"=>[
            "id"=>12,
            "results"=>[0,0],
            "Game"=>0
          ],
          "Pega3"=>[
            "id"=>3,
            "results"=>[0,0,0],
            "Game"=>0
          ],
          "Bingo Con Todo"=>[
            "id"=>15,
            "results"=>[0,0,0,0,0,0,0],
            "Game"=>0
          ]
    ];
    $data="fail";
    $resp = "true";
    try {
        //URL, Where the JSON data is going to be sent
        // sending post request to reqres.in
        $url = "https://gamesdata.loto.hn";
        //initialize CURL
        $ch = curl_init();
        //options for curl
        $array_options = array(
            //set the url option
            CURLOPT_URL=>$url,
            //instead of outputting it 
            CURLOPT_RETURNTRANSFER=>true,
            //Using the CURLOPT_HTTPHEADER set the Content-Type to application/json
            CURLOPT_HTTPHEADER=>array('Content-Type:application/json')
        );
        //setting multiple options using curl_setopt_array
        curl_setopt_array($ch,$array_options);
        // using curl_exec() is used to execute the POST request
        $prev = curl_exec($ch);
        $resp = json_decode($prev,true);
        foreach ($games as $key => $value) {
            if($key=="Jugá Tres"){
                $games[$key]["results"] = str_split($resp[$key]["Last two draws"]["draws"]["Last Draw"]["result"][0]);
            }elseif($key=="Diaria +1"){
                $string = $resp[$key]["Last two draws"]["draws"]["Last Draw"]["result"];
                // Encuentra la posición del primer número
                $firstNumber = substr($string, 0, 2);
                $Slength = strlen($string);
                $lastNumber = substr($string, $Slength -2 , $Slength-1);
                // Crea el arreglo con los valores extraídos
                $result = array(intval($firstNumber), intval($lastNumber));

                $games[$key]["results"] =  $result;

            }
            else{
                $games[$key]["results"] = $resp[$key]["Last two draws"]["draws"]["Last Draw"]["result"];
            }
            $games[$key]["Game"] = $resp[$key]["Last two draws"]["draws"]["Last Draw"]["drawnumber"];
        }
        //close the cURL and load the page
        curl_close($ch);
        $resp = "success";
    } catch (Exception $e) {
        echo 'Excepción capturada: ',  $e->getMessage(), "\n";
        $resp = "error";
    }
    

    return [$resp, $games];
}

function insertResults($juegoID,$json_obj,$numeroNuevoSorteo){
    $conn = getSqlConnection();

    $sqlMax = "SELECT MAX(sorteo) AS numSorteo FROM loto_sorteos_HN WHERE juego = ?";
    $params = [$juegoID];
    $stmt = sqlsrv_query($conn, $sqlMax, $params);

    if ($stmt === false) {
        throw new Exception('Error en consulta SELECT: ' . print_r(sqlsrv_errors(), true));
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
    $ultimoNumeroSorteo = isset($row['numSorteo']) ? intval($row['numSorteo']) : 0;

    $parValues = [];
    for ($i = 0; $i < 7; $i++) {
        $parValues[] = isset($json_obj[$i]) ? trim($json_obj[$i]) : null;
    }

    if ($ultimoNumeroSorteo < $numeroNuevoSorteo) {
        $fechaInt = intval(date('Ymd'));
        $fecha = date('Y-m-d H:i:s');
        $hora = date('H:00:00');

        $sql = "INSERT INTO loto_sorteos_HN (juego, fechaInt, fecha, hora, sorteo, par1, par2, par3, par4, par5, par6, par7)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = array_merge([$juegoID, $fechaInt, $fecha, $hora, $numeroNuevoSorteo], $parValues);
        $stmt = sqlsrv_query($conn, $sql, $params);

        if ($stmt === false) {
            throw new Exception('Error en INSERT: ' . print_r(sqlsrv_errors(), true));
        }

        sqlsrv_free_stmt($stmt);

        if (function_exists('w3tc_pgcache_flush_post')) {
            w3tc_pgcache_flush_post(441);
        }
    } else {
        echo "no requerida\n";
        sqlsrv_close($conn);
        return;
    }

    sqlsrv_close($conn);
    echo "$juegoID";
}


// Verificar si la hora actual está entre las horas límite
if ($actual >= $inicio && $actual <= $fin) {
    try {
        list($response, $data)  = getLastResults();
        if($response=="success"){
            foreach ($data as $key => $value) {
                insertResults($value["id"],$value["results"],$value["Game"]);
            }
            echo "success";
        }
    } catch (Exception $e) {
        echo 'Excepción capturada: ',  $e->getMessage(), "\n";
        $data = "error";
    }    
} else {
    // Fuera del rango de tiempo permitido
    echo "El proceso no debe ejecutarse en este momento.";
}

//echo json_encode($games);
?>
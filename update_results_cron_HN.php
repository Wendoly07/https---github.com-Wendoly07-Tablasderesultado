<?php
header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('America/Managua');

// ─── Configuración Azure SQL ───────────────────────────────────────────────
$azureSqlConfig = [
    'server'               => getenv('DB_SERVER'),
    'database'             => getenv('DB_NAME'),
    'uid'                  => getenv('DB_USER'),
    'pwd'                  => getenv('DB_PASSWORD'),
    'Encrypt'              => true,
    'TrustServerCertificate' => false,
    'CharacterSet'         => 'UTF-8'
];

function getSqlConnection() {
    global $azureSqlConfig;
    $conn = sqlsrv_connect($azureSqlConfig['server'], [
        'Database'               => $azureSqlConfig['database'],
        'Uid'                    => $azureSqlConfig['uid'],
        'PWD'                    => $azureSqlConfig['pwd'],
        'Encrypt'                => $azureSqlConfig['Encrypt'],
        'TrustServerCertificate' => $azureSqlConfig['TrustServerCertificate'],
        'CharacterSet'           => $azureSqlConfig['CharacterSet']
    ]);
    if ($conn === false) {
        throw new Exception('Error SQL connection: ' . print_r(sqlsrv_errors(), true));
    }
    return $conn;
}

// ─── Fetch JSON desde gamesdata.loto.hn ───────────────────────────────────
function fetchGamesData(): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://gamesdata.loto.hn',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err) {
        throw new Exception("cURL error: $err");
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new Exception('JSON inválido recibido de gamesdata.loto.hn');
    }

    return $data;
}

// ─── Parsear result según tipo de juego ───────────────────────────────────
//
//  Retorna: [ result_raw (string), pares (array hasta 7 elementos) ]
//
function parseResult(string $gameName, $rawResult): array {

    // ── Diaria +1: string  "43 \"+1\":    5 "
    if ($gameName === 'Diaria +1') {
        preg_match('/^\s*(\d+)/', $rawResult, $m1);
        preg_match('/\"[^\"]+\"\s*:\s*(\d+)/', $rawResult, $m2);
        $par1 = $m1[1] ?? '';
        $par2 = $m2[1] ?? '';
        return ["$par1-$par2", array_filter([$par1, $par2], fn($v) => $v !== '')];
    }

    // A partir de aquí result siempre es array
    if (!is_array($rawResult)) {
        $rawResult = [$rawResult];
    }

    // ── Jugá Tres: ["596"] → 5, 9, 6
    if ($gameName === 'Jugá Tres') {
        $digits = str_split($rawResult[0] ?? '');
        return [implode('-', $digits), $digits];
    }

    // ── Juegos con resultado compacto de 6 dígitos separados en pares
    //    TE HACE FALTA VIAJE: "098026" → 09, 80, 26
    //    Dobleteá tu Suerte:  "389472" → 38, 94, 72
    //    Ribete:              "496199" → 49, 61, 99
    $compactPairGames = ['TE HACE FALTA VIAJE', 'Dobleteá tu Suerte', 'Ribete'];
    if (in_array($gameName, $compactPairGames)) {
        $str  = $rawResult[0] ?? '';
        $pares = str_split($str, 2);
        return [implode('-', $pares), $pares];
    }

    // ── NAVIDAD PA GANAR / Tu Carro Soñado: resultado masivo → solo result_raw
    $rawOnlyGames = ['NAVIDAD PA GANAR', 'Tu Carro Soñado'];
    if (in_array($gameName, $rawOnlyGames)) {
        $raw = implode('-', $rawResult);
        return [$raw, []]; // sin pares individuales
    }

    // ── Caso general: array normal ["09","38","70"], ["01","09","13","14","27","31"], etc.
    $pares = array_values($rawResult);
    return [implode('-', $pares), $pares];
}

// ─── Insertar un sorteo en la tabla ───────────────────────────────────────
function insertDraw(
    $conn,
    string $gameName,
    string $drawNumber,
    int    $timestampMs,
    string $drawTime,      // clave horaria del JSON, ej. "08:58"
    $rawResult,
    ?float $jackpot
): void {

    // Deduplicación: ya existe este draw_number para este juego?
    $stmtChk = sqlsrv_query(
        $conn,
        "SELECT COUNT(*) AS total
           FROM numeros_ganadores_sorteos_prod_prueba
          WHERE pais = 'HN' AND game_name = ? AND draw_number = ?",
        [$gameName, $drawNumber]
    );
    if ($stmtChk === false) {
        throw new Exception('Error en SELECT: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmtChk, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtChk);

    if (intval($row['total']) > 0) {
        echo "  [SKIP] $gameName #$drawNumber ya existe\n";
        return;
    }

    // Convertir timestamp (ms) a valores de fecha
    $tsSeconds = intval($timestampMs / 1000);
    $drawDate  = date('Y-m-d H:i:s', $tsSeconds);   // draw_date  datetime2
    $dayOfWeek = date('l', $tsSeconds);              // Monday, Tuesday...

    // draw_time: usar la clave horaria del JSON si es válida, si no usar timestamp
    $drawTimeFinal = preg_match('/^\d{2}:\d{2}$/', $drawTime)
        ? $drawTime . ':00'
        : date('H:i:s', $tsSeconds);

    [$resultRaw, $pares] = parseResult($gameName, $rawResult);

    // Rellenar par1..par7 (null si no aplica)
    $parValues = [];
    for ($i = 0; $i < 7; $i++) {
        $parValues[] = isset($pares[$i]) ? trim(strval($pares[$i])) : null;
    }

    $sql = "INSERT INTO numeros_ganadores_sorteos_prod_prueba
                (pais, game_name, draw_number, draw_date, result_raw,
                 jackpot, day_of_week, draw_time, source_section,
                 par1, par2, par3, par4, par5, par6, par7)
            VALUES
                (?, ?, ?, ?, ?,
                 ?, ?, ?, ?,
                 ?, ?, ?, ?, ?, ?, ?)";

    $params = array_merge(
        [
            'HN',
            $gameName,
            $drawNumber,
            $drawDate,
            $resultRaw,
            $jackpot,
            $dayOfWeek,
            $drawTimeFinal,
            'gamesdata.loto.hn',
        ],
        $parValues
    );

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception("Error INSERT $gameName #$drawNumber: " . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    echo "  [OK]   $gameName #$drawNumber ($dayOfWeek $drawTimeFinal) → $resultRaw\n";
}

// ─── Procesar todos los juegos → Whole week ────────────────────────────────
function processAllGames(array $gamesData): void {
    $conn = getSqlConnection();

    $totalInserted = 0;
    $totalSkipped  = 0;

    foreach ($gamesData as $gameKey => $gameInfo) {

        $wholeWeek = $gameInfo['Whole week'] ?? null;
        if (!$wholeWeek) continue;

        $gameName = $wholeWeek['gamename'] ?? $gameKey;
        $draws    = $wholeWeek['draws']    ?? [];

        echo "\n[JUEGO] $gameName\n";

        // draws es: { "Monday": { "08:58": { result, drawnumber, date, jackpot? } }, ... }
        foreach ($draws as $dayName => $timeslots) {
            if (empty($timeslots) || !is_array($timeslots)) continue;

            foreach ($timeslots as $timeKey => $drawData) {
                if (empty($drawData) || !is_array($drawData)) continue;

                $drawNumber = strval($drawData['drawnumber'] ?? '');
                $timestampMs = intval($drawData['date'] ?? 0);
                $rawResult  = $drawData['result'] ?? null;
                $jackpot    = isset($drawData['jackpot']) ? floatval($drawData['jackpot']) : null;

                if ($drawNumber === '' || $rawResult === null || $timestampMs === 0) continue;

                insertDraw($conn, $gameName, $drawNumber, $timestampMs, $timeKey, $rawResult, $jackpot);
            }
        }
    }

    sqlsrv_close($conn);
}

// ─── Ejecución principal ───────────────────────────────────────────────────
$hora_actual = date('H:i');
$actual      = strtotime($hora_actual);
$inicio      = strtotime('11:00');
$fin         = strtotime('23:00');

if ($actual >= $inicio && $actual <= $fin) {
    try {
        echo "=== Inicio cron " . date('Y-m-d H:i:s') . " ===\n";
        $gamesData = fetchGamesData();
        processAllGames($gamesData);
        echo "\n=== Proceso completado ===\n";
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        http_response_code(500);
    }
} else {
    echo "Fuera del horario permitido (11:00 - 23:00). Hora actual: $hora_actual\n";
}
<?php

date_default_timezone_set('America/Managua');

// ─── Configuración Azure SQL ───────────────────────────────────────────────
$azureSqlConfig = [
    'server'                 => getenv('DB_SERVER'),
    'database'               => getenv('DB_NAME'),
    'uid'                    => getenv('DB_USER'),
    'pwd'                    => getenv('DB_PASSWORD'),
    'Encrypt'                => true,
    'TrustServerCertificate' => false,
    'CharacterSet'           => 'UTF-8'
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
function parseResult(string $gameName, $rawResult): array {

    // ── Diaria +1: string "43 \"+1\":    5 "
    if ($gameName === 'Diaria +1') {
        preg_match('/^\s*(\d+)/', $rawResult, $m1);
        preg_match('/\"[^\"]+\"\s*:\s*(\d+)/', $rawResult, $m2);
        $par1 = $m1[1] ?? '';
        $par2 = $m2[1] ?? '';
        return ["$par1-$par2", array_filter([$par1, $par2], fn($v) => $v !== '')];
    }

    if (!is_array($rawResult)) {
        $rawResult = [$rawResult];
    }

    // ── Jugá Tres: ["417"] → par1 = 417 completo
    if ($gameName === 'Jugá Tres') {
        $val = $rawResult[0] ?? '';
        return [$val, [$val]];
    }

    // ── Compactos de 6 dígitos en pares
    $compactPairGames = ['TE HACE FALTA VIAJE', 'Dobleteá tu Suerte', 'Ribete'];
    if (in_array($gameName, $compactPairGames)) {
        $str   = $rawResult[0] ?? '';
        $pares = str_split($str, 2);
        return [implode('-', $pares), $pares];
    }

    // ── Resultado masivo → solo result_raw
    $rawOnlyGames = ['NAVIDAD PA GANAR', 'Tu Carro Soñado'];
    if (in_array($gameName, $rawOnlyGames)) {
        return [implode('-', $rawResult), []];
    }

    // ── Caso general
    $pares = array_values($rawResult);
    return [implode('-', $pares), $pares];
}

// ─── Insertar un sorteo ────────────────────────────────────────────────────
function insertDraw(
    $conn,
    string $gameName,
    string $drawNumber,
    int    $timestampMs,
    string $drawTime,
    $rawResult,
    ?float $jackpot
): void {

    // Deduplicación
    $stmtChk = sqlsrv_query(
        $conn,
        "SELECT COUNT(*) AS total
           FROM numeros_ganadores_sorteos_prod
          WHERE pais = 'Honduras' AND game_name = ? AND draw_number = ?",
        [$gameName, $drawNumber]
    );
    if ($stmtChk === false) {
        throw new Exception('Error en SELECT: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmtChk, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtChk);

    if (intval($row['total']) > 0) {
        return; // ya existe, silencioso
    }

    $tsSeconds     = intval($timestampMs / 1000);
    $drawDate      = date('Y-m-d H:i:s', $tsSeconds);
    $dayOfWeek     = date('l', $tsSeconds);
    // Usar siempre el timestamp para draw_time (las claves del JSON no son confiables)
    $drawTimeFinal = date('H:i:s', $tsSeconds);

    [$resultRaw, $pares] = parseResult($gameName, $rawResult);

    $parValues = [];
    for ($i = 0; $i < 7; $i++) {
        $parValues[] = isset($pares[$i]) ? trim(strval($pares[$i])) : null;
    }

    $sql = "INSERT INTO numeros_ganadores_sorteos_prod
                (pais, game_name, draw_number, draw_date, result_raw,
                 jackpot, day_of_week, draw_time, source_section,
                 par1, par2, par3, par4, par5, par6, par7)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = array_merge(
        ['Honduras', $gameName, $drawNumber, $drawDate, $resultRaw,
         $jackpot, $dayOfWeek, $drawTimeFinal, 'gamesdata.loto.hn'],
        $parValues
    );

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception("Error INSERT $gameName #$drawNumber: " . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    echo "[NUEVO] $gameName #$drawNumber ($dayOfWeek $drawTimeFinal) → $resultRaw\n";
}

// ─── Procesar sorteos de un bloque ────────────────────────────────────────
// Soporta 3 estructuras:
//   Today/Yesterday: { "08:58": {drawnumber,...}, "02:58": {...} }
//   Last two draws:  { "Last Draw": {drawnumber,...}, "Penultimate": {...} }
//   Last week:       { "Monday": { "08:58": {drawnumber,...} }, "Tuesday": {...} }
function processBlock(array $draws, string $gameName, $conn): void {
    foreach ($draws as $key => $drawData) {
        if (empty($drawData) || !is_array($drawData)) continue;

        // Caso directo: el valor tiene drawnumber (Today/Yesterday/Last two draws)
        if (isset($drawData['drawnumber'])) {
            $drawNumber  = strval($drawData['drawnumber']);
            $timestampMs = intval($drawData['date'] ?? 0);
            $rawResult   = $drawData['result'] ?? null;
            $jackpot     = isset($drawData['jackpot']) ? floatval($drawData['jackpot']) : null;

            if ($drawNumber === '' || $rawResult === null || $timestampMs === 0) continue;
            // No insertar si result es null o vacío (Next Draw / sorteo futuro)
            if (is_array($rawResult) && (empty($rawResult) || $rawResult[0] === null || $rawResult[0] === '')) continue;

            // No insertar sorteos futuros
            $tsSorteo = ($timestampMs > 9999999999) ? intval($timestampMs / 1000) : $timestampMs;
            if ($tsSorteo > time() + 300) continue;
            insertDraw($conn, $gameName, $drawNumber, $timestampMs, $key, $rawResult, $jackpot);

        } else {
            // Caso Last week: el valor es un sub-array de timeslots { "08:58": {drawnumber,...} }
            foreach ($drawData as $timeKey => $timeDraw) {
                if (empty($timeDraw) || !is_array($timeDraw) || !isset($timeDraw['drawnumber'])) continue;

                $drawNumber  = strval($timeDraw['drawnumber']);
                $timestampMs = intval($timeDraw['date'] ?? 0);
                $rawResult   = $timeDraw['result'] ?? null;
                $jackpot     = isset($timeDraw['jackpot']) ? floatval($timeDraw['jackpot']) : null;

                if ($drawNumber === '' || $rawResult === null || $timestampMs === 0) continue;
                // No insertar si result es null o vacío (Next Draw / sorteo futuro)
                if (is_array($rawResult) && (empty($rawResult) || $rawResult[0] === null || $rawResult[0] === '')) continue;

                // No insertar sorteos futuros
                $tsSorteo = ($timestampMs > 9999999999) ? intval($timestampMs / 1000) : $timestampMs;
                if ($tsSorteo > time() + 300) continue;
                insertDraw($conn, $gameName, $drawNumber, $timestampMs, $timeKey, $rawResult, $jackpot);
            }
        }
    }
}

// ─── Procesar todos los juegos → Today + Yesterday + Last two draws ────────
function processAllGames(array $gamesData): void {
    $conn     = getSqlConnection();
    $sections = ['Today Draws', 'Yesterday Draws', 'Last week', 'Last two draws'];

    foreach ($gamesData as $gameKey => $gameInfo) {
        $gameName = null;
        foreach ($sections as $sec) {
            if (isset($gameInfo[$sec]['gamename'])) { $gameName = $gameInfo[$sec]['gamename']; break; }
        }
        if (!$gameName) $gameName = $gameKey;

        foreach ($sections as $sec) {
            $draws = $gameInfo[$sec]['draws'] ?? [];
            if (empty($draws) || !is_array($draws)) continue;
            processBlock($draws, $gameName, $conn);
        }
    }

    sqlsrv_close($conn);
}


// ─── Ejecución principal ───────────────────────────────────────────────────
// Solo corre entre 11:00 y 23:00 hora Managua
try {
    echo "=== Revisión " . date('Y-m-d H:i:s') . " ===\n";
    $gamesData = fetchGamesData();
    processAllGames($gamesData);
    echo "=== Fin ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    http_response_code(500);
}
<?php
header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: *');

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

// ─── Fetch JSON desde gamesdata.loto.com.ni ───────────────────────────────
function fetchGamesData(): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://gamesdata.loto.com.ni',
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
        throw new Exception('JSON inválido recibido de gamesdata.loto.com.ni');
    }
    return $data;
}

// ─── Parsear result según tipo de juego ───────────────────────────────────
function parseResult(string $gameName, $rawResult): array {
    if (!is_array($rawResult)) {
        $rawResult = [$rawResult];
    }

    // Filtrar valores vacíos
    $filtered = array_filter($rawResult, fn($v) => $v !== '' && $v !== null);
    if (empty($filtered)) return ['', []];

    // Juga 3: string compacto ["053"] → 0, 5, 3
    if ($gameName === 'Juga 3') {
        $digits = str_split($rawResult[0] ?? '');
        return [implode('-', $digits), $digits];
    }

    // Caso general: array normal
    $pares = array_values($rawResult);
    return [implode('-', $pares), $pares];
}

// ─── Insertar un sorteo ────────────────────────────────────────────────────
function insertDraw(
    $conn,
    string $gameName,
    string $drawNumber,
    int    $timestampSec,  // Nicaragua usa segundos, no milisegundos
    string $drawTime,
    $rawResult,
    ?float $jackpot
): void {

    // Validar que haya resultado real
    if (!is_array($rawResult)) $rawResult = [$rawResult];
    $hasData = array_filter($rawResult, fn($v) => $v !== '' && $v !== null);
    if (empty($hasData)) return;

    // Deduplicación
    $stmtChk = sqlsrv_query(
        $conn,
        "SELECT COUNT(*) AS total
           FROM numeros_ganadores_sorteos_prod
          WHERE pais = 'Nicaragua' AND game_name = ? AND draw_number = ?",
        [$gameName, $drawNumber]
    );
    if ($stmtChk === false) {
        throw new Exception('Error en SELECT: ' . print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmtChk, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmtChk);

    if (intval($row['total']) > 0) return; // ya existe

    $drawDate      = date('Y-m-d H:i:s', $timestampSec);
    $dayOfWeek     = date('l', $timestampSec);
    $drawTimeFinal = preg_match('/^\d{2}:\d{2}$/', $drawTime)
        ? $drawTime . ':00'
        : date('H:i:s', $timestampSec);

    [$resultRaw, $pares] = parseResult($gameName, $rawResult);
    if ($resultRaw === '') return;

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
        ['Nicaragua', $gameName, $drawNumber, $drawDate, $resultRaw,
         $jackpot, $dayOfWeek, $drawTimeFinal, 'gamesdata.loto.com.ni'],
        $parValues
    );

    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new Exception("Error INSERT $gameName #$drawNumber: " . print_r(sqlsrv_errors(), true));
    }
    sqlsrv_free_stmt($stmt);

    echo "[NUEVO] $gameName #$drawNumber ($dayOfWeek $drawTimeFinal) → $resultRaw\n";
}

// ─── Procesar un bloque de sorteos ────────────────────────────────────────
// Soporta:
//   Today/Yesterday: { "11:58": {drawnumber,...}, "14:58": {...} }
//   Last two draws:  { "Last Draw": {drawnumber,...}, "Penultimate": {...} }
//   Last week:       { "Monday": { "11:58": {drawnumber,...} }, ... }
//   Whole week NI:   igual que Last week pero con datos vacíos (se filtran)
function processBlock(array $draws, string $gameName, $conn): void {
    foreach ($draws as $key => $drawData) {
        if (empty($drawData) || !is_array($drawData)) continue;

        if (isset($drawData['drawnumber']) && $drawData['drawnumber'] !== '') {
            // Estructura plana (Today/Yesterday/Last two draws)
            $drawNumber  = strval($drawData['drawnumber']);
            $timestampSec = intval($drawData['date'] ?? 0);
            $rawResult   = $drawData['result'] ?? null;
            $jackpot     = isset($drawData['jackpot']) && $drawData['jackpot'] > 0
                           ? floatval($drawData['jackpot']) : null;

            if ($drawNumber === '' || $rawResult === null || $timestampSec === 0) continue;
            insertDraw($conn, $gameName, $drawNumber, $timestampSec, $key, $rawResult, $jackpot);

        } else {
            // Estructura anidada (Last week): { "Monday": { "11:58": {...} } }
            foreach ($drawData as $timeKey => $timeDraw) {
                if (empty($timeDraw) || !is_array($timeDraw)) continue;
                if (!isset($timeDraw['drawnumber']) || $timeDraw['drawnumber'] === '') continue;

                $drawNumber   = strval($timeDraw['drawnumber']);
                $timestampSec = intval($timeDraw['date'] ?? 0);
                $rawResult    = $timeDraw['result'] ?? null;
                $jackpot      = isset($timeDraw['jackpot']) && $timeDraw['jackpot'] > 0
                                ? floatval($timeDraw['jackpot']) : null;

                if ($drawNumber === '' || $rawResult === null || $timestampSec === 0) continue;
                insertDraw($conn, $gameName, $drawNumber, $timestampSec, $timeKey, $rawResult, $jackpot);
            }
        }
    }
}

// ─── Procesar todos los juegos ────────────────────────────────────────────
function processAllGames(array $gamesData): void {
    $conn     = getSqlConnection();
    $sections = ['Today Draws', 'Yesterday Draws', 'Last week', 'Last two draws'];

    foreach ($gamesData as $gameKey => $gameInfo) {
        $gameName = null;
        foreach ($sections as $sec) {
            if (isset($gameInfo[$sec]['gamename'])) {
                $gameName = $gameInfo[$sec]['gamename'];
                break;
            }
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
try {
    echo "=== Revisión NI " . date('Y-m-d H:i:s') . " ===\n";
    $gamesData = fetchGamesData();
    processAllGames($gamesData);
    echo "=== Fin ===\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    http_response_code(500);
}
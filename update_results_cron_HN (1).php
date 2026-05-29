<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('America/Managua');

// ─── Conexión con PDO ──────────────────────────────────────────────────────
function getSqlConnection() {
    try {
        $conn = new PDO(
            "sqlsrv:Server=" . getenv('DB_SERVER') . ";Database=" . getenv('DB_NAME'),
            getenv('DB_USER'),
            getenv('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        return $conn;
    } catch (PDOException $e) {
        throw new Exception('Error al conectar: ' . $e->getMessage());
    }
}

// ─── Parsear resultados según el juego ────────────────────────────────────
function parseResult($gameName, $result) {
    $resultRaw = is_array($result) ? implode('-', $result) : $result;
    $pars = [null, null, null, null, null, null, null];

    if ($gameName === 'Diaria +1') {
        preg_match('/^(\d+)/', trim($result), $m1);
        preg_match('/\"?\+1\"?\s*:\s*(\d+)/', $result, $m2);
        $pars[0] = isset($m1[1]) ? $m1[1] : null;
        $pars[1] = isset($m2[1]) ? $m2[1] : null;

    } elseif ($gameName === 'Jugá Tres') {
        $str = is_array($result) ? $result[0] : $result;
        $digits = str_split($str);
        foreach ($digits as $i => $d) {
            if ($i < 7) $pars[$i] = $d;
        }

    } else {
        if (is_array($result)) {
            foreach ($result as $i => $val) {
                if ($i < 7) $pars[$i] = trim($val);
            }
        } else {
            $pars[0] = trim($result);
        }
    }

    return [$resultRaw, $pars];
}

// ─── Obtener sorteos de la API ─────────────────────────────────────────────
function getAllDraws() {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://gamesdata.loto.hn',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new Exception('cURL error: ' . $err);

    $data = json_decode($raw, true);
    if ($data === null) throw new Exception('JSON inválido de la API');

    $draws    = [];
    $daysOrder = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
    $dayNames  = [
        'Monday'    => 'Lunes',
        'Tuesday'   => 'Martes',
        'Wednesday' => 'Miércoles',
        'Thursday'  => 'Jueves',
        'Friday'    => 'Viernes',
        'Saturday'  => 'Sábado',
        'Sunday'    => 'Domingo'
    ];

    foreach ($data as $gameName => $sections) {
        if (!isset($sections['Whole week']['draws'])) continue;

        $weekDraws = $sections['Whole week']['draws'];

        foreach ($daysOrder as $day) {
            if (!isset($weekDraws[$day]) || !is_array($weekDraws[$day]) || empty($weekDraws[$day])) continue;

            foreach ($weekDraws[$day] as $time => $drawData) {
                if (!isset($drawData['drawnumber'], $drawData['result'])) continue;
                if ($drawData['result'] === null) continue;

                [$resultRaw, $pars] = parseResult($gameName, $drawData['result']);

                $timestampSec = intval($drawData['date'] / 1000);

                $draws[] = [
                    'pais'           => 'HN',
                    'game_name'      => $gameName,
                    'draw_number'    => strval($drawData['drawnumber']),
                    'draw_date'      => date('Y-m-d H:i:s', $timestampSec),
                    'result_raw'     => $resultRaw,
                    'jackpot'        => isset($drawData['jackpot']) ? floatval($drawData['jackpot']) : null,
                    'day_of_week'    => $dayNames[$day] ?? $day,
                    'draw_time'      => date('H:i:s', $timestampSec),
                    'source_section' => 'Whole week',
                    'par1'           => $pars[0],
                    'par2'           => $pars[1],
                    'par3'           => $pars[2],
                    'par4'           => $pars[3],
                    'par5'           => $pars[4],
                    'par6'           => $pars[5],
                    'par7'           => $pars[6],
                ];
            }
        }
    }

    return $draws;
}

// ─── Insertar si no existe ─────────────────────────────────────────────────
function insertDraw($conn, $draw) {
    // Verificar duplicado
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM numeros_ganadores_sorteos_prod_prueba 
        WHERE game_name = ? AND draw_number = ?
    ");
    $stmt->execute([$draw['game_name'], $draw['draw_number']]);

    if ($stmt->fetchColumn() > 0) return false;

    // Insertar
    $stmt = $conn->prepare("
        INSERT INTO numeros_ganadores_sorteos_prod_prueba 
            (pais, game_name, draw_number, draw_date, result_raw, jackpot,
             day_of_week, draw_time, source_section,
             par1, par2, par3, par4, par5, par6, par7)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $draw['pais'],
        $draw['game_name'],
        $draw['draw_number'],
        $draw['draw_date'],
        $draw['result_raw'],
        $draw['jackpot'],
        $draw['day_of_week'],
        $draw['draw_time'],
        $draw['source_section'],
        $draw['par1'],
        $draw['par2'],
        $draw['par3'],
        $draw['par4'],
        $draw['par5'],
        $draw['par6'],
        $draw['par7'],
    ]);

    return true;
}

// ─── Main ──────────────────────────────────────────────────────────────────
$hora_actual = date('H:i');
$hora_inicio = '11:00';
$hora_fin    = '23:00';

if ($hora_actual >= $hora_inicio && $hora_actual <= $hora_fin) {
    $conn = null;
    try {
        $draws    = getAllDraws();
        $conn     = getSqlConnection();
        $inserted = 0;
        $skipped  = 0;

        foreach ($draws as $draw) {
            if (insertDraw($conn, $draw)) {
                $inserted++;
            } else {
                $skipped++;
            }
        }

        echo json_encode([
            'status'   => 'success',
            'inserted' => $inserted,
            'skipped'  => $skipped,
            'total'    => count($draws)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    } finally {
        $conn = null; // PDO se cierra asignando null
    }

} else {
    echo json_encode([
        'status'  => 'skipped',
        'message' => 'Fuera del horario permitido (11:00 - 23:00)',
        'hora'    => $hora_actual
    ]);
}
?>
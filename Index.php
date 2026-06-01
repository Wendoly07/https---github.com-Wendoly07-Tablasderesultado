<?php
date_default_timezone_set('America/Managua');

$output = '';
$status = '';
$pais   = '';

if (isset($_POST['run'])) {
    $pais = $_POST['run'];
    ob_start();
    if ($pais === 'HN') {
        include __DIR__ . '/Update_results_cron_hn.php';
    } elseif ($pais === 'NI') {
        include __DIR__ . '/update_results_cron_NI.php';
    } elseif ($pais === 'SV') {
        include __DIR__ . '/update_results_cron_SV.php';
    }
    $output = ob_get_clean();
    $status = 'done';
}

function colorize($text) {
    $lines = explode("\n", htmlspecialchars($text));
    $result = [];
    foreach ($lines as $line) {
        if (str_starts_with($line, '[NUEVO]'))     $result[] = '<span class="ok">'  . $line . '</span>';
        elseif (str_starts_with($line, '[SKIP]'))  $result[] = '<span class="skip">' . $line . '</span>';
        elseif (str_starts_with($line, 'ERROR'))   $result[] = '<span class="err">'  . $line . '</span>';
        elseif (str_starts_with($line, '==='))     $result[] = '<span class="head">' . $line . '</span>';
        elseif (str_starts_with($line, '[JUEGO]')) $result[] = '<span class="game">' . $line . '</span>';
        else $result[] = $line;
    }
    return implode("\n", $result);
}

function getLastDraws() {
    $azureSqlConfig = [
        'server'                 => getenv('DB_SERVER'),
        'database'               => getenv('DB_NAME'),
        'uid'                    => getenv('DB_USER'),
        'pwd'                    => getenv('DB_PASSWORD'),
        'Encrypt'                => true,
        'TrustServerCertificate' => false,
        'CharacterSet'           => 'UTF-8'
    ];
    $conn = sqlsrv_connect($azureSqlConfig['server'], [
        'Database'               => $azureSqlConfig['database'],
        'Uid'                    => $azureSqlConfig['uid'],
        'PWD'                    => $azureSqlConfig['pwd'],
        'Encrypt'                => $azureSqlConfig['Encrypt'],
        'TrustServerCertificate' => $azureSqlConfig['TrustServerCertificate'],
        'CharacterSet'           => $azureSqlConfig['CharacterSet']
    ]);
    if ($conn === false) {
        error_log('getLastDraws connection error: ' . print_r(sqlsrv_errors(), true));
        return [];
    }

    $sql = "SELECT TOP 30 pais, game_name, draw_number, draw_date, result_raw, draw_time
            FROM numeros_ganadores_sorteos_prod_prueba
            ORDER BY draw_date DESC";
    $stmt = sqlsrv_query($conn, $sql);
    $rows = [];
    if ($stmt) {
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
    }
    sqlsrv_close($conn);
    return $rows;
}

$lastDraws = getLastDraws();

$paisLabel = $pais === 'HN' ? 'Honduras' : ($pais === 'NI' ? 'Nicaragua' : 'El Salvador');
if ($status === 'done') {
    $hasErr = stripos($output, 'error') !== false;
    $hasNew = strpos($output, '[NUEVO]') !== false;
    if ($hasErr)     { $badgeClass = 'badge-err';  $badgeText = 'Error'; }
    elseif ($hasNew) { $badgeClass = 'badge-ok';   $badgeText = 'Nuevos insertados'; }
    else             { $badgeClass = 'badge-none';  $badgeText = 'Sin cambios'; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Loto Centroamérica — Panel</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --orange: #ef7d00;
      --orange-dark: #d46d00;
      --orange-light: #fff4e6;
      --blue: #1d4ed8;
      --blue-dark: #1e40af;
      --green: #16a34a;
      --green-dark: #15803d;
      --bg: #f5f5f0;
      --card: #ffffff;
      --border: #e8e4de;
      --text: #1a1714;
      --muted: #6b6560;
      --radius: 16px;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      min-height: 100vh;
    }

    .header {
      background: var(--orange);
      padding: 0 3rem;
      height: 72px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 20px rgba(239,125,0,0.3);
    }

    .header-left { display: flex; align-items: center; gap: 14px; }
    .logo img { width: 60px; height: 60px; object-fit: contain; }
    .header-title { font-size: 18px; font-weight: 700; color: #fff; letter-spacing: -0.02em; }
    .header-sub { font-size: 16px; color: rgba(255,255,255,0.75); margin-top: 1px; }
    .header-time { font-size: 13px; color: rgba(255,255,255,0.85); font-weight: 500; background: rgba(0,0,0,0.12); padding: 6px 14px; border-radius: 20px; }

    .page { max-width: 100%; margin: 0; padding: 1.5rem 2.5rem; }
    .grid-top { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 1.5rem; }

    .section-title {
      font-size: 14px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 1rem;
    }

    .countries { display: grid; grid-template-columns: repeat(3,1fr); gap: 12px; }

    .c-card {
      background: var(--card);
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      padding: 1.25rem;
      transition: box-shadow 0.15s;
    }
    .c-card.active { border-color: var(--orange); box-shadow: 0 0 0 3px rgba(239,125,0,0.1); }
    .c-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .c-iso { font-size: 26px; font-weight: 700; color: var(--text); }
    .dot { width: 11px; height: 11px; border-radius: 50%; background: #d1cdc8; }
    .dot.on { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,0.2); }
    .c-name { font-size: 18px; font-weight: 600; color: var(--text); }
    .c-src { font-size: 13px; color: var(--muted); margin-top: 4px; }
    .c-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 600; padding: 5px 12px; border-radius: 20px; margin-top: 14px; background: var(--orange-light); color: var(--orange-dark); border: 1px solid #fdd9a8; }
    .c-link { display: block; font-size: 12px; color: var(--blue); text-decoration: none; margin-top: 10px; word-break: break-all; }
    .c-link:hover { text-decoration: underline; }

    .actions-card {
      background: var(--card);
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      padding: 1.5rem;
    }

    .btns { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-bottom: 1rem; }

    .run-btn {
      padding: 12px;
      border-radius: 10px;
      border: none;
      font-family: 'DM Sans', sans-serif;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.12s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      letter-spacing: 0.01em;
      width: 100%;
    }
    .btn-hn { background: var(--orange); color: #fff; }
    .btn-hn:hover { background: var(--orange-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(239,125,0,0.35); }
    .btn-ni { background: var(--blue); color: #fff; }
    .btn-ni:hover { background: var(--blue-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(29,78,216,0.35); }
    .btn-sv { background: var(--green); color: #fff; }
    .btn-sv:hover { background: var(--green-dark); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(22,163,74,0.35); }

    .output-wrap { background: #0f1117; border-radius: 10px; overflow: hidden; }
    .output-head { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid #1e2030; }
    .output-label { font-size: 11px; font-weight: 700; color: #4b5563; letter-spacing: 0.08em; text-transform: uppercase; }
    .output-body { padding: 12px 14px; font-family: 'DM Mono', monospace; font-size: 12px; color: #6b7280; white-space: pre-wrap; word-break: break-all; max-height: 240px; overflow-y: auto; line-height: 1.8; }
    .ok { color: #4ade80; } .skip { color: #374151; } .err { color: #f87171; } .head { color: #f9fafb; font-weight: 600; } .game { color: #60a5fa; }

    .badge-ok   { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: #14532d; color: #86efac; }
    .badge-none { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: #1e2030; color: #6b7280; }
    .badge-err  { font-size: 11px; font-weight: 700; padding: 3px 10px; border-radius: 20px; background: #7f1d1d; color: #fca5a5; }

    .table-card {
      background: var(--card);
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      grid-column: 1 / -1;
    }

    .table-head {
      padding: 1rem 1.5rem;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 10px;
    }

    .filter-btn {
      font-size: 12px;
      font-weight: 600;
      padding: 4px 14px;
      border-radius: 20px;
      border: 1.5px solid var(--border);
      background: var(--card);
      color: var(--muted);
      cursor: pointer;
      transition: all 0.12s;
    }
    .filter-btn:hover { border-color: var(--orange); color: var(--orange); }
    .filter-btn.active { background: var(--orange); color: #fff; border-color: var(--orange); }

    table { width: 100%; border-collapse: collapse; }
    thead th {
      padding: 10px 16px;
      text-align: left;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      color: var(--muted);
      background: #faf9f7;
      border-bottom: 1px solid var(--border);
    }
    tbody tr { border-bottom: 1px solid var(--border); transition: background 0.1s; }
    tbody tr:last-child { border-bottom: none; }
    tbody tr:hover { background: #faf9f7; }
    tbody td { padding: 10px 16px; font-size: 13px; color: var(--text); }

    .pais-tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
    .tag-hn { background: #fff4e6; color: var(--orange-dark); }
    .tag-ni { background: #eff6ff; color: var(--blue); }
    .tag-sv { background: #f0fdf4; color: var(--green-dark); }

    .result-pill {
      font-family: 'DM Mono', monospace;
      font-size: 12px;
      background: #f5f5f0;
      padding: 3px 8px;
      border-radius: 6px;
      color: var(--text);
      font-weight: 500;
    }

    .foot { margin-top: 1.5rem; text-align: center; font-size: 12px; color: var(--muted); }

    @media (max-width: 768px) {
      .grid-top { grid-template-columns: 1fr; }
      .countries { grid-template-columns: 1fr; }
      .btns { grid-template-columns: 1fr; }
      .header { padding: 0 1rem; }
      .page { padding: 1rem; }
    }
  </style>
</head>
<body>

<div class="header">
  <div class="header-left">
    <div class="logo"><img src="img/logo.svg" alt="Loto"></div>
    <div>
      <div class="header-title">Loto Centroamérica</div>
      <div class="header-sub">Panel de actualización de resultados</div>
    </div>
  </div>
  <div class="header-time"><?php echo date('d/m/Y H:i:s'); ?></div>
</div>

<div class="page">

  <div class="grid-top">

    <div>
      <p class="section-title">Países configurados</p>
      <div class="countries">

        <div class="c-card active">
          <div class="c-top"><span class="c-iso">HN</span><span class="dot on"></span></div>
          <div class="c-name">Honduras</div>
          <div class="c-src">gamesdata.loto.hn</div>
          <span class="c-badge">&#10003; Activo</span>
          <a class="c-link" href="https://tablasderesultados-gyf8dha0cxdyggb3.canadacentral-01.azurewebsites.net/Update_results_cron_hn.php" target="_blank">&#128279; Update_results_cron_hn.php</a>
        </div>

        <div class="c-card active">
          <div class="c-top"><span class="c-iso">NI</span><span class="dot on"></span></div>
          <div class="c-name">Nicaragua</div>
          <div class="c-src">gamesdata.loto.com.ni</div>
          <span class="c-badge">&#10003; Activo</span>
          <a class="c-link" href="https://tablasderesultados-gyf8dha0cxdyggb3.canadacentral-01.azurewebsites.net/update_results_cron_NI.php" target="_blank">&#128279; update_results_cron_NI.php</a>
        </div>

        <div class="c-card active">
          <div class="c-top"><span class="c-iso">SV</span><span class="dot on"></span></div>
          <div class="c-name">El Salvador</div>
          <div class="c-src">gamesdata.loto.sv</div>
          <span class="c-badge">&#10003; Activo</span>
          <a class="c-link" href="https://tablasderesultados-gyf8dha0cxdyggb3.canadacentral-01.azurewebsites.net/update_results_cron_SV.php" target="_blank">&#128279; update_results_cron_SV.php</a>
        </div>

      </div>
    </div>

    <div>
      <p class="section-title">Actualización manual</p>
      <div class="actions-card">
        <div class="btns">
          <form method="POST">
            <input type="hidden" name="run" value="HN">
            <button type="submit" class="run-btn btn-hn">&#9654; Honduras</button>
          </form>
          <form method="POST">
            <input type="hidden" name="run" value="NI">
            <button type="submit" class="run-btn btn-ni">&#9654; Nicaragua</button>
          </form>
          <form method="POST">
            <input type="hidden" name="run" value="SV">
            <button type="submit" class="run-btn btn-sv">&#9654; El Salvador</button>
          </form>
        </div>

        <?php if ($status === 'done'): ?>
        <div class="output-wrap">
          <div class="output-head">
            <span class="output-label">Output — <?php echo $paisLabel; ?></span>
            <span class="<?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
          </div>
          <div class="output-body"><?php echo colorize($output); ?></div>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:2rem 0;color:var(--muted);font-size:13px;">
          Presiona un botón para ejecutar la actualización
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Tabla últimos sorteos -->
  <div class="table-card">
    <div class="table-head">
      <p class="section-title" style="margin:0">Últimos sorteos insertados</p>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <button onclick="filtrar('ALL')" class="filter-btn active" id="f-all">Todos</button>
        <button onclick="filtrar('HN')"  class="filter-btn" id="f-hn">Honduras</button>
        <button onclick="filtrar('NI')"  class="filter-btn" id="f-ni">Nicaragua</button>
        <button onclick="filtrar('SV')"  class="filter-btn" id="f-sv">El Salvador</button>
        <span style="font-size:12px;color:var(--muted)"><?php echo date('d/m/Y H:i:s'); ?></span>
      </div>
    </div>
    <table>
      <thead>
        <tr>
          <th>País</th>
          <th>Juego</th>
          <th>Sorteo #</th>
          <th>Fecha</th>
          <th>Hora</th>
          <th>Resultado</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($lastDraws)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:2rem">Sin datos</td></tr>
        <?php else: ?>
        <?php foreach ($lastDraws as $row): ?>
        <?php
          $paisCod  = $row['pais'];
          $tagClass = $paisCod === 'HN' ? 'tag-hn' : ($paisCod === 'NI' ? 'tag-ni' : 'tag-sv');
          if ($row['draw_date'] instanceof DateTime) {
              $drawDate = $row['draw_date']->format('d/m/Y');
          } else {
              $drawDate = substr($row['draw_date'], 0, 10);
          }
          if ($row['draw_time'] instanceof DateTime) {
              $drawTime = $row['draw_time']->format('H:i');
          } elseif (is_string($row['draw_time'])) {
              $drawTime = substr($row['draw_time'], 0, 5);
          } else {
              $drawTime = '';
          }
        ?>
        <tr data-pais="<?php echo htmlspecialchars($paisCod); ?>">
          <td><span class="pais-tag <?php echo $tagClass; ?>"><?php echo htmlspecialchars($paisCod); ?></span></td>
          <td><?php echo htmlspecialchars($row['game_name']); ?></td>
          <td style="font-family:'DM Mono',monospace;font-size:12px">#<?php echo htmlspecialchars($row['draw_number']); ?></td>
          <td style="font-size:12px;color:var(--muted)"><?php echo $drawDate; ?></td>
          <td style="font-size:12px;color:var(--muted)"><?php echo $drawTime; ?></td>
          <td><span class="result-pill"><?php echo htmlspecialchars($row['result_raw'] ?? '—'); ?></span></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="foot">Loto Centroamérica &mdash; Panel interno &mdash; <?php echo date('Y'); ?></div>

</div>

<script>
function filtrar(pais) {
    document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('f-' + pais.toLowerCase()).classList.add('active');
    document.querySelectorAll('tbody tr[data-pais]').forEach(row => {
        row.style.display = (pais === 'ALL' || row.dataset.pais === pais) ? '' : 'none';
    });
}
</script>

</body>
</html>
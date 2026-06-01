<?php
date_default_timezone_set('America/Managua');

$output = '';
$status = '';
$pais   = '';
// ─── EJECUCION MANUAL POST ────────
if (isset($_POST['run'])) {
    $pais = $_POST['run'];
    ob_start();
    if ($pais === 'HN') {
        include __DIR__ . '/Update_results_cron_hn.php';
    } elseif ($pais === 'NI') {
        include __DIR__ . '/update_results_cron_NI.php';
    } elseif ($pais === 'SV') {
        include __DIR__ . '/update_results_cron_sv.php';
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Loto — Panel de actualización</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',system-ui,sans-serif;background:#f0f2f5;min-height:100vh}

    .header{background:#ef7d00;padding:0 2.5rem;height:76px;display:flex;align-items:center;justify-content:space-between}
    .header-left{display:flex;align-items:center;gap:14px}
    .logo-wrap{width:54px;height:54px;flex-shrink:0}
    .logo-wrap img{width:54px;height:54px;object-fit:contain}
    .header-info h1{font-size:20px;font-weight:800;color:#fff;letter-spacing:0.01em}
    .header-info p{font-size:13px;color:rgba(255,255,255,0.8);margin-top:2px}
    .panel-tag{background:rgba(255,255,255,0.2);border:1.5px solid rgba(255,255,255,0.45);color:#fff;font-size:13px;font-weight:600;padding:8px 20px;border-radius:22px}

    .content{max-width:760px;margin:2.5rem auto;padding:0 1.5rem}
    .section-label{font-size:12px;font-weight:700;letter-spacing:0.08em;color:#94a3b8;text-transform:uppercase;margin-bottom:1rem}

    .countries{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:1.5rem}
    .c-card{background:#fff;border-radius:14px;border:1px solid #e8ecf0;padding:1.25rem 1.5rem}
    .c-card.active{border:2px solid #ef7d00;box-shadow:0 0 0 4px rgba(239,125,0,0.1)}
    .c-card.disabled{opacity:0.45}
    .c-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
    .c-iso{font-size:20px;font-weight:700;color:#1e293b}
    .dot{width:9px;height:9px;border-radius:50%;background:#e2e8f0}
    .dot.on{background:#22c55e}
    .c-name{font-size:15px;font-weight:600;color:#1e293b;margin-bottom:2px}
    .c-src{font-size:11px;color:#94a3b8}
    .c-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:4px 10px;border-radius:20px;margin-top:10px}
    .b-active{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
    .b-soon{background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0}

    .btns{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:0}
    .run-btn{width:100%;padding:14px;border-radius:12px;border:none;background:#ef7d00;color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:background 0.12s,transform 0.1s}
    .run-btn:hover{background:#d96a00}
    .run-btn:active{transform:scale(0.99)}
    .run-btn.ni{background:#1d4ed8}
    .run-btn.ni:hover{background:#1e40af}
    .run-btn.sv{background:#16a34a}
    .run-btn.sv:hover{background:#15803d}
    .run-btn.ni:hover{background:#1e40af}

    .output-wrap{margin-top:16px;background:#111827;border-radius:12px;overflow:hidden}
    .output-head{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid #1f2937}
    .output-label-tag{font-size:11px;font-weight:700;color:#4b5563;letter-spacing:0.08em;text-transform:uppercase}
    .status{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px}
    .s-ok{background:#14532d;color:#86efac}
    .s-none{background:#1f2937;color:#6b7280}
    .s-err{background:#7f1d1d;color:#fca5a5}
    .output-body{padding:14px 16px;font-family:'Cascadia Code','Fira Code','Courier New',monospace;font-size:12px;color:#6b7280;white-space:pre-wrap;word-break:break-all;max-height:320px;overflow-y:auto;line-height:1.8}
    .ok{color:#4ade80}.skip{color:#374151}.err{color:#f87171}.head{color:#f9fafb;font-weight:600}.game{color:#60a5fa}

    .footer{margin-top:12px;display:flex;justify-content:space-between}
    .foot-txt{font-size:12px;color:#94a3b8}
  </style>
</head>
<body>

<div class="header">
  <div class="header-left">
    <div class="logo-wrap">
      <img src="img/logo.svg" alt="Loto">
    </div>
    <div class="header-info">
      <h1>Loto Centroamérica</h1>
      <p>Panel de actualización de resultados</p>
    </div>
  </div>
  <span class="panel-tag">Panel de resultados</span>
</div>

<div class="content">

  <p class="section-label">Países configurados</p>

  <div class="countries">
    <div class="c-card active">
      <div class="c-top"><span class="c-iso">HN</span><span class="dot on"></span></div>
      <div class="c-name">Honduras</div>
      <div class="c-src">gamesdata.loto.hn</div>
      <span class="c-badge b-active">&#10003; Activo</span>
      <div style="margin-top:10px">
        <a href="https://tablasderesultados-gyf8dha0cxdyggb3.canadacentral-01.azurewebsites.net/Update_results_cron_hn.php"
           target="_blank" style="font-size:11px;color:#2563eb;word-break:break-all;text-decoration:none;line-height:1.5">
          &#128279; Update_results_cron_hn.php
        </a>
      </div>
    </div>
    <div class="c-card active">
      <div class="c-top"><span class="c-iso">NI</span><span class="dot on"></span></div>
      <div class="c-name">Nicaragua</div>
      <div class="c-src">gamesdata.loto.com.ni</div>
      <span class="c-badge b-active">&#10003; Activo</span>
      <div style="margin-top:10px">
        <a href="https://tablasderesultados-gyf8dha0cxdyggb3.canadacentral-01.azurewebsites.net/update_results_cron_NI.php"
           target="_blank" style="font-size:11px;color:#2563eb;word-break:break-all;text-decoration:none;line-height:1.5">
          &#128279; update_results_cron_NI.php
        </a>
      </div>
    </div>
    <div class="c-card active">
      <div class="c-top"><span class="c-iso">SV</span><span class="dot on"></span></div>
      <div class="c-name">El Salvador</div>
      <div class="c-src">gamesdata.loto.sv</div>
      <span class="c-badge b-active">&#10003; Activo</span>
      <div style="margin-top:10px">
        <a href="https://tablasderesultados-gyf8dha0cxdyggb3.canadacentral-01.azurewebsites.net/update_results_cron_SV.php"
           target="_blank" style="font-size:11px;color:#2563eb;word-break:break-all;text-decoration:none;line-height:1.5">
          &#128279; update_results_cron_sv.php
        </a>
      </div>
    </div>
  </div>

  <div class="btns">
    <form method="POST">
      <input type="hidden" name="run" value="HN">
      <button type="submit" class="run-btn">&#9654; Actualizar Honduras</button>
    </form>
    <form method="POST">
      <input type="hidden" name="run" value="NI">
      <button type="submit" class="run-btn ni">&#9654; Actualizar Nicaragua</button>
    </form>
    <form method="POST">
      <input type="hidden" name="run" value="SV">
      <button type="submit" class="run-btn sv">&#9654; Actualizar El Salvador</button>
    </form>
  </div>

  <?php if ($status === 'done'): ?>
  <?php
    $hasErr = stripos($output, 'error') !== false;
    $hasNew = strpos($output, '[NUEVO]') !== false;
    if ($hasErr)     { $badgeClass = 's-err';  $badgeText = 'Error'; }
    elseif ($hasNew) { $badgeClass = 's-ok';   $badgeText = 'Nuevos insertados'; }
    else             { $badgeClass = 's-none';  $badgeText = 'Sin cambios'; }
    $paisLabel = $pais === 'HN' ? 'Honduras' : ($pais === 'NI' ? 'Nicaragua' : 'El Salvador');
  ?>
  <div class="output-wrap">
    <div class="output-head">
      <span class="output-label-tag">Output — <?php echo $paisLabel; ?></span>
      <span class="status <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
    </div>
    <div class="output-body"><?php echo colorize($output); ?></div>
  </div>
  <?php endif; ?>

  <div class="footer">
    <span class="foot-txt"><?php if($status==='done') echo 'Última ejecución: '.date('d/m/Y H:i:s'); ?></span>
    <span class="foot-txt">Servidor: <?php echo date('d/m/Y H:i:s'); ?></span>
  </div>

</div>
</body>
</html>
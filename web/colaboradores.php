<?php
declare(strict_types=1);
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
require __DIR__ . '/../actas-app/inventario.php';
require __DIR__ . '/../actas-app/tema.php';

$yo = actas_requerir_login();
actas_requerir_acceso($yo, 'datos');
$csrf = actas_csrf();
$pend = substr(hash('sha256', session_id() . $yo), 0, 16);   // clave de la carga pendiente de esta sesión

function ir(string $q = '', string $tipo = '', string $msg = ''): never {
    if ($msg !== '') $_SESSION['flash_inv'] = [$tipo, $msg];
    header('Location: colaboradores.php' . $q); exit;
}

/* ---------- Descargar el archivo original de una versión ---------- */
if (isset($_GET['descargar'])) {
    $ver = ctype_digit((string)$_GET['descargar']) ? inv_version((int)$_GET['descargar']) : null;
    if (!$ver || $ver['original'] === null) { http_response_code(404); exit('No encontrado'); }
    $nom = preg_replace('/[^A-Za-z0-9._ -]/', '_', $ver['original_nombre'] ?: 'inventario.xlsx');
    header('Content-Type: ' . (preg_match('/\.csv$/i', $nom) ? 'text/csv' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'));
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . strlen($ver['original']));
    echo $ver['original']; exit;
}

/* ---------- Acciones ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!actas_csrf_ok()) ir('', 'err', 'La página expiró. Intente de nuevo.');
    switch ((string)($_POST['accion'] ?? '')) {
        case 'subir':
            $f = $_FILES['archivo'] ?? null;
            if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) ir('', 'err', ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'El archivo es demasiado grande.' : 'Elija el archivo de Excel (.xlsx) o CSV a cargar.');
            $nombre = basename((string)$f['name']);
            $bin = (string)file_get_contents($f['tmp_name']);
            try {
                $hojas = str_starts_with($bin, "PK") ? inv_leer_xlsx($bin) : (preg_match('/\.csv$/i', $nombre) ? inv_leer_csv($bin) : throw new RuntimeException('Use un archivo .xlsx (Excel) o .csv.'));
                $datos = inv_normalizar($hojas);
            } catch (Throwable $e) { ir('', 'err', 'No se pudo leer «' . $nombre . '»: ' . $e->getMessage()); }
            if (!$datos['empleados'] && !$datos['equipos']) ir('', 'err', 'El archivo no tiene filas de datos.');
            inv_pendiente_poner($pend, $nombre, $datos, $bin);
            ir('?v=revisar');
        case 'aplicar':
            $p = inv_pendiente($pend);
            if (!$p) ir('', 'err', 'No hay una carga pendiente. Suba el archivo de nuevo.');
            if (!inv_guardar($p['datos'], $yo, $p['nombre'], $p['original'])) ir('?v=revisar', 'err', 'No se pudo guardar en la base de datos.');
            inv_pendiente_borrar($pend);
            actas_log("inventario: $yo cargó {$p['nombre']} (" . count($p['datos']['empleados']) . ' colaboradores, ' . count($p['datos']['equipos']) . ' equipos)');
            ir('', 'ok', 'Base de datos actualizada con «' . $p['nombre'] . '»: ' . count($p['datos']['empleados']) . ' colaboradores y ' . count($p['datos']['equipos']) . ' equipos. Las actas ya usan los datos nuevos.');
        case 'cancelar':
            inv_pendiente_borrar($pend);
            ir('', 'warn', 'Carga cancelada. La base de datos no cambió.');
        case 'restaurar':
            $n = (string)($_POST['version'] ?? '');
            $d = ctype_digit($n) ? inv_version((int)$n) : null;
            if (!$d) ir('?v=historial', 'err', 'Esa versión ya no existe.');
            if (!inv_guardar($d, $yo, $d['archivo'], $d['original'])) ir('?v=historial', 'err', 'No se pudo restaurar.');
            actas_log("inventario: $yo restauró la versión $n");
            ir('?v=historial', 'ok', 'Se restauró la versión del ' . date('d/m/Y h:i a', $d['actualizado']) . ' (' . $d['archivo'] . ').');
    }
    ir();
}

$v = (string)($_GET['v'] ?? 'inicio');
$inv = inv_cargar();
$flash = $_SESSION['flash_inv'] ?? null; unset($_SESSION['flash_inv']);
$pendiente = inv_pendiente($pend);
if ($v === 'revisar' && !is_array($pendiente)) $v = 'inicio';
$fmt = fn($t) => $t ? date('d/m/Y h:i a', (int)$t) : '—';
$eqDe = function (array $e) use ($inv) { return array_map(fn($q) => $q['hostname'] ?: $q['serie'], inv_equipos_de($e, $inv['equipos'] ?? [])); };
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Base de datos de colaboradores · Actas TIC</title>
<style>
:root{--paper:#fff;--ink:#17212e;--mute:#566273;--line:#d3dbe5;--band:#e9eff6;--zebra:#f5f7fa;--accent:#1f4e79;--gold:#c08a1e;--err:#b3261e;--ok:#1d7a4a;--warn:#9a6700}
@media (prefers-color-scheme:dark){:root{--paper:#19212a;--ink:#e8edf3;--mute:#9aa6b5;--line:#344050;--band:#22303f;--zebra:#1d2630;--accent:#7fb2e0;--gold:#e0b04f;--err:#f2b8b5;--ok:#5fc48c;--warn:#e8c26b}}
*{box-sizing:border-box}
body{margin:0;color:var(--ink);font:15px/1.5 "Segoe UI",system-ui,Roboto,Arial,sans-serif;background:var(--bg,#edf1f5);padding:0 12px 40px}
.sheet{max-width:1180px;margin:24px auto 0;background:var(--paper);border:1px solid var(--line);border-radius:12px;box-shadow:0 10px 30px -12px rgba(18,52,86,.22);overflow:hidden}
.sheet::before{content:"";display:block;height:4px;background:linear-gradient(90deg,var(--navy,#123456) 0 76%,var(--gold) 76% 100%)}
.hd{display:flex;align-items:center;gap:18px;padding:18px 24px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,var(--zebra),var(--paper))}
.hd img{height:64px}.hd .eyebrow{font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--mute)}
.hd h1{margin:2px 0 0;font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;font-size:26px;font-weight:600}
.stats{margin-left:auto;display:flex;gap:10px}.stat{min-width:100px;text-align:center;padding:8px 12px;border:1px solid var(--line);border-radius:10px}
.stat b{display:block;font-size:22px;color:var(--navy-ink,var(--accent))}.stat span{font-size:12px;color:var(--mute)}
.tabs{display:flex;gap:4px;padding:0 20px;border-bottom:1px solid var(--line);background:var(--zebra);overflow-x:auto}
.tabs a{padding:12px 14px;font-weight:600;font-size:14px;text-decoration:none;color:var(--mute);border-bottom:3px solid transparent;white-space:nowrap}
.tabs a.on{color:var(--navy-ink,var(--accent));border-bottom-color:var(--gold)}
.body{padding:20px 24px 26px}
.msg{margin:0 0 16px;padding:12px 14px;border-radius:8px;border:1px solid currentColor;background:color-mix(in srgb,currentColor 9%,transparent);font-weight:600}
.msg.ok{color:var(--ok)}.msg.err{color:var(--err)}.msg.warn{color:var(--warn)}
.panel{border:1px solid var(--line);border-left:4px solid var(--gold);border-radius:10px;background:var(--zebra);padding:18px 20px;margin:0 0 18px}
.panel h3{margin:0 0 10px;font-size:16px;color:var(--navy-ink,var(--accent))}
.b{font:inherit;font-size:13.5px;font-weight:600;padding:8px 14px;border-radius:8px;border:1px solid var(--line);background:var(--paper);color:var(--accent);cursor:pointer;text-decoration:none;display:inline-flex;gap:6px;align-items:center}
.b.p{background:var(--navy,#123456);border-color:var(--navy,#123456);color:#fff}.b.big{font-size:15px;padding:10px 20px}.b.danger{color:var(--err)}
.acts{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}
.drop{display:flex;flex-wrap:wrap;gap:14px;align-items:center;padding:18px;border:2px dashed var(--line);border-radius:10px;background:var(--paper)}
.drop input[type=file]{font:inherit}
.hint{font-size:13px;color:var(--mute)}
.tw{overflow-x:auto;border:1px solid var(--line);border-radius:10px;background:var(--paper)}
table{width:100%;border-collapse:collapse}
th{background:var(--navy,#123456);color:#fff;font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;text-align:left;padding:9px 10px;white-space:nowrap;position:sticky;top:0}
td{padding:8px 10px;border-top:1px solid color-mix(in srgb,var(--line) 70%,transparent);font-size:13.5px;vertical-align:top}
td small{display:block;color:var(--mute)}
.res{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:14px}
.res .card{border:1px solid var(--line);border-radius:10px;background:var(--paper);padding:14px 16px}
.res .card h4{margin:0 0 8px;font-size:15px}
.num{display:flex;gap:16px;flex-wrap:wrap;font-size:14px}.num b{font-size:20px;display:block}
.n-ok{color:var(--ok)}.n-mod{color:var(--warn)}.n-del{color:var(--err)}
details{margin-top:8px}summary{cursor:pointer;font-weight:600;font-size:13.5px;color:var(--accent)}
details .tw{margin-top:8px;max-height:320px;overflow:auto}
.cambio del{color:var(--err)}.cambio ins{color:var(--ok);text-decoration:none}
.filtro{display:flex;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap}
.filtro input{flex:1 1 300px;max-width:420px;font:inherit;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:var(--paper);color:var(--ink)}
.vacio{padding:26px;text-align:center;color:var(--mute)}
@media(max-width:760px){.res{grid-template-columns:1fr}.hd{flex-wrap:wrap}.stats{margin-left:0;width:100%}.stat{flex:1}.body{padding:14px}}
</style>
<?php tema_estilos(); ?>
</head>
<body>
<?php tema_barra('index.php', 'Volver al menú', $yo, '1180px'); ?>
<main class="sheet">
  <header class="hd">
    <img src="<?= ESCUDO_MINI ?>" alt="Escudo de la Municipalidad de Carrillo">
    <div><div class="eyebrow">Municipalidad de Carrillo · Departamento de TI</div><h1>Base de datos de colaboradores</h1></div>
    <div class="stats"><div class="stat"><b><?= count($inv['empleados'] ?? []) ?></b><span>colaboradores</span></div><div class="stat"><b><?= count($inv['equipos'] ?? []) ?></b><span>equipos</span></div></div>
  </header>
  <nav class="tabs">
    <a href="colaboradores.php" class="<?= in_array($v, ['inicio', 'revisar'], true) ? 'on' : '' ?>">Actualizar</a>
    <a href="?v=consulta" class="<?= $v === 'consulta' ? 'on' : '' ?>">Consultar datos</a>
    <a href="?v=historial" class="<?= $v === 'historial' ? 'on' : '' ?>">Historial de versiones</a>
  </nav>
  <div class="body">
<?php if ($flash): ?>    <div class="msg <?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if ($v === 'inicio'): ?>
    <p style="margin-top:0">Estos datos llenan automáticamente las <strong>Actas de Entrega y Devolución</strong>: nombre, puesto, departamento, usuario, correo, cédula y los equipos asignados.</p>
    <div class="panel"><h3>Datos vigentes</h3>
<?php if (empty($inv['actualizado'])): ?>
      <p class="hint" style="margin:0">Todavía no se ha cargado ningún archivo. Suba el Excel de inventario para empezar.</p>
<?php else: ?>
      <p style="margin:0">Cargados el <strong><?= e($fmt($inv['actualizado'])) ?></strong> por <strong><?= e((string)($inv['por'] ?? '')) ?></strong> desde «<?= e((string)($inv['archivo'] ?? '')) ?>».
      <?php if (!empty($inv['original'])): ?><a href="?descargar=<?= urlencode($inv['original']) ?>">Descargar ese archivo</a><?php endif; ?></p>
<?php endif; ?>
    </div>
    <div class="panel"><h3>Actualizar con un archivo nuevo</h3>
      <form method="post" enctype="multipart/form-data" class="drop">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="subir">
        <input type="file" name="archivo" accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" required>
        <button class="b p big">Revisar archivo</button>
      </form>
      <p class="hint">Use el mismo formato del archivo de inventario: una hoja <strong>Empleados</strong> (Código, Nombre Completo, Cédula, Puesto, Departamento, Correo Electrónico) y una hoja de <strong>equipos</strong> de ManageEngine (Computer Name, Logged On Users, Código Empleado, Device Model, Service Tag/Serial Number, monitor, teléfono, mouse, teclado y UPS). Antes de aplicar, el sistema le muestra qué cambia; nada se modifica hasta que usted lo confirme.</p>
    </div>
<?php elseif ($v === 'revisar'):
    $d = $pendiente['datos']; $cmp = inv_comparar($inv, $d);
    $resumen = fn(array $e) => trim($e['nombre'] . ($e['cedula'] !== '' ? ' · ' . $e['cedula'] : '') . ($e['departamento'] !== '' ? ' · ' . $e['departamento'] : ''));
    $resEq = fn(array $q) => trim(strtoupper($q['hostname']) . ' · ' . $q['modelo'] . ($q['serie'] !== '' ? ' · ' . $q['serie'] : '') . ($q['nombre'] !== '' ? ' · ' . $q['nombre'] : ''));
?>
    <div class="panel"><h3>Revise los cambios de «<?= e($pendiente['nombre']) ?>»</h3>
      <p style="margin:0 0 12px">El archivo trae <strong><?= count($d['empleados']) ?> colaboradores</strong> y <strong><?= count($d['equipos']) ?> equipos</strong> (hojas: <?= e(implode(', ', $d['hojas'])) ?>).</p>
<?php foreach ($d['avisos'] as $a): ?>      <p class="hint" style="margin:2px 0">&#9888; <?= e($a) ?></p>
<?php endforeach; ?>
    </div>
    <div class="res">
<?php foreach (['empleados' => ['Colaboradores', $resumen], 'equipos' => ['Equipos', $resEq]] as $tipo => [$tit, $fn]): $c = $cmp[$tipo]; ?>
      <div class="card"><h4><?= $tit ?></h4>
        <div class="num"><span class="n-ok"><b>+<?= count($c['nuevos']) ?></b>nuevos</span><span class="n-mod"><b><?= count($c['modificados']) ?></b>con cambios</span><span class="n-del"><b>−<?= count($c['retirados']) ?></b>ya no aparecen</span></div>
<?php foreach (['nuevos' => 'Nuevos', 'modificados' => 'Con cambios', 'retirados' => 'Ya no aparecen'] as $k => $lab): if (!$c[$k]) continue; ?>
        <details><summary><?= $lab ?> (<?= count($c[$k]) ?>)</summary><div class="tw"><table><tbody>
<?php foreach (array_slice($c[$k], 0, 300) as $x):
        if ($k === 'modificados') {
            $dif = [];
            foreach ($x['despues'] as $campo => $val) if (json_encode($val) !== json_encode($x['antes'][$campo] ?? null))
                $dif[] = '<b>' . e($campo) . '</b>: <del>' . e(is_array($x['antes'][$campo] ?? null) ? implode(' ', $x['antes'][$campo]) : (string)($x['antes'][$campo] ?? '')) . '</del> → <ins>' . e(is_array($val) ? implode(' ', $val) : (string)$val) . '</ins>';
            echo '<tr><td>' . e($fn($x['despues'])) . '<small class="cambio">' . implode(' · ', $dif) . '</small></td></tr>';
        } else echo '<tr><td>' . e($fn($x)) . '</td></tr>';
      endforeach; ?>
        </tbody></table></div></details>
<?php endforeach; ?>
      </div>
<?php endforeach; ?>
    </div>
    <div class="acts">
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="aplicar"><button class="b p big">Aplicar estos cambios</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="cancelar"><button class="b">Cancelar</button></form>
      <span class="hint">La versión actual queda guardada en el historial y se puede restaurar.</span>
    </div>
<?php elseif ($v === 'consulta'): ?>
    <div class="filtro"><input type="search" id="q" placeholder="Buscar por nombre, cédula, código, usuario, departamento, equipo o serie" aria-label="Buscar"><span class="hint" id="cuenta"></span></div>
<?php if (empty($inv['empleados']) && empty($inv['equipos'])): ?>
    <div class="vacio">No hay datos cargados. Vaya a «Actualizar» y suba el Excel de inventario.</div>
<?php else: ?>
    <div class="tw" style="max-height:70vh"><table id="tabla">
      <thead><tr><th>Colaborador</th><th>Cédula</th><th>Código</th><th>Puesto</th><th>Departamento</th><th>Correo</th><th>Equipos</th></tr></thead>
      <tbody>
<?php foreach ($inv['empleados'] as $e): $eqs = inv_equipos_de($e, $inv['equipos']); ?>
        <tr><td><strong><?= e($e['nombre']) ?></strong></td><td><?= e($e['cedula']) ?></td><td><?= e($e['codigo']) ?></td><td><?= e($e['puesto']) ?></td><td><?= e($e['departamento']) ?></td><td><?= e($e['correo'] ?: '—') ?></td>
          <td><?php foreach ($eqs as $q): ?><div><?= e(strtoupper($q['hostname'])) ?><small><?= e(trim($q['modelo'] . ' ' . $q['serie'])) ?></small></div><?php endforeach; ?><?= $eqs ? '' : '—' ?></td></tr>
<?php endforeach; ?>
<?php foreach ($inv['equipos'] as $q): if ($q['codigo'] !== '' && array_filter($inv['empleados'], fn($e) => $e['codigo'] === $q['codigo'])) continue;
        $ligado = array_filter($inv['empleados'], fn($e) => $e['correo'] !== '' && ($q['correo'] === $e['correo'] || strtolower($q['usuario']) === $e['usuario'])); if ($ligado) continue; ?>
        <tr><td><?= e($q['nombre'] ?: '(sin colaborador)') ?><small>Equipo sin colaborador en la hoja de empleados</small></td><td></td><td><?= e($q['codigo']) ?></td><td></td><td><?= e($q['departamento']) ?></td><td><?= e($q['correo'] ?: '—') ?></td>
          <td><div><?= e(strtoupper($q['hostname'])) ?><small><?= e(trim($q['modelo'] . ' ' . $q['serie'])) ?></small></div></td></tr>
<?php endforeach; ?>
      </tbody></table></div>
    <script>
    (()=>{const q=document.getElementById('q'),filas=[...document.querySelectorAll('#tabla tbody tr')],c=document.getElementById('cuenta');
      const norm=s=>s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g,'');const txt=filas.map(f=>norm(f.textContent));
      const f=()=>{const p=norm(q.value.trim()).split(/\s+/).filter(Boolean);let n=0;filas.forEach((r,i)=>{const ok=p.every(x=>txt[i].includes(x));r.hidden=!ok;if(ok)n++});c.textContent=n+' de '+filas.length};
      q.addEventListener('input',f);f()})();
    </script>
<?php endif; ?>
<?php elseif ($v === 'historial'): $h = inv_historial(); ?>
    <p style="margin-top:0">Cada vez que se aplica un archivo nuevo, la versión anterior se guarda aquí (las últimas <?= INV_HISTORIAL ?>).</p>
    <div class="tw"><table>
      <thead><tr><th>Versión del</th><th>Cargada por</th><th>Archivo</th><th>Colaboradores</th><th>Equipos</th><th></th></tr></thead>
      <tbody>
        <tr style="background:var(--band)"><td><strong><?= e($fmt($inv['actualizado'] ?? 0)) ?></strong><small>Vigente</small></td><td><?= e((string)($inv['por'] ?? '—')) ?></td><td><?= e((string)($inv['archivo'] ?? '—')) ?><?php if (!empty($inv['original'])): ?> <a href="?descargar=<?= urlencode($inv['original']) ?>">descargar</a><?php endif; ?></td><td><?= count($inv['empleados'] ?? []) ?></td><td><?= count($inv['equipos'] ?? []) ?></td><td></td></tr>
<?php foreach ($h as $x): ?>
        <tr><td><?= e($fmt($x['actualizado'])) ?></td><td><?= e($x['por']) ?></td><td><?= e($x['nombre']) ?><?php if ($x['original']): ?> <a href="?descargar=<?= urlencode($x['original']) ?>">descargar</a><?php endif; ?></td><td><?= $x['empleados'] ?></td><td><?= $x['equipos'] ?></td>
          <td style="text-align:right"><form method="post" onsubmit="return confirm('¿Volver a los datos del <?= e($fmt($x['actualizado'])) ?>? La versión vigente queda guardada en el historial.')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="restaurar"><input type="hidden" name="version" value="<?= e($x['archivo']) ?>"><button class="b">Restaurar</button></form></td></tr>
<?php endforeach; ?>
      </tbody></table>
<?php if (!$h): ?>      <div class="vacio">Aún no hay versiones anteriores.</div><?php endif; ?>
    </div>
<?php endif; ?>
  </div>
</main>
</body>
</html>

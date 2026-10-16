<?php
/**
 * Papelera de formularios — solo administradores.
 */
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
require __DIR__ . '/../actas-app/formularios.php';
$yo = actas_requerir_admin();

function volver(string $tipo, string $msg): never {
    $_SESSION['flash'] = [$tipo, $msg];
    header('Location: papelera.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!actas_csrf_ok()) volver('err', 'El formulario expiró. Intente de nuevo.');
    $clave = (string)($_POST['clave'] ?? '');
    switch ($_POST['accion'] ?? '') {
        case 'restaurar':
            $d = form_papelera_cargar($clave);
            if ($d === null) volver('err', 'Ese formulario ya no está en la papelera.');
            $id = form_papelera_restaurar($clave, $yo);
            if ($id === '') volver('err', 'No se pudo restaurar. Revise los permisos de ' . ACTAS_FORMS_DIR . '.');
            actas_log("admin=$yo restauró de la papelera formulario=$id");
            $f = form_cargar($id);
            volver('ok', '«' . ($f['titulo'] ?? $id) . '» se restauró' . (!empty($f['publicado']) ? ' y vuelve a aparecer en el menú.' : ' (está oculto: publíquelo desde el Constructor para que aparezca en el menú).'));
        case 'restaurar_fijo':
            if (!isset(FORM_FIJOS[$clave]) || !form_fijo_set($clave, false)) volver('err', 'No se pudo restaurar.');
            actas_log("admin=$yo restauró formulario del sistema=$clave");
            volver('ok', '«' . FORM_FIJOS[$clave]['titulo'] . '» se restauró y vuelve a aparecer en el menú.');
        case 'borrar':
            $d = form_papelera_cargar($clave);
            if ($d === null || !form_papelera_borrar($clave)) volver('err', 'No se pudo eliminar.');
            actas_log("admin=$yo eliminó definitivamente formulario=" . ($d['_papelera']['id_original'] ?? $clave));
            volver('ok', '«' . $d['titulo'] . '» se eliminó definitivamente.');
        case 'vaciar':
            $n = 0;
            foreach (form_papelera_listar() as $d) if (form_papelera_borrar($d['_clave'])) $n++;
            actas_log("admin=$yo vació la papelera ($n formularios)");
            volver('ok', $n ? "Papelera vaciada: se eliminaron definitivamente $n formulario(s)." : 'La papelera ya estaba vacía.');
    }
    volver('err', 'Acción no reconocida.');
}

$csrf = actas_csrf();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$creados = form_papelera_listar();
$info = form_fijos_info();
$fijos = form_fijos_eliminados();
$fecha = fn($t) => $t ? date('d/m/Y H:i', (int)$t) : '—';
$total = count($creados) + count($fijos);
function btn(string $acc, string $clave, string $csrf, string $txt, string $cls = '', string $conf = ''): string {
    return '<form method="post" class="inl"' . ($conf ? ' data-confirm="' . e($conf) . '"' : '') . '><input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="accion" value="' . e($acc) . '"><input type="hidden" name="clave" value="' . e($clave) . '"><button class="b ' . e($cls) . '" type="submit">' . $txt . '</button></form>';
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Papelera · Actas TIC</title>
<style>

:root{--bg:#eef1f5;--paper:#fff;--ink:#1b2430;--mute:#5b6675;--line:#c9d1dc;--field:#f6f8fb;--band:#e7edf4;--zebra:#f6f8fb;--accent:#1f4e79;--gold:#c08a1e;--err:#b3261e;--ok:#1d7a4a;--warn:#9a6700;--shadow:0 1px 2px rgba(20,35,55,.08),0 8px 24px rgba(20,35,55,.08)}
@media (prefers-color-scheme:dark){:root{--bg:#12171d;--paper:#1b222b;--ink:#e8edf3;--mute:#9aa6b5;--line:#3a4554;--field:#141a21;--band:#243040;--zebra:#1f2731;--accent:#6fa8dc;--gold:#e0b04f;--err:#f2b8b5;--ok:#5fc48c;--warn:#e8c26b;--shadow:0 1px 2px rgba(0,0,0,.4),0 8px 24px rgba(0,0,0,.35)}}
*{box-sizing:border-box}
body{margin:0;color:var(--ink);font:15px/1.5 "Source Sans 3","Segoe UI",Roboto,Arial,sans-serif;-webkit-font-smoothing:antialiased;
  background:radial-gradient(1200px 500px at 50% -120px,color-mix(in srgb,var(--accent) 16%,transparent),transparent 70%) no-repeat,var(--bg);padding:0 12px 32px}
a{color:var(--accent)}
.top{position:sticky;top:0;z-index:20;margin:0 -12px;padding:10px max(12px,calc((100vw - 1100px)/2));display:flex;align-items:center;justify-content:space-between;gap:12px;
  background:color-mix(in srgb,var(--paper) 86%,transparent);backdrop-filter:saturate(1.4) blur(8px);-webkit-backdrop-filter:saturate(1.4) blur(8px);border-bottom:1px solid var(--line)}
.pill-link{display:inline-flex;align-items:center;gap:6px;font-weight:600;font-size:13.5px;text-decoration:none;padding:7px 16px 7px 12px;border:1px solid var(--line);border-radius:999px;background:var(--paper)}
.pill-link:hover{border-color:var(--accent)}
.who{display:inline-flex;gap:12px;align-items:center;font-size:13px;color:var(--mute)}.who strong{color:var(--ink)}
.who a{font-weight:600;text-decoration:none}
.sheet{max-width:1100px;margin:24px auto 0;background:var(--paper);border:1px solid var(--line);border-radius:8px;box-shadow:var(--shadow);overflow:hidden}
.sheet::before{content:"";display:block;height:6px;background:linear-gradient(90deg,var(--accent) 0 72%,var(--gold) 72% 100%)}
.hd{display:flex;align-items:center;gap:18px;padding:20px 24px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,color-mix(in srgb,var(--accent) 5%,var(--paper)),var(--paper))}
.hd img{height:72px;width:auto;filter:drop-shadow(0 2px 3px rgba(20,35,55,.15))}
.hd .eyebrow{font-size:11px;font-weight:600;letter-spacing:.22em;text-transform:uppercase;color:var(--mute)}
.hd h1{margin:2px 0 0;font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;font-size:27px;font-weight:600;color:var(--accent);line-height:1.15}
.stats{margin-left:auto;display:flex;gap:10px}
.stat{min-width:86px;text-align:center;padding:8px 12px;border:1px solid var(--line);border-radius:8px;background:var(--paper)}
.stat b{display:block;font-size:22px;color:var(--accent);line-height:1.1}.stat span{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:var(--mute)}
.body{padding:18px 24px 24px}
.sheet{max-width:1180px}.top{padding-left:max(12px,calc((100vw - 1180px)/2));padding-right:max(12px,calc((100vw - 1180px)/2))}
.msg{margin:0 0 16px;padding:12px 14px;border-radius:8px;border:1px solid currentColor;background:color-mix(in srgb,currentColor 9%,transparent);font-weight:600}
.msg.ok{color:var(--ok)}.msg.err{color:var(--err)}.msg.warn{color:var(--warn)}
.tmp{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:8px;font-weight:400;color:var(--ink)}
.tmp code{font:700 20px/1 ui-monospace,Consolas,monospace;letter-spacing:.06em;padding:8px 12px;border-radius:6px;background:var(--paper);border:1px dashed var(--ok);color:var(--ink)}
.tmp small{flex-basis:100%;color:var(--mute)}
.bar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.bar h2{margin:0;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:var(--accent)}
.b{font:inherit;font-size:13px;font-weight:600;padding:6px 12px;border-radius:6px;border:1px solid var(--line);background:var(--paper);color:var(--accent);cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-block;line-height:1.4}
.b:hover:not(:disabled){border-color:var(--accent);background:var(--band)}
.b:disabled{opacity:.4;cursor:not-allowed}
.b.p{background:var(--accent);border-color:var(--accent);color:var(--paper)}.b.p:hover{filter:brightness(1.08);background:var(--accent)}
.b.danger{color:var(--err)}.b.danger:hover:not(:disabled){border-color:var(--err);background:color-mix(in srgb,var(--err) 10%,transparent)}
.b.big{font-size:14px;padding:9px 18px}
.panel{border:1px solid var(--line);border-left:4px solid var(--gold);border-radius:8px;background:var(--zebra);padding:16px 18px;margin-bottom:18px}
.panel h3{margin:0 0 12px;font-size:15px;color:var(--accent)}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px 18px}
.full{grid-column:1/-1}
label{display:block;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--mute);margin-bottom:4px}
input[type=text],input[type=email],select{width:100%;font:inherit;color:var(--ink);background:var(--field);border:1px solid var(--line);border-radius:6px;padding:8px 10px}
input:focus,select:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 25%,transparent)}
input[readonly]{opacity:.7}
.hint{font-size:12.5px;color:var(--mute);margin-top:4px}
.radios{display:flex;gap:10px;flex-wrap:wrap}
.radios label{display:flex;gap:8px;align-items:flex-start;text-transform:none;letter-spacing:0;font-size:14px;font-weight:500;color:var(--ink);padding:10px 12px;border:1px solid var(--line);border-radius:8px;background:var(--paper);cursor:pointer;flex:1 1 240px;margin:0}
.radios label:has(input:checked){border-color:var(--accent);background:var(--band)}
.radios small{display:block;color:var(--mute);font-size:12.5px}
.acts{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
.tw{overflow-x:auto;border:1px solid var(--line);border-radius:8px}
table{width:100%;border-collapse:collapse;min-width:1040px}
th{background:var(--accent);color:var(--paper);font-size:11px;letter-spacing:.08em;text-transform:uppercase;text-align:left;padding:10px 12px}
td{padding:10px 12px;border-top:1px solid color-mix(in srgb,var(--line) 60%,transparent);vertical-align:middle;font-size:14px}
tbody tr:hover{background:color-mix(in srgb,var(--accent) 5%,transparent)}
tr.off td{color:var(--mute)}
tr.sel{background:var(--band)}
.u{font-weight:700}.yo{font-size:11px;font-weight:700;color:var(--gold);margin-left:4px}
.tag{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 9px;border-radius:999px;border:1px solid currentColor}
.tag.admin{color:var(--gold)}.tag.tec{color:var(--accent)}.tag.on{color:var(--ok)}.tag.offt{color:var(--err)}.tag.pend{color:var(--warn);margin-left:4px}
.ops{display:flex;gap:5px;flex-wrap:nowrap;justify-content:flex-end}
.ops .b{padding:5px 9px;font-size:12.5px}
.inl{display:inline}
.vacio{padding:24px;text-align:center;color:var(--mute)}
.ayuda{margin-top:16px;font-size:13px;color:var(--mute)}
@media(max-width:720px){.hd{flex-wrap:wrap}.stats{margin-left:0;width:100%}.stat{flex:1}.grid{grid-template-columns:1fr}.who .nm,.who .cc{display:none}.pill-link,.who a{white-space:nowrap}.body,.hd{padding:14px}}

.tw table{min-width:760px}
td .t{font-weight:700}td .d{font-size:12.5px;color:var(--mute)}
.tipo-f{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 9px;border-radius:999px;border:1px solid currentColor}
.tipo-f.sis{color:var(--gold)}.tipo-f.cre{color:var(--accent)}
.vacia{text-align:center;padding:40px 20px;color:var(--mute)}
.vacia .ico-p{font-size:46px;line-height:1;margin-bottom:8px;opacity:.6}
.b.restaurar{color:var(--ok)}.b.restaurar:hover{border-color:var(--ok);background:color-mix(in srgb,var(--ok) 10%,transparent)}
</style>
</head>
<body>
<nav class="top">
  <a class="pill-link" href="formularios_admin.php">&larr; Volver al Constructor</a>
  <span class="who"><span class="nm">&#128100; <strong><?= e($yo) ?></strong></span><a href="index.php">Menú</a><a href="logout.php">Cerrar sesión</a></span>
</nav>
<main class="sheet">
  <header class="hd">
    <img src="<?= ESCUDO_MINI ?>" alt="Escudo de la Municipalidad de Carrillo">
    <div><div class="eyebrow">Municipalidad de Carrillo · TIC</div><h1>Papelera de formularios</h1></div>
    <div class="stats"><div class="stat"><b><?= $total ?></b><span>En papelera</span></div></div>
  </header>
  <div class="body">
<?php if ($flash): ?>
    <div class="msg <?= e($flash[0]) ?>" role="<?= $flash[0] === 'err' ? 'alert' : 'status' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if (!$total): ?>
    <div class="vacia panel"><div class="ico-p">&#128465;</div><strong>La papelera está vacía.</strong><p>Los formularios que elimine aparecerán aquí y podrá restaurarlos.</p></div>
<?php else: ?>
    <div class="bar"><h2>Formularios eliminados</h2>
<?php if ($creados): ?>
      <?= btn('vaciar', '', $csrf, '&#128465; Vaciar papelera', 'danger', '¿Vaciar la papelera? Se eliminarán DEFINITIVAMENTE ' . count($creados) . ' formulario(s) creados con el constructor. Esta acción no se puede deshacer.') ?>
<?php endif; ?>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Formulario</th><th>Tipo</th><th>Eliminado</th><th style="text-align:right">Acciones</th></tr></thead>
      <tbody>
<?php foreach ($fijos as $k): $i = $info[$k] ?? []; ?>
        <tr>
          <td><div class="t"><?= e(FORM_FIJOS[$k]['titulo']) ?></div><div class="d">Formulario del sistema</div></td>
          <td><span class="tipo-f sis">Sistema</span></td>
          <td style="white-space:nowrap"><?= $fecha($i['at'] ?? 0) ?><div class="d"><?= e((string)($i['por'] ?? '')) ?></div></td>
          <td><div class="ops"><?= btn('restaurar_fijo', $k, $csrf, '&#8634; Restaurar', 'restaurar') ?></div></td>
        </tr>
<?php endforeach; ?>
<?php foreach ($creados as $d): $n = array_sum(array_map(fn($s) => count($s['campos'] ?? []), $d['secciones'] ?? [])); ?>
        <tr>
          <td><div class="t"><?= e($d['titulo']) ?></div><div class="d"><?= e(($d['subtitulo'] ?? '') ?: $n . ' campo(s)') ?></div></td>
          <td><span class="tipo-f cre">Creado</span></td>
          <td style="white-space:nowrap"><?= $fecha($d['_papelera']['at'] ?? 0) ?><div class="d"><?= e((string)($d['_papelera']['por'] ?? '')) ?></div></td>
          <td><div class="ops">
            <a class="b" href="formulario.php?papelera=<?= urlencode($d['_clave']) ?>" target="_blank" rel="noopener">Ver</a>
            <?= btn('restaurar', $d['_clave'], $csrf, '&#8634; Restaurar', 'restaurar') ?>
            <?= btn('borrar', $d['_clave'], $csrf, 'Eliminar definitivamente', 'danger', '¿Eliminar DEFINITIVAMENTE «' . $d['titulo'] . '»? Esta acción no se puede deshacer.') ?>
          </div></td>
        </tr>
<?php endforeach; ?>
      </tbody></table></div>
<?php endif; ?>
    <p class="ayuda"><strong>Restaurar</strong> devuelve el formulario a su lugar (y al menú, si estaba publicado). <strong>Eliminar definitivamente</strong> lo borra para siempre. Los formularios del sistema (actas y bitácora) no se pueden borrar definitivamente, solo restaurar.</p>
  </div>
</main>
<script>
document.querySelectorAll('form[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!confirm(f.dataset.confirm))e.preventDefault()}));
</script>
</body>
</html>

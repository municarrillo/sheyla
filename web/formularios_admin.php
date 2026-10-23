<?php
/**
 * Constructor de formularios — solo administradores.
 */
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
require __DIR__ . '/../actas-app/formularios.php';
$yo = actas_requerir_login();
$soyAdmin = actas_es_admin(actas_registro($yo));
if (!$soyAdmin) actas_requerir_acceso($yo, 'constructor');
/** Los administradores administran todos los formularios; los demás, solo los que ellos crearon. */
$mio = fn(?array $f) => $f !== null && ($soyAdmin || (string)($f['autor'] ?? '') === $yo);

function volver(string $tipo, string $msg): never {
    $_SESSION['flash'] = [$tipo, $msg];
    header('Location: formularios_admin.php');
    exit;
}

/* ---------- Guardar (llamada desde el editor, JSON) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in) || !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($in['csrf'] ?? ''))) {
        echo json_encode(['ok' => false, 'error' => 'La sesión expiró. Recargue la página (sus cambios siguen en pantalla: cópielos si es necesario).']); exit;
    }
    $id = (string)($in['id'] ?? '');
    if ($id !== '' && form_cargar($id) === null) { echo json_encode(['ok' => false, 'error' => 'El formulario ya no existe.']); exit; }
    if ($id !== '' && !$mio(form_cargar($id))) { echo json_encode(['ok' => false, 'error' => 'Solo puede modificar los formularios que usted creó.']); exit; }
    [$def, $err] = form_normalizar(is_array($in['def'] ?? null) ? $in['def'] : []);
    if ($def === null) { echo json_encode(['ok' => false, 'error' => $err], JSON_UNESCAPED_UNICODE); exit; }
    $nuevo = form_guardar($def, $yo, $id !== '' ? $id : null);
    if ($nuevo === '') { echo json_encode(['ok' => false, 'error' => 'No se pudo guardar en la base de datos.']); exit; }
    if ($id === '') actas_otorgar($yo, 'f:' . $nuevo);   // quien lo crea tiene acceso automáticamente
    actas_log("usuario=$yo guardó formulario=$nuevo");
    echo json_encode(['ok' => true, 'id' => $nuevo, 'def' => form_cargar($nuevo)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- Acciones de la lista (formulario normal) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!actas_csrf_ok()) volver('err', 'El formulario expiró. Intente de nuevo.');
    if (($_POST['accion'] ?? '') === 'eliminar_varios') {
        $hechos = [];
        foreach ((array)($_POST['creados'] ?? []) as $fid) {
            $fid = (string)$fid; $fx = form_cargar($fid);
            if ($fx !== null && $mio($fx) && form_eliminar($fid, $yo)) { $hechos[] = $fx['titulo']; actas_log("admin=$yo eliminó formulario=$fid"); }
        }
        foreach ($soyAdmin ? (array)($_POST['fijos'] ?? []) : [] as $k) {
            $k = (string)$k;
            if (isset(FORM_FIJOS[$k]) && form_fijo_activo($k) && form_fijo_set($k, true, $yo)) { $hechos[] = FORM_FIJOS[$k]['titulo']; actas_log("admin=$yo retiró formulario del sistema=$k"); }
        }
        if (!$hechos) volver('err', 'No seleccionó ningún formulario.');
        volver('ok', 'Enviado(s) a la papelera: ' . implode(', ', $hechos) . '. Puede restaurarlos desde «Papelera».');
    }
    if (($_POST['accion'] ?? '') === 'restaurar' && $soyAdmin) {
        $k = (string)($_POST['fijo'] ?? '');
        if (!isset(FORM_FIJOS[$k]) || !form_fijo_set($k, false)) volver('err', 'No se pudo restaurar.');
        actas_log("admin=$yo restauró formulario del sistema=$k");
        volver('ok', '«' . FORM_FIJOS[$k]['titulo'] . '» se restauró y vuelve a aparecer en el menú.');
    }
    $id = (string)($_POST['id'] ?? '');
    $f = form_cargar($id);
    if ($f === null) volver('err', 'El formulario no existe.');
    if (!$mio($f)) volver('err', 'Solo puede administrar los formularios que usted creó.');
    switch ($_POST['accion'] ?? '') {
        case 'publicar':
            $f['publicado'] = empty($f['publicado']);
            form_guardar($f, $yo, $id);
            actas_log("admin=$yo " . ($f['publicado'] ? 'publicó' : 'ocultó') . " formulario=$id");
            volver('ok', $f['publicado'] ? "«{$f['titulo']}» ya aparece en el menú." : "«{$f['titulo']}» quedó oculto del menú.");
        case 'duplicar':
            $f['titulo'] = form_txt($f['titulo'] . ' (copia)', 120);
            $f['publicado'] = false;
            $f['version'] = 0;
            unset($f['creado'], $f['autor']);
            $n = form_guardar($f, $yo, null);
            if ($n !== '') actas_otorgar($yo, 'f:' . $n);
            actas_log("admin=$yo duplicó formulario=$id como $n");
            volver('ok', "Se creó «{$f['titulo']}» (oculto). Edítelo y publíquelo cuando esté listo.");
        case 'eliminar':
            if (!form_eliminar($id, $yo)) volver('err', 'No se pudo eliminar.');
            actas_log("admin=$yo eliminó formulario=$id");
            volver('ok', "«{$f['titulo']}» se envió a la papelera. Puede restaurarlo desde «Papelera».");
    }
    volver('err', 'Acción no reconocida.');
}

$csrf = actas_csrf();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$editar = isset($_GET['editar']) ? form_cargar((string)$_GET['editar']) : null;
if ($editar !== null && !$mio($editar)) { $editar = null; $flash = ['err', 'Solo puede editar los formularios que usted creó.']; }
$modoEditor = $editar !== null || isset($_GET['nuevo']);
$lista = $modoEditor ? [] : array_values(array_filter(form_listar(), $mio));
$fecha = fn($t) => $t ? date('d/m/Y H:i', (int)$t) : '—';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Constructor de formularios · Actas TIC</title>
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

/* ---------- Constructor ---------- */
.sheet,.top{--w:1100px}
.vacio p{margin:6px 0 14px}
.estado{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;padding:3px 9px;border-radius:999px;border:1px solid currentColor}
.estado.pub{color:var(--ok)}.estado.bor{color:var(--warn)}
td .t{font-weight:700}td .d{font-size:12.5px;color:var(--mute)}
.ed-top{display:grid;grid-template-columns:2fr 1fr;gap:12px 18px}
textarea{width:100%;font:inherit;color:var(--ink);background:var(--field);border:1px solid var(--line);border-radius:6px;padding:8px 10px;resize:vertical}
textarea:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 25%,transparent)}
input[type=number]{width:100%;font:inherit;color:var(--ink);background:var(--field);border:1px solid var(--line);border-radius:6px;padding:8px 10px}
.chks{display:flex;gap:18px;flex-wrap:wrap;margin-top:6px}
.chks label,.prop .ck{display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0;font-size:14px;font-weight:500;color:var(--ink);margin:0;cursor:pointer}
.chks input,.prop .ck input{width:17px;height:17px;accent-color:var(--accent)}
.sec{border:1px solid var(--line);border-radius:10px;margin:0 0 16px;background:var(--paper);box-shadow:0 1px 2px rgba(20,35,55,.05)}
.sec-h{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--band);border-bottom:1px solid var(--line);border-radius:10px 10px 0 0}
.sec-h .num{flex:none;display:grid;place-items:center;width:26px;height:26px;border-radius:50%;background:var(--accent);color:var(--paper);font-weight:700;font-size:13px}
.sec-h input{font-weight:700;color:var(--accent);background:var(--paper)}
.sec-b{padding:12px}
.sec-cond{padding:0 12px 10px}
.ico{display:inline-grid;place-items:center;width:30px;height:30px;padding:0;border-radius:6px;border:1px solid var(--line);background:var(--paper);color:var(--accent);cursor:pointer;font-size:14px;flex:none}
.ico:hover:not(:disabled){border-color:var(--accent);background:var(--band)}
.ico:disabled{opacity:.35;cursor:not-allowed}
.ico.danger{color:var(--err)}.ico.danger:hover:not(:disabled){border-color:var(--err);background:color-mix(in srgb,var(--err) 10%,transparent)}
.cmp{border:1px solid color-mix(in srgb,var(--line) 80%,transparent);border-radius:8px;margin:0 0 8px;background:var(--paper)}
.cmp.abierto{border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 12%,transparent)}
.cmp-h{display:flex;align-items:center;gap:10px;padding:8px 10px;cursor:pointer}
.cmp-h .tp{flex:none;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--accent);background:var(--band);border-radius:5px;padding:3px 7px;min-width:96px;text-align:center}
.cmp-h .et{flex:1;min-width:0;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cmp-h .et.sin{color:var(--mute);font-style:italic;font-weight:400}
.chip{flex:none;font-size:10.5px;font-weight:700;padding:2px 7px;border-radius:999px;border:1px solid currentColor}
.chip.req{color:var(--err)}.chip.cnd{color:var(--gold)}.chip.aut{color:var(--ok)}
.cmp-h .acc{display:flex;gap:4px}
.prop{padding:12px 14px 14px;border-top:1px dashed var(--line);display:grid;grid-template-columns:repeat(2,1fr);gap:10px 16px;background:var(--zebra);border-radius:0 0 8px 8px}
.prop .full{grid-column:1/-1}
.prop>div,.ed-top>div,.sec-h input{min-width:0}
.prop input,.prop select,.prop textarea,.cond select,.cond input{max-width:100%;min-width:0}
.sec-h input{flex:1}
.prop .hint{margin:2px 0 0}
.cond{display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:13.5px}
.cond select,.cond input{width:auto;flex:1 1 150px}
.cond .lb{font-weight:600;color:var(--gold);flex:none}
.add-c{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:6px;padding-top:10px;border-top:1px dashed var(--line)}
.add-c .add-t{font-size:12px;font-weight:700;letter-spacing:.04em;color:var(--mute);margin-right:2px}
.tb{display:inline-flex;align-items:center;gap:6px;font:inherit;font-size:12.5px;font-weight:600;padding:5px 10px;border-radius:999px;border:1px solid var(--line);background:var(--paper);color:var(--accent);cursor:pointer}
.tb:hover{border-color:var(--accent);background:var(--band)}
.tb .ic{display:inline-grid;place-items:center;min-width:18px;font-size:12px;font-weight:700}
.tb.dest{border-color:var(--gold);color:var(--ink);background:color-mix(in srgb,var(--gold) 10%,var(--paper))}
.tb.dest .ic{color:var(--gold)}
.add-s{display:flex;gap:8px;flex-wrap:wrap}
.asa{flex:none;display:inline-grid;place-items:center;width:22px;height:30px;border-radius:5px;color:var(--mute);font-size:17px;line-height:1;cursor:grab;touch-action:none;user-select:none}
.asa:hover{background:var(--band);color:var(--accent)}
.sec-h .asa{height:32px}
body.arrastrando,body.arrastrando *{cursor:grabbing!important;user-select:none}
.fantasma{position:fixed;z-index:500;pointer-events:none;opacity:.92;box-shadow:0 14px 34px rgba(20,35,55,.28);transform:rotate(.6deg);background:var(--paper)}
.hueco{border:2px dashed var(--accent);border-radius:8px;background:color-mix(in srgb,var(--accent) 8%,transparent);margin:0 0 8px}
.hueco-sec{margin:0 0 16px;border-radius:10px}
.barra{position:sticky;bottom:0;z-index:15;display:flex;gap:10px;align-items:center;justify-content:flex-end;flex-wrap:wrap;padding:12px 24px;margin:0 -24px -24px;background:color-mix(in srgb,var(--paper) 92%,transparent);backdrop-filter:blur(6px);border-top:1px solid var(--line)}
.barra .estado-g{margin-right:auto;font-size:13px;color:var(--mute)}
.barra .estado-g.sucio{color:var(--warn);font-weight:600}
.ayuda-c{font-size:13px;color:var(--mute);margin:0 0 14px}
.borrar{border-left-color:var(--err)}
.borrar summary{cursor:pointer;color:var(--err)}
.borrar .d{font-size:13px;color:var(--mute);font-weight:400}
.borrar .grupo{margin-top:14px}
.borrar h4{margin:0 0 8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--mute)}
.borrar .sel{display:flex;align-items:center;gap:10px;padding:9px 12px;margin:0 0 6px;border:1px solid var(--line);border-radius:8px;background:var(--paper);text-transform:none;letter-spacing:0;font-size:14px;font-weight:600;color:var(--ink);cursor:pointer}
.borrar .sel input{width:18px;height:18px;accent-color:var(--err)}
.borrar .sel em{margin-left:auto;font-style:normal;font-size:11.5px;font-weight:600;color:var(--mute)}
.borrar .sel:has(input:checked){border-color:var(--err);background:color-mix(in srgb,var(--err) 7%,var(--paper))}
.borrar .sel.off{opacity:.55;cursor:not-allowed}
.borrar .acts-b{display:flex;gap:8px;justify-content:flex-end;margin-top:10px}
.borrar .restaurar{padding-top:12px;border-top:1px dashed var(--line)}
.borrar .restaurar form{margin:0 6px 6px 0}
@media(max-width:720px){.ed-top,.prop{grid-template-columns:1fr}.cmp-h .tp{min-width:0}.cmp-h .chip{display:none}.barra{padding:10px 14px;margin:0 -14px -14px}}
.colores{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}
.colores .sw{width:34px;height:34px;border-radius:50%;border:3px solid var(--paper,#fff);background:var(--c);box-shadow:0 0 0 1px var(--line,#d3dbe5);cursor:pointer;color:#fff;font-weight:700;font-size:13px;padding:0;transition:transform .15s,box-shadow .15s}
.colores .sw:hover{transform:scale(1.12)}
.colores .sw.on{box-shadow:0 0 0 3px var(--c),0 0 0 5px color-mix(in srgb,var(--c) 30%,transparent);transform:scale(1.08)}
.colores .sw:first-child{background-image:var(--c)}
.colores .sw.on:not(:first-child)::before{content:"\2713";font-size:16px;line-height:28px}
</style>
<?php require_once __DIR__ . '/../actas-app/tema.php'; tema_estilos(); ?>
</head>
<body>
<?php tema_barra($modoEditor ? 'formularios_admin.php' : 'index.php', $modoEditor ? 'Volver a la lista' : 'Volver al menú', $yo, $modoEditor ? '1180px' : '1100px'); ?>
<main class="sheet">
  <header class="hd">
    <img src="<?= ESCUDO_MINI ?>" alt="Escudo de la Municipalidad de Carrillo">
    <div><div class="eyebrow">Municipalidad de Carrillo · TIC</div><h1>Constructor de formularios</h1></div>
  </header>
  <div class="body">
<?php if ($flash): ?>
    <div class="msg <?= e($flash[0]) ?>" role="<?= $flash[0] === 'err' ? 'alert' : 'status' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if (!$modoEditor): ?>
    <div class="bar"><h2>Formularios creados</h2><?php $enPap = count(form_papelera_listar()) + count(form_fijos_eliminados()); ?><span><?php if ($soyAdmin): ?><a class="b" href="papelera.php">&#9851; Papelera<?= $enPap ? " ($enPap)" : '' ?></a> <?php endif; ?><a class="b danger" href="formularios_admin.php?eliminar=1#borrar">&#128465; Eliminar formularios</a> <a class="b" href="formularios_admin.php?nuevo=1&amp;ejemplo=1">Ver un ejemplo</a> <a class="b p big" href="formularios_admin.php?nuevo=1">+ Nuevo formulario</a></span></div>
<?php $elim = form_fijos_eliminados(); ?>
    <details class="panel borrar" id="borrar"<?= isset($_GET['eliminar']) ? ' open' : '' ?>>
      <summary><strong>&#128465; Eliminar formularios</strong> <span class="d">— seleccione uno o varios</span></summary>
      <form method="post" id="f-borrar">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="eliminar_varios">
<?php if ($soyAdmin): ?>
        <div class="grupo"><h4>Formularios del sistema</h4>
<?php foreach (FORM_FIJOS as $k => $fx): $act = form_fijo_activo($k); ?>
          <label class="sel<?= $act ? '' : ' off' ?>"><input type="checkbox" name="fijos[]" value="<?= e($k) ?>" data-t="<?= e($fx['titulo']) ?>"<?= $act ? '' : ' disabled' ?>> <span><?= e($fx['titulo']) ?></span><?= $act ? '' : ' <em>(eliminado)</em>' ?></label>
<?php endforeach; ?>
        </div>
<?php endif; ?>
        <div class="grupo"><h4><?= $soyAdmin ? 'Formularios creados con el constructor' : 'Formularios creados por usted' ?></h4>
<?php if (!$lista): ?>
          <p class="d">No hay formularios creados.</p>
<?php endif; foreach ($lista as $fx): ?>
          <label class="sel"><input type="checkbox" name="creados[]" value="<?= e($fx['id']) ?>" data-t="<?= e($fx['titulo']) ?>"> <span><?= e($fx['titulo']) ?></span> <em><?= !empty($fx['publicado']) ? 'Publicado' : 'Oculto' ?></em></label>
<?php endforeach; ?>
        </div>
        <p class="d">Los formularios eliminados van a la <?= $soyAdmin ? '<a href="papelera.php">Papelera</a>, desde donde se pueden ver y restaurar' : 'Papelera; si necesita recuperar uno, pídaselo a un administrador' ?>. Lo ya llenado sigue guardado en los navegadores y en los PDF impresos.</p>
        <div class="acts-b"><a class="b" href="formularios_admin.php">Cancelar</a><button type="submit" class="b danger big" id="btn-borrar" disabled>Eliminar seleccionados (0)</button></div>
      </form>
<?php if ($elim && $soyAdmin): ?>
      <p class="d" style="margin-top:12px">Hay formularios del sistema en la <a href="papelera.php">Papelera</a>.</p>
<?php endif; ?>
    </details>
<?php if (!$lista): ?>
    <div class="vacio panel"><strong>Aún no hay formularios.</strong><p>Cree uno desde cero con «+ Nuevo formulario», o abra «Ver un ejemplo» para ver cómo funcionan las condiciones, el autollenado y las validaciones.</p></div>
<?php else: ?>
    <div class="tw"><table style="min-width:820px">
      <thead><tr><th>Formulario</th><th>Estado</th><th>Campos</th><th>Modificado</th><th style="text-align:right">Acciones</th></tr></thead>
      <tbody>
<?php foreach ($lista as $f): $n = array_sum(array_map(fn($s) => count($s['campos']), $f['secciones'])); ?>
        <tr>
          <td><div class="t"><?= e($f['titulo']) ?></div><div class="d"><?= e($f['subtitulo'] ?: ($f['descripcion'] ?? '')) ?></div></td>
          <td><span class="estado <?= !empty($f['publicado']) ? 'pub' : 'bor' ?>"><?= !empty($f['publicado']) ? 'Publicado' : 'Oculto' ?></span></td>
          <td><?= $n ?> en <?= count($f['secciones']) ?> secc.</td>
          <td style="white-space:nowrap"><?= $fecha($f['modificado'] ?? 0) ?><div class="d"><?= e((string)($f['modificado_por'] ?? '')) ?></div></td>
          <td><div class="ops">
            <a class="b" href="formularios_admin.php?editar=<?= urlencode($f['id']) ?>">Editar</a>
            <a class="b" href="formulario.php?id=<?= urlencode($f['id']) ?>" target="_blank" rel="noopener">Abrir</a>
            <form method="post" class="inl"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="publicar"><button class="b" type="submit"><?= !empty($f['publicado']) ? 'Ocultar' : 'Publicar' ?></button></form>
            <form method="post" class="inl"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="duplicar"><button class="b" type="submit">Duplicar</button></form>
            <form method="post" class="inl" data-confirm="¿Enviar «<?= e($f['titulo']) ?>» a la papelera? Dejará de aparecer en el menú, pero podrá restaurarlo."><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="accion" value="eliminar"><button class="b danger" type="submit">Eliminar</button></form>
          </div></td>
        </tr>
<?php endforeach; ?>
      </tbody></table></div>
<?php endif; ?>
    <p class="ayuda"><strong>Publicado:</strong> aparece en el menú de los usuarios que tienen acceso (el administrador lo asigna en «Mantenimiento de usuarios»; quien crea un formulario recibe acceso automáticamente). <strong>Oculto:</strong> solo los administradores y quien lo creó lo pueden abrir para probarlo. Lo que se llena en cada formulario se guarda en el navegador de cada PC.</p>
<?php else: ?>
    <div id="editor"></div>
<?php endif; ?>
  </div>
</main>
<script>
document.querySelectorAll('form[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!confirm(f.dataset.confirm))e.preventDefault()}));
(()=>{const f=document.getElementById('f-borrar');if(!f)return;const b=document.getElementById('btn-borrar');
  const sel=()=>[...f.querySelectorAll('input[type=checkbox]:checked')];
  f.addEventListener('change',()=>{const n=sel().length;b.disabled=!n;b.textContent='Eliminar seleccionados ('+n+')'});
  f.addEventListener('submit',e=>{const t=sel().map(i=>'• '+i.dataset.t).join('\n');
    if(!confirm('¿Eliminar estos formularios?\n\n'+t+'\n\nIrán a la Papelera, desde donde se pueden restaurar.'))e.preventDefault()});})();
</script>
<?php if ($modoEditor): ?>
<script>
const CSRF=<?= form_json_script($csrf) ?>;
let ID=<?= form_json_script($editar['id'] ?? '') ?>;
let F=<?= form_json_script($editar ?? null) ?>;
const EJEMPLO=<?= isset($_GET['ejemplo']) ? 'true' : 'false' ?>;
const TIPOS={
  texto:'Texto corto',parrafo:'Texto largo',numero:'Número',fecha:'Fecha',hora:'Hora',correo:'Correo',
  lista:'Lista desplegable',unica:'Opción única',multiple:'Varias opciones',checklist:'Checklist (lista de verificación)',
  tabla:'Tabla',firma:'Firma',info:'Texto informativo',aceptar:'Casilla de aceptación'};
const ICONOS={texto:'Aa',parrafo:'¶',numero:'#',fecha:'📅',hora:'🕒',correo:'@',lista:'▾',unica:'◉',multiple:'☑',checklist:'✔',tabla:'▦',firma:'✍',info:'ℹ',aceptar:'☐'};
const ANCHO_OK=['texto','numero','fecha','hora','correo','lista','unica','firma'];
const OPS={igual:'es igual a',distinto:'es distinto de',contiene:'contiene',marcado:'está marcado',no_marcado:'no está marcado',lleno:'tiene respuesta',vacio:'está vacío',mayor:'es mayor que',menor:'es menor que'};
const OPS_POR_TIPO={texto:['igual','distinto','contiene','lleno','vacio'],parrafo:['contiene','lleno','vacio'],correo:['igual','contiene','lleno','vacio'],
  fecha:['igual','lleno','vacio'],hora:['igual','lleno','vacio'],numero:['igual','mayor','menor','lleno','vacio'],
  lista:['igual','distinto','lleno','vacio'],unica:['igual','distinto','lleno','vacio'],multiple:['igual','distinto','lleno','vacio'],
  aceptar:['marcado','no_marcado'],checklist:['marcado','no_marcado']};
const SIN_VALOR=['lleno','vacio','marcado','no_marcado'];
const $=s=>document.querySelector(s);
const rid=p=>p+Math.random().toString(36).slice(2,10);
function el(tag,attrs,...hijos){const n=document.createElement(tag);for(const[k,v]of Object.entries(attrs||{})){if(v===null||v===undefined||v===false)continue;if(k==='class')n.className=v;else if(k==='text')n.textContent=v;else if(k.startsWith('on'))n[k]=v;else n.setAttribute(k,v===true?'':v)}for(const h of hijos.flat())if(h!==null&&h!==undefined)n.append(h);return n}

function campoNuevo(tipo){const c={id:rid('c'),tipo,etiqueta:'',requerido:false,ayuda:'',ancho:ANCHO_OK.includes(tipo)?'medio':'completo',cond:null};
  if(['lista','unica','multiple'].includes(tipo))c.opciones=['Opción 1','Opción 2'];
  if(tipo==='checklist'){c.etiqueta='Puntos a verificar';c.opciones=['Punto 1','Punto 2','Punto 3'];c.editable=true}
  if(tipo==='tabla'){c.columnas=['Descripción','Marca','Modelo','Serie'];c.filas=1;c.max_filas=20;c.numerada=true}
  if(tipo==='firma'){c.etiqueta='Firma del responsable';c.con_nombre=true;c.con_cedula=false;c.con_fecha=true;c.requerido=true;c.ancho='medio'}
  if(tipo==='info'){c.etiqueta='';c.texto='Escriba aquí el texto que debe leer quien llena el formulario.'}
  if(tipo==='aceptar')c.etiqueta='He leído y acepto lo indicado.';
  if(['texto','fecha','hora','parrafo','numero'].includes(tipo))c.auto='';
  if(tipo==='texto')c.formato='';
  if(tipo==='numero'){c.min=null;c.max=null}
  return c}
const VACIO=()=>({titulo:'',subtitulo:'',descripcion:'',publicado:true,numeracion:true,secciones:[{id:rid('s'),titulo:'Información general',cond:null,campos:[
  Object.assign(campoNuevo('fecha'),{etiqueta:'Fecha',auto:'fecha',requerido:true}),
  Object.assign(campoNuevo('texto'),{etiqueta:'Técnico responsable',requerido:true})]},
  {id:rid('s'),titulo:'Firmas',cond:null,campos:[Object.assign(campoNuevo('firma'),{etiqueta:'Firma del técnico responsable'})]}]});
function ejemplo(){const s1=rid('s'),tipo=rid('c'),falla=rid('c'),lap=rid('c');return{titulo:'Reporte de falla de equipo',subtitulo:'Ejemplo del constructor',descripcion:'Ejemplo: pruebe a elegir «Laptop» o responder «Sí» a la falla para ver cómo aparecen campos nuevos.',publicado:false,numeracion:true,secciones:[
  {id:s1,titulo:'Datos del reporte',cond:null,campos:[
    Object.assign(campoNuevo('fecha'),{etiqueta:'Fecha',auto:'fecha',requerido:true}),
    Object.assign(campoNuevo('hora'),{etiqueta:'Hora',auto:'hora'}),
    Object.assign(campoNuevo('texto'),{etiqueta:'Técnico',auto:'nombre',requerido:true}),
    Object.assign(campoNuevo('texto'),{etiqueta:'Cédula del colaborador',formato:'cedula',requerido:true}),
    Object.assign(campoNuevo('correo'),{etiqueta:'Correo institucional'}),
    Object.assign(campoNuevo('unica'),{id:tipo,etiqueta:'Tipo de equipo',opciones:['All in One','Laptop','Tablet'],requerido:true}),
    Object.assign(campoNuevo('aceptar'),{id:lap,etiqueta:'El colaborador entregó también el cargador',cond:{campo:tipo,op:'igual',valor:'Laptop'}})]},
  {id:rid('s'),titulo:'Diagnóstico',cond:null,campos:[
    Object.assign(campoNuevo('unica'),{id:falla,etiqueta:'¿Se encontró una falla?',opciones:['Sí','No'],requerido:true}),
    Object.assign(campoNuevo('lista'),{etiqueta:'Tipo de falla',opciones:['Hardware','Software','Red','Periférico'],requerido:true,cond:{campo:falla,op:'igual',valor:'Sí'}}),
    Object.assign(campoNuevo('parrafo'),{etiqueta:'Descripción de la falla',requerido:true,ancho:'completo',cond:{campo:falla,op:'igual',valor:'Sí'}}),
    Object.assign(campoNuevo('checklist'),{etiqueta:'Revisión realizada',opciones:['Encendido y POST','Antivirus actualizado','Actualizaciones de Windows','Conexión a la red'],editable:true})]},
  {id:rid('s'),titulo:'Firma',cond:null,campos:[Object.assign(campoNuevo('firma'),{etiqueta:'Firma del técnico'})]}]}}

if(!F)F=EJEMPLO?ejemplo():VACIO();
F.secciones.forEach(s=>{s.cond=s.cond||null;s.campos.forEach(c=>c.cond=c.cond||null)});
let abierto=null,sucio=false;
function marcar(){sucio=true;const g=$('#estado-g');if(g){g.textContent='Cambios sin guardar';g.classList.add('sucio')}}
addEventListener('beforeunload',e=>{if(sucio){e.preventDefault();e.returnValue=''}});
const todos=()=>F.secciones.flatMap(s=>s.campos);
const mover=(arr,i,d)=>{const j=i+d;if(j<0||j>=arr.length)return;[arr[i],arr[j]]=[arr[j],arr[i]]};

/* ---------- Controles reutilizables ---------- */
function inp(obj,k,attrs={}){const{vivo,...rest}=attrs;const i=el('input',Object.assign({type:'text',value:obj[k]??''},rest));i.oninput=()=>{obj[k]=rest.type==='number'?(i.value===''?null:Number(i.value)):i.value;marcar();if(vivo)vivo()};return i}
function area(obj,k,rows=3,lineas=false,vivo){const t=el('textarea',{rows});t.value=lineas?(obj[k]||[]).join('\n'):(obj[k]||'');
  t.oninput=()=>{obj[k]=lineas?t.value.split('\n').map(x=>x.trim()).filter(Boolean):t.value;marcar();if(vivo)vivo()};return t}
function chk(obj,k,texto,vivo){const i=el('input',{type:'checkbox'});i.checked=!!obj[k];i.onchange=()=>{obj[k]=i.checked;marcar();if(vivo)vivo()};return el('label',{class:'ck'},i,' '+texto)}
function sel(obj,k,opciones,vivo){const s=el('select',{},Object.entries(opciones).map(([v,t])=>el('option',{value:v,text:t})));s.value=obj[k]??'';s.onchange=()=>{obj[k]=s.value;marcar();if(vivo)vivo()};return s}
const COLORES=<?= json_encode(FORM_COLORES, JSON_UNESCAPED_UNICODE) ?>;
function colores(obj){const w=el('div',{class:'colores',role:'radiogroup','aria-label':'Color del formulario'});
  const op=(k,nom,hex)=>{const b=el('button',{type:'button',class:'sw'+((obj.color||'')===k?' on':''),title:nom,'aria-label':nom,role:'radio','aria-checked':(obj.color||'')===k?'true':'false',style:'--c:'+hex});
    if(!k)b.textContent='A';b.onclick=()=>{obj.color=k;marcar();w.querySelectorAll('.sw').forEach(x=>{x.classList.remove('on');x.setAttribute('aria-checked','false')});b.classList.add('on');b.setAttribute('aria-checked','true')};return b};
  w.append(op('','Automático','linear-gradient(135deg,#1f5fa8,#0f8a7e 50%,#d9731a)'));
  Object.entries(COLORES).forEach(([k,[nom,hex]])=>w.append(op(k,nom,hex)));return w}
function campo(lbl,ctrl,hint,full){return el('div',{class:full?'full':''},el('label',{text:lbl}),ctrl,hint?el('div',{class:'hint',text:hint}):null)}

/* ---------- Condición (mostrar solo si…) ---------- */
function editorCond(obj,propioId){
  const fuentes=todos().filter(c=>c.id!==propioId&&OPS_POR_TIPO[c.tipo]);
  const box=el('div',{class:'cond'});
  function pintar(){box.innerHTML='';
    const fsel=el('select',{},el('option',{value:'',text:'Mostrar siempre'}),fuentes.map(c=>el('option',{value:c.id,text:'Mostrar solo si «'+(c.etiqueta||TIPOS[c.tipo])+'»'})));
    fsel.value=obj.cond?.campo||'';
    fsel.onchange=()=>{if(!fsel.value)obj.cond=null;else{const src=fuentes.find(c=>c.id===fsel.value);obj.cond={campo:fsel.value,op:OPS_POR_TIPO[src.tipo][0],valor:(src.opciones||[])[0]||''}}marcar();pintar();refrescarCabeceras()};
    box.append(el('span',{class:'lb',text:'⟲ Condición'}),fsel);
    if(!obj.cond)return;
    const src=fuentes.find(c=>c.id===obj.cond.campo);if(!src){obj.cond=null;pintar();return}
    const osel=el('select',{},OPS_POR_TIPO[src.tipo].map(o=>el('option',{value:o,text:src.tipo==='multiple'&&o==='igual'?'incluye':src.tipo==='multiple'&&o==='distinto'?'no incluye':OPS[o]})));
    osel.value=obj.cond.op;osel.onchange=()=>{obj.cond.op=osel.value;marcar();pintar()};box.append(osel);
    if(SIN_VALOR.includes(obj.cond.op))return;
    if(src.opciones&&['lista','unica','multiple'].includes(src.tipo)){const v=el('select',{},src.opciones.map(o=>el('option',{value:o,text:o})));v.value=obj.cond.valor;if(v.value!==obj.cond.valor){obj.cond.valor=src.opciones[0]||''}v.onchange=()=>{obj.cond.valor=v.value;marcar()};box.append(v)}
    else box.append(inp(obj.cond,'valor',{placeholder:'valor',type:src.tipo==='numero'?'number':'text'}))}
  pintar();return box}

/* ---------- Propiedades de un campo ---------- */
function propiedades(c){const p=el('div',{class:'prop'});const viva=()=>refrescarCabeceras();
  if(c.tipo==='info'){p.append(campo('Título (opcional)',inp(c,'etiqueta',{vivo:viva}),null,true),campo('Texto que se mostrará',area(c,'texto',6),'Por ejemplo, términos y condiciones o instrucciones.',true))}
  else p.append(campo(c.tipo==='aceptar'?'Texto de la casilla':'Etiqueta (pregunta)',inp(c,'etiqueta',{vivo:viva,maxlength:200}),null,true));
  if(['lista','unica','multiple'].includes(c.tipo))p.append(campo('Opciones (una por línea)',area(c,'opciones',4,true,viva),null,true));
  if(c.tipo==='checklist'){p.append(campo('Puntos a verificar (uno por línea)',area(c,'opciones',6,true),null,true),el('div',{class:'full'},chk(c,'editable','Permitir modificar, eliminar y agregar puntos al llenarlo')))}
  if(c.tipo==='tabla'){p.append(campo('Columnas (una por línea)',area(c,'columnas',4,true),'Máximo 12 columnas.',true),
    campo('Filas al iniciar',inp(c,'filas',{type:'number',min:1,max:50})),campo('Máximo de filas',inp(c,'max_filas',{type:'number',min:1,max:200})),
    el('div',{class:'full'},chk(c,'numerada','Numerar las filas (columna «Ítem» que se renumera sola)')))}
  if(c.tipo==='texto'){p.append(campo('Formato que debe cumplir',sel(c,'formato',{'':'Cualquier texto',cedula:'Cédula (9 a 12 dígitos)',telefono:'Teléfono (8 dígitos)',numeros:'Solo números',placa:'Placa / N.° de activo'},viva)),
    campo('Llenar automáticamente con',sel(c,'auto',{'':'(nada)',nombre:'Nombre del usuario conectado',usuario:'Usuario conectado'},viva)))}
  if(c.tipo==='fecha')p.append(campo('Llenar automáticamente',sel(c,'auto',{'':'(nada)',fecha:'Con la fecha de hoy'},viva)));
  if(c.tipo==='hora')p.append(campo('Llenar automáticamente',sel(c,'auto',{'':'(nada)',hora:'Con la hora actual'},viva)));
  if(c.tipo==='numero')p.append(campo('Valor mínimo',inp(c,'min',{type:'number'})),campo('Valor máximo',inp(c,'max',{type:'number'})));
  if(c.tipo==='firma')p.append(el('div',{class:'full chks'},chk(c,'con_nombre','Pedir nombre'),chk(c,'con_cedula','Pedir cédula'),chk(c,'con_fecha','Pedir fecha (se llena con la de hoy)')),
    el('div',{class:'full hint',text:'Dos firmas seguidas con ancho «Media línea» se muestran lado a lado, como en las actas (Entrega / Recibe).'}));
  if(c.tipo!=='info'){p.append(campo('Texto de ayuda (opcional)',inp(c,'ayuda',{placeholder:'Aparece debajo del campo',maxlength:200})));
    if(ANCHO_OK.includes(c.tipo))p.append(campo('Ancho',sel(c,'ancho',{medio:'Media línea',completo:'Línea completa'},viva)));
    p.append(el('div',{class:'full'},chk(c,'requerido',c.tipo==='checklist'?'Obligatorio: todos los puntos deben quedar marcados':'Obligatorio',viva)))}
  const mv=el('select',{'aria-label':'Mover este campo'},el('option',{value:'',text:'— Elegir destino —'}),
    F.secciones.flatMap((x,i)=>[el('option',{value:i+':0',text:'Al inicio de «'+(x.titulo||'Sección '+(i+1))+'»'}),el('option',{value:i+':fin',text:'Al final de «'+(x.titulo||'Sección '+(i+1))+'»'})]));
  mv.onchange=()=>{if(!mv.value)return;const[d,q]=mv.value.split(':');const o=ubicar(c.id);if(!o)return;F.secciones[o[0]].campos.splice(o[1],1);
    const dest=F.secciones[+d].campos;dest.splice(q==='fin'?dest.length:0,0,c);marcar();dibujar();requestAnimationFrame(()=>{const n=document.querySelector('.cmp[data-id="'+c.id+'"]');if(n)n.scrollIntoView({block:'center',behavior:'smooth'})})};
  p.append(campo('Mover a',mv,'También puede arrastrarlo con ⠿.'));
  p.append(el('div',{class:'full'},editorCond(c,c.id),el('div',{class:'hint',text:'Con una condición, este campo solo aparece (y solo se valida) cuando se cumple.'})));
  return p}

/* ---------- Dibujo del editor ---------- */
function cabecera(c){const h=el('div',{class:'cmp-h'});
  const asa=el('span',{class:'asa',title:'Arrastre para mover este campo','aria-hidden':'true'},'⠿');
  asa.onpointerdown=e=>{const pos=ubicar(c.id);if(pos)iniciarArrastre(e,'campo',pos[0],pos[1])};
  h.append(asa,el('span',{class:'tp',text:TIPOS[c.tipo]}),el('span',{class:'et'+((c.etiqueta||c.texto)?'':' sin'),text:c.etiqueta||(c.tipo==='info'?(c.texto||'').slice(0,80):'(sin etiqueta)')}));
  if(c.requerido)h.append(el('span',{class:'chip req',text:'Obligatorio'}));
  if(c.cond)h.append(el('span',{class:'chip cnd',text:'Condicional'}));
  if(c.auto)h.append(el('span',{class:'chip aut',text:'Automático'}));
  if(c.formato)h.append(el('span',{class:'chip aut',text:{cedula:'Cédula',telefono:'Teléfono',numeros:'Números',placa:'Placa'}[c.formato]}));
  return h}
function refrescarCabeceras(){document.querySelectorAll('.cmp').forEach(n=>{const c=todos().find(x=>x.id===n.dataset.id);if(!c)return;const v=n.querySelector('.cmp-h');const nv=cabecera(c);nv.append(v.querySelector('.acc'));nv.onclick=v.onclick;nv.querySelector('.asa').onpointerdown=v.querySelector('.asa').onpointerdown;v.replaceWith(nv)})}
function dibujar(){const ed=$('#editor');const y=scrollY;ed.innerHTML='';
  const d=el('section',{class:'panel'},el('h3',{text:'Datos del formulario'}),el('div',{class:'ed-top'},
    campo('Título *',inp(F,'titulo',{maxlength:120,placeholder:'Ej. Revisión mensual de UPS'})),
    campo('Subtítulo',inp(F,'subtitulo',{maxlength:120,placeholder:'Opcional'})),
    campo('Descripción o instrucciones',area(F,'descripcion',2),'Aparece debajo del encabezado.',true),
    campo('Color en el menú principal',colores(F),'Así se distingue el formulario en el tablero. «Automático» le asigna uno fijo.',true),
    el('div',{class:'full chks'},chk(F,'publicado','Publicado: aparece en el menú de los usuarios con acceso'),chk(F,'numeracion','Numeración consecutiva (N.° 001-2026)'))));
  ed.append(d,el('p',{class:'ayuda-c',text:'Haga clic en un campo para editarlo. Use las flechas para ordenar. Las condiciones permiten que un campo o una sección aparezcan solo según otra respuesta.'}));
  F.secciones.forEach((s,si)=>{
    const sec=el('section',{class:'sec'});
    const tit=inp(s,'titulo',{maxlength:120,'aria-label':'Título de la sección'});
    const asaS=el('span',{class:'asa',title:'Arrastre para mover la sección completa','aria-hidden':'true'},'⠿');asaS.onpointerdown=e=>iniciarArrastre(e,'sec',si);
    sec.append(el('div',{class:'sec-h'},asaS,el('span',{class:'num',text:si+1}),tit,
      el('button',{type:'button',class:'ico',title:'Subir sección',disabled:si===0,onclick:()=>{mover(F.secciones,si,-1);marcar();dibujar()}},'↑'),
      el('button',{type:'button',class:'ico',title:'Bajar sección',disabled:si===F.secciones.length-1,onclick:()=>{mover(F.secciones,si,1);marcar();dibujar()}},'↓'),
      el('button',{type:'button',class:'ico danger',title:'Eliminar sección',disabled:F.secciones.length===1,onclick:()=>{if(!confirm('¿Eliminar la sección «'+(s.titulo||si+1)+'» y sus '+s.campos.length+' campo(s)?'))return;F.secciones.splice(si,1);limpiarConds();marcar();dibujar()}},'✕')));
    const b=el('div',{class:'sec-b'});
    if(!s.campos.length)b.append(el('p',{class:'hint',text:'Esta sección no tiene campos todavía.'}));
    s.campos.forEach((c,ci)=>{const n=el('div',{class:'cmp'+(abierto===c.id?' abierto':''),'data-id':c.id});const h=cabecera(c);
      h.onclick=e=>{if(e.target.closest('.acc')||e.target.closest('.asa'))return;abierto=abierto===c.id?null:c.id;dibujar()};
      h.append(el('span',{class:'acc'},
        el('button',{type:'button',class:'ico',title:'Subir',disabled:ci===0&&si===0,onclick:()=>{if(ci>0)mover(s.campos,ci,-1);else{F.secciones[si-1].campos.push(s.campos.shift())}marcar();dibujar()}},'↑'),
        el('button',{type:'button',class:'ico',title:'Bajar',disabled:ci===s.campos.length-1&&si===F.secciones.length-1,onclick:()=>{if(ci<s.campos.length-1)mover(s.campos,ci,1);else{F.secciones[si+1].campos.unshift(s.campos.pop())}marcar();dibujar()}},'↓'),
        el('button',{type:'button',class:'ico',title:'Duplicar',onclick:()=>{const k=JSON.parse(JSON.stringify(c));k.id=rid('c');k.etiqueta=k.etiqueta?k.etiqueta+' (copia)':'';s.campos.splice(ci+1,0,k);abierto=k.id;marcar();dibujar()}},'⧉'),
        el('button',{type:'button',class:'ico danger',title:'Eliminar',onclick:()=>{const dep=todos().filter(x=>x.cond?.campo===c.id).length+F.secciones.filter(x=>x.cond?.campo===c.id).length;
          if(!confirm('¿Eliminar el campo «'+(c.etiqueta||TIPOS[c.tipo])+'»?'+(dep?'\n\n'+dep+' condición(es) que dependen de él se quitarán.':'')))return;s.campos.splice(ci,1);limpiarConds();marcar();dibujar()}},'✕')));
      n.append(h);if(abierto===c.id)n.append(propiedades(c));b.append(n)});
    const agregar=tipo=>{const c=campoNuevo(tipo);let pos=s.campos.length;if(tipo!=='firma')while(pos>0&&s.campos[pos-1].tipo==='firma')pos--;s.campos.splice(pos,0,c);abierto=c.id;marcar();dibujar();
      requestAnimationFrame(()=>{const n=document.querySelector('.cmp[data-id="'+c.id+'"] .prop input');if(n){n.focus();n.scrollIntoView({block:'center'})}})};
    b.append(el('div',{class:'add-c'},el('span',{class:'add-t',text:'+ Agregar campo:'}),
      Object.entries(TIPOS).map(([v,t])=>el('button',{type:'button',class:'tb'+(v==='checklist'||v==='firma'?' dest':''),'data-tipo':v,title:'Agregar '+t,onclick:()=>agregar(v)},el('span',{class:'ic','aria-hidden':'true',text:ICONOS[v]}),v==='checklist'?'Checklist':t))));
    sec.append(b,el('div',{class:'sec-cond'},editorCond(s,s.id)));ed.append(sec)});
  const nuevaSec=(titulo,campos)=>{const ult=F.secciones[F.secciones.length-1];const conFirma=x=>x&&x.campos.some(c=>c.tipo==='firma'),soloFirmas=x=>x&&x.campos.length&&x.campos.every(c=>c.tipo==='firma');
    const ns={id:rid('s'),titulo,cond:null,campos};if(conFirma(ult)&&!soloFirmas(ns))F.secciones.splice(F.secciones.length-1,0,ns);else F.secciones.push(ns);abierto=campos[0]?campos[0].id:null;marcar();dibujar();scrollTo({top:document.body.scrollHeight,behavior:'smooth'})};
  ed.append(el('div',{class:'add-s'},
    el('button',{type:'button',class:'b',onclick:()=>nuevaSec('Nueva sección',[])},'+ Agregar sección'),
    el('button',{type:'button',class:'b',onclick:()=>nuevaSec('Checklist',[campoNuevo('checklist')])},'✔ + Sección de checklist'),
    el('button',{type:'button',class:'b',onclick:()=>nuevaSec('Firmas',[Object.assign(campoNuevo('firma'),{etiqueta:'Entrega: firma del colaborador',con_cedula:true}),Object.assign(campoNuevo('firma'),{etiqueta:'Recibe: firma del funcionario de TI'})])},'✍ + Sección de firmas (entrega y recibe)')));
  const g=el('span',{class:'estado-g'+(sucio?' sucio':''),id:'estado-g',text:sucio?'Cambios sin guardar':(ID?(F.publicado?'Guardado · Publicado: aparece en el menú':'Guardado · Oculto: NO aparece en el menú'):'Nuevo formulario (sin guardar)')});
  ed.append(el('div',{class:'barra'},g,
    el('a',{class:'b big',href:'index.php'},'Ir al menú'),
    el('button',{type:'button',class:'b big',onclick:()=>guardar(true)},'Guardar y ver'),
    el('button',{type:'button',class:'b p big',onclick:()=>guardar(false)},'Guardar')));
  scrollTo(0,y)}
/* ---------- Arrastrar y soltar (mouse y pantalla táctil) ---------- */
function ubicar(id){for(let i=0;i<F.secciones.length;i++){const j=F.secciones[i].campos.findIndex(c=>c.id===id);if(j>=0)return[i,j]}return null}
let arr=null;
function iniciarArrastre(e,tipo,si,ci){
  if(e.pointerType==='mouse'&&e.button!==0)return;e.preventDefault();
  const origen=tipo==='campo'?document.querySelector('.cmp[data-id="'+F.secciones[si].campos[ci].id+'"]'):document.querySelectorAll('#editor .sec')[si];
  if(!origen)return;const r=origen.getBoundingClientRect();
  const fant=origen.cloneNode(true);fant.classList.add('fantasma');Object.assign(fant.style,{width:r.width+'px',left:r.left+'px',top:r.top+'px'});document.body.append(fant);
  const ph=el('div',{class:'hueco hueco-'+tipo});ph.style.height=Math.min(r.height,tipo==='sec'?90:r.height)+'px';origen.after(ph);origen.style.display='none';
  arr={tipo,si,ci,origen,fant,ph,dx:e.clientX-r.left,dy:e.clientY-r.top,y:e.clientY};
  document.body.classList.add('arrastrando');addEventListener('pointermove',moverArr);addEventListener('pointerup',soltarArr);addEventListener('pointercancel',soltarArr);
  arr.timer=setInterval(()=>{if(!arr)return;const m=70;if(arr.y<m)scrollBy(0,-14);else if(arr.y>innerHeight-m)scrollBy(0,14)},16)}
function moverArr(e){if(!arr)return;arr.y=e.clientY;Object.assign(arr.fant.style,{left:(e.clientX-arr.dx)+'px',top:(e.clientY-arr.dy)+'px'});
  arr.fant.style.visibility='hidden';const t=document.elementFromPoint(e.clientX,e.clientY);arr.fant.style.visibility='';if(!t)return;
  if(arr.tipo==='campo'){
    const c=t.closest('.cmp');if(c&&c!==arr.origen){const rr=c.getBoundingClientRect();e.clientY<rr.top+rr.height/2?c.before(arr.ph):c.after(arr.ph);return}
    const sec=t.closest('.sec');if(!sec||c)return;const b=sec.querySelector('.sec-b');
    if(t.closest('.sec-h')){const pri=[...b.querySelectorAll('.cmp')].find(n=>n!==arr.origen);pri?pri.before(arr.ph):b.querySelector('.add-c').before(arr.ph)}
    else if(!b.contains(arr.ph)||t.closest('.add-c')||t.closest('.sec-cond'))b.querySelector('.add-c').before(arr.ph)}
  else{const sec=t.closest('#editor .sec');if(sec&&sec!==arr.origen){const rr=sec.getBoundingClientRect();e.clientY<rr.top+rr.height/2?sec.before(arr.ph):sec.after(arr.ph)}}}
function soltarArr(){if(!arr)return;const d=arr;arr=null;clearInterval(d.timer);removeEventListener('pointermove',moverArr);removeEventListener('pointerup',soltarArr);removeEventListener('pointercancel',soltarArr);
  document.body.classList.remove('arrastrando');d.fant.remove();
  if(d.tipo==='campo'){const secEl=d.ph.closest('.sec'),sj=[...document.querySelectorAll('#editor .sec')].indexOf(secEl);
    const idx=[...secEl.querySelectorAll('.cmp,.hueco')].filter(n=>n!==d.origen).indexOf(d.ph);
    if(sj>=0&&idx>=0){const c=F.secciones[d.si].campos.splice(d.ci,1)[0];F.secciones[sj].campos.splice(idx,0,c);if(sj!==d.si||idx!==d.ci)marcar()}}
  else{const lista=[...document.getElementById('editor').children].filter(n=>(n.classList.contains('sec')&&n!==d.origen)||n===d.ph),idx=lista.indexOf(d.ph);
    if(idx>=0){const x=F.secciones.splice(d.si,1)[0];F.secciones.splice(idx,0,x);if(idx!==d.si)marcar()}}
  dibujar()}
function limpiarConds(){const ids=new Set(todos().map(c=>c.id));F.secciones.forEach(s=>{if(s.cond&&!ids.has(s.cond.campo))s.cond=null;s.campos.forEach(c=>{if(c.cond&&!ids.has(c.cond.campo))c.cond=null})})}

async function guardar(ver){const g=$('#estado-g');g.textContent='Guardando…';const nuevo=!ID;
  const win=ver?window.open('about:blank','_blank'):null;
  try{const r=await fetch('formularios_admin.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf:CSRF,id:ID,def:F}),credentials:'same-origin'});
    const j=await r.json();
    if(!j.ok){g.textContent='No se guardó';g.classList.add('sucio');alert(j.error||'No se pudo guardar.');if(win)win.close();return}
    ID=j.id;F=j.def;sucio=false;history.replaceState(null,'','formularios_admin.php?editar='+encodeURIComponent(ID));dibujar();
    const e=$('#estado-g');e.textContent='Guardado ✓ '+new Date().toLocaleTimeString('es-CR',{hour:'2-digit',minute:'2-digit'})+(F.publicado?' · Publicado: ya aparece en el menú':' · Oculto: NO aparece en el menú (marque «Publicado»)');e.classList.toggle('sucio',!F.publicado);
    if(win)win.location='formulario.php?id='+encodeURIComponent(ID);
    else avisoExito({titulo:nuevo?'Formulario creado':'Cambios guardados',texto:'«'+F.titulo+'» '+(nuevo?'se creó y ':'')+'quedó guardado.\n'+(F.publicado?'Ya aparece en el menú de los usuarios con acceso.'+(nuevo?' Usted ya tiene acceso; para dárselo a otras personas, un administrador lo asigna en «Mantenimiento de usuarios».':''):'Está OCULTO: no aparece en el menú hasta que marque «Publicado».'),
      botones:[{t:'Ver formulario',p:true,f:()=>window.open('formulario.php?id='+encodeURIComponent(ID),'_blank')},{t:'Ir al menú',f:()=>{location='index.php'}},{t:'Seguir editando'}]})}
  catch(err){g.textContent='No se guardó';alert('No se pudo conectar con el servidor.');if(win)win.close()}}
dibujar();if(EJEMPLO)marcar();

</script>
<?php endif; ?>
<?php require __DIR__ . '/../actas-app/aviso.php'; ?>
</body>
</html>

<?php
/**
 * Mantenimiento de usuarios de TI — solo administradores.
 */
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
require __DIR__ . '/../actas-app/formularios.php';
$yo = actas_requerir_admin();

/** Formularios a los que se puede dar acceso: los del sistema, préstamos, constructor y los creados. */
function accesos_disponibles(): array {
    $out = [];
    foreach (ACTAS_ACCESOS as $k => $t) if (!isset(FORM_FIJOS[$k]) || form_fijo_activo($k)) $out[$k] = $t;
    foreach (form_listar() as $f) $out['f:' . $f['id']] = $f['titulo'] . (empty($f['publicado']) ? ' (oculto)' : '') . ' · creado por ' . ($f['autor'] ?? '—');
    return $out;
}
function permisos_post(): array {
    $ok = accesos_disponibles();
    return array_values(array_filter(array_map('strval', (array)($_POST['acceso'] ?? [])), fn($k) => isset($ok[$k])));
}
function accesos_html(?array $r, string $pref): void {
    $sel = $r ? actas_permisos($r) : [];
    $marcado = fn($k) => in_array($k, $sel, true) || (str_starts_with($k, 'f:') && in_array('f:*', $sel, true));
    $todos = accesos_disponibles();
    $grupos = ['Formularios del sistema' => array_filter($todos, fn($k) => !str_starts_with($k, 'f:') && $k !== 'constructor', ARRAY_FILTER_USE_KEY),
               'Formularios creados con el constructor' => array_filter($todos, fn($k) => str_starts_with($k, 'f:'), ARRAY_FILTER_USE_KEY),
               'Herramientas' => array_intersect_key($todos, ['constructor' => 1])]; ?>
          <div class="full accesos" data-accesos>
            <label>Acceso a formularios</label>
            <div class="hint" style="margin:0 0 8px">Marque lo que esta persona puede abrir. Si crea formularios, recibe acceso a ellos automáticamente.</div>
            <div class="acc-admin hint" hidden>Los administradores tienen acceso a todos los formularios.</div>
            <div class="acc-grupos">
<?php foreach ($grupos as $g => $items): if (!$items) continue; ?>
              <fieldset><legend><?= e($g) ?> <button type="button" class="lnk" data-todos>Marcar todos</button></legend>
<?php foreach ($items as $k => $t): ?>                <label class="chk"><input type="checkbox" name="acceso[]" value="<?= e($k) ?>"<?= $marcado($k) ? ' checked' : '' ?>> <span><?= e($t) ?></span></label>
<?php endforeach; ?>
              </fieldset>
<?php endforeach; ?>
            </div>
          </div>
<?php }

function volver(string $tipo, string $msg, string $extra = '', string $ir = ''): never {
    $_SESSION['flash'] = [$tipo, $msg, $extra];
    header('Location: usuarios_admin.php' . $ir);
    exit;
}
function limpiar_nombre(string $s): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '');
    return actas_largo($s) > 80 ? (string)preg_replace('/^(.{80}).*$/su', '$1', $s) : $s;
}

/* ---------------- Acciones (POST → redirige) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!actas_csrf_ok()) volver('err', 'El formulario expiró. Intente de nuevo.');
    $acc = (string)($_POST['accion'] ?? '');
    $u = strtolower(trim((string)($_POST['usuario'] ?? '')));
    $us = actas_usuarios();
    $existe = isset($us[$u]);
    if ($acc !== 'crear' && !$existe) volver('err', 'El usuario no existe.');
    $esUltimoAdmin = $existe && actas_es_admin($us[$u]) && actas_activo($us[$u]) && actas_admins_activos($us) <= 1;

    switch ($acc) {
        case 'crear':
            $nombre = limpiar_nombre((string)($_POST['nombre'] ?? ''));
            $correo = strtolower(trim((string)($_POST['correo'] ?? '')));
            $rol = isset(ACTAS_ROLES[$_POST['rol'] ?? '']) ? (string)$_POST['rol'] : 'tecnico';
            $modo = ($_POST['modo'] ?? '') === 'temporal' ? 'temporal' : 'correo';
            if (!actas_usuario_valido($u)) volver('err', 'Usuario inválido: use de 2 a 32 caracteres en minúscula (a-z, 0-9, punto, guion o guion bajo).', '', '?nuevo=1');
            if ($existe) volver('err', "El usuario «{$u}» ya existe.", '', '?nuevo=1');
            if ($nombre === '') volver('err', 'Escriba el nombre completo.', '', '?nuevo=1');
            if (!actas_correo_valido($correo)) volver('err', 'El correo debe ser institucional (@' . actas_config('dominio', 'municarrillo.go.cr') . ').', '', '?nuevo=1');
            foreach ($us as $n => $r) if (strtolower((string)($r['correo'] ?? '')) === $correo) volver('err', "Ese correo ya lo usa «{$n}».", '', '?nuevo=1');
            $temporal = $modo === 'temporal' ? actas_clave_temporal() : bin2hex(random_bytes(24)); // sin temporal: clave aleatoria que nadie conoce
            $us[$u] = [
                'hash' => password_hash($temporal, PASSWORD_BCRYPT, ['cost' => 12]),
                'nombre' => $nombre, 'correo' => $correo, 'rol' => $rol, 'activo' => true, 'permisos' => permisos_post(),
                'forzar' => true, 'cambio' => time(), 'creado' => time(), 'creado_por' => $yo,
            ];
            if (!actas_guardar_usuarios($us)) volver('err', 'No se pudo guardar. Revise los permisos de ' . ACTAS_USERS_FILE . '.');
            actas_log("admin=$yo creó usuario=$u rol=$rol modo=$modo");
            if ($modo === 'temporal') volver('ok', "Usuario «{$u}» creado. Contraseña temporal:", $temporal);
            $err = actas_enviar_enlace($u, 'invitacion');
            if ($err !== '') volver('warn', "Usuario «{$u}» creado, pero no se envió el correo: $err Use «Clave temporal» para darle acceso.");
            volver('ok', "Usuario «{$u}» creado. Se envió a {$correo} un enlace para que cree su contraseña (vence en 24 horas).");

        case 'editar':
            $nombre = limpiar_nombre((string)($_POST['nombre'] ?? ''));
            $correo = strtolower(trim((string)($_POST['correo'] ?? '')));
            $rol = isset(ACTAS_ROLES[$_POST['rol'] ?? '']) ? (string)$_POST['rol'] : 'tecnico';
            if ($nombre === '') volver('err', 'Escriba el nombre completo.', '', '?editar=' . urlencode($u));
            if (!actas_correo_valido($correo)) volver('err', 'El correo debe ser institucional (@' . actas_config('dominio', 'municarrillo.go.cr') . ').', '', '?editar=' . urlencode($u));
            foreach ($us as $n => $r) if ($n !== $u && strtolower((string)($r['correo'] ?? '')) === $correo) volver('err', "Ese correo ya lo usa «{$n}».", '', '?editar=' . urlencode($u));
            if ($rol !== 'admin' && $u === $yo) volver('err', 'No puede quitarse a sí mismo el rol de administrador.', '', '?editar=' . urlencode($u));
            if ($rol !== 'admin' && $esUltimoAdmin) volver('err', 'Debe quedar al menos un administrador activo.', '', '?editar=' . urlencode($u));
            $us[$u]['nombre'] = $nombre;
            $us[$u]['correo'] = $correo;
            $us[$u]['rol'] = $rol;
            $us[$u]['permisos'] = permisos_post();
            if (!actas_guardar_usuarios($us)) volver('err', 'No se pudo guardar.');
            actas_log("admin=$yo editó usuario=$u rol=$rol");
            volver('ok', "Datos de «{$u}» actualizados.");

        case 'estado':
            if ($u === $yo) volver('err', 'No puede desactivar su propia cuenta.');
            $activar = !actas_activo($us[$u]);
            if (!$activar && $esUltimoAdmin) volver('err', 'Debe quedar al menos un administrador activo.');
            $us[$u]['activo'] = $activar;
            if (!actas_guardar_usuarios($us)) volver('err', 'No se pudo guardar.');
            actas_log("admin=$yo " . ($activar ? 'activó' : 'desactivó') . " usuario=$u");
            volver('ok', $activar ? "«{$u}» fue activado." : "«{$u}» fue desactivado: ya no puede ingresar y sus sesiones abiertas se cerraron.");

        case 'enlace':
            $err = actas_enviar_enlace($u, 'reset');
            actas_log("admin=$yo envió enlace a usuario=$u");
            if ($err !== '') volver('err', $err);
            volver('ok', "Se envió a " . ($us[$u]['correo'] ?? '') . " un enlace para restablecer la contraseña (vence en " . (ACTAS_RESET_VIGENCIA / 60) . " minutos).");

        case 'clave':
            $ir = '?editar=' . urlencode($u) . '#clave';
            if ($u === $yo) volver('err', 'Para su propia cuenta use «Cambiar mi contraseña».', '', $ir);
            $c1 = (string)($_POST['clave1'] ?? ''); $c2 = (string)($_POST['clave2'] ?? '');
            if ($p = actas_problema_clave($u, $c1, $c2)) volver('err', $p, '', $ir);
            $forzar = !empty($_POST['forzar']);
            if (!actas_cambiar_clave($u, $c1, $forzar)) volver('err', 'No se pudo guardar. Revise los permisos de ' . ACTAS_USERS_FILE . '.', '', $ir);
            actas_log("admin=$yo cambió la contraseña de usuario=$u forzar=" . ($forzar ? 'si' : 'no'));
            volver('ok', "Contraseña de «{$u}» actualizada." . ($forzar ? ' Al ingresar, el sistema le pedirá que cree una propia.' : '') . ' Si tenía una sesión abierta, se cerró.');

        case 'temporal':
            if ($u === $yo) volver('err', 'Para su propia cuenta use «Cambiar mi contraseña».');
            $tmp = actas_clave_temporal();
            if (!actas_cambiar_clave($u, $tmp, true)) volver('err', 'No se pudo guardar.');
            actas_log("admin=$yo asignó clave temporal a usuario=$u");
            volver('ok', "Contraseña temporal para «{$u}»:", $tmp);

        case 'eliminar':
            if ($u === $yo) volver('err', 'No puede eliminar su propia cuenta.');
            if ($esUltimoAdmin) volver('err', 'Debe quedar al menos un administrador activo.');
            unset($us[$u]);
            if (!actas_guardar_usuarios($us)) volver('err', 'No se pudo guardar.');
            actas_log("admin=$yo eliminó usuario=$u");
            volver('ok', "Usuario «{$u}» eliminado.");
    }
    volver('err', 'Acción no reconocida.');
}

/* ---------------- Vista ---------------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = actas_csrf();
$us = actas_usuarios();
$editar = strtolower((string)($_GET['editar'] ?? ''));
$ed = $us[$editar] ?? null;
$nuevo = isset($_GET['nuevo']) || !$us;
$activos = count(array_filter($us, 'actas_activo'));
$admins = count(array_filter($us, 'actas_es_admin'));
$dominio = actas_config('dominio', 'municarrillo.go.cr');
$fecha = fn($t) => $t ? date('d/m/Y H:i', (int)$t) : '—';

function boton(string $acc, string $u, string $csrf, string $texto, string $clase = '', string $confirmar = '', bool $deshab = false, string $titulo = ''): string {
    return '<form method="post" class="inl"' . ($confirmar !== '' ? ' data-confirm="' . e($confirmar) . '"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="accion" value="' . e($acc) . '">'
        . '<input type="hidden" name="usuario" value="' . e($u) . '">'
        . '<button type="submit" class="b ' . e($clase) . '"' . ($deshab ? ' disabled' : '') . ($titulo !== '' ? ' title="' . e($titulo) . '"' : '') . '>' . $texto . '</button></form>';
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Usuarios · Actas TIC</title>
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
.tag.admin{color:var(--gold)}.tag.tec{color:var(--accent)}.tag.usr{color:var(--mute)}.tag.on{color:var(--ok)}.tag.offt{color:var(--err)}.tag.pend{color:var(--warn);margin-left:4px}
.ops{display:flex;gap:5px;flex-wrap:nowrap;justify-content:flex-end}
.ops .b{padding:5px 9px;font-size:12.5px}
.inl{display:inline}
.vacio{padding:24px;text-align:center;color:var(--mute)}
.ayuda{margin-top:16px;font-size:13px;color:var(--mute)}
@media(max-width:720px){.hd{flex-wrap:wrap}.stats{margin-left:0;width:100%}.stat{flex:1}.grid{grid-template-columns:1fr}.who .nm,.who .cc{display:none}.pill-link,.who a{white-space:nowrap}.body,.hd{padding:14px}}
fieldset{border:1px solid var(--line);border-radius:8px;padding:8px 12px 10px;margin:0 0 10px;background:var(--paper)}
legend{font-size:12.5px;font-weight:700;color:var(--mute);padding:0 6px}
.lnk{border:0;background:none;color:var(--accent);font:inherit;font-size:12px;font-weight:600;cursor:pointer;margin-left:6px}
.acc-grupos[hidden],.acc-admin[hidden]{display:none!important}
.acc-grupos{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:0 12px}
label.chk{display:flex;gap:8px;align-items:flex-start;text-transform:none;letter-spacing:0;font-size:14px;font-weight:500;color:var(--ink);margin:6px 0;cursor:pointer}
label.chk input{margin-top:3px;accent-color:var(--accent)}
.d{font-size:13px;color:var(--mute)}
.pw{position:relative}.pw input{width:100%;font:inherit;color:var(--ink);background:var(--field);border:1px solid var(--line);border-radius:6px;padding:8px 84px 8px 10px}
.ops{flex-wrap:wrap!important;max-width:400px;margin-left:auto}
table{min-width:980px!important}
.pw .ver{position:absolute;right:6px;top:50%;transform:translateY(-50%);border:0;background:none;color:var(--accent);font:inherit;font-size:13px;font-weight:600;cursor:pointer;padding:6px 8px}
#c-coinc.mal{color:var(--err)}#c-coinc.bien{color:var(--ok)}
</style>
<?php require_once __DIR__ . '/../actas-app/tema.php'; tema_estilos(); ?>
</head>
<body>
<?php tema_barra('index.php', 'Volver al menú', $yo, '1100px', [['cambiar_clave.php', 'Cambiar mi contraseña']]); ?>

<main class="sheet">
  <header class="hd">
    <img src="<?= ESCUDO_MINI ?>" alt="Escudo de la Municipalidad de Carrillo">
    <div>
      <div class="eyebrow">Municipalidad de Carrillo · TIC</div>
      <h1>Mantenimiento de usuarios</h1>
    </div>
    <div class="stats">
      <div class="stat"><b><?= count($us) ?></b><span>Usuarios</span></div>
      <div class="stat"><b><?= $activos ?></b><span>Activos</span></div>
      <div class="stat"><b><?= $admins ?></b><span>Admins</span></div>
    </div>
  </header>

  <div class="body">
<?php if ($flash): [$ft, $fm, $fx] = $flash; ?>
    <div class="msg <?= e($ft) ?>" role="<?= $ft === 'err' ? 'alert' : 'status' ?>"><?= e($fm) ?>
<?php if ($fx !== ''): ?>
      <div class="tmp"><code id="tmpc"><?= e($fx) ?></code><button type="button" class="b" id="copiar">Copiar</button>
        <small>Entréguela por un medio seguro (en persona o por teléfono). Al ingresar, el sistema le pedirá crear su propia contraseña. Esta clave no se volverá a mostrar.</small></div>
<?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($ed !== null): ?>
    <section class="panel" id="edicion">
      <h3>Editar usuario «<?= e($editar) ?>»</h3>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="editar"><input type="hidden" name="usuario" value="<?= e($editar) ?>">
        <div class="grid">
          <div><label>Usuario</label><input type="text" value="<?= e($editar) ?>" readonly><div class="hint">El nombre de usuario no se puede cambiar.</div></div>
          <div><label for="e_nombre">Nombre completo</label><input id="e_nombre" name="nombre" type="text" maxlength="80" required value="<?= e((string)($ed['nombre'] ?? '')) ?>"></div>
          <div><label for="e_correo">Correo institucional</label><input id="e_correo" name="correo" type="email" maxlength="120" required value="<?= e((string)($ed['correo'] ?? '')) ?>"></div>
          <div><label for="e_rol">Rol</label>
            <select id="e_rol" name="rol"<?= $editar === $yo ? ' disabled' : '' ?>>
              <option value="usuario"<?= actas_rol($ed) === 'usuario' ? ' selected' : '' ?>>Usuario: llena los formularios asignados (en préstamos solo reserva)</option>
              <option value="tecnico"<?= actas_rol($ed) === 'tecnico' ? ' selected' : '' ?>>Técnico: llena los formularios asignados y entrega préstamos</option>
              <option value="admin"<?= actas_rol($ed) === 'admin' ? ' selected' : '' ?>>Administrador: acceso a todo y gestiona usuarios</option>
            </select>
            <?php if ($editar === $yo): ?><input type="hidden" name="rol" value="admin"><div class="hint">No puede cambiar su propio rol.</div><?php endif; ?>
          </div>
<?php accesos_html($ed, 'e'); ?>
        </div>
        <div class="acts"><a class="b" href="usuarios_admin.php">Cancelar</a><button type="submit" class="b p big">Guardar cambios</button></div>
      </form>
    </section>
<?php if ($editar !== $yo): ?>
    <section class="panel" id="clave">
      <h3>Cambiar la contraseña de «<?= e($editar) ?>»</h3>
      <form method="post" autocomplete="off" id="f-clave" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="clave"><input type="hidden" name="usuario" value="<?= e($editar) ?>">
        <div class="grid">
          <div><label for="c1">Contraseña nueva</label><div class="pw"><input id="c1" name="clave1" type="password" minlength="<?= ACTAS_CLAVE_MIN ?>" maxlength="200" autocomplete="new-password" required><button type="button" class="ver" data-ver="c1">Mostrar</button></div>
            <div class="hint">Mínimo <?= ACTAS_CLAVE_MIN ?> caracteres y sin el nombre de usuario.</div></div>
          <div><label for="c2">Repita la contraseña nueva</label><div class="pw"><input id="c2" name="clave2" type="password" maxlength="200" autocomplete="new-password" required><button type="button" class="ver" data-ver="c2">Mostrar</button></div>
            <div class="hint" id="c-coinc"></div></div>
          <div class="full"><label class="chk"><input type="checkbox" name="forzar" value="1" checked> <span>Pedirle que cree su propia contraseña al ingresar <small class="d">(recomendado: así solo esa persona la conoce)</small></span></label></div>
        </div>
        <div class="acts"><button type="button" class="b" id="c-gen">Generar una segura</button><button type="submit" class="b p big">Guardar contraseña</button></div>
      </form>
    </section>
<?php endif; ?>
<?php endif; ?>

    <details class="panel" id="nuevo"<?= $nuevo ? ' open' : '' ?>>
      <summary class="b p big" style="list-style:none;display:inline-block">+ Nuevo usuario</summary>
      <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="crear">
        <div class="grid">
          <div><label for="n_usuario">Usuario</label><input id="n_usuario" name="usuario" type="text" maxlength="32" pattern="[a-z0-9._-]{2,32}" autocapitalize="none" spellcheck="false" required placeholder="ej. cmairena">
            <div class="hint">Minúsculas, números, punto o guion. No se puede cambiar después.</div></div>
          <div><label for="n_nombre">Nombre completo</label><input id="n_nombre" name="nombre" type="text" maxlength="80" required></div>
          <div><label for="n_correo">Correo institucional</label><input id="n_correo" name="correo" type="email" maxlength="120" required placeholder="usuario@<?= e($dominio) ?>"></div>
          <div><label for="n_rol">Rol</label>
            <select id="n_rol" name="rol"><option value="usuario">Usuario: llena los formularios asignados (en préstamos solo reserva)</option><option value="tecnico" selected>Técnico: llena los formularios asignados y entrega préstamos</option><option value="admin">Administrador: acceso a todo y gestiona usuarios</option></select></div>
<?php accesos_html(null, 'n'); ?>
          <div class="full"><label>¿Cómo obtendrá su contraseña?</label>
            <div class="radios">
              <label><input type="radio" name="modo" value="correo" checked><span>Enviar enlace por correo<small>Recibe un enlace (24 h) para crear su contraseña.</small></span></label>
              <label><input type="radio" name="modo" value="temporal"><span>Generar contraseña temporal<small>Se muestra aquí una sola vez; deberá cambiarla al ingresar.</small></span></label>
            </div></div>
        </div>
        <div class="acts"><button type="submit" class="b p big">Crear usuario</button></div>
      </form>
    </details>

    <div class="bar"><h2>Usuarios registrados</h2></div>
    <div class="tw">
<?php if (!$us): ?>
      <div class="vacio">No hay usuarios.</div>
<?php else: ?>
      <table>
        <thead><tr><th>Usuario</th><th>Nombre</th><th>Correo</th><th>Rol</th><th>Acceso</th><th>Estado</th><th style="white-space:nowrap">Último acceso</th><th style="text-align:right">Acciones</th></tr></thead>
        <tbody>
<?php foreach ($us as $n => $r):
        $act = actas_activo($r); $esYo = $n === $yo;
        $ultimoAdmin = actas_es_admin($r) && $act && actas_admins_activos($us) <= 1; ?>
          <tr class="<?= $act ? '' : 'off' ?><?= $n === $editar ? ' sel' : '' ?>">
            <td><span class="u"><?= e($n) ?></span><?= $esYo ? '<span class="yo">(usted)</span>' : '' ?></td>
            <td><?= e((string)($r['nombre'] ?? '—')) ?></td>
            <td style="white-space:nowrap"><?= e((string)($r['correo'] ?? '—')) ?></td>
            <td><span class="tag <?= ['admin' => 'admin', 'tecnico' => 'tec', 'usuario' => 'usr'][actas_rol($r)] ?>"><?= ['admin' => 'Admin', 'tecnico' => 'Técnico', 'usuario' => 'Usuario'][actas_rol($r)] ?></span></td>
            <td style="white-space:nowrap"><?php if (actas_es_admin($r)): ?><span class="d">Todos</span><?php else: $pm = array_filter(accesos_disponibles(), fn($k) => actas_puede($r, $k), ARRAY_FILTER_USE_KEY); ?><span class="d" title="<?= e(implode("\n", $pm)) ?>"><?= count($pm) ?> formulario<?= count($pm) === 1 ? '' : 's' ?></span><?php endif; ?></td>
            <td><span class="tag <?= $act ? 'on' : 'offt' ?>"><?= $act ? 'Activo' : 'Inactivo' ?></span><?= !empty($r['forzar']) && $act ? '<span class="tag pend" title="Aún no ha creado su propia contraseña">Pendiente</span>' : '' ?></td>
            <td style="white-space:nowrap"><?= $fecha($r['ultimo_acceso'] ?? 0) ?></td>
            <td><div class="ops">
              <a class="b" href="usuarios_admin.php?editar=<?= urlencode($n) ?>#edicion">Editar</a>
<?php if ($n !== $yo): ?>              <a class="b" href="usuarios_admin.php?editar=<?= urlencode($n) ?>#clave">Contraseña</a>
<?php endif; ?>
              <?= boton('enlace', $n, $csrf, 'Enlace', '', "¿Enviar a $n un enlace por correo para restablecer su contraseña?", !$act || empty($r['correo']), 'Envía por correo un enlace para crear una contraseña nueva') ?>
              <?= boton('temporal', $n, $csrf, 'Temporal', '', "¿Generar una contraseña temporal para $n? Su contraseña actual dejará de funcionar y se cerrarán sus sesiones.", $esYo || !$act, $esYo ? 'Use «Cambiar mi contraseña»' : 'Genera una contraseña temporal') ?>
              <?= boton('estado', $n, $csrf, $act ? 'Desactivar' : 'Activar', $act ? 'danger' : '', $act ? "¿Desactivar a $n? No podrá ingresar hasta que se active de nuevo." : '', $esYo || ($act && $ultimoAdmin), $esYo ? 'No puede desactivarse a sí mismo' : '') ?>
              <?= boton('eliminar', $n, $csrf, 'Eliminar', 'danger', "¿Eliminar definitivamente a $n? Esta acción no se puede deshacer.", $esYo || $ultimoAdmin, $esYo ? 'No puede eliminarse a sí mismo' : '') ?>
            </div></td>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table>
<?php endif; ?>
    </div>
    <p class="ayuda"><strong>Acceso:</strong> cada persona solo ve en el menú los formularios que se le asignan (pase el cursor sobre el número para ver cuáles). <strong>Usuario:</strong> en préstamos solo consulta y reserva. <strong>Técnico:</strong> además entrega y recibe préstamos. <strong>Administrador:</strong> además entra a esta pantalla. Desactivar bloquea el acceso sin borrar la cuenta; es lo recomendado cuando alguien deja el departamento. Siempre debe quedar al menos un administrador activo.</p>
  </div>
</main>
<script>
document.querySelectorAll('form[data-confirm]').forEach(f=>f.addEventListener('submit',e=>{if(!confirm(f.dataset.confirm))e.preventDefault()}));
const cp=document.getElementById('copiar');
if(cp)cp.onclick=()=>{const t=document.getElementById('tmpc').textContent;(navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(()=>{cp.textContent='Copiada ✓'},()=>{const r=document.createRange();r.selectNodeContents(document.getElementById('tmpc'));const s=getSelection();s.removeAllRanges();s.addRange(r);cp.textContent='Seleccionada: Ctrl+C'})};
</script>
<script>
(()=>{const f=document.getElementById('f-clave');if(!f)return;const a=f.querySelector('#c1'),b=f.querySelector('#c2'),m=f.querySelector('#c-coinc');
  f.querySelectorAll('[data-ver]').forEach(x=>x.onclick=()=>{const i=f.querySelector('#'+x.dataset.ver),s=i.type==='password';i.type=s?'text':'password';x.textContent=s?'Ocultar':'Mostrar'});
  const ver=()=>{if(!b.value){m.textContent='';m.className='hint';return}const ok=a.value===b.value;m.textContent=ok?'Las contraseñas coinciden.':'Las contraseñas no coinciden.';m.className='hint '+(ok?'bien':'mal')};
  a.oninput=b.oninput=ver;
  f.querySelector('#c-gen').onclick=()=>{const ch='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789',r=new Uint32Array(12);crypto.getRandomValues(r);
    let c=[...r].map(n=>ch[n%ch.length]).join('');c=c.slice(0,4)+'-'+c.slice(4,8)+'-'+c.slice(8);a.value=b.value=c;[a,b].forEach(i=>i.type='text');f.querySelectorAll('[data-ver]').forEach(x=>x.textContent='Ocultar');ver()};
  f.addEventListener('submit',e=>{if(a.value.length<<?= ACTAS_CLAVE_MIN ?>||a.value!==b.value){e.preventDefault();ver();if(a.value.length<<?= ACTAS_CLAVE_MIN ?>){m.textContent='La contraseña debe tener al menos <?= ACTAS_CLAVE_MIN ?> caracteres.';m.className='hint mal'}}});
  if(location.hash==='#clave')a.focus();})();
document.querySelectorAll('[data-accesos]').forEach(b=>{const f=b.closest('form'),rol=f.querySelector('select[name=rol]');
  const ver=()=>{const adm=(rol.disabled?'admin':rol.value)==='admin';b.querySelector('.acc-grupos').hidden=adm;b.querySelector('.acc-admin').hidden=!adm};
  if(rol){rol.addEventListener('change',ver);ver()}
  b.querySelectorAll('[data-todos]').forEach(x=>x.onclick=()=>{const cs=[...x.closest('fieldset').querySelectorAll('input')];const v=!cs.every(c=>c.checked);cs.forEach(c=>c.checked=v)})});
</script>
</body>
</html>

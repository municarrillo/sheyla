<?php
declare(strict_types=1);
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
require __DIR__ . '/../actas-app/tecnicos.php';
require __DIR__ . '/../actas-app/tema.php';

$yo = actas_requerir_login();
actas_requerir_acceso($yo, 'tecnicos');
$csrf = actas_csrf();
function ir(string $q = '', string $tipo = '', string $msg = ''): never {
    if ($msg !== '') $_SESSION['flash_tec'] = [$tipo, $msg];
    header('Location: tecnicos.php' . $q); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!actas_csrf_ok()) ir('', 'err', 'La página expiró. Intente de nuevo.');
    $id = (int)($_POST['id'] ?? 0);
    switch ((string)($_POST['accion'] ?? '')) {
        case 'guardar':
            $err = tec_guardar($id, $_POST);
            if ($err !== '') { $_SESSION['form_tec'] = $_POST; ir($id ? "?editar=$id" : '?nuevo=1', 'err', $err); }
            actas_log("tecnicos: $yo " . ($id ? "editó $id" : 'agregó') . ' ' . trim((string)$_POST['nombre']));
            ir('', 'ok', $id ? 'Técnico actualizado. Las listas de los formularios ya muestran el cambio.' : 'Técnico agregado. Ya aparece en las listas de los formularios.');
        case 'estado':
            $t = tec_leer($id); if (!$t) ir('', 'err', 'Ese técnico ya no existe.');
            db_q('UPDATE tecnicos SET activo = ? WHERE id = ?', [$t['activo'] ? 0 : 1, $id]);
            ir('', 'ok', $t['activo'] ? "«{$t['nombre']}» ya no aparece en las listas (se puede volver a activar)." : "«{$t['nombre']}» vuelve a aparecer en las listas.");
        case 'eliminar':
            $t = tec_leer($id); if (!$t) ir('', 'err', 'Ese técnico ya no existe.');
            tec_eliminar($id); actas_log("tecnicos: $yo eliminó {$t['nombre']}");
            ir('', 'ok', "Se eliminó a «{$t['nombre']}». Las actas que ya lo tienen anotado no cambian.");
        case 'subir': tec_mover($id, -1); ir();
        case 'bajar': tec_mover($id, 1); ir();
    }
    ir();
}

$lista = tec_listar();
$flash = $_SESSION['flash_tec'] ?? null; unset($_SESSION['flash_tec']);
$previo = $_SESSION['form_tec'] ?? null; unset($_SESSION['form_tec']);
$editar = isset($_GET['editar']) ? tec_leer((int)$_GET['editar']) : null;
$nuevo = isset($_GET['nuevo']);
$f = $previo ?? $editar ?? ['nombre' => '', 'cedula' => '', 'puesto' => '', 'correo' => '', 'usuario' => '', 'activo' => 1];
$cuentas = actas_usuarios();
$activos = count(array_filter($lista, fn($t) => $t['activo']));
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Técnicos de TI · Actas TIC</title>
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
.fm{display:grid;grid-template-columns:2fr 1fr;gap:12px 16px}
.fm .full{grid-column:1/-1}
.fm label.l{display:block;font-size:13.5px;font-weight:600;margin:0 0 4px}
.fm input[type=text],.fm input[type=email],.fm select{width:100%;font:inherit;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:var(--paper);color:var(--ink)}
.ck{display:inline-flex;gap:8px;align-items:center;font-weight:600;font-size:14px}
.est{display:inline-block;font-size:12px;font-weight:700;padding:2px 9px;border-radius:99px}
.est.on{background:color-mix(in srgb,var(--ok) 14%,transparent);color:var(--ok)}.est.off{background:color-mix(in srgb,var(--mute) 16%,transparent);color:var(--mute)}
tr.inactivo td{opacity:.6}
.acc{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}
.acc form{display:inline}
.acc .b{padding:5px 10px;font-size:12.5px}
.ord{display:flex;gap:4px}.ord .b{padding:3px 8px}
@media(max-width:760px){.fm{grid-template-columns:1fr}}
</style>
<?php tema_estilos(); ?>
</head>
<body>
<?php tema_barra('index.php', 'Volver al menú', $yo, '1180px'); ?>
<main class="sheet">
  <header class="hd">
    <img src="<?= ESCUDO_MINI ?>" alt="Escudo de la Municipalidad de Carrillo">
    <div><div class="eyebrow">Municipalidad de Carrillo · Departamento de TI</div><h1>Técnicos de TI</h1></div>
    <div class="stats"><div class="stat"><b><?= $activos ?></b><span>en las listas</span></div><div class="stat"><b><?= count($lista) - $activos ?></b><span>inactivos</span></div></div>
  </header>
  <div class="body">
<?php if ($flash): ?>    <div class="msg <?= e($flash[0]) ?>" role="status"><?= e($flash[1]) ?></div>
<?php endif; ?>
    <p style="margin-top:0">Estos nombres aparecen en la <strong>lista desplegable</strong> de los formularios donde se anota el técnico o funcionario de TI: Actas de Entrega y Devolución, Bitácora de Simulacro, Préstamo de Activos y formularios del Constructor. Al elegir un técnico, su cédula se llena sola donde se pide.</p>
<?php if ($editar || $nuevo || $previo): ?>
    <div class="panel"><h3><?= $editar ? 'Editar técnico' : 'Agregar técnico' ?></h3>
      <form method="post" class="fm" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)($editar['id'] ?? ($previo['id'] ?? 0)) ?>">
        <div><label class="l" for="nombre">Nombre completo *</label><input type="text" id="nombre" name="nombre" maxlength="120" required value="<?= e((string)$f['nombre']) ?>" autofocus></div>
        <div><label class="l" for="cedula">Cédula</label><input type="text" id="cedula" name="cedula" maxlength="25" inputmode="numeric" placeholder="Ej. 5-0123-0456" value="<?= e((string)$f['cedula']) ?>"></div>
        <div><label class="l" for="puesto">Puesto</label><input type="text" id="puesto" name="puesto" maxlength="120" value="<?= e((string)$f['puesto']) ?>"></div>
        <div><label class="l" for="correo">Correo</label><input type="email" id="correo" name="correo" maxlength="160" value="<?= e((string)$f['correo']) ?>"></div>
        <div><label class="l" for="usuario">Cuenta del sistema (opcional)</label>
          <select id="usuario" name="usuario"><option value="">— Ninguna —</option>
<?php foreach ($cuentas as $u => $r): ?>            <option value="<?= e($u) ?>"<?= (string)$f['usuario'] === $u ? ' selected' : '' ?>><?= e($u . (!empty($r['nombre']) ? ' · ' . $r['nombre'] : '')) ?></option>
<?php endforeach; ?>          </select></div>
        <div style="align-self:end"><label class="ck"><input type="checkbox" name="activo" value="1"<?= !empty($f['activo']) ? ' checked' : '' ?>> Aparece en las listas</label></div>
        <div class="full acts" style="margin-top:4px"><button class="b p big">Guardar</button><a class="b" href="tecnicos.php">Cancelar</a></div>
      </form>
    </div>
<?php else: ?>
    <div class="acts" style="margin:0 0 16px"><a class="b p big" href="?nuevo=1">+ Agregar técnico</a></div>
<?php endif; ?>
    <div class="tw"><table>
      <thead><tr><th>Orden</th><th>Nombre</th><th>Cédula</th><th>Puesto</th><th>Correo / cuenta</th><th>Estado</th><th></th></tr></thead>
      <tbody>
<?php foreach ($lista as $i => $t): ?>
        <tr class="<?= $t['activo'] ? '' : 'inactivo' ?>">
          <td><div class="ord">
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="subir"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="b" title="Subir"<?= $i === 0 ? ' disabled' : '' ?> aria-label="Subir a <?= e($t['nombre']) ?>">&uarr;</button></form>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="bajar"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="b" title="Bajar"<?= $i === count($lista) - 1 ? ' disabled' : '' ?> aria-label="Bajar a <?= e($t['nombre']) ?>">&darr;</button></form>
          </div></td>
          <td><strong><?= e($t['nombre']) ?></strong></td>
          <td><?= e($t['cedula'] ?: '—') ?></td>
          <td><?= e($t['puesto'] ?: '—') ?></td>
          <td><?= e($t['correo'] ?: '—') ?><?php if ($t['usuario']): ?><small>cuenta: <?= e($t['usuario']) ?></small><?php endif; ?></td>
          <td><span class="est <?= $t['activo'] ? 'on' : 'off' ?>"><?= $t['activo'] ? 'Activo' : 'Inactivo' ?></span></td>
          <td><div class="acc">
            <a class="b" href="?editar=<?= (int)$t['id'] ?>">Editar</a>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="b"><?= $t['activo'] ? 'Desactivar' : 'Activar' ?></button></form>
            <form method="post" onsubmit="return confirm('¿Eliminar a <?= e(addslashes($t['nombre'])) ?> de la lista de técnicos?\n\nLas actas que ya lo tienen anotado no cambian. Si solo dejará de aparecer temporalmente, use «Desactivar».')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="b danger">Eliminar</button></form>
          </div></td>
        </tr>
<?php endforeach; ?>
      </tbody></table>
<?php if (!$lista): ?>      <div class="vacio">No hay técnicos. Agregue el primero con «+ Agregar técnico».</div><?php endif; ?>
    </div>
    <p class="hint">«Desactivar» quita al técnico de las listas sin borrarlo (por ejemplo, si está de vacaciones o ya no labora en TI). El orden de esta tabla es el de las listas desplegables.</p>
  </div>
</main>
<?php require __DIR__ . '/../actas-app/aviso.php'; ?>
</body>
</html>

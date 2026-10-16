<?php
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
$usuario = actas_requerir_login();
$reg = actas_registro($usuario);
$forzado = !empty($reg['forzar']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $actual = (string)($_POST['actual'] ?? '');
    $c1 = (string)($_POST['clave'] ?? '');
    $c2 = (string)($_POST['clave2'] ?? '');
    $llave = 'cambio|' . $usuario;
    if (!actas_csrf_ok()) {
        $error = 'El formulario expiró. Intente de nuevo.';
    } elseif (actas_bloqueado($llave) > 0) {
        actas_log("cambio de clave bloqueado usuario=$usuario");
        header('Location: logout.php');
        exit;
    } elseif (!actas_verificar($usuario, $actual)) {
        actas_registrar_fallo($llave);
        $error = 'La contraseña actual no es correcta.';
    } elseif (($p = actas_problema_clave($usuario, $c1, $c2)) !== '') {
        $error = $p;
    } elseif (hash_equals($actual, $c1)) {
        $error = 'La contraseña nueva debe ser distinta de la actual.';
    } elseif (!actas_cambiar_clave($usuario, $c1, false)) {
        $error = 'No se pudo guardar la contraseña. Avise al administrador del sistema.';
    } else {
        actas_limpiar_fallos($llave);
        session_regenerate_id(true);
        $_SESSION['desde'] = time();   // esta sesión sigue abierta; las demás de este usuario se cierran
        $_SESSION['flash'] = ['ok', 'Contraseña actualizada.'];
        actas_log("cambio de clave usuario=$usuario");
        header('Location: index.php');
        exit;
    }
}
$csrf = actas_csrf();

actas_pagina_inicio('Cambiar contraseña', 'Cambiar contraseña', 'Cuenta: ' . $usuario);
if ($forzado): ?>
    <p class="msg info" role="status">Está usando una contraseña temporal. Debe crear su propia contraseña para continuar.</p>
<?php endif; ?>
    <?php if ($error !== ''): ?><p class="msg err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <form method="post" action="cambiar_clave.php" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="text" name="usuario" value="<?= e($usuario) ?>" autocomplete="username" hidden>
      <div class="f">
        <label for="actual"><?= $forzado ? 'Contraseña temporal' : 'Contraseña actual' ?></label>
        <input id="actual" name="actual" type="password" autocomplete="current-password" maxlength="200" required autofocus>
      </div>
      <div class="f">
        <label for="clave">Contraseña nueva</label>
        <input id="clave" name="clave" type="password" autocomplete="new-password" maxlength="200" required>
      </div>
      <ul class="reglas">
        <li>Mínimo <?= ACTAS_CLAVE_MIN ?> caracteres</li>
        <li>No puede contener su nombre de usuario</li>
      </ul>
      <div class="f">
        <label for="clave2">Repita la contraseña nueva</label>
        <input id="clave2" name="clave2" type="password" autocomplete="new-password" maxlength="200" required>
      </div>
      <button type="submit">Guardar contraseña</button>
    </form>
    <p class="alt"><?php if (!$forzado): ?><a href="index.php">Cancelar</a> · <?php endif; ?><a href="logout.php">Cerrar sesión</a></p>
<?php
actas_pagina_fin();

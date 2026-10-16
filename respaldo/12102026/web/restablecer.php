<?php
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
actas_iniciar_sesion();
actas_cabeceras();

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$token = (string)($_POST['t'] ?? $_GET['t'] ?? '');
$usuario = actas_reset_usuario($token);
$error = '';

if ($usuario !== null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $c1 = (string)($_POST['clave'] ?? '');
    $c2 = (string)($_POST['clave2'] ?? '');
    if (!actas_csrf_ok()) {
        $error = 'El formulario expiró. Intente de nuevo.';
    } elseif (($p = actas_problema_clave($usuario, $c1, $c2)) !== '') {
        $error = $p;
    } elseif (!actas_cambiar_clave($usuario, $c1)) {
        $error = 'No se pudo guardar la contraseña. Avise al administrador del sistema.';
        actas_log("restablecer ERROR al guardar usuario=$usuario");
    } else {
        actas_reset_consumir($token);
        actas_limpiar_fallos($ip);
        actas_log("restablecer correcto usuario=$usuario");
        $_SESSION = [];
        session_regenerate_id(true);
        header('Location: login.php?restablecida=1');
        exit;
    }
}
$csrf = actas_csrf();

actas_pagina_inicio('Nueva contraseña', 'Nueva contraseña', 'Actas de Equipo · Departamento de TI');
if ($usuario === null): ?>
    <p class="msg err" role="alert">El enlace no es válido, ya se usó o venció.</p>
    <p class="nota">Por seguridad, cada enlace sirve una sola vez y vence en <?= ACTAS_RESET_VIGENCIA / 60 ?> minutos. Solicite uno nuevo.</p>
    <p class="alt"><a href="olvido.php">Solicitar un enlace nuevo</a> · <a href="login.php">Iniciar sesión</a></p>
<?php else: ?>
    <?php if ($error !== ''): ?><p class="msg err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <p class="nota">Cuenta: <strong><?= e($usuario) ?></strong></p>
    <form method="post" action="restablecer.php" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <input type="text" name="usuario" value="<?= e($usuario) ?>" autocomplete="username" hidden>
      <div class="f">
        <label for="clave">Contraseña nueva</label>
        <input id="clave" name="clave" type="password" autocomplete="new-password" maxlength="200" required autofocus>
      </div>
      <ul class="reglas">
        <li>Mínimo <?= ACTAS_CLAVE_MIN ?> caracteres</li>
        <li>No puede contener su nombre de usuario</li>
      </ul>
      <div class="f">
        <label for="clave2">Repita la contraseña</label>
        <input id="clave2" name="clave2" type="password" autocomplete="new-password" maxlength="200" required>
      </div>
      <button type="submit">Guardar contraseña</button>
    </form>
    <p class="alt"><a href="login.php">Cancelar</a></p>
<?php endif;
actas_pagina_fin();

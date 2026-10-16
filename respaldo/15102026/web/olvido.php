<?php
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
actas_iniciar_sesion();
actas_cabeceras();
if (!empty($_SESSION['usuario'])) { header('Location: index.php'); exit; }

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$error = '';
$enviado = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dato = strtolower(trim((string)($_POST['dato'] ?? '')));
    if (!actas_csrf_ok()) {
        $error = 'El formulario expiró. Intente de nuevo.';
    } elseif ($dato === '') {
        $error = 'Escriba su usuario o su correo institucional.';
    } elseif (($resta = actas_bloqueado('olvido|' . $ip)) > 0) {
        $error = 'Demasiadas solicitudes desde este equipo. Espere ' . (int)ceil($resta / 60) . ' minuto(s).';
        actas_log('olvido bloqueado');
    } else {
        actas_registrar_fallo('olvido|' . $ip);   // cuenta cada solicitud: máximo 5 cada 15 min por equipo
        // Buscar por usuario o por correo
        $usuario = null;
        foreach (actas_usuarios() as $n => $r) {
            if ($n === $dato || strtolower((string)($r['correo'] ?? '')) === $dato) { $usuario = $n; break; }
        }
        if ($usuario !== null) {
            $err = actas_enviar_enlace($usuario, 'reset');
            if ($err !== '') actas_log("olvido usuario=$usuario: $err");
        } else {
            actas_log('olvido sin coincidencia dato=' . substr(preg_replace('/[^a-z0-9@._-]/', '', $dato), 0, 64));
        }
        // Siempre la misma respuesta: no revela si el usuario o correo existe.
        $enviado = true;
    }
}
$csrf = actas_csrf();

actas_pagina_inicio('Recuperar contraseña', 'Recuperar contraseña', 'Le enviaremos un enlace a su correo institucional');
if ($enviado): ?>
    <p class="msg info" role="status">Si los datos corresponden a una cuenta de TI, en unos minutos llegará un correo con un enlace para crear una contraseña nueva. Revise también la carpeta de correo no deseado.</p>
    <p class="nota">El enlace vence en <?= ACTAS_RESET_VIGENCIA / 60 ?> minutos y solo puede usarse una vez.</p>
    <p class="alt"><a href="login.php">&larr; Volver al inicio de sesión</a></p>
<?php else: ?>
    <?php if ($error !== ''): ?><p class="msg err" role="alert"><?= e($error) ?></p><?php endif; ?>
    <p class="nota">Escriba su usuario o su correo institucional. Si la cuenta existe, recibirá un enlace para crear una contraseña nueva.</p>
    <form method="post" action="olvido.php" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <div class="f">
        <label for="dato">Usuario o correo institucional</label>
        <input id="dato" name="dato" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="120" required autofocus>
      </div>
      <button type="submit">Enviar enlace</button>
    </form>
    <p class="alt"><a href="login.php">&larr; Volver al inicio de sesión</a></p>
<?php endif;
actas_pagina_fin();

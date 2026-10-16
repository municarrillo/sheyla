<?php
require __DIR__ . '/../actas-app/auth.php';
actas_iniciar_sesion();
actas_cabeceras();
if (!empty($_SESSION['usuario'])) actas_log('logout usuario=' . $_SESSION['usuario']);
$_SESSION = [];
$p = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
session_destroy();
header('Location: login.php?salio=1');
exit;

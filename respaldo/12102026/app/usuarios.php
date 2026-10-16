<?php
/**
 * Administración de usuarios de TI (solo desde la terminal, como root).
 *   php /var/www/actas-app/usuarios.php agregar  <usuario> [admin]  crea usuario (pide correo y contraseña)
 *   php /var/www/actas-app/usuarios.php rol      <usuario> admin|tecnico
 *   php /var/www/actas-app/usuarios.php clave    <usuario>          cambia la contraseña
 *   php /var/www/actas-app/usuarios.php correo   <usuario>          cambia el correo de recuperación
 *   php /var/www/actas-app/usuarios.php eliminar <usuario>
 *   php /var/www/actas-app/usuarios.php listar
 *   php /var/www/actas-app/usuarios.php probar-correo <usuario>    envía un correo de prueba
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/auth.php';

function salir(string $m, int $c = 1): never { fwrite(STDERR, $m . PHP_EOL); exit($c); }
function pedir(string $p, bool $oculto = false): string {
    echo $p;
    $tty = $oculto && stream_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $c = rtrim((string)fgets(STDIN), "\r\n");
    if ($tty) { shell_exec('stty echo'); echo PHP_EOL; }
    return $c;
}
function pedir_correo(): string {
    $dom = actas_config('dominio', 'municarrillo.go.cr');
    $c = strtolower(trim(pedir('Correo institucional para recuperar la contraseña' . ($dom !== '' ? " (@$dom)" : '') . ': ')));
    if (!actas_correo_valido($c)) salir('Correo inválido' . ($dom !== '' ? " (debe terminar en @$dom)." : '.'));
    return $c;
}
function pedir_clave_nueva(string $u): string {
    $c1 = pedir("Contraseña para '$u' (mínimo " . ACTAS_CLAVE_MIN . ' caracteres): ', true);
    $c2 = pedir('Repita la contraseña: ', true);
    $p = actas_problema_clave($u, $c1, $c2);
    if ($p !== '') salir($p);
    return $c1;
}
function guardar(array $us): void {
    if (!actas_guardar_usuarios($us)) salir('No se pudo escribir ' . ACTAS_USERS_FILE);
}

$accion = $argv[1] ?? '';
$u = strtolower(trim($argv[2] ?? ''));
$us = actas_usuarios();
$existe = isset($us[$u]);
if (in_array($accion, ['agregar', 'clave', 'correo', 'eliminar', 'probar-correo', 'rol'], true)) {
    if (!actas_usuario_valido($u)) salir('Usuario inválido: use 2-32 caracteres a-z, 0-9, punto, guion o guion bajo.');
    if ($accion === 'agregar' && $existe) salir("El usuario '$u' ya existe. Use: clave $u  o  correo $u");
    if ($accion !== 'agregar' && !$existe) salir("El usuario '$u' no existe.");
}

switch ($accion) {
    case 'listar':
        if (!$us) { echo "(no hay usuarios)" . PHP_EOL; break; }
        foreach ($us as $n => $r) echo str_pad($n, 18) . str_pad(actas_es_admin($r) ? 'admin' : 'tecnico', 10)
            . str_pad(actas_activo($r) ? 'activo' : 'INACTIVO', 10) . ($r['correo'] ?? '(sin correo: no podrá recuperar su contraseña)') . PHP_EOL;
        break;
    case 'agregar':
        $correo = pedir_correo();
        $clave = pedir_clave_nueva($u);
        $us[$u] = ['hash' => password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]), 'correo' => $correo,
                   'rol' => (($argv[3] ?? '') === 'admin' ? 'admin' : 'tecnico'), 'activo' => true, 'cambio' => time(), 'creado' => time()];
        guardar($us);
        echo "Usuario '$u' creado." . PHP_EOL;
        break;
    case 'clave':
        if (!actas_cambiar_clave($u, pedir_clave_nueva($u))) salir('No se pudo guardar la contraseña.');
        echo "Contraseña de '$u' actualizada. Sus sesiones abiertas se cerraron." . PHP_EOL;
        break;
    case 'correo':
        $us[$u]['correo'] = pedir_correo();
        guardar($us);
        echo "Correo de '$u' actualizado." . PHP_EOL;
        break;
    case 'rol':
        $rol = $argv[3] ?? '';
        if (!in_array($rol, ['admin', 'tecnico'], true)) salir('Indique el rol: admin o tecnico');
        $us[$u]['rol'] = $rol;
        guardar($us);
        echo "Rol de '$u': $rol." . PHP_EOL;
        break;
    case 'eliminar':
        unset($us[$u]);
        guardar($us);
        echo "Usuario '$u' eliminado." . PHP_EOL;
        break;
    case 'probar-correo':
        $c = (string)($us[$u]['correo'] ?? '');
        if ($c === '') salir("'$u' no tiene correo. Use: correo $u");
        $ok = actas_enviar_correo($c, 'Prueba de correo - Actas TIC',
            "Este es un correo de prueba del sistema de Actas TIC.\nSi lo recibió, la recuperación de contraseña está lista.\n");
        echo $ok ? "Correo de prueba entregado al servidor de correo para $c. Revise la bandeja (y spam)." . PHP_EOL
                 : salir("No se pudo enviar. Revise 'remitente' en " . ACTAS_CONFIG_FILE . " y la configuración de /etc/msmtprc (journalctl -t msmtp).");
        break;
    default:
        salir("Uso: php " . basename(__FILE__) . " agregar <usuario> [admin] | clave|correo|eliminar|probar-correo <usuario> | rol <usuario> admin|tecnico | listar");
}

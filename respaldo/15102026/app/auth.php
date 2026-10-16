<?php
/**
 * Actas TIC - autenticación del personal de TI.
 * Este archivo va FUERA de la carpeta web: /var/www/actas-app/auth.php
 */
declare(strict_types=1);

define('ACTAS_USERS_FILE',  getenv('ACTAS_USERS_FILE')  ?: '/etc/actas/usuarios.json');
define('ACTAS_CONFIG_FILE', getenv('ACTAS_CONFIG_FILE') ?: '/etc/actas/config.json');
define('ACTAS_LOCK_DIR',    getenv('ACTAS_LOCK_DIR')    ?: '/var/lib/actas/intentos');
define('ACTAS_RESET_DIR',   getenv('ACTAS_RESET_DIR')   ?: '/var/lib/actas/reset');
const ACTAS_MAX_INTENTOS    = 5;     // intentos fallidos permitidos por equipo (IP)...
const ACTAS_BLOQUEO_SEG     = 900;   // ...antes de bloquearlo 15 minutos
const ACTAS_INACTIVIDAD_SEG = 1800;  // la sesión se cierra tras 30 min sin uso
const ACTAS_RESET_VIGENCIA  = 1800;  // el enlace para restablecer vence en 30 min
const ACTAS_INVITA_VIGENCIA = 86400; // el enlace de bienvenida (usuario nuevo) vence en 24 h
const ACTAS_CLAVE_MIN       = 10;    // largo mínimo de contraseña
// Hash de relleno: se verifica igual cuando el usuario no existe, para no revelar qué usuarios son válidos.
const ACTAS_HASH_RELLENO = '$2y$12$fZkZ9dq9ssWdFxMeFo8P.uLNVneueIxoywzFmWrUhIo8YWeCrhcJO';

date_default_timezone_set('America/Costa_Rica');

function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function actas_largo(string $s): int { return (int)preg_match_all('/./su', $s); }

/* ---------------- Configuración (/etc/actas/config.json) ---------------- */
function actas_config(string $clave, string $defecto = ''): string {
    static $c = null;
    if ($c === null) {
        $d = json_decode((string)@file_get_contents(ACTAS_CONFIG_FILE), true);
        $c = is_array($d) ? $d : [];
    }
    return isset($c[$clave]) && is_string($c[$clave]) ? $c[$clave] : $defecto;
}

/* ---------------- Sesión ---------------- */
function actas_iniciar_sesion(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('ACTASTIC');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

function actas_cabeceras(): void {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, max-age=0');
}

/** Llamar al inicio de cada página protegida. Devuelve el usuario conectado. */
function actas_requerir_login(): string {
    actas_iniciar_sesion();
    actas_cabeceras();
    $u = $_SESSION['usuario'] ?? '';
    $t = (int)($_SESSION['ultimo'] ?? 0);
    $reg = $u !== '' ? actas_registro($u) : null;
    // Se cierra si: no hay sesión, pasó el tiempo de inactividad, el usuario fue eliminado o
    // desactivado, o su contraseña cambió después de iniciar esta sesión.
    $valida = $u !== '' && actas_activo($reg)
        && (time() - $t) <= ACTAS_INACTIVIDAD_SEG
        && (int)($reg['cambio'] ?? 0) <= (int)($_SESSION['desde'] ?? 0);
    if (!$valida) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php' . ($u !== '' ? '?expirada=1' : ''));
        exit;
    }
    $_SESSION['ultimo'] = time();
    // Contraseña temporal: obligar a cambiarla antes de usar el sistema
    if (!empty($reg['forzar']) && basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) !== 'cambiar_clave.php') {
        header('Location: cambiar_clave.php');
        exit;
    }
    return $u;
}

/** Igual que actas_requerir_login(), pero solo deja pasar administradores. */
function actas_requerir_admin(): string {
    $u = actas_requerir_login();
    if (!actas_es_admin(actas_registro($u))) {
        actas_log("acceso denegado a administración usuario=$u");
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Sin permiso</title><body style="font-family:sans-serif;padding:40px">'
           . '<h2>Sin permiso</h2><p>Esta sección es solo para administradores del sistema.</p><p><a href="index.php">Volver al menú</a></p>';
        exit;
    }
    return $u;
}

/** Roles: admin (todo + usuarios), tecnico (formularios y préstamos), usuario (solo reservar préstamos). */
const ACTAS_ROLES = ['admin' => 'Administrador', 'tecnico' => 'Técnico', 'usuario' => 'Usuario'];
function actas_rol(?array $r): string { $x = (string)($r['rol'] ?? 'tecnico'); return isset(ACTAS_ROLES[$x]) ? $x : 'tecnico'; }
function actas_es_ti(?array $r): bool { return $r !== null && actas_rol($r) !== 'usuario'; }
/* ---------------- Acceso a formularios ----------------
 * Cada usuario tiene en usuarios.json la lista «permisos» con lo que puede abrir:
 *   acta_entrega, acta_devolucion, bitacora, prestamos, constructor, f:<id del formulario creado>
 * Los administradores entran a todo. Quien crea un formulario recibe acceso a él automáticamente.
 * Cuentas anteriores sin «permisos»: técnico = todos los formularios; usuario = solo préstamos. */
const ACTAS_ACCESOS = [
    'acta_entrega'    => 'Acta de Entrega',
    'acta_devolucion' => 'Acta de Devolución',
    'bitacora'        => 'Bitácora de Simulacro',
    'prestamos'       => 'Préstamo de Activos',
    'constructor'     => 'Constructor de formularios (crear formularios propios)',
];
function actas_permisos(?array $r): array {
    if ($r === null) return [];
    if (isset($r['permisos']) && is_array($r['permisos'])) return array_values(array_filter($r['permisos'], 'is_string'));
    return actas_rol($r) === 'usuario' ? ['prestamos'] : ['acta_entrega', 'acta_devolucion', 'bitacora', 'prestamos', 'f:*'];
}
function actas_puede(?array $r, string $clave): bool {
    if ($r === null || !actas_activo($r)) return false;
    if (actas_es_admin($r)) return true;
    $p = actas_permisos($r);
    return in_array($clave, $p, true) || (str_starts_with($clave, 'f:') && in_array('f:*', $p, true));
}
/** Da acceso a $clave al usuario $u (se usa al crear un formulario). */
function actas_otorgar(string $u, string $clave): bool {
    $us = actas_usuarios();
    if (!isset($us[$u]) || actas_puede($us[$u], $clave)) return true;
    $us[$u]['permisos'] = array_values(array_unique(array_merge(actas_permisos($us[$u]), [$clave])));
    return actas_guardar_usuarios($us);
}
/** Corta con 403 si el usuario no tiene acceso a $clave. */
function actas_requerir_acceso(string $u, string $clave): void {
    if (actas_puede(actas_registro($u), $clave)) return;
    actas_log("acceso denegado usuario=$u formulario=$clave");
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sin acceso</title>'
       . '<body style="font-family:Segoe UI,system-ui,sans-serif;background:#edf1f5;color:#17212e;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
       . '<div style="max-width:460px;background:#fff;border-radius:12px;border-top:4px solid #c08a1e;box-shadow:0 10px 30px -12px rgba(18,52,86,.3);padding:28px 30px">'
       . '<h2 style="margin:0 0 8px;color:#123456">No tiene acceso a este formulario</h2>'
       . '<p style="margin:0 0 18px;color:#566273">Un administrador del sistema debe darle acceso desde «Mantenimiento de usuarios».</p>'
       . '<a href="index.php" style="display:inline-block;padding:9px 18px;border-radius:8px;background:#123456;color:#fff;text-decoration:none;font-weight:600">Volver al menú</a></div>';
    exit;
}

function actas_es_admin(?array $r): bool { return $r !== null && ($r['rol'] ?? '') === 'admin'; }
function actas_activo(?array $r): bool  { return $r !== null && ($r['activo'] ?? true) !== false; }
function actas_admins_activos(array $us): int {
    return count(array_filter($us, fn($r) => actas_es_admin($r) && actas_activo($r)));
}

function actas_csrf(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function actas_csrf_ok(): bool {
    return hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''));
}

/* ---------------- Usuarios (/etc/actas/usuarios.json) ----------------
 * Formato: { "usuario": { "hash": "...", "correo": "...", "cambio": 1760000000 } }
 * (se acepta también el formato anterior { "usuario": "hash" }) */
function actas_usuarios(): array {
    $raw = @file_get_contents(ACTAS_USERS_FILE);
    $d = $raw !== false ? json_decode($raw, true) : null;
    if (!is_array($d)) return [];
    $out = [];
    foreach ($d as $u => $r) {
        if (is_string($r)) $r = ['hash' => $r];
        if (is_array($r) && isset($r['hash']) && is_string($r['hash'])) $out[(string)$u] = $r;
    }
    return $out;
}

function actas_registro(string $u): ?array {
    $us = actas_usuarios();
    return $us[$u] ?? null;
}

function actas_guardar_usuarios(array $us): bool {
    ksort($us);
    $dir = dirname(ACTAS_USERS_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0770, true);
    $tmp = $dir . '/.usuarios.' . bin2hex(random_bytes(6)) . '.tmp';
    $json = json_encode($us, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0660);
    @chgrp($tmp, 'www-data');
    if (!@rename($tmp, ACTAS_USERS_FILE)) { @unlink($tmp); return false; }
    return true;
}

/** Cambia la contraseña e invalida las sesiones abiertas de ese usuario.
 *  $forzar = true: es temporal y deberá cambiarla al ingresar. */
function actas_cambiar_clave(string $u, string $clave, bool $forzar = false): bool {
    $us = actas_usuarios();
    if (!isset($us[$u])) return false;
    $us[$u]['hash'] = password_hash($clave, PASSWORD_BCRYPT, ['cost' => 12]);
    $us[$u]['cambio'] = time();
    if ($forzar) $us[$u]['forzar'] = true; else unset($us[$u]['forzar']);
    return actas_guardar_usuarios($us);
}

/** Contraseña temporal legible (sin 0/O, 1/l/I) de 12 caracteres. */
function actas_clave_temporal(): string {
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $c = '';
    for ($i = 0; $i < 12; $i++) $c .= $abc[random_int(0, strlen($abc) - 1)];
    return substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 4);
}

function actas_usuario_valido(string $u): bool {
    return (bool)preg_match('/^[a-z0-9._-]{2,32}$/', $u);
}

/** Valida el correo y, si está configurado, que sea del dominio institucional. */
function actas_correo_valido(string $c): bool {
    if (!filter_var($c, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $c)) return false;
    $dom = strtolower(actas_config('dominio', 'municarrillo.go.cr'));
    return $dom === '' || str_ends_with(strtolower($c), '@' . $dom);
}

/** Problema con la contraseña nueva, o '' si es aceptable. */
function actas_problema_clave(string $u, string $c1, string $c2): string {
    if (actas_largo($c1) < ACTAS_CLAVE_MIN) return 'La contraseña debe tener al menos ' . ACTAS_CLAVE_MIN . ' caracteres.';
    if (!hash_equals($c1, $c2)) return 'Las contraseñas no coinciden.';
    if (stripos($c1, $u) !== false) return 'La contraseña no puede contener el nombre de usuario.';
    return '';
}

function actas_verificar(string $usuario, string $clave): bool {
    $reg = actas_usuario_valido($usuario) ? actas_registro($usuario) : null;
    $hash = $reg['hash'] ?? null;
    $ok = password_verify($clave, $hash ?? ACTAS_HASH_RELLENO);
    return $ok && $hash !== null && actas_activo($reg);
}

/* ---------------- Bloqueo por intentos (por IP y tipo) ---------------- */
function actas_archivo_intentos(string $clave): string {
    return ACTAS_LOCK_DIR . '/' . hash('sha256', $clave) . '.json';
}
function actas_leer_intentos(string $clave): array {
    $d = json_decode((string)@file_get_contents(actas_archivo_intentos($clave)), true);
    $d = is_array($d) ? $d : ['n' => 0, 'desde' => 0];
    if (time() - (int)$d['desde'] > ACTAS_BLOQUEO_SEG) $d = ['n' => 0, 'desde' => 0];
    return $d;
}
/** Segundos que faltan de bloqueo (0 = no bloqueado). */
function actas_bloqueado(string $clave): int {
    $d = actas_leer_intentos($clave);
    return $d['n'] >= ACTAS_MAX_INTENTOS ? max(1, ACTAS_BLOQUEO_SEG - (time() - (int)$d['desde'])) : 0;
}
function actas_registrar_fallo(string $clave): void {
    if (!is_dir(ACTAS_LOCK_DIR)) @mkdir(ACTAS_LOCK_DIR, 0700, true);
    $d = actas_leer_intentos($clave);
    if ($d['n'] === 0) $d['desde'] = time();
    $d['n']++;
    @file_put_contents(actas_archivo_intentos($clave), json_encode($d), LOCK_EX);
}
function actas_limpiar_fallos(string $clave): void {
    @unlink(actas_archivo_intentos($clave));
}

/* ---------------- Restablecer contraseña ----------------
 * El token viaja solo en el correo; en disco se guarda su hash SHA-256. Un solo uso, vence en 30 min. */
function actas_reset_archivo(string $token): string {
    return ACTAS_RESET_DIR . '/' . hash('sha256', $token) . '.json';
}
function actas_reset_crear(string $u, int $vigencia = ACTAS_RESET_VIGENCIA): string {
    if (!is_dir(ACTAS_RESET_DIR)) @mkdir(ACTAS_RESET_DIR, 0700, true);
    foreach (glob(ACTAS_RESET_DIR . '/*.json') ?: [] as $f) {   // limpia vencidos y anteriores del mismo usuario
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || ($d['u'] ?? '') === $u || (int)($d['exp'] ?? 0) < time()) @unlink($f);
    }
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    file_put_contents(actas_reset_archivo($token), json_encode(['u' => $u, 'exp' => time() + $vigencia]), LOCK_EX);
    return $token;
}
/** Devuelve el usuario del token, o null si no existe o venció. */
function actas_reset_usuario(string $token): ?string {
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) return null;
    $f = actas_reset_archivo($token);
    $d = json_decode((string)@file_get_contents($f), true);
    if (!is_array($d) || (int)($d['exp'] ?? 0) < time()) { @unlink($f); return null; }
    $u = (string)($d['u'] ?? '');
    return actas_activo(actas_registro($u)) ? $u : null;
}
function actas_reset_consumir(string $token): void {
    @unlink(actas_reset_archivo($token));
}

/* ---------------- Correo (usa el sendmail del sistema: msmtp) ---------------- */
function actas_enviar_correo(string $para, string $asunto, string $cuerpo, array $adjuntos = []): bool {
    $de = actas_config('remitente');
    if (!actas_correo_valido($para) || !filter_var($de, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $de)) return false;
    $cab = ['From: Actas TIC <' . $de . '>', 'MIME-Version: 1.0', 'Auto-Submitted: auto-generated'];
    if ($adjuntos) {
        // Mensaje con archivos adjuntos (por ejemplo, el PDF del préstamo): multipart/mixed
        $lim = '=_actas_' . bin2hex(random_bytes(12));
        $cab[] = 'Content-Type: multipart/mixed; boundary="' . $lim . '"';
        $msg = "--$lim\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($cuerpo)) ;
        foreach ($adjuntos as [$nombre, $tipo, $datos]) {
            $nombre = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$nombre);
            $msg .= "--$lim\r\nContent-Type: $tipo; name=\"$nombre\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nombre\"\r\n\r\n" . chunk_split(base64_encode((string)$datos));
        }
        $cuerpo = $msg . "--$lim--\r\n";
    } else {
        $cab[] = 'Content-Type: text/plain; charset=UTF-8';
        $cab[] = 'Content-Transfer-Encoding: 8bit';
    }
    return @mail($para, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $cuerpo, implode("\r\n", $cab), '-f' . $de);
}

/** Envía el enlace para crear/restablecer contraseña. $tipo: 'reset' | 'invitacion'.
 *  Devuelve '' si se envió, o el motivo del error. */
function actas_enviar_enlace(string $u, string $tipo = 'reset'): string {
    $reg = actas_registro($u);
    $correo = (string)($reg['correo'] ?? '');
    if (!actas_activo($reg)) return 'El usuario no existe o está inactivo.';
    if (!actas_correo_valido($correo)) return 'El usuario no tiene un correo institucional válido.';
    $base = rtrim(actas_config('url_base'), '/');
    if ($base === '') return "Falta 'url_base' en " . ACTAS_CONFIG_FILE . '.';
    $inv = $tipo === 'invitacion';
    $vig = $inv ? ACTAS_INVITA_VIGENCIA : ACTAS_RESET_VIGENCIA;
    $enlace = $base . '/restablecer.php?t=' . actas_reset_crear($u, $vig);
    $plazo = $inv ? '24 horas' : ($vig / 60) . ' minutos';
    $nombre = trim((string)($reg['nombre'] ?? '')) ?: $u;
    $cuerpo = "Hola, $nombre:\n\n"
        . ($inv ? "Se le creó una cuenta en el sistema de Actas TIC de la Municipalidad de Carrillo.\nSu usuario es: $u\n\nPara crear su contraseña, abra este enlace"
                : "Se solicitó restablecer su contraseña del sistema de Actas TIC de la Municipalidad de Carrillo.\n\nPara crear una contraseña nueva, abra este enlace")
        . " (vence en $plazo y solo sirve una vez):\n\n$enlace\n\n"
        . ($inv ? '' : "Si usted no lo solicitó, ignore este correo: su contraseña actual sigue funcionando.\n")
        . 'Enviado el ' . date('d/m/Y H:i') . ".\n\nDepartamento de Tecnologías de Información\n";
    $asunto = $inv ? 'Bienvenido a Actas TIC - cree su contraseña' : 'Restablecer contraseña - Actas TIC';
    $ok = actas_enviar_correo($correo, $asunto, $cuerpo);
    actas_log("enlace $tipo usuario=$u envio=" . ($ok ? 'ok' : 'ERROR'));
    return $ok ? '' : 'No se pudo enviar el correo. Revise la configuración de /etc/msmtprc.';
}

/** Registra la fecha del último ingreso. */
function actas_marcar_acceso(string $u): void {
    $us = actas_usuarios();
    if (!isset($us[$u])) return;
    $us[$u]['ultimo_acceso'] = time();
    actas_guardar_usuarios($us);
}

function actas_log(string $msg): void {
    error_log('[actas-tic] ' . $msg . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
}

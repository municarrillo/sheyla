<?php
/**
 * Conexión a la base de datos MySQL del sistema Actas TIC.
 * Credenciales en /etc/actas/db.php (lo crea el instalador):
 *   <?php return ['host' => 'localhost', 'puerto' => 3306, 'base' => 'actas_tic', 'usuario' => 'actas', 'clave' => '...'];
 * Las tablas se crean solas la primera vez (db_esquema).
 */
declare(strict_types=1);

define('ACTAS_DB_FILE', getenv('ACTAS_DB_FILE') ?: '/etc/actas/db.php');

function actas_db_config(): array {
    $c = is_readable(ACTAS_DB_FILE) ? (require ACTAS_DB_FILE) : [];
    return (is_array($c) ? $c : []) + ['host' => 'localhost', 'puerto' => 3306, 'base' => 'actas_tic', 'usuario' => 'actas', 'clave' => '', 'socket' => ''];
}

function actas_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $c = actas_db_config();
    $dsn = $c['socket'] !== '' ? "mysql:unix_socket={$c['socket']};dbname={$c['base']};charset=utf8mb4"
                               : "mysql:host={$c['host']};port={$c['puerto']};dbname={$c['base']};charset=utf8mb4";
    try {
        $pdo = new PDO($dsn, $c['usuario'], $c['clave'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET time_zone = '-06:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
    } catch (PDOException $e) {
        error_log('[actas-tic] No se pudo conectar a MySQL: ' . $e->getMessage());
        if (PHP_SAPI === 'cli') { fwrite(STDERR, "No se pudo conectar a MySQL ({$c['base']}): " . $e->getMessage() . "\n"); exit(1); }
        http_response_code(503);
        $json = str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') || str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json');
        if ($json) { header('Content-Type: application/json; charset=utf-8'); echo '{"ok":false,"error":"La base de datos no está disponible. Avise al administrador del sistema."}'; exit; }
        echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Base de datos no disponible</title>'
           . '<body style="font-family:Segoe UI,system-ui,sans-serif;background:#edf1f5;color:#17212e;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
           . '<div style="max-width:480px;background:#fff;border-radius:12px;border-top:4px solid #c08a1e;box-shadow:0 10px 30px -12px rgba(18,52,86,.3);padding:28px 30px">'
           . '<h2 style="margin:0 0 8px;color:#123456">La base de datos no está disponible</h2><p>El sistema no pudo conectarse a MySQL. Revise que el servicio esté encendido:</p>'
           . '<pre style="background:#f5f7fa;padding:10px;border-radius:8px">sudo systemctl status mysql</pre><p>Si el problema continúa, avise al administrador del sistema.</p></div>';
        exit;
    }
    static $listo = false;
    if (!$listo) { $listo = true; db_esquema($pdo); }
    return $pdo;
}

/* ---------- Ayudantes ---------- */
function db_q(string $sql, array $p = []): PDOStatement { $s = actas_db()->prepare($sql); $s->execute($p); return $s; }
function db_fila(string $sql, array $p = []): ?array { $r = db_q($sql, $p)->fetch(); return $r === false ? null : $r; }
function db_todas(string $sql, array $p = []): array { return db_q($sql, $p)->fetchAll(); }
function db_valor(string $sql, array $p = []) { $v = db_q($sql, $p)->fetchColumn(); return $v === false ? null : $v; }
function db_json($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function db_dejson(?string $s): ?array { if ($s === null) return null; $d = json_decode($s, true); return is_array($d) ? $d : null; }
/** Ejecuta $fn dentro de una transacción. */
function db_tx(callable $fn) {
    $pdo = actas_db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Bloqueo con nombre (dos personas no modifican lo mismo a la vez). */
function db_bloquear(string $nombre, int $seg = 15): string {
    $n = 'actas:' . substr(hash('sha256', $nombre), 0, 40);
    if ((int)db_valor('SELECT GET_LOCK(?, ?)', [$n, $seg]) !== 1) throw new RuntimeException('El sistema está ocupado; intente de nuevo en unos segundos.');
    return $n;
}
function db_desbloquear(string $n): void { try { db_q('SELECT RELEASE_LOCK(?)', [$n]); } catch (Throwable $e) {} }
function db_corta($v, int $max): string { $s = trim(is_scalar($v) ? (string)$v : ''); return preg_match('/^.{0,' . $max . '}/su', $s, $m) ? $m[0] : ''; }

/* ---------- Tablas ---------- */
function db_esquema(PDO $pdo): void {
    $ver = 4;
    try { if ((int)$pdo->query("SELECT valor FROM ajustes WHERE clave = 'esquema'")->fetchColumn() >= $ver) return; } catch (PDOException $e) {}
    $T = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $sql = [
        "CREATE TABLE IF NOT EXISTS ajustes (clave VARCHAR(64) PRIMARY KEY, valor LONGTEXT NOT NULL)$T",
        "CREATE TABLE IF NOT EXISTS usuarios (
            usuario VARCHAR(64) PRIMARY KEY, nombre VARCHAR(120) NOT NULL DEFAULT '', correo VARCHAR(160) NOT NULL DEFAULT '',
            rol VARCHAR(20) NOT NULL DEFAULT 'tecnico', activo TINYINT(1) NOT NULL DEFAULT 1,
            datos LONGTEXT NOT NULL COMMENT 'registro completo: hash de la contraseña, permisos, fechas',
            actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)$T",
        "CREATE TABLE IF NOT EXISTS intentos (clave CHAR(64) PRIMARY KEY, n INT NOT NULL DEFAULT 0, desde INT NOT NULL DEFAULT 0)$T",
        "CREATE TABLE IF NOT EXISTS reset_tokens (token CHAR(64) PRIMARY KEY, usuario VARCHAR(64) NOT NULL, expira INT NOT NULL, KEY (usuario))$T",
        "CREATE TABLE IF NOT EXISTS formularios (
            id VARCHAR(48) PRIMARY KEY, titulo VARCHAR(160) NOT NULL, publicado TINYINT(1) NOT NULL DEFAULT 1,
            autor VARCHAR(64) NOT NULL DEFAULT '', creado INT NOT NULL DEFAULT 0, modificado INT NOT NULL DEFAULT 0,
            modificado_por VARCHAR(64) NOT NULL DEFAULT '', def LONGTEXT NOT NULL COMMENT 'definición del formulario (JSON)')$T",
        "CREATE TABLE IF NOT EXISTS formularios_papelera (
            clave VARCHAR(64) PRIMARY KEY, id VARCHAR(48) NOT NULL, titulo VARCHAR(160) NOT NULL,
            eliminado INT NOT NULL, eliminado_por VARCHAR(64) NOT NULL DEFAULT '', def LONGTEXT NOT NULL)$T",
        "CREATE TABLE IF NOT EXISTS registros (
            form VARCHAR(90) NOT NULL COMMENT 'acta_entrega, acta_devolucion, bitacora o f:<formulario>', id VARCHAR(80) NOT NULL COMMENT 'número de registro',
            nombre VARCHAR(160) NOT NULL DEFAULT '' COMMENT 'colaborador o usuario principal', fecha VARCHAR(20) NOT NULL DEFAULT '',
            datos LONGTEXT NOT NULL COMMENT 'lo llenado (JSON), incluidas las firmas',
            creado INT NOT NULL, creado_por VARCHAR(64) NOT NULL DEFAULT '', modificado INT NOT NULL DEFAULT 0, modificado_por VARCHAR(64) NOT NULL DEFAULT '',
            migrado TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (form, id), KEY (form, creado), KEY (nombre))$T",
        "CREATE TABLE IF NOT EXISTS registros_eliminados (
            n BIGINT AUTO_INCREMENT PRIMARY KEY, form VARCHAR(90) NOT NULL, id VARCHAR(80) NOT NULL,
            eliminado INT NOT NULL, eliminado_por VARCHAR(64) NOT NULL DEFAULT '', doc LONGTEXT NOT NULL, KEY (form, id))$T",
        "CREATE TABLE IF NOT EXISTS pres_activos (
            id VARCHAR(40) PRIMARY KEY, codigo VARCHAR(40) NOT NULL DEFAULT '', tipo VARCHAR(60) NOT NULL DEFAULT '',
            descripcion VARCHAR(160) NOT NULL DEFAULT '', activo TINYINT(1) NOT NULL DEFAULT 1, datos LONGTEXT NOT NULL)$T",
        "CREATE TABLE IF NOT EXISTS pres_reservas (
            id VARCHAR(40) PRIMARY KEY, estado VARCHAR(20) NOT NULL DEFAULT 'reservada', nombre VARCHAR(120) NOT NULL DEFAULT '',
            departamento VARCHAR(120) NOT NULL DEFAULT '', inicio INT NOT NULL DEFAULT 0, fin INT NOT NULL DEFAULT 0, creado INT NOT NULL DEFAULT 0,
            datos LONGTEXT NOT NULL, KEY (estado), KEY (inicio))$T",
        "CREATE TABLE IF NOT EXISTS pres_pdf (id VARCHAR(40) NOT NULL, doc VARCHAR(20) NOT NULL, pdf LONGBLOB NOT NULL, creado INT NOT NULL, PRIMARY KEY (id, doc))$T",
        "CREATE TABLE IF NOT EXISTS colaboradores (
            n INT AUTO_INCREMENT PRIMARY KEY, codigo VARCHAR(40) NOT NULL DEFAULT '', nombre VARCHAR(160) NOT NULL DEFAULT '', cedula VARCHAR(40) NOT NULL DEFAULT '',
            puesto VARCHAR(160) NOT NULL DEFAULT '', departamento VARCHAR(160) NOT NULL DEFAULT '', correo VARCHAR(160) NOT NULL DEFAULT '', usuario VARCHAR(80) NOT NULL DEFAULT '',
            KEY (codigo), KEY (cedula), KEY (nombre))$T",
        "CREATE TABLE IF NOT EXISTS equipos (
            n INT AUTO_INCREMENT PRIMARY KEY, hostname VARCHAR(80) NOT NULL DEFAULT '', usuario VARCHAR(120) NOT NULL DEFAULT '', nombre VARCHAR(160) NOT NULL DEFAULT '',
            codigo VARCHAR(40) NOT NULL DEFAULT '', departamento VARCHAR(160) NOT NULL DEFAULT '', correo VARCHAR(160) NOT NULL DEFAULT '', observacion VARCHAR(255) NOT NULL DEFAULT '',
            so VARCHAR(120) NOT NULL DEFAULT '', modelo VARCHAR(120) NOT NULL DEFAULT '', serie VARCHAR(80) NOT NULL DEFAULT '',
            perifericos LONGTEXT NOT NULL COMMENT 'monitor, teléfono, mouse, teclado y UPS (JSON)', KEY (hostname), KEY (codigo), KEY (serie))$T",
        "CREATE TABLE IF NOT EXISTS inventario_versiones (
            n INT AUTO_INCREMENT PRIMARY KEY, actualizado INT NOT NULL, por VARCHAR(64) NOT NULL DEFAULT '', archivo VARCHAR(200) NOT NULL DEFAULT '',
            empleados INT NOT NULL DEFAULT 0, equipos INT NOT NULL DEFAULT 0, datos LONGTEXT NOT NULL,
            original LONGBLOB NULL, original_nombre VARCHAR(200) NOT NULL DEFAULT '')$T",
        "CREATE TABLE IF NOT EXISTS inventario_pendiente (clave CHAR(16) PRIMARY KEY, nombre VARCHAR(200) NOT NULL, datos LONGTEXT NOT NULL, original LONGBLOB NULL, creado INT NOT NULL)$T",
        "CREATE TABLE IF NOT EXISTS tecnicos (
            id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(120) NOT NULL, cedula VARCHAR(25) NOT NULL DEFAULT '',
            puesto VARCHAR(120) NOT NULL DEFAULT '', correo VARCHAR(160) NOT NULL DEFAULT '', usuario VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'cuenta del sistema (opcional)',
            activo TINYINT(1) NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0,
            creado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, actualizado TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY (nombre))$T",
        // Vistas para consultar fácilmente desde MySQL Workbench, phpMyAdmin o reportes
        "CREATE OR REPLACE VIEW v_actas_entrega AS SELECT id AS numero, nombre AS colaborador,
            JSON_UNQUOTE(JSON_EXTRACT(datos,'$.ced')) AS cedula, JSON_UNQUOTE(JSON_EXTRACT(datos,'$.dep')) AS dependencia,
            JSON_UNQUOTE(JSON_EXTRACT(datos,'$.cargo')) AS cargo, JSON_UNQUOTE(JSON_EXTRACT(datos,'$.tipo')) AS tipo_equipo, fecha,
            JSON_UNQUOTE(JSON_EXTRACT(datos,'$.ent')) AS funcionario_ti, creado_por, FROM_UNIXTIME(creado) AS registrado
            FROM registros WHERE form = 'acta_entrega'",
        "CREATE OR REPLACE VIEW v_actas_devolucion AS SELECT id AS numero, nombre AS colaborador,
            JSON_UNQUOTE(JSON_EXTRACT(datos,'$.ced')) AS cedula, JSON_UNQUOTE(JSON_EXTRACT(datos,'$.dep')) AS dependencia,
            JSON_UNQUOTE(JSON_EXTRACT(datos,'$.obs')) AS observaciones, fecha,
            JSON_UNQUOTE(JSON_EXTRACT(datos,'$.ent')) AS funcionario_ti, creado_por, FROM_UNIXTIME(creado) AS registrado
            FROM registros WHERE form = 'acta_devolucion'",
        "CREATE OR REPLACE VIEW v_prestamos AS SELECT id AS numero, estado, nombre AS solicitante, departamento,
            FROM_UNIXTIME(inicio) AS retiro, FROM_UNIXTIME(fin) AS devolucion, FROM_UNIXTIME(creado) AS reservado FROM pres_reservas",
    ];
    foreach ($sql as $q) $pdo->exec($q);
    // Técnicos del Departamento de TI (solo la primera vez; luego se administran en «Técnicos de TI»)
    if ((int)$pdo->query('SELECT COUNT(*) FROM tecnicos')->fetchColumn() === 0 && !$pdo->query("SELECT 1 FROM ajustes WHERE clave = 'tecnicos_iniciales'")->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO tecnicos (nombre, puesto, orden) VALUES (?, ?, ?)');
        foreach ([['Erick Ortega Morales', 'Soporte Técnico y Operaciones'], ['Carlos Mairena Lopez', 'Encargado de Infraestructura'],
                  ['Dereck Antonio Angulo Jaen', 'Encargado de Ciberseguridad'], ['Jason Barrantes Apu', 'Analista de Sistemas'],
                  ['Andrés Cortes Fuentes', 'Jefe del Departamento de Tecnologías de Información']] as $i => [$n, $p]) $ins->execute([$n, $p, $i + 1]);
        $pdo->exec("REPLACE INTO ajustes (clave, valor) VALUES ('tecnicos_iniciales', '1')");
    }
    $pdo->prepare("REPLACE INTO ajustes (clave, valor) VALUES ('esquema', ?)")->execute([(string)$ver]);
}

/* ---------- Ajustes (clave → JSON) ---------- */
function db_ajuste(string $clave, $defecto = null) {
    $v = db_valor('SELECT valor FROM ajustes WHERE clave = ?', [$clave]);
    return $v === null ? $defecto : json_decode((string)$v, true);
}
function db_ajuste_poner(string $clave, $valor): void { db_q('REPLACE INTO ajustes (clave, valor) VALUES (?, ?)', [$clave, db_json($valor)]); }

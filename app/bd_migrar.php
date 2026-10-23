<?php
/**
 * Prepara la base de datos MySQL de Actas TIC (solo desde la terminal, como root).
 *   php bd_migrar.php [--datos=/var/lib/actas] [--usuarios=/etc/actas/usuarios.json] [--paquete=<carpeta formularios del paquete>]
 *   php bd_migrar.php --probar        solo comprueba la conexión
 * 1. Crea las tablas (si no existen).
 * 2. Importa lo que la versión anterior guardaba en archivos JSON (solo la primera vez: si la tabla ya tiene datos, no la toca).
 * 3. Instala o actualiza los formularios que vienen con el paquete (sin pisar los editados desde el constructor).
 * Los archivos JSON anteriores no se borran: quedan como respaldo.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/auth.php';
require __DIR__ . '/formularios.php';
require __DIR__ . '/registros.php';
require __DIR__ . '/prestamos.php';
require __DIR__ . '/inventario.php';

$op = [];
foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) $op[$m[1]] = $m[2] ?? '1';
$DATOS = rtrim($op['datos'] ?? '/var/lib/actas', '/');
$USUARIOS = $op['usuarios'] ?? '/etc/actas/usuarios.json';
$PAQUETE = $op['paquete'] ?? '';

actas_db();   // conecta y crea las tablas
if (!empty($op['probar'])) { echo "   OK: conexión a MySQL (" . actas_db_config()['base'] . ") y tablas listas.\n"; exit(0); }

$leer = fn(string $f) => is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
$vacia = fn(string $t) => (int)db_valor("SELECT COUNT(*) FROM $t") === 0;
$dijo = fn(string $m) => print("   $m\n");

/* ---------- 1. Usuarios ---------- */
if ($vacia('usuarios') && is_array($d = $leer($USUARIOS))) {
    $us = [];
    foreach ($d as $u => $r) { if (is_string($r)) $r = ['hash' => $r]; if (is_array($r) && isset($r['hash'])) $us[(string)$u] = $r; }
    if ($us && actas_guardar_usuarios($us)) $dijo('Usuarios importados: ' . count($us));
}

/* ---------- 2. Formularios del constructor, papelera y formularios del sistema retirados ---------- */
if ($vacia('formularios') && is_dir("$DATOS/formularios")) {
    $n = 0;
    foreach (glob("$DATOS/formularios/*.json") ?: [] as $f) { $d = $leer($f); if (is_array($d) && !empty($d['id'])) { form_escribir($d); $n++; } }
    foreach (glob("$DATOS/formularios/papelera/*.json") ?: [] as $f) {
        $d = $leer($f); $clave = basename($f, '.json');
        if (!is_array($d) || !form_papelera_valida($clave)) continue;
        db_q('INSERT IGNORE INTO formularios_papelera (clave, id, titulo, eliminado, eliminado_por, def) VALUES (?, ?, ?, ?, ?, ?)',
             [$clave, (string)($d['_papelera']['id_original'] ?? $d['id'] ?? ''), db_corta($d['titulo'] ?? '', 160), (int)($d['_papelera']['at'] ?? filemtime($f)), (string)($d['_papelera']['por'] ?? ''), db_json($d)]);
    }
    if (is_array($s = $leer("$DATOS/formularios/.sistema.json"))) db_ajuste_poner('formularios_sistema', $s);
    if ($n) $dijo("Formularios importados: $n");
}

/* ---------- 3. Registros (actas, bitácoras y formularios llenados) ---------- */
if ($vacia('registros') && is_dir("$DATOS/registros")) {
    $n = 0; $e = 0;
    foreach (glob("$DATOS/registros/*", GLOB_ONLYDIR) ?: [] as $dir) {
        $b = basename($dir);
        $form = str_starts_with($b, 'f-') ? 'f:' . substr($b, 2) : $b;
        if (reg_carpeta($form) === null) continue;
        foreach (glob("$dir/*.json") ?: [] as $f) {
            $d = $leer($f);
            if (!is_array($d) || !isset($d['id'], $d['datos'])) continue;
            $d['form'] = $form; $d['creado'] = (int)($d['creado'] ?? filemtime($f)); $d['creado_por'] = (string)($d['creado_por'] ?? '');
            reg_escribir($d); $n++;
        }
        foreach (glob("$dir/_eliminados/*.json") ?: [] as $f) {
            $d = $leer($f);
            if (!is_array($d) || !isset($d['id'])) continue;
            db_q('INSERT INTO registros_eliminados (form, id, eliminado, eliminado_por, doc) VALUES (?, ?, ?, ?, ?)',
                 [$form, (string)$d['id'], (int)($d['eliminado'] ?? filemtime($f)), (string)($d['eliminado_por'] ?? ''), db_json($d)]);
            $e++;
        }
    }
    if ($n || $e) $dijo("Registros importados: $n" . ($e ? " (y $e eliminados conservados)" : ''));
}

/* ---------- 4. Préstamo de activos ---------- */
if ($vacia('pres_activos') && $vacia('pres_reservas') && is_dir("$DATOS/prestamos")) {
    $act = $leer("$DATOS/prestamos/activos.json"); $res = $leer("$DATOS/prestamos/reservas.json");
    db_tx(function () use ($act, $res) { if (is_array($act)) pres_escribir('activos', $act); if (is_array($res)) pres_escribir('reservas', $res); });
    $p = 0;
    foreach (glob("$DATOS/prestamos/pdf/*.pdf") ?: [] as $f) {
        if (!preg_match('/^(.+)-(reserva|entrega|devolucion)\.pdf$/', basename($f), $m)) continue;
        $st = actas_db()->prepare('REPLACE INTO pres_pdf (id, doc, pdf, creado) VALUES (?, ?, ?, ?)');
        $st->bindValue(1, $m[1]); $st->bindValue(2, $m[2]); $st->bindValue(3, (string)file_get_contents($f), PDO::PARAM_LOB); $st->bindValue(4, filemtime($f), PDO::PARAM_INT);
        $st->execute(); $p++;
    }
    if (is_array($act) || is_array($res)) $dijo('Préstamos importados: ' . count((array)$act) . ' activos, ' . count((array)$res) . " reservas, $p PDF");
}

/* ---------- 5. Base de datos de colaboradores (inventario) ---------- */
if ($vacia('inventario_versiones') && is_array($cur = $leer("$DATOS/datos/inventario.json"))) {
    $hist = glob("$DATOS/datos/historial/inventario-*.json") ?: []; sort($hist);
    foreach ($hist as $f) {
        $d = $leer($f); if (!is_array($d)) continue;
        $orig = !empty($d['original']) && is_file("$DATOS/datos/historial/{$d['original']}") ? (string)file_get_contents("$DATOS/datos/historial/{$d['original']}") : null;
        $st = actas_db()->prepare('INSERT INTO inventario_versiones (actualizado, por, archivo, empleados, equipos, datos, original, original_nombre) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([(int)($d['actualizado'] ?? filemtime($f)), (string)($d['por'] ?? ''), db_corta($d['archivo'] ?? '', 200), count($d['empleados'] ?? []), count($d['equipos'] ?? []),
                      db_json(['empleados' => $d['empleados'] ?? [], 'equipos' => $d['equipos'] ?? []]), $orig, $orig === null ? '' : db_corta($d['archivo'] ?? '', 200)]);
    }
    $orig = !empty($cur['original']) && is_file("$DATOS/datos/historial/{$cur['original']}") ? (string)file_get_contents("$DATOS/datos/historial/{$cur['original']}") : null;
    if (inv_guardar($cur, (string)($cur['por'] ?? 'migración'), (string)($cur['archivo'] ?? ''), $orig)) {
        $m = db_ajuste('inventario', []); $m['actualizado'] = (int)($cur['actualizado'] ?? time()); $m['por'] = (string)($cur['por'] ?? '');
        db_ajuste_poner('inventario', $m);
        db_q('UPDATE inventario_versiones SET actualizado = ?, por = ? WHERE n = ?', [$m['actualizado'], $m['por'], (int)$m['version']]);
        $dijo('Colaboradores importados: ' . count($cur['empleados'] ?? []) . ', equipos: ' . count($cur['equipos'] ?? []) . ', versiones anteriores: ' . count($hist));
    }
}

/* ---------- 6. Formularios incluidos en el paquete ---------- */
// El formulario «Préstamo de Activos» del constructor se reemplazó por el módulo de préstamos
if (($v = form_cargar('prestamo-de-activos')) && ($v['modificado_por'] ?? '') === 'instalador') {
    db_q('DELETE FROM formularios WHERE id = ?', ['prestamo-de-activos']);
    $dijo('Se retiró el formulario «Préstamo de Activos» anterior: ahora es el módulo de préstamos.');
}
if ($PAQUETE !== '' && is_dir($PAQUETE)) {
    foreach (glob("$PAQUETE/*.json") ?: [] as $f) {
        $d = $leer($f); $id = basename($f, '.json');
        if (!is_array($d) || !form_id_valido($id)) continue;
        $d['id'] = $id;
        $actual = form_cargar($id);
        if (db_valor('SELECT 1 FROM formularios_papelera WHERE id = ? LIMIT 1', [$id]) !== null && !$actual)
            $dijo("Se omite «{$id}»: está en la Papelera (restáurelo desde ahí si lo necesita).");
        elseif (!$actual) { form_escribir($d); $dijo("Formulario agregado: $id"); }
        elseif (($actual['modificado_por'] ?? '') === 'instalador') {
            if (db_json($actual) !== db_json($d)) { form_escribir($d); $dijo("Formulario actualizado: $id"); }
        } else $dijo("Se conserva «{$id}» porque fue editado desde el constructor.");
    }
}

/* ---------- Resumen ---------- */
$c = fn(string $t, string $w = '') => (int)db_valor("SELECT COUNT(*) FROM $t" . ($w ? " WHERE $w" : ''));
$dijo(sprintf('Base de datos lista: %d usuarios, %d formularios, %d registros (%d actas de entrega, %d de devolución), %d reservas de préstamo, %d colaboradores.',
    $c('usuarios'), $c('formularios'), $c('registros'), $c('registros', "form='acta_entrega'"), $c('registros', "form='acta_devolucion'"), $c('pres_reservas'), $c('colaboradores')));

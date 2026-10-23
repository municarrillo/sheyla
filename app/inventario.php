<?php
/**
 * Base de datos de colaboradores y equipos (se carga desde el Excel de inventario).
 *   /var/lib/actas/datos/inventario.json        datos vigentes
 *   /var/lib/actas/datos/historial/             versiones anteriores + archivo original subido
 * El Excel se lee sin extensiones de PHP adicionales (zip y XML se procesan aquí mismo).
 */
declare(strict_types=1);

define('INV_DIR', getenv('ACTAS_INV_DIR') ?: '/var/lib/actas/datos');   // solo para migrar la versión anterior
require_once __DIR__ . '/db.php';
const INV_HISTORIAL = 15;   // versiones anteriores que se conservan

/* ======================= Lectura de .xlsx ======================= */

/** Devuelve [ruta interna => contenido] de un .zip (solo «stored» y «deflate»). */
function inv_zip(string $bin): array {
    $eocd = strrpos($bin, "PK\x05\x06");
    if ($eocd === false) throw new RuntimeException('El archivo no es un Excel (.xlsx) válido.');
    $e = unpack('vdisco/vdiscocd/ventradas/vtotal/Vtam/Voffset', substr($bin, $eocd + 4, 16));
    $p = $e['offset']; $out = [];
    for ($i = 0; $i < $e['total']; $i++) {
        if (substr($bin, $p, 4) !== "PK\x01\x02") break;
        $c = unpack('vver/vnec/vflag/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vtam/vlnom/vlextra/vlcom/vdisco/vintatr/Vextatr/Vlocal', substr($bin, $p + 4, 42));
        $nombre = substr($bin, $p + 46, $c['lnom']);
        $p += 46 + $c['lnom'] + $c['lextra'] + $c['lcom'];
        $l = unpack('vlnom/vlextra', substr($bin, $c['local'] + 26, 4));
        $datos = substr($bin, $c['local'] + 30 + $l['lnom'] + $l['lextra'], $c['comp']);
        if ($c['metodo'] === 8) $datos = @gzinflate($datos);
        elseif ($c['metodo'] !== 0) continue;
        if ($datos !== false && $c['tam'] < 60_000_000) $out[$nombre] = $datos;
    }
    return $out;
}
function inv_xml_texto(string $s): string { return html_entity_decode($s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/** Lee todas las hojas de un .xlsx: [nombre de hoja => filas (arreglos de celdas)]. */
function inv_leer_xlsx(string $bin): array {
    $z = inv_zip($bin);
    if (!isset($z['xl/workbook.xml'])) throw new RuntimeException('El archivo no es un Excel (.xlsx) válido.');
    $comp = [];
    if (isset($z['xl/sharedStrings.xml']) && preg_match_all('#<si>(.*?)</si>#s', $z['xl/sharedStrings.xml'], $m))
        foreach ($m[1] as $si) { preg_match_all('#<t[^>]*>(.*?)</t>#s', $si, $t); $comp[] = inv_xml_texto(implode('', $t[1])); }
    $rels = [];
    $attr = fn(string $tag, string $n) => preg_match('#\s' . $n . '="([^"]*)"#', $tag, $x) ? $x[1] : '';
    preg_match_all('#<Relationship\b[^>]*>#', $z['xl/_rels/workbook.xml.rels'] ?? '', $m);
    foreach ($m[0] as $tag) $rels[$attr($tag, 'Id')] = 'xl/' . ltrim(preg_replace('#^/?xl/#', '', $attr($tag, 'Target')), '/');
    $hojas = [];
    preg_match_all('#<(?:\w+:)?sheet\b[^>]*>#', $z['xl/workbook.xml'], $m);
    $m = array_map(fn($tag) => [$tag, $attr($tag, 'name'), $attr($tag, 'r:id') ?: $attr($tag, '\w+:id')], $m[0]);
    foreach ($m as $h) {
        $xml = $z[$rels[$h[2]] ?? ''] ?? '';
        $filas = [];
        preg_match_all('#<row\b[^>]*>(.*?)</row>#s', $xml, $rs);
        foreach ($rs[1] as $row) {
            $fila = [];
            preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $row, $cs, PREG_SET_ORDER);
            foreach ($cs as $c) {
                preg_match('#\br="([A-Z]+)\d+"#', $c[1], $ref);
                $col = 0; foreach (str_split($ref[1] ?? 'A') as $ch) $col = $col * 26 + (ord($ch) - 64); $col--;
                $tipo = preg_match('#\bt="([^"]+)"#', $c[1], $t) ? $t[1] : 'n';
                $inner = $c[2] ?? '';
                if ($tipo === 'inlineStr') { preg_match_all('#<t[^>]*>(.*?)</t>#s', $inner, $t); $v = inv_xml_texto(implode('', $t[1])); }
                else {
                    $v = preg_match('#<v>(.*?)</v>#s', $inner, $vv) ? inv_xml_texto($vv[1]) : '';
                    if ($tipo === 's') $v = $comp[(int)$v] ?? '';
                    elseif ($tipo === 'n' && $v !== '' && is_numeric($v) && (float)$v == floor((float)$v) && abs((float)$v) < 1e15) $v = (string)(int)(float)$v;
                }
                $fila[$col] = trim((string)$v);
            }
            if ($fila) { $max = max(array_keys($fila)); $filas[] = array_map(fn($i) => $fila[$i] ?? '', range(0, $max)); }
        }
        $hojas[inv_xml_texto($h[1])] = $filas;
    }
    return $hojas;
}
/** CSV (; o ,) como una sola hoja. */
function inv_leer_csv(string $bin): array {
    $bin = preg_replace('/^\xEF\xBB\xBF/', '', $bin);
    if (!preg_match('//u', $bin)) $bin = mb_convert_encoding_seguro($bin);
    $sep = substr_count(strtok($bin, "\n"), ';') >= substr_count(strtok($bin, "\n"), ',') ? ';' : ',';
    $f = fopen('php://memory', 'r+'); fwrite($f, $bin); rewind($f);
    $filas = [];
    while (($r = fgetcsv($f, 0, $sep, '"', '\\')) !== false) $filas[] = array_map('trim', $r);
    return ['CSV' => $filas];
}
function mb_convert_encoding_seguro(string $s): string { return function_exists('iconv') ? (string)@iconv('Windows-1252', 'UTF-8//IGNORE', $s) : $s; }

/* ======================= Normalización ======================= */

function inv_clave(string $s): string {   // «Código Empleado» → «codigoempleado»
    $s = strtolower(strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n','°'=>'','º'=>'']));
    return preg_replace('/[^a-z0-9]/', '', $s);
}
function inv_buscable(string $s): string {
    return strtolower(strtr($s, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ñ'=>'n','Ü'=>'u']));
}
/** Valor de serie útil (descarta textos de relleno de fábrica). */
function inv_serie(string $s): string {
    return preg_match('/^(system serial number|to be filled.*|default string|pantalla|n\/?a|-+|none|0+)$/i', trim($s)) ? '' : trim($s);
}
function inv_marca_de_modelo(string $m): string {
    foreach (['Dell' => '/dell|optiplex|latitude|precision|vostro|inspiron/i', 'HP' => '/\bhp\b|probook|elitebook|prodesk/i', 'Lenovo' => '/lenovo|thinkpad|thinkcentre|ideapad/i', 'Zebra' => '/^ET\d/i', 'Apple' => '/mac|apple/i'] as $marca => $re)
        if (preg_match($re, $m)) return $marca;
    return '';
}
/** «DELL» → «Dell»; deja las siglas cortas (LG, CDP) como vienen. */
function inv_marca_bonita(string $m): string { $m = trim($m); return strlen($m) > 3 && strtoupper($m) === $m ? ucfirst(strtolower($m)) : $m; }
function inv_tipo_equipo(string $m): string {
    if (preg_match('/all-?in-?one|\baio\b/i', $m)) return 'All in One';
    if (preg_match('/latitude|probook|elitebook|thinkpad|notebook|laptop|precision 7[5-7]\d\d|macbook/i', $m)) return 'Laptop';
    if (preg_match('/^ET\d|tablet|ipad|\btab\b/i', $m)) return 'Tablet';
    if (preg_match('/optiplex|prodesk|elitedesk|thinkcentre|vostro \d+ (?:tower|sff|mt)|desktop|tower|\bsff\b|\bmt\b|mini ?pc|\bmicro\b|workstation/i', $m)) return 'Escritorio';
    return '';
}

/** Convierte las hojas leídas en {empleados, equipos, avisos}. */
function inv_normalizar(array $hojas): array {
    $emp = []; $eq = []; $avisos = []; $hojaEmp = $hojaEq = null;
    foreach ($hojas as $nombre => $filas) {
        if (!$filas) continue;
        $cab = array_map('inv_clave', $filas[0]);
        $idx = array_flip(array_reverse($cab, true));
        $g = fn(array $r, string ...$claves) => (function () use ($r, $claves, $idx) { foreach ($claves as $k) if (isset($idx[$k]) && ($r[$idx[$k]] ?? '') !== '') return (string)$r[$idx[$k]]; return ''; })();
        $esEq = isset($idx['computername']) || isset($idx['servicetagserialnumber']);
        $esEmp = !$esEq && isset($idx['nombrecompleto']) && (isset($idx['cedula']) || isset($idx['codigo']) || isset($idx['puesto']));
        if ($esEmp && $hojaEmp === null) {
            $hojaEmp = $nombre;
            foreach (array_slice($filas, 1) as $r) {
                $n = $g($r, 'nombrecompleto'); if ($n === '') continue;
                $correo = strtolower($g($r, 'correoelectronico', 'correo', 'email'));
                $emp[] = ['codigo' => $g($r, 'codigo', 'codigoempleado'), 'nombre' => $n, 'cedula' => $g($r, 'cedula'), 'puesto' => $g($r, 'puesto', 'cargo'),
                          'departamento' => $g($r, 'departamento', 'dependencia'), 'correo' => $correo, 'usuario' => $correo !== '' ? strtok($correo, '@') : ''];
            }
        } elseif ($esEq && $hojaEq === null) {
            $hojaEq = $nombre;
            foreach (array_slice($filas, 1) as $r) {
                $host = $g($r, 'computername'); $modelo = preg_match('/^(system product name|to be filled.*|default string)$/i', $g($r, 'devicemodel')) ? '' : $g($r, 'devicemodel');
                if ($host === '' && $modelo === '') continue;
                $eq[] = ['hostname' => $host, 'usuario' => $g($r, 'loggedonusers'), 'nombre' => $g($r, 'nombrecompleto'), 'codigo' => $g($r, 'codigoempleado', 'codigo'),
                    'departamento' => $g($r, 'departamento'), 'correo' => strtolower($g($r, 'correoelectronico')), 'observacion' => $g($r, 'observacion'),
                    'so' => $g($r, 'operatingsystem'), 'modelo' => $modelo, 'serie' => inv_serie($g($r, 'servicetagserialnumber')),
                    'monitor' => ['marca' => $g($r, 'marcadelmonitor'), 'modelo' => $g($r, 'modelomonitor'), 'serie' => inv_serie($g($r, 'servicetagmonitor'))],
                    'telefono' => ['marca' => $g($r, 'marcadeltelefono'), 'modelo' => $g($r, 'modeldeltelefono', 'modelodeltelefono'), 'serie' => inv_serie($g($r, 'seriedeltelefono'))],
                    'mouse' => ['marca' => $g($r, 'marcamouse')], 'teclado' => ['marca' => $g($r, 'marcateclado')],
                    'ups' => ['marca' => $g($r, 'marcaups'), 'modelo' => $g($r, 'modeloups'), 'serie' => inv_serie($g($r, 'serieups'))]];
            }
        }
    }
    if ($hojaEmp === null && $hojaEq === null)
        throw new RuntimeException('No encontré las columnas esperadas. La hoja de empleados debe tener «Nombre Completo» y «Cédula» o «Código»; la de equipos, «Computer Name» o «Service Tag/Serial Number».');
    if ($hojaEmp === null) $avisos[] = 'No se encontró la hoja de empleados: solo se cargarán los equipos.';
    if ($hojaEq === null) $avisos[] = 'No se encontró la hoja de equipos: solo se cargarán los colaboradores.';
    // Nombre, departamento y correo del equipo salen de la hoja de empleados cuando vienen vacíos (p. ej. fórmulas sin calcular)
    $porCod = [];
    foreach ($emp as $e) if ($e['codigo'] !== '') $porCod[$e['codigo']] = $e;
    foreach ($eq as &$q) {
        $e = $q['codigo'] !== '' ? ($porCod[$q['codigo']] ?? null) : null;
        if ($e) foreach (['nombre', 'departamento', 'correo'] as $k) if ($q[$k] === '') $q[$k] = $e[$k];
    }
    unset($q);
    $sinCorreo = count(array_filter($emp, fn($e) => $e['correo'] === ''));
    if ($sinCorreo) $avisos[] = "$sinCorreo colaborador(es) no tienen correo electrónico.";
    $sinCod = count(array_filter($eq, fn($e) => $e['codigo'] === ''));
    if ($sinCod) $avisos[] = "$sinCod equipo(s) no tienen código de empleado; se asocian por usuario o correo cuando es posible.";
    return ['empleados' => $emp, 'equipos' => $eq, 'avisos' => $avisos, 'hojas' => array_filter([$hojaEmp, $hojaEq])];
}

/* ======================= Guardar / cargar ======================= */

const INV_CAMPOS_EMP = ['codigo', 'nombre', 'cedula', 'puesto', 'departamento', 'correo', 'usuario'];
const INV_CAMPOS_EQ  = ['hostname', 'usuario', 'nombre', 'codigo', 'departamento', 'correo', 'observacion', 'so', 'modelo', 'serie'];
const INV_PERIFERICOS = ['monitor', 'telefono', 'mouse', 'teclado', 'ups'];

/** Datos vigentes: tablas «colaboradores» y «equipos» + datos de la carga (ajuste «inventario»). */
function inv_cargar(): array {
    $meta = db_ajuste('inventario', []);
    $emp = array_map(fn($f) => array_combine(INV_CAMPOS_EMP, array_map(fn($k) => (string)$f[$k], INV_CAMPOS_EMP)),
                     db_todas('SELECT ' . implode(', ', INV_CAMPOS_EMP) . ' FROM colaboradores ORDER BY n'));
    $eq = [];
    foreach (db_todas('SELECT ' . implode(', ', INV_CAMPOS_EQ) . ', perifericos FROM equipos ORDER BY n') as $f) {
        $x = array_combine(INV_CAMPOS_EQ, array_map(fn($k) => (string)$f[$k], INV_CAMPOS_EQ));
        $p = db_dejson($f['perifericos']) ?? [];
        foreach (INV_PERIFERICOS as $k) $x[$k] = is_array($p[$k] ?? null) ? $p[$k] : [];
        $eq[] = $x;
    }
    return ['empleados' => $emp, 'equipos' => $eq, 'actualizado' => (int)($meta['actualizado'] ?? 0), 'por' => (string)($meta['por'] ?? ''),
            'archivo' => (string)($meta['archivo'] ?? ''), 'version' => (int)($meta['version'] ?? 0), 'original' => !empty($meta['original']) ? (string)$meta['version'] : ''];
}
/** Reemplaza los datos vigentes y guarda la carga como una versión nueva (con el Excel original). */
function inv_guardar(array $datos, string $usuario, string $archivo, ?string $original): bool {
    try {
        db_tx(function () use ($datos, $usuario, $archivo, $original) {
            $emp = array_values($datos['empleados'] ?? []); $eq = array_values($datos['equipos'] ?? []);
            $st = actas_db()->prepare('INSERT INTO inventario_versiones (actualizado, por, archivo, empleados, equipos, datos, original, original_nombre) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $st->bindValue(1, time(), PDO::PARAM_INT); $st->bindValue(2, $usuario); $st->bindValue(3, db_corta($archivo, 200));
            $st->bindValue(4, count($emp), PDO::PARAM_INT); $st->bindValue(5, count($eq), PDO::PARAM_INT);
            $st->bindValue(6, db_json(['empleados' => $emp, 'equipos' => $eq]));
            $st->bindValue(7, $original, $original === null ? PDO::PARAM_NULL : PDO::PARAM_LOB); $st->bindValue(8, $original === null ? '' : db_corta($archivo, 200));
            $st->execute();
            $n = (int)actas_db()->lastInsertId();
            db_q('DELETE FROM colaboradores'); db_q('DELETE FROM equipos');
            $ie = actas_db()->prepare('INSERT INTO colaboradores (' . implode(', ', INV_CAMPOS_EMP) . ') VALUES (' . implode(', ', array_fill(0, count(INV_CAMPOS_EMP), '?')) . ')');
            foreach ($emp as $e) $ie->execute(array_map(fn($k) => db_corta($e[$k] ?? '', $k === 'usuario' ? 80 : 160), INV_CAMPOS_EMP));
            $iq = actas_db()->prepare('INSERT INTO equipos (' . implode(', ', INV_CAMPOS_EQ) . ', perifericos) VALUES (' . implode(', ', array_fill(0, count(INV_CAMPOS_EQ) + 1, '?')) . ')');
            $lim = ['hostname' => 80, 'usuario' => 120, 'codigo' => 40, 'observacion' => 255, 'so' => 120, 'modelo' => 120, 'serie' => 80];
            foreach ($eq as $q) $iq->execute(array_merge(array_map(fn($k) => db_corta($q[$k] ?? '', $lim[$k] ?? 160), INV_CAMPOS_EQ),
                                                         [db_json(array_intersect_key($q, array_flip(INV_PERIFERICOS)))]));
            db_ajuste_poner('inventario', ['actualizado' => time(), 'por' => $usuario, 'archivo' => $archivo, 'version' => $n, 'original' => $original !== null]);
            // Conservar solo las últimas versiones
            $corte = db_valor('SELECT n FROM inventario_versiones ORDER BY n DESC LIMIT 1 OFFSET ' . INV_HISTORIAL);
            if ($corte !== null) db_q('DELETE FROM inventario_versiones WHERE n <= ?', [(int)$corte]);
        });
        return true;
    } catch (Throwable $e) { error_log('[actas-tic] inventario: ' . $e->getMessage()); return false; }
}
/** Versiones anteriores a la vigente (más recientes primero). 'archivo' es el número de versión. */
function inv_historial(): array {
    $vig = (int)(db_ajuste('inventario', [])['version'] ?? 0);
    return array_map(fn($f) => ['archivo' => (string)$f['n'], 'actualizado' => (int)$f['actualizado'], 'por' => $f['por'], 'nombre' => $f['archivo'],
                                'empleados' => (int)$f['empleados'], 'equipos' => (int)$f['equipos'], 'original' => $f['tiene'] ? (string)$f['n'] : ''],
        db_todas('SELECT n, actualizado, por, archivo, empleados, equipos, original IS NOT NULL AS tiene FROM inventario_versiones WHERE n <> ? ORDER BY n DESC', [$vig]));
}
function inv_version(int $n): ?array {
    $f = db_fila('SELECT actualizado, por, archivo, datos, original, original_nombre FROM inventario_versiones WHERE n = ?', [$n]);
    if (!$f) return null;
    $d = db_dejson($f['datos']) ?? [];
    return ['empleados' => $d['empleados'] ?? [], 'equipos' => $d['equipos'] ?? [], 'actualizado' => (int)$f['actualizado'], 'por' => $f['por'],
            'archivo' => $f['archivo'], 'original' => $f['original'] === null ? null : (string)$f['original'], 'original_nombre' => $f['original_nombre']];
}
/* Carga pendiente de confirmar (se revisa antes de aplicar) */
function inv_pendiente_poner(string $clave, string $nombre, array $datos, string $bin): void {
    db_q('DELETE FROM inventario_pendiente WHERE clave = ? OR creado < ?', [$clave, time() - 86400]);
    $st = actas_db()->prepare('INSERT INTO inventario_pendiente (clave, nombre, datos, original, creado) VALUES (?, ?, ?, ?, ?)');
    $st->bindValue(1, $clave); $st->bindValue(2, db_corta($nombre, 200)); $st->bindValue(3, db_json($datos)); $st->bindValue(4, $bin, PDO::PARAM_LOB); $st->bindValue(5, time(), PDO::PARAM_INT);
    $st->execute();
}
function inv_pendiente(string $clave): ?array {
    $f = db_fila('SELECT nombre, datos, original FROM inventario_pendiente WHERE clave = ?', [$clave]);
    return $f ? ['nombre' => $f['nombre'], 'datos' => db_dejson($f['datos']) ?? [], 'original' => (string)$f['original']] : null;
}
function inv_pendiente_borrar(string $clave): void { db_q('DELETE FROM inventario_pendiente WHERE clave = ?', [$clave]); }

/** Compara dos versiones: nuevos, modificados y retirados por colaborador (cédula o código) y por equipo (hostname o serie). */
function inv_comparar(array $antes, array $despues): array {
    $kEmp = fn($e) => $e['cedula'] !== '' ? 'c' . $e['cedula'] : 'k' . $e['codigo'] . $e['nombre'];
    $kEq = fn($e) => $e['hostname'] !== '' ? 'h' . strtolower($e['hostname']) : 's' . $e['serie'];
    $res = [];
    foreach (['empleados' => $kEmp, 'equipos' => $kEq] as $tipo => $k) {
        $a = []; foreach ($antes[$tipo] ?? [] as $x) $a[$k($x)] = $x;
        $b = []; foreach ($despues[$tipo] ?? [] as $x) $b[$k($x)] = $x;
        $nuevos = array_values(array_diff_key($b, $a)); $retirados = array_values(array_diff_key($a, $b));
        $mod = [];
        foreach (array_intersect_key($b, $a) as $clave => $x) if (json_encode($x) !== json_encode($a[$clave])) $mod[] = ['antes' => $a[$clave], 'despues' => $x];
        $res[$tipo] = ['nuevos' => $nuevos, 'modificados' => $mod, 'retirados' => $retirados];
    }
    return $res;
}

/* ======================= Búsqueda para las actas ======================= */

/** Equipos asociados a un colaborador (por código, usuario o correo). */
function inv_equipos_de(array $e, array $equipos): array {
    return array_values(array_filter($equipos, fn($q) =>
        ($e['codigo'] !== '' && $q['codigo'] === $e['codigo'])
        || ($q['codigo'] === '' && $e['correo'] !== '' && ($q['correo'] === $e['correo'] || strtolower($q['usuario']) === $e['usuario']))));
}
/** Filas para la tabla de equipos del acta: descripción, marca, modelo, serie, N.° de activo. */
function inv_filas_acta(array $q): array {
    $f = [];
    $tipo = inv_tipo_equipo($q['modelo']);
    $f[] = ['Computadora ' . ($tipo !== '' ? ($tipo === 'Escritorio' ? 'de escritorio' : $tipo) . ' ' : '') . ($q['hostname'] !== '' ? '(' . strtoupper($q['hostname']) . ')' : ''), inv_marca_de_modelo($q['modelo']), $q['modelo'], $q['serie'], ''];
    foreach (['monitor' => 'Monitor', 'telefono' => 'Teléfono IP', 'mouse' => 'Mouse', 'teclado' => 'Teclado', 'ups' => 'UPS'] as $k => $nom) {
        $x = $q[$k] ?? [];
        if (trim(($x['marca'] ?? '') . ($x['modelo'] ?? '') . ($x['serie'] ?? '')) === '') continue;
        $f[] = [$nom, inv_marca_bonita($x['marca'] ?? ''), $x['modelo'] ?? '', $x['serie'] ?? '', ''];
    }
    return array_map(fn($r) => array_map('trim', $r), $f);
}
function inv_buscar(array $inv, string $q, int $max = 12): array {
    $q = trim(inv_buscable($q));
    if (strlen($q) < 2) return [];
    $palabras = preg_split('/\s+/', $q);
    $coincide = fn(string $texto) => array_reduce($palabras, fn($ok, $p) => $ok && str_contains($texto, $p), true);
    $out = []; $vistos = [];
    foreach ($inv['empleados'] ?? [] as $e) {
        $eqs = inv_equipos_de($e, $inv['equipos'] ?? []);
        $texto = inv_buscable(implode(' ', [$e['nombre'], $e['cedula'], $e['codigo'], $e['correo'], $e['usuario'], $e['puesto'], $e['departamento']]) . ' ' . implode(' ', array_map(fn($x) => $x['hostname'] . ' ' . $x['serie'] . ' ' . ($x['monitor']['serie'] ?? '') . ' ' . ($x['ups']['serie'] ?? '') . ' ' . ($x['telefono']['serie'] ?? '') . ' ' . $x['usuario'], $eqs)));
        if (!$coincide($texto)) continue;
        foreach ($eqs as $x) $vistos[$x['hostname'] . $x['serie']] = true;
        $out[] = ['empleado' => $e, 'equipos' => array_map(fn($x) => ['hostname' => $x['hostname'], 'modelo' => $x['modelo'], 'serie' => $x['serie'], 'tipo' => inv_tipo_equipo($x['modelo']), 'usuario' => $x['usuario'], 'filas' => inv_filas_acta($x)], $eqs)];
        if (count($out) >= $max) return $out;
    }
    // Equipos que no están ligados a un colaborador de la lista
    foreach ($inv['equipos'] ?? [] as $x) {
        if (isset($vistos[$x['hostname'] . $x['serie']])) continue;
        $texto = inv_buscable(implode(' ', [$x['hostname'], $x['serie'], $x['nombre'], $x['usuario'], $x['correo'], $x['departamento'], $x['monitor']['serie'] ?? '', $x['ups']['serie'] ?? '', $x['telefono']['serie'] ?? '']));
        $ligado = $x['codigo'] !== '' && array_filter($inv['empleados'] ?? [], fn($e) => $e['codigo'] === $x['codigo']);
        if ($ligado || !$coincide($texto)) continue;
        $out[] = ['empleado' => ['codigo' => $x['codigo'], 'nombre' => $x['nombre'], 'cedula' => '', 'puesto' => '', 'departamento' => $x['departamento'], 'correo' => $x['correo'], 'usuario' => $x['usuario']],
                  'equipos' => [['hostname' => $x['hostname'], 'modelo' => $x['modelo'], 'serie' => $x['serie'], 'tipo' => inv_tipo_equipo($x['modelo']), 'usuario' => $x['usuario'], 'filas' => inv_filas_acta($x)]]];
        if (count($out) >= $max) break;
    }
    return $out;
}

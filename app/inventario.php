<?php
/**
 * Base de datos de colaboradores y equipos (se carga desde el Excel de inventario).
 *   /var/lib/actas/datos/inventario.json        datos vigentes
 *   /var/lib/actas/datos/historial/             versiones anteriores + archivo original subido
 * El Excel se lee sin extensiones de PHP adicionales (zip y XML se procesan aquí mismo).
 */
declare(strict_types=1);

define('INV_DIR', getenv('ACTAS_INV_DIR') ?: '/var/lib/actas/datos');
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

function inv_cargar(): array {
    $d = json_decode((string)@file_get_contents(INV_DIR . '/inventario.json'), true);
    return is_array($d) ? $d : ['empleados' => [], 'equipos' => [], 'actualizado' => 0];
}
function inv_guardar(array $datos, string $usuario, string $archivo, ?string $original): bool {
    foreach ([INV_DIR, INV_DIR . '/historial'] as $d) if (!is_dir($d)) @mkdir($d, 0770, true);
    $actual = INV_DIR . '/inventario.json';
    if (is_file($actual)) @copy($actual, INV_DIR . '/historial/inventario-' . date('Ymd-His') . '.json');
    $datos['actualizado'] = time(); $datos['por'] = $usuario; $datos['archivo'] = $archivo;
    if ($original !== null) {
        $datos['original'] = 'original-' . date('Ymd-His') . '.' . (preg_match('/\.csv$/i', $archivo) ? 'csv' : 'xlsx');
        @file_put_contents(INV_DIR . '/historial/' . $datos['original'], $original);
    }
    unset($datos['avisos']);
    $tmp = $actual . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, $actual)) { @unlink($tmp); return false; }
    // Conservar solo las últimas versiones
    $h = glob(INV_DIR . '/historial/inventario-*.json') ?: []; rsort($h);
    foreach (array_slice($h, INV_HISTORIAL) as $viejo) @unlink($viejo);
    $o = glob(INV_DIR . '/historial/original-*') ?: []; rsort($o);
    foreach (array_slice($o, INV_HISTORIAL + 1) as $viejo) @unlink($viejo);
    return true;
}
function inv_historial(): array {
    $out = [];
    foreach (glob(INV_DIR . '/historial/inventario-*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d)) $out[] = ['archivo' => basename($f), 'actualizado' => $d['actualizado'] ?? filemtime($f), 'por' => $d['por'] ?? '', 'nombre' => $d['archivo'] ?? '',
            'empleados' => count($d['empleados'] ?? []), 'equipos' => count($d['equipos'] ?? []), 'original' => $d['original'] ?? ''];
    }
    usort($out, fn($a, $b) => $b['actualizado'] <=> $a['actualizado']);
    return $out;
}

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
    $f[] = ['Computadora ' . ($tipo !== '' ? $tipo . ' ' : '') . ($q['hostname'] !== '' ? '(' . strtoupper($q['hostname']) . ')' : ''), inv_marca_de_modelo($q['modelo']), $q['modelo'], $q['serie'], ''];
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

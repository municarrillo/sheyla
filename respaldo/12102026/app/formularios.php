<?php
/**
 * Actas TIC - formularios creados con el constructor.
 * Cada formulario se guarda como JSON en /var/lib/actas/formularios/<id>.json
 * (solo la DEFINICIÓN; lo que se llena se guarda en el navegador de cada PC).
 */
declare(strict_types=1);

define('ACTAS_FORMS_DIR', getenv('ACTAS_FORMS_DIR') ?: '/var/lib/actas/formularios');

const FORM_TIPOS = ['texto', 'parrafo', 'numero', 'fecha', 'hora', 'correo', 'lista', 'unica', 'multiple',
                    'checklist', 'tabla', 'firma', 'info', 'aceptar'];
const FORM_FORMATOS = ['', 'cedula', 'telefono', 'numeros', 'placa'];
const FORM_AUTO = ['', 'fecha', 'hora', 'usuario', 'nombre'];
const FORM_OPS = ['igual', 'distinto', 'contiene', 'marcado', 'no_marcado', 'lleno', 'vacio', 'mayor', 'menor'];

function form_id_valido(string $id): bool { return (bool)preg_match('/^[a-z0-9][a-z0-9-]{1,47}$/', $id); }
function form_archivo(string $id): string { return ACTAS_FORMS_DIR . '/' . $id . '.json'; }

/** Texto limpio: sin caracteres de control (salvo saltos de línea si $multi) y con largo máximo. */
function form_txt($v, int $max, bool $multi = false): string {
    $s = is_scalar($v) ? (string)$v : '';
    $s = (string)preg_replace($multi ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', '', $s);
    $s = trim($s);
    if (preg_match_all('/./su', $s) > $max) $s = (string)preg_replace('/^(.{' . $max . '}).*$/su', '$1', $s);
    return $s;
}
function form_lista($v, int $maxItems, int $maxLen): array {
    $out = [];
    foreach (is_array($v) ? $v : [] as $x) {
        $t = form_txt($x, $maxLen);
        if ($t !== '' && count($out) < $maxItems) $out[] = $t;
    }
    return $out;
}

/** Crea un id legible a partir del título (ej. "Revisión de UPS" → "revision-de-ups"). */
function form_slug(string $titulo): string {
    $s = strtr(mb_strtolower_seguro($titulo), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = substr($s, 0, 40) ?: 'formulario';
    if (!preg_match('/^[a-z0-9]/', $s)) $s = 'f-' . $s;
    $base = rtrim($s, '-'); $id = $base; $n = 2;
    while (is_file(form_archivo($id))) $id = $base . '-' . $n++;
    return $id;
}
function mb_strtolower_seguro(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtr(strtolower($s), ['Á'=>'á','É'=>'é','Í'=>'í','Ó'=>'ó','Ú'=>'ú','Ü'=>'ü','Ñ'=>'ñ']);
}

/**
 * Valida y normaliza la definición que envía el constructor.
 * Devuelve [definición limpia, ''] o [null, 'motivo del error'].
 */
function form_normalizar(array $f): array {
    $titulo = form_txt($f['titulo'] ?? '', 120);
    if ($titulo === '') return [null, 'El formulario necesita un título.'];
    $out = [
        'titulo'      => $titulo,
        'subtitulo'   => form_txt($f['subtitulo'] ?? '', 120),
        'descripcion' => form_txt($f['descripcion'] ?? '', 400),
        'publicado'   => !empty($f['publicado']),
        'numeracion'  => !empty($f['numeracion']),
        'secciones'   => [],
    ];
    $ids = [];
    $secs = is_array($f['secciones'] ?? null) ? $f['secciones'] : [];
    if (!$secs) return [null, 'Agregue al menos una sección.'];
    if (count($secs) > 30) return [null, 'Máximo 30 secciones.'];
    $total = 0;
    foreach ($secs as $si => $s) {
        if (!is_array($s)) continue;
        $sec = ['id' => 's' . ($si + 1), 'titulo' => form_txt($s['titulo'] ?? '', 120) ?: 'Sección ' . ($si + 1), 'campos' => []];
        $sid = (string)($s['id'] ?? '');
        if (preg_match('/^s[a-z0-9]{1,20}$/', $sid) && !isset($ids[$sid])) $sec['id'] = $sid;
        $ids[$sec['id']] = 'seccion';
        $sec['cond'] = $s['cond'] ?? null;    // se valida al final (necesita conocer todos los campos)
        foreach (is_array($s['campos'] ?? null) ? $s['campos'] : [] as $c) {
            if (!is_array($c) || ++$total > 200) continue;
            $tipo = (string)($c['tipo'] ?? '');
            if (!in_array($tipo, FORM_TIPOS, true)) return [null, "Tipo de campo no válido: «{$tipo}»."];
            $id = (string)($c['id'] ?? '');
            if (!preg_match('/^c[a-z0-9]{1,20}$/', $id) || isset($ids[$id])) $id = 'c' . bin2hex(random_bytes(4));
            $ids[$id] = $tipo;
            $campo = [
                'id' => $id, 'tipo' => $tipo,
                'etiqueta'  => form_txt($c['etiqueta'] ?? '', 200),
                'requerido' => !empty($c['requerido']) && !in_array($tipo, ['info'], true),
                'ayuda'     => form_txt($c['ayuda'] ?? '', 200),
                'ancho'     => ($c['ancho'] ?? '') === 'medio' && in_array($tipo, ['texto','numero','fecha','hora','correo','lista','unica','firma'], true) ? 'medio' : 'completo',
                'cond'      => $c['cond'] ?? null,
            ];
            if ($campo['etiqueta'] === '' && $tipo !== 'info') return [null, 'Hay un campo sin etiqueta en «' . $sec['titulo'] . '».'];
            if (in_array($tipo, ['texto', 'numero', 'fecha', 'hora', 'parrafo'], true)) {
                $a = (string)($c['auto'] ?? '');
                $validos = ['texto' => ['', 'usuario', 'nombre'], 'parrafo' => [''], 'numero' => [''], 'fecha' => ['', 'fecha'], 'hora' => ['', 'hora']][$tipo];
                $campo['auto'] = in_array($a, $validos, true) ? $a : '';
            }
            if ($tipo === 'texto') {
                $fm = (string)($c['formato'] ?? '');
                $campo['formato'] = in_array($fm, FORM_FORMATOS, true) ? $fm : '';
            }
            if ($tipo === 'numero') {
                foreach (['min', 'max'] as $k) {
                    $campo[$k] = isset($c[$k]) && $c[$k] !== '' && is_numeric($c[$k]) ? (float)$c[$k] : null;
                }
            }
            if (in_array($tipo, ['lista', 'unica', 'multiple', 'checklist'], true)) {
                $campo['opciones'] = form_lista($c['opciones'] ?? [], 100, 300);
                if (!$campo['opciones'] && $tipo !== 'checklist') return [null, "«{$campo['etiqueta']}» necesita al menos una opción."];
            }
            if ($tipo === 'checklist') $campo['editable'] = !empty($c['editable']);
            if ($tipo === 'tabla') {
                $campo['columnas'] = form_lista($c['columnas'] ?? [], 12, 60);
                if (!$campo['columnas']) return [null, "La tabla «{$campo['etiqueta']}» necesita al menos una columna."];
                $campo['filas'] = max(1, min(50, (int)($c['filas'] ?? 1)));
                $campo['max_filas'] = max($campo['filas'], min(200, (int)($c['max_filas'] ?? 20)));
                $campo['numerada'] = !empty($c['numerada']);
            }
            if ($tipo === 'info') {
                $campo['texto'] = form_txt($c['texto'] ?? '', 5000, true);
                $campo['requerido'] = false;
            }
            if ($tipo === 'firma') { $campo['con_nombre'] = !empty($c['con_nombre']); $campo['con_cedula'] = !empty($c['con_cedula']); $campo['con_fecha'] = !empty($c['con_fecha']); }
            $sec['campos'][] = $campo;
        }
        $out['secciones'][] = $sec;
    }
    if ($total === 0) return [null, 'Agregue al menos un campo.'];
    if ($total > 200) return [null, 'Máximo 200 campos por formulario.'];

    // Condiciones: solo pueden depender de campos que existan (y no de sí mismos)
    $limpiar = function ($cond, string $propio) use ($ids) {
        if (!is_array($cond) || empty($cond['campo'])) return null;
        $campo = (string)$cond['campo'];
        $op = (string)($cond['op'] ?? '');
        if ($campo === $propio || !isset($ids[$campo]) || $ids[$campo] === 'seccion' || !in_array($op, FORM_OPS, true)) return null;
        return ['campo' => $campo, 'op' => $op, 'valor' => form_txt($cond['valor'] ?? '', 300)];
    };
    foreach ($out['secciones'] as &$sec) {
        $sec['cond'] = $limpiar($sec['cond'], $sec['id']);
        foreach ($sec['campos'] as &$c) $c['cond'] = $limpiar($c['cond'], $c['id']);
        unset($c);
    }
    unset($sec);
    return [$out, ''];
}

function form_cargar(string $id): ?array {
    if (!form_id_valido($id)) return null;
    $d = json_decode((string)@file_get_contents(form_archivo($id)), true);
    return is_array($d) ? $d : null;
}

/** Lista de formularios (solo publicados si $soloPublicados), ordenados por título. */
function form_listar(bool $soloPublicados = false): array {
    $out = [];
    foreach (glob(ACTAS_FORMS_DIR . '/*.json') ?: [] as $f) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || empty($d['id'])) continue;
        if ($soloPublicados && empty($d['publicado'])) continue;
        $out[] = $d;
    }
    usort($out, fn($a, $b) => strcasecmp((string)$a['titulo'], (string)$b['titulo']));
    return $out;
}

function form_guardar(array $def, string $usuario, ?string $id = null): string {
    if (!is_dir(ACTAS_FORMS_DIR)) @mkdir(ACTAS_FORMS_DIR, 0770, true);
    $prev = $id !== null ? form_cargar($id) : null;
    $id = $prev ? $id : form_slug($def['titulo']);
    $def['id'] = $id;
    $def['creado'] = $prev['creado'] ?? time();
    $def['autor'] = $prev['autor'] ?? $usuario;
    $def['modificado'] = time();
    $def['modificado_por'] = $usuario;
    $def['version'] = (int)($prev['version'] ?? 0) + 1;
    $tmp = ACTAS_FORMS_DIR . '/.' . $id . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($def, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) return '';
    @chmod($tmp, 0660);
    if (!@rename($tmp, form_archivo($id))) { @unlink($tmp); return ''; }
    return $id;
}

/* ---------------- Papelera ----------------
 * Eliminar un formulario lo mueve a /var/lib/actas/formularios/papelera/<id>__<fecha>.json */
function form_papelera_dir(): string { return ACTAS_FORMS_DIR . '/papelera'; }
function form_papelera_valida(string $clave): bool { return (bool)preg_match('/^[a-z0-9][a-z0-9-]{1,47}__\d{10}$/', $clave); }
function form_papelera_archivo(string $clave): string { return form_papelera_dir() . '/' . $clave . '.json'; }

/** Mueve el formulario a la papelera. */
function form_eliminar(string $id, string $por = ''): bool {
    if (!form_id_valido($id) || !is_file(form_archivo($id))) return false;
    $d = form_cargar($id);
    if ($d === null) return false;
    if (!is_dir(form_papelera_dir())) @mkdir(form_papelera_dir(), 0770, true);
    $t = time();
    while (is_file(form_papelera_archivo($id . '__' . $t))) $t++;
    $d['_papelera'] = ['at' => time(), 'por' => $por, 'id_original' => $id];
    $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || @file_put_contents(form_papelera_archivo($id . '__' . $t), $json . PHP_EOL, LOCK_EX) === false) return false;
    return @unlink(form_archivo($id));
}
/** Formularios creados que están en la papelera (más recientes primero). */
function form_papelera_listar(): array {
    $out = [];
    foreach (glob(form_papelera_dir() . '/*.json') ?: [] as $f) {
        $clave = basename($f, '.json');
        $d = json_decode((string)@file_get_contents($f), true);
        if (!form_papelera_valida($clave) || !is_array($d)) continue;
        $d['_clave'] = $clave;
        $out[] = $d;
    }
    usort($out, fn($a, $b) => (int)($b['_papelera']['at'] ?? 0) <=> (int)($a['_papelera']['at'] ?? 0));
    return $out;
}
function form_papelera_cargar(string $clave): ?array {
    if (!form_papelera_valida($clave)) return null;
    $d = json_decode((string)@file_get_contents(form_papelera_archivo($clave)), true);
    return is_array($d) ? $d : null;
}
/** Restaura; si ya existe otro formulario con el mismo id, lo restaura con un id nuevo. Devuelve el id o ''. */
function form_papelera_restaurar(string $clave, string $por): string {
    $d = form_papelera_cargar($clave);
    if ($d === null) return '';
    $id = (string)($d['_papelera']['id_original'] ?? $d['id'] ?? '');
    unset($d['_papelera'], $d['_clave']);
    if (form_id_valido($id) && !is_file(form_archivo($id))) {
        $d['id'] = $id;
        $d['modificado'] = time();
        $d['modificado_por'] = $por;
        $json = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents(form_archivo($id), $json . PHP_EOL, LOCK_EX) === false) return '';
        @chmod(form_archivo($id), 0660);
        $res = $id;
    } else {
        $d['titulo'] = form_txt($d['titulo'] . ' (restaurado)', 120);
        $res = form_guardar($d, $por, null);
    }
    if ($res !== '') @unlink(form_papelera_archivo($clave));
    return $res;
}
function form_papelera_borrar(string $clave): bool {
    return form_papelera_valida($clave) && @unlink(form_papelera_archivo($clave));
}

/** JSON seguro para incrustar dentro de <script>. */
function form_json_script($v): string {
    return (string)json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/* ---------------- Formularios del sistema (páginas fijas) ----------------
 * No se borran del disco: se retiran del menú y quedan bloqueados, y se pueden restaurar. */
const FORM_FIJOS = [
    'acta_entrega'    => ['titulo' => 'Acta de Entrega',       'pagina' => 'acta_entrega_equipos_web.php'],
    'acta_devolucion' => ['titulo' => 'Acta de Devolución',    'pagina' => 'acta_devolucion_equipos_web.php'],
    'bitacora'        => ['titulo' => 'Bitácora de Simulacro', 'pagina' => 'bitacora_mantenimiento.php'],
];
function form_sistema_archivo(): string { return ACTAS_FORMS_DIR . '/.sistema.json'; }
function form_fijos_eliminados(): array {
    $d = json_decode((string)@file_get_contents(form_sistema_archivo()), true);
    return array_values(array_intersect(is_array($d['eliminados'] ?? null) ? $d['eliminados'] : [], array_keys(FORM_FIJOS)));
}
function form_fijo_activo(string $clave): bool { return !in_array($clave, form_fijos_eliminados(), true); }
function form_fijo_set(string $clave, bool $eliminado, string $por = ''): bool {
    if (!isset(FORM_FIJOS[$clave])) return false;
    if (!is_dir(ACTAS_FORMS_DIR)) @mkdir(ACTAS_FORMS_DIR, 0770, true);
    $l = array_values(array_diff(form_fijos_eliminados(), [$clave]));
    $info = form_fijos_info();
    unset($info[$clave]);
    if ($eliminado) { $l[] = $clave; $info[$clave] = ['at' => time(), 'por' => $por]; }
    return @file_put_contents(form_sistema_archivo(), json_encode(['eliminados' => $l, 'info' => $info]) . PHP_EOL, LOCK_EX) !== false;
}
/** Fecha y usuario de cada formulario del sistema eliminado. */
function form_fijos_info(): array {
    $d = json_decode((string)@file_get_contents(form_sistema_archivo()), true);
    return is_array($d['info'] ?? null) ? $d['info'] : [];
}
/** Para las páginas fijas: si el formulario fue eliminado, responde 404. */
function form_fijo_requerir(string $clave): void {
    if (form_fijo_activo($clave)) return;
    http_response_code(404);
    echo '<!DOCTYPE html><meta charset="utf-8"><title>No disponible</title><body style="font-family:sans-serif;padding:40px"><h2>Formulario no disponible</h2><p>Este formulario fue eliminado del sistema.</p><p><a href="index.php">Volver al menú</a></p>';
    exit;
}

<?php
/**
 * Préstamo de activos: catálogo de activos prestables y reservas, guardados en el servidor.
 *   /var/lib/actas/prestamos/activos.json   catálogo (lo administra TI)
 *   /var/lib/actas/prestamos/reservas.json  reservas, entregas y devoluciones
 * Toda escritura se hace con un bloqueo de archivo, así dos personas no pueden reservar
 * el mismo activo para las mismas horas al mismo tiempo.
 */
declare(strict_types=1);

define('PRES_DIR', getenv('ACTAS_PRES_DIR') ?: '/var/lib/actas/prestamos');

const PRES_TIPOS = ['Proyector', 'Computadora portátil', 'Computadora de escritorio', 'Tablet', 'Pantalla o monitor',
                    'Parlante o micrófono', 'Cámara', 'Herramienta', 'Accesorio', 'Otro'];
const PRES_ESTADOS = ['reservada' => 'Reservada', 'prestado' => 'Prestado', 'devuelto' => 'Devuelto', 'cancelada' => 'Cancelada'];
const PRES_MAX_DIAS = 30;
const PRES_TERMINOS = "1. Los activos se prestan exclusivamente para uso institucional y para la actividad indicada en esta acta.\n"
    . "2. El solicitante es responsable del cuidado, custodia y buen uso de los activos desde que los recibe hasta su devolución al Departamento de Tecnologías de Información.\n"
    . "3. No se permite prestar los activos a terceros, instalar software, modificar su configuración ni retirar etiquetas de inventario.\n"
    . "4. Los activos deben devolverse en la fecha y hora indicadas, completos y en el mismo estado en que se entregaron. Si necesita más tiempo, debe solicitarlo a TI antes del vencimiento.\n"
    . "5. Cualquier daño, falla, pérdida o robo debe reportarse de inmediato a TI. En caso de robo o pérdida, el solicitante deberá presentar la denuncia correspondiente.\n"
    . "6. El daño o la pérdida por negligencia o mal uso se tramitará conforme a la normativa interna de la Municipalidad de Carrillo.";
const PRES_VERIF_ENTREGA = [
    'El equipo enciende y funciona correctamente',
    'Se revisó el estado físico (golpes, rayones, pantalla o lente)',
    'Se entregan cables de poder y cargador',
    'Se entregan los accesorios indicados',
    'Se explicó al solicitante el uso básico y los cuidados del activo',
];
const PRES_VERIF_DEVOL = [
    'El equipo enciende y funciona correctamente',
    'El estado físico coincide con el de la entrega',
    'Se devolvieron cables, cargador y accesorios',
    'Se borraron archivos o sesiones del solicitante (si aplica)',
];

function pres_archivo(string $n): string { return PRES_DIR . '/' . $n . '.json'; }

function pres_leer(string $n): array {
    $d = json_decode((string)@file_get_contents(pres_archivo($n)), true);
    return is_array($d) ? $d : [];
}

/** Ejecuta $fn(&$activos, &$reservas) con bloqueo exclusivo y guarda si devuelve true. */
function pres_transaccion(callable $fn) {
    if (!is_dir(PRES_DIR)) @mkdir(PRES_DIR, 0770, true);
    $lk = @fopen(PRES_DIR . '/.bloqueo', 'c');
    if (!$lk || !flock($lk, LOCK_EX)) throw new RuntimeException('No se pudo bloquear el registro de préstamos.');
    try {
        $act = pres_leer('activos'); $res = pres_leer('reservas');
        $r = $fn($act, $res);
        if ($r !== false) {
            foreach (['activos' => $act, 'reservas' => $res] as $n => $d) {
                $tmp = PRES_DIR . "/.$n." . bin2hex(random_bytes(5)) . '.tmp';
                if (@file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !@rename($tmp, pres_archivo($n))) {
                    @unlink($tmp);
                    throw new RuntimeException('No se pudo guardar. Revise los permisos de ' . PRES_DIR . '.');
                }
            }
        }
        return $r;
    } finally { flock($lk, LOCK_UN); fclose($lk); }
}

/** Recorta a $max caracteres sin depender de mbstring. */
function pres_cortar(string $s, int $max): string { return preg_match('/^.{0,' . $max . '}/su', $s, $m) ? $m[0] : ''; }
function pres_txt($v, int $max = 200): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_string($v) ? $v : '') ?? '');
    return pres_cortar($s, $max);
}
function pres_parrafo($v, int $max = 1500): string {
    $s = trim(str_replace("\r", '', preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', is_string($v) ? $v : '') ?? ''));
    return pres_cortar($s, $max);
}
/** 'YYYY-MM-DDTHH:MM' → timestamp, o null si no es válido. */
function pres_ts(string $v): ?int {
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $v);
    return $d && $d->format('Y-m-d\TH:i') === $v ? $d->getTimestamp() : null;
}
function pres_fmt(int $ts, bool $hora = true): string { return date($hora ? 'd/m/Y h:i a' : 'd/m/Y', $ts); }

/**
 * Intervalo en que una reserva ocupa sus activos, o null si ya no ocupa nada.
 * - reservada: del inicio al fin pedidos (si el fin ya pasó sin retirarse, deja de ocupar: «vencida»)
 * - prestado:  desde la entrega hasta que se devuelva (si se atrasa, sigue ocupando)
 */
function pres_ocupa(array $r, ?int $ahora = null): ?array {
    $ahora ??= time();
    if ($r['estado'] === 'reservada') return $r['fin'] > $ahora ? [$r['inicio'], $r['fin']] : null;
    if ($r['estado'] === 'prestado') return [min($r['inicio'], (int)($r['entrega']['at'] ?? $r['inicio'])), max($r['fin'], $ahora + 60)];
    return null;
}
function pres_vencida(array $r): bool { return $r['estado'] === 'reservada' && $r['fin'] <= time(); }
function pres_atrasado(array $r): bool { return $r['estado'] === 'prestado' && $r['fin'] < time(); }

/** Reservas que chocan con [$ini, $fin) para el activo $aid (excluye $ignorar). */
function pres_choques(array $reservas, string $aid, int $ini, int $fin, string $ignorar = ''): array {
    $out = [];
    foreach ($reservas as $r) {
        if ($r['id'] === $ignorar || !in_array($aid, $r['activos'], true)) continue;
        $o = pres_ocupa($r);
        if ($o && $ini < $o[1] && $o[0] < $fin) $out[] = $r;
    }
    return $out;
}

/** Ocupación pública (sin datos personales) para calcular disponibilidad en el navegador. */
function pres_ocupacion_publica(array $reservas): array {
    $out = [];
    foreach ($reservas as $r) {
        $o = pres_ocupa($r);
        if ($o) foreach ($r['activos'] as $a) $out[] = ['a' => $a, 'i' => $o[0], 'f' => $o[1], 'e' => $r['estado']];
    }
    return $out;
}

function pres_siguiente_id(array $reservas, int $anio): array {
    $n = 0;
    foreach ($reservas as $r) if ((int)$r['yr'] === $anio) $n = max($n, (int)$r['cons']);
    $cons = str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
    return [$cons, "PA-$cons-$anio"];
}

/** Firma dibujada: imagen (JPEG con fondo blanco; PNG en registros anteriores) en data URL, de tamaño razonable. */
function pres_firma(array $p, string $pref, string $quien): array {
    $img = (string)($p[$pref . '_img'] ?? '');
    $f = [
        'img' => $img,
        'nombre' => pres_txt($p[$pref . '_nombre'] ?? '', 80),
        'cedula' => pres_txt($p[$pref . '_cedula'] ?? '', 25),
        'fecha'  => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p[$pref . '_fecha'] ?? '')) ? (string)$p[$pref . '_fecha'] : '',
    ];
    if (!preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $img) || strlen($img) > 400000 || strlen($img) < 200) throw new InvalidArgumentException("Falta la firma de $quien.");
    if ($f['nombre'] === '' || $f['cedula'] === '' || $f['fecha'] === '') throw new InvalidArgumentException("Complete nombre, cédula y fecha de $quien.");
    return $f;
}

function pres_verificacion(array $p, string $campo, array $lista): array {
    $marcados = is_array($p[$campo] ?? null) ? array_map('intval', $p[$campo]) : [];
    $out = [];
    foreach ($lista as $i => $t) $out[] = ['t' => $t, 'ok' => in_array($i, $marcados, true)];
    return $out;
}

function pres_nombre_activo(array $a): string {
    return trim($a['tipo'] . ' ' . trim(($a['marca'] ?? '') . ' ' . ($a['modelo'] ?? '')) . ($a['codigo'] !== '' ? ' (N.° ' . $a['codigo'] . ')' : ''));
}

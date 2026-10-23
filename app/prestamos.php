<?php
/**
 * Préstamo de activos: catálogo de activos prestables y reservas, guardados en MySQL.
 *   tabla «pres_activos»   catálogo (lo administra TI)
 *   tabla «pres_reservas»  reservas, entregas y devoluciones
 *   tabla «pres_pdf»       comprobantes y actas en PDF generados en cada evento
 * Toda escritura se hace con un bloqueo y una transacción, así dos personas no pueden reservar
 * el mismo activo para las mismas horas al mismo tiempo.
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php';

define('PRES_DIR', getenv('ACTAS_PRES_DIR') ?: '/var/lib/actas/prestamos');   // solo para migrar la versión anterior

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

/** 'activos' o 'reservas' → [id => datos] */
function pres_leer(string $n): array {
    $t = $n === 'activos' ? 'pres_activos' : 'pres_reservas';
    $out = [];
    foreach (db_todas("SELECT id, datos FROM $t ORDER BY id") as $f) { $d = db_dejson($f['datos']); if (is_array($d)) $out[(string)$f['id']] = $d; }
    return $out;
}
function pres_escribir(string $n, array $todos): void {
    if ($n === 'activos') {
        $ins = actas_db()->prepare('INSERT INTO pres_activos (id, codigo, tipo, descripcion, activo, datos) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE codigo = VALUES(codigo), tipo = VALUES(tipo), descripcion = VALUES(descripcion), activo = VALUES(activo), datos = VALUES(datos)');
        foreach ($todos as $id => $a) $ins->execute([(string)$id, db_corta($a['codigo'] ?? '', 40), db_corta($a['tipo'] ?? '', 60), db_corta($a['descripcion'] ?? '', 160),
                                                    ($a['activo'] ?? true) === false ? 0 : 1, db_json($a)]);
        $t = 'pres_activos';
    } else {
        $ins = actas_db()->prepare('INSERT INTO pres_reservas (id, estado, nombre, departamento, inicio, fin, creado, datos) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE estado = VALUES(estado), nombre = VALUES(nombre), departamento = VALUES(departamento), inicio = VALUES(inicio),
            fin = VALUES(fin), creado = VALUES(creado), datos = VALUES(datos)');
        foreach ($todos as $id => $r) $ins->execute([(string)$id, db_corta($r['estado'] ?? '', 20), db_corta($r['nombre'] ?? '', 120), db_corta($r['departamento'] ?? '', 120),
                                                    (int)($r['inicio'] ?? 0), (int)($r['fin'] ?? 0), (int)($r['creado'] ?? 0), db_json($r)]);
        $t = 'pres_reservas';
    }
    $ids = array_map('strval', array_keys($todos));
    db_q("DELETE FROM $t" . ($ids ? ' WHERE id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')' : ''), $ids);
}

/** Ejecuta $fn(&$activos, &$reservas) con bloqueo exclusivo y guarda si devuelve algo distinto de false. */
function pres_transaccion(callable $fn) {
    $lk = db_bloquear('prestamos');
    try {
        $act = pres_leer('activos'); $res = pres_leer('reservas');
        $antesA = $act; $antesR = $res;
        $r = $fn($act, $res);
        if ($r !== false) {
            try {
                db_tx(function () use ($act, $res, $antesA, $antesR) {
                    if ($act !== $antesA) pres_escribir('activos', $act);
                    if ($res !== $antesR) pres_escribir('reservas', $res);
                });
            } catch (PDOException $e) { error_log('[actas-tic] préstamos: ' . $e->getMessage()); throw new RuntimeException('No se pudo guardar en la base de datos.'); }
        }
        return $r;
    } finally { db_desbloquear($lk); }
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

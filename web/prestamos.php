<?php
declare(strict_types=1);
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/plantilla.php';
require __DIR__ . '/../actas-app/prestamos.php';
require __DIR__ . '/../actas-app/prestamos_pdf.php';
require __DIR__ . '/../actas-app/tema.php';

$yo   = actas_requerir_login();
actas_requerir_acceso($yo, 'prestamos');
$reg  = actas_registro($yo);
$esTI = actas_es_ti($reg);
$csrf = actas_csrf();

function ir(string $q, string $tipo = '', string $msg = ''): void {
    if ($msg !== '') $_SESSION['flash_pres'] = [$tipo, $msg];
    header('Location: prestamos.php' . $q);
    exit;
}
function jsn($v): string { return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); }
function puede_ver(array $r): bool { global $esTI, $yo; return $esTI || $r['solicitante'] === $yo; }

/** Correo al solicitante con el PDF adjunto. Devuelve el texto para el aviso en pantalla. */
function notificar(array $r, string $evento, string $pdf): string {
    $para = actas_correo_valido((string)$r['correo']) ? (string)$r['correo'] : (string)(actas_registro($r['solicitante'])['correo'] ?? '');
    $act = activos_txt($r, pres_leer('activos'));
    $lin = ["Hola, {$r['nombre']}:", ''];
    switch ($evento) {
        case 'reserva':
            $asunto = "Reserva de activos {$r['id']} registrada";
            $lin[] = "Su reserva quedó registrada el " . pres_fmt((int)$r['creado']) . '.';
            $lin[] = '';
            $lin[] = "Activos: $act";
            $lin[] = 'Retiro programado: ' . pres_fmt((int)$r['inicio']);
            $lin[] = 'Devolución estimada: ' . pres_fmt((int)$r['fin']);
            $lin[] = '';
            $lin[] = 'Preséntese en el Departamento de Tecnologías de Información en la fecha de retiro. Ahí se le entregarán los activos y ambas partes firmarán el acta de préstamo.';
            $lin[] = 'Si ya no los necesita, cancele la reserva en el sistema para que queden disponibles.';
            break;
        case 'entrega':
            $asunto = "Entrega de activos {$r['id']}";
            $lin[] = 'Se le entregaron los activos el ' . pres_fmt((int)$r['entrega']['at']) . '.';
            $lin[] = '';
            $lin[] = "Activos: $act";
            $lin[] = 'Devolución acordada: ' . pres_fmt((int)$r['fin']);
            $lin[] = '';
            $lin[] = 'Adjuntamos el acta de préstamo firmada. Recuerde que usted es responsable del cuidado de los activos hasta su devolución.';
            break;
        case 'devolucion':
            $asunto = "Devolución de activos {$r['id']} registrada";
            $lin[] = 'Se registró la devolución de los activos el ' . pres_fmt((int)$r['devolucion']['at']) . '.';
            $lin[] = "Activos: $act";
            $lin[] = 'Condición: ' . ($r['devolucion']['condicion'] === 'bien' ? 'completo y en buen estado.' : 'con daños o faltantes: ' . $r['devolucion']['danos']);
            $lin[] = '';
            $lin[] = 'Adjuntamos el acta con la entrega y la devolución.';
            break;
        default: // cambio de fecha
            $asunto = "Nueva fecha de devolución {$r['id']}";
            $c = end($r['cambios_fin']);
            $lin[] = 'La fecha de devolución de sus activos cambió del ' . pres_fmt((int)$c['de']) . ' al ' . pres_fmt((int)$c['a']) . '.';
            if ($c['motivo'] !== '') $lin[] = 'Motivo: ' . $c['motivo'];
            $lin[] = "Activos: $act";
    }
    $lin[] = ''; $lin[] = 'Departamento de Tecnologías de Información'; $lin[] = 'Municipalidad de Carrillo';
    $lin[] = ''; $lin[] = 'Este es un mensaje automático del sistema Actas TIC.';
    if ($para === '') return 'No se envió correo: el solicitante no tiene un correo institucional registrado.';
    $ok = actas_enviar_correo($para, $asunto, implode("\n", $lin), $pdf !== '' ? [[$r['id'] . '-' . $evento . '.pdf', 'application/pdf', $pdf]] : []);
    actas_log("prestamos: correo $evento {$r['id']} a $para " . ($ok ? 'enviado' : 'FALLÓ'));
    return $ok ? "Se envió una copia en PDF a $para." : "No se pudo enviar el correo a $para (revise la configuración de correo del servidor). Puede descargar el PDF aquí.";
}
function fin_o_error(string $v, string $q): int {
    $t = pres_ts($v);
    if ($t === null) ir($q, 'err', 'Indique la fecha y hora de devolución.');
    return $t;
}
function cambiar_fin(array &$res, string $id, int $nuevo, string $yo, string $motivo, string $q): void {
    $r = $res[$id];
    if ($nuevo === (int)$r['fin']) return;
    $desde = $r['estado'] === 'prestado' ? (int)$r['entrega']['at'] : (int)$r['inicio'];
    if ($nuevo <= max($desde, time())) ir($q, 'err', 'La nueva fecha de devolución debe ser posterior a hoy' . ($r['estado'] === 'reservada' ? ' y al retiro.' : '.'));
    if ($nuevo - $desde > PRES_MAX_DIAS * 86400) ir($q, 'err', 'El préstamo no puede durar más de ' . PRES_MAX_DIAS . ' días.');
    foreach ($r['activos'] as $aid)
        if ($ch = pres_choques($res, $aid, min($desde, time()), $nuevo, $id))
            ir($q, 'err', 'No se puede extender hasta esa fecha: uno de los activos está reservado por otra persona desde el ' . pres_fmt($ch[0]['inicio']) . '. Elija una fecha anterior.');
    $res[$id]['fin_solicitada'] ??= $r['fin'];
    $res[$id]['cambios_fin'][] = ['at' => time(), 'por' => $yo, 'de' => (int)$r['fin'], 'a' => $nuevo, 'motivo' => $motivo];
    $res[$id]['fin'] = $nuevo;
}

/* ======================= PDF (ver o descargar) ======================= */
if (isset($_GET['pdf'])) {
    $todas = pres_leer('reservas'); $r = $todas[(string)$_GET['pdf']] ?? null;
    if (!$r || !puede_ver($r)) { http_response_code(404); exit('No encontrado'); }
    $doc = isset(PRES_DOCS[$_GET['doc'] ?? '']) ? (string)$_GET['doc'] : pres_doc_actual($r);
    $bin = pres_pdf_guardado((string)$r['id'], $doc) ?? pres_pdf($r, pres_leer('activos'), $doc);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (isset($_GET['descargar']) ? 'attachment' : 'inline') . '; filename="' . $r['id'] . '-' . $doc . '.pdf"');
    header('Content-Length: ' . strlen($bin));
    echo $bin; exit;
}

/* ======================= Acciones ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!actas_csrf_ok()) ir('', 'err', 'La sesión del formulario venció. Intente de nuevo.');
    $acc = (string)($_POST['accion'] ?? '');
    $P = $_POST;
    try {
        switch ($acc) {
        case 'reservar':
            $ini = pres_ts((string)($P['inicio'] ?? '')); $fin = pres_ts((string)($P['fin'] ?? ''));
            $d = [
                'nombre' => pres_txt($P['nombre'] ?? '', 80), 'cedula' => pres_txt($P['cedula'] ?? '', 25),
                'departamento' => pres_txt($P['departamento'] ?? '', 80), 'cargo' => pres_txt($P['cargo'] ?? '', 80),
                'telefono' => pres_txt($P['telefono'] ?? '', 30), 'correo' => strtolower(pres_txt($P['correo'] ?? '', 120)),
                'motivo' => pres_parrafo($P['motivo'] ?? '', 800), 'uso' => ($P['uso'] ?? '') === 'fuera' ? 'fuera' : 'dentro',
                'lugar' => pres_txt($P['lugar'] ?? '', 150), 'autoriza' => pres_txt($P['autoriza'] ?? '', 80),
            ];
            $sel = array_values(array_unique(array_map('strval', is_array($P['activos'] ?? null) ? $P['activos'] : [])));
            $volver = '?v=nueva&' . http_build_query(['inicio' => $P['inicio'] ?? '', 'fin' => $P['fin'] ?? '']);
            $_SESSION['borrador_pres'] = $d + ['activos' => $sel];
            foreach (['nombre' => 'nombre', 'cedula' => 'cédula', 'departamento' => 'departamento', 'cargo' => 'cargo', 'correo' => 'correo', 'motivo' => 'motivo', 'lugar' => 'lugar de uso'] as $k => $n)
                if ($d[$k] === '') ir($volver, 'err', "Falta el dato: $n.");
            if (!filter_var($d['correo'], FILTER_VALIDATE_EMAIL)) ir($volver, 'err', 'El correo no es válido.');
            if ($d['uso'] === 'fuera' && $d['autoriza'] === '') ir($volver, 'err', 'Indique la jefatura que autoriza la salida del activo.');
            if ($ini === null || $fin === null) ir($volver, 'err', 'Indique la fecha y hora de retiro y de devolución.');
            if ($fin <= $ini) ir($volver, 'err', 'La devolución debe ser posterior al retiro.');
            if ($ini < time() - 900) ir($volver, 'err', 'La fecha de retiro no puede estar en el pasado.');
            if ($fin - $ini > PRES_MAX_DIAS * 86400) ir($volver, 'err', 'El préstamo no puede durar más de ' . PRES_MAX_DIAS . ' días.');
            if (!$sel) ir($volver, 'err', 'Seleccione al menos un activo.');
            if (empty($P['acepto'])) ir($volver, 'err', 'Debe aceptar las condiciones del préstamo.');
            $id = pres_transaccion(function (&$act, &$res) use ($sel, $ini, $fin, $d, $yo, $volver) {
                foreach ($sel as $aid) {
                    if (empty($act[$aid]) || empty($act[$aid]['activo'])) ir($volver, 'err', 'Uno de los activos ya no está disponible para préstamo.');
                    if ($ch = pres_choques($res, $aid, $ini, $fin))
                        ir($volver, 'err', 'No se puede reservar: «' . pres_nombre_activo($act[$aid]) . '» ya está ' . ($ch[0]['estado'] === 'prestado' ? 'prestado' : 'reservado')
                            . ' del ' . pres_fmt($ch[0]['inicio']) . ' al ' . pres_fmt($ch[0]['fin']) . '. Elija otras fechas u otro activo.');
                }
                [$cons, $id] = pres_siguiente_id($res, (int)date('Y', $ini));
                $res[$id] = ['id' => $id, 'cons' => $cons, 'yr' => (int)date('Y', $ini), 'estado' => 'reservada',
                    'solicitante' => $yo, 'inicio' => $ini, 'fin' => $fin, 'activos' => $sel,
                    'fin_solicitada' => $fin, 'creado' => time(), 'creado_por' => $yo] + $d;
                return $id;
            });
            unset($_SESSION['borrador_pres']);
            actas_log("prestamos: $yo reservó $id");
            $r = pres_leer('reservas')[$id];
            $_SESSION['correo_pres'] = notificar($r, 'reserva', pres_pdf_guardar($r, pres_leer('activos'), 'reserva'));
            ir('?v=ver&id=' . urlencode($id) . '&nueva=1');

        case 'cancelar':
            $id = (string)($P['id'] ?? '');
            pres_transaccion(function (&$act, &$res) use ($id, $yo, $esTI, $P) {
                $r = $res[$id] ?? null;
                if (!$r || !puede_ver($r)) ir('', 'err', 'La reserva no existe.');
                if ($r['estado'] !== 'reservada') ir('?v=ver&id=' . urlencode($id), 'err', 'Solo se pueden cancelar reservas que aún no se han retirado.');
                $res[$id]['estado'] = 'cancelada';
                $res[$id]['cancelada'] = ['at' => time(), 'por' => $yo, 'motivo' => pres_txt($P['motivo'] ?? '', 200)];
            });
            actas_log("prestamos: $yo canceló $id");
            ir('?v=ver&id=' . urlencode($id), 'ok', "Reserva $id cancelada. Los activos quedaron disponibles.");

        case 'entregar':
            if (!$esTI) ir('', 'err', 'Solo el personal de TI entrega activos.');
            $id = (string)($P['id'] ?? '');
            $fTI = pres_firma($P, 'fti', 'quien entrega (TI)'); $fSol = pres_firma($P, 'fsol', 'quien recibe (solicitante)');
            $finAcordado = fin_o_error((string)($P['fin_acordado'] ?? ''), '?v=ver&id=' . urlencode($id));
            pres_transaccion(function (&$act, &$res) use ($id, $yo, $P, $fTI, $fSol, $finAcordado) {
                $r = $res[$id] ?? null;
                if (!$r) ir('', 'err', 'La reserva no existe.');
                if ($r['estado'] !== 'reservada') ir('?v=ver&id=' . urlencode($id), 'err', 'Esta reserva ya no está pendiente de entrega.');
                $ahora = time();
                if ($r['fin'] <= $ahora) ir('?v=ver&id=' . urlencode($id), 'err', 'La reserva venció. Cancélela y cree una nueva con las fechas correctas.');
                foreach ($r['activos'] as $aid)
                    if ($ch = pres_choques($res, $aid, min($ahora, $r['inicio']), $r['fin'], $id))
                        ir('?v=ver&id=' . urlencode($id), 'err', '«' . pres_nombre_activo($act[$aid] ?? ['tipo' => 'Activo', 'codigo' => $aid]) . '» todavía está ocupado por ' . $ch[0]['id'] . ($ch[0]['estado'] === 'prestado' ? ' (no se ha devuelto)' : '') . '. No se puede entregar todavía.');
                $det = [];
                foreach ($r['activos'] as $aid) $det[$aid] = pres_txt($P['det'][$aid] ?? '', 200);
                if ($finAcordado <= $ahora) ir('?v=ver&id=' . urlencode($id), 'err', 'La devolución acordada debe ser posterior a este momento.');
                $res[$id]['estado'] = 'prestado'; $res[$id]['entrega'] = ['at' => $ahora];   // para validar el nuevo plazo desde la entrega
                cambiar_fin($res, $id, $finAcordado, $yo, 'Acordada al entregar', '?v=ver&id=' . urlencode($id));
                $res[$id]['estado'] = 'prestado';
                $res[$id]['entrega'] = ['at' => $ahora, 'por' => $yo, 'detalle' => $det,
                    'verif' => pres_verificacion($P, 'verif', PRES_VERIF_ENTREGA), 'obs' => pres_parrafo($P['obs'] ?? '', 800),
                    'firma_ti' => $fTI, 'firma_sol' => $fSol];
            });
            actas_log("prestamos: $yo entregó $id");
            $r = pres_leer('reservas')[$id];
            $_SESSION['correo_pres'] = notificar($r, 'entrega', pres_pdf_guardar($r, pres_leer('activos'), 'entrega'));
            ir('?v=ver&id=' . urlencode($id) . '&listo=entrega');

        case 'devolver':
            if (!$esTI) ir('', 'err', 'Solo el personal de TI recibe devoluciones.');
            $id = (string)($P['id'] ?? '');
            $cond = ($P['condicion'] ?? '') === 'danos' ? 'danos' : (($P['condicion'] ?? '') === 'bien' ? 'bien' : '');
            if ($cond === '') ir('?v=ver&id=' . urlencode($id), 'err', 'Indique la condición en que se devuelven los activos.');
            $danos = pres_parrafo($P['danos'] ?? '', 800);
            if ($cond === 'danos' && $danos === '') ir('?v=ver&id=' . urlencode($id), 'err', 'Describa los daños o faltantes.');
            $fSol = pres_firma($P, 'fsol', 'quien devuelve (solicitante)'); $fTI = pres_firma($P, 'fti', 'quien recibe (TI)');
            $real = fin_o_error((string)($P['fecha_real'] ?? ''), '?v=ver&id=' . urlencode($id));
            if ($real > time() + 300) ir('?v=ver&id=' . urlencode($id), 'err', 'La fecha de devolución no puede estar en el futuro.');
            pres_transaccion(function (&$act, &$res) use ($id, $yo, $P, $cond, $danos, $fTI, $fSol, $real) {
                $r = $res[$id] ?? null;
                if (!$r) ir('', 'err', 'El préstamo no existe.');
                if ($r['estado'] !== 'prestado') ir('?v=ver&id=' . urlencode($id), 'err', 'Este préstamo no está pendiente de devolución.');
                if ($real < intdiv((int)$r['entrega']['at'], 60) * 60) ir('?v=ver&id=' . urlencode($id), 'err', 'La fecha de devolución no puede ser anterior a la entrega (' . pres_fmt((int)$r['entrega']['at']) . ').');
                $res[$id]['estado'] = 'devuelto';
                $res[$id]['devolucion'] = ['at' => max($real, (int)$r['entrega']['at']), 'registrado' => time(), 'por' => $yo, 'condicion' => $cond, 'danos' => $danos,
                    'verif' => pres_verificacion($P, 'verif', PRES_VERIF_DEVOL), 'obs' => pres_parrafo($P['obs'] ?? '', 800),
                    'firma_sol' => $fSol, 'firma_ti' => $fTI];
            });
            actas_log("prestamos: $yo recibió devolución $id");
            $r = pres_leer('reservas')[$id];
            $_SESSION['correo_pres'] = notificar($r, 'devolucion', pres_pdf_guardar($r, pres_leer('activos'), 'devolucion'));
            ir('?v=ver&id=' . urlencode($id) . '&listo=devolucion');

        case 'cambiar_fin':
            $id = (string)($P['id'] ?? ''); $q = '?v=ver&id=' . urlencode($id);
            $nuevo = fin_o_error((string)($P['fin'] ?? ''), $q);
            $motivo = pres_txt($P['motivo'] ?? '', 200);
            pres_transaccion(function (&$act, &$res) use ($id, $yo, $nuevo, $motivo, $q) {
                $r = $res[$id] ?? null;
                if (!$r || !puede_ver($r)) ir('', 'err', 'La reserva no existe.');
                if (!in_array($r['estado'], ['reservada', 'prestado'], true)) ir($q, 'err', 'Solo se puede cambiar la fecha de reservas o préstamos activos.');
                if ($nuevo === (int)$r['fin']) ir($q, 'warn', 'La fecha indicada es la misma que ya estaba registrada.');
                cambiar_fin($res, $id, $nuevo, $yo, $motivo, $q);
            });
            actas_log("prestamos: $yo cambió la devolución de $id a " . date('Y-m-d H:i', $nuevo));
            $r = pres_leer('reservas')[$id];
            $aviso = $r['solicitante'] !== $yo ? ' ' . notificar($r, 'cambio', '') : '';
            ir($q, 'ok', 'Nueva fecha de devolución: ' . pres_fmt($nuevo) . '.' . $aviso);

        case 'activo_guardar':
            if (!$esTI) ir('', 'err', 'Sin permiso.');
            $aid = (string)($P['aid'] ?? '');
            $a = ['codigo' => pres_txt($P['codigo'] ?? '', 40), 'tipo' => in_array($P['tipo'] ?? '', PRES_TIPOS, true) ? $P['tipo'] : 'Otro',
                  'descripcion' => pres_txt($P['descripcion'] ?? '', 150), 'marca' => pres_txt($P['marca'] ?? '', 60),
                  'modelo' => pres_txt($P['modelo'] ?? '', 60), 'serie' => pres_txt($P['serie'] ?? '', 60),
                  'accesorios' => pres_txt($P['accesorios'] ?? '', 200)];
            $q = '?v=catalogo' . ($aid !== '' ? '&editar=' . urlencode($aid) : '&nuevo=1');
            if ($a['codigo'] === '') ir($q, 'err', 'Indique el número de activo (placa).');
            $nuevo = pres_transaccion(function (&$act, &$res) use ($aid, $a, $yo, $q) {
                foreach ($act as $k => $x) if ($k !== $aid && strcasecmp($x['codigo'], $a['codigo']) === 0) ir($q, 'err', "Ya existe un activo con el N.° {$a['codigo']}.");
                if ($aid === '') { $aid = 'a' . bin2hex(random_bytes(5)); $act[$aid] = ['id' => $aid, 'activo' => true, 'creado' => time(), 'creado_por' => $yo]; }
                elseif (empty($act[$aid])) ir('?v=catalogo', 'err', 'El activo no existe.');
                $act[$aid] = array_merge($act[$aid], $a, ['modificado' => time()]);
                return $aid;
            });
            actas_log("prestamos: $yo guardó activo {$a['codigo']}");
            ir('?v=catalogo', 'ok', 'Activo N.° ' . $a['codigo'] . ($aid === '' ? ' agregado al catálogo.' : ' actualizado.'));

        case 'activo_estado':
            if (!$esTI) ir('', 'err', 'Sin permiso.');
            $aid = (string)($P['aid'] ?? '');
            $on = pres_transaccion(function (&$act, &$res) use ($aid) {
                if (empty($act[$aid])) ir('?v=catalogo', 'err', 'El activo no existe.');
                $act[$aid]['activo'] = empty($act[$aid]['activo']);
                return $act[$aid]['activo'];
            });
            ir('?v=catalogo', 'ok', $on ? 'El activo vuelve a estar disponible para préstamos.' : 'El activo ya no se podrá reservar (sus préstamos actuales se mantienen).');
        }
    } catch (InvalidArgumentException $ex) {
        ir('?v=ver&id=' . urlencode((string)($P['id'] ?? '')) . '&form=' . urlencode($acc), 'err', $ex->getMessage());
    } catch (RuntimeException $ex) {
        ir('', 'err', $ex->getMessage());
    }
    ir('');
}

/* ======================= Datos para las vistas ======================= */
$activos  = pres_leer('activos');
$reservas = pres_leer('reservas');
uasort($activos, fn($a, $b) => [$a['tipo'], $a['codigo']] <=> [$b['tipo'], $b['codigo']]);
$v = (string)($_GET['v'] ?? 'inicio');
if (!in_array($v, ['inicio', 'disponibilidad', 'nueva', 'ver', 'catalogo'], true) || ($v === 'catalogo' && !$esTI)) $v = 'inicio';
$flash = $_SESSION['flash_pres'] ?? null; unset($_SESSION['flash_pres']);
$ESTADO_TAG = ['reservada' => 'res', 'prestado' => 'pre', 'devuelto' => 'dev', 'cancelada' => 'can'];
function estado_txt(array $r): string {
    if (pres_vencida($r)) return 'Vencida (no se retiró)';
    if (pres_atrasado($r)) return 'Prestado: atrasado';
    return PRES_ESTADOS[$r['estado']];
}
function activos_txt(array $r, array $activos): string {
    return implode(', ', array_map(fn($aid) => isset($activos[$aid]) ? pres_nombre_activo($activos[$aid]) : 'Activo eliminado', $r['activos']));
}
$misReservas = array_filter($reservas, fn($r) => $r['solicitante'] === $yo);
// Datos del solicitante: los de su última reserva o los de su cuenta
$ultima = $misReservas ? array_reduce($misReservas, fn($c, $r) => !$c || $r['creado'] > $c['creado'] ? $r : $c) : null;
$pref = $_SESSION['borrador_pres'] ?? null; unset($_SESSION['borrador_pres']);
$pref ??= [
    'nombre' => $ultima['nombre'] ?? (string)($reg['nombre'] ?? ''), 'cedula' => $ultima['cedula'] ?? '',
    'departamento' => $ultima['departamento'] ?? '', 'cargo' => $ultima['cargo'] ?? '', 'telefono' => $ultima['telefono'] ?? '',
    'correo' => $ultima['correo'] ?? (string)($reg['correo'] ?? ''), 'motivo' => '', 'uso' => 'dentro', 'lugar' => '', 'autoriza' => '', 'activos' => [],
];
if ($esTI && $v === 'nueva' && !isset($_SESSION['borrador_pres']) && !$ultima) { /* TI que reserva para otra persona: deja los datos vacíos */ }
$titulos = ['inicio' => $esTI ? 'Préstamo de activos' : 'Mis reservas', 'disponibilidad' => 'Disponibilidad de activos',
            'nueva' => 'Nueva reserva', 'ver' => 'Acta de préstamo', 'catalogo' => 'Catálogo de activos'];
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulos[$v]) ?> · Actas TIC</title>
<style>
:root{--bg:#eef1f5;--paper:#fff;--ink:#1b2430;--mute:#5b6675;--line:#c9d1dc;--field:#fff;--band:#e7edf4;--zebra:#f6f8fb;--accent:#1f4e79;--gold:#c08a1e;--err:#b3261e;--ok:#1d7a4a;--warn:#9a6700;--shadow:0 1px 2px rgba(20,35,55,.08),0 8px 24px rgba(20,35,55,.08)}
@media (prefers-color-scheme:dark){:root{--bg:#12171d;--paper:#1b222b;--ink:#e8edf3;--mute:#9aa6b5;--line:#3a4554;--field:#141a21;--band:#243040;--zebra:#1f2731;--accent:#6fa8dc;--gold:#e0b04f;--err:#f2b8b5;--ok:#5fc48c;--warn:#e8c26b}}
*{box-sizing:border-box}
body{margin:0;color:var(--ink);font:15px/1.5 "Segoe UI",system-ui,Roboto,Arial,sans-serif;-webkit-font-smoothing:antialiased;background:var(--bg);padding:0 12px 40px}
a{color:var(--accent)}
.sheet{max-width:1100px;margin:24px auto 0;background:var(--paper);border:1px solid var(--line);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}
.sheet::before{content:"";display:block;height:4px;background:linear-gradient(90deg,var(--navy,#123456) 0 76%,var(--gold) 76% 100%)}
.hd{display:flex;align-items:center;gap:18px;padding:18px 24px;border-bottom:1px solid var(--line);background:linear-gradient(180deg,var(--zebra),var(--paper))}
.hd img{height:64px;width:auto}
.hd .eyebrow{font-size:11px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:var(--mute)}
.hd h1{margin:2px 0 0;font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;font-size:26px;font-weight:600;line-height:1.15}
.hd .num{margin-left:auto;text-align:right}.hd .num small{display:block;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--mute)}.hd .num b{font-size:24px;color:var(--navy-ink,var(--accent))}
.tabs{display:flex;gap:4px;padding:0 20px;border-bottom:1px solid var(--line);background:var(--zebra);overflow-x:auto}
.tabs a{padding:12px 14px;font-weight:600;font-size:14px;text-decoration:none;color:var(--mute);border-bottom:3px solid transparent;white-space:nowrap}
.tabs a:hover{color:var(--ink)}.tabs a.on{color:var(--navy-ink,var(--accent));border-bottom-color:var(--gold)}
.body{padding:20px 24px 26px}
.msg{margin:0 0 16px;padding:12px 14px;border-radius:8px;border:1px solid currentColor;background:color-mix(in srgb,currentColor 9%,transparent);font-weight:600}
.msg.ok{color:var(--ok)}.msg.err{color:var(--err)}.msg.warn{color:var(--warn)}
.bar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.bar h2,.sec-t{margin:0;font-size:17px;font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;color:var(--navy-ink,var(--accent))}
.b{font:inherit;font-size:13.5px;font-weight:600;padding:7px 14px;border-radius:8px;border:1px solid var(--line);background:var(--paper);color:var(--accent);cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:6px;line-height:1.4}
.b:hover:not(:disabled){border-color:var(--accent);background:var(--band)}
.b:disabled{opacity:.45;cursor:not-allowed}
.b.p{background:var(--navy,#123456);border-color:var(--navy,#123456);color:#fff}.b.p:hover{background:var(--navy-2,#1a4570)}
.b.danger{color:var(--err)}.b.danger:hover{border-color:var(--err);background:color-mix(in srgb,var(--err) 9%,transparent)}
.b.big{font-size:15px;padding:10px 20px}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
.stat{padding:14px 16px;border:1px solid var(--line);border-radius:10px;background:var(--paper);text-decoration:none;color:var(--ink)}
.stat b{display:block;font-size:26px;line-height:1.1;color:var(--navy-ink,var(--accent))}.stat span{font-size:13px;color:var(--mute)}
.stat.alerta b{color:var(--err)}
a.stat:hover{border-color:var(--accent)}
.filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.chip{padding:6px 12px;border-radius:999px;border:1px solid var(--line);font-size:13px;font-weight:600;text-decoration:none;color:var(--mute);background:var(--paper)}
.chip.on{background:var(--navy,#123456);border-color:var(--navy,#123456);color:#fff}
.filtros input[type=search]{margin-left:auto;max-width:260px}
.tw{overflow-x:auto;border:1px solid var(--line);border-radius:10px}
table{width:100%;border-collapse:collapse}
th{background:var(--navy,#123456);color:#fff;font-size:11.5px;letter-spacing:.06em;text-transform:uppercase;text-align:left;padding:10px 12px;white-space:nowrap}
td{padding:10px 12px;border-top:1px solid color-mix(in srgb,var(--line) 70%,transparent);vertical-align:top;font-size:14px}
tbody tr:hover{background:color-mix(in srgb,var(--band) 55%,transparent)}
tr.off td{color:var(--mute)}
td small{display:block;color:var(--mute);font-size:12.5px}
.tag{display:inline-block;font-size:12px;font-weight:700;padding:3px 10px;border-radius:999px;border:1px solid currentColor;white-space:nowrap}
.tag.res{color:var(--accent)}.tag.pre{color:var(--gold)}.tag.dev{color:var(--ok)}.tag.can,.tag.ven{color:var(--mute)}.tag.atr{color:var(--err)}
.tag.disp{color:var(--ok)}.tag.ocup{color:var(--err)}
.vacio{padding:28px;text-align:center;color:var(--mute)}
.panel{border:1px solid var(--line);border-left:4px solid var(--gold);border-radius:10px;background:var(--zebra);padding:18px 20px;margin:0 0 18px}
.panel h3{margin:0 0 12px;font-size:16px;color:var(--navy-ink,var(--accent))}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px 20px}
.grid3{grid-template-columns:repeat(3,1fr)}
.full{grid-column:1/-1}
label.l{display:block;font-size:13.5px;font-weight:600;color:var(--ink);margin-bottom:5px}
input[type=text],input[type=email],input[type=search],input[type=date],input[type=datetime-local],select,textarea{width:100%;font:inherit;color:var(--ink);background:var(--field);border:1px solid var(--line);border-radius:8px;padding:9px 11px}
textarea{min-height:76px;resize:vertical}
input:focus,select:focus,textarea:focus{outline:0;border-color:var(--accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 22%,transparent)}
.hint{font-size:12.5px;color:var(--mute);margin-top:4px}
.req{color:var(--err)}
.radios{display:flex;gap:10px;flex-wrap:wrap}
.radios label{display:flex;gap:8px;align-items:center;font-size:14px;padding:9px 14px;border:1px solid var(--line);border-radius:999px;background:var(--paper);cursor:pointer}
.radios label:has(input:checked){border-color:var(--accent);background:var(--band);font-weight:600}
input[type=radio],input[type=checkbox]{accent-color:var(--navy-ink,var(--accent));width:17px;height:17px}
.acts{display:flex;gap:10px;justify-content:flex-end;align-items:center;flex-wrap:wrap;margin-top:18px}
/* Selección de activos */
.lista-act{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:10px}
.act{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid var(--line);border-radius:10px;background:var(--paper);cursor:pointer}
.act:has(input:checked){border-color:var(--accent);background:var(--band);box-shadow:inset 0 0 0 1px var(--accent)}
.act.no{cursor:not-allowed;opacity:.62;background:var(--zebra)}
.act b{display:block;font-size:14.5px}.act small{display:block;font-size:12.5px;color:var(--mute)}
.act .est{margin-top:6px}
.grupo-t{grid-column:1/-1;margin:8px 0 0;font-size:12.5px;font-weight:700;color:var(--mute);text-transform:uppercase;letter-spacing:.06em}
.rango{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin-bottom:16px}
.dur{font-size:13px;color:var(--mute);padding-bottom:10px}
/* Acta */
.acta .sec{margin-bottom:18px}
.acta .sec>h3{display:flex;align-items:center;gap:10px;margin:0 0 10px;padding:8px 12px;background:var(--band);border-radius:8px;font-size:13.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--navy-ink,var(--accent))}
.dl{display:grid;grid-template-columns:repeat(3,1fr);gap:10px 18px}
.dl div small{display:block;font-size:12px;color:var(--mute)}
.dl div span{font-weight:600}
.dl .full{grid-column:1/-1}
.ver-list{margin:0;padding:0;list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:4px 18px;font-size:14px}
.ver-list li::before{content:"\2610  ";color:var(--mute)}.ver-list li.ok::before{content:"\2611  ";color:var(--ok)}
.terms{font-size:13px;line-height:1.55;padding:12px 14px;border:1px solid var(--line);border-left:4px solid var(--gold);border-radius:8px;background:var(--zebra);white-space:pre-line}
.firmas{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.firma{border:1px solid var(--line);border-radius:10px;padding:12px 14px;background:var(--paper)}
.firma h4{margin:0 0 8px;font-size:12.5px;letter-spacing:.06em;text-transform:uppercase;color:var(--navy-ink,var(--accent))}
.firma img{display:block;width:100%;height:110px;object-fit:contain;border-bottom:1px solid var(--ink);background:#fff}
.firma .dat{display:grid;grid-template-columns:1.6fr 1fr 1fr;gap:8px;margin-top:8px;font-size:13px}.firma .dat small{display:block;font-size:11px;color:var(--mute)}
.pad-w{position:relative}
.pad{display:block;width:100%;height:140px;border:1px dashed var(--line);border-radius:8px;background:#fff;touch-action:none;cursor:crosshair}
.pad-w .x{position:absolute;left:14px;bottom:24px;font-size:12px;color:#9aa5b4;pointer-events:none}
.pad-w .x::after{content:"";position:absolute;left:0;right:-9999px;bottom:-6px;border-bottom:1px solid #c9d1dc;width:calc(100% + 0px)}
.pad-row{display:flex;justify-content:space-between;align-items:center;margin:6px 0 8px;font-size:13px}
.pad-row button{border:0;background:none;color:var(--accent);font:inherit;font-weight:600;cursor:pointer}
.firma .g3{display:grid;grid-template-columns:1fr;gap:8px}.firma .g2{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.docs{display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;font-size:13.5px;margin:0 0 14px;padding:10px 14px;border:1px solid var(--line);border-radius:10px;background:var(--zebra)}
.docs .doc a{font-weight:600}
.aviso-linea{font-size:13.5px;color:var(--mute);margin:0 0 14px}
.det-act td input{min-width:240px}
@media(max-width:760px){.grid,.grid3,.dl,.firmas,.ver-list,.rango{grid-template-columns:1fr}.stats{grid-template-columns:1fr 1fr}.hd{flex-wrap:wrap;padding:14px}.body{padding:14px}.hd .num{margin-left:0}.filtros input[type=search]{margin-left:0;max-width:none}}
@media print{
  body{background:#fff;padding:0;font-size:11.5px}.sheet{border:0;box-shadow:none;margin:0;border-radius:0}.sheet::before{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .tabs,.no-print,.msg,.acts,.panel,.aviso-fondo{display:none!important}
  .hd{padding:6px 4px 10px}.hd img{height:56px}.body{padding:8px 4px}
  .acta .sec{margin-bottom:8px;break-inside:avoid}.acta .sec>h3{padding:4px 8px;margin-bottom:6px;font-size:10.5px;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  th{-webkit-print-color-adjust:exact;print-color-adjust:exact;padding:5px 8px}td{padding:5px 8px;font-size:11px}
  .firma img{height:70px}.terms{font-size:10px;padding:6px 10px}.tw{border-radius:0}
}
</style>
<?php tema_estilos(); ?>
</head>
<body>
<?php tema_barra($esTI ? 'index.php' : null, 'Volver al menú', $yo, '1100px', $esTI ? [] : [['cambiar_clave.php', 'Cambiar mi contraseña']]); ?>
<main class="sheet">
<?php
$rv = null;
if ($v === 'ver') {
    $rv = $reservas[(string)($_GET['id'] ?? '')] ?? null;
    if (!$rv || !puede_ver($rv)) { $rv = null; $v = 'inicio'; $flash = ['err', 'La reserva no existe o no tiene acceso a ella.']; }
}
?>
  <header class="hd">
    <img src="<?= ESCUDO_MINI ?>" alt="Escudo de la Municipalidad de Carrillo">
    <div><div class="eyebrow">Municipalidad de Carrillo · Departamento de TI</div><h1><?= e($v === 'ver' ? 'Acta de préstamo de activos' : $titulos[$v]) ?></h1></div>
<?php if ($rv): ?>    <div class="num"><small>N.° de acta</small><b><?= e($rv['id']) ?></b></div><?php endif; ?>
  </header>
  <nav class="tabs no-print">
    <a href="prestamos.php" class="<?= $v === 'inicio' ? 'on' : '' ?>"><?= $esTI ? 'Reservas y préstamos' : 'Mis reservas' ?></a>
    <a href="prestamos.php?v=disponibilidad" class="<?= $v === 'disponibilidad' ? 'on' : '' ?>">Disponibilidad</a>
    <a href="prestamos.php?v=nueva" class="<?= $v === 'nueva' ? 'on' : '' ?>">Nueva reserva</a>
<?php if ($esTI): ?>    <a href="prestamos.php?v=catalogo" class="<?= $v === 'catalogo' ? 'on' : '' ?>">Catálogo de activos</a><?php endif; ?>
<?php if ($rv): ?>    <a href="#" class="on">Acta <?= e($rv['id']) ?></a><?php endif; ?>
  </nav>
  <div class="body">
<?php if ($flash): ?>    <div class="msg <?= e($flash[0]) ?>" role="<?= $flash[0] === 'err' ? 'alert' : 'status' ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php
/* ======================= INICIO ======================= */
if ($v === 'inicio'):
    $lista = $esTI ? $reservas : $misReservas;
    $f = (string)($_GET['f'] ?? 'activas');
    $filtros = ['activas' => 'Pendientes y prestados', 'reservada' => 'Por entregar', 'prestado' => 'Prestados', 'atrasados' => 'Atrasados', 'devuelto' => 'Devueltos', 'cancelada' => 'Cancelados', 'todas' => 'Todos'];
    if (!isset($filtros[$f])) $f = 'activas';
    $lista = array_filter($lista, fn($r) => match ($f) {
        'activas' => in_array($r['estado'], ['reservada', 'prestado'], true) && !pres_vencida($r),
        'atrasados' => pres_atrasado($r),
        'todas' => true,
        'cancelada' => $r['estado'] === 'cancelada' || pres_vencida($r),
        'reservada' => $r['estado'] === 'reservada' && !pres_vencida($r),
        default => $r['estado'] === $f,
    });
    uasort($lista, fn($a, $b) => in_array($f, ['activas', 'reservada', 'prestado', 'atrasados'], true) ? $a['inicio'] <=> $b['inicio'] : $b['creado'] <=> $a['creado']);
    $base = $esTI ? $reservas : $misReservas;
    $cuenta = fn($fn) => count(array_filter($base, $fn));
?>
    <div class="stats">
      <a class="stat" href="?f=reservada"><b><?= $cuenta(fn($r) => $r['estado'] === 'reservada' && !pres_vencida($r)) ?></b><span>Reservas por retirar</span></a>
      <a class="stat" href="?f=prestado"><b><?= $cuenta(fn($r) => $r['estado'] === 'prestado') ?></b><span>Activos prestados</span></a>
      <a class="stat<?= $cuenta('pres_atrasado') ? ' alerta' : '' ?>" href="?f=atrasados"><b><?= $cuenta('pres_atrasado') ?></b><span>Devoluciones atrasadas</span></a>
      <a class="stat" href="?v=disponibilidad"><b><?= count(array_filter($activos, fn($a) => !empty($a['activo']))) ?></b><span>Activos en catálogo</span></a>
    </div>
    <div class="bar"><h2><?= $esTI ? 'Reservas y préstamos' : 'Mis reservas' ?></h2>
      <span style="display:flex;gap:8px;flex-wrap:wrap"><a class="b" href="?v=disponibilidad">Ver disponibilidad</a><a class="b p" href="?v=nueva">+ Nueva reserva</a></span></div>
    <div class="filtros">
<?php foreach ($filtros as $k => $t): ?>      <a class="chip<?= $f === $k ? ' on' : '' ?>" href="?f=<?= $k ?>"><?= e($t) ?></a>
<?php endforeach; ?>
      <input type="search" id="buscar" placeholder="Buscar por nombre, activo o N.°" aria-label="Buscar">
    </div>
    <div class="tw"><table id="tabla">
      <thead><tr><th>N.° acta</th><?= $esTI ? '<th>Solicitante</th>' : '' ?><th>Activos</th><th>Retiro</th><th>Devolución</th><th>Estado</th><th></th></tr></thead>
      <tbody>
<?php foreach ($lista as $r): $tag = pres_vencida($r) ? 'ven' : (pres_atrasado($r) ? 'atr' : $ESTADO_TAG[$r['estado']]); ?>
        <tr class="<?= in_array($r['estado'], ['cancelada', 'devuelto'], true) || pres_vencida($r) ? 'off' : '' ?>">
          <td style="white-space:nowrap"><strong><?= e($r['id']) ?></strong></td>
<?php if ($esTI): ?>          <td><?= e($r['nombre']) ?><small><?= e($r['departamento']) ?></small></td><?php endif; ?>
          <td><?= e(activos_txt($r, $activos)) ?></td>
          <td><?= e(pres_fmt($r['inicio'])) ?></td>
          <td><?= e(pres_fmt($r['fin'])) ?><?php if ($r['estado'] === 'devuelto'): ?><small>Devuelto el <?= e(pres_fmt((int)$r['devolucion']['at'])) ?></small><?php endif; ?></td>
          <td><span class="tag <?= $tag ?>"><?= e(estado_txt($r)) ?></span></td>
          <td style="text-align:right"><a class="b<?= $esTI && in_array($r['estado'], ['reservada', 'prestado'], true) && !pres_vencida($r) ? ' p' : '' ?>" href="?v=ver&amp;id=<?= urlencode($r['id']) ?>"><?= $esTI && $r['estado'] === 'reservada' && !pres_vencida($r) ? 'Entregar' : ($esTI && $r['estado'] === 'prestado' ? 'Recibir devolución' : 'Ver acta') ?></a></td>
        </tr>
<?php endforeach; ?>
      </tbody></table>
<?php if (!$lista): ?>      <div class="vacio"><?= $f === 'activas' ? ($esTI ? 'No hay reservas pendientes ni activos prestados.' : 'No tiene reservas pendientes. Use «Nueva reserva» para solicitar un activo.') : 'No hay registros en esta categoría.' ?></div><?php endif; ?>
    </div>
    <script>document.getElementById('buscar').addEventListener('input',e=>{const q=e.target.value.trim().toLowerCase();document.querySelectorAll('#tabla tbody tr').forEach(tr=>tr.hidden=!!q&&!tr.textContent.toLowerCase().includes(q))})</script>
<?php if (!$esTI): ?>    <p class="aviso-linea" style="margin-top:14px">Después de reservar, preséntese en el Departamento de TI en la fecha de retiro. Ahí se le entregará el activo y ambos firmarán el acta de préstamo.</p><?php endif; ?>
<?php
/* ======================= DISPONIBILIDAD y NUEVA ======================= */
elseif ($v === 'disponibilidad' || $v === 'nueva'):
    $ini0 = (string)($_GET['inicio'] ?? ''); $fin0 = (string)($_GET['fin'] ?? '');
    if (pres_ts($ini0) === null) { $t = strtotime(date('Y-m-d H:00')) + 3600; $ini0 = date('Y-m-d\TH:i', $t); }
    if (pres_ts($fin0) === null) $fin0 = date('Y-m-d\TH:i', pres_ts($ini0) + 3 * 3600);
    $cat = array_values(array_filter($activos, fn($a) => !empty($a['activo'])));
    $catJs = array_map(fn($a) => ['id' => $a['id'], 'codigo' => $a['codigo'], 'tipo' => $a['tipo'], 'nombre' => trim(($a['marca'] ?? '') . ' ' . ($a['modelo'] ?? '')) ?: $a['tipo'],
                                  'desc' => $a['descripcion'] ?? '', 'acc' => $a['accesorios'] ?? ''], $cat);
?>
<?php if ($v === 'nueva'): ?>
    <form method="post" id="fres" novalidate>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="reservar">
      <p class="aviso-linea">Elija las fechas, seleccione los activos disponibles y complete sus datos. Solo se muestran como disponibles los activos que nadie más tiene reservados o prestados en ese horario.</p>
      <div class="panel"><h3>1. ¿Cuándo lo necesita?</h3>
<?php endif; ?>
      <div class="rango">
        <div><label class="l" for="inicio">Retiro (fecha y hora)<?= $v === 'nueva' ? ' <span class="req">*</span>' : '' ?></label><input type="datetime-local" id="inicio" name="inicio" value="<?= e($ini0) ?>" required></div>
        <div><label class="l" for="fin">Devolución estimada (fecha y hora)<?= $v === 'nueva' ? ' <span class="req">*</span>' : '' ?></label><input type="datetime-local" id="fin" name="fin" value="<?= e($fin0) ?>" required></div>
        <div class="dur" id="dur"></div>
      </div>
<?php if ($v === 'nueva'): ?>      </div>
      <div class="panel"><h3>2. Activos <span class="req">*</span></h3>
<?php endif; ?>
      <div class="filtros"><span id="resumen" class="hint" style="margin:0"></span><input type="search" id="filtro" placeholder="Filtrar por tipo, marca o N.°" aria-label="Filtrar activos"></div>
      <div class="lista-act" id="lista"></div>
<?php if (!$cat): ?>      <div class="vacio">Aún no hay activos en el catálogo.<?= $esTI ? ' Agréguelos en «Catálogo de activos».' : ' Consulte con el Departamento de TI.' ?></div><?php endif; ?>
<?php if ($v === 'disponibilidad'): ?>
      <div class="acts"><button type="button" class="b p big" id="ir-reservar">Reservar en este horario</button></div>
<?php else: ?>
      </div>
      <div class="panel"><h3>3. Datos del solicitante</h3>
        <div class="grid grid3">
          <div><label class="l" for="nombre">Nombre completo <span class="req">*</span></label><input type="text" id="nombre" name="nombre" maxlength="80" required value="<?= e($pref['nombre']) ?>"></div>
          <div><label class="l" for="cedula">Cédula <span class="req">*</span></label><input type="text" id="cedula" name="cedula" maxlength="25" required inputmode="numeric" placeholder="Ej. 5-0123-0456" value="<?= e($pref['cedula']) ?>"></div>
          <div><label class="l" for="telefono">Extensión o teléfono</label><input type="text" id="telefono" name="telefono" maxlength="30" value="<?= e($pref['telefono']) ?>"></div>
          <div><label class="l" for="departamento">Departamento <span class="req">*</span></label><input type="text" id="departamento" name="departamento" maxlength="80" required value="<?= e($pref['departamento']) ?>"></div>
          <div><label class="l" for="cargo">Cargo <span class="req">*</span></label><input type="text" id="cargo" name="cargo" maxlength="80" required value="<?= e($pref['cargo']) ?>"></div>
          <div><label class="l" for="correo">Correo institucional <span class="req">*</span></label><input type="email" id="correo" name="correo" maxlength="120" required value="<?= e($pref['correo']) ?>"></div>
        </div>
      </div>
      <div class="panel"><h3>4. Uso del activo</h3>
        <div class="grid">
          <div class="full"><label class="l" for="motivo">Motivo o actividad <span class="req">*</span></label><textarea id="motivo" name="motivo" maxlength="800" required><?= e($pref['motivo']) ?></textarea></div>
          <div><label class="l">Lugar de uso <span class="req">*</span></label>
            <div class="radios"><label><input type="radio" name="uso" value="dentro"<?= $pref['uso'] !== 'fuera' ? ' checked' : '' ?>> Dentro de la Municipalidad</label><label><input type="radio" name="uso" value="fuera"<?= $pref['uso'] === 'fuera' ? ' checked' : '' ?>> Fuera de la Municipalidad</label></div></div>
          <div><label class="l" for="lugar">Lugar o dirección donde se usará <span class="req">*</span></label><input type="text" id="lugar" name="lugar" maxlength="150" required value="<?= e($pref['lugar']) ?>"></div>
          <div id="w-autoriza"<?= $pref['uso'] !== 'fuera' ? ' hidden' : '' ?>><label class="l" for="autoriza">Jefatura que autoriza la salida <span class="req">*</span></label><input type="text" id="autoriza" name="autoriza" maxlength="80" value="<?= e($pref['autoriza']) ?>"><div class="hint">Requerido cuando el activo sale de las instalaciones municipales.</div></div>
        </div>
      </div>
      <div class="panel"><h3>5. Condiciones del préstamo</h3>
        <div class="terms"><?= e(PRES_TERMINOS) ?></div>
        <label style="display:flex;gap:10px;align-items:center;margin-top:12px;font-weight:600"><input type="checkbox" name="acepto" value="1" id="acepto"> He leído y acepto las condiciones del préstamo.</label>
      </div>
      <div class="msg err" id="err-res" role="alert" hidden></div>
      <div class="acts"><a class="b" href="prestamos.php">Cancelar</a><button type="submit" class="b p big">Reservar activos</button></div>
    </form>
<?php endif; ?>
<script>
(()=>{
const CAT=<?= jsn($catJs) ?>, OCU=<?= jsn(pres_ocupacion_publica($reservas)) ?>, PRE=<?= jsn($pref['activos'] ?? []) ?>, NUEVA=<?= $v === 'nueva' ? 'true' : 'false' ?>;
const $=s=>document.querySelector(s), ini=$('#inicio'), fin=$('#fin'), lista=$('#lista');
const ts=v=>{const d=new Date(v);return isNaN(d)?null:Math.floor(d/1000)};
const f=t=>new Date(t*1000).toLocaleString('es-CR',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
const marcados=new Set(PRE);
function el(t,a,...h){const n=document.createElement(t);for(const k in a||{})k==='text'?n.textContent=a[k]:n.setAttribute(k,a[k]);h.forEach(x=>x!=null&&n.append(x));return n}
function pintar(){
  const i=ts(ini.value),e=ts(fin.value),q=($('#filtro').value||'').toLowerCase(),ok=i&&e&&e>i;
  const d=$('#dur');if(ok){const h=(e-i)/3600;d.textContent='Duración: '+(h<24?Math.round(h*10)/10+' h':Math.round(h/24*10)/10+' días')}else d.textContent=i&&e?'La devolución debe ser posterior al retiro.':'';
  lista.innerHTML='';let disp=0,tipo='';
  CAT.filter(a=>!q||(a.tipo+' '+a.nombre+' '+a.codigo+' '+a.desc).toLowerCase().includes(q)).forEach(a=>{
    const ch=ok?OCU.filter(o=>o.a===a.id&&i<o.f&&o.i<e):[];const libre=ok&&!ch.length;if(libre)disp++;
    if(a.tipo!==tipo){tipo=a.tipo;lista.append(el('div',{class:'grupo-t',text:tipo}))}
    const est=!ok?el('span',{class:'tag can',text:'Elija fechas'}):libre?el('span',{class:'tag disp',text:'Disponible'}):
      el('span',{class:'tag ocup',text:(ch[0].e==='prestado'?'Prestado':'Reservado')+' hasta '+f(ch[0].f)});
    const caja=el('label',{class:'act'+(libre?'':' no')});
    if(NUEVA){const cb=el('input',{type:'checkbox',name:'activos[]',value:a.id});cb.disabled=!libre;cb.checked=libre&&marcados.has(a.id);
      cb.onchange=()=>{cb.checked?marcados.add(a.id):marcados.delete(a.id);resumen()};caja.append(cb)}
    caja.append(el('span',{},el('b',{text:a.nombre}),el('small',{text:'N.° de activo '+a.codigo+(a.desc?' · '+a.desc:'')}),a.acc?el('small',{text:'Incluye: '+a.acc}):null,el('div',{class:'est'},est)));
    lista.append(caja)});
  resumen(disp)}
function resumen(disp){const r=$('#resumen');if(disp!==undefined)r.dataset.d=disp;
  const sel=NUEVA?[...document.querySelectorAll('input[name="activos[]"]:checked')].length:0;
  r.textContent=(r.dataset.d||0)+' de '+CAT.length+' activos disponibles en ese horario'+(NUEVA?' · '+sel+' seleccionado(s)':'')}
[ini,fin].forEach(x=>x.addEventListener('input',pintar));$('#filtro').addEventListener('input',pintar);pintar();
const ir=$('#ir-reservar');if(ir)ir.onclick=()=>location.href='prestamos.php?v=nueva&inicio='+encodeURIComponent(ini.value)+'&fin='+encodeURIComponent(fin.value);
if(NUEVA){
  document.querySelectorAll('[name=uso]').forEach(r=>r.onchange=()=>$('#w-autoriza').hidden=$('[name=uso]:checked').value!=='fuera');
  $('#fres').addEventListener('submit',ev=>{const errs=[];const i=ts(ini.value),e=ts(fin.value);
    document.querySelectorAll('#fres [required]').forEach(x=>{const mal=!x.value.trim();x.style.borderColor=mal?'var(--err)':'';if(mal)errs.push(1)});
    const fuera=$('[name=uso]:checked').value==='fuera';$('#autoriza').style.borderColor=fuera&&!$('#autoriza').value.trim()?'var(--err)':'';
    let m=errs.length||(fuera&&!$('#autoriza').value.trim())?'Complete los campos obligatorios marcados en rojo.':'';
    if(!m&&!(i&&e&&e>i))m='Revise las fechas: la devolución debe ser posterior al retiro.';
    if(!m&&!document.querySelectorAll('input[name="activos[]"]:checked').length)m='Seleccione al menos un activo disponible.';
    if(!m&&!$('#acepto').checked)m='Debe aceptar las condiciones del préstamo.';
    const b=$('#err-res');if(m){ev.preventDefault();b.textContent=m;b.hidden=false;b.scrollIntoView({behavior:'smooth',block:'center'})}else b.hidden=true})}
})();
</script>
<?php
/* ======================= VER ACTA ======================= */
elseif ($v === 'ver'):
    $r = $rv; $en = $r['entrega'] ?? null; $dv = $r['devolucion'] ?? null;
    $formErr = (string)($_GET['form'] ?? '');
    $firmaVista = function (string $t, ?array $fz) { ?>
        <div class="firma"><h4><?= e($t) ?></h4>
          <?php if ($fz): ?><img src="<?= e($fz['img']) ?>" alt="Firma"><?php else: ?><div style="height:110px;border-bottom:1px solid var(--ink)"></div><?php endif; ?>
          <div class="dat"><div><small>Nombre</small><?= e($fz['nombre'] ?? '') ?></div><div><small>Cédula</small><?= e($fz['cedula'] ?? '') ?></div><div><small>Fecha</small><?= e(isset($fz['fecha']) && $fz['fecha'] ? implode('/', array_reverse(explode('-', $fz['fecha']))) : '') ?></div></div>
        </div>
<?php };
    $padForm = function (string $pref, string $t, string $nombre, string $cedula) { ?>
        <div class="firma"><h4><?= e($t) ?> <span class="req">*</span></h4>
          <div class="pad-w"><canvas class="pad" data-pad="<?= $pref ?>" aria-label="Área de firma: <?= e($t) ?>"></canvas><span class="x">✕ Firme aquí</span></div>
          <div class="pad-row"><span class="req" data-err="<?= $pref ?>"></span><button type="button" data-borrar="<?= $pref ?>">↺ Borrar firma</button></div>
          <input type="hidden" name="<?= $pref ?>_img">
          <div class="g3"><div><label class="l">Nombre <span class="req">*</span></label><input type="text" name="<?= $pref ?>_nombre" maxlength="80" value="<?= e($nombre) ?>" required></div></div>
          <div class="g2" style="margin-top:8px"><div><label class="l">Cédula <span class="req">*</span></label><input type="text" name="<?= $pref ?>_cedula" maxlength="25" inputmode="numeric" value="<?= e($cedula) ?>" required></div>
            <div><label class="l">Fecha <span class="req">*</span></label><input type="date" name="<?= $pref ?>_fecha" value="<?= date('Y-m-d') ?>" required></div></div>
        </div>
<?php };
?>
<?php $correoMsg = $_SESSION['correo_pres'] ?? ''; unset($_SESSION['correo_pres']); ?>
<?php if ($correoMsg !== ''): ?>    <div class="msg <?= str_starts_with($correoMsg, 'Se envió') ? 'ok' : 'warn' ?>" role="status">&#9993; <?= e($correoMsg) ?></div>
<?php endif; ?>
<?php if (isset($_GET['nueva'])): ?>
    <div class="msg ok" role="status">Reserva <?= e($r['id']) ?> registrada. Preséntese en el Departamento de TI el <?= e(pres_fmt($r['inicio'])) ?> para retirar los activos y firmar el acta.</div>
<?php endif; ?>
    <div class="acts no-print" style="margin:0 0 16px;justify-content:space-between">
      <span><span class="tag <?= pres_vencida($r) ? 'ven' : (pres_atrasado($r) ? 'atr' : $ESTADO_TAG[$r['estado']]) ?>" style="font-size:13px"><?= e(estado_txt($r)) ?></span></span>
      <span style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="b" href="prestamos.php">← Volver a la lista</a>
        <a class="b" href="?pdf=<?= urlencode($r['id']) ?>" target="_blank" rel="noopener">Ver PDF</a>
        <a class="b p" href="?pdf=<?= urlencode($r['id']) ?>&amp;descargar=1">Descargar PDF</a>
        <button type="button" class="b" onclick="print()">Imprimir</button>
<?php if ($r['estado'] === 'reservada'): ?>
        <form method="post" class="inl" onsubmit="return confirm('¿Cancelar la reserva <?= e($r['id']) ?>? Los activos quedarán disponibles para otras personas.')">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="cancelar"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
          <button class="b danger">Cancelar reserva</button></form>
<?php endif; ?>
      </span>
    </div>
    <div class="docs no-print">
      <strong>Documentos registrados:</strong>
<?php foreach (['reserva' => 'Comprobante de reserva', 'entrega' => 'Acta de préstamo', 'devolucion' => 'Acta de devolución'] as $dk => $dt):
        if (($dk === 'entrega' && !$en) || ($dk === 'devolucion' && !$dv)) continue; ?>
      <span class="doc">&#128196; <?= e($dt) ?> <a href="?pdf=<?= urlencode($r['id']) ?>&amp;doc=<?= $dk ?>" target="_blank" rel="noopener">ver</a> · <a href="?pdf=<?= urlencode($r['id']) ?>&amp;doc=<?= $dk ?>&amp;descargar=1">descargar</a></span>
<?php endforeach; ?>
    </div>
<?php if (in_array($r['estado'], ['reservada', 'prestado'], true) && !pres_vencida($r)): ?>
    <details class="panel no-print" id="cambiar-fin"<?= ($_GET['form'] ?? '') === 'cambiar_fin' ? ' open' : '' ?>>
      <summary style="cursor:pointer;font-weight:700;color:var(--navy-ink,var(--accent))">&#128197; Devolución <?= $r['estado'] === 'prestado' ? 'acordada' : 'estimada' ?>: <?= e(pres_fmt((int)$r['fin'])) ?> · Cambiar fecha de devolución</summary>
      <form method="post" style="margin-top:12px" novalidate>
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="cambiar_fin"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
        <div class="grid">
          <div><label class="l" for="nfin">Nueva fecha y hora de devolución</label><input type="datetime-local" id="nfin" name="fin" value="<?= e(date('Y-m-d\TH:i', (int)$r['fin'])) ?>" required></div>
          <div><label class="l" for="nmot">Motivo</label><input type="text" id="nmot" name="motivo" maxlength="200" placeholder="Ej. la actividad se extendió un día"></div>
        </div>
        <p class="hint">El sistema revisa que los activos no estén reservados por otra persona en el nuevo plazo.<?= $esTI ? ' Se le avisará al solicitante por correo.' : '' ?></p>
        <div class="acts" style="display:flex"><button class="b p">Guardar nueva fecha</button></div>
      </form>
    </details>
<?php endif; ?>
    <div class="acta">
      <section class="sec"><h3>Datos del solicitante</h3>
        <div class="dl">
          <div><small>Nombre completo</small><span><?= e($r['nombre']) ?></span></div>
          <div><small>Cédula</small><span><?= e($r['cedula']) ?></span></div>
          <div><small>Departamento</small><span><?= e($r['departamento']) ?></span></div>
          <div><small>Cargo</small><span><?= e($r['cargo']) ?></span></div>
          <div><small>Correo</small><span><?= e($r['correo']) ?></span></div>
          <div><small>Extensión o teléfono</small><span><?= e($r['telefono'] ?: '—') ?></span></div>
        </div>
      </section>
      <section class="sec"><h3>Préstamo</h3>
        <div class="dl">
          <div><small>Fecha de la reserva</small><span><?= e(pres_fmt($r['creado'])) ?></span></div>
          <div><small>Retiro programado</small><span><?= e(pres_fmt($r['inicio'])) ?></span></div>
          <div><small>Devolución estimada por el solicitante</small><span><?= e(pres_fmt((int)($r['fin_solicitada'] ?? $r['fin']))) ?></span></div>
<?php if ($en || (int)$r['fin'] !== (int)($r['fin_solicitada'] ?? $r['fin'])): ?>          <div><small>Devolución acordada</small><span><?= e(pres_fmt((int)$r['fin'])) ?></span></div><?php endif; ?>
<?php if ($en): ?>          <div><small>Entregado el</small><span><?= e(pres_fmt((int)$en['at'])) ?></span></div><?php endif; ?>
<?php if ($dv): ?>          <div><small>Devuelto el</small><span><?= e(pres_fmt((int)$dv['at'])) ?></span></div><?php endif; ?>
          <div><small>Lugar de uso</small><span><?= $r['uso'] === 'fuera' ? 'Fuera de la Municipalidad' : 'Dentro de la Municipalidad' ?></span></div>
          <div><small>Lugar o dirección</small><span><?= e($r['lugar']) ?></span></div>
          <div><small>Autoriza la salida</small><span><?= e($r['uso'] === 'fuera' ? $r['autoriza'] : 'No aplica') ?></span></div>
          <div class="full"><small>Motivo o actividad</small><span style="white-space:pre-line"><?= e($r['motivo']) ?></span></div>
<?php if (!empty($r['cambios_fin'])): ?>          <div class="full"><small>Cambios en la fecha de devolución</small>
<?php foreach ($r['cambios_fin'] as $c): ?>            <div style="font-size:13.5px">• <?= e(pres_fmt((int)$c['at'])) ?> — <?= e($c['por']) ?>: del <?= e(pres_fmt((int)$c['de'])) ?> al <strong><?= e(pres_fmt((int)$c['a'])) ?></strong><?= $c['motivo'] !== '' ? ' (' . e($c['motivo']) . ')' : '' ?></div>
<?php endforeach; ?>          </div><?php endif; ?>
        </div>
      </section>
      <section class="sec"><h3>Activos</h3>
        <div class="tw"><table>
          <thead><tr><th>#</th><th>N.° de activo</th><th>Tipo</th><th>Marca / modelo</th><th>Serie</th><th>Accesorios y estado al entregar</th></tr></thead>
          <tbody>
<?php foreach ($r['activos'] as $k => $aid): $a = $activos[$aid] ?? ['codigo' => '—', 'tipo' => 'Activo eliminado del catálogo', 'marca' => '', 'modelo' => '', 'serie' => '', 'accesorios' => '']; ?>
            <tr><td><?= $k + 1 ?></td><td><strong><?= e($a['codigo']) ?></strong></td><td><?= e($a['tipo']) ?><?php if (!empty($a['descripcion'])): ?><small><?= e($a['descripcion']) ?></small><?php endif; ?></td>
              <td><?= e(trim(($a['marca'] ?? '') . ' ' . ($a['modelo'] ?? '')) ?: '—') ?></td><td><?= e(($a['serie'] ?? '') ?: '—') ?></td>
              <td><?= e($en ? ($en['detalle'][$aid] ?? '') : ($a['accesorios'] ? 'Incluye: ' . $a['accesorios'] : '')) ?></td></tr>
<?php endforeach; ?>
          </tbody></table></div>
      </section>
      <section class="sec"><h3>Condiciones del préstamo</h3><div class="terms"><?= e(PRES_TERMINOS) ?></div><p style="margin:6px 0 0;font-size:13px">&#9745; El solicitante aceptó estas condiciones al reservar.</p></section>
<?php if ($en): ?>
      <section class="sec"><h3>Entrega · <?= e(pres_fmt((int)$en['at'])) ?></h3>
        <ul class="ver-list"><?php foreach ($en['verif'] as $x): ?><li class="<?= $x['ok'] ? 'ok' : '' ?>"><?= e($x['t']) ?></li><?php endforeach; ?></ul>
        <?php if ($en['obs'] !== ''): ?><p style="margin:8px 0 0"><small style="color:var(--mute)">Observaciones:</small> <?= e($en['obs']) ?></p><?php endif; ?>
        <div class="firmas" style="margin-top:12px"><?php $firmaVista('Entrega: funcionario de TI', $en['firma_ti']); $firmaVista('Recibe: solicitante', $en['firma_sol']); ?></div>
      </section>
<?php endif; ?>
<?php if ($dv): ?>
      <section class="sec"><h3>Devolución · <?= e(pres_fmt((int)$dv['at'])) ?></h3>
        <div class="dl" style="margin-bottom:8px"><div class="full"><small>Condición</small><span><?= $dv['condicion'] === 'bien' ? 'Completo y en buen estado' : 'Con daños o faltantes: ' . e($dv['danos']) ?></span></div></div>
        <ul class="ver-list"><?php foreach ($dv['verif'] as $x): ?><li class="<?= $x['ok'] ? 'ok' : '' ?>"><?= e($x['t']) ?></li><?php endforeach; ?></ul>
        <?php if ($dv['obs'] !== ''): ?><p style="margin:8px 0 0"><small style="color:var(--mute)">Observaciones:</small> <?= e($dv['obs']) ?></p><?php endif; ?>
        <div class="firmas" style="margin-top:12px"><?php $firmaVista('Entrega: solicitante', $dv['firma_sol']); $firmaVista('Recibe: funcionario de TI', $dv['firma_ti']); ?></div>
      </section>
<?php endif; ?>
<?php if ($r['estado'] === 'cancelada'): ?>
      <section class="sec"><h3>Cancelada</h3><p>Cancelada por <?= e($r['cancelada']['por']) ?> el <?= e(pres_fmt((int)$r['cancelada']['at'])) ?>.</p></section>
<?php endif; ?>
<?php if (!$en && $r['estado'] !== 'cancelada'): ?>
      <section class="sec"><h3>Firmas</h3><div class="firmas"><?php $firmaVista('Entrega: funcionario de TI', null); $firmaVista('Recibe: solicitante', null); ?></div>
        <p class="hint no-print">Las firmas se registran en el Departamento de TI al momento de retirar los activos.</p></section>
<?php endif; ?>
    </div>

<?php if ($esTI && $r['estado'] === 'reservada' && !pres_vencida($r)): ?>
    <form method="post" novalidate class="panel no-print firmable" id="f-entregar" style="margin-top:22px">
      <h3>Entregar activos y firmar el acta</h3>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="entregar"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
      <?php if ($r['inicio'] > time() + 3600): ?><p class="msg warn" style="font-weight:500">El retiro estaba programado para el <?= e(pres_fmt($r['inicio'])) ?>. Si entrega antes, el sistema revisa que los activos no estén ocupados desde ahora.</p><?php endif; ?>
      <label class="l">Accesorios y estado de cada activo al entregar</label>
      <div class="tw det-act" style="margin-bottom:14px"><table><tbody>
<?php foreach ($r['activos'] as $aid): $a = $activos[$aid] ?? null; ?>
        <tr><td style="width:40%"><strong><?= e($a ? pres_nombre_activo($a) : 'Activo') ?></strong></td><td><input type="text" name="det[<?= e($aid) ?>]" maxlength="200" value="<?= e(($a['accesorios'] ?? '') !== '' ? 'Incluye ' . $a['accesorios'] . '. Buen estado.' : 'Buen estado.') ?>"></td></tr>
<?php endforeach; ?>
      </tbody></table></div>
      <label class="l">Verificación al entregar</label>
      <div class="radios" style="flex-direction:column;gap:6px;margin-bottom:12px">
<?php foreach (PRES_VERIF_ENTREGA as $i => $t): ?>        <label style="border-radius:8px"><input type="checkbox" name="verif[]" value="<?= $i ?>"> <?= e($t) ?></label>
<?php endforeach; ?>
      </div>
      <div class="grid" style="margin-bottom:12px"><div><label class="l" for="fin_acordado">Devolución acordada <span class="req">*</span></label><input type="datetime-local" id="fin_acordado" name="fin_acordado" required value="<?= e(date('Y-m-d\TH:i', (int)$r['fin'])) ?>"><div class="hint">El solicitante estimó el <?= e(pres_fmt((int)$r['fin'])) ?>. Puede acordar otra fecha.</div></div></div>
      <label class="l" for="obs-e">Observaciones</label><textarea id="obs-e" name="obs" maxlength="800"></textarea>
      <div class="firmas" style="margin-top:16px"><?php $padForm('fti', 'Entrega: funcionario de TI', (string)($reg['nombre'] ?? $yo), ''); $padForm('fsol', 'Recibe: solicitante', $r['nombre'], $r['cedula']); ?></div>
      <div class="msg err" data-errform hidden></div>
      <div class="acts" style="display:flex"><button type="submit" class="b p big">Entregar y firmar acta</button></div>
    </form>
<?php elseif ($esTI && $r['estado'] === 'prestado'): ?>
    <form method="post" novalidate class="panel no-print firmable" id="f-devolver" style="margin-top:22px">
      <h3>Recibir devolución y firmar</h3>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="devolver"><input type="hidden" name="id" value="<?= e($r['id']) ?>">
      <label class="l">Condición en que se devuelven <span class="req">*</span></label>
      <div class="radios" style="margin-bottom:12px"><label><input type="radio" name="condicion" value="bien"> Completo y en buen estado</label><label><input type="radio" name="condicion" value="danos"> Con daños o faltantes</label></div>
      <div id="w-danos" hidden style="margin-bottom:12px"><label class="l" for="danos">Describa los daños o faltantes <span class="req">*</span></label><textarea id="danos" name="danos" maxlength="800"></textarea></div>
      <label class="l">Revisión al recibir</label>
      <div class="radios" style="flex-direction:column;gap:6px;margin-bottom:12px">
<?php foreach (PRES_VERIF_DEVOL as $i => $t): ?>        <label style="border-radius:8px"><input type="checkbox" name="verif[]" value="<?= $i ?>"> <?= e($t) ?></label>
<?php endforeach; ?>
      </div>
      <div class="grid" style="margin-bottom:12px"><div><label class="l" for="fecha_real">Fecha y hora en que se devolvió <span class="req">*</span></label><input type="datetime-local" id="fecha_real" name="fecha_real" required value="<?= date('Y-m-d\TH:i') ?>" max="<?= date('Y-m-d\TH:i', time() + 300) ?>"><div class="hint">Devolución acordada: <?= e(pres_fmt((int)$r['fin'])) ?>. Si lo devolvieron en otro momento, corrija la fecha.</div></div></div>
      <label class="l" for="obs-d">Observaciones</label><textarea id="obs-d" name="obs" maxlength="800"></textarea>
      <div class="firmas" style="margin-top:16px"><?php $padForm('fsol', 'Entrega: solicitante', $r['nombre'], $r['cedula']); $padForm('fti', 'Recibe: funcionario de TI', (string)($reg['nombre'] ?? $yo), (string)($en['firma_ti']['nombre'] ?? '') === (string)($reg['nombre'] ?? $yo) ? (string)$en['firma_ti']['cedula'] : ''); ?></div>
      <div class="msg err" data-errform hidden></div>
      <div class="acts" style="display:flex"><button type="submit" class="b p big">Registrar devolución</button></div>
    </form>
<?php endif; ?>
<script>
(()=>{
document.querySelectorAll('canvas[data-pad]').forEach(cv=>{
  const ctx=cv.getContext('2d');let dib=false,tiene=false,ult=null;
  const ajustar=()=>{const r=cv.getBoundingClientRect(),k=window.devicePixelRatio||1;const img=tiene?cv.toDataURL():null;cv.width=r.width*k;cv.height=r.height*k;ctx.setTransform(k,0,0,k,0,0);ctx.fillStyle='#fff';ctx.fillRect(0,0,r.width,r.height);ctx.lineWidth=2.2;ctx.lineCap='round';ctx.lineJoin='round';ctx.strokeStyle='#14263b';
    if(img){const im=new Image();im.onload=()=>ctx.drawImage(im,0,0,r.width,r.height);im.src=img}};
  ajustar();addEventListener('resize',ajustar);
  const pt=e=>{const r=cv.getBoundingClientRect();return[e.clientX-r.left,e.clientY-r.top]};
  cv.addEventListener('pointerdown',e=>{dib=true;ult=pt(e);cv.setPointerCapture(e.pointerId);ctx.beginPath();ctx.arc(ult[0],ult[1],1,0,7);ctx.fillStyle='#14263b';ctx.fill();tiene=true;marca()});
  cv.addEventListener('pointermove',e=>{if(!dib)return;const p=pt(e);ctx.beginPath();ctx.moveTo(ult[0],ult[1]);ctx.lineTo(p[0],p[1]);ctx.stroke();ult=p});
  ['pointerup','pointercancel','pointerleave'].forEach(t=>cv.addEventListener(t,()=>dib=false));
  const marca=()=>{cv.parentNode.querySelector('.x').style.display=tiene?'none':'';const f=cv.closest('form');f.querySelector('[name="'+cv.dataset.pad+'_img"]').value=tiene?cv.toDataURL('image/jpeg',0.85):''};
  cv.closest('form').querySelector('[data-borrar="'+cv.dataset.pad+'"]').onclick=()=>{ctx.save();ctx.setTransform(1,0,0,1,0,0);ctx.fillStyle='#fff';ctx.fillRect(0,0,cv.width,cv.height);ctx.restore();tiene=false;marca()};
  cv.addEventListener('pointerup',marca);
});
document.querySelectorAll('form.firmable').forEach(f=>f.addEventListener('submit',ev=>{
  let m='';f.querySelectorAll('[required]').forEach(x=>{const mal=!x.value.trim();x.style.borderColor=mal?'var(--err)':'';if(mal)m='Complete nombre, cédula y fecha de ambas firmas.'});
  f.querySelectorAll('canvas[data-pad]').forEach(cv=>{const v=f.querySelector('[name="'+cv.dataset.pad+'_img"]').value;f.querySelector('[data-err="'+cv.dataset.pad+'"]').textContent=v?'':'Falta la firma.';if(!v)m='Faltan firmas: ambas partes deben firmar.'});
  const c=f.querySelector('[name=condicion]');if(c){const s=f.querySelector('[name=condicion]:checked');if(!s)m='Indique la condición en que se devuelven los activos.';else if(s.value==='danos'&&!f.querySelector('#danos').value.trim())m='Describa los daños o faltantes.'}
  const b=f.querySelector('[data-errform]');if(m){ev.preventDefault();b.textContent=m;b.hidden=false}else{b.hidden=true;f.querySelector('button[type=submit]').disabled=true}}));
document.querySelectorAll('[name=condicion]').forEach(x=>x.onchange=()=>document.querySelector('#w-danos').hidden=x.value!=='danos'||!x.checked);
<?php if (isset($_GET['listo'])): ?>
addEventListener('load',()=>avisoExito({titulo:<?= jsn($_GET['listo'] === 'entrega' ? 'Activos entregados' : 'Devolución registrada') ?>,texto:<?= jsn(($_GET['listo'] === 'entrega' ? 'El acta ' . $r['id'] . ' quedó firmada y los activos figuran como prestados hasta el ' . pres_fmt($r['fin']) . '.' : 'El acta ' . $r['id'] . ' quedó cerrada y los activos vuelven a estar disponibles.') . ($correoMsg !== '' ? "\n" . $correoMsg : '')) ?>,botones:[{t:'Descargar PDF',p:true,f:()=>location.href='?pdf='+encodeURIComponent(<?= jsn($r['id']) ?>)+'&descargar=1'},{t:'Volver a la lista',f:()=>location.href='prestamos.php'},{t:'Cerrar'}]}));
<?php elseif (isset($_GET['nueva'])): ?>
addEventListener('load',()=>avisoExito({titulo:'Reserva registrada',texto:<?= jsn('Acta ' . $r['id'] . '. Retire los activos en el Departamento de TI el ' . pres_fmt($r['inicio']) . '; ahí se le entregarán y firmarán el acta.' . ($correoMsg !== '' ? "\n" . $correoMsg : '')) ?>,botones:[{t:'Descargar comprobante (PDF)',p:true,f:()=>location.href='?pdf='+encodeURIComponent(<?= jsn($r['id']) ?>)+'&doc=reserva&descargar=1'},{t:'Ver mi reserva'},{t:'Ir a mis reservas',f:()=>location.href='prestamos.php'}]}));
<?php endif; ?>
})();
</script>
<?php
/* ======================= CATÁLOGO ======================= */
elseif ($v === 'catalogo'):
    $ed = isset($_GET['editar']) ? ($activos[(string)$_GET['editar']] ?? null) : null;
    $abrir = $ed || isset($_GET['nuevo']);
    $ocupadoAhora = [];
    foreach ($reservas as $r) if ($o = pres_ocupa($r)) if ($o[0] <= time()) foreach ($r['activos'] as $aid) $ocupadoAhora[$aid] = $r;
?>
    <details class="panel" <?= $abrir ? 'open' : '' ?> id="form-activo">
      <summary class="b p" style="list-style:none;display:inline-flex"><?= $ed ? 'Editar activo N.° ' . e($ed['codigo']) : '+ Agregar activo' ?></summary>
      <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="activo_guardar"><input type="hidden" name="aid" value="<?= e($ed['id'] ?? '') ?>">
        <div class="grid grid3">
          <div><label class="l" for="codigo">N.° de activo (placa) <span class="req">*</span></label><input type="text" id="codigo" name="codigo" maxlength="40" required value="<?= e($ed['codigo'] ?? '') ?>"></div>
          <div><label class="l" for="tipo">Tipo <span class="req">*</span></label><select id="tipo" name="tipo"><?php foreach (PRES_TIPOS as $t): ?><option<?= ($ed['tipo'] ?? '') === $t ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
          <div><label class="l" for="serie">N.° de serie</label><input type="text" id="serie" name="serie" maxlength="60" value="<?= e($ed['serie'] ?? '') ?>"></div>
          <div><label class="l" for="marca">Marca</label><input type="text" id="marca" name="marca" maxlength="60" value="<?= e($ed['marca'] ?? '') ?>"></div>
          <div><label class="l" for="modelo">Modelo</label><input type="text" id="modelo" name="modelo" maxlength="60" value="<?= e($ed['modelo'] ?? '') ?>"></div>
          <div><label class="l" for="descripcion">Descripción breve</label><input type="text" id="descripcion" name="descripcion" maxlength="150" placeholder="Ej. 3500 lúmenes, HDMI" value="<?= e($ed['descripcion'] ?? '') ?>"></div>
          <div class="full"><label class="l" for="accesorios">Accesorios que se entregan con el activo</label><input type="text" id="accesorios" name="accesorios" maxlength="200" placeholder="Ej. cable de poder, cable HDMI, control remoto, estuche" value="<?= e($ed['accesorios'] ?? '') ?>"></div>
        </div>
        <div class="acts"><a class="b" href="?v=catalogo">Cancelar</a><button class="b p big"><?= $ed ? 'Guardar cambios' : 'Agregar al catálogo' ?></button></div>
      </form>
    </details>
    <div class="bar"><h2>Activos prestables (<?= count($activos) ?>)</h2></div>
    <div class="tw"><table>
      <thead><tr><th>N.° de activo</th><th>Tipo</th><th>Marca / modelo</th><th>Serie</th><th>Accesorios</th><th>Ahora</th><th></th></tr></thead>
      <tbody>
<?php foreach ($activos as $a): $oc = $ocupadoAhora[$a['id']] ?? null; ?>
        <tr class="<?= empty($a['activo']) ? 'off' : '' ?>">
          <td><strong><?= e($a['codigo']) ?></strong></td><td><?= e($a['tipo']) ?><?php if ($a['descripcion'] !== ''): ?><small><?= e($a['descripcion']) ?></small><?php endif; ?></td>
          <td><?= e(trim($a['marca'] . ' ' . $a['modelo']) ?: '—') ?></td><td><?= e($a['serie'] ?: '—') ?></td><td><?= e($a['accesorios'] ?: '—') ?></td>
          <td><?php if (empty($a['activo'])): ?><span class="tag can">Fuera de préstamo</span><?php elseif ($oc): ?><a class="tag <?= $oc['estado'] === 'prestado' ? 'pre' : 'res' ?>" href="?v=ver&amp;id=<?= urlencode($oc['id']) ?>" style="text-decoration:none"><?= $oc['estado'] === 'prestado' ? 'Prestado' : 'Reservado' ?> · <?= e($oc['cons'] . '-' . $oc['yr']) ?></a><?php else: ?><span class="tag disp">Disponible</span><?php endif; ?></td>
          <td style="white-space:nowrap;text-align:right"><a class="b" href="?v=catalogo&amp;editar=<?= urlencode($a['id']) ?>">Editar</a>
            <form method="post" class="inl"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="accion" value="activo_estado"><input type="hidden" name="aid" value="<?= e($a['id']) ?>"><button class="b<?= empty($a['activo']) ? '' : ' danger' ?>"><?= empty($a['activo']) ? 'Habilitar' : 'Retirar' ?></button></form></td>
        </tr>
<?php endforeach; ?>
      </tbody></table>
<?php if (!$activos): ?>      <div class="vacio">Agregue los proyectores, computadoras, herramientas y accesorios que el Departamento de TI presta.</div><?php endif; ?>
    </div>
    <p class="aviso-linea" style="margin-top:14px"><strong>Retirar</strong> saca el activo de la lista de reservas (por ejemplo, si está en reparación o se dio de baja) sin borrar su historial.</p>
<?php endif; ?>
  </div>
</main>
<?php if ($esTI) { require_once __DIR__ . '/../actas-app/tecnicos.php'; tecnicos_js((string)($reg['nombre'] ?? '')); } ?>
<?php require __DIR__ . '/../actas-app/aviso.php'; ?>
</body>
</html>

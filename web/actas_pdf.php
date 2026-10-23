<?php
/**
 * Descarga en PDF de las actas guardadas en el servidor.
 *   actas_pdf.php?e=<N.° entrega>            → solo el Acta de Entrega
 *   actas_pdf.php?d=<N.° devolución>         → solo el Acta de Devolución
 *   actas_pdf.php?e=<N.°>&d=<N.°>            → ambas en un mismo PDF (primero la entrega)
 *   actas_pdf.php?e=<N.°>&f=<id formulario>&r=<registro>  → entrega + un registro de un formulario creado (p. ej. Preparación de equipo)
 *   &ver=1 → abre en el navegador en vez de descargar
 */
declare(strict_types=1);
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/registros.php';
require __DIR__ . '/../actas-app/formularios.php';
require __DIR__ . '/../actas-app/actas_pdf.php';

$yo = actas_requerir_login();
$reg = actas_registro($yo);
function fallar(int $c, string $m): never {
    http_response_code($c);
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Actas en PDF</title>'
       . '<body style="font-family:Segoe UI,system-ui,sans-serif;background:#edf1f5;color:#17212e;display:grid;place-items:center;min-height:100vh;margin:0;padding:16px">'
       . '<div style="max-width:460px;background:#fff;border-radius:12px;border-top:4px solid #c08a1e;box-shadow:0 10px 30px -12px rgba(18,52,86,.3);padding:28px 30px">'
       . '<h2 style="margin:0 0 8px;color:#123456">No se pudo generar el PDF</h2><p>' . e($m) . '</p><p><a href="javascript:history.back()">Volver</a></p></div>';
    exit;
}
$actas = []; $nombres = [];
foreach (['e' => 'acta_entrega', 'd' => 'acta_devolucion'] as $p => $form) {
    $id = trim((string)($_GET[$p] ?? ''));
    if ($id === '') continue;
    if (!actas_puede($reg, $form)) fallar(403, 'No tiene acceso al ' . ACTA_TIPOS[$form][0] . '.');
    $r = reg_id_valido($id) ? reg_leer(reg_carpeta($form), $id) : null;
    if (!$r) fallar(404, 'El ' . ACTA_TIPOS[$form][0] . ' N.° ' . $id . ' no existe (quizá se eliminó).');
    $actas[] = [$form, $id, (array)$r['datos']];
    $nombres[] = ($p === 'e' ? 'Entrega-' : 'Devolucion-') . preg_replace('/[^A-Za-z0-9-]/', '', $id);
}
// Registro de un formulario del constructor (va después del acta de entrega y antes de la devolución)
$fid = trim((string)($_GET['f'] ?? '')); $rid = trim((string)($_GET['r'] ?? ''));
if ($fid !== '' && $rid !== '') {
    $def = preg_match('/^[a-z0-9-]{1,80}$/', $fid) ? form_cargar($fid) : null;
    if (!$def) fallar(404, 'El formulario ya no existe.');
    if (!actas_puede($reg, 'f:' . $fid)) fallar(403, 'No tiene acceso al formulario «' . $def['titulo'] . '».');
    $r = reg_id_valido($rid) ? reg_leer(reg_carpeta('f:' . $fid), $rid) : null;
    if (!$r) fallar(404, 'El registro ' . $rid . ' de «' . $def['titulo'] . '» no existe (quizá se eliminó).');
    $doc = ['form', $def, $rid, (array)$r['datos']];
    $pos = ($actas && $actas[0][0] === 'acta_entrega') ? 1 : 0;
    array_splice($actas, $pos, 0, [$doc]);
    array_splice($nombres, $pos, 0, [preg_replace('/[^A-Za-z0-9]+/', '', ucwords(strtr($def['titulo'], ['ó' => 'o', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ú' => 'u', 'ñ' => 'n']))) . (!empty($def['numeracion']) ? '-' . preg_replace('/[^A-Za-z0-9-]/', '', $rid) : '')]);
}
// Bitácora de Simulacro (siempre sola)
$bid = trim((string)($_GET['b'] ?? ''));
if ($bid !== '') {
    if (!actas_puede($reg, 'bitacora')) fallar(403, 'No tiene acceso a la Bitácora de Simulacro.');
    $r = reg_id_valido($bid) ? reg_leer('bitacora', $bid) : null;
    if (!$r) fallar(404, 'La bitácora del ' . $bid . ' no existe (quizá se eliminó).');
    $actas = [['bitacora', $bid, (array)$r['datos']]];
    $nombres = ['Bitacora_Simulacro_' . preg_replace('/[^0-9]+/', '-', $bid)];
}
if (!$actas) fallar(400, 'Indique el acta de entrega, de devolución o ambas.');
@set_time_limit(60);
$bin = actas_pdf($actas);
$quien = preg_replace('/[^A-Za-z0-9]+/', '_', strtr((string)($actas[0][0] === 'form' ? ($actas[0][3]['v']['cusu'] ?? '') : ($actas[0][0] === 'bitacora' ? ($actas[0][2]['tec'] ?? '') : ($actas[0][2]['nombre'] ?? ''))), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N', 'ü' => 'u']));
$archivo = (count($actas) === 1 && in_array($actas[0][0], ['form', 'bitacora'], true) ? '' : 'Acta_') . implode('_', $nombres) . ($quien !== '' ? '_' . trim($quien, '_') : '') . '.pdf';
actas_log("actas_pdf: $yo descargó " . implode(' + ', $nombres));
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (!empty($_GET['ver']) ? 'inline' : 'attachment') . '; filename="' . $archivo . '"');
header('Content-Length: ' . strlen($bin));
header('Cache-Control: private, no-store');
echo $bin;

<?php
/**
 * Búsqueda en la base de datos de colaboradores y equipos (JSON), para autollenar las actas.
 *   GET ?q=<texto>  → hasta 12 coincidencias por nombre, cédula, código, usuario, correo, hostname o serie
 */
declare(strict_types=1);
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/inventario.php';

header('Content-Type: application/json; charset=utf-8');
actas_iniciar_sesion();
$yo = (string)($_SESSION['usuario'] ?? '');
$reg = $yo !== '' ? actas_registro($yo) : null;
$valida = $yo !== '' && actas_activo($reg) && (time() - (int)($_SESSION['ultimo'] ?? 0)) <= ACTAS_INACTIVIDAD_SEG && (int)($reg['cambio'] ?? 0) <= (int)($_SESSION['desde'] ?? 0);
if (!$valida) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'La sesión venció.']); exit; }
if (!actas_puede($reg, 'acta_entrega') && !actas_puede($reg, 'acta_devolucion') && !actas_puede($reg, 'datos')) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Sin acceso.']); exit; }
$_SESSION['ultimo'] = time();
$inv = inv_cargar();
echo json_encode(['ok' => true, 'cargado' => !empty($inv['empleados']) || !empty($inv['equipos']), 'resultados' => inv_buscar($inv, (string)($_GET['q'] ?? ''))], JSON_UNESCAPED_UNICODE);

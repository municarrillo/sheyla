<?php
/**
 * API de registros (JSON). Guarda en el servidor lo que se llena en las actas, la bitácora y los formularios creados.
 *   GET  ?f=<form>&accion=lista          → registros sin imágenes (firmas)
 *   GET  ?f=<form>&accion=ver&id=<id>    → un registro completo
 *   POST {csrf, f, accion:'guardar', id, datos, reemplazar, original}
 *   POST {csrf, f, accion:'eliminar', id}
 */
declare(strict_types=1);
require __DIR__ . '/../actas-app/auth.php';
require __DIR__ . '/../actas-app/formularios.php';
require __DIR__ . '/../actas-app/registros.php';

header('Content-Type: application/json; charset=utf-8');
function responder(array $d, int $codigo = 200): never { http_response_code($codigo); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

actas_iniciar_sesion();
$yo = (string)($_SESSION['usuario'] ?? '');
$reg = $yo !== '' ? actas_registro($yo) : null;
if ($yo === '' || !actas_activo($reg) || (time() - (int)($_SESSION['ultimo'] ?? 0)) > ACTAS_INACTIVIDAD_SEG || (int)($reg['cambio'] ?? 0) > (int)($_SESSION['desde'] ?? 0))
    responder(['ok' => false, 'error' => 'La sesión venció. Vuelva a iniciar sesión (copie lo que lleva escrito si lo necesita).'], 401);
$_SESSION['ultimo'] = time();

$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$in = $post ? json_decode((string)file_get_contents('php://input'), true) : $_GET;
if (!is_array($in)) responder(['ok' => false, 'error' => 'Solicitud inválida.'], 400);
if ($post && !hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($in['csrf'] ?? ''))) responder(['ok' => false, 'error' => 'La página expiró. Recárguela.'], 403);

$form = (string)($in['f'] ?? '');
$dir = reg_carpeta($form);
if ($dir === null || (str_starts_with($form, 'f:') && form_cargar(substr($form, 2)) === null)) responder(['ok' => false, 'error' => 'Formulario desconocido.'], 404);
if (!actas_puede($reg, $form)) responder(['ok' => false, 'error' => 'No tiene acceso a este formulario.'], 403);
$esAdmin = actas_es_admin($reg);
$accion = (string)($in['accion'] ?? '');
$puedeBorrar = fn(array $r) => $esAdmin || ($r['creado_por'] ?? '') === $yo;

switch ($accion) {
    case 'lista':
        $out = [];
        foreach (reg_listar($dir) as $r) {
            $out[] = ['id' => $r['id'], 'datos' => reg_sin_imagenes($r['datos']), 'creado' => $r['creado'] ?? 0, 'creado_por' => $r['creado_por'] ?? '',
                      'modificado' => $r['modificado'] ?? 0, 'modificado_por' => $r['modificado_por'] ?? '', 'puede_eliminar' => $puedeBorrar($r)];
        }
        usort($out, fn($a, $b) => $b['creado'] <=> $a['creado']);
        responder(['ok' => true, 'registros' => $out]);

    case 'ver':
        $id = (string)($in['id'] ?? '');
        $r = reg_id_valido($id) ? reg_leer($dir, $id) : null;
        if (!$r) responder(['ok' => false, 'error' => 'El registro ya no existe (quizá otra persona lo eliminó).'], 404);
        $r['puede_eliminar'] = $puedeBorrar($r);
        responder(['ok' => true, 'registro' => $r]);

    case 'guardar':
        if (!$post) responder(['ok' => false, 'error' => 'Método no permitido.'], 405);
        $id = trim((string)($in['id'] ?? '')); $orig = trim((string)($in['original'] ?? ''));
        if (!reg_id_valido($id) || ($orig !== '' && !reg_id_valido($orig))) responder(['ok' => false, 'error' => 'Número de registro inválido.'], 400);
        if (!is_array($in['datos'] ?? null)) responder(['ok' => false, 'error' => 'No hay datos para guardar.'], 400);
        $tam = strlen((string)json_encode($in['datos']));
        if ($tam > 3_000_000) responder(['ok' => false, 'error' => 'El registro es demasiado grande para guardarlo.'], 413);
        $lk = reg_bloquear($dir);
        try {
            $previo = reg_leer($dir, $id);
            $editando = $orig !== '' ? reg_leer($dir, $orig) : null;
            // Ya existe otro registro con ese número y no se pidió reemplazarlo (ni es el mismo que se está editando)
            if ($previo && empty($in['reemplazar']) && $orig !== $id)
                responder(['ok' => false, 'existe' => true, 'error' => "Ya existe un registro N.° $id."]);
            $base = $editando ?? $previo;
            $doc = ['id' => $id, 'form' => $form, 'datos' => $in['datos'],
                    'creado' => $base['creado'] ?? time(), 'creado_por' => $base['creado_por'] ?? $yo,
                    'modificado' => $base ? time() : 0, 'modificado_por' => $base ? $yo : ''];
            if (!empty($in['migrado']) && !$base) { $doc['migrado'] = true; }
            if (!reg_escribir(reg_archivo($dir, $id), $doc)) responder(['ok' => false, 'error' => 'No se pudo guardar. Revise los permisos de ' . REG_DIR . '.'], 500);
            if ($editando && $orig !== $id) @unlink(reg_archivo($dir, $orig));   // al editar se le cambió el número
        } finally { flock($lk, LOCK_UN); fclose($lk); }
        actas_log("registros: $yo " . ($base ? 'actualizó' : 'guardó') . " $form $id" . ($editando && $orig !== $id ? " (antes $orig)" : ''));
        responder(['ok' => true, 'id' => $id, 'actualizado' => (bool)$base]);

    case 'eliminar':
        if (!$post) responder(['ok' => false, 'error' => 'Método no permitido.'], 405);
        $id = (string)($in['id'] ?? '');
        if (!reg_id_valido($id)) responder(['ok' => false, 'error' => 'Registro inválido.'], 400);
        $lk = reg_bloquear($dir);
        try {
            $r = reg_leer($dir, $id);
            if (!$r) responder(['ok' => false, 'error' => 'El registro ya no existe.'], 404);
            if (!$puedeBorrar($r)) responder(['ok' => false, 'error' => 'Solo un administrador o quien lo registró puede eliminarlo.'], 403);
            if (!is_dir($dir . '/_eliminados')) @mkdir($dir . '/_eliminados', 0770, true);
            $r['eliminado'] = time(); $r['eliminado_por'] = $yo;
            reg_escribir($dir . '/_eliminados/' . bin2hex($id) . '__' . time() . '.json', $r);
            @unlink(reg_archivo($dir, $id));
        } finally { flock($lk, LOCK_UN); fclose($lk); }
        actas_log("registros: $yo eliminó $form $id");
        responder(['ok' => true]);
}
responder(['ok' => false, 'error' => 'Acción no reconocida.'], 400);

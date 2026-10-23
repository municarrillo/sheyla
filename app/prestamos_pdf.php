<?php
/**
 * Documentos PDF del préstamo de activos:
 *   reserva     → Comprobante de reserva (al reservar)
 *   entrega     → Acta de préstamo (al entregar, con firmas)
 *   devolucion  → Acta de préstamo y devolución (al devolver, con todas las firmas)
 * Cada documento se guarda en la tabla «pres_pdf» en el momento del evento, como registro.
 */
declare(strict_types=1);
require_once __DIR__ . '/pdf.php';

const PRES_DOCS = ['reserva' => 'Comprobante de reserva', 'entrega' => 'Acta de préstamo de activos', 'devolucion' => 'Acta de préstamo y devolución de activos'];

function pres_doc_actual(array $r): string { return $r['estado'] === 'devuelto' ? 'devolucion' : ($r['estado'] === 'prestado' ? 'entrega' : 'reserva'); }
/** PDF guardado en la base (tabla «pres_pdf») o null. */
function pres_pdf_guardado(string $id, string $doc): ?string { $v = db_valor('SELECT pdf FROM pres_pdf WHERE id = ? AND doc = ?', [$id, $doc]); return $v === null ? null : (string)$v; }

function pres_pdf(array $r, array $activos, string $doc): string {
    $pdf = new PdfDoc();
    $titulo = PRES_DOCS[$doc] ?? PRES_DOCS['reserva'];
    $pdf->titulo = $titulo . ' ' . $r['id'] . ' · Municipalidad de Carrillo';
    $escudo = $pdf->jpeg((string)base64_decode(PDF_ESCUDO_JPG));
    $M = PdfHoja::M; $AN = PdfHoja::AN;
    $enc = function (PdfHoja $h, bool $sig = false) use ($pdf, $escudo, $titulo, $r, $M, $AN) {
        if ($sig) {   // páginas siguientes: encabezado compacto
            $pdf->texto($M, 30, 'Municipalidad de Carrillo · ' . $titulo, 8.5, true, PdfHoja::NAVY);
            $pdf->texto($M, 30, 'N.° ' . $r['id'], 8.5, true, PdfHoja::NAVY, 'der', $AN);
            $pdf->linea($M, 44, $M + $AN, 44, PdfHoja::GOLD, 1.2);
            $h->y = 56; return;
        }
        $pdf->rect(0, 0, PdfDoc::W, 6, PdfHoja::NAVY);
        $pdf->rect(PdfDoc::W * 0.76, 0, PdfDoc::W * 0.24, 6, PdfHoja::GOLD);
        if ($escudo) $pdf->imagen($escudo, $M, 24, 62, 62 * $pdf->proporcion($escudo));
        $pdf->texto($M + 76, 30, 'MUNICIPALIDAD DE CARRILLO', 8, true, PdfHoja::MUTE);
        $tam = 17; while ($tam > 11 && PdfDoc::ancho($titulo, $tam, true) > $AN - 76 - 130) $tam -= 0.5;
        $pdf->texto($M + 76, 42 + (17 - $tam) / 2, $titulo, $tam, true, PdfHoja::NAVY);
        $pdf->texto($M + 76, 64, 'Departamento de Tecnologías de Información', 9, false, PdfHoja::MUTE);
        $pdf->texto($M + 76, 76, 'Emitido el ' . date('d/m/Y h:i a'), 7.5, false, PdfHoja::MUTE);
        $pdf->rect($M + $AN - 118, 28, 118, 44, [245, 247, 250], PdfHoja::LINE);
        $pdf->texto($M + $AN - 118, 34, 'N.° DE ACTA', 7, true, PdfHoja::MUTE, 'centro', 118);
        $pdf->texto($M + $AN - 118, 46, $r['id'], 14, true, PdfHoja::NAVY, 'centro', 118);
        $pdf->linea($M, 96, $M + $AN, 96, PdfHoja::GOLD, 1.5);
        $h->y = 106;
    };
    $h = new PdfHoja($pdf, $enc);
    $en = $r['entrega'] ?? null; $dv = $r['devolucion'] ?? null;
    if ($doc === 'reserva') { $en = null; $dv = null; }
    if ($doc === 'entrega') $dv = null;

    $h->seccion('Datos del solicitante');
    $h->datos(['Nombre completo' => $r['nombre'], 'Cédula' => $r['cedula'], 'Departamento' => $r['departamento'],
               'Cargo' => $r['cargo'], 'Correo' => $r['correo'], 'Extensión o teléfono' => $r['telefono'] ?: '—']);

    $h->seccion('Detalle del préstamo');
    $fins = (int)($r['fin_solicitada'] ?? $r['fin']);
    $pares = ['Fecha de la reserva' => pres_fmt((int)$r['creado']), 'Retiro programado' => pres_fmt((int)$r['inicio']),
              'Devolución estimada por el solicitante' => pres_fmt($fins)];
    if ($en || (int)$r['fin'] !== $fins) $pares['Devolución acordada'] = pres_fmt((int)$r['fin']);
    if ($en) $pares['Entregado el'] = pres_fmt((int)$en['at']);
    if ($dv) $pares['Devuelto el'] = pres_fmt((int)$dv['at']);
    $pares += ['Lugar de uso' => $r['uso'] === 'fuera' ? 'Fuera de la Municipalidad' : 'Dentro de la Municipalidad',
               'Lugar o dirección' => $r['lugar'], 'Autoriza la salida' => $r['uso'] === 'fuera' ? $r['autoriza'] : 'No aplica'];
    $h->datos($pares);
    $h->datos(['Motivo o actividad' => $r['motivo']], 1);
    if (!empty($r['cambios_fin'])) {
        $h->parrafo('Cambios en la fecha de devolución:', 8, PdfHoja::MUTE, true);
        foreach ($r['cambios_fin'] as $c)
            $h->parrafo('• ' . pres_fmt((int)$c['at']) . ' — ' . $c['por'] . ' cambió la devolución del ' . pres_fmt((int)$c['de']) . ' al ' . pres_fmt((int)$c['a']) . ($c['motivo'] !== '' ? '. Motivo: ' . $c['motivo'] : '') . '.', 8, PdfHoja::INK, false, 6);
        $h->y += 4;
    }

    $h->seccion($doc === 'reserva' ? 'Activos reservados' : 'Activos prestados');
    $filas = [];
    foreach ($r['activos'] as $k => $aid) {
        $a = $activos[$aid] ?? ['codigo' => '—', 'tipo' => 'Activo eliminado del catálogo', 'marca' => '', 'modelo' => '', 'serie' => '', 'accesorios' => ''];
        $filas[] = [(string)($k + 1), $a['codigo'], $a['tipo'] . (($a['descripcion'] ?? '') !== '' ? ' · ' . $a['descripcion'] : ''),
                    trim(($a['marca'] ?? '') . ' ' . ($a['modelo'] ?? '')) ?: '—', ($a['serie'] ?? '') ?: '—',
                    $en ? ($en['detalle'][$aid] ?? '') : (($a['accesorios'] ?? '') !== '' ? 'Incluye: ' . $a['accesorios'] : '—')];
    }
    $h->tabla(['#', 'N.° activo', 'Tipo', 'Marca / modelo', 'Serie', $en ? 'Accesorios y estado al entregar' : 'Accesorios'], $filas, [4, 11, 20, 18, 14, 33], 1);

    $h->seccion('Condiciones del préstamo');
    $h->parrafo(PRES_TERMINOS, 8, PdfHoja::INK);
    $h->y += 2;
    $h->parrafo('El solicitante aceptó estas condiciones al registrar la reserva el ' . pres_fmt((int)$r['creado']) . '.', 8, PdfHoja::MUTE, true);

    if ($doc === 'reserva') {
        $h->seccion('Próximo paso');
        $h->parrafo('Preséntese en el Departamento de Tecnologías de Información el ' . pres_fmt((int)$r['inicio'])
            . ' para retirar los activos. Ahí se verificará su estado y ambas partes firmarán el acta de préstamo. '
            . 'Si ya no necesita los activos, cancele la reserva en el sistema para que queden disponibles para otras personas.', 9);
        if ($r['estado'] === 'cancelada') $h->parrafo('Esta reserva fue cancelada el ' . pres_fmt((int)$r['cancelada']['at']) . '.', 9, [179, 38, 30], true);
    }
    if ($en) {
        $h->seccion('Entrega · ' . pres_fmt((int)$en['at']) . ' · entregado por ' . $en['por']);
        $h->casillas($en['verif']);
        if (($en['obs'] ?? '') !== '') $h->parrafo('Observaciones: ' . $en['obs'], 8.5);
        $h->y += 4;
        $h->firmas([['Entrega: funcionario de TI', $en['firma_ti']], ['Recibe: solicitante', $en['firma_sol']]]);
    }
    if ($dv) {
        $h->seccion('Devolución · ' . pres_fmt((int)$dv['at']) . ' · recibido por ' . $dv['por']);
        $h->datos(['Condición' => $dv['condicion'] === 'bien' ? 'Completo y en buen estado' : 'Con daños o faltantes: ' . $dv['danos']], 1);
        $h->casillas($dv['verif']);
        if (($dv['obs'] ?? '') !== '') $h->parrafo('Observaciones: ' . $dv['obs'], 8.5);
        $h->y += 4;
        $h->firmas([['Entrega: solicitante', $dv['firma_sol']], ['Recibe: funcionario de TI', $dv['firma_ti']]]);
    }
    return $pdf->salida();
}

/** Genera y guarda el documento como registro; devuelve el contenido. */
function pres_pdf_guardar(array $r, array $activos, string $doc): string {
    $bin = pres_pdf($r, $activos, $doc);
    try {
        $st = actas_db()->prepare('REPLACE INTO pres_pdf (id, doc, pdf, creado) VALUES (?, ?, ?, ?)');
        $st->bindValue(1, (string)$r['id']); $st->bindValue(2, $doc); $st->bindValue(3, $bin, PDO::PARAM_LOB); $st->bindValue(4, time(), PDO::PARAM_INT);
        $st->execute();
    } catch (Throwable $e) { error_log('[actas-tic] pres_pdf: ' . $e->getMessage()); }
    return $bin;
}

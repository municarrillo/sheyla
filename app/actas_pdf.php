<?php
/**
 * PDF de las Actas de Entrega y Devolución de equipo de cómputo (una o varias en un mismo archivo).
 * Cada acta empieza en una página nueva.
 */
declare(strict_types=1);
require_once __DIR__ . '/pdf.php';

const ACTA_TIPOS = [
    'acta_entrega'    => ['Acta de Entrega de Equipos de Cómputo', 'Fecha de entrega', 'Asignación de tipo de cómputo', 'Recibe: firma del colaborador', 'Entrega: firma del funcionario de TIC'],
    'acta_devolucion' => ['Acta de Devolución de Equipo de Cómputo', 'Fecha de devolución', 'Equipo devuelto', 'Entrega: firma del colaborador', 'Recibe: firma del funcionario de TIC'],
];
const ACTA_TERMINOS = 'Importante: Certifico que los elementos detallados en el presente documento me han sido entregados para mi cuidado con el propósito de cumplir con las tareas y asignaciones propias de mi cargo en la institución, siendo estos de mi única y exclusiva responsabilidad. Me comprometo a usar correctamente los recursos, y utilizarlos solo para los fines establecidos, lo que conlleva a no alterar la configuración establecida y no instalar ni permitir la instalación de software por lo personal ajeno al Departamento de Tecnologías de Información.'
    . "\nSoy consciente de mi responsabilidad de cumplir con el marco normativo en materia de TIC existente y mi compromiso para cumplir con lo estipulado en el Reglamento para la Gestión, Control y Aplicación de las Tecnologías de Información: Artículo 26.— Obligaciones de los funcionarios respecto al hardware y software. Artículo 27.— Del Acceso y uso de Internet: Apartado 27.1. Uso legítimo de la conexión a Internet y 27.4. Prohibiciones de uso en la conexión a Internet. En caso de incumplimiento o denuncia por violación a las normas soy consciente de que deberé afrontar intervenciones o auditorías (sobre el hardware y software) con mi participación para esclarecer los hechos acusados.";

function acta_fecha(string $f): string { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $f, $m) ? "$m[3]/$m[2]/$m[1]" : $f; }

/** Encabezado institucional (primera página de cada documento) y compacto en las siguientes. */
function acta_pdf_encabezado(PdfDoc $pdf, ?string $escudo, string $titulo, string $id, string $subtitulo = '', string $etNum = 'N.° DE ACTA'): Closure {
    $M = PdfHoja::M; $AN = PdfHoja::AN;
    return function (PdfHoja $h, bool $sig = false) use ($pdf, $escudo, $titulo, $id, $subtitulo, $etNum, $M, $AN) {
            if ($sig) {
                $pdf->texto($M, 30, 'Municipalidad de Carrillo · ' . $titulo, 8.5, true, PdfHoja::NAVY);
                $pdf->texto($M, 30, ($id !== '' ? 'N.° ' . $id : ''), 8.5, true, PdfHoja::NAVY, 'der', $AN);
                $pdf->linea($M, 44, $M + $AN, 44, PdfHoja::GOLD, 1.2);
                $h->y = 56; return;
            }
            $pdf->rect(0, 0, PdfDoc::W, 6, PdfHoja::NAVY);
            $pdf->rect(PdfDoc::W * 0.76, 0, PdfDoc::W * 0.24, 6, PdfHoja::GOLD);
            if ($escudo) $pdf->imagen($escudo, $M, 24, 62, 62 * $pdf->proporcion($escudo));
            $pdf->texto($M + 76, 30, 'MUNICIPALIDAD DE CARRILLO', 8, true, PdfHoja::MUTE);
            $tam = 17; while ($tam > 11 && PdfDoc::ancho($titulo, $tam, true) > $AN - 76 - 130) $tam -= 0.5;
            $pdf->texto($M + 76, 42 + (17 - $tam) / 2, $titulo, $tam, true, PdfHoja::NAVY);
            $pdf->texto($M + 76, 64, $subtitulo !== '' ? $subtitulo : 'Departamento de Tecnologías de Información', 9, false, PdfHoja::MUTE);
            if ($id !== '') {
                $pdf->rect($M + $AN - 118, 28, 118, 44, [245, 247, 250], PdfHoja::LINE);
                $pdf->texto($M + $AN - 118, 34, $etNum, 7, true, PdfHoja::MUTE, 'centro', 118);
                $pdf->texto($M + $AN - 118, 46, $id, 14, true, PdfHoja::NAVY, 'centro', 118);
            }
            $pdf->linea($M, 96, $M + $AN, 96, PdfHoja::GOLD, 1.5);
            $h->y = 106;
        };
}

/** $docs: lista de [tipo ('acta_entrega'|'acta_devolucion'), id, datos] o ['form', definición, id, datos]. */
function actas_pdf(array $docs): string {
    $pdf = new PdfDoc();
    $nombres = array_map(fn($x) => $x[0] === 'form' ? $x[1]['titulo'] : ($x[0] === 'bitacora' ? BITACORA_TITULO : ACTA_TIPOS[$x[0]][0]), $docs);
    $pdf->titulo = (count($docs) > 1 ? implode(' y ', array_unique(array_map(fn($n) => preg_replace('/ de Equipos? de Cómputo$/', '', $n), $nombres))) : $nombres[0]) . ' · Municipalidad de Carrillo';
    $escudo = $pdf->jpeg((string)base64_decode(PDF_ESCUDO_JPG));
    foreach (array_values($docs) as $k => $doc) {
        if ($k > 0) $pdf->nuevaPagina();
        if ($doc[0] === 'form') { form_pdf_hoja($pdf, $escudo, $doc[1], $doc[2], $doc[3]); continue; }
        if ($doc[0] === 'bitacora') { bitacora_pdf_hoja($pdf, $escudo, $doc[1], $doc[2]); continue; }
        [$tipo, $id, $d] = $doc;
        [$titulo, $etFecha, $etEquipo, $f1, $f2] = ACTA_TIPOS[$tipo];
        $v = fn(string $c) => trim((string)($d[$c] ?? ''));
        $h = new PdfHoja($pdf, acta_pdf_encabezado($pdf, $escudo, $titulo, $id));

        $h->seccion('Datos del colaborador');
        $h->datos(['Nombre completo' => $v('nombre') ?: '—', 'Cargo' => $v('cargo') ?: '—', 'Dependencia' => $v('dep') ?: '—',
                   'Usuario de red' => $v('usr') ?: '—', 'Correo institucional' => $v('correo') ?: '—', $etFecha => acta_fecha($v('fecha')) ?: '—',
                   'Tipo de equipo' => $v('tipo') ?: '—']);

        $filas = [];
        foreach ((array)($d['eq'] ?? []) as $r) {
            $c = array_pad(explode('|', (string)$r), 5, '');
            if (trim(implode('', $c)) === '') continue;
            $filas[] = array_merge([(string)(count($filas) + 1)], array_map('trim', array_slice($c, 0, 5)));
        }
        $h->seccion($etEquipo);
        if ($filas) $h->tabla(['Ítem', 'Descripción', 'Marca', 'Modelo', 'Serial', 'N.° activo'], $filas, [0.45, 2.4, 1.1, 1.6, 1.4, 1.05]);
        else $h->parrafo('Sin equipos registrados.', 9, PdfHoja::MUTE);

        $sw = [];
        foreach ((array)($d['sw'] ?? []) as $r) {
            $c = array_pad(explode('|', (string)$r), 3, '');
            if (trim(implode('', $c)) !== '') $sw[] = array_map('trim', array_slice($c, 0, 3));
        }
        if ($sw) { $h->seccion('Asignación de software'); $h->tabla(['Software estándar institucional', 'Software especial solicitado', 'Licencia'], $sw, [2, 2, 1]); }

        if ($v('obs') !== '') { $h->seccion('Observaciones'); $h->parrafo($v('obs'), 9); $h->y += 4; }

        $h->seccion('Términos y condiciones');
        $h->parrafo(ACTA_TERMINOS, 7.8, PdfHoja::INK);
        $h->y += 4;
        $h->casillas([['t' => 'He leído y acepto los términos y condiciones de esta acta.', 'ok' => true]]);

        $h->seccion('Firmas');
        $h->firmas([
            [$f1, ['img' => (string)($d['f1'] ?? ''), 'nombre' => $v('ncol') ?: $v('nombre'), 'cedula' => $v('ced'), 'fecha' => $v('fcol') ?: $v('fecha')]],
            [$f2, ['img' => (string)($d['f2'] ?? ''), 'nombre' => $v('ent'), 'cedula' => $v('cedtic'), 'fecha' => $v('ftic') ?: $v('fecha')]],
        ]);
    }
    return $pdf->salida();
}

/** Una hoja (o varias) con un registro de un formulario creado en el constructor. */
function form_pdf_hoja(PdfDoc $pdf, ?string $escudo, array $def, string $id, array $d): void {
    $num = !empty($def['numeracion']);
    $at = strtotime((string)($d['at'] ?? '')) ?: 0;
    $sub = trim((string)($def['subtitulo'] ?? ''));
    $h = new PdfHoja($pdf, acta_pdf_encabezado($pdf, $escudo, (string)$def['titulo'] . ($sub !== '' ? ' ' . $sub : ''), $num ? $id : '',
        'Departamento de Tecnologías de Información' . ($at ? ' · Registrado el ' . date('d/m/Y h:i a', $at) : ''), 'N.° DE REGISTRO'));
    $vals = (array)($d['v'] ?? []);
    $txt = fn($x) => is_array($x) ? implode(', ', array_map('strval', $x)) : trim((string)$x);
    foreach ((array)($def['secciones'] ?? []) as $sec) {
        $campos = array_values(array_filter((array)$sec['campos'], fn($c) => array_key_exists($c['id'], $vals) || ($c['tipo'] === 'info' && !$c['cond'])));
        if (!$campos || !array_filter($campos, fn($c) => $c['tipo'] !== 'info')) continue;
        $h->seccion((string)$sec['titulo']);
        $pares = []; $firmas = [];
        $vaciarPares = function () use (&$pares, $h) { if ($pares) { $h->datos($pares, 2); $pares = []; } };
        $vaciarFirmas = function () use (&$firmas, $h) { foreach (array_chunk($firmas, 2) as $par) $h->firmas($par); $firmas = []; };
        foreach ($campos as $c) {
            $x = $vals[$c['id']] ?? null; $et = (string)$c['etiqueta'];
            switch ($c['tipo']) {
                case 'firma':
                    $vaciarPares();
                    $f = is_array($x) ? $x : [];
                    $firmas[] = [$et, ['img' => (string)($f['firma'] ?? ''), 'nombre' => (string)($f['nombre'] ?? ''), 'cedula' => (string)($f['cedula'] ?? ''), 'fecha' => (string)($f['fecha'] ?? '')]];
                    break;
                case 'checklist':
                    $vaciarPares(); $vaciarFirmas();
                    $items = array_values(array_filter((array)$x, fn($i) => is_array($i) && trim((string)($i['t'] ?? '')) !== ''));
                    $hechos = count(array_filter($items, fn($i) => !empty($i['ok'])));
                    $h->espacio(30);
                    $h->pdf->texto(PdfHoja::M, $h->y, $et . '  (' . $hechos . ' de ' . count($items) . ' completados)', 7.5, false, PdfHoja::MUTE);
                    $h->y += 13;
                    $h->casillas(array_map(fn($i) => ['t' => (string)$i['t'], 'ok' => !empty($i['ok'])], $items));
                    break;
                case 'tabla':
                    $vaciarPares(); $vaciarFirmas();
                    $filas = array_values(array_filter((array)$x, fn($f) => is_array($f) && trim(implode('', $f)) !== ''));
                    $cols = (array)($c['columnas'] ?? []);
                    $h->espacio(30); $h->pdf->texto(PdfHoja::M, $h->y, $et, 7.5, false, PdfHoja::MUTE); $h->y += 12;
                    if (!$filas) { $h->parrafo('—', 9, PdfHoja::MUTE); break; }
                    if (!empty($c['numerada'])) { $cols = array_merge(['Ítem'], $cols); foreach ($filas as $i => $f) $filas[$i] = array_merge([(string)($i + 1)], $f); }
                    $filas = array_map(fn($f) => array_map('strval', array_pad(array_slice($f, 0, count($cols)), count($cols), '')), $filas);
                    $h->tabla($cols, $filas, array_map(fn($i) => !empty($c['numerada']) && $i === 0 ? 0.5 : 1, array_keys($cols)));
                    break;
                case 'aceptar':
                    $vaciarPares(); $vaciarFirmas();
                    $h->casillas([['t' => $et, 'ok' => (bool)$x]]);
                    break;
                case 'info':
                    $vaciarPares(); $vaciarFirmas();
                    if (trim((string)($c['texto'] ?? '')) !== '') { $h->parrafo(strip_tags((string)$c['texto']), 8.5, PdfHoja::MUTE); $h->y += 4; }
                    break;
                case 'parrafo':
                    $vaciarPares(); $vaciarFirmas();
                    $h->espacio(26); $h->pdf->texto(PdfHoja::M, $h->y, $et, 7.5, false, PdfHoja::MUTE); $h->y += 11;
                    $h->parrafo($txt($x) !== '' ? $txt($x) : '—', 9.5, PdfHoja::INK, true); $h->y += 4;
                    break;
                default:
                    $vaciarFirmas();
                    $val = $txt($x);
                    if ($c['tipo'] === 'fecha') $val = acta_fecha($val);
                    while (isset($pares[$et])) $et .= ' ';
                    $pares[$et] = $val !== '' ? $val : '—';
            }
        }
        $vaciarPares(); $vaciarFirmas();
    }
}


/* ---------------- Bitácora de Simulacro ---------------- */
const BITACORA_TITULO = 'Bitácora de Simulacro Semanal de Infraestructura Crítica';
const BITACORA_FASES = [1 => 'Fase de inspección y corte eléctrico', 2 => 'Pruebas de climatización y BMS', 3 => 'Fase de restablecimiento y cierre'];

function bitacora_pdf_hoja(PdfDoc $pdf, ?string $escudo, string $id, array $d): void {
    $v = fn(string $c) => trim((string)($d[$c] ?? ''));
    $h = new PdfHoja($pdf, acta_pdf_encabezado($pdf, $escudo, BITACORA_TITULO, '', 'Departamento de Tecnologías de Información'));
    $items = array_values(array_filter((array)($d['items'] ?? []), fn($i) => is_array($i) && trim((string)($i['t'] ?? '')) !== ''));
    $hechos = $items ? count(array_filter($items, fn($i) => !empty($i['ok']))) : (int)($d['hechos'] ?? count((array)($d['p'] ?? [])));
    $total = $items ? count($items) : (int)($d['total'] ?? 13);
    $h->seccion('Información general');
    $h->datos(['Fecha' => acta_fecha($v('fecha')) ?: '—', 'Hora' => $v('hora') ?: '—', 'Técnico' => $v('tec') ?: '—',
               'Puntos verificados' => "$hechos de $total" . ($hechos < $total ? ' (con observaciones)' : '')]);
    if ($items) {
        foreach (BITACORA_FASES as $n => $fase) {
            $l = array_values(array_filter($items, fn($i) => (int)($i['s'] ?? 0) === $n));
            if (!$l) continue;
            $ok = count(array_filter($l, fn($i) => !empty($i['ok'])));
            $h->seccion($fase . "  ($ok / " . count($l) . ')');
            $h->casillas(array_map(fn($i) => ['t' => (string)$i['t'], 'ok' => !empty($i['ok'])], $l));
        }
    } else {
        $h->seccion('Puntos del simulacro');
        $h->parrafo("Se verificaron $hechos de $total puntos (bitácora registrada con la versión anterior del formulario).", 9);
    }
    $h->seccion('Observaciones y contingencias');
    $h->parrafo($v('obs') !== '' ? $v('obs') : 'Sin observaciones.', 9, $v('obs') !== '' ? PdfHoja::INK : PdfHoja::MUTE);
    $h->y += 4;
    $h->seccion('Firma');
    $h->firmas([['Firma del técnico responsable', ['img' => (string)($d['f1'] ?? ''), 'nombre' => $v('firmante') ?: $v('tec'), 'cedula' => '', 'fecha' => $v('fecha')]]]);
    $h->parrafo('Este documento es un registro oficial. Mantener en los archivos del Departamento de TI.', 7.5, PdfHoja::MUTE);
}

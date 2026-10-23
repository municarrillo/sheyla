<?php
/**
 * Generador mínimo de PDF (sin librerías externas).
 * Texto en Helvetica / Helvetica-Bold (WinAnsi: español completo), rectángulos, líneas e imágenes JPEG.
 * Coordenadas en puntos desde la esquina superior izquierda. Página tamaño carta.
 */
declare(strict_types=1);
require_once __DIR__ . '/pdf_recursos.php';

final class PdfDoc {
    public const W = 612.0, H = 792.0;
    private array $paginas = [];
    private string $c = '';
    private array $imgs = [];   // nombre => [datos, ancho, alto, gris]
    public string $titulo = '';

    public function nuevaPagina(): void { $this->paginas[] = $this->c; $this->c = ''; }

    /** UTF-8 → Windows-1252 (lo que entiende Helvetica estándar). */
    public static function cp(string $s): string {
        $map = [0x20AC => 0x80, 0x201A => 0x82, 0x2026 => 0x85, 0x2018 => 0x91, 0x2019 => 0x92, 0x201C => 0x93, 0x201D => 0x94,
                0x2022 => 0x95, 0x2013 => 0x96, 0x2014 => 0x97, 0x2122 => 0x99];
        $out = '';
        foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $o = self::ord($ch);
            if ($o < 256) $out .= chr($o);
            elseif (isset($map[$o])) $out .= chr($map[$o]);
            else $out .= '?';
        }
        return $out;
    }
    private static function ord(string $ch): int {
        $b = array_values(unpack('C*', $ch));
        return match (count($b)) {
            1 => $b[0], 2 => (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F),
            3 => (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F),
            default => (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
        };
    }
    public static function ancho(string $s, float $tam, bool $negrita = false): float {
        $w = 0; $t = PDF_ANCHOS[$negrita ? 'F2' : 'F1'];
        foreach (str_split(self::cp($s)) as $ch) if ($ch !== '') $w += $t[ord($ch)];
        return $w * $tam / 1000;
    }
    /** Parte el texto en líneas que caben en $max puntos (respeta saltos de línea). */
    public static function lineas(string $s, float $tam, float $max, bool $negrita = false): array {
        $out = [];
        foreach (explode("\n", str_replace("\r", '', $s)) as $parr) {
            $linea = '';
            foreach (preg_split('/ +/u', $parr) ?: [''] as $pal) {
                $prueba = $linea === '' ? $pal : "$linea $pal";
                if ($linea !== '' && self::ancho($prueba, $tam, $negrita) > $max) { $out[] = $linea; $linea = $pal; }
                else $linea = $prueba;
                while (self::ancho($linea, $tam, $negrita) > $max && preg_match('/^(.+?)(.)$/us', $linea)) {   // palabra más larga que la línea
                    $corte = $linea; $resto = '';
                    while (self::ancho($corte, $tam, $negrita) > $max) { preg_match('/^(.*)(.)$/us', $corte, $m); $corte = $m[1]; $resto = $m[2] . $resto; }
                    $out[] = $corte; $linea = $resto;
                }
            }
            $out[] = $linea;
        }
        return $out;
    }
    private static function esc(string $s): string { return strtr(self::cp($s), ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']); }
    private static function rgb(array $c): string { return sprintf('%.3F %.3F %.3F', $c[0] / 255, $c[1] / 255, $c[2] / 255); }
    private function y(float $y): float { return self::H - $y; }

    public function texto(float $x, float $y, string $s, float $tam = 10, bool $negrita = false, array $color = [23, 33, 46], string $alinear = 'izq', float $ancho = 0): void {
        if ($alinear !== 'izq') { $w = self::ancho($s, $tam, $negrita); $x += $alinear === 'der' ? $ancho - $w : ($ancho - $w) / 2; }
        $this->c .= sprintf("BT /%s %.2F Tf %s rg %.2F %.2F Td (%s) Tj ET\n", $negrita ? 'F2' : 'F1', $tam, self::rgb($color), $x, $this->y($y + $tam * 0.8), self::esc($s));
    }
    public function rect(float $x, float $y, float $w, float $h, ?array $relleno = null, ?array $borde = null, float $grosor = 0.6): void {
        $op = $relleno && $borde ? 'B' : ($relleno ? 'f' : 'S');
        $this->c .= ($relleno ? self::rgb($relleno) . ' rg ' : '') . ($borde ? self::rgb($borde) . ' RG ' : '') . sprintf('%.2F w %.2F %.2F %.2F %.2F re %s', $grosor, $x, $this->y($y + $h), $w, $h, $op) . "\n";
    }
    public function linea(float $x1, float $y1, float $x2, float $y2, array $color = [23, 33, 46], float $grosor = 0.6): void {
        $this->c .= self::rgb($color) . sprintf(' RG %.2F w %.2F %.2F m %.2F %.2F l S', $grosor, $x1, $this->y($y1), $x2, $this->y($y2)) . "\n";
    }
    /** Registra un JPEG y devuelve su nombre, o null si no es un JPEG válido. */
    public function jpeg(string $datos): ?string {
        $i = @getimagesizefromstring($datos);
        if (!$i || ($i[2] ?? 0) !== IMAGETYPE_JPEG) return null;
        $n = 'Im' . (count($this->imgs) + 1);
        $this->imgs[$n] = [$datos, (int)$i[0], (int)$i[1], ($i['channels'] ?? 3) === 1];
        return $n;
    }
    /** Registra un PNG (firmas del lienzo) sobre fondo blanco y devuelve su nombre, o null si no se puede leer. */
    public function png(string $bin): ?string {
        if (!str_starts_with($bin, "\x89PNG\r\n\x1a\n") || !function_exists('gzuncompress')) return null;
        $p = 8; $idat = ''; $w = $h = $prof = $tipo = $inter = 0;
        while ($p + 8 <= strlen($bin)) {
            $len = unpack('N', substr($bin, $p, 4))[1]; $t = substr($bin, $p + 4, 4); $d = substr($bin, $p + 8, $len); $p += 12 + $len;
            if ($t === 'IHDR') { $i = unpack('Nw/Nh/Cprof/Ctipo/Ccomp/Cfiltro/Cinter', $d); [$w, $h, $prof, $tipo, $inter] = [$i['w'], $i['h'], $i['prof'], $i['tipo'], $i['inter']]; }
            elseif ($t === 'IDAT') $idat .= $d;
            elseif ($t === 'IEND') break;
        }
        $bpp = [0 => 1, 2 => 3, 4 => 2, 6 => 4][$tipo] ?? 0;
        if ($prof !== 8 || $inter !== 0 || !$bpp || $w < 1 || $h < 1 || $w * $h > 4_000_000) return null;
        $raw = @gzuncompress($idat);
        $fila = $w * $bpp;
        if ($raw === false || strlen($raw) < ($fila + 1) * $h) return null;
        $out = ''; $prev = array_fill(0, $fila, 0);
        for ($y = 0; $y < $h; $y++) {
            $f = ord($raw[$y * ($fila + 1)]);
            $cur = array_values(unpack('C*', substr($raw, $y * ($fila + 1) + 1, $fila)));
            if ($f === 1) { for ($i = $bpp; $i < $fila; $i++) $cur[$i] = ($cur[$i] + $cur[$i - $bpp]) & 255; }
            elseif ($f === 2) { for ($i = 0; $i < $fila; $i++) $cur[$i] = ($cur[$i] + $prev[$i]) & 255; }
            elseif ($f === 3) { for ($i = 0; $i < $fila; $i++) $cur[$i] = ($cur[$i] + (($i >= $bpp ? $cur[$i - $bpp] : 0) + $prev[$i] >> 1)) & 255; }
            elseif ($f === 4) {
                for ($i = 0; $i < $fila; $i++) {
                    $a = $i >= $bpp ? $cur[$i - $bpp] : 0; $b = $prev[$i]; $c = $i >= $bpp ? $prev[$i - $bpp] : 0;
                    $pp = $a + $b - $c; $pa = abs($pp - $a); $pb = abs($pp - $b); $pc = abs($pp - $c);
                    $cur[$i] = ($cur[$i] + ($pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c))) & 255;
                }
            }
            $prev = $cur;
            // Componer sobre blanco → RGB
            if ($tipo === 6) { for ($i = 0; $i < $fila; $i += 4) { $al = $cur[$i + 3]; $k = 255 - $al;
                    $out .= chr(intdiv($cur[$i] * $al + 255 * $k, 255)) . chr(intdiv($cur[$i + 1] * $al + 255 * $k, 255)) . chr(intdiv($cur[$i + 2] * $al + 255 * $k, 255)); } }
            elseif ($tipo === 4) { for ($i = 0; $i < $fila; $i += 2) { $v = chr(intdiv($cur[$i] * $cur[$i + 1] + 255 * (255 - $cur[$i + 1]), 255)); $out .= $v . $v . $v; } }
            elseif ($tipo === 0) { foreach ($cur as $v) $out .= chr($v) . chr($v) . chr($v); }
            else $out .= pack('C*', ...$cur);
        }
        $n = 'Im' . (count($this->imgs) + 1);
        $this->imgs[$n] = [gzcompress($out), $w, $h, false, 'FlateDecode'];
        return $n;
    }
    /** JPEG o PNG en «data:image/...;base64,». */
    public function imagenDataUrl(string $url): ?string {
        if (!preg_match('#^data:image/(jpeg|png);base64,(.+)$#s', $url, $m)) return null;
        $bin = base64_decode($m[2], true);
        if ($bin === false) return null;
        return $m[1] === 'png' ? $this->png($bin) : $this->jpeg($bin);
    }
    public function imagen(string $n, float $x, float $y, float $w, float $h): void {
        $this->c .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, $this->y($y + $h), $n);
    }
    public function proporcion(string $n): float { return $this->imgs[$n][2] / max(1, $this->imgs[$n][1]); }

    public function salida(): string {
        if ($this->c !== '' || !$this->paginas) $this->paginas[] = $this->c;
        $this->c = '';
        $obj = []; // número => contenido
        $n = 3;
        $fuentes = ['F1' => $n++, 'F2' => $n++];
        $obj[$fuentes['F1']] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $obj[$fuentes['F2']] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $imgObj = [];
        foreach ($this->imgs as $nom => $im) {
            [$d, $w, $h, $gris] = $im; $fil = $im[4] ?? 'DCTDecode';
            $imgObj[$nom] = $n;
            $obj[$n++] = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /" . ($gris ? 'DeviceGray' : 'DeviceRGB')
                . " /BitsPerComponent 8 /Filter /$fil /Length " . strlen($d) . " >>\nstream\n$d\nendstream";
        }
        $res = '<< /Font << /F1 ' . $fuentes['F1'] . ' 0 R /F2 ' . $fuentes['F2'] . ' 0 R >>'
             . ($imgObj ? ' /XObject << ' . implode(' ', array_map(fn($k, $v) => "/$k $v 0 R", array_keys($imgObj), $imgObj)) . ' >>' : '') . ' >>';
        $kids = [];
        $total = count($this->paginas);
        foreach ($this->paginas as $i => $cont) {
            // pie de página con número
            $pie = sprintf("BT /F1 7.5 Tf 0.45 0.5 0.56 rg %.2F 22 Td (%s) Tj ET\n", 40.0, self::esc($this->titulo))
                 . sprintf("BT /F1 7.5 Tf 0.45 0.5 0.56 rg %.2F 22 Td (%s) Tj ET\n", self::W - 40 - self::ancho('Página ' . ($i + 1) . ' de ' . $total, 7.5), self::esc('Página ' . ($i + 1) . ' de ' . $total));
            $flujo = $cont . $pie;
            $filtro = '';
            if (function_exists('gzcompress')) { $flujo = gzcompress($flujo); $filtro = ' /Filter /FlateDecode'; }
            $cn = $n++; $obj[$cn] = '<< /Length ' . strlen($flujo) . "$filtro >>\nstream\n$flujo\nendstream";
            $pn = $n++; $obj[$pn] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . "] /Resources $res /Contents $cn 0 R >>";
            $kids[] = "$pn 0 R";
        }
        $obj[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $obj[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        $info = $n++; $obj[$info] = '<< /Title (' . self::esc($this->titulo) . ') /Producer (Actas TIC - Municipalidad de Carrillo) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($obj);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $xref = [];
        foreach ($obj as $k => $v) { $xref[$k] = strlen($pdf); $pdf .= "$k 0 obj\n$v\nendobj\n"; }
        $x = strlen($pdf);
        $pdf .= "xref\n0 " . ($n) . "\n0000000000 65535 f \n";
        for ($k = 1; $k < $n; $k++) $pdf .= sprintf("%010d 00000 n \n", $xref[$k] ?? 0);
        $pdf .= "trailer\n<< /Size $n /Root 1 0 R /Info $info 0 R >>\nstartxref\n$x\n%%EOF\n";
        return $pdf;
    }
}

/**
 * Ayudante de maquetación: lleva la posición vertical y salta de página cuando hace falta.
 */
final class PdfHoja {
    public float $y;
    public const M = 40.0;                      // margen
    public const AN = PdfDoc::W - 2 * self::M;  // ancho útil
    public const NAVY = [18, 52, 86], GOLD = [192, 138, 30], MUTE = [86, 98, 115], LINE = [211, 219, 229], BAND = [233, 239, 246], INK = [23, 33, 46];
    public function __construct(public PdfDoc $pdf, private $encabezado) { $this->y = 0; ($this->encabezado)($this); }
    public function espacio(float $h): void {
        if ($this->y + $h > PdfDoc::H - 44) { $this->pdf->nuevaPagina(); ($this->encabezado)($this, true); }
    }
    public function seccion(string $t): void {
        $this->espacio(40);
        $this->y += 6;
        $this->pdf->rect(self::M, $this->y, self::AN, 17, self::BAND);
        $this->pdf->rect(self::M, $this->y, 3, 17, self::GOLD);
        $this->pdf->texto(self::M + 10, $this->y + 4.5, mb_strtoupper_seguro($t), 8.5, true, self::NAVY);
        $this->y += 24;
    }
    /** Pares etiqueta/valor en columnas. */
    public function datos(array $pares, int $cols = 3): void {
        $w = self::AN / $cols;
        foreach (array_chunk($pares, $cols, true) as $fila) {
            $alto = 0; $lin = [];
            foreach ($fila as $et => $val) { $lin[$et] = PdfDoc::lineas((string)$val, 9.5, $w - 10, true); $alto = max($alto, 12 + count($lin[$et]) * 12); }
            $this->espacio($alto);
            $i = 0;
            foreach ($fila as $et => $val) {
                $x = self::M + $i++ * $w;
                $this->pdf->texto($x, $this->y, (string)$et, 7.5, false, self::MUTE);
                foreach ($lin[$et] as $k => $l) $this->pdf->texto($x, $this->y + 10 + $k * 12, $l, 9.5, true, self::INK);
            }
            $this->y += $alto + 4;
        }
    }
    public function parrafo(string $s, float $tam = 9, array $color = self::INK, bool $negrita = false, float $sangria = 0): void {
        foreach (PdfDoc::lineas($s, $tam, self::AN - $sangria, $negrita) as $l) {
            $this->espacio($tam * 1.45);
            $this->pdf->texto(self::M + $sangria, $this->y, $l, $tam, $negrita, $color);
            $this->y += $tam * 1.45;
        }
    }
    /** Tabla con encabezado azul; $anchos en proporciones. */
    public function tabla(array $cabeza, array $filas, array $anchos, int $colNegrita = -1): void {
        $tot = array_sum($anchos); $ws = array_map(fn($a) => self::AN * $a / $tot, $anchos);
        $dibCabeza = function () use ($cabeza, $ws) {
            $this->pdf->rect(self::M, $this->y, self::AN, 18, self::NAVY);
            $x = self::M;
            foreach ($cabeza as $i => $c) { $this->pdf->texto($x + 5, $this->y + 5, mb_strtoupper_seguro($c), 7, true, [255, 255, 255]); $x += $ws[$i]; }
            $this->y += 18;
        };
        $this->espacio(40); $dibCabeza();
        foreach ($filas as $f) {
            $lin = []; $alto = 0;
            foreach ($f as $i => $v) { $lin[$i] = PdfDoc::lineas((string)$v, 8.5, $ws[$i] - 10); $alto = max($alto, count($lin[$i]) * 11 + 9); }
            if ($this->y + $alto > PdfDoc::H - 44) { $this->pdf->nuevaPagina(); ($this->encabezado)($this, true); $dibCabeza(); }
            $x = self::M;
            foreach ($f as $i => $v) { foreach ($lin[$i] as $k => $l) $this->pdf->texto($x + 5, $this->y + 5 + $k * 11, $l, 8.5, $i === $colNegrita); $x += $ws[$i]; }
            $this->y += $alto;
            $this->pdf->linea(self::M, $this->y, self::M + self::AN, $this->y, self::LINE);
        }
        $this->y += 6;
    }
    public function casillas(array $items): void {
        $w = self::AN / 2;
        foreach (array_chunk($items, 2) as $fila) {
            $alto = 0; $ls = [];
            foreach ($fila as $k => $it) { $ls[$k] = PdfDoc::lineas($it['t'], 8.5, $w - 22); $alto = max($alto, count($ls[$k]) * 11 + 3); }
            $this->espacio($alto);
            foreach ($fila as $k => $it) {
                $x = self::M + $k * $w;
                $this->pdf->rect($x, $this->y + 0.5, 8, 8, $it['ok'] ? [29, 122, 74] : null, $it['ok'] ? [29, 122, 74] : self::MUTE, 0.7);
                if ($it['ok']) { $this->pdf->linea($x + 1.8, $this->y + 4.6, $x + 3.6, $this->y + 6.6, [255, 255, 255], 1.2); $this->pdf->linea($x + 3.6, $this->y + 6.6, $x + 6.6, $this->y + 2.2, [255, 255, 255], 1.2); }
                foreach ($ls[$k] as $j => $l) $this->pdf->texto($x + 13, $this->y + $j * 11, $l, 8.5);
            }
            $this->y += $alto;
        }
        $this->y += 4;
    }
    /** Dos recuadros de firma lado a lado. $firmas: [[titulo, ['img','nombre','cedula','fecha']|null], ...] */
    public function firmas(array $firmas): void {
        $this->espacio(122);
        $w = (self::AN - 14) / 2;
        foreach ($firmas as $i => [$t, $f]) {
            $x = self::M + $i * ($w + 14); $y = $this->y;
            $this->pdf->rect($x, $y, $w, 116, null, self::LINE, 0.7);
            $this->pdf->texto($x + 9, $y + 8, mb_strtoupper_seguro($t), 7.5, true, self::NAVY);
            $img = $f['img'] ?? '';
            if ($img !== '' && ($n = $this->pdf->imagenDataUrl($img))) {
                $bw = $w - 18; $bh = 52; $p = $this->pdf->proporcion($n);
                $iw = min($bw, $bh / $p); $ih = $iw * $p;
                $this->pdf->imagen($n, $x + 9 + ($bw - $iw) / 2, $y + 22 + ($bh - $ih) / 2, $iw, $ih);
            } elseif ($img !== '') {
                $this->pdf->texto($x + 9, $y + 44, '(firma registrada en el sistema)', 8, false, self::MUTE, 'centro', $w - 18);
            }
            $this->pdf->linea($x + 9, $y + 78, $x + $w - 9, $y + 78, self::INK, 0.7);
            $cw = ($w - 18) / 3.6;
            foreach ([['Nombre', $f['nombre'] ?? '', 0, 1.6], ['Cédula', $f['cedula'] ?? '', 1.6, 1], ['Fecha', isset($f['fecha']) && $f['fecha'] !== '' ? implode('/', array_reverse(explode('-', $f['fecha']))) : '', 2.6, 1]] as [$et, $v, $off, $span]) {
                $this->pdf->texto($x + 9 + $off * $cw, $y + 84, $et, 6.5, false, self::MUTE);
                $l = PdfDoc::lineas((string)$v, 8.5, $span * $cw - 4)[0] ?? '';
                $this->pdf->texto($x + 9 + $off * $cw, $y + 94, $l, 8.5, true);
            }
        }
        $this->y += 124;
    }
}

/** Mayúsculas sin depender de mbstring (cubre las letras del español). */
function mb_strtoupper_seguro(string $s): string {
    return strtr(strtoupper($s), ['á' => 'Á', 'é' => 'É', 'í' => 'Í', 'ó' => 'Ó', 'ú' => 'Ú', 'ñ' => 'Ñ', 'ü' => 'Ü']);
}

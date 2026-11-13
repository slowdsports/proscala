<?php
// ── Escritor mínimo de libros de Excel (.xlsx) ─────────────────────
// Sin dependencias: arma el paquete Office Open XML con ZipArchive. Lo usa formatos/generar.php.
// Soporta lo que necesitan los formatos de los documentos: estilos por celda (fuente, relleno sólido
// o degradado, bordes, alineación, formato de número), celdas combinadas, fórmulas, texto enriquecido,
// listas desplegables, formato condicional, imágenes (logo), área de impresión y ajuste a la página.
//
// Estilo de una celda (arreglo; se combinan con array_replace_recursive):
//   'f'    => ['sz'=>9, 'b'=>true, 'i'=>true, 'u'=>true, 'c'=>'1A1A2E', 'n'=>'Segoe UI']   fuente
//   'bg'   => 'F8FAFC'  o  ['grad'=>[[0,'1B6BA8'],[1,'E53935']], 'deg'=>0]               relleno
//   'b'    => ['t'=>['thin','E2E8F0'], 'b'=>[...], 'l'=>[...], 'r'=>[...]]                bordes
//   'nf'   => '"L. "#,##0.00'                                                           formato
//   'h'    => left|center|right|justify, 'v' => top|center|bottom, 'wrap' => true, 'ind' => 1,
//   'shrink' => true (reducir hasta ajustar; los formatos numéricos lo llevan siempre)
//
// Valor de una celda: texto, número, null, ['f' => 'SUM(A1:A3)'] (fórmula, sin "=") o
// ['rico' => [['texto', ['b'=>true]], ...]] (texto con partes en otra fuente).

final class XlsxEstilos {
    private array $fonts = [], $fills = [], $borders = [], $xfs = [], $numFmts = [], $dxfs = [];
    private array $indice = [];
    public const FUENTE_BASE = ['sz' => 11, 'c' => '000000', 'n' => 'Calibri'];

    public function __construct() {
        $this->unico('fonts', self::xmlFuente(self::FUENTE_BASE));
        $this->unico('fills', '<fill><patternFill patternType="none"/></fill>');
        $this->unico('fills', '<fill><patternFill patternType="gray125"/></fill>');
        $this->unico('borders', '<border><left/><right/><top/><bottom/><diagonal/></border>');
        $this->unico('xfs', '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>');
    }

    private function unico(string $lista, string $xml): int {
        $k = $lista . '|' . $xml;
        if (!isset($this->indice[$k])) {
            $this->$lista[] = $xml;
            $this->indice[$k] = count($this->$lista) - 1;
        }
        return $this->indice[$k];
    }

    public static function color(string $c): string { return 'FF' . strtoupper(ltrim($c, '#')); }
    public static function x(string $s): string { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

    public static function xmlFuente(array $f, string $tag = 'font', bool $run = false): string {
        $f += self::FUENTE_BASE;
        // <font>: b, i, u, sz, color, name · <rPr> (texto enriquecido): rFont, b, i, color, sz, u
        $b = !empty($f['b']) ? '<b/>' : '';
        $i = !empty($f['i']) ? '<i/>' : '';
        $u = !empty($f['u']) ? '<u/>' : '';
        $sz = '<sz val="' . $f['sz'] . '"/>';
        $c = '<color rgb="' . self::color($f['c']) . '"/>';
        if ($run) return "<rPr><rFont val=\"" . self::x($f['n']) . "\"/>{$b}{$i}{$c}{$sz}{$u}</rPr>";
        return "<{$tag}>{$b}{$i}{$u}{$sz}{$c}<name val=\"" . self::x($f['n']) . "\"/></{$tag}>";
    }

    private function relleno($bg): int {
        if (!$bg) return 0;
        if (is_array($bg)) {
            $paradas = '';
            foreach ($bg['grad'] as [$pos, $col]) $paradas .= '<stop position="' . $pos . '"><color rgb="' . self::color($col) . '"/></stop>';
            return $this->unico('fills', '<fill><gradientFill degree="' . ($bg['deg'] ?? 0) . '">' . $paradas . '</gradientFill></fill>');
        }
        return $this->unico('fills', '<fill><patternFill patternType="solid"><fgColor rgb="' . self::color($bg) . '"/><bgColor indexed="64"/></patternFill></fill>');
    }

    private function borde(array $b): int {
        if (!$b) return 0;
        $xml = '<border>';
        foreach (['l' => 'left', 'r' => 'right', 't' => 'top', 'b' => 'bottom'] as $k => $tag) {
            $xml .= isset($b[$k]) && $b[$k] ? "<{$tag} style=\"{$b[$k][0]}\"><color rgb=\"" . self::color($b[$k][1]) . "\"/></{$tag}>" : "<{$tag}/>";
        }
        return $this->unico('borders', $xml . '<diagonal/></border>');
    }

    private function formato(?string $nf): int {
        if ($nf === null || $nf === '' || $nf === 'General') return 0;
        if ($nf === '@') return 49;
        if (!isset($this->numFmts[$nf])) $this->numFmts[$nf] = 164 + count($this->numFmts);
        return $this->numFmts[$nf];
    }

    /** Índice de cellXfs del estilo */
    public function id(array $e): int {
        if (!$e) return 0;
        $font = $this->unico('fonts', self::xmlFuente($e['f'] ?? []));
        $fill = $this->relleno($e['bg'] ?? null);
        $border = $this->borde($e['b'] ?? []);
        $num = $this->formato($e['nf'] ?? null);
        $al = '';
        foreach (['h' => 'horizontal', 'v' => 'vertical', 'ind' => 'indent'] as $k => $a) if (!empty($e[$k])) $al .= " {$a}=\"{$e[$k]}\"";
        if (!empty($e['wrap'])) $al .= ' wrapText="1"';
        // Montos y cantidades: si no caben, la letra se achica en lugar de mostrar "####"
        elseif (!empty($e['shrink']) || str_contains($e['nf'] ?? '', '#,##0') || ($e['nf'] ?? '') === '0') $al .= ' shrinkToFit="1"';
        $xml = "<xf numFmtId=\"{$num}\" fontId=\"{$font}\" fillId=\"{$fill}\" borderId=\"{$border}\" xfId=\"0\" applyNumberFormat=\"1\" applyFont=\"1\" applyFill=\"1\" applyBorder=\"1\" applyAlignment=\"1\">"
             . ($al ? "<alignment{$al}/>" : '') . '</xf>';
        return $this->unico('xfs', $xml);
    }

    /** Índice de dxfs (formato condicional): ['c'=>color de letra, 'b'=>negrita, 'bg'=>relleno] */
    public function dxf(array $d): int {
        $font = isset($d['c']) || !empty($d['b']) ? '<font>' . (!empty($d['b']) ? '<b/>' : '') . (isset($d['c']) ? '<color rgb="' . self::color($d['c']) . '"/>' : '') . '</font>' : '';
        $fill = isset($d['bg']) ? '<fill><patternFill patternType="solid"><bgColor rgb="' . self::color($d['bg']) . '"/></patternFill></fill>' : '';
        return $this->unico('dxfs', "<dxf>{$font}{$fill}</dxf>");
    }

    public function xml(): string {
        $nf = '';
        foreach ($this->numFmts as $code => $id) $nf .= '<numFmt numFmtId="' . $id . '" formatCode="' . self::x($code) . '"/>';
        $lista = fn(string $tag, array $items) => "<{$tag} count=\"" . count($items) . '">' . implode('', $items) . "</{$tag}>";
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($nf ? '<numFmts count="' . count($this->numFmts) . '">' . $nf . '</numFmts>' : '')
            . $lista('fonts', $this->fonts) . $lista('fills', $this->fills) . $lista('borders', $this->borders)
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . $lista('cellXfs', $this->xfs)
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . $lista('dxfs', $this->dxfs)
            . '</styleSheet>';
    }
}

final class XlsxHoja {
    public array $celdas = [];        // [fila][col] => ['v' => valor, 'e' => estilo]
    public array $altos = [];         // fila => puntos
    public array $anchos = [];        // col => ancho en caracteres
    public array $ocultas = [];       // col => true (columnas auxiliares de cálculo)
    public int $colsImpresion = 0;    // última columna del área de impresión (0 = la última con ancho)
    public array $combinadas = [];
    public array $listas = [];        // [rango, opciones]
    public array $condicionales = []; // [rango, fórmula, dxf]
    public array $imagenes = [];      // [fila, col, ruta, ancho px, alto px, despl. x px, despl. y px]
    public bool $horizontal = false;
    public bool $unaPagina = true;    // ajusta también el alto a una página
    public string $color = '';        // color de la pestaña

    public function __construct(public string $nombre) {}

    public static function col(int $c): string {
        $s = '';
        for (; $c > 0; $c = intdiv($c - 1, 26)) $s = chr(65 + ($c - 1) % 26) . $s;
        return $s;
    }
    public static function ref(int $f, int $c, bool $abs = false): string {
        return $abs ? '$' . self::col($c) . '$' . $f : self::col($c) . $f;
    }

    /** Valor y estilo de una celda (el estilo se combina con el que ya tenía) */
    public function poner(int $f, int $c, $valor = null, array $estilo = []): void {
        $actual = $this->celdas[$f][$c] ?? ['v' => null, 'e' => []];
        $this->celdas[$f][$c] = ['v' => $valor ?? $actual['v'], 'e' => array_replace_recursive($actual['e'], $estilo)];
    }

    /** Estilo en todas las celdas de un rango (sin cambiar sus valores) */
    public function pintar(int $f1, int $c1, int $f2, int $c2, array $estilo): void {
        for ($f = $f1; $f <= $f2; $f++) for ($c = $c1; $c <= $c2; $c++) $this->poner($f, $c, null, $estilo);
    }

    /** Celdas combinadas con un valor; el estilo va en todas (Excel dibuja los bordes de cada una) */
    public function rango(int $f1, int $c1, int $f2, int $c2, $valor, array $estilo = []): void {
        $this->pintar($f1, $c1, $f2, $c2, $estilo);
        $this->poner($f1, $c1, $valor);
        if ($f1 !== $f2 || $c1 !== $c2) $this->combinadas[] = self::ref($f1, $c1) . ':' . self::ref($f2, $c2);
    }

    public function lista(string $rango, array $opciones): void { $this->listas[] = [$rango, $opciones]; }
    public function condicional(string $rango, string $formula, int $dxf): void { $this->condicionales[] = [$rango, $formula, $dxf]; }

    public function xml(XlsxEstilos $estilos, ?int $dibujo): string {
        $maxF = max(array_merge([1], array_keys($this->celdas), array_keys($this->altos)));
        $maxC = max(1, count($this->anchos));
        foreach ($this->celdas as $fila) $maxC = max($maxC, ...array_keys($fila));

        $cols = '';
        ksort($this->anchos);
        foreach ($this->anchos as $c => $w) $cols .= "<col min=\"{$c}\" max=\"{$c}\" width=\"{$w}\" customWidth=\"1\"" . (!empty($this->ocultas[$c]) ? ' hidden="1"' : '') . '/>';

        $filas = '';
        for ($f = 1; $f <= $maxF; $f++) {
            if (!isset($this->celdas[$f]) && !isset($this->altos[$f])) continue;
            $alto = isset($this->altos[$f]) ? " ht=\"{$this->altos[$f]}\" customHeight=\"1\"" : '';
            $filas .= "<row r=\"{$f}\"{$alto}>";
            $celdas = $this->celdas[$f] ?? [];
            ksort($celdas);
            foreach ($celdas as $c => $cel) $filas .= $this->xmlCelda($f, $c, $cel, $estilos);
            $filas .= '</row>';
        }

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
           . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
           . '<sheetPr>' . ($this->color ? '<tabColor rgb="' . XlsxEstilos::color($this->color) . '"/>' : '') . '<pageSetUpPr fitToPage="1"/></sheetPr>'
           . '<dimension ref="A1:' . self::ref($maxF, $maxC) . '"/>'
           . '<sheetViews><sheetView showGridLines="0" workbookViewId="0"><selection activeCell="A1" sqref="A1"/></sheetView></sheetViews>'
           . '<sheetFormatPr defaultRowHeight="15"/>'
           . ($cols ? "<cols>{$cols}</cols>" : '')
           . "<sheetData>{$filas}</sheetData>";
        if ($this->combinadas) {
            $x .= '<mergeCells count="' . count($this->combinadas) . '">';
            foreach ($this->combinadas as $m) $x .= "<mergeCell ref=\"{$m}\"/>";
            $x .= '</mergeCells>';
        }
        $prio = 1;
        foreach ($this->condicionales as [$rango, $formula, $dxf]) {
            $x .= "<conditionalFormatting sqref=\"{$rango}\"><cfRule type=\"expression\" dxfId=\"{$dxf}\" priority=\"" . $prio++ . '"><formula>' . XlsxEstilos::x($formula) . '</formula></cfRule></conditionalFormatting>';
        }
        if ($this->listas) {
            $x .= '<dataValidations count="' . count($this->listas) . '">';
            foreach ($this->listas as [$rango, $op]) {
                $x .= "<dataValidation type=\"list\" allowBlank=\"1\" showInputMessage=\"1\" showErrorMessage=\"1\" sqref=\"{$rango}\"><formula1>"
                    . XlsxEstilos::x('"' . implode(',', $op) . '"') . '</formula1></dataValidation>';
            }
            $x .= '</dataValidations>';
        }
        $x .= '<printOptions horizontalCentered="1"/>'
            . '<pageMargins left="0.3" right="0.3" top="0.35" bottom="0.35" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" orientation="' . ($this->horizontal ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="' . ($this->unaPagina ? 1 : 0) . '"/>'
            . '<headerFooter><oddFooter>' . XlsxEstilos::x('&R&7&K94A3B8Página &P de &N') . '</oddFooter></headerFooter>';
        if ($dibujo !== null) $x .= '<drawing r:id="rId1"/>';
        return $x . '</worksheet>';
    }

    private function xmlCelda(int $f, int $c, array $cel, XlsxEstilos $estilos): string {
        $r = self::ref($f, $c);
        $s = $estilos->id($cel['e']);
        $sa = $s ? " s=\"{$s}\"" : '';
        $v = $cel['v'];
        if ($v === null || $v === '') return "<c r=\"{$r}\"{$sa}/>";
        if (is_int($v) || is_float($v)) return "<c r=\"{$r}\"{$sa}><v>{$v}</v></c>";
        if (is_array($v) && isset($v['f'])) return "<c r=\"{$r}\"{$sa}><f>" . XlsxEstilos::x($v['f']) . '</f></c>';
        if (is_array($v) && isset($v['rico'])) {
            $runs = '';
            $base = $cel['e']['f'] ?? [];
            foreach ($v['rico'] as [$texto, $fuente]) {
                $runs .= '<r>' . XlsxEstilos::xmlFuente(array_replace($base, $fuente), 'font', true)
                       . '<t xml:space="preserve">' . XlsxEstilos::x($texto) . '</t></r>';
            }
            return "<c r=\"{$r}\"{$sa} t=\"inlineStr\"><is>{$runs}</is></c>";
        }
        return "<c r=\"{$r}\"{$sa} t=\"inlineStr\"><is><t xml:space=\"preserve\">" . XlsxEstilos::x((string)$v) . '</t></is></c>';
    }
}

final class XlsxLibro {
    public XlsxEstilos $estilos;
    /** @var XlsxHoja[] */
    public array $hojas = [];

    public function __construct(public string $titulo = '') { $this->estilos = new XlsxEstilos(); }

    public function hoja(string $nombre): XlsxHoja {
        $nombre = mb_substr(preg_replace('/[\[\]:*?\/\\\\]/', '', $nombre), 0, 31);
        return $this->hojas[] = new XlsxHoja($nombre);
    }

    public function guardar(string $ruta): void {
        if (!$this->hojas) throw new LogicException('El libro no tiene hojas.');
        $zip = new ZipArchive();
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("No se pudo crear {$ruta}");

        $tipos = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
               . '<Default Extension="xml" ContentType="application/xml"/>'
               . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
               . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
               . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
               . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $hojasXml = $relsLibro = $nombres = '';
        $dibujos = $media = 0;
        foreach ($this->hojas as $i => $h) {
            $n = $i + 1;
            $dibujo = null;
            if ($h->imagenes) {
                $dibujo = ++$dibujos;
                [$xmlDib, $relsDib] = $this->dibujo($h, $zip, $media);
                $zip->addFromString("xl/drawings/drawing{$dibujo}.xml", $xmlDib);
                $zip->addFromString("xl/drawings/_rels/drawing{$dibujo}.xml.rels", $relsDib);
                $zip->addFromString("xl/worksheets/_rels/sheet{$n}.xml.rels", self::rels([['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing', "../drawings/drawing{$dibujo}.xml"]]));
                $tipos .= "<Override PartName=\"/xl/drawings/drawing{$dibujo}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.drawing+xml\"/>";
            }
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $h->xml($this->estilos, $dibujo));
            $tipos .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            $hojasXml .= '<sheet name="' . XlsxEstilos::x($h->nombre) . "\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
            $relsLibro .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
            // Área de impresión: hasta la última fila y columna usadas
            $maxF = max(array_merge(array_keys($h->celdas), array_keys($h->altos)));
            $maxC = $h->colsImpresion ?: max(array_keys($h->anchos) ?: [1]);
            $nombres .= "<definedName name=\"_xlnm.Print_Area\" localSheetId=\"{$i}\">'" . XlsxEstilos::x(str_replace("'", "''", $h->nombre)) . "'!\$A\$1:" . XlsxHoja::ref($maxF, $maxC, true) . '</definedName>';
        }
        $s = count($this->hojas) + 1;
        $relsLibro .= "<Relationship Id=\"rId{$s}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>";

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews>'
            . "<sheets>{$hojasXml}</sheets><definedNames>{$nombres}</definedNames>"
            . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relsLibro . '</Relationships>');
        $zip->addFromString('xl/styles.xml', $this->estilos->xml());
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . XlsxEstilos::x($this->titulo) . '</dc:title><dc:creator>Proscala</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('_rels/.rels', self::rels([
            ['rId1', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument', 'xl/workbook.xml'],
            ['rId2', 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
        ]));
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . $tipos . '</Types>');
        $zip->close();
    }

    private static function rels(array $rels): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($rels as [$id, $tipo, $destino]) $x .= "<Relationship Id=\"{$id}\" Type=\"{$tipo}\" Target=\"{$destino}\"/>";
        return $x . '</Relationships>';
    }

    private function dibujo(XlsxHoja $h, ZipArchive $zip, int &$media): array {
        $emu = 9525;   // EMU por píxel
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $rels = [];
        foreach ($h->imagenes as $k => [$f, $c, $ruta, $w, $alto, $dx, $dy]) {
            $ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpeg';
            $nombre = 'image' . (++$media) . '.' . $ext;
            $zip->addFromString("xl/media/{$nombre}", file_get_contents($ruta));
            $rid = 'rId' . ($k + 1);
            $rels[] = [$rid, 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', "../media/{$nombre}"];
            $cx = (int)round($w * $emu); $cy = (int)round($alto * $emu);
            $xml .= '<xdr:oneCellAnchor><xdr:from><xdr:col>' . ($c - 1) . '</xdr:col><xdr:colOff>' . ($dx * $emu) . '</xdr:colOff><xdr:row>' . ($f - 1) . '</xdr:row><xdr:rowOff>' . ($dy * $emu) . '</xdr:rowOff></xdr:from>'
                  . "<xdr:ext cx=\"{$cx}\" cy=\"{$cy}\"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id=\"" . ($k + 2) . "\" name=\"Logo\"/><xdr:cNvPicPr><a:picLocks noChangeAspect=\"1\"/></xdr:cNvPicPr></xdr:nvPicPr>"
                  . "<xdr:blipFill><a:blip r:embed=\"{$rid}\"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>"
                  . "<xdr:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"{$cx}\" cy=\"{$cy}\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>";
        }
        return [$xml . '</xdr:wsDr>', self::rels($rels)];
    }
}

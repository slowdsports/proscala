<?php
// ── Exportar a Excel (.xlsx) ─────────────────────────────────────────
// Escritor mínimo de hojas de cálculo Office Open XML con ZipArchive (sin librerías externas):
// una hoja, encabezado en negrita y fijo, montos como números con formato 0.00 y anchos de columna
// según el contenido. Lo usan exportar_excel.php y las plantillas de importación (leerXlsx, abajo).

/** Valor numérico de un texto como "L. 1,234.56", "(1,234.56)" o "15", o null si no es un número. */
function numeroExcel(string $v): ?float {
    $t = trim(str_replace(["\u{00A0}", ' '], '', $v));
    if (!preg_match('/^(-|\()?(L\.)?-?[\d,]*\.?\d+\)?%?$/u', $t) || preg_match('/^0\d/', ltrim($t, '-(L.'))) return null;   // sin ceros a la izquierda (identidad, cuentas)
    $neg = str_starts_with($t, '-') || str_starts_with($t, '(');
    $n = (float)str_replace([',', 'L.', '(', ')', '-', '%'], '', $t);
    return $neg ? -$n : $n;
}

/**
 * Contenido binario de un .xlsx con $columnas (encabezados) y $filas (arreglos de textos).
 * $opciones (plantillas de importación):
 *   texto  → índices de columna que siempre son texto (códigos: "1.10" no se vuelve 1.1), también al escribir en Excel
 *   listas → [índice => [valores]]: lista desplegable de Excel en esa columna
 *   filas  → filas preparadas para listas y formato (por defecto, las que haya)
 */
function generarXlsx(string $hoja, array $columnas, array $filas, array $opciones = []): string {
    $texto = $opciones['texto'] ?? [];
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $hoja = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/', ' ', $hoja)) ?: 'Hoja1', 0, 31);

    $anchos = array_map(fn($c) => mb_strlen((string)$c), $columnas);
    $xmlFilas = '<row r="1">';
    foreach ($columnas as $i => $c) $xmlFilas .= '<c r="' . $col($i) . '1" t="inlineStr" s="1"><is><t>' . $x($c) . '</t></is></c>';
    $xmlFilas .= '</row>';
    foreach (array_values($filas) as $n => $fila) {
        $r = $n + 2;
        $xmlFilas .= "<row r=\"$r\">";
        foreach (array_values($fila) as $i => $v) {
            $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
            $anchos[$i] = max($anchos[$i] ?? 0, mb_strlen($v));
            $ref = $col($i) . $r;
            if (in_array($i, $texto, true)) { if ($v !== '') $xmlFilas .= "<c r=\"$ref\" t=\"inlineStr\" s=\"3\"><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>'; }
            elseif (($num = numeroExcel($v)) !== null) $xmlFilas .= "<c r=\"$ref\" s=\"" . (str_contains($v, '.') ? '2' : '0') . "\"><v>$num</v></c>";
            elseif ($v !== '') $xmlFilas .= "<c r=\"$ref\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>';
        }
        $xmlFilas .= '</row>';
    }
    $cols = '<cols>';
    foreach ($anchos as $i => $a) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(8, $a + 2)) . '" customWidth="1"'
        . (in_array($i, $texto, true) ? ' style="3"' : '') . '/>';
    $cols .= '</cols>';
    $ultima = $col(max(0, count($columnas) - 1)) . (count($filas) + 1);
    $hasta = max(count($filas) + 1, (int)($opciones['filas'] ?? 0) + 1);
    $validaciones = '';
    foreach ($opciones['listas'] ?? [] as $i => $valores) {
        $validaciones .= '<dataValidation type="list" allowBlank="1" showErrorMessage="1" errorTitle="Valor no válido" error="Elija un valor de la lista." sqref="'
            . $col($i) . '2:' . $col($i) . $hasta . '"><formula1>"' . $x(implode(',', $valores)) . '"</formula1></dataValidation>';
    }
    if ($validaciones) $validaciones = '<dataValidations count="' . count($opciones['listas']) . '">' . $validaciones . '</dataValidations>';

    $archivos = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $x($hoja) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        // Estilos: 0 normal · 1 encabezado (negrita, fondo azul claro) · 2 número con 2 decimales · 3 texto (@)
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF0D3F6A"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8F2FB"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="4"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="49" applyNumberFormat="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . $cols . '<sheetData>' . $xmlFilas . '</sheetData><autoFilter ref="A1:' . $ultima . '"/>' . $validaciones . '</worksheet>',
    ];
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('No se pudo crear el archivo de Excel.');
    foreach ($archivos as $nombre => $contenido) $zip->addFromString($nombre, $contenido);
    $zip->close();
    $datos = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $datos;
}

// ── Importar desde Excel (.xlsx) ─────────────────────────────────────
// Lector de la primera hoja de un .xlsx subido por un usuario: solo acepta un libro de Excel real
// (ZIP con el tipo de contenido de hoja de cálculo), con límites de tamaño y filas, y sin DOCTYPE
// (sin entidades externas ni "bombas" XML). Devuelve [número de fila => [índice de columna => texto]],
// solo las filas con algún valor. Lanza RuntimeException con un mensaje para el usuario.
const XLSX_MAX_XML = 20 * 1048576;   // tamaño máximo de cada parte descomprimida

function leerXlsx(string $ruta, int $maxFilas = 10000): array {
    $f = @fopen($ruta, 'rb');
    $firma = $f ? fread($f, 4) : '';
    if ($f) fclose($f);
    if ($firma !== "PK\x03\x04") throw new RuntimeException('El archivo no es un libro de Excel (.xlsx). Guárdelo como "Libro de Excel" e inténtelo de nuevo.');
    $zip = new ZipArchive();
    if ($zip->open($ruta, ZipArchive::RDONLY) !== true) throw new RuntimeException('El archivo de Excel está dañado o no se puede abrir.');
    try {
        $leer = function (string $parte, bool $requerida = true) use ($zip): ?SimpleXMLElement {
            $st = $zip->statName($parte);
            if ($st === false) {
                if ($requerida) throw new RuntimeException('El archivo no tiene la estructura de un libro de Excel (.xlsx).');
                return null;
            }
            if ($st['size'] > XLSX_MAX_XML) throw new RuntimeException('El archivo es demasiado grande para importarlo.');
            $xml = $zip->getFromName($parte);
            if ($xml === false || stripos($xml, '<!DOCTYPE') !== false) throw new RuntimeException('El archivo de Excel tiene contenido no permitido.');
            $prev = libxml_use_internal_errors(true);
            $sx = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if ($sx === false) throw new RuntimeException('El archivo de Excel está dañado.');
            return $sx;
        };
        // Debe declararse como libro de Excel (no .xlsm con macros, ni Word/PowerPoint renombrados)
        $tipos = $leer('[Content_Types].xml');
        $esLibro = false;
        foreach ($tipos->Override as $o) {
            if ((string)$o['ContentType'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml') $esLibro = true;
        }
        if (!$esLibro) throw new RuntimeException('El archivo no es un libro de Excel (.xlsx) sin macros.');

        // Primera hoja del libro
        $libro = $leer('xl/workbook.xml');
        $hoja = $libro->sheets->sheet[0] ?? null;
        if (!$hoja) throw new RuntimeException('El libro de Excel no tiene hojas.');
        $rid = (string)$hoja->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $destino = null;
        foreach ($leer('xl/_rels/workbook.xml.rels')->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) $destino = (string)$rel['Target'];
        }
        if ($destino === null || str_contains($destino, '..')) throw new RuntimeException('El archivo no tiene la estructura de un libro de Excel (.xlsx).');
        $destino = str_starts_with($destino, '/') ? ltrim($destino, '/') : 'xl/' . $destino;

        // Texto de un <si> o <is>: simple, o en tramos con formato (sin la guía fonética rPh)
        $textoDe = function (SimpleXMLElement $e): string {
            if (isset($e->t)) return (string)$e->t;
            $t = '';
            foreach ($e->r as $r) $t .= (string)$r->t;
            return $t;
        };
        $compartidos = [];
        if ($ss = $leer('xl/sharedStrings.xml', false)) {
            foreach ($ss->si as $si) $compartidos[] = $textoDe($si);
        }

        $filas = [];
        $sig = 1;
        foreach ($leer($destino)->sheetData->row as $row) {
            $n = isset($row['r']) ? (int)$row['r'] : $sig;
            $sig = $n + 1;
            if ($n > $maxFilas + 1) throw new RuntimeException("El archivo tiene más de $maxFilas filas: divídalo e impórtelo por partes.");
            $celdas = [];
            $colSig = 0;
            foreach ($row->c as $c) {
                $i = $colSig;
                if (isset($c['r']) && preg_match('/^([A-Z]{1,3})\d+$/', (string)$c['r'], $m)) {
                    $i = 0;
                    foreach (str_split($m[1]) as $l) $i = $i * 26 + ord($l) - 64;
                    $i--;
                }
                $colSig = $i + 1;
                $v = match ((string)$c['t']) {
                    's'         => $compartidos[(int)$c->v] ?? '',
                    'inlineStr' => isset($c->is) ? $textoDe($c->is) : '',
                    'b'         => (string)$c->v === '1' ? 'Sí' : 'No',
                    default     => (string)$c->v,
                };
                $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
                if ($v !== '') $celdas[$i] = $v;
            }
            if ($celdas) $filas[$n] = $celdas;
        }
        return $filas;
    } finally {
        $zip->close();
    }
}

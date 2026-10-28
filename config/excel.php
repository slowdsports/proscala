<?php
// ── Exportar a Excel (.xlsx) ─────────────────────────────────────────
// Escritor mínimo de hojas de cálculo Office Open XML con ZipArchive (sin librerías externas):
// una hoja, encabezado en negrita y fijo, montos como números con formato 0.00 y anchos de columna
// según el contenido. Lo usa exportar_excel.php.

/** Valor numérico de un texto como "L. 1,234.56", "(1,234.56)" o "15", o null si no es un número. */
function numeroExcel(string $v): ?float {
    $t = trim(str_replace(["\u{00A0}", ' '], '', $v));
    if (!preg_match('/^(-|\()?(L\.)?-?[\d,]*\.?\d+\)?%?$/u', $t) || preg_match('/^0\d/', ltrim($t, '-(L.'))) return null;   // sin ceros a la izquierda (identidad, cuentas)
    $neg = str_starts_with($t, '-') || str_starts_with($t, '(');
    $n = (float)str_replace([',', 'L.', '(', ')', '-', '%'], '', $t);
    return $neg ? -$n : $n;
}

/** Contenido binario de un .xlsx con $columnas (encabezados) y $filas (arreglos de textos). */
function generarXlsx(string $hoja, array $columnas, array $filas): string {
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
            if (($num = numeroExcel($v)) !== null) $xmlFilas .= "<c r=\"$ref\" s=\"" . (str_contains($v, '.') ? '2' : '0') . "\"><v>$num</v></c>";
            elseif ($v !== '') $xmlFilas .= "<c r=\"$ref\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>';
        }
        $xmlFilas .= '</row>';
    }
    $cols = '<cols>';
    foreach ($anchos as $i => $a) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . min(60, max(8, $a + 2)) . '" customWidth="1"/>';
    $cols .= '</cols>';
    $ultima = $col(max(0, count($columnas) - 1)) . (count($filas) + 1);

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
        // Estilos: 0 normal · 1 encabezado (negrita, fondo azul claro) · 2 número con 2 decimales
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FF0D3F6A"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE8F2FB"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="3"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/><xf numFmtId="164" applyNumberFormat="1"/></cellXfs></styleSheet>',
        'xl/worksheets/sheet1.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . $cols . '<sheetData>' . $xmlFilas . '</sheetData><autoFilter ref="A1:' . $ultima . '"/></worksheet>',
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

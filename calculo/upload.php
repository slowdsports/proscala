<?php
/**
 * upload.php
 * -----------------------------------------------------------------------
 * Recibe el archivo .xlsx subido (plantilla "Empleados"), lee las filas con
 * XlsxReader, y devuelve la lista de empleados a procesar en JSON.
 *
 * IMPORTANTE: este endpoint SOLO lee y valida el Excel — NO llama a la STSS
 * aquí (eso tardaría demasiado para una sola petición HTTP con muchas filas).
 * El navegador (index.php) recibe esta lista y luego llama a api.php fila
 * por fila, igual que en el llenado manual, mostrando progreso en vivo.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/../src/XlsxReader.php';

function responder(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['archivo'])) {
    responder(['ok' => false, 'error' => 'Suba un archivo .xlsx en el campo "archivo".'], 400);
}

$archivo = $_FILES['archivo'];
if ($archivo['error'] !== UPLOAD_ERR_OK) {
    responder(['ok' => false, 'error' => 'Error al subir el archivo (código ' . $archivo['error'] . ').'], 400);
}

$extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
if ($extension !== 'xlsx') {
    responder(['ok' => false, 'error' => 'El archivo debe ser .xlsx (la plantilla descargable).'], 400);
}

try {
    $lector = new XlsxReader($archivo['tmp_name']);
    $filas = $lector->leerHoja('Empleados');
} catch (XlsxReaderException $e) {
    responder(['ok' => false, 'error' => $e->getMessage()], 422);
}

$MOTIVOS_VALIDOS = [
    'Despido', 'Renuncia', 'Despido Justificado',
    'Por Muerte Natural', 'Cálculo de Anticipo de Cesantía',
];

$empleados = [];
$avisos = [];

foreach ($filas as $numFila => $celdas) {
    if ($numFila < 5) {
        continue; // filas 1-4 son título/subtítulo/encabezado de la plantilla
    }
    $nombre = trim($celdas['A'] ?? '');
    $dni = trim($celdas['B'] ?? '');
    if ($nombre === '' && $dni === '') {
        continue; // fila vacía, se ignora silenciosamente
    }

    $fechaIngreso = XlsxReader::convertirFechaSerial($celdas['C'] ?? '');
    $fechaFin = XlsxReader::convertirFechaSerial($celdas['D'] ?? '');
    $motivo = trim($celdas['E'] ?? '');
    $salario = trim($celdas['F'] ?? '');
    $empresa = trim($celdas['G'] ?? '');
    $sexo = trim($celdas['H'] ?? '');

    $faltantes = [];
    if ($nombre === '') $faltantes[] = 'Nombre';
    if ($dni === '') $faltantes[] = 'DNI';
    if (!$fechaIngreso) $faltantes[] = 'Fecha Ingreso';
    if (!$fechaFin) $faltantes[] = 'Fecha Finalización';
    if ($motivo === '') $faltantes[] = 'Motivo';
    if ($salario === '' || !is_numeric($salario)) $faltantes[] = 'Salario';

    if (!empty($faltantes)) {
        $avisos[] = "Fila {$numFila}: se omite — faltan/son inválidos: " . implode(', ', $faltantes) . '.';
        continue;
    }
    if (!in_array($motivo, $MOTIVOS_VALIDOS, true)) {
        $avisos[] = "Fila {$numFila}: Motivo \"{$motivo}\" no es válido — se omite. Use exactamente uno de: "
            . implode(' | ', $MOTIVOS_VALIDOS);
        continue;
    }

    $empleados[] = [
        'fila' => $numFila,
        'nombre' => $nombre,
        'dni' => $dni,
        'fecha_ingreso' => $fechaIngreso,
        'fecha_fin' => $fechaFin,
        'motivo' => $motivo,
        'salario' => (float) $salario,
        'empresa' => $empresa !== '' ? $empresa : null,
        'sexo' => $sexo !== '' ? $sexo : null,
    ];
}

if (empty($empleados)) {
    responder([
        'ok' => false,
        'error' => 'No se encontró ninguna fila válida en la plantilla.',
        'avisos' => $avisos,
    ], 422);
}

responder(['ok' => true, 'empleados' => $empleados, 'avisos' => $avisos]);

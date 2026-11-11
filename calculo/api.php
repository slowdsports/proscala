<?php
/**
 * api.php — Endpoint JSON
 * -----------------------------------------------------------------------
 * Recibe los datos de UN trabajador y devuelve el cálculo real obtenido
 * de la STSS en formato JSON. Pensado para ser llamado por index.php vía
 * fetch() para cada fila de la tabla masiva, o desde cualquier otro
 * sistema (Excel con Power Query, Postman, otro script, etc.).
 *
 * POST application/json o application/x-www-form-urlencoded con:
 *   nombre, dni, fecha_ingreso, fecha_fin, motivo, salario
 *   (opcionales: empresa, sexo, actividad_economica, alimentacion, vivienda)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // ajuste esto si lo expone públicamente
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../src/StssClient.php';

function responder(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(['ok' => false, 'error' => 'Use POST'], 405);
}

$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$entrada = is_array($json) ? $json : $_POST;

$datos = [
    'nombre'              => trim((string) ($entrada['nombre'] ?? '')),
    'dni'                 => trim((string) ($entrada['dni'] ?? '')),
    'fecha_ingreso'       => trim((string) ($entrada['fecha_ingreso'] ?? '')),
    'fecha_fin'           => trim((string) ($entrada['fecha_fin'] ?? '')),
    'motivo'              => trim((string) ($entrada['motivo'] ?? '')),
    'salario'             => $entrada['salario'] ?? null,
    'empresa'             => isset($entrada['empresa']) ? trim((string) $entrada['empresa']) : null,
    'sexo'                => isset($entrada['sexo']) ? trim((string) $entrada['sexo']) : null,
    'actividad_economica' => isset($entrada['actividad_economica']) ? trim((string) $entrada['actividad_economica']) : null,
    'alimentacion'        => !empty($entrada['alimentacion']),
    'vivienda'            => !empty($entrada['vivienda']),
];
$datos = array_filter($datos, fn($v) => $v !== null);
$conConstancia = !empty($entrada['con_constancia']);

try {
    $cliente = new StssClient();
    $resultado = $cliente->calcular($datos, $conConstancia);
    responder(['ok' => true, 'resultado' => $resultado]);
} catch (StssException $e) {
    responder(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    responder(['ok' => false, 'error' => 'Error inesperado: ' . $e->getMessage()], 500);
}

<?php
// ── Exportar a Excel ────────────────────────────────────────────────
// Recibe la tabla que el usuario está viendo (botón "Excel" de las listas y reportes, proscala.js →
// Excel) y la devuelve como .xlsx (config/excel.php). Solo exporta lo que el navegador ya muestra al
// usuario, con sus permisos; queda en la bitácora.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/excel.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
verifyCsrf();
$GLOBALS['_bitacora_explicita'] = true;

$datos = json_decode((string)($_POST['datos'] ?? ''), true);
$titulo = mb_substr(trim((string)($datos['titulo'] ?? 'Proscala')), 0, 80) ?: 'Proscala';
$columnas = array_map('strval', array_slice((array)($datos['columnas'] ?? []), 0, 60));
$filas = array_slice((array)($datos['filas'] ?? []), 0, 50000);
if (!$columnas) jsonErr('No hay datos para exportar.');

try { $xlsx = generarXlsx($titulo, $columnas, array_map(fn($f) => array_map('strval', array_slice((array)$f, 0, count($columnas))), $filas)); }
catch (Throwable $e) { jsonErr($e->getMessage(), 500); }
registrarEvento($pdo, 'EXPORTAR', ['modulo' => basename(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: 'exportar_excel.php'),
    'detalle' => "Exportó a Excel: $titulo (" . count($filas) . ' filas)']);

$archivo = trim(preg_replace('/[^\w\-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $titulo) ?: ''), '_') ?: 'Proscala';
$archivo .= '_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $archivo . '"');
header('Content-Length: ' . strlen($xlsx));
header('Cache-Control: no-store');
echo $xlsx;

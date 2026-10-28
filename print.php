<?php
// ── Impresión de documentos ────────────────────────────────────────
// Valida el pedido, carga los datos comunes de la empresa y entrega el documento con su
// plantilla: templates/print/<plantilla>.php (una por documento). Todas comparten el mismo
// diseño desde las partes comunes de esa carpeta:
//   _inicio.php         <head> con los estilos y la barra Cerrar / Descargar PDF
//   _org.php            bloque de la organización (logo, nombre, RTN…) del encabezado
//   _pagina_inicio.php  página común de los documentos de una página (con _pagina_fin.php)
//   _fin.php            hora de generación y descarga en PDF desde el navegador
//   _funciones.php      firmas y distribución por proyecto
// pdf_download.php incluye este archivo para generar el PDF en el servidor.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$tipo = $_GET['tipo'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if (!$id || !isset(DOCUMENTOS_MODULO[$tipo])) { http_response_code(400); die('Parámetros inválidos.'); }
if (!puedeVerDocumento($tipo)) { http_response_code(403); die('No tiene permiso para ver este documento.'); }

// Plantilla de cada tipo (las constancias comparten una)
$plantilla = in_array($tipo, ['trabajo', 'salario', 'referencia', 'tiempo'], true) ? 'constancia' : $tipo;

$config  = getAllConfig($pdo);
$org     = $config['nombre_organizacion'] ?? 'Proscala';
$orgDir  = $config['direccion'] ?? 'Tegucigalpa, Honduras';
$orgRtn   = $config['rtn'] ?? '';
$orgTel   = $config['telefono'] ?? '';
$orgEmail = $config['correo'] ?? '';
$orgFull  = $config['nombre_completo'] ?? '';
$orgInicial = mb_strtoupper(mb_substr($org, 0, 1));
$logoData   = logoEmpresaDataUri($pdo);
$meses   = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

$doc = $detalle = [];
$title = $subtitle = $docNum = '';

require_once __DIR__ . '/templates/print/_funciones.php';
require __DIR__ . '/templates/print/' . $plantilla . '.php';

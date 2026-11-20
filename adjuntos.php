<?php
// Documentos adjuntos de los procesos y expedientes (config/adjuntos.php): subir, ver/descargar,
// listar y quitar. No es un módulo: cada acción valida que el usuario pueda ver el registro.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_POST['_action'] ?? $_GET['action'] ?? '';

/** Adjunto por id, con su módulo, si el usuario puede ver el proceso; si no, termina. */
function adjuntoPermitido(PDO $pdo, int $id, bool $json): array {
    $s = $pdo->prepare("SELECT * FROM adjuntos WHERE id = ?");
    $s->execute([$id]);
    $a = $s->fetch();
    $modulo = $a ? moduloDeTabla($a['tabla']) : null;
    if (!$a || !$modulo || !puedeVerProceso($pdo, $modulo, (int)$a['registro_id'])) {
        if ($json) jsonErr('Documento no encontrado.', 404);
        http_response_code(404);
        die('Documento no encontrado.');
    }
    return [$a, $modulo];
}

// ── Ver o descargar ───────────────────────────────────────────────
if ($action === 'ver') {
    [$a] = adjuntoPermitido($pdo, (int)($_GET['id'] ?? 0), false);
    $ruta = rutaAdjunto($a);
    if (!is_file($ruta)) { http_response_code(404); die('El archivo ya no existe en el servidor.'); }
    $enLinea = empty($_GET['descargar']) && (str_starts_with($a['mime'], 'image/') || $a['mime'] === 'application/pdf');
    header('Content-Type: ' . $a['mime']);
    header('Content-Length: ' . filesize($ruta));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Content-Disposition: ' . ($enLinea ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^\w.\- ]/', '_', $a['nombre'])
        . "\"; filename*=UTF-8''" . rawurlencode($a['nombre']));
    readfile($ruta);
    exit;
}

// ── Lista (para refrescar el panel sin recargar la página) ────────
if ($action === 'lista') {
    $modulo = (string)($_GET['modulo'] ?? '');
    $id = (int)($_GET['id'] ?? 0);
    if (!puedeVerProceso($pdo, $modulo, $id)) jsonErr('Proceso no encontrado.', 404);
    $seccion = seccionAdjunto($modulo, $_GET['categoria'] ?? null);
    jsonOk(['html' => htmlAdjuntos($pdo, $modulo, $id, $seccion), 'total' => count(adjuntosPanel($pdo, $modulo, $id, $seccion))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_URL . paginaInicio()); exit; }
// Envío mayor que post_max_size: PHP descarta todo $_POST (también el token), así que se avisa antes
if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    jsonErr('Los archivos superan el tamaño que admite el servidor (' . adjuntosMaxMb() . ' MB). Súbalos de uno en uno o redúzcalos.', 413);
}
verifyCsrf();
$GLOBALS['_bitacora_explicita'] = true;   // cada acción registra su propio evento, en el módulo del proceso

// ── Subir uno o varios archivos ───────────────────────────────────
if ($action === 'subir') {
    $modulo = (string)($_POST['modulo'] ?? '');
    $id = (int)($_POST['registro_id'] ?? 0);
    if (!puedeVerProceso($pdo, $modulo, $id)) jsonErr('Proceso no encontrado.', 404);
    $tabla = tablaAdjuntos($modulo);
    // Expediente: categoría elegida en el panel (las que no son de la lista quedan en "Otros").
    // Proceso: la sección del panel (fotos del reporte de viaje) o ninguna.
    $seccion = seccionAdjunto($modulo, $_POST['categoria'] ?? null);
    $categoria = isset(EXPEDIENTES[$modulo]) ? (categoriaAdjunto($modulo, $_POST['categoria'] ?? null) ?? 'Otros') : $seccion;

    $archivos = archivosSubidos('archivos');
    if (!$archivos) {
        jsonErr('No se recibió ningún archivo. Si es muy grande, el servidor pudo rechazarlo (máximo ' . adjuntosMaxMb() . ' MB).');
    }
    $subidos = []; $errores = [];
    foreach ($archivos as $a) {
        // Una sección se imprime: solo fotos que el navegador muestra (JPG, PNG, WEBP o GIF)
        if ($seccion && $a['error'] === UPLOAD_ERR_OK && is_uploaded_file($a['tmp'])
            && !in_array(tipoAdjunto($a['tmp'], $a['nombre'])[0] ?? '', ADJUNTOS_IMAGENES, true)) {
            $errores[] = "{$a['nombre']}: solo se admiten fotos (JPG, PNG, WEBP o GIF)";
            continue;
        }
        $r = guardarAdjunto($pdo, $tabla, $id, $a, null, $categoria);
        if ($r === true) $subidos[] = $a['nombre']; else $errores[] = "{$a['nombre']}: $r";
    }

    if ($subidos) {
        registrarEvento($pdo, 'ADJUNTAR', ['modulo' => $modulo, 'tabla' => $tabla, 'registro_id' => $id,
            'detalle' => 'Adjuntó' . ($categoria ? " ($categoria)" : '') . ': ' . implode(', ', $subidos) . ($errores ? ' | Rechazados: ' . implode('; ', $errores) : '')]);
    } else {
        registrarEvento($pdo, 'ADJUNTAR', ['modulo' => $modulo, 'tabla' => $tabla, 'registro_id' => $id, 'resultado' => 'error',
            'detalle' => 'No se adjuntó: ' . implode('; ', $errores)]);
        jsonErr(implode('. ', $errores) . '.');
    }
    $msg = count($subidos) === 1 ? 'Documento adjuntado.' : count($subidos) . ' documentos adjuntados.';
    if ($errores) $msg .= ' No se adjuntaron: ' . implode('; ', $errores) . '.';
    jsonOk(['html' => htmlAdjuntos($pdo, $modulo, $id, $seccion), 'total' => count(adjuntosPanel($pdo, $modulo, $id, $seccion)), 'parcial' => (bool)$errores], $msg);
}

// ── Quitar ────────────────────────────────────────────────────────
if ($action === 'quitar') {
    [$a, $modulo] = adjuntoPermitido($pdo, (int)($_POST['id'] ?? 0), true);
    if (($motivo = puedeQuitarAdjunto($pdo, $a, $modulo)) !== true) {
        registrarEvento($pdo, 'ACCESO_DENEGADO', ['modulo' => $modulo, 'tabla' => $a['tabla'], 'registro_id' => $a['registro_id'],
            'resultado' => 'error', 'detalle' => "No pudo quitar el adjunto {$a['nombre']}: $motivo"]);
        jsonErr($motivo, 403);
    }
    $pdo->prepare("DELETE FROM adjuntos WHERE id = ?")->execute([$a['id']]);
    @unlink(rutaAdjunto($a));
    registrarEvento($pdo, 'QUITAR_ADJUNTO', ['modulo' => $modulo, 'tabla' => $a['tabla'], 'registro_id' => $a['registro_id'],
        'detalle' => "Quitó el adjunto: {$a['nombre']}"]);
    $seccion = seccionAdjunto($modulo, $a['categoria']);
    jsonOk(['html' => htmlAdjuntos($pdo, $modulo, (int)$a['registro_id'], $seccion), 'total' => count(adjuntosPanel($pdo, $modulo, (int)$a['registro_id'], $seccion))], 'Documento quitado.');
}

jsonErr('Acción no válida.');

<?php
// ── Envíos duplicados ───────────────────────────────────────────────
// Varios clics seguidos en Guardar / Crear mandan la misma petición varias veces y crearían
// el mismo registro repetido. Cada usuario tiene, por módulo, la huella de su último envío
// (envios_recientes): si llega otro idéntico antes de VENTANA_ENVIO_DUPLICADO segundos desde
// que el anterior terminó bien, se rechaza sin ejecutar nada.
//   · Solo se compara con el último envío: A → B → A no es un duplicado (p. ej. marcar y desmarcar).
//   · Si el envío anterior falló, se libera y se puede reintentar de inmediato.
//   · La comparación y el registro son una sola sentencia: dos peticiones simultáneas no pasan ambas.
const VENTANA_ENVIO_DUPLICADO = 10;
// Peticiones POST que solo consultan o que se repiten a propósito
const ENVIOS_SIN_CONTROL = ['sesion.php', 'notificaciones.php', 'buscar.php', 'exportar_excel.php', 'borradores.php'];
const ACCIONES_SIN_CONTROL = ['calcular', 'items_sc', 'get_co_data', 'conexion'];

/** Huella del envío: módulo, campos (sin el token de seguridad), archivos adjuntos y cuerpo JSON. */
function huellaEnvio(string $script): string {
    $datos = $_POST;
    unset($datos['csrf_token']);
    $archivos = [];
    foreach ($_FILES as $k => $f) {
        foreach ((array)$f['name'] as $i => $n) {
            $tmp = is_array($f['tmp_name']) ? ($f['tmp_name'][$i] ?? '') : $f['tmp_name'];
            $archivos[] = [$k, $n, is_file($tmp) ? md5_file($tmp) : ''];
        }
    }
    $json = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json') ? (string)file_get_contents('php://input') : '';
    return hash('sha256', $script . "\n" . serialize($datos) . "\n" . serialize($archivos) . "\n" . $json);
}

/**
 * Llamada desde requireLogin en cada POST. Rechaza el envío si repite el último del usuario en
 * ese módulo; si no, lo anota y, al terminar la petición, lo da por terminado (o lo libera si falló).
 */
function controlarEnvioDuplicado(PDO $pdo, string $script): void {
    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    if (!$uid || in_array($script, ENVIOS_SIN_CONTROL, true) || in_array($_POST['_action'] ?? '', ACCIONES_SIN_CONTROL, true)) return;
    $huella = huellaEnvio($script);
    try {
        // Toma el lugar salvo que el último envío sea este mismo y reciente
        $s = $pdo->prepare("UPDATE envios_recientes SET huella = ?, enviado_at = NOW()
                            WHERE usuario_id = ? AND modulo = ?
                              AND NOT (huella = ? AND enviado_at > NOW() - INTERVAL " . VENTANA_ENVIO_DUPLICADO . " SECOND)");
        $s->execute([$huella, $uid, $script, $huella]);
        $tomado = $s->rowCount() > 0;
        if (!$tomado) {
            $s = $pdo->prepare("INSERT IGNORE INTO envios_recientes (usuario_id, modulo, huella, enviado_at) VALUES (?,?,?,NOW())");
            $s->execute([$uid, $script, $huella]);
            $tomado = $s->rowCount() > 0;
        }
    } catch (PDOException $e) {
        return;   // tabla aún no creada (falta actualizar_bd.php): no se bloquea el sistema
    }

    if (!$tomado) {
        registrarEvento($pdo, 'DUPLICADO', ['resultado' => 'error', 'detalle' => 'Envío repetido en pocos segundos: no se ejecutó'
                                            . (($_POST['_action'] ?? '') !== '' ? ' (' . $_POST['_action'] . ')' : '')]);
        $msg = 'Esta acción ya se envió hace unos segundos y no se repitió. Revise el listado antes de intentarlo de nuevo.';
        http_response_code(409);
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'duplicado' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE));
        }
        die($msg);
    }

    // Al terminar: la ventana cuenta desde que terminó (una petición lenta no deja pasar la repetida);
    // si falló, se libera para poder corregir y reintentar. Se registra durante el cierre para correr
    // después de registrarPeticion, que aún puede convertir la respuesta en error (orden de pago)
    register_shutdown_function(fn() => register_shutdown_function(function () use ($pdo, $uid, $script, $huella) {
        $res   = $GLOBALS['_resultado'] ?? null;
        $err   = error_get_last();
        $fatal = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
        $fallo = $fatal || ($res && $res[0] === 'error') || http_response_code() >= 400;
        try {
            $pdo->prepare("UPDATE envios_recientes SET enviado_at = " . ($fallo ? "'2000-01-01'" : 'NOW()') . "
                           WHERE usuario_id = ? AND modulo = ? AND huella = ?")->execute([$uid, $script, $huella]);
        } catch (Throwable $e) {}
    }));
}

<?php
// Campana de la barra superior (assets/js/proscala.js → Notificaciones): lo pendiente de aprobar
// por el usuario y sus avisos (config/notificaciones.php). No es un módulo. La consulta
// automática no cuenta como actividad: no mantiene abierta la sesión de quien no trabaja.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') define('SIN_ACTIVIDAD', true);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $GLOBALS['_bitacora_explicita'] = true;   // marcar como leído no va a la bitácora
    $act = $_POST['_action'] ?? '';
    if ($act === 'leer') {
        $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE id = ? AND usuario_id = ?")
            ->execute([(int)($_POST['id'] ?? 0), $_SESSION['usuario_id']]);
    } elseif ($act === 'leer_todas') {
        $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE usuario_id = ? AND leida = 0")->execute([$_SESSION['usuario_id']]);
    } else {
        jsonErr('Acción desconocida.');
    }
    jsonOk(resumenNotificaciones($pdo), 'Listo.');
}

// De vez en cuando se borran los avisos leídos de hace más de 90 días
if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM notificaciones WHERE leida = 1 AND created_at < NOW() - INTERVAL 90 DAY");
jsonOk(resumenNotificaciones($pdo));

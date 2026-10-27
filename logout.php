<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
if (!empty($pdo) && isset($_SESSION['usuario_id'])) {
    registrarEvento($pdo, 'LOGOUT', ['detalle' => 'Cierre de sesión']);
}
$_SESSION = [];
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
header('Location: ' . BASE_URL . 'index.php');
exit;

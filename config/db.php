<?php
require_once __DIR__ . '/base.php';

// ── Sesión ─────────────────────────────────────────────────────────
// Cookie solo por HTTP (HttpOnly), del mismo sitio (SameSite=Lax) y, con HTTPS, segura.
// La sesión dura mientras el usuario trabaje: cada petición (y el pulso de proscala.js
// mientras hay actividad) la renueva; tras SESION_INACTIVIDAD sin actividad se cierra.
const SESION_INACTIVIDAD = 2 * 3600;
function iniciarSesion(): void {
    if (session_status() !== PHP_SESSION_NONE || PHP_SAPI === 'cli') return;
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'
          || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    // Sesiones en una carpeta propia: en un hosting compartido, la limpieza de otros sitios
    // (con su propio tiempo de vida) no borra las de Proscala antes de tiempo
    $dir = dirname(__DIR__) . '/storage/sesiones';
    if (is_dir($dir) || @mkdir($dir, 0700, true)) session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)(SESION_INACTIVIDAD + 600));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('PROSCALA');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();

    $ahora = time();
    if (isset($_SESSION['usuario_id'], $_SESSION['ultima_actividad']) && $ahora - $_SESSION['ultima_actividad'] > SESION_INACTIVIDAD) {
        $_SESSION = ['aviso_login' => 'Su sesión se cerró tras ' . (SESION_INACTIVIDAD / 3600) . ' horas sin actividad. Ingrese de nuevo.'];
        session_regenerate_id(true);
    }
    // Consultas automáticas (campana de notificaciones): no cuentan como actividad del usuario
    if (!defined('SIN_ACTIVIDAD')) $_SESSION['ultima_actividad'] = $ahora;
}
iniciarSesion();

// ── Conexión ───────────────────────────────────────────────────────
define('DB_HOST', credenciales()['db']['host']);
define('DB_MASTER', credenciales()['db']['master']);
define('DB_USER', credenciales()['db']['usuario']);
define('DB_PASS', credenciales()['db']['clave']);
define('DB_CHARSET', 'utf8mb4');

// Conexión a una base de datos ('' = sin base seleccionada)
function conectarBD(string $db): PDO {
    return new PDO(
        "mysql:host=".DB_HOST.($db !== '' ? ";dbname=$db" : '').";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

// $pdoMaster → base central (empresas y directorio de acceso)
// $pdo       → base de la empresa con la que inició sesión el usuario.
//              Es null sin sesión; requireLogin() redirige al login en ese caso.
try {
    $pdoMaster = conectarBD(DB_MASTER);
} catch (PDOException $e) {
    error_log('Proscala: sin conexión a la base central: ' . $e->getMessage());
    paginaError('No pudimos conectar con la base de datos',
        'El servidor de datos no responde en este momento. Puede ser una interrupción breve: espere un minuto e intente de nuevo. Sus datos están a salvo.', 'BD');
}
try {
    $pdo = !empty($_SESSION['empresa_db']) ? conectarBD($_SESSION['empresa_db']) : null;
} catch (PDOException $e) {
    error_log('Proscala: sin conexión a la base de la empresa ' . $_SESSION['empresa_db'] . ': ' . $e->getMessage());
    paginaError('No pudimos abrir los datos de su empresa',
        'La base de datos de ' . ($_SESSION['empresa_nombre'] ?? 'su empresa') . ' no está disponible en este momento. Intente de nuevo en unos minutos; si persiste, avise al administrador.', 'BDE');
}

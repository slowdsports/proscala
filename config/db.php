<?php
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
date_default_timezone_set('America/Tegucigalpa');

// ── Credenciales (fuera del código: ver config/credenciales.ejemplo.php) ──
function credenciales(): array {
    static $c = null;
    if ($c !== null) return $c;
    $raiz = dirname(__DIR__);
    $candidatos = array_filter([
        getenv('PROSCALA_CONFIG') ?: null,
        dirname($raiz) . '/proscala_config.php',
        dirname($raiz, 2) . '/proscala_config.php',
    ]);
    // El archivo local solo vale en desarrollo (XAMPP en Windows): en el servidor nunca
    if (PHP_OS_FAMILY === 'Windows') $candidatos[] = __DIR__ . '/credenciales.local.php';
    foreach ($candidatos as $f) {
        if (is_file($f) && is_array($datos = include $f)) return $c = $datos;
    }
    error_log('Proscala: no se encontró proscala_config.php (ver config/credenciales.ejemplo.php)');
    paginaError('El sistema no está configurado', 'Falta el archivo de configuración del servidor. Avise al administrador de Proscala.', 'CFG');
}

// ── Página de error (sin depender de la sesión ni de la base) ───────
/** Muestra el error al usuario (HTML o JSON según la petición) y termina. El detalle técnico va al log. */
function paginaError(string $titulo, string $mensaje, string $codigo = 'ERR', int $http = 503): void {
    $ref = $codigo . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    if (!headers_sent()) {
        http_response_code($http);
        header('Retry-After: 60');
        header('Cache-Control: no-store');
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest' || PHP_SAPI === 'cli') {
        if (PHP_SAPI !== 'cli' && !headers_sent()) header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => "$titulo. $mensaje (Ref. $ref)"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $hora = date('d/m/Y H:i');
    echo <<<HTML
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Proscala - {$h($titulo)}</title>
<style>
  :root { --p:#1B6BA8; --t:#0e1726; --t2:#526070; --b:#e4e9f0; --bg:#f0f4f8; --s:#fff; }
  @media (prefers-color-scheme: dark) { :root { --t:#e6edf5; --t2:#9fb0c2; --b:#243244; --bg:#0b1320; --s:#111c2d; } }
  * { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:16px;
         font-family: system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; background:var(--bg); color:var(--t); }
  .caja { max-width:460px; width:100%; background:var(--s); border:1px solid var(--b); border-radius:18px; padding:2.2rem 2rem; text-align:center;
          box-shadow:0 8px 24px rgba(0,0,0,.08); }
  .icono { width:64px; height:64px; margin:0 auto 1rem; border-radius:50%; display:flex; align-items:center; justify-content:center;
           background:rgba(229,57,53,.1); color:#E53935; font-size:30px; }
  h1 { font-size:1.25rem; margin:.2rem 0 .6rem; }
  p { color:var(--t2); font-size:.92rem; line-height:1.5; margin:0 0 1.2rem; }
  .acciones { display:flex; gap:.6rem; justify-content:center; flex-wrap:wrap; }
  a, button { font:inherit; font-size:.88rem; font-weight:600; padding:.6rem 1.1rem; border-radius:10px; cursor:pointer; text-decoration:none; }
  button { background:var(--p); color:#fff; border:none; }
  a { border:1px solid var(--b); color:var(--t); background:transparent; }
  .ref { margin-top:1.4rem; font-size:.74rem; color:var(--t2); }
  .ref code { font-family:ui-monospace,Consolas,monospace; background:var(--bg); padding:.1rem .4rem; border-radius:6px; }
</style></head>
<body><div class="caja">
  <div class="icono">!</div>
  <h1>{$h($titulo)}</h1>
  <p>{$h($mensaje)}</p>
  <div class="acciones">
    <button onclick="location.reload()">Reintentar</button>
    <a href="{$base}index.php">Ir al inicio de sesión</a>
  </div>
  <div class="ref">Si el problema continúa, comparta esta referencia con soporte:<br><code>{$h($ref)}</code> · {$h($hora)}</div>
</div>
<script>setTimeout(() => location.reload(), 60000);</script>
</body></html>
HTML;
    exit;
}

// Cualquier error no controlado: página amable en lugar de una pantalla en blanco
set_exception_handler(function (Throwable $e) {
    error_log('Proscala: ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
    paginaError('Ocurrió un error inesperado', 'No se pudo completar la operación. Intente de nuevo en unos momentos; si se repite, avise al administrador.', 'APP', 500);
});

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

<?php
// Base de todas las páginas, sin base de datos: errores, zona horaria, credenciales y página de
// error amable. La usan db.php (sistema) e index.php (página principal pública).
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
    <a href="{$base}login.php">Ir al inicio de sesión</a>
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

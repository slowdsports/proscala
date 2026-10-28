<?php
// ════════════════════════════════════════════════════════════════════════════
//  Proscala — Pruebas automáticas (en el equipo de desarrollo, antes de cada versión)
//    php tests/ejecutar.php            todas
//    php tests/ejecutar.php reglas     solo un grupo (estructura | reglas | http)
//  Usan la configuración local (config/credenciales.local.php) y la primera base de empresa que
//  exista. Las que modifican datos corren dentro de una transacción que se revierte; las de HTTP
//  necesitan Apache encendido (si no responde, se omiten). Esta carpeta no se despliega.
// ════════════════════════════════════════════════════════════════════════════
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
ini_set('display_errors', '1');
define('RAIZ', dirname(__DIR__));
$_SERVER['DOCUMENT_ROOT'] = RAIZ;
$_SERVER['SCRIPT_NAME'] = '/tests/ejecutar.php';
chdir(RAIZ);
require RAIZ . '/config/db.php';
require RAIZ . '/config/functions.php';
require RAIZ . '/config/auth.php';
require RAIZ . '/config/excel.php';
require RAIZ . '/config/esquema.php';

// ── Mini marco de pruebas ────────────────────────────────────────────
$GLOBALS['_pruebas'] = ['ok' => 0, 'fallas' => 0, 'omitidas' => 0];
final class Omitir extends Exception {}
function prueba(string $nombre, callable $fn): void {
    try {
        $fn();
        $GLOBALS['_pruebas']['ok']++;
        echo "  ✓ $nombre\n";
    } catch (Omitir $e) {
        $GLOBALS['_pruebas']['omitidas']++;
        echo "  - $nombre (omitida: {$e->getMessage()})\n";
    } catch (Throwable $e) {
        $GLOBALS['_pruebas']['fallas']++;
        echo "  ✗ $nombre\n      → {$e->getMessage()}" . ($e instanceof AssertionError ? '' : ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') . "\n";
    }
}
function afirmar($condicion, string $mensaje): void { if (!$condicion) throw new AssertionError($mensaje); }
function iguales($esperado, $obtenido, string $que): void {
    if ($esperado !== $obtenido) throw new AssertionError("$que: se esperaba " . var_export($esperado, true) . ', se obtuvo ' . var_export($obtenido, true));
}

/** Contexto de un usuario para las funciones del sistema (lo que pondría el inicio de sesión). */
function comoUsuario(PDO $pdo, int $usuarioId): void {
    $u = $pdo->query("SELECT * FROM usuarios WHERE id = $usuarioId")->fetch();
    $_SESSION['usuario_id'] = (int)$u['id'];
    $_SESSION['usuario_nombre'] = $u['nombre'];
    $_SESSION['usuario_rol'] = $u['rol'];
    $_SESSION['usuario_email'] = $u['email'];
    $_SESSION['empleado_id'] = $u['empleado_id'] ? (int)$u['empleado_id'] : null;
    $_SESSION['es_superadmin'] = false;
    roles(true);
    cargarPermisos($pdo, $u['rol']);
}

// ── Empresa de prueba: la primera cuya base exista ───────────────────
$empresa = null;
foreach ($pdoMaster->query("SELECT id, nombre, rtn, db_nombre FROM empresas ORDER BY id") as $e) {
    try { $GLOBALS['pdo'] = $pdo = conectarBD($e['db_nombre']); $empresa = $e; break; } catch (PDOException $x) {}
}
if (!$empresa) exit("No hay ninguna base de empresa disponible para las pruebas.\n");
$_SESSION = ['empresa_id' => (int)$empresa['id'], 'empresa_db' => $empresa['db_nombre'], 'empresa_nombre' => $empresa['nombre']];
echo "Proscala " . APP_VERSION . " · pruebas con {$empresa['nombre']} ({$empresa['db_nombre']})\n";

$grupos = ['estructura', 'reglas', 'http'];
$solo = $argv[1] ?? null;
foreach ($grupos as $g) {
    if ($solo && $solo !== $g) continue;
    echo "\n■ " . ucfirst($g) . "\n";
    require __DIR__ . "/casos/$g.php";
}
$r = $GLOBALS['_pruebas'];
echo "\n{$r['ok']} correcta(s), {$r['fallas']} falla(s)" . ($r['omitidas'] ? ", {$r['omitidas']} omitida(s)" : '') . "\n";
exit($r['fallas'] ? 1 : 0);

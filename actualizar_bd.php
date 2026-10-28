<?php
// ════════════════════════════════════════════════════════════════════════════
//  Proscala — Actualizar la base de datos después de desplegar una versión nueva
// ════════════════════════════════════════════════════════════════════════════
//  Actualiza la base central y la base de CADA empresa sin borrar datos:
//   1. Sincronización automática con database/proscala_master.sql y database/empresa_schema.sql:
//      crea las tablas, columnas e índices que falten. Nunca borra ni modifica lo que ya existe.
//   2. PASOS DE LA VERSIÓN (abajo): lo que no es solo agregar — borrar tablas, mover datos,
//      valores de configuración nuevos. ESTA LISTA SE REESCRIBE EN CADA VERSIÓN.
//  Primero muestra lo pendiente sin cambiar nada; solo aplica al confirmar. Se puede ejecutar
//  varias veces: lo que ya está al día se omite.
//
//  Cómo ejecutarlo (después de cada despliegue con cambios de base de datos):
//   · Navegador: https://sudominio.com/actualizar_bd.php → escriba la clave de mantenimiento
//     (proscala_config.php → 'mantenimiento' → 'clave'; sin clave configurada queda desactivado).
//   · Terminal de cPanel:  php actualizar_bd.php            (solo muestra lo pendiente)
//                          php actualizar_bd.php --aplicar  (aplica los cambios)
//  Haga un respaldo antes (cron/backup.php o el respaldo semanal del super administrador).
// ════════════════════════════════════════════════════════════════════════════
require_once __DIR__ . '/config/db.php';   // credenciales, conectarBD() y $pdoMaster
require_once __DIR__ . '/config/functions.php';

const VERSION_BD = '1.9.0';

// ── PASOS DE LA VERSIÓN ──────────────────────────────────────────────
// 'base': 'central' o 'empresa' · 'pendiente': fn(PDO) → bool · 'aplicar': fn(PDO) → void
// Producción tiene la estructura de la versión 1.3.0; estos pasos la llevan a VERSION_BD.
$PASOS = [
    [
        'base' => 'empresa',
        'descripcion' => 'Eliminar las tablas sin uso cuentas_bancarias y movimientos_bancarios (1.8.0)',
        'pendiente' => fn(PDO $db) => tablaExiste($db, 'movimientos_bancarios') || tablaExiste($db, 'cuentas_bancarias'),
        'aplicar' => function (PDO $db) {
            $db->exec('SET FOREIGN_KEY_CHECKS=0');
            $db->exec('DROP TABLE IF EXISTS `movimientos_bancarios`');
            $db->exec('DROP TABLE IF EXISTS `cuentas_bancarias`');
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
        },
    ],
    [
        'base' => 'empresa',
        'descripcion' => 'Configuración de recordatorios y escalamiento de procesos pendientes (1.9.0)',
        'pendiente' => fn(PDO $db) => !$db->query("SELECT 1 FROM configuracion WHERE clave = 'recordatorio_dias'")->fetchColumn(),
        'aplicar' => function (PDO $db) {
            $db->exec("INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES
                ('recordatorio_dias', '2', 'Días que un proceso espera antes de recordar a quien le toca aprobarlo'),
                ('escalamiento_dias', '5', 'Días que un proceso espera antes de avisar al siguiente nivel del organigrama')");
        },
    ],
];

// ── Requisitos del servidor (REQUISITOS.md) ──────────────────────────
/** [requisito, cumple, detalle, obligatorio] */
function requisitosServidor(PDO $pdo): array {
    $mb = function (string $v): float {
        $n = (float)$v;
        return match (strtoupper(substr(trim($v), -1))) { 'G' => $n * 1024, 'M' => $n, 'K' => $n / 1024, default => $n / 1048576 };
    };
    $r = [['PHP 8.0 o superior', PHP_VERSION_ID >= 80000, PHP_VERSION, true]];
    foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'openssl', 'zip', 'zlib', 'json', 'session'] as $ext) $r[] = ["Extensión $ext", extension_loaded($ext), '', true];
    $r[] = ['Extensión gd (fotos de perfil)', extension_loaded('gd'), 'recomendada', false];
    $r[] = ['upload_max_filesize ≥ 10M', $mb(ini_get('upload_max_filesize')) >= 10, ini_get('upload_max_filesize'), false];
    $r[] = ['post_max_size mayor que upload_max_filesize', $mb(ini_get('post_max_size')) > $mb(ini_get('upload_max_filesize')), ini_get('post_max_size'), false];
    $v = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
    $esMaria = stripos($v, 'mariadb') !== false;
    $r[] = ['MySQL 5.7+ / MariaDB 10.3+', version_compare(preg_replace('/[^0-9.].*$/', '', $v), $esMaria ? '10.3' : '5.7', '>='), $v, true];
    return $r;
}

// ── Utilidades de estructura ─────────────────────────────────────────
function tablaExiste(PDO $db, string $t): bool {
    $s = $db->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$t]);
    return (bool)$s->fetchColumn();
}
function columnasDe(PDO $db, string $t): array {
    $s = $db->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$t]);
    return array_map('strtolower', $s->fetchAll(PDO::FETCH_COLUMN));
}
function indicesDe(PDO $db, string $t): array {
    $s = $db->prepare("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $s->execute([$t]);
    return array_map('strtolower', $s->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Tablas de un archivo de esquema: nombre => ['sql' => CREATE completo, 'columnas' => [nombre => definición],
 * 'orden' => [nombres], 'indices' => [nombre => definición]].
 */
function leerEsquema(string $archivo): array {
    $sql = (string)file_get_contents($archivo);
    preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\n\)\s*(ENGINE[^;]*);/si', $sql, $m, PREG_SET_ORDER);
    $tablas = [];
    foreach ($m as [$completo, $nombre, $cuerpo]) {
        $t = ['sql' => preg_replace('/^CREATE TABLE (?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $completo),
              'columnas' => [], 'orden' => [], 'indices' => []];
        foreach (preg_split('/,\s*\n/', trim($cuerpo)) as $linea) {
            $linea = trim(preg_replace('/\s+/', ' ', $linea), " ,");
            if ($linea === '') continue;
            if (preg_match('/^(PRIMARY KEY|CONSTRAINT|FOREIGN KEY)/i', $linea)) continue;
            if (preg_match('/^(UNIQUE KEY|UNIQUE INDEX|KEY|INDEX)\s+`?(\w+)`?\s*(\(.*\))/i', $linea, $k)) {
                $t['indices'][strtolower($k[2])] = (stripos($k[1], 'UNIQUE') === 0 ? 'UNIQUE KEY' : 'KEY') . " `{$k[2]}` {$k[3]}";
                continue;
            }
            if (preg_match('/^`?(\w+)`?\s+(.+)$/', $linea, $c)) {
                // "id INT AUTO_INCREMENT PRIMARY KEY": la clave primaria no se agrega a una tabla existente
                $t['columnas'][strtolower($c[1])] = "`{$c[1]}` " . preg_replace('/\s+PRIMARY KEY\b/i', '', $c[2]);
                $t['orden'][] = strtolower($c[1]);
            }
        }
        $tablas[strtolower($nombre)] = $t;
    }
    return $tablas;
}

/** Cambios que le faltan a una base para coincidir con su esquema: lista de [descripción, sql]. */
function cambiosEsquema(PDO $db, array $esquema): array {
    $cambios = [];
    foreach ($esquema as $nombre => $t) {
        if (!tablaExiste($db, $nombre)) { $cambios[] = ["Crear la tabla $nombre", $t['sql']]; continue; }
        $existentes = columnasDe($db, $nombre);
        $anterior = null;
        foreach ($t['orden'] as $col) {
            if (!in_array($col, $existentes, true)) {
                $cambios[] = ["Agregar la columna $nombre.$col", "ALTER TABLE `$nombre` ADD COLUMN {$t['columnas'][$col]}" . ($anterior ? " AFTER `$anterior`" : ' FIRST')];
            }
            $anterior = $col;
        }
        $indices = indicesDe($db, $nombre);
        foreach ($t['indices'] as $idx => $def) {
            if (!in_array($idx, $indices, true)) $cambios[] = ["Agregar el índice $nombre.$idx", "ALTER TABLE `$nombre` ADD $def"];
        }
    }
    return $cambios;
}

// ── Acceso: terminal o clave de mantenimiento ────────────────────────
$cli = PHP_SAPI === 'cli';
$aplicar = $cli ? in_array('--aplicar', $argv ?? [], true) : (($_POST['aplicar'] ?? '') === '1');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (!$cli) {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    $clave = (string)(credenciales()['mantenimiento']['clave'] ?? '');
    $dada  = (string)($_POST['clave'] ?? '');
    if (strlen($clave) < 16) {
        http_response_code(403);
        exit('Desactivado: defina una clave de mantenimiento de al menos 16 caracteres en proscala_config.php (mantenimiento → clave), o ejecútelo desde la terminal.');
    }
    if (!hash_equals($clave, $dada)) {
        if ($dada !== '') sleep(2);   // frena los intentos de adivinar la clave
        $mensaje = $dada !== '' ? '<p style="color:#c62828">Clave incorrecta.</p>' : '';
        echo "<!DOCTYPE html><html lang=\"es\"><head><meta charset=\"UTF-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Actualizar base de datos</title></head>"
           . "<body style=\"font-family:system-ui,sans-serif;max-width:420px;margin:60px auto;padding:0 16px;color:#0e1726\">"
           . "<h2>Proscala · Actualizar base de datos</h2>$mensaje<form method=\"post\"><label>Clave de mantenimiento<br>"
           . "<input type=\"password\" name=\"clave\" autofocus required style=\"width:100%;padding:8px;margin:6px 0 12px\"></label>"
           . "<button style=\"padding:8px 18px\">Revisar cambios pendientes</button></form></body></html>";
        exit;
    }
}

// ── Revisión (y aplicación) por base ─────────────────────────────────
set_time_limit(0);
$l = $pdoMaster->query("SELECT GET_LOCK('proscala_actualizar_bd', 5)")->fetchColumn();
if ((int)$l !== 1) exit("Ya hay otra actualización en curso. Intente en unos minutos.\n");

$bases = [['nombre' => DB_MASTER, 'tipo' => 'central', 'etiqueta' => 'Base central']];
foreach ($pdoMaster->query("SELECT nombre, db_nombre FROM empresas ORDER BY id") as $e) {
    $bases[] = ['nombre' => $e['db_nombre'], 'tipo' => 'empresa', 'etiqueta' => $e['nombre']];
}
$esquemas = ['central' => leerEsquema(__DIR__ . '/database/proscala_master.sql'), 'empresa' => leerEsquema(__DIR__ . '/database/empresa_schema.sql')];

$informe = [];
$totalPendientes = 0;
$errores = 0;
foreach ($bases as $b) {
    $r = ['base' => $b, 'cambios' => [], 'error' => null, 'aplicados' => 0];
    try {
        $db = $b['tipo'] === 'central' ? $pdoMaster : conectarBD($b['nombre']);
        foreach (cambiosEsquema($db, $esquemas[$b['tipo']]) as [$desc, $sql]) $r['cambios'][] = ['desc' => $desc, 'sql' => $sql];
        foreach ($PASOS as $p) {
            if ($p['base'] === $b['tipo'] && ($p['pendiente'])($db)) $r['cambios'][] = ['desc' => $p['descripcion'], 'paso' => $p];
        }
        if ($aplicar) {
            $db->exec('SET FOREIGN_KEY_CHECKS=0');   // las tablas nuevas pueden referirse entre sí
            foreach ($r['cambios'] as &$c) {
                try {
                    isset($c['paso']) ? ($c['paso']['aplicar'])($db) : $db->exec($c['sql']);
                    $c['ok'] = true;
                    $r['aplicados']++;
                } catch (Throwable $e) {
                    $c['ok'] = false;
                    $c['error'] = $e->getMessage();
                    $errores++;
                }
            }
            unset($c);
            $db->exec('SET FOREIGN_KEY_CHECKS=1');
            if ($b['tipo'] === 'empresa' && tablaExiste($db, 'configuracion')) {
                $db->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES ('bd_version', ?, 'Versión de la estructura de la base (actualizar_bd.php)')
                              ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([VERSION_BD]);
            }
        }
    } catch (Throwable $e) {
        $r['error'] = $e->getMessage();
        $errores++;
    }
    $totalPendientes += count($r['cambios']);
    $informe[] = $r;
}
$pdoMaster->query("SELECT RELEASE_LOCK('proscala_actualizar_bd')");
$requisitos = requisitosServidor($pdoMaster);
$faltan = array_filter($requisitos, fn($q) => !$q[1] && $q[3]);

// ── Resultado ────────────────────────────────────────────────────────
if ($cli) {
    echo "Proscala · actualizar base de datos (estructura " . VERSION_BD . ")" . ($aplicar ? ' — APLICANDO' : ' — solo revisión') . "\n\n";
    echo "■ Servidor\n";
    foreach ($requisitos as [$req, $ok, $det, $oblig]) echo '  ' . ($ok ? '✓' : ($oblig ? '✗' : '!')) . " $req" . ($det !== '' ? " ($det)" : '') . "\n";
    echo "\n";
    foreach ($informe as $r) {
        echo "■ {$r['base']['etiqueta']} ({$r['base']['nombre']})\n";
        if ($r['error']) { echo "  ERROR: {$r['error']}\n\n"; continue; }
        if (!$r['cambios']) { echo "  Al día.\n\n"; continue; }
        foreach ($r['cambios'] as $c) {
            echo '  ' . (!$aplicar ? '· ' : (($c['ok'] ?? false) ? '✓ ' : '✗ ')) . $c['desc'] . (isset($c['error']) ? "  → {$c['error']}" : '') . "\n";
        }
        echo "\n";
    }
    echo !$aplicar ? ($totalPendientes ? "$totalPendientes cambio(s) pendiente(s). Ejecute con --aplicar para aplicarlos.\n" : "Todo está al día.\n")
                   : ($errores ? "Terminó con $errores error(es).\n" : "Actualización completa.\n");
    exit($errores ? 1 : 0);
}
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actualizar base de datos</title>
<style>
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; max-width: 860px; margin: 32px auto; padding: 0 16px; color: #0e1726; background: #f4f7fb; }
  .base { background: #fff; border: 1px solid #e4e9f0; border-radius: 12px; padding: 14px 18px; margin: 12px 0; }
  .base h3 { margin: 0 0 8px; font-size: 1rem; } .base small { color: #8ea3b4; font-weight: 400; }
  li { margin: 4px 0; font-size: .9rem; } code { font-size: .78rem; color: #526070; word-break: break-all; }
  .ok { color: #2e7d32; } .error { color: #c62828; } .aldia { color: #2e7d32; font-size: .9rem; }
  button { background: #1B6BA8; color: #fff; border: 0; border-radius: 8px; padding: 10px 20px; font-size: .95rem; cursor: pointer; }
  .aviso { background: #fff8e1; border: 1px solid #ffe08a; border-radius: 10px; padding: 10px 14px; font-size: .9rem; }
</style></head>
<body>
<h2>Proscala · Actualizar base de datos</h2>
<p>Estructura de la versión <strong><?= VERSION_BD ?></strong> · sistema <?= $h(APP_VERSION) ?> · <?= count($bases) ?> base(s)</p>
<?php if ($aplicar): ?>
  <p class="<?= $errores ? 'error' : 'ok' ?>"><strong><?= $errores ? "Terminó con $errores error(es). Revise el detalle." : 'Actualización completa.' ?></strong></p>
<?php elseif ($totalPendientes): ?>
  <p class="aviso"><?= $totalPendientes ?> cambio(s) pendiente(s). Revise la lista y haga un respaldo antes de aplicar.</p>
<?php else: ?>
  <p class="aldia"><strong>Todo está al día.</strong></p>
<?php endif; ?>
<div class="base">
  <h3>Servidor <small><?= $faltan ? count($faltan) . ' requisito(s) obligatorio(s) sin cumplir — ver REQUISITOS.md' : 'cumple los requisitos obligatorios' ?></small></h3>
  <ul><?php foreach ($requisitos as [$req, $ok, $det, $oblig]): ?>
    <li class="<?= $ok ? 'ok' : ($oblig ? 'error' : '') ?>"><?= $ok ? '✓' : ($oblig ? '✗' : '!') ?> <?= $h($req) ?><?= $det !== '' ? ' <small>(' . $h($det) . ')</small>' : '' ?></li>
  <?php endforeach; ?></ul>
</div>
<?php foreach ($informe as $r): ?>
  <div class="base">
    <h3><?= $h($r['base']['etiqueta']) ?> <small><?= $h($r['base']['nombre']) ?></small></h3>
    <?php if ($r['error']): ?><p class="error">No se pudo revisar: <?= $h($r['error']) ?></p>
    <?php elseif (!$r['cambios']): ?><p class="aldia">Al día.</p>
    <?php else: ?><ul>
      <?php foreach ($r['cambios'] as $c): ?>
      <li class="<?= $aplicar ? (($c['ok'] ?? false) ? 'ok' : 'error') : '' ?>"><?= $aplicar ? (($c['ok'] ?? false) ? '✓' : '✗') : '·' ?> <?= $h($c['desc']) ?>
        <?php if (isset($c['error'])): ?><br><code><?= $h($c['error']) ?></code><?php elseif (isset($c['sql']) && !$aplicar): ?><br><code><?= $h(mb_strimwidth($c['sql'], 0, 220, '…')) ?></code><?php endif; ?></li>
      <?php endforeach; ?>
    </ul><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$aplicar && $totalPendientes): ?>
  <form method="post">
    <input type="hidden" name="clave" value="<?= $h($_POST['clave'] ?? '') ?>">
    <input type="hidden" name="aplicar" value="1">
    <button>Aplicar los <?= $totalPendientes ?> cambio(s)</button>
  </form>
<?php endif; ?>
</body></html>

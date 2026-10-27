<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 *  Proscala — Respaldo automático (bases de datos + archivos subidos)
 * ════════════════════════════════════════════════════════════════════════════
 *
 *  Qué respalda en cada ejecución (una carpeta por fecha y hora):
 *    · La base central (empresas y accesos) y la base de CADA empresa registrada
 *      → <base>.sql.gz
 *    · Los archivos subidos: logos y documentos adjuntos (assets/uploads)
 *      → archivos_subidos.zip
 *  Borra los respaldos con más días que 'dias' (proscala_config.php → respaldos).
 *  Si algo falla termina con código 1 y, si se configuró 'avisar_a', envía un correo.
 *
 *  Configuración: proscala_config.php → 'respaldos' (ver config/credenciales.ejemplo.php)
 *    dir        → carpeta donde se guardan (FUERA de la web, p. ej. /home/u6613652/proscala_respaldos)
 *    dias       → días que se conservan (14 por defecto)
 *    mysqldump  → ruta de mysqldump si no está en el PATH (si no se puede usar, se respalda con PHP)
 *    avisar_a   → correo que recibe el aviso cuando un respaldo falla
 *
 * ────────────────────────────────────────────────────────────────────────────
 *  CRON EN CPANEL  (cPanel → Avanzado → Trabajos de cron → "Añadir nuevo trabajo de cron")
 * ────────────────────────────────────────────────────────────────────────────
 *  Configuración común: "Una vez al día" y en el campo Comando pegue UNA de estas líneas
 *  (ajuste la ruta si el sistema no está en /home/u6613652/web/ahdeco):
 *
 *    Todos los días a las 2:30 a. m.:
 *    30 2 * * * /usr/local/bin/php /home/u6613652/web/ahdeco/cron/backup.php >> /home/u6613652/proscala_respaldos/backup.log 2>&1
 *
 *    (En el formulario de cPanel: Minuto 30 · Hora 2 · Día * · Mes * · Día de la semana *
 *     y en Comando solo la parte que empieza en /usr/local/bin/php …)
 *
 *    Opcional, además: un respaldo a mediodía en días hábiles:
 *    0 13 * * 1-5 /usr/local/bin/php /home/u6613652/web/ahdeco/cron/backup.php >> /home/u6613652/proscala_respaldos/backup.log 2>&1
 *
 *  Notas:
 *   · Si /usr/local/bin/php no existe, use la versión de PHP del sitio, p. ej.
 *     /usr/local/bin/ea-php82 o /opt/alt/php82/usr/bin/php (cPanel → "Seleccionar versión de PHP").
 *   · Pruébelo una vez a mano desde cPanel → Terminal:
 *       /usr/local/bin/php /home/u6613652/web/ahdeco/cron/backup.php
 *     Debe terminar con "Respaldo completo" y crear la carpeta del día en proscala_respaldos.
 *   · cPanel envía por correo la salida de cada cron si "Correo electrónico de cron" tiene una
 *     dirección; con ">> …backup.log 2>&1" la salida queda en el log y no llega un correo diario.
 *   · Descargue de vez en cuando una copia FUERA del servidor (un respaldo en el mismo
 *     hosting no protege si se pierde la cuenta).
 *
 * ────────────────────────────────────────────────────────────────────────────
 *  RESTAURAR
 * ────────────────────────────────────────────────────────────────────────────
 *   Base de datos (Terminal; la base debe existir, vacía o para sobrescribir):
 *     gunzip < /home/u6613652/proscala_respaldos/2026-10-27_0230/ahdeco_admin.sql.gz | mysql -u USUARIO -p NOMBRE_BASE
 *   o en phpMyAdmin → Importar el archivo .sql.gz.
 *   Archivos subidos: descomprima archivos_subidos.zip dentro de la carpeta del sistema
 *   (recrea assets/uploads/…).
 * ════════════════════════════════════════════════════════════════════════════
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo se ejecuta desde el cron del servidor.');
}
set_time_limit(0);
ini_set('memory_limit', '512M');

require_once dirname(__DIR__) . '/config/db.php';      // credenciales() y conectarBD(); en CLI no abre sesión

$inicio = microtime(true);
$cfg    = (credenciales()['respaldos'] ?? []) + ['dir' => '', 'dias' => 14, 'mysqldump' => 'mysqldump', 'avisar_a' => ''];
$fallas = [];

function registro(string $msg): void { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; }
function tamano(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; }

// ── Carpeta de destino y bloqueo (evita dos respaldos a la vez) ───────
$dirBase = rtrim($cfg['dir'] ?: dirname(__DIR__, 2) . '/proscala_respaldos', '/\\');
if (!is_dir($dirBase) && !mkdir($dirBase, 0700, true)) { registro("ERROR: no se pudo crear $dirBase"); exit(1); }
$lock = fopen("$dirBase/.backup.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { registro('Ya hay un respaldo en curso; se omite esta ejecución.'); exit(0); }
$destino = "$dirBase/" . date('Y-m-d_Hi');
if (!is_dir($destino)) mkdir($destino, 0700, true);
registro("Respaldo en $destino");

// ── Bases a respaldar: la central y la de cada empresa ───────────────
$bases = [DB_MASTER];
foreach ($pdoMaster->query("SELECT db_nombre FROM empresas ORDER BY id") as $e) $bases[] = $e['db_nombre'];
$bases = array_values(array_unique($bases));

/** ¿Se puede ejecutar mysqldump en este servidor? */
function mysqldumpDisponible(string $bin): bool {
    if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) return false;
    @exec(escapeshellarg($bin) . ' --version 2>&1', $out, $code);
    return $code === 0;
}

/** Respaldo con mysqldump (consistente y rápido). Credenciales en un archivo temporal, no en la línea de comandos. */
function respaldarConMysqldump(string $bin, string $base, string $archivoGz): ?string {
    $cnf = tempnam(sys_get_temp_dir(), 'psc');
    file_put_contents($cnf, "[client]\nuser=\"" . DB_USER . "\"\npassword=\"" . DB_PASS . "\"\nhost=\"" . DB_HOST . "\"\n");
    chmod($cnf, 0600);
    $sql = substr($archivoGz, 0, -3);
    $cmd = escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf)
         . ' --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 '
         . escapeshellarg($base) . ' > ' . escapeshellarg($sql) . ' 2>&1';
    exec($cmd, $out, $code);
    unlink($cnf);
    if ($code !== 0 || !is_file($sql)) {
        $err = is_file($sql) ? trim((string)file_get_contents($sql, false, null, 0, 500)) : 'sin salida';
        @unlink($sql);
        return "mysqldump terminó con código $code: $err";
    }
    // gzip por partes (no carga el respaldo completo en memoria)
    $in = fopen($sql, 'rb'); $gz = gzopen($archivoGz, 'wb6');
    while (!feof($in)) gzwrite($gz, fread($in, 1048576));
    fclose($in); gzclose($gz); unlink($sql);
    return null;
}

/** Respaldo con PHP (si el hosting no permite mysqldump): estructura y datos tabla por tabla. */
function respaldarConPhp(string $base, string $archivoGz): ?string {
    try {
        $db = conectarBD($base);
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $gz = gzopen($archivoGz, 'wb6');
        gzwrite($gz, "-- Proscala: respaldo de `$base` (" . date('Y-m-d H:i:s') . ")\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        $tablas = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tablas as $t) {
            $crear = $db->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$crear;\n\n");
            $filas = $db->query("SELECT * FROM `$t`");
            $lote = [];
            while ($f = $filas->fetch(PDO::FETCH_NUM)) {
                $lote[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $db->quote((string)$v), $f)) . ')';
                if (count($lote) === 200) { gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $lote) . ";\n"); $lote = []; }
            }
            if ($lote) gzwrite($gz, "INSERT INTO `$t` VALUES\n" . implode(",\n", $lote) . ";\n");
            $filas->closeCursor();
            gzwrite($gz, "\n");
        }
        foreach ($db->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN) as $v) {
            gzwrite($gz, "DROP VIEW IF EXISTS `$v`;\n" . $db->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_NUM)[1] . ";\n\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n-- Respaldo completo\n");
        gzclose($gz);
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$usarMysqldump = mysqldumpDisponible($cfg['mysqldump']);
registro('Método: ' . ($usarMysqldump ? 'mysqldump' : 'PHP (mysqldump no disponible)'));
foreach ($bases as $base) {
    $archivo = "$destino/$base.sql.gz";
    $t0 = microtime(true);
    $error = $usarMysqldump ? respaldarConMysqldump($cfg['mysqldump'], $base, $archivo) : respaldarConPhp($base, $archivo);
    if (!$error && (!is_file($archivo) || filesize($archivo) < 200)) $error = 'el archivo quedó vacío';
    if ($error) { $fallas[] = "$base: $error"; registro("ERROR $base: $error"); continue; }
    registro(sprintf('OK  %-28s %8s  (%.1f s)', $base, tamano(filesize($archivo)), microtime(true) - $t0));
}

// ── Archivos subidos (logos y adjuntos) ──────────────────────────────
$uploads = dirname(__DIR__) . '/assets/uploads';
if (is_dir($uploads)) {
    try {
        if (!class_exists('ZipArchive')) throw new RuntimeException('la extensión zip de PHP no está instalada');
        $zip = new ZipArchive();
        if ($zip->open("$destino/archivos_subidos.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('no se pudo crear el zip');
        $n = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = 'assets/uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($uploads) + 1));
            $zip->addFile($f->getPathname(), $rel);
            $zip->setCompressionName($rel, preg_match('/\.(jpe?g|png|webp|gif|pdf|zip|docx|xlsx)$/i', $rel) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
            $n++;
        }
        $zip->close();
        registro(sprintf('OK  %-28s %8s  (%d archivos)', 'archivos_subidos.zip', tamano((int)@filesize("$destino/archivos_subidos.zip")), $n));
    } catch (Throwable $e) {
        $fallas[] = 'archivos subidos: ' . $e->getMessage();
        registro('ERROR archivos subidos: ' . $e->getMessage());
    }
}

// ── Limpieza: respaldos más viejos que 'dias' ────────────────────────
$limite = time() - max(1, (int)$cfg['dias']) * 86400;
foreach (glob("$dirBase/20*_*", GLOB_ONLYDIR) as $viejo) {
    if ($viejo === $destino || filemtime($viejo) >= $limite) continue;
    array_map('unlink', glob("$viejo/*"));
    @rmdir($viejo);
    registro('Eliminado respaldo antiguo: ' . basename($viejo));
}

// ── Resultado ───────────────────────────────────────────────────────
$duracion = round(microtime(true) - $inicio, 1);
if ($fallas) {
    registro("Respaldo con errores ($duracion s): " . implode(' | ', $fallas));
    if ($cfg['avisar_a']) {
        try {
            require_once dirname(__DIR__) . '/config/correo.php';
            $lista = '<ul><li>' . implode('</li><li>', array_map(fn($x) => htmlspecialchars($x), $fallas)) . '</li></ul>';
            enviarCorreo($cfg['avisar_a'], 'Proscala: el respaldo automático falló',
                plantillaCorreo('El respaldo automático falló', '<p>El respaldo del ' . date('d/m/Y H:i') . ' terminó con errores:</p>' . $lista
                    . '<p>Revise el archivo backup.log en la carpeta de respaldos.</p>'));
        } catch (Throwable $e) { registro('Tampoco se pudo enviar el aviso por correo: ' . $e->getMessage()); }
    }
    exit(1);
}
registro("Respaldo completo ($duracion s)");
exit(0);

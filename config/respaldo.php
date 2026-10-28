<?php
// ── Respaldo del sistema ─────────────────────────────────────────────
// Lo usan cron/backup.php (respaldo automático diario en el servidor) y respaldo.php (descarga
// semanal del super administrador). Respalda la base central, la de cada empresa y los archivos
// subidos (logos, fotos y documentos adjuntos).

/** Bases a respaldar: la central y la de cada empresa registrada. */
function basesARespaldar(PDO $pdoMaster): array {
    $bases = [DB_MASTER];
    foreach ($pdoMaster->query("SELECT db_nombre FROM empresas ORDER BY id") as $e) $bases[] = $e['db_nombre'];
    return array_values(array_unique($bases));
}

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

/** Respalda una base en $archivoGz con mysqldump si se puede; si no, con PHP. null o el error. */
function respaldarBase(string $base, string $archivoGz, string $mysqldump): ?string {
    static $usarMysqldump = null;
    $usarMysqldump ??= mysqldumpDisponible($mysqldump);
    $error = $usarMysqldump ? respaldarConMysqldump($mysqldump, $base, $archivoGz) : respaldarConPhp($base, $archivoGz);
    if (!$error && (!is_file($archivoGz) || filesize($archivoGz) < 200)) $error = 'el archivo quedó vacío';
    return $error;
}

/**
 * Agrega los archivos subidos (assets/uploads) a un zip abierto, bajo $prefijo. Devuelve cuántos.
 * Lo ya comprimido (fotos, PDF, Office) se guarda sin volver a comprimir.
 */
function zipArchivosSubidos(ZipArchive $zip, string $prefijo = 'assets/uploads/'): int {
    $uploads = dirname(__DIR__) . '/assets/uploads';
    if (!is_dir($uploads)) return 0;
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = $prefijo . str_replace('\\', '/', substr($f->getPathname(), strlen($uploads) + 1));
        $zip->addFile($f->getPathname(), $rel);
        $zip->setCompressionName($rel, preg_match('/\.(jpe?g|png|webp|gif|pdf|zip|docx|xlsx)$/i', $rel) ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
        $n++;
    }
    return $n;
}

function tamanoRespaldo(int $b): string { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; }

<?php
// Estructura de la base de datos: lectura de los esquemas (database/*.sql), comparación con una
// base real y requisitos del servidor. Lo usan actualizar_bd.php, empresas.php y las pruebas (tests/).

/** Ejecuta un archivo .sql sentencia por sentencia (alta de una empresa en empresas.php). */
function ejecutarArchivoSQL(PDO $db, string $archivo): void {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents($archivo));
    foreach (preg_split('/;\s*$/m', $sql) as $sentencia) {
        if (trim($sentencia) !== '') $db->exec($sentencia);
    }
}

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
    // Desde el navegador: la dirección de proscala_config.php ('url') es la de los enlaces de los
    // correos (activar la cuenta, restablecer la contraseña); debe ser la que se está usando
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $url = rtrim((string)(credenciales()['url'] ?? ''), '/');
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $actual = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(BASE_URL, '/');
        $r[] = ['Dirección del sistema (url) para los enlaces de los correos', strcasecmp($url, $actual) === 0,
                $url === '' ? "sin definir; debería ser $actual" : (strcasecmp($url, $actual) === 0 ? $url : "es $url y debería ser $actual"), false];
    }
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

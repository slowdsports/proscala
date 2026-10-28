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
require_once dirname(__DIR__) . '/config/respaldo.php';

$inicio = microtime(true);
$cfg    = (credenciales()['respaldos'] ?? []) + ['dir' => '', 'dias' => 14, 'mysqldump' => 'mysqldump', 'avisar_a' => ''];
$fallas = [];

function registro(string $msg): void { echo '[' . date('Y-m-d H:i:s') . "] $msg\n"; }

// ── Carpeta de destino y bloqueo (evita dos respaldos a la vez) ───────
$dirBase = rtrim($cfg['dir'] ?: dirname(__DIR__, 2) . '/proscala_respaldos', '/\\');
if (!is_dir($dirBase) && !mkdir($dirBase, 0700, true)) { registro("ERROR: no se pudo crear $dirBase"); exit(1); }
$lock = fopen("$dirBase/.backup.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { registro('Ya hay un respaldo en curso; se omite esta ejecución.'); exit(0); }
$destino = "$dirBase/" . date('Y-m-d_Hi');
if (!is_dir($destino)) mkdir($destino, 0700, true);
registro("Respaldo en $destino");

// ── Bases: la central y la de cada empresa (config/respaldo.php) ─────
registro('Método: ' . (mysqldumpDisponible($cfg['mysqldump']) ? 'mysqldump' : 'PHP (mysqldump no disponible)'));
foreach (basesARespaldar($pdoMaster) as $base) {
    $archivo = "$destino/$base.sql.gz";
    $t0 = microtime(true);
    if ($error = respaldarBase($base, $archivo, $cfg['mysqldump'])) { $fallas[] = "$base: $error"; registro("ERROR $base: $error"); continue; }
    registro(sprintf('OK  %-28s %8s  (%.1f s)', $base, tamanoRespaldo(filesize($archivo)), microtime(true) - $t0));
}

// ── Archivos subidos (logos y adjuntos) ──────────────────────────────
try {
    if (!class_exists('ZipArchive')) throw new RuntimeException('la extensión zip de PHP no está instalada');
    $zip = new ZipArchive();
    if ($zip->open("$destino/archivos_subidos.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('no se pudo crear el zip');
    $n = zipArchivosSubidos($zip);
    $zip->close();
    if ($n) registro(sprintf('OK  %-28s %8s  (%d archivos)', 'archivos_subidos.zip', tamanoRespaldo((int)@filesize("$destino/archivos_subidos.zip")), $n));
} catch (Throwable $e) {
    $fallas[] = 'archivos subidos: ' . $e->getMessage();
    registro('ERROR archivos subidos: ' . $e->getMessage());
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

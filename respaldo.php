<?php
// ── Respaldo semanal del sistema (super administrador) ──────────────
// Al iniciar sesión el super administrador (login.php marca la sesión), includes/header.php abre
// esta página en un marco oculto y el navegador descarga un .zip con la base central, la base de
// cada empresa y los archivos subidos. Solo se entrega uno cada DIAS_RESPALDO_SEMANAL días
// (config/auth.php), aunque se pida de nuevo. El respaldo automático diario en el servidor es
// aparte (cron/backup.php).
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/respaldo.php';
requireLogin();

$fallar = function (int $codigo, string $mensaje) {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    exit($mensaje);
};
if (!esSuperadmin()) $fallar(403, 'Solo el super administrador descarga el respaldo del sistema.');
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_GET['t'] ?? ''))) $fallar(403, 'Enlace vencido. Vuelva a iniciar sesión.');
unset($_SESSION['respaldo_semanal']);
if (!respaldoSemanalPendiente($pdoMaster)) {
    $ult = $pdoMaster->query("SELECT MAX(created_at) FROM respaldos_descargas")->fetchColumn();
    $fallar(429, 'Ya se descargó el respaldo de esta semana. El próximo estará disponible el '
        . date('d/m/Y H:i', strtotime($ult . ' +' . DIAS_RESPALDO_SEMANAL . ' days')) . '.');
}
if (!class_exists('ZipArchive')) $fallar(500, 'El servidor no tiene la extensión zip de PHP.');

// La sesión se libera para que el super administrador siga trabajando mientras se genera
$email = $_SESSION['usuario_email'] ?? '';
session_write_close();
set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '512M');

// Un solo respaldo a la vez (dos pestañas abiertas no generan dos)
$tmp = sys_get_temp_dir() . '/proscala_respaldo_' . bin2hex(random_bytes(6));
$lock = fopen(sys_get_temp_dir() . '/proscala_respaldo.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) $fallar(429, 'Ya se está generando un respaldo. Espere a que termine.');
mkdir($tmp, 0700, true);
$limpiar = function () use ($tmp) {
    foreach (glob("$tmp/*") ?: [] as $f) @unlink($f);
    @rmdir($tmp);
};

try {
    $fecha = date('Y-m-d_Hi');
    $zipRuta = "$tmp/Proscala-respaldo-$fecha.zip";
    $zip = new ZipArchive();
    if ($zip->open($zipRuta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('no se pudo crear el archivo zip');
    $mysqldump = (string)(credenciales()['respaldos']['mysqldump'] ?? 'mysqldump');
    // Una base que falla no impide respaldar las demás; el respaldo incompleto se entrega, pero no
    // cuenta como el de la semana (se vuelve a intentar en el próximo inicio de sesión)
    $bases = $fallas = [];
    foreach (basesARespaldar($pdoMaster) as $base) {
        $gz = "$tmp/$base.sql.gz";
        if ($error = respaldarBase($base, $gz, $mysqldump)) { $fallas[] = "$base: " . strtok($error, "\n"); continue; }
        $zip->addFile($gz, "bases_de_datos/$base.sql.gz");
        $zip->setCompressionName("bases_de_datos/$base.sql.gz", ZipArchive::CM_STORE);
        $bases[] = $base;
    }
    if (!$bases) throw new RuntimeException('no se pudo respaldar ninguna base: ' . implode('; ', $fallas));
    $archivos = zipArchivosSubidos($zip, 'archivos_subidos/assets/uploads/');
    $zip->addFromString('LEEME.txt', "Proscala — respaldo completo del sistema\r\n"
        . 'Generado: ' . date('d/m/Y H:i') . ' por ' . $email . ' · versión ' . APP_VERSION . "\r\n\r\n"
        . "bases_de_datos/  Una copia .sql.gz de cada base (" . implode(', ', $bases) . ").\r\n"
        . "                 Restaurar: phpMyAdmin → Importar, o en la terminal:\r\n"
        . "                 gunzip < base.sql.gz | mysql -u USUARIO -p NOMBRE_BASE\r\n"
        . "archivos_subidos/ Logos, fotos y documentos adjuntos ($archivos archivos).\r\n"
        . "                 Restaurar: copie la carpeta assets/ dentro de la carpeta del sistema.\r\n\r\n"
        . ($fallas ? "\r\nATENCIÓN: RESPALDO INCOMPLETO. No se pudieron respaldar estas bases:\r\n  - " . implode("\r\n  - ", $fallas)
                   . "\r\nAvise al soporte de Proscala. Se intentará de nuevo en el próximo inicio de sesión.\r\n" : '')
        . "\r\nGuarde este archivo en un lugar seguro FUERA del servidor: contiene todos los datos.\r\n");
    $zip->close();
    clearstatcache();
    $tamano = (int)filesize($zipRuta);

    // Se anota antes de enviarlo: la regla de los 7 días cuenta desde que el respaldo se entrega
    if (!$fallas) {
        $pdoMaster->prepare("INSERT INTO respaldos_descargas (email, ip, tamano) VALUES (?,?,?)")
            ->execute([$email, $_SERVER['REMOTE_ADDR'] ?? '', $tamano]);
    }
    registrarEvento($pdo, 'RESPALDO', ['tabla' => null, 'resultado' => $fallas ? 'error' : 'ok',
        'detalle' => ($fallas ? 'Respaldo INCOMPLETO descargado (no cuenta como el semanal): ' : 'Respaldo completo del sistema descargado: ')
        . count($bases) . ' bases, ' . $archivos . ' archivos, ' . tamanoRespaldo($tamano) . ($fallas ? '. Faltaron: ' . implode('; ', $fallas) : '')]);

    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($zipRuta) . '"');
    header('Content-Length: ' . $tamano);
    header('Cache-Control: no-store');
    readfile($zipRuta);
} catch (Throwable $e) {
    error_log('Proscala: no se pudo generar el respaldo semanal: ' . $e->getMessage());
    $limpiar();
    $fallar(500, 'No se pudo generar el respaldo: ' . $e->getMessage());
}
$limpiar();

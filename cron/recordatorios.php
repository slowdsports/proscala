<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 *  Proscala — Recordatorios y escalamiento de procesos pendientes (cron diario)
 * ════════════════════════════════════════════════════════════════════════════
 *  En cada empresa, los procesos que llevan días esperando revisión o autorización:
 *   · a los "recordatorio_dias" se le recuerda a quien le toca (campana y correo);
 *   · a los "escalamiento_dias" se avisa al siguiente nivel del organigrama, y cada vez que
 *     vuelven a pasar esos días, al siguiente.
 *  Los días se configuran en Configuración. La lógica está en config/notificaciones.php
 *  (recordarPendientes).
 *
 *  CRON EN CPANEL (una vez al día, p. ej. 7:00 a. m.):
 *    0 7 * * * /usr/local/bin/php /home/u6613652/web/ahdeco/cron/recordatorios.php >> /home/u6613652/proscala_respaldos/recordatorios.log 2>&1
 *  Para ver qué se enviaría sin enviar nada:  php cron/recordatorios.php --revisar
 * ════════════════════════════════════════════════════════════════════════════
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Solo se ejecuta desde el cron del servidor.');
}
set_time_limit(0);
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);          // BASE_URL = "/" fuera del servidor web
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/functions.php';
require_once dirname(__DIR__) . '/config/auth.php';

$revisar = in_array('--revisar', $argv ?? [], true);
$registro = fn(string $m) => print('[' . date('Y-m-d H:i:s') . "] $m\n");
$registro('Recordatorios de procesos pendientes' . ($revisar ? ' (solo revisión: no se envía nada)' : ''));
$total = 0;
foreach ($pdoMaster->query("SELECT id, nombre, db_nombre FROM empresas WHERE activo = 1 ORDER BY id")->fetchAll() as $emp) {
    try {
        // Contexto de la empresa para las funciones del sistema (roles, avisos, correos)
        $GLOBALS['pdo'] = $pdo = conectarBD($emp['db_nombre']);
        $_SESSION = ['usuario_id' => 0, 'empresa_id' => (int)$emp['id'], 'empresa_nombre' => $emp['nombre'], 'empresa_db' => $emp['db_nombre']];
        roles(true);
        $avisos = recordarPendientes($pdo, !$revisar);
        foreach ($avisos as $a) {
            $registro(sprintf('  %s · %s · %s, %d días → %d usuario(s)', $emp['nombre'], $a['proceso'],
                $a['nivel'] ? "escalado al nivel {$a['nivel']}" : 'recordatorio', $a['dias'], count($a['usuarios'])));
        }
        $total += count($avisos);
        if ($avisos && !$revisar) enviarCorreosNotificacion();   // los correos de esta empresa
    } catch (Throwable $e) {
        $registro("  ERROR en {$emp['nombre']}: " . $e->getMessage());
    }
}
$registro("Listo: $total aviso(s)" . ($revisar ? ' (no enviados)' : ''));

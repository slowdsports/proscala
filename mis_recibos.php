<?php
// ── Mis recibos de pago ─────────────────────────────────────────────
// Para cada usuario vinculado a un empleado: sus recibos de las planillas PAGADAS (sueldos,
// beneficios y asignación), y nada más. Abre todos los usuarios con empleado, aunque su puesto
// no tenga acceso a los módulos de planillas: print.php / pdf_download.php validan que sea su
// propio recibo (esReciboPropio en config/auth.php). No muestra la planilla ni a los compañeros.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Mis recibos de pago';
$meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

$empleadoId = (int)($_SESSION['empleado_id'] ?? 0);
$recibos = [];
if ($empleadoId) {
    // Sueldos: un recibo por planilla
    $s = $pdo->prepare("SELECT p.*, d.sueldo_neto AS neto FROM planilla_periodos p JOIN planilla_detalle d ON d.periodo_id = p.id
                        WHERE p.estado = 'pagada' AND d.empleado_id = ?");
    $s->execute([$empleadoId]);
    foreach ($s->fetchAll() as $r) {
        $recibos[] = ['tipo' => 'plv', 'id' => (int)$r['id'], 'numero' => $r['numero'],
            'fecha' => $r['fecha_pago'] ?: sprintf('%04d-%02d-%02d', $r['anio'], $r['mes'], $r['quincena'] === 'primera' ? 15 : 28),
            'clase' => 'Planilla de sueldos', 'detalle' => $meses[$r['mes'] - 1] . ' ' . $r['anio'] . ' — ' . nombrePeriodoPlanilla($r), 'neto' => (float)$r['neto']];
    }
    // Beneficios: décimo tercer / cuarto mes
    $s = $pdo->prepare("SELECT b.id, b.numero, b.tipo, b.anio, b.fecha_pago, b.created_at, d.neto
                        FROM planilla_beneficios b JOIN planilla_beneficios_detalle d ON d.beneficio_id = b.id
                        WHERE b.estado = 'pagada' AND d.empleado_id = ?");
    $s->execute([$empleadoId]);
    foreach ($s->fetchAll() as $r) {
        $recibos[] = ['tipo' => 'pbv', 'id' => (int)$r['id'], 'numero' => $r['numero'], 'fecha' => $r['fecha_pago'] ?: substr($r['created_at'], 0, 10),
            'clase' => 'Planilla de beneficios', 'detalle' => ($r['tipo'] === 'decimo_tercero' ? 'Décimo Tercer Mes' : 'Décimo Cuarto Mes') . ' ' . $r['anio'],
            'neto' => (float)$r['neto']];
    }
    // Asignación: puede tener varios conceptos por empleado (se suman)
    $s = $pdo->prepare("SELECT a.id, a.numero, a.anio, a.mes, a.descripcion, SUM(d.monto) AS neto
                        FROM planilla_asignacion a JOIN planilla_asignacion_detalle d ON d.asignacion_id = a.id
                        WHERE a.estado = 'pagada' AND d.empleado_id = ? GROUP BY a.id");
    $s->execute([$empleadoId]);
    foreach ($s->fetchAll() as $r) {
        $recibos[] = ['tipo' => 'pav', 'id' => (int)$r['id'], 'numero' => $r['numero'], 'fecha' => sprintf('%04d-%02d-01', $r['anio'], $r['mes']),
            'clase' => 'Planilla de asignación', 'detalle' => trim($meses[$r['mes'] - 1] . ' ' . $r['anio'] . ($r['descripcion'] !== '' ? ' — ' . $r['descripcion'] : '')),
            'neto' => (float)$r['neto']];
    }
    usort($recibos, fn($a, $b) => [$b['fecha'], $b['id']] <=> [$a['fecha'], $a['id']]);
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-receipt"></i> Mis recibos de pago</h1>
</div>

<?php if (!$empleadoId): ?>
<div class="alert alert-info"><i class="fas fa-circle-info"></i> Su usuario no está vinculado a un empleado, por eso no tiene recibos de pago.
  Si debería tenerlos, pida al administrador que lo vincule en Empleados.</div>
<?php else: ?>
<div class="card"><div class="card-body p-0">
  <table id="tbl-recibos" class="table-proscala w-100">
    <thead><tr><th>Fecha</th><th>Planilla</th><th>Período</th><th class="text-end">Neto recibido</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($recibos as $r): $q = "tipo={$r['tipo']}&id={$r['id']}&emp_id={$empleadoId}"; ?>
      <tr>
        <td data-order="<?= htmlspecialchars($r['fecha']) ?>" class="text-nowrap"><?= fmtFecha($r['fecha']) ?></td>
        <td><?= htmlspecialchars($r['clase']) ?><?php if ($r['numero']): ?> <span class="font-mono text-muted small"><?= htmlspecialchars($r['numero']) ?></span><?php endif; ?></td>
        <td><?= htmlspecialchars($r['detalle']) ?></td>
        <td class="text-end font-mono fw-bold text-success text-nowrap"><?= lps($r['neto']) ?></td>
        <td class="text-end text-nowrap">
          <a href="<?= BASE_URL ?>print.php?<?= $q ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i> Ver</a>
          <a href="<?= BASE_URL ?>pdf_download.php?<?= $q ?>" class="btn btn-sm btn-outline-danger py-0 px-2"><i class="fas fa-file-pdf"></i> PDF</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$recibos): ?><tr><td colspan="5" class="text-center text-muted py-4">Aún no tiene recibos de planillas pagadas.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<p class="text-muted small mt-2"><i class="fas fa-lock"></i> Solo usted ve sus recibos. Aparecen cuando la planilla queda pagada.</p>
<?php endif; ?>
<?php
$extraJs = $recibos ? "initDataTable('#tbl-recibos', { order: [] });" : '';
include __DIR__ . '/includes/footer.php';
?>

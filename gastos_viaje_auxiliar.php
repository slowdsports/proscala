<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$pagina = 'Auxiliar de Viáticos';

// ── Datos principales ─────────────────────────────────────────────
// Roles que solo ven sus solicitudes: solo sus anticipos
[$fp, $fpParams] = filtroPropios('gastos_viajes.php', 'gv');
$filas = $pdo->prepare("
    SELECT
        gv.id            AS gv_id,
        gv.numero        AS gv_numero,
        gv.tipo          AS gv_tipo,
        gv.fecha_salida,
        gv.fecha_regreso,
        gv.destino,
        gv.viaticos_anticipados,
        gv.estado        AS gv_estado,
        e.id             AS emp_id,
        e.codigo         AS emp_codigo,
        CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
        e.cargo,
        COALESCE(liq.total_liquidado, 0)   AS total_liquidado,
        COALESCE(liq.num_liquidaciones, 0) AS num_liquidaciones,
        gv.viaticos_anticipados - COALESCE(liq.total_liquidado, 0) AS balance
    FROM gastos_viaje gv
    JOIN empleados e ON gv.empleado_id = e.id
    LEFT JOIN (
        SELECT viaje_id,
               SUM(monto_total) AS total_liquidado,
               COUNT(*)         AS num_liquidaciones
        FROM solicitud_gastos
        WHERE estado != 'rechazada' AND viaje_id IS NOT NULL
        GROUP BY viaje_id
    ) liq ON liq.viaje_id = gv.id
    WHERE $fp
    ORDER BY e.apellidos, e.nombre, gv.fecha_salida DESC
");
$filas->execute($fpParams);
$filas = $filas->fetchAll();

// ── Agrupar por empleado ──────────────────────────────────────────
$porEmpleado = [];
$totales = ['anticipo' => 0, 'liquidado' => 0, 'reintegrar' => 0, 'favor_emp' => 0];

foreach ($filas as $f) {
    $eid = $f['emp_id'];
    if (!isset($porEmpleado[$eid])) {
        $porEmpleado[$eid] = [
            'nombre'    => $f['emp_nombre'],
            'cargo'     => $f['cargo'],
            'codigo'    => $f['emp_codigo'],
            'viajes'    => [],
            'anticipo'  => 0,
            'liquidado' => 0,
        ];
    }
    $porEmpleado[$eid]['viajes'][]   = $f;
    $porEmpleado[$eid]['anticipo']  += $f['viaticos_anticipados'];
    $porEmpleado[$eid]['liquidado'] += $f['total_liquidado'];

    $totales['anticipo']  += $f['viaticos_anticipados'];
    $totales['liquidado'] += $f['total_liquidado'];
    if ($f['balance'] > 0) $totales['reintegrar'] += $f['balance'];
    if ($f['balance'] < 0) $totales['favor_emp']  += abs($f['balance']);
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-scale-balanced"></i> Auxiliar Contable — Anticipos (Viáticos y Compras)</h1>
</div>

<!-- ── Tarjetas de resumen ───────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-blue kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
      <div class="kpi-value"><?= lps($totales['anticipo']) ?></div>
      <div class="kpi-label">Total Anticipos</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-teal kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
      <div class="kpi-value"><?= lps($totales['liquidado']) ?></div>
      <div class="kpi-label">Total Liquidado</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-yellow kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-rotate-left"></i></div>
      <div class="kpi-value"><?= lps($totales['reintegrar']) ?></div>
      <div class="kpi-label">Emp. debe reintegrar</div>
      <div class="kpi-note">Anticipo mayor al gasto</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-green kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-arrow-right-to-bracket"></i></div>
      <div class="kpi-value"><?= lps($totales['favor_emp']) ?></div>
      <div class="kpi-label">Empresa debe al emp.</div>
      <div class="kpi-note">Gasto mayor al anticipo</div>
    </div>
  </div>
</div>

<!-- ── Tabla auxiliar ────────────────────────────────────────────── -->
<?php if (!$porEmpleado): ?>
<div class="card"><div class="card-body text-center text-muted py-5">No hay anticipos de viáticos registrados.</div></div>
<?php else: ?>

<?php foreach ($porEmpleado as $emp): ?>
<?php
$balEmp  = $emp['anticipo'] - $emp['liquidado'];
$empClass = $balEmp > 0 ? '#d97706' : ($balEmp < 0 ? '#16a34a' : '#64748b');
?>
<div class="card mb-3">
  <!-- Encabezado del empleado -->
  <div class="card-header" style="background:var(--surface-2);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <div>
      <span style="font-weight:700;font-size:.95rem"><?= htmlspecialchars($emp['nombre']) ?></span>
      <?php if ($emp['cargo']): ?>
      <span style="font-size:.75rem;color:var(--text-3);margin-left:6px"><?= htmlspecialchars($emp['cargo']) ?></span>
      <?php endif; ?>
      <span class="badge bg-secondary ms-2" style="font-size:.6rem"><?= htmlspecialchars($emp['codigo']) ?></span>
    </div>
    <div class="ms-auto d-flex gap-3" style="font-size:.8rem;">
      <span>Anticipos: <strong class="font-mono"><?= lps($emp['anticipo']) ?></strong></span>
      <span>Liquidado: <strong class="font-mono"><?= lps($emp['liquidado']) ?></strong></span>
      <span style="color:<?= $empClass ?>">
        <?= $balEmp >= 0 ? 'Por reintegrar:' : 'Empresa debe:' ?>
        <strong class="font-mono"><?= lps(abs($balEmp)) ?></strong>
      </span>
    </div>
  </div>

  <!-- Detalle de viajes -->
  <div class="card-body p-0">
    <table class="table-proscala w-100 mb-0">
      <thead>
        <tr>
          <th>Número</th>
          <th>Tipo</th>
          <th>Destino / Concepto</th>
          <th>Salida</th>
          <th>Regreso</th>
          <th>Estado</th>
          <th class="text-end">Anticipo</th>
          <th class="text-end">Liquidado</th>
          <th class="text-end">Balance</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($emp['viajes'] as $v):
          $bal = (float)$v['balance'];
          if ($bal == 0) {
              $balColor = '#16a34a'; $balIcon = '✓'; $balLabel = 'Saldado';
          } elseif ($bal > 0 && $v['num_liquidaciones'] == 0) {
              $balColor = '#64748b'; $balIcon = '⏳'; $balLabel = 'Sin liquidar';
          } elseif ($bal > 0) {
              $balColor = '#d97706'; $balIcon = '↩'; $balLabel = 'Emp. reintegra';
          } else {
              $balColor = '#2563eb'; $balIcon = '↪'; $balLabel = 'Empresa debe';
          }
        ?>
        <tr>
          <td class="font-mono" style="font-size:.82rem"><?= htmlspecialchars($v['gv_numero']) ?></td>
          <td><?= tipoAnticipoBadge($v['gv_tipo']) ?></td>
          <td><?= htmlspecialchars($v['destino']) ?></td>
          <td><?= fmtFecha($v['fecha_salida']) ?></td>
          <td><?= fmtFecha($v['fecha_regreso']) ?></td>
          <td><?= estadoBadge($v['gv_estado']) ?></td>
          <td class="font-mono text-end"><?= lps($v['viaticos_anticipados']) ?></td>
          <td class="font-mono text-end"><?= $v['num_liquidaciones'] > 0 ? lps($v['total_liquidado']) : '<span style="color:var(--text-3)">—</span>' ?></td>
          <td class="font-mono text-end" style="color:<?= $balColor ?>;font-weight:600">
            <?= $balIcon ?> <?= lps(abs($bal)) ?>
            <div style="font-size:.65rem;font-weight:400"><?= $balLabel ?></div>
          </td>
          <td>
            <a href="<?= BASE_URL ?>gastos_viajes.php?action=ver&id=<?= $v['gv_id'] ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2" title="Ver anticipo">
              <i class="fas fa-eye"></i>
            </a>
            <?php if ($v['num_liquidaciones'] > 0): ?>
            <a href="<?= BASE_URL ?>gastos_solicitud.php?viaje_id=<?= $v['gv_id'] ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1" title="Ver liquidación">
              <i class="fas fa-file-invoice-dollar"></i>
            </a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>gastos_solicitud.php?action=nuevo&viaje_id=<?= $v['gv_id'] ?>"
               class="btn btn-sm btn-outline-success py-0 px-2 ms-1" title="Registrar liquidación">
              <i class="fas fa-plus"></i>
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--surface-2);font-weight:600;font-size:.82rem">
          <td colspan="6" class="text-end" style="padding:7px 10px">Subtotal empleado:</td>
          <td class="font-mono text-end" style="padding:7px 10px"><?= lps($emp['anticipo']) ?></td>
          <td class="font-mono text-end" style="padding:7px 10px"><?= lps($emp['liquidado']) ?></td>
          <td class="font-mono text-end" style="padding:7px 10px;color:<?= $empClass ?>">
            <?= lps(abs($balEmp)) ?>
            <div style="font-size:.65rem;font-weight:400"><?= $balEmp >= 0 ? 'por reintegrar' : 'empresa debe' ?></div>
          </td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endforeach; ?>

<!-- Totales globales -->
<div class="card" style="border:2px solid var(--border)">
  <div class="card-body p-0">
    <table class="table-proscala w-100 mb-0">
      <tfoot>
        <tr style="font-weight:700;font-size:.9rem;background:var(--surface-2)">
          <td colspan="5" class="text-end" style="padding:10px 12px">TOTAL GENERAL</td>
          <td class="font-mono text-end" style="padding:10px 12px"><?= lps($totales['anticipo']) ?></td>
          <td class="font-mono text-end" style="padding:10px 12px"><?= lps($totales['liquidado']) ?></td>
          <td class="font-mono text-end" style="padding:10px 12px">
            <?php if ($totales['reintegrar'] > 0): ?>
            <div style="color:#d97706">↩ <?= lps($totales['reintegrar']) ?> <span style="font-size:.65rem;font-weight:400">emp. reintegra</span></div>
            <?php endif; ?>
            <?php if ($totales['favor_emp'] > 0): ?>
            <div style="color:#2563eb">↪ <?= lps($totales['favor_emp']) ?> <span style="font-size:.65rem;font-weight:400">empresa debe</span></div>
            <?php endif; ?>
            <?php if ($totales['reintegrar'] == 0 && $totales['favor_emp'] == 0): ?>
            <span style="color:#16a34a">✓ Todo saldado</span>
            <?php endif; ?>
          </td>
          <td style="padding:10px 12px"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php endif; ?>

<?php
$extraJs = '';
include __DIR__ . '/includes/footer.php';
?>

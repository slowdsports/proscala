<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$pagina = 'Dashboard';

// ── Pending counts ───────────────────────────────────────────────
$stats = [];
$stats['empleados']         = $pdo->query("SELECT COUNT(*) FROM empleados WHERE activo=1")->fetchColumn();
$stats['gastos_pendientes'] = $pdo->query("SELECT COUNT(*) FROM solicitud_gastos WHERE estado='pendiente'")->fetchColumn();
$stats['viajes_pendientes'] = $pdo->query("SELECT COUNT(*) FROM gastos_viaje WHERE estado='pendiente'")->fetchColumn();
$stats['sc_pendientes']     = $pdo->query("SELECT COUNT(*) FROM solicitud_compra WHERE estado='pendiente'")->fetchColumn();
$stats['op_pendientes']     = $pdo->query("SELECT COUNT(*) FROM ordenes_pago WHERE estado='pendiente'")->fetchColumn();
$stats['proyectos_activos'] = $pdo->query("SELECT COUNT(*) FROM proyectos WHERE estado='activo'")->fetchColumn();

// ── Monetary stats (current month) ──────────────────────────────
// Rangos de fechas (y no DATE_FORMAT/YEAR sobre la columna) para que se usen los índices por fecha
$mesIni = date('Y-m-01');
$mesFin = date('Y-m-01', strtotime('+1 month'));
$s = $pdo->prepare("SELECT COALESCE(SUM(monto_total),0) FROM solicitud_gastos WHERE fecha >= ? AND fecha < ? AND estado IN ('aprobada','pagada')");
$s->execute([$mesIni, $mesFin]);
$stats['gastos_mes'] = (float)$s->fetchColumn();
$s = $pdo->prepare("SELECT COALESCE(SUM(monto),0) FROM ordenes_pago WHERE fecha >= ? AND fecha < ? AND estado IN ('aprobada','pagada')");
$s->execute([$mesIni, $mesFin]);
$stats['pagos_mes'] = (float)$s->fetchColumn();
$stats['prest_saldo'] = (float)$pdo->query("SELECT COALESCE(SUM(saldo),0)       FROM prestamos          WHERE estado='activo'")->fetchColumn();

// ── Last payroll ─────────────────────────────────────────────────
$ult_pl = $pdo->query("SELECT anio, mes, quincena, estado, total_neto FROM planilla_periodos WHERE estado <> 'anulada' ORDER BY id DESC LIMIT 1")->fetch();

// ── Chart: gastos + pagos last 6 months, aligned ────────────────
$chartLabels = $chartGastos = $chartPagos = [];
for ($i = 5; $i >= 0; $i--) {
    $chartLabels[] = date('Y-m', strtotime("-$i months"));
}
$gRaw = $pdo->query("SELECT DATE_FORMAT(fecha,'%Y-%m') m, SUM(monto_total) t FROM solicitud_gastos WHERE estado IN ('aprobada','pagada') AND fecha >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY m")->fetchAll(PDO::FETCH_KEY_PAIR);
$pRaw = $pdo->query("SELECT DATE_FORMAT(fecha,'%Y-%m') m, SUM(monto) t        FROM ordenes_pago    WHERE estado IN ('aprobada','pagada') AND fecha >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY m")->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($chartLabels as $lbl) {
    $chartGastos[] = (float)($gRaw[$lbl] ?? 0);
    $chartPagos[]  = (float)($pRaw[$lbl] ?? 0);
}
$mesesCortos = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$prettyLabels = array_map(fn($m) => $mesesCortos[(int)substr($m, 5, 2) - 1] . ' ' . substr($m, 0, 4), $chartLabels);

// ── Top expense accounts this year ──────────────────────────────
$topCuentas = $pdo->query("
    SELECT cc.codigo, cc.nombre, SUM(sgd.monto) AS total
    FROM solicitud_gastos_detalle sgd
    JOIN cuentas_contables cc ON sgd.cuenta_id = cc.id
    JOIN solicitud_gastos sg  ON sgd.solicitud_id = sg.id
    WHERE sg.fecha >= '" . date('Y') . "-01-01' AND sg.fecha < '" . (date('Y') + 1) . "-01-01' AND sg.estado IN ('aprobada','pagada')
    GROUP BY cc.id ORDER BY total DESC LIMIT 6")->fetchAll();
$totTopCuentas = array_sum(array_column($topCuentas, 'total'));

// ── Recent purchases + payments ──────────────────────────────────
$compras_recientes = $pdo->query("
    SELECT sc.id, sc.numero, CONCAT(e.nombre,' ',e.apellidos) AS solicitante, sc.estado
    FROM solicitud_compra sc JOIN empleados e ON sc.solicitante_id=e.id
    ORDER BY sc.id DESC LIMIT 6")->fetchAll();

$pagos_recientes = $pdo->query("
    SELECT id, numero, beneficiario, monto, estado
    FROM ordenes_pago ORDER BY id DESC LIMIT 6")->fetchAll();

$labelsJson  = json_encode($prettyLabels);
$gastosJson  = json_encode($chartGastos);
$pagosJson   = json_encode($chartPagos);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-gauge-high"></i> Dashboard</h1>
  <span style="font-size:.8rem;color:var(--text-3)">
    <i class="fas fa-calendar-day"></i> <?= date('j') . ' de ' . mb_strtolower(nombreMes((int)date('n'))) . ' de ' . date('Y') ?>
  </span>
</div>

<!-- ── Fila 1: contadores pendientes ── -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= BASE_URL ?>empleados.php" class="kpi-card kpi-blue">
      <div class="kpi-icon"><i class="fas fa-users"></i></div>
      <div class="kpi-value"><?= $stats['empleados'] ?></div>
      <div class="kpi-label">Empleados Activos</div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= BASE_URL ?>gastos_solicitud.php?estado=pendiente" class="kpi-card kpi-yellow">
      <div class="kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="kpi-value"><?= $stats['gastos_pendientes'] ?></div>
      <div class="kpi-label">Gastos Pendientes</div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= BASE_URL ?>gastos_viajes.php?estado=pendiente" class="kpi-card kpi-teal">
      <div class="kpi-icon"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="kpi-value"><?= $stats['viajes_pendientes'] ?></div>
      <div class="kpi-label">Anticipos Pendientes</div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= BASE_URL ?>compras_solicitud.php?estado=pendiente" class="kpi-card kpi-red">
      <div class="kpi-icon"><i class="fas fa-cart-plus"></i></div>
      <div class="kpi-value"><?= $stats['sc_pendientes'] ?></div>
      <div class="kpi-label">SC Pendientes</div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= BASE_URL ?>compras_pago.php?estado=pendiente" class="kpi-card kpi-purple">
      <div class="kpi-icon"><i class="fas fa-money-bill-transfer"></i></div>
      <div class="kpi-value"><?= $stats['op_pendientes'] ?></div>
      <div class="kpi-label">OP Pendientes</div>
    </a>
  </div>
  <div class="col-6 col-md-4 col-xl-2">
    <a href="<?= BASE_URL ?>proyectos.php?estado=activo" class="kpi-card kpi-green">
      <div class="kpi-icon"><i class="fas fa-diagram-project"></i></div>
      <div class="kpi-value"><?= $stats['proyectos_activos'] ?></div>
      <div class="kpi-label">Proyectos Activos</div>
    </a>
  </div>
</div>

<!-- ── Fila 2: resumen financiero del mes ── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <a href="<?= BASE_URL ?>gastos_solicitud.php" class="kpi-card kpi-red kpi-sm">
      <div class="kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="kpi-value"><?= lps($stats['gastos_mes']) ?></div>
      <div class="kpi-label">Gastos del Mes</div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= BASE_URL ?>compras_pago.php" class="kpi-card kpi-blue kpi-sm">
      <div class="kpi-icon"><i class="fas fa-money-bill-wave"></i></div>
      <div class="kpi-value"><?= lps($stats['pagos_mes']) ?></div>
      <div class="kpi-label">Pagos del Mes</div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= BASE_URL ?>prestamos.php" class="kpi-card kpi-yellow kpi-sm">
      <div class="kpi-icon"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="kpi-value"><?= lps($stats['prest_saldo']) ?></div>
      <div class="kpi-label">Préstamos por Cobrar</div>
    </a>
  </div>
  <div class="col-6 col-md-3">
    <?php if ($ult_pl): ?>
    <a href="<?= BASE_URL ?>planillas.php" class="kpi-card kpi-<?= $ult_pl['estado'] === 'pagada' ? 'green' : 'teal' ?> kpi-sm">
      <div class="kpi-icon"><i class="fas fa-money-check-dollar"></i></div>
      <div class="kpi-value"><?= lps($ult_pl['total_neto']) ?></div>
      <div class="kpi-label">Última Planilla Neta</div>
    </a>
    <?php else: ?>
    <a href="<?= BASE_URL ?>planillas.php" class="kpi-card kpi-gray kpi-sm">
      <div class="kpi-icon"><i class="fas fa-money-check-dollar"></i></div>
      <div class="kpi-value">—</div>
      <div class="kpi-label">Sin Planilla</div>
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- ── Fila 3: gráfico + cuentas de gasto ── -->
<div class="row g-3 mb-3">
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-line"></i> Gastos y Pagos — Últimos 6 Meses</div>
      <div class="card-body p-3">
        <canvas id="chartGastos" height="190"></canvas>
      </div>
    </div>
  </div>
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-bar"></i> Top Cuentas de Gasto — <?= date('Y') ?></div>
      <div class="card-body p-0">
        <?php if ($topCuentas): ?>
        <table class="table-proscala w-100">
          <tbody>
            <?php foreach ($topCuentas as $tc):
              $pctTC = $totTopCuentas > 0 ? round($tc['total'] / $totTopCuentas * 100, 1) : 0;
            ?>
            <tr>
              <td style="padding:.55rem .9rem">
                <div style="font-size:.8rem;font-weight:600;margin-bottom:.25rem"><?= htmlspecialchars($tc['nombre']) ?></div>
                <div class="budget-bar-wrap" style="height:5px">
                  <div class="budget-bar" style="width:<?= $pctTC ?>%;background:var(--primary);transition:width .6s"></div>
                </div>
              </td>
              <td class="font-mono text-end" style="padding:.55rem .9rem;white-space:nowrap;font-size:.82rem;font-weight:600"><?= lps($tc['total']) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php else: ?>
        <div class="text-center text-muted py-5" style="font-size:.85rem">Sin gastos registrados este año</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Fila 4: compras recientes + pagos recientes ── -->
<div class="row g-3">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header">
        <i class="fas fa-cart-plus"></i> Solicitudes de Compra Recientes
        <a href="<?= BASE_URL ?>compras_solicitud.php" class="ms-auto" style="font-size:.76rem">Ver todas</a>
      </div>
      <div class="card-body p-0">
        <table class="table-proscala w-100">
          <thead><tr><th>Número</th><th>Solicitante</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($compras_recientes as $r): ?>
            <tr>
              <td class="font-mono"><a href="<?= BASE_URL ?>compras_solicitud.php?action=ver&id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['numero']) ?></a></td>
              <td><?= htmlspecialchars($r['solicitante']) ?></td>
              <td><?= estadoBadge($r['estado']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$compras_recientes): ?>
            <tr><td colspan="3" class="text-center text-muted py-4">Sin solicitudes</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header">
        <i class="fas fa-money-bill-transfer"></i> Órdenes de Pago Recientes
        <a href="<?= BASE_URL ?>compras_pago.php" class="ms-auto" style="font-size:.76rem">Ver todas</a>
      </div>
      <div class="card-body p-0">
        <table class="table-proscala w-100">
          <thead><tr><th>Número</th><th>Beneficiario</th><th>Monto</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($pagos_recientes as $r): ?>
            <tr>
              <td class="font-mono"><a href="<?= BASE_URL ?>compras_pago.php?action=ver&id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['numero']) ?></a></td>
              <td><?= htmlspecialchars($r['beneficiario']) ?></td>
              <td class="font-mono"><?= lps($r['monto']) ?></td>
              <td><?= estadoBadge($r['estado']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$pagos_recientes): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">Sin órdenes</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = strtr(<<<'JS'

(function(){
  const canvas = document.getElementById('chartGastos');
  if (!canvas) return;
  let _chart = null;

  function buildChart() {
    const dark   = document.documentElement.getAttribute('data-theme') === 'dark';
    const grid   = dark ? 'rgba(255,255,255,.06)' : '#e9ecef';
    const tick   = dark ? '#9090a8' : '#666';
    const gBar   = dark ? 'rgba(248,113,113,.7)'  : 'rgba(229,57,53,.65)';
    const gBord  = dark ? '#f87171' : '#E53935';
    const pBar   = dark ? 'rgba(96,165,250,.7)'   : 'rgba(27,107,168,.65)';
    const pBord  = dark ? '#60a5fa' : '#1B6BA8';

    if (_chart) _chart.destroy();
    _chart = new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: __LABELS_JSON__,
        datasets: [
          { label:'Gastos',  data:__GASTOS_JSON__, backgroundColor:gBar, borderColor:gBord, borderWidth:1, borderRadius:4 },
          { label:'Pagos',   data:__PAGOS_JSON__,  backgroundColor:pBar, borderColor:pBord, borderWidth:1, borderRadius:4 }
        ]
      },
      options: {
        responsive:true,
        plugins: {
          legend: { display:true, labels:{ color: tick, font:{ size:11 } } }
        },
        scales: {
          x: { grid:{ color:grid }, ticks:{ color:tick } },
          y: { beginAtZero:true, grid:{ color:grid }, ticks:{ color:tick, callback: v => 'L. '+v.toLocaleString('es-HN') } }
        }
      }
    });
  }

  buildChart();
  document.addEventListener('proscala:themechange', buildChart);
})();

JS, [
    '__LABELS_JSON__' => $labelsJson,
    '__GASTOS_JSON__' => $gastosJson,
    '__PAGOS_JSON__' => $pagosJson,
]);
include __DIR__ . '/includes/footer.php';
?>

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

// ── Gráfico: egresos de los últimos 6 meses por tipo ─────────────
// Todo egreso pasa por una orden de pago (aprobada o pagada): agruparlas por su origen dice en qué
// se va el dinero sin contar dos veces (las liquidaciones y reembolsos se pagan con su propia OP).
$chartLabels = [];
for ($i = 5; $i >= 0; $i--) $chartLabels[] = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
$tiposEgreso = ['Compras', 'Viáticos y gastos', 'Planillas', 'Otros'];
$egresos = array_fill_keys($tiposEgreso, array_fill_keys($chartLabels, 0.0));
$s = $pdo->prepare("SELECT DATE_FORMAT(fecha, '%Y-%m') AS m,
        CASE WHEN orden_compra_id IS NOT NULL OR origen_tabla = 'ordenes_compra' THEN 'Compras'
             WHEN solicitud_gastos_id IS NOT NULL OR origen_tabla IN ('gastos_viaje', 'solicitud_gastos') THEN 'Viáticos y gastos'
             WHEN origen_tabla IN ('planilla_periodos', 'planilla_asignacion', 'planilla_beneficios') THEN 'Planillas'
             ELSE 'Otros' END AS tipo,
        SUM(monto) AS total
    FROM ordenes_pago WHERE estado IN ('aprobada', 'pagada') AND fecha >= ? GROUP BY m, tipo");
$s->execute([$chartLabels[0] . '-01']);
foreach ($s->fetchAll() as $r) if (isset($egresos[$r['tipo']][$r['m']])) $egresos[$r['tipo']][$r['m']] = round((float)$r['total'], 2);
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

// ── Procesos recientes (cada tabla, solo si el usuario tiene acceso a su módulo) ──
$compras_recientes = !puedeAcceder('compras_solicitud.php') ? null : $pdo->query("
    SELECT sc.id, sc.numero, CONCAT(e.nombre,' ',e.apellidos) AS solicitante, sc.presupuesto_estimado AS monto, sc.estado
    FROM solicitud_compra sc JOIN empleados e ON sc.solicitante_id=e.id
    ORDER BY sc.id DESC LIMIT 6")->fetchAll();

$pagos_recientes = !puedeAcceder('compras_pago.php') ? null : $pdo->query("
    SELECT id, numero, beneficiario, monto, estado
    FROM ordenes_pago ORDER BY id DESC LIMIT 6")->fetchAll();

$anticipos_recientes = !puedeAcceder('gastos_viajes.php') ? null : $pdo->query("
    SELECT gv.id, gv.numero, gv.tipo, gv.destino, " . SQL_BENEFICIARIO_GASTO . " AS empleado, gv.viaticos_anticipados AS monto, gv.estado
    FROM gastos_viaje gv " . joinBeneficiarioGasto('gv') . "
    ORDER BY gv.id DESC LIMIT 6")->fetchAll();

$gastos_recientes = !puedeAcceder('gastos_solicitud.php') ? null : $pdo->query("
    SELECT sg.id, sg.numero, sg.viaje_id, sg.descripcion, " . SQL_BENEFICIARIO_GASTO . " AS empleado, sg.monto_total AS monto, sg.estado
    FROM solicitud_gastos sg " . joinBeneficiarioGasto('sg') . "
    ORDER BY sg.id DESC LIMIT 6")->fetchAll();

$labelsJson  = json_encode($prettyLabels);
$egresosJson = json_encode(array_map(fn($t) => ['label' => $t, 'data' => array_values($egresos[$t])], $tiposEgreso), JSON_UNESCAPED_UNICODE);

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
      <div class="card-header"><i class="fas fa-chart-column"></i> Egresos por Tipo — Últimos 6 Meses
        <span class="ms-auto text-muted fw-normal" style="font-size:.72rem" title="Órdenes de pago aprobadas y pagadas, según el proceso que las generó">órdenes de pago aprobadas y pagadas</span></div>
      <div class="card-body p-3">
        <canvas id="chartGastos" height="190" aria-label="Egresos de los últimos 6 meses por tipo: compras, viáticos y gastos, planillas y otros"></canvas>
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

<!-- ── Fila 4: procesos recientes ── -->
<?php
// [título, ícono, módulo, filas (null: sin acceso), encabezado de la 2.ª columna, cómo describir la fila, texto si no hay]
$tablasRecientes = [
    ['Solicitudes de Compra Recientes', 'fa-cart-plus', 'compras_solicitud.php', $compras_recientes, 'Solicitante', fn($r) => $r['solicitante'], 'Sin solicitudes'],
    ['Órdenes de Pago Recientes', 'fa-money-bill-transfer', 'compras_pago.php', $pagos_recientes, 'Beneficiario', fn($r) => $r['beneficiario'], 'Sin órdenes'],
    ['Anticipos Recientes', 'fa-plane', 'gastos_viajes.php', $anticipos_recientes, 'Beneficiario / Destino',
        fn($r) => $r['empleado'] . ' — ' . ($r['tipo'] === 'compra' ? 'Compra: ' : '') . $r['destino'], 'Sin anticipos'],
    ['Solicitudes de Gastos Recientes', 'fa-file-invoice-dollar', 'gastos_solicitud.php', $gastos_recientes, 'Beneficiario / Descripción',
        fn($r) => $r['empleado'] . ' — ' . ($r['viaje_id'] ? 'Liquidación: ' : 'Reembolso: ') . $r['descripcion'], 'Sin solicitudes'],
];
?>
<div class="row g-3">
  <?php foreach ($tablasRecientes as [$titulo, $icono, $modulo, $filas, $col2, $describir, $vacio]): if ($filas === null) continue; ?>
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header">
        <i class="fas <?= $icono ?>"></i> <?= $titulo ?>
        <a href="<?= BASE_URL . $modulo ?>" class="ms-auto" style="font-size:.76rem">Ver todas</a>
      </div>
      <div class="card-body p-0">
        <table class="table-proscala w-100">
          <thead><tr><th>Número</th><th><?= $col2 ?></th><th class="text-end">Monto</th><th>Estado</th></tr></thead>
          <tbody>
            <?php foreach ($filas as $r): ?>
            <tr>
              <td class="font-mono text-nowrap"><a href="<?= BASE_URL . $modulo ?>?action=ver&id=<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['numero'] ?: '#' . $r['id']) ?></a></td>
              <td><?= htmlspecialchars(mb_strimwidth($describir($r), 0, 60, '…')) ?></td>
              <td class="font-mono text-end text-nowrap"><?= lps((float)$r['monto']) ?></td>
              <td><?= estadoBadge($r['estado']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$filas): ?>
            <tr><td colspan="4" class="text-center text-muted py-4"><?= $vacio ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php
$extraJs = strtr(<<<'JS'

(function(){
  const canvas = document.getElementById('chartGastos');
  if (!canvas) return;
  let _chart = null;

  // Barras apiladas: una por mes, dividida por tipo de egreso; el total del mes en la ayuda emergente
  function buildChart() {
    const dark   = document.documentElement.getAttribute('data-theme') === 'dark';
    const grid   = dark ? 'rgba(255,255,255,.06)' : '#e9ecef';
    const tick   = dark ? '#9090a8' : '#666';
    // Compras · Viáticos y gastos · Planillas · Otros
    const colores = dark ? ['#60a5fa', '#fbbf24', '#a78bfa', '#94a3b8'] : ['#1B6BA8', '#F59E0B', '#7C3AED', '#94A3B8'];
    const series = __EGRESOS_JSON__.map((s, i) => ({ ...s, backgroundColor: colores[i], borderRadius: 3, stack: 'egresos' }));
    const lps = v => 'L. ' + Number(v).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    if (_chart) _chart.destroy();
    _chart = new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: { labels: __LABELS_JSON__, datasets: series },
      options: {
        responsive:true,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display:true, labels:{ color: tick, font:{ size:11 } } },
          tooltip: { callbacks: {
            label: c => c.raw ? `${c.dataset.label}: ${lps(c.raw)}` : null,
            footer: items => 'Total: ' + lps(items.reduce((s, i) => s + i.raw, 0)),
          } }
        },
        scales: {
          x: { stacked:true, grid:{ color:grid }, ticks:{ color:tick } },
          y: { stacked:true, beginAtZero:true, grid:{ color:grid }, ticks:{ color:tick, callback: v => 'L. '+v.toLocaleString('es-HN') } }
        }
      }
    });
  }

  buildChart();
  document.addEventListener('proscala:themechange', buildChart);
})();

JS, [
    '__LABELS_JSON__'  => $labelsJson,
    '__EGRESOS_JSON__' => $egresosJson,
]);
include __DIR__ . '/includes/footer.php';
?>

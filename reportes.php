<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Reportes Financieros';

$anio = (int)($_GET['anio'] ?? date('Y'));
$mes  = (int)($_GET['mes']  ?? 0);
// Rango del año (y no YEAR(fecha)) para que las consultas usen el índice por fecha
$rangoAnio = ["$anio-01-01", ($anio + 1) . '-01-01'];

// Gastos por mes
$gastosQ = $pdo->prepare("SELECT MONTH(fecha) as m, SUM(monto_total) as total FROM solicitud_gastos WHERE fecha >= ? AND fecha < ? AND estado IN ('aprobada','pagada') GROUP BY m ORDER BY m");
$gastosQ->execute($rangoAnio); $gastosMes = $gastosQ->fetchAll();

// Pagos por mes
$pagosQ = $pdo->prepare("SELECT MONTH(fecha) as m, SUM(monto) as total FROM ordenes_pago WHERE fecha >= ? AND fecha < ? AND estado IN ('aprobada','pagada') GROUP BY m ORDER BY m");
$pagosQ->execute($rangoAnio); $pagosMes = $pagosQ->fetchAll();

// Planillas por mes
$plQ = $pdo->prepare("SELECT mes, SUM(total_neto) as neto, SUM(total_bruto) as bruto FROM planilla_periodos WHERE anio=? AND estado IN ('aprobada','pagada') GROUP BY mes ORDER BY mes");
$plQ->execute([$anio]); $planillasMes = $plQ->fetchAll();

// Resumen general del año
$stm=$pdo->prepare("SELECT SUM(monto_total) FROM solicitud_gastos WHERE fecha >= ? AND fecha < ? AND estado IN ('aprobada','pagada')");
$stm->execute($rangoAnio); $totGastos = (float)$stm->fetchColumn();

$stm=$pdo->prepare("SELECT SUM(monto) FROM ordenes_pago WHERE fecha >= ? AND fecha < ? AND estado IN ('aprobada','pagada')");
$stm->execute($rangoAnio); $totPagos = (float)$stm->fetchColumn();

$stm=$pdo->prepare("SELECT SUM(total_neto) FROM planilla_periodos WHERE anio=? AND estado IN ('aprobada','pagada')");
$stm->execute([$anio]); $totPlanillas = (float)$stm->fetchColumn();

$stm=$pdo->prepare("SELECT SUM(monto) FROM ordenes_pago WHERE fecha >= ? AND fecha < ? AND estado IN ('aprobada','pagada') AND beneficiario_tipo='proveedor'");
$stm->execute($rangoAnio); $totProveedores = (float)$stm->fetchColumn();

// Top proveedores
$topProvQ=$pdo->prepare("SELECT p.nombre, SUM(op.monto) as total FROM ordenes_pago op JOIN proveedores p ON op.proveedor_id=p.id WHERE op.fecha >= ? AND op.fecha < ? AND op.estado IN ('aprobada','pagada') GROUP BY p.id ORDER BY total DESC LIMIT 8");
$topProvQ->execute($rangoAnio); $topProv=$topProvQ->fetchAll();

// Gastos por cuenta
$gastCtaQ=$pdo->prepare("SELECT cc.codigo,cc.nombre,SUM(sgd.monto) as total FROM solicitud_gastos_detalle sgd JOIN cuentas_contables cc ON sgd.cuenta_id=cc.id JOIN solicitud_gastos sg ON sgd.solicitud_id=sg.id WHERE sg.fecha >= ? AND sg.fecha < ? AND sg.estado IN ('aprobada','pagada') GROUP BY cc.id ORDER BY total DESC LIMIT 10");
$gastCtaQ->execute($rangoAnio); $gastCta=$gastCtaQ->fetchAll();

// Gastos por proyecto
$gastProy=$pdo->prepare("SELECT p.nombre,SUM(sg.monto_total) as total FROM solicitud_gastos sg JOIN proyectos p ON sg.proyecto_id=p.id WHERE sg.fecha >= ? AND sg.fecha < ? AND sg.estado IN ('aprobada','pagada') GROUP BY p.id ORDER BY total DESC");
$gastProy->execute($rangoAnio); $gastProy=$gastProy->fetchAll();

$meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$gMesArr = array_fill(1, 12, 0);
foreach($gastosMes as $r) $gMesArr[$r['m']] = (float)$r['total'];
$pMesArr = array_fill(1, 12, 0);
foreach($pagosMes as $r) $pMesArr[$r['m']] = (float)$r['total'];
$plMesArr = array_fill(1, 12, 0);
foreach($planillasMes as $r) $plMesArr[$r['mes']] = (float)$r['neto'];

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-chart-bar"></i> Reportes Financieros</h1>
  <div class="d-flex gap-2 align-items-center">
    <select class="form-select form-select-sm" id="sel-anio-rep" style="width:100px" onchange="Nav.ir('reportes.php?anio='+this.value)">
      <?php for($y=2022;$y<=date('Y')+1;$y++): ?><option value="<?= $y ?>" <?= $y==$anio?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
    </select>
    <button class="btn-proscala-outline" onclick="window.print()"><i class="fas fa-print"></i> Imprimir</button>
  </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-red kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="kpi-value"><?= lps($totGastos) ?></div>
      <div class="kpi-label">Gastos Reembolsados</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-blue kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-truck"></i></div>
      <div class="kpi-value"><?= lps($totProveedores) ?></div>
      <div class="kpi-label">Pagos a Proveedores</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-green kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-users"></i></div>
      <div class="kpi-value"><?= lps($totPlanillas) ?></div>
      <div class="kpi-label">Planillas Pagadas</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-purple kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-money-bill-transfer"></i></div>
      <div class="kpi-value"><?= lps($totPagos) ?></div>
      <div class="kpi-label">Total Órdenes de Pago</div>
    </div>
  </div>
</div>

<div class="row g-3">
  <!-- Gráfico combinado gastos + pagos + planillas -->
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fas fa-chart-line"></i> Evolución Mensual <?= $anio ?></div>
      <div class="card-body"><canvas id="chartMensual" height="100"></canvas></div>
    </div>
  </div>

  <!-- Top proveedores -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-truck"></i> Top Proveedores (Pagos)</div>
      <div class="card-body p-0">
        <table class="table-proscala w-100" data-exportar="Top proveedores">
          <thead><tr><th>Proveedor</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php foreach($topProv as $r): ?>
            <tr><td><?= htmlspecialchars($r['nombre']) ?></td><td class="font-mono text-end"><?= lps($r['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$topProv): ?><tr><td colspan="2" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Gastos por cuenta -->
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-book"></i> Gastos por Cuenta Contable</div>
      <div class="card-body p-0">
        <table class="table-proscala w-100" data-exportar="Gastos por cuenta">
          <thead><tr><th>Código</th><th>Cuenta</th><th class="text-end">Total</th></tr></thead>
          <tbody>
            <?php foreach($gastCta as $r): ?>
            <tr><td class="font-mono"><?= $r['codigo'] ?></td><td><?= htmlspecialchars($r['nombre']) ?></td><td class="font-mono text-end"><?= lps($r['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$gastCta): ?><tr><td colspan="3" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Gastos por proyecto -->
  <div class="col-12">
    <div class="card">
      <div class="card-header"><i class="fas fa-diagram-project"></i> Gastos por Proyecto</div>
      <div class="card-body p-0">
        <table class="table-proscala w-100" data-exportar="Gastos por proyecto">
          <thead><tr><th>Proyecto</th><th class="text-end">Total Gastos Reembolsados</th></tr></thead>
          <tbody>
            <?php foreach($gastProy as $r): ?>
            <tr><td><?= htmlspecialchars($r['nombre']) ?></td><td class="font-mono text-end"><?= lps($r['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if(!$gastProy): ?><tr><td colspan="2" class="text-center text-muted py-3">Sin datos</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
$labelsJson = json_encode(array_values($meses));
$gJson  = json_encode(array_values($gMesArr));
$pJson  = json_encode(array_values($pMesArr));
$plJson = json_encode(array_values($plMesArr));
$extraJs = strtr(<<<'JS'

new Chart(document.getElementById('chartMensual').getContext('2d'), {
  type:'bar',
  data:{
    labels:__LABELS_JSON__,
    datasets:[
      {label:'Gastos Reembolsados',data:__G_JSON__,backgroundColor:'rgba(229,57,53,.65)',borderColor:'#E53935',borderWidth:1,borderRadius:3},
      {label:'Órdenes de Pago',data:__P_JSON__,backgroundColor:'rgba(27,107,168,.65)',borderColor:'#1B6BA8',borderWidth:1,borderRadius:3},
      {label:'Planillas Neto',data:__PL_JSON__,backgroundColor:'rgba(67,160,71,.65)',borderColor:'#43A047',borderWidth:1,borderRadius:3},
    ]
  },
  options:{responsive:true,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true,ticks:{callback:v=>'L. '+v.toLocaleString()}}}}
});

JS, [
    '__LABELS_JSON__' => $labelsJson,
    '__G_JSON__' => $gJson,
    '__P_JSON__' => $pJson,
    '__PL_JSON__' => $plJson,
]);
include __DIR__ . '/includes/footer.php';
?>

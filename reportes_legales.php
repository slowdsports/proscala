<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$pagina = 'Reportes Legales';
$tipo   = $_GET['tipo']   ?? 'ihss';
$anio   = (int)($_GET['anio']   ?? date('Y'));
$mes    = (int)($_GET['mes']    ?? date('n'));
$export = $_GET['export'] ?? '';

// ── CSV Export ─────────────────────────────────────────────────────
if ($export === 'csv') {
    $tipo   = in_array($_GET['tipo'], ['ihss','rap','sar']) ? $_GET['tipo'] : 'ihss';
    $nombre = match($tipo) {
        'ihss' => "IHSS_{$anio}_" . str_pad($mes, 2, '0', STR_PAD_LEFT),
        'rap'  => "RAP_{$anio}_" . str_pad($mes, 2, '0', STR_PAD_LEFT),
        'sar'  => "SAR_{$anio}",
    };

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$nombre}.csv\"");
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fwrite($out, "\xEF\xBB\xBF");

    if ($tipo === 'ihss') {
        fputcsv($out, ['RTN Empresa','Identidad Empleado','Nombre','Sueldo Mensual',
            'IHSS Empleado','IHSS Patronal','Total IHSS']);
        $rtn = getConfig($pdo, 'rtn_empresa', '');
        $rows = $pdo->prepare("
            SELECT e.identidad, CONCAT(e.nombre,' ',e.apellidos) AS nombre,
                   e.sueldo_mensual,
                   SUM(pd.ihss_empleado)*2 AS ihss_emp,
                   SUM(pd.ihss_patronal)*2 AS ihss_pat
            FROM planilla_detalle pd
            JOIN planilla_periodos pp ON pd.periodo_id = pp.id
            JOIN empleados e ON pd.empleado_id = e.id
            WHERE pp.anio=? AND pp.mes=? AND pp.estado IN ('aprobada','pagada')
              AND e.aplica_ihss=1
            GROUP BY e.id
            ORDER BY e.apellidos, e.nombre
        ");
        $rows->execute([$anio, $mes]);
        foreach ($rows->fetchAll() as $r) {
            fputcsv($out, [
                $rtn,
                $r['identidad'],
                $r['nombre'],
                number_format($r['sueldo_mensual'], 2, '.', ''),
                number_format($r['ihss_emp'], 2, '.', ''),
                number_format($r['ihss_pat'], 2, '.', ''),
                number_format($r['ihss_emp'] + $r['ihss_pat'], 2, '.', ''),
            ]);
        }

    } elseif ($tipo === 'rap') {
        fputcsv($out, ['RTN Empresa','Identidad Empleado','Nombre','Sueldo Mensual',
            'RAP Empleado','RAP Patronal','Total RAP']);
        $rtn = getConfig($pdo, 'rtn_empresa', '');
        $rows = $pdo->prepare("
            SELECT e.identidad, CONCAT(e.nombre,' ',e.apellidos) AS nombre,
                   e.sueldo_mensual,
                   SUM(pd.rap_empleado)*2 AS rap_emp,
                   SUM(pd.rap_patronal)*2 AS rap_pat
            FROM planilla_detalle pd
            JOIN planilla_periodos pp ON pd.periodo_id = pp.id
            JOIN empleados e ON pd.empleado_id = e.id
            WHERE pp.anio=? AND pp.mes=? AND pp.estado IN ('aprobada','pagada')
              AND e.aplica_rap=1
            GROUP BY e.id
            ORDER BY e.apellidos, e.nombre
        ");
        $rows->execute([$anio, $mes]);
        foreach ($rows->fetchAll() as $r) {
            fputcsv($out, [
                $rtn,
                $r['identidad'],
                $r['nombre'],
                number_format($r['sueldo_mensual'], 2, '.', ''),
                number_format($r['rap_emp'], 2, '.', ''),
                number_format($r['rap_pat'], 2, '.', ''),
                number_format($r['rap_emp'] + $r['rap_pat'], 2, '.', ''),
            ]);
        }

    } elseif ($tipo === 'sar') {
        fputcsv($out, ['RTN Empresa','Identidad Empleado','RTN Empleado','Nombre','Cargo',
            'Total Devengado','Total ISR Retenido','Período']);
        $rtn = getConfig($pdo, 'rtn_empresa', '');
        $rows = $pdo->prepare("
            SELECT e.identidad, e.rtn, CONCAT(e.nombre,' ',e.apellidos) AS nombre,
                   e.cargo,
                   SUM(pd.total_bruto) AS total_devengado,
                   SUM(pd.isr)*2 AS total_isr
            FROM planilla_detalle pd
            JOIN planilla_periodos pp ON pd.periodo_id = pp.id
            JOIN empleados e ON pd.empleado_id = e.id
            WHERE pp.anio=? AND pp.estado IN ('aprobada','pagada')
              AND e.aplica_isr=1
            GROUP BY e.id
            ORDER BY e.apellidos, e.nombre
        ");
        $rows->execute([$anio]);
        foreach ($rows->fetchAll() as $r) {
            fputcsv($out, [
                $rtn,
                $r['identidad'],
                $r['rtn'],
                $r['nombre'],
                $r['cargo'],
                number_format($r['total_devengado'], 2, '.', ''),
                number_format($r['total_isr'], 2, '.', ''),
                $anio,
            ]);
        }
    }

    fclose($out);
    exit;
}

// ── Preview data ───────────────────────────────────────────────────
$tipo = in_array($tipo, ['ihss','rap','sar']) ? $tipo : 'ihss';

$rowsIhss = $rowsRap = $rowsSar = [];
$totals = [];

if ($tipo === 'ihss') {
    $stmt = $pdo->prepare("
        SELECT e.identidad, CONCAT(e.nombre,' ',e.apellidos) AS nombre,
               e.cargo, e.sueldo_mensual,
               SUM(pd.ihss_empleado)*2 AS ihss_emp,
               SUM(pd.ihss_patronal)*2 AS ihss_pat
        FROM planilla_detalle pd
        JOIN planilla_periodos pp ON pd.periodo_id = pp.id
        JOIN empleados e ON pd.empleado_id = e.id
        WHERE pp.anio=? AND pp.mes=? AND pp.estado IN ('aprobada','pagada')
          AND e.aplica_ihss=1
        GROUP BY e.id
        ORDER BY e.apellidos, e.nombre
    ");
    $stmt->execute([$anio, $mes]);
    $rowsIhss = $stmt->fetchAll();
    $totals = ['emp'=>0,'pat'=>0,'total'=>0];
    foreach ($rowsIhss as $r) {
        $totals['emp']   += $r['ihss_emp'];
        $totals['pat']   += $r['ihss_pat'];
        $totals['total'] += $r['ihss_emp'] + $r['ihss_pat'];
    }

} elseif ($tipo === 'rap') {
    $stmt = $pdo->prepare("
        SELECT e.identidad, CONCAT(e.nombre,' ',e.apellidos) AS nombre,
               e.cargo, e.sueldo_mensual,
               SUM(pd.rap_empleado)*2 AS rap_emp,
               SUM(pd.rap_patronal)*2 AS rap_pat
        FROM planilla_detalle pd
        JOIN planilla_periodos pp ON pd.periodo_id = pp.id
        JOIN empleados e ON pd.empleado_id = e.id
        WHERE pp.anio=? AND pp.mes=? AND pp.estado IN ('aprobada','pagada')
          AND e.aplica_rap=1
        GROUP BY e.id
        ORDER BY e.apellidos, e.nombre
    ");
    $stmt->execute([$anio, $mes]);
    $rowsRap = $stmt->fetchAll();
    $totals = ['emp'=>0,'pat'=>0,'total'=>0];
    foreach ($rowsRap as $r) {
        $totals['emp']   += $r['rap_emp'];
        $totals['pat']   += $r['rap_pat'];
        $totals['total'] += $r['rap_emp'] + $r['rap_pat'];
    }

} elseif ($tipo === 'sar') {
    $stmt = $pdo->prepare("
        SELECT e.identidad, e.rtn, CONCAT(e.nombre,' ',e.apellidos) AS nombre,
               e.cargo,
               SUM(pd.total_bruto) AS total_devengado,
               SUM(pd.isr)*2 AS total_isr
        FROM planilla_detalle pd
        JOIN planilla_periodos pp ON pd.periodo_id = pp.id
        JOIN empleados e ON pd.empleado_id = e.id
        WHERE pp.anio=? AND pp.estado IN ('aprobada','pagada')
          AND e.aplica_isr=1
        GROUP BY e.id
        ORDER BY e.apellidos, e.nombre
    ");
    $stmt->execute([$anio]);
    $rowsSar = $stmt->fetchAll();
    $totals = ['devengado'=>0,'isr'=>0];
    foreach ($rowsSar as $r) {
        $totals['devengado'] += $r['total_devengado'];
        $totals['isr']       += $r['total_isr'];
    }
}

$nombresMes = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
               'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

// Years with processed planillas
$aniosDisp = $pdo->query("SELECT DISTINCT anio FROM planilla_periodos WHERE estado IN ('aprobada','pagada') ORDER BY anio DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!$aniosDisp) $aniosDisp = [date('Y')];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-file-export"></i> Reportes Legales</h1>
</div>

<!-- Filtros -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Reporte</label>
        <select name="tipo" class="form-select" onchange="this.form.submit()">
          <option value="ihss" <?= $tipo==='ihss'?'selected':'' ?>>IHSS — Instituto de Seguridad Social</option>
          <option value="rap"  <?= $tipo==='rap' ?'selected':'' ?>>RAP  — Régimen de Aportaciones Privadas</option>
          <option value="sar"  <?= $tipo==='sar' ?'selected':'' ?>>SAR  — Servicio de Administración de Rentas</option>
        </select>
      </div>

      <div class="col-md-2">
        <label class="form-label">Año</label>
        <select name="anio" class="form-select">
          <?php foreach ($aniosDisp as $y): ?>
          <option value="<?= $y ?>" <?= $y==$anio?'selected':'' ?>><?= $y ?></option>
          <?php endforeach; ?>
          <?php if (!in_array(date('Y'), $aniosDisp)): ?>
          <option value="<?= date('Y') ?>" <?= date('Y')==$anio?'selected':'' ?>><?= date('Y') ?></option>
          <?php endif; ?>
        </select>
      </div>

      <?php if ($tipo !== 'sar'): ?>
      <div class="col-md-2">
        <label class="form-label">Mes</label>
        <select name="mes" class="form-select">
          <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m==$mes?'selected':'' ?>><?= $nombresMes[$m] ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" name="mes" value="<?= $mes ?>">
      <?php endif; ?>

      <div class="col-md-2">
        <button type="submit" class="btn btn-outline-secondary w-100">
          <i class="fas fa-search"></i> Filtrar
        </button>
      </div>

      <div class="col-md-3">
        <a href="?tipo=<?= $tipo ?>&anio=<?= $anio ?>&mes=<?= $mes ?>&export=csv"
           class="btn btn-success w-100">
          <i class="fas fa-file-csv"></i> Descargar CSV
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Encabezado del reporte -->
<?php
$tituloReporte = match($tipo) {
    'ihss' => 'IHSS — ' . $nombresMes[$mes] . ' ' . $anio,
    'rap'  => 'RAP — ' . $nombresMes[$mes] . ' ' . $anio,
    'sar'  => 'SAR — Declaración Anual ' . $anio,
};
?>
<div class="card">
  <div class="card-header">
    <i class="fas fa-table"></i> <?= $tituloReporte ?>
    <?php
    $rowCount = match($tipo) {
        'ihss' => count($rowsIhss),
        'rap'  => count($rowsRap),
        'sar'  => count($rowsSar),
    };
    ?>
    <span class="badge bg-secondary ms-2"><?= $rowCount ?> empleado<?= $rowCount != 1 ? 's' : '' ?></span>
  </div>
  <div class="card-body p-0">

    <?php if ($tipo === 'ihss'): ?>
    <table class="table-proscala w-100">
      <thead>
        <tr>
          <th>Identidad</th><th>Empleado</th><th>Cargo</th>
          <th class="text-end">Sueldo Mensual</th>
          <th class="text-end">IHSS Empleado</th>
          <th class="text-end">IHSS Patronal</th>
          <th class="text-end">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rowsIhss as $r): ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($r['identidad'] ?? '—') ?></td>
          <td><?= htmlspecialchars($r['nombre']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($r['cargo'] ?? '') ?></td>
          <td class="text-end font-mono"><?= lps($r['sueldo_mensual']) ?></td>
          <td class="text-end font-mono"><?= lps($r['ihss_emp']) ?></td>
          <td class="text-end font-mono"><?= lps($r['ihss_pat']) ?></td>
          <td class="text-end font-mono fw-bold"><?= lps($r['ihss_emp']+$r['ihss_pat']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rowsIhss): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">
          No hay datos para <?= $nombresMes[$mes] ?> <?= $anio ?>.
          Verifique que existan planillas aprobadas o pagadas para ese período.
        </td></tr>
        <?php else: ?>
        <tr class="fw-bold" style="background:var(--surface-2)">
          <td colspan="4" class="text-end">TOTALES:</td>
          <td class="text-end font-mono"><?= lps($totals['emp']) ?></td>
          <td class="text-end font-mono"><?= lps($totals['pat']) ?></td>
          <td class="text-end font-mono" style="color:var(--proscala)"><?= lps($totals['total']) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php elseif ($tipo === 'rap'): ?>
    <table class="table-proscala w-100">
      <thead>
        <tr>
          <th>Identidad</th><th>Empleado</th><th>Cargo</th>
          <th class="text-end">Sueldo Mensual</th>
          <th class="text-end">RAP Empleado</th>
          <th class="text-end">RAP Patronal</th>
          <th class="text-end">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rowsRap as $r): ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($r['identidad'] ?? '—') ?></td>
          <td><?= htmlspecialchars($r['nombre']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($r['cargo'] ?? '') ?></td>
          <td class="text-end font-mono"><?= lps($r['sueldo_mensual']) ?></td>
          <td class="text-end font-mono"><?= lps($r['rap_emp']) ?></td>
          <td class="text-end font-mono"><?= lps($r['rap_pat']) ?></td>
          <td class="text-end font-mono fw-bold"><?= lps($r['rap_emp']+$r['rap_pat']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rowsRap): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">
          No hay datos para <?= $nombresMes[$mes] ?> <?= $anio ?>.
          Verifique que existan planillas aprobadas o pagadas para ese período.
        </td></tr>
        <?php else: ?>
        <tr class="fw-bold" style="background:var(--surface-2)">
          <td colspan="4" class="text-end">TOTALES:</td>
          <td class="text-end font-mono"><?= lps($totals['emp']) ?></td>
          <td class="text-end font-mono"><?= lps($totals['pat']) ?></td>
          <td class="text-end font-mono" style="color:var(--proscala)"><?= lps($totals['total']) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php elseif ($tipo === 'sar'): ?>
    <table class="table-proscala w-100">
      <thead>
        <tr>
          <th>Identidad</th><th>RTN</th><th>Empleado</th><th>Cargo</th>
          <th class="text-end">Total Devengado</th>
          <th class="text-end">ISR Retenido</th>
          <th class="text-end">% ISR Efectivo</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rowsSar as $r): ?>
        <?php $pctIsr = $r['total_devengado'] > 0 ? round($r['total_isr'] / $r['total_devengado'] * 100, 2) : 0; ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($r['identidad'] ?? '—') ?></td>
          <td class="font-mono"><?= htmlspecialchars($r['rtn'] ?? '—') ?></td>
          <td><?= htmlspecialchars($r['nombre']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($r['cargo'] ?? '') ?></td>
          <td class="text-end font-mono"><?= lps($r['total_devengado']) ?></td>
          <td class="text-end font-mono"><?= lps($r['total_isr']) ?></td>
          <td class="text-end font-mono"><?= $pctIsr ?>%</td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rowsSar): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">
          No hay datos para el año <?= $anio ?>.
          Verifique que existan planillas aprobadas o pagadas para ese año.
        </td></tr>
        <?php else: ?>
        <tr class="fw-bold" style="background:var(--surface-2)">
          <td colspan="4" class="text-end">TOTALES:</td>
          <td class="text-end font-mono"><?= lps($totals['devengado']) ?></td>
          <td class="text-end font-mono" style="color:var(--proscala)"><?= lps($totals['isr']) ?></td>
          <td></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <?php endif; ?>
  </div>
</div>

<!-- Nota informativa -->
<div class="mt-3 p-3" style="background:var(--surface-2);border-radius:8px;font-size:.8rem;color:var(--text-3)">
  <?php if ($tipo === 'ihss'): ?>
  <strong>IHSS:</strong> Datos calculados de planillas quincenales aprobadas/pagadas del mes seleccionado.
  El CSV usa el formato de importación IHSS. Tarifas: empleado <?= getConfig($pdo,'ihss_empleado','2.5') ?>%,
  patronal <?= getConfig($pdo,'ihss_patronal','5.0') ?>%. Techo mensual: <?= lps((float)getConfig($pdo,'ihss_techo_mensual','9357')) ?>.
  <?php elseif ($tipo === 'rap'): ?>
  <strong>RAP:</strong> Datos de planillas quincenales del mes. Aportes: empleado <?= getConfig($pdo,'rap_empleado','1.5') ?>%,
  patronal <?= getConfig($pdo,'rap_patronal','1.5') ?>%. Sin techo salarial.
  <?php elseif ($tipo === 'sar'): ?>
  <strong>SAR (Declaración Jurada de Sueldos):</strong> Total devengado y ISR retenido durante el año fiscal.
  Solo incluye empleados con ISR activo. Verificar con contador antes de presentar.
  <?php endif; ?>
</div>

<?php
$extraJs = <<<'JS'
initDataTable('#tbl-liq');
JS;
include __DIR__ . '/includes/footer.php';
?>

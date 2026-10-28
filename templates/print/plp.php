<?php
// ── Planilla Quincenal distribuida por Proyecto ─────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla no encontrada.');
$s2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre, e.cargo
    FROM planilla_detalle pd
    JOIN empleados e ON pd.empleado_id = e.id
    WHERE pd.periodo_id = ? ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$proyMap = proyectosMap($pdo);
[$repartos, $resumenProy] = distribuirQuincenal($detalle,
    cargarDistribucion($pdo, 'planilla_detalle_proyectos', array_column($detalle, 'id')));
$mes    = $meses[$doc['mes'] - 1] ?? '';
$quin   = $doc['quincena'] === 'primera' ? '1ª Quincena (1-15)' : '2ª Quincena (16-31)';
$title  = 'PLANILLA QUINCENAL POR PROYECTO';
$docNum = ($doc['numero'] ? "{$doc['numero']} — " : '') . "{$mes} {$doc['anio']} — {$quin}";

// Firmas
$fPL = loadFirmantes($pdo, 'pl');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Período</div>
        <div class="meta-value"><?= $meses[$doc['mes'] - 1] . ' ' . $doc['anio'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Quincena</div>
        <div class="meta-value"><?= $doc['quincena'] === 'primera' ? '1ª (1-15)' : '2ª (16-31)' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">No. Proyectos</div>
        <div class="meta-value"><?= count($resumenProy) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Resumen por Proyecto</div>
  <div class="items-wrap">
    <table class="items" style="font-size:8pt">
      <thead>
        <tr>
          <th>Proyecto</th><th class="r">Empleados</th><th class="r">Bruto</th><th class="r">Deducciones</th>
          <th class="r">Neto a Pagar</th><th class="r">IHSS Pat.</th><th class="r">RAP Pat.</th><th class="r">Costo Total</th>
        </tr>
      </thead>
      <tbody>
        <?php $tR = ['total_bruto'=>0,'total_deducciones'=>0,'sueldo_neto'=>0,'ihss_patronal'=>0,'rap_patronal'=>0,'costo_total'=>0];
        foreach ($resumenProy as $pid => $r): foreach ($tR as $k => $_) $tR[$k] += $r[$k]; ?>
        <tr>
          <td><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></td>
          <td class="r"><?= $r['empleados'] ?></td>
          <td class="r"><?= lps($r['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($r['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($r['sueldo_neto']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($r['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($r['rap_patronal']) ?></td>
          <td class="r" style="font-weight:700"><?= lps($r['costo_total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="font-weight:700;color:#0D3F6A">TOTALES</td>
          <td class="r"><?= lps($tR['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($tR['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a"><?= lps($tR['sueldo_neto']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($tR['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($tR['rap_patronal']) ?></td>
          <td class="r"><?= lps($tR['costo_total']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php foreach ($resumenProy as $pid => $r): ?>
  <div class="section-head" style="margin-top:12px"><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></div>
  <div class="items-wrap">
    <table class="items" style="font-size:7.5pt">
      <thead>
        <tr>
          <th style="width:2em">#</th><th>Empleado</th><th>Cargo</th>
          <th class="r">% Sal.</th><th class="r">Bruto</th><th class="r">Deducciones</th><th class="r">Neto</th>
          <th class="r">% Pat.</th><th class="r">IHSS Pat.</th><th class="r">RAP Pat.</th><th class="r">Costo Total</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($detalle as $d): if (!isset($repartos[$d['id']][$pid])) continue; $x = $repartos[$d['id']][$pid]; ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['empleado_nombre']) ?></td>
          <td style="font-size:7pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r"><?= number_format($x['pct_salario'], 2) ?>%</td>
          <td class="r"><?= lps($x['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($x['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($x['sueldo_neto']) ?></td>
          <td class="r"><?= number_format($x['pct_patronal'], 2) ?>%</td>
          <td class="r" style="color:#b45309"><?= lps($x['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($x['rap_patronal']) ?></td>
          <td class="r" style="font-weight:600"><?= lps($x['costo_total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="font-weight:700;color:#0D3F6A">SUBTOTAL</td>
          <td class="r"><?= lps($r['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($r['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a"><?= lps($r['sueldo_neto']) ?></td>
          <td></td>
          <td class="r" style="color:#b45309"><?= lps($r['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($r['rap_patronal']) ?></td>
          <td class="r"><?= lps($r['costo_total']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endforeach; ?>

  <?= sigBlock($fPL, 'cols-3', 'margin-top:20px') ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

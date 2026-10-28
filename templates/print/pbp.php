<?php
// ── Planilla de Beneficios distribuida por Proyecto ─────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT * FROM planilla_beneficios WHERE id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla de beneficios no encontrada.');
$s2 = $pdo->prepare("SELECT pbd.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo
    FROM planilla_beneficios_detalle pbd JOIN empleados e ON pbd.empleado_id = e.id
    WHERE pbd.beneficio_id = ? ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$proyMap = proyectosMap($pdo);
[$repartos, $resumenProy] = distribuirBeneficios($detalle,
    cargarDistribucion($pdo, 'planilla_beneficios_detalle_proyectos', array_column($detalle, 'id')));
$pbTipos = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];
$title  = strtoupper($pbTipos[$doc['tipo']] ?? $doc['tipo']) . ' POR PROYECTO';
$docNum = $doc['numero'];

// Firmas
$fPB = loadFirmantes($pdo, 'pb');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Tipo</div>
        <div class="meta-value"><?= $pbTipos[$doc['tipo']] ?? ucfirst($doc['tipo']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Año</div>
        <div class="meta-value"><?= $doc['anio'] ?></div>
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
    <table class="items">
      <thead>
        <tr><th>Proyecto</th><th class="r">Empleados</th><th class="r">Beneficio</th><th class="r">Deducciones</th><th class="r">Neto a Pagar</th></tr>
      </thead>
      <tbody>
        <?php $tRB = ['sueldo_mensual'=>0,'deduccion'=>0,'neto'=>0];
        foreach ($resumenProy as $pid => $r): foreach ($tRB as $k => $_) $tRB[$k] += $r[$k]; ?>
        <tr>
          <td><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></td>
          <td class="r"><?= $r['empleados'] ?></td>
          <td class="r"><?= lps($r['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($r['deduccion']) ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($r['neto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="font-weight:700;color:#0D3F6A">TOTALES</td>
          <td class="r"><?= lps($tRB['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($tRB['deduccion']) ?></td>
          <td class="r" style="color:#16a34a"><?= lps($tRB['neto']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php foreach ($resumenProy as $pid => $r): ?>
  <div class="section-head" style="margin-top:12px"><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></div>
  <div class="items-wrap">
    <table class="items" style="font-size:8pt">
      <thead>
        <tr>
          <th style="width:2em">#</th><th>Empleado</th><th>Cargo</th>
          <th class="r">%</th><th class="r">Beneficio</th><th class="r">Deducción</th><th class="r">Neto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($detalle as $d): if (!isset($repartos[$d['id']][$pid])) continue; $x = $repartos[$d['id']][$pid]; ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['emp_nombre']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r"><?= number_format($x['porcentaje'], 2) ?>%</td>
          <td class="r"><?= lps($x['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= $x['deduccion'] > 0 ? lps($x['deduccion']) : '—' ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($x['neto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="font-weight:700;color:#0D3F6A">SUBTOTAL</td>
          <td class="r"><?= lps($r['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($r['deduccion']) ?></td>
          <td class="r" style="color:#16a34a"><?= lps($r['neto']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endforeach; ?>

  <?= sigBlock($fPB, 'cols-3', 'margin-top:20px') ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

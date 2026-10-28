<?php
// ── Reporte de Deducciones Credimpulsa ──────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla no encontrada.');
$s2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre,
    e.cargo, e.identidad
    FROM planilla_detalle pd
    JOIN empleados e ON pd.empleado_id = e.id
    WHERE pd.periodo_id = ? AND pd.credimpulsa > 0
    ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$mes    = $meses[$doc['mes'] - 1] ?? '';
$quin   = nombrePeriodoPlanilla($doc);
$title  = 'REPORTE CREDIMPULSA';
$docNum = ($doc['numero'] ? "{$doc['numero']} — " : '') . "{$mes} {$doc['anio']} — {$quin}";

// Firmas
$fPLC = loadFirmantes($pdo, 'pl');

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
        <div class="meta-label"><?= esPlanillaMensual($doc) ? 'Tipo' : 'Quincena' ?></div>
        <div class="meta-value"><?= esPlanillaMensual($doc) ? 'Mensual' : ($doc['quincena'] === 'primera' ? '1ª (1-15)' : '2ª (16-31)') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Empleados con Deducción</div>
        <div class="meta-value"><?= count($detalle) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Detalle de Deducciones Credimpulsa</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2em">#</th>
          <th>Empleado</th>
          <th>N° Identidad</th>
          <th>Cargo</th>
          <th class="r">Deducción Credimpulsa</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; $totCred=0; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['empleado_nombre']) ?></td>
          <td class="c" style="font-family:'Courier New',monospace;font-size:8pt"><?= htmlspecialchars($d['identidad'] ?? '—') ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r" style="color:#dc2626;font-weight:600"><?= lps($d['credimpulsa']) ?></td>
        </tr>
        <?php $totCred += $d['credimpulsa']; endforeach; ?>
        <?php if (!$detalle): ?>
        <tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;font-style:italic">No hay deducciones Credimpulsa para este período.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px;font-weight:700;color:#0D3F6A">TOTAL CREDIMPULSA</td>
          <td class="r" style="color:#dc2626;font-weight:800;font-size:10pt"><?= lps($totCred) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="amount-block" style="margin:16px 28px">
    <div>
      <div class="amount-label">Total Deducido Credimpulsa</div>
      <div class="amount-value"><?= lps($totCred ?? 0) ?></div>
    </div>
    <div style="margin-left:auto;text-align:right;padding-right:8px">
      <div class="amount-label">Período</div>
      <div style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($docNum) ?></div>
    </div>
  </div>

  <?= sigBlock($fPLC, 'cols-3', 'margin-top:20px') ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

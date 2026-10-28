<?php
// ── Planilla de Beneficios (13°/14° mes) ────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT * FROM planilla_beneficios WHERE id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla de beneficios no encontrada.');
$s2 = $pdo->prepare("SELECT pbd.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    e.cargo, e.banco, e.cuenta_banco
    FROM planilla_beneficios_detalle pbd JOIN empleados e ON pbd.empleado_id = e.id
    WHERE pbd.beneficio_id = ? ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$pbTipos = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];
$title  = 'PLANILLA DE BENEFICIOS — ' . strtoupper($pbTipos[$doc['tipo']] ?? $doc['tipo']);
$docNum = $doc['numero'];

// Firmas
$fPB = loadFirmantes($pdo, 'pb');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php $pbTiposLabel = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes']; ?>
  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Tipo</div>
        <div class="meta-value"><?= $pbTiposLabel[$doc['tipo']] ?? ucfirst($doc['tipo']) ?></div>
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
        <div class="meta-label">No. Empleados</div>
        <div class="meta-value"><?= count($detalle) ?></div>
      </div>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle por Empleado</div>
  <div class="items-wrap">
    <table class="items" style="font-size:8.5pt">
      <thead>
        <tr>
          <th style="width:2em">#</th>
          <th>Empleado</th>
          <th>Cargo</th>
          <th class="r">Décimo Cuarto</th>
          <th class="r">Deducción</th>
          <th class="r">Total a Pagar (50%)</th>
          <th>Banco / Cuenta</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; $totBrutoPB=$totDedPB=$totNetoPB=0; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['emp_nombre']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r"><?= lps($d['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= $d['deduccion'] > 0 ? lps($d['deduccion']) : '—' ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($d['neto']) ?></td>
          <td style="font-size:7.5pt"><?= htmlspecialchars($d['banco'] ?? '—') ?>
            <?php if ($d['cuenta_banco']): ?><br><span style="color:#64748b;font-size:6.5pt;font-family:'Courier New',monospace"><?= htmlspecialchars($d['cuenta_banco']) ?></span><?php endif; ?></td>
        </tr>
        <?php $totBrutoPB+=$d['sueldo_mensual']; $totDedPB+=$d['deduccion']; $totNetoPB+=$d['neto']; endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="font-weight:700;color:#0D3F6A">TOTALES</td>
          <td class="r"><?= lps($totBrutoPB) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($totDedPB) ?></td>
          <td class="r" style="color:#16a34a;font-weight:700"><?= lps($totNetoPB) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fPB, 'cols-3', 'margin-top:20px') ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

<?php
// ── Vouchers Individuales de Beneficios ─────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido.

$s = $pdo->prepare("SELECT * FROM planilla_beneficios WHERE id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla de beneficios no encontrada.');
$s2 = $pdo->prepare("SELECT pbd.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    e.cargo, e.identidad, e.banco, e.cuenta_banco
    FROM planilla_beneficios_detalle pbd JOIN empleados e ON pbd.empleado_id = e.id
    WHERE pbd.beneficio_id = ? ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$pbvTipos = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];
$proyMap = proyectosMap($pdo);
$distV   = cargarDistribucion($pdo, 'planilla_beneficios_detalle_proyectos', array_column($detalle, 'id'));
[$repartosV] = distribuirBeneficios($detalle, $distV);
$title  = 'RECIBOS DE BENEFICIO — ' . strtoupper($pbvTipos[$doc['tipo']] ?? $doc['tipo']);
$docNum = $doc['numero'];

// Recibo de un solo empleado (?emp_id=)
if (isset($_GET['emp_id'])) {
    $vEmp    = (int)$_GET['emp_id'];
    $detalle = array_values(array_filter($detalle, fn($d) => ($d['empleado_id'] ?? 0) === $vEmp));
}

require __DIR__ . '/_inicio.php';
?>
<div id="all-pages">
<?php
$pbvTiposLabel = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];
$pbvLabel = $pbvTiposLabel[$doc['tipo']] ?? ucfirst($doc['tipo']);
foreach ($detalle as $i => $d):
    $safeEmpName = preg_replace('/[^\w]/', '_', $d['emp_nombre']);
?>
<div class="page voucher" id="voucher-<?= $i ?>" data-empname="<?= htmlspecialchars($safeEmpName) ?>" data-empfull="<?= htmlspecialchars($d['emp_nombre']) ?>">
  <div class="top-bar"></div>
  <div class="doc-header">
    <?php $orgContacto = false; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type">RECIBO DE BENEFICIO</div>
      <div class="doc-number" style="font-size:11pt"><?= htmlspecialchars($pbvLabel) ?></div>
      <div style="font-size:8.5pt;color:#64748b;margin-top:3px">Año <?= $doc['anio'] ?></div>
    </div>
  </div>

  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($d['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($d['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($d['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <?php if ($d['banco'] ?? ''): ?>
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($d['banco']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($d['cuenta_banco'] ?? ''): ?>
      <div class="meta-item">
        <div class="meta-label">No. Cuenta</div>
        <div class="meta-value mono"><?= htmlspecialchars($d['cuenta_banco']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Pago</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px;color:#374151">Décimo Cuarto</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#374151"><?= lps($d['sueldo_mensual']) ?></td>
        </tr>
        <?php if ($d['deduccion'] > 0): ?>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px;color:#64748b">Deducción</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626">(<?= lps($d['deduccion']) ?>)</td>
        </tr>
        <?php endif; ?>
        <tr style="border-bottom:2px solid #16a34a;background:#f0fdf4">
          <td style="padding:8px;font-weight:700;color:#15803d">Total a Pagar (50%)</td>
          <td style="padding:8px;text-align:right;font-family:'Courier New',monospace;font-weight:800;color:#16a34a;font-size:11pt"><?= lps($d['neto']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php $tieneDist = !empty($distV[$d['id']]);
  if ($tieneDist) echo voucherDistTabla($repartosV[$d['id']], $proyMap,
      ['porcentaje' => '%', 'sueldo_mensual' => 'BENEFICIO', 'deduccion' => 'DEDUCCIÓN', 'neto' => 'NETO']); ?>

  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label"><?= htmlspecialchars($pbvLabel) ?></div>
      <div class="amount-value"><?= lps($d['neto']) ?></div>
    </div>
  </div>

  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($d['emp_nombre'])) ?></strong><?php if ($d['identidad'] ?? ''): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($d['identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($d['neto']) ?></strong>
      correspondiente al <em><?= htmlspecialchars($pbvLabel) ?></em> del año <strong><?= $doc['anio'] ?></strong>, en conformidad con lo indicado<?= $tieneDist ? ', incluida la distribución por proyecto' : '' ?>.
    </p>
    <div style="display:grid;grid-template-columns:1fr 180px;gap:20px;margin-top:8px">
      <div style="text-align:center">
        <div style="border-top:1.5px solid #64748b;margin-bottom:5px;margin-top:36px"></div>
        <div style="font-size:8pt;font-weight:600"><?= htmlspecialchars($d['emp_nombre']) ?></div>
        <div style="font-size:7.5pt;color:#64748b">Firma y nombre del empleado</div>
      </div>
      <div>
        <div style="font-size:8pt;color:#64748b;margin-bottom:4px">Fecha de recibo:</div>
        <div style="border-bottom:1px solid #64748b;height:28px"></div>
      </div>
    </div>
  </div>

  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right"><?= htmlspecialchars($pbvLabel) ?> <?= $doc['anio'] ?> — <?= htmlspecialchars($d['emp_nombre']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endforeach; ?>
</div><!-- #all-pages pbv -->
<?php require __DIR__ . '/_fin.php';

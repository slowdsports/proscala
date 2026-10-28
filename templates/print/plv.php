<?php
// ── Vouchers Individuales de Planilla ───────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido.

$s = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla no encontrada.');
$s2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre,
    e.cargo, e.banco, e.cuenta_banco, e.identidad, e.sueldo_mensual AS sueldo_base
    FROM planilla_detalle pd
    JOIN empleados e ON pd.empleado_id = e.id
    WHERE pd.periodo_id = ? ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$mes    = $meses[$doc['mes'] - 1] ?? '';
$quin   = nombrePeriodoPlanilla($doc);
$plMensual = esPlanillaMensual($doc);
// Distribución por proyecto (el donante requiere el recibo firmado por el empleado)
$proyMap = proyectosMap($pdo);
$distV   = cargarDistribucion($pdo, 'planilla_detalle_proyectos', array_column($detalle, 'id'));
[$repartosV] = distribuirQuincenal($detalle, $distV);
$title  = $plMensual ? 'RECIBOS DE PAGO MENSUAL' : 'RECIBOS DE QUINCENA';
$docNum = ($doc['numero'] ? "{$doc['numero']} — " : '') . "{$mes} {$doc['anio']} — {$quin}";

// Recibo de un solo empleado (?emp_id=)
if (isset($_GET['emp_id'])) {
    $vEmp    = (int)$_GET['emp_id'];
    $detalle = array_values(array_filter($detalle, fn($d) => ($d['empleado_id'] ?? 0) === $vEmp));
}

require __DIR__ . '/_inicio.php';
?>
<div id="all-pages">
<?php
$mesNombrePlv = $meses[$doc['mes'] - 1];
$quinceNamePlv = $quin;
foreach ($detalle as $i => $d):
    $safeEmpName = preg_replace('/[^\w]/', '_', $d['empleado_nombre']);
?>
<div class="page voucher" id="voucher-<?= $i ?>" data-empname="<?= htmlspecialchars($safeEmpName) ?>" data-empfull="<?= htmlspecialchars($d['empleado_nombre']) ?>">
  <div class="top-bar"></div>
  <div class="doc-header">
    <?php $orgContacto = false; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type"><?= $plMensual ? 'RECIBO DE PAGO MENSUAL' : 'RECIBO DE QUINCENA' ?></div>
      <div class="doc-number" style="font-size:13pt"><?= htmlspecialchars($mesNombrePlv . ' ' . $doc['anio']) ?></div>
      <div style="font-size:8.5pt;color:#64748b;margin-top:3px"><?= $quinceNamePlv ?></div>
    </div>
  </div>

  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($d['empleado_nombre']) ?></div>
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
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($d['banco'] ?? '—') ?></div>
      </div>
      <?php if ($d['cuenta_banco']): ?>
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
          <td style="padding:7px 8px;color:#374151">Sueldo Mensual Base</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#374151"><?= lps($d['sueldo_base'] ?? ($d['total_bruto'] * 2)) ?></td>
        </tr>
        <tr style="border-bottom:2px solid #e2e8f0;background:#f0f5fc">
          <td style="padding:8px 8px;font-weight:700;color:#0D3F6A"><?= $plMensual ? 'Sueldo Bruto' : 'Quincena Bruta' ?></td>
          <td style="padding:8px 8px;text-align:right;font-family:'Courier New',monospace;font-weight:700;color:#0D3F6A"><?= lps($d['total_bruto']) ?></td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px 7px 20px;color:#64748b;font-size:8.5pt">IHSS (Empleado)</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626;font-size:8.5pt">(<?= lps($d['ihss_empleado']) ?>)</td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px 7px 20px;color:#64748b;font-size:8.5pt">RAP (Empleado)</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626;font-size:8.5pt">(<?= lps($d['rap_empleado']) ?>)</td>
        </tr>
        <?php if ($d['isr'] > 0): ?>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px 7px 20px;color:#64748b;font-size:8.5pt">ISR</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626;font-size:8.5pt">(<?= lps($d['isr']) ?>)</td>
        </tr>
        <?php endif; ?>
        <?php if ($d['credimpulsa'] > 0): ?>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px 7px 20px;color:#64748b;font-size:8.5pt">Credimpulsa</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626;font-size:8.5pt">(<?= lps($d['credimpulsa']) ?>)</td>
        </tr>
        <?php endif; ?>
        <tr style="border-bottom:2px solid #dc2626">
          <td style="padding:7px 8px;font-weight:600;color:#991b1b">Total Deducciones</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;font-weight:700;color:#dc2626">(<?= lps($d['total_deducciones']) ?>)</td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php $tieneDist = !empty($distV[$d['id']]);
  if ($tieneDist) echo voucherDistTabla($repartosV[$d['id']], $proyMap,
      ['pct_salario' => '%', 'total_bruto' => $plMensual ? 'SUELDO BRUTO' : 'QUINCENA BRUTA', 'total_deducciones' => 'DEDUCCIONES', 'sueldo_neto' => 'NETO']); ?>

  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label">Total a Pagar</div>
      <div class="amount-value"><?= lps($d['sueldo_neto']) ?></div>
    </div>
  </div>

  <?php if (!empty($d['observacion'])): ?>
  <div style="margin:12px 28px 0;padding:10px 14px;border-left:3px solid #1B6BA8;background:#f0f7ff;border-radius:4px">
    <div class="obs-label" style="font-size:7.5pt;font-weight:700;color:#1B6BA8;margin-bottom:3px;text-transform:uppercase;letter-spacing:.05em">Observación</div>
    <div style="font-size:8.5pt;color:#374151"><?= htmlspecialchars($d['observacion']) ?></div>
  </div>
  <?php endif; ?>
  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($d['empleado_nombre'])) ?></strong><?php if ($d['identidad']): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($d['identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($d['sueldo_neto']) ?></strong> correspondiente a la
      <?= $quinceNamePlv ?> del período <strong><?= htmlspecialchars($mesNombrePlv . ' ' . $doc['anio']) ?></strong>,
      en conformidad con el detalle<?= $tieneDist ? ' y la distribución por proyecto' : '' ?> indicado<?= $tieneDist ? 's' : '' ?>.
    </p>
    <div style="display:grid;grid-template-columns:1fr 180px;gap:20px;margin-top:8px">
      <div style="text-align:center">
        <div style="border-top:1.5px solid #64748b;margin-bottom:5px;margin-top:36px"></div>
        <div style="font-size:8pt;font-weight:600"><?= htmlspecialchars($d['empleado_nombre']) ?></div>
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
    <div class="footer-right">RECIBO — <?= htmlspecialchars($d['empleado_nombre']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endforeach; ?>
</div><!-- #all-pages plv -->
<?php require __DIR__ . '/_fin.php';

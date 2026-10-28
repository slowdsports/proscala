<?php
// ── Voucher de Alimentación de Viaje ────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido.

$s = $pdo->prepare("SELECT gv.*,
    CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    e.identidad AS emp_identidad, e.cargo AS emp_cargo,
    e.banco, e.cuenta_banco,
    p.codigo AS proy_nombre,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM gastos_viaje gv
    JOIN empleados e ON gv.empleado_id = e.id
    LEFT JOIN proyectos p ON gv.proyecto_id = p.id
    LEFT JOIN empleados a ON gv.aprobador_id = a.id
    WHERE gv.id = ? AND gv.monto_alimentacion > 0");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Anticipo no encontrado o sin monto de alimentación.');
$title  = 'VOUCHER DE ALIMENTACIÓN';
$docNum = $doc['numero'];

require __DIR__ . '/_inicio.php';
?>
<!-- ══ VOUCHER DE ALIMENTACIÓN DE VIAJE ══ -->
<div class="page voucher">
  <div class="top-bar"></div>

  <!-- Header -->
  <div class="doc-header">
    <?php $orgContacto = false; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type">VOUCHER DE ALIMENTACIÓN</div>
      <div class="doc-number" style="font-size:13pt"><?= htmlspecialchars($docNum) ?></div>
      <div style="font-size:8pt;color:#64748b;margin-top:3px">
        <?= fmtFecha($doc['fecha_salida']) ?> – <?= fmtFecha($doc['fecha_regreso']) ?>
      </div>
    </div>
  </div>

  <!-- Meta: employee + trip info -->
  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <?php if ($doc['emp_identidad']): ?>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['emp_identidad']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['emp_cargo']): ?>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_cargo']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item">
        <div class="meta-label">Destino</div>
        <div class="meta-value"><?= htmlspecialchars($doc['destino']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Salida</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_salida']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Regreso</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_regreso']) ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['banco']): ?>
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($doc['banco']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['cuenta_banco']): ?>
      <div class="meta-item">
        <div class="meta-label">No. Cuenta</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['cuenta_banco']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Detail: single alimentación line -->
  <div class="section-head">Detalle del Beneficio</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:8px">Anticipo de Viaje de Referencia</td>
          <td style="padding:8px;text-align:right;font-family:'Courier New',monospace;color:#374151"><?= htmlspecialchars($doc['numero']) ?></td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0;background:#f0f5fc">
          <td style="padding:8px;font-weight:700;color:#0D3F6A">Viáticos de Alimentación</td>
          <td style="padding:8px;text-align:right;font-family:'Courier New',monospace;font-weight:700;color:#0D3F6A"><?= lps($doc['monto_alimentacion']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Amount highlight -->
  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label">Monto de Alimentación a Pagar</div>
      <div class="amount-value"><?= lps($doc['monto_alimentacion']) ?></div>
    </div>
  </div>

  <!-- Receipt / signature box -->
  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($doc['emp_nombre'])) ?></strong><?php if ($doc['emp_identidad']): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($doc['emp_identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($doc['monto_alimentacion']) ?></strong>
      en concepto de <em>viáticos de alimentación</em> correspondientes al viaje a
      <strong><?= htmlspecialchars($doc['destino']) ?></strong>
      (<?= fmtFecha($doc['fecha_salida']) ?> – <?= fmtFecha($doc['fecha_regreso']) ?>),
      según anticipo <strong><?= htmlspecialchars($doc['numero']) ?></strong>.
    </p>
    <div style="display:grid;grid-template-columns:1fr 180px;gap:20px;margin-top:8px">
      <div style="text-align:center">
        <div style="border-top:1.5px solid #64748b;margin-bottom:5px;margin-top:36px"></div>
        <div style="font-size:8pt;font-weight:600"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
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
    <div class="footer-right">ALIMENTACIÓN — <?= htmlspecialchars($doc['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php require __DIR__ . '/_fin.php';

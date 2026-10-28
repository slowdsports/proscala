<?php
// ── Vouchers Individuales de Asignación ─────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido.

$s = $pdo->prepare("SELECT pa.*, p.codigo AS proy_nombre
    FROM planilla_asignacion pa LEFT JOIN proyectos p ON pa.proyecto_id = p.id WHERE pa.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla de asignación no encontrada.');
$s2 = $pdo->prepare("SELECT pad.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    e.cargo, e.identidad, e.banco, e.cuenta_banco
    FROM planilla_asignacion_detalle pad JOIN empleados e ON pad.empleado_id = e.id
    WHERE pad.asignacion_id = ? ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$title  = 'RECIBOS DE ASIGNACIÓN';
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
$mesesPAV = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$periodoLabel = isset($doc['mes']) ? ($mesesPAV[$doc['mes']-1] . ' ' . $doc['anio']) : '';
foreach ($detalle as $i => $d):
    $safeEmpName = preg_replace('/[^\w]/', '_', $d['emp_nombre']);
?>
<div class="page voucher" id="voucher-<?= $i ?>" data-empname="<?= htmlspecialchars($safeEmpName) ?>" data-empfull="<?= htmlspecialchars($d['emp_nombre']) ?>">
  <div class="top-bar"></div>
  <div class="doc-header">
    <?php $orgContacto = false; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type">RECIBO DE ASIGNACIÓN</div>
      <div class="doc-number" style="font-size:13pt"><?= htmlspecialchars($doc['numero']) ?></div>
      <?php if ($periodoLabel): ?><div style="font-size:8.5pt;color:#64748b;margin-top:3px"><?= htmlspecialchars($periodoLabel) ?></div><?php endif; ?>
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
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
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
      <?php if ($doc['descripcion'] ?? ''): ?>
      <div class="meta-item" style="grid-column:1/-1">
        <div class="meta-label">Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['descripcion']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Asignación</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:2px solid #e2e8f0;background:#f0f5fc">
          <td style="padding:8px;font-weight:700;color:#0D3F6A">Concepto</td>
          <td style="padding:8px;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($d['concepto']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label">Monto Asignado</div>
      <div class="amount-value"><?= lps($d['monto']) ?></div>
    </div>
  </div>

  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($d['emp_nombre'])) ?></strong><?php if ($d['identidad'] ?? ''): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($d['identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($d['monto']) ?></strong>
      en concepto de <em><?= htmlspecialchars($d['concepto']) ?></em>, en conformidad con lo indicado.
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
    <div class="footer-right">ASIGNACIÓN <?= htmlspecialchars($doc['numero']) ?> — <?= htmlspecialchars($d['emp_nombre']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endforeach; ?>
</div><!-- #all-pages pav -->
<?php require __DIR__ . '/_fin.php';

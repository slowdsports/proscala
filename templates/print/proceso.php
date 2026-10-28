<?php
// ── Proceso de Compra Completo ──────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido.

$s = $pdo->prepare("SELECT sc.*, CONCAT(e.nombre,' ',e.apellidos) AS sol_nombre, p.codigo AS proy_nombre,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM solicitud_compra sc JOIN empleados e ON sc.solicitante_id = e.id
    LEFT JOIN proyectos p ON sc.proyecto_id = p.id
    LEFT JOIN empleados a ON sc.aprobador_id = a.id WHERE sc.id = ?");
$s->execute([$id]); $proc_sc = $s->fetch();
if (!$proc_sc) die('Solicitud de compra no encontrada.');
$s2 = $pdo->prepare("SELECT * FROM solicitud_compra_detalle WHERE solicitud_id = ?");
$s2->execute([$id]); $proc_sc_det = $s2->fetchAll();
$proc_oc = null; $proc_oc_det = [];
$s3 = $pdo->prepare("SELECT oc.*, p.nombre AS prov_nombre, p.rtn AS prov_rtn,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM ordenes_compra oc JOIN proveedores p ON oc.proveedor_id = p.id
    LEFT JOIN empleados a ON oc.aprobador_id = a.id
    WHERE oc.solicitud_id = ? ORDER BY oc.id DESC LIMIT 1");
$s3->execute([$id]); $proc_oc = $s3->fetch();
if ($proc_oc) {
    $s4 = $pdo->prepare("SELECT ocd.*, cc.nombre AS cta_nombre FROM ordenes_compra_detalle ocd
        LEFT JOIN cuentas_contables cc ON ocd.cuenta_id = cc.id WHERE ocd.orden_id = ?");
    $s4->execute([$proc_oc['id']]); $proc_oc_det = $s4->fetchAll();
}
$proc_op = null;
if ($proc_oc) {
    $s5 = $pdo->prepare("SELECT op.*,
        CONCAT(a1.nombre,' ',a1.apellidos) AS aprobador1_nombre,
        CONCAT(a2.nombre,' ',a2.apellidos) AS aprobador2_nombre
        FROM ordenes_pago op
        LEFT JOIN empleados a1 ON op.aprobador1_id = a1.id
        LEFT JOIN empleados a2 ON op.aprobador2_id = a2.id
        WHERE op.orden_compra_id = ? ORDER BY op.id DESC LIMIT 1");
    $s5->execute([$proc_oc['id']]); $proc_op = $s5->fetch();
}
$doc    = $proc_sc;
$title  = 'PROCESO DE COMPRA';
$docNum = $proc_sc['numero'];

// Firmas
$fSC = firmasFlujo($pdo, loadFirmantes($pdo, 'sc'), 'compras_solicitud.php', $id);
$fOC = loadFirmantes($pdo, 'oc');
$fOP = loadFirmantes($pdo, 'op');
if ($proc_oc) $fOC = firmasFlujo($pdo, $fOC, 'compras_orden.php', (int)$proc_oc['id']);
if ($proc_op) $fOP = firmasFlujo($pdo, $fOP, 'compras_pago.php', (int)$proc_op['id']);

require __DIR__ . '/_inicio.php';
?>
<div id="all-pages">

<!-- ══ PÁGINA 1: SOLICITUD DE COMPRA ══ -->
<div class="page">
  <div class="top-bar"></div>
  <div class="doc-header">
    <?php $orgContacto = true; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type">SOLICITUD DE COMPRA</div>
      <div class="doc-number"><?= htmlspecialchars($proc_sc['numero']) ?></div>
      <div><span class="doc-estado est-<?= $proc_sc['estado'] ?>"><?= ucfirst($proc_sc['estado']) ?></span></div>
    </div>
  </div>
  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item"><div class="meta-label">Fecha</div><div class="meta-value"><?= fmtFecha($proc_sc['fecha']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Solicitante</div><div class="meta-value"><?= htmlspecialchars($proc_sc['sol_nombre']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Proyecto</div><div class="meta-value"><?= htmlspecialchars($proc_sc['proy_nombre'] ?? '—') ?></div></div>
      <div class="meta-item"><div class="meta-label">Urgencia</div><div class="meta-value"><?= str_replace('_',' ',ucfirst($proc_sc['urgencia'])) ?></div></div>
      <div class="meta-item meta-full"><div class="meta-label">Descripción</div><div class="meta-value"><?= htmlspecialchars($proc_sc['descripcion']) ?></div></div>
      <?php if ($proc_sc['justificacion']): ?><div class="meta-item meta-full"><div class="meta-label">Justificación</div><div class="meta-value"><?= htmlspecialchars($proc_sc['justificacion']) ?></div></div><?php endif; ?>
    </div>
  </div>
  <div class="section-head">Artículos / Servicios Requeridos</div>
  <div class="items-wrap">
    <table class="items">
      <thead><tr><th style="width:2.5em">#</th><th>Descripción</th><th>Unidad</th><th class="r">Cantidad</th><th class="r">Precio Est.</th><th class="r">Total Est.</th></tr></thead>
      <tbody>
        <?php $i=1; $tot=0; foreach ($proc_sc_det as $d): $sub=($d['cantidad']??1)*($d['precio_estimado']??0); $tot+=$sub; ?>
        <tr><td class="c"><?= $i++ ?></td><td><?= htmlspecialchars($d['descripcion']) ?></td><td class="c"><?= htmlspecialchars($d['unidad']??'') ?></td><td class="r"><?= number_format($d['cantidad']??1,2) ?></td><td class="r"><?= lps($d['precio_estimado']??0) ?></td><td class="r"><?= lps($sub) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">PRESUPUESTO ESTIMADO TOTAL</td><td class="r"><?= lps($tot) ?></td></tr></tfoot>
    </table>
  </div>
  <?= sigBlock($fSC) ?>
  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">SOLICITUD DE COMPRA — <?= htmlspecialchars($proc_sc['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>

<?php if ($proc_oc): ?>
<!-- ══ PÁGINA 2: ORDEN DE COMPRA ══ -->
<div class="page">
  <div class="top-bar"></div>
  <div class="doc-header">
    <?php $orgContacto = true; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type">ORDEN DE COMPRA</div>
      <div class="doc-number"><?= htmlspecialchars($proc_oc['numero']) ?></div>
      <div><span class="doc-estado est-<?= $proc_oc['estado'] ?>"><?= ucfirst($proc_oc['estado']) ?></span></div>
    </div>
  </div>
  <div class="ref-chain">
    <span class="ref-pill">📋 <?= htmlspecialchars($proc_sc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">🛒 <?= htmlspecialchars($proc_oc['numero']) ?></span>
  </div>
  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item"><div class="meta-label">Fecha Emisión</div><div class="meta-value"><?= fmtFecha($proc_oc['fecha']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Fecha Entrega</div><div class="meta-value"><?= fmtFecha($proc_oc['fecha_entrega']??'') ?></div></div>
      <div class="meta-item"><div class="meta-label">Condiciones de Pago</div><div class="meta-value"><?= htmlspecialchars($proc_oc['condiciones_pago']??'—') ?></div></div>
      <div class="meta-item"><div class="meta-label">Lugar de Entrega</div><div class="meta-value"><?= htmlspecialchars($proc_oc['lugar_entrega']??'—') ?></div></div>
      <div class="meta-item" style="grid-column:1/3"><div class="meta-label">Proveedor</div><div class="meta-value"><?= htmlspecialchars($proc_oc['prov_nombre']) ?></div></div>
      <div class="meta-item"><div class="meta-label">RTN Proveedor</div><div class="meta-value mono"><?= htmlspecialchars($proc_oc['prov_rtn']??'—') ?></div></div>
    </div>
  </div>
  <div class="section-head">Ítems de la Orden</div>
  <div class="items-wrap">
    <table class="items">
      <thead><tr><th style="width:2.5em">#</th><th>Descripción</th><th>Unidad</th><th class="r">Cantidad</th><th class="r">Precio Unit.</th><th class="r">Total</th></tr></thead>
      <tbody>
        <?php $i=1; foreach ($proc_oc_det as $d): ?>
        <tr><td class="c"><?= $i++ ?></td><td><?= htmlspecialchars($d['descripcion']) ?></td><td class="c"><?= htmlspecialchars($d['unidad']??'') ?></td><td class="r"><?= number_format($d['cantidad']??1,2) ?></td><td class="r"><?= lps($d['precio_unitario']) ?></td><td class="r"><?= lps($d['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Subtotal</td><td class="r"><?= lps($proc_oc['subtotal']) ?></td></tr>
        <?php if (($proc_oc['descuento']??0) > 0): ?>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Descuento</td><td class="r">-<?= lps($proc_oc['descuento']) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Impuesto ISV</td><td class="r"><?= lps($proc_oc['impuestos']) ?></td></tr>
        <?php if (($proc_oc['impuesto_turismo']??0) > 0): ?>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Impuesto Turismo</td><td class="r"><?= lps($proc_oc['impuesto_turismo']) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="5" style="text-align:right;padding-right:12px">TOTAL</td><td class="r"><?= lps($proc_oc['total']) ?></td></tr>
      </tfoot>
    </table>
  </div>
  <?php
  echo sigBlock($fOC);
  echo sigProvBlock($proc_oc['prov_nombre'] ?? '', 'Nombre, Firma y Sello');
  ?>
  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">ORDEN DE COMPRA — <?= htmlspecialchars($proc_oc['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endif; /* proc_oc */ ?>

<?php if ($proc_op): ?>
<!-- ══ PÁGINA 3: ORDEN DE PAGO ══ -->
<div class="page">
  <div class="top-bar"></div>
  <div class="doc-header">
    <?php $orgContacto = true; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type">ORDEN DE PAGO</div>
      <div class="doc-number"><?= htmlspecialchars($proc_op['numero']) ?></div>
      <div><span class="doc-estado est-<?= $proc_op['estado'] ?>"><?= ucfirst($proc_op['estado']) ?></span></div>
    </div>
  </div>
  <div class="ref-chain">
    <span class="ref-pill">📋 <?= htmlspecialchars($proc_sc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill">🛒 <?= htmlspecialchars($proc_oc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">💳 <?= htmlspecialchars($proc_op['numero']) ?></span>
  </div>
  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item"><div class="meta-label">Fecha</div><div class="meta-value"><?= fmtFecha($proc_op['fecha']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Forma de Pago</div><div class="meta-value"><?= ucfirst($proc_op['forma_pago']) ?></div></div>
      <?php if ($proc_op['numero_cheque']): ?><div class="meta-item"><div class="meta-label">N° Cheque</div><div class="meta-value mono"><?= htmlspecialchars($proc_op['numero_cheque']) ?></div></div><?php endif; ?>
      <?php if ($proc_op['factura_proveedor']): ?><div class="meta-item"><div class="meta-label">N° Factura Proveedor</div><div class="meta-value mono"><?= htmlspecialchars($proc_op['factura_proveedor']) ?></div></div><?php endif; ?>
      <div class="meta-item"><div class="meta-label">Fecha de Pago</div><div class="meta-value"><?= fmtFecha($proc_op['fecha_pago']??'') ?></div></div>
      <div class="meta-item" style="grid-column:1/3"><div class="meta-label">Beneficiario</div><div class="meta-value" style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($proc_op['beneficiario']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Banco</div><div class="meta-value"><?= htmlspecialchars($proc_op['banco']??'—') ?></div></div>
      <div class="meta-item"><div class="meta-label">Cuenta Bancaria</div><div class="meta-value mono"><?= htmlspecialchars($proc_op['cuenta_bancaria']??'—') ?></div></div>
      <div class="meta-item meta-full"><div class="meta-label">Concepto</div><div class="meta-value"><?= htmlspecialchars($proc_op['concepto']) ?></div></div>
    </div>
  </div>
  <div class="amount-block">
    <div><div class="amount-label">Monto a Pagar</div><div class="amount-value"><?= lps($proc_op['monto']) ?></div></div>
  </div>
  <?= sigBlock($fOP) ?>
  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">ORDEN DE PAGO — <?= htmlspecialchars($proc_op['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endif; /* proc_op */ ?>

</div><!-- #all-pages -->
<?php require __DIR__ . '/_fin.php';

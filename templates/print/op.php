<?php
// ── Orden de Pago ───────────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT op.*,
    p.nombre AS prov_nombre,
    oc.numero AS oc_numero,
    sc.numero AS sc_numero,
    CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    CONCAT(a1.nombre,' ',a1.apellidos) AS aprobador1_nombre,
    CONCAT(a2.nombre,' ',a2.apellidos) AS aprobador2_nombre,
    pr.codigo AS proy_codigo, pr.nombre AS proy_nombre,
    cc.codigo AS cuenta_codigo, cc.nombre AS cuenta_nombre,
    sg.numero AS sg_numero, gv.numero AS gv_numero
    FROM ordenes_pago op
    LEFT JOIN proveedores p ON op.proveedor_id = p.id
    LEFT JOIN ordenes_compra oc ON op.orden_compra_id = oc.id
    LEFT JOIN solicitud_compra sc ON oc.solicitud_id = sc.id
    LEFT JOIN empleados e ON op.empleado_id = e.id
    LEFT JOIN empleados a1 ON op.aprobador1_id = a1.id
    LEFT JOIN empleados a2 ON op.aprobador2_id = a2.id
    LEFT JOIN proyectos pr ON op.proyecto_id = pr.id
    LEFT JOIN cuentas_contables cc ON op.cuenta_id = cc.id
    LEFT JOIN solicitud_gastos sg ON op.solicitud_gastos_id = sg.id
    LEFT JOIN gastos_viaje gv ON sg.viaje_id = gv.id
    WHERE op.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Orden de pago no encontrada.');
// Proceso que originó la OP (planilla, anticipo, préstamo…), si no es una OC o liquidación ya mostrada
$doc['origen_numero'] = $doc['origen_tabla'] && !in_array($doc['origen_tabla'], ['ordenes_compra', 'solicitud_gastos'], true)
    ? numeroDe($pdo, $doc['origen_tabla'], $doc['origen_id']) : null;
$title  = 'ORDEN DE PAGO';
$docNum = $doc['numero'];

// Firmas
$fOP = firmasFlujo($pdo, loadFirmantes($pdo, 'op'), 'compras_pago.php', $id);

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php if ($doc['sc_numero'] || $doc['oc_numero'] || $doc['sg_numero'] || $doc['origen_numero']): ?>
  <div class="ref-chain">
    <?php foreach (['📋' => $doc['sc_numero'], '🛒' => $doc['oc_numero'], '✈' => $doc['gv_numero'], '🧾' => $doc['sg_numero'], '📄' => $doc['origen_numero']] as $ico => $ref): if (!$ref) continue; ?>
      <span class="ref-pill"><?= $ico ?> <?= htmlspecialchars($ref) ?></span>
      <span class="ref-arrow">→</span>
    <?php endforeach; ?>
    <span class="ref-pill ref-current">💳 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>
  <?php endif; ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Forma de Pago</div>
        <div class="meta-value"><?= ucfirst($doc['forma_pago']) ?></div>
      </div>
      <?php if ($doc['numero_cheque']): ?>
      <div class="meta-item">
        <div class="meta-label">Número de Cheque</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['numero_cheque']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['factura_proveedor']): ?>
      <div class="meta-item">
        <div class="meta-label">N° Factura Proveedor</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['factura_proveedor']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <?php if ($doc['proceso_id']): ?>
      <div class="meta-item">
        <div class="meta-label">Proceso</div>
        <div class="meta-value mono"><?= formatProceso((int)$doc['proceso_id']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Beneficiario (<?= ['proveedor' => 'Proveedor', 'empleado' => 'Empleado', 'otro' => 'Otro'][$doc['beneficiario_tipo']] ?? 'Otro' ?>)</div>
        <div class="meta-value" style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($doc['beneficiario']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($doc['banco'] ?: '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cuenta Bancaria<?= $doc['banco_tipo'] ? ' (' . ucfirst($doc['banco_tipo']) . ')' : '' ?></div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['cuenta_bancaria'] ?: '—') ?></div>
      </div>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= $doc['proy_nombre'] ? htmlspecialchars($doc['proy_codigo'] . ' — ' . $doc['proy_nombre']) : 'General' ?></div>
      </div>
      <div class="meta-item" style="grid-column:3/5">
        <div class="meta-label">Cuenta Contable</div>
        <div class="meta-value"><?= $doc['cuenta_nombre'] ? htmlspecialchars($doc['cuenta_codigo'] . ' — ' . $doc['cuenta_nombre']) : '—' ?></div>
      </div>
      <div class="meta-item meta-full">
        <div class="meta-label">Concepto / Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['concepto']) ?></div>
      </div>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="amount-block">
    <div>
      <div class="amount-label">Monto a Pagar</div>
      <div class="amount-value"><?= lps($doc['monto']) ?></div>
    </div>
  </div>

  <?= sigBlock($fOP) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

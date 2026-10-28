<?php
// ── Orden de Compra ─────────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT oc.*, p.nombre AS prov_nombre, p.rtn AS prov_rtn, p.direccion AS prov_dir,
    proy.codigo AS proy_nombre,
    sc.numero AS sc_numero,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM ordenes_compra oc
    JOIN proveedores p ON oc.proveedor_id = p.id
    LEFT JOIN proyectos proy ON oc.proyecto_id = proy.id
    LEFT JOIN solicitud_compra sc ON oc.solicitud_id = sc.id
    LEFT JOIN empleados a ON oc.aprobador_id = a.id
    WHERE oc.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Orden de compra no encontrada.');
$s2 = $pdo->prepare("SELECT ocd.*, cc.nombre AS cta_nombre
    FROM ordenes_compra_detalle ocd
    LEFT JOIN cuentas_contables cc ON ocd.cuenta_id = cc.id
    WHERE ocd.orden_id = ?");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$title  = 'ORDEN DE COMPRA';
$docNum = $doc['numero'];

// Firmas
$fOC = firmasFlujo($pdo, loadFirmantes($pdo, 'oc'), 'compras_orden.php', $id);

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php if ($doc['sc_numero']): ?>
  <div class="ref-chain">
    <span class="ref-pill">📋 <?= htmlspecialchars($doc['sc_numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">🛒 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>
  <?php endif; ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha Emisión</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha Entrega</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_entrega'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Condiciones de Pago</div>
        <div class="meta-value"><?= htmlspecialchars($doc['condiciones_pago'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Lugar de Entrega</div>
        <div class="meta-value"><?= htmlspecialchars($doc['lugar_entrega'] ?? '—') ?></div>
      </div>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Proveedor</div>
        <div class="meta-value"><?= htmlspecialchars($doc['prov_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">RTN Proveedor</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['prov_rtn'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre'] ?? '—') ?></div>
      </div>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Ítems de la Orden</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Descripción</th>
          <th>Unidad</th>
          <th class="r">Cantidad</th>
          <th class="r">Precio Unit.</th>
          <th class="r">Total</th>
          <th>Cuenta</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td class="c"><?= htmlspecialchars($d['unidad'] ?? '') ?></td>
          <td class="r"><?= number_format($d['cantidad'] ?? 1, 2) ?></td>
          <td class="r"><?= lps($d['precio_unitario']) ?></td>
          <td class="r"><?= lps($d['total']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cta_nombre'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;font-size:8pt;color:#64748b">Subtotal</td>
          <td class="r"><?= lps($doc['subtotal']) ?></td>
          <td></td>
        </tr>
        <?php if (($doc['descuento']??0) > 0): ?>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;font-size:8pt;color:#64748b">Descuento</td>
          <td class="r">-<?= lps($doc['descuento']) ?></td>
          <td></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;font-size:8pt;color:#64748b">Impuesto ISV</td>
          <td class="r"><?= lps($doc['impuestos']) ?></td>
          <td></td>
        </tr>
        <?php if (($doc['impuesto_turismo']??0) > 0): ?>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;font-size:8pt;color:#64748b">Impuesto Turismo</td>
          <td class="r"><?= lps($doc['impuesto_turismo']) ?></td>
          <td></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px">TOTAL</td>
          <td class="r"><?= lps($doc['total']) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php
  echo sigBlock($fOC);
  echo sigProvBlock($doc['prov_nombre'] ?? '', 'Nombre, Firma y Sello');
  ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

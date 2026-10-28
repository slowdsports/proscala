<?php
// ── Resumen de Cotizaciones (cuadro comparativo) ────────────────
// Plantilla de impresión (print.php): la solicitud de compra de referencia, lo que se pidió,
// las ofertas de cada proveedor de la menor a la mayor, el proveedor seleccionado con su
// justificación y las firmas. Cotizaciones no tiene flujo de revisión: firman quien elaboró
// el resumen y quien lo aprobó (o los firmantes 'co' configurados, si los hay).

$s = $pdo->prepare("SELECT co.*, sc.numero AS sc_numero, sc.descripcion AS sc_desc, sc.presupuesto_estimado,
    CONCAT(sol.nombre,' ',sol.apellidos) AS sol_nombre, p.codigo AS proy_nombre,
    CONCAT(el.nombre,' ',el.apellidos) AS elab_nombre, CONCAT(ap.nombre,' ',ap.apellidos) AS aprobador_nombre
    FROM cotizaciones co
    JOIN solicitud_compra sc ON co.solicitud_id = sc.id
    LEFT JOIN empleados sol ON sc.solicitante_id = sol.id
    LEFT JOIN proyectos p ON sc.proyecto_id = p.id
    LEFT JOIN empleados el ON co.elaborado_por_id = el.id
    LEFT JOIN empleados ap ON co.aprobador_id = ap.id
    WHERE co.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Cotización no encontrada.');
$s2 = $pdo->prepare("SELECT cp.*, p.nombre AS prov_nombre, p.rtn AS prov_rtn
    FROM cotizaciones_proveedores cp JOIN proveedores p ON cp.proveedor_id = p.id
    WHERE cp.cotizacion_id = ? ORDER BY cp.total, p.nombre");
$s2->execute([$id]); $ofertas = $s2->fetchAll();
$s3 = $pdo->prepare("SELECT * FROM solicitud_compra_detalle WHERE solicitud_id = ? ORDER BY id");
$s3->execute([$doc['solicitud_id']]); $detalle = $s3->fetchAll();
$title  = 'RESUMEN DE COTIZACIONES';
$docNum = $doc['numero'];

$menor = $ofertas ? min(array_map(fn($o) => (float)$o['total'], $ofertas)) : 0;
$sel = null;
foreach ($ofertas as $o) if ((int)$o['proveedor_id'] === (int)$doc['proveedor_seleccionado_id']) $sel = $o;

// Firmas: las configuradas para 'co' o, si no hay, Elaborado por / Aprobado por del documento
$fCO = loadFirmantes($pdo, 'co') ?: [['posicion' => 1, 'etiqueta' => 'Elaborado por', 'emp_nombre' => ''],
                                      ['posicion' => 2, 'etiqueta' => 'Aprobado por',  'emp_nombre' => '']];
if ($doc['elab_nombre']) $fCO[0]['emp_nombre'] = $doc['elab_nombre'];
if ($doc['aprobador_nombre'] && count($fCO) > 1) $fCO[count($fCO) - 1]['emp_nombre'] = $doc['aprobador_nombre'];

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <div class="ref-chain">
    <span class="ref-pill">📋 <?= htmlspecialchars($doc['sc_numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">📑 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Solicitud de Compra</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['sc_numero']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Solicitante</div>
        <div class="meta-value"><?= htmlspecialchars($doc['sol_nombre'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre'] ?? 'General') ?></div>
      </div>
      <div class="meta-item meta-full">
        <div class="meta-label">Descripción de la compra</div>
        <div class="meta-value"><?= htmlspecialchars($doc['sc_desc']) ?></div>
      </div>
    </div>
  </div>

  <?php if ($detalle): ?>
  <div class="section-head">Artículos / Servicios Solicitados</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr><th style="width:2.5em">#</th><th>Descripción</th><th>Unidad</th><th class="r">Cantidad</th><th class="r">Precio Est.</th><th class="r">Total Est.</th></tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($detalle as $d): $sub = ($d['cantidad'] ?? 1) * ($d['precio_estimado'] ?? 0); ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td class="c"><?= htmlspecialchars($d['unidad'] ?? '') ?></td>
          <td class="r"><?= number_format($d['cantidad'] ?? 1, 2) ?></td>
          <td class="r"><?= lps($d['precio_estimado'] ?? 0) ?></td>
          <td class="r"><?= lps($sub) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt;">PRESUPUESTO ESTIMADO</td>
          <td class="r"><?= lps($doc['presupuesto_estimado'] ?? 0) ?></td></tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>

  <div class="section-head">Cuadro Comparativo de Ofertas (<?= count($ofertas) ?> proveedor<?= count($ofertas) === 1 ? '' : 'es' ?>)</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr><th style="width:2.5em">#</th><th>Proveedor</th><th>No. Cotización</th><th>Fecha</th><th>Condiciones de Pago</th><th>T. Entrega</th>
            <th class="r">Total Ofertado</th><th class="r">Dif. vs. menor</th></tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($ofertas as $o):
          $esSel = $sel && (int)$o['id'] === (int)$sel['id'];
          $dif = (float)$o['total'] - $menor; ?>
        <tr<?= $esSel ? ' style="background:#ecfdf5"' : '' ?>>
          <td class="c"><?= $i++ ?></td>
          <td>
            <strong><?= htmlspecialchars($o['prov_nombre']) ?></strong><?= $esSel ? ' <span style="color:#15803d;font-weight:700">✔ Seleccionado</span>' : '' ?>
            <?php if ($o['prov_rtn']): ?><div style="font-size:7pt;color:#64748b">RTN <?= htmlspecialchars($o['prov_rtn']) ?></div><?php endif; ?>
            <?php if ($o['notas']): ?><div style="font-size:7pt;color:#64748b"><?= htmlspecialchars($o['notas']) ?></div><?php endif; ?>
          </td>
          <td><?= htmlspecialchars($o['num_cotizacion'] ?: '—') ?></td>
          <td><?= fmtFecha((string)($o['fecha_cotizacion'] ?? '')) ?></td>
          <td><?= htmlspecialchars($o['condiciones'] ?: '—') ?></td>
          <td><?= htmlspecialchars($o['tiempo_entrega'] ?: '—') ?></td>
          <td class="r"<?= $dif == 0 ? ' style="font-weight:700"' : '' ?>><?= lps((float)$o['total']) ?></td>
          <td class="r" style="color:<?= $dif == 0 ? '#15803d' : '#64748b' ?>"><?= $dif == 0 ? 'Menor oferta' : '+ ' . lps($dif) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$ofertas): ?><tr><td colspan="8" class="c" style="color:#64748b;padding:14px">No hay ofertas registradas.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="amount-block">
    <div style="flex:1">
      <div class="amount-label">Proveedor seleccionado</div>
      <?php if ($sel): ?>
      <div style="font-size:13pt;font-weight:800;color:#0D3F6A;margin-top:2px"><?= htmlspecialchars($sel['prov_nombre']) ?></div>
      <?php if ((float)$sel['total'] > $menor): ?>
      <div style="font-size:8pt;color:#b45309;margin-top:3px">No es la menor oferta: <?= lps((float)$sel['total'] - $menor) ?> sobre la más baja.</div>
      <?php endif; ?>
      <?php else: ?>
      <div style="font-size:10pt;color:#64748b;margin-top:2px">Pendiente de selección</div>
      <?php endif; ?>
    </div>
    <?php if ($sel): ?><div class="amount-value"><?= lps((float)$sel['total']) ?></div><?php endif; ?>
  </div>

  <?php if ($doc['justificacion']): ?>
  <div class="meta-section">
    <div class="meta-grid">
      <div class="meta-item meta-full">
        <div class="meta-label">Justificación de la selección</div>
        <div class="meta-value"><?= nl2br(htmlspecialchars($doc['justificacion'])) ?></div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?= sigBlock($fCO, count($fCO) === 2 ? 'cols-2' : 'cols-3') ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

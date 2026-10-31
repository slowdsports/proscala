<?php
// ── Resumen de Cotizaciones (cuadro comparativo) ────────────────
// Plantilla de impresión (print.php): la solicitud de compra de referencia, el cuadro artículo ×
// proveedor con el precio unitario y el total de cada línea (el menor de cada artículo resaltado),
// los totales, la recomendación (config/cotizaciones.php: proveedor único y compra por artículo),
// el proveedor seleccionado con su justificación y las firmas. Las cotizaciones registradas con un
// monto global (sin precios por artículo) se comparan por ese monto.
// Firman Elaborado / Revisado / Autorizado por, del flujo de aprobación (config/aprobaciones.php).
require_once dirname(__DIR__, 2) . '/config/cotizaciones.php';

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
$cot = cargarCotizacion($pdo, $id);
$items = $cot['items'];
$ofertas = $cot['ofertas'];
$an = analizarCotizacion($items, $ofertas);
$title  = 'RESUMEN DE COTIZACIONES';
$docNum = $doc['numero'];

$porId = array_column($ofertas, null, 'id');
$nombreOf = fn($oid) => $porId[$oid]['prov_nombre'] ?? '—';
$sel = null;
foreach ($ofertas as $o) if ((int)$o['proveedor_id'] === (int)$doc['proveedor_seleccionado_id']) $sel = $o;
$totalSel = $sel ? $an['ofertas'][(int)$sel['id']]['total'] : 0;
$recoId = $an['unico']['oferta_id'] ?? null;
$cant = fn($q) => rtrim(rtrim(number_format((float)$q, 2), '0'), '.');
$angosto = count($ofertas) >= 4;   // con muchos proveedores, letra más chica para que quepa

// Firmas del flujo: Elaborado / Revisado / Autorizado por (con las personas reales de cada paso).
// "Elaborado por" sin creador registrado: quien figura en el resumen; una cotización aprobada antes
// del flujo (botón "Aprobar") conserva a quien la aprobó como "Autorizado por".
$fCO = firmasFlujo($pdo, loadFirmantes($pdo, 'co'), 'compras_cotizaciones.php', $id);
if (trim((string)($fCO[0]['emp_nombre'] ?? '')) === '' && $doc['elab_nombre']) $fCO[0]['emp_nombre'] = $doc['elab_nombre'];
if (trim((string)($fCO[2]['emp_nombre'] ?? '')) === '' && $doc['estado'] === 'aprobada' && $doc['aprobador_nombre']) $fCO[2]['emp_nombre'] = $doc['aprobador_nombre'];

require __DIR__ . '/_inicio.php';
?>
<style>
  table.cmp { width: 100%; border-collapse: collapse; font-size: <?= $angosto ? '7pt' : '8pt' ?>; }
  table.cmp th { background: #0D3F6A; color: #fff; padding: 6px 6px; font-weight: 600; font-size: <?= $angosto ? '6.5pt' : '7pt' ?>; text-align: left; vertical-align: bottom; }
  table.cmp th.r, table.cmp td.r { text-align: right; }
  table.cmp td { padding: 5px 6px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
  table.cmp td.p { text-align: right; font-family: 'Courier New', monospace; white-space: nowrap; }
  table.cmp td.p .l { display: block; color: #64748b; font-size: 6.5pt; }
  table.cmp td.min { background: #dcfce7; color: #15803d; font-weight: 700; }
  table.cmp td.na { color: #94a3b8; text-align: center; }
  table.cmp tfoot td { font-weight: 700; color: #0D3F6A; border-top: 2px solid #1B6BA8; border-bottom: none; }
  table.cmp tfoot tr.sub td { font-weight: 400; color: #64748b; border-top: none; font-size: 6.8pt; }
  table.cmp th .sel { color: #86efac; }
  .reco { margin: 12px 28px 0; display: grid; grid-template-columns: <?= $an['por_articulo'] ? '1fr 1.3fr' : '1fr' ?>; gap: 12px; }
  .reco > div { border: 1px solid #dbeafe; border-radius: 8px; padding: 10px 12px; background: #f8fbff; }
  .reco .t { font-size: 6.8pt; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #1B6BA8; }
  .reco .n { font-size: 10.5pt; font-weight: 800; color: #0D3F6A; margin-top: 2px; }
  .reco .m { font-family: 'Courier New', monospace; font-size: 12pt; font-weight: 800; color: #0D3F6A; }
  .reco .x { font-size: 7pt; color: #64748b; margin-top: 3px; }
  .reco table { width: 100%; border-collapse: collapse; font-size: 7.5pt; margin-top: 5px; }
  .reco table td { padding: 3px 0; border-bottom: 1px dashed #dbeafe; vertical-align: top; }
  .ahorro { display: inline-block; font-size: 7pt; font-weight: 700; color: #15803d; background: #dcfce7; border-radius: 10px; padding: 1px 7px; margin-left: 4px; font-family: inherit; }
</style>
<?php require __DIR__ . '/_pagina_inicio.php'; ?>

  <div class="ref-chain">
    <span class="ref-pill">📋 <?= htmlspecialchars($doc['sc_numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">📑 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item"><div class="meta-label">Fecha</div><div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Solicitud de Compra</div><div class="meta-value mono"><?= htmlspecialchars($doc['sc_numero']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Solicitante</div><div class="meta-value"><?= htmlspecialchars($doc['sol_nombre'] ?? '—') ?></div></div>
      <div class="meta-item"><div class="meta-label">Proyecto</div><div class="meta-value"><?= htmlspecialchars($doc['proy_nombre'] ?? 'General') ?></div></div>
      <div class="meta-item meta-full"><div class="meta-label">Descripción de la compra</div><div class="meta-value"><?= htmlspecialchars($doc['sc_desc']) ?></div></div>
    </div>
  </div>

  <div class="section-head">Cuadro Comparativo<?= $an['con_detalle'] ? ' · ' . count($items) . ' artículo(s)' : '' ?> · <?= count($ofertas) ?> proveedor<?= count($ofertas) === 1 ? '' : 'es' ?></div>
  <div class="items-wrap">
    <?php if ($an['con_detalle']): ?>
    <table class="cmp">
      <thead>
        <tr>
          <th style="width:2em">#</th><th>Artículo</th><th class="r" style="width:3.5em">Cant.</th>
          <?php foreach ($ofertas as $o): ?>
          <th class="r"><?= htmlspecialchars($o['prov_nombre']) ?><?= $sel && (int)$o['id'] === (int)$sel['id'] ? ' <span class="sel">✔</span>' : '' ?>
            <?php if ($o['num_cotizacion']): ?><div style="font-weight:400;opacity:.8">Cot. <?= htmlspecialchars($o['num_cotizacion']) ?></div><?php endif; ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $it): $iid = (int)$it['id']; $min = $an['minimos'][$iid] ?? null; ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($it['descripcion']) ?><?= $it['unidad'] ? ' <span style="color:#64748b">(' . htmlspecialchars($it['unidad']) . ')</span>' : '' ?></td>
          <td class="r"><?= $cant($it['cantidad']) ?></td>
          <?php foreach ($ofertas as $o): $p = $o['precios'][$iid] ?? null; ?>
            <?php if ($p === null): ?><td class="na" title="No lo cotizó">—</td>
            <?php else: ?><td class="p<?= $min && in_array((int)$o['id'], $min['ofertas'], true) ? ' min' : '' ?>"><?= lps($p) ?><span class="l"><?= lps($p * (float)$it['cantidad']) ?></span></td><?php endif; ?>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" style="text-align:right">TOTAL OFERTADO</td>
          <?php foreach ($ofertas as $o): ?><td class="p"><?= lps($an['ofertas'][(int)$o['id']]['total']) ?></td><?php endforeach; ?></tr>
        <tr class="sub"><td colspan="3" style="text-align:right">Artículos cotizados</td>
          <?php foreach ($ofertas as $o): $c = $an['ofertas'][(int)$o['id']]['cubre']; ?><td class="r"<?= $c < count($items) ? ' style="color:#b45309"' : '' ?>><?= $c ?> de <?= count($items) ?></td><?php endforeach; ?></tr>
        <tr class="sub"><td colspan="3" style="text-align:right">Condiciones de pago</td>
          <?php foreach ($ofertas as $o): ?><td class="r"><?= htmlspecialchars($o['condiciones'] ?: '—') ?></td><?php endforeach; ?></tr>
        <tr class="sub"><td colspan="3" style="text-align:right">Tiempo de entrega</td>
          <?php foreach ($ofertas as $o): ?><td class="r"><?= htmlspecialchars($o['tiempo_entrega'] ?: '—') ?></td><?php endforeach; ?></tr>
      </tfoot>
    </table>
    <div style="padding:4px 28px 0;font-size:6.8pt;color:#64748b">Precio unitario y, debajo, total de la línea. En verde, el menor precio de cada artículo. «—»: no lo cotizó.</div>
    <?php else: ?>
    <table class="cmp">
      <thead><tr><th style="width:2em">#</th><th>Proveedor</th><th>No. Cotización</th><th>Fecha</th><th>Condiciones de Pago</th><th>T. Entrega</th><th class="r">Total Ofertado</th></tr></thead>
      <tbody>
        <?php $i = 1; foreach ($ofertas as $o): $t = $an['ofertas'][(int)$o['id']]['total']; ?>
        <tr<?= $sel && (int)$o['id'] === (int)$sel['id'] ? ' style="background:#ecfdf5"' : '' ?>>
          <td><?= $i++ ?></td>
          <td><strong><?= htmlspecialchars($o['prov_nombre']) ?></strong><?= $sel && (int)$o['id'] === (int)$sel['id'] ? ' <span style="color:#15803d;font-weight:700">✔</span>' : '' ?></td>
          <td><?= htmlspecialchars($o['num_cotizacion'] ?: '—') ?></td>
          <td><?= fmtFecha((string)($o['fecha_cotizacion'] ?? '')) ?></td>
          <td><?= htmlspecialchars($o['condiciones'] ?: '—') ?></td>
          <td><?= htmlspecialchars($o['tiempo_entrega'] ?: '—') ?></td>
          <td class="p<?= (int)$o['id'] === $recoId ? ' min' : '' ?>"><?= lps($t) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$ofertas): ?><tr><td colspan="7" class="na" style="padding:14px">No hay ofertas registradas.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div style="padding:4px 28px 0;font-size:6.8pt;color:#64748b">Cotización registrada con montos globales (sin precio por artículo).</div>
    <?php endif; ?>
  </div>

  <?php if ($an['unico']): $u = $an['unico']; $pa = $an['por_articulo']; ?>
  <div class="section-head" style="margin-top:12px">Recomendación del sistema</div>
  <div class="reco">
    <div>
      <div class="t">Un solo proveedor</div>
      <div class="n"><?= htmlspecialchars($nombreOf($u['oferta_id'])) ?></div>
      <div class="m"><?= lps($u['total']) ?></div>
      <div class="x"><?= $u['completo'] ? ($an['con_detalle'] ? 'La oferta más baja entre los que cotizaron todos los artículos.' : 'La oferta más baja.')
                     : "Ningún proveedor cotizó todo: es el que más cubre (le faltan {$u['faltan']} artículo(s))." ?></div>
    </div>
    <?php if ($pa): ?>
    <div>
      <div class="t">Compra por artículo (mejor precio de cada uno)</div>
      <div class="m"><?= lps($pa['total']) ?><?php if ($an['ahorro'] > 0): ?><span class="ahorro">ahorra <?= lps($an['ahorro']) ?></span><?php endif; ?></div>
      <table>
        <?php foreach ($pa['grupos'] as $oid => $g): ?>
        <tr><td><strong><?= htmlspecialchars($nombreOf($oid)) ?></strong> — <?= count($g['items']) ?> artículo(s)</td>
            <td style="text-align:right;font-family:'Courier New',monospace;white-space:nowrap"><?= lps($g['total']) ?></td></tr>
        <?php endforeach; ?>
      </table>
      <div class="x"><?= count($pa['grupos']) > 1 ? 'Requiere ' . count($pa['grupos']) . ' órdenes de compra, una por proveedor.' : 'Coincide con un solo proveedor.' ?>
        <?= $pa['sin_oferta'] ? ' ' . count($pa['sin_oferta']) . ' artículo(s) sin ninguna oferta.' : '' ?></div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="amount-block">
    <div style="flex:1">
      <div class="amount-label">Proveedor seleccionado</div>
      <?php if ($sel): ?>
      <div style="font-size:13pt;font-weight:800;color:#0D3F6A;margin-top:2px"><?= htmlspecialchars($sel['prov_nombre']) ?></div>
      <?php if ($recoId !== null && (int)$sel['id'] !== (int)$recoId): ?>
      <div style="font-size:8pt;color:#b45309;margin-top:3px">No es el proveedor único recomendado<?= $totalSel > $an['unico']['total'] ? ' (' . lps($totalSel - $an['unico']['total']) . ' más)' : '' ?>: ver la justificación.</div>
      <?php endif; ?>
      <?php else: ?>
      <div style="font-size:10pt;color:#64748b;margin-top:2px">Pendiente de selección</div>
      <?php endif; ?>
    </div>
    <?php if ($sel): ?><div class="amount-value"><?= lps($totalSel) ?></div><?php endif; ?>
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

  <?= sigBlock($fCO) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

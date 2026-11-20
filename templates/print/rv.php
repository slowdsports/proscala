<?php
// ── Formulario de reporte de viaje ──────────────────────────────
// Plantilla de impresión (print.php): el reporte que el empleado escribe en la liquidación de un
// anticipo de viaje (solicitud_gastos.reporte_*) con el resumen del viaje y de la liquidación, y en
// las hojas siguientes las fotos de la sección FOTOS_VIAJE (config/adjuntos.php), seis por hoja.

$s = $pdo->prepare("SELECT sg.*, " . SQL_BENEFICIARIO_GASTO . " AS emp_nombre,
    IF(sg.proveedor_id IS NULL, e.cargo, 'Consultor') AS emp_cargo, p.codigo AS proy_nombre,
    gv.numero AS gv_numero, gv.tipo AS gv_tipo, gv.destino AS gv_destino, gv.proposito AS gv_proposito,
    gv.fecha_salida AS gv_fecha_salida, gv.fecha_regreso AS gv_fecha_regreso, gv.viaticos_anticipados
    FROM solicitud_gastos sg
    " . joinBeneficiarioGasto('sg') . "
    LEFT JOIN proyectos p ON sg.proyecto_id = p.id
    JOIN gastos_viaje gv ON sg.viaje_id = gv.id
    WHERE sg.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc || $doc['gv_tipo'] !== 'viaje') die('El reporte de viaje es de la liquidación de un anticipo de viaje.');

$title  = 'FORMULARIO DE REPORTE DE VIAJE';
$docNum = $doc['numero'];
$dias   = diasIncluidos($doc['gv_fecha_salida'], $doc['gv_fecha_regreso']);
$dif    = round((float)$doc['monto_total'] - (float)$doc['viaticos_anticipados'], 2);
$fotos  = fotosParaImprimir($pdo, 'gastos_solicitud.php', $id, FOTOS_VIAJE);
$hojasFotos = array_chunk($fotos, 6);

// Firmas: las de la liquidación (quien la elaboró, revisó y autorizó)
$fRV = loadFirmantes($pdo, 'sg');
if (!empty($doc['firmante1_id'])) $fRV = firmante1Documento($pdo, $fRV, $doc['firmante1_id']);
elseif ($doc['proveedor_id']) $fRV[0] = ['posicion' => 1, 'etiqueta' => $fRV[0]['etiqueta'] ?? 'Elaborado por', 'emp_nombre' => $doc['emp_nombre']];
$fRV = firmasFlujo($pdo, $fRV, 'gastos_solicitud.php', $id);
$fRV[0]['etiqueta'] = 'Elaborado por';

require __DIR__ . '/_inicio.php';
if ($hojasFotos) echo '<div id="all-pages">';
require __DIR__ . '/_pagina_inicio.php';

/** Bloque de texto del reporte; vacío, líneas para completarlo a mano. */
$bloque = function (string $titulo, ?string $texto): string {
    $html = '<div class="section-head" style="margin-top:8px">' . htmlspecialchars($titulo) . '</div>'
          . '<div style="padding:8px 28px 4px;font-size:9pt;line-height:1.6;color:#1a1a2e;text-align:justify">';
    if (trim((string)$texto) !== '') return $html . nl2br(htmlspecialchars($texto)) . '</div>';
    return $html . str_repeat('<div style="border-bottom:1px solid #cbd5e1;height:22px"></div>', 3) . '</div>';
};
?>

  <div class="ref-chain">
    <span class="ref-pill">✈ Anticipo <?= htmlspecialchars($doc['gv_numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill">📄 Liquidación <?= htmlspecialchars($doc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">🧭 Reporte de viaje</span>
  </div>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label"><?= $doc['proveedor_id'] ? 'Proveedor / Consultor' : 'Empleado' ?></div>
        <div class="meta-value" style="font-weight:700"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_cargo'] ?: '—') ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Destino</div>
        <div class="meta-value"><?= htmlspecialchars($doc['gv_destino']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Salida / regreso</div>
        <div class="meta-value"><?= fmtFecha($doc['gv_fecha_salida']) ?> — <?= fmtFecha($doc['gv_fecha_regreso']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Duración</div>
        <div class="meta-value"><?= $dias ?> día<?= $dias === 1 ? '' : 's' ?></div>
      </div>
      <?php if ($doc['gv_proposito']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Propósito del viaje</div>
        <div class="meta-value"><?= nl2br(htmlspecialchars($doc['gv_proposito'])) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?= $bloque('Actividades realizadas', $doc['reporte_actividades']) ?>
  <?= $bloque('Resultados obtenidos', $doc['reporte_resultados']) ?>
  <?= $bloque('Observaciones y recomendaciones', $doc['reporte_observaciones']) ?>

  <div class="section-head" style="margin-top:8px">Resumen de la liquidación</div>
  <div style="padding:8px 28px 0">
    <table class="items">
      <tbody>
        <tr><td>Anticipo recibido (<?= htmlspecialchars($doc['gv_numero']) ?>)</td><td class="r"><?= lps((float)$doc['viaticos_anticipados']) ?></td></tr>
        <tr><td>Gastos liquidados (<?= htmlspecialchars($doc['numero']) ?>)</td><td class="r"><?= lps((float)$doc['monto_total']) ?></td></tr>
      </tbody>
      <tfoot>
        <tr><td style="text-align:right;padding-right:12px"><?= $dif > 0 ? 'Diferencia a pagar al empleado' : ($dif < 0 ? 'Saldo a reintegrar' : 'Sin diferencia') ?></td>
            <td class="r" style="color:<?= $dif > 0 ? '#dc2626' : '#16a34a' ?>"><?= lps(abs($dif)) ?></td></tr>
      </tfoot>
    </table>
  </div>

  <div style="padding:8px 28px 0;font-size:8pt;color:#64748b">
    <?= $fotos ? 'Registro fotográfico: ' . count($fotos) . ' foto' . (count($fotos) === 1 ? '' : 's') . ' en la' . (count($hojasFotos) === 1 ? ' hoja siguiente.' : 's hojas siguientes.')
               : 'No se adjuntaron fotos del viaje.' ?>
  </div>

  <?= sigBlock($fRV) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>

<?php foreach ($hojasFotos as $n => $hoja):
  $title = 'REGISTRO FOTOGRÁFICO DEL VIAJE';
  require __DIR__ . '/_pagina_inicio.php'; ?>
  <div class="section-head">Fotos <?= $n * 6 + 1 ?>–<?= $n * 6 + count($hoja) ?> de <?= count($fotos) ?> · <?= htmlspecialchars($doc['gv_destino']) ?></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:12px 28px 0">
    <?php foreach ($hoja as $i => $foto): ?>
    <figure style="margin:0;border:1px solid #e2e8f0;border-radius:6px;padding:6px;text-align:center;break-inside:avoid">
      <img src="<?= $foto['src'] ?>" alt="" style="width:100%;height:68mm;object-fit:contain;background:#f8fafc;border-radius:4px">
      <figcaption style="font-size:7pt;color:#64748b;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
        <?= $n * 6 + $i + 1 ?>. <?= htmlspecialchars(pathinfo($foto['nombre'], PATHINFO_FILENAME)) ?></figcaption>
    </figure>
    <?php endforeach; ?>
  </div>
<?php require __DIR__ . '/_pagina_fin.php'; endforeach; ?>

<?php if ($hojasFotos): ?></div><!-- #all-pages reporte + fotos --><?php endif; ?>
<?php require __DIR__ . '/_fin.php';

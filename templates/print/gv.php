<?php
// ── Gasto de Viaje ──────────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT gv.*, " . SQL_BENEFICIARIO_GASTO . " AS emp_nombre, p.codigo AS proy_nombre,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM gastos_viaje gv " . joinBeneficiarioGasto('gv') . "
    LEFT JOIN proyectos p ON gv.proyecto_id = p.id
    LEFT JOIN empleados a ON gv.aprobador_id = a.id
    WHERE gv.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Gasto de viaje no encontrado.');
$s2 = $pdo->prepare("SELECT d.*, cc.codigo AS cuenta_codigo, cc.nombre AS cuenta_nombre
    FROM gastos_viaje_detalle d
    LEFT JOIN cuentas_contables cc ON d.cuenta_id = cc.id
    WHERE d.viaje_id = ? ORDER BY d.id");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$title  = $doc['tipo'] === 'compra' ? 'SOLICITUD DE ANTICIPO PARA COMPRA' : 'SOLICITUD DE ANTICIPO DE VIÁTICOS';
$docNum = $doc['numero'];

// Firmas
$fGV = loadFirmantes($pdo, 'gv');
if (!empty($doc['firmante1_id'])) $fGV = firmante1Documento($pdo, $fGV, $doc['firmante1_id']);
elseif ($doc['proveedor_id']) $fGV[0] = ['posicion' => 1, 'etiqueta' => $fGV[0]['etiqueta'] ?? 'Solicitado por', 'emp_nombre' => $doc['emp_nombre']];   // lo pidió el consultor
$fGV = firmasFlujo($pdo, $fGV, 'gastos_viajes.php', $id);

// Si requiere vehículo, segunda hoja con su solicitud (mismas firmas del flujo del anticipo)
$gvVehiculo = !empty($doc['requiere_vehiculo']);

require __DIR__ . '/_inicio.php';
if ($gvVehiculo) echo '<div id="all-pages">';
require __DIR__ . '/_pagina_inicio.php';
?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label"><?= $doc['proveedor_id'] ? 'Proveedor / Consultor' : 'Empleado' ?></div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Concepto / Insumo a Comprar' : 'Destino' ?></div>
        <div class="meta-value"><?= htmlspecialchars($doc['destino']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Fecha del Anticipo' : 'Fecha de Salida' ?></div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_salida']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Fecha Límite de Liquidación' : 'Fecha de Regreso' ?></div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_regreso']) ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item">
        <div class="meta-label">Monto del Anticipo</div>
        <div class="meta-value mono"><?= lps($doc['viaticos_anticipados']) ?></div>
      </div>
      <?php if ($doc['tipo'] !== 'compra' && !empty($doc['monto_alimentacion']) && $doc['monto_alimentacion'] > 0): ?>
      <div class="meta-item">
        <div class="meta-label">Alimentación</div>
        <div class="meta-value mono"><?= lps($doc['monto_alimentacion']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['proposito']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Justificación de la Compra' : 'Propósito del Viaje' ?></div>
        <div class="meta-value"><?= htmlspecialchars($doc['proposito']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Desglose del Anticipo Solicitado</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Descripción del Gasto</th>
          <th>Cuenta Contable</th>
          <th class="r">Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?php
            $ctaLabel = $d['cuenta_nombre']
                ? trim(($d['cuenta_codigo'] ?? '') . ' ' . $d['cuenta_nombre'])
                : '—';
            echo htmlspecialchars($ctaLabel);
          ?></td>
          <td class="r"><?= lps($d['monto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="text-align:right;padding-right:12px;font-weight:700">Total Anticipo Solicitado</td>
          <td class="r" style="font-weight:700;color:#16a34a"><?= lps($doc['viaticos_anticipados']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fGV) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>

<?php if ($gvVehiculo):
  // ══ SOLICITUD DE VEHÍCULO ══
  $title = 'SOLICITUD DE VEHÍCULO';
  require __DIR__ . '/_pagina_inicio.php'; ?>

  <div class="ref-chain">
    <span class="ref-pill"><?= $doc['tipo'] === 'compra' ? '📦' : '✈' ?> Anticipo <?= htmlspecialchars($doc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">🚗 Solicitud de vehículo</span>
  </div>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Solicitante</div>
        <div class="meta-value" style="font-weight:700"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item">
        <div class="meta-label">Fecha de la solicitud</div>
        <div class="meta-value"><?= fmtFecha(substr((string)$doc['created_at'], 0, 10)) ?></div>
      </div>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Concepto del anticipo' : 'Destino' ?></div>
        <div class="meta-value"><?= htmlspecialchars($doc['destino']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Fecha del anticipo' : 'Salida / regreso' ?></div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_salida']) ?><?= $doc['fecha_regreso'] !== $doc['fecha_salida'] ? ' — ' . fmtFecha($doc['fecha_regreso']) : '' ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Uso del vehículo</div>
  <div class="amount-block" style="justify-content:space-between">
    <div>
      <div class="amount-label">Desde</div>
      <div class="amount-value" style="font-size:15pt"><?= fmtFecha($doc['vehiculo_desde'] ?? '') ?></div>
    </div>
    <div>
      <div class="amount-label">Hasta</div>
      <div class="amount-value" style="font-size:15pt"><?= fmtFecha($doc['vehiculo_hasta'] ?? '') ?></div>
    </div>
    <div>
      <div class="amount-label">Días</div>
      <div class="amount-value" style="font-size:15pt"><?= diasIncluidos($doc['vehiculo_desde'], $doc['vehiculo_hasta']) ?></div>
    </div>
  </div>

  <div class="meta-section" style="background:#fff;border-bottom:none">
    <div class="meta-label">Descripción (para qué se necesita el vehículo)</div>
    <div class="meta-value" style="line-height:1.6;margin-top:4px"><?= nl2br(htmlspecialchars($doc['vehiculo_descripcion'] ?? '')) ?></div>
    <?php if ($doc['proposito']): ?>
    <div class="meta-label" style="margin-top:12px"><?= $doc['tipo'] === 'compra' ? 'Justificación del anticipo' : 'Propósito del viaje' ?></div>
    <div class="meta-value" style="line-height:1.6;margin-top:4px"><?= nl2br(htmlspecialchars($doc['proposito'])) ?></div>
    <?php endif; ?>
  </div>

  <div style="margin:6px 28px 0;font-size:8pt;color:#64748b;line-height:1.6">
    El vehículo se entrega con el Acta de recepción de vehículo una vez revisada y autorizada esta solicitud. El solicitante
    se compromete a usarlo solo para la comisión indicada y a devolverlo en las mismas condiciones en que lo recibió.
  </div>

  <?= sigBlock($fGV) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
</div><!-- #all-pages gv + vehículo -->
<?php endif; ?>
<?php require __DIR__ . '/_fin.php';

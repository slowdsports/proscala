<?php
// ── Solicitud de Gastos / Reembolsos ────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT sg.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    e.identidad AS emp_identidad, e.cargo AS emp_cargo,
    p.codigo AS proy_nombre,
    gv.numero AS gv_numero, gv.id AS gv_id, gv.viaticos_anticipados, gv.tipo AS gv_tipo,
    gv.monto_alimentacion, gv.fecha_salida AS gv_fecha_salida,
    gv.fecha_regreso AS gv_fecha_regreso, gv.destino AS gv_destino,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM solicitud_gastos sg
    JOIN empleados e ON sg.empleado_id = e.id
    LEFT JOIN proyectos p ON sg.proyecto_id = p.id
    LEFT JOIN gastos_viaje gv ON sg.viaje_id = gv.id
    LEFT JOIN empleados a ON sg.aprobador_id = a.id
    WHERE sg.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Solicitud de gastos no encontrada.');
$s2 = $pdo->prepare("SELECT d.*, cc.codigo AS cuenta_codigo, cc.nombre AS cuenta_nombre FROM solicitud_gastos_detalle d
    LEFT JOIN cuentas_contables cc ON d.cuenta_id = cc.id WHERE d.solicitud_id = ? ORDER BY d.fecha, d.id");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$title  = $doc['viaje_id'] ? 'LIQUIDACIÓN DE GASTOS' : 'SOLICITUD DE GASTOS';   // ligada a un anticipo
$docNum = $doc['numero'];

// Firmas
$fSG = loadFirmantes($pdo, 'sg');
if (!empty($doc['firmante1_id'])) $fSG = firmante1Documento($pdo, $fSG, $doc['firmante1_id']);
$fSG = firmasFlujo($pdo, $fSG, 'gastos_solicitud.php', $id);

// Liquidación con viáticos de alimentación: segunda página con su voucher
$sgHasAlim = ($doc['monto_alimentacion'] ?? 0) > 0;

require __DIR__ . '/_inicio.php';
if ($sgHasAlim) echo '<div id="all-pages">';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php if ($doc['gv_numero']): ?>
  <div class="ref-chain">
    <span class="ref-pill"><?= ($doc['gv_tipo'] ?? '') === 'compra' ? '📦' : '✈' ?> <?= htmlspecialchars($doc['gv_numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">📄 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>
  <?php endif; ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Empleado</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['gv_numero']): ?>
      <div class="meta-item">
        <div class="meta-label">Anticipo<?= ($doc['gv_tipo'] ?? '') === 'compra' ? ' de Compra' : ' de Viaje' ?></div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['gv_numero']) ?> — <?= lps($doc['viaticos_anticipados']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['descripcion']) ?></div>
      </div>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas / Justificación</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Gastos</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Fecha</th>
          <th>Descripción</th>
          <th>Cuenta Contable</th>
          <th class="r">Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= fmtFecha($d['fecha'] ?? '') ?></td>
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
          <td colspan="4" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">TOTAL GASTOS</td>
          <td class="r"><?= lps($doc['monto_total']) ?></td>
        </tr>
        <?php if ($doc['gv_numero']): ?>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Anticipo de Viaje Recibido</td>
          <td class="r"><?= lps($doc['viaticos_anticipados']) ?></td>
        </tr>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px">
            <?= $doc['monto_total'] <= $doc['viaticos_anticipados'] ? 'Saldo a Reintegrar' : 'Diferencia a Pagar' ?>
          </td>
          <td class="r" style="color:<?= $doc['monto_total'] <= $doc['viaticos_anticipados'] ? '#16a34a' : '#dc2626' ?>;font-weight:700">
            <?= lps(abs($doc['monto_total'] - $doc['viaticos_anticipados'])) ?>
          </td>
        </tr>
        <?php endif; ?>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fSG) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>

<?php if ($sgHasAlim): ?>
<!-- ══ VOUCHER DE ALIMENTACIÓN ══ -->
<div class="page">
  <div class="top-bar"></div>
  <div class="doc-header">
    <?php $orgContacto = false; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type">VOUCHER DE ALIMENTACIÓN</div>
      <div class="doc-number" style="font-size:13pt"><?= htmlspecialchars($doc['numero']) ?></div>
      <div style="font-size:8.5pt;color:#64748b;margin-top:3px">Viáticos de Viaje</div>
    </div>
  </div>

  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['emp_identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_cargo'] ?? '—') ?></div>
      </div>
      <?php if ($doc['gv_numero']): ?>
      <div class="meta-item">
        <div class="meta-label">Ref. Anticipo de Viaje</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['gv_numero']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['gv_destino']): ?>
      <div class="meta-item">
        <div class="meta-label">Destino</div>
        <div class="meta-value"><?= htmlspecialchars($doc['gv_destino']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['gv_fecha_salida']): ?>
      <div class="meta-item">
        <div class="meta-label">Fecha de Salida</div>
        <div class="meta-value"><?= fmtFecha($doc['gv_fecha_salida']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['gv_fecha_regreso']): ?>
      <div class="meta-item">
        <div class="meta-label">Fecha de Regreso</div>
        <div class="meta-value"><?= fmtFecha($doc['gv_fecha_regreso']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Alimentación</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:2px solid #e2e8f0;background:#f0f5fc">
          <td style="padding:8px 8px;font-weight:700;color:#0D3F6A">Concepto</td>
          <td style="padding:8px 8px;font-weight:700;color:#0D3F6A">Viáticos de Alimentación por comisión a <?= htmlspecialchars($doc['gv_destino'] ?? 'destino del viaje') ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label">Monto de Alimentación</div>
      <div class="amount-value"><?= lps($doc['monto_alimentacion']) ?></div>
    </div>
  </div>

  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($doc['emp_nombre'])) ?></strong><?php if ($doc['emp_identidad'] ?? ''): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($doc['emp_identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($doc['monto_alimentacion']) ?></strong>
      en concepto de <em>viáticos de alimentación</em> correspondientes a la comisión<?php if ($doc['gv_destino']): ?> a <strong><?= htmlspecialchars($doc['gv_destino']) ?></strong><?php endif; ?><?php if ($doc['gv_fecha_salida'] && $doc['gv_fecha_regreso']): ?>, del <?= fmtFecha($doc['gv_fecha_salida']) ?> al <?= fmtFecha($doc['gv_fecha_regreso']) ?><?php endif; ?>, en conformidad con lo indicado.
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
  <!-- Solo lo firma el empleado que recibió el viático (arriba): sin las firmas del flujo -->

  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">VOUCHER ALIMENTACIÓN — <?= htmlspecialchars($doc['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endif; /* sgHasAlim */ ?>

<?php if ($sgHasAlim): ?></div><!-- #all-pages sg+alim --><?php endif; ?>
<?php require __DIR__ . '/_fin.php';

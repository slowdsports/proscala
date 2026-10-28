<?php
// ── Caja Chica ──────────────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT cc.*,
    CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    e.cargo, e.identidad, e.departamento,
    u.nombre AS aprobador_nombre
    FROM caja_chica cc
    JOIN empleados e ON cc.empleado_id = e.id
    LEFT JOIN usuarios u ON cc.aprobador_id = u.id
    WHERE cc.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Caja chica no encontrada.');
$s2 = $pdo->prepare("SELECT d.*,
    COALESCE(CONCAT(cta.codigo,' — ',cta.nombre),'Sin cuenta') AS cuenta_nombre
    FROM caja_chica_detalle d
    LEFT JOIN cuentas_contables cta ON d.cuenta_id = cta.id
    WHERE d.caja_id = ? ORDER BY d.fecha, d.id");
$s2->execute([$id]); $detalle = $s2->fetchAll();
// Resumen por cuenta
$s3 = $pdo->prepare("
    SELECT COALESCE(CONCAT(cta.codigo,' — ',cta.nombre),'Sin cuenta') AS cuenta_nombre,
           SUM(d.monto) AS subtotal
    FROM caja_chica_detalle d
    LEFT JOIN cuentas_contables cta ON d.cuenta_id = cta.id
    WHERE d.caja_id = ?
    GROUP BY d.cuenta_id, cta.codigo, cta.nombre ORDER BY cta.codigo");
$s3->execute([$id]); $ccResumen = $s3->fetchAll();
// Firmante 1 = el empleado de la caja chica
$empFirmante = [
    'etiqueta'    => $doc['cargo'] ?? 'Responsable de Caja Chica',
    'emp_nombre'  => $doc['emp_nombre'],
];
$title  = 'CAJA CHICA';
$docNum = $doc['numero'];

// Firmas
$fCC = loadFirmantes($pdo, 'cc');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php
  $ccEstado = $doc['estado'] === 'liquidada' ? 'LIQUIDADA' : 'ABIERTA';
  $saldoAbs = abs($doc['saldo']);
  $esReemb  = $doc['saldo'] < 0;
  ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Responsable</div>
        <div class="meta-value" style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Estado</div>
        <div class="meta-value"><?= $ccEstado ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Apertura</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_apertura']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Cierre</div>
        <div class="meta-value"><?= $doc['fecha_cierre'] ? fmtFecha($doc['fecha_cierre']) : '—' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Anticipo</div>
        <div class="meta-value mono" style="font-weight:700;font-size:10pt"><?= lps($doc['anticipo']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Total Gastos</div>
        <div class="meta-value mono" style="font-weight:700;font-size:10pt;color:#d97706"><?= lps($doc['total_gastos']) ?></div>
      </div>
      <?php if ($doc['aprobador_nombre']): ?>
      <div class="meta-item" style="grid-column:1/-1">
        <div class="meta-label">Liquidado por</div>
        <div class="meta-value"><?= htmlspecialchars($doc['aprobador_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['notas']): ?>
      <div class="meta-item" style="grid-column:1/-1">
        <div class="meta-label">Notas</div>
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
          <th style="width:2em">#</th>
          <th>Fecha</th>
          <th>Cuenta Contable</th>
          <th>Descripción</th>
          <th class="r">Cant.</th>
          <th class="r">P. Unit.</th>
          <th class="r">Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td style="white-space:nowrap"><?= fmtFecha($d['fecha']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cuenta_nombre']) ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td class="r"><?= number_format($d['cantidad'],2) ?></td>
          <td class="r"><?= lps($d['precio_unitario']) ?></td>
          <td class="r" style="font-weight:600"><?= lps($d['monto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" style="text-align:right;padding-right:12px;font-weight:700">TOTAL GASTOS</td>
          <td class="r"><?= lps($doc['total_gastos']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="section-head">Resumen por Cuenta Contable</div>
  <div class="items-wrap" style="padding:0 28px">
    <table class="items" style="width:55%;min-width:300px;margin-left:auto">
      <thead>
        <tr>
          <th>Cuenta Contable</th>
          <th class="r">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ccResumen as $cr): ?>
        <tr>
          <td><?= htmlspecialchars($cr['cuenta_nombre']) ?></td>
          <td class="r"><?= lps($cr['subtotal']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td style="font-weight:700">TOTAL GASTOS</td>
          <td class="r"><?= lps($doc['total_gastos']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Cuadro de saldo -->
  <div style="display:flex;justify-content:flex-end;padding:10px 28px 0">
    <table style="border:1.5px solid #1B6BA8;border-radius:6px;overflow:hidden;min-width:280px;font-size:9pt">
      <tr style="background:#f0f5fc">
        <td style="padding:6px 14px;font-weight:600;color:#1B6BA8">Anticipo recibido</td>
        <td style="padding:6px 14px;font-family:'Courier New',monospace;text-align:right"><?= lps($doc['anticipo']) ?></td>
      </tr>
      <tr>
        <td style="padding:6px 14px;font-weight:600">Total de gastos</td>
        <td style="padding:6px 14px;font-family:'Courier New',monospace;text-align:right"><?= lps($doc['total_gastos']) ?></td>
      </tr>
      <tr style="background:#0D3F6A;color:#fff;font-weight:700">
        <td style="padding:7px 14px"><?= $esReemb ? 'Reembolso a recibir' : 'Saldo a devolver' ?></td>
        <td style="padding:7px 14px;font-family:'Courier New',monospace;text-align:right"><?= lps($saldoAbs) ?></td>
      </tr>
    </table>
  </div>

  <?php
  $ccSigRest = !empty($fCC) ? array_slice($fCC, 1, 2) : [
      ['etiqueta' => 'Administración / Contabilidad', 'emp_nombre' => ''],
      ['etiqueta' => 'Dirección Ejecutiva',            'emp_nombre' => ''],
  ];
  $ccSigs = [$empFirmante, ...$ccSigRest];
  echo sigBlock($ccSigs, 'cols-3', 'margin-top:28px');
  ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

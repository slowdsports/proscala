<?php
// ── Planilla de sueldos (quincenal o mensual) ──────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla no encontrada.');
$s2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre,
    e.cargo, e.banco, e.cuenta_banco
    FROM planilla_detalle pd
    JOIN empleados e ON pd.empleado_id = e.id
    WHERE pd.periodo_id = ? ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$mes    = $meses[$doc['mes'] - 1] ?? '';
$quin   = nombrePeriodoPlanilla($doc);
$title  = esPlanillaMensual($doc) ? 'PLANILLA MENSUAL' : 'PLANILLA QUINCENAL';
$docNum = ($doc['numero'] ? "{$doc['numero']} — " : '') . "{$mes} {$doc['anio']} — {$quin}";

// Firmas
$fPL = loadFirmantes($pdo, 'pl');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Período</div>
        <div class="meta-value"><?= $meses[$doc['mes'] - 1] . ' ' . $doc['anio'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= esPlanillaMensual($doc) ? 'Tipo' : 'Quincena' ?></div>
        <div class="meta-value"><?= esPlanillaMensual($doc) ? 'Mensual' : ($doc['quincena'] === 'primera' ? '1ª (1-15)' : '2ª (16-31)') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">No. Empleados</div>
        <div class="meta-value"><?= count($detalle) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Detalle por Empleado</div>
  <div class="items-wrap">
    <table class="items" style="font-size:7.5pt">
      <thead>
        <tr>
          <th style="width:2em">#</th>
          <th>Empleado</th>
          <th>Cargo</th>
          <th class="r">Q. Bruta</th>
          <th class="r">IHSS</th>
          <th class="r">RAP</th>
          <th class="r">ISR</th>
          <th class="r">Credimpulsa</th>
          <th class="r">Ded. Total</th>
          <th class="r">Neto</th>
          <th class="r">IHSS Pat.</th>
          <th class="r">RAP Pat.</th>
          <th>Obs.</th>
          <th>Banco / Cuenta</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $i=1;
        $totBruto=$totIhssE=$totRap=$totIsr=$totCredimpulsa=$totDed=$totNeto=$totIhssP=$totRapP=0;
        foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['empleado_nombre']) ?></td>
          <td style="font-size:7pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r"><?= lps($d['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($d['ihss_empleado']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($d['rap_empleado']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($d['isr']) ?></td>
          <td class="r" style="color:#dc2626"><?= $d['credimpulsa'] > 0 ? lps($d['credimpulsa']) : '<span style="color:#94a3b8">—</span>' ?></td>
          <td class="r" style="color:#dc2626;font-weight:600"><?= lps($d['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($d['sueldo_neto']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($d['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($d['rap_patronal']) ?></td>
          <td style="font-size:7pt;color:#374151"><?= htmlspecialchars($d['observacion'] ?? '') ?></td>
          <td style="font-size:7pt">
            <?= htmlspecialchars($d['banco'] ?? '—') ?>
            <?php if ($d['cuenta_banco']): ?>
            <br><span style="color:#64748b;font-size:6.5pt;font-family:'Courier New',monospace"><?= htmlspecialchars($d['cuenta_banco']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php
          $totBruto += $d['total_bruto']; $totIhssE += $d['ihss_empleado'];
          $totRap   += $d['rap_empleado']; $totIsr  += $d['isr'];
          $totCredimpulsa += $d['credimpulsa'];
          $totDed   += $d['total_deducciones']; $totNeto += $d['sueldo_neto'];
          $totIhssP += $d['ihss_patronal']; $totRapP += $d['rap_patronal'];
        endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="font-weight:700;color:#0D3F6A">TOTALES</td>
          <td class="r"><?= lps($totBruto) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($totIhssE) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($totRap) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($totIsr) ?></td>
          <td class="r" style="color:#dc2626"><?= $totCredimpulsa > 0 ? lps($totCredimpulsa) : '—' ?></td>
          <td class="r" style="color:#dc2626;font-weight:700"><?= lps($totDed) ?></td>
          <td class="r" style="color:#16a34a;font-weight:700"><?= lps($totNeto) ?></td>
          <td class="r" style="color:#b45309"><?= lps($totIhssP) ?></td>
          <td class="r" style="color:#b45309"><?= lps($totRapP) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fPL, 'cols-3', 'margin-top:20px') ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

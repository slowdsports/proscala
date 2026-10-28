<?php
// ── Liquidación ─────────────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT l.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    e.cargo, e.identidad, e.departamento, e.fecha_ingreso, e.rtn AS emp_rtn,
    CONCAT(u.nombre,' ',u.apellidos) AS aprobador_nombre
    FROM liquidaciones l
    JOIN empleados e ON l.empleado_id = e.id
    LEFT JOIN empleados u ON l.aprobador_id = u.id
    WHERE l.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Liquidación no encontrada.');
$title  = 'LIQUIDACIÓN DE PERSONAL';
$docNum = $doc['numero'];

// Firmas
$fLIQ = loadFirmantes($pdo, 'liq');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php
  $motivoLabels = [
      'renuncia'              => 'Renuncia voluntaria',
      'despido_injustificado' => 'Despido injustificado (Art. 116 / 120 CT)',
      'despido_justificado'   => 'Despido justificado',
      'vencimiento_contrato'  => 'Vencimiento de contrato',
      'mutuo_acuerdo'         => 'Mutuo acuerdo entre las partes',
      'fallecimiento'         => 'Fallecimiento del trabajador',
  ];
  $motLbl = $motivoLabels[$doc['motivo']] ?? $doc['motivo'];
  $aniosSrv = floor($doc['anios_servicio']);
  $mesesSrv = round(($doc['anios_servicio'] - $aniosSrv) * 12);
  $tiempoSrv = "$aniosSrv año" . ($aniosSrv != 1 ? 's' : '');
  if ($mesesSrv) $tiempoSrv .= ", $mesesSrv mes" . ($mesesSrv != 1 ? 'es' : '');
  ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Departamento</div>
        <div class="meta-value"><?= htmlspecialchars($doc['departamento'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Ingreso</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_ingreso']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Salida</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_salida']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Antigüedad</div>
        <div class="meta-value"><?= $tiempoSrv ?></div>
      </div>
      <div class="meta-item" style="grid-column:1/-1">
        <div class="meta-label">Motivo de Salida</div>
        <div class="meta-value"><?= htmlspecialchars($motLbl) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Conceptos de Liquidación</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th>Concepto</th>
          <th>Referencia Legal</th>
          <th class="r">Monto</th>
        </tr>
      </thead>
      <tbody>
        <tr style="background:#f0f5fc">
          <td>Sueldo Mensual</td>
          <td style="color:#64748b;font-size:8pt">Base de cálculo</td>
          <td class="r"><?= lps($doc['sueldo_mensual']) ?></td>
        </tr>
        <?php if ($doc['dias_pendientes'] > 0): ?>
        <tr>
          <td>Salarios Pendientes (<?= $doc['dias_pendientes'] ?> días)</td>
          <td style="color:#64748b;font-size:8pt">Días no pagados</td>
          <td class="r"><?= lps($doc['salarios_pendientes']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($doc['dias_vacaciones'] > 0): ?>
        <tr>
          <td>Vacaciones Pendientes (<?= $doc['dias_vacaciones'] ?> días)</td>
          <td style="color:#64748b;font-size:8pt">Art. 346 C.T.</td>
          <td class="r"><?= lps($doc['vacaciones_pendientes']) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td>Décimo Tercer Mes Proporcional</td>
          <td style="color:#64748b;font-size:8pt"><?= round($doc['meses_anio_actual'], 1) ?> meses / 12</td>
          <td class="r"><?= lps($doc['decimo_tercero_prop']) ?></td>
        </tr>
        <tr>
          <td>Décimo Cuarto Mes Proporcional</td>
          <td style="color:#64748b;font-size:8pt"><?= round($doc['meses_anio_actual'], 1) ?> meses / 12</td>
          <td class="r"><?= lps($doc['decimo_cuarto_prop']) ?></td>
        </tr>
        <?php if ($doc['preaviso'] > 0): ?>
        <tr>
          <td>Preaviso</td>
          <td style="color:#64748b;font-size:8pt">Art. 116 C.T.</td>
          <td class="r"><?= lps($doc['preaviso']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($doc['cesantia'] > 0): ?>
        <tr>
          <td>Auxilio de Cesantía</td>
          <td style="color:#64748b;font-size:8pt">Art. 120 C.T.</td>
          <td class="r"><?= lps($doc['cesantia']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($doc['otros_conceptos'] > 0): ?>
        <tr>
          <td>Otros: <?= htmlspecialchars($doc['otros_descripcion'] ?? '') ?></td>
          <td></td>
          <td class="r"><?= lps($doc['otros_conceptos']) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="text-align:right;padding-right:12px;font-weight:700">TOTAL A PAGAR AL TRABAJADOR</td>
          <td class="r"><?= lps($doc['total']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php if ($doc['notas']): ?>
  <p style="font-size:.82rem;margin:12px 28px 0;color:#555">
    <strong>Notas:</strong> <?= nl2br(htmlspecialchars($doc['notas'])) ?>
  </p>
  <?php endif; ?>

  <p style="font-size:.8rem;margin:18px 28px 0;text-align:center;color:#777">
    Habiendo recibido conforme el monto total indicado, el trabajador y la institución dan por concluida
    la relación laboral en los términos descritos en el presente documento.
  </p>

  <?php
  $liqSigDefault = [['etiqueta' => 'Director Ejecutivo', 'emp_nombre' => ''],
                    ['etiqueta' => 'Trabajador / Representante Legal', 'emp_nombre' => ''],
                    ['etiqueta' => 'Testigo', 'emp_nombre' => '']];
  $liqSigs = !empty($fLIQ) ? $fLIQ : $liqSigDefault;
  echo sigBlock($liqSigs, 'cols-3', 'margin-top:28px');
  ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

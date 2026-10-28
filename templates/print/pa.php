<?php
// ── Planilla de Asignación ──────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT pa.*, p.codigo AS proy_nombre FROM planilla_asignacion pa
    LEFT JOIN proyectos p ON pa.proyecto_id = p.id WHERE pa.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Planilla de asignación no encontrada.');
$s2 = $pdo->prepare("SELECT pad.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
    e.cargo, e.banco, e.cuenta_banco
    FROM planilla_asignacion_detalle pad JOIN empleados e ON pad.empleado_id = e.id
    WHERE pad.asignacion_id = ? ORDER BY e.apellidos, e.nombre");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$title  = 'PLANILLA DE ASIGNACIÓN';
$docNum = $doc['numero'];

// Firmas
$fPA = loadFirmantes($pdo, 'pa');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Período</div>
        <div class="meta-value"><?php
          $mesesPrint = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
          echo $mesesPrint[$doc['mes']-1] . ' ' . $doc['anio'];
        ?></div>
      </div>
      <?php if ($doc['descripcion']): ?>
      <div class="meta-item" style="grid-column:2/5">
        <div class="meta-label">Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['descripcion']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle por Empleado</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Empleado</th>
          <th>Cargo</th>
          <th>Concepto de Asignación</th>
          <th class="r">Monto</th>
          <th>Banco / Cuenta</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; $totPA = 0; foreach ($detalle as $d): $totPA += $d['monto']; ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['emp_nombre']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td><?= htmlspecialchars($d['concepto']) ?></td>
          <td class="r"><?= lps($d['monto']) ?></td>
          <td style="font-size:7.5pt"><?= htmlspecialchars($d['banco'] ?? '—') ?>
            <?php if ($d['cuenta_banco']): ?><br><span style="color:#64748b;font-size:6.5pt;font-family:'Courier New',monospace"><?= htmlspecialchars($d['cuenta_banco']) ?></span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">TOTAL ASIGNADO</td>
          <td class="r"><?= lps($totPA) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fPA) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

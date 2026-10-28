<?php
// ── Solicitud de Compra ─────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT sc.*, CONCAT(e.nombre,' ',e.apellidos) AS sol_nombre, p.codigo AS proy_nombre,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM solicitud_compra sc
    JOIN empleados e ON sc.solicitante_id = e.id
    LEFT JOIN proyectos p ON sc.proyecto_id = p.id
    LEFT JOIN empleados a ON sc.aprobador_id = a.id
    WHERE sc.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Solicitud no encontrada.');
$s2 = $pdo->prepare("SELECT * FROM solicitud_compra_detalle WHERE solicitud_id = ?");
$s2->execute([$id]); $detalle = $s2->fetchAll();
$title  = 'SOLICITUD DE COMPRA';
$docNum = $doc['numero'];

// Firmas
$fSC = firmasFlujo($pdo, loadFirmantes($pdo, 'sc'), 'compras_solicitud.php', $id);

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php if ($doc['sc_numero'] ?? false): ?>
  <div class="ref-chain">
    <span class="ref-pill ref-current">📋 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>
  <?php endif; ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Solicitante</div>
        <div class="meta-value"><?= htmlspecialchars($doc['sol_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Urgencia</div>
        <div class="meta-value"><?= str_replace('_',' ',ucfirst($doc['urgencia'])) ?></div>
      </div>
      <div class="meta-item meta-full">
        <div class="meta-label">Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['descripcion']) ?></div>
      </div>
      <?php if ($doc['justificacion']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Justificación</div>
        <div class="meta-value"><?= htmlspecialchars($doc['justificacion']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Artículos / Servicios Requeridos</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Descripción</th>
          <th>Unidad</th>
          <th class="r">Cantidad</th>
          <th class="r">Precio Est.</th>
          <th class="r">Total Est.</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; $tot=0; foreach ($detalle as $d):
          $sub = ($d['cantidad'] ?? 1) * ($d['precio_estimado'] ?? 0); $tot += $sub; ?>
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
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt;">PRESUPUESTO ESTIMADO TOTAL</td>
          <td class="r"><?= lps($tot) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fSC) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

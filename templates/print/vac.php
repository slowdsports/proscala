<?php
// ── Vacaciones ──────────────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT v.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo,
    e.identidad, e.fecha_ingreso,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM vacaciones v JOIN empleados e ON v.empleado_id=e.id
    LEFT JOIN empleados a ON v.aprobador_id=a.id WHERE v.id=?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Solicitud de vacaciones no encontrada.');
$title  = 'SOLICITUD DE VACACIONES';
$docNum = $doc['numero'];

// Firmas
$fVAC = firmasFlujo($pdo, loadFirmantes($pdo, 'vac'), 'vacaciones.php', $id);

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Empleado</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Ingreso</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_ingreso'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Tipo</div>
        <div class="meta-value"><?= ucfirst($doc['tipo']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Solicitud</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_solicitud']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Inicio</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_inicio']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Regreso</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_fin']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Días Solicitados</div>
        <div class="meta-value" style="font-size:14pt;font-weight:800;color:#0D3F6A"><?= $doc['dias'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Estado</div>
        <div class="meta-value"><span class="doc-estado est-<?= $doc['estado'] ?>"><?= ucfirst($doc['estado']) ?></span></div>
      </div>
      <?php if ($doc['aprobador_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Aprobado por</div>
        <div class="meta-value"><?= htmlspecialchars($doc['aprobador_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php
  $vacSigs = array_merge(
      [['etiqueta' => 'Solicitado por (Empleado)', 'emp_nombre' => $doc['emp_nombre']]],
      $fVAC
  );
  $vacCols = count($vacSigs) >= 3 ? 'cols-3' : 'cols-2';
  echo sigBlock($vacSigs, $vacCols);
  ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

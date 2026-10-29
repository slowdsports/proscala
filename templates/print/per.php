<?php
// ── Permiso ─────────────────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT p.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo,
    e.identidad,
    CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
    FROM permisos p JOIN empleados e ON p.empleado_id=e.id
    LEFT JOIN empleados a ON p.aprobador_id=a.id WHERE p.id=?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Permiso no encontrado.');
$TIPOS_PER = [
    'enfermedad'=>'Enfermedad / Incapacidad','personal'=>'Personal',
    'maternidad'=>'Maternidad','paternidad'=>'Paternidad',
    'luto'=>'Luto / Duelo','capacitacion'=>'Capacitación','otro'=>'Otro',
];
$title  = 'PERMISO — ' . strtoupper($TIPOS_PER[$doc['tipo']] ?? $doc['tipo']);
$docNum = $doc['numero'];

// Firmas: el empleado que solicita (en lugar de "Elaborado por"), Revisado por y Autorizado por
$fPER = firmasFlujo($pdo, loadFirmantes($pdo, 'per'), 'permisos.php', $id);
$fPER[0] = ['posicion' => 1, 'etiqueta' => 'Solicitado por (Empleado)', 'emp_nombre' => $doc['emp_nombre']];

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
        <div class="meta-label">Tipo de Permiso</div>
        <div class="meta-value"><?= htmlspecialchars($TIPOS_PER[$doc['tipo']] ?? ucfirst($doc['tipo'])) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Con Goce de Sueldo</div>
        <div class="meta-value"><?= $doc['con_goce'] ? 'Sí' : 'No' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Inicio</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_inicio']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Finalización</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_fin']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Días</div>
        <div class="meta-value" style="font-size:14pt;font-weight:800;color:#0D3F6A"><?= $doc['dias'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Estado</div>
        <div class="meta-value"><span class="doc-estado est-<?= $doc['estado'] ?>"><?= ucfirst($doc['estado']) ?></span></div>
      </div>
      <?php if ($doc['motivo']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Motivo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['motivo']) ?></div>
      </div>
      <?php endif; ?>
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

  <?= sigBlock($fPER) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

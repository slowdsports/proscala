<?php
// ── Acta de Reunión ─────────────────────────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT * FROM actas_reunion WHERE id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Acta no encontrada.');
$title  = 'ACTA DE REUNIÓN';
$docNum = $doc['numero'];

// Firmas
$fACTA = loadFirmantes($pdo, 'acta');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <?php if ($doc['hora_inicio']): ?>
      <div class="meta-item">
        <div class="meta-label">Hora</div>
        <div class="meta-value mono"><?= substr($doc['hora_inicio'],0,5) ?> – <?= substr($doc['hora_fin']??'??:??',0,5) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['lugar']): ?>
      <div class="meta-item" style="<?= !$doc['hora_inicio'] ? 'grid-column:2/4' : '' ?>">
        <div class="meta-label">Lugar</div>
        <div class="meta-value"><?= htmlspecialchars($doc['lugar']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['convocado_por']): ?>
      <div class="meta-item">
        <div class="meta-label">Convocado por</div>
        <div class="meta-value"><?= htmlspecialchars($doc['convocado_por']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Asunto</div>
        <div class="meta-value" style="font-weight:700;font-size:10pt"><?= htmlspecialchars($doc['asunto']) ?></div>
      </div>
      <?php if ($doc['proxima_reunion']): ?>
      <div class="meta-item">
        <div class="meta-label">Próxima Reunión</div>
        <div class="meta-value"><?= fmtFecha($doc['proxima_reunion']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php
  $actaSecciones = [
      'participantes' => 'Participantes',
      'agenda'        => 'Agenda',
      'desarrollo'    => 'Desarrollo',
      'acuerdos'      => 'Acuerdos',
  ];
  foreach ($actaSecciones as $campo => $etiqueta):
    if (empty($doc[$campo])) continue; ?>
  <div class="section-head"><?= $etiqueta ?></div>
  <div style="padding:10px 28px;font-size:8.5pt;line-height:1.8;white-space:pre-wrap"><?= htmlspecialchars($doc[$campo]) ?></div>
  <?php endforeach; ?>

  <?php
  $actaSigDefault = [
      ['etiqueta' => 'Elaboró', 'emp_nombre' => ''],
      ['etiqueta' => 'Revisó',  'emp_nombre' => ''],
      ['etiqueta' => 'Aprobó', 'emp_nombre' => ''],
  ];
  $actaSigs = !empty($fACTA) ? $fACTA : $actaSigDefault;
  echo sigBlock($actaSigs, 'cols-3', 'margin-top:28px');
  ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

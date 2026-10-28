<?php
// Panel "Creado → Revisado → Autorizado" de un proceso (config/aprobaciones.php).
// Uso: $flujoModulo = 'compras_solicitud.php'; $flujoId = 12; include __DIR__ . '/includes/flujo_aprobacion.php';
$flujo = estadoFlujo($pdo, $flujoModulo, (int)$flujoId);
if ($flujo):
    $flujoDef = PROCESOS[$flujoModulo];
    $flujoAcc = accionesFlujo($flujo, $flujoModulo);
    $flujoPaso = function (?array $p, string $titulo, string $icono, string $pendiente): string {
        $hecho = $p !== null;
        $quien = $hecho ? htmlspecialchars($p['nombre']) : "<span class=\"text-muted\">{$pendiente}</span>";
        // Paso hecho por delegación ("En nombre de …"); el comentario de un rechazo es su motivo, no se muestra aquí
        if ($hecho && $titulo !== 'Rechazado por' && str_starts_with((string)($p['comentario'] ?? ''), 'En nombre de ')) {
            $quien .= '<div class="text-muted" style="font-size:.72rem">' . htmlspecialchars($p['comentario']) . '</div>';
        }
        $cuando = $hecho ? '<div class="text-muted" style="font-size:.72rem">' . date('d/m/Y H:i', strtotime($p['fecha'])) . '</div>' : '';
        return "<div class=\"flujo-paso" . ($hecho ? ' hecho' : '') . "\">
            <div class=\"flujo-icono\"><i class=\"fas {$icono}\"></i></div>
            <div><div class=\"flujo-titulo\">{$titulo}</div><div class=\"flujo-quien\">{$quien}</div>{$cuando}</div>
        </div>";
    };
    $flujoUrl = htmlspecialchars($flujoModulo);
    $autorizarJs = $flujoDef['autorizado'] !== null
        ? "flujoAccion('{$flujoUrl}', {$flujoId}, 'cambiar_estado', '{$flujoDef['autorizado']}', '¿Autorizar este proceso?')"
        : "flujoAccion('{$flujoUrl}', {$flujoId}, 'autorizar', '', '¿Autorizar este proceso?')";
?>
<div class="card mb-3 no-print flujo-aprobacion">
  <div class="card-body py-2 d-flex flex-wrap align-items-center gap-3">
    <?= $flujoPaso($flujo['creado'], 'Creado por', 'fa-pen', 'Sin registro') ?>
    <i class="fas fa-chevron-right text-muted"></i>
    <?= $flujoPaso($flujo['revisado'], 'Revisado por', 'fa-magnifying-glass', 'Pendiente') ?>
    <i class="fas fa-chevron-right text-muted"></i>
    <?php if ($flujo['rechazado']): ?>
      <?= $flujoPaso($flujo['rechazado'], 'Rechazado por', 'fa-xmark', '') ?>
    <?php else: ?>
      <?= $flujoPaso($flujo['autorizado'], 'Autorizado por', 'fa-check', 'Pendiente') ?>
    <?php endif; ?>
    <div class="ms-auto d-flex gap-2 flex-wrap">
      <?php if ($flujoAcc['revisar'] === true): ?>
      <button class="btn btn-sm btn-primary" onclick="flujoAccion('<?= $flujoUrl ?>', <?= (int)$flujoId ?>, 'revisar', '', '¿Marcar este proceso como revisado?')"><i class="fas fa-magnifying-glass"></i> Revisar</button>
      <?php endif; ?>
      <?php if ($flujoAcc['autorizar'] === true): ?>
      <button class="btn btn-sm btn-success" onclick="<?= $autorizarJs ?>"><i class="fas fa-check"></i> Autorizar</button>
      <?php endif; ?>
      <?php if ($flujoAcc['rechazar'] === true): ?>
      <button class="btn btn-sm btn-danger" onclick="flujoRechazar('<?= $flujoUrl ?>', <?= (int)$flujoId ?>, '<?= $flujoDef['rechazado'] ?>')"><i class="fas fa-times"></i> Rechazar</button>
      <?php endif; ?>
      <?php
      // Por qué no puede actuar (solo si el proceso sigue pendiente y su puesto aprueba)
      $flujoMotivo = $flujoAcc['autorizar'] !== true && $flujoAcc['revisar'] !== true && puedeAprobar()
          && !$flujo['autorizado'] && !$flujo['rechazado'] ? $flujoAcc['autorizar'] : '';
      if ($flujoMotivo && $flujoMotivo !== 'El proceso ya no está pendiente.'): ?>
      <span class="text-muted" style="font-size:.78rem"><i class="fas fa-circle-info"></i> <?= htmlspecialchars($flujoMotivo) ?></span>
      <?php endif; ?>
    </div>
  </div>
  <?php
  // Historial de rechazos: cada uno con quién, cuándo, el motivo y su evidencia. Se conserva
  // aunque el proceso se corrija y vuelva a revisión.
  $flujoRechazos = rechazosDe($pdo, $flujoDef['tabla'], (int)$flujoId);
  if ($flujoRechazos): ?>
  <div class="flujo-rechazos">
    <div class="flujo-titulo mb-2"><i class="fas fa-comment-slash"></i>
      <?= count($flujoRechazos) === 1 ? 'Rechazo' : 'Historial de rechazos (' . count($flujoRechazos) . ')' ?></div>
    <?php foreach ($flujoRechazos as $i => $r):
      $vigente = $i === 0 && $flujo['rechazado']; ?>
    <div class="flujo-rechazo<?= $vigente ? ' vigente' : '' ?>">
      <div class="flujo-rechazo-cab">
        <span class="badge <?= $vigente ? 'bg-danger' : 'bg-secondary' ?>"><?= $vigente ? 'Rechazo vigente' : 'Corregido después' ?></span>
        <strong><?= htmlspecialchars($r['nombre']) ?></strong>
        <span class="text-muted"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span>
      </div>
      <div class="flujo-rechazo-motivo">
        <?php if (trim($r['motivo']) !== ''): ?><?= nl2br(htmlspecialchars($r['motivo'])) ?>
        <?php else: ?><span class="text-muted">No se registró el motivo (rechazo anterior a este requisito).</span><?php endif; ?>
      </div>
      <?php if ($r['evidencias']): ?>
      <div class="flujo-titulo mt-2 mb-1"><i class="fas fa-paperclip"></i> Evidencia</div>
      <div class="adjuntos-lista"><?php foreach ($r['evidencias'] as $a) echo htmlAdjunto($a); ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

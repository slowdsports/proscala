<?php
// Cadena del proceso (config/cadena.php): todos los documentos del mismo proceso, paso por paso, con
// el que se está viendo resaltado. Un paso con varios documentos (p. ej. una orden de compra por
// proveedor) los lista todos. Los pasos aún sin documento quedan atenuados.
// Uso: $cadModulo = 'compras_orden.php'; $cadId = 19; include __DIR__ . '/includes/cadena_proceso.php';
require_once dirname(__DIR__) . '/config/cadena.php';
$cad = cadenaProceso($pdo, $cadModulo, (int)$cadId);
if ($cad):
    $cadUrl = fn(string $m, $docId) => BASE_URL . $m . '?action=ver&id=' . (int)$docId;
?>
<div class="trace-chain mb-3 no-print">
  <?php foreach ($cad as $paso):
    $docs = $paso['docs'];
    $esPaso = $paso['modulo'] === $cadModulo;
    $icono = '<i class="fas ' . htmlspecialchars($paso['icono']) . '"></i><span class="trace-step-label">' . htmlspecialchars($paso['etiqueta']) . '</span>';
    if (!$docs): ?>
  <div class="trace-step inactive"><?= $icono ?><span class="trace-step-num">—</span></div>
    <?php elseif (count($docs) === 1): $d = $docs[0];
      if ($esPaso && (int)$d['id'] === (int)$cadId): ?>
  <div class="trace-step active"><?= $icono ?><span class="trace-step-num"><?= htmlspecialchars($d['numero']) ?></span><span class="trace-step-badge"><?= estadoBadge($d['estado']) ?></span></div>
      <?php else: ?>
  <a href="<?= $cadUrl($paso['modulo'], $d['id']) ?>" class="trace-step linked"><?= $icono ?><span class="trace-step-num"><?= htmlspecialchars($d['numero']) ?></span><span class="trace-step-badge"><?= estadoBadge($d['estado']) ?></span></a>
      <?php endif; ?>
    <?php else: ?>
  <div class="trace-step multi<?= $esPaso ? ' active' : '' ?>"><?= $icono ?>
    <?php foreach ($docs as $d): $esEste = $esPaso && (int)$d['id'] === (int)$cadId; ?>
    <a href="<?= $cadUrl($paso['modulo'], $d['id']) ?>" class="trace-item<?= $esEste ? ' actual' : '' ?>" title="<?= htmlspecialchars($d['numero'] . ' — ' . ucfirst($d['estado'])) ?>">
      <span class="trace-step-num"><?= htmlspecialchars($d['numero']) ?></span> <span class="trace-step-badge"><?= estadoBadge($d['estado']) ?></span></a>
    <?php endforeach; ?>
  </div>
    <?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

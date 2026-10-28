<?php
// Página común de los documentos de una página: barra superior y encabezado (organización,
// tipo de documento, número y estado). Cierra en _pagina_fin.php.
?>
<div class="page">
  <div class="top-bar"></div>

  <!-- Header -->
  <div class="doc-header">
    <?php $orgContacto = true; require __DIR__ . '/_org.php'; ?>
    <div class="doc-title-block">
      <div class="doc-type"><?= $title ?></div>
      <div class="doc-number"><?= htmlspecialchars($docNum) ?></div>
      <?php
      $estado = $doc['estado'] ?? '';
      $estClass = 'est-' . ($estado ?: 'pendiente');
      if ($estado): ?>
      <div><span class="doc-estado <?= htmlspecialchars($estClass) ?>"><?= ucfirst($estado) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

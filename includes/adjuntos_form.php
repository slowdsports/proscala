<?php
// Documentos adjuntos al llenar el formulario de un proceso (facturas, cotizaciones, fotos…).
// Los archivos quedan en el navegador mientras se llena; al guardar, proscala.js (AdjuntosForm)
// los sube al proceso recién guardado por adjuntos.php, con las mismas validaciones del panel
// "Documentos adjuntos" (includes/adjuntos.php). En un expediente (EXPEDIENTES) se elige además
// la categoría con la que se guardan.
// Uso, dentro del <form>: $adjModulo = 'gastos_solicitud.php'; include __DIR__ . '/includes/adjuntos_form.php';
$adjExp = EXPEDIENTES[$adjModulo] ?? null;
?>
<div class="adjuntos-form mt-3" data-adjuntos-form="<?= htmlspecialchars($adjModulo) ?>" data-max-mb="<?= adjuntosMaxMb() ?>">
  <div class="form-section-title d-flex flex-wrap align-items-center gap-2">
    <span><i class="fas <?= $adjExp ? 'fa-folder-open' : 'fa-paperclip' ?>"></i> <?= htmlspecialchars($adjExp['titulo'] ?? 'Documentos adjuntos') ?></span>
    <span class="text-muted fw-normal small" style="text-transform:none;letter-spacing:0"><?= $adjExp
      ? 'Identidad, contrato, hoja de vida, títulos… Se guardan junto con el formulario.'
      : 'Facturas, recibos, cotizaciones o fotos. Se guardan junto con el formulario.' ?></span>
  </div>
  <div class="d-flex gap-2 flex-wrap align-items-center">
    <?php if ($adjExp): ?>
    <select class="form-select form-select-sm adj-categoria no-search" style="width:auto;max-width:260px" title="Categoría de los documentos">
      <?php foreach ($adjExp['categorias'] as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label class="btn btn-sm btn-outline-primary mb-0" title="Tomar una foto con la cámara del celular">
      <i class="fas fa-camera"></i> Tomar foto
      <input type="file" accept="image/*" capture="environment" hidden onchange="AdjuntosForm.agregar(this)">
    </label>
    <label class="btn btn-sm btn-outline-secondary mb-0" title="PDF, fotos, Word, Excel o XML (máx. <?= adjuntosMaxMb() ?> MB cada uno)">
      <i class="fas fa-upload"></i> Elegir archivos
      <input type="file" accept="<?= ADJUNTOS_ACCEPT ?>" multiple hidden onchange="AdjuntosForm.agregar(this)">
    </label>
  </div>
  <ul class="adjf-lista list-unstyled mb-0 mt-2"></ul>
  <div class="adj-soltar text-muted small"><i class="fas fa-cloud-arrow-up"></i> También puede arrastrar los archivos aquí</div>
</div>

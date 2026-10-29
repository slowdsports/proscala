<?php
// Panel "Documentos adjuntos" de un proceso (config/adjuntos.php): facturas, recibos, fotos…
// En un expediente (EXPEDIENTES, p. ej. empleados) se titula como tal, pide la categoría del
// documento antes de subirlo y lista los documentos agrupados por categoría.
// Uso: $adjModulo = 'gastos_solicitud.php'; $adjId = 12; include __DIR__ . '/includes/adjuntos.php';
if (puedeVerProceso($pdo, $adjModulo, (int)$adjId)):
    $adjTotal = count(adjuntosDe($pdo, tablaAdjuntos($adjModulo), (int)$adjId));
    $adjExp = EXPEDIENTES[$adjModulo] ?? null;
?>
<div class="card mt-3 mb-3 no-print adjuntos-panel" data-modulo="<?= htmlspecialchars($adjModulo) ?>" data-id="<?= (int)$adjId ?>" data-max-mb="<?= adjuntosMaxMb() ?>">
  <div class="card-header flex-wrap gap-2">
    <span><i class="fas <?= $adjExp ? 'fa-folder-open' : 'fa-paperclip' ?>"></i> <?= htmlspecialchars($adjExp['titulo'] ?? 'Documentos adjuntos') ?>
      <span class="badge bg-secondary adj-total"><?= $adjTotal ?></span></span>
    <div class="ms-auto d-flex gap-2 flex-wrap align-items-center">
      <?php if ($adjExp): ?>
      <select class="form-select form-select-sm adj-categoria no-search" style="width:auto;max-width:260px" title="Categoría de los documentos que va a subir">
        <?php foreach ($adjExp['categorias'] as $cat): ?><option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option><?php endforeach; ?>
      </select>
      <?php endif; ?>
      <label class="btn btn-sm btn-outline-primary mb-0" title="Tomar una foto con la cámara del celular">
        <i class="fas fa-camera"></i> Tomar foto
        <input type="file" accept="image/*" capture="environment" hidden onchange="Adjuntos.subir(this)">
      </label>
      <label class="btn btn-sm btn-primary mb-0" title="<?= $adjExp ? 'PDF, fotos, Word o Excel' : 'Facturas, recibos, PDF, Word, Excel o XML' ?> (máx. <?= adjuntosMaxMb() ?> MB cada uno)">
        <i class="fas fa-upload"></i> Subir archivos
        <input type="file" accept="<?= ADJUNTOS_ACCEPT ?>" multiple hidden onchange="Adjuntos.subir(this)">
      </label>
    </div>
  </div>
  <div class="card-body adj-cuerpo">
    <div class="adj-lista"><?= htmlAdjuntos($pdo, $adjModulo, (int)$adjId) ?></div>
    <div class="adj-soltar text-muted small"><i class="fas fa-cloud-arrow-up"></i> También puede arrastrar los archivos aquí<?= $adjExp ? ' (se guardan en la categoría elegida)' : '' ?></div>
  </div>
</div>
<?php endif; ?>

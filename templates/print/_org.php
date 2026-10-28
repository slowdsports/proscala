<?php
// Bloque de la organización en el encabezado de todos los documentos: logo (o su inicial),
// nombre, razón social, dirección y RTN; con $orgContacto, también teléfono y correo.
?>
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
        <?php if ($orgContacto && $orgTel): ?><div class="org-sub">Tel: <?= htmlspecialchars($orgTel) ?></div><?php endif; ?>
        <?php if ($orgContacto && $orgEmail): ?><div class="org-sub"><?= htmlspecialchars($orgEmail) ?></div><?php endif; ?>
      </div>
    </div>

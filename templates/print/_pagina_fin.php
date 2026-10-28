<?php
// Pie de la página común (abierta en _pagina_inicio.php).
?>
  <div class="spacer"></div>

  <!-- Footer -->
  <div class="doc-footer">
    <div class="footer-left">
      <?= htmlspecialchars($org) ?><?php if($orgRtn): ?> &nbsp;·&nbsp; RTN <?= htmlspecialchars($orgRtn) ?><?php endif; ?><br>
      Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala
    </div>
    <div class="footer-right">
      <?= $title ?> — <?= htmlspecialchars($docNum) ?>
    </div>
  </div>
  <div class="bottom-bar"></div>
</div>

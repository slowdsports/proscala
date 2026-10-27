</main><!-- /main-content -->

<footer class="app-footer">
  <span>&copy; <?= date('Y') ?> Proscala &mdash; Sistema de Administración Empresarial</span>
  <span>Versión <?= APP_VERSION ?></span>
</footer>

<script src="<?= recurso('assets/vendor/jquery/jquery.min.js') ?>"></script>
<script src="<?= recurso('assets/vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= recurso('assets/vendor/datatables/jquery.dataTables.min.js') ?>"></script>
<script src="<?= recurso('assets/vendor/datatables/dataTables.bootstrap5.min.js') ?>"></script>
<script src="<?= recurso('assets/vendor/datatables/dataTables.responsive.min.js') ?>"></script>
<script src="<?= recurso('assets/vendor/datatables/es-MX.js') ?>"></script>
<script src="<?= recurso('assets/vendor/flatpickr/flatpickr.min.js') ?>"></script>
<script src="<?= recurso('assets/vendor/flatpickr/es.js') ?>"></script>
<script src="<?= recurso('assets/vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= recurso('assets/vendor/select2/select2.min.js') ?>"></script>
<script src="<?= recurso('assets/vendor/select2/es.js') ?>"></script>
<script src="<?= recurso('assets/js/proscala.js') ?>"></script>
<script>
  // Flatpickr antes del script de la página (puede usar sus instancias); Select2 al terminar de cargar
  initFechas(document);

  // Sync footer margin with sidebar collapse (mirrors #main-content.expanded logic)
  const sidebar = document.getElementById('sidebar');
  const footer  = document.querySelector('.app-footer');
  const observer = new MutationObserver(() => {
    if (!footer) return;
    const collapsed = sidebar?.classList.contains('collapsed');
    footer.style.marginLeft = collapsed ? '0' : 'var(--sidebar-w)';
  });
  if (sidebar) observer.observe(sidebar, { attributes: true, attributeFilter: ['class'] });
</script>
<?php if (isset($extraJs)): ?>
<?php // data-pagina: la navegación sin recarga ejecuta este bloque al cargar la página por fetch ?>
<script>Nav.iniciarCaptura();</script>
<script data-pagina><?= $extraJs ?></script>
<script>Nav.terminarCaptura();</script>
<?php endif; ?>
</body>
</html>

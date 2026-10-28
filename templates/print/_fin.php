<?php
// Final de todos los documentos: hora de generación, descarga en PDF desde el navegador.
?>
<script src="<?= recurso('assets/vendor/html2pdf/html2pdf.bundle.min.js') ?>"></script>
<script>
<?php
$orientation = in_array($tipo, ['pl','pb']) ? 'landscape' : 'portrait';
$isMultiPageDoc = ($tipo === 'proceso') || ($sgHasAlim ?? false);
$pdfSelector = $isMultiPageDoc ? '#all-pages' : '.page';
$safeNum     = preg_replace('/[^\w\-]/', '_', "$tipo-$docNum");
$filename    = "Proscala-{$safeNum}.pdf";
?>

// ── Hora local del dispositivo en todos los footers ───────────────
(function() {
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  const ts  = pad(now.getDate()) + '/' + pad(now.getMonth() + 1) + '/' + now.getFullYear()
            + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());
  document.querySelectorAll('.gen-ts').forEach(el => el.textContent = ts);
})();

const spinSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="animation:spin 1s linear infinite"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>';
const dlSvg   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>';

// ── html2canvas + text-transform:uppercase pierde los acentos en
// mayúsculas al usar letterRendering con letter-spacing (bug conocido
// de html2canvas). Se resuelve escribiendo el texto ya en mayúsculas
// (JS sí acentúa bien) y desactivando el transform solo durante la captura.
const UPPERCASE_SELECTORS = '.doc-type, .doc-estado, .meta-label, .section-head, .amount-label, .letter-ref, .letter-to strong, .letter-p .emp-name, .obs-label';
function fixAccentedUppercase(root) {
  const nodes = root.querySelectorAll(UPPERCASE_SELECTORS);
  const restore = [];
  nodes.forEach(n => {
    restore.push([n, n.textContent, n.style.textTransform]);
    n.textContent = n.textContent.toLocaleUpperCase('es');
    n.style.textTransform = 'none';
  });
  return () => restore.forEach(([n, text, tt]) => { n.textContent = text; n.style.textTransform = tt; });
}

// ── PDF download (single / proceso) ───────────────────────────────
async function downloadPdf() {
  const btn     = document.getElementById('btn-download');
  const toolbar = document.getElementById('toolbar');
  btn.disabled  = true;
  btn.innerHTML = spinSvg + ' Generando…';
  toolbar.style.visibility = 'hidden';

  const el          = document.querySelector('<?= $pdfSelector ?>');
  const isMultiPage = <?= $isMultiPageDoc ? 'true' : 'false' ?>;

  document.body.style.background = '#fff';
  document.body.style.padding    = '0';
  el.style.margin                = '0';
  el.style.boxShadow             = 'none';
  const restoreCase = fixAccentedUppercase(el);

  const opt = {
    margin:      0,
    filename:    '<?= addslashes($filename) ?>',
    html2canvas: { scale: 2, useCORS: true, logging: false, letterRendering: true },
    jsPDF:       { unit: 'mm', format: 'a4', orientation: '<?= $orientation ?>' },
    pagebreak:   { mode: isMultiPage ? 'css' : 'avoid-all' }
  };

  try {
    await html2pdf().set(opt).from(el).save();
  } finally {
    restoreCase();
    document.body.style.background = '';
    document.body.style.padding    = '';
    el.style.margin                = '';
    el.style.boxShadow             = '';
    toolbar.style.visibility = 'visible';
    btn.disabled  = false;
    btn.innerHTML = dlSvg + ' Descargar PDF';
  }
}

// Spinner keyframe
const style = document.createElement('style');
style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(style);

// Auto-descarga cuando se llega desde pdf_download.php
if (new URLSearchParams(location.search).get('auto') === '1') {
  window.addEventListener('load', () => setTimeout(downloadPdf, 400));
}
</script>
</body>
</html>

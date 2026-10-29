<?php
// Inicio de todos los documentos: <head> con los estilos comunes y la barra Cerrar / Descargar PDF.
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars("$title — $docNum") ?></title>
<?php include dirname(__DIR__, 2) . '/includes/favicon.php'; ?>
<style>
/* ── Reset ──────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 10pt; }
body {
  font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
  color: #1a1a2e;
  background: #fff;
  line-height: 1.45;
}

/* ── Page layout ─────────────────────────────────────────────────── */
<?php $isLandscape = in_array($tipo, ['pl','pb','plp']); ?>
.page {
  width: <?= $isLandscape ? '297mm' : '210mm' ?>;
  min-height: <?= $isLandscape ? '210mm' : '297mm' ?>;
  margin: 0 auto;
  padding: 0;
  display: flex;
  flex-direction: column;
}
@media screen {
  body { background: #e8edf2; padding: 20px 0 40px; }
  .page { box-shadow: 0 4px 30px rgba(0,0,0,.18); background: #fff; }
}
@media print {
  body { background: #fff !important; padding: 0; }
  .page { box-shadow: none; margin: 0; width: 100%; }
  .no-print { display: none !important; }
  @page { margin: 0; size: A4 <?= $isLandscape ? 'landscape' : 'portrait' ?>; }
  /* Ancla el gradiente inferior al borde físico de cada hoja impresa */
  .bottom-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: 4px;
  }
  /* Oculta la copia en el flujo normal para evitar doble renderización */
  .page > .bottom-bar { display: none; }
}

/* ── Top accent bar ──────────────────────────────────────────────── */
.top-bar {
  height: 6px;
  background: linear-gradient(90deg, #1B6BA8 0%, #0D3F6A 60%, #E53935 100%);
}

/* ── Document header ─────────────────────────────────────────────── */
.doc-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 18px 28px 14px;
  border-bottom: 1px solid #e2e8f0;
  gap: 16px;
}
.org-block {
  display: flex;
  align-items: center;
  gap: 12px;
}
.org-logo {
  height: 52px;
  width: auto;
  object-fit: contain;
  flex-shrink: 0;
}
.org-logo-placeholder {
  height: 52px;
  width: 52px;
  background: #1B6BA8;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-weight: 700;
  font-size: 16pt;
  flex-shrink: 0;
}
.org-info { line-height: 1.35; }
.org-name {
  font-size: 11pt;
  font-weight: 700;
  color: #0D3F6A;
  letter-spacing: .01em;
}
.org-sub {
  font-size: 7.5pt;
  color: #64748b;
  margin-top: 1px;
}
.org-fullname {
  font-size: 6.5pt;
  color: #475569;
  letter-spacing: .01em;
  white-space: nowrap;
  margin-top: 2px;
}

.doc-title-block { text-align: right; flex-shrink: 0; }
.doc-type {
  font-size: 8pt;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: #1B6BA8;
  margin-bottom: 4px;
  white-space: nowrap;
}
.doc-number {
  font-size: 17pt;
  font-weight: 800;
  color: #0D3F6A;
  letter-spacing: .02em;
  line-height: 1;
  white-space: nowrap;
}
.doc-estado {
  margin-top: 5px;
  display: inline-block;
  padding: 2px 8px;
  border-radius: 20px;
  font-size: 7.5pt;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
}
.est-pendiente  { background: #fff3cd; color: #856404; }
.est-aprobada   { background: #d1e7dd; color: #0a3622; }
.est-rechazada  { background: #f8d7da; color: #842029; }
.est-pagada     { background: #cff4fc; color: #055160; }
.est-emitida    { background: #cfe2ff; color: #084298; }
.est-procesada  { background: #cfe2ff; color: #084298; }
.est-completa   { background: #d1e7dd; color: #0a3622; }
.est-anulada    { background: #e2e3e5; color: #41464b; }
.est-borrador   { background: #e2e3e5; color: #41464b; }

/* ── Meta section ─────────────────────────────────────────────────── */
.meta-section {
  padding: 14px 28px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
}
.meta-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px 20px;
}
.meta-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
.meta-grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
.meta-item {}
.meta-label {
  font-size: 7pt;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 2px;
}
.meta-value {
  font-size: 9pt;
  color: #1a1a2e;
  font-weight: 500;
}
.meta-value.mono { font-family: 'Courier New', monospace; }
.meta-full { grid-column: 1 / -1; }

/* ── Section title ────────────────────────────────────────────────── */
.section-head {
  padding: 8px 28px;
  font-size: 7pt;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: #1B6BA8;
  background: #f0f5fc;
  border-top: 1px solid #dbeafe;
  border-bottom: 1px solid #dbeafe;
}

/* ── Items table ──────────────────────────────────────────────────── */
.items-wrap { padding: 0; }
table.items {
  width: 100%;
  border-collapse: collapse;
  font-size: 8.5pt;
}
table.items thead th {
  background: #0D3F6A;
  color: #fff;
  padding: 7px 10px;
  text-align: left;
  font-weight: 600;
  font-size: 7.5pt;
  letter-spacing: .04em;
  white-space: nowrap;
}
table.items thead th.r { text-align: right; }
table.items tbody tr:nth-child(even) { background: #f8fafc; }
table.items tbody tr:hover { background: #eff6ff; }
table.items tbody td {
  padding: 6px 10px;
  border-bottom: 1px solid #e2e8f0;
  vertical-align: top;
}
table.items tbody td.r { text-align: right; font-family: 'Courier New', monospace; }
table.items tbody td.c { text-align: center; }
table.items tfoot td {
  padding: 6px 10px;
  font-size: 8.5pt;
}
table.items tfoot td.r { text-align: right; font-family: 'Courier New', monospace; }
table.items tfoot tr:last-child td {
  border-top: 2px solid #1B6BA8;
  font-weight: 700;
  font-size: 9.5pt;
  color: #0D3F6A;
}

/* ── Amount highlight (Orden de Pago) ─────────────────────────────── */
.amount-block {
  margin: 16px 28px;
  padding: 14px 20px;
  background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
  border-left: 5px solid #1B6BA8;
  border-radius: 0 8px 8px 0;
  display: flex;
  align-items: center;
  gap: 20px;
}
.amount-label {
  font-size: 8pt;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: #1B6BA8;
}
.amount-value {
  font-size: 22pt;
  font-weight: 800;
  color: #0D3F6A;
  font-family: 'Courier New', monospace;
  letter-spacing: .02em;
}

/* ── Reference chain (trazabilidad) ──────────────────────────────── */
.ref-chain {
  padding: 10px 28px;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  border-bottom: 1px solid #e2e8f0;
}
.ref-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 7.5pt;
  font-weight: 700;
  background: #f0f5fc;
  color: #1B6BA8;
  border: 1px solid #bfdbfe;
}
.ref-arrow { color: #94a3b8; font-size: 9pt; }
.ref-current {
  background: #1B6BA8;
  color: #fff;
  border-color: #1B6BA8;
}

/* ── Signatures ───────────────────────────────────────────────────── */
.signatures {
  margin: 28px 28px 0;
  display: grid;
  gap: 20px;
}
.signatures.cols-3 { grid-template-columns: repeat(3, 1fr); }
.signatures.cols-2 { grid-template-columns: repeat(2, 1fr); }
.signatures.cols-4 { grid-template-columns: repeat(4, 1fr); }
/* Proveedor block — centered, not full-width */
.sig-prov-wrap {
  margin: 40px 28px 0;
  display: flex;
  justify-content: center;
}
.sig-prov-wrap .sig-item {
  width: 38%;
  min-width: 190px;
}
.sig-prov-wrap .sig-line { margin-top: 58px; }
.sig-item.sig-ext .sig-line { border-top-style: dashed; border-top-color: #94a3b8; }
.sig-item.sig-ext .sig-name { color: #475569; }
.sig-item.sig-ext .sig-role { color: #94a3b8; }
.sig-item.sig-ext::before {
  content: 'PROVEEDOR';
  display: block;
  font-size: 6pt;
  font-weight: 700;
  letter-spacing: .1em;
  color: #1B6BA8;
  border: 1px solid #bfdbfe;
  border-radius: 20px;
  padding: 1px 7px;
  text-align: center;
  margin-bottom: 4px;
  width: fit-content;
  margin-left: auto;
  margin-right: auto;
}
.sig-item { text-align: center; }
.sig-line {
  border-top: 1.5px solid #cbd5e1;
  margin-bottom: 5px;
  margin-top: 32px;
}
.sig-name {
  font-size: 8pt;
  font-weight: 600;
  color: #1a1a2e;
}
.sig-role {
  font-size: 7pt;
  color: #64748b;
  margin-top: 1px;
}

/* ── Footer ──────────────────────────────────────────────────────── */
.spacer { flex: 1; }
.doc-footer {
  margin-top: 24px;
  padding: 10px 28px;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.footer-left { font-size: 7pt; color: #94a3b8; }
.footer-right { font-size: 7pt; color: #94a3b8; text-align: right; }
.bottom-bar {
  height: 4px;
  background: linear-gradient(90deg, #E53935 0%, #1B6BA8 100%);
}

/* ── Multi-page wrapper (proceso completo) ───────────────────────── */
#all-pages { display: flex; flex-direction: column; }
@media screen { #all-pages .page + .page { margin-top: 24px; } }
@media print  { #all-pages .page + .page { page-break-before: always; } }

/* ── Toolbar (screen only) ───────────────────────────────────────── */
.print-toolbar {
  position: fixed; bottom: 24px; right: 24px;
  display: flex; gap: 10px; z-index: 999;
}
.btn-print {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer;
  font-size: 10pt; font-weight: 600; font-family: inherit;
  box-shadow: 0 3px 14px rgba(0,0,0,.22);
  transition: transform .15s, box-shadow .15s;
}
.btn-print:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.28); }
.btn-print:disabled { opacity: .6; cursor: not-allowed; transform: none; }
.btn-print.primary { background: #1B6BA8; color: #fff; }
.btn-print.secondary { background: #fff; color: #374151; border: 1px solid #d1d5db; }

/* ── Letter format (constancias / RRHH) ──────────────────────── */
.letter-body { padding: 26px 42px; flex: 1; }
.letter-date { text-align: right; font-size: 9pt; color: #374151; margin-bottom: 20px; }
.letter-to { font-size: 9.5pt; color: #1a1a2e; margin-bottom: 6px; }
.letter-to strong { display: block; font-size: 10.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #0D3F6A; }
.letter-separator { border: none; border-top: 1.5px solid #dbeafe; margin: 12px 0 16px; }
.letter-ref { font-size: 8pt; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #1B6BA8; margin-bottom: 14px; }
.letter-p { font-size: 10pt; line-height: 1.8; text-align: justify; margin-bottom: 14px; color: #1a1a2e; }
.letter-p .emp-name { font-weight: 700; text-transform: uppercase; }
.letter-p .emp-id { font-family: 'Courier New', monospace; font-weight: 600; }
.letter-p .highlight { font-weight: 700; color: #0D3F6A; }
.letter-close { margin-top: 16px; font-size: 9.5pt; color: #374151; }
.signatures.cols-1 { grid-template-columns: 1fr; max-width: 260px; margin-left: auto; margin-right: auto; }
</style>
</head>
<body>

<!-- Screen-only toolbar -->
<div class="print-toolbar no-print" id="toolbar">
  <button class="btn-print secondary" onclick="window.close()">✕ Cerrar</button>
  <?php if (in_array($tipo, ['plv','pav','pbv']) && isset($_GET['emp_id'])): ?>
  <a class="btn-print primary" id="btn-download"
     href="<?= BASE_URL ?>pdf_download.php?tipo=<?= urlencode($tipo) ?>&id=<?= $id ?>&emp_id=<?= (int)$_GET['emp_id'] ?>">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
    Descargar PDF
  </a>
  <?php else: ?>
  <a class="btn-print primary" id="btn-download"
     href="<?= BASE_URL ?>pdf_download.php?tipo=<?= urlencode($tipo) ?>&id=<?= $id ?>">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
    Descargar PDF
  </a>
  <?php endif; ?>
</div>

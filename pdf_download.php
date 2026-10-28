<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$tipo = $_GET['tipo'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if (!$id || !isset(DOCUMENTOS_MODULO[$tipo])) {
    http_response_code(400); die('Parámetros inválidos.');
}
if (!puedeVerDocumento($tipo)) { http_response_code(403); die('No tiene permiso para ver este documento.'); }

// ── Capturar HTML de print.php ────────────────────────────────────
ob_start();
include __DIR__ . '/print.php';
$html = ob_get_clean();

// ── Escribir HTML a archivo temporal ─────────────────────────────
$uid     = 'proscala_' . uniqid();
$tmpDir  = sys_get_temp_dir();
$tmpHtml = $tmpDir . DIRECTORY_SEPARATOR . $uid . '.html';
$tmpPdf  = $tmpDir . DIRECTORY_SEPARATOR . $uid . '.pdf';

file_put_contents($tmpHtml, $html);

// ── Detectar motor de renderizado disponible ──────────────────────
function findBrowser(): string {
    $candidates = [
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium',
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/local/bin/chromium',
        '/usr/local/bin/chromium-browser',
        '/usr/local/bin/google-chrome',
        'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
        'C:\Program Files\Google\Chrome\Application\chrome.exe',
    ];
    foreach ($candidates as $p) {
        if (@file_exists($p) && @is_executable($p)) return $p;
    }
    return '';
}

function findWkhtmltopdf(): string {
    foreach (['/usr/bin/wkhtmltopdf', '/usr/local/bin/wkhtmltopdf'] as $p) {
        if (@file_exists($p) && @is_executable($p)) return $p;
    }
    return '';
}

$exitCode = 1;
$output   = [];
$browser  = findBrowser();
$wkhtml   = findWkhtmltopdf();

// El proceso de Apache corre elevado (integridad "High"); Edge/Chrome se niega a
// arrancar por completo cuando se lanza así (auto-verificación de integridad de
// proceso) y termina en silencio con exit 0 sin generar el PDF. Hay que lanzar el
// navegador con integridad "Medium" (des-elevado) para que funcione.
function isElevated(): bool {
    $out = [];
    exec('whoami /groups 2>&1', $out);
    foreach ($out as $line) {
        if (stripos($line, 'High Mandatory Level') !== false) return true;
    }
    return false;
}

if ($browser) {
    $isWin   = DIRECTORY_SEPARATOR === '\\';
    $fileUrl = $isWin
        ? 'file:///' . str_replace('\\', '/', $tmpHtml)
        : 'file://' . $tmpHtml;
    // Perfil de usuario dedicado: si el navegador ya está abierto en modo normal
    // en el servidor, lanzar msedge/chrome sin --user-data-dir propio hace que el
    // proceso reenvíe la orden a la instancia existente (que ignora --headless) y falle.
    $udd = $tmpDir . DIRECTORY_SEPARATOR . $uid . '_profile';
    $browserCmd = escapeshellarg($browser)
         . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
         . ' --user-data-dir=' . escapeshellarg($udd)
         . ' --print-to-pdf=' . escapeshellarg($tmpPdf)
         . ' --no-pdf-header-footer'
         . ' ' . escapeshellarg($fileUrl);
    if ($isWin && isElevated()) {
        $inner = str_replace('"', '\"', $browserCmd);
        $cmd = 'runas /trustlevel:0x20000 "' . $inner . '" 2>&1';
    } else {
        $cmd = $browserCmd . ' 2>&1';
    }
    exec($cmd, $output, $exitCode);
} elseif ($wkhtml) {
    $cmd = escapeshellarg($wkhtml)
         . ' --quiet --disable-javascript'
         . ' --page-size A4 --margin-top 10mm --margin-bottom 10mm'
         . ' --margin-left 10mm --margin-right 10mm'
         . ' ' . escapeshellarg($tmpHtml)
         . ' ' . escapeshellarg($tmpPdf) . ' 2>&1';
    exec($cmd, $output, $exitCode);
} else {
    $output[] = 'No se encontró ningún motor PDF (Chromium, Chrome, ni wkhtmltopdf) en el servidor.';
}

// ── Verificar resultado ───────────────────────────────────────────
if (!file_exists($tmpPdf) || filesize($tmpPdf) === 0) {
    @unlink($tmpHtml);
    $printUrl = BASE_URL . 'print.php?tipo=' . urlencode($tipo) . '&id=' . $id
              . (!empty($_GET['emp_id']) ? '&emp_id=' . (int)$_GET['emp_id'] : '');
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    die('<p><strong>Error generando PDF en el servidor.</strong><br>'
      . 'Motor detectado: ' . ($browser ? htmlspecialchars(basename($browser)) : ($wkhtml ? 'wkhtmltopdf' : 'ninguno')) . '<br>'
      . 'Exit: ' . $exitCode . '<br>'
      . htmlspecialchars(implode("\n", $output))
      . '</p><p><a href="' . htmlspecialchars($printUrl) . '">Abrir versión imprimible</a> '
      . '(usa Ctrl+P o el botón del navegador para guardar como PDF)</p>');
}

// ── Nombre del archivo ────────────────────────────────────────────
$docNum  = preg_replace('/[^\w\-]/', '_', "$tipo-$id");
$empSlug = '';
if (!empty($_GET['emp_id'])) {
    $se = $pdo->prepare("SELECT CONCAT(nombre,' ',apellidos) FROM empleados WHERE id=?");
    $se->execute([(int)$_GET['emp_id']]);
    $empSlug = '-' . preg_replace('/[^\w]/', '_', $se->fetchColumn() ?: $_GET['emp_id']);
}
$filename = "Proscala-{$docNum}{$empSlug}.pdf";

// ── Enviar al navegador ───────────────────────────────────────────
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpPdf));
header('Cache-Control: private, no-cache');
readfile($tmpPdf);

// ── Limpieza ──────────────────────────────────────────────────────
@unlink($tmpHtml);
@unlink($tmpPdf);
if (!empty($udd) && is_dir($udd)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($udd, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($udd);
}

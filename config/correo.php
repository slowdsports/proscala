<?php
// ── Correo saliente ─────────────────────────────────────────────────
// Configuración en proscala_config.php → 'correo' (ver config/credenciales.ejemplo.php):
//   smtp    → servidor SMTP con usuario y contraseña (recomendado)
//   mail    → función mail() del hosting
//   archivo → no envía: guarda el correo en storage/correos/ (desarrollo local)
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

/** Envía un correo HTML. Devuelve true o el motivo del fallo (el detalle técnico va al log). */
function enviarCorreo(string $para, string $asunto, string $html, string $texto = '') {
    $cfg = credenciales()['correo'] ?? null;
    if (!$cfg || empty($cfg['metodo'])) return 'El correo saliente no está configurado en el servidor.';
    $texto = $texto ?: trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>|<\/h\d>/i', "\n", $html)), ENT_QUOTES, 'UTF-8'));

    if ($cfg['metodo'] === 'archivo') {
        $dir = dirname(__DIR__) . '/storage/correos';
        if (!is_dir($dir)) mkdir($dir, 0700, true);
        $f = $dir . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9]+/i', '_', $para) . '.html';
        file_put_contents($f, "<!-- Para: $para | Asunto: $asunto -->\n" . $html);
        return true;
    }

    $m = new PHPMailer(true);
    try {
        $m->CharSet = 'UTF-8';
        $m->Timeout = 15;
        if ($cfg['metodo'] === 'smtp') {
            $m->isSMTP();
            $m->Host       = $cfg['host'];
            $m->Port       = (int)($cfg['puerto'] ?? 465);
            $m->SMTPAuth   = true;
            $m->Username   = $cfg['usuario'];
            $m->Password   = $cfg['clave'];
            $m->SMTPSecure = ($cfg['seguridad'] ?? 'ssl') === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
        } else {
            $m->isMail();
        }
        $m->setFrom($cfg['remitente'], $cfg['nombre'] ?? 'Proscala');
        $m->addAddress($para);
        $m->isHTML(true);
        $m->Subject = $asunto;
        $m->Body    = $html;
        $m->AltBody = $texto;
        $m->send();
        return true;
    } catch (Throwable $e) {
        error_log('Proscala: no se pudo enviar el correo a ' . $para . ': ' . ($m->ErrorInfo ?: $e->getMessage()));
        return 'No se pudo enviar el correo. Intente más tarde o avise al administrador.';
    }
}

/** Correo con el formato de Proscala: título, contenido (HTML ya escapado) y botón opcional. */
function plantillaCorreo(string $titulo, string $contenido, string $boton = '', string $url = ''): string {
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $btn = $boton && $url
        ? '<p style="text-align:center;margin:28px 0"><a href="' . $h($url) . '" style="background:#1B6BA8;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 26px;border-radius:8px;display:inline-block">' . $h($boton) . '</a></p>'
          . '<p style="font-size:12px;color:#8ea3b4;word-break:break-all">Si el botón no funciona, copie este enlace en su navegador:<br>' . $h($url) . '</p>'
        : '';
    return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:24px 12px;background:#f0f4f8;font-family:Segoe UI,Roboto,Arial,sans-serif;color:#0e1726">'
        . '<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e4e9f0;border-radius:14px;padding:28px">'
        . '<div style="font-size:20px;font-weight:700;color:#1B6BA8;margin-bottom:18px">Proscala</div>'
        . '<h2 style="font-size:18px;margin:0 0 14px">' . $h($titulo) . '</h2>'
        . '<div style="font-size:14px;line-height:1.6;color:#526070">' . $contenido . '</div>' . $btn
        . '</div><p style="text-align:center;font-size:11px;color:#8ea3b4;margin-top:14px">Mensaje automático de Proscala. No responda a este correo.</p>'
        . '</body></html>';
}

/**
 * URL absoluta de una página del sistema (para enlaces en correos). Usa la dirección oficial
 * de proscala_config.php ('url'): armarla con la cabecera Host de la petición permitiría que
 * alguien hiciera llegar enlaces de restablecimiento que apunten a otro dominio.
 */
function urlAbsoluta(string $pagina): string {
    $base = rtrim((string)(credenciales()['url'] ?? ''), '/');
    if ($base === '') {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(BASE_URL, '/');
    }
    return $base . '/' . ltrim($pagina, '/');
}

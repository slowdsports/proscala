<?php
// ── Herramienta de desarrollador: prueba del correo saliente del servidor actual ──
// Solo el super administrador (PAGINAS_SUPERADMIN). Muestra la configuración (sin la contraseña),
// prueba la conexión al servidor SMTP y envía un correo de prueba con el diálogo completo.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/correo.php';
requireLogin();
if (!esSuperadmin()) { header('Location: ' . BASE_URL . paginaInicio()); exit; }
$pagina = 'Prueba de correo';

$cfg    = credenciales()['correo'] ?? [];
$metodo = $cfg['metodo'] ?? '';

// Como jsonErr, pero con el diálogo con el servidor para diagnosticar el fallo
function errConTraza(string $msg, array $traza): void {
    $GLOBALS['_resultado'] = ['error', $msg, null];
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $msg, 'traza' => $traza]);
    exit;
}

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    // ── Conexión: abre el puerto del servidor SMTP y lee su saludo, sin autenticarse ni enviar ──
    if ($_act === 'conexion') {
        if ($metodo !== 'smtp') jsonErr("El método configurado es '$metodo': no usa un servidor SMTP.");
        $host = (string)($cfg['host'] ?? '');
        $port = (int)($cfg['puerto'] ?? 465);
        $ssl  = ($cfg['seguridad'] ?? 'ssl') !== 'tls';
        $ip   = gethostbyname($host);
        $traza = ["Servidor: $host → " . ($ip !== $host ? $ip : 'no se pudo resolver el nombre (DNS)'),
                  'Conectando a ' . ($ssl ? 'ssl' : 'tcp') . "://$host:$port …"];
        $t0  = microtime(true);
        $ctx = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
        $f   = @stream_socket_client(($ssl ? 'ssl' : 'tcp') . "://$host:$port", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        $ms  = round((microtime(true) - $t0) * 1000);
        if (!$f) {
            $traza[] = "ERROR ($errno): " . ($errstr ?: (error_get_last()['message'] ?? 'sin detalle'));
            errConTraza("No se pudo conectar a $host:$port ({$ms} ms).", $traza);
        }
        stream_set_timeout($f, 10);
        $saludo = trim((string)fgets($f, 1024));
        fwrite($f, "QUIT\r\n");
        fclose($f);
        $traza[] = "Conectado en {$ms} ms";
        $traza[] = 'Saludo del servidor: ' . ($saludo !== '' ? $saludo : '(ninguno)');
        if (!str_starts_with($saludo, '220')) errConTraza('El puerto respondió, pero no con un saludo SMTP válido (220).', $traza);
        jsonOk(['traza' => $traza], "Conexión correcta con $host:$port ({$ms} ms).");
    }

    // ── Envío de un correo de prueba ─────────────────────────────────
    if ($_act === 'enviar') {
        $para   = trim($_POST['para'] ?? '');
        $asunto = trim($_POST['asunto'] ?? '') ?: 'Prueba de correo de Proscala';
        $nota   = trim($_POST['mensaje'] ?? '');
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) jsonErr('Escriba un correo de destino válido.');

        $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $contenido = '<p>Este es un correo de prueba enviado desde Proscala para comprobar el correo saliente del servidor.</p>'
            . ($nota !== '' ? '<p>' . nl2br($h($nota)) . '</p>' : '')
            . '<p style="font-size:12px;color:#8ea3b4">Servidor: ' . $h(php_uname('n')) . ' · Método: ' . $h($metodo)
            . ($metodo === 'smtp' ? ' · ' . $h(($cfg['host'] ?? '') . ':' . ($cfg['puerto'] ?? '')) : '')
            . '<br>Enviado: ' . date('d/m/Y H:i:s') . ' por ' . $h($_SESSION['usuario_nombre'] ?? '') . '</p>';

        $traza = [];
        $t0 = microtime(true);
        $r  = enviarCorreo($para, $asunto, plantillaCorreo($asunto, $contenido), '', '', $traza);
        $ms = round((microtime(true) - $t0) * 1000);
        if ($r !== true) errConTraza("$r ({$ms} ms)", $traza);
        jsonOk(['traza' => $traza], ($metodo === 'archivo' ? 'Correo guardado en storage/correos/' : "Correo enviado a $para") . " ({$ms} ms). Revise también la carpeta de spam.");
    }
    jsonErr('Acción desconocida.');
}

// ── Entorno del servidor ─────────────────────────────────────────────
$deshabilitadas = array_map('trim', explode(',', (string)ini_get('disable_functions')));
$mailDisponible = function_exists('mail') && !in_array('mail', $deshabilitadas, true);
$filas = [
    'Servidor'            => php_uname('n') . ' · ' . PHP_OS_FAMILY,
    'PHP'                 => PHP_VERSION,
    'PHPMailer'           => PHPMailer\PHPMailer\PHPMailer::VERSION,
    'Extensión OpenSSL'   => extension_loaded('openssl') ? 'Activa · ' . OPENSSL_VERSION_TEXT : 'No disponible (SSL/TLS no funcionará)',
    'Función mail()'      => $mailDisponible ? 'Disponible' : 'Deshabilitada',
    'sendmail_path'       => (string)ini_get('sendmail_path') ?: '(vacío)',
];
$config = [
    'Método'     => $metodo ?: '(no configurado)',
    'Servidor'   => $cfg['host'] ?? '',
    'Puerto'     => $cfg['puerto'] ?? '',
    'Seguridad'  => $cfg['seguridad'] ?? '',
    'Usuario'    => $cfg['usuario'] ?? '',
    'Contraseña' => !empty($cfg['clave']) ? 'Configurada' : 'Falta',
    'Remitente'  => trim(($cfg['nombre'] ?? '') . ' <' . ($cfg['remitente'] ?? '') . '>'),
];
if ($metodo !== 'smtp') $config = array_intersect_key($config, array_flip(['Método', 'Remitente']));
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-envelope-circle-check"></i> Prueba de correo</h1>
  <span class="badge bg-dark"><i class="fas fa-code"></i> Desarrollador</span>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-gear"></i> Configuración del correo saliente</div>
      <div class="card-body p-0">
        <table class="table-proscala w-100" style="font-size:.85rem">
          <tbody>
            <?php foreach ($config as $k => $v): ?>
            <tr><th style="width:38%"><?= htmlspecialchars($k) ?></th><td class="font-mono"><?= htmlspecialchars((string)$v) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-transparent" style="font-size:.78rem;">
        <span class="text-muted"><i class="fas fa-info-circle"></i> Se lee de <code>proscala_config.php</code> → <code>'correo'</code>.</span>
        <?php if ($metodo === 'smtp'): ?>
        <button class="btn btn-sm btn-outline-primary float-end" id="btn-conexion" onclick="probarConexion()"><i class="fas fa-plug"></i> Probar conexión</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-server"></i> Entorno del servidor</div>
      <div class="card-body p-0">
        <table class="table-proscala w-100" style="font-size:.85rem">
          <tbody>
            <?php foreach ($filas as $k => $v): ?>
            <tr><th style="width:38%"><?= htmlspecialchars($k) ?></th><td class="font-mono" style="word-break:break-all"><?= htmlspecialchars($v) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if (!$metodo): ?>
<div class="alert alert-danger"><i class="fas fa-triangle-exclamation"></i> El correo saliente no está configurado en este servidor. Complete <code>'correo'</code> en <code>proscala_config.php</code> (vea <code>config/credenciales.ejemplo.php</code>).</div>
<?php else: ?>
<div class="card mb-3">
  <div class="card-header"><i class="fas fa-paper-plane"></i> Enviar correo de prueba</div>
  <div class="card-body">
    <form id="form-prueba" onsubmit="event.preventDefault(); enviarPrueba();">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="enviar">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Para *</label><input type="email" name="para" class="form-control" value="<?= htmlspecialchars($_SESSION['usuario_email'] ?? '') ?>" required></div>
        <div class="col-md-6"><label class="form-label">Asunto</label><input type="text" name="asunto" class="form-control" value="Prueba de correo de Proscala"></div>
        <div class="col-12"><label class="form-label">Mensaje adicional</label><textarea name="mensaje" class="form-control" rows="2" placeholder="Opcional"></textarea></div>
      </div>
      <div class="d-flex justify-content-between align-items-center mt-3">
        <span class="text-muted" style="font-size:.78rem;"><?= $metodo === 'archivo' ? '<i class="fas fa-folder"></i> Método \'archivo\': el correo no se envía, se guarda en <code>storage/correos/</code>.' : '<i class="fas fa-eye-slash"></i> El diálogo SMTP oculta el usuario y la contraseña.' ?></span>
        <button type="submit" class="btn-proscala" id="btn-enviar"><i class="fas fa-paper-plane"></i> Enviar prueba</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<div class="card mb-3 d-none" id="card-traza">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="fas fa-terminal"></i> Resultado</span>
    <button class="btn btn-sm btn-outline-secondary py-0" onclick="copiarTraza()"><i class="fas fa-copy"></i> Copiar</button>
  </div>
  <div class="card-body">
    <div id="traza-msg" class="alert py-2 mb-2"></div>
    <pre id="traza" class="font-mono mb-0" style="font-size:.75rem;max-height:420px;overflow:auto;background:var(--bs-tertiary-bg,#f6f8fa);padding:.75rem;border-radius:8px;white-space:pre-wrap;word-break:break-all"></pre>
  </div>
</div>
<?php
$extraJs = <<<'JS'

// post() lanza una excepción con error; aquí se necesita también la traza de la respuesta fallida
async function pedirPrueba(fd, boton){
  const b=document.getElementById(boton), html=b.innerHTML;
  b.disabled=true; b.innerHTML='<i class="fas fa-spinner fa-spin"></i> Probando…';
  try {
    const res=await fetch('prueba_correo.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
    let r; try { r=await res.json(); } catch(e){ r={success:false,message:'Respuesta inesperada del servidor (HTTP '+res.status+').'}; }
    mostrarTraza(r);
  } catch(e){ mostrarTraza({success:false,message:e.message}); }
  finally { b.disabled=false; b.innerHTML=html; }
}
function mostrarTraza(r){
  document.getElementById('card-traza').classList.remove('d-none');
  const m=document.getElementById('traza-msg');
  m.className='alert py-2 mb-2 alert-'+(r.success?'success':'danger');
  m.textContent=r.message||'';
  document.getElementById('traza').textContent=(r.traza||[]).join('\n')||'(sin detalle)';
  Toast.show(r.message||'', r.success?'success':'error');
}
function enviarPrueba(){ pedirPrueba(new FormData(document.getElementById('form-prueba')),'btn-enviar'); }
function probarConexion(){
  const fd=new FormData(); fd.append('csrf_token',document.querySelector('#form-prueba [name=csrf_token]').value); fd.append('_action','conexion');
  pedirPrueba(fd,'btn-conexion');
}
function copiarTraza(){
  navigator.clipboard.writeText(document.getElementById('traza-msg').textContent+'\n\n'+document.getElementById('traza').textContent)
    .then(()=>Toast.show('Resultado copiado.','success'));
}

JS;
include __DIR__ . '/includes/footer.php';
?>

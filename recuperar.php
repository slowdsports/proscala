<?php
// Restablecer la contraseña por correo:
//   1. El usuario indica el RTN de su empresa y su correo → se envía un enlace de un solo uso
//      (válido RECUPERACION_MINUTOS). La respuesta es la misma exista o no el usuario.
//   2. El enlace abre esta página con ?token= → define la nueva contraseña.
// Los enlaces viven en la base central (recuperaciones) y solo se guarda su hash.
// Restablecer NO desbloquea a un usuario bloqueado por intentos fallidos (lo hace un
// Administrador), salvo al super administrador, que no tendría quién lo desbloquee.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/correo.php';

const RECUPERACION_MINUTOS = 60;
const RECUPERACION_POR_IP = 5;       // solicitudes por IP cada 15 minutos
const RECUPERACION_POR_CORREO = 3;   // enlaces por usuario cada hora

if (isset($_SESSION['usuario_id'], $_SESSION['empresa_id'])) { header('Location: ' . BASE_URL . paginaInicio()); exit; }

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$error = $exito = '';
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');

/** Enlace vigente (no usado ni vencido) a partir del token recibido, con su empresa. */
function enlaceVigente(PDO $pdoMaster, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $s = $pdoMaster->prepare("SELECT r.*, e.nombre AS empresa, e.db_nombre, e.activo AS empresa_activa
        FROM recuperaciones r JOIN empresas e ON e.id = r.empresa_id
        WHERE r.token_hash = ? AND r.usado_en IS NULL AND r.expira > NOW()");
    $s->execute([hash('sha256', $token)]);
    return $s->fetch() ?: null;
}

// ── Paso 1: solicitar el enlace ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'solicitar') {
    verifyCsrf();
    $rtn   = normalizarRtn($_POST['rtn'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $s = $pdoMaster->prepare("SELECT COUNT(*) FROM log_accesos WHERE ip = ? AND motivo LIKE 'Restablecer%' AND created_at > NOW() - INTERVAL 15 MINUTE");
    $s->execute([$ip]);
    if (!$rtn || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingrese el RTN de su empresa y su correo electrónico.';
    } elseif ($s->fetchColumn() >= RECUPERACION_POR_IP) {
        $error = 'Demasiadas solicitudes desde esta conexión. Espere 15 minutos e intente de nuevo.';
    } else {
        $pdoMaster->prepare("INSERT INTO log_accesos (rtn, email, ip, motivo) VALUES (?,?,?,?)")
            ->execute([mb_substr($rtn, 0, 30), mb_substr($email, 0, 150), $ip, 'Restablecer contraseña: solicitud']);
        // Siempre el mismo mensaje: no revela qué correos existen
        $exito = 'Si los datos corresponden a un usuario activo, en unos minutos recibirá un correo con un enlace para crear una nueva contraseña. '
               . 'El enlace vence en ' . RECUPERACION_MINUTOS . ' minutos. Revise también la carpeta de correo no deseado.';

        $s = $pdoMaster->prepare("SELECT e.id, e.nombre, e.db_nombre FROM empresas e JOIN accesos a ON a.empresa_id = e.id
                                  WHERE e.rtn = ? AND e.activo = 1 AND a.email = ?");
        $s->execute([$rtn, $email]);
        $emp = $s->fetch();
        $u = null;
        if ($emp) {
            $pdoEmp = conectarBD($emp['db_nombre']);
            $s = $pdoEmp->prepare("SELECT id, nombre, email FROM usuarios WHERE email = ? AND activo = 1");
            $s->execute([$email]);
            $u = $s->fetch();
        }
        if ($u) {
            $s = $pdoMaster->prepare("SELECT COUNT(*) FROM recuperaciones WHERE email = ? AND empresa_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
            $s->execute([$email, $emp['id']]);
            if ($s->fetchColumn() < RECUPERACION_POR_CORREO) {
                $nuevo = bin2hex(random_bytes(32));
                // Un solo enlace vigente por usuario: los anteriores dejan de servir
                $pdoMaster->prepare("UPDATE recuperaciones SET usado_en = NOW() WHERE email = ? AND empresa_id = ? AND usado_en IS NULL")->execute([$email, $emp['id']]);
                $pdoMaster->prepare("INSERT INTO recuperaciones (empresa_id, email, token_hash, expira, ip) VALUES (?,?,?, NOW() + INTERVAL " . RECUPERACION_MINUTOS . " MINUTE, ?)")
                    ->execute([$emp['id'], $email, hash('sha256', $nuevo), $ip]);
                $h = fn($x) => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
                $html = plantillaCorreo('Restablecer su contraseña',
                    '<p>Hola ' . $h($u['nombre']) . ',</p>'
                    . '<p>Recibimos una solicitud para crear una nueva contraseña de su usuario en <strong>' . $h($emp['nombre']) . '</strong>.</p>'
                    . '<p>El enlace es válido por ' . RECUPERACION_MINUTOS . ' minutos y se puede usar una sola vez.</p>'
                    . '<p>Si usted no lo pidió, ignore este correo: su contraseña actual sigue funcionando.</p>',
                    'Crear nueva contraseña', urlAbsoluta('recuperar.php?token=' . $nuevo));
                $envio = enviarCorreo($u['email'], 'Proscala: restablecer su contraseña', $html);
                registrarEvento($pdoEmp, 'RECUPERAR', ['usuario_id' => $u['id'], 'email' => $email, 'modulo' => 'recuperar.php',
                    'tabla' => 'usuarios', 'registro_id' => $u['id'], 'resultado' => $envio === true ? 'ok' : 'error',
                    'detalle' => $envio === true ? 'Solicitó restablecer su contraseña: enlace enviado por correo' : 'No se pudo enviar el enlace: ' . $envio]);
                if ($envio !== true) { $exito = ''; $error = $envio; }
            }
        }
    }
}

// ── Paso 2: nueva contraseña con el enlace ──────────────────────────
$enlace = $token !== '' ? enlaceVigente($pdoMaster, $token) : null;
if ($token !== '' && !$enlace && !$exito) {
    $error = 'El enlace no es válido o ya venció (dura ' . RECUPERACION_MINUTOS . ' minutos y sirve una sola vez). Solicite uno nuevo.';
    $token = '';
}
if ($enlace && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'restablecer') {
    verifyCsrf();
    $nueva = (string)($_POST['password'] ?? '');
    if (mb_strlen($nueva) < MIN_PASSWORD) {
        $error = 'La contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres.';
    } elseif (!preg_match('/[A-Za-zÁÉÍÓÚÑáéíóúñ]/u', $nueva) || !preg_match('/\d/', $nueva)) {
        $error = 'La contraseña debe combinar letras y números.';
    } elseif ($nueva !== ($_POST['password_conf'] ?? '')) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (!$enlace['empresa_activa']) {
        $error = 'La empresa está desactivada. Contacte al administrador de Proscala.';
    } else {
        $pdoEmp = conectarBD($enlace['db_nombre']);
        $s = $pdoEmp->prepare("SELECT id, bloqueado_en FROM usuarios WHERE email = ? AND activo = 1");
        $s->execute([$enlace['email']]);
        $u = $s->fetch();
        if (!$u) {
            $error = 'El usuario ya no está activo. Contacte al administrador de su empresa.';
        } else {
            $s = $pdoMaster->prepare("SELECT superadmin FROM accesos WHERE email = ? AND empresa_id = ?");
            $s->execute([$enlace['email'], $enlace['empresa_id']]);
            $esSuper = (bool)$s->fetchColumn();
            $pdoEmp->prepare("UPDATE usuarios SET password = ?, debe_cambiar_password = 0, intentos_fallidos = 0"
                . ($esSuper ? ', bloqueado_en = NULL' : '') . " WHERE id = ?")
                ->execute([password_hash($nueva, PASSWORD_BCRYPT), $u['id']]);
            $pdoMaster->prepare("UPDATE recuperaciones SET usado_en = NOW() WHERE email = ? AND empresa_id = ? AND usado_en IS NULL")
                ->execute([$enlace['email'], $enlace['empresa_id']]);
            $sigueBloqueado = $u['bloqueado_en'] && !$esSuper;
            registrarEvento($pdoEmp, 'RECUPERAR', ['usuario_id' => $u['id'], 'email' => $enlace['email'], 'modulo' => 'recuperar.php',
                'tabla' => 'usuarios', 'registro_id' => $u['id'],
                'detalle' => 'Contraseña restablecida con el enlace enviado por correo' . ($sigueBloqueado ? ' (el usuario sigue bloqueado)' : '') . '. IP ' . $ip]);
            session_regenerate_id(true);
            $_SESSION['aviso_login'] = $sigueBloqueado
                ? 'Su contraseña se cambió, pero su usuario sigue bloqueado por intentos fallidos: pida a un administrador de su empresa que lo desbloquee.'
                : 'Su contraseña se cambió. Ingrese con la nueva contraseña.';
            header('Location: ' . BASE_URL . 'index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="referrer" content="no-referrer">
  <title>Proscala - Restablecer contraseña</title>
  <link rel="stylesheet" href="<?= recurso('assets/vendor/fontawesome/css/all.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/vendor/bootstrap/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/css/proscala.css') ?>">
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">
    <img src="<?= BASE_URL ?>assets/images/proscala.png" alt="Proscala" class="logo mb-2" style="height:70px;">
    <p class="org-name">Restablecer contraseña</p>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 text-start" style="font-size:.85rem;"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($exito): ?>
      <div class="alert alert-success py-2 text-start" style="font-size:.85rem;"><i class="fas fa-envelope-circle-check"></i> <?= htmlspecialchars($exito) ?></div>
      <a href="<?= BASE_URL ?>index.php" class="btn-login d-block text-decoration-none"><i class="fas fa-arrow-left"></i> Volver al inicio de sesión</a>

    <?php elseif ($enlace): ?>
      <p class="text-muted" style="font-size:.82rem;">
        Cree una nueva contraseña para <strong><?= htmlspecialchars($enlace['email']) ?></strong> en <strong><?= htmlspecialchars($enlace['empresa']) ?></strong>.
      </p>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="_action" value="restablecer">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="mb-3 text-start">
          <label class="form-label"><i class="fas fa-lock text-blue"></i> Nueva contraseña</label>
          <input type="password" name="password" class="form-control" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password" autofocus>
          <div class="form-text">Al menos <?= MIN_PASSWORD ?> caracteres, con letras y números.</div>
        </div>
        <div class="mb-3 text-start">
          <label class="form-label"><i class="fas fa-lock text-blue"></i> Repita la contraseña</label>
          <input type="password" name="password_conf" class="form-control" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password">
        </div>
        <button type="submit" class="btn-login"><i class="fas fa-check"></i> Guardar nueva contraseña</button>
      </form>

    <?php else: ?>
      <p class="text-muted" style="font-size:.82rem;">Indique el RTN de su empresa y su correo. Le enviaremos un enlace para crear una nueva contraseña.</p>
      <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="_action" value="solicitar">
        <div class="mb-3 text-start">
          <label class="form-label"><i class="fas fa-building text-blue"></i> RTN de la empresa</label>
          <select id="rtn-select" class="form-select mb-2" style="display:none"></select>
          <input type="text" name="rtn" id="rtn" class="form-control font-mono" inputmode="numeric" placeholder="14 dígitos"
                 value="<?= htmlspecialchars($_POST['rtn'] ?? '') ?>" required>
        </div>
        <div class="mb-3 text-start">
          <label class="form-label"><i class="fas fa-envelope text-blue"></i> Correo electrónico</label>
          <input type="email" name="email" id="email" class="form-control" placeholder="usuario@empresa.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        <button type="submit" class="btn-login"><i class="fas fa-paper-plane"></i> Enviar enlace</button>
      </form>
      <p class="mt-3 mb-0" style="font-size:.82rem;"><a href="<?= BASE_URL ?>index.php"><i class="fas fa-arrow-left"></i> Volver al inicio de sesión</a></p>
    <?php endif; ?>
  </div>
</div>
<script>
// RTN: los usados antes en este navegador (los guarda includes/header.php), como en el login
(function () {
  const input = document.getElementById('rtn'), sel = document.getElementById('rtn-select');
  if (!input || !sel) return;
  let recientes = [];
  try { recientes = JSON.parse(localStorage.getItem('proscala-rtns') || '[]'); } catch (_) {}
  if (!recientes.length) return;
  recientes.forEach(r => sel.add(new Option(r.empresa + ' — ' + r.rtn, r.rtn)));
  sel.add(new Option('Otro RTN…', ''));
  sel.style.display = '';
  const actual = input.value.replace(/\D/g, '') || recientes[0].rtn;
  sel.value = recientes.some(r => r.rtn === actual) ? actual : '';
  const sincronizar = () => {
    const otro = sel.value === '';
    input.style.display = otro ? '' : 'none';
    if (!otro) input.value = sel.value;
    else if (recientes.some(r => r.rtn === input.value)) input.value = '';
  };
  sel.addEventListener('change', sincronizar);
  sincronizar();
  // El correo escrito en el login viaja en la dirección (?email=) para no escribirlo dos veces
  const e = new URLSearchParams(location.search).get('email');
  if (e && !document.getElementById('email').value) document.getElementById('email').value = e;
})();
</script>
</body>
</html>

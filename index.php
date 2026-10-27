<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/auth.php';
if (isset($_SESSION['usuario_id'], $_SESSION['empresa_id'])) {
    header('Location: ' . BASE_URL . paginaInicio());
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rtn      = normalizarRtn($_POST['rtn'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($rtn && $email && $password) {
        // El RTN indica la empresa (y su base de datos); el correo debe estar asociado a ella
        $stmt = $pdoMaster->prepare("SELECT id AS empresa_id, nombre AS empresa, rtn, db_nombre, activo FROM empresas WHERE rtn = ?");
        $stmt->execute([$rtn]);
        $acc = $stmt->fetch();

        $u = null;
        $motivo = '';
        if (!$acc) {
            // RTN inexistente: no hay bitácora de empresa donde anotarlo, queda en la central
            $pdoMaster->prepare("INSERT INTO log_accesos (rtn, email, ip, motivo) VALUES (?,?,?,?)")
                ->execute([mb_substr($rtn, 0, 30), mb_substr($email, 0, 150), $_SERVER['REMOTE_ADDR'] ?? '', 'RTN no registrado']);
        } else {
            $stmt = $pdoMaster->prepare("SELECT superadmin FROM accesos WHERE email = ? AND empresa_id = ?");
            $stmt->execute([$email, $acc['empresa_id']]);
            $superadmin = $stmt->fetchColumn();

            $pdo  = conectarBD($acc['db_nombre']);
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $u = $stmt->fetch() ?: null;

            if ($superadmin === false || !$u)                     $motivo = 'Correo no registrado en la empresa';
            elseif ($u['bloqueado_en'])                           $motivo = 'Usuario bloqueado';
            elseif (!password_verify($password, $u['password'])) $motivo = 'Contraseña incorrecta';
            elseif (!$u['activo'])                                $motivo = 'Usuario inactivo';
            elseif (!$acc['activo'])                              $motivo = 'Empresa desactivada';
            $acc['superadmin'] = (int)$superadmin;
        }

        // Contraseña incorrecta: cuenta el intento; al llegar al límite el usuario queda bloqueado
        $restantes = null;
        if ($motivo === 'Contraseña incorrecta') {
            $pdo->prepare("UPDATE usuarios SET intentos_fallidos = intentos_fallidos + 1,
                               bloqueado_en = IF(intentos_fallidos >= ?, NOW(), NULL) WHERE id = ?")
                ->execute([MAX_INTENTOS_LOGIN, $u['id']]);
            $s = $pdo->prepare("SELECT intentos_fallidos, bloqueado_en FROM usuarios WHERE id = ?");
            $s->execute([$u['id']]);
            $estado = $s->fetch();
            $restantes = MAX_INTENTOS_LOGIN - (int)$estado['intentos_fallidos'];
            $motivo .= " (intento {$estado['intentos_fallidos']} de " . MAX_INTENTOS_LOGIN . ')';
            if ($estado['bloqueado_en']) {
                $motivo = 'Usuario bloqueado';
                registrarEvento($pdo, 'BLOQUEO', ['usuario_id' => $u['id'], 'email' => $email, 'modulo' => 'index.php', 'resultado' => 'error',
                    'tabla' => 'usuarios', 'registro_id' => $u['id'],
                    'detalle' => 'Bloqueado tras ' . MAX_INTENTOS_LOGIN . ' contraseñas incorrectas seguidas. IP ' . ($_SERVER['REMOTE_ADDR'] ?? '')]);
            }
        }

        if ($acc && $motivo !== '') {
            registrarEvento($pdo, 'LOGIN_FALLIDO', [
                'usuario_id' => $u['id'] ?? null, 'email' => $email, 'modulo' => 'index.php',
                'resultado' => 'error', 'detalle' => $motivo,
            ]);
        }

        if ($acc && $motivo === '') {
            $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0 WHERE id = ?")->execute([$u['id']]);
            session_regenerate_id(true);
            $_SESSION['usuario_id']     = $u['id'];
            $_SESSION['usuario_nombre'] = $u['nombre'];
            $_SESSION['usuario_rol']    = $u['rol'];
            $_SESSION['usuario_email']  = $u['email'];
            $_SESSION['usuario_foto']   = $u['foto'] ?? '';
            $_SESSION['empresa_id']     = (int)$acc['empresa_id'];
            $_SESSION['empresa_db']     = $acc['db_nombre'];
            $_SESSION['empresa_nombre'] = $acc['empresa'];
            $_SESSION['es_superadmin']  = (bool)$acc['superadmin'];
            // includes/header.php guarda este RTN en el navegador para precargarlo la próxima vez
            $_SESSION['rtn_recordar']   = ['rtn' => $acc['rtn'], 'empresa' => $acc['empresa']];
            cargarPermisos($pdo, $u['rol']);

            $pdo->prepare("UPDATE usuarios SET ultimo_acceso = NOW() WHERE id = ?")->execute([$u['id']]);
            registrarEvento($pdo, 'LOGIN', ['modulo' => 'index.php', 'detalle' => 'Inicio de sesión']);
            // Contraseña inicial (usuarios creados desde Empleados): primero debe cambiarla
            header('Location: ' . BASE_URL . ($u['debe_cambiar_password'] ? 'perfil.php?cambiar=1' : paginaInicio()));
            exit;
        } elseif ($motivo === 'Empresa desactivada') {
            $error = 'La empresa de este usuario está desactivada. Contacte al administrador de Proscala.';
        } elseif ($motivo === 'Usuario bloqueado') {
            $error = 'Su usuario está bloqueado por ' . MAX_INTENTOS_LOGIN . ' intentos fallidos. Pida a un administrador de su empresa que lo desbloquee.';
        } else {
            $error = 'RTN, correo o contraseña incorrectos.'
                . ($restantes !== null && $restantes <= 2 ? " Le queda" . ($restantes === 1 ? '' : 'n') . " $restantes intento" . ($restantes === 1 ? '' : 's') . ' antes de que su usuario se bloquee.' : '');
        }
    } else {
        $error = 'Ingrese el RTN de la empresa, su correo y contraseña.';
    }
}
// Avisos al llegar al login: sesión cerrada por inactividad, contraseña restablecida…
$aviso = $_SESSION['aviso_login'] ?? '';
unset($_SESSION['aviso_login']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Proscala - Iniciar Sesión</title>
  <link rel="stylesheet" href="<?= recurso('assets/vendor/fontawesome/css/all.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/vendor/bootstrap/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/css/proscala.css') ?>">
</head>
<body>
<div class="login-wrapper">
  <div class="login-card">
    <img src="<?= BASE_URL ?>assets/images/proscala.png" alt="Proscala" class="logo mb-2" style="height:70px;">
    <p class="org-name">Proscala</p>
    <p class="text-muted" style="font-size:.78rem;margin-bottom:1.5rem;">
      <strong>Sistema de Administración Empresarial</strong>
    </p>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2" style="font-size:.85rem;">
        <i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php elseif ($aviso): ?>
      <div class="alert alert-info py-2" style="font-size:.85rem;">
        <i class="fas fa-circle-info"></i> <?= htmlspecialchars($aviso) ?>
      </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
      <div class="mb-3 text-start">
        <label class="form-label"><i class="fas fa-building text-blue"></i> RTN de la empresa</label>
        <select id="rtn-select" class="form-select mb-2" style="display:none"></select>
        <input type="text" name="rtn" id="rtn" class="form-control font-mono" inputmode="numeric"
               placeholder="14 dígitos" value="<?= htmlspecialchars($_POST['rtn'] ?? '') ?>" required>
      </div>
      <div class="mb-3 text-start">
        <label class="form-label"><i class="fas fa-envelope text-blue"></i> Correo electrónico</label>
        <input type="email" name="email" id="email" class="form-control" placeholder="usuario@empresa.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="mb-3 text-start">
        <label class="form-label"><i class="fas fa-lock text-blue"></i> Contraseña</label>
        <div class="input-group">
          <input type="password" name="password" id="pwd" class="form-control" placeholder="••••••••" required>
          <button type="button" class="btn btn-outline-secondary" onclick="togglePwd()">
            <i class="fas fa-eye" id="pwd-icon"></i>
          </button>
        </div>
      </div>
      <button type="submit" class="btn-login">
        <i class="fas fa-sign-in-alt"></i> Iniciar Sesión
      </button>
    </form>

    <p class="mt-3 mb-1" style="font-size:.82rem;">
      <a href="<?= BASE_URL ?>recuperar.php" id="lnk-recuperar"><i class="fas fa-key"></i> ¿Olvidó su contraseña?</a>
    </p>
    <p class="text-muted" style="font-size:.72rem;">
      ¿Problemas para acceder? Contacte al administrador del sistema.
    </p>
    <p class="text-muted mb-0" style="font-size:.66rem;">Proscala v<?= APP_VERSION ?></p>
  </div>
</div>
<script>
function togglePwd() {
  const f = document.getElementById('pwd');
  const i = document.getElementById('pwd-icon');
  if (f.type === 'password') { f.type = 'text'; i.className = 'fas fa-eye-slash'; }
  else { f.type = 'password'; i.className = 'fas fa-eye'; }
}

// RTN: se precarga el último usado en este navegador y se puede elegir otro de los
// anteriores o escribir uno nuevo (lista guardada por includes/header.php tras cada
// inicio de sesión correcto, el más reciente primero)
(function () {
  let recientes = [];
  try { recientes = JSON.parse(localStorage.getItem('proscala-rtns') || '[]'); } catch (_) {}
  const input = document.getElementById('rtn');
  const sel   = document.getElementById('rtn-select');

  if (recientes.length) {
    recientes.forEach(r => sel.add(new Option(r.empresa + ' — ' + r.rtn, r.rtn)));
    sel.add(new Option('Otro RTN…', ''));
    sel.style.display = '';
    // Tras un intento fallido se conserva lo escrito; si no, el último RTN usado
    const actual = input.value.replace(/\D/g, '') || recientes[0].rtn;
    sel.value = recientes.some(r => r.rtn === actual) ? actual : '';
    const sincronizar = () => {
      const otro = sel.value === '';
      input.style.display = otro ? '' : 'none';
      if (!otro) input.value = sel.value;
      else if (recientes.some(r => r.rtn === input.value)) input.value = '';
    };
    sel.addEventListener('change', () => { sincronizar(); if (sel.value === '') input.focus(); });
    sincronizar();
  }

  // "¿Olvidó su contraseña?" lleva el correo ya escrito
  const lnk = document.getElementById('lnk-recuperar');
  lnk?.addEventListener('click', () => {
    const e = document.getElementById('email').value.trim();
    if (e) lnk.href = lnk.href.split('?')[0] + '?email=' + encodeURIComponent(e);
  });

  // Foco en el primer campo visible que falte por llenar
  const campos = [input, document.getElementById('email'), document.getElementById('pwd')];
  (campos.find(el => el.offsetParent && !el.value) || campos[2]).focus();
})();
</script>
</body>
</html>

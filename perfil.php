<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Mi Perfil';

$userId = currentUser()['id'];

// ── AJAX ─────────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['_action'] ?? '';

    if ($act === 'datos') {
        $nombre = trim($_POST['nombre'] ?? '');
        $email  = trim($_POST['email']  ?? '');
        if (!$nombre || !$email) jsonErr('Nombre y email son requeridos.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('Email inválido.');
        $s = $pdo->prepare("SELECT email FROM usuarios WHERE id=?");
        $s->execute([$userId]);
        $emailAnterior = $s->fetchColumn();
        if (emailEnUso($pdoMaster, $email, $_SESSION['empresa_id'], $emailAnterior)) jsonErr('Ese correo ya está en uso por otro usuario.');
        try {
            $pdo->prepare("UPDATE usuarios SET nombre=?, email=? WHERE id=?")->execute([$nombre, $email, $userId]);
            $pdoMaster->prepare("UPDATE accesos SET email=? WHERE email=? AND empresa_id=?")->execute([$email, $emailAnterior, $_SESSION['empresa_id']]);
            $_SESSION['usuario_nombre'] = $nombre;
            $_SESSION['usuario_email']  = $email;
            jsonOk(['nombre' => $nombre], 'Datos actualizados correctamente.');
        } catch (PDOException $e) {
            jsonErr($e->getCode() == 23000 ? 'Ese correo ya está en uso por otro usuario.' : $e->getMessage());
        }
    }

    if ($act === 'password') {
        $actual   = $_POST['password_actual'] ?? '';
        $nueva    = $_POST['password_nueva']  ?? '';
        $confirma = $_POST['password_conf']   ?? '';
        if (!$actual || !$nueva) jsonErr('Complete todos los campos.');
        if (mb_strlen($nueva) < MIN_PASSWORD) jsonErr('La nueva contraseña debe tener al menos ' . MIN_PASSWORD . ' caracteres.');
        if ($nueva !== $confirma) jsonErr('Las contraseñas nuevas no coinciden.');
        $stmt = $pdo->prepare("SELECT password FROM usuarios WHERE id=?");
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        if (!password_verify($actual, $hash)) jsonErr('La contraseña actual es incorrecta.');
        if ($nueva === $actual) jsonErr('La nueva contraseña debe ser distinta de la actual.');
        $pdo->prepare("UPDATE usuarios SET password=?, debe_cambiar_password=0 WHERE id=?")->execute([password_hash($nueva, PASSWORD_BCRYPT), $userId]);
        jsonOk([], 'Contraseña actualizada correctamente.');
    }

    if ($act === 'notificar_correo') {
        $activo = ($_POST['activo'] ?? '') === '1' ? 1 : 0;
        $pdo->prepare("UPDATE usuarios SET notificar_correo=? WHERE id=?")->execute([$activo, $userId]);
        jsonOk([], $activo ? 'Recibirá los avisos también por correo.' : 'Ya no recibirá avisos por correo; seguirán en la campana.');
    }

    if ($act === 'foto') {
        if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) jsonErr('No se recibió ningún archivo.');
        $file = $_FILES['foto'];
        $mime = mime_content_type($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed)) jsonErr('Solo se permiten imágenes (JPG, PNG, GIF, WEBP).');
        if ($file['size'] > 5 * 1024 * 1024) jsonErr('La imagen no debe superar 5 MB.');

        $dir = __DIR__ . '/assets/uploads/avatars/';

        // Delete old photo
        $stmt = $pdo->prepare("SELECT foto FROM usuarios WHERE id=?");
        $stmt->execute([$userId]);
        $oldFoto = $stmt->fetchColumn();
        if ($oldFoto && file_exists(__DIR__ . '/' . $oldFoto)) unlink(__DIR__ . '/' . $oldFoto);

        // Load source image
        $src = match($mime) {
            'image/jpeg' => imagecreatefromjpeg($file['tmp_name']),
            'image/png'  => imagecreatefrompng($file['tmp_name']),
            'image/gif'  => imagecreatefromgif($file['tmp_name']),
            'image/webp' => imagecreatefromwebp($file['tmp_name']),
            default      => false,
        };
        if (!$src) jsonErr('No se pudo procesar la imagen.');

        $w = imagesx($src);
        $h = imagesy($src);

        // Center-crop to square
        $side = min($w, $h);
        $srcX = (int)(($w - $side) / 2);
        $srcY = (int)(($h - $side) / 2);

        // Resample to 300×300 JPEG
        $size = 300;
        $dest = imagecreatetruecolor($size, $size);
        imagecopyresampled($dest, $src, 0, 0, $srcX, $srcY, $size, $size, $side, $side);
        imagedestroy($src);

        $fname = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.jpg';
        imagejpeg($dest, $dir . $fname, 88);
        imagedestroy($dest);

        $path = 'assets/uploads/avatars/' . $fname;
        $pdo->prepare("UPDATE usuarios SET foto=? WHERE id=?")->execute([$path, $userId]);
        $_SESSION['usuario_foto'] = $path;
        jsonOk(['foto' => BASE_URL . $path], 'Foto de perfil actualizada.');
    }

    if ($act === 'quitar_foto') {
        $stmt = $pdo->prepare("SELECT foto FROM usuarios WHERE id=?");
        $stmt->execute([$userId]);
        $oldFoto = $stmt->fetchColumn();
        if ($oldFoto && file_exists(__DIR__ . '/' . $oldFoto)) unlink(__DIR__ . '/' . $oldFoto);
        $pdo->prepare("UPDATE usuarios SET foto=NULL WHERE id=?")->execute([$userId]);
        $_SESSION['usuario_foto'] = '';
        jsonOk(['logo' => BASE_URL . 'assets/images/proscala.png'], 'Foto eliminada.');
    }

    jsonErr('Acción desconocida.');
}

// ── Load profile ──────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT id, nombre, email, rol, foto, created_at, ultimo_acceso, debe_cambiar_password, notificar_correo FROM usuarios WHERE id=?");
$stmt->execute([$userId]);
$me = $stmt->fetch();

$fotoUrl = $me['foto'] ? BASE_URL . $me['foto'] : BASE_URL . 'assets/images/proscala.png';
$rolColor = $me['rol'] === 'admin' ? 'danger' : 'primary';

include __DIR__ . '/includes/header.php';
?>

<style>
.perfil-foto-wrap {
  width: 150px; height: 150px;
  border-radius: 50%; overflow: hidden;
  position: relative; cursor: pointer;
  border: 3px solid var(--border);
  transition: border-color .25s, box-shadow .25s;
  flex-shrink: 0;
}
.perfil-foto-wrap:hover { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(var(--primary-rgb),.15); }
.perfil-foto { width: 100%; height: 100%; object-fit: cover; display: block; }
.perfil-foto-overlay {
  position: absolute; inset: 0;
  background: rgba(0,0,0,.48);
  color: #fff; font-size: .72rem;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: .25rem;
  opacity: 0; transition: opacity .2s;
}
.perfil-foto-wrap:hover .perfil-foto-overlay { opacity: 1; }
.perfil-info-row { display: flex; align-items: center; gap: .5rem; font-size: .82rem; color: var(--text-2); margin-bottom: .35rem; }
.perfil-info-row i { width: 14px; color: var(--text-3); }
</style>

<div class="page-header">
  <h1><i class="fas fa-circle-user"></i> Mi Perfil</h1>
</div>

<div class="row g-4">

  <!-- ── Columna izquierda: foto + info ── -->
  <div class="col-md-4 col-lg-3">
    <div class="card text-center">
      <div class="card-body py-4">

        <div class="perfil-foto-wrap mx-auto mb-3" onclick="document.getElementById('inp-foto').click()" title="Cambiar foto de perfil">
          <img id="perfil-img" src="<?= $fotoUrl ?>" alt="Foto de perfil" class="perfil-foto">
          <div class="perfil-foto-overlay">
            <i class="fas fa-camera fa-lg"></i>
            <span>Cambiar foto</span>
          </div>
        </div>
        <input type="file" id="inp-foto" accept="image/*" style="display:none">

        <h5 class="fw-bold mb-1" id="perfil-nombre-display"><?= htmlspecialchars($me['nombre']) ?></h5>
        <span class="badge bg-<?= $rolColor ?> mb-3"><?= htmlspecialchars(rolLabel($me['rol'])) ?></span>

        <div class="text-start px-2">
          <div class="perfil-info-row"><i class="fas fa-envelope"></i> <?= htmlspecialchars($me['email']) ?></div>
          <?php if ($me['ultimo_acceso']): ?>
          <div class="perfil-info-row"><i class="fas fa-clock"></i> Acceso: <?= date('d/m/Y H:i', strtotime($me['ultimo_acceso'])) ?></div>
          <?php endif; ?>
          <div class="perfil-info-row"><i class="fas fa-calendar"></i> Miembro desde <?= date('M Y', strtotime($me['created_at'])) ?></div>
        </div>

        <button class="btn btn-outline-secondary btn-sm mt-3 w-100" onclick="document.getElementById('inp-foto').click()">
          <i class="fas fa-camera"></i> Cambiar foto
        </button>
        <?php if ($me['foto']): ?>
        <button class="btn btn-outline-danger btn-sm mt-2 w-100" onclick="quitarFoto()">
          <i class="fas fa-trash"></i> Quitar foto
        </button>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- ── Columna derecha: formularios ── -->
  <div class="col-md-8 col-lg-9">

    <!-- Datos personales -->
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-user-pen"></i> Información Personal</div>
      <div class="card-body">
        <form id="form-datos">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="_action" value="datos">
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label">Nombre completo *</label>
              <input type="text" name="nombre" class="form-control" value="<?= htmlspecialchars($me['nombre']) ?>" required>
            </div>
            <div class="col-md-5">
              <label class="form-label">Correo electrónico *</label>
              <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($me['email']) ?>" required>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar cambios</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Notificaciones -->
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-bell"></i> Notificaciones</div>
      <div class="card-body">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="chk-notificar-correo" <?= $me['notificar_correo'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="chk-notificar-correo">Recibir los avisos también por correo</label>
        </div>
        <div class="text-muted mt-1" style="font-size:.8rem;">
          Procesos que esperan su revisión o autorización, y los suyos cuando se revisan, autorizan, rechazan o corrigen.
          Siempre aparecen en la campana <i class="fas fa-bell"></i> de la barra superior.
        </div>
      </div>
    </div>

    <!-- Cambiar contraseña -->
    <?php if ($me['debe_cambiar_password']): ?>
    <div class="alert alert-warning py-2" style="font-size:.85rem;">
      <i class="fas fa-key"></i> <strong>Debe cambiar su contraseña inicial</strong> antes de usar el sistema.
      Su contraseña actual es su número de identidad, sin guiones.
    </div>
    <?php endif; ?>
    <div class="card" id="card-password">
      <div class="card-header"><i class="fas fa-lock"></i> Cambiar Contraseña</div>
      <div class="card-body">
        <form id="form-pass" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="_action" value="password">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Contraseña actual *</label>
              <div class="input-group">
                <input type="password" name="password_actual" class="form-control" required autocomplete="current-password">
                <button type="button" class="btn btn-outline-secondary" tabindex="-1" onclick="togglePass(this)"><i class="fas fa-eye"></i></button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Nueva contraseña *</label>
              <div class="input-group">
                <input type="password" name="password_nueva" class="form-control" required minlength="<?= MIN_PASSWORD ?>" autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" tabindex="-1" onclick="togglePass(this)"><i class="fas fa-eye"></i></button>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Confirmar nueva contraseña *</label>
              <div class="input-group">
                <input type="password" name="password_conf" class="form-control" required autocomplete="new-password">
                <button type="button" class="btn btn-outline-secondary" tabindex="-1" onclick="togglePass(this)"><i class="fas fa-eye"></i></button>
              </div>
            </div>
          </div>
          <div class="mt-3">
            <button type="submit" class="btn-proscala"><i class="fas fa-key"></i> Actualizar contraseña</button>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php
$csrfJs = json_encode($csrf);
$extraJs = "
const _csrfPerfil = {$csrfJs};

// ── Subir foto automáticamente al seleccionar ──
document.getElementById('inp-foto').addEventListener('change', function(){
  const file = this.files[0];
  if (!file) return;
  if (file.size > 2*1024*1024) { Toast.show('La imagen no debe superar 2 MB','danger'); this.value=''; return; }

  // Preview inmediato
  const reader = new FileReader();
  reader.onload = e => document.getElementById('perfil-img').src = e.target.result;
  reader.readAsDataURL(file);

  // Upload
  const fd = new FormData();
  fd.append('csrf_token', _csrfPerfil);
  fd.append('_action', 'foto');
  fd.append('foto', file);
  fetch('perfil.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body: fd })
    .then(r => r.json())
    .then(r => {
      if (r.success) Toast.show(r.message,'success');
      else { Toast.show(r.message,'danger'); document.getElementById('perfil-img').src = document.getElementById('perfil-img').dataset.original || ''; }
    });
});

function togglePass(btn) {
  const inp = btn.previousElementSibling;
  const ico = btn.querySelector('i');
  if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fas fa-eye-slash'; }
  else { inp.type = 'password'; ico.className = 'fas fa-eye'; }
}

async function quitarFoto() {
  if (!await confirmar('¿Quitar la foto de perfil y volver al logo de Proscala?', { titulo: 'Quitar foto', aceptar: 'Quitar', peligro: true })) return;
  const fd = new FormData();
  fd.append('csrf_token', _csrfPerfil);
  fd.append('_action', 'foto');
  // Upload a dummy flag to remove — we handle via empty file by resetting to logo
  // Actually just re-use the datos action with a clear-foto flag
  fetch('perfil.php?quitar_foto=1', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest',
    'X-Csrf-Token': _csrfPerfil }, body: new URLSearchParams({csrf_token:_csrfPerfil, _action:'quitar_foto'}) })
    .then(r => r.json()).then(r => {
      if (r.success) { document.getElementById('perfil-img').src = r.logo; Toast.show(r.message,'success'); setTimeout(()=>Nav.recargar(),900); }
      else Toast.show(r.message,'danger');
    });
}

document.getElementById('chk-notificar-correo').addEventListener('change', async function(){
  const fd = new FormData();
  fd.append('csrf_token', _csrfPerfil);
  fd.append('_action', 'notificar_correo');
  fd.append('activo', this.checked ? '1' : '0');
  try { const r = await post('perfil.php', fd); Toast.show(r.message, 'success'); }
  catch (e) { this.checked = !this.checked; Toast.show(e.message, 'error'); }
});

bindAjaxForm('form-datos', r => {
  Toast.show(r.message, 'success');
  if (r.nombre) document.getElementById('perfil-nombre-display').textContent = r.nombre;
});
bindAjaxForm('form-pass', r => {
  Toast.show(r.message,'success'); document.getElementById('form-pass').reset();
  " . ($me['debe_cambiar_password'] ? "setTimeout(()=>Nav.ir('" . BASE_URL . "'), 1200);" : '') . "
});
";
include __DIR__ . '/includes/footer.php';
?>

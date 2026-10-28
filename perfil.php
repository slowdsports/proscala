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

    // ── Delegar mis aprobaciones (config/aprobaciones.php) ──
    if ($act === 'delegar') {
        if (!tieneAccesoTotal() && empty($_SESSION['rol_aprueba'])) jsonErr('Su puesto no aprueba procesos: no tiene aprobaciones que delegar.');
        $delegado = (int)($_POST['delegado_id'] ?? 0);
        $desde = (string)($_POST['desde'] ?? '');
        $hasta = (string)($_POST['hasta'] ?? '');
        $motivo = mb_substr(trim((string)($_POST['motivo'] ?? '')), 0, 200);
        $fecha = fn($f) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && strtotime($f);
        if (!$fecha($desde) || !$fecha($hasta)) jsonErr('Indique las fechas de inicio y fin.');
        if ($desde < date('Y-m-d')) jsonErr('La delegación no puede empezar en una fecha pasada.');
        if ($hasta < $desde) jsonErr('La fecha de fin debe ser igual o posterior a la de inicio.');
        if ((strtotime($hasta) - strtotime($desde)) / 86400 > 90) jsonErr('Una delegación puede durar como máximo 90 días.');
        $s = $pdo->prepare("SELECT u.id, u.nombre, u.rol FROM usuarios u WHERE u.id = ? AND u.activo = 1 AND u.id <> ?");
        $s->execute([$delegado, $userId]);
        if (!$d = $s->fetch()) jsonErr('Elija a la persona en quien delega.');
        if (!empty(roles()[$d['rol']]['solo_propios'])) jsonErr("{$d['nombre']} solo ve sus propias solicitudes: elija a alguien con acceso a los módulos.");
        $s = $pdo->prepare("SELECT COUNT(*) FROM delegaciones WHERE usuario_id = ? AND cancelada = 0 AND desde <= ? AND hasta >= ?");
        $s->execute([$userId, $hasta, $desde]);
        if ($s->fetchColumn()) jsonErr('Ya tiene una delegación en esas fechas. Cancélela primero si quiere cambiarla.');
        $pdo->prepare("INSERT INTO delegaciones (usuario_id, delegado_id, desde, hasta, motivo) VALUES (?,?,?,?,?)")
            ->execute([$userId, $d['id'], $desde, $hasta, $motivo ?: null]);
        $idDel = (int)$pdo->lastInsertId();
        $periodo = 'del ' . fmtFecha($desde) . ' al ' . fmtFecha($hasta);
        registrarAuditoria($pdo, 'INSERT', 'delegaciones', $idDel, "Delegó sus aprobaciones en {$d['nombre']} $periodo" . ($motivo ? " ($motivo)" : ''));
        avisar($pdo, [$d['id']], 'delegacion', 'Le delegaron aprobaciones',
            ($_SESSION['usuario_nombre'] ?? 'Un usuario') . " le delegó sus aprobaciones $periodo" . ($motivo ? " ($motivo)" : '')
            . '. En esas fechas podrá revisar y autorizar los procesos que le corresponden a esa persona.', 'perfil.php', 'delegaciones', $idDel, 'Le delegaron aprobaciones');
        jsonOk(['id' => $idDel], "Sus aprobaciones quedan delegadas en {$d['nombre']} $periodo.");
    }
    if ($act === 'cancelar_delegacion') {
        $s = $pdo->prepare("SELECT d.id, d.delegado_id, u.nombre FROM delegaciones d JOIN usuarios u ON u.id = d.delegado_id
                            WHERE d.id = ? AND d.usuario_id = ? AND d.cancelada = 0");
        $s->execute([(int)($_POST['id'] ?? 0), $userId]);
        if (!$d = $s->fetch()) jsonErr('Delegación no encontrada.');
        $pdo->prepare("UPDATE delegaciones SET cancelada = 1 WHERE id = ?")->execute([$d['id']]);
        registrarAuditoria($pdo, 'UPDATE', 'delegaciones', (int)$d['id'], "Canceló la delegación de aprobaciones en {$d['nombre']}");
        avisar($pdo, [$d['delegado_id']], 'delegacion', 'Delegación cancelada',
            ($_SESSION['usuario_nombre'] ?? 'Un usuario') . ' canceló la delegación de sus aprobaciones.', 'perfil.php', 'delegaciones', (int)$d['id'], 'Delegación cancelada');
        jsonOk([], 'Delegación cancelada.');
    }

    if ($act === 'foto') {
        if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) jsonErr('No se recibió ningún archivo.');
        $file = $_FILES['foto'];
        $mime = mime_content_type($file['tmp_name']);
        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowed)) jsonErr('Solo se permiten imágenes (JPG, PNG, GIF, WEBP).');
        if ($file['size'] > 5 * 1024 * 1024) jsonErr('La imagen no debe superar 5 MB.');

        // La carpeta de fotos no viaja con el código (assets/uploads es de datos): se crea si falta
        $dir = __DIR__ . '/assets/uploads/avatars/';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) jsonErr('No se pudo preparar la carpeta de fotos en el servidor. Avise al administrador.');

        $base = 'u' . $userId . '_' . bin2hex(random_bytes(6));
        $creadora = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng',
                     'image/gif' => 'imagecreatefromgif', 'image/webp' => 'imagecreatefromwebp'][$mime];
        if (function_exists($creadora) && ($src = @$creadora($file['tmp_name']))) {
            // Recorte cuadrado al centro, 300×300 en JPEG
            $w = imagesx($src);
            $h = imagesy($src);
            $side = min($w, $h);
            $size = 300;
            $dest = imagecreatetruecolor($size, $size);
            imagecopyresampled($dest, $src, 0, 0, (int)(($w - $side) / 2), (int)(($h - $side) / 2), $size, $size, $side, $side);
            imagedestroy($src);
            $fname = $base . '.jpg';
            $ok = imagejpeg($dest, $dir . $fname, 88);
            imagedestroy($dest);
        } else {
            // Servidor sin la extensión GD: se guarda la imagen original (la vista la recorta en círculo)
            $fname = $base . '.' . ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime];
            $ok = move_uploaded_file($file['tmp_name'], $dir . $fname);
        }
        if (!$ok) jsonErr('No se pudo guardar la foto en el servidor. Intente de nuevo o avise al administrador.');

        // La foto anterior se borra solo cuando la nueva ya quedó guardada
        $stmt = $pdo->prepare("SELECT foto FROM usuarios WHERE id=?");
        $stmt->execute([$userId]);
        $oldFoto = $stmt->fetchColumn();
        if ($oldFoto && str_starts_with($oldFoto, 'assets/uploads/avatars/') && is_file(__DIR__ . '/' . $oldFoto)) unlink(__DIR__ . '/' . $oldFoto);

        $path = 'assets/uploads/avatars/' . $fname;
        $pdo->prepare("UPDATE usuarios SET foto=? WHERE id=?")->execute([$path, $userId]);
        $_SESSION['usuario_foto'] = $path;
        jsonOk(['foto' => BASE_URL . $path], 'Foto de perfil actualizada.');
    }

    if ($act === 'quitar_foto') {
        $stmt = $pdo->prepare("SELECT foto FROM usuarios WHERE id=?");
        $stmt->execute([$userId]);
        $oldFoto = $stmt->fetchColumn();
        if ($oldFoto && str_starts_with($oldFoto, 'assets/uploads/avatars/') && is_file(__DIR__ . '/' . $oldFoto)) unlink(__DIR__ . '/' . $oldFoto);
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

// Delegación de aprobaciones: la puede hacer quien aprueba con su propio puesto
$puedeDelegar = tieneAccesoTotal() || !empty($_SESSION['rol_aprueba']);
$s = $pdo->prepare("SELECT d.*, u.nombre AS delegado FROM delegaciones d JOIN usuarios u ON u.id = d.delegado_id
                    WHERE d.usuario_id = ? AND d.cancelada = 0 AND d.hasta >= CURDATE() ORDER BY d.desde");
$s->execute([$userId]);
$misDelegaciones = $s->fetchAll();
$s = $pdo->prepare("SELECT d.*, u.nombre AS delegante FROM delegaciones d JOIN usuarios u ON u.id = d.usuario_id
                    WHERE d.delegado_id = ? AND d.cancelada = 0 AND d.hasta >= CURDATE() ORDER BY d.desde");
$s->execute([$userId]);
$delegacionesRecibidas = $s->fetchAll();
$candidatosDelegar = [];
if ($puedeDelegar) {
    $s = $pdo->prepare("SELECT id, nombre, rol FROM usuarios WHERE activo = 1 AND id <> ? ORDER BY nombre");
    $s->execute([$userId]);
    $candidatosDelegar = array_filter($s->fetchAll(), fn($u) => empty(roles()[$u['rol']]['solo_propios']));
}
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
          <div class="perfil-info-row"><i class="fas fa-calendar"></i> Miembro desde <?= mb_strtolower(nombreMes((int)date('n', strtotime($me['created_at'])))) . ' de ' . date('Y', strtotime($me['created_at'])) ?></div>
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

    <!-- Delegación de aprobaciones -->
    <?php if ($puedeDelegar || $delegacionesRecibidas): ?>
    <div class="card mb-4">
      <div class="card-header"><i class="fas fa-user-clock"></i> Delegación de aprobaciones</div>
      <div class="card-body">
        <?php foreach ($delegacionesRecibidas as $d): $vigente = $d['desde'] <= date('Y-m-d'); ?>
        <div class="alert alert-info py-2 small mb-2"><i class="fas fa-hand-holding"></i>
          <strong><?= htmlspecialchars($d['delegante']) ?></strong> le delegó sus aprobaciones del <?= fmtFecha($d['desde']) ?> al <?= fmtFecha($d['hasta']) ?>
          <?= $vigente ? '(vigente: puede revisar y autorizar sus procesos)' : '(empieza el ' . fmtFecha($d['desde']) . ')' ?><?= $d['motivo'] ? ' — ' . htmlspecialchars($d['motivo']) : '' ?>.</div>
        <?php endforeach; ?>

        <?php if ($puedeDelegar): ?>
        <p class="text-muted small mb-3">Si va a ausentarse, otra persona puede revisar y autorizar en su lugar los procesos que le corresponden.
          Lo que apruebe quedará registrado "en nombre de" usted.</p>
        <?php foreach ($misDelegaciones as $d): $vigente = $d['desde'] <= date('Y-m-d'); ?>
        <div class="d-flex flex-wrap align-items-center gap-2 border rounded p-2 mb-2 small">
          <span class="badge <?= $vigente ? 'bg-success' : 'bg-secondary' ?>"><?= $vigente ? 'Vigente' : 'Programada' ?></span>
          <span>En <strong><?= htmlspecialchars($d['delegado']) ?></strong>, del <?= fmtFecha($d['desde']) ?> al <?= fmtFecha($d['hasta']) ?><?= $d['motivo'] ? ' — ' . htmlspecialchars($d['motivo']) : '' ?></span>
          <button type="button" class="btn btn-sm btn-outline-danger py-0 ms-auto" onclick="cancelarDelegacion(<?= (int)$d['id'] ?>)"><i class="fas fa-xmark"></i> Cancelar</button>
        </div>
        <?php endforeach; ?>
        <form id="form-delegar" class="row g-2 align-items-end">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="_action" value="delegar">
          <div class="col-md-4">
            <label class="form-label">Delegar en *</label>
            <select name="delegado_id" class="form-select" required>
              <option value="">Seleccione…</option>
              <?php foreach ($candidatosDelegar as $c): ?><option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?> — <?= htmlspecialchars(rolLabel($c['rol'])) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-2"><label class="form-label">Desde *</label><input type="text" name="desde" class="form-control date-input" value="<?= date('Y-m-d') ?>" required></div>
          <div class="col-6 col-md-2"><label class="form-label">Hasta *</label><input type="text" name="hasta" class="form-control date-input" required></div>
          <div class="col-md-4"><label class="form-label">Motivo</label><input type="text" name="motivo" class="form-control" maxlength="200" placeholder="Ej.: vacaciones"></div>
          <div class="col-12"><button type="submit" class="btn-proscala"><i class="fas fa-user-check"></i> Delegar aprobaciones</button></div>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

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
$extraJs = strtr(<<<'JS'

const _csrfPerfil = __CSRF_JS__;

// La foto de la barra superior también cambia
function fotoTopbar(src) {
  const img = document.querySelector('#topbar .avatar img');
  if (img) img.src = src;
}

// ── Subir foto automáticamente al seleccionar ──
document.getElementById('inp-foto').addEventListener('change', async function(){
  const file = this.files[0];
  this.value = '';
  if (!file) return;
  if (!/^image\/(jpeg|png|gif|webp)$/.test(file.type)) { Toast.show('Solo se permiten imágenes (JPG, PNG, GIF, WEBP).', 'error'); return; }
  if (file.size > 5*1024*1024) { Toast.show('La imagen no debe superar 5 MB.', 'error'); return; }

  // Vista previa inmediata; si el servidor la rechaza se vuelve a la anterior
  const img = document.getElementById('perfil-img');
  const anterior = img.src;
  img.src = URL.createObjectURL(file);

  const fd = new FormData();
  fd.append('csrf_token', _csrfPerfil);
  fd.append('_action', 'foto');
  fd.append('foto', file);
  try {
    const r = await post('perfil.php', fd);
    if (!r.success) throw new Error(r.message);
    img.src = r.foto;
    fotoTopbar(r.foto);
    Toast.show(r.message, 'success');
    setTimeout(() => Nav.recargar(), 900);   // muestra el botón Quitar foto
  } catch (e) {
    img.src = anterior;
    Toast.show(e.message || 'No se pudo cambiar la foto.', 'error');
  }
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
  fd.append('_action', 'quitar_foto');
  try {
    const r = await post('perfil.php', fd);
    if (!r.success) throw new Error(r.message);
    document.getElementById('perfil-img').src = r.logo;
    fotoTopbar(r.logo);
    Toast.show(r.message, 'success');
    setTimeout(() => Nav.recargar(), 900);
  } catch (e) {
    Toast.show(e.message || 'No se pudo quitar la foto.', 'error');
  }
}

document.getElementById('chk-notificar-correo').addEventListener('change', async function(){
  const fd = new FormData();
  fd.append('csrf_token', _csrfPerfil);
  fd.append('_action', 'notificar_correo');
  fd.append('activo', this.checked ? '1' : '0');
  try { const r = await post('perfil.php', fd); Toast.show(r.message, 'success'); }
  catch (e) { this.checked = !this.checked; Toast.show(e.message, 'error'); }
});

bindAjaxForm('form-delegar', () => setTimeout(() => Nav.recargar(), 900));
async function cancelarDelegacion(id) {
  if (!await confirmar('Quien recibió la delegación dejará de poder aprobar en su nombre.', { titulo: '¿Cancelar la delegación?', aceptar: 'Cancelar delegación', peligro: true })) return;
  const fd = new FormData();
  fd.append('csrf_token', _csrfPerfil);
  fd.append('_action', 'cancelar_delegacion');
  fd.append('id', id);
  try { const r = await post('perfil.php', fd); Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  catch (e) { Toast.show(e.message, 'error'); }
}

bindAjaxForm('form-datos', r => {
  Toast.show(r.message, 'success');
  if (r.nombre) document.getElementById('perfil-nombre-display').textContent = r.nombre;
});
bindAjaxForm('form-pass', r => {
  Toast.show(r.message,'success'); document.getElementById('form-pass').reset();
  __TRAS_CAMBIAR_CLAVE__
});

JS, [
    '__CSRF_JS__' => $csrfJs,
    '__TRAS_CAMBIAR_CLAVE__' => ($me['debe_cambiar_password'] ? "setTimeout(()=>Nav.ir('" . BASE_URL . "'), 1200);" : ''),
]);
include __DIR__ . '/includes/footer.php';
?>

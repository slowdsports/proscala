<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
if (!tieneAccesoTotal()) { header('Location: ' . BASE_URL . paginaInicio()); exit; }
$pagina = 'Usuarios del Sistema';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $uid  = (int)($_POST['id'] ?? 0);
        $d = ['nombre'=>trim($_POST['nombre']),'email'=>trim($_POST['email']),'rol'=>isset(roles()[$_POST['rol']??''])?$_POST['rol']:'empleado','activo'=>isset($_POST['activo'])?1:0];
        if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) jsonErr('Correo electrónico inválido.');
        $empresaId = $_SESSION['empresa_id'];

        // Empleado vinculado: con él el sistema lo reconoce como responsable (caja chica), firmante y
        // dueño de sus solicitudes. Un usuario por empleado; los consultores se vinculan en Proveedores
        $empleadoId = (int)($_POST['empleado_id'] ?? 0) ?: null;
        if ($empleadoId) {
            $s = $pdo->prepare("SELECT 1 FROM empleados WHERE id=?");
            $s->execute([$empleadoId]);
            if (!$s->fetchColumn()) jsonErr('Empleado no encontrado.');
            $s = $pdo->prepare("SELECT nombre, email FROM usuarios WHERE empleado_id=? AND id<>?");
            $s->execute([$empleadoId, $uid]);
            if ($otro = $s->fetch()) jsonErr("Ese empleado ya está vinculado al usuario {$otro['nombre']} ({$otro['email']}).");
        }

        $actual = null;
        if ($uid) {
            $s = $pdo->prepare("SELECT email, rol, activo, proveedor_id FROM usuarios WHERE id=?");
            $s->execute([$uid]);
            if (!$actual = $s->fetch()) jsonErr('Usuario no encontrado.');
            // La cuenta del super administrador solo la modifica él mismo
            $s = $pdoMaster->prepare("SELECT superadmin FROM accesos WHERE email=?");
            $s->execute([$actual['email']]);
            if ($s->fetchColumn() && !esSuperadmin()) jsonErr('Esta cuenta solo la puede modificar el super administrador.');
            // Cada empresa debe conservar al menos un administrador activo
            if ($actual['rol'] === 'admin' && $actual['activo'] && ($d['rol'] !== 'admin' || !$d['activo'])) {
                $s = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol='admin' AND activo=1 AND id<>?");
                $s->execute([$uid]);
                if (!$s->fetchColumn()) jsonErr('La empresa debe tener al menos un administrador activo.');
            }
        }
        if (emailEnUso($pdoMaster, $d['email'], $empresaId, $actual['email'] ?? '')) jsonErr('Ese correo ya está registrado en esta empresa.');
        if (!empty($actual['proveedor_id'])) $empleadoId = null;   // consultor: no es empleado

        try {
            if ($uid) {
                $pdo->prepare("UPDATE usuarios SET nombre=?,email=?,rol=?,activo=?,empleado_id=? WHERE id=?")->execute([...array_values($d), $empleadoId, $uid]);
                $pdoMaster->prepare("UPDATE accesos SET email=? WHERE email=? AND empresa_id=?")->execute([$d['email'], $actual['email'], $empresaId]);
                // Una contraseña puesta por el Administrador: el usuario debe cambiarla al entrar
                if ($_POST['new_password'] ?? '') {
                    $pdo->prepare("UPDATE usuarios SET password=?, pendiente_activacion=0, debe_cambiar_password=1 WHERE id=?")
                        ->execute([password_hash($_POST['new_password'], PASSWORD_BCRYPT), $uid]);
                }
                jsonOk([],'Usuario actualizado.');
            } else {
                // Usuario nuevo: crea su propia contraseña con el enlace que recibe por correo
                $pdoMaster->prepare("INSERT INTO accesos (email, empresa_id) VALUES (?,?)")->execute([$d['email'], $empresaId]);
                try {
                    $pdo->prepare("INSERT INTO usuarios (nombre,email,password,pendiente_activacion,rol,activo,empleado_id) VALUES (?,?,?,1,?,?,?)")
                        ->execute([$d['nombre'],$d['email'],claveSinActivar(),$d['rol'],$d['activo'],$empleadoId]);
                } catch (PDOException $e) {
                    $pdoMaster->prepare("DELETE FROM accesos WHERE email=? AND empresa_id=?")->execute([$d['email'], $empresaId]);
                    throw $e;
                }
                $nuevoId = (int)$pdo->lastInsertId();
                if (!$d['activo']) jsonOk(['id' => $nuevoId], 'Usuario creado (inactivo). Al activarlo, envíele la invitación con el botón del sobre.');
                $envio = enviarActivacion($pdo, $pdoMaster, (int)$empresaId, $nuevoId);
                jsonOk(['id' => $nuevoId], $envio === true
                    ? "Usuario creado. Se envió a {$d['email']} el enlace para activar su cuenta."
                    : "Usuario creado, pero no se pudo enviar el correo de activación ($envio). Reenvíelo con el botón del sobre.");
            }
        } catch(PDOException $e){ jsonErr($e->getCode()==23000?'Ese correo ya está registrado en esta empresa.':$e->getMessage()); }
    }
    // Reenviar el enlace de activación a un usuario que aún no activa su cuenta
    if ($_act === 'reenviar_activacion') {
        $s = $pdo->prepare("SELECT id, nombre, email, pendiente_activacion FROM usuarios WHERE id = ?");
        $s->execute([(int)($_POST['id'] ?? 0)]);
        $u = $s->fetch();
        if (!$u) jsonErr('Usuario no encontrado.');
        if (!$u['pendiente_activacion']) jsonErr('Este usuario ya activó su cuenta.');
        $GLOBALS['_bitacora_explicita'] = true;   // enviarActivacion ya lo registra
        $envio = enviarActivacion($pdo, $pdoMaster, (int)$_SESSION['empresa_id'], (int)$u['id']);
        if ($envio !== true) jsonErr('No se pudo enviar el correo: ' . $envio);
        jsonOk(['id' => $u['id']], "Se envió a {$u['email']} un nuevo enlace de activación (vence en " . DIAS_ACTIVACION . ' días).');
    }
    // Desbloquear a un usuario bloqueado por intentos fallidos (login.php)
    if ($_act === 'desbloquear') {
        $s = $pdo->prepare("SELECT id, nombre, email, bloqueado_en FROM usuarios WHERE id = ?");
        $s->execute([(int)($_POST['id'] ?? 0)]);
        $b = $s->fetch();
        if (!$b) jsonErr('Usuario no encontrado.');
        if (!$b['bloqueado_en']) jsonErr('El usuario no está bloqueado.');
        $pdo->prepare("UPDATE usuarios SET bloqueado_en = NULL, intentos_fallidos = 0 WHERE id = ?")->execute([$b['id']]);
        $GLOBALS['_bitacora_explicita'] = true;
        registrarEvento($pdo, 'DESBLOQUEO', ['tabla' => 'usuarios', 'registro_id' => $b['id'],
            'detalle' => "Desbloqueó a {$b['nombre']} ({$b['email']}), bloqueado desde " . date('d/m/Y H:i', strtotime($b['bloqueado_en']))]);
        jsonOk(['id' => $b['id']], "{$b['nombre']} fue desbloqueado. Ya puede iniciar sesión.");
    }
    jsonErr('Acción desconocida.');
}

$usuarios = $pdo->query("SELECT u.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre FROM usuarios u
    LEFT JOIN empleados e ON e.id = u.empleado_id ORDER BY u.bloqueado_en IS NULL, u.nombre")->fetchAll();
// Empleados para vincular: los activos y los que ya tienen usuario (con quién, para no repetirlos)
$empleadosVincular = $pdo->query("SELECT e.id, CONCAT(e.nombre,' ',e.apellidos) AS nombre, e.cargo, e.email, u.id AS usuario_id, u.email AS usuario_email
    FROM empleados e LEFT JOIN usuarios u ON u.empleado_id = e.id
    WHERE e.activo = 1 OR u.id IS NOT NULL ORDER BY e.apellidos, e.nombre")->fetchAll();
$bloqueados = count(array_filter($usuarios, fn($u) => $u['bloqueado_en']));
$_s = $pdoMaster->prepare("SELECT email FROM accesos WHERE empresa_id=? AND superadmin=1");
$_s->execute([$_SESSION['empresa_id']]);
$superadmins = array_map('strtolower', $_s->fetchAll(PDO::FETCH_COLUMN));
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-user-shield"></i> Usuarios del Sistema</h1>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-user"><i class="fas fa-plus"></i> Nuevo Usuario</button>
</div>
<?php if ($bloqueados): ?>
<div class="alert alert-danger py-2" style="font-size:.85rem;">
  <i class="fas fa-user-lock"></i>
  <?= $bloqueados === 1 ? 'Hay 1 usuario bloqueado' : "Hay $bloqueados usuarios bloqueados" ?> por <?= MAX_INTENTOS_LOGIN ?> contraseñas incorrectas seguidas.
  Verifique con la persona que fue ella quien intentó entrar antes de desbloquearla (botón <i class="fas fa-lock-open"></i>).
</div>
<?php endif; ?>
<div class="card"><div class="card-body p-0">
  <table id="tbl-usr" class="table-proscala w-100">
    <thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Último Acceso</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach($usuarios as $u): ?>
      <tr>
        <td><?= htmlspecialchars($u['nombre']) ?>
          <?php if ($u['proveedor_id']): // usuario de un consultor: sus datos y su acceso se gestionan en Proveedores ?>
          <div style="font-size:.72rem;color:var(--text-3)"><i class="fas fa-truck"></i> Consultor (<a href="<?= BASE_URL ?>proveedores.php">Proveedores</a>)</div>
          <?php elseif ($u['empleado_nombre']): ?>
          <div style="font-size:.72rem;color:var(--text-3)"><i class="fas fa-id-badge"></i> Empleado: <?= htmlspecialchars($u['empleado_nombre']) ?></div>
          <?php else: ?>
          <div style="font-size:.72rem;color:var(--text-3)" title="Sin empleado vinculado no se le reconoce como responsable (caja chica) ni como firmante de lo que pide"><i class="fas fa-link-slash"></i> Sin empleado vinculado</div>
          <?php endif; ?></td>
        <td><?= htmlspecialchars($u['email']) ?></td>
        <td><span class="badge bg-<?= $u['rol'] === 'admin' ? 'danger' : 'primary' ?>"><?= htmlspecialchars(rolLabel($u['rol'])) ?></span><?php if (in_array(strtolower($u['email']), $superadmins, true)): ?> <span class="badge bg-dark"><i class="fas fa-crown"></i> Super Admin</span><?php endif; ?></td>
        <td><?= $u['ultimo_acceso']?date('d/m/Y H:i',strtotime($u['ultimo_acceso'])):'—' ?></td>
        <td><?= $u['activo']?'<span class="badge bg-success">Activo</span>':'<span class="badge bg-danger">Inactivo</span>' ?>
          <?php if ($u['pendiente_activacion']): ?><span class="badge bg-warning text-dark" title="Aún no crea su contraseña con el enlace que recibió por correo"><i class="fas fa-envelope"></i> Sin activar</span><?php endif; ?>
          <?php if ($u['bloqueado_en']): ?><span class="badge bg-dark" title="Bloqueado el <?= date('d/m/Y H:i', strtotime($u['bloqueado_en'])) ?>"><i class="fas fa-lock"></i> Bloqueado</span>
          <?php elseif ($u['intentos_fallidos']): ?><span class="badge bg-warning text-dark" title="Contraseñas incorrectas seguidas"><?= (int)$u['intentos_fallidos'] ?>/<?= MAX_INTENTOS_LOGIN ?> intentos</span><?php endif; ?></td>
        <td style="white-space:nowrap"><a href="<?= BASE_URL ?>auditoria.php?usuario_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Historial de actividad"><i class="fas fa-clock-rotate-left"></i></a>
          <?php if ($u['pendiente_activacion'] && $u['activo']): ?>
          <button class="btn btn-sm btn-outline-warning py-0 px-2" title="Reenviar enlace de activación" onclick='reenviarActivacion(<?= (int)$u['id'] ?>, <?= json_encode($u['email'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-envelope"></i></button>
          <?php endif; ?>
          <?php if ($u['bloqueado_en']): ?>
          <button class="btn btn-sm btn-danger py-0 px-2" title="Desbloquear" onclick='desbloquearUsuario(<?= (int)$u['id'] ?>, <?= json_encode($u['nombre'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-lock-open"></i></button>
          <?php endif; ?>
          <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick='editUser(<?= json_encode(['id'=>$u['id'],'nombre'=>$u['nombre'],'email'=>$u['email'],'rol'=>$u['rol'],'activo'=>$u['activo'],'empleado_id'=>$u['empleado_id'],'proveedor_id'=>$u['proveedor_id']], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<div class="modal fade" id="modal-user" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-shield"></i> <span id="usr-title">Nuevo Usuario</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-user">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar">
          <input type="hidden" name="id" id="u-id" value="0">
          <div class="mb-3"><label class="form-label">Nombre completo *</label><input type="text" name="nombre" id="u-nombre" class="form-control" required></div>
          <div class="mb-3"><label class="form-label">Correo electrónico *</label><input type="email" name="email" id="u-email" class="form-control" required></div>
          <div class="mb-3">
            <label class="form-label">Rol *</label>
            <select name="rol" id="u-rol" class="form-select">
              <?php foreach (rolesNombres() as $k => $lbl): ?>
              <option value="<?= $k ?>" <?= $k === 'empleado' ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?><?= $k === 'admin' ? ' (acceso total)' : '' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Los módulos de cada puesto se definen en <a href="<?= BASE_URL ?>roles.php">Roles y Permisos</a>.</div>
          </div>
          <div class="mb-3" id="u-empleado-grupo">
            <label class="form-label">Empleado vinculado</label>
            <select name="empleado_id" id="u-empleado" class="form-select">
              <option value="">— Ninguno —</option>
              <?php foreach ($empleadosVincular as $e): ?>
              <option value="<?= $e['id'] ?>" data-email="<?= htmlspecialchars(strtolower((string)$e['email'])) ?>" data-usuario="<?= (int)$e['usuario_id'] ?>"><?= htmlspecialchars($e['nombre'] . ($e['cargo'] ? ' — ' . $e['cargo'] : '')) ?><?= $e['usuario_id'] ? ' · ya es de ' . htmlspecialchars($e['usuario_email']) : '' ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Su ficha en Empleados. Con ella el sistema lo reconoce como responsable de su caja chica, firmante y dueño de sus solicitudes.</div>
          </div>
          <div class="alert alert-info py-2 small" id="u-aviso-nuevo"><i class="fas fa-envelope"></i> El usuario recibirá un correo con un enlace para crear su contraseña y activar su cuenta.</div>
          <div class="mb-3" id="u-pass-grupo" style="display:none"><label class="form-label">Nueva contraseña <small class="text-muted">(vacío para no cambiarla; el usuario deberá cambiarla al entrar)</small></label><input type="password" name="new_password" id="u-pass" class="form-control" minlength="8"></div>
          <div class="form-check"><input type="checkbox" name="activo" class="form-check-input" id="u-activo" checked><label class="form-check-label" for="u-activo">Activo</label></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="saveUser()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php
$extraJs = <<<'JS'
initDataTable('#tbl-usr');
// Nuevo: sin contraseña (la crea con el enlace del correo). Editar: se puede poner una nueva
function modoFormUsuario(editando) {
  document.getElementById('u-aviso-nuevo').style.display = editando ? 'none' : '';
  document.getElementById('u-pass-grupo').style.display = editando ? '' : 'none';
}
function editUser(u){
  document.getElementById('u-id').value=u.id;
  document.getElementById('u-nombre').value=u.nombre;
  document.getElementById('u-email').value=u.email;
  document.getElementById('u-rol').value=u.rol;
  $('#u-rol').trigger('change');
  document.getElementById('u-activo').checked=u.activo==1;
  document.getElementById('u-empleado').value=u.empleado_id||'';
  document.getElementById('u-empleado-grupo').hidden=!!u.proveedor_id;   // consultor: no es empleado
  document.getElementById('u-pass').value='';
  document.getElementById('usr-title').textContent='Editar: '+u.nombre;
  modoFormUsuario(true);
  new bootstrap.Modal(document.getElementById('modal-user')).show();
}
// Al escribir el correo, sugiere el empleado con ese mismo correo que aún no tiene usuario (si no eligió ninguno).
// Uno que ya es de otro usuario lo rechaza el servidor al guardar
document.getElementById('u-email').addEventListener('change', e => {
  const sel = document.getElementById('u-empleado');
  if (sel.value) return;
  const o = [...sel.options].find(o => o.value && !+o.dataset.usuario && o.dataset.email === e.target.value.trim().toLowerCase());
  if (o) sel.value = o.value;
});
// Al cerrar vuelve a ser "Nuevo Usuario"
document.getElementById('modal-user').addEventListener('hidden.bs.modal', () => {
  document.getElementById('form-user').reset();
  document.getElementById('u-empleado-grupo').hidden = false;
  document.getElementById('u-id').value = 0;
  document.getElementById('usr-title').textContent = 'Nuevo Usuario';
  modoFormUsuario(false);
});
async function accionUsuario(accion, id) {
  const fd = new FormData();
  fd.append('_action', accion);
  fd.append('id', id);
  fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
  try { const r = await post('usuarios.php', fd); Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  catch (e) { Toast.show(e.message, 'error'); }
}
async function desbloquearUsuario(id, nombre){
  if (!await confirmar(nombre + ' podrá volver a iniciar sesión.', { titulo: '¿Desbloquear usuario?', aceptar: 'Desbloquear' })) return;
  accionUsuario('desbloquear', id);
}
async function reenviarActivacion(id, email){
  if (!await confirmar('Se enviará a ' + email + ' un nuevo enlace para crear su contraseña. El enlace anterior dejará de servir.', { titulo: '¿Reenviar la invitación?', aceptar: 'Reenviar' })) return;
  accionUsuario('reenviar_activacion', id);
}
async function saveUser(){
  try {
    const r = await post('usuarios.php', new FormData(document.getElementById('form-user')));
    Toast.show(r.message, 'success', 6000);
    setTimeout(() => Nav.recargar(), 1200);
  } catch (e) { Toast.show(e.message, 'error'); }
}
JS;
include __DIR__ . '/includes/footer.php';
?>


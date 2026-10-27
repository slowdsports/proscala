<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
if (!esSuperadmin()) { header('Location: ' . BASE_URL . paginaInicio()); exit; }
$pagina = 'Empresas';

// Ejecuta un archivo .sql sentencia por sentencia (cada una termina en ";" al final de línea)
function ejecutarArchivoSQL(PDO $db, string $archivo): void {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents($archivo));
    foreach (preg_split('/;\s*$/m', $sql) as $sentencia) {
        if (trim($sentencia) !== '') $db->exec($sentencia);
    }
}

function validarAdmin(string $nombre, string $email, string $password): void {
    if ($nombre === '') jsonErr('El nombre del administrador es requerido.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonErr('Correo del administrador inválido.');
    if (strlen($password) < 6) jsonErr('La contraseña debe tener al menos 6 caracteres.');
}

// El RTN identifica a la empresa en el login: 14 dígitos y sin repetirse
function validarRtn(PDO $pdoMaster, string $rtn, int $exceptoId = 0): void {
    if (!rtnValido($rtn)) jsonErr('El RTN debe tener 14 dígitos.');
    $s = $pdoMaster->prepare("SELECT 1 FROM empresas WHERE rtn=? AND id<>?");
    $s->execute([$rtn, $exceptoId]);
    if ($s->fetchColumn()) jsonErr('Ese RTN ya está registrado en otra empresa.');
}

function empresaPorId(PDO $pdoMaster, int $id): array {
    $s = $pdoMaster->prepare("SELECT * FROM empresas WHERE id=?");
    $s->execute([$id]);
    $e = $s->fetch();
    if (!$e) jsonErr('Empresa no encontrada.');
    return $e;
}

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    // ── Alta de empresa: base de datos propia + su administrador ─────
    if ($_act === 'crear') {
        $nombre   = trim($_POST['nombre'] ?? '');
        $rtn      = normalizarRtn($_POST['rtn'] ?? '');
        $dbNombre = trim($_POST['db_nombre'] ?? '');
        $aNombre  = trim($_POST['admin_nombre'] ?? '');
        $aEmail   = trim($_POST['admin_email'] ?? '');
        $aPass    = $_POST['admin_password'] ?? '';

        if ($nombre === '') jsonErr('El nombre de la empresa es requerido.');
        validarRtn($pdoMaster, $rtn);
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbNombre)) jsonErr('El nombre de la base de datos solo admite letras, números y guion bajo (máx. 64).');
        validarAdmin($aNombre, $aEmail, $aPass);
        $s = $pdoMaster->prepare("SELECT 1 FROM empresas WHERE db_nombre=?");
        $s->execute([$dbNombre]);
        if ($s->fetchColumn()) jsonErr('Esa base de datos ya está asignada a otra empresa.');

        // Localmente se crea la base; en el hosting (cPanel) debe crearse antes desde el panel
        try { conectarBD('')->exec("CREATE DATABASE IF NOT EXISTS `$dbNombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
        catch (PDOException $e) { /* sin permiso para crear bases: se intenta usar una existente */ }
        try {
            $db = conectarBD($dbNombre);
        } catch (PDOException $e) {
            jsonErr("No se pudo acceder a la base \"$dbNombre\". Créela en cPanel y asígnele el usuario de MySQL del sistema.");
        }
        if ($db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn()) {
            jsonErr("La base \"$dbNombre\" ya contiene tablas. Use una base vacía.");
        }

        try {
            ejecutarArchivoSQL($db, __DIR__ . '/database/empresa_schema.sql');
            ejecutarArchivoSQL($db, __DIR__ . '/database/empresa_seed.sql');
            ejecutarArchivoSQL($db, __DIR__ . '/database/roles_modulos_inicial.sql');
            $db->prepare("UPDATE configuracion SET valor=? WHERE clave='nombre_organizacion'")->execute([$nombre]);
            $db->prepare("UPDATE configuracion SET valor=? WHERE clave='rtn'")->execute([$rtn]);
            $db->prepare("INSERT INTO usuarios (nombre,email,password,rol,activo) VALUES (?,?,?,'admin',1)")
               ->execute([$aNombre, $aEmail, password_hash($aPass, PASSWORD_BCRYPT)]);

            $pdoMaster->beginTransaction();
            $pdoMaster->prepare("INSERT INTO empresas (nombre, rtn, db_nombre) VALUES (?,?,?)")->execute([$nombre, $rtn, $dbNombre]);
            $empresaId = (int)$pdoMaster->lastInsertId();
            $pdoMaster->prepare("INSERT INTO accesos (email, empresa_id) VALUES (?,?)")->execute([$aEmail, $empresaId]);
            $pdoMaster->commit();
        } catch (Exception $e) {
            if ($pdoMaster->inTransaction()) $pdoMaster->rollBack();
            // La base estaba vacía antes de empezar: se deja vacía otra vez para poder reintentar
            $db->exec("SET FOREIGN_KEY_CHECKS=0");
            foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $t) {
                $db->exec("DROP TABLE `$t`");
            }
            jsonErr('No se pudo crear la empresa: ' . $e->getMessage());
        }
        jsonOk(['id' => $empresaId], 'Empresa creada con su administrador.');
    }

    // ── Editar nombre / estado ────────────────────────────────────────
    if ($_act === 'editar') {
        $emp    = empresaPorId($pdoMaster, (int)($_POST['id'] ?? 0));
        $nombre = trim($_POST['nombre'] ?? '');
        $rtn    = normalizarRtn($_POST['rtn'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;
        if ($nombre === '') jsonErr('El nombre de la empresa es requerido.');
        validarRtn($pdoMaster, $rtn, (int)$emp['id']);
        if (!$activo && (int)$emp['id'] === (int)$_SESSION['empresa_id']) jsonErr('No puede desactivar la empresa con la que inició sesión.');
        $pdoMaster->prepare("UPDATE empresas SET nombre=?, rtn=?, activo=? WHERE id=?")->execute([$nombre, $rtn, $activo, $emp['id']]);
        // El RTN también sale en los documentos de la empresa: se mantiene igual en su configuración
        try { conectarBD($emp['db_nombre'])->prepare("UPDATE configuracion SET valor=? WHERE clave='rtn'")->execute([$rtn]); }
        catch (PDOException $e) { /* base no disponible: se sincroniza la próxima vez que se edite */ }
        jsonOk([], 'Empresa actualizada.');
    }

    // ── Administradores: agregar uno nuevo o cambiar la contraseña de uno existente ──
    if ($_act === 'admin') {
        $emp     = empresaPorId($pdoMaster, (int)($_POST['empresa_id'] ?? 0));
        $adminId = (int)($_POST['admin_id'] ?? 0);
        $aNombre = trim($_POST['admin_nombre'] ?? '');
        $aEmail  = trim($_POST['admin_email'] ?? '');
        $aPass   = $_POST['admin_password'] ?? '';
        $db      = conectarBD($emp['db_nombre']);

        if ($adminId) {
            if (strlen($aPass) < 6) jsonErr('La contraseña debe tener al menos 6 caracteres.');
            $s = $db->prepare("UPDATE usuarios SET password=? WHERE id=? AND rol='admin'");
            $s->execute([password_hash($aPass, PASSWORD_BCRYPT), $adminId]);
            if (!$s->rowCount()) jsonErr('Administrador no encontrado.');
            jsonOk([], 'Contraseña del administrador actualizada.');
        }

        validarAdmin($aNombre, $aEmail, $aPass);
        if (emailEnUso($pdoMaster, $aEmail, (int)$emp['id'])) jsonErr('Ese correo ya está registrado en esta empresa.');
        $pdoMaster->prepare("INSERT INTO accesos (email, empresa_id) VALUES (?,?)")->execute([$aEmail, $emp['id']]);
        try {
            $db->prepare("INSERT INTO usuarios (nombre,email,password,rol,activo) VALUES (?,?,?,'admin',1)")
               ->execute([$aNombre, $aEmail, password_hash($aPass, PASSWORD_BCRYPT)]);
        } catch (PDOException $e) {
            $pdoMaster->prepare("DELETE FROM accesos WHERE email=? AND empresa_id=?")->execute([$aEmail, $emp['id']]);
            jsonErr($e->getCode() == 23000 ? 'Ese correo ya existe en la empresa.' : $e->getMessage());
        }
        jsonOk([], 'Administrador agregado.');
    }
    jsonErr('Acción desconocida.');
}

// ── Listado: datos del registro central + resumen de usuarios de cada base ──
$empresas = $pdoMaster->query("SELECT * FROM empresas ORDER BY nombre")->fetchAll();
// Intentos con un RTN inexistente (los demás quedan en la bitácora de cada empresa)
$intentosRtn = $pdoMaster->query("SELECT * FROM log_accesos WHERE motivo = 'RTN no registrado' ORDER BY id DESC LIMIT 50")->fetchAll();
foreach ($empresas as &$e) {
    $e['usuarios'] = null;
    $e['admins']   = [];
    try {
        $db = conectarBD($e['db_nombre']);
        $e['usuarios'] = (int)$db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
        $e['admins']   = $db->query("SELECT id, nombre, email, activo FROM usuarios WHERE rol='admin' ORDER BY nombre")->fetchAll();
    } catch (PDOException $ex) { /* base no disponible: se indica en la tarjeta */ }
}
unset($e);
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-building"></i> Empresas</h1>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-nueva"><i class="fas fa-plus"></i> Nueva Empresa</button>
</div>
<div class="row g-3 mb-3">
  <?php foreach ($empresas as $e): ?>
  <div class="col-md-6 col-xl-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <h5 style="font-size:1rem;margin:0;"><?= htmlspecialchars($e['nombre']) ?></h5>
          <?= $e['activo'] ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-danger">Inactiva</span>' ?>
        </div>
        <div style="font-size:.8rem;" class="text-muted mb-1">
          <i class="fas fa-id-card"></i> RTN <span class="font-mono"><?= htmlspecialchars($e['rtn']) ?></span>
        </div>
        <div style="font-size:.8rem;" class="text-muted mb-2">
          <i class="fas fa-database"></i> <span class="font-mono"><?= htmlspecialchars($e['db_nombre']) ?></span>
          <?php if ((int)$e['id'] === (int)$_SESSION['empresa_id']): ?> · <span class="text-primary">Su empresa</span><?php endif; ?>
        </div>
        <?php if ($e['usuarios'] === null): ?>
          <div class="alert alert-warning py-1 px-2 mb-0" style="font-size:.8rem;"><i class="fas fa-triangle-exclamation"></i> No se pudo acceder a la base de datos.</div>
        <?php else: ?>
          <div style="font-size:.8rem;" class="mb-1"><i class="fas fa-users text-blue"></i> <?= $e['usuarios'] ?> usuario(s)</div>
          <div style="font-size:.8rem;" class="fw-bold mt-2 mb-1">Administradores</div>
          <?php foreach ($e['admins'] as $a): ?>
          <div class="d-flex justify-content-between align-items-center" style="font-size:.8rem;">
            <span><?= htmlspecialchars($a['nombre']) ?> <span class="text-muted">· <?= htmlspecialchars($a['email']) ?></span><?= $a['activo'] ? '' : ' <span class="badge bg-secondary">Inactivo</span>' ?></span>
            <button class="btn btn-sm btn-link py-0" title="Cambiar contraseña"
                    onclick='resetAdmin(<?= json_encode(['empresa_id'=>$e['id'],'id'=>$a['id'],'nombre'=>$a['nombre']]) ?>)'><i class="fas fa-key"></i></button>
          </div>
          <?php endforeach; ?>
          <?php if (!$e['admins']): ?><div class="text-danger" style="font-size:.8rem;">Sin administrador</div><?php endif; ?>
        <?php endif; ?>
      </div>
      <div class="card-footer bg-transparent d-flex justify-content-end gap-2 py-2">
        <?php if ($e['usuarios'] !== null): ?>
        <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick='nuevoAdmin(<?= json_encode(['empresa_id'=>$e['id'],'nombre'=>$e['nombre']]) ?>)'><i class="fas fa-user-plus"></i> Administrador</button>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick='editEmpresa(<?= json_encode(['id'=>$e['id'],'nombre'=>$e['nombre'],'rtn'=>$e['rtn'],'activo'=>$e['activo']]) ?>)'><i class="fas fa-edit"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card mb-3">
  <div class="card-header"><i class="fas fa-user-xmark"></i> Intentos de acceso con RTN no registrado <span class="text-muted" style="font-size:.78rem;">(últimos 50)</span></div>
  <div class="card-body p-0" style="overflow-x:auto;">
    <table class="table-proscala w-100" style="font-size:.82rem">
      <thead><tr><th>Fecha / Hora</th><th>RTN escrito</th><th>Correo</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($intentosRtn as $i): ?>
        <tr>
          <td class="font-mono"><?= date('d/m/Y H:i:s', strtotime($i['created_at'])) ?></td>
          <td class="font-mono"><?= htmlspecialchars($i['rtn']) ?></td>
          <td><?= htmlspecialchars($i['email']) ?></td>
          <td class="font-mono"><?= htmlspecialchars($i['ip']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$intentosRtn): ?><tr><td colspan="4" class="text-center text-muted py-3">Sin intentos registrados.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Nueva empresa -->
<div class="modal fade" id="modal-nueva" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-building"></i> Nueva Empresa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-nueva">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="crear">
          <div class="row g-3">
            <div class="col-md-12"><label class="form-label">Nombre de la empresa *</label><input type="text" name="nombre" class="form-control" required></div>
            <div class="col-md-6">
              <label class="form-label">RTN *</label>
              <input type="text" name="rtn" class="form-control font-mono" inputmode="numeric" placeholder="14 dígitos" required>
              <div class="form-text">Los usuarios lo escriben al iniciar sesión para entrar a esta empresa.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Base de datos *</label>
              <input type="text" name="db_nombre" class="form-control font-mono" pattern="[A-Za-z0-9_]+" placeholder="<?= $isLocal ? 'empresa_admin' : 'u6613652_empresa' ?>" required>
            </div>
            <div class="col-12">
              <div class="alert alert-info py-2 mb-0" style="font-size:.8rem;">
                <i class="fas fa-info-circle"></i> Cada empresa guarda su información en su propia base de datos, sin relación con las demás.
                <?php if (!$isLocal): ?><br><strong>En el hosting:</strong> cree antes la base en cPanel (MySQL Databases) y asígnele el usuario <code><?= htmlspecialchars(DB_USER) ?></code> con todos los privilegios. Proscala crea las tablas.<?php endif; ?>
              </div>
            </div>
            <div class="col-12"><h6 class="fw-bold mb-0 mt-1"><i class="fas fa-user-shield text-blue"></i> Administrador de la empresa</h6></div>
            <div class="col-md-12"><label class="form-label">Nombre completo *</label><input type="text" name="admin_nombre" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Correo electrónico *</label><input type="email" name="admin_email" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Contraseña *</label><input type="password" name="admin_password" class="form-control" minlength="6" required></div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" id="btn-crear" onclick="crearEmpresa()"><i class="fas fa-save"></i> Crear Empresa</button>
      </div>
    </div>
  </div>
</div>

<!-- Editar empresa -->
<div class="modal fade" id="modal-editar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-building"></i> Editar Empresa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-editar">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="editar">
          <input type="hidden" name="id" id="ed-id">
          <div class="mb-3"><label class="form-label">Nombre *</label><input type="text" name="nombre" id="ed-nombre" class="form-control" required></div>
          <div class="mb-3">
            <label class="form-label">RTN *</label><input type="text" name="rtn" id="ed-rtn" class="form-control font-mono" inputmode="numeric" required>
            <div class="form-text">Si lo cambia, los usuarios deberán usar el nuevo RTN al iniciar sesión.</div>
          </div>
          <div class="form-check"><input type="checkbox" name="activo" class="form-check-input" id="ed-activo"><label class="form-check-label" for="ed-activo">Activa <small class="text-muted">(si se desactiva, sus usuarios no pueden entrar)</small></label></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="enviar('form-editar')"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- Administrador: nuevo o cambio de contraseña -->
<div class="modal fade" id="modal-admin" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-shield"></i> <span id="adm-title"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-admin">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="admin">
          <input type="hidden" name="empresa_id" id="adm-empresa">
          <input type="hidden" name="admin_id" id="adm-id">
          <div id="adm-datos">
            <div class="mb-3"><label class="form-label">Nombre completo *</label><input type="text" name="admin_nombre" id="adm-nombre" class="form-control"></div>
            <div class="mb-3"><label class="form-label">Correo electrónico *</label><input type="email" name="admin_email" id="adm-email" class="form-control"></div>
          </div>
          <div class="mb-1"><label class="form-label" id="adm-pass-label">Contraseña *</label><input type="password" name="admin_password" id="adm-pass" class="form-control" minlength="6"></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="enviar('form-admin')"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php
$extraJs = "
async function enviar(formId){
  // post() lanza una excepción cuando el servidor responde con error (jsonErr → HTTP 400)
  try {
    const r=await post('empresas.php',new FormData(document.getElementById(formId)));
    Toast.show(r.message,'success');
    setTimeout(()=>Nav.recargar(),1000);
  } catch(e){ Toast.show(e.message,'error'); }
}
async function crearEmpresa(){
  const b=document.getElementById('btn-crear');
  b.disabled=true;
  try { await enviar('form-nueva'); } finally { b.disabled=false; }
}
function editEmpresa(e){
  document.getElementById('ed-id').value=e.id;
  document.getElementById('ed-nombre').value=e.nombre;
  document.getElementById('ed-rtn').value=e.rtn;
  document.getElementById('ed-activo').checked=e.activo==1;
  new bootstrap.Modal(document.getElementById('modal-editar')).show();
}
function abrirAdmin(empresaId, adminId, titulo, conDatos){
  document.getElementById('form-admin').reset();
  document.getElementById('adm-empresa').value=empresaId;
  document.getElementById('adm-id').value=adminId;
  document.getElementById('adm-title').textContent=titulo;
  document.getElementById('adm-datos').style.display=conDatos?'':'none';
  document.getElementById('adm-pass-label').textContent=conDatos?'Contraseña *':'Nueva contraseña *';
  new bootstrap.Modal(document.getElementById('modal-admin')).show();
}
function nuevoAdmin(e){ abrirAdmin(e.empresa_id, 0, 'Nuevo administrador: '+e.nombre, true); }
function resetAdmin(a){ abrirAdmin(a.empresa_id, a.id, 'Contraseña de '+a.nombre, false); }
";
include __DIR__ . '/includes/footer.php';
?>

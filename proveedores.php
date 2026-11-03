<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Proveedores';
$action = $_GET['action'] ?? 'list';   // list | expediente (documentos del proveedor: config/adjuntos.php → EXPEDIENTES)
$id     = (int)($_GET['id'] ?? 0);

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $pid = (int)($_POST['id'] ?? 0);
        $categoria = isset(CATEGORIAS_PROVEEDOR[$_POST['categoria'] ?? '']) ? $_POST['categoria'] : 'ambos';
        $d = ['nombre'=>trim($_POST['nombre']),
              'rtn'=>trim($_POST['rtn']??''),'contacto'=>trim($_POST['contacto']??''),
              'telefono'=>trim($_POST['telefono']??''),'email'=>trim($_POST['email']??''),
              'direccion'=>trim($_POST['direccion']??''),'categoria'=>$categoria,
              'banco'=>trim($_POST['banco']??'')?:null,
              'banco_cuenta'=>trim($_POST['banco_cuenta']??'')?:null,
              'banco_tipo'=>in_array($_POST['banco_tipo']??'',['ahorros','cheques'])?$_POST['banco_tipo']:null,
              'activo'=>isset($_POST['activo'])?1:0,
              'identidad'=>$categoria === 'consultor' ? (trim($_POST['identidad'] ?? '') ?: null) : null];
        // Consultor: su usuario del sistema (config/auth.php, sincronizarUsuarioProveedor)
        $acceso = $categoria === 'consultor' && isset($_POST['acceso_sistema']);
        $clave  = $acceso ? (string)($_POST['clave_inicial'] ?? '') : '';
        $pdo->beginTransaction();
        try {
            if ($pid) {
                $pdo->prepare("UPDATE proveedores SET nombre=?,rtn=?,contacto=?,telefono=?,email=?,direccion=?,categoria=?,banco=?,banco_cuenta=?,banco_tipo=?,activo=?,identidad=? WHERE id=?")
                    ->execute([...array_values($d), $pid]);
                $msg = 'Proveedor actualizado.';
            } else {
                $codigo = generarNumero($pdo, 'proveedores', 'codigo', 'PROV');
                $pdo->prepare("INSERT INTO proveedores (codigo,nombre,rtn,contacto,telefono,email,direccion,categoria,banco,banco_cuenta,banco_tipo,activo,identidad) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$codigo, ...array_values($d)]);
                $pid = (int)$pdo->lastInsertId();
                $msg = $categoria === 'consultor' ? 'Consultor creado.' : 'Proveedor creado.';
            }
            [$ok, $msgUsuario] = sincronizarUsuarioProveedor($pdo, $pdoMaster, (int)$_SESSION['empresa_id'], $pid, $acceso, $clave);
            if (!$ok) { $pdo->rollBack(); jsonErr($msgUsuario); }
            $pdo->commit();
            jsonOk(['id' => $pid], trim("$msg $msgUsuario"));
        } catch(PDOException $e){ if ($pdo->inTransaction()) $pdo->rollBack(); jsonErr($e->getMessage()); }
    }
    if ($_act === 'eliminar') {
        $pdo->prepare("UPDATE proveedores SET activo=0 WHERE id=?")->execute([(int)$_POST['id']]);
        [, $msgUsuario] = sincronizarUsuarioProveedor($pdo, $pdoMaster, (int)$_SESSION['empresa_id'], (int)$_POST['id'], false);
        jsonOk([], trim("Proveedor dado de baja. $msgUsuario"));
    }
    // Reenviar al consultor el enlace para crear su contraseña
    if ($_act === 'reenviar_activacion') {
        $s = $pdo->prepare("SELECT id, email, pendiente_activacion FROM usuarios WHERE proveedor_id = ? AND activo = 1");
        $s->execute([(int)($_POST['id'] ?? 0)]);
        $u = $s->fetch();
        if (!$u) jsonErr('El consultor no tiene un usuario activo.');
        if (!$u['pendiente_activacion']) jsonErr('El consultor ya activó su cuenta.');
        $GLOBALS['_bitacora_explicita'] = true;   // enviarActivacion ya lo registra
        $envio = enviarActivacion($pdo, $pdoMaster, (int)$_SESSION['empresa_id'], (int)$u['id']);
        if ($envio !== true) jsonErr('No se pudo enviar el correo: ' . $envio);
        jsonOk([], "Se envió a {$u['email']} un nuevo enlace de activación (vence en " . DIAS_ACTIVACION . ' días).');
    }
    jsonErr('Acción desconocida.');
}

// Con el usuario del consultor, si lo tiene
$proveedores = $pdo->query("SELECT p.*, u.id AS usuario_id, u.activo AS usuario_activo, u.pendiente_activacion, u.ultimo_acceso, u.rol AS usuario_rol,
        (SELECT COUNT(*) FROM adjuntos a WHERE a.tabla = 'proveedores' AND a.registro_id = p.id AND a.rechazo_id IS NULL) AS docs_expediente
    FROM proveedores p LEFT JOIN usuarios u ON u.proveedor_id = p.id ORDER BY p.nombre")->fetchAll();
$prov = null;
if ($action === 'expediente') {
    foreach ($proveedores as $p) if ((int)$p['id'] === $id) $prov = $p;
    if (!$prov) { header('Location: ' . BASE_URL . 'proveedores.php'); exit; }
}
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-truck"></i> Proveedores</h1>
  <?php if ($prov): ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php else: ?>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-prov"><i class="fas fa-plus"></i> Nuevo Proveedor</button>
  <?php endif; ?>
</div>

<?php if ($prov): // Expediente del proveedor: sus datos y sus documentos por categoría ?>
<div class="card mb-3">
  <div class="card-header">
    <i class="fas <?= $prov['categoria'] === 'consultor' ? 'fa-user-tie' : 'fa-truck' ?>"></i> <?= htmlspecialchars($prov['nombre']) ?>
    <span class="badge <?= $prov['categoria'] === 'consultor' ? 'bg-info text-dark' : 'bg-secondary' ?> ms-2"><?= CATEGORIAS_PROVEEDOR[$prov['categoria']] ?? ucfirst($prov['categoria']) ?></span>
    <?= $prov['activo'] ? '' : '<span class="badge bg-danger ms-1">Inactivo</span>' ?>
    <button class="btn btn-sm btn-outline-primary ms-auto" onclick='editProv(<?= json_encode($prov, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i> Editar datos</button>
  </div>
  <div class="card-body">
    <div class="row g-2" style="font-size:.85rem">
      <div class="col-md-3"><strong>Código:</strong> <span class="font-mono"><?= htmlspecialchars($prov['codigo'] ?? '—') ?></span></div>
      <div class="col-md-3"><strong>RTN:</strong> <?= htmlspecialchars($prov['rtn'] ?: '—') ?></div>
      <?php if ($prov['categoria'] === 'consultor'): ?><div class="col-md-3"><strong>Identidad:</strong> <?= htmlspecialchars($prov['identidad'] ?: '—') ?></div><?php endif; ?>
      <div class="col-md-3"><strong>Contacto:</strong> <?= htmlspecialchars($prov['contacto'] ?: '—') ?></div>
      <div class="col-md-3"><strong>Teléfono:</strong> <?= htmlspecialchars($prov['telefono'] ?: '—') ?></div>
      <div class="col-md-3"><strong>Correo:</strong> <?= htmlspecialchars($prov['email'] ?: '—') ?></div>
      <div class="col-md-6"><strong>Cuenta bancaria:</strong> <?= $prov['banco'] ? htmlspecialchars(trim($prov['banco'] . ' ' . ($prov['banco_cuenta'] ?? '') . ($prov['banco_tipo'] ? ' · ' . ucfirst($prov['banco_tipo']) : ''))) : '—' ?></div>
      <?php if ($prov['direccion']): ?><div class="col-12"><strong>Dirección:</strong> <?= htmlspecialchars($prov['direccion']) ?></div><?php endif; ?>
    </div>
  </div>
</div>
<?php $adjModulo = 'proveedores.php'; $adjId = $prov['id']; include __DIR__ . '/includes/adjuntos.php'; ?>

<?php else: ?>
<div class="card"><div class="card-body p-0">
  <table id="tbl-prov" class="table-proscala w-100">
    <thead><tr><th>Código</th><th>Nombre</th><th>RTN</th><th>Contacto</th><th>Teléfono</th><th>Cuenta Bancaria</th><th>Categoría</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach($proveedores as $p): ?>
      <tr>
        <td class="font-mono"><?= htmlspecialchars($p['codigo']??'') ?></td>
        <td><?= htmlspecialchars($p['nombre']) ?>
          <?php if ($p['docs_expediente']): ?><a href="?action=expediente&id=<?= $p['id'] ?>" class="badge bg-light text-dark border ms-1 text-decoration-none" title="Documentos en su expediente"><i class="fas fa-folder-open"></i> <?= (int)$p['docs_expediente'] ?></a><?php endif; ?></td>
        <td><?= $p['rtn'] ?></td><td><?= htmlspecialchars($p['contacto']??'') ?></td>
        <td><?= $p['telefono'] ?></td>
        <td style="font-size:.8rem">
          <?php if ($p['banco']): ?>
          <div style="font-weight:600"><?= htmlspecialchars($p['banco']) ?></div>
          <div style="color:var(--text-3)"><?= htmlspecialchars($p['banco_cuenta']??'') ?> <?= $p['banco_tipo'] ? '· '.ucfirst($p['banco_tipo']) : '' ?></div>
          <?php else: ?>
          <span style="color:var(--text-3)">—</span>
          <?php endif; ?>
        </td>
        <td>
          <span class="badge <?= $p['categoria'] === 'consultor' ? 'bg-info text-dark' : 'bg-secondary' ?>"><?= CATEGORIAS_PROVEEDOR[$p['categoria']] ?? ucfirst($p['categoria']) ?></span>
          <?php if ($p['usuario_id']): // usuario del consultor ?>
          <div style="font-size:.72rem;color:var(--text-3);margin-top:2px">
            <?php if (!$p['usuario_activo']): ?><i class="fas fa-user-slash"></i> Sin acceso
            <?php elseif ($p['pendiente_activacion']): ?><i class="fas fa-envelope"></i> Acceso pendiente de activar
            <?php else: ?><i class="fas fa-user-check text-success"></i> Con acceso · <?= htmlspecialchars(rolLabel($p['usuario_rol'])) ?><?php endif; ?>
          </div>
          <?php endif; ?>
        </td>
        <td><?= $p['activo']?'<span class="badge bg-success">Activo</span>':'<span class="badge bg-danger">Inactivo</span>' ?></td>
        <td>
          <a href="?action=expediente&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Expediente: documentos del proveedor"><i class="fas fa-folder-open"></i></a>
          <button class="btn btn-sm btn-outline-primary py-0 px-2 ms-1" onclick='editProv(<?= json_encode($p, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" onclick="deleteRecord('proveedores.php',<?= $p['id'] ?>,()=>Nav.recargar())"><i class="fas fa-ban"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<?php endif; ?>

<div class="modal fade" id="modal-prov" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-truck"></i> <span id="prov-modal-title">Nuevo Proveedor</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-prov">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar">
          <input type="hidden" name="id" id="p-id" value="0">
          <div id="p-codigo-wrap" style="display:none" class="mb-2">
            <span style="font-size:.75rem;color:var(--text-3)">Código:</span>
            <span id="p-codigo-display" class="font-mono ms-1" style="font-size:.82rem;color:var(--text-2)"></span>
          </div>
          <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Nombre *</label><input type="text" name="nombre" id="p-nombre" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">RTN</label><input type="text" name="rtn" id="p-rtn" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">Contacto</label><input type="text" name="contacto" id="p-contacto" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Teléfono</label><input type="text" name="telefono" id="p-tel" class="form-control"></div>
            <div class="col-md-5"><label class="form-label">Email</label><input type="email" name="email" id="p-email" class="form-control"></div>
            <div class="col-12"><label class="form-label">Dirección</label><input type="text" name="direccion" id="p-dir" class="form-control"></div>
            <div class="col-md-3">
              <label class="form-label">Categoría</label>
              <select name="categoria" id="p-cat" class="form-select" onchange="toggleConsultor()">
                <?php foreach (CATEGORIAS_PROVEEDOR as $v => $n): ?><option value="<?= $v ?>" <?= $v === 'ambos' ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2 d-flex align-items-end pb-1">
              <div class="form-check"><input type="checkbox" name="activo" class="form-check-input" id="p-activo" checked><label class="form-check-label" for="p-activo">Activo</label></div>
            </div>

            <!-- Consultor: datos personales y su usuario del sistema (sincronizarUsuarioProveedor) -->
            <div class="col-12 grp-consultor" hidden>
              <hr style="border-color:var(--border);margin:.25rem 0 .75rem">
              <div style="font-size:.78rem;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em" class="mb-2">Consultor · Acceso al sistema</div>
              <div class="row g-3">
                <div class="col-md-4"><label class="form-label">N° de Identidad</label><input type="text" name="identidad" id="p-identidad" class="form-control" maxlength="20" placeholder="0801-1990-12345"></div>
                <div class="col-md-8 d-flex align-items-end pb-1">
                  <div class="form-check form-switch">
                    <input type="checkbox" name="acceso_sistema" class="form-check-input" id="p-acceso" onchange="toggleConsultor()">
                    <label class="form-check-label" for="p-acceso">Darle usuario para entrar al sistema y pedir sus anticipos y liquidaciones</label>
                  </div>
                </div>
                <div class="col-12 grp-acceso" hidden>
                  <div class="row g-3">
                    <div class="col-md-5">
                      <label class="form-label">Usuario</label>
                      <input type="text" class="form-control" id="p-usuario" readonly placeholder="El correo de arriba">
                      <div class="form-text">Entra con este correo y el RTN de la empresa.</div>
                    </div>
                    <div class="col-md-4">
                      <label class="form-label">Contraseña inicial <small class="text-muted">(opcional)</small></label>
                      <div class="input-group">
                        <input type="password" name="clave_inicial" id="p-clave" class="form-control" autocomplete="new-password" minlength="<?= MIN_PASSWORD ?>">
                        <button type="button" class="btn btn-outline-secondary" title="Mostrar" onclick="const i=document.getElementById('p-clave');i.type=i.type==='password'?'text':'password'"><i class="fas fa-eye"></i></button>
                      </div>
                      <div class="form-text">Mínimo <?= MIN_PASSWORD ?> caracteres, letras y números; la cambia al entrar. Vacía: recibe por correo el enlace para crearla.</div>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">Puesto</label>
                      <input type="text" class="form-control" readonly value="<?= htmlspecialchars(rolLabel(ROL_CONSULTOR)) ?>">
                      <div class="form-text">Sus permisos y a quién reporta: <?= tieneAccesoTotal() ? '<a href="roles.php">Roles y Permisos</a>' : 'Roles y Permisos' ?>.</div>
                    </div>
                    <div class="col-12" id="p-estado-usuario" style="font-size:.82rem"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Datos bancarios -->
            <div class="col-12"><hr style="border-color:var(--border);margin:.25rem 0"><div style="font-size:.78rem;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Cuenta Bancaria</div></div>
            <div class="col-md-4">
              <label class="form-label">Banco</label>
              <select name="banco" id="p-banco" class="form-select" data-alta="banco">
                <option value="">— Sin especificar —</option>
                <?php
                $bancosLista = $pdo->query("SELECT nombre FROM cat_bancos ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($bancosLista as $b): ?>
                <option value="<?= htmlspecialchars($b) ?>"><?= htmlspecialchars($b) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Número de Cuenta</label><input type="text" name="banco_cuenta" id="p-banco-cuenta" class="form-control" placeholder="XXXX-XXXX-XXXX"></div>
            <div class="col-md-4">
              <label class="form-label">Tipo de Cuenta</label>
              <select name="banco_tipo" id="p-banco-tipo" class="form-select">
                <option value="">— Sin especificar —</option>
                <option value="ahorros">Ahorros</option>
                <option value="cheques">Cheques</option>
              </select>
            </div>
          </div>
          <?php // Documentos del expediente: se suben al guardar (los ya subidos, en su expediente)
          $adjModulo = 'proveedores.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
          <div id="p-ver-expediente" class="small mt-2" hidden></div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="saveProv()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'

initDataTable('#tbl-prov');

// Categoría Consultor: identidad y acceso al sistema; con acceso, su usuario es el correo
function toggleConsultor() {
  const esConsultor = document.getElementById('p-cat').value === 'consultor';
  const acceso = esConsultor && document.getElementById('p-acceso').checked;
  document.querySelector('#form-prov .grp-consultor').hidden = !esConsultor;
  document.querySelector('#form-prov .grp-acceso').hidden = !acceso;
  document.getElementById('p-email').required = acceso;
  document.getElementById('p-usuario').value = document.getElementById('p-email').value;
}
document.getElementById('p-email').addEventListener('input', toggleConsultor);

// Estado del usuario del consultor que se edita (y el botón para reenviarle la activación)
function estadoUsuario(p) {
  const el = document.getElementById('p-estado-usuario');
  if (!p || !p.usuario_id) { el.innerHTML = ''; return; }
  if (p.usuario_activo != 1) el.innerHTML = '<span class="text-muted"><i class="fas fa-user-slash"></i> Su usuario está desactivado; al guardar con el acceso marcado se reactiva.</span>';
  else if (p.pendiente_activacion == 1) el.innerHTML = '<span class="text-warning-emphasis"><i class="fas fa-envelope"></i> Aún no activa su cuenta.</span> '
    + `<button type="button" class="btn btn-sm btn-outline-secondary py-0 ms-1" onclick="reenviarActivacionProv(${p.id})"><i class="fas fa-paper-plane"></i> Reenviar enlace</button>`;
  else el.innerHTML = '<span class="text-success"><i class="fas fa-user-check"></i> Tiene acceso.</span> '
    + (p.ultimo_acceso ? 'Último ingreso: ' + p.ultimo_acceso : 'Aún no ha ingresado.') + ' Escriba una contraseña inicial solo si quiere reemplazar la suya.';
}
async function reenviarActivacionProv(id) {
  const fd = new FormData();
  fd.append('_action', 'reenviar_activacion'); fd.append('id', id);
  fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
  try { const r = await post('proveedores.php', fd); Toast.show(r.message, r.success ? 'success' : 'error'); }
  catch (e) { Toast.show(e.message, 'error'); }
}

function editProv(p){
  document.getElementById('p-id').value=p.id;
  document.getElementById('p-codigo-display').textContent=p.codigo||'';
  document.getElementById('p-codigo-wrap').style.display='';
  document.getElementById('p-nombre').value=p.nombre;
  document.getElementById('p-rtn').value=p.rtn||'';
  document.getElementById('p-contacto').value=p.contacto||'';
  document.getElementById('p-tel').value=p.telefono||'';
  document.getElementById('p-email').value=p.email||'';
  document.getElementById('p-dir').value=p.direccion||'';
  document.getElementById('p-cat').value=p.categoria;
  $('#p-cat').trigger('change');
  document.getElementById('p-activo').checked=p.activo==1;
  document.getElementById('p-banco').value=p.banco||'';
  document.getElementById('p-banco-cuenta').value=p.banco_cuenta||'';
  document.getElementById('p-banco-tipo').value=p.banco_tipo||'';
  $('#p-banco-tipo').trigger('change');
  document.getElementById('p-identidad').value=p.identidad||'';
  document.getElementById('p-acceso').checked=!!p.usuario_id && p.usuario_activo==1;
  document.getElementById('p-clave').value='';
  estadoUsuario(p);
  toggleConsultor();
  const verExp = document.getElementById('p-ver-expediente');
  verExp.hidden = false;
  verExp.innerHTML = `<i class="fas fa-folder-open"></i> ${p.docs_expediente > 0 ? 'Tiene ' + p.docs_expediente + ' documento' + (p.docs_expediente == 1 ? '' : 's') + ' en su expediente.' : 'Su expediente aún no tiene documentos.'} `
    + `<a href="proveedores.php?action=expediente&id=${p.id}">Ver expediente</a>`;
  document.getElementById('prov-modal-title').textContent='Editar: '+p.nombre;
  new bootstrap.Modal(document.getElementById('modal-prov')).show();
}
document.getElementById('modal-prov').addEventListener('show.bs.modal', function(e){
  if (!e.relatedTarget) return; // opened via editProv(), already populated
  document.getElementById('p-id').value=0;
  document.getElementById('form-prov').reset();
  document.getElementById('p-codigo-wrap').style.display='none';
  $('#p-cat').val('ambos').trigger('change.select2');
  estadoUsuario(null);
  toggleConsultor();
  document.getElementById('p-ver-expediente').hidden = true;
  document.getElementById('prov-modal-title').textContent='Nuevo Proveedor';
});
async function saveProv(){
  const form = document.getElementById('form-prov');
  AdjuntosForm.anotar(form);   // los documentos elegidos se suben al proveedor guardado
  const r=await post('proveedores.php',new FormData(form));
  if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.recargar(),1000);}
  else Toast.show(r.message,'error');
}

JS;
include __DIR__ . '/includes/footer.php';
?>

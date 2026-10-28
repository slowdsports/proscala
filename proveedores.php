<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Proveedores';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $pid = (int)($_POST['id'] ?? 0);
        $d = ['nombre'=>trim($_POST['nombre']),
              'rtn'=>trim($_POST['rtn']??''),'contacto'=>trim($_POST['contacto']??''),
              'telefono'=>trim($_POST['telefono']??''),'email'=>trim($_POST['email']??''),
              'direccion'=>trim($_POST['direccion']??''),'categoria'=>$_POST['categoria']??'ambos',
              'banco'=>trim($_POST['banco']??'')?:null,
              'banco_cuenta'=>trim($_POST['banco_cuenta']??'')?:null,
              'banco_tipo'=>in_array($_POST['banco_tipo']??'',['ahorros','cheques'])?$_POST['banco_tipo']:null,
              'activo'=>isset($_POST['activo'])?1:0];
        try {
            if ($pid) {
                $pdo->prepare("UPDATE proveedores SET nombre=?,rtn=?,contacto=?,telefono=?,email=?,direccion=?,categoria=?,banco=?,banco_cuenta=?,banco_tipo=?,activo=? WHERE id=?")
                    ->execute([...array_values($d), $pid]);
                jsonOk([],'Proveedor actualizado.');
            } else {
                $codigo = generarNumero($pdo, 'proveedores', 'codigo', 'PROV');
                $pdo->prepare("INSERT INTO proveedores (codigo,nombre,rtn,contacto,telefono,email,direccion,categoria,banco,banco_cuenta,banco_tipo,activo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$codigo, ...array_values($d)]);
                jsonOk(['id'=>$pdo->lastInsertId()],'Proveedor creado.');
            }
        } catch(PDOException $e){ jsonErr($e->getMessage()); }
    }
    if ($_act === 'eliminar') { $pdo->prepare("UPDATE proveedores SET activo=0 WHERE id=?")->execute([(int)$_POST['id']]); jsonOk([],'Proveedor dado de baja.'); }
    jsonErr('Acción desconocida.');
}

$proveedores = $pdo->query("SELECT * FROM proveedores ORDER BY nombre")->fetchAll();
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-truck"></i> Proveedores</h1>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-prov"><i class="fas fa-plus"></i> Nuevo Proveedor</button>
</div>
<div class="card"><div class="card-body p-0">
  <table id="tbl-prov" class="table-proscala w-100">
    <thead><tr><th>Código</th><th>Nombre</th><th>RTN</th><th>Contacto</th><th>Teléfono</th><th>Cuenta Bancaria</th><th>Categoría</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach($proveedores as $p): ?>
      <tr>
        <td class="font-mono"><?= htmlspecialchars($p['codigo']??'') ?></td>
        <td><?= htmlspecialchars($p['nombre']) ?></td>
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
        <td><span class="badge bg-secondary"><?= ucfirst($p['categoria']) ?></span></td>
        <td><?= $p['activo']?'<span class="badge bg-success">Activo</span>':'<span class="badge bg-danger">Inactivo</span>' ?></td>
        <td>
          <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick='editProv(<?= json_encode($p) ?>)'><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" onclick="deleteRecord('proveedores.php',<?= $p['id'] ?>,()=>Nav.recargar())"><i class="fas fa-ban"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

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
              <select name="categoria" id="p-cat" class="form-select">
                <option value="bienes">Bienes</option><option value="servicios">Servicios</option><option value="ambos" selected>Ambos</option>
              </select>
            </div>
            <div class="col-md-2 d-flex align-items-end pb-1">
              <div class="form-check"><input type="checkbox" name="activo" class="form-check-input" id="p-activo" checked><label class="form-check-label" for="p-activo">Activo</label></div>
            </div>

            <!-- Datos bancarios -->
            <div class="col-12"><hr style="border-color:var(--border);margin:.25rem 0"><div style="font-size:.78rem;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.06em">Cuenta Bancaria</div></div>
            <div class="col-md-4">
              <label class="form-label">Banco</label>
              <select name="banco" id="p-banco" class="form-select">
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
  document.getElementById('prov-modal-title').textContent='Editar: '+p.nombre;
  new bootstrap.Modal(document.getElementById('modal-prov')).show();
}
document.getElementById('modal-prov').addEventListener('show.bs.modal', function(e){
  if (!e.relatedTarget) return; // opened via editProv(), already populated
  document.getElementById('p-id').value=0;
  document.getElementById('form-prov').reset();
  document.getElementById('p-codigo-wrap').style.display='none';
  document.getElementById('prov-modal-title').textContent='Nuevo Proveedor';
});
async function saveProv(){
  const r=await post('proveedores.php',new FormData(document.getElementById('form-prov')));
  if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.recargar(),1000);}
  else Toast.show(r.message,'error');
}

JS;
include __DIR__ . '/includes/footer.php';
?>

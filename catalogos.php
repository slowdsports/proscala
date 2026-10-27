<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Catálogos';

// Catálogos de nombres. Los registros guardan el nombre (no el id): renombrar actualiza
// los registros que lo usan y no se elimina mientras alguno lo use.
//   usos → [tabla, columna, descripción] donde se guarda el nombre
const CATALOGOS_SIMPLES = [
    'cargos'        => ['tabla' => 'cat_cargos',        'label' => 'Cargo',        'plural' => 'Cargos',        'icono' => 'fa-briefcase',
                        'usos'  => [['empleados', 'cargo', 'empleado(s)']]],
    'departamentos' => ['tabla' => 'cat_departamentos', 'label' => 'Departamento', 'plural' => 'Departamentos', 'icono' => 'fa-sitemap',
                        'usos'  => [['empleados', 'departamento', 'empleado(s)']]],
    'bancos'        => ['tabla' => 'cat_bancos',        'label' => 'Banco',        'plural' => 'Bancos',        'icono' => 'fa-building-columns',
                        'usos'  => [['empleados', 'banco', 'empleado(s)'], ['proveedores', 'banco', 'proveedor(es)'], ['ordenes_pago', 'banco', 'orden(es) de pago']]],
];
$TIPOS_CUENTA = ['activo'=>'Activo','pasivo'=>'Pasivo','capital'=>'Capital','ingreso'=>'Ingreso','gasto'=>'Gasto','otro_ingreso'=>'Otro Ingreso','otro_gasto'=>'Otro Gasto'];

// Cuántos registros usan cada nombre del catálogo: [nombre => [descripción => n]]
function usosCatalogo(PDO $pdo, array $def): array {
    $usos = [];
    foreach ($def['usos'] as [$tabla, $col, $desc]) {
        foreach ($pdo->query("SELECT `$col` AS nombre, COUNT(*) AS n FROM `$tabla` WHERE `$col` <> '' GROUP BY `$col`") as $r) {
            $usos[$r['nombre']][$desc] = (int)$r['n'];
        }
    }
    return $usos;
}

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    // ── Catálogo contable ─────────────────────────────────────────────
    if ($_act === 'guardar') {
        $cId = (int)($_POST['id'] ?? 0);
        $d = ['codigo'=>trim($_POST['codigo']),'nombre'=>trim($_POST['nombre']),
              'tipo'=>$_POST['tipo'],'subtipo'=>trim($_POST['subtipo']??''),
              'cuenta_padre_id'=>$_POST['cuenta_padre_id']?:null,
              'nivel'=>(int)($_POST['nivel']??1),'descripcion'=>trim($_POST['descripcion']??''),
              'activa'=>isset($_POST['activa'])?1:0];
        try {
            if ($cId) {
                $pdo->prepare("UPDATE cuentas_contables SET codigo=?,nombre=?,tipo=?,subtipo=?,cuenta_padre_id=?,nivel=?,descripcion=?,activa=? WHERE id=?")
                    ->execute([...array_values($d), $cId]);
                jsonOk([],'Cuenta actualizada.');
            } else {
                $pdo->prepare("INSERT INTO cuentas_contables (codigo,nombre,tipo,subtipo,cuenta_padre_id,nivel,descripcion,activa) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute(array_values($d));
                jsonOk(['id'=>$pdo->lastInsertId()],'Cuenta creada.');
            }
        } catch(PDOException $e){ jsonErr($e->getCode()==23000?'El código ya existe.':$e->getMessage()); }
    }
    if ($_act === 'eliminar') {
        $pdo->prepare("UPDATE cuentas_contables SET activa=0 WHERE id=?")->execute([(int)$_POST['id']]);
        jsonOk([],'Cuenta desactivada.');
    }

    // ── Cargos, departamentos y bancos ────────────────────────────────
    $def = CATALOGOS_SIMPLES[$_POST['catalogo'] ?? ''] ?? null;
    if ($def && $_act === 'guardar_catalogo') {
        $cid    = (int)($_POST['id'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        if ($nombre === '') jsonErr("El nombre del {$def['label']} es requerido.");
        $pdo->beginTransaction();
        try {
            if ($cid) {
                $s = $pdo->prepare("SELECT nombre FROM `{$def['tabla']}` WHERE id=?");
                $s->execute([$cid]);
                $anterior = $s->fetchColumn();
                if ($anterior === false) jsonErr("{$def['label']} no encontrado.");
                $pdo->prepare("UPDATE `{$def['tabla']}` SET nombre=? WHERE id=?")->execute([$nombre, $cid]);
                // El nuevo nombre llega a todos los registros que usaban el anterior
                foreach ($def['usos'] as [$tabla, $col]) {
                    $pdo->prepare("UPDATE `$tabla` SET `$col`=? WHERE `$col`=?")->execute([$nombre, $anterior]);
                }
            } else {
                $pdo->prepare("INSERT INTO `{$def['tabla']}` (nombre) VALUES (?)")->execute([$nombre]);
                $cid = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonErr($e->getCode() == 23000 ? "Ya existe un {$def['label']} con ese nombre." : $e->getMessage());
        }
        jsonOk(['id' => $cid], "{$def['label']} guardado.");
    }
    if ($def && $_act === 'eliminar_catalogo') {
        $s = $pdo->prepare("SELECT nombre FROM `{$def['tabla']}` WHERE id=?");
        $s->execute([(int)$_POST['id']]);
        $nombre = $s->fetchColumn();
        if ($nombre === false) jsonErr("{$def['label']} no encontrado.");
        $enUso = usosCatalogo($pdo, $def)[$nombre] ?? [];
        if ($enUso) {
            jsonErr("No se puede eliminar: lo usan " . implode(', ', array_map(fn($d, $n) => "$n $d", array_keys($enUso), $enUso)) . '.');
        }
        $pdo->prepare("DELETE FROM `{$def['tabla']}` WHERE id=?")->execute([(int)$_POST['id']]);
        jsonOk([], "{$def['label']} eliminado.");
    }
    jsonErr('Acción desconocida.');
}

// ── Pestaña actual ─────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'cuentas';
if ($tab !== 'cuentas' && !isset(CATALOGOS_SIMPLES[$tab])) $tab = 'cuentas';

if ($tab === 'cuentas') {
    $cuentas = $pdo->query("SELECT c.*,p.nombre as padre_nombre FROM cuentas_contables c LEFT JOIN cuentas_contables p ON c.cuenta_padre_id=p.id ORDER BY c.codigo")->fetchAll();
    $padres  = $pdo->query("SELECT id,codigo,nombre FROM cuentas_contables WHERE nivel=1 ORDER BY codigo")->fetchAll();
} else {
    $def   = CATALOGOS_SIMPLES[$tab];
    $registros = $pdo->query("SELECT id, nombre FROM `{$def['tabla']}` ORDER BY nombre")->fetchAll();
    $usos  = usosCatalogo($pdo, $def);
}

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-book"></i> Catálogos</h1>
  <div class="d-flex gap-2">
    <?php if ($tab === 'cuentas'): ?>
    <button class="btn-proscala-outline" data-bs-toggle="collapse" data-bs-target="#filtros"><i class="fas fa-filter"></i> Filtrar</button>
    <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-cuenta"><i class="fas fa-plus"></i> Nueva Cuenta</button>
    <?php else: ?>
    <button class="btn-proscala" onclick="abrirCatalogo()"><i class="fas fa-plus"></i> Nuevo <?= htmlspecialchars($def['label']) ?></button>
    <?php endif; ?>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $tab === 'cuentas' ? 'active' : '' ?>" href="?tab=cuentas"><i class="fas fa-book"></i> Catálogo contable</a></li>
  <?php foreach (CATALOGOS_SIMPLES as $k => $c): ?>
  <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?tab=<?= $k ?>"><i class="fas <?= $c['icono'] ?>"></i> <?= $c['plural'] ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'cuentas'): ?>
<!-- Filtro rápido por tipo -->
<div class="collapse mb-3" id="filtros">
  <div class="card card-body">
    <div class="d-flex gap-2 flex-wrap">
      <button class="btn btn-sm btn-outline-secondary filter-btn active" data-filter="">Todas</button>
      <?php foreach($TIPOS_CUENTA as $k=>$v): ?>
      <button class="btn btn-sm btn-outline-primary filter-btn" data-filter="<?= $k ?>"><?= $v ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <table id="tbl-cuentas" class="table-proscala w-100">
      <thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th>Subtipo</th><th>Nivel</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($cuentas as $c): ?>
        <tr data-tipo="<?= $c['tipo'] ?>">
          <td class="font-mono"><?= htmlspecialchars($c['codigo']) ?></td>
          <td><?= str_repeat('&nbsp;&nbsp;&nbsp;', max(0, $c['nivel']-1)) ?><?= htmlspecialchars($c['nombre']) ?></td>
          <td><?= $TIPOS_CUENTA[$c['tipo']]??$c['tipo'] ?></td>
          <td><?= htmlspecialchars($c['subtipo'] ?? '') ?></td>
          <td><span class="badge bg-secondary"><?= $c['nivel'] ?></span></td>
          <td><?= $c['activa'] ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-danger">Inactiva</span>' ?></td>
          <td>
            <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick='editCuenta(<?= json_encode($c) ?>)'><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" title="Desactivar" onclick="deleteRecord('catalogos.php',<?= $c['id'] ?>,()=>Nav.recargar())"><i class="fas fa-ban"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal cuenta -->
<div class="modal fade" id="modal-cuenta" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-book"></i> <span id="modal-title-text">Nueva Cuenta</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-cuenta">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar">
          <input type="hidden" name="id" id="f-id" value="0">
          <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Código *</label><input type="text" name="codigo" id="f-codigo" class="form-control" required maxlength="20"></div>
            <div class="col-md-7"><label class="form-label">Nombre *</label><input type="text" name="nombre" id="f-nombre" class="form-control" required></div>
            <div class="col-md-2"><label class="form-label">Nivel</label><input type="number" name="nivel" id="f-nivel" class="form-control" min="1" max="5" value="2"></div>
            <div class="col-md-4">
              <label class="form-label">Tipo *</label>
              <select name="tipo" id="f-tipo" class="form-select" required>
                <?php foreach($TIPOS_CUENTA as $k=>$v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4"><label class="form-label">Subtipo</label><input type="text" name="subtipo" id="f-subtipo" class="form-control" placeholder="ej: Banco, Nómina, etc."></div>
            <div class="col-md-4">
              <label class="form-label">Cuenta Padre</label>
              <select name="cuenta_padre_id" id="f-padre" class="form-select">
                <option value="">-- Ninguna --</option>
                <?php foreach($padres as $p): ?><option value="<?= $p['id'] ?>"><?= $p['codigo'] ?> — <?= htmlspecialchars($p['nombre']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12"><label class="form-label">Descripción</label><textarea name="descripcion" id="f-desc" class="form-control" rows="2"></textarea></div>
            <div class="col-12"><div class="form-check"><input type="checkbox" name="activa" class="form-check-input" id="f-activa" checked><label class="form-check-label" for="f-activa">Activa</label></div></div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="saveCuenta()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<div class="card">
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th><?= htmlspecialchars($def['label']) ?></th><th>En uso</th><th style="width:90px"></th></tr></thead>
      <tbody>
        <?php foreach ($registros as $it): $u = $usos[$it['nombre']] ?? []; ?>
        <tr>
          <td><?= htmlspecialchars($it['nombre']) ?></td>
          <td class="text-muted" style="font-size:.82rem;"><?= $u ? htmlspecialchars(implode(', ', array_map(fn($d, $n) => "$n $d", array_keys($u), $u))) : '—' ?></td>
          <td class="text-end" style="white-space:nowrap">
            <button class="btn btn-sm btn-outline-primary py-0 px-2" title="Editar" onclick='abrirCatalogo(<?= json_encode(['id' => $it['id'], 'nombre' => $it['nombre']]) ?>)'><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" title="Eliminar" <?= $u ? 'disabled' : '' ?> onclick='eliminarCatalogo(<?= json_encode(['id' => $it['id'], 'nombre' => $it['nombre']]) ?>)'><i class="fas fa-trash"></i></button>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$registros): ?><tr><td colspan="3" class="text-center text-muted py-4">Sin registros</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-muted mt-2" style="font-size:.78rem;"><i class="fas fa-circle-info"></i>
  Al renombrar, el cambio llega a todos los registros que lo usan. Solo se puede eliminar lo que nadie usa.</p>

<!-- Modal de cargo / departamento / banco -->
<div class="modal fade" id="modal-catalogo" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2"><h6 class="modal-title" id="cat-titulo"></h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-catalogo" onsubmit="event.preventDefault(); guardarCatalogo();">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar_catalogo">
          <input type="hidden" name="catalogo" value="<?= $tab ?>">
          <input type="hidden" name="id" id="cat-id" value="0">
          <label class="form-label fw-semibold">Nombre *</label>
          <input type="text" name="nombre" id="cat-nombre" class="form-control" maxlength="150" required>
        </form>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala btn-sm" onclick="guardarCatalogo()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
if ($tab === 'cuentas') {
    $extraJs = "
initDataTable('#tbl-cuentas', {order:[[0,'asc']]});

document.querySelectorAll('.filter-btn').forEach(b=>{
  b.addEventListener('click',function(){
    document.querySelectorAll('.filter-btn').forEach(x=>x.classList.remove('active'));
    this.classList.add('active');
    const f=this.dataset.filter;
    document.querySelectorAll('#tbl-cuentas tbody tr').forEach(r=>{
      r.style.display=(!f||r.dataset.tipo===f)?'':'none';
    });
  });
});

function editCuenta(c){
  document.getElementById('f-id').value=c.id;
  document.getElementById('f-codigo').value=c.codigo;
  document.getElementById('f-nombre').value=c.nombre;
  document.getElementById('f-nivel').value=c.nivel;
  document.getElementById('f-tipo').value=c.tipo;
  $('#f-tipo').trigger('change');
  document.getElementById('f-subtipo').value=c.subtipo||'';
  document.getElementById('f-padre').value=c.cuenta_padre_id||'';
  $('#f-padre').trigger('change');
  document.getElementById('f-desc').value=c.descripcion||'';
  document.getElementById('f-activa').checked=c.activa==1;
  document.getElementById('modal-title-text').textContent='Editar: '+c.codigo+' '+c.nombre;
  new bootstrap.Modal(document.getElementById('modal-cuenta')).show();
}

async function saveCuenta(){
  try {
    const r=await post('catalogos.php',new FormData(document.getElementById('form-cuenta')));
    Toast.show(r.message,'success'); setTimeout(()=>Nav.recargar(),800);
  } catch(e){ Toast.show(e.message,'error'); }
}
";
} else {
    $label = json_encode($def['label'], JSON_UNESCAPED_UNICODE);
    $extraJs = "
function abrirCatalogo(c){
  document.getElementById('cat-id').value = c ? c.id : 0;
  document.getElementById('cat-nombre').value = c ? c.nombre : '';
  document.getElementById('cat-titulo').textContent = (c ? 'Editar ' : 'Nuevo ') + $label;
  new bootstrap.Modal(document.getElementById('modal-catalogo')).show();
  setTimeout(()=>document.getElementById('cat-nombre').focus(), 350);
}
async function guardarCatalogo(){
  try {
    const r = await post('catalogos.php', new FormData(document.getElementById('form-catalogo')));
    Toast.show(r.message,'success'); setTimeout(()=>Nav.recargar(),700);
  } catch(e){ Toast.show(e.message,'error'); }
}
async function eliminarCatalogo(c){
  if (!await confirmar('¿Eliminar \"' + c.nombre + '\"?', { titulo: 'Eliminar', aceptar: 'Eliminar', peligro: true })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('meta[name=csrf]').content);
  fd.append('_action','eliminar_catalogo'); fd.append('catalogo','$tab'); fd.append('id', c.id);
  try { const r = await post('catalogos.php', fd); Toast.show(r.message,'success'); setTimeout(()=>Nav.recargar(),700); }
  catch(e){ Toast.show(e.message,'error'); }
}
";
}
include __DIR__ . '/includes/footer.php';
?>

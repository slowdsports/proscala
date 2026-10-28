<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Activos Fijos';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $aId = (int)($_POST['id'] ?? 0);
        $d = ['codigo'=>strtoupper(trim($_POST['codigo'])),'descripcion'=>trim($_POST['descripcion']),
              'categoria'=>trim($_POST['categoria']??''),'fecha_adquisicion'=>$_POST['fecha_adquisicion']?:null,
              'costo_adquisicion'=>(float)$_POST['costo_adquisicion'],'vida_util_anios'=>(int)($_POST['vida_util_anios']??0),
              'porcentaje_depreciacion'=>(float)($_POST['porcentaje_depreciacion']??0),
              'valor_residual'=>(float)($_POST['valor_residual']??0),
              'valor_libro'=>(float)($_POST['valor_libro']??$_POST['costo_adquisicion']),
              'ubicacion'=>trim($_POST['ubicacion']??''),'responsable_id'=>$_POST['responsable_id']?:null,
              'numero_serie'=>trim($_POST['numero_serie']??''),'proveedor_id'=>$_POST['proveedor_id']?:null,
              'estado'=>$_POST['estado']??'activo','notas'=>trim($_POST['notas']??'')];
        try {
            if ($aId) {
                $pdo->prepare("UPDATE activos_fijos SET codigo=?,descripcion=?,categoria=?,fecha_adquisicion=?,costo_adquisicion=?,vida_util_anios=?,porcentaje_depreciacion=?,valor_residual=?,valor_libro=?,ubicacion=?,responsable_id=?,numero_serie=?,proveedor_id=?,estado=?,notas=? WHERE id=?")
                    ->execute([...array_values($d), $aId]);
                jsonOk([],'Activo actualizado.');
            } else {
                $pdo->prepare("INSERT INTO activos_fijos (codigo,descripcion,categoria,fecha_adquisicion,costo_adquisicion,vida_util_anios,porcentaje_depreciacion,valor_residual,valor_libro,ubicacion,responsable_id,numero_serie,proveedor_id,estado,notas) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute(array_values($d));
                jsonOk(['id'=>$pdo->lastInsertId()],'Activo registrado.');
            }
        } catch(PDOException $e){ jsonErr($e->getCode()==23000?'Código duplicado.':$e->getMessage()); }
    }
    jsonErr('Acción desconocida.');
}

$activos = $pdo->query("SELECT a.*,CONCAT(e.nombre,' ',e.apellidos) as resp_nombre, p.nombre as prov_nombre FROM activos_fijos a LEFT JOIN empleados e ON a.responsable_id=e.id LEFT JOIN proveedores p ON a.proveedor_id=p.id ORDER BY a.categoria,a.codigo")->fetchAll();
$empleados   = $pdo->query("SELECT id,CONCAT(nombre,' ',apellidos) as nombre FROM empleados WHERE activo=1 ORDER BY apellidos")->fetchAll();
$proveedores = $pdo->query("SELECT id,nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$categorias  = $pdo->query("SELECT DISTINCT categoria FROM activos_fijos WHERE categoria IS NOT NULL ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);

// Summary stats
$totalCosto = $pdo->query("SELECT SUM(costo_adquisicion) FROM activos_fijos WHERE estado='activo'")->fetchColumn();
$totalLibro = $pdo->query("SELECT SUM(valor_libro) FROM activos_fijos WHERE estado='activo'")->fetchColumn();
$totalActivos = $pdo->query("SELECT COUNT(*) FROM activos_fijos WHERE estado='activo'")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-laptop"></i> Activos Fijos</h1>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-activo"><i class="fas fa-plus"></i> Nuevo Activo</button>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="kpi-card kpi-blue kpi-hover">
      <div class="kpi-icon"><i class="fas fa-laptop"></i></div>
      <div class="kpi-value"><?= $totalActivos ?></div>
      <div class="kpi-label">Total Activos</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="kpi-card kpi-teal kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-tag"></i></div>
      <div class="kpi-value"><?= lps($totalCosto??0) ?></div>
      <div class="kpi-label">Costo de Adquisición</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="kpi-card kpi-green kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-book"></i></div>
      <div class="kpi-value"><?= lps($totalLibro??0) ?></div>
      <div class="kpi-label">Valor en Libros</div>
    </div>
  </div>
</div>
<div class="card"><div class="card-body p-0">
  <table id="tbl-act" class="table-proscala w-100">
    <thead><tr><th>Código</th><th>Descripción</th><th>Categoría</th><th>Fecha Adq.</th><th>Costo</th><th>Valor Libro</th><th>Responsable</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach($activos as $a): ?>
      <tr>
        <td class="font-mono"><?= htmlspecialchars($a['codigo']) ?></td>
        <td><strong><?= htmlspecialchars($a['descripcion']) ?></strong><?php if($a['numero_serie']): ?><br><small class="text-muted">S/N: <?= htmlspecialchars($a['numero_serie']) ?></small><?php endif; ?></td>
        <td><?= htmlspecialchars($a['categoria']??'—') ?></td>
        <td><?= fmtFecha($a['fecha_adquisicion']??'') ?></td>
        <td class="font-mono"><?= lps($a['costo_adquisicion']) ?></td>
        <td class="font-mono"><?= lps($a['valor_libro']) ?></td>
        <td><?= htmlspecialchars($a['resp_nombre']??'—') ?></td>
        <td><?= estadoBadge($a['estado']) ?></td>
        <td><button class="btn btn-sm btn-outline-primary py-0 px-2" onclick='editActivo(<?= json_encode($a) ?>)'><i class="fas fa-edit"></i></button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>

<div class="modal fade" id="modal-activo" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-laptop"></i> <span id="activo-modal-title">Nuevo Activo</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-activo">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar">
          <input type="hidden" name="id" id="a-id" value="0">
          <div class="row g-3">
            <div class="col-md-2"><label class="form-label">Código *</label><input type="text" name="codigo" id="a-cod" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Descripción *</label><input type="text" name="descripcion" id="a-desc" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Categoría</label><input type="text" name="categoria" id="a-cat" class="form-control" list="dl-cat"><datalist id="dl-cat"><?php foreach($categorias as $c): ?><option><?= htmlspecialchars($c) ?></option><?php endforeach; ?><option>Mobiliario y Equipo de Oficina</option><option>Equipo de Cómputo</option><option>Vehículos</option><option>Equipo de Campo</option></datalist></div>
            <div class="col-md-2"><label class="form-label">Fecha Adq.</label><input type="text" name="fecha_adquisicion" id="a-fi" class="form-control date-input"></div>
            <div class="col-md-3"><label class="form-label">Costo de Adquisición</label><input type="number" name="costo_adquisicion" id="a-costo" class="form-control" step="0.01" min="0" value="0" oninput="calcDepre()"></div>
            <div class="col-md-2"><label class="form-label">Vida Útil (años)</label><input type="number" name="vida_util_anios" id="a-vu" class="form-control" min="1" max="50" value="5" oninput="calcDepre()"></div>
            <div class="col-md-2"><label class="form-label">% Depreciación</label><input type="number" name="porcentaje_depreciacion" id="a-pct" class="form-control" step="0.01" min="0" max="100" oninput="calcDepre()"></div>
            <div class="col-md-3"><label class="form-label">Valor Residual</label><input type="number" name="valor_residual" id="a-vr" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-3"><label class="form-label">Valor en Libros</label><input type="number" name="valor_libro" id="a-vl" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-2"><label class="form-label">No. Serie</label><input type="text" name="numero_serie" id="a-serie" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Ubicación</label><input type="text" name="ubicacion" id="a-ubic" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Responsable</label><select name="responsable_id" id="a-resp" class="form-select"><option value="">--</option><?php foreach($empleados as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label">Proveedor</label><select name="proveedor_id" id="a-prov" class="form-select"><option value="">--</option><?php foreach($proveedores as $p): ?><option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nombre']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><label class="form-label">Estado</label><select name="estado" id="a-est" class="form-select"><option value="activo">Activo</option><option value="baja">Baja</option><option value="vendido">Vendido</option><option value="robado">Robado</option><option value="donado">Donado</option></select></div>
            <div class="col-12"><label class="form-label">Notas</label><textarea name="notas" id="a-notas" class="form-control" rows="2"></textarea></div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="saveActivo()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php
$extraJs = <<<'JS'

initDataTable('#tbl-act');
function calcDepre(){
  const costo=parseFloat(document.getElementById('a-costo').value||0);
  const vu=parseFloat(document.getElementById('a-vu').value||1);
  const pct=vu>0?parseFloat((100/vu).toFixed(2)):0;
  document.getElementById('a-pct').value=pct;
  document.getElementById('a-vl').value=costo.toFixed(2);
}
function editActivo(a){
  const m={id:'a-id',cod:'a-cod',desc:'a-desc',cat:'a-cat',fi:'a-fi',costo:'a-costo',vu:'a-vu',pct:'a-pct',vr:'a-vr',vl:'a-vl',serie:'a-serie',ubic:'a-ubic',resp:'a-resp',prov:'a-prov',est:'a-est',notas:'a-notas'};
  const f={id:'id',cod:'codigo',desc:'descripcion',cat:'categoria',fi:'fecha_adquisicion',costo:'costo_adquisicion',vu:'vida_util_anios',pct:'porcentaje_depreciacion',vr:'valor_residual',vl:'valor_libro',serie:'numero_serie',ubic:'ubicacion',resp:'responsable_id',prov:'proveedor_id',est:'estado',notas:'notas'};
  Object.keys(m).forEach(k=>{const el=document.getElementById(m[k]);if(el)el.value=a[f[k]]||'';});
  document.getElementById('activo-modal-title').textContent='Editar: '+a.descripcion;
  new bootstrap.Modal(document.getElementById('modal-activo')).show();
}
async function saveActivo(){
  const r=await post('activos.php',new FormData(document.getElementById('form-activo')));
  if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.recargar(),1000);}
  else Toast.show(r.message,'error');
}

JS;
include __DIR__ . '/includes/footer.php';
?>


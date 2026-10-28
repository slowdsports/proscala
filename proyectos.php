<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Proyectos / Donantes';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $pid = (int)($_POST['id'] ?? 0);
        $d = ['codigo'=>strtoupper(trim($_POST['codigo'])),'nombre'=>trim($_POST['nombre']),
              'donante'=>trim($_POST['donante']??''),'descripcion'=>trim($_POST['descripcion']??''),
              'fecha_inicio'=>$_POST['fecha_inicio']?:null,'fecha_fin'=>$_POST['fecha_fin']?:null,
              'presupuesto_total'=>(float)($_POST['presupuesto_total']??0),
              'moneda'=>$_POST['moneda']??'HNL',
              'responsable_id'=>$_POST['responsable_id']?:null,
              'estado'=>$_POST['estado']??'activo'];
        try {
            if ($pid) {
                $pdo->prepare("UPDATE proyectos SET codigo=?,nombre=?,donante=?,descripcion=?,fecha_inicio=?,fecha_fin=?,presupuesto_total=?,moneda=?,responsable_id=?,estado=? WHERE id=?")
                    ->execute([...array_values($d), $pid]);
                jsonOk([],'Proyecto actualizado.');
            } else {
                $pdo->prepare("INSERT INTO proyectos (codigo,nombre,donante,descripcion,fecha_inicio,fecha_fin,presupuesto_total,moneda,responsable_id,estado) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute(array_values($d));
                jsonOk(['id'=>$pdo->lastInsertId()],'Proyecto creado.');
            }
        } catch(PDOException $e){ jsonErr($e->getCode()==23000?'El código ya existe.':$e->getMessage()); }
    }
    jsonErr('Acción desconocida.');
}

$proyectos = $pdo->query("SELECT p.*,CONCAT(e.nombre,' ',e.apellidos) as resp_nombre FROM proyectos p LEFT JOIN empleados e ON p.responsable_id=e.id ORDER BY p.estado,p.nombre")->fetchAll();
$empleados = $pdo->query("SELECT id,CONCAT(nombre,' ',apellidos) as nombre FROM empleados WHERE activo=1 ORDER BY apellidos")->fetchAll();
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-diagram-project"></i> Proyectos / Donantes</h1>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-proy"><i class="fas fa-plus"></i> Nuevo Proyecto</button>
</div>
<div class="row g-3 mb-3">
  <?php foreach($proyectos as $p): ?>
  <div class="col-md-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <span class="font-mono text-blue fw-bold"><?= htmlspecialchars($p['codigo']) ?></span>
          <?= estadoBadge($p['estado']) ?>
        </div>
        <h5 style="font-size:.95rem;margin-bottom:.5rem;"><?= htmlspecialchars($p['nombre']) ?></h5>
        <?php if($p['donante']): ?><p style="font-size:.8rem;color:var(--ah-gray);margin-bottom:.3rem;"><i class="fas fa-handshake"></i> <?= htmlspecialchars($p['donante']) ?></p><?php endif; ?>
        <div style="font-size:.8rem;" class="mt-2">
          <span class="me-3"><i class="fas fa-calendar text-blue"></i> <?= fmtFecha($p['fecha_inicio']??'') ?> — <?= fmtFecha($p['fecha_fin']??'') ?></span><br>
          <span class="fw-bold"><i class="fas fa-dollar-sign text-green"></i> Presupuesto: <?= lps($p['presupuesto_total']) ?> <?= $p['moneda'] ?></span>
          <?php if($p['resp_nombre']): ?><br><i class="fas fa-user text-blue"></i> <?= htmlspecialchars($p['resp_nombre']) ?><?php endif; ?>
        </div>
        <?php if($p['descripcion']): ?><p class="text-muted mt-2 mb-0" style="font-size:.78rem;"><?= htmlspecialchars(substr($p['descripcion'],0,120)) ?></p><?php endif; ?>
      </div>
      <div class="card-footer bg-transparent d-flex justify-content-end gap-2 py-2">
        <button class="btn btn-sm btn-outline-primary py-0 px-2" onclick='editProy(<?= json_encode($p) ?>)'><i class="fas fa-edit"></i></button>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if(!$proyectos): ?><div class="col-12 text-center text-muted py-4">No hay proyectos registrados</div><?php endif; ?>
</div>

<div class="modal fade" id="modal-proy" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-diagram-project"></i> <span id="proy-modal-title">Nuevo Proyecto</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-proy">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar">
          <input type="hidden" name="id" id="py-id" value="0">
          <div class="row g-3">
            <div class="col-md-3"><label class="form-label">Código *</label><input type="text" name="codigo" id="py-cod" class="form-control" required></div>
            <div class="col-md-9"><label class="form-label">Nombre del Proyecto *</label><input type="text" name="nombre" id="py-nom" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Donante / Financiador</label><input type="text" name="donante" id="py-don" class="form-control" placeholder="ej: USAID, OPS, FAO"></div>
            <div class="col-md-3">
              <label class="form-label">Estado</label>
              <select name="estado" id="py-est" class="form-select"><option value="activo">Activo</option><option value="cerrado">Cerrado</option><option value="suspendido">Suspendido</option></select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Moneda</label>
              <select name="moneda" id="py-mon" class="form-select"><option value="HNL">Lempiras (HNL)</option><option value="USD">Dólares (USD)</option><option value="EUR">Euros (EUR)</option></select>
            </div>
            <div class="col-md-2"><label class="form-label">Fecha Inicio</label><input type="text" name="fecha_inicio" id="py-fi" class="form-control date-input"></div>
            <div class="col-md-2"><label class="form-label">Fecha Fin</label><input type="text" name="fecha_fin" id="py-ff" class="form-control date-input"></div>
            <div class="col-md-4"><label class="form-label">Presupuesto Total</label><input type="number" name="presupuesto_total" id="py-pre" class="form-control" step="0.01" min="0" value="0"></div>
            <div class="col-md-4">
              <label class="form-label">Responsable</label>
              <select name="responsable_id" id="py-resp" class="form-select"><option value="">--</option><?php foreach($empleados as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nombre']) ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-12"><label class="form-label">Descripción / Objetivos</label><textarea name="descripcion" id="py-desc" class="form-control" rows="3"></textarea></div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="saveProy()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php
$extraJs = <<<'JS'

function editProy(p){
  ['id','cod','nom','don','fi','ff','pre','resp','est','mon','desc'].forEach(k=>{
    const m={'id':'py-id','cod':'py-cod','nom':'py-nom','don':'py-don','fi':'py-fi','ff':'py-ff','pre':'py-pre','resp':'py-resp','est':'py-est','mon':'py-mon','desc':'py-desc'};
    const fk={'id':'id','cod':'codigo','nom':'nombre','don':'donante','fi':'fecha_inicio','ff':'fecha_fin','pre':'presupuesto_total','resp':'responsable_id','est':'estado','mon':'moneda','desc':'descripcion'};
    const el=document.getElementById(m[k]);
    if(el) el.value=p[fk[k]]||'';
  });
  document.getElementById('proy-modal-title').textContent='Editar: '+p.nombre;
  new bootstrap.Modal(document.getElementById('modal-proy')).show();
}
async function saveProy(){
  const r=await post('proyectos.php',new FormData(document.getElementById('form-proy')));
  if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.recargar(),1000);}
  else Toast.show(r.message,'error');
}

JS;
include __DIR__ . '/includes/footer.php';
?>

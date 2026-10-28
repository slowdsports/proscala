<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$scId   = (int)($_GET['sc_id'] ?? 0);
$pagina = 'Resumen de Cotizaciones';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $coId = (int)($_POST['id'] ?? 0);
        $provs = json_decode($_POST['proveedores_data'] ?? '[]', true);
        $pdo->beginTransaction();
        try {
            if ($coId) {
                $pdo->prepare("UPDATE cotizaciones SET solicitud_id=?,fecha=?,elaborado_por_id=?,proveedor_seleccionado_id=?,justificacion=? WHERE id=?")
                    ->execute([$_POST['solicitud_id'],$_POST['fecha'],$_POST['elaborado_por_id']?:null,$_POST['proveedor_seleccionado_id']?:null,trim($_POST['justificacion']??''),$coId]);
                $pdo->prepare("DELETE FROM cotizaciones_proveedores WHERE cotizacion_id=?")->execute([$coId]);
            } else {
                $num = numeroProceso($pdo,'cotizaciones',getConfig($pdo,'prefijo_co','CO'), numeroDe($pdo,'solicitud_compra',$_POST['solicitud_id']));
                $pdo->prepare("INSERT INTO cotizaciones (numero,solicitud_id,fecha,elaborado_por_id,proveedor_seleccionado_id,justificacion,estado)
                    VALUES (?,?,?,?,?,?,'pendiente')")->execute([$num,$_POST['solicitud_id'],$_POST['fecha'],$_POST['elaborado_por_id']?:null,$_POST['proveedor_seleccionado_id']?:null,trim($_POST['justificacion']??'')]);
                $coId = $pdo->lastInsertId();
                // Update SC status
                $pdo->prepare("UPDATE solicitud_compra SET estado='cotizando' WHERE id=?")->execute([$_POST['solicitud_id']]);
            }
            foreach ($provs as $pv) {
                $pdo->prepare("INSERT INTO cotizaciones_proveedores (cotizacion_id,proveedor_id,num_cotizacion,fecha_cotizacion,total,condiciones,tiempo_entrega,notas)
                    VALUES (?,?,?,?,?,?,?,?)")->execute([$coId,$pv['proveedor_id'],$pv['num_cotizacion']??'',$pv['fecha']??null,$pv['total']??0,$pv['condiciones']??'',$pv['tiempo_entrega']??'',$pv['notas']??'']);
            }
            $pdo->commit();
            jsonOk(['id'=>$coId],'Cotización guardada.');
        } catch(\Exception $e){ $pdo->rollBack(); jsonErr($e->getMessage()); }
    }
    if ($_act === 'cambiar_estado') {
        $coId=(int)$_POST['id'];
        $pdo->prepare("UPDATE cotizaciones SET estado=?,aprobador_id=? WHERE id=?")->execute([$_POST['estado'],$_SESSION['empleado_id'] ?? null,$coId]);
        jsonOk([],'Estado actualizado.');
    }
    jsonErr('Acción desconocida.');
}

$co = null; $cotProvs = [];
if (in_array($action,['ver','editar']) && $id) {
    $s=$pdo->prepare("SELECT co.*,sc.numero as sc_num,sc.descripcion as sc_desc,CONCAT(e.nombre,' ',e.apellidos) as elab_nombre
        FROM cotizaciones co JOIN solicitud_compra sc ON co.solicitud_id=sc.id
        LEFT JOIN empleados e ON co.elaborado_por_id=e.id WHERE co.id=?");
    $s->execute([$id]); $co=$s->fetch();
    $s2=$pdo->prepare("SELECT cp.*,p.nombre as prov_nombre FROM cotizaciones_proveedores cp JOIN proveedores p ON cp.proveedor_id=p.id WHERE cp.cotizacion_id=? ORDER BY cp.total");
    $s2->execute([$id]); $cotProvs=$s2->fetchAll();
}
$solicitudes  = $pdo->query("SELECT id,numero,descripcion FROM solicitud_compra WHERE estado IN ('aprobada','cotizando') ORDER BY numero DESC")->fetchAll();
$empleados    = $pdo->query("SELECT id,CONCAT(nombre,' ',apellidos) as nombre FROM empleados WHERE activo=1 ORDER BY apellidos")->fetchAll();
$proveedores  = $pdo->query("SELECT id,nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$lista = [];
if ($action==='list') $lista=$pdo->query("SELECT co.*,sc.numero as sc_num,sc.descripcion as sc_desc,p.nombre as prov_sel FROM cotizaciones co JOIN solicitud_compra sc ON co.solicitud_id=sc.id LEFT JOIN proveedores p ON co.proveedor_seleccionado_id=p.id WHERE " . filtroHistorico('co') . " ORDER BY co.id DESC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="workflow-steps mb-3">
  <a href="<?= BASE_URL ?>compras_solicitud.php" class="wf-step done"><i class="fas fa-cart-plus"></i><span>Solicitud de Compra</span></a>
  <div class="wf-step active"><i class="fas fa-file-lines"></i><span>Cotizaciones</span></div>
  <a href="<?= BASE_URL ?>compras_orden.php" class="wf-step"><i class="fas fa-file-circle-check"></i><span>Orden de Compra</span></a>
  <a href="<?= BASE_URL ?>compras_pago.php" class="wf-step"><i class="fas fa-money-bill-transfer"></i><span>Orden de Pago</span></a>
  <a href="<?= BASE_URL ?>compras_recepcion.php" class="wf-step"><i class="fas fa-boxes-stacked"></i><span>Nota de Recepción</span></a>
</div>

<div class="page-header">
  <h1><i class="fas fa-file-lines"></i> Resumen de Cotizaciones</h1>
  <?php if ($action==='list'): ?>
  <a href="?action=nuevo<?= $scId?"&sc_id=$scId":'' ?>" class="btn-proscala"><i class="fas fa-plus"></i> Nueva Cotización</a>
  <?php else: ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action==='list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card"><div class="card-body p-0">
  <table id="tbl-co" class="table-proscala w-100">
    <thead><tr><th>Número</th><th>SC Referencia</th><th>Fecha</th><th>Proveedor Seleccionado</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach($lista as $r): ?>
      <tr>
        <td class="font-mono"><?= $r['numero'] ?><?= badgeHistorico($r) ?></td>
        <td><?= htmlspecialchars($r['sc_num'].' - '.$r['sc_desc']) ?></td>
        <td><?= fmtFecha($r['fecha']) ?></td>
        <td><?= htmlspecialchars($r['prov_sel']??'—') ?></td>
        <td><?= estadoBadge($r['estado']) ?></td>
        <td><a href="?action=ver&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a><?= botonEditar($pdo, 'compras_cotizaciones.php', (int)$r['id'], '?action=editar&id=' . $r['id'], true) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$lista): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin cotizaciones</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php elseif ($action==='ver' && $co): ?>
<div id="print-area">
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-file-lines"></i> <?= $co['numero'] ?> — Ref. SC: <?= $co['sc_num'] ?> — <?= estadoBadge($co['estado']) ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?php if($co['estado']==='pendiente'): ?>
      <button class="btn btn-sm btn-success" onclick="cambiarEstado('compras_cotizaciones.php',<?=$co['id']?>,'aprobada','cotizaciones',()=>Nav.recargar())"><i class="fas fa-check"></i> Aprobar</button>
      <?php endif; ?>
      <?php if(in_array($co['estado'],['aprobada'])): ?>
      <a href="<?= BASE_URL ?>compras_orden.php?action=nuevo&co_id=<?= $co['id'] ?>" class="btn btn-sm btn-primary"><i class="fas fa-file-circle-check"></i> Crear OC</a>
      <?php endif; ?>
      <?= botonEditar($pdo, 'compras_cotizaciones.php', (int)$co['id'], '?action=editar&id=' . $co['id']) ?>
      <button class="btn btn-sm btn-outline-secondary" onclick="printDoc()"><i class="fas fa-print"></i></button>
    </div>
  </div>
  <div class="card-body" style="font-size:.85rem;">
    <div class="row g-2">
      <div class="col-md-4"><strong>Fecha:</strong> <?= fmtFecha($co['fecha']) ?></div>
      <div class="col-md-4"><strong>Elaborado por:</strong> <?= htmlspecialchars($co['elab_nombre']??'—') ?></div>
      <div class="col-md-4"><strong>SC:</strong> <?= htmlspecialchars($co['sc_desc']) ?></div>
      <?php if($co['justificacion']): ?><div class="col-12"><strong>Justificación de Selección:</strong> <?= htmlspecialchars($co['justificacion']) ?></div><?php endif; ?>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><i class="fas fa-balance-scale"></i> Comparativo de Cotizaciones</div>
  <div class="card-body p-0" style="overflow-x:auto;">
    <table class="table-proscala w-100">
      <thead><tr><th>Proveedor</th><th>No. Cotización</th><th>Fecha</th><th>Total Ofertado</th><th>Condiciones</th><th>T. Entrega</th><th>Seleccionado</th></tr></thead>
      <tbody>
        <?php foreach($cotProvs as $cp): ?>
        <tr class="<?= $cp['proveedor_id']==$co['proveedor_seleccionado_id']?'table-success':'' ?>">
          <td><?= htmlspecialchars($cp['prov_nombre']) ?></td>
          <td><?= htmlspecialchars($cp['num_cotizacion']??'—') ?></td>
          <td><?= fmtFecha($cp['fecha_cotizacion']??'') ?></td>
          <td class="font-mono fw-bold"><?= lps($cp['total']) ?></td>
          <td><?= htmlspecialchars($cp['condiciones']??'—') ?></td>
          <td><?= htmlspecialchars($cp['tiempo_entrega']??'—') ?></td>
          <td><?= $cp['proveedor_id']==$co['proveedor_seleccionado_id'] ? '<i class="fas fa-check-circle text-success"></i>' : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<?php $adjModulo = 'compras_cotizaciones.php'; $adjId = $co['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php else: ?>
<div class="card">
  <div class="card-header"><i class="fas <?= $co ? 'fa-edit' : 'fa-plus' ?>"></i> <?= $co ? 'Editar cotización ' . htmlspecialchars($co['numero']) : 'Nueva Cotización' ?></div>
  <div class="card-body">
    <form id="form-co" action="compras_cotizaciones.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="guardar">
      <input type="hidden" name="id" value="<?= $co['id']??0 ?>">
      <input type="hidden" name="proveedores_data" id="provs-json">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Solicitud de Compra *</label>
          <select name="solicitud_id" class="form-select" required>
            <option value="">--</option>
            <?php foreach($solicitudes as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ($scId==$s['id']||($co['solicitud_id']??0)==$s['id'])?'selected':'' ?>><?= $s['numero'] ?> — <?= htmlspecialchars($s['descripcion']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2"><label class="form-label">Fecha *</label><input type="text" name="fecha" class="form-control date-input" required value="<?= $co['fecha']??date('Y-m-d') ?>"></div>
        <div class="col-md-3">
          <label class="form-label">Elaborado por</label>
          <select name="elaborado_por_id" class="form-select"><option value="">--</option><?php foreach($empleados as $e): ?><option value="<?= $e['id'] ?>" <?= ($co['elaborado_por_id'] ?? 0)==$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nombre']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Proveedor Seleccionado</label>
          <select name="proveedor_seleccionado_id" class="form-select" id="sel-prov-rec" data-alta="proveedor"><option value="">-- Al finalizar --</option><?php foreach($proveedores as $p): ?><option value="<?= $p['id'] ?>" <?= ($co['proveedor_seleccionado_id'] ?? 0)==$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nombre']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-12"><label class="form-label">Justificación de Selección</label><textarea name="justificacion" class="form-control" rows="2" placeholder="Indique por qué se eligió el proveedor recomendado..."><?= htmlspecialchars($co['justificacion'] ?? '') ?></textarea></div>
      </div>

      <div class="form-section-title">Cotizaciones por Proveedor (mínimo 3 recomendado)</div>
      <div id="provs-container">
        <?php // Al editar, una fila por cada proveedor cotizado (mínimo 3)
        for($pi=0;$pi<max(3, count($cotProvs));$pi++): $cp = $cotProvs[$pi] ?? []; ?>
        <div class="card mb-2 prov-row">
          <div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <strong style="font-size:.85rem;">Proveedor <?= $pi+1 ?></strong>
              <?php if($pi>0): ?><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="this.closest('.prov-row').remove()"><i class="fas fa-trash"></i></button><?php endif; ?>
            </div>
            <div class="row g-2">
              <div class="col-md-4"><label class="form-label">Proveedor *</label><select class="form-select form-select-sm prov-sel" data-f="proveedor_id" data-alta="proveedor"><option value="">--</option><?php foreach($proveedores as $p): ?><option value="<?= $p['id'] ?>" <?= ($cp['proveedor_id'] ?? 0)==$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nombre']) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-2"><label class="form-label">No. Cotización</label><input type="text" class="form-control form-control-sm" data-f="num_cotizacion" placeholder="Ref. proveedor" value="<?= htmlspecialchars($cp['num_cotizacion'] ?? '') ?>"></div>
              <div class="col-md-2"><label class="form-label">Fecha</label><input type="text" class="form-control form-control-sm date-input" data-f="fecha" value="<?= $cp['fecha_cotizacion'] ?? '' ?>"></div>
              <div class="col-md-2"><label class="form-label">Total (L.) *</label><input type="number" class="form-control form-control-sm text-end" step="0.01" min="0" data-f="total" value="<?= $cp['total'] ?? 0 ?>"></div>
              <div class="col-md-2"><label class="form-label">T. Entrega</label><input type="text" class="form-control form-control-sm" data-f="tiempo_entrega" placeholder="ej: 5 días" value="<?= htmlspecialchars($cp['tiempo_entrega'] ?? '') ?>"></div>
              <div class="col-md-4"><label class="form-label">Condiciones de Pago</label><input type="text" class="form-control form-control-sm" data-f="condiciones" placeholder="ej: 30 días" value="<?= htmlspecialchars($cp['condiciones'] ?? '') ?>"></div>
              <div class="col-md-8"><label class="form-label">Notas</label><input type="text" class="form-control form-control-sm" data-f="notas" value="<?= htmlspecialchars($cp['notas'] ?? '') ?>"></div>
            </div>
          </div>
        </div>
        <?php endfor; ?>
      </div>
      <button type="button" class="btn btn-sm btn-outline-success" onclick="addProvRow()"><i class="fas fa-plus"></i> Agregar Proveedor</button>
      <?php $adjModulo = 'compras_cotizaciones.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar</button>
        <a href="?" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = <<<'JS'

initDataTable('#tbl-co');

function addProvRow(){
  // Las opciones de la primera fila (incluye los proveedores agregados desde la lista)
  const opts = [...document.querySelector('#provs-container .prov-sel').options].filter(o=>o.value)
    .map(o=>`<option value="${o.value}">${Notificaciones.esc(o.text)}</option>`).join('');
  const idx = document.querySelectorAll('#provs-container .prov-row').length+1;
  const d=document.createElement('div'); d.className='card mb-2 prov-row';
  d.innerHTML=`<div class="card-body p-3"><div class="d-flex justify-content-between align-items-center mb-2"><strong style="font-size:.85rem;">Proveedor ${idx}</strong><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="this.closest('.prov-row').remove()"><i class="fas fa-trash"></i></button></div><div class="row g-2"><div class="col-md-4"><select class="form-select form-select-sm prov-sel" data-f="proveedor_id" data-alta="proveedor"><option value="">--</option>${opts}</select></div><div class="col-md-2"><input type="text" class="form-control form-control-sm" data-f="num_cotizacion"></div><div class="col-md-2"><input type="text" class="form-control form-control-sm date-input" data-f="fecha"></div><div class="col-md-2"><input type="number" class="form-control form-control-sm text-end" step="0.01" min="0" data-f="total" value="0"></div><div class="col-md-2"><input type="text" class="form-control form-control-sm" data-f="tiempo_entrega"></div><div class="col-md-4"><input type="text" class="form-control form-control-sm" data-f="condiciones"></div><div class="col-md-8"><input type="text" class="form-control form-control-sm" data-f="notas"></div></div></div>`;
  document.getElementById('provs-container').appendChild(d);
  flatpickr(d.querySelectorAll('.date-input'),{locale:'es',dateFormat:'Y-m-d',allowInput:true});
}

document.getElementById('form-co')?.addEventListener('submit',function(e){
  e.preventDefault();
  const provs=[];
  document.querySelectorAll('#provs-container .prov-row').forEach(row=>{
    const obj={};
    row.querySelectorAll('[data-f]').forEach(el=>obj[el.dataset.f]=el.value);
    if(obj.proveedor_id) provs.push(obj);
  });
  if(provs.length<1){Toast.show('Agregue al menos una cotización.','warning');return;}
  document.getElementById('provs-json').value=JSON.stringify(provs);
  post('compras_cotizaciones.php',new FormData(this)).then(r=>{
    if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.ir('compras_cotizaciones.php'), 1200);}
    else Toast.show(r.message,'error');
  }).catch(()=>Toast.show('Error','error'));
});

JS;
include __DIR__ . '/includes/footer.php';
?>

<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$ocId   = (int)($_GET['oc_id'] ?? 0);
$pagina = 'Nota de Recepción';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $nrId  = (int)($_POST['id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        $pdo->beginTransaction();
        try {
            if ($nrId) {
                $pdo->prepare("UPDATE notas_recepcion SET orden_compra_id=?,proveedor_id=?,fecha=?,factura_proveedor=?,fecha_factura=?,receptor_id=?,estado=?,notas=? WHERE id=?")
                    ->execute([$_POST['orden_compra_id']?:null,$_POST['proveedor_id'],$_POST['fecha'],$_POST['factura_proveedor']??'',$_POST['fecha_factura']?:null,$_POST['receptor_id']?:null,$_POST['estado']??'completa',$_POST['notas']??'',$nrId]);
                $pdo->prepare("DELETE FROM notas_recepcion_detalle WHERE nota_id=?")->execute([$nrId]);
            } else {
                $num = numeroProceso($pdo,'notas_recepcion',getConfig($pdo,'prefijo_nr','NR'), numeroDe($pdo,'ordenes_compra',$_POST['orden_compra_id']));
                $pdo->prepare("INSERT INTO notas_recepcion (numero,orden_compra_id,proveedor_id,fecha,factura_proveedor,fecha_factura,receptor_id,estado,notas)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$num,$_POST['orden_compra_id']?:null,$_POST['proveedor_id'],$_POST['fecha'],$_POST['factura_proveedor']??'',$_POST['fecha_factura']?:null,$_POST['receptor_id']?:null,$_POST['estado']??'completa',$_POST['notas']??'']);
                $nrId = $pdo->lastInsertId();
                if ($_POST['orden_compra_id']) {
                    $pdo->prepare("UPDATE ordenes_compra SET estado=? WHERE id=?")->execute([$_POST['estado']==='completa'?'completa':'parcial',$_POST['orden_compra_id']]);
                }
            }
            foreach ($items as $it) {
                $pdo->prepare("INSERT INTO notas_recepcion_detalle (nota_id,descripcion,unidad,cantidad_pedida,cantidad_recibida,precio_unitario,total,estado_item,observacion)
                    VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$nrId,$it['descripcion'],$it['unidad']??'',$it['cantidad_pedida']??0,$it['cantidad_recibida']??0,$it['precio']??0,($it['cantidad_recibida']??0)*($it['precio']??0),$it['estado_item']??'bueno',$it['observacion']??'']);
            }
            $pdo->commit();
            jsonOk(['id'=>$nrId],'Nota de recepción guardada.');
        } catch(\Exception $e){ $pdo->rollBack(); jsonErr($e->getMessage()); }
    }
    jsonErr('Acción desconocida.');
}

// Load OC for pre-fill
$ocData = null; $ocDetalle = [];
if ($ocId) {
    $s=$pdo->prepare("SELECT oc.*,p.nombre as prov_nombre FROM ordenes_compra oc JOIN proveedores p ON oc.proveedor_id=p.id WHERE oc.id=?");
    $s->execute([$ocId]); $ocData=$s->fetch();
    $s2=$pdo->prepare("SELECT * FROM ordenes_compra_detalle WHERE orden_id=?");
    $s2->execute([$ocId]); $ocDetalle=$s2->fetchAll();
}

$nr = null; $detalle = [];
if (in_array($action,['ver','editar']) && $id) {
    $s=$pdo->prepare("SELECT nr.*,p.nombre as prov_nombre,oc.numero as oc_num,CONCAT(e.nombre,' ',e.apellidos) as rec_nombre
        FROM notas_recepcion nr JOIN proveedores p ON nr.proveedor_id=p.id
        LEFT JOIN ordenes_compra oc ON nr.orden_compra_id=oc.id
        LEFT JOIN empleados e ON nr.receptor_id=e.id WHERE nr.id=?");
    $s->execute([$id]); $nr=$s->fetch();
    $s2=$pdo->prepare("SELECT * FROM notas_recepcion_detalle WHERE nota_id=?");
    $s2->execute([$id]); $detalle=$s2->fetchAll();
}

$proveedores = $pdo->query("SELECT id,nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$empleados   = $pdo->query("SELECT id,CONCAT(nombre,' ',apellidos) as nombre FROM empleados WHERE activo=1 ORDER BY apellidos")->fetchAll();
$ocList      = $pdo->query("SELECT id,numero FROM ordenes_compra WHERE estado IN ('emitida','parcial') ORDER BY numero DESC")->fetchAll();
$lista = [];
if ($action==='list') $lista=$pdo->query("SELECT nr.*,p.nombre as prov_nombre,oc.numero as oc_num FROM notas_recepcion nr JOIN proveedores p ON nr.proveedor_id=p.id LEFT JOIN ordenes_compra oc ON nr.orden_compra_id=oc.id WHERE " . filtroHistorico('nr') . " ORDER BY nr.id DESC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="workflow-steps mb-3">
  <a href="<?= BASE_URL ?>compras_solicitud.php" class="wf-step done"><i class="fas fa-cart-plus"></i><span>Solicitud de Compra</span></a>
  <a href="<?= BASE_URL ?>compras_cotizaciones.php" class="wf-step done"><i class="fas fa-file-lines"></i><span>Cotizaciones</span></a>
  <a href="<?= BASE_URL ?>compras_orden.php" class="wf-step done"><i class="fas fa-file-circle-check"></i><span>Orden de Compra</span></a>
  <a href="<?= BASE_URL ?>compras_pago.php" class="wf-step done"><i class="fas fa-money-bill-transfer"></i><span>Orden de Pago</span></a>
  <div class="wf-step active"><i class="fas fa-boxes-stacked"></i><span>Nota de Recepción</span></div>
</div>

<div class="page-header">
  <h1><i class="fas fa-boxes-stacked"></i> Nota de Recepción</h1>
  <?php if ($action==='list'): ?>
  <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nueva NR</a>
  <?php else: ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action==='list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card"><div class="card-body p-0">
  <table id="tbl-nr" class="table-proscala w-100">
    <thead><tr><th>Número</th><th>Fecha</th><th>OC Ref.</th><th>Proveedor</th><th>Factura</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach($lista as $r): ?>
      <tr>
        <td class="font-mono"><?= $r['numero'] ?><?= badgeHistorico($r) ?></td>
        <td><?= fmtFecha($r['fecha']) ?></td>
        <td><?= htmlspecialchars($r['oc_num']??'—') ?></td>
        <td><?= htmlspecialchars($r['prov_nombre']) ?></td>
        <td><?= htmlspecialchars($r['factura_proveedor']??'—') ?></td>
        <td><?= estadoBadge($r['estado']) ?></td>
        <td><a href="?action=ver&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a><?= botonEditar($pdo, 'compras_recepcion.php', (int)$r['id'], '?action=editar&id=' . $r['id'], true) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$lista): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin notas de recepción</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php elseif ($action==='ver' && $nr): ?>
<div id="print-area">
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-boxes-stacked"></i> NOTA DE RECEPCIÓN <?= $nr['numero'] ?> — <?= estadoBadge($nr['estado']) ?>
    <div class="ms-auto no-print d-flex gap-2"><?= botonEditar($pdo, 'compras_recepcion.php', (int)$nr['id'], '?action=editar&id=' . $nr['id']) ?><button class="btn btn-sm btn-outline-secondary" onclick="printDoc()"><i class="fas fa-print"></i></button></div>
  </div>
  <div class="card-body" style="font-size:.85rem;">
    <div class="row g-2">
      <div class="col-md-3"><strong>Fecha:</strong> <?= fmtFecha($nr['fecha']) ?></div>
      <div class="col-md-4"><strong>Proveedor:</strong> <?= htmlspecialchars($nr['prov_nombre']) ?></div>
      <div class="col-md-2"><strong>OC Ref.:</strong> <?= htmlspecialchars($nr['oc_num']??'—') ?></div>
      <div class="col-md-2"><strong>Factura:</strong> <?= htmlspecialchars($nr['factura_proveedor']??'—') ?></div>
      <div class="col-md-3"><strong>Receptor:</strong> <?= htmlspecialchars($nr['rec_nombre']??'—') ?></div>
      <?php if($nr['notas']): ?><div class="col-12"><strong>Notas:</strong> <?= htmlspecialchars($nr['notas']) ?></div><?php endif; ?>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><i class="fas fa-list"></i> Artículos Recibidos</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>#</th><th>Descripción</th><th>Unidad</th><th>Cant. Pedida</th><th>Cant. Recibida</th><th>P. Unit.</th><th>Total</th><th>Estado</th><th>Observación</th></tr></thead>
      <tbody>
        <?php $i=1; foreach($detalle as $d): ?>
        <tr class="<?= $d['estado_item']==='danado'?'table-danger':($d['estado_item']==='incompleto'?'table-warning':'') ?>">
          <td><?= $i++ ?></td><td><?= htmlspecialchars($d['descripcion']) ?></td><td><?= $d['unidad'] ?></td>
          <td class="text-end"><?= $d['cantidad_pedida'] ?></td><td class="text-end"><?= $d['cantidad_recibida'] ?></td>
          <td class="font-mono"><?= lps($d['precio_unitario']) ?></td><td class="font-mono"><?= lps($d['total']) ?></td>
          <td><?= $d['estado_item'] ?></td><td><?= htmlspecialchars($d['observacion']??'') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<?php $adjModulo = 'compras_recepcion.php'; $adjId = $nr['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php else: ?>
<?php $nrE = $action === 'editar' ? $nr : null;   // edición (rangos superiores): el formulario trae la nota guardada ?>
<div class="card"><div class="card-header"><i class="fas <?= $nrE ? 'fa-edit' : 'fa-plus' ?>"></i> <?= $nrE ? 'Editar nota de recepción ' . htmlspecialchars($nrE['numero']) : 'Nueva Nota de Recepción' ?></div>
<div class="card-body">
  <form id="form-nr" action="compras_recepcion.php" method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="guardar">
    <input type="hidden" name="id" value="<?= (int)($nrE['id'] ?? 0) ?>">
    <input type="hidden" name="items" id="items-json">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Orden de Compra</label>
        <select name="orden_compra_id" class="form-select" id="sel-oc-nr" onchange="loadOcItems(this.value)">
          <option value="">-- Sin OC --</option>
          <?php $ocSel = $nrE['orden_compra_id'] ?? $ocId;
          if ($ocSel && !in_array($ocSel, array_column($ocList, 'id'))): ?><option value="<?= (int)$ocSel ?>" selected><?= htmlspecialchars($nrE['oc_num'] ?? numeroDe($pdo, 'ordenes_compra', $ocSel) ?? '') ?></option><?php endif; ?>
          <?php foreach($ocList as $oc): ?><option value="<?= $oc['id'] ?>" <?= $ocSel==$oc['id']?'selected':'' ?>><?= $oc['numero'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Proveedor *</label>
        <select name="proveedor_id" class="form-select" required id="sel-prov-nr">
          <option value="">--</option>
          <?php foreach($proveedores as $p): ?><option value="<?= $p['id'] ?>" <?= (($nrE['proveedor_id'] ?? ($ocData['proveedor_id'] ?? 0))==$p['id'])?'selected':'' ?>><?= htmlspecialchars($p['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">Fecha *</label><input type="text" name="fecha" class="form-control date-input" required value="<?= $nrE['fecha'] ?? date('Y-m-d') ?>"></div>
      <div class="col-md-2"><label class="form-label">Factura Proveedor</label><input type="text" name="factura_proveedor" class="form-control" placeholder="No. Factura" value="<?= htmlspecialchars($nrE['factura_proveedor'] ?? '') ?>"></div>
      <div class="col-md-2"><label class="form-label">Fecha Factura</label><input type="text" name="fecha_factura" class="form-control date-input" value="<?= $nrE['fecha_factura'] ?? '' ?>"></div>
      <div class="col-md-3"><label class="form-label">Receptor</label><select name="receptor_id" class="form-select"><option value="">--</option><?php foreach($empleados as $e): ?><option value="<?= $e['id'] ?>" <?= ($nrE['receptor_id'] ?? 0)==$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><label class="form-label">Estado General</label><select name="estado" class="form-select"><?php foreach (['completa' => 'Completa', 'parcial' => 'Parcial', 'devolucion' => 'Devolución'] as $k => $v): ?><option value="<?= $k ?>" <?= ($nrE['estado'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div class="col-md-7"><label class="form-label">Notas / Observaciones</label><input type="text" name="notas" class="form-control" value="<?= htmlspecialchars($nrE['notas'] ?? '') ?>"></div>
    </div>
    <div class="form-section-title">Artículos Recibidos</div>
    <div class="table-responsive">
      <table class="table-proscala w-100">
        <thead><tr><th>#</th><th>Descripción</th><th>Unidad</th><th>Cant. Pedida</th><th>Cant. Recibida</th><th>Precio Unit.</th><th>Total</th><th>Estado</th><th>Observación</th><th></th></tr></thead>
        <tbody id="tbody-items">
          <?php
          // Al editar, los artículos guardados; si no, los de la OC (o una fila vacía)
          $initNr = $nrE ? array_map(fn($d) => ['descripcion' => $d['descripcion'], 'unidad' => $d['unidad'], 'cantidad' => $d['cantidad_pedida'],
                  'cantidad_recibida' => $d['cantidad_recibida'], 'precio_unitario' => $d['precio_unitario'], 'estado_item' => $d['estado_item'],
                  'observacion' => $d['observacion']], $detalle)
              : ($ocDetalle ?: [['descripcion'=>'','unidad'=>'','cantidad'=>1,'precio_unitario'=>0]]);
          foreach($initNr as $idx=>$it): ?>
          <tr class="detail-row">
            <td class="row-num"><?= $idx+1 ?></td>
            <td><input type="text" class="form-control form-control-sm" data-f="descripcion" value="<?= htmlspecialchars($it['descripcion']??'') ?>" required></td>
            <td><input type="text" class="form-control form-control-sm" data-f="unidad" value="<?= htmlspecialchars($it['unidad']??'') ?>" style="width:70px"></td>
            <td><input type="number" class="form-control form-control-sm text-end" data-f="cantidad_pedida" step="0.01" min="0" value="<?= $it['cantidad']??1 ?>" style="width:80px"></td>
            <td><input type="number" class="form-control form-control-sm text-end row-monto" data-f="cantidad_recibida" step="0.01" min="0" value="<?= $it['cantidad_recibida'] ?? $it['cantidad'] ?? 1 ?>" style="width:80px"></td>
            <td><input type="number" class="form-control form-control-sm text-end" data-f="precio" step="0.01" min="0" value="<?= $it['precio_unitario']??0 ?>" style="width:100px"></td>
            <td><span class="font-mono" data-f="total">L. 0.00</span></td>
            <td>
              <select class="form-select form-select-sm" data-f="estado_item" style="width:110px">
                <?php foreach (['bueno' => 'Bueno', 'danado' => 'Dañado', 'incompleto' => 'Incompleto'] as $k => $v): ?><option value="<?= $k ?>" <?= ($it['estado_item'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" class="form-control form-control-sm" data-f="observacion" placeholder="Obs." value="<?= htmlspecialchars($it['observacion'] ?? '') ?>"></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeDetailRow(this)"><i class="fas fa-trash"></i></button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="button" class="btn btn-sm btn-outline-success mt-2" onclick="addNrRow()"><i class="fas fa-plus"></i> Agregar Ítem</button>
    <?php $adjModulo = 'compras_recepcion.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
    <div class="mt-4 d-flex gap-2">
      <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> <?= $nrE ? 'Guardar cambios' : 'Registrar Recepción' ?></button>
      <a href="?" class="btn btn-outline-secondary btn-sm">Cancelar</a>
    </div>
  </form>
</div></div>
<?php endif; ?>

<?php
$extraJs = "
initDataTable('#tbl-nr');
function addNrRow(){
  const idx=document.querySelectorAll('#tbody-items .detail-row').length;
  const tr=document.createElement('tr'); tr.className='detail-row';
  tr.innerHTML=`<td class=\"row-num\">\${idx+1}</td>
    <td><input type=\"text\" class=\"form-control form-control-sm\" data-f=\"descripcion\" required></td>
    <td><input type=\"text\" class=\"form-control form-control-sm\" data-f=\"unidad\" style=\"width:70px\"></td>
    <td><input type=\"number\" class=\"form-control form-control-sm text-end\" data-f=\"cantidad_pedida\" step=\"0.01\" min=\"0\" value=\"1\" style=\"width:80px\"></td>
    <td><input type=\"number\" class=\"form-control form-control-sm text-end row-monto\" data-f=\"cantidad_recibida\" step=\"0.01\" min=\"0\" value=\"1\" style=\"width:80px\"></td>
    <td><input type=\"number\" class=\"form-control form-control-sm text-end\" data-f=\"precio\" step=\"0.01\" min=\"0\" value=\"0\" style=\"width:100px\"></td>
    <td><span class=\"font-mono\" data-f=\"total\">L. 0.00</span></td>
    <td><select class=\"form-select form-select-sm\" data-f=\"estado_item\" style=\"width:110px\"><option value=\"bueno\">Bueno</option><option value=\"danado\">Dañado</option><option value=\"incompleto\">Incompleto</option></select></td>
    <td><input type=\"text\" class=\"form-control form-control-sm\" data-f=\"observacion\"></td>
    <td><button type=\"button\" class=\"btn btn-sm btn-outline-danger py-0 px-1\" onclick=\"removeDetailRow(this)\"><i class=\"fas fa-trash\"></i></button></td>`;
  document.getElementById('tbody-items').appendChild(tr);
  updateDetailRowNumbers('tbody-items');
}
document.getElementById('form-nr')?.addEventListener('submit',function(e){
  e.preventDefault();
  const items=[];
  document.querySelectorAll('#tbody-items .detail-row').forEach(r=>{
    const obj={}; r.querySelectorAll('[data-f]').forEach(el=>obj[el.dataset.f]=el.value||el.textContent); items.push(obj);
  });
  document.getElementById('items-json').value=JSON.stringify(items);
  post('compras_recepcion.php',new FormData(this)).then(r=>{
    if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.ir('compras_recepcion.php'), 1200);}
    else Toast.show(r.message,'error');
  }).catch(()=>Toast.show('Error','error'));
});
";
include __DIR__ . '/includes/footer.php';
?>

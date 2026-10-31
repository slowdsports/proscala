<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Solicitud de Compra';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $scId  = (int)($_POST['id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        $total = array_sum(array_column($items,'total'));
        $d = ['fecha'=>$_POST['fecha'],'solicitante_id'=>(int)$_POST['solicitante_id'],
              'proyecto_id'=>$_POST['proyecto_id']?:null,'descripcion'=>trim($_POST['descripcion']),
              'justificacion'=>trim($_POST['justificacion']??''),'urgencia'=>$_POST['urgencia']??'normal',
              'presupuesto_estimado'=>$total,'notas'=>trim($_POST['notas']??'')];
        $pdo->beginTransaction();
        try {
            if ($scId) {
                $pdo->prepare("UPDATE solicitud_compra SET fecha=?,solicitante_id=?,proyecto_id=?,descripcion=?,justificacion=?,urgencia=?,presupuesto_estimado=?,notas=? WHERE id=?")
                    ->execute([...array_values($d), $scId]);
                $pdo->prepare("DELETE FROM solicitud_compra_detalle WHERE solicitud_id=?")->execute([$scId]);
            } else {
                $num = numeroProceso($pdo,'solicitud_compra',getConfig($pdo,'prefijo_sc','SC'));
                $pdo->prepare("INSERT INTO solicitud_compra (numero,fecha,solicitante_id,proyecto_id,descripcion,justificacion,urgencia,presupuesto_estimado,notas,estado)
                    VALUES (?,?,?,?,?,?,?,?,?,'pendiente')")->execute([$num,$d['fecha'],$d['solicitante_id'],$d['proyecto_id'],$d['descripcion'],$d['justificacion'],$d['urgencia'],$d['presupuesto_estimado'],$d['notas']]);
                $scId = $pdo->lastInsertId();
            }
            foreach ($items as $it) {
                $pdo->prepare("INSERT INTO solicitud_compra_detalle (solicitud_id,descripcion,unidad,cantidad,precio_estimado) VALUES (?,?,?,?,?)")
                    ->execute([$scId,$it['descripcion'],$it['unidad']??'',$it['cantidad']??1,$it['precio_estimado']??0]);
            }
            $pdo->commit();
            jsonOk(['id'=>$scId],'Solicitud de compra guardada.');
        } catch(\Exception $e){ $pdo->rollBack(); jsonErr($e->getMessage()); }
    }
    if ($_act === 'cambiar_estado') {
        $scId = (int)$_POST['id'];
        $pdo->prepare("UPDATE solicitud_compra SET estado=?,aprobador_id=?,fecha_aprobacion=CURDATE() WHERE id=?")
            ->execute([$_POST['estado'],$_SESSION['empleado_id'] ?? null,$scId]);
        jsonOk([],'Estado actualizado.');
    }
    jsonErr('Acción desconocida.');
}

$sc = null; $detalle = [];
if (in_array($action,['ver','editar']) && $id) {
    $s=$pdo->prepare("SELECT sc.*,CONCAT(e.nombre,' ',e.apellidos) as sol_nombre,p.nombre as proy_nombre
        FROM solicitud_compra sc JOIN empleados e ON sc.solicitante_id=e.id
        LEFT JOIN proyectos p ON sc.proyecto_id=p.id WHERE sc.id=?");
    $s->execute([$id]); $sc=$s->fetch();
    $s2=$pdo->prepare("SELECT * FROM solicitud_compra_detalle WHERE solicitud_id=?");
    $s2->execute([$id]); $detalle=$s2->fetchAll();
}
$empleados=$pdo->query("SELECT id,CONCAT(nombre,' ',apellidos) as nombre FROM empleados WHERE activo=1 ORDER BY apellidos")->fetchAll();
$proyectos=$pdo->query("SELECT id,nombre FROM proyectos WHERE estado='activo'")->fetchAll();
$lista=[];
if($action==='list') {
    [$fp, $fpParams] = filtroPropios('compras_solicitud.php', 'sc');   // roles que solo ven sus solicitudes
    $s = $pdo->prepare("SELECT sc.*,CONCAT(e.nombre,' ',e.apellidos) as sol_nombre FROM solicitud_compra sc JOIN empleados e ON sc.solicitante_id=e.id WHERE $fp AND " . filtroHistorico('sc') . " ORDER BY sc.id DESC");
    $s->execute($fpParams); $lista = $s->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<!-- Workflow indicator -->
<div class="workflow-steps mb-3">
  <div class="wf-step active"><i class="fas fa-cart-plus"></i><span>Solicitud de Compra</span></div>
  <a href="<?= BASE_URL ?>compras_cotizaciones.php" class="wf-step"><i class="fas fa-file-lines"></i><span>Cotizaciones</span></a>
  <a href="<?= BASE_URL ?>compras_orden.php" class="wf-step"><i class="fas fa-file-circle-check"></i><span>Orden de Compra</span></a>
  <a href="<?= BASE_URL ?>compras_pago.php" class="wf-step"><i class="fas fa-money-bill-transfer"></i><span>Orden de Pago</span></a>
  <a href="<?= BASE_URL ?>compras_recepcion.php" class="wf-step"><i class="fas fa-boxes-stacked"></i><span>Nota de Recepción</span></a>
</div>

<div class="page-header">
  <h1><i class="fas fa-cart-plus"></i> Solicitud de Compra</h1>
  <?php if ($action==='list'): ?>
  <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nueva Solicitud</a>
  <?php else: ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action==='list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card">
  <div class="card-body p-0">
    <table id="tbl-sc" class="table-proscala w-100">
      <thead><tr><th>Número</th><th>Fecha</th><th>Solicitante</th><th>Descripción</th><th>Urgencia</th><th>Presup. Est.</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($lista as $r): ?>
        <tr>
          <td class="font-mono"><?= $r['numero'] ?><?= badgeHistorico($r) ?></td>
          <td><?= fmtFecha($r['fecha']) ?></td>
          <td><?= htmlspecialchars($r['sol_nombre']) ?></td>
          <td><?= htmlspecialchars(substr($r['descripcion'],0,50)) ?></td>
          <td><?= urgenciaBadge($r['urgencia']) ?></td>
          <td class="font-mono"><?= lps($r['presupuesto_estimado']??0) ?></td>
          <td><?= estadoBadge($r['estado']) ?></td>
          <td>
            <a href="?action=ver&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a>
            <?= botonEditar($pdo, 'compras_solicitud.php', (int)$r['id'], '?action=editar&id=' . $r['id'], true) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$lista): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay solicitudes</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action==='ver' && $sc): ?>
<?php $flujoModulo = 'compras_solicitud.php'; $flujoId = $sc['id']; include __DIR__ . '/includes/flujo_aprobacion.php'; ?>

<?php // Cadena del proceso (config/cadena.php)
$cadModulo = 'compras_solicitud.php'; $cadId = $sc['id']; include __DIR__ . '/includes/cadena_proceso.php'; ?>

<div id="print-area">
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-cart-plus"></i> <?= $sc['numero'] ?> — <?= estadoBadge($sc['estado']) ?> <?= urgenciaBadge($sc['urgencia']) ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?php if(in_array($sc['estado'],['aprobada','cotizando'])): ?>
      <a href="<?= BASE_URL ?>compras_cotizaciones.php?action=nuevo&sc_id=<?= $sc['id'] ?>" class="btn btn-sm btn-primary">
        <i class="fas fa-file-lines"></i> Crear Cotización
      </a>
      <?php endif; ?>
      <?= botonEditar($pdo, 'compras_solicitud.php', (int)$sc['id'], '?action=editar&id=' . $sc['id']) ?>
      <a href="<?= BASE_URL ?>print.php?tipo=sc&id=<?= $sc['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
      <a href="<?= BASE_URL ?>print.php?tipo=proceso&id=<?= $sc['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fas fa-layer-group"></i> Proceso Completo</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-2" style="font-size:.85rem;">
      <div class="col-md-3"><strong>Fecha:</strong> <?= fmtFecha($sc['fecha']) ?></div>
      <div class="col-md-4"><strong>Solicitante:</strong> <?= htmlspecialchars($sc['sol_nombre']) ?></div>
      <div class="col-md-5"><strong>Proyecto:</strong> <?= htmlspecialchars($sc['proy_nombre']??'—') ?></div>
      <div class="col-12"><strong>Descripción:</strong> <?= htmlspecialchars($sc['descripcion']) ?></div>
      <?php if($sc['justificacion']): ?><div class="col-12"><strong>Justificación:</strong> <?= htmlspecialchars($sc['justificacion']) ?></div><?php endif; ?>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><i class="fas fa-list"></i> Artículos / Servicios Requeridos</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>#</th><th>Descripción</th><th>Unidad</th><th>Cantidad</th><th>Precio Est.</th><th>Total Est.</th></tr></thead>
      <tbody>
        <?php $i=1; $tot=0; foreach ($detalle as $d): $tot+=$d['cantidad']*($d['precio_estimado']??0); ?>
        <tr>
          <td><?= $i++ ?></td><td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td><?= $d['unidad'] ?></td><td><?= $d['cantidad'] ?></td>
          <td class="font-mono"><?= lps($d['precio_estimado']??0) ?></td>
          <td class="font-mono"><?= lps($d['cantidad']*($d['precio_estimado']??0)) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="5" class="text-end fw-bold">Total Estimado</td><td class="font-mono fw-bold"><?= lps($sc['presupuesto_estimado']??0) ?></td></tr></tfoot>
    </table>
  </div>
</div>
</div>

<?php $adjModulo = 'compras_solicitud.php'; $adjId = $sc['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php else: ?>
<div class="card">
  <div class="card-header"><i class="fas fa-plus"></i> <?= $sc ? 'Editar: '.$sc['numero'] : 'Nueva Solicitud de Compra' ?></div>
  <div class="card-body">
    <form id="form-sc" action="compras_solicitud.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="guardar">
      <input type="hidden" name="id" value="<?= $sc['id']??0 ?>">
      <input type="hidden" name="items" id="items-json">
      <div class="row g-3">
        <div class="col-md-2"><label class="form-label">Fecha *</label><input type="text" name="fecha" class="form-control date-input" required value="<?= $sc['fecha']??date('Y-m-d') ?>"></div>
        <div class="col-md-4"><label class="form-label">Solicitante *</label><select name="solicitante_id" class="form-select" required><option value="">--</option><?php foreach($empleados as $e): ?><option value="<?= $e['id'] ?>" <?= ($sc['solicitante_id']??$_SESSION['empleado_id']??0)==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Proyecto</label><select name="proyecto_id" class="form-select"><option value="">-- General --</option><?php foreach($proyectos as $p): ?><option value="<?= $p['id'] ?>" <?= ($sc['proyecto_id']??0)==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['nombre']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Urgencia</label><select name="urgencia" class="form-select"><option value="normal">Normal</option><option value="urgente">Urgente</option><option value="muy_urgente">Muy Urgente</option></select></div>
        <div class="col-12"><label class="form-label">Descripción General *</label><input type="text" name="descripcion" class="form-control" required value="<?= htmlspecialchars($sc['descripcion']??'') ?>"></div>
        <div class="col-12"><label class="form-label">Justificación</label><textarea name="justificacion" class="form-control" rows="2"><?= htmlspecialchars($sc['justificacion']??'') ?></textarea></div>
      </div>
      <div class="form-section-title">Artículos / Servicios Requeridos</div>
      <div class="table-responsive">
        <table class="table-proscala w-100">
          <thead><tr><th>#</th><th>Descripción *</th><th>Unidad</th><th>Cantidad</th><th>Precio Est. (L.)</th><th>Total</th><th></th></tr></thead>
          <tbody id="tbody-items">
            <?php $initI=$detalle?:[['descripcion'=>'','unidad'=>'','cantidad'=>1,'precio_estimado'=>0]];
            foreach($initI as $idx=>$it): ?>
            <tr class="detail-row">
              <td class="row-num"><?= $idx+1 ?></td>
              <td><input type="text" class="form-control form-control-sm" data-field="descripcion" value="<?= htmlspecialchars($it['descripcion']??'') ?>" required></td>
              <td><input type="text" class="form-control form-control-sm" data-field="unidad" value="<?= htmlspecialchars($it['unidad']??'') ?>" placeholder="und, m, kg..."></td>
              <td><input type="number" class="form-control form-control-sm row-qty text-end" step="0.01" min="1" data-field="cantidad" value="<?= $it['cantidad']??1 ?>"></td>
              <td><input type="number" class="form-control form-control-sm row-price text-end" step="0.01" min="0" data-field="precio_estimado" value="<?= $it['precio_estimado']??0 ?>"></td>
              <td><input type="number" class="form-control form-control-sm row-total text-end font-mono" readonly value="<?= ($it['cantidad']??1)*($it['precio_estimado']??0) ?>"></td>
              <td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeDetailRow(this)"><i class="fas fa-trash"></i></button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="5" class="text-end fw-bold">TOTAL ESTIMADO:</td><td class="font-mono fw-bold" id="grand-total">L. 0.00</td><td></td></tr></tfoot>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-success mt-2" onclick="addScRow()"><i class="fas fa-plus"></i> Agregar Ítem</button>
      <?php $adjModulo = 'compras_solicitud.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
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

initDataTable('#tbl-sc');
recalcTotals();
function addScRow(){
  const idx=document.querySelectorAll('#tbody-items .detail-row').length;
  const tr=document.createElement('tr'); tr.className='detail-row';
  tr.innerHTML=`<td class="row-num">${idx+1}</td>
    <td><input type="text" class="form-control form-control-sm" data-field="descripcion" required></td>
    <td><input type="text" class="form-control form-control-sm" data-field="unidad" placeholder="und"></td>
    <td><input type="number" class="form-control form-control-sm row-qty text-end" step="0.01" min="1" data-field="cantidad" value="1"></td>
    <td><input type="number" class="form-control form-control-sm row-price text-end" step="0.01" min="0" data-field="precio_estimado" value="0"></td>
    <td><input type="number" class="form-control form-control-sm row-total text-end font-mono" readonly value="0"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeDetailRow(this)"><i class="fas fa-trash"></i></button></td>`;
  document.getElementById('tbody-items').appendChild(tr);
  updateDetailRowNumbers('tbody-items');
}
document.getElementById('form-sc')?.addEventListener('submit',function(e){
  e.preventDefault();
  const items=[];
  document.querySelectorAll('#tbody-items .detail-row').forEach(r=>{
    const qty=parseFloat(r.querySelector('[data-field=cantidad]')?.value||1);
    const price=parseFloat(r.querySelector('[data-field=precio_estimado]')?.value||0);
    items.push({descripcion:r.querySelector('[data-field=descripcion]')?.value,unidad:r.querySelector('[data-field=unidad]')?.value,cantidad:qty,precio_estimado:price,total:qty*price});
  });
  document.getElementById('items-json').value=JSON.stringify(items);
  post('compras_solicitud.php',new FormData(this)).then(r=>{
    if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.ir('compras_solicitud.php'), 1200);}
    else Toast.show(r.message,'error');
  }).catch(e=>Toast.show(e.message||'No se pudo guardar.','error',8000));
});

JS;
include __DIR__ . '/includes/footer.php';
?>


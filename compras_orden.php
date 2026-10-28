<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

// ── Data endpoint: SC items for a given cotización (GET, no CSRF) ──
if (($_GET['_action'] ?? '') === 'get_co_data' && isset($_GET['cotizacion_id'])) {
    $cqId = (int)$_GET['cotizacion_id'];
    $cq = $pdo->prepare("SELECT co.proveedor_seleccionado_id as prov_id, sc.id as sc_id
        FROM cotizaciones co JOIN solicitud_compra sc ON co.solicitud_id=sc.id WHERE co.id=?");
    $cq->execute([$cqId]); $cqRow = $cq->fetch();
    if (!$cqRow) { header('Content-Type:application/json'); echo json_encode(['success'=>false]); exit; }
    $cqItems = $pdo->prepare("SELECT descripcion,unidad,cantidad,precio_estimado FROM solicitud_compra_detalle WHERE solicitud_id=?");
    $cqItems->execute([$cqRow['sc_id']]);
    header('Content-Type:application/json');
    echo json_encode(['success'=>true,'prov_id'=>$cqRow['prov_id'],'sc_id'=>$cqRow['sc_id'],'items'=>$cqItems->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$coId   = (int)($_GET['co_id'] ?? 0);
$pagina = 'Orden de Compra';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $ocId  = (int)($_POST['id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        $sub   = array_sum(array_column($items,'total'));
        $desc  = (float)($_POST['descuento'] ?? 0);
        $imp   = (float)($_POST['impuestos'] ?? 0);
        $impT  = (float)($_POST['impuesto_turismo'] ?? 0);
        $total = $sub - $desc + $imp + $impT;
        $pdo->beginTransaction();
        try {
            if ($ocId) {
                $pdo->prepare("UPDATE ordenes_compra SET cotizacion_id=?,solicitud_id=?,proveedor_id=?,fecha=?,fecha_entrega=?,condiciones_pago=?,lugar_entrega=?,subtotal=?,descuento=?,impuestos=?,impuesto_turismo=?,total=?,proyecto_id=?,notas=? WHERE id=?")
                    ->execute([$_POST['cotizacion_id']?:null,$_POST['solicitud_id']?:null,$_POST['proveedor_id'],$_POST['fecha'],$_POST['fecha_entrega']?:null,$_POST['condiciones_pago']??'',$_POST['lugar_entrega']??'',$sub,$desc,$imp,$impT,$total,$_POST['proyecto_id']?:null,$_POST['notas']??'',$ocId]);
                $pdo->prepare("DELETE FROM ordenes_compra_detalle WHERE orden_id=?")->execute([$ocId]);
            } else {
                // Hereda el número de su Solicitud de Compra (directa o a través de la cotización)
                $scOrigen = $_POST['solicitud_id'] ?: null;
                if (!$scOrigen && $_POST['cotizacion_id']) {
                    $c = $pdo->prepare("SELECT solicitud_id FROM cotizaciones WHERE id=?");
                    $c->execute([$_POST['cotizacion_id']]);
                    $scOrigen = $c->fetchColumn() ?: null;
                }
                $num = numeroProceso($pdo,'ordenes_compra',getConfig($pdo,'prefijo_oc','OC'), numeroDe($pdo,'solicitud_compra',$scOrigen));
                $pdo->prepare("INSERT INTO ordenes_compra (numero,cotizacion_id,solicitud_id,proveedor_id,fecha,fecha_entrega,condiciones_pago,lugar_entrega,subtotal,descuento,impuestos,impuesto_turismo,total,proyecto_id,notas,estado,aprobador_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'emitida',?)")
                    ->execute([$num,$_POST['cotizacion_id']?:null,$_POST['solicitud_id']?:null,$_POST['proveedor_id'],$_POST['fecha'],$_POST['fecha_entrega']?:null,$_POST['condiciones_pago']??'',$_POST['lugar_entrega']??'',$sub,$desc,$imp,$impT,$total,$_POST['proyecto_id']?:null,$_POST['notas']??'',$_SESSION['empleado_id'] ?? null]);
                $ocId = $pdo->lastInsertId();
                if ($_POST['cotizacion_id']) $pdo->prepare("UPDATE solicitud_compra SET estado='adjudicada' WHERE id=(SELECT solicitud_id FROM cotizaciones WHERE id=?)")->execute([$_POST['cotizacion_id']]);
            }
            foreach ($items as $it) {
                $pdo->prepare("INSERT INTO ordenes_compra_detalle (orden_id,descripcion,unidad,cantidad,precio_unitario,total,cuenta_id) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$ocId,$it['descripcion'],$it['unidad']??'',$it['cantidad']??1,$it['precio']??0,$it['total']??0,$it['cuenta_id']??null]);
            }
            $pdo->commit();
            jsonOk(['id'=>$ocId],'Orden de Compra creada.');
        } catch(\Exception $e){ $pdo->rollBack(); jsonErr($e->getMessage()); }
    }
    if ($_act === 'cambiar_estado') {
        $ocId=(int)$_POST['id'];
        $pdo->prepare("UPDATE ordenes_compra SET estado=? WHERE id=?")->execute([$_POST['estado'],$ocId]);
        jsonOk([],'Estado actualizado.');
    }
    jsonErr('Acción desconocida.');
}

// Load data for pre-filling from cotización
$coData = null; $scItems = [];
if ($coId) {
    $s=$pdo->prepare("SELECT co.*,p.id as prov_id,p.nombre as prov_nombre,sc.id as sc_id,sc.numero as sc_num FROM cotizaciones co LEFT JOIN proveedores p ON co.proveedor_seleccionado_id=p.id JOIN solicitud_compra sc ON co.solicitud_id=sc.id WHERE co.id=?");
    $s->execute([$coId]); $coData=$s->fetch();
    if ($coData) {
        $si=$pdo->prepare("SELECT descripcion,unidad,cantidad,precio_estimado FROM solicitud_compra_detalle WHERE solicitud_id=?");
        $si->execute([$coData['sc_id']]); $scItems=$si->fetchAll();
    }
}

$oc = null; $detalle = [];
$traza = ['sc'=>null,'co'=>null,'op'=>null];
if (in_array($action,['ver','editar']) && $id) {
    $s=$pdo->prepare("SELECT oc.*,p.nombre as prov_nombre FROM ordenes_compra oc JOIN proveedores p ON oc.proveedor_id=p.id WHERE oc.id=?");
    $s->execute([$id]); $oc=$s->fetch();
    $s2=$pdo->prepare("SELECT ocd.*,cc.nombre as cta_nombre FROM ordenes_compra_detalle ocd LEFT JOIN cuentas_contables cc ON ocd.cuenta_id=cc.id WHERE ocd.orden_id=?");
    $s2->execute([$id]); $detalle=$s2->fetchAll();
    if ($action==='ver' && $oc) {
        // Solicitud de compra
        if ($oc['solicitud_id']) {
            $t=$pdo->prepare("SELECT id,numero,estado FROM solicitud_compra WHERE id=?");
            $t->execute([$oc['solicitud_id']]); $traza['sc']=$t->fetch();
        }
        // Cotización
        if ($oc['cotizacion_id']) {
            $t2=$pdo->prepare("SELECT id,numero,estado FROM cotizaciones WHERE id=?");
            $t2->execute([$oc['cotizacion_id']]); $traza['co']=$t2->fetch();
        }
        // Orden de pago
        $t3=$pdo->prepare("SELECT id,numero,estado FROM ordenes_pago WHERE orden_compra_id=? ORDER BY id DESC LIMIT 1");
        $t3->execute([$id]); $traza['op']=$t3->fetch();
    }
}

$proveedores = $pdo->query("SELECT id,nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$proyectos   = $pdo->query("SELECT id,nombre FROM proyectos WHERE estado='activo'")->fetchAll();
$cotAprobadas= $pdo->query("SELECT co.id,co.numero,sc.descripcion FROM cotizaciones co JOIN solicitud_compra sc ON co.solicitud_id=sc.id WHERE co.estado='aprobada' ORDER BY co.numero DESC")->fetchAll();
$cuentas     = $pdo->query("SELECT id,codigo,nombre FROM cuentas_contables WHERE activa=1 AND tipo='gasto' ORDER BY codigo")->fetchAll();
$lista = [];
if ($action==='list') $lista=$pdo->query("SELECT oc.*,p.nombre as prov_nombre FROM ordenes_compra oc JOIN proveedores p ON oc.proveedor_id=p.id WHERE " . filtroHistorico('oc') . " ORDER BY oc.id DESC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="workflow-steps mb-3">
  <a href="<?= BASE_URL ?>compras_solicitud.php" class="wf-step done"><i class="fas fa-cart-plus"></i><span>Solicitud de Compra</span></a>
  <a href="<?= BASE_URL ?>compras_cotizaciones.php" class="wf-step done"><i class="fas fa-file-lines"></i><span>Cotizaciones</span></a>
  <div class="wf-step active"><i class="fas fa-file-circle-check"></i><span>Orden de Compra</span></div>
  <a href="<?= BASE_URL ?>compras_pago.php" class="wf-step"><i class="fas fa-money-bill-transfer"></i><span>Orden de Pago</span></a>
  <a href="<?= BASE_URL ?>compras_recepcion.php" class="wf-step"><i class="fas fa-boxes-stacked"></i><span>Nota de Recepción</span></a>
</div>

<div class="page-header">
  <h1><i class="fas fa-file-circle-check"></i> Orden de Compra</h1>
  <?php if ($action==='list'): ?>
  <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nueva OC</a>
  <?php else: ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action==='list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card"><div class="card-body p-0">
  <table id="tbl-oc" class="table-proscala w-100">
    <thead><tr><th>Número</th><th>Fecha</th><th>Proveedor</th><th>Subtotal</th><th>Descuento</th><th>Impuestos</th><th>Total</th><th>Estado</th><th></th></tr></thead>
    <tbody>
      <?php foreach($lista as $r): ?>
      <tr>
        <td class="font-mono"><?= $r['numero'] ?><?= badgeHistorico($r) ?></td><td><?= fmtFecha($r['fecha']) ?></td>
        <td><?= htmlspecialchars($r['prov_nombre']) ?></td>
        <td class="font-mono"><?= lps($r['subtotal']) ?></td>
        <td class="font-mono"><?= lps($r['descuento'] ?? 0) ?></td>
        <td class="font-mono"><?= lps(($r['impuestos']??0) + ($r['impuesto_turismo']??0)) ?></td>
        <td class="font-mono fw-bold"><?= lps($r['total']) ?></td>
        <td><?= estadoBadge($r['estado']) ?></td>
        <td>
          <a href="?action=ver&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a>
          <?= botonEditar($pdo, 'compras_orden.php', (int)$r['id'], '?action=editar&id=' . $r['id'], true) ?>
          <?php if($r['estado']==='emitida'): ?>
          <a href="<?= BASE_URL ?>compras_pago.php?action=nuevo&oc_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-success py-0 px-2 ms-1" title="Crear Orden de Pago"><i class="fas fa-money-bill"></i></a>
          <a href="<?= BASE_URL ?>compras_recepcion.php?action=nuevo&oc_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-info py-0 px-2 ms-1" title="Nota de Recepción"><i class="fas fa-boxes-stacked"></i></a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(!$lista): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin órdenes de compra</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php elseif ($action==='ver' && $oc): ?>
<?php $flujoModulo = 'compras_orden.php'; $flujoId = $oc['id']; include __DIR__ . '/includes/flujo_aprobacion.php'; ?>

<!-- Cadena de proceso -->
<div class="trace-chain">
  <?php if($traza['sc']): ?>
  <a href="<?= BASE_URL ?>compras_solicitud.php?action=ver&id=<?= $traza['sc']['id'] ?>" class="trace-step linked">
    <i class="fas fa-cart-plus"></i>
    <span class="trace-step-label">Solicitud</span>
    <span class="trace-step-num"><?= $traza['sc']['numero'] ?></span>
    <span class="trace-step-badge"><?= estadoBadge($traza['sc']['estado']) ?></span>
  </a>
  <?php else: ?>
  <div class="trace-step inactive">
    <i class="fas fa-cart-plus"></i>
    <span class="trace-step-label">Solicitud</span>
    <span class="trace-step-num">—</span>
  </div>
  <?php endif; ?>
  <?php if($traza['co']): ?>
  <a href="<?= BASE_URL ?>compras_cotizaciones.php?action=ver&id=<?= $traza['co']['id'] ?>" class="trace-step linked">
    <i class="fas fa-file-lines"></i>
    <span class="trace-step-label">Cotización</span>
    <span class="trace-step-num"><?= $traza['co']['numero'] ?></span>
    <span class="trace-step-badge"><?= estadoBadge($traza['co']['estado']) ?></span>
  </a>
  <?php else: ?>
  <div class="trace-step inactive">
    <i class="fas fa-file-lines"></i>
    <span class="trace-step-label">Cotización</span>
    <span class="trace-step-num">—</span>
  </div>
  <?php endif; ?>
  <div class="trace-step active">
    <i class="fas fa-file-circle-check"></i>
    <span class="trace-step-label">Orden de Compra</span>
    <span class="trace-step-num"><?= $oc['numero'] ?></span>
    <span class="trace-step-badge"><?= estadoBadge($oc['estado']) ?></span>
  </div>
  <?php if($traza['op']): ?>
  <a href="<?= BASE_URL ?>compras_pago.php?action=ver&id=<?= $traza['op']['id'] ?>" class="trace-step linked">
    <i class="fas fa-money-bill-transfer"></i>
    <span class="trace-step-label">Orden de Pago</span>
    <span class="trace-step-num"><?= $traza['op']['numero'] ?></span>
    <span class="trace-step-badge"><?= estadoBadge($traza['op']['estado']) ?></span>
  </a>
  <?php else: ?>
  <div class="trace-step inactive">
    <i class="fas fa-money-bill-transfer"></i>
    <span class="trace-step-label">Orden de Pago</span>
    <span class="trace-step-num">—</span>
  </div>
  <?php endif; ?>
</div>

<div id="print-area">
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-file-circle-check"></i> ORDEN DE COMPRA <?= $oc['numero'] ?> — <?= estadoBadge($oc['estado']) ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?php if($oc['estado']==='emitida'): ?>
      <a href="<?= BASE_URL ?>compras_pago.php?action=nuevo&oc_id=<?= $oc['id'] ?>" class="btn btn-sm btn-success"><i class="fas fa-money-bill"></i> Generar OP</a>
      <a href="<?= BASE_URL ?>compras_recepcion.php?action=nuevo&oc_id=<?= $oc['id'] ?>" class="btn btn-sm btn-info text-white"><i class="fas fa-boxes-stacked"></i> Nota Recepción</a>
      <?php endif; ?>
      <?= botonEditar($pdo, 'compras_orden.php', (int)$oc['id'], '?action=editar&id=' . $oc['id']) ?>
      <a href="<?= BASE_URL ?>print.php?tipo=oc&id=<?= $oc['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
      <?php if ($oc['solicitud_id']): ?>
      <a href="<?= BASE_URL ?>print.php?tipo=proceso&id=<?= $oc['solicitud_id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark"><i class="fas fa-layer-group"></i> Proceso Completo</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body" style="font-size:.85rem;">
    <div class="row g-2">
      <div class="col-md-4"><strong>Proveedor:</strong> <?= htmlspecialchars($oc['prov_nombre']) ?></div>
      <div class="col-md-2"><strong>Fecha:</strong> <?= fmtFecha($oc['fecha']) ?></div>
      <div class="col-md-2"><strong>Entrega:</strong> <?= fmtFecha($oc['fecha_entrega']??'') ?></div>
      <div class="col-md-2"><strong>Condiciones:</strong> <?= htmlspecialchars($oc['condiciones_pago']??'—') ?></div>
      <div class="col-md-2"><strong>Lugar:</strong> <?= htmlspecialchars($oc['lugar_entrega']??'—') ?></div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><i class="fas fa-list"></i> Ítems</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>#</th><th>Descripción</th><th>Unidad</th><th>Cantidad</th><th>Precio Unit.</th><th>Total</th><th>Cuenta</th></tr></thead>
      <tbody>
        <?php $i=1; foreach($detalle as $d): ?>
        <tr><td><?= $i++ ?></td><td><?= htmlspecialchars($d['descripcion']) ?></td><td><?= $d['unidad'] ?></td><td><?= $d['cantidad'] ?></td>
        <td class="font-mono"><?= lps($d['precio_unitario']) ?></td><td class="font-mono"><?= lps($d['total']) ?></td>
        <td><?= htmlspecialchars($d['cta_nombre']??'—') ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="5" class="text-end">Subtotal:</td><td class="font-mono"><?= lps($oc['subtotal']) ?></td><td></td></tr>
        <tr><td colspan="5" class="text-end">Descuento:</td><td class="font-mono"><?= lps($oc['descuento'] ?? 0) ?></td><td></td></tr>
        <tr><td colspan="5" class="text-end">Impuesto ISV:</td><td class="font-mono"><?= lps($oc['impuestos']) ?></td><td></td></tr>
        <tr><td colspan="5" class="text-end">Impuesto Turismo:</td><td class="font-mono"><?= lps($oc['impuesto_turismo'] ?? 0) ?></td><td></td></tr>
        <tr><td colspan="5" class="text-end fw-bold">TOTAL:</td><td class="font-mono fw-bold"><?= lps($oc['total']) ?></td><td></td></tr>
      </tfoot>
    </table>
  </div>
</div>
</div>

<?php $adjModulo = 'compras_orden.php'; $adjId = $oc['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php else: ?>
<?php $ocE = $action === 'editar' ? $oc : null;   // edición (rangos superiores): el formulario trae la OC guardada ?>
<div class="card"><div class="card-header"><i class="fas <?= $ocE ? 'fa-edit' : 'fa-plus' ?>"></i> <?= $ocE ? 'Editar orden de compra ' . htmlspecialchars($ocE['numero']) : 'Nueva Orden de Compra' ?></div>
<div class="card-body">
  <form id="form-oc" action="compras_orden.php" method="POST">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="_action" value="guardar">
    <input type="hidden" name="id" value="<?= (int)($ocE['id'] ?? 0) ?>">
    <input type="hidden" name="items" id="items-json">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Cotización de Referencia</label>
        <select name="cotizacion_id" class="form-select" id="sel-co" onchange="loadCo(this.value)">
          <option value="">-- Sin referencia --</option>
          <?php $coSel = $ocE['cotizacion_id'] ?? $coId;
          // La cotización de una OC en edición puede ya no estar entre las aprobadas
          if ($coSel && !in_array($coSel, array_column($cotAprobadas, 'id'))): ?>
          <option value="<?= (int)$coSel ?>" selected><?= htmlspecialchars(numeroDe($pdo, 'cotizaciones', $coSel) ?? '') ?></option>
          <?php endif; ?>
          <?php foreach($cotAprobadas as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $coSel==$c['id']?'selected':'' ?>><?= $c['numero'] ?> — <?= htmlspecialchars($c['descripcion']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Proveedor *</label>
        <select name="proveedor_id" class="form-select" required id="sel-prov">
          <option value="">--</option>
          <?php foreach($proveedores as $p): ?>
          <option value="<?= $p['id'] ?>" <?= (($ocE['proveedor_id'] ?? ($coData['prov_id'] ?? 0))==$p['id'])?'selected':'' ?>><?= htmlspecialchars($p['nombre']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label">Fecha *</label><input type="text" name="fecha" class="form-control date-input" required value="<?= $ocE['fecha'] ?? date('Y-m-d') ?>"></div>
      <div class="col-md-2"><label class="form-label">Fecha Entrega</label><input type="text" name="fecha_entrega" class="form-control date-input" value="<?= $ocE['fecha_entrega'] ?? '' ?>"></div>
      <div class="col-md-2">
        <label class="form-label">Proyecto</label>
        <select name="proyecto_id" class="form-select"><option value="">--</option><?php foreach($proyectos as $p): ?><option value="<?= $p['id'] ?>" <?= ($ocE['proyecto_id'] ?? 0)==$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nombre']) ?></option><?php endforeach; ?></select>
      </div>
      <div class="col-md-4"><label class="form-label">Condiciones de Pago</label><input type="text" name="condiciones_pago" class="form-control" value="<?= htmlspecialchars($ocE['condiciones_pago'] ?? '') ?>" placeholder="ej: Contado, 30 días, 50/50"></div>
      <div class="col-md-4"><label class="form-label">Lugar de Entrega</label><input type="text" name="lugar_entrega" class="form-control" value="<?= htmlspecialchars($ocE['lugar_entrega'] ?? '') ?>" placeholder="ej: Oficinas Proscala, Tegucigalpa"></div>
      <div class="col-md-4"><label class="form-label">Notas</label><input type="text" name="notas" class="form-control" value="<?= htmlspecialchars($ocE['notas'] ?? '') ?>"></div>
      <input type="hidden" name="solicitud_id" id="hd-sc" value="<?= $ocE['solicitud_id'] ?? ($coData['sc_id'] ?? '') ?>">
    </div>
    <div class="form-section-title">Ítems de la Orden</div>
    <div class="table-responsive">
      <table class="table-proscala w-100">
        <thead><tr><th>#</th><th>Descripción</th><th>Unidad</th><th>Cantidad</th><th>Precio Unit. (L.)</th><th>Total</th><th>Cuenta</th><th></th></tr></thead>
        <?php
        $initOcRows = $ocE ? array_map(fn($d)=>[
            'descripcion'=>$d['descripcion'],'unidad'=>$d['unidad'],'cantidad'=>$d['cantidad'],
            'precio'=>$d['precio_unitario'],'total'=>$d['total'],'cuenta_id'=>$d['cuenta_id'],
        ],$detalle) : ($scItems ? array_map(fn($it)=>[
            'descripcion'=>$it['descripcion'],'unidad'=>$it['unidad'],
            'cantidad'=>$it['cantidad'],'precio'=>$it['precio_estimado']??0,
            'total'=>($it['cantidad']??1)*($it['precio_estimado']??0),'cuenta_id'=>'',
        ],$scItems) : [['descripcion'=>'','unidad'=>'','cantidad'=>1,'precio'=>0,'total'=>0,'cuenta_id'=>'']]);
        ?>
        <tbody id="tbody-items">
          <?php $ri=1; foreach($initOcRows as $rit): ?>
          <tr class="detail-row">
            <td class="row-num"><?= $ri++ ?></td>
            <td><input type="text" class="form-control form-control-sm" data-field="descripcion" required value="<?= htmlspecialchars($rit['descripcion']) ?>"></td>
            <td><input type="text" class="form-control form-control-sm" data-field="unidad" placeholder="und" value="<?= htmlspecialchars($rit['unidad']??'') ?>"></td>
            <td><input type="number" class="form-control form-control-sm row-qty text-end" step="0.01" min="0" data-field="cantidad" value="<?= $rit['cantidad']??1 ?>"></td>
            <td><input type="number" class="form-control form-control-sm row-price text-end" step="0.01" min="0" data-field="precio" value="<?= $rit['precio']??0 ?>"></td>
            <td><input type="number" class="form-control form-control-sm row-total text-end font-mono" readonly value="<?= $rit['total']??0 ?>"></td>
            <td><select class="form-select form-select-sm" data-field="cuenta_id"><option value="">--</option><?php foreach($cuentas as $c): ?><option value="<?= $c['id'] ?>" <?= ($rit['cuenta_id'] ?? '')==$c['id'] ? 'selected' : '' ?>><?= $c['codigo'] ?> <?= htmlspecialchars($c['nombre']) ?></option><?php endforeach; ?></select></td>
            <td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeDetailRow(this)"><i class="fas fa-trash"></i></button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td colspan="5" class="text-end">Subtotal:</td><td class="font-mono" id="grand-total">L. 0.00</td><td colspan="2"></td></tr>
          <tr>
            <td colspan="4" class="text-end">Descuento:</td>
            <td><div class="input-group input-group-sm"><input type="number" id="desc-pct" class="form-control text-end" step="0.01" min="0" value="0" oninput="onPctChange('desc')"><span class="input-group-text">%</span></div></td>
            <td><input type="number" name="descuento" id="desc-monto" class="form-control form-control-sm text-end font-mono" step="0.01" min="0" value="<?= $ocE['descuento'] ?? 0 ?>" oninput="onMontoEdit('desc')"></td>
            <td colspan="2"></td>
          </tr>
          <tr>
            <td colspan="4" class="text-end">Impuesto ISV:</td>
            <td><div class="input-group input-group-sm"><input type="number" id="isv-pct" class="form-control text-end" step="0.01" min="0" value="15" oninput="onPctChange('isv')"><span class="input-group-text">%</span></div></td>
            <td><input type="number" name="impuestos" id="isv-monto" class="form-control form-control-sm text-end font-mono" step="0.01" min="0" value="<?= $ocE['impuestos'] ?? 0 ?>" oninput="onMontoEdit('isv')"></td>
            <td colspan="2"></td>
          </tr>
          <tr>
            <td colspan="4" class="text-end">Impuesto Turismo:</td>
            <td><div class="input-group input-group-sm"><input type="number" id="tur-pct" class="form-control text-end" step="0.01" min="0" value="0" oninput="onPctChange('tur')"><span class="input-group-text">%</span></div></td>
            <td><input type="number" name="impuesto_turismo" id="tur-monto" class="form-control form-control-sm text-end font-mono" step="0.01" min="0" value="<?= $ocE['impuesto_turismo'] ?? 0 ?>" oninput="onMontoEdit('tur')"></td>
            <td colspan="2"></td>
          </tr>
          <tr><td colspan="5" class="text-end fw-bold">TOTAL:</td><td class="font-mono fw-bold" id="total-final">L. 0.00</td><td colspan="2"></td></tr>
        </tfoot>
      </table>
    </div>
    <button type="button" class="btn btn-sm btn-outline-success mt-2" onclick="addOcRow()"><i class="fas fa-plus"></i> Agregar Ítem</button>
    <?php $adjModulo = 'compras_orden.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
    <div class="mt-4 d-flex gap-2">
      <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> <?= $ocE ? 'Guardar cambios' : 'Emitir Orden de Compra' ?></button>
      <a href="?" class="btn btn-outline-secondary btn-sm">Cancelar</a>
    </div>
  </form>
</div></div>
<?php endif; ?>

<?php
$cuentasJson = json_encode(array_map(fn($c)=>['id'=>$c['id'],'codigo'=>$c['codigo'],'nombre'=>$c['nombre']],$cuentas));
$extraJs = "
initDataTable('#tbl-oc');
// Al editar, descuento e impuestos guardados se respetan (no se recalculan desde el %)
const taxDirty = {desc:" . ($action === 'editar' && $oc ? 'true' : 'false') . ", isv:" . ($action === 'editar' && $oc ? 'true' : 'false') . ", tur:" . ($action === 'editar' && $oc ? 'true' : 'false') . "};
recalcTotals();
updateTotal();

function calcSubtotal(){
  return [...document.querySelectorAll('#tbody-items .row-total')].reduce((s,e)=>s+parseFloat(e.value||0),0);
}
function recomputeTaxes(){
  const sub = calcSubtotal();
  if(!taxDirty.desc) document.getElementById('desc-monto').value = (sub*parseFloat(document.getElementById('desc-pct').value||0)/100).toFixed(2);
  const descMonto = parseFloat(document.getElementById('desc-monto')?.value||0);
  const base = Math.max(sub-descMonto,0);
  if(!taxDirty.isv) document.getElementById('isv-monto').value = (base*parseFloat(document.getElementById('isv-pct').value||0)/100).toFixed(2);
  if(!taxDirty.tur) document.getElementById('tur-monto').value = (base*parseFloat(document.getElementById('tur-pct').value||0)/100).toFixed(2);
}
function onPctChange(key){ taxDirty[key]=false; updateTotal(); }
function onMontoEdit(key){ taxDirty[key]=true; updateTotal(); }
function updateTotal(){
  recomputeTaxes();
  const sub  = calcSubtotal();
  const desc = parseFloat(document.getElementById('desc-monto')?.value||0);
  const isv  = parseFloat(document.getElementById('isv-monto')?.value||0);
  const tur  = parseFloat(document.getElementById('tur-monto')?.value||0);
  const tot  = document.getElementById('total-final');
  if(tot) tot.textContent = lps(sub-desc+isv+tur);
}
const origR = window.recalcTotals;
window.recalcTotals = function(){ if(origR) origR(); updateTotal(); };

function addOcRow(){
  const opts = " . $cuentasJson . ".map(c=>`<option value=\"\${c.id}\">\${c.codigo} \${c.nombre}</option>`).join('');
  const tr=document.createElement('tr'); tr.className='detail-row';
  tr.innerHTML=`<td class=\"row-num\"></td>
    <td><input type=\"text\" class=\"form-control form-control-sm\" data-field=\"descripcion\" required></td>
    <td><input type=\"text\" class=\"form-control form-control-sm\" data-field=\"unidad\" placeholder=\"und\"></td>
    <td><input type=\"number\" class=\"form-control form-control-sm row-qty text-end\" step=\"0.01\" min=\"0\" data-field=\"cantidad\" value=\"1\"></td>
    <td><input type=\"number\" class=\"form-control form-control-sm row-price text-end\" step=\"0.01\" min=\"0\" data-field=\"precio\" value=\"0\"></td>
    <td><input type=\"number\" class=\"form-control form-control-sm row-total text-end font-mono\" readonly value=\"0\"></td>
    <td><select class=\"form-select form-select-sm\" data-field=\"cuenta_id\"><option value=\"\">--</option>\${opts}</select></td>
    <td><button type=\"button\" class=\"btn btn-sm btn-outline-danger py-0 px-1\" onclick=\"removeDetailRow(this)\"><i class=\"fas fa-trash\"></i></button></td>`;
  document.getElementById('tbody-items').appendChild(tr);
  updateDetailRowNumbers('tbody-items');
}
function escHtml(s){return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;');}
function loadCo(coId){
  if(!coId){document.getElementById('hd-sc').value='';return;}
  fetch('compras_orden.php?_action=get_co_data&cotizacion_id='+coId)
    .then(r=>r.json()).then(data=>{
      if(!data.success)return;
      document.getElementById('hd-sc').value=data.sc_id||'';
      const sp=document.getElementById('sel-prov');if(sp&&data.prov_id)sp.value=data.prov_id;
      if(data.items&&data.items.length){
        const lOpts=" . $cuentasJson . ".map(c=>`<option value=\"\${c.id}\">\${c.codigo} \${c.nombre}</option>`).join('');
        const tb=document.getElementById('tbody-items');tb.innerHTML='';
        data.items.forEach((it,i)=>{
          const p=parseFloat(it.precio_estimado)||0,q=parseFloat(it.cantidad)||1;
          const tr=document.createElement('tr');tr.className='detail-row';
          tr.innerHTML=`<td class=\"row-num\">\${i+1}</td>
            <td><input type=\"text\" class=\"form-control form-control-sm\" data-field=\"descripcion\" required value=\"\${escHtml(it.descripcion)}\"></td>
            <td><input type=\"text\" class=\"form-control form-control-sm\" data-field=\"unidad\" placeholder=\"und\" value=\"\${escHtml(it.unidad||'')}\"></td>
            <td><input type=\"number\" class=\"form-control form-control-sm row-qty text-end\" step=\"0.01\" min=\"0\" data-field=\"cantidad\" value=\"\${q}\"></td>
            <td><input type=\"number\" class=\"form-control form-control-sm row-price text-end\" step=\"0.01\" min=\"0\" data-field=\"precio\" value=\"\${p}\"></td>
            <td><input type=\"number\" class=\"form-control form-control-sm row-total text-end font-mono\" readonly value=\"\${q*p}\"></td>
            <td><select class=\"form-select form-select-sm\" data-field=\"cuenta_id\"><option value=\"\">--</option>\${lOpts}</select></td>
            <td><button type=\"button\" class=\"btn btn-sm btn-outline-danger py-0 px-1\" onclick=\"removeDetailRow(this)\"><i class=\"fas fa-trash\"></i></button></td>`;
          tb.appendChild(tr);
        });
        recalcTotals();updateTotal();
      }
    }).catch(e=>console.error(e));
}
document.getElementById('form-oc')?.addEventListener('submit',function(e){
  e.preventDefault();
  const items=[];
  document.querySelectorAll('#tbody-items .detail-row').forEach(r=>{
    const qty=parseFloat(r.querySelector('[data-field=cantidad]')?.value||1);
    const price=parseFloat(r.querySelector('[data-field=precio]')?.value||0);
    items.push({descripcion:r.querySelector('[data-field=descripcion]')?.value,unidad:r.querySelector('[data-field=unidad]')?.value,cantidad:qty,precio:price,total:qty*price,cuenta_id:r.querySelector('[data-field=cuenta_id]')?.value});
  });
  document.getElementById('items-json').value=JSON.stringify(items);
  post('compras_orden.php',new FormData(this)).then(r=>{
    if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.ir('compras_orden.php'), 1200);}
    else Toast.show(r.message,'error');
  }).catch(()=>Toast.show('Error','error'));
});
";
include __DIR__ . '/includes/footer.php';
?>

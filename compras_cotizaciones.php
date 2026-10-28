<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/config/cotizaciones.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$scId   = (int)($_GET['sc_id'] ?? 0);
$pagina = 'Resumen de Cotizaciones';

/** Artículos de una solicitud de compra, para empezar el cuadro comparativo. */
function itemsSolicitud(PDO $pdo, int $scId): array {
    $s = $pdo->prepare("SELECT id, descripcion, unidad, cantidad FROM solicitud_compra_detalle WHERE solicitud_id = ? ORDER BY id");
    $s->execute([$scId]);
    return array_map(fn($d) => ['sd_id' => (int)$d['id'], 'descripcion' => $d['descripcion'], 'unidad' => $d['unidad'] ?? '',
                                'cantidad' => (float)$d['cantidad']], $s->fetchAll());
}

// ── Artículos de la solicitud elegida en el formulario (GET) ──────────
if (($_GET['_action'] ?? '') === 'items_sc') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'items' => itemsSolicitud($pdo, (int)($_GET['sc_id'] ?? 0))]);
    exit;
}

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $coId = (int)($_POST['id'] ?? 0);
        $itemsIn = json_decode($_POST['items_data'] ?? '[]', true);
        $provsIn = json_decode($_POST['proveedores_data'] ?? '[]', true);
        if (!is_array($itemsIn) || !is_array($provsIn)) jsonErr('Datos de la cotización no válidos.');
        if (!(int)($_POST['solicitud_id'] ?? 0)) jsonErr('Elija la solicitud de compra.');

        // Artículos: los vacíos se descartan; $orig guarda su posición en el formulario (precios)
        $items = [];
        foreach (array_values($itemsIn) as $i => $it) {
            $desc = mb_substr(trim((string)($it['descripcion'] ?? '')), 0, 300);
            if ($desc === '') continue;
            $q = (float)($it['cantidad'] ?? 0);
            if ($q <= 0) jsonErr("La cantidad de «{$desc}» debe ser mayor que cero.");
            $items[] = ['orig' => $i, 'sd_id' => (int)($it['sd_id'] ?? 0) ?: null, 'descripcion' => $desc,
                        'unidad' => mb_substr(trim((string)($it['unidad'] ?? '')), 0, 50), 'cantidad' => round($q, 2)];
        }
        // Ofertas: precio por artículo (vacío = no lo cotizó); sin precios, el monto global
        $ofertas = []; $vistos = [];
        foreach ($provsIn as $pv) {
            $pid = (int)($pv['proveedor_id'] ?? 0);
            if (!$pid) continue;
            if (isset($vistos[$pid])) jsonErr('Un proveedor aparece más de una vez en el cuadro comparativo.');
            $vistos[$pid] = true;
            $precios = [];
            foreach ($items as $k => $it) {
                $v = $pv['precios'][$it['orig']] ?? '';
                if ($v === '' || $v === null) continue;
                if (!is_numeric($v) || (float)$v < 0) jsonErr("Precio no válido para «{$it['descripcion']}».");
                $precios[$k] = round((float)$v, 2);
            }
            $total = $precios ? array_sum(array_map(fn($k, $p) => $items[$k]['cantidad'] * $p, array_keys($precios), $precios))
                              : max(0, (float)($pv['total_global'] ?? 0));
            $ofertas[] = ['proveedor_id' => $pid, 'num_cotizacion' => mb_substr(trim((string)($pv['num_cotizacion'] ?? '')), 0, 50),
                          'fecha' => ($pv['fecha'] ?? '') ?: null, 'condiciones' => mb_substr(trim((string)($pv['condiciones'] ?? '')), 0, 200),
                          'tiempo_entrega' => mb_substr(trim((string)($pv['tiempo_entrega'] ?? '')), 0, 100), 'notas' => trim((string)($pv['notas'] ?? '')),
                          'total' => round($total, 2), 'precios' => $precios];
        }
        if (!$ofertas) jsonErr('Agregue al menos un proveedor con su cotización.');
        $sel = (int)($_POST['proveedor_seleccionado_id'] ?? 0) ?: null;
        if ($sel && !isset($vistos[$sel])) jsonErr('El proveedor seleccionado debe ser uno de los que cotizaron.');

        $pdo->beginTransaction();
        try {
            if ($coId) {
                $pdo->prepare("UPDATE cotizaciones SET solicitud_id=?,fecha=?,elaborado_por_id=?,proveedor_seleccionado_id=?,justificacion=? WHERE id=?")
                    ->execute([$_POST['solicitud_id'],$_POST['fecha'],$_POST['elaborado_por_id']?:null,$sel,trim($_POST['justificacion']??''),$coId]);
                // Los precios se borran en cascada con artículos y ofertas
                $pdo->prepare("DELETE FROM cotizaciones_items WHERE cotizacion_id=?")->execute([$coId]);
                $pdo->prepare("DELETE FROM cotizaciones_proveedores WHERE cotizacion_id=?")->execute([$coId]);
            } else {
                $num = numeroProceso($pdo,'cotizaciones',getConfig($pdo,'prefijo_co','CO'), numeroDe($pdo,'solicitud_compra',$_POST['solicitud_id']));
                $pdo->prepare("INSERT INTO cotizaciones (numero,solicitud_id,fecha,elaborado_por_id,proveedor_seleccionado_id,justificacion,estado)
                    VALUES (?,?,?,?,?,?,'pendiente')")->execute([$num,$_POST['solicitud_id'],$_POST['fecha'],$_POST['elaborado_por_id']?:null,$sel,trim($_POST['justificacion']??'')]);
                $coId = $pdo->lastInsertId();
                $pdo->prepare("UPDATE solicitud_compra SET estado='cotizando' WHERE id=?")->execute([$_POST['solicitud_id']]);
            }
            $insItem = $pdo->prepare("INSERT INTO cotizaciones_items (cotizacion_id,solicitud_detalle_id,orden,descripcion,unidad,cantidad) VALUES (?,?,?,?,?,?)");
            $itemIds = [];
            foreach ($items as $k => $it) {
                $insItem->execute([$coId, $it['sd_id'], $k + 1, $it['descripcion'], $it['unidad'], $it['cantidad']]);
                $itemIds[$k] = (int)$pdo->lastInsertId();
            }
            $insOf = $pdo->prepare("INSERT INTO cotizaciones_proveedores (cotizacion_id,proveedor_id,num_cotizacion,fecha_cotizacion,total,condiciones,tiempo_entrega,notas)
                VALUES (?,?,?,?,?,?,?,?)");
            $insPr = $pdo->prepare("INSERT INTO cotizaciones_precios (item_id,cotizacion_proveedor_id,precio_unitario) VALUES (?,?,?)");
            foreach ($ofertas as $of) {
                $insOf->execute([$coId,$of['proveedor_id'],$of['num_cotizacion'],$of['fecha'],$of['total'],$of['condiciones'],$of['tiempo_entrega'],$of['notas']]);
                $ofId = (int)$pdo->lastInsertId();
                foreach ($of['precios'] as $k => $p) $insPr->execute([$itemIds[$k], $ofId, $p]);
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

$co = null; $cot = ['items' => [], 'ofertas' => []]; $an = null;
if (in_array($action,['ver','editar']) && $id) {
    $s=$pdo->prepare("SELECT co.*,sc.numero as sc_num,sc.descripcion as sc_desc,CONCAT(e.nombre,' ',e.apellidos) as elab_nombre
        FROM cotizaciones co JOIN solicitud_compra sc ON co.solicitud_id=sc.id
        LEFT JOIN empleados e ON co.elaborado_por_id=e.id WHERE co.id=?");
    $s->execute([$id]); $co=$s->fetch();
    if ($co) { $cot = cargarCotizacion($pdo, (int)$co['id']); $an = analizarCotizacion($cot['items'], $cot['ofertas']); }
}
$solicitudes  = $pdo->query("SELECT id,numero,descripcion FROM solicitud_compra WHERE estado IN ('aprobada','cotizando') ORDER BY numero DESC")->fetchAll();
$empleados    = $pdo->query("SELECT id,CONCAT(nombre,' ',apellidos) as nombre FROM empleados WHERE activo=1 ORDER BY apellidos")->fetchAll();
$proveedores  = $pdo->query("SELECT id,nombre FROM proveedores WHERE activo=1 ORDER BY nombre")->fetchAll();
$lista = [];
if ($action==='list') $lista=$pdo->query("SELECT co.*,sc.numero as sc_num,sc.descripcion as sc_desc,p.nombre as prov_sel FROM cotizaciones co JOIN solicitud_compra sc ON co.solicitud_id=sc.id LEFT JOIN proveedores p ON co.proveedor_seleccionado_id=p.id WHERE " . filtroHistorico('co') . " ORDER BY co.id DESC")->fetchAll();

// Estado inicial del cuadro comparativo del formulario: artículos y ofertas (precios por posición)
$matriz = ['items' => [], 'ofertas' => []];
if (in_array($action, ['nuevo', 'editar'], true)) {
    if ($co && $cot['items']) {
        $matriz['items'] = array_map(fn($it) => ['sd_id' => $it['solicitud_detalle_id'] ? (int)$it['solicitud_detalle_id'] : null,
            'descripcion' => $it['descripcion'], 'unidad' => $it['unidad'] ?? '', 'cantidad' => (float)$it['cantidad']], $cot['items']);
    } elseif ($co || $scId) {
        $matriz['items'] = itemsSolicitud($pdo, $co ? (int)$co['solicitud_id'] : $scId);   // cotización anterior sin artículos, o nueva
    }
    foreach ($cot['ofertas'] as $o) {
        $matriz['ofertas'][] = ['proveedor_id' => (int)$o['proveedor_id'], 'num_cotizacion' => $o['num_cotizacion'] ?? '',
            'fecha' => $o['fecha_cotizacion'] ?? '', 'condiciones' => $o['condiciones'] ?? '', 'tiempo_entrega' => $o['tiempo_entrega'] ?? '',
            'notas' => $o['notas'] ?? '', 'total_global' => $o['precios'] ? '' : (float)$o['total'],
            'precios' => $cot['items'] ? array_map(fn($it) => $o['precios'][(int)$it['id']] ?? '', $cot['items']) : []];
    }
}

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

<?php elseif ($action==='ver' && $co):
  $ofertasPorId = array_column($cot['ofertas'], null, 'id');
  $nombreOf = fn($oid) => $ofertasPorId[$oid]['prov_nombre'] ?? '—';
  $aprobada = $co['estado'] === 'aprobada';
  $urlOc = fn(int $provId, bool $plan = false) => BASE_URL . 'compras_orden.php?action=nuevo&co_id=' . $co['id'] . '&prov_id=' . $provId . ($plan ? '&plan=articulo' : '');
?>
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-file-lines"></i> <?= $co['numero'] ?> — Ref. SC: <?= $co['sc_num'] ?> — <?= estadoBadge($co['estado']) ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?php if($co['estado']==='pendiente'): ?>
      <button class="btn btn-sm btn-success" onclick="cambiarEstado('compras_cotizaciones.php',<?=$co['id']?>,'aprobada','cotizaciones',()=>Nav.recargar())"><i class="fas fa-check"></i> Aprobar</button>
      <?php endif; ?>
      <?php if($aprobada && $co['proveedor_seleccionado_id']): ?>
      <a href="<?= $urlOc((int)$co['proveedor_seleccionado_id']) ?>" class="btn btn-sm btn-primary"><i class="fas fa-file-circle-check"></i> Crear OC</a>
      <?php endif; ?>
      <?= botonEditar($pdo, 'compras_cotizaciones.php', (int)$co['id'], '?action=editar&id=' . $co['id']) ?>
      <a href="<?= BASE_URL ?>print.php?tipo=co&id=<?= $co['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF Resumen</a>
    </div>
  </div>
  <div class="card-body" style="font-size:.85rem;">
    <div class="row g-2">
      <div class="col-md-4"><strong>Fecha:</strong> <?= fmtFecha($co['fecha']) ?></div>
      <div class="col-md-4"><strong>Elaborado por:</strong> <?= htmlspecialchars($co['elab_nombre']??'—') ?></div>
      <div class="col-md-4"><strong>SC:</strong> <?= htmlspecialchars($co['sc_desc']) ?></div>
      <div class="col-md-4"><strong>Proveedor seleccionado:</strong> <?= htmlspecialchars($co['proveedor_seleccionado_id'] ? (array_column($cot['ofertas'], 'prov_nombre', 'proveedor_id')[$co['proveedor_seleccionado_id']] ?? '—') : 'Pendiente') ?></div>
      <?php if($co['justificacion']): ?><div class="col-12"><strong>Justificación de Selección:</strong> <?= htmlspecialchars($co['justificacion']) ?></div><?php endif; ?>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><i class="fas fa-balance-scale"></i> Cuadro Comparativo de Cotizaciones</div>
  <div class="card-body p-0" style="overflow-x:auto;">
    <?php if ($an['con_detalle']): ?>
    <table class="table-proscala w-100 cot-matriz-ver">
      <thead><tr><th>#</th><th>Artículo</th><th class="text-end">Cant.</th>
        <?php foreach ($cot['ofertas'] as $o): ?><th class="text-end"><?= htmlspecialchars($o['prov_nombre']) ?><?= (int)$o['proveedor_id'] === (int)$co['proveedor_seleccionado_id'] ? ' <i class="fas fa-check-circle text-success" title="Seleccionado"></i>' : '' ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($cot['items'] as $i => $it): $iid = (int)$it['id']; $min = $an['minimos'][$iid] ?? null; ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($it['descripcion']) ?><?= $it['unidad'] ? ' <span class="text-muted">(' . htmlspecialchars($it['unidad']) . ')</span>' : '' ?></td>
          <td class="text-end font-mono"><?= rtrim(rtrim(number_format((float)$it['cantidad'], 2), '0'), '.') ?></td>
          <?php foreach ($cot['ofertas'] as $o): $p = $o['precios'][$iid] ?? null; $esMin = $min && in_array((int)$o['id'], $min['ofertas'], true); ?>
          <td class="text-end font-mono<?= $esMin ? ' cot-min' : '' ?>">
            <?php if ($p === null): ?><span class="text-muted" title="No lo cotizó">—</span>
            <?php else: ?><?= lps($p) ?><div class="small text-muted"><?= lps($p * (float)$it['cantidad']) ?></div><?php endif; ?>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" class="text-end fw-bold">Total ofertado</td>
          <?php foreach ($cot['ofertas'] as $o): ?><td class="text-end font-mono fw-bold"><?= lps($an['ofertas'][(int)$o['id']]['total']) ?></td><?php endforeach; ?></tr>
        <tr><td colspan="3" class="text-end text-muted">Artículos cotizados</td>
          <?php foreach ($cot['ofertas'] as $o): $c = $an['ofertas'][(int)$o['id']]['cubre']; ?><td class="text-end <?= $c < count($cot['items']) ? 'text-warning' : 'text-muted' ?>"><?= $c ?> de <?= count($cot['items']) ?></td><?php endforeach; ?></tr>
        <tr><td colspan="3" class="text-end text-muted">Condiciones / entrega</td>
          <?php foreach ($cot['ofertas'] as $o): ?><td class="text-end small text-muted"><?= htmlspecialchars(trim(($o['condiciones'] ?: '—') . ' · ' . ($o['tiempo_entrega'] ?: '—'))) ?></td><?php endforeach; ?></tr>
      </tfoot>
    </table>
    <?php else: ?>
    <table class="table-proscala w-100">
      <thead><tr><th>Proveedor</th><th>No. Cotización</th><th>Fecha</th><th>Total Ofertado</th><th>Condiciones</th><th>T. Entrega</th><th>Seleccionado</th></tr></thead>
      <tbody>
        <?php foreach($cot['ofertas'] as $cp): ?>
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
    <div class="p-2 small text-muted"><i class="fas fa-circle-info"></i> Esta cotización se registró con montos globales. Edítela para detallar el precio de cada artículo y obtener la recomendación por artículo.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($an['unico']): $u = $an['unico']; $pa = $an['por_articulo']; ?>
<div class="card mb-3">
  <div class="card-header"><i class="fas fa-lightbulb"></i> Recomendación del sistema</div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-md-<?= $pa ? '5' : '12' ?>">
        <div class="cot-reco">
          <div class="cot-reco-titulo">Un solo proveedor</div>
          <div class="cot-reco-prov"><?= htmlspecialchars($nombreOf($u['oferta_id'])) ?></div>
          <div class="cot-reco-monto"><?= lps($u['total']) ?></div>
          <div class="small text-muted"><?= $u['completo'] ? 'La oferta más baja entre los que cotizaron todos los artículos.'
              : "Ningún proveedor cotizó todo: es el que más cubre (le faltan {$u['faltan']} artículo(s))." ?></div>
          <?php if ($aprobada): ?><a href="<?= $urlOc((int)$ofertasPorId[$u['oferta_id']]['proveedor_id']) ?>" class="btn btn-sm btn-outline-primary mt-2"><i class="fas fa-file-circle-check"></i> Crear OC a este proveedor</a><?php endif; ?>
        </div>
      </div>
      <?php if ($pa): ?>
      <div class="col-md-7">
        <div class="cot-reco">
          <div class="cot-reco-titulo">Compra por artículo (mejor precio de cada uno)</div>
          <div class="cot-reco-monto"><?= lps($pa['total']) ?>
            <?php if ($an['ahorro'] > 0): ?><span class="cot-ahorro">ahorra <?= lps($an['ahorro']) ?></span><?php endif; ?></div>
          <table class="table table-sm mb-1 mt-2" style="font-size:.82rem">
            <?php foreach ($pa['grupos'] as $oid => $g): ?>
            <tr>
              <td><strong><?= htmlspecialchars($nombreOf($oid)) ?></strong>
                <div class="text-muted small"><?= htmlspecialchars(implode(', ', array_map(fn($iid) => array_column($cot['items'], 'descripcion', 'id')[$iid] ?? '', $g['items']))) ?></div></td>
              <td class="text-end text-nowrap"><?= count($g['items']) ?> art.</td>
              <td class="text-end font-mono text-nowrap"><?= lps($g['total']) ?></td>
              <?php if ($aprobada): ?><td class="text-end"><a href="<?= $urlOc((int)$ofertasPorId[$oid]['proveedor_id'], true) ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Orden de compra con estos artículos"><i class="fas fa-file-circle-check"></i> OC</a></td><?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </table>
          <?php if ($pa['sin_oferta']): ?><div class="small text-warning"><i class="fas fa-triangle-exclamation"></i> <?= count($pa['sin_oferta']) ?> artículo(s) sin ninguna oferta.</div><?php endif; ?>
          <div class="small text-muted"><?= count($pa['grupos']) > 1 ? 'Requiere ' . count($pa['grupos']) . ' órdenes de compra, una por proveedor.' : 'Coincide con un solo proveedor.' ?></div>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <?php if ($co['proveedor_seleccionado_id'] && (int)$ofertasPorId[$u['oferta_id']]['proveedor_id'] !== (int)$co['proveedor_seleccionado_id']): ?>
    <div class="alert alert-warning py-2 mt-3 mb-0 small"><i class="fas fa-circle-info"></i> El proveedor seleccionado no es el recomendado; la justificación de la selección debe explicar por qué.</div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php $adjModulo = 'compras_cotizaciones.php'; $adjId = $co['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php else: ?>
<div class="card">
  <div class="card-header"><i class="fas <?= $co ? 'fa-edit' : 'fa-plus' ?>"></i> <?= $co ? 'Editar cotización ' . htmlspecialchars($co['numero']) : 'Nueva Cotización' ?></div>
  <div class="card-body">
    <form id="form-co" action="compras_cotizaciones.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="guardar">
      <input type="hidden" name="id" value="<?= $co['id']??0 ?>">
      <input type="hidden" name="items_data" id="items-json">
      <input type="hidden" name="proveedores_data" id="provs-json">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Solicitud de Compra *</label>
          <select name="solicitud_id" class="form-select" required id="sel-sc">
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
        <div class="col-12"><label class="form-label">Justificación de Selección</label><textarea name="justificacion" class="form-control" rows="2" placeholder="Indique por qué se eligió el proveedor..."><?= htmlspecialchars($co['justificacion'] ?? '') ?></textarea></div>
      </div>

      <div class="form-section-title">Cuadro Comparativo (mínimo 3 proveedores recomendado)</div>
      <p class="text-muted small mb-2">Una fila por artículo y una columna por proveedor. Escriba el <strong>precio unitario</strong> que cotizó cada proveedor; deje la celda vacía si no lo cotizó.
        El menor precio de cada artículo se marca en verde. Si un proveedor solo dio un monto global, déjele los precios vacíos y escriba el monto en su total.</p>
      <div class="table-responsive cot-matriz-wrap">
        <table class="table-proscala cot-matriz" id="matriz"></table>
      </div>
      <div class="d-flex gap-2 mt-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-success" onclick="Matriz.agregarItem()"><i class="fas fa-plus"></i> Agregar artículo</button>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="Matriz.agregarOferta()"><i class="fas fa-truck"></i> Agregar proveedor</button>
      </div>
      <div id="matriz-reco" class="cot-reco-vivo mt-3"></div>
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
$extraJs = strtr(<<<'JS'

initDataTable('#tbl-co');

// ── Cuadro comparativo del formulario: artículos (filas) × proveedores (columnas) ──
// El estado vive en Matriz.d; los cambios de estructura vuelven a dibujar la tabla y lo que se
// escribe solo recalcula totales, mínimos y la recomendación (sin perder el foco).
// En window: la navegación sin recarga ejecuta este script dentro de un bloque y los onclick lo usan.
const Matriz = window.Matriz = {
  d: __MATRIZ__,
  el: document.getElementById('matriz'),
  esc: s => Notificaciones.esc(s),
  num: v => { const n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? null : n; },
  // Opciones de proveedor: las de "Proveedor Seleccionado" (incluye los agregados desde la lista)
  opciones(sel) {
    return [...document.querySelectorAll('#sel-prov-rec option')].filter(o => o.value)
      .map(o => `<option value="${o.value}"${String(sel) === o.value ? ' selected' : ''}>${this.esc(o.text)}</option>`).join('');
  },
  ofertaVacia() { return { proveedor_id: '', num_cotizacion: '', fecha: '', condiciones: '', tiempo_entrega: '', notas: '', total_global: '', precios: this.d.items.map(() => '') }; },
  iniciar() {
    if (!this.el) return;
    while (this.d.ofertas.length < 3) this.d.ofertas.push(this.ofertaVacia());
    this.d.ofertas.forEach(o => { while (o.precios.length < this.d.items.length) o.precios.push(''); });
    if (!this.d.items.length) this.d.items.push({ sd_id: null, descripcion: '', unidad: '', cantidad: 1 });
    $(this.el).on('input change', '[data-campo]', e => this.cambio(e.target));
    this.dibujar();
  },
  dibujar() {
    const esc = this.esc, d = this.d;
    let h = '<thead><tr><th style="width:2em">#</th><th style="min-width:220px">Artículo</th><th style="width:80px">Unidad</th><th style="width:80px">Cant.</th>';
    d.ofertas.forEach((o, j) => {
      h += `<th class="cot-prov">
        <div class="d-flex gap-1 align-items-start"><select class="form-select form-select-sm" data-campo="proveedor_id" data-j="${j}" data-alta="proveedor"><option value="">Proveedor ${j + 1}…</option>${this.opciones(o.proveedor_id)}</select>
          <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" title="Quitar proveedor" onclick="Matriz.quitarOferta(${j})"><i class="fas fa-times"></i></button></div>
        <input class="form-control form-control-sm mt-1" placeholder="No. cotización" data-campo="num_cotizacion" data-j="${j}" value="${esc(o.num_cotizacion)}">
        <input class="form-control form-control-sm mt-1 date-input" placeholder="Fecha" data-campo="fecha" data-j="${j}" value="${esc(o.fecha)}">
        <input class="form-control form-control-sm mt-1" placeholder="Condiciones de pago" data-campo="condiciones" data-j="${j}" value="${esc(o.condiciones)}">
        <input class="form-control form-control-sm mt-1" placeholder="Tiempo de entrega" data-campo="tiempo_entrega" data-j="${j}" value="${esc(o.tiempo_entrega)}">
        <input class="form-control form-control-sm mt-1" placeholder="Notas" data-campo="notas" data-j="${j}" value="${esc(o.notas)}"></th>`;
    });
    h += '<th style="width:2em"></th></tr></thead><tbody>';
    d.items.forEach((it, i) => {
      h += `<tr><td class="text-muted">${i + 1}</td>
        <td><input class="form-control form-control-sm" data-campo="descripcion" data-i="${i}" value="${esc(it.descripcion)}" placeholder="Descripción del artículo"></td>
        <td><input class="form-control form-control-sm" data-campo="unidad" data-i="${i}" value="${esc(it.unidad)}" placeholder="und"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" data-campo="cantidad" data-i="${i}" value="${esc(it.cantidad)}"></td>`;
      d.ofertas.forEach((o, j) => {
        h += `<td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end cot-precio" data-campo="precio" data-i="${i}" data-j="${j}" value="${esc(o.precios[i])}" placeholder="—" title="Precio unitario">
          <div class="cot-linea small text-muted text-end" data-linea="${i}-${j}"></div></td>`;
      });
      h += `<td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" title="Quitar artículo" onclick="Matriz.quitarItem(${i})"><i class="fas fa-trash"></i></button></td></tr>`;
    });
    h += '</tbody><tfoot><tr><td colspan="4" class="text-end fw-bold">Total ofertado</td>';
    d.ofertas.forEach((o, j) => {
      h += `<td><div class="fw-bold font-mono text-end" data-total="${j}"></div>
        <input type="number" step="0.01" min="0" class="form-control form-control-sm text-end mt-1" data-campo="total_global" data-j="${j}" value="${esc(o.total_global)}" placeholder="Monto global" title="Solo si no detalló precios por artículo"></td>`;
    });
    h += '<td></td></tr><tr><td colspan="4" class="text-end text-muted small">Artículos cotizados</td>';
    d.ofertas.forEach((o, j) => { h += `<td class="text-end small" data-cubre="${j}"></td>`; });
    h += '<td></td></tr></tfoot>';
    this.el.innerHTML = h;
    initFechas(this.el);
    this.calcular();
  },
  cambio(t) {
    const i = +t.dataset.i, j = +t.dataset.j, c = t.dataset.campo;
    if (c === 'precio') this.d.ofertas[j].precios[i] = t.value;
    else if (t.dataset.i !== undefined) this.d.items[i][c] = t.value;
    else this.d.ofertas[j][c] = t.value;
    if (c === 'proveedor_id' && t.value) {
      const otra = this.d.ofertas.findIndex((o, k) => k !== j && String(o.proveedor_id) === t.value);
      if (otra >= 0) { Toast.show('Ese proveedor ya está en otra columna.', 'warning'); }
    }
    this.calcular();
  },
  agregarItem() { this.d.items.push({ sd_id: null, descripcion: '', unidad: '', cantidad: 1 }); this.d.ofertas.forEach(o => o.precios.push('')); this.dibujar(); },
  quitarItem(i) {
    if (this.d.items.length === 1) { Toast.show('La cotización necesita al menos un artículo.', 'warning'); return; }
    this.d.items.splice(i, 1); this.d.ofertas.forEach(o => o.precios.splice(i, 1)); this.dibujar();
  },
  agregarOferta() { this.d.ofertas.push(this.ofertaVacia()); this.dibujar(); },
  quitarOferta(j) { this.d.ofertas.splice(j, 1); if (!this.d.ofertas.length) this.d.ofertas.push(this.ofertaVacia()); this.dibujar(); },
  // Nueva cotización: al elegir la solicitud se cargan sus artículos
  async cargarSolicitud(scId) {
    if (!scId) return;
    const hayDatos = this.d.items.some(it => it.descripcion) && this.d.ofertas.some(o => o.precios.some(p => p !== ''));
    if (hayDatos && !await confirmar('Se reemplazarán los artículos del cuadro por los de la solicitud elegida y se borrarán los precios escritos.', { titulo: '¿Cargar los artículos de la solicitud?', aceptar: 'Cargar' })) return;
    try {
      const r = await (await fetch('compras_cotizaciones.php?_action=items_sc&sc_id=' + encodeURIComponent(scId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })).json();
      if (!r.items?.length) return;
      this.d.items = r.items;
      this.d.ofertas.forEach(o => { o.precios = r.items.map(() => ''); });
      this.dibujar();
    } catch (_) { Toast.show('No se pudieron cargar los artículos de la solicitud.', 'error'); }
  },
  // Totales, menor precio por artículo y recomendación (misma regla que config/cotizaciones.php)
  calcular() {
    const d = this.d, n = d.items.length, lps2 = v => lps(v);
    const tot = d.ofertas.map(() => 0), cubre = d.ofertas.map(() => 0);
    let porArticulo = 0, sinOferta = 0, conPrecios = false;
    d.items.forEach((it, i) => {
      const q = this.num(it.cantidad) || 0;
      let min = null;
      d.ofertas.forEach((o, j) => {
        const p = this.num(o.precios[i]);
        const linea = this.el.querySelector(`[data-linea="${i}-${j}"]`);
        if (linea) linea.textContent = p === null ? '' : lps2(p * q);
        if (p === null || !o.proveedor_id) return;
        conPrecios = true; tot[j] += p * q; cubre[j]++;
        if (min === null || p < min) min = p;
      });
      d.ofertas.forEach((o, j) => {
        const p = this.num(o.precios[i]);
        const inp = this.el.querySelector(`[data-campo="precio"][data-i="${i}"][data-j="${j}"]`);
        const esMin = min !== null && p !== null && o.proveedor_id && Math.abs(p - min) < 0.0001;
        inp?.classList.toggle('cot-min', !!esMin);
      });
      if (min === null) sinOferta++; else porArticulo += min * q;
    });
    d.ofertas.forEach((o, j) => {
      const tieneDetalle = o.precios.some(p => this.num(p) !== null);
      const total = tieneDetalle ? tot[j] : (this.num(o.total_global) || 0);
      tot[j] = total;
      const t = this.el.querySelector(`[data-total="${j}"]`);
      if (t) t.textContent = lps2(total);
      const g = this.el.querySelector(`[data-campo="total_global"][data-j="${j}"]`);
      if (g) g.hidden = tieneDetalle;
      const c = this.el.querySelector(`[data-cubre="${j}"]`);
      if (c) { c.textContent = tieneDetalle ? `${cubre[j]} de ${n}` : 'monto global'; c.className = 'text-end small ' + (tieneDetalle && cubre[j] < n ? 'text-warning' : 'text-muted'); }
    });
    // Recomendación en vivo
    const nombre = j => this.el.querySelector(`[data-campo="proveedor_id"][data-j="${j}"]`)?.selectedOptions[0]?.text || `Proveedor ${j + 1}`;
    const completos = d.ofertas.map((o, j) => j).filter(j => d.ofertas[j].proveedor_id && (conPrecios ? cubre[j] === n : tot[j] > 0));
    const caja = document.getElementById('matriz-reco');
    if (!caja) return;
    if (!completos.length && !conPrecios) { caja.innerHTML = ''; return; }
    let html = '<i class="fas fa-lightbulb"></i> ';
    if (completos.length) {
      const mejor = completos.reduce((a, b) => tot[b] < tot[a] ? b : a);
      html += `Proveedor único recomendado: <strong>${this.esc(nombre(mejor))}</strong> (${lps2(tot[mejor])})`;
      if (conPrecios && !sinOferta && porArticulo < tot[mejor] - 0.005) html += ` · Comprando cada artículo al mejor precio: <strong>${lps2(porArticulo)}</strong> (ahorra ${lps2(tot[mejor] - porArticulo)})`;
    } else {
      html += `Ningún proveedor cotizó todos los artículos. Comprando cada artículo al mejor precio: <strong>${lps2(porArticulo)}</strong>`;
    }
    if (sinOferta) html += ` · <span class="text-warning">${sinOferta} artículo(s) sin oferta</span>`;
    caja.innerHTML = html;
  },
  datos() {
    return {
      items: this.d.items.map(it => ({ sd_id: it.sd_id || null, descripcion: it.descripcion, unidad: it.unidad, cantidad: it.cantidad })),
      ofertas: this.d.ofertas.filter(o => o.proveedor_id),
    };
  },
};
Matriz.iniciar();
$('#sel-sc').on('change', function () { Matriz.cargarSolicitud(this.value); });

document.getElementById('form-co')?.addEventListener('submit',function(e){
  e.preventDefault();
  const {items, ofertas} = Matriz.datos();
  if (!items.some(it => String(it.descripcion).trim())) { Toast.show('Agregue al menos un artículo.','warning'); return; }
  if (!ofertas.length) { Toast.show('Elija al menos un proveedor en el cuadro comparativo.','warning'); return; }
  document.getElementById('items-json').value=JSON.stringify(items);
  document.getElementById('provs-json').value=JSON.stringify(ofertas);
  post('compras_cotizaciones.php',new FormData(this)).then(r=>{
    if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.ir('compras_cotizaciones.php?action=ver&id='+r.id), 1200);}
    else Toast.show(r.message,'error');
  }).catch(e=>Toast.show(e.message||'No se pudo guardar.','error',8000));
});

JS, [
    '__MATRIZ__' => json_encode($matriz, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP),
]);
include __DIR__ . '/includes/footer.php';
?>

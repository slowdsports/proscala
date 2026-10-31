<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Gastos de Viaje';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'guardar') {
        $gvId  = (int)($_POST['id'] ?? 0);
        $items = json_decode($_POST['items'] ?? '[]', true);
        $total = round(array_sum(array_column($items, 'monto')), 2);
        $alimentacion = round((float)($_POST['monto_alimentacion'] ?? 0), 2);
        // Firmante #1: quien llena la solicitud (su empleado vinculado; si no tiene, el beneficiario).
        // Se fija al crearla; al editarla se conserva.
        $firmante1Id = (int)($_SESSION['empleado_id'] ?? 0) ?: (int)$_POST['empleado_id'];
        $tipo = ($_POST['tipo'] ?? 'viaje') === 'compra' ? 'compra' : 'viaje';
        $d = [
            'tipo'               => $tipo,
            'empleado_id'        => (int)$_POST['empleado_id'],
            'firmante1_id'       => $firmante1Id,
            'proyecto_id'        => $_POST['proyecto_id'] ?: null,
            'destino'            => trim($_POST['destino']),
            'proposito'          => trim($_POST['proposito'] ?? ''),
            'fecha_salida'       => $_POST['fecha_salida'],
            'fecha_regreso'      => $_POST['fecha_regreso'],
            'notas'              => trim($_POST['notas'] ?? ''),
            'monto_alimentacion' => $tipo === 'compra' ? 0 : $alimentacion,
        ];
        $pdo->beginTransaction();
        try {
            if ($gvId) {
                $pdo->prepare("UPDATE gastos_viaje SET tipo=?,empleado_id=?,proyecto_id=?,destino=?,proposito=?,
                    fecha_salida=?,fecha_regreso=?,viaticos_anticipados=?,monto_alimentacion=?,notas=? WHERE id=?")->execute([
                    $d['tipo'],$d['empleado_id'],$d['proyecto_id'],$d['destino'],$d['proposito'],
                    $d['fecha_salida'],$d['fecha_regreso'],$total,$d['monto_alimentacion'],$d['notas'],$gvId
                ]);
                $pdo->prepare("DELETE FROM gastos_viaje_detalle WHERE viaje_id=?")->execute([$gvId]);
            } else {
                $procesoId = generarProceso($pdo);
                $num = numeroProceso($pdo,'gastos_viaje',getConfig($pdo,'prefijo_gv','GV'));
                $pdo->prepare("INSERT INTO gastos_viaje (numero,proceso_id,tipo,empleado_id,firmante1_id,proyecto_id,destino,proposito,
                    fecha_salida,fecha_regreso,viaticos_anticipados,monto_alimentacion,total_gastos,saldo,notas,estado)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,0,?,'pendiente')")
                    ->execute([$num,$procesoId,$d['tipo'],$d['empleado_id'],$d['firmante1_id'],$d['proyecto_id'],$d['destino'],$d['proposito'],
                    $d['fecha_salida'],$d['fecha_regreso'],$total,$d['monto_alimentacion'],$d['notas']]);
                $gvId = $pdo->lastInsertId();
            }
            foreach ($items as $item) {
                $pdo->prepare("INSERT INTO gastos_viaje_detalle (viaje_id,fecha,descripcion,cuenta_id,monto) VALUES (?,?,?,?,?)")
                    ->execute([$gvId, $item['fecha'] ?? $d['fecha_salida'], $item['descripcion'],
                               $item['cuenta_id'] ?: null, (float)$item['monto']]);
            }
            $pdo->commit();
            $esNuevo = !(int)($_POST['id'] ?? 0);
            registrarAuditoria($pdo, $esNuevo ? 'INSERT' : 'UPDATE', 'gastos_viaje', $gvId,
                ($esNuevo ? 'GV creado' : 'GV actualizado') . ': ' . lps($total));
            jsonOk(['id'=>$gvId],'Anticipo guardado.');
        } catch(\Exception $e){ $pdo->rollBack(); jsonErr($e->getMessage()); }
    }

    if ($_act === 'cambiar_estado') {
        $gvId   = (int)$_POST['id'];
        $estado = $_POST['estado'];
        $pdo->prepare("UPDATE gastos_viaje SET estado=?,aprobador_id=? WHERE id=?")
            ->execute([$estado,$_SESSION['empleado_id'] ?? null,$gvId]);
        registrarAuditoria($pdo, 'UPDATE', 'gastos_viaje', $gvId, "Estado GV → $estado");
        jsonOk([],'Estado actualizado.');
    }
    jsonErr('Acción desconocida.');
}

$gv = null; $detalle = []; $liquidaciones = [];
if (in_array($action,['ver','editar']) && $id) {
    $s = $pdo->prepare("SELECT gv.*,CONCAT(e.nombre,' ',e.apellidos) as emp_nombre FROM gastos_viaje gv JOIN empleados e ON gv.empleado_id=e.id WHERE gv.id=?");
    $s->execute([$id]); $gv = $s->fetch();
    $s2 = $pdo->prepare("SELECT d.*,cc.nombre as cuenta_nombre FROM gastos_viaje_detalle d LEFT JOIN cuentas_contables cc ON d.cuenta_id=cc.id WHERE d.viaje_id=?");
    $s2->execute([$id]); $detalle = $s2->fetchAll();
    if ($action === 'ver') {
        $liq = $pdo->prepare("SELECT sg.*, CONCAT(e.nombre,' ',e.apellidos) as emp_nombre FROM solicitud_gastos sg JOIN empleados e ON sg.empleado_id=e.id WHERE sg.viaje_id=? ORDER BY sg.id DESC");
        $liq->execute([$id]); $liquidaciones = $liq->fetchAll();
    }
}

$empleados = $pdo->query("SELECT id,CONCAT(nombre,' ',apellidos) as nombre FROM empleados WHERE activo=1 ORDER BY apellidos")->fetchAll();
$proyectos = $pdo->query("SELECT id,nombre FROM proyectos WHERE estado='activo' ORDER BY nombre")->fetchAll();
$cuentas   = $pdo->query("SELECT id,codigo,nombre FROM cuentas_contables WHERE activa=1 AND tipo='gasto' ORDER BY codigo")->fetchAll();
$listaViajes = [];
if ($action === 'list') {
    [$fp, $fpParams] = filtroPropios('gastos_viajes.php', 'gv');   // roles que solo ven sus solicitudes
    $s = $pdo->prepare("SELECT gv.*,CONCAT(e.nombre,' ',e.apellidos) as emp_nombre FROM gastos_viaje gv JOIN empleados e ON gv.empleado_id=e.id WHERE $fp AND " . filtroHistorico('gv') . " ORDER BY gv.id DESC");
    $s->execute($fpParams); $listaViajes = $s->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-hand-holding-dollar"></i> Anticipos (Viáticos y Compras)</h1>
  <?php if ($action === 'list'): ?>
  <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nuevo Anticipo</a>
  <?php else: ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card">
  <div class="card-body p-0">
    <table id="tbl-gv" class="table-proscala w-100">
      <thead><tr><th>Proceso</th><th>Número</th><th>Tipo</th><th>Empleado</th><th>Destino / Concepto</th><th>Salida</th><th>Regreso</th><th>Monto Anticipo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($listaViajes as $r): ?>
        <tr>
          <td class="font-mono text-primary fw-bold"><?= $r['proceso_id'] ? formatProceso($r['proceso_id']) : '—' ?></td>
          <td class="font-mono"><?= $r['numero'] ?><?= badgeHistorico($r) ?></td>
          <td><?= tipoAnticipoBadge($r['tipo']) ?></td>
          <td><?= htmlspecialchars($r['emp_nombre']) ?></td>
          <td><?= htmlspecialchars($r['destino']) ?></td>
          <td><?= fmtFecha($r['fecha_salida']) ?></td>
          <td><?= fmtFecha($r['fecha_regreso']) ?></td>
          <td class="font-mono"><?= lps($r['viaticos_anticipados']) ?></td>
          <td><?= estadoBadge($r['estado']) ?></td>
          <td>
            <a href="?action=ver&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a>
            <?= botonEditar($pdo, 'gastos_viajes.php', (int)$r['id'], '?action=editar&id=' . $r['id'], true) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$listaViajes): ?>
        <tr><td colspan="10" class="text-center text-muted py-4">No hay anticipos registrados</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $gv): ?>
<?php $flujoModulo = 'gastos_viajes.php'; $flujoId = $gv['id']; include __DIR__ . '/includes/flujo_aprobacion.php'; ?>
<?php $cadModulo = 'gastos_viajes.php'; $cadId = $gv['id']; include __DIR__ . '/includes/cadena_proceso.php'; ?>
<div id="print-area">
<div class="card mb-3">
  <div class="card-header">
    <i class="fas <?= tipoAnticipoIcon($gv['tipo']) ?>"></i> <?= $gv['numero'] ?> — <?= estadoBadge($gv['estado']) ?>
    <?= tipoAnticipoBadge($gv['tipo']) ?>
    <?php if ($gv['proceso_id']): ?>
    <span class="badge bg-primary ms-2 font-mono" title="Número de proceso compartido">Proceso <?= formatProceso($gv['proceso_id']) ?></span>
    <?php endif; ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?php if (!soloRegistrosPropios() && $gv['estado']==='aprobada'): ?>
      <button class="btn btn-sm btn-info text-white" onclick="cambiarEstado('gastos_viajes.php',<?=$gv['id']?>,'liquidada','gastos_viaje',()=>Nav.recargar())"><i class="fas fa-check-double"></i> Liquidar</button>
      <?php endif; ?>
      <?= botonEditar($pdo, 'gastos_viajes.php', (int)$gv['id'], '?action=editar&id=' . $gv['id']) ?>
      <a href="<?= BASE_URL ?>print.php?tipo=gv&id=<?= $gv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
      <?php if ($gv['tipo']==='viaje' && $gv['estado']==='liquidada' && ($gv['monto_alimentacion'] ?? 0) > 0): ?>
      <a href="<?= BASE_URL ?>print.php?tipo=gva&id=<?= $gv['id'] ?>" target="_blank" class="btn btn-sm btn-outline-warning"><i class="fas fa-utensils"></i> Voucher Alimentación</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-2" style="font-size:.85rem;">
      <div class="col-md-3"><strong>Empleado:</strong> <?= htmlspecialchars($gv['emp_nombre']) ?></div>
      <div class="col-md-3"><strong><?= $gv['tipo']==='compra' ? 'Concepto:' : 'Destino:' ?></strong> <?= htmlspecialchars($gv['destino']) ?></div>
      <div class="col-md-3"><strong><?= $gv['tipo']==='compra' ? 'Fecha del Anticipo:' : 'Salida:' ?></strong> <?= fmtFecha($gv['fecha_salida']) ?></div>
      <div class="col-md-3"><strong><?= $gv['tipo']==='compra' ? 'Fecha Límite de Liquidación:' : 'Regreso:' ?></strong> <?= fmtFecha($gv['fecha_regreso']) ?></div>
      <?php if ($gv['tipo']==='viaje' && $gv['monto_alimentacion'] > 0): ?>
      <div class="col-md-3"><strong>Alimentación:</strong> <span class="font-mono"><?= lps($gv['monto_alimentacion']) ?></span></div>
      <?php endif; ?>
      <?php if ($gv['proposito']): ?>
      <div class="col-12"><strong><?= $gv['tipo']==='compra' ? 'Justificación:' : 'Propósito:' ?></strong> <?= htmlspecialchars($gv['proposito']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><i class="fas fa-list"></i> Desglose del Anticipo Solicitado</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>#</th><th>Descripción del Gasto</th><th>Cuenta Contable</th><th>Monto</th></tr></thead>
      <tbody>
        <?php $i=1; foreach ($detalle as $d): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td><?= htmlspecialchars($d['cuenta_nombre'] ?? '—') ?></td>
          <td class="font-mono"><?= lps($d['monto']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$detalle): ?>
        <tr><td colspan="4" class="text-center text-muted py-3">Sin líneas de detalle.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="3" class="text-end fw-bold">Total Anticipo Solicitado:</td><td class="font-mono fw-bold text-success"><?= lps($gv['viaticos_anticipados']) ?></td></tr>
      </tfoot>
    </table>
  </div>
</div>
</div>

<?php if ($liquidaciones): ?>
<div class="card mt-3">
  <div class="card-header">
    <i class="fas fa-file-invoice-dollar"></i> Liquidación de gastos del anticipo
    <a href="<?= BASE_URL ?>gastos_solicitud.php?action=nuevo&viaje_id=<?= $gv['id'] ?>" class="ms-auto btn btn-sm btn-outline-success py-0"><i class="fas fa-plus"></i> Nueva Liquidación</a>
  </div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>Número</th><th>Fecha</th><th>Descripción</th><th>Total</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($liquidaciones as $liq): ?>
        <tr>
          <td class="font-mono"><?= $liq['numero'] ?></td>
          <td><?= fmtFecha($liq['fecha']) ?></td>
          <td><?= htmlspecialchars(substr($liq['descripcion'],0,60)) ?></td>
          <td class="font-mono"><?= lps($liq['monto_total']) ?></td>
          <td><?= estadoBadge($liq['estado']) ?></td>
          <td><a href="<?= BASE_URL ?>gastos_solicitud.php?action=ver&id=<?= $liq['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card mt-3">
  <div class="card-body d-flex align-items-center justify-content-between" style="font-size:.85rem;">
    <span class="text-muted"><i class="fas fa-info-circle me-1"></i> Sin liquidaciones registradas para este viaje.</span>
    <a href="<?= BASE_URL ?>gastos_solicitud.php?action=nuevo&viaje_id=<?= $gv['id'] ?>" class="btn btn-sm btn-outline-success"><i class="fas fa-plus"></i> Registrar Liquidación</a>
  </div>
</div>
<?php endif; ?>

<?php $adjModulo = 'gastos_viajes.php'; $adjId = $gv['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php else: ?>
<div class="card">
  <div class="card-header"><i class="fas fa-plus"></i> <?= $gv ? 'Editar: '.$gv['numero'] : 'Nuevo Anticipo' ?></div>
  <div class="card-body">
    <form id="form-gv" action="gastos_viajes.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="guardar">
      <input type="hidden" name="id" value="<?= $gv['id'] ?? 0 ?>">
      <input type="hidden" name="items" id="items-json">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Tipo de Anticipo *</label>
          <select name="tipo" id="sel-tipo" class="form-select" required onchange="toggleTipoAnticipo()">
            <option value="viaje" <?= ($gv['tipo']??'viaje')==='viaje'?'selected':'' ?>>Viaje (Viáticos)</option>
            <option value="compra" <?= ($gv['tipo']??'viaje')==='compra'?'selected':'' ?>>Compra de Insumos/Activos</option>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label">Empleado *</label>
          <select name="empleado_id" id="sel-empleado" class="form-select" required>
            <option value="">-- Seleccione --</option>
            <?php foreach ($empleados as $e): ?>
            <option value="<?= $e['id'] ?>" <?= ($gv['empleado_id']??$_SESSION['empleado_id']??0)==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Proyecto</label>
          <select name="proyecto_id" class="form-select">
            <option value="">-- General --</option>
            <?php foreach ($proyectos as $p): ?>
            <option value="<?= $p['id'] ?>" <?= ($gv['proyecto_id']??0)==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label" id="lbl-destino">Destino *</label>
          <input type="text" name="destino" id="inp-destino" class="form-control" required value="<?= htmlspecialchars($gv['destino']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Firmante (Firma 1 del documento)</label>
          <input type="text" class="form-control" readonly value="<?= htmlspecialchars(firmante1Nombre($pdo, $gv ? (int)$gv['firmante1_id'] : 0)) ?>">
          <div class="form-text">Es quien llena la solicitud.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label" id="lbl-fecha-salida">Fecha de Salida *</label>
          <input type="text" name="fecha_salida" class="form-control date-input" required value="<?= $gv['fecha_salida']??date('Y-m-d') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label" id="lbl-fecha-regreso">Fecha de Regreso *</label>
          <input type="text" name="fecha_regreso" class="form-control date-input" required value="<?= $gv['fecha_regreso']??date('Y-m-d') ?>">
        </div>
        <div class="col-md-4" id="grp-alimentacion">
          <label class="form-label">Viáticos de Alimentación (L.)</label>
          <input type="number" name="monto_alimentacion" class="form-control text-end" step="0.01" min="0" value="<?= $gv['monto_alimentacion'] ?? 0 ?>">
          <div class="form-text">Monto para voucher de alimentación. Si es 0 no se genera voucher.</div>
        </div>
        <div class="col-md-12">
          <label class="form-label" id="lbl-proposito">Propósito del Viaje</label>
          <textarea name="proposito" class="form-control" rows="2"><?= htmlspecialchars($gv['proposito']??'') ?></textarea>
        </div>
      </div>

      <div class="form-section-title mt-3">Desglose del Anticipo Solicitado</div>
      <div class="table-responsive">
        <table class="table-proscala w-100">
          <thead><tr><th>#</th><th>Descripción del Gasto</th><th>Cuenta Contable</th><th style="width:10rem">Monto (L.)</th><th></th></tr></thead>
          <tbody id="tbody-gv-items">
            <?php
              $initItems = $detalle ?: [['fecha' => date('Y-m-d'), 'descripcion' => '', 'cuenta_id' => '', 'monto' => 0]];
              foreach ($initItems as $idx => $it):
            ?>
            <tr class="detail-row">
              <td class="row-num"><?= $idx + 1 ?></td>
              <td><input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($it['descripcion'] ?? '') ?>" data-field="descripcion" required></td>
              <td>
                <select class="form-select form-select-sm" data-field="cuenta_id">
                  <option value="">-- Cuenta --</option>
                  <?php foreach ($cuentas as $cc): ?>
                  <option value="<?= $cc['id'] ?>" <?= ($it['cuenta_id'] ?? 0) == $cc['id'] ? 'selected' : '' ?>><?= $cc['codigo'] ?> - <?= htmlspecialchars($cc['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" class="form-control form-control-sm gv-monto text-end" step="0.01" min="0" value="<?= $it['monto'] ?? 0 ?>" data-field="monto" oninput="calcGvTotal()"></td>
              <td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeDetailRow(this);calcGvTotal()"><i class="fas fa-trash"></i></button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr><td colspan="3" class="text-end fw-bold">Total Anticipo Solicitado:</td><td class="font-mono fw-bold text-success" id="gv-total">L. 0.00</td><td></td></tr>
          </tfoot>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-success mt-2" onclick="addGvRow()"><i class="fas fa-plus"></i> Agregar Línea</button>

      <?php $adjModulo = 'gastos_viajes.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar</button>
        <a href="?" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$cuentasJson = json_encode(array_map(fn($c) => ['id' => $c['id'], 'codigo' => $c['codigo'], 'nombre' => $c['nombre']], $cuentas));
$extraJs = strtr(<<<'JS'

initDataTable('#tbl-gv');
calcGvTotal();
toggleTipoAnticipo();

function toggleTipoAnticipo() {
  const sel = document.getElementById('sel-tipo');
  if (!sel) return;
  const esCompra = sel.value === 'compra';
  const lblDestino = document.getElementById('lbl-destino');
  const lblSalida  = document.getElementById('lbl-fecha-salida');
  const lblRegreso = document.getElementById('lbl-fecha-regreso');
  const lblProposito = document.getElementById('lbl-proposito');
  const grpAlimentacion = document.getElementById('grp-alimentacion');
  if (lblDestino) lblDestino.textContent = esCompra ? 'Concepto / Insumo a Comprar *' : 'Destino *';
  if (lblSalida) lblSalida.textContent = esCompra ? 'Fecha del Anticipo *' : 'Fecha de Salida *';
  if (lblRegreso) lblRegreso.textContent = esCompra ? 'Fecha Límite de Liquidación *' : 'Fecha de Regreso *';
  if (lblProposito) lblProposito.textContent = esCompra ? 'Justificación de la Compra' : 'Propósito del Viaje';
  if (grpAlimentacion) grpAlimentacion.style.display = esCompra ? 'none' : '';
}

function calcGvTotal() {
  let sum = 0;
  document.querySelectorAll('#tbody-gv-items .gv-monto').forEach(i => sum += parseFloat(i.value)||0);
  const el = document.getElementById('gv-total');
  if (el) el.textContent = lps(sum);
}

function addGvRow() {
  const opts = __CUENTAS_JSON__.map(c=>`<option value="${c.id}">${c.codigo} - ${c.nombre}</option>`).join('');
  const tr = document.createElement('tr');
  tr.className = 'detail-row';
  tr.innerHTML = `<td class="row-num"></td>
    <td><input type="text" class="form-control form-control-sm" data-field="descripcion" required></td>
    <td><select class="form-select form-select-sm" data-field="cuenta_id"><option value="">-- Cuenta --</option>${opts}</select></td>
    <td><input type="number" class="form-control form-control-sm gv-monto text-end" step="0.01" min="0" value="0" data-field="monto" oninput="calcGvTotal()"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeDetailRow(this);calcGvTotal()"><i class="fas fa-trash"></i></button></td>`;
  document.getElementById('tbody-gv-items').appendChild(tr);
  updateDetailRowNumbers('tbody-gv-items');
}

document.getElementById('form-gv')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const items = [];
  document.querySelectorAll('#tbody-gv-items .detail-row').forEach(r => {
    items.push({
      descripcion: r.querySelector('[data-field=descripcion]')?.value,
      cuenta_id:   r.querySelector('[data-field=cuenta_id]')?.value,
      monto:       r.querySelector('[data-field=monto]')?.value
    });
  });
  document.getElementById('items-json').value = JSON.stringify(items);
  post('gastos_viajes.php', new FormData(this)).then(r => {
    if (r.success) { Toast.show(r.message,'success'); setTimeout(()=>Nav.ir('gastos_viajes.php'), 1200); }
    else Toast.show(r.message,'error');
  }).catch(e=>Toast.show(e.message||'No se pudo guardar.','error',8000));
});

JS, [
    '__CUENTAS_JSON__' => $cuentasJson,
]);
include __DIR__ . '/includes/footer.php';
?>

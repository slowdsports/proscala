<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Solicitud de Gastos';

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'guardar') {
        $sgId        = (int)($_POST['id'] ?? 0);
        $empleadoId  = (int)$_POST['empleado_id'];
        // Firmante #1: quien llena la solicitud (su empleado vinculado; si no tiene, el beneficiario).
        // Se fija al crearla; al editarla se conserva.
        $firmante1Id = (int)($_SESSION['empleado_id'] ?? 0) ?: $empleadoId;
        $d = [
            'fecha'        => $_POST['fecha'],
            'empleado_id'  => $empleadoId,
            'firmante1_id' => $firmante1Id,
            'descripcion'  => trim($_POST['descripcion']),
            'proyecto_id'  => $_POST['proyecto_id'] ?: null,
            'viaje_id'     => $_POST['viaje_id'] ?: null,
            'notas'        => trim($_POST['notas'] ?? ''),
        ];

        $items = json_decode($_POST['items'] ?? '[]', true);
        $total = array_sum(array_column($items, 'monto'));

        $pdo->beginTransaction();
        try {
            if ($sgId) {
                $pdo->prepare("UPDATE solicitud_gastos SET fecha=?,empleado_id=?,descripcion=?,proyecto_id=?,viaje_id=?,monto_total=?,notas=? WHERE id=?")
                    ->execute([$d['fecha'],$d['empleado_id'],$d['descripcion'],$d['proyecto_id'],$d['viaje_id'],$total,$d['notas'],$sgId]);
                $pdo->prepare("DELETE FROM solicitud_gastos_detalle WHERE solicitud_id=?")->execute([$sgId]);
            } else {
                // Si viene vinculada a un anticipo, heredar su proceso_id
                $procesoId = null;
                if ($d['viaje_id']) {
                    $ps = $pdo->prepare("SELECT proceso_id FROM gastos_viaje WHERE id=?");
                    $ps->execute([$d['viaje_id']]);
                    $procesoId = $ps->fetchColumn() ?: null;
                }
                $num = numeroProceso($pdo,'solicitud_gastos',getConfig($pdo,'prefijo_sg','SG'), numeroDe($pdo,'gastos_viaje',$d['viaje_id']));
                $pdo->prepare("INSERT INTO solicitud_gastos (numero,proceso_id,fecha,empleado_id,firmante1_id,descripcion,proyecto_id,viaje_id,monto_total,notas,estado)
                    VALUES (?,?,?,?,?,?,?,?,?,?,'pendiente')")
                    ->execute([$num,$procesoId,$d['fecha'],$d['empleado_id'],$d['firmante1_id'],$d['descripcion'],$d['proyecto_id'],$d['viaje_id'],$total,$d['notas']]);
                $sgId = $pdo->lastInsertId();
            }
            foreach ($items as $item) {
                $pdo->prepare("INSERT INTO solicitud_gastos_detalle (solicitud_id,fecha,descripcion,cuenta_id,monto)
                    VALUES (?,?,?,?,?)")
                    ->execute([$sgId,$item['fecha']??$d['fecha'],$item['descripcion'],$item['cuenta_id']??null,$item['monto']]);
            }
            $pdo->commit();
            registrarAuditoria($pdo,'INSERT','solicitud_gastos',$sgId,'Nueva solicitud de gastos');
            jsonOk(['id'=>$sgId],'Solicitud guardada correctamente.');
        } catch(\Exception $e) { $pdo->rollBack(); jsonErr($e->getMessage()); }
    }

    if ($_act === 'cambiar_estado') {
        $sgId   = (int)$_POST['id'];
        $estado = $_POST['estado'];
        $pdo->prepare("UPDATE solicitud_gastos SET estado=?,aprobador_id=?,fecha_aprobacion=CURDATE() WHERE id=?")
            ->execute([$estado,$_SESSION['empleado_id'] ?? null,$sgId]);
        jsonOk([],'Estado actualizado.');
    }
    jsonErr('Acción desconocida.');
}

// ── Load ──────────────────────────────────────────────────────────
$sg = null; $detalle = []; $viaje_link = null;
$prefill_viaje_id = (int)($_GET['viaje_id'] ?? 0);
if (in_array($action,['ver','editar']) && $id) {
    $s = $pdo->prepare("SELECT sg.*,CONCAT(e.nombre,' ',e.apellidos) as emp_nombre
        FROM solicitud_gastos sg JOIN empleados e ON sg.empleado_id=e.id WHERE sg.id=?");
    $s->execute([$id]); $sg = $s->fetch();
    $s2 = $pdo->prepare("SELECT d.*, cc.nombre as cuenta_nombre FROM solicitud_gastos_detalle d
        LEFT JOIN cuentas_contables cc ON d.cuenta_id=cc.id WHERE d.solicitud_id=?");
    $s2->execute([$id]); $detalle = $s2->fetchAll();
    if ($sg && $sg['viaje_id']) {
        $sv = $pdo->prepare("SELECT gv.*, CONCAT(e.nombre,' ',e.apellidos) as emp_nombre FROM gastos_viaje gv JOIN empleados e ON gv.empleado_id=e.id WHERE gv.id=?");
        $sv->execute([$sg['viaje_id']]); $viaje_link = $sv->fetch();
    }
}

// ── Auxiliares ────────────────────────────────────────────────────
$empleados = $pdo->query("SELECT id, CONCAT(nombre,' ',apellidos) as nombre FROM empleados WHERE activo=1 ORDER BY apellidos")->fetchAll();
$proyectos = $pdo->query("SELECT id,nombre FROM proyectos WHERE estado='activo' ORDER BY nombre")->fetchAll();
$cuentas   = $pdo->query("SELECT id,codigo,nombre FROM cuentas_contables WHERE activa=1 AND tipo='gasto' ORDER BY codigo")->fetchAll();
// Roles que solo ven sus solicitudes: también solo pueden ligar sus propios anticipos
[$fpGv, $fpGvParams] = filtroPropios('gastos_viajes.php', 'gv');
$s = $pdo->prepare("SELECT gv.id, gv.numero, gv.tipo, gv.destino, gv.fecha_salida, gv.viaticos_anticipados FROM gastos_viaje gv WHERE $fpGv ORDER BY gv.id DESC");
$s->execute($fpGvParams); $viajes = $s->fetchAll();
// Anticipos autorizados que su empleado aún no ha liquidado (sin liquidación vigente):
// al elegir al empleado, el formulario los liga solo y pasa a "Liquidación de gastos"
$s = $pdo->prepare("SELECT gv.id, gv.numero, gv.empleado_id, gv.destino, gv.viaticos_anticipados FROM gastos_viaje gv
    WHERE gv.estado = 'aprobada' AND $fpGv
      AND NOT EXISTS (SELECT 1 FROM solicitud_gastos sg WHERE sg.viaje_id = gv.id AND sg.estado <> 'rechazada')
    ORDER BY gv.fecha_salida");
$s->execute($fpGvParams);
$anticiposPendientes = [];
foreach ($s->fetchAll() as $a) $anticiposPendientes[$a['empleado_id']][] = $a;

// Ligada a un anticipo es una liquidación; si no, una solicitud de gastos (reembolso)
function tipoSolicitudGastos(?array $sg): string {
    return !empty($sg['viaje_id']) ? 'Liquidación de gastos' : 'Solicitud de gastos';
}
$listaSolicitudes = [];
if ($action === 'list') {
    [$fp, $fpParams] = filtroPropios('gastos_solicitud.php', 'sg');
    $s = $pdo->prepare("SELECT sg.*,CONCAT(e.nombre,' ',e.apellidos) as emp_nombre
        FROM solicitud_gastos sg JOIN empleados e ON sg.empleado_id=e.id WHERE $fp AND " . filtroHistorico('sg') . " ORDER BY sg.id DESC");
    $s->execute($fpParams); $listaSolicitudes = $s->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-file-invoice-dollar"></i> Liquidaciones / Reembolsos</h1>
  <?php if ($action === 'list'): ?>
  <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nueva Solicitud</a>
  <?php else: ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card">
  <div class="card-body p-0">
    <table id="tbl-sg" class="table-proscala w-100">
      <thead><tr><th>Proceso</th><th>Número</th><th>Fecha</th><th>Empleado</th><th>Descripción</th><th>Total</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($listaSolicitudes as $r): ?>
        <tr>
          <td class="font-mono text-primary fw-bold"><?= $r['proceso_id'] ? formatProceso($r['proceso_id']) : '—' ?></td>
          <td class="font-mono"><?= $r['numero'] ?><?= badgeHistorico($r) ?>
            <div class="text-muted" style="font-size:.72rem;font-family:inherit"><?= $r['viaje_id'] ? '<i class="fas fa-hand-holding-dollar"></i> Liquidación' : '<i class="fas fa-receipt"></i> Solicitud' ?></div></td>
          <td><?= fmtFecha($r['fecha']) ?></td>
          <td><?= htmlspecialchars($r['emp_nombre']) ?></td>
          <td><?= htmlspecialchars(substr($r['descripcion'],0,60)) ?></td>
          <td class="font-mono"><?= lps($r['monto_total']) ?></td>
          <td><?= estadoBadge($r['estado']) ?></td>
          <td>
            <a href="?action=ver&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a>
            <?= botonEditar($pdo, 'gastos_solicitud.php', (int)$r['id'], '?action=editar&id=' . $r['id'], true) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$listaSolicitudes): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No hay solicitudes registradas</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $sg): ?>
<?php $flujoModulo = 'gastos_solicitud.php'; $flujoId = $sg['id']; include __DIR__ . '/includes/flujo_aprobacion.php'; ?>
<!-- ── VIEW ── -->

<?php if ($viaje_link): ?>
<div class="trace-chain mb-3">
  <a href="<?= BASE_URL ?>gastos_viajes.php?action=ver&id=<?= $viaje_link['id'] ?>" class="trace-step linked">
    <i class="fas <?= tipoAnticipoIcon($viaje_link['tipo']) ?>"></i>
    <span class="trace-step-label">Anticipo<?= $viaje_link['tipo']==='compra' ? ' de Compra' : ' de Viaje' ?></span>
    <span class="trace-step-num"><?= $viaje_link['numero'] ?></span>
    <span class="trace-step-badge"><?= estadoBadge($viaje_link['estado']) ?></span>
  </a>
  <div class="trace-step active">
    <i class="fas fa-file-invoice-dollar"></i>
    <span class="trace-step-label">Liquidación de Gastos</span>
    <span class="trace-step-num"><?= $sg['numero'] ?></span>
    <span class="trace-step-badge"><?= estadoBadge($sg['estado']) ?></span>
  </div>
</div>
<?php endif; ?>

<div id="print-area">
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-file-invoice-dollar"></i> <?= tipoSolicitudGastos($sg) ?> <?= $sg['numero'] ?> — <?= estadoBadge($sg['estado']) ?>
    <?php if ($sg['proceso_id']): ?>
    <span class="badge bg-primary ms-2 font-mono" title="Número de proceso compartido">Proceso <?= formatProceso($sg['proceso_id']) ?></span>
    <?php endif; ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?= botonEditar($pdo, 'gastos_solicitud.php', (int)$sg['id'], '?action=editar&id=' . $sg['id']) ?>
      <?php if (!soloRegistrosPropios() && $sg['estado']==='aprobada'): ?>
      <button class="btn btn-sm btn-info text-white" onclick="cambiarEstado('gastos_solicitud.php',<?=$sg['id']?>,'pagada','solicitud_gastos',()=>Nav.recargar())">
        <i class="fas fa-money-bill"></i> Pagar
      </button>
      <a href="<?= BASE_URL ?>compras_pago.php?action=nuevo&sg_id=<?= $sg['id'] ?>" class="btn btn-sm btn-success">
        <i class="fas fa-money-bill-transfer"></i> Crear Orden de Pago
      </a>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>print.php?tipo=sg&id=<?= $sg['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-2" style="font-size:.85rem;">
      <div class="col-md-3"><strong>Fecha:</strong> <?= fmtFecha($sg['fecha']) ?></div>
      <div class="col-md-4"><strong>Empleado:</strong> <?= htmlspecialchars($sg['emp_nombre']) ?></div>
      <div class="col-md-5"><strong>Descripción:</strong> <?= htmlspecialchars($sg['descripcion']) ?></div>
      <?php if ($sg['notas']): ?><div class="col-12"><strong>Notas:</strong> <?= htmlspecialchars($sg['notas']) ?></div><?php endif; ?>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><i class="fas fa-list"></i> Detalle de Gastos</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>#</th><th>Fecha</th><th>Descripción</th><th>Cuenta</th><th>Monto</th></tr></thead>
      <tbody>
        <?php $i=1; foreach ($detalle as $d): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= fmtFecha($d['fecha']??'') ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td><?= htmlspecialchars($d['cuenta_nombre']??'—') ?></td>
          <td class="font-mono"><?= lps($d['monto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4" class="text-end fw-bold">TOTAL</td><td class="font-mono fw-bold"><?= lps($sg['monto_total']) ?></td></tr></tfoot>
    </table>
  </div>
</div>
</div><!-- /print-area -->

<?php $adjModulo = 'gastos_solicitud.php'; $adjId = $sg['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php else: ?>
<!-- ── FORM ── -->
<div class="card">
  <div class="card-header"><i class="fas fa-plus"></i>
    <span id="titulo-sg"><?= ($sg ? 'Editar ' : 'Nueva ') . mb_strtolower(tipoSolicitudGastos($sg ?: ['viaje_id' => $prefill_viaje_id])) ?></span><?= $sg ? ': ' . $sg['numero'] : '' ?>
  </div>
  <div class="card-body">
    <form id="form-sg" action="gastos_solicitud.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="guardar">
      <input type="hidden" name="id" value="<?= $sg['id'] ?? 0 ?>">
      <input type="hidden" name="items" id="items-json">
      <input type="hidden" name="monto_total" id="monto_total">

      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Fecha *</label>
          <input type="text" name="fecha" class="form-control date-input" required value="<?= $sg['fecha'] ?? date('Y-m-d') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Empleado *</label>
          <select name="empleado_id" id="sel-empleado-sg" class="form-select" required>
            <option value="">-- Seleccione --</option>
            <?php foreach ($empleados as $e): ?>
            <option value="<?= $e['id'] ?>" <?= ($sg['empleado_id']??$_SESSION['empleado_id']??0)==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label">Descripción General *</label>
          <input type="text" name="descripcion" class="form-control" required value="<?= htmlspecialchars($sg['descripcion']??'') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Firmante (Firma 1 del documento)</label>
          <input type="text" class="form-control" readonly value="<?= htmlspecialchars(firmante1Nombre($pdo, $sg ? (int)$sg['firmante1_id'] : 0)) ?>">
          <div class="form-text">Es quien llena la solicitud.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Proyecto</label>
          <select name="proyecto_id" class="form-select">
            <option value="">-- General --</option>
            <?php foreach ($proyectos as $p): ?>
            <option value="<?= $p['id'] ?>" <?= ($sg['proyecto_id']??0)==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8">
          <label class="form-label">Anticipo Relacionado <small class="text-muted">(liquidación de un anticipo de viaje o de compra)</small></label>
          <select name="viaje_id" id="sel-viaje" class="form-select">
            <option value="">— Ninguno (solicitud de gastos) —</option>
            <?php
            $sgViajeId = $sg['viaje_id'] ?? $prefill_viaje_id;
            foreach ($viajes as $v):
            ?>
            <option value="<?= $v['id'] ?>" <?= $sgViajeId==$v['id']?'selected':'' ?>>
              [<?= $v['tipo']==='compra' ? 'Compra' : 'Viaje' ?>] <?= $v['numero'] ?> — <?= htmlspecialchars($v['destino']) ?> (<?= fmtFecha($v['fecha_salida']) ?>) — Anticipo: <?= lps($v['viaticos_anticipados']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div id="aviso-anticipo" class="form-text text-primary" style="display:none"></div>
        </div>
        <div class="col-md-12">
          <label class="form-label">Notas / Justificación</label>
          <input type="text" name="notas" class="form-control" value="<?= htmlspecialchars($sg['notas']??'') ?>">
        </div>
      </div>

      <div class="form-section-title mt-3">Detalle de Gastos</div>
      <div class="table-responsive">
        <table class="table-proscala w-100" id="tbl-items">
          <thead><tr><th>#</th><th>Fecha</th><th>Descripción</th><th>Cuenta Contable</th><th>Monto (L.)</th><th></th></tr></thead>
          <tbody id="tbody-items">
            <?php
            $initItems = $detalle ?: [['fecha'=>date('Y-m-d'),'descripcion'=>'','cuenta_id'=>'','cuenta_nombre'=>'','monto'=>0]];
            foreach ($initItems as $idx => $it): ?>
            <tr class="detail-row" id="row-<?= $idx ?>">
              <td class="row-num"><?= $idx+1 ?></td>
              <td><input type="text" class="form-control form-control-sm date-input" placeholder="Fecha" value="<?= $it['fecha']??date('Y-m-d') ?>" data-field="fecha"></td>
              <td><input type="text" class="form-control form-control-sm" placeholder="Descripción del gasto" value="<?= htmlspecialchars($it['descripcion']??'') ?>" data-field="descripcion" required></td>
              <td>
                <select class="form-select form-select-sm" data-field="cuenta_id">
                  <option value="">-- Cuenta --</option>
                  <?php foreach ($cuentas as $cc): ?>
                  <option value="<?= $cc['id'] ?>" <?= ($it['cuenta_id']??0)==$cc['id']?'selected':'' ?>><?= $cc['codigo'] ?> - <?= htmlspecialchars($cc['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><input type="number" class="form-control form-control-sm row-monto text-end" step="0.01" min="0" placeholder="0.00" value="<?= $it['monto']??0 ?>" data-field="monto"></td>
              <td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeDetailRow(this)"><i class="fas fa-trash"></i></button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4" class="text-end fw-bold">TOTAL:</td>
              <td class="font-mono fw-bold" id="grand-total">L. 0.00</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-success mt-2" onclick="addGastoRow()">
        <i class="fas fa-plus"></i> Agregar Línea
      </button>

      <?php $adjModulo = 'gastos_solicitud.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar <span id="boton-sg"><?= $sg && $sg['viaje_id'] || $prefill_viaje_id ? 'liquidación' : 'solicitud' ?></span></button>
        <a href="?" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$cuentasJson = json_encode(array_map(fn($c)=>['id'=>$c['id'],'codigo'=>$c['codigo'],'nombre'=>$c['nombre']],$cuentas));
$extraJs = "
initDataTable('#tbl-sg');
recalcTotals();

function addGastoRow() {
  const idx = document.querySelectorAll('#tbody-items .detail-row').length;
  const cuentaOpts = " . $cuentasJson . ".map(c=>`<option value=\"\${c.id}\">\${c.codigo} - \${c.nombre}</option>`).join('');
  const tr = document.createElement('tr');
  tr.className='detail-row';
  tr.innerHTML = `
    <td class=\"row-num\">\${idx+1}</td>
    <td><input type=\"text\" class=\"form-control form-control-sm date-input\" placeholder=\"Fecha\" value=\"".date('Y-m-d')."\" data-field=\"fecha\"></td>
    <td><input type=\"text\" class=\"form-control form-control-sm\" placeholder=\"Descripción\" data-field=\"descripcion\" required></td>
    <td><select class=\"form-select form-select-sm\" data-field=\"cuenta_id\"><option value=\"\">-- Cuenta --</option>\${cuentaOpts}</select></td>
    <td><input type=\"number\" class=\"form-control form-control-sm row-monto text-end\" step=\"0.01\" min=\"0\" placeholder=\"0.00\" value=\"0\" data-field=\"monto\"></td>
    <td><button type=\"button\" class=\"btn btn-sm btn-outline-danger py-0 px-1\" onclick=\"removeDetailRow(this)\"><i class=\"fas fa-trash\"></i></button></td>`;
  document.getElementById('tbody-items').appendChild(tr);
  flatpickr(tr.querySelector('.date-input'), {locale:'es',dateFormat:'Y-m-d',allowInput:true});
  updateDetailRowNumbers('tbody-items');
}

document.getElementById('form-sg')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const items = [];
  document.querySelectorAll('#tbody-items .detail-row').forEach(row => {
    items.push({
      fecha:       row.querySelector('[data-field=fecha]')?.value,
      descripcion: row.querySelector('[data-field=descripcion]')?.value,
      cuenta_id:   row.querySelector('[data-field=cuenta_id]')?.value,
      monto:       row.querySelector('[data-field=monto]')?.value,
    });
  });
  document.getElementById('items-json').value = JSON.stringify(items);
  const total = items.reduce((s,i)=>s+parseFloat(i.monto||0),0);
  document.getElementById('monto_total').value = total.toFixed(2);
  const fd = new FormData(this);
  post('gastos_solicitud.php', fd).then(r => {
    if (r.success) { Toast.show(r.message,'success'); setTimeout(()=>Nav.ir('gastos_solicitud.php'), 1200); }
    else Toast.show(r.message,'error');
  }).catch(()=>Toast.show('Error de conexión','error'));
});
";
if ($action !== 'list' && $action !== 'ver') {
    $extraJs .= 'const ANTICIPOS_PENDIENTES = ' . json_encode($anticiposPendientes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ";\n"
              . 'const SG_NUEVA = ' . ($sg ? 'false' : 'true') . ";\n";
    $extraJs .= <<<'ENDJS'
// Ligada a un anticipo es una liquidación de gastos; si no, una solicitud de gastos
function actualizarTipoSG() {
  const liq = !!document.getElementById('sel-viaje').value;
  document.getElementById('titulo-sg').textContent = (SG_NUEVA ? 'Nueva ' : 'Editar ') + (liq ? 'liquidación de gastos' : 'solicitud de gastos');
  document.getElementById('boton-sg').textContent = liq ? 'liquidación' : 'solicitud';
}

// Al elegir al empleado: si tiene anticipos autorizados sin liquidar, se liga solo.
// Un anticipo ligado automáticamente se quita si luego se elige a otro empleado.
let anticipoAuto = '';
function detectarAnticipo() {
  const aviso = document.getElementById('aviso-anticipo');
  const pend = ANTICIPOS_PENDIENTES[document.getElementById('sel-empleado-sg').value] || [];
  if (anticipoAuto && document.getElementById('sel-viaje').value === anticipoAuto) $('#sel-viaje').val('').trigger('change');
  anticipoAuto = '';
  aviso.style.display = pend.length ? '' : 'none';
  if (!pend.length) return;
  if (pend.length === 1) {
    anticipoAuto = String(pend[0].id);
    $('#sel-viaje').val(anticipoAuto).trigger('change');
    aviso.innerHTML = '<i class="fas fa-circle-info"></i> El empleado tiene el anticipo <strong>' + pend[0].numero
      + '</strong> pendiente de liquidar: se registrará como su liquidación de gastos.';
  } else {
    aviso.innerHTML = '<i class="fas fa-circle-info"></i> El empleado tiene ' + pend.length + ' anticipos pendientes de liquidar ('
      + pend.map(a => a.numero).join(', ') + '). Seleccione cuál está liquidando.';
  }
}

$('#sel-viaje').on('change', actualizarTipoSG);
$('#sel-empleado-sg').on('change', () => { if (SG_NUEVA) detectarAnticipo(); });
if (SG_NUEVA && !document.getElementById('sel-viaje').value) detectarAnticipo();
actualizarTipoSG();
ENDJS;
}
include __DIR__ . '/includes/footer.php';
?>

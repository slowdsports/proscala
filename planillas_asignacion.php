<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Planillas de Asignación';

$config = getAllConfig($pdo);
$meses  = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'crear') {
        $anio   = (int)$_POST['anio'];
        $mes    = (int)$_POST['mes'];
        $desc   = trim($_POST['descripcion'] ?? '');
        $proyId = !empty($_POST['proyecto_id']) ? (int)$_POST['proyecto_id'] : null;

        $chk = $pdo->prepare("SELECT id FROM planilla_asignacion WHERE anio=? AND mes=?");
        $chk->execute([$anio, $mes]);
        if ($chk->fetch()) jsonErr('Ya existe una planilla de asignación para ese mes y año.');

        $prefijo = $config['prefijo_pa'] ?? 'PA';
        $numero  = numeroProceso($pdo, 'planilla_asignacion', $prefijo, null, $anio);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO planilla_asignacion (numero,anio,mes,descripcion,proyecto_id) VALUES (?,?,?,?,?)")
                ->execute([$numero, $anio, $mes, $desc, $proyId]);
            $pid = $pdo->lastInsertId();

            $empleados = $pdo->query("SELECT id FROM empleados WHERE activo=1 ORDER BY apellidos,nombre")->fetchAll();
            $ins = $pdo->prepare("INSERT INTO planilla_asignacion_detalle (asignacion_id,empleado_id,concepto,monto) VALUES (?,?,?,0)");
            foreach ($empleados as $e) {
                $ins->execute([$pid, $e['id'], $desc]);
            }
            $pdo->commit();
            jsonOk(['id' => $pid], 'Planilla creada.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr('Error: ' . $e->getMessage());
        }
    }

    if ($_act === 'guardar_detalle') {
        $pid  = (int)$_POST['id'];
        $rows = $_POST['rows'] ?? [];
        $pdo->beginTransaction();
        try {
            $upd   = $pdo->prepare("UPDATE planilla_asignacion_detalle SET concepto=?, monto=? WHERE id=? AND asignacion_id=?");
            $total = 0;
            foreach ($rows as $row) {
                $monto = (float)str_replace(',', '', $row['monto']);
                $upd->execute([trim($row['concepto'] ?? ''), $monto, (int)$row['id'], $pid]);
                $total += $monto;
            }
            $pdo->prepare("UPDATE planilla_asignacion SET total=? WHERE id=?")->execute([$total, $pid]);
            $pdo->commit();
            jsonOk(['total' => $total], 'Detalle guardado correctamente.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr($e->getMessage());
        }
    }

    // 'aprobada' llega del flujo (validarFlujoAprobacion ya verificó quién autoriza); 'pagada', solo si ya se aprobó
    if ($_act === 'cambiar_estado') {
        $pid    = (int)$_POST['id'];
        $estado = $_POST['estado'] ?? '';
        if (!in_array($estado, ['aprobada', 'pagada'], true)) jsonErr('Estado no válido.');
        $s = $pdo->prepare("UPDATE planilla_asignacion SET estado=? WHERE id=? AND estado=?");
        $s->execute([$estado, $pid, $estado === 'pagada' ? 'aprobada' : 'borrador']);
        if (!$s->rowCount()) jsonErr($estado === 'pagada' ? 'Solo una planilla aprobada se marca como pagada.' : 'La planilla ya no está pendiente de aprobación.');
        jsonOk([], $estado === 'aprobada' ? 'Planilla autorizada.' : 'Planilla marcada como pagada.');
    }

    if ($_act === 'eliminar') {
        $pdo->prepare("DELETE FROM planilla_asignacion WHERE id=?")->execute([(int)$_POST['id']]);
        jsonOk([], 'Planilla eliminada.');
    }

    jsonErr('Acción desconocida.');
}

// ── CARGA ──────────────────────────────────────────────────────────
$periodo = null;
$detalle = [];
if ($action === 'ver' && $id) {
    $s = $pdo->prepare("SELECT pa.*, p.nombre AS proy_nombre FROM planilla_asignacion pa
        LEFT JOIN proyectos p ON pa.proyecto_id = p.id WHERE pa.id=?");
    $s->execute([$id]); $periodo = $s->fetch();
    if (!$periodo) { header('Location: planillas_asignacion.php'); exit; }
    $s2 = $pdo->prepare("SELECT pad.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo
        FROM planilla_asignacion_detalle pad JOIN empleados e ON pad.empleado_id = e.id
        WHERE pad.asignacion_id=? ORDER BY e.apellidos,e.nombre");
    $s2->execute([$id]); $detalle = $s2->fetchAll();
}

$periodos = [];
if ($action === 'list') {
    $periodos = $pdo->query("SELECT pa.*, p.nombre AS proy_nombre FROM planilla_asignacion pa
        LEFT JOIN proyectos p ON pa.proyecto_id=p.id WHERE " . filtroHistorico('pa') . " ORDER BY pa.anio DESC, pa.mes DESC")->fetchAll();
}

$proyectos = $pdo->query("SELECT id, nombre FROM proyectos WHERE estado='activo' ORDER BY nombre")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-list-check"></i> Planillas de Asignación</h1>
  <?php if ($action === 'list'): ?>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-crear">
    <i class="fas fa-plus"></i> Nueva Planilla
  </button>
  <?php else: ?>
  <a href="planillas_asignacion.php" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card"><div class="card-body p-0">
  <table id="tbl-pa" class="table-proscala w-100">
    <thead>
      <tr><th>Número</th><th>Período</th><th>Descripción</th><th>Proyecto</th><th>Total</th><th>Estado</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($periodos as $p): ?>
      <tr>
        <td class="font-mono"><?= htmlspecialchars($p['numero']) ?><?= badgeHistorico($p) ?></td>
        <td><?= $meses[$p['mes']-1] . ' ' . $p['anio'] ?></td>
        <td><?= htmlspecialchars($p['descripcion']) ?></td>
        <td><?= htmlspecialchars($p['proy_nombre'] ?? '—') ?></td>
        <td class="font-mono fw-bold"><?= lps($p['total']) ?></td>
        <td><?= estadoBadge($p['estado']) ?></td>
        <td>
          <a href="?action=ver&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a>
          <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
            onclick="deleteRecord('planillas_asignacion.php',<?= $p['id'] ?>,()=>Nav.recargar())">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$periodos): ?>
      <tr><td colspan="7" class="text-center text-muted py-4">No hay planillas de asignación registradas</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php elseif ($action === 'ver' && $periodo): ?>
<?php $flujoModulo = 'planillas_asignacion.php'; $flujoId = $periodo['id']; include __DIR__ . '/includes/flujo_aprobacion.php'; ?>
<?php $cadModulo = 'planillas_asignacion.php'; $cadId = $periodo['id']; include __DIR__ . '/includes/cadena_proceso.php'; ?>
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-list-check"></i>
    <strong><?= htmlspecialchars($periodo['numero']) ?></strong> —
    <?= $meses[$periodo['mes']-1] . ' ' . $periodo['anio'] ?>
    <?php if ($periodo['descripcion']): ?>
      <span class="text-muted ms-2" style="font-size:.85rem;"><?= htmlspecialchars($periodo['descripcion']) ?></span>
    <?php endif; ?>
    <?= estadoBadge($periodo['estado']) ?>
    <div class="ms-auto d-flex gap-2">
      <?php // Aprobar: desde el panel del flujo (revisar y autorizar), arriba
      if ($periodo['estado'] === 'aprobada'): ?>
      <button class="btn btn-sm btn-info text-white" onclick="cambiarEstadoPa(<?= $id ?>,'pagada')">
        <i class="fas fa-money-bill"></i> Marcar Pagada
      </button>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>print.php?tipo=pa&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
      <?php if (in_array($periodo['estado'], ['aprobada','pagada'])): ?>
      <a href="<?= BASE_URL ?>print.php?tipo=pav&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-primary"><i class="fas fa-file-zipper"></i> Vouchers</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php $editarPa = puedeEditar($pdo, 'planillas_asignacion.php', (int)$id);   // rangos superiores, no quien la creó ?>
<form id="form-detalle">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <input type="hidden" name="_action" value="guardar_detalle">
  <input type="hidden" name="id" value="<?= $id ?>">
  <fieldset <?= $editarPa === true ? '' : 'disabled' ?>>
  <div class="card"><div class="card-body p-0" style="overflow-x:auto;">
    <table class="table-proscala w-100">
      <thead>
        <tr>
          <th style="width:2.5rem">#</th>
          <th>Empleado</th>
          <th>Cargo</th>
          <th>Concepto de Asignación</th>
          <th style="width:12rem">Monto (L.)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($detalle as $i => $d): ?>
        <tr>
          <td class="text-center text-muted"><?= $i + 1 ?></td>
          <td><?= htmlspecialchars($d['emp_nombre']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td>
            <input type="hidden" name="rows[<?= $i ?>][id]" value="<?= $d['id'] ?>">
            <input type="text" name="rows[<?= $i ?>][concepto]"
              value="<?= htmlspecialchars($d['concepto']) ?>"
              class="form-control form-control-sm"
              placeholder="Ej: Combustible, Alimentación…">
          </td>
          <td>
            <input type="number" name="rows[<?= $i ?>][monto]"
              value="<?= $d['monto'] ?>" step="0.01" min="0"
              class="form-control form-control-sm text-end monto-input"
              oninput="recalcTotal()">
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:var(--surface-2)">
          <td colspan="4" style="padding:8px 10px;text-align:right">TOTAL</td>
          <td class="font-mono text-success" style="padding:8px 10px" id="total-display">
            <?= lps($periodo['total']) ?>
          </td>
        </tr>
      </tfoot>
    </table>
  </div></div>
  </fieldset>

  <div class="mt-3 d-flex gap-2 align-items-center">
    <?php if ($editarPa === true): ?>
    <button type="button" class="btn-proscala" onclick="guardarDetalle()">
      <i class="fas fa-save"></i> Guardar Detalle
    </button>
    <?php else: ?>
    <span class="text-muted" style="font-size:.82rem"><i class="fas fa-lock"></i> <?= htmlspecialchars($editarPa) ?></span>
    <?php endif; ?>
    <span id="save-status" style="font-size:.82rem;color:var(--text-3)"></span>
  </div>
</form>
<?php $adjModulo = 'planillas_asignacion.php'; $adjId = $periodo['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php endif; ?>

<!-- Modal: Nueva Planilla -->
<div class="modal fade" id="modal-crear" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-list-check"></i> Nueva Planilla de Asignación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-crear">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="crear">
          <div class="row g-3">
            <div class="col-md-5">
              <label class="form-label">Año *</label>
              <input type="number" name="anio" class="form-control" value="<?= date('Y') ?>" min="2020" max="2040" required>
            </div>
            <div class="col-md-7">
              <label class="form-label">Mes *</label>
              <select name="mes" class="form-select" required>
                <?php foreach ($meses as $mi => $mn): ?>
                <option value="<?= $mi + 1 ?>" <?= ($mi + 1) == date('n') ? 'selected' : '' ?>><?= $mn ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Descripción</label>
              <input type="text" name="descripcion" class="form-control"
                placeholder="Ej: Asignación mensual de combustible">
            </div>
            <?php if ($proyectos): ?>
            <div class="col-12">
              <label class="form-label">Proyecto (opcional)</label>
              <select name="proyecto_id" class="form-select">
                <option value="">— Sin proyecto —</option>
                <?php foreach ($proyectos as $py): ?>
                <option value="<?= $py['id'] ?>"><?= htmlspecialchars($py['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php endif; ?>
          </div>
          <div class="alert alert-info mt-3 mb-0" style="font-size:.82rem;">
            <i class="fas fa-info-circle"></i>
            Se incluirán todos los empleados activos. Ingrese el monto y concepto por empleado en la siguiente pantalla.
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="crearPlanilla()">
          <i class="fas fa-plus"></i> Crear
        </button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
initDataTable('#tbl-pa');

function recalcTotal() {
  let sum = 0;
  document.querySelectorAll('.monto-input').forEach(i => sum += parseFloat(i.value) || 0);
  document.getElementById('total-display').textContent =
    'L. ' + sum.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}

async function guardarDetalle() {
  const status = document.getElementById('save-status');
  const btn    = document.querySelector('[onclick="guardarDetalle()"]');
  btn.disabled = true; status.textContent = 'Guardando…';
  try {
    const r = await post('planillas_asignacion.php', new FormData(document.getElementById('form-detalle')));
    if (r.success) { Toast.show(r.message, 'success'); status.textContent = '✓ Guardado'; setTimeout(() => status.textContent = '', 3000); }
    else           { Toast.show(r.message, 'error'); status.textContent = ''; }
  } finally { btn.disabled = false; }
}

async function cambiarEstadoPa(id, estado) {
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'cambiar_estado'); fd.append('id', id); fd.append('estado', estado);
  const r = await post('planillas_asignacion.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  else Toast.show(r.message, 'error');
}

async function crearPlanilla() {
  const r = await post('planillas_asignacion.php', new FormData(document.getElementById('form-crear')));
  if (r.success) {
    Toast.show(r.message, 'success');
    setTimeout(() => Nav.ir('planillas_asignacion.php?action=ver&id=' + r.id), 1000);
  } else { Toast.show(r.message, 'error'); }
}
JS;
include __DIR__ . '/includes/footer.php';
?>

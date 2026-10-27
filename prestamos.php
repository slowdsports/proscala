<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Préstamos a Empleados';

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'crear') {
        $empId  = (int)$_POST['empleado_id'];
        $monto  = (float)$_POST['monto'];
        $cuotas = max(1, (int)$_POST['cuotas']);
        $cuota  = round($monto / $cuotas, 2);
        $desc   = trim($_POST['descripcion']);
        $fecha  = $_POST['fecha'];
        if ($monto <= 0) jsonErr('El monto debe ser mayor a cero.');
        if (!$desc)     jsonErr('La descripción es obligatoria.');
        // Con id: edición (rangos superiores, sin cuotas pagadas; lo valida config/edicion.php)
        if ($presId = (int)($_POST['id'] ?? 0)) {
            $pdo->prepare("UPDATE prestamos SET empleado_id=?, fecha=?, monto=?, cuotas=?, monto_cuota=?, saldo=?, descripcion=? WHERE id=?")
                ->execute([$empId, $fecha, $monto, $cuotas, $cuota, $monto, $desc, $presId]);
            registrarAuditoria($pdo, 'UPDATE', 'prestamos', $presId, 'Préstamo editado: ' . resumenDatosBitacora($_POST));
            jsonOk(['id' => $presId], 'Préstamo actualizado.');
        }
        $num = numeroProceso($pdo, 'prestamos', getConfig($pdo, 'prefijo_pres', 'PRES'));
        $pdo->prepare("INSERT INTO prestamos (numero,empleado_id,fecha,monto,cuotas,monto_cuota,saldo,descripcion)
            VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$num, $empId, $fecha, $monto, $cuotas, $cuota, $monto, $desc]);
        $presId = (int)$pdo->lastInsertId();
        registrarAuditoria($pdo, 'INSERT', 'prestamos', $presId, "Préstamo creado: $num — " . lps($monto));
        jsonOk(['id' => $presId], 'Préstamo registrado.');
    }

    if ($_act === 'registrar_pago') {
        $pid   = (int)$_POST['prestamo_id'];
        $monto = (float)$_POST['monto'];
        $fecha = $_POST['fecha'];
        $notas = trim($_POST['notas'] ?? '');
        if ($monto <= 0) jsonErr('El monto debe ser mayor a cero.');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO prestamos_pagos (prestamo_id,fecha,monto,notas) VALUES (?,?,?,?)")
                ->execute([$pid, $fecha, $monto, $notas]);
            // Actualizar saldo
            $pdo->prepare("UPDATE prestamos SET saldo = GREATEST(0, saldo - ?) WHERE id=?")->execute([$monto, $pid]);
            // Marcar pagado si saldo = 0
            $saldo = (float)$pdo->query("SELECT saldo FROM prestamos WHERE id=$pid")->fetchColumn();
            if ($saldo <= 0) $pdo->prepare("UPDATE prestamos SET estado='pagado' WHERE id=?")->execute([$pid]);
            $pdo->commit();
            registrarAuditoria($pdo, 'INSERT', 'prestamos_pagos', $pid, "Pago " . lps($monto) . " en préstamo #$pid");
            jsonOk(['saldo' => $saldo], 'Pago registrado correctamente.');
        } catch (\Exception $e) { $pdo->rollBack(); jsonErr($e->getMessage()); }
    }

    if ($_act === 'cambiar_estado') {
        $pid    = (int)$_POST['id'];
        $estado = in_array($_POST['estado'], ['activo','pagado','cancelado']) ? $_POST['estado'] : '';
        if (!$estado) jsonErr('Estado inválido.');
        $pdo->prepare("UPDATE prestamos SET estado=?, aprobador_id=? WHERE id=?")
            ->execute([$estado, $_SESSION['empleado_id'] ?? null, $pid]);
        registrarAuditoria($pdo, 'UPDATE', 'prestamos', $pid, "Estado préstamo → $estado");
        jsonOk([], 'Estado actualizado.');
    }

    jsonErr('Acción desconocida.');
}

// ── Carga ──────────────────────────────────────────────────────────
$empleados = $pdo->query("SELECT id, CONCAT(nombre,' ',apellidos) AS nombre, cargo, sueldo_mensual
    FROM empleados WHERE activo=1 ORDER BY apellidos, nombre")->fetchAll();

$prestamo = null;
$pagos    = [];
if ($action === 'ver' && $id) {
    $s = $pdo->prepare("SELECT pr.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo, e.sueldo_mensual,
        CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
        FROM prestamos pr JOIN empleados e ON pr.empleado_id=e.id
        LEFT JOIN empleados a ON pr.aprobador_id=a.id WHERE pr.id=?");
    $s->execute([$id]); $prestamo = $s->fetch();
    if (!$prestamo) { header('Location: prestamos.php'); exit; }
    $pagos = $pdo->prepare("SELECT * FROM prestamos_pagos WHERE prestamo_id=? ORDER BY fecha DESC, id DESC");
    $pagos->execute([$id]); $pagos = $pagos->fetchAll();
}

// Resumen activos por empleado (para lista)
$lista = [];
if ($action === 'list') {
    $lista = $pdo->query("SELECT pr.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo
        FROM prestamos pr JOIN empleados e ON pr.empleado_id=e.id
        WHERE " . filtroHistorico('pr') . " ORDER BY pr.estado ASC, pr.id DESC")->fetchAll();
}

// Total saldo activo
$totalSaldo = (float)$pdo->query("SELECT COALESCE(SUM(saldo),0) FROM prestamos WHERE estado='activo'")->fetchColumn();

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-hand-holding-dollar"></i> Préstamos a Empleados</h1>
  <?php if ($action === 'list'): ?>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-nuevo">
    <i class="fas fa-plus"></i> Nuevo Préstamo
  </button>
  <?php else: ?>
  <a href="prestamos.php" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>

<!-- Resumen -->
<div class="row g-3 mb-4">
  <?php
    $activos    = array_filter($lista, fn($r) => $r['estado'] === 'activo');
    $pagados    = array_filter($lista, fn($r) => $r['estado'] === 'pagado');
    $cancelados = array_filter($lista, fn($r) => $r['estado'] === 'cancelado');
  ?>
  <div class="col-md-4">
    <div class="kpi-card kpi-yellow kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="kpi-value"><?= lps($totalSaldo) ?></div>
      <div class="kpi-label">Saldo Total Activo</div>
      <div class="kpi-note"><?= count($activos) ?> préstamo<?= count($activos) != 1 ? 's' : '' ?> activo<?= count($activos) != 1 ? 's' : '' ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="kpi-card kpi-green kpi-hover">
      <div class="kpi-icon"><i class="fas fa-circle-check"></i></div>
      <div class="kpi-value"><?= count($pagados) ?></div>
      <div class="kpi-label">Pagados</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="kpi-card kpi-gray kpi-hover">
      <div class="kpi-icon"><i class="fas fa-ban"></i></div>
      <div class="kpi-value"><?= count($cancelados) ?></div>
      <div class="kpi-label">Cancelados</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <table id="tbl-pres" class="table-proscala w-100">
      <thead><tr><th>Número</th><th>Empleado</th><th>Fecha</th><th>Monto</th><th>Cuotas</th><th>Cuota</th><th>Saldo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($lista as $p): ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($p['numero']) ?><?= badgeHistorico($p) ?></td>
          <td>
            <div><?= htmlspecialchars($p['emp_nombre']) ?></div>
            <div style="font-size:.75rem;color:var(--text-3)"><?= htmlspecialchars($p['cargo'] ?? '') ?></div>
          </td>
          <td><?= fmtFecha($p['fecha']) ?></td>
          <td class="font-mono"><?= lps($p['monto']) ?></td>
          <td class="font-mono text-center"><?= $p['cuotas'] ?></td>
          <td class="font-mono"><?= lps($p['monto_cuota']) ?></td>
          <td class="font-mono fw-bold <?= $p['saldo'] > 0 ? 'text-danger' : 'text-success' ?>"><?= lps($p['saldo']) ?></td>
          <td><?= estadoBadge($p['estado']) ?></td>
          <td>
            <a href="?action=ver&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a>
            <?= botonEditar($pdo, 'prestamos.php', (int)$p['id'], 'js:editarPrestamo(' . json_encode(array_intersect_key($p, array_flip(['id','empleado_id','fecha','monto','cuotas','descripcion']))) . ')', true) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$lista): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No hay préstamos registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $prestamo): ?>
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-hand-holding-dollar"></i>
    <strong><?= htmlspecialchars($prestamo['numero']) ?></strong> — <?= estadoBadge($prestamo['estado']) ?>
    <div class="ms-auto d-flex gap-2">
      <?= botonEditar($pdo, 'prestamos.php', (int)$id, 'js:editarPrestamo(' . json_encode(array_intersect_key($prestamo, array_flip(['id','empleado_id','fecha','monto','cuotas','descripcion']))) . ')') ?>
      <?php if ($prestamo['estado'] === 'activo'): ?>
      <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modal-pago">
        <i class="fas fa-money-bill-wave"></i> Registrar Pago
      </button>
      <button class="btn btn-sm btn-outline-secondary" onclick="cancelarPrestamo(<?= $id ?>)">Cancelar préstamo</button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-3" style="font-size:.85rem">
      <div class="col-md-4"><strong>Empleado:</strong> <?= htmlspecialchars($prestamo['emp_nombre']) ?></div>
      <div class="col-md-4"><strong>Cargo:</strong> <?= htmlspecialchars($prestamo['cargo'] ?? '—') ?></div>
      <div class="col-md-4"><strong>Sueldo mensual:</strong> <span class="font-mono"><?= lps($prestamo['sueldo_mensual']) ?></span></div>
      <div class="col-md-3"><strong>Fecha:</strong> <?= fmtFecha($prestamo['fecha']) ?></div>
      <div class="col-md-3"><strong>Monto prestado:</strong> <span class="font-mono fw-bold"><?= lps($prestamo['monto']) ?></span></div>
      <div class="col-md-3"><strong>Cuotas:</strong> <span class="font-mono"><?= $prestamo['cuotas'] ?> × <?= lps($prestamo['monto_cuota']) ?></span></div>
      <div class="col-md-3"><strong>Saldo pendiente:</strong> <span class="font-mono fw-bold <?= $prestamo['saldo'] > 0 ? 'text-danger' : 'text-success' ?>"><?= lps($prestamo['saldo']) ?></span></div>
      <div class="col-12"><strong>Descripción:</strong> <?= htmlspecialchars($prestamo['descripcion']) ?></div>
    </div>
  </div>
</div>

<!-- Barra de progreso -->
<?php $pct = $prestamo['monto'] > 0 ? round((($prestamo['monto'] - $prestamo['saldo']) / $prestamo['monto']) * 100) : 100; ?>
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between mb-1" style="font-size:.8rem">
      <span>Pagado: <strong><?= lps($prestamo['monto'] - $prestamo['saldo']) ?></strong></span>
      <span><?= $pct ?>%</span>
      <span>Saldo: <strong><?= lps($prestamo['saldo']) ?></strong></span>
    </div>
    <div style="height:10px;background:var(--border);border-radius:5px;overflow:hidden">
      <div style="width:<?= $pct ?>%;height:100%;background:<?= $pct >= 100 ? '#16a34a' : '#2563eb' ?>;border-radius:5px;transition:width .4s"></div>
    </div>
  </div>
</div>

<!-- Historial de pagos -->
<div class="card">
  <div class="card-header"><i class="fas fa-history"></i> Historial de Pagos</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>#</th><th>Fecha</th><th>Monto</th><th>Notas</th></tr></thead>
      <tbody>
        <?php $i = 1; foreach ($pagos as $pg): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= fmtFecha($pg['fecha']) ?></td>
          <td class="font-mono"><?= lps($pg['monto']) ?></td>
          <td><?= htmlspecialchars($pg['notas'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$pagos): ?>
        <tr><td colspan="4" class="text-center text-muted py-3">Sin pagos registrados.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="2" class="text-end fw-bold">Total pagado:</td>
            <td class="font-mono fw-bold text-success"><?= lps($prestamo['monto'] - $prestamo['saldo']) ?></td><td></td></tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- Modal: Registrar Pago -->
<div class="modal fade" id="modal-pago" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title"><i class="fas fa-money-bill-wave"></i> Registrar Pago</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-pago">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="registrar_pago">
          <input type="hidden" name="prestamo_id" value="<?= $id ?>">
          <div class="mb-3">
            <label class="form-label">Fecha *</label>
            <input type="text" name="fecha" class="form-control date-input" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Monto *</label>
            <input type="number" name="monto" class="form-control" step="0.01" min="0.01"
              value="<?= $prestamo['monto_cuota'] ?>" required>
            <div class="form-text">Saldo actual: <?= lps($prestamo['saldo']) ?></div>
          </div>
          <div class="mb-3">
            <label class="form-label">Notas</label>
            <input type="text" name="notas" class="form-control" placeholder="Ej: Descuento en planilla">
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="registrarPago()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php $adjModulo = 'prestamos.php'; $adjId = $prestamo['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php endif; ?>

<!-- Modal: Nuevo Préstamo -->
<div class="modal fade" id="modal-nuevo" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-hand-holding-dollar"></i> Nuevo Préstamo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-nuevo-pres">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="crear">
          <input type="hidden" name="id" id="pres-id" value="0">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Empleado *</label>
              <select name="empleado_id" id="pres-empleado" class="form-select" required>
                <option value="">— Seleccione —</option>
                <?php foreach ($empleados as $e): ?>
                <option value="<?= $e['id'] ?>" data-sueldo="<?= $e['sueldo_mensual'] ?>">
                  <?= htmlspecialchars($e['nombre']) ?> — <?= lps($e['sueldo_mensual']) ?>/mes
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha *</label>
              <input type="text" name="fecha" class="form-control date-input" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Monto (L.) *</label>
              <input type="number" name="monto" id="pres-monto" class="form-control" step="0.01" min="1" required oninput="calcCuota()">
            </div>
            <div class="col-md-6">
              <label class="form-label">Número de cuotas *</label>
              <input type="number" name="cuotas" id="pres-cuotas" class="form-control" min="1" max="36" value="1" required oninput="calcCuota()">
            </div>
            <div class="col-md-6">
              <label class="form-label">Cuota estimada</label>
              <div id="pres-cuota-display" class="form-control-plaintext font-mono fw-bold">—</div>
            </div>
            <div class="col-12">
              <label class="form-label">Descripción / Motivo *</label>
              <input type="text" name="descripcion" id="pres-desc" class="form-control" required placeholder="Ej: Préstamo personal para gastos médicos">
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="crearPrestamo()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
initDataTable('#tbl-pres');

function calcCuota() {
  const monto  = parseFloat(document.getElementById('pres-monto')?.value || 0);
  const cuotas = parseInt(document.getElementById('pres-cuotas')?.value || 1);
  const el = document.getElementById('pres-cuota-display');
  if (el) el.textContent = monto > 0 && cuotas > 0 ? lps(monto / cuotas) : '—';
}

async function crearPrestamo() {
  try {
    const r = await post('prestamos.php', new FormData(document.getElementById('form-nuevo-pres')));
    Toast.show(r.message,'success'); setTimeout(()=>Nav.ir('prestamos.php?action=ver&id='+r.id), 800);
  } catch (e) { Toast.show(e.message,'error'); }
}

// Al cerrar el modal vuelve a ser "Nuevo" (después de una edición)
const tituloPres = document.querySelector('#modal-nuevo .modal-title').innerHTML;
document.getElementById('modal-nuevo').addEventListener('hidden.bs.modal', () => {
  document.getElementById('pres-id').value = 0;
  document.querySelector('#modal-nuevo .modal-title').innerHTML = tituloPres;
});

// Editar (rangos superiores, mientras no tenga cuotas pagadas): el mismo formulario
function editarPrestamo(p) {
  const f = document.getElementById('form-nuevo-pres');
  document.getElementById('pres-id').value = p.id;
  $('#pres-empleado').val(String(p.empleado_id)).trigger('change');
  f.querySelector('[name=fecha]')._flatpickr?.setDate(p.fecha, false);
  document.getElementById('pres-monto').value = p.monto;
  document.getElementById('pres-cuotas').value = p.cuotas;
  document.getElementById('pres-desc').value = p.descripcion || '';
  calcCuota();
  document.querySelector('#modal-nuevo .modal-title').innerHTML = '<i class="fas fa-hand-holding-dollar"></i> Editar préstamo';
  new bootstrap.Modal(document.getElementById('modal-nuevo')).show();
}

async function registrarPago() {
  const r = await post('prestamos.php', new FormData(document.getElementById('form-pago')));
  if (r.success) { Toast.show(r.message,'success'); setTimeout(()=>Nav.recargar(),800); }
  else Toast.show(r.message,'error');
}

async function cancelarPrestamo(id) {
  if (!await confirmar('El saldo pendiente se marcará como no cobrado.', { titulo: '¿Cancelar este préstamo?', aceptar: 'Cancelar préstamo', cancelar: 'Volver', peligro: true })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action','cambiar_estado'); fd.append('id',id); fd.append('estado','cancelado');
  const r = await post('prestamos.php', fd);
  if (r.success) { Toast.show(r.message,'success'); setTimeout(()=>Nav.recargar(),800); }
  else Toast.show(r.message,'error');
}
JS;
include __DIR__ . '/includes/footer.php';
?>

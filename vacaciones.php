<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Control de Vacaciones';

// ── Días según ley hondureña (Art. 346 Código del Trabajo) ─────────
function diasVacLey(int $anios): int {
    if ($anios >= 4) return 20;
    if ($anios >= 3) return 15;
    if ($anios >= 2) return 12;
    if ($anios >= 1) return 10;
    return 0;
}

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'crear') {
        $empId  = (int)$_POST['empleado_id'];
        $inicio = $_POST['fecha_inicio'];
        $fin    = $_POST['fecha_fin'];
        $dias   = max(1, (int)$_POST['dias']);
        $tipo   = in_array($_POST['tipo'], ['ordinaria','anticipada']) ? $_POST['tipo'] : 'ordinaria';
        $notas  = trim($_POST['notas'] ?? '');
        // Con id: edición (solo rangos superiores; lo valida config/edicion.php)
        if ($vacId = (int)($_POST['id'] ?? 0)) {
            $pdo->prepare("UPDATE vacaciones SET empleado_id=?, fecha_inicio=?, fecha_fin=?, dias=?, tipo=?, notas=? WHERE id=?")
                ->execute([$empId, $inicio, $fin, $dias, $tipo, $notas, $vacId]);
            registrarAuditoria($pdo, 'UPDATE', 'vacaciones', $vacId, 'Solicitud de vacaciones editada: ' . resumenDatosBitacora($_POST));
            jsonOk(['id' => $vacId], 'Solicitud de vacaciones actualizada.');
        }
        $num    = generarNumero($pdo, 'vacaciones', 'numero', getConfig($pdo, 'prefijo_vac', 'VAC'));
        $pdo->prepare("INSERT INTO vacaciones (numero,empleado_id,fecha_solicitud,fecha_inicio,fecha_fin,dias,tipo,notas)
            VALUES (?,?,CURDATE(),?,?,?,?,?)")
            ->execute([$num, $empId, $inicio, $fin, $dias, $tipo, $notas]);
        $vacId = (int)$pdo->lastInsertId();
        registrarAuditoria($pdo, 'INSERT', 'vacaciones', $vacId, "Solicitud de vacaciones creada: $num");
        jsonOk(['id' => $vacId], 'Solicitud de vacaciones registrada.');
    }

    if ($_act === 'cambiar_estado') {
        $vid    = (int)$_POST['id'];
        $estado = in_array($_POST['estado'], ['aprobada','rechazada','disfrutada']) ? $_POST['estado'] : '';
        if (!$estado) jsonErr('Estado inválido.');
        $pdo->prepare("UPDATE vacaciones SET estado=?, aprobador_id=? WHERE id=?")
            ->execute([$estado, $_SESSION['empleado_id'] ?? null, $vid]);
        registrarAuditoria($pdo, 'UPDATE', 'vacaciones', $vid, "Estado vacaciones → $estado");
        jsonOk([], 'Estado actualizado.');
    }

    if ($_act === 'eliminar') {
        $eid = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM vacaciones WHERE id=? AND estado='pendiente'")->execute([$eid]);
        registrarAuditoria($pdo, 'DELETE', 'vacaciones', $eid, 'Solicitud de vacaciones eliminada');
        jsonOk([], 'Solicitud eliminada.');
    }

    jsonErr('Acción desconocida.');
}

// ── Carga ──────────────────────────────────────────────────────────
$empleados = $pdo->query("SELECT id, CONCAT(nombre,' ',apellidos) AS nombre, fecha_ingreso, cargo
    FROM empleados WHERE activo=1 ORDER BY apellidos, nombre")->fetchAll();

// Saldo vacacional por empleado
function saldoVac(PDO $pdo, int $empId, string $fechaIngreso): array {
    $hoy   = new DateTime();
    $ing   = $fechaIngreso ? new DateTime($fechaIngreso) : $hoy;
    $anios = (int)$ing->diff($hoy)->y;
    $ley   = diasVacLey($anios);
    $stmt  = $pdo->prepare("SELECT COALESCE(SUM(dias),0) FROM vacaciones
        WHERE empleado_id=? AND estado IN ('aprobada','disfrutada') AND YEAR(fecha_inicio)=YEAR(CURDATE())");
    $stmt->execute([$empId]);
    $tomados = (int)$stmt->fetchColumn();
    return ['anios' => $anios, 'ley' => $ley, 'tomados' => $tomados, 'saldo' => max(0, $ley - $tomados)];
}

$solicitud = null;
if ($action === 'ver' && $id) {
    $s = $pdo->prepare("SELECT v.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo, e.fecha_ingreso,
        CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
        FROM vacaciones v JOIN empleados e ON v.empleado_id=e.id
        LEFT JOIN empleados a ON v.aprobador_id=a.id WHERE v.id=?");
    $s->execute([$id]); $solicitud = $s->fetch();
    if (!$solicitud) { header('Location: vacaciones.php'); exit; }
}

$listaVac = [];
if ($action === 'list') {
    [$fp, $fpParams] = filtroPropios('vacaciones.php', 'v');   // roles que solo ven sus solicitudes
    $s = $pdo->prepare("SELECT v.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo
        FROM vacaciones v JOIN empleados e ON v.empleado_id=e.id
        WHERE $fp AND " . filtroHistorico('v') . " ORDER BY v.id DESC");
    $s->execute($fpParams); $listaVac = $s->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-umbrella-beach"></i> Control de Vacaciones</h1>
  <?php if ($action === 'list'): ?>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-nueva">
    <i class="fas fa-plus"></i> Nueva Solicitud
  </button>
  <?php else: ?>
  <a href="vacaciones.php" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>

<!-- Resumen de saldos -->
<div class="card mb-4">
  <div class="card-header"><i class="fas fa-chart-bar"></i> Saldo Vacacional <?= date('Y') ?></div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>Empleado</th><th>Cargo</th><th>Antigüedad</th><th>Días Ley</th><th>Tomados este año</th><th>Saldo disponible</th></tr></thead>
      <tbody>
        <?php foreach ($empleados as $e):
          $sv = saldoVac($pdo, $e['id'], $e['fecha_ingreso'] ?? '');
        ?>
        <tr>
          <td><?= htmlspecialchars($e['nombre']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($e['cargo'] ?? '') ?></td>
          <td><?= $sv['anios'] ?> año<?= $sv['anios'] != 1 ? 's' : '' ?></td>
          <td class="font-mono"><?= $sv['ley'] ?> días</td>
          <td class="font-mono"><?= $sv['tomados'] ?> días</td>
          <td class="font-mono fw-bold <?= $sv['saldo'] <= 0 ? 'text-danger' : 'text-success' ?>">
            <?= $sv['saldo'] ?> días
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Lista de solicitudes -->
<div class="card">
  <div class="card-header"><i class="fas fa-list"></i> Solicitudes de Vacaciones</div>
  <div class="card-body p-0">
    <table id="tbl-vac" class="table-proscala w-100">
      <thead><tr><th>Número</th><th>Empleado</th><th>Cargo</th><th>Inicio</th><th>Fin</th><th>Días</th><th>Tipo</th><th>Estado</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($listaVac as $v): ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($v['numero']) ?><?= badgeHistorico($v) ?></td>
          <td><?= htmlspecialchars($v['emp_nombre']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($v['cargo'] ?? '') ?></td>
          <td><?= fmtFecha($v['fecha_inicio']) ?></td>
          <td><?= fmtFecha($v['fecha_fin']) ?></td>
          <td class="font-mono"><?= $v['dias'] ?></td>
          <td><span class="badge bg-secondary"><?= ucfirst($v['tipo']) ?></span></td>
          <td><?= estadoBadge($v['estado']) ?></td>
          <td>
            <a href="?action=ver&id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a>
            <?= botonEditar($pdo, 'vacaciones.php', (int)$v['id'], 'js:editarVac(' . json_encode(array_intersect_key($v, array_flip(['id','empleado_id','fecha_inicio','fecha_fin','dias','tipo','notas']))) . ')', true) ?>
            <?php if ($v['estado'] === 'pendiente' && puedeEditar($pdo, 'vacaciones.php', (int)$v['id']) === true): ?>
            <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
              onclick="deleteRecord('vacaciones.php',<?= $v['id'] ?>,()=>Nav.recargar())">
              <i class="fas fa-trash"></i>
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$listaVac): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No hay solicitudes registradas.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $solicitud):
  $sv = saldoVac($pdo, $solicitud['empleado_id'], $solicitud['fecha_ingreso'] ?? '');
  $flujoModulo = 'vacaciones.php'; $flujoId = $solicitud['id']; include __DIR__ . '/includes/flujo_aprobacion.php';
?>
<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-umbrella-beach"></i>
    <strong><?= htmlspecialchars($solicitud['numero']) ?></strong> — <?= estadoBadge($solicitud['estado']) ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?php if (!soloRegistrosPropios() && $solicitud['estado'] === 'aprobada'): ?>
      <button class="btn btn-sm btn-info text-white" onclick="cambiarEstVac(<?= $id ?>,'disfrutada')"><i class="fas fa-check-double"></i> Marcar Disfrutada</button>
      <?php endif; ?>
      <?= botonEditar($pdo, 'vacaciones.php', (int)$id, 'js:editarVac(' . json_encode(array_intersect_key($solicitud, array_flip(['id','empleado_id','fecha_inicio','fecha_fin','dias','tipo','notas']))) . ')') ?>
      <a href="<?= BASE_URL ?>print.php?tipo=vac&id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-3" style="font-size:.85rem">
      <div class="col-md-4"><strong>Empleado:</strong> <?= htmlspecialchars($solicitud['emp_nombre']) ?></div>
      <div class="col-md-4"><strong>Cargo:</strong> <?= htmlspecialchars($solicitud['cargo'] ?? '—') ?></div>
      <div class="col-md-4"><strong>Antigüedad:</strong> <?= $sv['anios'] ?> año<?= $sv['anios'] != 1 ? 's' : '' ?></div>
      <div class="col-md-3"><strong>Fecha solicitud:</strong> <?= fmtFecha($solicitud['fecha_solicitud']) ?></div>
      <div class="col-md-3"><strong>Inicio:</strong> <?= fmtFecha($solicitud['fecha_inicio']) ?></div>
      <div class="col-md-3"><strong>Fin:</strong> <?= fmtFecha($solicitud['fecha_fin']) ?></div>
      <div class="col-md-3"><strong>Días:</strong> <span class="font-mono fw-bold"><?= $solicitud['dias'] ?></span> (<?= ucfirst($solicitud['tipo']) ?>)</div>
      <?php if ($solicitud['aprobador_nombre']): ?>
      <div class="col-md-6"><strong>Aprobado por:</strong> <?= htmlspecialchars($solicitud['aprobador_nombre']) ?></div>
      <?php endif; ?>
      <?php if ($solicitud['notas']): ?>
      <div class="col-12"><strong>Notas:</strong> <?= htmlspecialchars($solicitud['notas']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-header"><i class="fas fa-calendar-check"></i> Saldo Vacacional del Empleado (<?= date('Y') ?>)</div>
  <div class="card-body">
    <div class="row g-3 text-center">
      <div class="col-md-3"><div style="font-size:.75rem;color:var(--text-3)">Días por ley</div><div class="font-mono" style="font-size:1.6rem;font-weight:700"><?= $sv['ley'] ?></div></div>
      <div class="col-md-3"><div style="font-size:.75rem;color:var(--text-3)">Tomados</div><div class="font-mono" style="font-size:1.6rem;font-weight:700;color:#d97706"><?= $sv['tomados'] ?></div></div>
      <div class="col-md-3"><div style="font-size:.75rem;color:var(--text-3)">Esta solicitud</div><div class="font-mono" style="font-size:1.6rem;font-weight:700;color:#2563eb"><?= $solicitud['dias'] ?></div></div>
      <div class="col-md-3"><div style="font-size:.75rem;color:var(--text-3)">Saldo disponible</div><div class="font-mono" style="font-size:1.6rem;font-weight:700;color:<?= $sv['saldo'] <= 0 ? '#dc2626' : '#16a34a' ?>"><?= $sv['saldo'] ?></div></div>
    </div>
  </div>
</div>
<?php $adjModulo = 'vacaciones.php'; $adjId = $solicitud['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php endif; ?>

<!-- Modal: Nueva Solicitud -->
<div class="modal fade" id="modal-nueva" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-umbrella-beach"></i> Nueva Solicitud de Vacaciones</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-vac">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="crear">
          <input type="hidden" name="id" id="vac-id" value="0">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Empleado *</label>
              <select name="empleado_id" id="vac-empleado" class="form-select" required>
                <option value="">— Seleccione —</option>
                <?php foreach ($empleados as $e): ?>
                <option value="<?= $e['id'] ?>" <?= ($_SESSION['empleado_id'] ?? 0) == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha Inicio *</label>
              <input type="text" name="fecha_inicio" id="vac-inicio" class="form-control date-input" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha Fin *</label>
              <input type="text" name="fecha_fin" id="vac-fin" class="form-control date-input" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Días hábiles</label>
              <input type="number" name="dias" id="vac-dias" class="form-control" min="1" max="30" value="1" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Tipo</label>
              <select name="tipo" id="vac-tipo" class="form-select">
                <option value="ordinaria">Ordinaria</option>
                <option value="anticipada">Anticipada</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Notas</label>
              <textarea name="notas" id="vac-notas" class="form-control" rows="2" placeholder="Observaciones adicionales…"></textarea>
            </div>
          </div>
          <?php $adjModulo = 'vacaciones.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="crearVac()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
initDataTable('#tbl-vac');

// Auto-calcular días hábiles (excluyendo sábados y domingos)
function calcDiasHabiles() {
  const ini = document.getElementById('vac-inicio')._flatpickr?.selectedDates[0];
  const fin = document.getElementById('vac-fin')._flatpickr?.selectedDates[0];
  if (!ini || !fin || fin < ini) return;
  let dias = 0, cur = new Date(ini);
  while (cur <= fin) {
    const d = cur.getDay();
    if (d !== 0 && d !== 6) dias++;
    cur.setDate(cur.getDate() + 1);
  }
  document.getElementById('vac-dias').value = dias;
}

document.getElementById('vac-inicio')?.addEventListener('change', calcDiasHabiles);
document.getElementById('vac-fin')?.addEventListener('change', calcDiasHabiles);

async function crearVac() {
  try {
    const form = document.getElementById('form-vac');
    AdjuntosForm.anotar(form);   // documentos elegidos en el formulario
    const r = await post('vacaciones.php', new FormData(form));
    Toast.show(r.message,'success'); setTimeout(()=>Nav.ir('vacaciones.php?action=ver&id='+r.id), 800);
  } catch (e) { Toast.show(e.message,'error'); }
}

// Al cerrar el modal vuelve a ser "Nueva" (después de una edición)
const tituloVac = document.querySelector('#modal-nueva .modal-title').innerHTML;
document.getElementById('modal-nueva').addEventListener('hidden.bs.modal', () => {
  document.getElementById('vac-id').value = 0;
  document.querySelector('#modal-nueva .modal-title').innerHTML = tituloVac;
});

// Editar (rangos superiores): el mismo formulario, con los datos de la solicitud
function editarVac(v) {
  document.getElementById('vac-id').value = v.id;
  $('#vac-empleado').val(String(v.empleado_id)).trigger('change');
  document.getElementById('vac-inicio')._flatpickr?.setDate(v.fecha_inicio, false);
  document.getElementById('vac-fin')._flatpickr?.setDate(v.fecha_fin, false);
  document.getElementById('vac-dias').value = v.dias;
  $('#vac-tipo').val(v.tipo).trigger('change');
  document.getElementById('vac-notas').value = v.notas || '';
  document.querySelector('#modal-nueva .modal-title').innerHTML = '<i class="fas fa-umbrella-beach"></i> Editar solicitud de vacaciones';
  new bootstrap.Modal(document.getElementById('modal-nueva')).show();
}

async function cambiarEstVac(id, estado) {
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action','cambiar_estado'); fd.append('id',id); fd.append('estado',estado);
  const r = await post('vacaciones.php', fd);
  if (r.success) { Toast.show(r.message,'success'); setTimeout(()=>Nav.recargar(),800); }
  else Toast.show(r.message,'error');
}
JS;
include __DIR__ . '/includes/footer.php';
?>

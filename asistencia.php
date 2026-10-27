<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$pagina = 'Control de Asistencia';

// ── Parámetros de mes ─────────────────────────────────────────────
$anio = (int)($_GET['anio'] ?? date('Y'));
$mes  = (int)($_GET['mes']  ?? date('n'));
$anio = max(2020, min(2040, $anio));
$mes  = max(1, min(12, $mes));

$diasEnMes  = (int)date('t', mktime(0,0,0,$mes,1,$anio));
$meses      = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'marcar') {
        $empId = (int)$_POST['empleado_id'];
        $fecha = $_POST['fecha'];
        $est   = $_POST['estado'];
        $valid = ['presente','ausente','permiso','vacaciones','feriado','tardanza','medio_dia'];
        if (!in_array($est, $valid)) jsonErr('Estado inválido.');
        $hora_e = trim($_POST['hora_entrada'] ?? '') ?: null;
        $hora_s = trim($_POST['hora_salida']  ?? '') ?: null;
        $obs    = trim($_POST['observacion']  ?? '') ?: null;
        $pdo->prepare("INSERT INTO asistencia (empleado_id,fecha,estado,hora_entrada,hora_salida,observacion)
            VALUES (?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE estado=VALUES(estado),hora_entrada=VALUES(hora_entrada),
            hora_salida=VALUES(hora_salida),observacion=VALUES(observacion)")
            ->execute([$empId, $fecha, $est, $hora_e, $hora_s, $obs]);
        jsonOk([], 'Asistencia actualizada.');
    }

    if ($_act === 'marcar_todos') {
        $fecha = $_POST['fecha'];
        $est   = $_POST['estado'];
        $empleados = $pdo->query("SELECT id FROM empleados WHERE activo=1")->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("INSERT INTO asistencia (empleado_id,fecha,estado)
            VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE estado=VALUES(estado)");
        foreach ($empleados as $eid) $stmt->execute([$eid, $fecha, $est]);
        jsonOk([], 'Asistencia masiva aplicada.');
    }

    if ($_act === 'guardar_feriado') {
        $fecha = $_POST['fecha'];
        $nombre = trim($_POST['nombre'] ?? 'Feriado');
        $empleados = $pdo->query("SELECT id FROM empleados WHERE activo=1")->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("INSERT INTO asistencia (empleado_id,fecha,estado,observacion)
            VALUES (?,?,'feriado',?)
            ON DUPLICATE KEY UPDATE estado='feriado',observacion=VALUES(observacion)");
        foreach ($empleados as $eid) $stmt->execute([$eid, $fecha, $nombre]);
        jsonOk([], 'Feriado registrado para todos los empleados.');
    }

    jsonErr('Acción desconocida.');
}

// ── Carga ──────────────────────────────────────────────────────────
$empleados = $pdo->query("SELECT id, CONCAT(nombre,' ',apellidos) AS nombre, cargo
    FROM empleados WHERE activo=1 ORDER BY apellidos, nombre")->fetchAll();

// Cargar asistencias del mes
$stmt = $pdo->prepare("SELECT empleado_id, fecha, estado, hora_entrada, hora_salida, observacion
    FROM asistencia WHERE YEAR(fecha)=? AND MONTH(fecha)=?");
$stmt->execute([$anio, $mes]);
$asistencias = [];
foreach ($stmt->fetchAll() as $a) {
    $dia = (int)date('j', strtotime($a['fecha']));
    $asistencias[$a['empleado_id']][$dia] = $a;
}

// Resumen del mes por empleado
$resumen = [];
foreach ($empleados as $e) {
    $eid = $e['id'];
    $cnt = ['presente'=>0,'ausente'=>0,'permiso'=>0,'vacaciones'=>0,'feriado'=>0,'tardanza'=>0,'medio_dia'=>0,'sin_marcar'=>0];
    for ($d = 1; $d <= $diasEnMes; $d++) {
        $dow = date('N', mktime(0,0,0,$mes,$d,$anio)); // 1=Mon, 7=Sun
        if ($dow >= 6) continue; // skip weekends
        if (isset($asistencias[$eid][$d])) {
            $est = $asistencias[$eid][$d]['estado'];
            $cnt[$est] = ($cnt[$est] ?? 0) + 1;
        } else {
            $cnt['sin_marcar']++;
        }
    }
    $resumen[$eid] = $cnt;
}

// Navegación de meses
$prevMes  = $mes == 1  ? 12 : $mes - 1;
$prevAnio = $mes == 1  ? $anio - 1 : $anio;
$nextMes  = $mes == 12 ? 1  : $mes + 1;
$nextAnio = $mes == 12 ? $anio + 1 : $anio;

$ESTADOS = [
    'presente'   => ['label' => 'Presente',    'short' => 'P',  'color' => '#16a34a', 'bg' => '#dcfce7'],
    'tardanza'   => ['label' => 'Tardanza',     'short' => 'T',  'color' => '#d97706', 'bg' => '#fef3c7'],
    'medio_dia'  => ['label' => 'Medio día',    'short' => 'MD', 'color' => '#2563eb', 'bg' => '#dbeafe'],
    'permiso'    => ['label' => 'Permiso',      'short' => 'PE', 'color' => '#7c3aed', 'bg' => '#ede9fe'],
    'vacaciones' => ['label' => 'Vacaciones',   'short' => 'V',  'color' => '#0891b2', 'bg' => '#e0f2fe'],
    'ausente'    => ['label' => 'Ausente',      'short' => 'A',  'color' => '#dc2626', 'bg' => '#fee2e2'],
    'feriado'    => ['label' => 'Feriado',      'short' => 'F',  'color' => '#475569', 'bg' => '#f1f5f9'],
];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-calendar-days"></i> Control de Asistencia</h1>
</div>

<!-- Navegación de mes -->
<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <a href="?anio=<?= $prevAnio ?>&mes=<?= $prevMes ?>" class="btn-proscala-outline">
    <i class="fas fa-chevron-left"></i>
  </a>
  <h4 class="mb-0 fw-bold" style="min-width:200px;text-align:center"><?= $meses[$mes] ?> <?= $anio ?></h4>
  <a href="?anio=<?= $nextAnio ?>&mes=<?= $nextMes ?>" class="btn-proscala-outline">
    <i class="fas fa-chevron-right"></i>
  </a>
  <a href="?anio=<?= date('Y') ?>&mes=<?= date('n') ?>" class="btn btn-sm btn-outline-secondary ms-2">Hoy</a>

  <div class="ms-auto d-flex gap-2 align-items-center flex-wrap">
    <!-- Leyenda -->
    <?php foreach ($ESTADOS as $k => $e): ?>
    <span style="font-size:.7rem;background:<?= $e['bg'] ?>;color:<?= $e['color'] ?>;padding:2px 7px;border-radius:4px;font-weight:600"><?= $e['label'] ?></span>
    <?php endforeach; ?>
    <span style="font-size:.7rem;background:#f8fafc;color:#94a3b8;padding:2px 7px;border-radius:4px;font-weight:600">— No marcado</span>
  </div>
</div>

<!-- Grilla de asistencia -->
<div class="card" style="overflow-x:auto">
  <div class="card-body p-0">
    <table class="w-100" style="border-collapse:collapse;min-width:900px">
      <thead>
        <tr style="background:var(--surface-2)">
          <th style="padding:8px 12px;text-align:left;min-width:180px;position:sticky;left:0;background:var(--surface-2);z-index:2">Empleado</th>
          <?php for ($d = 1; $d <= $diasEnMes; $d++):
            $dow = date('N', mktime(0,0,0,$mes,$d,$anio));
            $esFind = $dow >= 6;
          ?>
          <th style="padding:4px 2px;text-align:center;width:32px;font-size:.7rem;<?= $esFind ? 'color:#94a3b8' : '' ?>">
            <div><?= $d ?></div>
            <div style="font-weight:400;font-size:.6rem"><?= mb_substr(['','Lu','Ma','Mi','Ju','Vi','Sa','Do'][$dow],0,2) ?></div>
          </th>
          <?php endfor; ?>
          <th style="padding:8px 6px;text-align:center;font-size:.7rem;min-width:220px">Resumen</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($empleados as $emp):
          $eid = $emp['id'];
          $res = $resumen[$eid] ?? [];
        ?>
        <tr style="border-bottom:1px solid var(--border)">
          <td style="padding:6px 12px;font-size:.82rem;position:sticky;left:0;background:var(--surface-1);z-index:1;white-space:nowrap">
            <div style="font-weight:600"><?= htmlspecialchars($emp['nombre']) ?></div>
            <div style="font-size:.7rem;color:var(--text-3)"><?= htmlspecialchars($emp['cargo'] ?? '') ?></div>
          </td>
          <?php for ($d = 1; $d <= $diasEnMes; $d++):
            $dow  = date('N', mktime(0,0,0,$mes,$d,$anio));
            $esFind = $dow >= 6;
            $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $d);
            $a    = $asistencias[$eid][$d] ?? null;
            $est  = $a ? $a['estado'] : null;
            $cfg  = $est ? ($ESTADOS[$est] ?? null) : null;
          ?>
          <td style="padding:2px;text-align:center">
            <?php if ($esFind): ?>
            <div style="width:28px;height:28px;margin:auto;background:#f8fafc;border-radius:4px"></div>
            <?php else: ?>
            <div class="asist-cell"
                 data-emp="<?= $eid ?>" data-fecha="<?= $fecha ?>"
                 data-estado="<?= $est ?? '' ?>"
                 data-obs="<?= htmlspecialchars($a['observacion'] ?? '') ?>"
                 title="<?= $cfg ? $cfg['label'] : 'Sin marcar' ?>"
                 style="width:28px;height:28px;margin:auto;border-radius:4px;cursor:pointer;
                        display:flex;align-items:center;justify-content:center;
                        font-size:.6rem;font-weight:700;
                        background:<?= $cfg ? $cfg['bg'] : '#f8fafc' ?>;
                        color:<?= $cfg ? $cfg['color'] : '#94a3b8' ?>">
              <?= $cfg ? $cfg['short'] : '—' ?>
            </div>
            <?php endif; ?>
          </td>
          <?php endfor; ?>
          <td style="padding:4px 8px;font-size:.7rem">
            <div class="d-flex flex-wrap gap-1">
              <?php if (($res['presente'] ?? 0) > 0): ?><span style="background:#dcfce7;color:#16a34a;padding:1px 5px;border-radius:3px"><?= $res['presente'] ?>P</span><?php endif; ?>
              <?php if (($res['tardanza'] ?? 0) > 0): ?><span style="background:#fef3c7;color:#d97706;padding:1px 5px;border-radius:3px"><?= $res['tardanza'] ?>T</span><?php endif; ?>
              <?php if (($res['medio_dia'] ?? 0) > 0): ?><span style="background:#dbeafe;color:#2563eb;padding:1px 5px;border-radius:3px"><?= $res['medio_dia'] ?>MD</span><?php endif; ?>
              <?php if (($res['permiso'] ?? 0) > 0): ?><span style="background:#ede9fe;color:#7c3aed;padding:1px 5px;border-radius:3px"><?= $res['permiso'] ?>PE</span><?php endif; ?>
              <?php if (($res['vacaciones'] ?? 0) > 0): ?><span style="background:#e0f2fe;color:#0891b2;padding:1px 5px;border-radius:3px"><?= $res['vacaciones'] ?>V</span><?php endif; ?>
              <?php if (($res['ausente'] ?? 0) > 0): ?><span style="background:#fee2e2;color:#dc2626;padding:1px 5px;border-radius:3px"><?= $res['ausente'] ?>A</span><?php endif; ?>
              <?php if (($res['sin_marcar'] ?? 0) > 0): ?><span style="background:#f1f5f9;color:#94a3b8;padding:1px 5px;border-radius:3px"><?= $res['sin_marcar'] ?>?</span><?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal: Marcar asistencia individual -->
<div class="modal fade" id="modal-marcar" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0"><i class="fas fa-calendar-check"></i> Registrar Asistencia</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="m-emp">
        <input type="hidden" id="m-fecha">
        <div class="mb-3">
          <label class="form-label" style="font-size:.8rem">Fecha: <strong id="m-fecha-label"></strong></label>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:.8rem">Estado</label>
          <div class="d-grid gap-1" id="btn-estados">
            <?php foreach ($ESTADOS as $k => $e): ?>
            <button type="button" class="btn btn-sm est-btn" data-est="<?= $k ?>"
              style="background:<?= $e['bg'] ?>;color:<?= $e['color'] ?>;border:1.5px solid <?= $e['color'] ?>30;text-align:left;font-weight:600">
              <?= $e['label'] ?>
            </button>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mb-2">
          <label class="form-label" style="font-size:.8rem">Observación (opcional)</label>
          <input type="text" id="m-obs" class="form-control form-control-sm" placeholder="Ej: Llegó a las 8:30am">
        </div>
        <input type="hidden" id="m-csrf" value="<?= csrfToken() ?>">
      </div>
    </div>
  </div>
</div>

<?php
$estadosJson = json_encode($ESTADOS);
$extraJs = <<<JS
const ESTADOS_CFG = $estadosJson;

document.querySelectorAll('.asist-cell').forEach(cell => {
  cell.addEventListener('click', () => openMarcar(cell));
});

function openMarcar(cell) {
  document.getElementById('m-emp').value   = cell.dataset.emp;
  document.getElementById('m-fecha').value = cell.dataset.fecha;
  const parts = cell.dataset.fecha.split('-');
  document.getElementById('m-fecha-label').textContent = parts[2]+'/'+parts[1]+'/'+parts[0];
  document.getElementById('m-obs').value   = cell.dataset.obs || '';
  // Highlight active state
  document.querySelectorAll('.est-btn').forEach(b => b.style.fontWeight = '600');
  const active = document.querySelector('.est-btn[data-est="'+cell.dataset.estado+'"]');
  if (active) active.style.outline = '2px solid currentColor';
  const modal = new bootstrap.Modal(document.getElementById('modal-marcar'));
  modal.show();
}

document.querySelectorAll('.est-btn').forEach(btn => {
  btn.addEventListener('click', async () => {
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('m-csrf').value);
    fd.append('_action','marcar');
    fd.append('empleado_id', document.getElementById('m-emp').value);
    fd.append('fecha',       document.getElementById('m-fecha').value);
    fd.append('estado',      btn.dataset.est);
    fd.append('observacion', document.getElementById('m-obs').value);
    const r = await post('asistencia.php', fd);
    if (r.success) {
      bootstrap.Modal.getInstance(document.getElementById('modal-marcar'))?.hide();
      Nav.recargar();
    } else Toast.show(r.message,'error');
  });
});
JS;
include __DIR__ . '/includes/footer.php';
?>

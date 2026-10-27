<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Liquidaciones de Empleados';

// ── JSON record fetch for modal edit ──────────────────────────────
if (isset($_GET['_json']) && $id) {
    $rec = $pdo->prepare("SELECT * FROM liquidaciones WHERE id=? AND estado='borrador'");
    $rec->execute([$id]);
    $rec = $rec->fetch(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo $rec ? json_encode(['success'=>true,'record'=>$rec])
              : json_encode(['success'=>false,'message'=>'No encontrado']);
    exit;
}

// ── Helpers de cálculo Honduras ────────────────────────────────────
function diasVacLeyLiq(int $anios): int {
    if ($anios >= 7)  return 20;
    if ($anios >= 4)  return 15;
    if ($anios >= 2)  return 12;
    if ($anios >= 1)  return 10;
    return 0;
}

function calcLiquidacion(array $p): array {
    $sueldo   = (float)$p['sueldo_mensual'];
    $diario   = $sueldo / 30;
    $anios    = (float)$p['anios_servicio'];
    $meses    = (float)$p['meses_anio_actual'];   // meses transcurridos en el año actual
    $motivo   = $p['motivo'];
    $diasPend = (float)($p['dias_pendientes'] ?? 0);
    $diasVac  = (float)($p['dias_vacaciones'] ?? 0);
    $otros    = (float)($p['otros_conceptos'] ?? 0);

    // Salarios pendientes
    $salariosPend = round($diasPend * $diario, 2);

    // Vacaciones pendientes (días de vacación acumulados no tomados)
    $vacPend = round($diasVac * $diario, 2);

    // Décimo tercer mes proporcional (meses/12 × sueldo)
    $decimoTercero = round(($meses / 12) * $sueldo, 2);

    // Décimo cuarto mes proporcional (meses/12 × sueldo)
    $decimoCuarto = round(($meses / 12) * $sueldo, 2);

    // Preaviso — Art. 116 CT (solo despido injustificado)
    $preaviso = 0;
    if ($motivo === 'despido_injustificado') {
        $mesesTotal = $anios * 12;
        if ($mesesTotal >= 24)      $preaviso = round($sueldo * 2, 2);       // 2 meses
        elseif ($mesesTotal >= 12)  $preaviso = round($sueldo, 2);            // 1 mes
        elseif ($mesesTotal >= 6)   $preaviso = round($sueldo * 2 / 4, 2);   // 2 semanas
        elseif ($mesesTotal >= 3)   $preaviso = round($sueldo / 4, 2);        // 1 semana
    }

    // Auxilio de Cesantía — Art. 120 CT (solo despido injustificado)
    $cesantia = 0;
    if ($motivo === 'despido_injustificado') {
        $mesesTotal = $anios * 12;
        if ($mesesTotal >= 3 && $mesesTotal < 6)       $cesantia = round($diario * 10, 2);
        elseif ($mesesTotal >= 6 && $mesesTotal < 12)  $cesantia = round($diario * 20, 2);
        elseif ($mesesTotal >= 12) {
            $aniosCompletos = floor($anios);
            $fraccion       = $anios - $aniosCompletos;
            $cesantia       = round(($aniosCompletos + $fraccion) * $sueldo, 2);
        }
    }

    $total = $salariosPend + $vacPend + $decimoTercero + $decimoCuarto + $preaviso + $cesantia + $otros;

    return [
        'salarios_pendientes'  => $salariosPend,
        'vacaciones_pendientes'=> $vacPend,
        'decimo_tercero_prop'  => $decimoTercero,
        'decimo_cuarto_prop'   => $decimoCuarto,
        'preaviso'             => $preaviso,
        'cesantia'             => $cesantia,
        'total'                => round($total, 2),
    ];
}

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'calcular') {
        $empId  = (int)$_POST['empleado_id'];
        $fSal   = $_POST['fecha_salida'] ?? date('Y-m-d');
        $motivo = $_POST['motivo'] ?? 'renuncia';

        $emp = $pdo->prepare("SELECT sueldo_mensual, fecha_ingreso,
            CONCAT(nombre,' ',apellidos) AS nombre FROM empleados WHERE id=?");
        $emp->execute([$empId]);
        $emp = $emp->fetch();
        if (!$emp) jsonErr('Empleado no encontrado.');

        $ing     = new DateTime($emp['fecha_ingreso']);
        $sal     = new DateTime($fSal);
        $diff    = $ing->diff($sal);
        $anios   = $diff->y + round($diff->m / 12, 4);
        $meses   = (int)date('n', strtotime($fSal)) - 1 + round((int)date('j', strtotime($fSal)) / 30, 2);
        $meses   = min(12, max(0, $meses));

        // Vacaciones no disfrutadas del año (proporcional)
        $diasVacLey  = diasVacLeyLiq((int)$diff->y);
        $diasVacProp = round(($diasVacLey / 12) * $meses, 1);
        $stmtTom = $pdo->prepare("SELECT COALESCE(SUM(dias),0) FROM vacaciones
            WHERE empleado_id=? AND estado IN ('aprobada','disfrutada') AND YEAR(fecha_inicio)=YEAR(?)");
        $stmtTom->execute([$empId, $fSal]);
        $vacTomadas = (float)$stmtTom->fetchColumn();
        $diasVac = max(0, $diasVacProp - $vacTomadas);

        $calc = calcLiquidacion([
            'sueldo_mensual'   => $emp['sueldo_mensual'],
            'anios_servicio'   => $anios,
            'meses_anio_actual'=> $meses,
            'motivo'           => $motivo,
            'dias_pendientes'  => 0,
            'dias_vacaciones'  => $diasVac,
            'otros_conceptos'  => 0,
        ]);

        jsonOk(array_merge($calc, [
            'sueldo_mensual'   => (float)$emp['sueldo_mensual'],
            'anios_servicio'   => round($anios, 4),
            'meses_anio_actual'=> round($meses, 2),
            'dias_vacaciones'  => $diasVac,
            'emp_nombre'       => $emp['nombre'],
        ]));
    }

    if ($_act === 'guardar') {
        $isNew  = !(int)($_POST['id'] ?? 0);
        $empId  = (int)$_POST['empleado_id'];
        $fSal   = $_POST['fecha_salida'];
        $motivo = in_array($_POST['motivo'], ['renuncia','despido_injustificado','despido_justificado',
            'vencimiento_contrato','mutuo_acuerdo','fallecimiento']) ? $_POST['motivo'] : 'renuncia';
        $sueldo = (float)$_POST['sueldo_mensual'];
        $anios  = (float)$_POST['anios_servicio'];
        $meses  = (float)$_POST['meses_anio_actual'];
        $diasPend = (float)$_POST['dias_pendientes'];
        $diasVac  = (float)$_POST['dias_vacaciones'];
        $otros  = (float)($_POST['otros_conceptos'] ?? 0);
        $otrosDesc = trim($_POST['otros_descripcion'] ?? '');
        $notas  = trim($_POST['notas'] ?? '');

        $calc = calcLiquidacion([
            'sueldo_mensual'   => $sueldo,
            'anios_servicio'   => $anios,
            'meses_anio_actual'=> $meses,
            'motivo'           => $motivo,
            'dias_pendientes'  => $diasPend,
            'dias_vacaciones'  => $diasVac,
            'otros_conceptos'  => $otros,
        ]);

        if ($isNew) {
            $num = numeroProceso($pdo, 'liquidaciones', getConfig($pdo, 'prefijo_liq', 'LIQ'));
            $pdo->prepare("INSERT INTO liquidaciones (numero,empleado_id,fecha_salida,motivo,anios_servicio,
                meses_anio_actual,sueldo_mensual,dias_pendientes,salarios_pendientes,dias_vacaciones,
                vacaciones_pendientes,decimo_tercero_prop,decimo_cuarto_prop,preaviso,cesantia,
                otros_conceptos,otros_descripcion,total,notas)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$num, $empId, $fSal, $motivo, $anios, $meses, $sueldo, $diasPend,
                    $calc['salarios_pendientes'], $diasVac, $calc['vacaciones_pendientes'],
                    $calc['decimo_tercero_prop'], $calc['decimo_cuarto_prop'],
                    $calc['preaviso'], $calc['cesantia'],
                    $otros, $otrosDesc, $calc['total'], $notas]);
            $lid = (int)$pdo->lastInsertId();
            registrarAuditoria($pdo, 'INSERT', 'liquidaciones', $lid, "Liquidación creada: $num — " . lps($calc['total']));
            jsonOk(['id' => $lid], 'Liquidación registrada.');
        } else {
            $lid = (int)$_POST['id'];
            $pdo->prepare("UPDATE liquidaciones SET empleado_id=?,fecha_salida=?,motivo=?,anios_servicio=?,
                meses_anio_actual=?,sueldo_mensual=?,dias_pendientes=?,salarios_pendientes=?,dias_vacaciones=?,
                vacaciones_pendientes=?,decimo_tercero_prop=?,decimo_cuarto_prop=?,preaviso=?,cesantia=?,
                otros_conceptos=?,otros_descripcion=?,total=?,notas=? WHERE id=? AND estado='borrador'")
                ->execute([$empId, $fSal, $motivo, $anios, $meses, $sueldo, $diasPend,
                    $calc['salarios_pendientes'], $diasVac, $calc['vacaciones_pendientes'],
                    $calc['decimo_tercero_prop'], $calc['decimo_cuarto_prop'],
                    $calc['preaviso'], $calc['cesantia'],
                    $otros, $otrosDesc, $calc['total'], $notas, $lid]);
            registrarAuditoria($pdo, 'UPDATE', 'liquidaciones', $lid, "Liquidación actualizada — " . lps($calc['total']));
            jsonOk(['id' => $lid], 'Liquidación actualizada.');
        }
    }

    if ($_act === 'cambiar_estado') {
        $lid    = (int)$_POST['id'];
        $estado = in_array($_POST['estado'], ['aprobada','pagada','borrador']) ? $_POST['estado'] : '';
        if (!$estado) jsonErr('Estado inválido.');
        $fecha  = $estado === 'pagada' ? date('Y-m-d') : null;
        $pdo->prepare("UPDATE liquidaciones SET estado=?, aprobador_id=?, fecha_aprobacion=?, fecha_pago=? WHERE id=?")
            ->execute([$estado, currentUser()['id'],
                       in_array($estado,['aprobada','pagada']) ? date('Y-m-d') : null,
                       $fecha, $lid]);
        registrarAuditoria($pdo, 'UPDATE', 'liquidaciones', $lid, "Estado liquidación → $estado");
        jsonOk([], 'Estado actualizado.');
    }

    if ($_act === 'eliminar') {
        $lid = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM liquidaciones WHERE id=? AND estado='borrador'")->execute([$lid]);
        registrarAuditoria($pdo, 'DELETE', 'liquidaciones', $lid, 'Liquidación eliminada');
        jsonOk([], 'Liquidación eliminada.');
    }

    jsonErr('Acción desconocida.');
}

// ── Carga ──────────────────────────────────────────────────────────
$empleados = $pdo->query("SELECT id, CONCAT(nombre,' ',apellidos) AS nombre, cargo,
    fecha_ingreso, sueldo_mensual FROM empleados WHERE activo=1 ORDER BY apellidos, nombre")->fetchAll();

$liq = null;
if ($action === 'ver' && $id) {
    $s = $pdo->prepare("SELECT l.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
        e.cargo, e.identidad, e.departamento, e.fecha_ingreso,
        CONCAT(u.nombre,' ',u.apellidos) AS aprobador_nombre
        FROM liquidaciones l
        JOIN empleados e ON l.empleado_id = e.id
        LEFT JOIN empleados u ON l.aprobador_id = u.id
        WHERE l.id=?");
    $s->execute([$id]); $liq = $s->fetch();
    if (!$liq) { header('Location: liquidaciones.php'); exit; }
}

$listaLiq = [];
if ($action === 'list') {
    $listaLiq = $pdo->query("SELECT l.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo
        FROM liquidaciones l JOIN empleados e ON l.empleado_id=e.id
        WHERE " . filtroHistorico('l') . "
        ORDER BY l.id DESC")->fetchAll();
}

$motivoLabel = [
    'renuncia'              => 'Renuncia voluntaria',
    'despido_injustificado' => 'Despido injustificado',
    'despido_justificado'   => 'Despido justificado',
    'vencimiento_contrato'  => 'Vencimiento de contrato',
    'mutuo_acuerdo'         => 'Mutuo acuerdo',
    'fallecimiento'         => 'Fallecimiento',
];

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-file-invoice"></i> Liquidaciones de Empleados</h1>
  <?php if ($action === 'list'): ?>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-nueva">
    <i class="fas fa-plus"></i> Nueva Liquidación
  </button>
  <?php else: ?>
  <a href="liquidaciones.php" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>

<div class="card">
  <div class="card-header"><i class="fas fa-list"></i> Liquidaciones Registradas</div>
  <div class="card-body p-0">
    <table id="tbl-liq" class="table-proscala w-100">
      <thead>
        <tr>
          <th>Número</th><th>Empleado</th><th>Cargo</th><th>Motivo</th>
          <th>Fecha Salida</th><th>Total</th><th>Estado</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($listaLiq as $l): ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($l['numero']) ?><?= badgeHistorico($l) ?></td>
          <td><?= htmlspecialchars($l['emp_nombre']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($l['cargo'] ?? '') ?></td>
          <td><?= htmlspecialchars($motivoLabel[$l['motivo']] ?? $l['motivo']) ?></td>
          <td><?= fmtFecha($l['fecha_salida']) ?></td>
          <td class="font-mono fw-bold"><?= lps($l['total']) ?></td>
          <td><?= estadoBadge($l['estado']) ?></td>
          <td>
            <a href="?action=ver&id=<?= $l['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
              <i class="fas fa-eye"></i>
            </a>
            <?php if (puedeEditar($pdo, 'liquidaciones.php', (int)$l['id']) === true): ?>
            <button class="btn btn-sm btn-outline-warning py-0 px-2 ms-1"
              onclick="editarLiq(<?= $l['id'] ?>)"
              title="Editar"><i class="fas fa-pen"></i></button>
            <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
              onclick="deleteRecord('liquidaciones.php',<?= $l['id'] ?>,()=>Nav.recargar())"
              title="Eliminar"><i class="fas fa-trash"></i></button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$listaLiq): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No hay liquidaciones registradas.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $liq): ?>

<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-file-invoice"></i>
    <strong><?= htmlspecialchars($liq['numero']) ?></strong> — <?= estadoBadge($liq['estado']) ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?= botonEditar($pdo, 'liquidaciones.php', (int)$id, 'js:editarLiq(' . (int)$id . ')') ?>
      <?php if ($liq['estado'] === 'borrador'): ?>
      <button class="btn btn-sm btn-success" onclick="cambiarEstLiq(<?= $id ?>,'aprobada')">
        <i class="fas fa-check"></i> Aprobar
      </button>
      <?php elseif ($liq['estado'] === 'aprobada'): ?>
      <button class="btn btn-sm btn-info text-white" onclick="cambiarEstLiq(<?= $id ?>,'pagada')">
        <i class="fas fa-money-bill-wave"></i> Marcar Pagada
      </button>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>print.php?tipo=liq&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-3" style="font-size:.85rem">
      <div class="col-md-4"><strong>Empleado:</strong> <?= htmlspecialchars($liq['emp_nombre']) ?></div>
      <div class="col-md-4"><strong>Cargo:</strong> <?= htmlspecialchars($liq['cargo'] ?? '—') ?></div>
      <div class="col-md-4"><strong>Identidad:</strong> <?= htmlspecialchars($liq['identidad'] ?? '—') ?></div>
      <div class="col-md-4"><strong>Departamento:</strong> <?= htmlspecialchars($liq['departamento'] ?? '—') ?></div>
      <div class="col-md-4"><strong>Fecha Ingreso:</strong> <?= fmtFecha($liq['fecha_ingreso']) ?></div>
      <div class="col-md-4"><strong>Fecha Salida:</strong> <?= fmtFecha($liq['fecha_salida']) ?></div>
      <div class="col-md-4"><strong>Motivo:</strong> <?= htmlspecialchars($motivoLabel[$liq['motivo']] ?? $liq['motivo']) ?></div>
      <div class="col-md-4"><strong>Antigüedad:</strong>
        <?php
          $a = floor($liq['anios_servicio']);
          $m = round(($liq['anios_servicio'] - $a) * 12);
          echo "$a año" . ($a != 1 ? 's' : '') . ($m ? ", $m mes" . ($m != 1 ? 'es' : '') : '');
        ?>
      </div>
      <?php if ($liq['aprobador_nombre']): ?>
      <div class="col-md-4"><strong>Aprobado por:</strong> <?= htmlspecialchars($liq['aprobador_nombre']) ?></div>
      <?php endif; ?>
      <?php if ($liq['fecha_pago']): ?>
      <div class="col-md-4"><strong>Fecha Pago:</strong> <?= fmtFecha($liq['fecha_pago']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Detalle de cálculos -->
<div class="card mb-3">
  <div class="card-header"><i class="fas fa-calculator"></i> Desglose de Liquidación</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead>
        <tr><th>Concepto</th><th>Referencia Legal</th><th class="text-end">Monto</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Sueldo Mensual</td>
          <td style="color:var(--text-3);font-size:.8rem">Base de cálculo</td>
          <td class="text-end font-mono"><?= lps($liq['sueldo_mensual']) ?></td>
        </tr>
        <?php if ($liq['dias_pendientes'] > 0): ?>
        <tr>
          <td>Salarios Pendientes (<?= $liq['dias_pendientes'] ?> días)</td>
          <td style="color:var(--text-3);font-size:.8rem">Días no pagados</td>
          <td class="text-end font-mono"><?= lps($liq['salarios_pendientes']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($liq['dias_vacaciones'] > 0): ?>
        <tr>
          <td>Vacaciones Pendientes (<?= $liq['dias_vacaciones'] ?> días)</td>
          <td style="color:var(--text-3);font-size:.8rem">Art. 346 CT</td>
          <td class="text-end font-mono"><?= lps($liq['vacaciones_pendientes']) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td>Décimo Tercer Mes Proporcional</td>
          <td style="color:var(--text-3);font-size:.8rem"><?= round($liq['meses_anio_actual'], 1) ?> meses / 12</td>
          <td class="text-end font-mono"><?= lps($liq['decimo_tercero_prop']) ?></td>
        </tr>
        <tr>
          <td>Décimo Cuarto Mes Proporcional</td>
          <td style="color:var(--text-3);font-size:.8rem"><?= round($liq['meses_anio_actual'], 1) ?> meses / 12</td>
          <td class="text-end font-mono"><?= lps($liq['decimo_cuarto_prop']) ?></td>
        </tr>
        <?php if ($liq['preaviso'] > 0): ?>
        <tr>
          <td>Preaviso</td>
          <td style="color:var(--text-3);font-size:.8rem">Art. 116 CT</td>
          <td class="text-end font-mono"><?= lps($liq['preaviso']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($liq['cesantia'] > 0): ?>
        <tr>
          <td>Auxilio de Cesantía</td>
          <td style="color:var(--text-3);font-size:.8rem">Art. 120 CT</td>
          <td class="text-end font-mono"><?= lps($liq['cesantia']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($liq['otros_conceptos'] > 0): ?>
        <tr>
          <td>Otros: <?= htmlspecialchars($liq['otros_descripcion'] ?? '') ?></td>
          <td></td>
          <td class="text-end font-mono"><?= lps($liq['otros_conceptos']) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="fw-bold" style="background:var(--surface-2)">
          <td colspan="2">TOTAL A PAGAR</td>
          <td class="text-end font-mono" style="font-size:1.1rem;color:var(--proscala)"><?= lps($liq['total']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<?php if ($liq['notas']): ?>
<div class="card mb-3">
  <div class="card-body">
    <strong>Notas:</strong> <?= nl2br(htmlspecialchars($liq['notas'])) ?>
  </div>
</div>
<?php endif; ?>

<?php $adjModulo = 'liquidaciones.php'; $adjId = $liq['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php endif; ?>

<!-- ── Modal Nueva/Editar Liquidación ──────────────────────────────── -->
<div class="modal fade" id="modal-nueva" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="modal-liq-title"><i class="fas fa-file-invoice"></i> Nueva Liquidación</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-liq">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar">
          <input type="hidden" name="id" id="liq-id" value="0">
          <div class="row g-3">

            <div class="col-md-6">
              <label class="form-label">Empleado *</label>
              <select name="empleado_id" id="liq-emp" class="form-select" required>
                <option value="">— Seleccione —</option>
                <?php foreach ($empleados as $e): ?>
                <option value="<?= $e['id'] ?>"
                  data-sueldo="<?= $e['sueldo_mensual'] ?>"
                  data-ingreso="<?= $e['fecha_ingreso'] ?>">
                  <?= htmlspecialchars($e['nombre']) ?> — <?= htmlspecialchars($e['cargo'] ?? '') ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label">Fecha de Salida *</label>
              <input type="text" name="fecha_salida" id="liq-fsal" class="form-control date-input" required>
            </div>

            <div class="col-md-3">
              <label class="form-label">Motivo *</label>
              <select name="motivo" id="liq-motivo" class="form-select" required>
                <option value="renuncia">Renuncia voluntaria</option>
                <option value="despido_injustificado">Despido injustificado</option>
                <option value="despido_justificado">Despido justificado</option>
                <option value="vencimiento_contrato">Vencimiento de contrato</option>
                <option value="mutuo_acuerdo">Mutuo acuerdo</option>
                <option value="fallecimiento">Fallecimiento</option>
              </select>
            </div>

            <!-- Campos ocultos auto-calculados -->
            <input type="hidden" name="sueldo_mensual"    id="liq-sueldo">
            <input type="hidden" name="anios_servicio"    id="liq-anios">
            <input type="hidden" name="meses_anio_actual" id="liq-meses">

            <!-- Panel de resultado auto-cálculo -->
            <div class="col-12" id="liq-calc-panel" style="display:none">
              <div class="alert alert-info py-2 mb-0">
                <div class="row g-2 text-center" style="font-size:.8rem">
                  <div class="col-md-2"><div class="fw-bold font-mono" id="cv-sueldo">—</div><div>Sueldo mensual</div></div>
                  <div class="col-md-2"><div class="fw-bold font-mono" id="cv-anios">—</div><div>Antigüedad</div></div>
                  <div class="col-md-2"><div class="fw-bold font-mono" id="cv-vacpend">—</div><div>Días vac. pend.</div></div>
                  <div class="col-md-2"><div class="fw-bold font-mono" id="cv-preaviso">—</div><div>Preaviso</div></div>
                  <div class="col-md-2"><div class="fw-bold font-mono" id="cv-cesantia">—</div><div>Cesantía</div></div>
                  <div class="col-md-2"><div class="fw-bold font-mono text-success" id="cv-total">—</div><div>Total estimado</div></div>
                </div>
              </div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Días Salario Pendiente</label>
              <input type="number" name="dias_pendientes" id="liq-diaspend" class="form-control" min="0" step="0.5" value="0">
              <div class="form-text">Días trabajados no pagados aún</div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Días Vacación Pendiente</label>
              <input type="number" name="dias_vacaciones" id="liq-diasvac" class="form-control" min="0" step="0.5" value="0">
              <div class="form-text">Se auto-calcula (editable)</div>
            </div>

            <div class="col-md-3">
              <label class="form-label">Otros Conceptos</label>
              <input type="number" name="otros_conceptos" id="liq-otros" class="form-control" min="0" step="0.01" value="0">
            </div>

            <div class="col-md-3">
              <label class="form-label">Descripción Otros</label>
              <input type="text" name="otros_descripcion" class="form-control" placeholder="Bonificación, etc.">
            </div>

            <div class="col-12">
              <label class="form-label">Notas</label>
              <textarea name="notas" class="form-control" rows="2" placeholder="Observaciones adicionales…"></textarea>
            </div>

          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-outline-secondary" id="btn-recalc" onclick="autoCalcular()">
          <i class="fas fa-calculator"></i> Recalcular
        </button>
        <button type="button" class="btn-proscala" onclick="guardarLiq()">
          <i class="fas fa-save"></i> Guardar
        </button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
initDataTable('#tbl-liq');

const liqEmp    = document.getElementById('liq-emp');
const liqFsal   = document.getElementById('liq-fsal');
const liqMotivo = document.getElementById('liq-motivo');

function fmt(v) { return 'L. ' + parseFloat(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}); }

function triggerCalc() {
  const empId = liqEmp?.value;
  const fsal  = liqFsal?.value;
  if (empId && fsal) autoCalcular();
}

liqEmp?.addEventListener('change', triggerCalc);
liqFsal?.addEventListener('change', triggerCalc);
liqMotivo?.addEventListener('change', triggerCalc);
document.getElementById('liq-diaspend')?.addEventListener('input', triggerCalc);
document.getElementById('liq-diasvac')?.addEventListener('input', triggerCalc);
document.getElementById('liq-otros')?.addEventListener('input', triggerCalc);

async function autoCalcular() {
  const empId  = liqEmp?.value;
  const fsal   = liqFsal?.value;
  const motivo = liqMotivo?.value;
  if (!empId || !fsal) return;

  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
  fd.append('_action','calcular');
  fd.append('empleado_id', empId);
  fd.append('fecha_salida', fsal);
  fd.append('motivo', motivo);

  const r = await post('liquidaciones.php', fd);
  if (!r.success) { Toast.show(r.message,'error'); return; }

  document.getElementById('liq-sueldo').value = r.sueldo_mensual;
  document.getElementById('liq-anios').value  = r.anios_servicio;
  document.getElementById('liq-meses').value  = r.meses_anio_actual;

  // Auto-set días vacación si aún no modificado
  const dv = document.getElementById('liq-diasvac');
  if (dv.dataset.userModified !== '1') dv.value = r.dias_vacaciones;

  // Re-run totals with user overrides
  const diaspend = parseFloat(document.getElementById('liq-diaspend').value)||0;
  const diasvac  = parseFloat(dv.value)||0;
  const otros    = parseFloat(document.getElementById('liq-otros').value)||0;
  const sueldo   = r.sueldo_mensual;
  const diario   = sueldo / 30;
  const meses    = r.meses_anio_actual;

  const salPend  = diaspend * diario;
  const vacPend  = diasvac  * diario;
  const d13      = (meses / 12) * sueldo;
  const d14      = (meses / 12) * sueldo;

  // preaviso & cesantia from server (they depend on motivo & anios)
  const preaviso = r.preaviso;
  const cesantia = r.cesantia;

  const total = salPend + vacPend + d13 + d14 + preaviso + cesantia + otros;

  document.getElementById('cv-sueldo').textContent   = fmt(sueldo);
  document.getElementById('cv-anios').textContent    = (r.anios_servicio||0).toFixed(1) + ' años';
  document.getElementById('cv-vacpend').textContent  = parseFloat(dv.value).toFixed(1) + ' días';
  document.getElementById('cv-preaviso').textContent = fmt(preaviso);
  document.getElementById('cv-cesantia').textContent = fmt(cesantia);
  document.getElementById('cv-total').textContent    = fmt(total);
  document.getElementById('liq-calc-panel').style.display = '';
}

document.getElementById('liq-diasvac')?.addEventListener('input', function() {
  this.dataset.userModified = '1';
});

async function guardarLiq() {
  const fd = new FormData(document.getElementById('form-liq'));
  const r  = await post('liquidaciones.php', fd);
  if (r.success) {
    Toast.show(r.message,'success');
    setTimeout(()=>Nav.ir('liquidaciones.php?action=ver&id='+r.id), 800);
  } else Toast.show(r.message,'error');
}

async function editarLiq(id) {
  const r = await fetch('liquidaciones.php?action=ver&id='+id);
  // Redirect to open modal — fetch the record and populate
  const fd = new FormData();
  fd.append('_action','get_record'); fd.append('id', id);
  // Simpler: navigate to list and open modal via URL param
  Nav.ir('liquidaciones.php?edit='+id);
}

async function cambiarEstLiq(id, estado) {
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action','cambiar_estado'); fd.append('id',id); fd.append('estado',estado);
  const r = await post('liquidaciones.php', fd);
  if (r.success) { Toast.show(r.message,'success'); setTimeout(()=>Nav.recargar(),800); }
  else Toast.show(r.message,'error');
}

// Handle edit mode from URL
(function() {
  const urlParams = new URLSearchParams(window.location.search);
  const editId = urlParams.get('edit');
  if (!editId) return;
  // Fetch the record data via a lightweight approach — hidden in table
  // We'll just open the modal; pre-fill via data attributes in list rows
  // Actually we need an API call — use a simpler approach
  fetch('liquidaciones.php?_json=1&id='+editId)
    .then(r=>r.json()).then(d=>{
      if (!d.success) return;
      const rec = d.record;
      document.getElementById('modal-liq-title').innerHTML = '<i class="fas fa-pen"></i> Editar Liquidación';
      document.getElementById('liq-id').value         = rec.id;
      document.getElementById('liq-emp').value         = rec.empleado_id;
      $('#liq-emp').trigger('change');
      document.getElementById('liq-fsal').value        = rec.fecha_salida;
      document.getElementById('liq-motivo').value      = rec.motivo;
      $('#liq-motivo').trigger('change');
      document.getElementById('liq-sueldo').value      = rec.sueldo_mensual;
      document.getElementById('liq-anios').value       = rec.anios_servicio;
      document.getElementById('liq-meses').value       = rec.meses_anio_actual;
      document.getElementById('liq-diaspend').value    = rec.dias_pendientes;
      document.getElementById('liq-diasvac').value     = rec.dias_vacaciones;
      document.getElementById('liq-otros').value       = rec.otros_conceptos;
      document.querySelector('[name=otros_descripcion]').value = rec.otros_descripcion||'';
      document.querySelector('[name=notas]').value     = rec.notas||'';
      const modal = new bootstrap.Modal(document.getElementById('modal-nueva'));
      modal.show();
      autoCalcular();
    });
})();
JS;
include __DIR__ . '/includes/footer.php';
?>

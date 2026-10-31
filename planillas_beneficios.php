<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Planillas de Beneficios';

$config = getAllConfig($pdo);
$tipos  = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    // El beneficio se reparte por defecto según el % de salario de cada empleado
    $copiarDist = $pdo->prepare("INSERT INTO planilla_beneficios_detalle_proyectos (detalle_id,proyecto_id,porcentaje)
        SELECT pbd.id, ep.proyecto_id, ep.pct_salario
        FROM planilla_beneficios_detalle pbd JOIN empleado_proyectos ep ON ep.empleado_id = pbd.empleado_id
        WHERE pbd.beneficio_id=? AND ep.pct_salario > 0");
    $planillaEditable = function (int $pid) use ($pdo): void {
        $s = $pdo->prepare("SELECT estado FROM planilla_beneficios WHERE id=?");
        $s->execute([$pid]);
        $estado = $s->fetchColumn();
        if ($estado === false) jsonErr('Planilla no encontrada.');
        if ($estado === 'pagada') jsonErr('La planilla ya está pagada; no se puede modificar la distribución.');
    };

    if ($_act === 'guardar_distribucion') {
        $pid = (int)$_POST['id'];
        $did = (int)$_POST['detalle_id'];
        $planillaEditable($pid);
        $chk = $pdo->prepare("SELECT id FROM planilla_beneficios_detalle WHERE id=? AND beneficio_id=?");
        $chk->execute([$did, $pid]);
        if (!$chk->fetch()) jsonErr('Fila de planilla no encontrada.');
        $dist = parseDistribucion($_POST['dist'] ?? [], ['porcentaje']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM planilla_beneficios_detalle_proyectos WHERE detalle_id=?")->execute([$did]);
            $ins = $pdo->prepare("INSERT INTO planilla_beneficios_detalle_proyectos (detalle_id,proyecto_id,porcentaje) VALUES (?,?,?)");
            foreach ($dist as $r) $ins->execute([$did, $r['proyecto_id'], $r['porcentaje']]);
            $pdo->commit();
            jsonOk([], 'Distribución guardada.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr('Error: ' . $e->getMessage());
        }
    }

    if ($_act === 'aplicar_distribucion') {
        $pid = (int)$_POST['id'];
        $planillaEditable($pid);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE bp FROM planilla_beneficios_detalle_proyectos bp
                JOIN planilla_beneficios_detalle pbd ON bp.detalle_id = pbd.id WHERE pbd.beneficio_id=?")->execute([$pid]);
            $copiarDist->execute([$pid]);
            $pdo->commit();
            jsonOk([], 'Distribución de los empleados aplicada a la planilla.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr('Error: ' . $e->getMessage());
        }
    }

    if ($_act === 'crear') {
        $tipo  = $_POST['tipo'] ?? '';
        $anio  = (int)$_POST['anio'];
        $fPago = $_POST['fecha_pago'] ?: null;
        $notas = trim($_POST['notas'] ?? '');

        if (!array_key_exists($tipo, $tipos)) jsonErr('Tipo de planilla inválido.');

        $chk = $pdo->prepare("SELECT id FROM planilla_beneficios WHERE tipo=? AND anio=?");
        $chk->execute([$tipo, $anio]);
        if ($chk->fetch()) jsonErr("Ya existe una planilla de {$tipos[$tipo]} para el año {$anio}.");

        $prefijo = $config['prefijo_pb'] ?? 'PB';
        $numero  = numeroProceso($pdo, 'planilla_beneficios', $prefijo, null, $anio);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO planilla_beneficios (numero,tipo,anio,fecha_pago,notas) VALUES (?,?,?,?,?)")
                ->execute([$numero, $tipo, $anio, $fPago, $notas]);
            $pid = $pdo->lastInsertId();

            $empleados = $pdo->query("SELECT id, sueldo_mensual FROM empleados WHERE activo=1 ORDER BY apellidos,nombre")->fetchAll();
            $ins = $pdo->prepare("INSERT INTO planilla_beneficios_detalle (beneficio_id,empleado_id,sueldo_mensual,deduccion,neto) VALUES (?,?,?,0,?)");
            $totBruto = $totNeto = 0;
            foreach ($empleados as $e) {
                $sal = (float)$e['sueldo_mensual'];
                $ins->execute([$pid, $e['id'], $sal, $sal]);
                $totBruto += $sal; $totNeto += $sal;
            }
            $pdo->prepare("UPDATE planilla_beneficios SET total_bruto=?,total_neto=? WHERE id=?")
                ->execute([$totBruto, $totNeto, $pid]);
            $copiarDist->execute([$pid]);
            $pdo->commit();
            jsonOk(['id' => $pid], 'Planilla de beneficios creada.');
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
            $upd = $pdo->prepare("UPDATE planilla_beneficios_detalle SET deduccion=?,neto=? WHERE id=? AND beneficio_id=?");
            $totBruto = $totDed = $totNeto = 0;
            foreach ($rows as $row) {
                $ded    = (float)str_replace(',', '', $row['deduccion']);
                $sueldo = (float)str_replace(',', '', $row['sueldo_mensual']);
                $neto   = max(0, $sueldo - $ded);
                $upd->execute([$ded, $neto, (int)$row['id'], $pid]);
                $totBruto += $sueldo; $totDed += $ded; $totNeto += $neto;
            }
            $pdo->prepare("UPDATE planilla_beneficios SET total_bruto=?,total_deducciones=?,total_neto=? WHERE id=?")
                ->execute([$totBruto, $totDed, $totNeto, $pid]);
            $pdo->commit();
            jsonOk([], 'Detalle guardado correctamente.');
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
        $s = $pdo->prepare("UPDATE planilla_beneficios SET estado=? WHERE id=? AND estado=?");
        $s->execute([$estado, $pid, $estado === 'pagada' ? 'aprobada' : 'borrador']);
        if (!$s->rowCount()) jsonErr($estado === 'pagada' ? 'Solo una planilla aprobada se marca como pagada.' : 'La planilla ya no está pendiente de aprobación.');
        jsonOk([], $estado === 'aprobada' ? 'Planilla autorizada.' : 'Planilla marcada como pagada.');
    }

    if ($_act === 'eliminar') {
        $pdo->prepare("DELETE FROM planilla_beneficios WHERE id=?")->execute([(int)$_POST['id']]);
        jsonOk([], 'Planilla eliminada.');
    }

    jsonErr('Acción desconocida.');
}

// ── CARGA ──────────────────────────────────────────────────────────
$periodo = null;
$detalle = [];
$dist = $proyMap = $resumenProy = [];
if ($action === 'ver' && $id) {
    $s = $pdo->prepare("SELECT * FROM planilla_beneficios WHERE id=?");
    $s->execute([$id]); $periodo = $s->fetch();
    if (!$periodo) { header('Location: planillas_beneficios.php'); exit; }
    $s2 = $pdo->prepare("SELECT pbd.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo, e.banco
        FROM planilla_beneficios_detalle pbd JOIN empleados e ON pbd.empleado_id = e.id
        WHERE pbd.beneficio_id=? ORDER BY e.apellidos,e.nombre");
    $s2->execute([$id]); $detalle = $s2->fetchAll();
    $dist    = cargarDistribucion($pdo, 'planilla_beneficios_detalle_proyectos', array_column($detalle, 'id'));
    $proyMap = proyectosMap($pdo);
    [, $resumenProy] = distribuirBeneficios($detalle, $dist);
}

$periodos = [];
if ($action === 'list') {
    $periodos = $pdo->query("SELECT * FROM planilla_beneficios pb WHERE " . filtroHistorico('pb') . " ORDER BY anio DESC, tipo")->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-gift"></i> Planillas de Beneficios</h1>
  <?php if ($action === 'list'): ?>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-crear">
    <i class="fas fa-plus"></i> Nueva Planilla
  </button>
  <?php else: ?>
  <a href="planillas_beneficios.php" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card"><div class="card-body p-0">
  <table id="tbl-pb" class="table-proscala w-100">
    <thead>
      <tr><th>Número</th><th>Tipo</th><th>Año</th><th>Fecha de Pago</th><th>Bruto</th><th>Deducciones</th><th>Neto</th><th>Estado</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($periodos as $p): ?>
      <tr>
        <td class="font-mono"><?= htmlspecialchars($p['numero']) ?><?= badgeHistorico($p) ?></td>
        <td><?= $tipos[$p['tipo']] ?? ucfirst($p['tipo']) ?></td>
        <td><?= $p['anio'] ?></td>
        <td><?= fmtFecha($p['fecha_pago'] ?? '') ?></td>
        <td class="font-mono"><?= lps($p['total_bruto']) ?></td>
        <td class="font-mono text-danger"><?= lps($p['total_deducciones']) ?></td>
        <td class="font-mono fw-bold text-success"><?= lps($p['total_neto']) ?></td>
        <td><?= estadoBadge($p['estado']) ?></td>
        <td>
          <a href="?action=ver&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2"><i class="fas fa-eye"></i></a>
          <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1"
            onclick="deleteRecord('planillas_beneficios.php',<?= $p['id'] ?>,()=>Nav.recargar())">
            <i class="fas fa-trash"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$periodos): ?>
      <tr><td colspan="9" class="text-center text-muted py-4">No hay planillas de beneficios registradas</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div></div>

<?php elseif ($action === 'ver' && $periodo): ?>
<?php $tipoLabel = $tipos[$periodo['tipo']] ?? ucfirst($periodo['tipo']); ?>
<?php $flujoModulo = 'planillas_beneficios.php'; $flujoId = $periodo['id']; include __DIR__ . '/includes/flujo_aprobacion.php'; ?>
<?php $cadModulo = 'planillas_beneficios.php'; $cadId = $periodo['id']; include __DIR__ . '/includes/cadena_proceso.php'; ?>

<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-gift"></i>
    <strong><?= htmlspecialchars($periodo['numero']) ?></strong> —
    <?= $tipoLabel ?> <?= $periodo['anio'] ?>
    <?= estadoBadge($periodo['estado']) ?>
    <div class="ms-auto d-flex gap-2">
      <?php // Aprobar: desde el panel del flujo (revisar y autorizar), arriba
      if ($periodo['estado'] === 'aprobada'): ?>
      <button class="btn btn-sm btn-info text-white" onclick="cambiarEstadoPb(<?= $id ?>,'pagada')">
        <i class="fas fa-money-bill"></i> Marcar Pagada
      </button>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>print.php?tipo=pb&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
      <a href="<?= BASE_URL ?>print.php?tipo=pbp&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-secondary"><i class="fas fa-diagram-project"></i> Por Proyecto</a>
      <?php if ($periodo['estado'] !== 'pagada'): ?>
      <button class="btn btn-sm btn-outline-secondary" onclick="aplicarDistribucion(<?= $id ?>)"
        title="Reemplaza la distribución de esta planilla por el % de salario configurado en cada empleado">
        <i class="fas fa-rotate"></i> Aplicar distribución de empleados
      </button>
      <?php endif; ?>
      <?php if (in_array($periodo['estado'], ['aprobada','pagada'])): ?>
      <a href="<?= BASE_URL ?>print.php?tipo=pbv&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-primary"><i class="fas fa-file-zipper"></i> Vouchers</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<p style="font-size:.84rem;color:var(--text-2);margin-bottom:1rem;">
  El salario mensual se obtiene del expediente de cada empleado. Ingrese la deducción por compromisos pendientes donde aplique. El neto se actualiza automáticamente.
</p>

<?php $editarPb = puedeEditar($pdo, 'planillas_beneficios.php', (int)$id);   // rangos superiores, no quien la creó ?>
<form id="form-detalle">
  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
  <input type="hidden" name="_action" value="guardar_detalle">
  <input type="hidden" name="id" value="<?= $id ?>">
  <fieldset <?= $editarPb === true ? '' : 'disabled' ?>>
  <div class="card"><div class="card-body p-0" style="overflow-x:auto;">
    <table class="table-proscala w-100">
      <thead>
        <tr>
          <th style="width:2.5rem">#</th>
          <th>Empleado</th>
          <th>Cargo</th>
          <th>Proyectos</th>
          <th class="text-end">Salario Mensual</th>
          <th style="width:12rem">Deducción (L.)</th>
          <th class="text-end">Neto a Pagar</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($detalle as $i => $d): ?>
        <tr>
          <td class="text-center text-muted"><?= $i + 1 ?></td>
          <td>
            <?= htmlspecialchars($d['emp_nombre']) ?>
            <a href="<?= BASE_URL ?>pdf_download.php?tipo=pbv&id=<?= $id ?>&emp_id=<?= $d['empleado_id'] ?>"
               target="_blank" title="Descargar voucher PDF" class="ms-1" style="color:var(--text-2);font-size:.75rem">
              <i class="fas fa-file-pdf"></i>
            </a>
          </td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td style="white-space:nowrap">
            <?php foreach ($dist[$d['id']] ?? [] as $dp): ?>
            <span class="badge bg-light text-dark border" style="font-size:.68rem"
              title="<?= htmlspecialchars(proyectoLabel($proyMap, (int)$dp['proyecto_id'], true)) ?>">
              <?= htmlspecialchars(proyectoLabel($proyMap, (int)$dp['proyecto_id'])) ?> <?= fmtPct($dp['porcentaje']) ?>%
            </span><br>
            <?php endforeach; ?>
            <?php if (empty($dist[$d['id']])): ?>
            <span class="text-muted" style="font-size:.72rem">Sin proyecto</span>
            <?php endif; ?>
            <?php if ($editarPb === true): ?>
            <button type="button" class="btn btn-sm btn-link p-0 ms-1" title="Editar distribución"
              onclick="editarDist(<?= $d['id'] ?>)"><i class="fas fa-pen" style="font-size:.7rem"></i></button>
            <?php endif; ?>
          </td>
          <td class="font-mono text-end"><?= lps($d['sueldo_mensual']) ?></td>
          <td>
            <input type="hidden" name="rows[<?= $i ?>][id]"             value="<?= $d['id'] ?>">
            <input type="hidden" name="rows[<?= $i ?>][sueldo_mensual]" value="<?= $d['sueldo_mensual'] ?>">
            <input type="number"  name="rows[<?= $i ?>][deduccion]"
              value="<?= $d['deduccion'] ?>" step="0.01" min="0" max="<?= $d['sueldo_mensual'] ?>"
              class="form-control form-control-sm text-end ded-input"
              data-sueldo="<?= $d['sueldo_mensual'] ?>" data-idx="<?= $i ?>"
              oninput="recalcRow(this)">
          </td>
          <td class="font-mono fw-bold text-success text-end neto-cell" id="neto-<?= $i ?>">
            <?= lps($d['neto']) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:var(--surface-2)">
          <td colspan="4" style="padding:8px 10px">TOTALES</td>
          <td class="font-mono text-end"   style="padding:8px 10px"><?= lps($periodo['total_bruto']) ?></td>
          <td class="font-mono text-danger text-end" style="padding:8px 10px" id="total-ded"><?= lps($periodo['total_deducciones']) ?></td>
          <td class="font-mono text-success text-end" style="padding:8px 10px" id="total-neto"><?= lps($periodo['total_neto']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div></div>
  </fieldset>

  <div class="mt-3 d-flex gap-2 align-items-center">
    <?php if ($editarPb === true): ?>
    <button type="button" class="btn-proscala" onclick="guardarDetallePb()">
      <i class="fas fa-save"></i> Guardar Detalle
    </button>
    <?php else: ?>
    <span class="text-muted" style="font-size:.82rem"><i class="fas fa-lock"></i> <?= htmlspecialchars($editarPb) ?></span>
    <?php endif; ?>
    <span id="save-status" style="font-size:.82rem;color:var(--text-3)"></span>
  </div>
</form>

<!-- Resumen por proyecto -->
<div class="card mt-3">
  <div class="card-header"><i class="fas fa-diagram-project"></i> Resumen por Proyecto
    <span class="text-muted ms-2" style="font-size:.78rem">(según lo guardado)</span></div>
  <div class="card-body p-0" style="overflow-x:auto;">
    <table class="table-proscala w-100" style="font-size:.85rem;">
      <thead>
        <tr><th>Proyecto</th><th class="text-center">Empleados</th><th class="text-end">Beneficio</th><th class="text-end">Deducciones</th><th class="text-end">Neto a Pagar</th></tr>
      </thead>
      <tbody>
        <?php $trB = ['sueldo_mensual'=>0,'deduccion'=>0,'neto'=>0];
        foreach ($resumenProy as $pid => $r): foreach ($trB as $k => $_) $trB[$k] += $r[$k]; ?>
        <tr>
          <td><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></td>
          <td class="text-center"><?= $r['empleados'] ?></td>
          <td class="font-mono text-end"><?= lps($r['sueldo_mensual']) ?></td>
          <td class="font-mono text-end text-danger"><?= lps($r['deduccion']) ?></td>
          <td class="font-mono text-end fw-bold text-success"><?= lps($r['neto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:700;background:var(--surface-2)">
          <td colspan="2" style="padding:8px 10px">TOTALES</td>
          <td class="font-mono text-end" style="padding:8px 10px"><?= lps($trB['sueldo_mensual']) ?></td>
          <td class="font-mono text-end text-danger" style="padding:8px 10px"><?= lps($trB['deduccion']) ?></td>
          <td class="font-mono text-end text-success" style="padding:8px 10px"><?= lps($trB['neto']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- Modal: Distribución por proyecto de un empleado -->
<div class="modal fade" id="modal-dist" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-diagram-project"></i> Distribución por Proyecto — <span id="dist-emp"></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-2" style="font-size:.8rem;">
          Porcentaje del beneficio que paga cada proyecto (por ejemplo, según los meses que el empleado trabajó en cada uno durante el año). Solo afecta a esta planilla.
        </p>
        <table class="table table-sm align-middle mb-1" style="font-size:.83rem;">
          <thead>
            <tr><th>Proyecto</th><th style="width:130px" class="text-end">% Beneficio</th><th style="width:50px"></th></tr>
          </thead>
          <tbody id="dist-body"></tbody>
          <tfoot>
            <tr>
              <td><span data-dist-msg></span></td>
              <td class="text-end font-mono fw-bold" data-tot="porcentaje"></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="distPb.add()">
          <i class="fas fa-plus"></i> Agregar proyecto
        </button>
        <div id="dist-preview" class="mt-3" style="font-size:.78rem;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="guardarDist()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php $adjModulo = 'planillas_beneficios.php'; $adjId = $periodo['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php endif; ?>

<!-- Modal: Nueva Planilla -->
<div class="modal fade" id="modal-crear" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-gift"></i> Nueva Planilla de Beneficios</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="form-crear">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="crear">
          <div class="row g-3">
            <div class="col-md-7">
              <label class="form-label">Tipo *</label>
              <select name="tipo" class="form-select" required>
                <option value="decimo_tercero">Décimo Tercer Mes</option>
                <option value="decimo_cuarto">Décimo Cuarto Mes</option>
              </select>
            </div>
            <div class="col-md-5">
              <label class="form-label">Año *</label>
              <input type="number" name="anio" class="form-control" value="<?= date('Y') ?>" min="2020" max="2040" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Fecha de Pago</label>
              <input type="text" name="fecha_pago" class="form-control date-input">
            </div>
            <div class="col-12">
              <label class="form-label">Notas</label>
              <textarea name="notas" class="form-control" rows="2" placeholder="Observaciones opcionales"></textarea>
            </div>
          </div>
          <div class="alert alert-info mt-3 mb-0" style="font-size:.82rem;">
            <i class="fas fa-info-circle"></i>
            Se incluirán todos los empleados activos con su salario mensual actual. Sin deducciones de IHSS, RAP ni ISR. Podrá ingresar deducciones individuales por compromisos pendientes.
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="crearPlanillaPb()">
          <i class="fas fa-plus"></i> Crear
        </button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
initDataTable('#tbl-pb');

function fmt(v) {
  return 'L. ' + parseFloat(v||0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}

function recalcRow(input) {
  const idx    = input.dataset.idx;
  const sueldo = parseFloat(input.dataset.sueldo) || 0;
  const ded    = parseFloat(input.value) || 0;
  document.getElementById('neto-' + idx).textContent = fmt(Math.max(0, sueldo - ded));

  let totBruto = 0, totDed = 0, totNeto = 0;
  document.querySelectorAll('.ded-input').forEach(el => {
    const s = parseFloat(el.dataset.sueldo) || 0;
    const d = parseFloat(el.value) || 0;
    totBruto += s; totDed += d; totNeto += Math.max(0, s - d);
  });
  document.getElementById('total-ded').textContent  = fmt(totDed);
  document.getElementById('total-neto').textContent = fmt(totNeto);
}

async function guardarDetallePb() {
  const status = document.getElementById('save-status');
  const btn    = document.querySelector('[onclick="guardarDetallePb()"]');
  btn.disabled = true; status.textContent = 'Guardando…';
  try {
    const r = await post('planillas_beneficios.php', new FormData(document.getElementById('form-detalle')));
    if (r.success) { Toast.show(r.message, 'success'); status.textContent = '✓ Guardado'; setTimeout(() => status.textContent = '', 3000); }
    else           { Toast.show(r.message, 'error'); status.textContent = ''; }
  } finally { btn.disabled = false; }
}

async function cambiarEstadoPb(id, estado) {
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'cambiar_estado'); fd.append('id', id); fd.append('estado', estado);
  const r = await post('planillas_beneficios.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  else Toast.show(r.message, 'error');
}

async function crearPlanillaPb() {
  const r = await post('planillas_beneficios.php', new FormData(document.getElementById('form-crear')));
  if (r.success) {
    Toast.show(r.message, 'success');
    setTimeout(() => Nav.ir('planillas_beneficios.php?action=ver&id=' + r.id), 1000);
  } else { Toast.show(r.message, 'error'); }
}
JS;

if ($periodo) {
    $pbFilas = [];
    foreach ($detalle as $i => $d) {
        $pbFilas[$d['id']] = ['nombre' => $d['emp_nombre'], 'idx' => $i, 'dist' => $dist[$d['id']] ?? []];
    }
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $extraJs .= 'const PB_ID = ' . (int)$periodo['id'] . ";\n"
              . 'const PROYECTOS = ' . json_encode(array_values($proyMap), $jsonFlags) . ";\n"
              . 'const PB_FILAS = ' . json_encode($pbFilas ?: new stdClass, $jsonFlags) . ";\n";
    $extraJs .= <<<'JS'
const escH = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let distDid = 0, distModal = null, detalleDirty = false;
document.querySelectorAll('.ded-input').forEach(i => i.addEventListener('input', () => detalleDirty = true));

const distPb = new DistEditor(document.getElementById('dist-body'), {
  proyectos: PROYECTOS, campos: ['porcentaje'], onChange: previewDist
});

function editarDist(did) {
  distDid = did;
  document.getElementById('dist-emp').textContent = PB_FILAS[did].nombre;
  distPb.set(PB_FILAS[did].dist);
  distModal = distModal || new bootstrap.Modal(document.getElementById('modal-dist'));
  distModal.show();
}

// Usa los valores en pantalla (incluye deducciones aún no guardadas)
function previewDist(ed) {
  const f = PB_FILAS[distDid], el = document.getElementById('dist-preview');
  const rows = ed.rows();
  if (!f || !rows.length) { el.innerHTML = ''; return; }
  const inp = document.querySelector(`.ded-input[data-idx="${f.idx}"]`);
  const sueldo = parseFloat(inp?.dataset.sueldo) || 0, ded = parseFloat(inp?.value) || 0;
  const cod = id => (PROYECTOS.find(p => String(p.id) === String(id)) || {}).codigo || '';
  el.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Proyecto</th>'
    + '<th class="text-end">Beneficio</th><th class="text-end">Neto</th></tr></thead><tbody>'
    + rows.map(r => `<tr><td>${escH(cod(r.proyecto_id))}</td>`
      + `<td class="text-end font-mono">${fmt(sueldo * r.porcentaje / 100)}</td>`
      + `<td class="text-end font-mono">${fmt(Math.max(0, sueldo - ded) * r.porcentaje / 100)}</td></tr>`).join('')
    + '</tbody></table>';
}

async function guardarDist() {
  const err = distPb.error();
  if (err) { Toast.show(err, 'error'); return; }
  if (detalleDirty && !await confirmar('Hay deducciones sin guardar. Si continúa se perderán.', { titulo: 'Cambios sin guardar', aceptar: 'Continuar', peligro: true })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'guardar_distribucion');
  fd.append('id', PB_ID);
  fd.append('detalle_id', distDid);
  distPb.rows().forEach((r, i) => Object.entries(r).forEach(([k, v]) => fd.append(`dist[${i}][${k}]`, v)));
  const r = await post('planillas_beneficios.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 600); }
  else Toast.show(r.message, 'error');
}

async function aplicarDistribucion(pid) {
  if (!await confirmar('Se reemplazará la distribución de TODOS los empleados de esta planilla por el % de salario configurado en su expediente.', { titulo: 'Aplicar distribución', aceptar: 'Reemplazar', peligro: true })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'aplicar_distribucion');
  fd.append('id', pid);
  const r = await post('planillas_beneficios.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  else Toast.show(r.message, 'error');
}
JS;
}
include __DIR__ . '/includes/footer.php';
?>

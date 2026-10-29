<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Planillas de Sueldos';

// ── CSV Credimpulsa export ─────────────────────────────────────────
if ($action === 'export_credimpulsa' && $id) {
    $mesesExport = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $per = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id=?");
    $per->execute([$id]); $per = $per->fetch();
    if (!$per) { http_response_code(404); die('Período no encontrado.'); }
    $mesNom  = $mesesExport[$per['mes'] - 1];
    $quinNom = esPlanillaMensual($per) ? 'Mensual' : ($per['quincena'] === 'primera' ? '1ra_Quincena' : '2da_Quincena');
    $filename = "Credimpulsa_{$mesNom}_{$per['anio']}_{$quinNom}.csv";

    $rows = $pdo->prepare("SELECT CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre,
        e.identidad, e.cargo, pd.credimpulsa
        FROM planilla_detalle pd
        JOIN empleados e ON pd.empleado_id = e.id
        WHERE pd.periodo_id = ? AND pd.credimpulsa > 0
        ORDER BY e.apellidos, e.nombre");
    $rows->execute([$id]); $rows = $rows->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel
    fputcsv($out, ['Período', "{$mesNom} {$per['anio']} — {$quinNom}"]);
    fputcsv($out, ['Fecha de Pago', $per['fecha_pago'] ?? '']);
    fputcsv($out, []);
    fputcsv($out, ['#', 'Empleado', 'N° Identidad', 'Cargo', 'Deducción Credimpulsa (L.)']);
    $i = 1; $total = 0;
    foreach ($rows as $r) {
        fputcsv($out, [
            $i++,
            $r['empleado_nombre'],
            $r['identidad'] ?? '',
            $r['cargo'] ?? '',
            number_format((float)$r['credimpulsa'], 2, '.', ''),
        ]);
        $total += (float)$r['credimpulsa'];
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', 'TOTAL', number_format($total, 2, '.', '')]);
    fclose($out);
    exit;
}

$config = getAllConfig($pdo);
$meses  = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    // Copia la distribución por defecto de cada empleado a las filas del período
    $copiarDist = $pdo->prepare("INSERT INTO planilla_detalle_proyectos (detalle_id,proyecto_id,pct_salario,pct_patronal)
        SELECT pd.id, ep.proyecto_id, ep.pct_salario, ep.pct_patronal
        FROM planilla_detalle pd JOIN empleado_proyectos ep ON ep.empleado_id = pd.empleado_id
        WHERE pd.periodo_id=?");
    $periodoEditable = function (int $pid) use ($pdo): void {
        $s = $pdo->prepare("SELECT estado FROM planilla_periodos WHERE id=?");
        $s->execute([$pid]);
        $estado = $s->fetchColumn();
        if ($estado === false) jsonErr('Planilla no encontrada.');
        if ($estado === 'pagada') jsonErr('La planilla ya está pagada; no se puede modificar la distribución.');
    };

    if ($_act === 'guardar_distribucion') {
        $pid  = (int)$_POST['periodo_id'];
        $did  = (int)$_POST['detalle_id'];
        $periodoEditable($pid);
        $chk = $pdo->prepare("SELECT id FROM planilla_detalle WHERE id=? AND periodo_id=?");
        $chk->execute([$did, $pid]);
        if (!$chk->fetch()) jsonErr('Fila de planilla no encontrada.');
        $dist = parseDistribucion($_POST['dist'] ?? [], ['pct_salario', 'pct_patronal']);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM planilla_detalle_proyectos WHERE detalle_id=?")->execute([$did]);
            $ins = $pdo->prepare("INSERT INTO planilla_detalle_proyectos (detalle_id,proyecto_id,pct_salario,pct_patronal) VALUES (?,?,?,?)");
            foreach ($dist as $r) $ins->execute([$did, $r['proyecto_id'], $r['pct_salario'], $r['pct_patronal']]);
            $pdo->commit();
            jsonOk([], 'Distribución guardada.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr('Error: ' . $e->getMessage());
        }
    }

    if ($_act === 'aplicar_distribucion') {
        $pid = (int)$_POST['periodo_id'];
        $periodoEditable($pid);
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE pdp FROM planilla_detalle_proyectos pdp
                JOIN planilla_detalle pd ON pdp.detalle_id = pd.id WHERE pd.periodo_id=?")->execute([$pid]);
            $copiarDist->execute([$pid]);
            $pdo->commit();
            registrarAuditoria($pdo, 'UPDATE', 'planilla_periodos', $pid, 'Distribución por proyecto reaplicada desde empleados');
            jsonOk([], 'Distribución de los empleados aplicada a la planilla.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr('Error: ' . $e->getMessage());
        }
    }

    if ($_act === 'generar') {
        $anio      = (int)$_POST['anio'];
        $mes       = (int)$_POST['mes'];
        $tipo      = ($_POST['tipo'] ?? '') === 'mensual' ? 'mensual' : 'quincenal';
        // La mensual ocupa la 'segunda' (índice único año + mes + quincena; ver config/functions.php)
        $quincena  = $tipo === 'mensual' ? 'segunda' : (($_POST['quincena'] ?? '') === 'segunda' ? 'segunda' : 'primera');
        $fInicio   = $_POST['fecha_inicio'];
        $fFin      = $_POST['fecha_fin'];
        $fPago     = $_POST['fecha_pago'] ?: null;
        if ($mes < 1 || $mes > 12 || !strtotime($fInicio) || !strtotime($fFin) || $fFin < $fInicio) jsonErr('Revise el mes y las fechas del período.');

        // Un mes tiene una planilla mensual o sus dos quincenas, no ambas
        $chk = $pdo->prepare("SELECT tipo, quincena FROM planilla_periodos WHERE anio=? AND mes=?");
        $chk->execute([$anio, $mes]);
        foreach ($chk->fetchAll() as $ya) {
            if ($tipo === 'mensual' || $ya['tipo'] === 'mensual') {
                jsonErr('Ese mes ya tiene ' . (esPlanillaMensual($ya) ? 'una planilla mensual' : 'planilla quincenal') . ': un mes se paga con una planilla mensual o con sus dos quincenas, no ambas.');
            }
            if ($ya['quincena'] === $quincena) jsonErr('Ya existe una planilla para ese período.');
        }
        $periodo = ['tipo' => $tipo, 'quincena' => $quincena, 'anio' => $anio, 'mes' => $mes, 'fecha_inicio' => $fInicio, 'fecha_fin' => $fFin];
        $factor  = factorDeduccionesPlanilla($periodo);

        $pdo->beginTransaction();
        try {
            $numero = numeroProceso($pdo, 'planilla_periodos', getConfig($pdo, 'prefijo_pl', 'PL'), null, $anio);
            $pdo->prepare("INSERT INTO planilla_periodos (numero,anio,mes,quincena,tipo,fecha_inicio,fecha_fin,fecha_pago,estado)
                VALUES (?,?,?,?,?,?,?,?,'borrador')")->execute([$numero,$anio,$mes,$quincena,$tipo,$fInicio,$fFin,$fPago]);
            $periodoId = $pdo->lastInsertId();

            $empleados = $pdo->query("SELECT * FROM empleados WHERE activo=1 AND tipo_contrato != 'servicio'")->fetchAll();
            $totalBruto = $totalDed = $totalNeto = 0;

            foreach ($empleados as $e) {
                // Días del período (15 o 30), o desde su ingreso si entró durante el período
                $dias = diasTrabajadosPlanilla($periodo, $e['fecha_ingreso'] ?? null);
                if (!$dias) continue;   // ingresa después del período
                $bruto = round($e['sueldo_mensual'] / 30 * $dias, 2);
                $ihssE = $e['aplica_ihss']        ? (float)($e['ded_ihss_empleado'] ?? 0) * $factor : 0;
                $ihssP = $e['aplica_ihss']        ? (float)($e['ded_ihss_patronal'] ?? 0) * $factor : 0;
                $rapE  = $e['aplica_rap']          ? (float)($e['ded_rap']           ?? 0) * $factor : 0;
                $rapP  = $rapE;
                $isr   = $e['aplica_isr']          ? (float)($e['ded_isr']           ?? 0) * $factor : 0;
                $cred  = $e['aplica_credimpulsa']  ? (float)($e['ded_credimpulsa']   ?? 0) * $factor : 0;
                $ded   = $ihssE + $rapE + $isr + $cred;
                $neto  = round($bruto - $ded, 2);

                $pdo->prepare("INSERT INTO planilla_detalle
                    (periodo_id,empleado_id,dias_trabajados,sueldo_quincenal,total_bruto,
                    ihss_empleado,rap_empleado,isr,credimpulsa,total_deducciones,sueldo_neto,
                    ihss_patronal,rap_patronal)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$periodoId,$e['id'],$dias,$bruto,$bruto,$ihssE,$rapE,$isr,$cred,$ded,$neto,$ihssP,$rapP]);

                $totalBruto += $bruto;
                $totalDed   += $ded;
                $totalNeto  += $neto;
            }

            $pdo->prepare("UPDATE planilla_periodos SET total_bruto=?,total_deducciones=?,total_neto=?,estado='procesada' WHERE id=?")
                ->execute([$totalBruto,$totalDed,$totalNeto,$periodoId]);
            $copiarDist->execute([$periodoId]);

            $pdo->commit();
            registrarAuditoria($pdo,'INSERT','planilla_periodos',$periodoId,'Planilla ' . $tipo . ' generada');
            jsonOk(['id' => $periodoId], 'Planilla ' . $tipo . ' generada correctamente.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr('Error: ' . $e->getMessage());
        }
    }

    if ($_act === 'guardar_credimpulsa') {
        $pid  = (int)$_POST['periodo_id'];
        $rows = $_POST['rows'] ?? [];
        $sp = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id=?");
        $sp->execute([$pid]);
        if (!$per = $sp->fetch()) jsonErr('Planilla no encontrada.');
        $maxDias = diasPeriodoPlanilla($per);
        $factor  = factorDeduccionesPlanilla($per);
        $pdo->beginTransaction();
        try {
            // Salario mensual por fila para recalcular bruto según días trabajados
            $rowIds  = array_map(fn($r) => (int)$r['id'], $rows);
            $salMap  = [];
            if ($rowIds) {
                $marks = implode(',', array_fill(0, count($rowIds), '?'));
                $rs = $pdo->prepare("SELECT pd.id, e.sueldo_mensual FROM planilla_detalle pd JOIN empleados e ON pd.empleado_id=e.id WHERE pd.id IN ($marks)");
                $rs->execute($rowIds);
                foreach ($rs->fetchAll() as $sr) $salMap[$sr['id']] = (float)$sr['sueldo_mensual'];
            }

            $upd = $pdo->prepare("UPDATE planilla_detalle
                SET dias_trabajados=?,
                    sueldo_quincenal=?, total_bruto=?,
                    ihss_empleado=?, ihss_patronal=?,
                    rap_empleado=?, rap_patronal=?,
                    isr=?, credimpulsa=?,
                    total_deducciones=?, sueldo_neto=?,
                    observacion=?
                WHERE id=? AND periodo_id=?");
            // Las deducciones del empleado son por quincena: de una mensual se guarda la mitad
            $syncEmp = $pdo->prepare("UPDATE empleados e
                JOIN planilla_detalle pd ON e.id = pd.empleado_id
                SET e.ded_ihss_empleado=ROUND(pd.ihss_empleado/$factor,2),
                    e.ded_ihss_patronal=ROUND(pd.ihss_patronal/$factor,2),
                    e.ded_rap=ROUND(pd.rap_empleado/$factor,2),
                    e.ded_isr=ROUND(pd.isr/$factor,2),
                    e.ded_credimpulsa=ROUND(pd.credimpulsa/$factor,2)
                WHERE pd.id=?");
            foreach ($rows as $r) {
                $p    = fn(string $k) => max(0, (float)str_replace(',', '', $r[$k] ?? '0'));
                $id   = (int)$r['id'];
                $dias = max(1, min($maxDias, (int)($r['dias_trabajados'] ?? $maxDias)));
                $sal  = $salMap[$id] ?? 0;
                $bruto = round($sal / 30 * $dias, 2);
                $ihssE = $p('ihss_empleado'); $ihssP = $p('ihss_patronal');
                $rap   = $p('rap_empleado');  $isr   = $p('isr'); $cred = $p('credimpulsa');
                $ded   = $ihssE + $rap + $isr + $cred;
                $neto  = round($bruto - $ded, 2);
                $obs   = mb_substr(trim($r['observacion'] ?? ''), 0, 500);
                $upd->execute([$dias, $bruto, $bruto, $ihssE, $ihssP, $rap, $rap, $isr, $cred, $ded, $neto, $obs, $id, $pid]);
                $syncEmp->execute([$id]);
            }
            // Recalculate period totals
            $pdo->prepare("UPDATE planilla_periodos pp
                SET total_bruto       = (SELECT SUM(total_bruto)       FROM planilla_detalle WHERE periodo_id=?),
                    total_deducciones = (SELECT SUM(total_deducciones)  FROM planilla_detalle WHERE periodo_id=?),
                    total_neto        = (SELECT SUM(sueldo_neto)        FROM planilla_detalle WHERE periodo_id=?)
                WHERE pp.id=?")
                ->execute([$pid, $pid, $pid, $pid]);
            $pdo->commit();
            jsonOk([], 'Deducciones actualizadas.');
        } catch (\Exception $e) {
            $pdo->rollBack();
            jsonErr('Error: ' . $e->getMessage());
        }
    }

    if ($_act === 'cambiar_estado') {
        $pid    = (int)$_POST['id'];
        $estado = $_POST['estado'];
        $pdo->prepare("UPDATE planilla_periodos SET estado=? WHERE id=?")->execute([$estado,$pid]);
        jsonOk([], 'Estado actualizado.');
    }
    jsonErr('Acción desconocida.');
}

// ── LOAD DETAIL ───────────────────────────────────────────────────
$periodo  = null;
$detalle  = [];
$dist = $proyMap = $repartos = $resumenProy = [];
if ($action === 'ver' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id=?"); $stmt->execute([$id]); $periodo = $stmt->fetch();
    $detalle = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) as empleado_nombre, e.cargo, e.banco
        FROM planilla_detalle pd
        JOIN empleados e ON pd.empleado_id = e.id
        WHERE pd.periodo_id=? ORDER BY e.apellidos,e.nombre")->execute([$id]) ? [] : [];
    $stmt2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) as empleado_nombre, e.cargo, e.banco,
        e.cuenta_banco, e.aplica_ihss, e.aplica_rap, e.aplica_isr, e.aplica_credimpulsa
        FROM planilla_detalle pd JOIN empleados e ON pd.empleado_id = e.id WHERE pd.periodo_id=? ORDER BY e.apellidos");
    $stmt2->execute([$id]); $detalle = $stmt2->fetchAll();
    $dist    = cargarDistribucion($pdo, 'planilla_detalle_proyectos', array_column($detalle, 'id'));
    $proyMap = proyectosMap($pdo);
    [$repartos, $resumenProy] = distribuirQuincenal($detalle, $dist);
}

// ── LIST ──────────────────────────────────────────────────────────
$periodos = [];
if ($action === 'list') {
    $periodos = $pdo->query("SELECT * FROM planilla_periodos pp WHERE " . filtroHistorico('pp') . " ORDER BY anio DESC, mes DESC, quincena DESC")->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-money-check-dollar"></i> Planillas de Sueldos</h1>
  <?php if ($action === 'list'): ?>
  <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-generar">
    <i class="fas fa-plus"></i> Generar Planilla
  </button>
  <?php else: ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>
<div class="card">
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead>
        <tr><th>Número</th><th>Período</th><th>Tipo</th><th>Fecha Pago</th><th>Total Bruto</th><th>Deducciones</th><th>Total Neto</th><th>Estado</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($periodos as $p): ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($p['numero'] ?? '—') ?><?= badgeHistorico($p) ?></td>
          <td><?= $meses[$p['mes']-1] . ' ' . $p['anio'] ?></td>
          <td><?= htmlspecialchars(nombrePeriodoPlanilla($p)) ?></td>
          <td><?= fmtFecha($p['fecha_pago'] ?? '') ?></td>
          <td class="font-mono"><?= lps($p['total_bruto']) ?></td>
          <td class="font-mono text-danger"><?= lps($p['total_deducciones']) ?></td>
          <td class="font-mono fw-bold text-success"><?= lps($p['total_neto']) ?></td>
          <td><?= estadoBadge($p['estado']) ?></td>
          <td>
            <a href="?action=ver&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
              <i class="fas fa-eye"></i> Ver
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$periodos): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No hay planillas generadas</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $periodo): ?>
<!-- Detalle de planilla -->
<input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
<div id="print-area">
  <div class="print-header">
    <h4>Proscala — Planilla <?= esPlanillaMensual($periodo) ? 'Mensual' : 'Quincenal' ?></h4>
    <p><?= $meses[$periodo['mes']-1] . ' ' . $periodo['anio'] ?> | <?= htmlspecialchars(nombrePeriodoPlanilla($periodo)) ?></p>
  </div>

  <div class="card mb-3">
    <div class="card-header">
      <i class="fas fa-calendar"></i>
      <?php if ($periodo['numero']): ?><strong class="font-mono"><?= htmlspecialchars($periodo['numero']) ?></strong> —<?php endif; ?>
      <?= $meses[$periodo['mes']-1] . ' ' . $periodo['anio'] ?> —
      <?= htmlspecialchars(nombrePeriodoPlanilla($periodo, true)) ?>
      — <?= estadoBadge($periodo['estado']) ?>
      <div class="ms-auto d-flex gap-2 no-print">
        <?php if ($periodo['estado'] === 'procesada'): ?>
        <button class="btn btn-sm btn-success" onclick="cambiarEstado('planillas.php',<?= $periodo['id'] ?>,'aprobada','planilla_periodos',()=>Nav.recargar())">
          <i class="fas fa-check"></i> Aprobar
        </button>
        <?php elseif ($periodo['estado'] === 'aprobada'): ?>
        <button class="btn btn-sm btn-info text-white" onclick="cambiarEstado('planillas.php',<?= $periodo['id'] ?>,'pagada','planilla_periodos',()=>Nav.recargar())">
          <i class="fas fa-money-bill"></i> Marcar Pagada
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>print.php?tipo=pl&id=<?= $periodo['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger">
          <i class="fas fa-file-pdf"></i> PDF
        </a>
        <a href="<?= BASE_URL ?>print.php?tipo=plp&id=<?= $periodo['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
          <i class="fas fa-diagram-project"></i> Por Proyecto
        </a>
        <?php if ($periodo['estado'] !== 'pagada'): ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="aplicarDistribucion(<?= $periodo['id'] ?>)"
          title="Reemplaza la distribución de esta planilla por la configurada en cada empleado">
          <i class="fas fa-rotate"></i> Aplicar distribución de empleados
        </button>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>print.php?tipo=plc&id=<?= $periodo['id'] ?>" target="_blank" class="btn btn-sm btn-outline-warning">
          <i class="fas fa-credit-card"></i> Credimpulsa
        </a>
        <a href="<?= BASE_URL ?>planillas.php?action=export_credimpulsa&id=<?= $periodo['id'] ?>" class="btn btn-sm btn-outline-success">
          <i class="fas fa-file-excel"></i> Excel
        </a>
        <?php if (in_array($periodo['estado'], ['aprobada','pagada'])): ?>
        <a href="<?= BASE_URL ?>print.php?tipo=plv&id=<?= $periodo['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">
          <i class="fas fa-file-zipper"></i> Vouchers
        </a>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <div class="row g-3 mb-2" style="font-size:.85rem;">
        <div class="col-auto">Período: <strong><?= fmtFecha($periodo['fecha_inicio']) ?> — <?= fmtFecha($periodo['fecha_fin']) ?></strong></div>
        <div class="col-auto">Fecha de Pago: <strong><?= fmtFecha($periodo['fecha_pago'] ?? '') ?></strong></div>
        <div class="col-auto">Empleados: <strong><?= count($detalle) ?></strong></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0 table-scroll" style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 240px);">
      <table class="table-proscala w-100" style="font-size:.78rem;">
        <thead>
          <tr>
            <th>#</th><th>Empleado</th><th>Cargo</th><th>Proyectos</th>
            <th>Días</th><th><?= esPlanillaMensual($periodo) ? 'Sueldo Bruto' : 'Q. Bruta' ?></th><th>IHSS</th><th>RAP</th><th>ISR</th>
            <th>Credimpulsa</th>
            <th>Ded. Total</th><th>Sueldo Neto</th>
            <th>IHSS Pat.</th><th>RAP Pat.</th><th>Obs.</th><th>Banco / Cuenta</th>
          </tr>
        </thead>
        <tbody>
          <?php $i=1; $totBruto=$totIhssE=$totRap=$totIsr=$totCredimpulsa=$totDed=$totNeto=$totIhssP=$totRapP=0;
          // Corregir la planilla: rangos superiores, nunca quien la generó (config/edicion.php)
          $editarPl = puedeEditar($pdo, 'planillas.php', (int)$periodo['id']) === true;
          $canEdit = $editarPl && in_array($periodo['estado'], ['procesada','aprobada']);
          $canDist = $editarPl && $periodo['estado'] !== 'pagada';
          foreach ($detalle as $d): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td>
              <?= htmlspecialchars($d['empleado_nombre']) ?>
              <a href="<?= BASE_URL ?>pdf_download.php?tipo=plv&id=<?= $periodo['id'] ?>&emp_id=<?= $d['empleado_id'] ?>"
                 target="_blank" title="Descargar voucher PDF" class="ms-1" style="color:var(--text-2);font-size:.75rem">
                <i class="fas fa-file-pdf"></i>
              </a>
            </td>
            <td><?= htmlspecialchars($d['cargo']) ?></td>
            <td style="white-space:nowrap">
              <?php foreach ($dist[$d['id']] ?? [] as $dp): ?>
              <span class="badge bg-light text-dark border" style="font-size:.68rem"
                title="<?= htmlspecialchars(proyectoLabel($proyMap, (int)$dp['proyecto_id'], true)) ?>">
                <?= htmlspecialchars(proyectoLabel($proyMap, (int)$dp['proyecto_id'])) ?>
                <?= fmtPct($dp['pct_salario']) ?>%<?php if ((float)$dp['pct_patronal'] !== (float)$dp['pct_salario']): ?> · P <?= fmtPct($dp['pct_patronal']) ?>%<?php endif; ?>
              </span><br>
              <?php endforeach; ?>
              <?php if (empty($dist[$d['id']])): ?>
              <span class="text-muted" style="font-size:.72rem">Sin proyecto</span>
              <?php endif; ?>
              <?php if ($canDist): ?>
              <button type="button" class="btn btn-sm btn-link p-0 ms-1 no-print" title="Editar distribución"
                onclick="editarDist(<?= $d['id'] ?>)"><i class="fas fa-pen" style="font-size:.7rem"></i></button>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($canEdit): ?>
              <input type="number" min="1" max="<?= diasPeriodoPlanilla($periodo) ?>" step="1"
                class="form-control form-control-sm p-0 text-center font-mono manual-input"
                style="width:52px;font-size:.78rem"
                data-id="<?= $d['id'] ?>" data-field="dias_trabajados"
                value="<?= (int)($d['dias_trabajados'] ?? diasPeriodoPlanilla($periodo)) ?>">
              <?php else: ?>
              <?= (int)($d['dias_trabajados'] ?? diasPeriodoPlanilla($periodo)) ?>
              <?php endif; ?>
            </td>
            <td class="font-mono"><?= lps($d['total_bruto']) ?></td>
            <?php
              $mnInput = function(string $field, float $val, ?int $aplica, bool $canEdit) use ($d): void {
                $id = $d['id'];
                if ($aplica && $canEdit):
                  echo "<input type=\"number\" step=\"0.01\" min=\"0\"
                    class=\"form-control form-control-sm p-0 text-end font-mono manual-input\"
                    style=\"width:90px;font-size:.78rem\"
                    data-id=\"{$id}\" data-field=\"{$field}\"
                    value=\"" . number_format($val, 2, '.', '') . "\">";
                elseif ($aplica):
                  echo "<span class=\"font-mono\">" . lps($val) . "</span>";
                else:
                  echo '<span class="text-muted">—</span>';
                endif;
              };
            ?>
            <td class="font-mono text-danger"><?php $mnInput('ihss_empleado', $d['ihss_empleado'], $d['aplica_ihss'], $canEdit); ?></td>
            <td class="font-mono text-danger"><?php $mnInput('rap_empleado',  $d['rap_empleado'],  $d['aplica_rap'],  $canEdit); ?></td>
            <td class="font-mono text-danger"><?php $mnInput('isr',           $d['isr'],           $d['aplica_isr'],  $canEdit); ?></td>
            <td class="font-mono text-danger"><?php $mnInput('credimpulsa',   $d['credimpulsa'],   $d['aplica_credimpulsa'], $canEdit); ?></td>
            <td class="font-mono text-danger fw-bold"><?= lps($d['total_deducciones']) ?></td>
            <td class="font-mono fw-bold text-success"><?= lps($d['sueldo_neto']) ?></td>
            <td class="font-mono text-warning"><?php $mnInput('ihss_patronal', $d['ihss_patronal'], $d['aplica_ihss'], $canEdit); ?></td>
            <td class="font-mono text-warning"><?php $mnInput('rap_patronal',  $d['rap_patronal'],  $d['aplica_rap'],  false); ?></td>
            <td>
              <?php if ($canEdit): ?>
              <input type="text" maxlength="500"
                class="form-control form-control-sm p-0 px-1 manual-input"
                style="width:160px;font-size:.78rem"
                data-id="<?= $d['id'] ?>" data-field="observacion"
                value="<?= htmlspecialchars($d['observacion'] ?? '') ?>">
              <?php elseif (!empty($d['observacion'])): ?>
              <span style="font-size:.75rem"><?= htmlspecialchars($d['observacion']) ?></span>
              <?php endif; ?>
            </td>
            <td style="font-size:.8rem">
              <?= htmlspecialchars($d['banco'] ?? '—') ?>
              <?php if ($d['cuenta_banco']): ?>
              <br><span class="text-muted font-mono" style="font-size:.72rem"><?= htmlspecialchars($d['cuenta_banco']) ?></span>
              <?php endif; ?>
            </td>
          </tr>
          <?php
            $totBruto += $d['total_bruto']; $totIhssE += $d['ihss_empleado'];
            $totRap += $d['rap_empleado'];  $totIsr += $d['isr'];
            $totCredimpulsa += $d['credimpulsa'];
            $totDed += $d['total_deducciones']; $totNeto += $d['sueldo_neto'];
            $totIhssP += $d['ihss_patronal']; $totRapP += $d['rap_patronal'];
          endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4" class="fw-bold">TOTALES</td>
            <td></td>
            <td class="font-mono"><?= lps($totBruto) ?></td>
            <td class="font-mono text-danger"><?= lps($totIhssE) ?></td>
            <td class="font-mono text-danger"><?= lps($totRap) ?></td>
            <td class="font-mono text-danger"><?= lps($totIsr) ?></td>
            <td class="font-mono text-danger"><?= lps($totCredimpulsa) ?></td>
            <td class="font-mono text-danger fw-bold"><?= lps($totDed) ?></td>
            <td class="font-mono fw-bold text-success"><?= lps($totNeto) ?></td>
            <td class="font-mono text-warning"><?= lps($totIhssP) ?></td>
            <td class="font-mono text-warning"><?= lps($totRapP) ?></td>
            <td></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php if ($canEdit): ?>
  <div class="mt-2 no-print d-flex align-items-center gap-2">
    <button class="btn btn-sm btn-outline-warning" onclick="guardarManuales(<?= $periodo['id'] ?>)">
      <i class="fas fa-save"></i> Guardar días / IHSS / RAP / ISR / Credimpulsa
    </button>
    <span class="text-muted" style="font-size:.8rem">Edite los días (máximo <?= diasPeriodoPlanilla($periodo) ?>) y los montos, y guarde antes de aprobar.
      El sueldo se recalcula como sueldo mensual / 30 × días.</span>
  </div>
  <?php endif; ?>
</div>

  <!-- Resumen por proyecto -->
  <div class="card mt-3">
    <div class="card-header"><i class="fas fa-diagram-project"></i> Resumen por Proyecto</div>
    <div class="card-body p-0" style="overflow-x:auto;">
      <table class="table-proscala w-100" style="font-size:.8rem;">
        <thead>
          <tr>
            <th>Proyecto</th><th class="text-center">Empleados</th><th class="text-end">Bruto</th>
            <th class="text-end">Deducciones</th><th class="text-end">Neto a Pagar</th>
            <th class="text-end">IHSS Pat.</th><th class="text-end">RAP Pat.</th><th class="text-end">Costo Total</th>
          </tr>
        </thead>
        <tbody>
          <?php $tr = ['total_bruto'=>0,'total_deducciones'=>0,'sueldo_neto'=>0,'ihss_patronal'=>0,'rap_patronal'=>0,'costo_total'=>0];
          foreach ($resumenProy as $pid => $r): foreach ($tr as $k => $_) $tr[$k] += $r[$k]; ?>
          <tr>
            <td><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></td>
            <td class="text-center"><?= $r['empleados'] ?></td>
            <td class="font-mono text-end"><?= lps($r['total_bruto']) ?></td>
            <td class="font-mono text-end text-danger"><?= lps($r['total_deducciones']) ?></td>
            <td class="font-mono text-end fw-bold text-success"><?= lps($r['sueldo_neto']) ?></td>
            <td class="font-mono text-end text-warning"><?= lps($r['ihss_patronal']) ?></td>
            <td class="font-mono text-end text-warning"><?= lps($r['rap_patronal']) ?></td>
            <td class="font-mono text-end fw-bold"><?= lps($r['costo_total']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2" class="fw-bold">TOTALES</td>
            <td class="font-mono text-end"><?= lps($tr['total_bruto']) ?></td>
            <td class="font-mono text-end text-danger"><?= lps($tr['total_deducciones']) ?></td>
            <td class="font-mono text-end fw-bold text-success"><?= lps($tr['sueldo_neto']) ?></td>
            <td class="font-mono text-end text-warning"><?= lps($tr['ihss_patronal']) ?></td>
            <td class="font-mono text-end text-warning"><?= lps($tr['rap_patronal']) ?></td>
            <td class="font-mono text-end fw-bold"><?= lps($tr['costo_total']) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div><!-- /print-area -->

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
          Solo afecta a esta planilla. % Salario reparte bruto, deducciones y neto; % Patronal reparte IHSS y RAP patronal.
        </p>
        <table class="table table-sm align-middle mb-1" style="font-size:.83rem;">
          <thead>
            <tr><th>Proyecto</th><th style="width:110px" class="text-end">% Salario</th><th style="width:110px" class="text-end">% Patronal</th><th style="width:50px"></th></tr>
          </thead>
          <tbody id="dist-body"></tbody>
          <tfoot>
            <tr>
              <td><span data-dist-msg></span></td>
              <td class="text-end font-mono fw-bold" data-tot="pct_salario"></td>
              <td class="text-end font-mono fw-bold" data-tot="pct_patronal"></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="distPl.add()">
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
<?php $adjModulo = 'planillas.php'; $adjId = $periodo['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php endif; ?>

<!-- Modal: Generar Planilla -->
<div class="modal fade" id="modal-generar" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-invoice"></i> Generar Nueva Planilla</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-planilla">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="generar">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Tipo de planilla *</label>
              <div class="d-flex gap-4">
                <div class="form-check"><input class="form-check-input" type="radio" name="tipo" id="tipo-quincenal" value="quincenal" checked onchange="updateFechas()">
                  <label class="form-check-label" for="tipo-quincenal">Quincenal <span class="text-muted">(15 días por empleado)</span></label></div>
                <div class="form-check"><input class="form-check-input" type="radio" name="tipo" id="tipo-mensual" value="mensual" onchange="updateFechas()">
                  <label class="form-check-label" for="tipo-mensual">Mensual <span class="text-muted">(30 días por empleado)</span></label></div>
              </div>
            </div>
            <div class="col-md-3">
              <label class="form-label">Año *</label>
              <input type="number" name="anio" class="form-control" value="<?= date('Y') ?>" required min="2020" max="2040">
            </div>
            <div class="col-md-3">
              <label class="form-label">Mes *</label>
              <select name="mes" class="form-select" required id="sel-mes">
                <?php foreach ($meses as $mi => $mn): ?>
                <option value="<?= $mi+1 ?>" <?= ($mi+1) == date('n') ? 'selected' : '' ?>><?= $mn ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3" id="grp-quin">
              <label class="form-label">Quincena *</label>
              <select name="quincena" class="form-select" required id="sel-quin" onchange="updateFechas()">
                <option value="primera">1ª Quincena (1-15)</option>
                <option value="segunda">2ª Quincena (16-fin)</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Fecha de Pago</label>
              <input type="text" name="fecha_pago" class="form-control date-input" id="fecha-pago">
            </div>
            <div class="col-md-4">
              <label class="form-label">Fecha Inicio</label>
              <input type="text" name="fecha_inicio" class="form-control date-input" id="fecha-inicio">
            </div>
            <div class="col-md-4">
              <label class="form-label">Fecha Fin</label>
              <input type="text" name="fecha_fin" class="form-control date-input" id="fecha-fin">
            </div>
          </div>
          <div class="alert alert-info mt-3 mb-0" style="font-size:.82rem;">
            <i class="fas fa-info-circle"></i>
            Se generará la planilla con todos los empleados activos de contrato indefinido o definido, con
            <strong id="txt-dias">15</strong> días trabajados cada uno (sueldo mensual / 30 × días). Quien ingresó durante el
            período lleva solo los días desde su ingreso; los días se pueden corregir en la planilla.
            <span id="txt-mensual" hidden>Las deducciones de cada empleado (configuradas por quincena) se toman al doble.</span>
            IHSS, RAP e ISR se ingresan manualmente en la planilla. Solo INFOP se calcula automáticamente.
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="submitPlanilla()">
          <i class="fas fa-cog"></i> Generar Planilla
        </button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'

async function guardarManuales(periodoId) {
  const byRow = {};
  document.querySelectorAll('.manual-input').forEach(inp => {
    const id = inp.dataset.id;
    if (!byRow[id]) byRow[id] = { id };
    byRow[id][inp.dataset.field] = inp.value;
  });
  const rows = Object.values(byRow);
  if (!rows.length) { Toast.show('No hay campos para guardar.', 'error'); return; }
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'guardar_credimpulsa');
  fd.append('periodo_id', periodoId);
  rows.forEach((row, i) => {
    Object.entries(row).forEach(([k, v]) => fd.append('rows[' + i + '][' + k + ']', v));
  });
  const r = await post('planillas.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  else Toast.show(r.message, 'error');
}

function updateFechas() {
  const anio  = document.querySelector('[name=anio]').value;
  const mes   = document.querySelector('[name=mes]').value.padStart(2,'0');
  const quin  = document.getElementById('sel-quin').value;
  const lastDay = new Date(anio, mes, 0).getDate();
  const mensual = document.getElementById('tipo-mensual')?.checked;
  document.getElementById('grp-quin').hidden = mensual;
  document.getElementById('txt-dias').textContent = mensual ? '30' : '15';
  document.getElementById('txt-mensual').hidden = !mensual;
  if (mensual) {
    document.getElementById('fecha-inicio').value = anio+'-'+mes+'-01';
    document.getElementById('fecha-fin').value    = anio+'-'+mes+'-'+String(lastDay).padStart(2,'0');
  } else if (quin === 'primera') {
    document.getElementById('fecha-inicio').value = anio+'-'+mes+'-01';
    document.getElementById('fecha-fin').value    = anio+'-'+mes+'-15';
  } else {
    document.getElementById('fecha-inicio').value = anio+'-'+mes+'-16';
    document.getElementById('fecha-fin').value    = anio+'-'+mes+'-'+String(lastDay).padStart(2,'0');
  }
}
updateFechas();
document.getElementById('sel-mes')?.addEventListener('change', updateFechas);
document.querySelector('[name=anio]')?.addEventListener('change', updateFechas);

async function submitPlanilla() {
  const form = document.getElementById('form-planilla');
  const fd = new FormData(form);
  const btn = document.querySelector('.modal-footer .btn-proscala');
  btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Procesando...';
  try {
    const res = await post('planillas.php', fd);
    if (res.success) {
      Toast.show(res.message, 'success');
      setTimeout(() => Nav.ir('planillas.php?action=ver&id='+res.id), 1500);
    } else { Toast.show(res.message,'error'); btn.disabled=false; btn.innerHTML='<i class="fas fa-cog"></i> Generar Planilla'; }
  } catch (e) {   // el motivo del servidor (p. ej. el mes ya tiene planilla), no un genérico
    Toast.show(e.message || 'No se pudo generar la planilla.', 'error', 9000);
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-cog"></i> Generar Planilla';
  }
}

JS;

if ($periodo) {
    $plFilas = [];
    foreach ($detalle as $d) {
        $plFilas[$d['id']] = [
            'nombre'   => $d['empleado_nombre'],
            'bruto'    => (float)$d['total_bruto'],
            'ded'      => (float)$d['total_deducciones'],
            'patronal' => (float)$d['ihss_patronal'] + (float)$d['rap_patronal'],
            'dist'     => $dist[$d['id']] ?? [],
        ];
    }
    $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $extraJs .= strtr(<<<'JS'
const PL_PERIODO = __PERIODO_ID__;
const PROYECTOS = __PROYECTOS__;
const PL_FILAS = __FILAS__;

JS, [
    '__PERIODO_ID__' => (int)$periodo['id'],
    '__PROYECTOS__' => json_encode(array_values($proyMap), $jsonFlags),
    '__FILAS__' => json_encode($plFilas ?: new stdClass, $jsonFlags),
]);
    $extraJs .= <<<'JS'
const fmtL = n => 'L. ' + n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const escH = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
let distDid = 0, distModal = null, manualDirty = false;
document.querySelectorAll('.manual-input').forEach(i => i.addEventListener('input', () => manualDirty = true));

const distPl = new DistEditor(document.getElementById('dist-body'), {
  proyectos: PROYECTOS, campos: ['pct_salario', 'pct_patronal'], onChange: previewDist
});

function editarDist(did) {
  distDid = did;
  document.getElementById('dist-emp').textContent = PL_FILAS[did].nombre;
  distPl.set(PL_FILAS[did].dist);
  distModal = distModal || new bootstrap.Modal(document.getElementById('modal-dist'));
  distModal.show();
}

function previewDist(ed) {
  const f = PL_FILAS[distDid], el = document.getElementById('dist-preview');
  const rows = ed.rows();
  if (!f || !rows.length) { el.innerHTML = ''; return; }
  const cod = id => (PROYECTOS.find(p => String(p.id) === String(id)) || {}).codigo || '';
  el.innerHTML = '<table class="table table-sm mb-0"><thead><tr><th>Proyecto</th>'
    + '<th class="text-end">Bruto</th><th class="text-end">Neto</th><th class="text-end">IHSS + RAP Pat.</th></tr></thead><tbody>'
    + rows.map(r => `<tr><td>${escH(cod(r.proyecto_id))}</td>`
      + `<td class="text-end font-mono">${fmtL(f.bruto * r.pct_salario / 100)}</td>`
      + `<td class="text-end font-mono">${fmtL((f.bruto - f.ded) * r.pct_salario / 100)}</td>`
      + `<td class="text-end font-mono">${fmtL(f.patronal * r.pct_patronal / 100)}</td></tr>`).join('')
    + '</tbody></table>';
}

async function guardarDist() {
  const err = distPl.error();
  if (err) { Toast.show(err, 'error'); return; }
  if (manualDirty && !await confirmar('Hay cambios sin guardar en deducciones / días. Si continúa se perderán.', { titulo: 'Cambios sin guardar', aceptar: 'Continuar', peligro: true })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'guardar_distribucion');
  fd.append('periodo_id', PL_PERIODO);
  fd.append('detalle_id', distDid);
  distPl.rows().forEach((r, i) => Object.entries(r).forEach(([k, v]) => fd.append(`dist[${i}][${k}]`, v)));
  const r = await post('planillas.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 600); }
  else Toast.show(r.message, 'error');
}

async function aplicarDistribucion(pid) {
  if (!await confirmar('Se reemplazará la distribución de TODOS los empleados de esta planilla por la configurada en su expediente.', { titulo: 'Aplicar distribución', aceptar: 'Reemplazar', peligro: true })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'aplicar_distribucion');
  fd.append('periodo_id', pid);
  const r = await post('planillas.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  else Toast.show(r.message, 'error');
}
JS;
}
include __DIR__ . '/includes/footer.php';
?>

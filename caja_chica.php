<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Caja Chica';

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'guardar') {
        $ccId     = (int)($_POST['id'] ?? 0);
        $empId    = (int)$_POST['empleado_id'];
        $anticipo = round((float)$_POST['anticipo'], 2);
        $fApert   = $_POST['fecha_apertura'];
        $notas    = trim($_POST['notas'] ?? '');
        $items    = json_decode($_POST['items'] ?? '[]', true);

        if (!$empId)  jsonErr('Debe seleccionar un empleado.');
        if (!$fApert) jsonErr('La fecha de apertura es obligatoria.');
        if (!is_array($items) || !count($items)) jsonErr('Debe ingresar al menos un gasto.');

        $total = 0;
        foreach ($items as $item) $total += (float)$item['monto'];
        $total = round($total, 2);
        $saldo = round($anticipo - $total, 2);

        $pdo->beginTransaction();
        try {
            if ($ccId) {
                $pdo->prepare("UPDATE caja_chica SET empleado_id=?,anticipo=?,total_gastos=?,saldo=?,
                    fecha_apertura=?,notas=? WHERE id=? AND estado='abierta'")
                    ->execute([$empId,$anticipo,$total,$saldo,$fApert,$notas,$ccId]);
                $pdo->prepare("DELETE FROM caja_chica_detalle WHERE caja_id=?")->execute([$ccId]);
                registrarAuditoria($pdo,'UPDATE','caja_chica',$ccId,'CC actualizada — '.lps($total));
            } else {
                $num = numeroProceso($pdo,'caja_chica',getConfig($pdo,'prefijo_cc','CC'));
                $pdo->prepare("INSERT INTO caja_chica (numero,empleado_id,anticipo,total_gastos,saldo,fecha_apertura,notas)
                    VALUES (?,?,?,?,?,?,?)")
                    ->execute([$num,$empId,$anticipo,$total,$saldo,$fApert,$notas]);
                $ccId = (int)$pdo->lastInsertId();
                registrarAuditoria($pdo,'INSERT','caja_chica',$ccId,"CC creada: $num — ".lps($anticipo));
            }
            $stmtIns = $pdo->prepare("INSERT INTO caja_chica_detalle
                (caja_id,fecha,cuenta_id,descripcion,cantidad,precio_unitario,monto)
                VALUES (?,?,?,?,?,?,?)");
            foreach ($items as $item) {
                $cant  = round((float)($item['cantidad'] ?? 1), 3);
                $punit = round((float)($item['precio_unitario'] ?? $item['monto']), 2);
                $stmtIns->execute([
                    $ccId,
                    $item['fecha'] ?: $fApert,
                    !empty($item['cuenta_id']) ? (int)$item['cuenta_id'] : null,
                    trim($item['descripcion']),
                    $cant, $punit,
                    round((float)$item['monto'], 2),
                ]);
            }
            $pdo->commit();
            jsonOk(['id'=>$ccId],'Caja chica guardada.');
        } catch(\Exception $e){ $pdo->rollBack(); jsonErr($e->getMessage()); }
    }

    if ($_act === 'liquidar') {
        $ccId = (int)$_POST['id'];
        $s = $pdo->prepare("SELECT anticipo,total_gastos FROM caja_chica WHERE id=? AND estado='abierta'");
        $s->execute([$ccId]); $cc = $s->fetch();
        if (!$cc) jsonErr('Caja chica no encontrada o ya liquidada.');
        if ($cc['anticipo'] > 0 && $cc['total_gastos'] < $cc['anticipo'] * 0.80) {
            $pct = round($cc['total_gastos'] / $cc['anticipo'] * 100, 1);
            jsonErr("Solo se ha consumido el {$pct}% del anticipo. Se requiere al menos el 80% para liquidar.");
        }
        $pdo->prepare("UPDATE caja_chica SET estado='liquidada',aprobador_id=?,fecha_cierre=CURDATE() WHERE id=?")
            ->execute([currentUser()['id'],$ccId]);
        registrarAuditoria($pdo,'UPDATE','caja_chica',$ccId,'Caja chica liquidada');
        jsonOk([],'Caja chica liquidada correctamente.');
    }

    if ($_act === 'eliminar') {
        $ccId = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM caja_chica WHERE id=? AND estado='abierta'")->execute([$ccId]);
        registrarAuditoria($pdo,'DELETE','caja_chica',$ccId,'Caja chica eliminada');
        jsonOk([],'Caja chica eliminada.');
    }

    jsonErr('Acción desconocida.');
}

// ── Carga de datos ─────────────────────────────────────────────────
$cc = null; $detalle = []; $resumenCuentas = [];

if (in_array($action, ['ver','editar']) && $id) {
    $s = $pdo->prepare("SELECT cc.*,
        CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo, e.identidad,
        u.nombre AS aprobador_nombre
        FROM caja_chica cc
        JOIN empleados e ON cc.empleado_id=e.id
        LEFT JOIN usuarios u ON cc.aprobador_id=u.id
        WHERE cc.id=?");
    $s->execute([$id]); $cc = $s->fetch();
    if (!$cc) { header('Location: caja_chica.php'); exit; }

    $s2 = $pdo->prepare("SELECT d.*,
        COALESCE(CONCAT(cta.codigo,' — ',cta.nombre),'') AS cuenta_nombre
        FROM caja_chica_detalle d
        LEFT JOIN cuentas_contables cta ON d.cuenta_id=cta.id
        WHERE d.caja_id=? ORDER BY d.fecha,d.id");
    $s2->execute([$id]); $detalle = $s2->fetchAll();

    if ($action === 'ver') {
        $s3 = $pdo->prepare("
            SELECT COALESCE(CONCAT(cta.codigo,' — ',cta.nombre),'Sin cuenta') AS cuenta_nombre,
                   SUM(d.monto) AS subtotal
            FROM caja_chica_detalle d
            LEFT JOIN cuentas_contables cta ON d.cuenta_id=cta.id
            WHERE d.caja_id=?
            GROUP BY d.cuenta_id, cta.codigo, cta.nombre ORDER BY cta.codigo");
        $s3->execute([$id]); $resumenCuentas = $s3->fetchAll();
    }
}

$empleados = $pdo->query("SELECT id,CONCAT(nombre,' ',apellidos) AS nombre, cargo
    FROM empleados WHERE activo=1 ORDER BY apellidos,nombre")->fetchAll();
$cuentas   = $pdo->query("SELECT id,codigo,nombre FROM cuentas_contables
    WHERE activa=1 ORDER BY codigo")->fetchAll();

$listaCajas = [];
$statsCC    = null;
$resumenGlobalCuentas = [];
if ($action === 'list') {
    $listaCajas = $pdo->query("SELECT cc.*,
        CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo
        FROM caja_chica cc JOIN empleados e ON cc.empleado_id=e.id
        WHERE " . filtroHistorico('cc') . "
        ORDER BY cc.id DESC")->fetchAll();

    $statsCC = $pdo->query("
        SELECT
            COUNT(*)                                              AS total,
            SUM(CASE WHEN estado='abierta'   THEN 1 ELSE 0 END) AS abiertas,
            COALESCE(SUM(anticipo),    0)                        AS total_anticipo,
            COALESCE(SUM(total_gastos),0)                        AS total_gastos,
            COALESCE(SUM(saldo),       0)                        AS total_saldo
        FROM caja_chica")->fetch();

    $resumenGlobalCuentas = $pdo->query("
        SELECT
            COALESCE(CONCAT(cta.codigo,' — ',cta.nombre),'Sin cuenta asignada') AS cuenta_nombre,
            SUM(d.monto)              AS total,
            COUNT(DISTINCT d.caja_id) AS num_cajas
        FROM caja_chica_detalle d
        LEFT JOIN cuentas_contables cta ON d.cuenta_id = cta.id
        GROUP BY d.cuenta_id, cta.codigo, cta.nombre
        ORDER BY total DESC
        LIMIT 10")->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-cash-register"></i> Caja Chica</h1>
  <?php if ($action === 'list'): ?>
  <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nueva Caja Chica</a>
  <?php else: ?>
  <a href="caja_chica.php" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>

<!-- Resumen global por cuenta contable -->
<?php if ($resumenGlobalCuentas):
  $totalGlobalCuentas = array_sum(array_column($resumenGlobalCuentas, 'total'));
?>
<div class="card mb-4">
  <div class="card-header"><i class="fas fa-chart-bar"></i> Gastos por Cuenta Contable — Todas las Cajas</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead>
        <tr>
          <th>Cuenta Contable</th>
          <th class="text-end">Total</th>
          <th class="text-center" style="width:60px">Cajas</th>
          <th style="width:200px">Proporción</th>
          <th class="text-end" style="width:70px">%</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($resumenGlobalCuentas as $rc):
          $pctGC = $totalGlobalCuentas > 0 ? round($rc['total'] / $totalGlobalCuentas * 100, 1) : 0;
        ?>
        <tr>
          <td><?= htmlspecialchars($rc['cuenta_nombre']) ?></td>
          <td class="font-mono text-end fw-bold"><?= lps($rc['total']) ?></td>
          <td class="text-center" style="color:var(--text-3)"><?= $rc['num_cajas'] ?></td>
          <td style="padding:.55rem .9rem">
            <div class="budget-bar-wrap" style="height:6px">
              <div class="budget-bar" style="width:<?= $pctGC ?>%;background:var(--primary);transition:width .6s"></div>
            </div>
          </td>
          <td class="font-mono text-end" style="color:var(--text-3);font-size:.8rem"><?= $pctGC ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="fw-bold">TOTAL</td>
          <td class="font-mono text-end fw-bold"><?= lps($totalGlobalCuentas) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Tabla de cajas -->
<div class="card mb-4">
  <div class="card-body p-0">
    <table id="tbl-cc" class="table-proscala w-100">
      <thead>
        <tr>
          <th>Número</th><th>Responsable</th><th>Cargo</th>
          <th class="text-end">Anticipo</th><th class="text-end">Gastos</th><th class="text-end">Saldo</th>
          <th>Apertura</th><th>Estado</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($listaCajas as $r):
          $saldoColor = $r['saldo'] >= 0 ? 'text-success' : 'text-danger';
        ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($r['numero']) ?><?= badgeHistorico($r) ?></td>
          <td><?= htmlspecialchars($r['emp_nombre']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($r['cargo'] ?? '') ?></td>
          <td class="text-end font-mono"><?= lps($r['anticipo']) ?></td>
          <td class="text-end font-mono"><?= lps($r['total_gastos']) ?></td>
          <td class="text-end font-mono fw-bold <?= $saldoColor ?>"><?= lps($r['saldo']) ?></td>
          <td><?= fmtFecha($r['fecha_apertura']) ?></td>
          <td><?= estadoBadge($r['estado']) ?></td>
          <td style="white-space:nowrap">
            <a href="?action=ver&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
              <i class="fas fa-eye"></i>
            </a>
            <?= botonEditar($pdo, 'caja_chica.php', (int)$r['id'], '?action=editar&id=' . $r['id'], true) ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$listaCajas): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No hay cajas chicas registradas.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $cc):
  $pctConsumo = $cc['anticipo'] > 0 ? min(100, round($cc['total_gastos'] / $cc['anticipo'] * 100, 1)) : 100;
  $puedeL     = $cc['estado'] === 'abierta' && ($cc['anticipo'] <= 0 || $cc['total_gastos'] >= $cc['anticipo'] * 0.80);
?>
<input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-cash-register"></i>
    <strong><?= htmlspecialchars($cc['numero']) ?></strong> — <?= estadoBadge($cc['estado']) ?>
    <?php if ($cc['anticipo'] > 0): ?>
    <span class="ms-3 badge bg-<?= $pctConsumo >= 80 ? 'success' : 'warning' ?>">
      <?= $pctConsumo ?>% consumido
    </span>
    <?php endif; ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?= botonEditar($pdo, 'caja_chica.php', (int)$id, '?action=editar&id=' . $id) ?>
      <?php if ($cc['estado'] === 'abierta'): ?>
      <?php if ($puedeL): ?>
      <button class="btn btn-sm btn-success" onclick="liquidarCC(<?= $id ?>)">
        <i class="fas fa-check-double"></i> Liquidar
      </button>
      <?php else: ?>
      <button class="btn btn-sm btn-success disabled"
        title="Se requiere consumir al menos el 80% del anticipo (<?= $pctConsumo ?>% actual)">
        <i class="fas fa-check-double"></i> Liquidar
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>print.php?tipo=cc&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-2" style="font-size:.85rem">
      <div class="col-md-4"><strong>Responsable:</strong> <?= htmlspecialchars($cc['emp_nombre']) ?></div>
      <div class="col-md-4"><strong>Cargo:</strong> <?= htmlspecialchars($cc['cargo'] ?? '—') ?></div>
      <div class="col-md-4"><strong>Identidad:</strong> <?= htmlspecialchars($cc['identidad'] ?? '—') ?></div>
      <div class="col-md-3"><strong>Apertura:</strong> <?= fmtFecha($cc['fecha_apertura']) ?></div>
      <?php if ($cc['fecha_cierre']): ?>
      <div class="col-md-3"><strong>Cierre:</strong> <?= fmtFecha($cc['fecha_cierre']) ?></div>
      <?php endif; ?>
      <?php if ($cc['aprobador_nombre']): ?>
      <div class="col-md-5"><strong>Liquidado por:</strong> <?= htmlspecialchars($cc['aprobador_nombre']) ?></div>
      <?php endif; ?>
      <?php if ($cc['notas']): ?>
      <div class="col-12"><strong>Notas:</strong> <?= htmlspecialchars($cc['notas']) ?></div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Resumen financiero -->
<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="kpi-card kpi-blue kpi-sm">
      <div class="kpi-icon"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="kpi-value"><?= lps($cc['anticipo']) ?></div>
      <div class="kpi-label">Anticipo</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="kpi-card kpi-yellow kpi-sm">
      <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
      <div class="kpi-value"><?= lps($cc['total_gastos']) ?></div>
      <div class="kpi-label">Total Gastos</div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="kpi-card <?= $cc['saldo'] >= 0 ? 'kpi-green' : 'kpi-red' ?> kpi-sm">
      <div class="kpi-icon"><i class="fas fa-scale-balanced"></i></div>
      <div class="kpi-value"><?= lps(abs($cc['saldo'])) ?></div>
      <div class="kpi-label"><?= $cc['saldo'] >= 0 ? 'Saldo a devolver' : 'Reembolso pendiente' ?></div>
    </div>
  </div>
  <?php if ($cc['anticipo'] > 0): ?>
  <div class="col-md-3">
    <div class="kpi-card <?= $pctConsumo >= 80 ? 'kpi-green' : 'kpi-yellow' ?>">
      <div class="kpi-icon"><i class="fas fa-percent"></i></div>
      <div class="kpi-value"><?= $pctConsumo ?>%</div>
      <div class="kpi-label">Consumo del anticipo</div>
      <div style="background:var(--kb);border-radius:4px;height:4px;margin-top:8px">
        <div style="background:var(--kc);height:4px;border-radius:4px;width:<?= $pctConsumo ?>%;transition:width .5s"></div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Detalle de gastos -->
<div class="card mb-3">
  <div class="card-header"><i class="fas fa-receipt"></i> Detalle de Gastos</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead>
        <tr>
          <th>#</th><th>Fecha</th><th>Cuenta Contable</th><th>Descripción</th>
          <th class="text-end">Cant.</th><th class="text-end">P. Unit.</th><th class="text-end">Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($detalle as $d): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td style="white-space:nowrap"><?= fmtFecha($d['fecha']) ?></td>
          <td style="font-size:.8rem;color:var(--text-2)"><?= htmlspecialchars($d['cuenta_nombre'] ?: '—') ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td class="text-end font-mono"><?= number_format($d['cantidad'], 2) ?></td>
          <td class="text-end font-mono"><?= lps($d['precio_unitario']) ?></td>
          <td class="text-end font-mono fw-bold"><?= lps($d['monto']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$detalle): ?>
        <tr><td colspan="7" class="text-center text-muted py-3">Sin gastos registrados.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" class="text-end fw-bold">Total Gastos:</td>
          <td class="text-end font-mono fw-bold" style="color:var(--proscala)"><?= lps($cc['total_gastos']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- Resumen por cuenta contable -->
<?php if ($resumenCuentas): ?>
<div class="card">
  <div class="card-header"><i class="fas fa-chart-pie"></i> Resumen por Cuenta Contable</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>Cuenta Contable</th><th class="text-end">Subtotal</th><th class="text-end">%</th></tr></thead>
      <tbody>
        <?php foreach ($resumenCuentas as $r):
          $pct = $cc['total_gastos'] > 0 ? round($r['subtotal'] / $cc['total_gastos'] * 100, 1) : 0;
        ?>
        <tr>
          <td><?= htmlspecialchars($r['cuenta_nombre']) ?></td>
          <td class="text-end font-mono"><?= lps($r['subtotal']) ?></td>
          <td class="text-end font-mono" style="color:var(--text-3)"><?= $pct ?>%</td>
        </tr>
        <?php endforeach; ?>
        <tr style="font-weight:700;background:var(--surface-2)">
          <td>TOTAL</td>
          <td class="text-end font-mono" style="color:var(--proscala)"><?= lps($cc['total_gastos']) ?></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php $adjModulo = 'caja_chica.php'; $adjId = $cc['id']; include __DIR__ . '/includes/adjuntos.php'; ?>
<?php else: /* nuevo o editar */
  $initItems = $detalle ?: [['fecha' => date('Y-m-d'), 'cuenta_id' => '', 'descripcion' => '', 'cantidad' => 1, 'precio_unitario' => 0, 'monto' => 0]];
?>

<div class="card">
  <div class="card-header">
    <i class="fas fa-cash-register"></i>
    <?= $cc ? 'Editar: ' . htmlspecialchars($cc['numero']) : 'Nueva Caja Chica' ?>
  </div>
  <div class="card-body">
    <form id="form-cc" action="caja_chica.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="guardar">
      <input type="hidden" name="id" value="<?= $cc['id'] ?? 0 ?>">
      <input type="hidden" name="items" id="items-json">

      <div class="row g-3">
        <div class="col-md-5">
          <label class="form-label">Responsable *</label>
          <select name="empleado_id" class="form-select" required>
            <option value="">— Seleccione —</option>
            <?php foreach ($empleados as $e): ?>
            <option value="<?= $e['id'] ?>" <?= ($cc['empleado_id'] ?? 0) == $e['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($e['nombre']) ?> — <?= htmlspecialchars($e['cargo'] ?? '') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Fecha de Apertura *</label>
          <input type="text" name="fecha_apertura" class="form-control date-input" required
            value="<?= htmlspecialchars($cc['fecha_apertura'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Anticipo (L.)</label>
          <input type="number" name="anticipo" class="form-control text-end" min="0" step="0.01"
            value="<?= htmlspecialchars($cc['anticipo'] ?? '0') ?>">
          <div class="form-text">0 si es reembolso</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Total Gastos</label>
          <div class="form-control bg-light text-end font-mono fw-bold" id="cc-total"
               style="color:var(--proscala)">L. 0.00</div>
        </div>
        <div class="col-12">
          <label class="form-label">Notas</label>
          <textarea name="notas" class="form-control" rows="1"
            placeholder="Observaciones…"><?= htmlspecialchars($cc['notas'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="form-section-title mt-3">Detalle de Gastos</div>
      <div class="table-responsive">
        <table class="table-proscala w-100">
          <thead>
            <tr>
              <th>#</th>
              <th style="min-width:110px">Fecha</th>
              <th style="min-width:190px">Cuenta Contable</th>
              <th>Descripción</th>
              <th style="width:80px">Cant.</th>
              <th style="width:120px">P. Unit.</th>
              <th style="width:120px">Monto</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="tbody-cc-items">
            <?php foreach ($initItems as $idx => $it): ?>
            <tr class="detail-row">
              <td class="row-num"><?= $idx + 1 ?></td>
              <td>
                <input type="text" class="form-control form-control-sm date-input cc-fecha"
                  value="<?= htmlspecialchars($it['fecha'] ?? date('Y-m-d')) ?>"
                  data-field="fecha">
              </td>
              <td>
                <select class="form-select form-select-sm" data-field="cuenta_id">
                  <option value="">— Sin cuenta —</option>
                  <?php foreach ($cuentas as $cta): ?>
                  <option value="<?= $cta['id'] ?>"
                    <?= ($it['cuenta_id'] ?? '') == $cta['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cta['codigo'] . ' — ' . $cta['nombre']) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <input type="text" class="form-control form-control-sm"
                  value="<?= htmlspecialchars($it['descripcion'] ?? '') ?>"
                  data-field="descripcion" placeholder="Descripción" required>
              </td>
              <td>
                <input type="number" class="form-control form-control-sm text-end cc-cant"
                  value="<?= htmlspecialchars($it['cantidad'] ?? 1) ?>"
                  min="0.001" step="0.001" data-field="cantidad"
                  oninput="recalcRow(this)">
              </td>
              <td>
                <input type="number" class="form-control form-control-sm text-end cc-punit"
                  value="<?= htmlspecialchars($it['precio_unitario'] ?? 0) ?>"
                  min="0" step="0.01" data-field="precio_unitario"
                  oninput="recalcRow(this)">
              </td>
              <td>
                <input type="number" class="form-control form-control-sm text-end cc-monto"
                  value="<?= htmlspecialchars($it['monto'] ?? 0) ?>"
                  min="0" step="0.01" data-field="monto"
                  oninput="calcCcTotal()" style="background:#f0f4ff">
              </td>
              <td>
                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1"
                  onclick="removeDetailRow(this);calcCcTotal()">
                  <i class="fas fa-trash"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="6" class="text-end fw-bold">Total Gastos:</td>
              <td class="font-mono fw-bold text-end" id="cc-total-foot" style="color:var(--proscala)">L. 0.00</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <button type="button" class="btn btn-sm btn-outline-success mt-2" onclick="addCcRow()">
        <i class="fas fa-plus"></i> Agregar Línea
      </button>

      <?php $adjModulo = 'caja_chica.php'; include __DIR__ . '/includes/adjuntos_form.php'; ?>
      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar</button>
        <a href="caja_chica.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php
$cuentasJson = json_encode(array_map(fn($c) => [
    'id' => $c['id'], 'codigo' => $c['codigo'], 'nombre' => $c['nombre']
], $cuentas));

$extraJs = strtr(<<<'JS'

initDataTable('#tbl-cc');
calcCcTotal();

function calcCcTotal() {
  let sum = 0;
  document.querySelectorAll('#tbody-cc-items .cc-monto').forEach(i => sum += parseFloat(i.value)||0);
  const fmt = 'L. ' + sum.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
  const el1 = document.getElementById('cc-total');
  const el2 = document.getElementById('cc-total-foot');
  if (el1) el1.textContent = fmt;
  if (el2) el2.textContent = fmt;
}

function recalcRow(inp) {
  const tr    = inp.closest('tr');
  const cant  = parseFloat(tr.querySelector('.cc-cant')?.value)  || 0;
  const punit = parseFloat(tr.querySelector('.cc-punit')?.value) || 0;
  const monto = tr.querySelector('.cc-monto');
  if (monto) { monto.value = (cant * punit).toFixed(2); }
  calcCcTotal();
}

function addCcRow() {
  const opts = __CUENTAS_JSON__.map(c=>'<option value="'+c.id+'">'+c.codigo+' — '+c.nombre+'</option>').join('');
  const today = new Date().toISOString().split('T')[0];
  const tr = document.createElement('tr');
  tr.className = 'detail-row';
  tr.innerHTML =
    '<td class="row-num"></td>' +
    '<td><input type="text" class="form-control form-control-sm date-input cc-fecha" value="'+today+'" data-field="fecha"></td>' +
    '<td><select class="form-select form-select-sm" data-field="cuenta_id"><option value="">— Sin cuenta —</option>'+opts+'</select></td>' +
    '<td><input type="text" class="form-control form-control-sm" data-field="descripcion" placeholder="Descripción" required></td>' +
    '<td><input type="number" class="form-control form-control-sm text-end cc-cant" value="1" min="0.001" step="0.001" data-field="cantidad" oninput="recalcRow(this)"></td>' +
    '<td><input type="number" class="form-control form-control-sm text-end cc-punit" value="0" min="0" step="0.01" data-field="precio_unitario" oninput="recalcRow(this)"></td>' +
    '<td><input type="number" class="form-control form-control-sm text-end cc-monto" value="0" min="0" step="0.01" data-field="monto" oninput="calcCcTotal()" style="background:#f0f4ff"></td>' +
    '<td><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" onclick="removeDetailRow(this);calcCcTotal()"><i class="fas fa-trash"></i></button></td>';
  document.getElementById('tbody-cc-items').appendChild(tr);
  updateDetailRowNumbers('tbody-cc-items');
  flatpickr(tr.querySelector('.date-input'), { locale:'es', dateFormat:'Y-m-d', allowInput:true });
}

document.getElementById('form-cc')?.addEventListener('submit', function(e) {
  e.preventDefault();
  const items = [];
  document.querySelectorAll('#tbody-cc-items .detail-row').forEach(r => {
    items.push({
      fecha:           r.querySelector('[data-field=fecha]')?.value        || '',
      cuenta_id:       r.querySelector('[data-field=cuenta_id]')?.value    || '',
      descripcion:     r.querySelector('[data-field=descripcion]')?.value  || '',
      cantidad:        r.querySelector('[data-field=cantidad]')?.value      || 1,
      precio_unitario: r.querySelector('[data-field=precio_unitario]')?.value || 0,
      monto:           r.querySelector('[data-field=monto]')?.value         || 0,
    });
  });
  document.getElementById('items-json').value = JSON.stringify(items);
  post('caja_chica.php', new FormData(this)).then(r => {
    if (r.success) {
      Toast.show(r.message, 'success');
      setTimeout(() => Nav.ir('caja_chica.php?action=ver&id=' + r.id), 1000);
    } else Toast.show(r.message, 'error');
  }).catch(() => Toast.show('Error de comunicación.', 'error'));
});

async function liquidarCC(id) {
  if (!await confirmar('¿Confirma la liquidación de esta caja chica?', { titulo: 'Liquidar caja chica', aceptar: 'Liquidar' })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'liquidar');
  fd.append('id', id);
  const r = await post('caja_chica.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  else Toast.show(r.message, 'error');
}

JS, [
    '__CUENTAS_JSON__' => $cuentasJson,
]);

include __DIR__ . '/includes/footer.php';
?>

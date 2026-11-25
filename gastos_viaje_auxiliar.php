<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$pagina = 'Auxiliar de Viáticos';

// ── Saldo de cada anticipo ────────────────────────────────────────
//   saldo = anticipo − liquidado − reintegrado + reembolsado
//   > 0: el beneficiario aún debe reintegrar · < 0: la empresa aún le debe · 0: saldado.
// Reembolsado: órdenes de pago PAGADAS de sus liquidaciones (lo gastado por encima del anticipo).
// Reintegrado: lo que el beneficiario devolvió, registrado aquí (gastos_viaje.reintegro_*).
// Quien solo ve sus solicitudes ve sus anticipos, pero no registra reintegros.
$puedeRegistrar = !soloRegistrosPropios();
$verPagos = puedeAcceder('compras_pago.php');

function consultaAuxiliar(string $where): string {
    return "
    SELECT
        gv.id            AS gv_id,
        gv.numero        AS gv_numero,
        gv.tipo          AS gv_tipo,
        gv.fecha_salida,
        gv.fecha_regreso,
        gv.destino,
        gv.viaticos_anticipados,
        gv.estado        AS gv_estado,
        gv.reintegro_monto,
        gv.reintegro_fecha,
        gv.reintegro_referencia,
        -- Beneficiario: un empleado o un proveedor/consultor (config/auth.php)
        IF(gv.proveedor_id IS NULL, CONCAT('e', gv.empleado_id), CONCAT('p', gv.proveedor_id)) AS emp_id,
        COALESCE(e.codigo, pr.rtn) AS emp_codigo,
        " . SQL_BENEFICIARIO_GASTO . " AS emp_nombre,
        IF(gv.proveedor_id IS NULL, e.cargo, 'Proveedor / consultor') AS cargo,
        COALESCE(liq.total_liquidado, 0)   AS total_liquidado,
        COALESCE(liq.num_liquidaciones, 0) AS num_liquidaciones,
        COALESCE(reem.reembolsado, 0)      AS reembolsado,
        COALESCE(reem.reembolso_pendiente, 0) AS reembolso_pendiente,
        reem.ops_reembolso,
        opa.op_id     AS op_anticipo_id,
        opa.op_numero AS op_anticipo_numero,
        opa.op_pagada AS op_anticipo_pagada
    FROM gastos_viaje gv
    " . joinBeneficiarioGasto('gv') . "
    LEFT JOIN (
        SELECT viaje_id,
               SUM(monto_total) AS total_liquidado,
               COUNT(*)         AS num_liquidaciones
        FROM solicitud_gastos
        WHERE estado != 'rechazada' AND viaje_id IS NOT NULL
        GROUP BY viaje_id
    ) liq ON liq.viaje_id = gv.id
    LEFT JOIN (
        -- Órdenes de pago de reembolso de sus liquidaciones (manuales por solicitud_gastos_id o automáticas por origen)
        SELECT sg.viaje_id,
               SUM(IF(op.estado = 'pagada', op.monto, 0))  AS reembolsado,
               SUM(IF(op.estado <> 'pagada', op.monto, 0)) AS reembolso_pendiente,
               GROUP_CONCAT(CONCAT(op.numero, IF(op.estado = 'pagada', ' (pagada)', ' (pendiente)')) ORDER BY op.id SEPARATOR ', ') AS ops_reembolso
        FROM ordenes_pago op
        JOIN solicitud_gastos sg
          ON sg.id = COALESCE(op.solicitud_gastos_id, IF(op.origen_tabla = 'solicitud_gastos', op.origen_id, NULL))
        WHERE op.estado <> 'anulada' AND sg.viaje_id IS NOT NULL AND sg.estado != 'rechazada'
        GROUP BY sg.viaje_id
    ) reem ON reem.viaje_id = gv.id
    LEFT JOIN (
        SELECT origen_id, MIN(id) AS op_id, MIN(numero) AS op_numero, MAX(estado = 'pagada') AS op_pagada
        FROM ordenes_pago
        WHERE origen_tabla = 'gastos_viaje' AND estado <> 'anulada'
        GROUP BY origen_id
    ) opa ON opa.origen_id = gv.id
    WHERE $where
    ORDER BY COALESCE(e.apellidos, pr.nombre), e.nombre, gv.fecha_salida DESC";
}

/** Saldo antes del reintegro (lo que el beneficiario debería devolver) y saldo final. */
function saldosAnticipo(array $f): array {
    $antesReintegro = round((float)$f['viaticos_anticipados'] - (float)$f['total_liquidado'] + (float)$f['reembolsado'], 2);
    return [$antesReintegro, round($antesReintegro - (float)$f['reintegro_monto'], 2)];
}

// ── AJAX: registrar o quitar el reintegro de un anticipo ──────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$puedeRegistrar) jsonErr('Su puesto no puede registrar reintegros.', 403);
    $_act = $_POST['_action'] ?? '';
    $id   = (int)($_POST['id'] ?? 0);

    $s = $pdo->prepare(consultaAuxiliar('gv.id = ?'));
    $s->execute([$id]);
    $f = $s->fetch();
    if (!$f) jsonErr('Anticipo no encontrado.');

    if ($_act === 'reintegro') {
        [$debe] = saldosAnticipo($f);
        $monto = round((float)str_replace(',', '', (string)($_POST['monto'] ?? '0')), 2);
        $fecha = trim((string)($_POST['fecha'] ?? ''));
        $ref   = mb_substr(trim((string)($_POST['referencia'] ?? '')), 0, 200);
        if ($debe <= 0) jsonErr('Este anticipo no tiene saldo a favor de la empresa: no hay nada que reintegrar.');
        if ($monto <= 0) jsonErr('El monto reintegrado debe ser mayor que cero.');
        if ($monto > $debe + 0.004) jsonErr('El monto reintegrado no puede ser mayor que lo que debe reintegrar (' . lps($debe) . ').');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) jsonErr('Indique la fecha del reintegro.');

        $pdo->prepare("UPDATE gastos_viaje SET reintegro_monto = ?, reintegro_fecha = ?, reintegro_referencia = ?, reintegro_usuario_id = ? WHERE id = ?")
            ->execute([$monto, $fecha, $ref !== '' ? $ref : null, $_SESSION['usuario_id'] ?? null, $id]);
        registrarAuditoria($pdo, 'UPDATE', 'gastos_viaje', $id, "Reintegro del anticipo {$f['gv_numero']}: " . lps($monto) . " el $fecha" . ($ref !== '' ? " — $ref" : ''));
        jsonOk(['id' => $id], $monto + 0.004 >= $debe ? "Anticipo {$f['gv_numero']} saldado." : "Reintegro parcial registrado: quedan " . lps($debe - $monto) . ' por reintegrar.');
    }

    if ($_act === 'quitar_reintegro') {
        $pdo->prepare("UPDATE gastos_viaje SET reintegro_monto = 0, reintegro_fecha = NULL, reintegro_referencia = NULL, reintegro_usuario_id = NULL WHERE id = ?")
            ->execute([$id]);
        registrarAuditoria($pdo, 'UPDATE', 'gastos_viaje', $id, "Quitó el reintegro de " . lps((float)$f['reintegro_monto']) . " del anticipo {$f['gv_numero']}");
        jsonOk(['id' => $id], 'Reintegro quitado.');
    }
    jsonErr('Acción no válida.');
}

// ── Datos principales ─────────────────────────────────────────────
[$fp, $fpParams] = filtroPropios('gastos_viajes.php', 'gv');
$filas = $pdo->prepare(consultaAuxiliar($fp));
$filas->execute($fpParams);
$filas = $filas->fetchAll();

$soloPendientes = ($_GET['pendientes'] ?? '') === '1';

// ── Agrupar por empleado ──────────────────────────────────────────
$porEmpleado = [];
$totales = ['anticipo' => 0, 'liquidado' => 0, 'reintegrar' => 0, 'favor_emp' => 0, 'reintegrado' => 0, 'reembolsado' => 0];
$ocultos = 0;

foreach ($filas as $f) {
    [$f['debe'], $f['saldo']] = saldosAnticipo($f);
    if ($soloPendientes && abs($f['saldo']) < 0.005) { $ocultos++; continue; }

    $eid = $f['emp_id'];
    if (!isset($porEmpleado[$eid])) {
        $porEmpleado[$eid] = [
            'nombre'    => $f['emp_nombre'],
            'cargo'     => $f['cargo'],
            'codigo'    => $f['emp_codigo'],
            'viajes'    => [],
            'anticipo'  => 0,
            'liquidado' => 0,
            'ajustes'   => 0,   // reembolsado − reintegrado
            'saldo'     => 0,
        ];
    }
    $porEmpleado[$eid]['viajes'][]   = $f;
    $porEmpleado[$eid]['anticipo']  += $f['viaticos_anticipados'];
    $porEmpleado[$eid]['liquidado'] += $f['total_liquidado'];
    $porEmpleado[$eid]['ajustes']   += $f['reembolsado'] - $f['reintegro_monto'];
    $porEmpleado[$eid]['saldo']     += $f['saldo'];

    $totales['anticipo']    += $f['viaticos_anticipados'];
    $totales['liquidado']   += $f['total_liquidado'];
    $totales['reintegrado'] += $f['reintegro_monto'];
    $totales['reembolsado'] += $f['reembolsado'];
    if ($f['saldo'] > 0) $totales['reintegrar'] += $f['saldo'];
    if ($f['saldo'] < 0) $totales['favor_emp']  += abs($f['saldo']);
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-scale-balanced"></i> Auxiliar Contable — Anticipos (Viáticos y Compras)</h1>
  <div class="d-flex gap-2">
    <?php if ($soloPendientes): ?>
    <a href="<?= BASE_URL ?>gastos_viaje_auxiliar.php" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-list"></i> Ver todos<?= $ocultos ? " ($ocultos saldado" . ($ocultos > 1 ? 's' : '') . ' oculto' . ($ocultos > 1 ? 's' : '') . ')' : '' ?>
    </a>
    <?php else: ?>
    <a href="<?= BASE_URL ?>gastos_viaje_auxiliar.php?pendientes=1" class="btn btn-sm btn-outline-primary">
      <i class="fas fa-filter"></i> Solo con saldo pendiente
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- ── Tarjetas de resumen ───────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-blue kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-wallet"></i></div>
      <div class="kpi-value"><?= lps($totales['anticipo']) ?></div>
      <div class="kpi-label">Total Anticipos</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-teal kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-receipt"></i></div>
      <div class="kpi-value"><?= lps($totales['liquidado']) ?></div>
      <div class="kpi-label">Total Liquidado</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-yellow kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-rotate-left"></i></div>
      <div class="kpi-value"><?= lps($totales['reintegrar']) ?></div>
      <div class="kpi-label">Emp. debe reintegrar</div>
      <div class="kpi-note">Pendiente · ya reintegrado <?= lps($totales['reintegrado']) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="kpi-card kpi-green kpi-sm kpi-hover">
      <div class="kpi-icon"><i class="fas fa-arrow-right-to-bracket"></i></div>
      <div class="kpi-value"><?= lps($totales['favor_emp']) ?></div>
      <div class="kpi-label">Empresa debe al emp.</div>
      <div class="kpi-note">Pendiente · ya pagado <?= lps($totales['reembolsado']) ?></div>
    </div>
  </div>
</div>

<!-- ── Tabla auxiliar ────────────────────────────────────────────── -->
<?php if (!$porEmpleado): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
  <?= $soloPendientes && $ocultos ? 'Todos los anticipos están saldados.' : 'No hay anticipos de viáticos registrados.' ?>
</div></div>
<?php else: ?>

<?php foreach ($porEmpleado as $emp): ?>
<?php
$balEmp  = round($emp['saldo'], 2);
$empClass = $balEmp > 0 ? '#d97706' : ($balEmp < 0 ? '#16a34a' : '#64748b');
?>
<div class="card mb-3">
  <!-- Encabezado del empleado -->
  <div class="card-header" style="background:var(--surface-2);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <div>
      <span style="font-weight:700;font-size:.95rem"><?= htmlspecialchars($emp['nombre'] ?? '') ?></span>
      <?php if ($emp['cargo']): ?>
      <span style="font-size:.75rem;color:var(--text-3);margin-left:6px"><?= htmlspecialchars($emp['cargo']) ?></span>
      <?php endif; ?>
      <?php if ($emp['codigo']): ?><span class="badge bg-secondary ms-2" style="font-size:.6rem"><?= htmlspecialchars($emp['codigo']) ?></span><?php endif; ?>
    </div>
    <div class="ms-auto d-flex gap-3 flex-wrap" style="font-size:.8rem;">
      <span>Anticipos: <strong class="font-mono"><?= lps($emp['anticipo']) ?></strong></span>
      <span>Liquidado: <strong class="font-mono"><?= lps($emp['liquidado']) ?></strong></span>
      <span style="color:<?= $empClass ?>">
        <?= $balEmp > 0 ? 'Por reintegrar:' : ($balEmp < 0 ? 'Empresa debe:' : 'Saldado:') ?>
        <strong class="font-mono"><?= lps(abs($balEmp)) ?></strong>
      </span>
    </div>
  </div>

  <!-- Detalle de viajes -->
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table-proscala w-100 mb-0">
      <thead>
        <tr>
          <th>Número</th>
          <th>Tipo</th>
          <th>Destino / Concepto</th>
          <th>Salida</th>
          <th>Regreso</th>
          <th>Estado</th>
          <th class="text-end">Anticipo</th>
          <th class="text-end">Liquidado</th>
          <th class="text-end" title="Reembolsos pagados con orden de pago (+) y reintegros del beneficiario (−)">Pagado / Reintegrado</th>
          <th class="text-end">Saldo</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($emp['viajes'] as $v):
          $bal = (float)$v['saldo'];
          if (abs($bal) < 0.005) {
              $balColor = '#16a34a'; $balIcon = '✓'; $balLabel = 'Saldado';
          } elseif ($bal > 0 && $v['num_liquidaciones'] == 0 && $v['reintegro_monto'] == 0) {
              $balColor = '#64748b'; $balIcon = '⏳'; $balLabel = 'Sin liquidar';
          } elseif ($bal > 0) {
              $balColor = '#d97706'; $balIcon = '↩'; $balLabel = $v['reintegro_monto'] > 0 ? 'Reintegro parcial' : 'Emp. reintegra';
          } else {
              $balColor = '#2563eb'; $balIcon = '↪'; $balLabel = $v['reembolso_pendiente'] > 0 ? 'Empresa debe · OP por pagar' : 'Empresa debe';
          }
        ?>
        <tr>
          <td class="font-mono" style="font-size:.82rem"><?= htmlspecialchars($v['gv_numero']) ?></td>
          <td><?= tipoAnticipoBadge($v['gv_tipo']) ?></td>
          <td><?= htmlspecialchars($v['destino']) ?></td>
          <td><?= fmtFecha($v['fecha_salida']) ?></td>
          <td><?= fmtFecha($v['fecha_regreso']) ?></td>
          <td><?= estadoBadge($v['gv_estado']) ?></td>
          <td class="font-mono text-end">
            <?= lps($v['viaticos_anticipados']) ?>
            <?php if ($v['op_anticipo_numero']): ?>
            <div style="font-size:.65rem;font-weight:400;color:<?= $v['op_anticipo_pagada'] ? '#16a34a' : '#d97706' ?>">
              <?php if ($verPagos): ?><a href="<?= BASE_URL ?>compras_pago.php?action=ver&id=<?= (int)$v['op_anticipo_id'] ?>" style="color:inherit"><?php endif; ?>
              <?= htmlspecialchars($v['op_anticipo_numero']) ?> <?= $v['op_anticipo_pagada'] ? 'pagada' : 'por pagar' ?>
              <?php if ($verPagos): ?></a><?php endif; ?>
            </div>
            <?php endif; ?>
          </td>
          <td class="font-mono text-end"><?= $v['num_liquidaciones'] > 0 ? lps($v['total_liquidado']) : '<span style="color:var(--text-3)">—</span>' ?></td>
          <td class="font-mono text-end" style="font-size:.82rem">
            <?php if ($v['reembolsado'] > 0 || $v['reembolso_pendiente'] > 0): ?>
            <div style="color:#2563eb" title="<?= htmlspecialchars('Órdenes de pago de reembolso: ' . $v['ops_reembolso']) ?>">
              + <?= lps($v['reembolsado']) ?>
              <div style="font-size:.65rem;font-weight:400">reembolso pagado<?= $v['reembolso_pendiente'] > 0 ? ' · ' . lps($v['reembolso_pendiente']) . ' por pagar' : '' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($v['reintegro_monto'] > 0): ?>
            <div style="color:#d97706" title="<?= htmlspecialchars(trim('Reintegrado el ' . fmtFecha($v['reintegro_fecha']) . ' ' . ($v['reintegro_referencia'] ?? ''))) ?>">
              − <?= lps($v['reintegro_monto']) ?>
              <div style="font-size:.65rem;font-weight:400">reintegrado <?= fmtFecha($v['reintegro_fecha']) ?><?= $v['reintegro_referencia'] ? ' · ' . htmlspecialchars($v['reintegro_referencia']) : '' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($v['reembolsado'] <= 0 && $v['reembolso_pendiente'] <= 0 && $v['reintegro_monto'] <= 0): ?>
            <span style="color:var(--text-3)">—</span>
            <?php endif; ?>
          </td>
          <td class="font-mono text-end" style="color:<?= $balColor ?>;font-weight:600">
            <?= $balIcon ?> <?= lps(abs($bal)) ?>
            <div style="font-size:.65rem;font-weight:400"><?= $balLabel ?></div>
          </td>
          <td style="white-space:nowrap">
            <a href="<?= BASE_URL ?>gastos_viajes.php?action=ver&id=<?= $v['gv_id'] ?>"
               class="btn btn-sm btn-outline-primary py-0 px-2" title="Ver anticipo">
              <i class="fas fa-eye"></i>
            </a>
            <?php if ($v['num_liquidaciones'] > 0): ?>
            <a href="<?= BASE_URL ?>gastos_solicitud.php?viaje_id=<?= $v['gv_id'] ?>"
               class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1" title="Ver liquidación">
              <i class="fas fa-file-invoice-dollar"></i>
            </a>
            <?php else: ?>
            <a href="<?= BASE_URL ?>gastos_solicitud.php?action=nuevo&viaje_id=<?= $v['gv_id'] ?>"
               class="btn btn-sm btn-outline-success py-0 px-2 ms-1" title="Registrar liquidación">
              <i class="fas fa-plus"></i>
            </a>
            <?php endif; ?>
            <?php if ($puedeRegistrar && ($v['debe'] > 0 || $v['reintegro_monto'] > 0)): ?>
            <button type="button" class="btn btn-sm btn-outline-warning py-0 px-2 ms-1"
                    title="<?= $v['reintegro_monto'] > 0 ? 'Modificar o quitar el reintegro' : 'Registrar reintegro del beneficiario' ?>"
                    onclick='abrirReintegro(<?= htmlspecialchars(json_encode([
                        'id' => (int)$v['gv_id'], 'numero' => $v['gv_numero'], 'nombre' => $emp['nombre'],
                        'debe' => (float)$v['debe'], 'monto' => (float)$v['reintegro_monto'],
                        'fecha' => $v['reintegro_fecha'], 'referencia' => $v['reintegro_referencia'],
                    ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>
              <i class="fas fa-hand-holding-dollar"></i>
            </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:var(--surface-2);font-weight:600;font-size:.82rem">
          <td colspan="6" class="text-end" style="padding:7px 10px">Subtotal empleado:</td>
          <td class="font-mono text-end" style="padding:7px 10px"><?= lps($emp['anticipo']) ?></td>
          <td class="font-mono text-end" style="padding:7px 10px"><?= lps($emp['liquidado']) ?></td>
          <td class="font-mono text-end" style="padding:7px 10px"><?= abs($emp['ajustes']) >= 0.005 ? ($emp['ajustes'] > 0 ? '+ ' : '− ') . lps(abs($emp['ajustes'])) : '—' ?></td>
          <td class="font-mono text-end" style="padding:7px 10px;color:<?= $empClass ?>">
            <?= lps(abs($balEmp)) ?>
            <div style="font-size:.65rem;font-weight:400"><?= $balEmp > 0 ? 'por reintegrar' : ($balEmp < 0 ? 'empresa debe' : 'saldado') ?></div>
          </td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Totales globales -->
<div class="card" style="border:2px solid var(--border)">
  <div class="card-body p-0">
    <table class="table-proscala w-100 mb-0">
      <tfoot>
        <tr style="font-weight:700;font-size:.9rem;background:var(--surface-2)">
          <td class="text-end" style="padding:10px 12px">TOTAL GENERAL</td>
          <td class="font-mono text-end" style="padding:10px 12px"><span style="font-size:.65rem;font-weight:400">Anticipos</span><br><?= lps($totales['anticipo']) ?></td>
          <td class="font-mono text-end" style="padding:10px 12px"><span style="font-size:.65rem;font-weight:400">Liquidado</span><br><?= lps($totales['liquidado']) ?></td>
          <td class="font-mono text-end" style="padding:10px 12px">
            <?php if ($totales['reintegrar'] > 0): ?>
            <div style="color:#d97706">↩ <?= lps($totales['reintegrar']) ?> <span style="font-size:.65rem;font-weight:400">emp. reintegra</span></div>
            <?php endif; ?>
            <?php if ($totales['favor_emp'] > 0): ?>
            <div style="color:#2563eb">↪ <?= lps($totales['favor_emp']) ?> <span style="font-size:.65rem;font-weight:400">empresa debe</span></div>
            <?php endif; ?>
            <?php if ($totales['reintegrar'] == 0 && $totales['favor_emp'] == 0): ?>
            <span style="color:#16a34a">✓ Todo saldado</span>
            <?php endif; ?>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<?php endif; ?>

<?php if ($puedeRegistrar): ?>
<!-- Modal: reintegro del beneficiario -->
<div class="modal fade" id="modal-reintegro" tabindex="-1">
  <div class="modal-dialog">
    <form class="modal-content" id="form-reintegro">
      <div class="modal-header py-2">
        <h6 class="modal-title mb-0"><i class="fas fa-hand-holding-dollar"></i> Reintegro del anticipo <span id="r-numero"></span></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="_action" value="reintegro">
        <input type="hidden" name="id" id="r-id">
        <p style="font-size:.85rem" class="mb-3">
          <span id="r-nombre"></span> debe reintegrar <strong class="font-mono" id="r-debe"></strong>.
          Registre lo que ya devolvió; si devolvió menos, el resto sigue pendiente.
        </p>
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label" style="font-size:.8rem">Monto reintegrado</label>
            <input type="number" step="0.01" min="0.01" name="monto" id="r-monto" class="form-control form-control-sm" required>
          </div>
          <div class="col-6">
            <label class="form-label" style="font-size:.8rem">Fecha</label>
            <input type="date" name="fecha" id="r-fecha" class="form-control form-control-sm" required>
          </div>
          <div class="col-12">
            <label class="form-label" style="font-size:.8rem">Referencia (opcional)</label>
            <input type="text" name="referencia" id="r-ref" maxlength="200" class="form-control form-control-sm" placeholder="Ej: Recibo 0123, depósito Banco Atlántida">
          </div>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-outline-danger me-auto" id="r-quitar" onclick="quitarReintegro()">Quitar reintegro</button>
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-sm btn-primary">Guardar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php
$extraJs = !$puedeRegistrar ? '' : <<<'JS'

const fmtL = n => 'L. ' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function abrirReintegro(d) {
  document.getElementById('r-id').value = d.id;
  document.getElementById('r-numero').textContent = d.numero;
  document.getElementById('r-nombre').textContent = d.nombre || 'El beneficiario';
  document.getElementById('r-debe').textContent = fmtL(d.debe);
  const monto = document.getElementById('r-monto');
  monto.max = d.debe.toFixed(2);
  monto.value = (d.monto > 0 ? d.monto : d.debe).toFixed(2);
  document.getElementById('r-fecha').value = d.fecha || new Date().toLocaleDateString('en-CA');
  document.getElementById('r-ref').value = d.referencia || '';
  document.getElementById('r-quitar').classList.toggle('d-none', !(d.monto > 0));
  bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-reintegro')).show();
}

document.getElementById('form-reintegro').addEventListener('submit', async e => {
  e.preventDefault();
  const r = await post('gastos_viaje_auxiliar.php', new FormData(e.target));
  if (r.success) {
    bootstrap.Modal.getInstance(document.getElementById('modal-reintegro'))?.hide();
    Toast.show(r.message, 'success');
    setTimeout(() => Nav.recargar(), 700);
  } else Toast.show(r.message, 'error');
});

async function quitarReintegro() {
  if (!await confirmar('El anticipo vuelve a mostrar el saldo completo por reintegrar.', { titulo: '¿Quitar el reintegro registrado?', aceptar: 'Quitar', peligro: true })) return;
  const fd = new FormData(document.getElementById('form-reintegro'));
  fd.set('_action', 'quitar_reintegro');
  const r = await post('gastos_viaje_auxiliar.php', fd);
  if (r.success) {
    bootstrap.Modal.getInstance(document.getElementById('modal-reintegro'))?.hide();
    Toast.show(r.message, 'success');
    setTimeout(() => Nav.recargar(), 700);
  } else Toast.show(r.message, 'error');
}
JS;
include __DIR__ . '/includes/footer.php';
?>

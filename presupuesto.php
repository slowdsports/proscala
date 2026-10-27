<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Presupuesto';

$anio = (int)($_GET['anio'] ?? date('Y'));
$proyId = (int)($_GET['proyecto_id'] ?? 0);

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';
    if ($_act === 'guardar') {
        $items = json_decode($_POST['items'] ?? '[]', true);
        $pdo->beginTransaction();
        try {
            foreach ($items as $it) {
                $existing = $pdo->prepare("SELECT id FROM presupuesto WHERE anio=? AND cuenta_id=? AND (proyecto_id=? OR (proyecto_id IS NULL AND ? IS NULL))");
                $existing->execute([$it['anio'],$it['cuenta_id'],$it['proyecto_id']??null,$it['proyecto_id']??null]);
                $ex = $existing->fetch();
                if ($ex) {
                    $pdo->prepare("UPDATE presupuesto SET monto_presupuestado=?,notas=? WHERE id=?")
                        ->execute([$it['monto'],$it['notas']??'',$ex['id']]);
                } else {
                    $pdo->prepare("INSERT INTO presupuesto (anio,proyecto_id,cuenta_id,monto_presupuestado,notas) VALUES (?,?,?,?,?)")
                        ->execute([$it['anio'],$it['proyecto_id']??null,$it['cuenta_id'],$it['monto'],$it['notas']??'']);
                }
            }
            $pdo->commit();
            jsonOk([],'Presupuesto guardado.');
        } catch(\Exception $e){ $pdo->rollBack(); jsonErr($e->getMessage()); }
    }
    jsonErr('Acción desconocida.');
}

// Get budget with execution
$cuentasGasto = $pdo->query("SELECT id,codigo,nombre,tipo FROM cuentas_contables WHERE activa=1 AND tipo IN ('gasto') ORDER BY codigo")->fetchAll();
$proyectos = $pdo->query("SELECT id,nombre FROM proyectos WHERE estado='activo' ORDER BY nombre")->fetchAll();

// Budget vs actual
$query = "SELECT p.cuenta_id, cc.codigo, cc.nombre, p.monto_presupuestado,
    COALESCE(SUM(CASE WHEN sg.estado IN ('aprobada','pagada') AND YEAR(sg.fecha)=? THEN sgd.monto ELSE 0 END),0)
    + COALESCE(SUM(CASE WHEN gv.estado IN ('aprobada','liquidada') AND YEAR(gv.fecha_salida)=? THEN gvd.monto ELSE 0 END),0)
    + COALESCE(SUM(CASE WHEN ocd.id IS NOT NULL AND YEAR(oc.fecha)=? THEN ocd.total ELSE 0 END),0) as ejecutado
    FROM presupuesto p
    JOIN cuentas_contables cc ON p.cuenta_id=cc.id
    LEFT JOIN solicitud_gastos_detalle sgd ON sgd.cuenta_id=p.cuenta_id
    LEFT JOIN solicitud_gastos sg ON sgd.solicitud_id=sg.id
    LEFT JOIN gastos_viaje_detalle gvd ON gvd.cuenta_id=p.cuenta_id
    LEFT JOIN gastos_viaje gv ON gvd.viaje_id=gv.id
    LEFT JOIN ordenes_compra_detalle ocd ON ocd.cuenta_id=p.cuenta_id
    LEFT JOIN ordenes_compra oc ON ocd.orden_id=oc.id
    WHERE p.anio=?" . ($proyId ? " AND p.proyecto_id=?" : " AND p.proyecto_id IS NULL") . "
    GROUP BY p.cuenta_id, cc.codigo, cc.nombre, p.monto_presupuestado ORDER BY cc.codigo";
$stmt = $pdo->prepare($query);
$params = [$anio,$anio,$anio,$anio];
if ($proyId) $params[] = $proyId;
$stmt->execute($params);
$presupuesto = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-chart-pie"></i> Presupuesto <?= $anio ?></h1>
  <div class="d-flex gap-2 align-items-center">
    <select class="form-select form-select-sm" id="sel-proyecto" onchange="Nav.ir('presupuesto.php?anio='+document.getElementById('sel-anio').value+'&proyecto_id='+this.value)" style="width:200px">
      <option value="0">-- General --</option>
      <?php foreach($proyectos as $p): ?><option value="<?= $p['id'] ?>" <?= $proyId==$p['id']?'selected':'' ?>><?= htmlspecialchars($p['nombre']) ?></option><?php endforeach; ?>
    </select>
    <select class="form-select form-select-sm" id="sel-anio" onchange="Nav.ir('presupuesto.php?anio='+this.value+'&proyecto_id=<?= $proyId ?>')" style="width:100px">
      <?php for($y=2022;$y<=date('Y')+2;$y++): ?><option value="<?= $y ?>" <?= $y==$anio?'selected':'' ?>><?= $y ?></option><?php endfor; ?>
    </select>
    <button class="btn-proscala" data-bs-toggle="modal" data-bs-target="#modal-presupuesto"><i class="fas fa-edit"></i> Editar Presupuesto</button>
  </div>
</div>

<?php if ($presupuesto): ?>
<!-- Resumen total -->
<?php
$totPres = array_sum(array_column($presupuesto,'monto_presupuestado'));
$totEjec = array_sum(array_column($presupuesto,'ejecutado'));
$pct = $totPres > 0 ? min(100, round($totEjec / $totPres * 100, 1)) : 0;
?>
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="kpi-card kpi-blue kpi-sm">
      <div class="kpi-icon"><i class="fas fa-chart-pie"></i></div>
      <div class="kpi-value"><?= lps($totPres) ?></div>
      <div class="kpi-label">Total Presupuestado</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="kpi-card kpi-<?= $pct>90?'red':($pct>70?'yellow':'green') ?> kpi-sm">
      <div class="kpi-icon"><i class="fas fa-circle-dollar-to-slot"></i></div>
      <div class="kpi-value"><?= lps($totEjec) ?></div>
      <div class="kpi-label">Total Ejecutado</div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="kpi-card kpi-<?= $pct>90?'red':($pct>70?'yellow':'teal') ?>">
      <div class="kpi-icon"><i class="fas fa-percent"></i></div>
      <div class="kpi-value"><?= $pct ?>%</div>
      <div class="kpi-label">% Ejecución</div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><i class="fas fa-table"></i> Ejecución por Cuenta</div>
  <div class="card-body p-0">
    <table class="table-proscala w-100">
      <thead><tr><th>Cuenta</th><th>Nombre</th><th class="text-end">Presupuestado</th><th class="text-end">Ejecutado</th><th class="text-end">Saldo</th><th style="width:200px">% Ejecución</th></tr></thead>
      <tbody>
        <?php foreach($presupuesto as $r):
          $pctR = $r['monto_presupuestado'] > 0 ? min(100, round($r['ejecutado'] / $r['monto_presupuestado'] * 100, 1)) : 0;
          $saldo = $r['monto_presupuestado'] - $r['ejecutado'];
          $barCls = $pctR > 90 ? 'over' : ($pctR > 70 ? 'warn' : '');
        ?>
        <tr>
          <td class="font-mono"><?= $r['codigo'] ?></td>
          <td><?= htmlspecialchars($r['nombre']) ?></td>
          <td class="font-mono text-end"><?= lps($r['monto_presupuestado']) ?></td>
          <td class="font-mono text-end <?= $r['ejecutado']>$r['monto_presupuestado']?'text-danger':'' ?>"><?= lps($r['ejecutado']) ?></td>
          <td class="font-mono text-end <?= $saldo<0?'text-danger':'text-success' ?>"><?= lps($saldo) ?></td>
          <td>
            <div class="budget-bar-wrap"><div class="budget-bar <?= $barCls ?>" style="width:<?= $pctR ?>%"></div></div>
            <div style="font-size:.72rem;text-align:right"><?= $pctR ?>%</div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" class="fw-bold">TOTALES</td>
          <td class="font-mono text-end fw-bold"><?= lps($totPres) ?></td>
          <td class="font-mono text-end fw-bold"><?= lps($totEjec) ?></td>
          <td class="font-mono text-end fw-bold <?= ($totPres-$totEjec)<0?'text-danger':'text-success' ?>"><?= lps($totPres-$totEjec) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php else: ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="fas fa-chart-pie fa-3x mb-3 d-block"></i>
  No hay presupuesto definido para <?= $anio ?>.
  <br><button class="btn-proscala mt-3" data-bs-toggle="modal" data-bs-target="#modal-presupuesto"><i class="fas fa-plus"></i> Definir Presupuesto</button>
</div></div>
<?php endif; ?>

<!-- Modal editar presupuesto -->
<div class="modal fade" id="modal-presupuesto" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Editar Presupuesto <?= $anio ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" style="max-height:60vh;overflow-y:auto;">
        <form id="form-pres">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar">
          <input type="hidden" name="items" id="pres-items">
          <table class="table-proscala w-100">
            <thead><tr><th>Cuenta</th><th>Nombre</th><th>Monto Presupuestado (L.)</th><th>Notas</th></tr></thead>
            <tbody>
              <?php
              // Load existing budget for this year
              $existing = [];
              $s=$pdo->prepare("SELECT * FROM presupuesto WHERE anio=?".($proyId?" AND proyecto_id=?":" AND proyecto_id IS NULL"));
              $params2=[$anio]; if($proyId) $params2[]=$proyId;
              $s->execute($params2);
              foreach($s->fetchAll() as $ex) $existing[$ex['cuenta_id']]=$ex;
              foreach($cuentasGasto as $c): ?>
              <tr>
                <td class="font-mono" style="width:100px"><?= $c['codigo'] ?></td>
                <td><?= htmlspecialchars($c['nombre']) ?></td>
                <td><input type="number" class="form-control form-control-sm text-end" step="0.01" min="0"
                  data-cid="<?= $c['id'] ?>" data-anio="<?= $anio ?>" data-proy="<?= $proyId ?>"
                  value="<?= $existing[$c['id']]['monto_presupuestado'] ?? 0 ?>"></td>
                <td><input type="text" class="form-control form-control-sm" data-notes="<?= $c['id'] ?>"
                  value="<?= htmlspecialchars($existing[$c['id']]['notas'] ?? '') ?>"></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="savePresupuesto()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = "
async function savePresupuesto(){
  const items=[];
  document.querySelectorAll('[data-cid]').forEach(inp=>{
    const notes=document.querySelector('[data-notes=\"'+inp.dataset.cid+'\"]')?.value||'';
    items.push({anio:inp.dataset.anio,proyecto_id:inp.dataset.proy||null,cuenta_id:inp.dataset.cid,monto:inp.value,notas:notes});
  });
  document.getElementById('pres-items').value=JSON.stringify(items);
  const r=await post('presupuesto.php',new FormData(document.getElementById('form-pres')));
  if(r.success){Toast.show(r.message,'success');setTimeout(()=>Nav.recargar(),1200);}
  else Toast.show(r.message,'error');
}
";
include __DIR__ . '/includes/footer.php';
?>

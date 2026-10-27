<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Documentos Internos';

$TIPOS = ['memo' => 'Memorándum', 'circular' => 'Circular', 'comunicado' => 'Comunicado'];
$PREFIJOS = ['memo' => 'MEMO', 'circular' => 'CIRC', 'comunicado' => 'COM'];

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'guardar') {
        $mId    = (int)($_POST['id'] ?? 0);
        $tipo   = isset($TIPOS[$_POST['tipo'] ?? '']) ? $_POST['tipo'] : 'memo';
        $fecha  = $_POST['fecha'] ?? date('Y-m-d');
        $empId  = (int)($_POST['de_empleado_id'] ?? 0) ?: null;
        $deCargo= trim($_POST['de_cargo'] ?? '');
        $para   = trim($_POST['para_texto'] ?? '');
        $asunto = trim($_POST['asunto'] ?? '');
        $cuerpo = trim($_POST['cuerpo'] ?? '');
        $notas  = trim($_POST['notas'] ?? '');

        if (!$para)   jsonErr('El campo "Para" es obligatorio.');
        if (!$asunto) jsonErr('El asunto es obligatorio.');
        if (!$cuerpo) jsonErr('El contenido del documento es obligatorio.');

        if ($mId) {
            $pdo->prepare("UPDATE memorandos SET tipo=?,fecha=?,de_empleado_id=?,de_cargo=?,
                para_texto=?,asunto=?,cuerpo=?,notas=?
                WHERE id=? AND estado='borrador'")
                ->execute([$tipo,$fecha,$empId,$deCargo,$para,$asunto,$cuerpo,$notas,$mId]);
            registrarAuditoria($pdo,'UPDATE','memorandos',$mId,"Actualizado: $asunto");
        } else {
            $pref = $PREFIJOS[$tipo] ?? 'MEMO';
            $num  = generarNumero($pdo,'memorandos','numero',$pref);
            $pdo->prepare("INSERT INTO memorandos
                (numero,tipo,fecha,de_empleado_id,de_cargo,para_texto,asunto,cuerpo,notas)
                VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$num,$tipo,$fecha,$empId,$deCargo,$para,$asunto,$cuerpo,$notas]);
            $mId = (int)$pdo->lastInsertId();
            registrarAuditoria($pdo,'INSERT','memorandos',$mId,"Creado: $num — $asunto");
        }
        jsonOk(['id' => $mId], 'Documento guardado.');
    }

    if ($_act === 'emitir') {
        $mId = (int)$_POST['id'];
        $pdo->prepare("UPDATE memorandos SET estado='emitido' WHERE id=? AND estado='borrador'")->execute([$mId]);
        registrarAuditoria($pdo,'UPDATE','memorandos',$mId,'Emitido');
        jsonOk([], 'Documento emitido correctamente.');
    }

    if ($_act === 'eliminar') {
        $mId = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM memorandos WHERE id=? AND estado='borrador'")->execute([$mId]);
        registrarAuditoria($pdo,'DELETE','memorandos',$mId,'Eliminado');
        jsonOk([], 'Documento eliminado.');
    }

    jsonErr('Acción desconocida.');
}

// ── Carga ─────────────────────────────────────────────────────────
$doc = null;
if (in_array($action, ['ver','editar']) && $id) {
    $s = $pdo->prepare("SELECT m.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre_full
        FROM memorandos m LEFT JOIN empleados e ON m.de_empleado_id = e.id
        WHERE m.id = ?");
    $s->execute([$id]); $doc = $s->fetch();
    if (!$doc) { header('Location: memorandos.php'); exit; }
}

$empleados = $pdo->query("SELECT id, CONCAT(nombre,' ',apellidos) AS nombre, cargo
    FROM empleados WHERE activo=1 ORDER BY apellidos,nombre")->fetchAll();

$lista = [];
if ($action === 'list') {
    $lista = $pdo->query("SELECT m.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre_full
        FROM memorandos m LEFT JOIN empleados e ON m.de_empleado_id = e.id
        WHERE " . filtroHistorico('m') . "
        ORDER BY m.id DESC")->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-file-lines"></i> Documentos Internos</h1>
  <?php if ($action === 'list'): ?>
  <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nuevo Documento</a>
  <?php else: ?>
  <a href="memorandos.php" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>

<div class="card">
  <div class="card-body p-0">
    <table id="tbl-memo" class="table-proscala w-100">
      <thead>
        <tr>
          <th>Número</th><th>Tipo</th><th>Asunto</th><th>Remitente</th>
          <th>Fecha</th><th>Estado</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lista as $r):
          $tipoBadge = ['memo'=>'primary','circular'=>'info','comunicado'=>'warning'];
          $bg = $tipoBadge[$r['tipo']] ?? 'secondary';
        ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($r['numero']) ?><?= badgeHistorico($r) ?></td>
          <td><span class="badge bg-<?= $bg ?>"><?= $TIPOS[$r['tipo']] ?? $r['tipo'] ?></span></td>
          <td><?= htmlspecialchars($r['asunto']) ?></td>
          <td style="font-size:.85rem;color:var(--text-2)"><?= htmlspecialchars($r['emp_nombre_full'] ?? '—') ?></td>
          <td><?= fmtFecha($r['fecha']) ?></td>
          <td><?= estadoBadge($r['estado']) ?></td>
          <td style="white-space:nowrap">
            <a href="?action=ver&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2">
              <i class="fas fa-eye"></i>
            </a>
            <?php if ($r['estado'] === 'borrador'): ?>
            <a href="?action=editar&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2 ms-1">
              <i class="fas fa-edit"></i>
            </a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$lista): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No hay documentos registrados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $doc):
  $tipoBadge = ['memo'=>'primary','circular'=>'info','comunicado'=>'warning'];
  $bg = $tipoBadge[$doc['tipo']] ?? 'secondary';
?>

<div class="card">
  <div class="card-header">
    <i class="fas fa-file-lines"></i>
    <strong><?= htmlspecialchars($doc['numero']) ?></strong>
    &nbsp;<span class="badge bg-<?= $bg ?>"><?= $TIPOS[$doc['tipo']] ?? $doc['tipo'] ?></span>
    &nbsp;<?= estadoBadge($doc['estado']) ?>
    <div class="ms-auto d-flex gap-2 no-print">
      <?php if ($doc['estado'] === 'borrador'): ?>
      <a href="?action=editar&id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-edit"></i> Editar
      </a>
      <button class="btn btn-sm btn-success" onclick="emitirDoc(<?= $id ?>)">
        <i class="fas fa-paper-plane"></i> Emitir
      </button>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>print.php?tipo=memo&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
  </div>
  <div class="card-body">
    <table style="font-size:.88rem;border-collapse:collapse;width:100%;max-width:680px;margin-bottom:1rem">
      <tr>
        <td style="font-weight:700;padding:4px 14px 4px 0;width:90px;color:var(--text-3);text-transform:uppercase;font-size:.75rem;letter-spacing:.05em">Para</td>
        <td style="padding:4px 0"><?= nl2br(htmlspecialchars($doc['para_texto'])) ?></td>
      </tr>
      <tr>
        <td style="font-weight:700;padding:4px 14px 4px 0;color:var(--text-3);text-transform:uppercase;font-size:.75rem;letter-spacing:.05em">De</td>
        <td style="padding:4px 0">
          <?= htmlspecialchars($doc['emp_nombre_full'] ?? '—') ?>
          <?php if ($doc['de_cargo']): ?><span style="color:var(--text-3)"> — <?= htmlspecialchars($doc['de_cargo']) ?></span><?php endif; ?>
        </td>
      </tr>
      <tr>
        <td style="font-weight:700;padding:4px 14px 4px 0;color:var(--text-3);text-transform:uppercase;font-size:.75rem;letter-spacing:.05em">Asunto</td>
        <td style="padding:4px 0;font-weight:600"><?= htmlspecialchars($doc['asunto']) ?></td>
      </tr>
      <tr>
        <td style="font-weight:700;padding:4px 14px 4px 0;color:var(--text-3);text-transform:uppercase;font-size:.75rem;letter-spacing:.05em">Fecha</td>
        <td style="padding:4px 0"><?= fmtFecha($doc['fecha']) ?></td>
      </tr>
    </table>
    <hr style="border-color:var(--border-1);margin:1rem 0">
    <div style="font-size:.9rem;line-height:1.9;white-space:pre-wrap"><?= htmlspecialchars($doc['cuerpo']) ?></div>
    <?php if ($doc['notas']): ?>
    <hr style="border-color:var(--border-1);margin:1rem 0">
    <div style="font-size:.8rem;color:var(--text-3)"><strong>Notas internas:</strong> <?= htmlspecialchars($doc['notas']) ?></div>
    <?php endif; ?>
  </div>
</div>

<?php else: /* nuevo / editar */ ?>

<div class="card">
  <div class="card-header">
    <i class="fas fa-file-lines"></i>
    <?= $doc ? 'Editar: ' . htmlspecialchars($doc['numero']) : 'Nuevo Documento Interno' ?>
  </div>
  <div class="card-body">
    <form id="form-memo" action="memorandos.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="guardar">
      <input type="hidden" name="id" value="<?= $doc['id'] ?? 0 ?>">

      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Tipo *</label>
          <select name="tipo" class="form-select" required>
            <?php foreach ($TIPOS as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= ($doc['tipo'] ?? 'memo') === $val ? 'selected' : '' ?>>
              <?= $lbl ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Fecha *</label>
          <input type="text" name="fecha" class="form-control date-input" required
            value="<?= htmlspecialchars($doc['fecha'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Remitente</label>
          <select name="de_empleado_id" class="form-select" id="sel-remitente" onchange="fillCargo(this)">
            <option value="">— Sin asignar —</option>
            <?php foreach ($empleados as $e): ?>
            <option value="<?= $e['id'] ?>" data-cargo="<?= htmlspecialchars($e['cargo'] ?? '') ?>"
              <?= ($doc['de_empleado_id'] ?? 0) == $e['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($e['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Cargo / Posición</label>
          <input type="text" name="de_cargo" id="inp-cargo" class="form-control"
            value="<?= htmlspecialchars($doc['de_cargo'] ?? '') ?>"
            placeholder="Ej: Dirección Ejecutiva">
        </div>
        <div class="col-12">
          <label class="form-label">Para *</label>
          <textarea name="para_texto" class="form-control" rows="2" required
            placeholder="Ej: Todo el Personal / Departamento de Finanzas / Juan Pérez — Coordinador"><?= htmlspecialchars($doc['para_texto'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Asunto *</label>
          <input type="text" name="asunto" class="form-control" required
            value="<?= htmlspecialchars($doc['asunto'] ?? '') ?>"
            placeholder="Breve descripción del tema">
        </div>
        <div class="col-12">
          <label class="form-label">Contenido *</label>
          <textarea name="cuerpo" class="form-control" rows="12" required
            style="font-family:inherit"
            placeholder="Redacte aquí el cuerpo del documento…"><?= htmlspecialchars($doc['cuerpo'] ?? '') ?></textarea>
        </div>
        <div class="col-12">
          <label class="form-label">Notas internas</label>
          <textarea name="notas" class="form-control" rows="1"
            placeholder="Observaciones internas (no aparecen en el PDF)"><?= htmlspecialchars($doc['notas'] ?? '') ?></textarea>
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar</button>
        <a href="memorandos.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php
$extraJs = <<<'JS'
initDataTable('#tbl-memo');

function fillCargo(sel) {
  const opt = sel.options[sel.selectedIndex];
  const cargo = opt.dataset.cargo || '';
  const inp = document.getElementById('inp-cargo');
  if (inp && !inp.value) inp.value = cargo;
}

document.getElementById('form-memo')?.addEventListener('submit', function(e) {
  e.preventDefault();
  post('memorandos.php', new FormData(this)).then(r => {
    if (r.success) {
      Toast.show(r.message, 'success');
      setTimeout(() => Nav.ir('memorandos.php?action=ver&id=' + r.id), 900);
    } else Toast.show(r.message, 'error');
  }).catch(() => Toast.show('Error de comunicación.', 'error'));
});

async function emitirDoc(id) {
  if (!await confirmar('¿Confirma emitir este documento? Ya no podrá editarse.', { titulo: 'Emitir documento', aceptar: 'Emitir' })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'emitir');
  fd.append('id', id);
  const r = await post('memorandos.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  else Toast.show(r.message, 'error');
}
JS;
include __DIR__ . '/includes/footer.php';
?>

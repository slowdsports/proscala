<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Actas de Reunión';

// ── AJAX ──────────────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    if ($_act === 'guardar') {
        $aId    = (int)($_POST['id'] ?? 0);
        $fecha  = $_POST['fecha'] ?? date('Y-m-d');
        $hIni   = $_POST['hora_inicio'] ?? null;
        $hFin   = $_POST['hora_fin'] ?? null;
        $lugar  = trim($_POST['lugar'] ?? '');
        $convoc = trim($_POST['convocado_por'] ?? '');
        $asunto = trim($_POST['asunto'] ?? '');
        $partic = trim($_POST['participantes'] ?? '');
        $agenda = trim($_POST['agenda'] ?? '');
        $desarro= trim($_POST['desarrollo'] ?? '');
        $acuerd = trim($_POST['acuerdos'] ?? '');
        $proxRe = $_POST['proxima_reunion'] ?: null;

        if (!$asunto) jsonErr('El asunto es obligatorio.');

        if ($aId) {
            $pdo->prepare("UPDATE actas_reunion SET fecha=?,hora_inicio=?,hora_fin=?,lugar=?,
                convocado_por=?,asunto=?,participantes=?,agenda=?,desarrollo=?,acuerdos=?,
                proxima_reunion=? WHERE id=? AND estado='borrador'")
                ->execute([$fecha,$hIni,$hFin,$lugar,$convoc,$asunto,$partic,$agenda,$desarro,$acuerd,$proxRe,$aId]);
            registrarAuditoria($pdo,'UPDATE','actas_reunion',$aId,"Actualizada: $asunto");
        } else {
            $num = generarNumero($pdo,'actas_reunion','numero','ACTA');
            $pdo->prepare("INSERT INTO actas_reunion
                (numero,fecha,hora_inicio,hora_fin,lugar,convocado_por,asunto,
                 participantes,agenda,desarrollo,acuerdos,proxima_reunion)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$num,$fecha,$hIni,$hFin,$lugar,$convoc,$asunto,$partic,$agenda,$desarro,$acuerd,$proxRe]);
            $aId = (int)$pdo->lastInsertId();
            registrarAuditoria($pdo,'INSERT','actas_reunion',$aId,"Creada: $num — $asunto");
        }
        jsonOk(['id' => $aId], 'Acta guardada.');
    }

    if ($_act === 'aprobar') {
        $aId = (int)$_POST['id'];
        $pdo->prepare("UPDATE actas_reunion SET estado='aprobada' WHERE id=? AND estado='borrador'")->execute([$aId]);
        registrarAuditoria($pdo,'UPDATE','actas_reunion',$aId,'Aprobada');
        jsonOk([], 'Acta aprobada correctamente.');
    }

    if ($_act === 'eliminar') {
        $aId = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM actas_reunion WHERE id=? AND estado='borrador'")->execute([$aId]);
        registrarAuditoria($pdo,'DELETE','actas_reunion',$aId,'Eliminada');
        jsonOk([], 'Acta eliminada.');
    }

    jsonErr('Acción desconocida.');
}

// ── Carga ─────────────────────────────────────────────────────────
$doc = null;
if (in_array($action, ['ver','editar']) && $id) {
    $s = $pdo->prepare("SELECT * FROM actas_reunion WHERE id = ?");
    $s->execute([$id]); $doc = $s->fetch();
    if (!$doc) { header('Location: actas.php'); exit; }
}

$lista = [];
if ($action === 'list') {
    $lista = $pdo->query("SELECT * FROM actas_reunion a WHERE " . filtroHistorico('a') . " ORDER BY id DESC")->fetchAll();
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-clipboard-list"></i> Actas de Reunión</h1>
  <?php if ($action === 'list'): ?>
  <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nueva Acta</a>
  <?php else: ?>
  <a href="actas.php" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-end mb-2"><?= selectorHistorico() ?></div>

<div class="card">
  <div class="card-body p-0">
    <table id="tbl-actas" class="table-proscala w-100">
      <thead>
        <tr>
          <th>Número</th><th>Asunto</th><th>Fecha</th><th>Lugar</th><th>Estado</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lista as $r): ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($r['numero']) ?><?= badgeHistorico($r) ?></td>
          <td><?= htmlspecialchars($r['asunto']) ?></td>
          <td><?= fmtFecha($r['fecha']) ?></td>
          <td style="font-size:.85rem;color:var(--text-2)"><?= htmlspecialchars($r['lugar'] ?? '—') ?></td>
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
        <tr><td colspan="6" class="text-center text-muted py-4">No hay actas registradas.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'ver' && $doc): ?>

<div class="card mb-3">
  <div class="card-header">
    <i class="fas fa-clipboard-list"></i>
    <strong><?= htmlspecialchars($doc['numero']) ?></strong>
    &nbsp;<?= estadoBadge($doc['estado']) ?>
    <div class="ms-auto d-flex gap-2">
      <?php if ($doc['estado'] === 'borrador'): ?>
      <a href="?action=editar&id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fas fa-edit"></i> Editar
      </a>
      <button class="btn btn-sm btn-success" onclick="aprobarActa(<?= $id ?>)">
        <i class="fas fa-check-circle"></i> Aprobar
      </button>
      <?php endif; ?>
      <a href="<?= BASE_URL ?>print.php?tipo=acta&id=<?= $id ?>" target="_blank"
         class="btn btn-sm btn-outline-danger"><i class="fas fa-file-pdf"></i> PDF</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row g-2 mb-3" style="font-size:.86rem">
      <div class="col-md-2"><strong>Fecha:</strong> <?= fmtFecha($doc['fecha']) ?></div>
      <?php if ($doc['hora_inicio']): ?>
      <div class="col-md-2"><strong>Hora:</strong> <?= substr($doc['hora_inicio'],0,5) ?> – <?= substr($doc['hora_fin']??'',0,5) ?></div>
      <?php endif; ?>
      <?php if ($doc['lugar']): ?>
      <div class="col-md-4"><strong>Lugar:</strong> <?= htmlspecialchars($doc['lugar']) ?></div>
      <?php endif; ?>
      <?php if ($doc['convocado_por']): ?>
      <div class="col-md-4"><strong>Convocado por:</strong> <?= htmlspecialchars($doc['convocado_por']) ?></div>
      <?php endif; ?>
      <div class="col-12"><strong>Asunto:</strong> <?= htmlspecialchars($doc['asunto']) ?></div>
      <?php if ($doc['proxima_reunion']): ?>
      <div class="col-12"><strong>Próxima reunión:</strong> <?= fmtFecha($doc['proxima_reunion']) ?></div>
      <?php endif; ?>
    </div>

    <?php
    $secciones = [
        'participantes' => ['fas fa-users', 'Participantes'],
        'agenda'        => ['fas fa-list-ol', 'Agenda'],
        'desarrollo'    => ['fas fa-comments', 'Desarrollo'],
        'acuerdos'      => ['fas fa-handshake', 'Acuerdos'],
    ];
    foreach ($secciones as $campo => [$icon, $label]):
      if (!$doc[$campo]) continue; ?>
    <div class="form-section-title mt-3">
      <i class="fas <?= $icon ?>"></i> <?= $label ?>
    </div>
    <div style="font-size:.88rem;line-height:1.9;white-space:pre-wrap;padding:6px 0"><?= htmlspecialchars($doc[$campo]) ?></div>
    <?php endforeach; ?>
  </div>
</div>

<?php else: /* nuevo / editar */ ?>

<div class="card">
  <div class="card-header">
    <i class="fas fa-clipboard-list"></i>
    <?= $doc ? 'Editar: ' . htmlspecialchars($doc['numero']) : 'Nueva Acta de Reunión' ?>
  </div>
  <div class="card-body">
    <form id="form-acta" action="actas.php" method="POST">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action" value="guardar">
      <input type="hidden" name="id" value="<?= $doc['id'] ?? 0 ?>">

      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Fecha *</label>
          <input type="text" name="fecha" class="form-control date-input" required
            value="<?= htmlspecialchars($doc['fecha'] ?? date('Y-m-d')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Hora inicio</label>
          <input type="time" name="hora_inicio" class="form-control"
            value="<?= htmlspecialchars(substr($doc['hora_inicio'] ?? '',0,5)) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Hora fin</label>
          <input type="time" name="hora_fin" class="form-control"
            value="<?= htmlspecialchars(substr($doc['hora_fin'] ?? '',0,5)) ?>">
        </div>
        <div class="col-md-5">
          <label class="form-label">Lugar</label>
          <input type="text" name="lugar" class="form-control"
            value="<?= htmlspecialchars($doc['lugar'] ?? '') ?>"
            placeholder="Ej: Sala de reuniones, virtual (Zoom)…">
        </div>
        <div class="col-md-5">
          <label class="form-label">Convocado por</label>
          <input type="text" name="convocado_por" class="form-control"
            value="<?= htmlspecialchars($doc['convocado_por'] ?? '') ?>"
            placeholder="Nombre o cargo">
        </div>
        <div class="col-md-7">
          <label class="form-label">Asunto *</label>
          <input type="text" name="asunto" class="form-control" required
            value="<?= htmlspecialchars($doc['asunto'] ?? '') ?>"
            placeholder="Tema principal de la reunión">
        </div>
      </div>

      <div class="form-section-title mt-3"><i class="fas fa-users"></i> Participantes</div>
      <textarea name="participantes" class="form-control" rows="5"
        placeholder="Un participante por línea. Ej:&#10;Juan Pérez — Director Ejecutivo&#10;María López — Coordinadora Financiera"><?= htmlspecialchars($doc['participantes'] ?? '') ?></textarea>

      <div class="form-section-title mt-3"><i class="fas fa-list-ol"></i> Agenda</div>
      <textarea name="agenda" class="form-control" rows="4"
        placeholder="Puntos de la agenda. Ej:&#10;1. Informe de avance&#10;2. Revisión de presupuesto&#10;3. Varios"><?= htmlspecialchars($doc['agenda'] ?? '') ?></textarea>

      <div class="form-section-title mt-3"><i class="fas fa-comments"></i> Desarrollo</div>
      <textarea name="desarrollo" class="form-control" rows="8"
        placeholder="Narrativa de lo ocurrido en la reunión…"><?= htmlspecialchars($doc['desarrollo'] ?? '') ?></textarea>

      <div class="form-section-title mt-3"><i class="fas fa-handshake"></i> Acuerdos</div>
      <textarea name="acuerdos" class="form-control" rows="5"
        placeholder="Acuerdos alcanzados. Ej:&#10;1. Aprobar el presupuesto presentado.&#10;2. Contratar servicio de mantenimiento."><?= htmlspecialchars($doc['acuerdos'] ?? '') ?></textarea>

      <div class="row g-3 mt-2">
        <div class="col-md-3">
          <label class="form-label">Próxima reunión</label>
          <input type="text" name="proxima_reunion" class="form-control date-input"
            value="<?= htmlspecialchars($doc['proxima_reunion'] ?? '') ?>">
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar</button>
        <a href="actas.php" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php
$extraJs = <<<'JS'
initDataTable('#tbl-actas');

document.getElementById('form-acta')?.addEventListener('submit', function(e) {
  e.preventDefault();
  post('actas.php', new FormData(this)).then(r => {
    if (r.success) {
      Toast.show(r.message, 'success');
      setTimeout(() => Nav.ir('actas.php?action=ver&id=' + r.id), 900);
    } else Toast.show(r.message, 'error');
  }).catch(() => Toast.show('Error de comunicación.', 'error'));
});

async function aprobarActa(id) {
  if (!await confirmar('¿Confirma aprobar esta acta? Ya no podrá editarse.', { titulo: 'Aprobar acta', aceptar: 'Aprobar' })) return;
  const fd = new FormData();
  fd.append('csrf_token', document.querySelector('[name=csrf_token]')?.value || '');
  fd.append('_action', 'aprobar');
  fd.append('id', id);
  const r = await post('actas.php', fd);
  if (r.success) { Toast.show(r.message, 'success'); setTimeout(() => Nav.recargar(), 800); }
  else Toast.show(r.message, 'error');
}
JS;
include __DIR__ . '/includes/footer.php';
?>

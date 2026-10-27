<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$pagina = 'Bitácora de Actividad';

// ── Filtros ────────────────────────────────────────────────────────
$f = [
    'usuario_id' => (int)($_GET['usuario_id'] ?? 0),
    'modulo'     => trim($_GET['modulo'] ?? ''),
    'accion'     => trim($_GET['accion'] ?? ''),
    'resultado'  => trim($_GET['resultado'] ?? ''),
    'desde'      => $_GET['desde'] ?? '',
    'hasta'      => $_GET['hasta'] ?? '',
    'q'          => trim($_GET['q'] ?? ''),
];
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;

function buildQuery(array $f): array {
    $where  = [];
    $params = [];
    if ($f['usuario_id']) { $where[] = 'a.usuario_id = ?';                        $params[] = $f['usuario_id']; }
    if ($f['modulo'])     { $where[] = 'COALESCE(a.modulo, a.tabla_afectada) = ?'; $params[] = $f['modulo']; }
    if ($f['accion'])     { $where[] = 'a.accion = ?';                             $params[] = $f['accion']; }
    if ($f['resultado'])  { $where[] = 'a.resultado = ?';                          $params[] = $f['resultado']; }
    if ($f['desde'])      { $where[] = 'DATE(a.created_at) >= ?';                  $params[] = $f['desde']; }
    if ($f['hasta'])      { $where[] = 'DATE(a.created_at) <= ?';                  $params[] = $f['hasta']; }
    if ($f['q'])          { $where[] = '(a.detalle LIKE ? OR u.nombre LIKE ? OR a.email LIKE ?)';
                            array_push($params, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }

    $whereStr = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $sql = "SELECT a.id, a.usuario_id, a.accion, a.modulo, a.tabla_afectada, a.registro_id, a.resultado, a.ip, a.detalle,
                   a.created_at, a.email, u.nombre AS usuario_nombre
            FROM auditoria a
            LEFT JOIN usuarios u ON a.usuario_id = u.id
            $whereStr
            ORDER BY a.id DESC";
    return [$sql, $params];
}

function accionInfo(string $accion): array {
    return ACCIONES_BITACORA[$accion] ?? [ucfirst(strtolower(str_replace('_', ' ', $accion))), 'secondary'];
}

// ── Export CSV ─────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    [$sql, $params] = buildQuery($f);
    $rows = $pdo->prepare($sql);
    $rows->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bitacora_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Fecha/Hora', 'Usuario', 'Correo', 'Acción', 'Módulo', 'Tabla', 'Registro ID', 'Resultado', 'IP', 'Detalle']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['id'], $r['created_at'], $r['usuario_nombre'] ?? '—', $r['email'],
            accionInfo($r['accion'])[0], moduloLabel($r['modulo'] ?? $r['tabla_afectada']), $r['tabla_afectada'],
            $r['registro_id'], $r['resultado'], $r['ip'], $r['detalle']]);
    }
    fclose($out);
    exit;
}

// ── Total y página actual ──────────────────────────────────────────
[$sql, $params] = buildQuery($f);
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM ($sql) AS t");
$stmtCount->execute($params);
$total = (int)$stmtCount->fetchColumn();
$pg    = paginate($total, $page, $perPage);

$stmtRows = $pdo->prepare($sql . " LIMIT {$perPage} OFFSET {$pg['offset']}");
$stmtRows->execute($params);
$registros = $stmtRows->fetchAll();

// ── Listas para filtros ────────────────────────────────────────────
$usuarios = $pdo->query("SELECT id, nombre, activo FROM usuarios ORDER BY nombre")->fetchAll();
$modulos  = $pdo->query("SELECT DISTINCT COALESCE(modulo, tabla_afectada) FROM auditoria WHERE COALESCE(modulo, tabla_afectada) IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
usort($modulos, fn($a, $b) => strcasecmp(moduloLabel($a), moduloLabel($b)));
$acciones = $pdo->query("SELECT DISTINCT accion FROM auditoria ORDER BY accion")->fetchAll(PDO::FETCH_COLUMN);

// ── Estadísticas rápidas (respetan el filtro de usuario y fechas) ──
$fStats = array_merge($f, ['modulo' => '', 'accion' => '', 'resultado' => '', 'q' => '']);
[$sqlS, $paramsS] = buildQuery($fStats);
$stmtS = $pdo->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(accion='LOGIN'),0) AS logins,
        COALESCE(SUM(accion='LOGIN_FALLIDO'),0) AS fallidos,
        COALESCE(SUM(accion IN ('INSERT','UPDATE','DELETE')),0) AS cambios,
        COALESCE(SUM(resultado='error'),0) AS errores,
        COUNT(DISTINCT usuario_id) AS usuarios
    FROM ($sqlS) AS t");
$stmtS->execute($paramsS);
$stats = $stmtS->fetch();

include __DIR__ . '/includes/header.php';
$urlCon = fn(array $extra) => 'auditoria.php?' . http_build_query(array_filter(array_merge($f, $extra)));
?>

<div class="page-header">
  <h1><i class="fas fa-shield-halved"></i> Bitácora de Actividad</h1>
  <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>"
     class="btn btn-success btn-sm"><i class="fas fa-file-csv"></i> Exportar CSV</a>
</div>

<!-- Estadísticas -->
<div class="row g-3 mb-3">
  <?php
  $sts = [
      ['fa-list',              'Total eventos',     'kpi-gray',   $stats['total'],    $urlCon(['accion' => '', 'resultado' => ''])],
      ['fa-right-to-bracket',  'Inicios de sesión', 'kpi-blue',   $stats['logins'],   $urlCon(['accion' => 'LOGIN', 'resultado' => ''])],
      ['fa-user-xmark',        'Inicios fallidos',  'kpi-red',    $stats['fallidos'], $urlCon(['accion' => 'LOGIN_FALLIDO', 'resultado' => ''])],
      ['fa-pen-to-square',     'Cambios',           'kpi-yellow', $stats['cambios'],  $urlCon(['accion' => '', 'resultado' => ''])],
      ['fa-triangle-exclamation', 'Con error',      'kpi-red',    $stats['errores'],  $urlCon(['accion' => '', 'resultado' => 'error'])],
      ['fa-user',              'Usuarios',          'kpi-teal',   $stats['usuarios'], $urlCon(['accion' => '', 'resultado' => ''])],
  ];
  foreach ($sts as [$icon, $label, $color, $val, $href]): ?>
  <div class="col-6 col-md-2">
    <a href="<?= BASE_URL . $href ?>" class="kpi-card <?= $color ?>">
      <div class="kpi-icon"><i class="fas <?= $icon ?>"></i></div>
      <div class="kpi-value"><?= number_format((int)$val) ?></div>
      <div class="kpi-label"><?= $label ?></div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filtros -->
<div class="card mb-3">
  <div class="card-body">
    <form method="get" class="row g-2 align-items-end">
      <div class="col-md-2">
        <label class="form-label" style="font-size:.8rem">Buscar</label>
        <input type="text" name="q" class="form-control form-control-sm" value="<?= htmlspecialchars($f['q']) ?>" placeholder="Detalle, usuario, correo…">
      </div>
      <div class="col-md-2">
        <label class="form-label" style="font-size:.8rem">Usuario</label>
        <select name="usuario_id" class="form-select form-select-sm">
          <option value="">Todos</option>
          <?php foreach ($usuarios as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $u['id'] == $f['usuario_id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['nombre']) ?><?= $u['activo'] ? '' : ' (inactivo)' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label" style="font-size:.8rem">Módulo</label>
        <select name="modulo" class="form-select form-select-sm">
          <option value="">Todos</option>
          <?php foreach ($modulos as $m): ?>
          <option value="<?= htmlspecialchars($m) ?>" <?= $m === $f['modulo'] ? 'selected' : '' ?>><?= htmlspecialchars(moduloLabel($m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label" style="font-size:.8rem">Acción</label>
        <select name="accion" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach ($acciones as $a): ?>
          <option value="<?= htmlspecialchars($a) ?>" <?= $a === $f['accion'] ? 'selected' : '' ?>><?= htmlspecialchars(accionInfo($a)[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label" style="font-size:.8rem">Resultado</label>
        <select name="resultado" class="form-select form-select-sm">
          <option value="">Todos</option>
          <option value="ok" <?= $f['resultado'] === 'ok' ? 'selected' : '' ?>>Correcto</option>
          <option value="error" <?= $f['resultado'] === 'error' ? 'selected' : '' ?>>Error</option>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label" style="font-size:.8rem">Desde</label>
        <input type="text" name="desde" class="form-control form-control-sm date-input" value="<?= htmlspecialchars($f['desde']) ?>">
      </div>
      <div class="col-md-1">
        <label class="form-label" style="font-size:.8rem">Hasta</label>
        <input type="text" name="hasta" class="form-control form-control-sm date-input" value="<?= htmlspecialchars($f['hasta']) ?>">
      </div>
      <div class="col-md-1 d-flex gap-1">
        <button type="submit" class="btn btn-outline-primary btn-sm flex-fill"><i class="fas fa-search"></i></button>
        <a href="auditoria.php" class="btn btn-outline-secondary btn-sm" title="Limpiar filtros"><i class="fas fa-xmark"></i></a>
      </div>
    </form>
  </div>
</div>

<!-- Tabla de eventos -->
<div class="card">
  <div class="card-header">
    <i class="fas fa-list"></i>
    Eventos
    <span class="badge bg-secondary ms-2"><?= number_format($total) ?></span>
    <?php if ($pg['pages'] > 1): ?>
    <span class="ms-2" style="font-size:.78rem;color:var(--text-3)">
      Pág. <?= $pg['page'] ?> / <?= $pg['pages'] ?>
    </span>
    <?php endif; ?>
  </div>
  <div class="card-body p-0" style="overflow-x:auto;">
    <table class="table-proscala w-100" style="font-size:.82rem">
      <thead>
        <tr>
          <th style="width:110px">Fecha / Hora</th>
          <th>Usuario</th>
          <th style="width:130px">Acción</th>
          <th>Módulo</th>
          <th style="width:60px">ID</th>
          <th>Detalle</th>
          <th style="width:90px">IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($registros as $r):
          [$accLabel, $accColor] = accionInfo($r['accion']); ?>
        <tr>
          <td class="font-mono" style="white-space:nowrap;color:var(--text-2)">
            <?= date('d/m/Y', strtotime($r['created_at'])) ?><br>
            <span style="font-size:.75rem"><?= date('H:i:s', strtotime($r['created_at'])) ?></span>
          </td>
          <td>
            <?= htmlspecialchars($r['usuario_nombre'] ?? '—') ?>
            <?php if ($r['email'] && !$r['usuario_nombre']): ?><br><span class="text-muted" style="font-size:.75rem"><?= htmlspecialchars($r['email']) ?></span><?php endif; ?>
          </td>
          <td>
            <span class="badge bg-<?= $accColor ?>"><?= htmlspecialchars($accLabel) ?></span>
            <?php if ($r['resultado'] === 'error' && $accColor !== 'danger'): ?><span class="badge bg-danger" title="La acción no se completó"><i class="fas fa-xmark"></i></span><?php endif; ?>
          </td>
          <td style="color:var(--text-2)"><?= htmlspecialchars(moduloLabel($r['modulo'] ?? $r['tabla_afectada'])) ?></td>
          <td class="font-mono text-center" style="color:var(--text-3)"><?= $r['registro_id'] ?></td>
          <td style="max-width:480px;word-break:break-word;"><?= htmlspecialchars($r['detalle'] ?? '—') ?></td>
          <td class="font-mono" style="font-size:.75rem;color:var(--text-3)"><?= htmlspecialchars($r['ip']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$registros): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No hay registros con los filtros aplicados.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Paginación -->
  <?php if ($pg['pages'] > 1):
    $baseUrl = '?' . http_build_query(array_diff_key($_GET, ['p'=>''])); ?>
  <div class="card-body border-top d-flex justify-content-center gap-1 flex-wrap py-2">
    <?php if ($pg['page'] > 1): ?>
    <a href="<?= $baseUrl ?>&p=<?= $pg['page']-1 ?>" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-chevron-left"></i>
    </a>
    <?php endif; ?>

    <?php
    $start = max(1, $pg['page'] - 3);
    $end   = min($pg['pages'], $pg['page'] + 3);
    if ($start > 1) echo '<span class="btn btn-sm disabled">…</span>';
    for ($i = $start; $i <= $end; $i++):
    ?>
    <a href="<?= $baseUrl ?>&p=<?= $i ?>"
       class="btn btn-sm <?= $i === $pg['page'] ? 'btn-primary' : 'btn-outline-secondary' ?>">
      <?= $i ?>
    </a>
    <?php endfor;
    if ($end < $pg['pages']) echo '<span class="btn btn-sm disabled">…</span>'; ?>

    <?php if ($pg['page'] < $pg['pages']): ?>
    <a href="<?= $baseUrl ?>&p=<?= $pg['page']+1 ?>" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-chevron-right"></i>
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php
$extraJs = '';
include __DIR__ . '/includes/footer.php';
?>

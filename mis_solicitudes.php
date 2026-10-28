<?php
// ── Mis solicitudes ─────────────────────────────────────────────────
// Para cada usuario: las solicitudes que creó o que están a su nombre (y las órdenes de pago a su
// favor), con el paso del flujo en que van, a quién esperan y, si se rechazaron, el motivo.
// Es la página de inicio de quien solo ve sus propias solicitudes (paginaInicio en config/auth.php).
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Mis solicitudes';

// Por módulo: fecha, resumen, monto y la columna del empleado a cuyo nombre está (alias t)
const MIS_SOLICITUDES = [
    'compras_solicitud.php' => ['fecha' => 't.fecha', 'resumen' => 't.descripcion', 'monto' => 't.presupuesto_estimado', 'empleado' => 'solicitante_id', 'nuevo' => '?action=nuevo'],
    'gastos_viajes.php'     => ['fecha' => 't.fecha_salida', 'resumen' => "CONCAT(IF(t.tipo = 'compra', 'Compra: ', 'Viaje a '), t.destino)", 'monto' => 't.viaticos_anticipados', 'empleado' => 'empleado_id', 'nuevo' => '?action=nuevo'],
    'gastos_solicitud.php'  => ['fecha' => 't.fecha', 'resumen' => 't.descripcion', 'monto' => 't.monto_total', 'empleado' => 'empleado_id', 'nuevo' => '?action=nuevo'],
    'vacaciones.php'        => ['fecha' => 't.fecha_inicio', 'resumen' => "CONCAT(t.dias, ' días hábiles, del ', DATE_FORMAT(t.fecha_inicio, '%d/%m/%Y'), ' al ', DATE_FORMAT(t.fecha_fin, '%d/%m/%Y'))", 'monto' => 'NULL', 'empleado' => 'empleado_id', 'nuevo' => ''],
    'permisos.php'          => ['fecha' => 't.fecha_inicio', 'resumen' => "CONCAT(UPPER(LEFT(t.tipo, 1)), SUBSTRING(t.tipo, 2), ': ', t.motivo)", 'monto' => 'NULL', 'empleado' => 'empleado_id', 'nuevo' => ''],
    'compras_orden.php'     => ['fecha' => 't.fecha', 'resumen' => "(SELECT nombre FROM proveedores WHERE id = t.proveedor_id)", 'monto' => 't.total', 'empleado' => null, 'nuevo' => ''],
    'compras_pago.php'      => ['fecha' => 't.fecha', 'resumen' => 't.concepto', 'monto' => 't.monto', 'empleado' => 'empleado_id', 'nuevo' => ''],
];

$uid = (int)$_SESSION['usuario_id'];
$empleadoId = (int)($_SESSION['empleado_id'] ?? 0) ?: -1;
$filas = [];
foreach (MIS_SOLICITUDES as $modulo => $q) {
    $tabla = PROCESOS[$modulo]['tabla'];
    $suyo = "t.id IN (SELECT registro_id FROM registro_usuarios WHERE tabla = ? AND usuario_id = ? AND participacion = 'creador')"
          . ($q['empleado'] ? " OR t.`{$q['empleado']}` = ?" : '');
    $params = $q['empleado'] ? [$tabla, $uid, $empleadoId] : [$tabla, $uid];
    $s = $pdo->prepare("SELECT t.id, t.numero, t.estado, {$q['fecha']} AS fecha, {$q['resumen']} AS resumen, {$q['monto']} AS monto
                        FROM `$tabla` t WHERE ($suyo) ORDER BY t.id DESC LIMIT 200");
    $s->execute($params);
    foreach ($s->fetchAll() as $r) {
        $flujo = estadoFlujo($pdo, $modulo, (int)$r['id']);
        // Paso en que va y a quién espera
        if ($flujo['rechazado']) {
            [$paso, $clase] = ['Rechazado por ' . $flujo['rechazado']['nombre'] . ($flujo['rechazado']['comentario'] ? ': ' . $flujo['rechazado']['comentario'] : ''), 'danger'];
        } elseif ($flujo['autorizado']) {
            [$paso, $clase] = ['Autorizado por ' . $flujo['autorizado']['nombre'], 'success'];
        } elseif (in_array($r['estado'], PROCESOS[$modulo]['abiertos'], true)) {
            $falta = $flujo['revisado'] ? 'autorizar' : 'revisar';
            $excluir = array_filter([(int)($flujo['creado']['usuario_id'] ?? 0), (int)($flujo['revisado']['usuario_id'] ?? 0)]);
            $quienes = aprobadoresProceso($pdo, $modulo, (int)$r['id'], $excluir);
            $nombres = $quienes ? $pdo->query("SELECT nombre FROM usuarios WHERE id IN (" . implode(',', $quienes) . ") ORDER BY nombre")->fetchAll(PDO::FETCH_COLUMN) : [];
            $paso = ($falta === 'revisar' ? 'Esperando revisión' : 'Revisado; esperando autorización')
                  . ($nombres ? ' de ' . implode(', ', $nombres) : ': no hay nadie más que pueda aprobarlo (revise el organigrama en Roles y Permisos)');
            $clase = 'warning';
        } else {
            [$paso, $clase] = ['', 'secondary'];
        }
        $filas[] = ['modulo' => $modulo, 'proceso' => NOMBRES_PROCESO[$modulo], 'id' => (int)$r['id'], 'numero' => $r['numero'],
                    'estado' => $r['estado'], 'fecha' => $r['fecha'], 'resumen' => $r['resumen'], 'monto' => $r['monto'],
                    'paso' => $paso, 'clase' => $clase, 'en_curso' => $clase === 'warning', 'rechazado' => (bool)$flujo['rechazado']];
    }
}
usort($filas, fn($a, $b) => [$b['en_curso'], (string)$b['fecha'], $b['id']] <=> [$a['en_curso'], (string)$a['fecha'], $a['id']]);
$enCurso    = count(array_filter($filas, fn($f) => $f['en_curso']));
$rechazadas = count(array_filter($filas, fn($f) => $f['rechazado']));
$anioActual = date('Y');
$autorizadasAnio = count(array_filter($filas, fn($f) => $f['clase'] === 'success' && str_starts_with((string)$f['fecha'], $anioActual)));

include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-folder-open"></i> Mis solicitudes</h1>
  <div class="d-flex gap-2 flex-wrap">
    <?php foreach (MIS_SOLICITUDES as $modulo => $q): if (!isset(SOLICITUDES[$modulo]) || !puedeAcceder($modulo)) continue; ?>
    <a href="<?= BASE_URL . $modulo . $q['nuevo'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus"></i> <?= htmlspecialchars(NOMBRES_PROCESO[$modulo]) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="kpi-card kpi-yellow"><div class="kpi-icon"><i class="fas fa-hourglass-half"></i></div>
    <div class="kpi-value"><?= $enCurso ?></div><div class="kpi-label">En curso</div></div></div>
  <div class="col-md-4"><div class="kpi-card kpi-green"><div class="kpi-icon"><i class="fas fa-circle-check"></i></div>
    <div class="kpi-value"><?= $autorizadasAnio ?></div><div class="kpi-label">Autorizadas en <?= $anioActual ?></div></div></div>
  <div class="col-md-4"><div class="kpi-card kpi-red"><div class="kpi-icon"><i class="fas fa-circle-xmark"></i></div>
    <div class="kpi-value"><?= $rechazadas ?></div><div class="kpi-label">Rechazadas</div></div></div>
</div>

<div class="card"><div class="card-body p-0">
  <table id="tbl-mis" class="table-proscala w-100">
    <thead><tr><th>Número</th><th>Tipo</th><th>Fecha</th><th>Detalle</th><th class="text-end">Monto</th><th>Estado</th><th>En qué va</th></tr></thead>
    <tbody>
      <?php foreach ($filas as $f): ?>
      <tr>
        <td class="font-mono text-nowrap"><a href="<?= BASE_URL . $f['modulo'] ?>?action=ver&id=<?= $f['id'] ?>"><?= htmlspecialchars($f['numero']) ?></a></td>
        <td><?= htmlspecialchars($f['proceso']) ?></td>
        <td data-order="<?= htmlspecialchars((string)$f['fecha']) ?>"><?= fmtFecha((string)$f['fecha']) ?></td>
        <td><?= htmlspecialchars(mb_strimwidth((string)$f['resumen'], 0, 90, '…')) ?></td>
        <td class="text-end font-mono text-nowrap"><?= $f['monto'] !== null ? lps((float)$f['monto']) : '—' ?></td>
        <td><?= estadoBadge($f['estado']) ?></td>
        <td class="small text-<?= $f['clase'] === 'warning' ? 'body' : $f['clase'] ?>"><?= htmlspecialchars($f['paso']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$filas): ?><tr><td colspan="7" class="text-center text-muted py-4">Aún no tiene solicitudes. Use los botones de arriba para crear la primera.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div>
<?php
$extraJs = <<<'JS'
initDataTable('#tbl-mis', { order: [] });
JS;
include __DIR__ . '/includes/footer.php';
?>

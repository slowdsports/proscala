<?php
// ── Procesos históricos ────────────────────────────────────────────
// En un "punto y aparte" (corte del 2026-10-26, ver historial de Git) los procesos existentes se marcan
// historico = 1 y la numeración del año continúa desde configuracion.numeracion_inicio.
// Los históricos se listan aparte, no se pueden editar ni eliminar, pero sí terminar su
// trámite (revisar, autorizar, pagar, liquidar…).

// Módulo → tabla de los procesos numerados
const PROCESOS_NUMERADOS = [
    'compras_solicitud.php'    => 'solicitud_compra',
    'compras_cotizaciones.php' => 'cotizaciones',
    'compras_orden.php'        => 'ordenes_compra',
    'compras_pago.php'         => 'ordenes_pago',
    'compras_recepcion.php'    => 'notas_recepcion',
    'gastos_viajes.php'        => 'gastos_viaje',
    'gastos_solicitud.php'     => 'solicitud_gastos',
    'vacaciones.php'           => 'vacaciones',
    'permisos.php'             => 'permisos',
    'prestamos.php'            => 'prestamos',
    'liquidaciones.php'        => 'liquidaciones',
    'caja_chica.php'           => 'caja_chica',
    'memorandos.php'           => 'memorandos',
    'actas.php'                => 'actas_reunion',
    'planillas.php'            => 'planilla_periodos',
    'planillas_asignacion.php' => 'planilla_asignacion',
    'planillas_beneficios.php' => 'planilla_beneficios',
];

// ¿La lista muestra los históricos? (?historico=1)
function viendoHistorico(): bool {
    return ($_GET['historico'] ?? '') === '1';
}

// Condición SQL para la lista: los actuales o, con ?historico=1, los históricos
function filtroHistorico(string $alias): string {
    return "{$alias}.historico = " . (viendoHistorico() ? '1' : '0');
}

// Botones "Actuales / Histórico" de la lista
function selectorHistorico(): string {
    $url = fn(bool $h) => '?' . http_build_query(array_merge(array_diff_key($_GET, ['historico' => '', 'p' => '']), $h ? ['historico' => '1'] : []));
    $h = viendoHistorico();
    return '<div class="btn-group btn-group-sm" role="group" title="Los procesos anteriores al corte quedan como histórico">'
         . '<a href="' . htmlspecialchars($url(false)) . '" class="btn ' . ($h ? 'btn-outline-secondary' : 'btn-secondary') . '"><i class="fas fa-bolt"></i> Actuales</a>'
         . '<a href="' . htmlspecialchars($url(true)) . '" class="btn ' . ($h ? 'btn-secondary' : 'btn-outline-secondary') . '"><i class="fas fa-box-archive"></i> Histórico</a>'
         . '</div>';
}

function badgeHistorico(?array $r): string {
    return !empty($r['historico']) ? ' <span class="badge bg-secondary" title="Proceso anterior al corte: solo puede terminar su trámite"><i class="fas fa-box-archive"></i> Histórico</span>' : '';
}

function esHistorico(PDO $pdo, string $modulo, int $id): bool {
    if (!$id || !isset(PROCESOS_NUMERADOS[$modulo])) return false;
    $s = $pdo->prepare("SELECT historico FROM `" . PROCESOS_NUMERADOS[$modulo] . "` WHERE id = ?");
    $s->execute([$id]);
    return (bool)$s->fetchColumn();
}

/**
 * Se llama desde requireLogin(). Un proceso histórico no se edita ni se elimina:
 * "editar" lleva a "ver" y guardar/eliminar se rechazan. Al verlo, header.php muestra el aviso.
 */
function verificarHistorico(PDO $pdo, string $modulo): void {
    if (!isset(PROCESOS_NUMERADOS[$modulo])) return;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = $_POST['_action'] ?? '';
        if (in_array($act, ['guardar', 'eliminar'], true) && esHistorico($pdo, $modulo, (int)($_POST['id'] ?? 0))) {
            jsonErr('Los procesos históricos no se pueden editar ni eliminar; solo terminar su trámite.', 403);
        }
        return;
    }
    $id = (int)($_GET['id'] ?? 0);
    if (!$id || !esHistorico($pdo, $modulo, $id)) return;
    if (($_GET['action'] ?? '') === 'editar') {
        header('Location: ' . BASE_URL . $modulo . '?action=ver&id=' . $id);
        exit;
    }
    $GLOBALS['proceso_historico'] = true;   // aviso en includes/header.php
}

// Numeración tras el corte: en el año del corte, los procesos siguen desde numeracion_inicio
function minimoNumeracion(PDO $pdo, string $tabla, int $anio): int {
    if (!in_array($tabla, PROCESOS_NUMERADOS, true)) return 0;
    $inicio = (int)getConfig($pdo, 'numeracion_inicio', '0');
    return ($inicio && (int)getConfig($pdo, 'numeracion_inicio_anio', '0') === $anio) ? $inicio : 0;
}

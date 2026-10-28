<?php
// ── Búsqueda global (buscador de la barra superior → proscala.js, Busqueda) ──
// Busca procesos por número o texto, empleados, proveedores, activos, proyectos y módulos del menú.
// Respeta los permisos: solo módulos que el usuario puede abrir y, en las solicitudes, quien solo
// ve las suyas encuentra únicamente las propias (filtroPropios, el mismo filtro de las listas).
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
header('Cache-Control: no-store');

// Procesos: módulo => [tabla, texto que se busca y se muestra (alias t), columna del empleado o null]
const BUSQUEDA_PROCESOS = [
    'compras_solicitud.php'    => ['solicitud_compra',    't.descripcion', 'solicitante_id'],
    'compras_cotizaciones.php' => ['cotizaciones',        't.justificacion', null],
    'compras_orden.php'        => ['ordenes_compra',      '(SELECT nombre FROM proveedores WHERE id = t.proveedor_id)', null],
    'compras_pago.php'         => ['ordenes_pago',        "CONCAT(t.beneficiario, ' — ', t.concepto)", null],
    'compras_recepcion.php'    => ['notas_recepcion',     '(SELECT nombre FROM proveedores WHERE id = t.proveedor_id)', null],
    'gastos_viajes.php'        => ['gastos_viaje',        't.destino', 'empleado_id'],
    'gastos_solicitud.php'     => ['solicitud_gastos',    't.descripcion', 'empleado_id'],
    'vacaciones.php'           => ['vacaciones',          "CONCAT(t.dias, ' días')", 'empleado_id'],
    'permisos.php'             => ['permisos',            't.motivo', 'empleado_id'],
    'caja_chica.php'           => ['caja_chica',          't.notas', 'empleado_id'],
    'prestamos.php'            => ['prestamos',           't.descripcion', 'empleado_id'],
    'liquidaciones.php'        => ['liquidaciones',       't.motivo', 'empleado_id'],
    'planillas.php'            => ['planilla_periodos',   "CONCAT('Planilla ', LPAD(t.mes, 2, '0'), '/', t.anio)", null],
    'planillas_asignacion.php' => ['planilla_asignacion', 't.descripcion', null],
    'planillas_beneficios.php' => ['planilla_beneficios', "CONCAT('Beneficios ', t.anio)", null],
    'memorandos.php'           => ['memorandos',          't.asunto', null],
    'actas.php'                => ['actas_reunion',       't.asunto', null],
];
const BUSQUEDA_POR_GRUPO = 6;

$q = trim(preg_replace('/\s+/u', ' ', (string)($_GET['q'] ?? '')));
if (mb_strlen($q) < 2) jsonOk(['resultados' => []]);
$like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
$resultados = [];
$icono = function (string $modulo): string {
    foreach (MODULOS as $items) if (isset($items[$modulo])) return $items[$modulo][0];
    return 'fa-file';
};

// ── Módulos del menú (para ir rápido a uno) ──
foreach (MODULOS as $items) {
    foreach ($items as $modulo => [$ic, $label]) {
        if (puedeAcceder($modulo) && mb_stripos($label, $q) !== false) {
            $resultados[] = ['grupo' => 'Ir a', 'titulo' => $label, 'subtitulo' => '', 'url' => $modulo, 'icono' => $ic];
        }
    }
}

// ── Procesos ──
foreach (BUSQUEDA_PROCESOS as $modulo => [$tabla, $texto, $colEmpleado]) {
    if (!puedeAcceder($modulo)) continue;
    [$condPropios, $paramsPropios] = filtroPropios($modulo, 't');
    $empleado = $colEmpleado ? "CONCAT(e.nombre, ' ', e.apellidos)" : "''";
    $join = $colEmpleado ? "LEFT JOIN empleados e ON e.id = t.`$colEmpleado`" : '';
    try {
        $s = $pdo->prepare("SELECT t.id, t.numero, t.estado, $texto AS texto, $empleado AS empleado
            FROM `$tabla` t $join
            WHERE ($condPropios) AND (t.numero LIKE ? OR $texto LIKE ? OR $empleado LIKE ?)
            ORDER BY (t.numero = ?) DESC, t.id DESC LIMIT " . BUSQUEDA_POR_GRUPO);
        $s->execute(array_merge($paramsPropios, [$like, $like, $like, $q]));
    } catch (PDOException $e) { continue; }
    foreach ($s->fetchAll() as $r) {
        // Registros antiguos sin número (p. ej. planillas): el texto hace de título
        $titulo = trim((string)$r['numero']) !== '' ? $r['numero'] : (string)$r['texto'];
        $resultados[] = ['grupo' => moduloLabel($modulo), 'titulo' => $titulo,
            'subtitulo' => trim(implode(' · ', array_filter([$r['empleado'], $titulo === $r['texto'] ? '' : mb_strimwidth((string)$r['texto'], 0, 70, '…')]))),
            'url' => "$modulo?action=ver&id={$r['id']}", 'icono' => $icono($modulo), 'estado' => $r['estado']];
    }
}

// ── Catálogos y personas ──
$buscarEn = function (string $modulo, string $sql, callable $fila) use ($pdo, $like, &$resultados) {
    if (!puedeAcceder($modulo)) return;
    $s = $pdo->prepare($sql . ' LIMIT ' . BUSQUEDA_POR_GRUPO);
    $s->execute(array_fill(0, substr_count($sql, '?'), $like));
    foreach ($s->fetchAll() as $r) $resultados[] = $fila($r) + ['grupo' => moduloLabel($modulo)];
};
$buscarEn('empleados.php', "SELECT id, CONCAT(nombre, ' ', apellidos) AS nombre, cargo, activo FROM empleados
    WHERE CONCAT(nombre, ' ', apellidos) LIKE ? OR identidad LIKE ? OR cargo LIKE ? ORDER BY activo DESC, nombre",
    fn($r) => ['titulo' => $r['nombre'], 'subtitulo' => trim($r['cargo'] . ($r['activo'] ? '' : ' · inactivo'), ' ·'),
               'url' => "empleados.php?action=editar&id={$r['id']}", 'icono' => 'fa-user']);
$buscarEn('proveedores.php', "SELECT nombre, rtn FROM proveedores WHERE nombre LIKE ? OR rtn LIKE ? ORDER BY nombre",
    fn($r) => ['titulo' => $r['nombre'], 'subtitulo' => $r['rtn'] ? 'RTN ' . $r['rtn'] : '', 'url' => 'proveedores.php?buscar=' . rawurlencode($r['nombre']), 'icono' => 'fa-truck']);
$buscarEn('activos.php', "SELECT codigo, descripcion FROM activos_fijos WHERE codigo LIKE ? OR descripcion LIKE ? OR numero_serie LIKE ? ORDER BY codigo",
    fn($r) => ['titulo' => $r['codigo'], 'subtitulo' => $r['descripcion'], 'url' => 'activos.php?buscar=' . rawurlencode($r['codigo']), 'icono' => 'fa-laptop']);
$buscarEn('proyectos.php', "SELECT codigo, nombre, donante FROM proyectos WHERE codigo LIKE ? OR nombre LIKE ? OR donante LIKE ? ORDER BY codigo",
    fn($r) => ['titulo' => $r['codigo'] . ' — ' . $r['nombre'], 'subtitulo' => (string)$r['donante'], 'url' => 'proyectos.php?buscar=' . rawurlencode($r['codigo']), 'icono' => 'fa-diagram-project']);

jsonOk(['resultados' => array_slice($resultados, 0, 40)]);

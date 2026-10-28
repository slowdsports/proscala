<?php
// ── Alta rápida desde una lista desplegable ─────────────────────────
// Los <select data-alta="tipo"> ofrecen "Agregar nuevo…" (AltaRapida en assets/js/proscala.js):
// el elemento se crea aquí sin salir del formulario y queda seleccionado. Cada tipo lo puede
// crear quien tenga acceso a alguno de los módulos que lo usan. Si ya existe uno con el mismo
// nombre (o RTN, en proveedores), se devuelve ese en lugar de duplicarlo.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

//   modulos → quien accede a alguno puede crear el tipo · valor → lo que guarda el <select> (id o nombre)
const ALTA_RAPIDA = [
    'proveedor'    => ['label' => 'Proveedor',    'tabla' => 'proveedores',       'valor' => 'id',
                       'modulos' => ['proveedores.php', 'compras_cotizaciones.php', 'compras_orden.php', 'compras_pago.php', 'compras_recepcion.php', 'activos.php']],
    'banco'        => ['label' => 'Banco',        'tabla' => 'cat_bancos',        'valor' => 'nombre',
                       'modulos' => ['catalogos.php', 'empleados.php', 'proveedores.php', 'compras_pago.php']],
    'cargo'        => ['label' => 'Cargo',        'tabla' => 'cat_cargos',        'valor' => 'nombre',
                       'modulos' => ['catalogos.php', 'empleados.php']],
    'departamento' => ['label' => 'Departamento', 'tabla' => 'cat_departamentos', 'valor' => 'nombre',
                       'modulos' => ['catalogos.php', 'empleados.php']],
];

if (!isAjax() || $_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('Solicitud no válida.', 405);
verifyCsrf();

$tipo = $_POST['tipo'] ?? '';
$def  = ALTA_RAPIDA[$tipo] ?? null;
if (!$def) jsonErr('Tipo de elemento desconocido.');
if (!array_filter($def['modulos'], 'puedeAcceder')) jsonErr("No tiene permiso para agregar un {$def['label']}.", 403);

$nombre = trim($_POST['nombre'] ?? '');
if ($nombre === '') jsonErr("El nombre del {$def['label']} es requerido.");
if (mb_strlen($nombre) > 150) jsonErr('El nombre es demasiado largo (máximo 150 caracteres).');

$respuesta = fn(int $id, string $nom, string $msg) => jsonOk(['id' => $id, 'nombre' => $nom,
    'valor' => $def['valor'] === 'id' ? (string)$id : $nom], $msg);

if ($tipo === 'proveedor') {
    $rtn = trim($_POST['rtn'] ?? '');
    $s = $pdo->prepare("SELECT id, nombre, activo FROM proveedores WHERE nombre = ? OR (? <> '' AND rtn = ?) ORDER BY nombre = ? DESC LIMIT 1");
    $s->execute([$nombre, $rtn, $rtn, $nombre]);
    if ($ya = $s->fetch()) {
        if (!$ya['activo']) $pdo->prepare("UPDATE proveedores SET activo = 1 WHERE id = ?")->execute([$ya['id']]);
        $respuesta((int)$ya['id'], $ya['nombre'], "El proveedor «{$ya['nombre']}» ya estaba registrado: quedó seleccionado.");
    }
    $categoria = in_array($_POST['categoria'] ?? '', ['bienes', 'servicios', 'ambos'], true) ? $_POST['categoria'] : 'ambos';
    $codigo = generarNumero($pdo, 'proveedores', 'codigo', 'PROV');
    $pdo->prepare("INSERT INTO proveedores (codigo, nombre, rtn, contacto, telefono, email, categoria, activo) VALUES (?,?,?,?,?,?,?,1)")
        ->execute([$codigo, $nombre, $rtn, trim($_POST['contacto'] ?? ''), trim($_POST['telefono'] ?? ''), trim($_POST['email'] ?? ''), $categoria]);
    $respuesta((int)$pdo->lastInsertId(), $nombre, "Proveedor «{$nombre}» agregado. Complete sus datos bancarios en Proveedores cuando los tenga.");
}

// Catálogos de nombres (cargos, departamentos, bancos: ver catalogos.php)
$s = $pdo->prepare("SELECT id, nombre FROM `{$def['tabla']}` WHERE nombre = ?");
$s->execute([$nombre]);
if ($ya = $s->fetch()) $respuesta((int)$ya['id'], $ya['nombre'], "{$def['label']} «{$ya['nombre']}» ya existía: quedó seleccionado.");
$pdo->prepare("INSERT INTO `{$def['tabla']}` (nombre) VALUES (?)")->execute([$nombre]);
$respuesta((int)$pdo->lastInsertId(), $nombre, "{$def['label']} «{$nombre}» agregado.");

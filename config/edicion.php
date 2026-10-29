<?php
// ── Edición de procesos ────────────────────────────────────────────
// Cualquiera con el módulo puede crear un proceso. Quien lo creó puede editarlo mientras nadie
// lo haya revisado, autorizado ni rechazado (procesos con flujo: PROCESOS); después, editarlo (o
// eliminarlo) le corresponde a los puestos que aprueban procesos (puedeAprobar: roles.aprueba)
// por encima de él. El Administrador y el super administrador pueden editar todo.
// Se puede editar incluso ya autorizado, mientras no esté cerrado ni pagado: la edición
// anula revisión y autorización, devuelve el proceso a su estado inicial y actualiza su
// orden de pago (que también vuelve a pendiente).
//
//   tabla     → tabla del proceso
//   cerrados  → estados en los que ya no se edita
//   reinicio  → [estado al que vuelve, estados desde los que vuelve (null = cualquiera)] o null
//   acciones  → acciones del módulo que editan o eliminan => parámetro POST con el id del proceso
//   bloqueo   → [consulta con ? = id, motivo] que impide editar (p. ej. ya tiene pagos)
//   responsable → columna con el empleado a cargo: él también lo edita (no lo elimina) mientras
//                 no esté cerrado, aunque lo haya creado (caja chica: registra los gastos día a día)
//   op_fondo  → su orden de pago es el fondo que se entrega al empezar: pagarla no lo cierra
const EDICION_PROCESOS = [
    'compras_solicitud.php'    => ['tabla' => 'solicitud_compra',    'cerrados' => ['cotizando', 'adjudicada', 'anulada'], 'reinicio' => ['pendiente', null],
                                   'acciones' => ['guardar' => 'id']],
    'compras_cotizaciones.php' => ['tabla' => 'cotizaciones',        'cerrados' => [], 'reinicio' => ['pendiente', ['aprobada', 'rechazada']],
                                   'acciones' => ['guardar' => 'id'],
                                   'bloqueo'  => ['SELECT 1 FROM ordenes_compra WHERE cotizacion_id = ?', 'Ya tiene una orden de compra.']],
    'compras_orden.php'        => ['tabla' => 'ordenes_compra',      'cerrados' => ['completa', 'anulada'], 'reinicio' => null,
                                   'acciones' => ['guardar' => 'id']],
    'compras_pago.php'         => ['tabla' => 'ordenes_pago',        'cerrados' => ['pagada', 'anulada'], 'reinicio' => ['pendiente', null],
                                   'acciones' => ['guardar' => 'id']],
    'compras_recepcion.php'    => ['tabla' => 'notas_recepcion',     'cerrados' => [], 'reinicio' => null,
                                   'acciones' => ['guardar' => 'id']],
    'gastos_viajes.php'        => ['tabla' => 'gastos_viaje',        'cerrados' => ['liquidada'], 'reinicio' => ['pendiente', null],
                                   'acciones' => ['guardar' => 'id']],
    'gastos_solicitud.php'     => ['tabla' => 'solicitud_gastos',    'cerrados' => ['pagada'], 'reinicio' => ['pendiente', null],
                                   'acciones' => ['guardar' => 'id']],
    'vacaciones.php'           => ['tabla' => 'vacaciones',          'cerrados' => ['disfrutada'], 'reinicio' => ['pendiente', null],
                                   'acciones' => ['crear' => 'id', 'eliminar' => 'id']],
    'permisos.php'             => ['tabla' => 'permisos',            'cerrados' => [], 'reinicio' => ['pendiente', null],
                                   'acciones' => ['crear' => 'id', 'eliminar' => 'id']],
    'prestamos.php'            => ['tabla' => 'prestamos',           'cerrados' => ['pagado', 'cancelado'], 'reinicio' => null,
                                   'acciones' => ['crear' => 'id'],
                                   'bloqueo'  => ['SELECT 1 FROM prestamos_pagos WHERE prestamo_id = ?', 'Ya tiene cuotas pagadas.']],
    'liquidaciones.php'        => ['tabla' => 'liquidaciones',       'cerrados' => ['pagada'], 'reinicio' => ['borrador', null],
                                   'acciones' => ['guardar' => 'id', 'eliminar' => 'id']],
    'caja_chica.php'           => ['tabla' => 'caja_chica',          'cerrados' => ['liquidada'], 'reinicio' => null,
                                   'acciones' => ['guardar' => 'id', 'eliminar' => 'id'],
                                   'responsable' => 'empleado_id', 'op_fondo' => true],
    'planillas.php'            => ['tabla' => 'planilla_periodos',   'cerrados' => ['pagada', 'anulada'], 'reinicio' => ['procesada', ['aprobada']],
                                   'acciones' => ['guardar_credimpulsa' => 'periodo_id', 'guardar_distribucion' => 'periodo_id', 'aplicar_distribucion' => 'periodo_id']],
    'planillas_asignacion.php' => ['tabla' => 'planilla_asignacion', 'cerrados' => ['pagada'], 'reinicio' => ['borrador', ['aprobada']],
                                   'acciones' => ['guardar_detalle' => 'id', 'eliminar' => 'id']],
    'planillas_beneficios.php' => ['tabla' => 'planilla_beneficios', 'cerrados' => ['pagada'], 'reinicio' => ['borrador', ['aprobada']],
                                   'acciones' => ['guardar_detalle' => 'id', 'guardar_distribucion' => 'id', 'aplicar_distribucion' => 'id', 'eliminar' => 'id']],
];

/** ¿Puede el usuario editar (o, con $accion 'eliminar', eliminar) este proceso? true o el motivo por el que no. */
function puedeEditar(PDO $pdo, string $modulo, int $id, string $accion = 'editar') {
    $def = EDICION_PROCESOS[$modulo] ?? null;
    if (!$def || !$id) return true;
    if ($motivo = motivoCierre($pdo, $modulo, $id)) return $motivo;

    if (tieneAccesoTotal()) return true;
    if ($accion !== 'eliminar' && esResponsableProceso($pdo, $modulo, $id)) return true;
    $s = $pdo->prepare("SELECT usuario_id FROM registro_usuarios WHERE tabla = ? AND registro_id = ? AND participacion = 'creador'");
    $s->execute([$def['tabla'], $id]);
    if ((int)$s->fetchColumn() === (int)($_SESSION['usuario_id'] ?? 0)) {
        if (!isset(PROCESOS[$modulo])) return 'No puede editar un proceso que usted creó: debe corregirlo un rango superior.';
        return pasosFlujoDados($pdo, $def['tabla'], $id)
            ? 'Ya fue revisado, autorizado o rechazado: ya no puede editarlo. Si hay que cambiarlo, debe corregirlo un rango superior.'
            : true;
    }
    if (!puedeAprobar()) return 'Solo los puestos que aprueban procesos pueden editarlos.';
    return motivoJerarquia(rolCreador($pdo, $def['tabla'], $id)) ?? true;
}

/** ¿El usuario es el empleado a cargo del proceso (EDICION_PROCESOS → responsable)? */
function esResponsableProceso(PDO $pdo, string $modulo, int $id): bool {
    $def = EDICION_PROCESOS[$modulo] ?? null;
    $emp = (int)($_SESSION['empleado_id'] ?? 0);
    if (empty($def['responsable']) || !$emp || !$id) return false;
    $s = $pdo->prepare("SELECT `{$def['responsable']}` FROM `{$def['tabla']}` WHERE id = ?");
    $s->execute([$id]);
    return (int)$s->fetchColumn() === $emp;
}

/** ¿Alguien ya revisó, autorizó o rechazó el proceso? (tabla aprobaciones) */
function pasosFlujoDados(PDO $pdo, string $tabla, int $id): bool {
    $s = $pdo->prepare("SELECT 1 FROM aprobaciones WHERE tabla = ? AND registro_id = ? LIMIT 1");
    $s->execute([$tabla, $id]);
    return (bool)$s->fetchColumn();
}

/** Por qué el proceso ya no se modifica para nadie (histórico, cerrado, pagado…), o null. */
function motivoCierre(PDO $pdo, string $modulo, int $id): ?string {
    $def = EDICION_PROCESOS[$modulo];
    $s = $pdo->prepare("SELECT estado, historico FROM `{$def['tabla']}` WHERE id = ?");
    $s->execute([$id]);
    $r = $s->fetch();
    if (!$r) return 'Proceso no encontrado.';
    if ($r['historico']) return 'Los procesos históricos no se pueden editar.';
    if (in_array($r['estado'], $def['cerrados'], true)) return "El proceso ya está cerrado ({$r['estado']}) y no se puede editar.";
    if (!empty($def['bloqueo'])) {
        $b = $pdo->prepare($def['bloqueo'][0]);
        $b->execute([$id]);
        if ($b->fetchColumn()) return $def['bloqueo'][1] . ' Ya no se puede editar.';
    }
    if (empty($def['op_fondo'])) {
        $s = $pdo->prepare("SELECT 1 FROM ordenes_pago WHERE origen_tabla = ? AND origen_id = ? AND estado = 'pagada'");
        $s->execute([$def['tabla'], $id]);
        if ($s->fetchColumn()) return 'Su orden de pago ya fue pagada; ya no se puede editar.';
    }
    return null;
}

/**
 * Botón "Editar" de un proceso, solo si el usuario puede editarlo.
 * $accion: URL (enlace) o código JS para onclick (empieza con "js:"). $chico: versión de lista.
 */
function botonEditar(PDO $pdo, string $modulo, int $id, string $accion, bool $chico = false): string {
    if (puedeEditar($pdo, $modulo, $id) !== true) return '';
    $clase = $chico ? 'btn btn-sm btn-outline-secondary py-0 px-2 ms-1' : 'btn btn-sm btn-outline-secondary';
    $texto = $chico ? '<i class="fas fa-edit"></i>' : '<i class="fas fa-edit"></i> Editar';
    return str_starts_with($accion, 'js:')
        ? '<button type="button" class="' . $clase . '" title="Editar" onclick="' . htmlspecialchars(substr($accion, 3)) . '">' . $texto . '</button>'
        : '<a href="' . htmlspecialchars($accion) . '" class="' . $clase . '" title="Editar">' . $texto . '</a>';
}

/** Se llama desde requireLogin(): bloquea abrir el formulario de edición o editar sin permiso. */
function verificarEdicion(PDO $pdo, string $modulo): void {
    $def = EDICION_PROCESOS[$modulo] ?? null;
    if (!$def) return;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $param = $def['acciones'][$_POST['_action'] ?? ''] ?? null;
        $id = $param ? (int)($_POST[$param] ?? 0) : 0;
        $accion = preg_match('/elimin/i', (string)($_POST['_action'] ?? '')) ? 'eliminar' : 'editar';
        if ($id && ($motivo = puedeEditar($pdo, $modulo, $id, $accion)) !== true) {
            $GLOBALS['_bitacora_explicita'] = true;
            registrarEvento($pdo, 'ACCESO_DENEGADO', ['resultado' => 'error', 'registro_id' => $id, 'detalle' => "No pudo editar: $motivo"]);
            jsonErr($motivo, 403);
        }
        // El creador ajusta su propio proceso aún sin revisar: no es una corrección (notificarFlujo)
        if ($id && isset(PROCESOS[$modulo]) && !pasosFlujoDados($pdo, $def['tabla'], $id)) {
            $s = $pdo->prepare("SELECT usuario_id FROM registro_usuarios WHERE tabla = ? AND registro_id = ? AND participacion = 'creador'");
            $s->execute([$def['tabla'], $id]);
            if ((int)$s->fetchColumn() === (int)($_SESSION['usuario_id'] ?? 0)) $GLOBALS['_edicion_sin_revisar'] = true;
        }
        return;
    }
    $id = (int)($_GET['id'] ?? $_GET['edit'] ?? 0);
    $editando = ($_GET['action'] ?? '') === 'editar' || isset($_GET['edit']);
    if ($editando && $id && ($motivo = puedeEditar($pdo, $modulo, $id)) !== true) {
        $_SESSION['aviso'] = $motivo;
        header('Location: ' . BASE_URL . $modulo . '?action=ver&id=' . $id);
        exit;
    }
}

/**
 * Abrir un proceso (?action=ver|editar&id=…): el registro debe existir. Un enlace con el número del
 * proceso (?id=SC26-0201) se lleva a su id; si no existe, a la lista con un aviso. Sin esto la página
 * no encontraba el registro y mostraba el formulario de un proceso nuevo.
 */
function verificarRegistroAbierto(PDO $pdo, string $modulo): void {
    $def = EDICION_PROCESOS[$modulo] ?? null;
    if (!$def || $_SERVER['REQUEST_METHOD'] !== 'GET' || !isset($_GET['id'])
        || !in_array($_GET['action'] ?? '', ['ver', 'editar'], true)) return;
    $valor = trim((string)$_GET['id']);
    $esId  = ctype_digit($valor) && (int)$valor > 0;
    $s = $pdo->prepare("SELECT id FROM `{$def['tabla']}` WHERE " . ($esId ? 'id' : 'numero') . " = ? LIMIT 1");
    $s->execute([$esId ? (int)$valor : $valor]);
    $id = (int)$s->fetchColumn();
    if ($id && $esId) return;
    if ($id) {
        header('Location: ' . BASE_URL . $modulo . '?' . http_build_query(['id' => $id] + $_GET));
    } else {
        $_SESSION['aviso'] = 'No se encontró el proceso ' . mb_substr($valor, 0, 40) . '. Puede que se haya eliminado o que el enlace esté incompleto.';
        header('Location: ' . BASE_URL . $modulo);
    }
    exit;
}

/**
 * Al terminar una edición (registrarPeticion): el proceso vuelve a su estado inicial, se anulan
 * revisión / autorización / rechazo y su orden de pago se actualiza y vuelve a pendiente.
 */
function aplicarEdicion(PDO $pdo, string $modulo, int $id): void {
    $def = EDICION_PROCESOS[$modulo];
    if ($def['reinicio']) {
        [$a, $desde] = $def['reinicio'];
        $sql = "UPDATE `{$def['tabla']}` SET estado = ? WHERE id = ? AND estado <> ?";
        $params = [$a, $id, $a];
        if ($desde) { $sql .= ' AND estado IN (' . implode(',', array_fill(0, count($desde), '?')) . ')'; $params = array_merge($params, $desde); }
        $pdo->prepare($sql)->execute($params);
    }
    $pdo->prepare("DELETE FROM aprobaciones WHERE tabla = ? AND registro_id = ?")->execute([$def['tabla'], $id]);
    if ($modulo !== 'compras_pago.php') actualizarOrdenPago($pdo, $def['tabla'], $id);
}

/** Lleva a la orden de pago de un proceso los datos actuales del proceso (si aún no se pagó). */
function actualizarOrdenPago(PDO $pdo, string $tabla, int $id): void {
    $s = $pdo->prepare("SELECT id, numero, estado FROM ordenes_pago WHERE origen_tabla = ? AND origen_id = ?");
    $s->execute([$tabla, $id]);
    $op = $s->fetch();
    if (!$op || in_array($op['estado'], ['pagada', 'anulada'], true)) return;
    $d = datosOrdenPago($pdo, $tabla, $id);
    if (!$d) {
        // Ya no hay nada que pagar (p. ej. la liquidación quedó por debajo del anticipo)
        $pdo->prepare("UPDATE ordenes_pago SET estado = 'anulada' WHERE id = ?")->execute([$op['id']]);
        registrarEvento($pdo, 'UPDATE', ['modulo' => 'compras_pago.php', 'tabla' => 'ordenes_pago', 'registro_id' => $op['id'],
            'detalle' => "Orden de pago {$op['numero']} anulada: tras editar el proceso ya no hay monto que pagar"]);
        return;
    }
    $pdo->prepare("UPDATE ordenes_pago SET beneficiario = ?, beneficiario_tipo = ?, proveedor_id = ?, empleado_id = ?, concepto = ?,
            monto = ?, banco = ?, cuenta_bancaria = ?, banco_tipo = ?, proyecto_id = ?, estado = 'pendiente' WHERE id = ?")
        ->execute([$d['beneficiario'], $d['beneficiario_tipo'], $d['proveedor_id'] ?? null, $d['empleado_id'] ?? null, $d['concepto'],
                   round((float)$d['monto'], 2), $d['banco'] ?? null, $d['cuenta_bancaria'] ?? null, $d['banco_tipo'] ?? null,
                   $d['proyecto_id'] ?? null, $op['id']]);
    $pdo->prepare("DELETE FROM aprobaciones WHERE tabla = 'ordenes_pago' AND registro_id = ?")->execute([$op['id']]);
    registrarEvento($pdo, 'UPDATE', ['modulo' => 'compras_pago.php', 'tabla' => 'ordenes_pago', 'registro_id' => $op['id'],
        'detalle' => "Orden de pago {$op['numero']} actualizada por edición de su proceso: L. " . number_format((float)$d['monto'], 2)]);
}

/**
 * Una orden de pago generada por un proceso solo se autoriza si su proceso está autorizado
 * (tras editar el proceso, éste vuelve a pendiente). true o el motivo.
 */
function origenOrdenPagoAutorizado(PDO $pdo, int $opId) {
    $s = $pdo->prepare("SELECT origen_tabla, origen_id FROM ordenes_pago WHERE id = ?");
    $s->execute([$opId]);
    $o = $s->fetch();
    if (!$o || !$o['origen_tabla']) return true;
    foreach (EDICION_PROCESOS as $def) {
        if ($def['tabla'] !== $o['origen_tabla']) continue;
        if ($def['tabla'] === 'ordenes_compra') {   // la OC se autoriza sin cambiar de estado
            $a = $pdo->prepare("SELECT 1 FROM aprobaciones WHERE tabla = 'ordenes_compra' AND registro_id = ? AND paso = 'autorizado'");
            $a->execute([$o['origen_id']]);
            return $a->fetchColumn() ? true : 'Su orden de compra fue editada y debe autorizarse de nuevo.';
        }
        if (!$def['reinicio']) return true;     // préstamo, caja chica: sin autorización
        $e = $pdo->prepare("SELECT estado FROM `{$def['tabla']}` WHERE id = ?");
        $e->execute([$o['origen_id']]);
        return $e->fetchColumn() === $def['reinicio'][0] ? 'Su proceso de origen fue editado y debe autorizarse de nuevo.' : true;
    }
    return true;
}

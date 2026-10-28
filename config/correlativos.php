<?php
// ── Correlativo común de los procesos que terminan en Orden de Pago ─────
// La Orden de Pago manda el correlativo: cada proceso que se paga toma el siguiente
// número de un contador común (tabla correlativos, uno por año) y su Orden de Pago
// lleva el mismo número: SG26-0200 → OP26-0200, PL26-0201 → OP26-0201.
// Los procesos derivados heredan el número de su origen (SC → CO → OC → NR / OP,
// anticipo → liquidación). Si el número ya está usado en esa tabla (p. ej. la OP del
// reembolso de una liquidación cuyo anticipo ya tuvo OP), se agrega un sufijo: -B, -C…
// Al autorizar el proceso (ORDEN_PAGO_AUTOMATICA) el sistema crea su Orden de Pago.

// Tablas que comparten el correlativo
const TABLAS_CORRELATIVO = [
    'solicitud_compra', 'cotizaciones', 'ordenes_compra', 'notas_recepcion', 'ordenes_pago',
    'gastos_viaje', 'solicitud_gastos', 'planilla_periodos', 'planilla_asignacion',
    'planilla_beneficios', 'caja_chica', 'liquidaciones', 'prestamos',
];

// Cuándo se genera la Orden de Pago de cada proceso: acción del módulo y, si aplica,
// el estado al que pasa (los procesos sin aprobación la generan al crearse)
const ORDEN_PAGO_AUTOMATICA = [
    'gastos_viajes.php'        => ['tabla' => 'gastos_viaje',        'accion' => 'cambiar_estado', 'estado' => 'aprobada'],
    'gastos_solicitud.php'     => ['tabla' => 'solicitud_gastos',    'accion' => 'cambiar_estado', 'estado' => 'aprobada'],
    'compras_orden.php'        => ['tabla' => 'ordenes_compra',      'accion' => 'autorizar',      'estado' => null],
    'planillas.php'            => ['tabla' => 'planilla_periodos',   'accion' => 'cambiar_estado', 'estado' => 'aprobada'],
    'planillas_asignacion.php' => ['tabla' => 'planilla_asignacion', 'accion' => 'cambiar_estado', 'estado' => 'aprobada'],
    'planillas_beneficios.php' => ['tabla' => 'planilla_beneficios', 'accion' => 'cambiar_estado', 'estado' => 'aprobada'],
    'liquidaciones.php'        => ['tabla' => 'liquidaciones',       'accion' => 'cambiar_estado', 'estado' => 'aprobada'],
    'prestamos.php'            => ['tabla' => 'prestamos',           'accion' => 'crear',          'estado' => null],
    'caja_chica.php'           => ['tabla' => 'caja_chica',          'accion' => 'guardar',        'estado' => null],
];

/** Siguiente número del correlativo común del año (serializado entre usuarios). */
function generarCorrelativo(PDO $pdo, int $anio): int {
    static $bloqueado = false;
    if (!$bloqueado) {
        $l = $pdo->query("SELECT GET_LOCK(SHA1(CONCAT(DATABASE(), '_correlativo')), 15)");
        if ((int)$l->fetchColumn() !== 1) throw new RuntimeException('Otro usuario está generando un documento; intente de nuevo en unos segundos.');
        $bloqueado = true;
    }
    $s = $pdo->prepare("SELECT ultimo FROM correlativos WHERE anio = ?");
    $s->execute([$anio]);
    $ultimo = $s->fetchColumn();
    if ($ultimo === false) {
        // Primer número del año: después del mayor ya usado y del inicio del corte histórico
        $ultimo = 0;
        foreach (TABLAS_CORRELATIVO as $t) {
            $m = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(numero,'-',2),'-',-1) AS UNSIGNED))
                                FROM `$t` WHERE historico = 0 AND numero REGEXP ?");
            $m->execute(['^[A-Z]+' . substr((string)$anio, -2) . '-']);
            $ultimo = max($ultimo, (int)$m->fetchColumn());
        }
        if ((int)getConfig($pdo, 'numeracion_inicio_anio', '0') === $anio) {
            $ultimo = max($ultimo, (int)getConfig($pdo, 'numeracion_inicio', '0') - 1);
        }
        $pdo->prepare("INSERT INTO correlativos (anio, ultimo) VALUES (?, ?)")->execute([$anio, $ultimo]);
    }
    $pdo->prepare("UPDATE correlativos SET ultimo = ? WHERE anio = ?")->execute([$ultimo + 1, $anio]);
    return (int)$ultimo + 1;
}

/**
 * Número de un proceso: hereda el de $origen (cambiando el prefijo) o toma el siguiente del
 * correlativo común. Si ya existe en la tabla, agrega sufijo -B, -C…
 */
function numeroProceso(PDO $pdo, string $tabla, string $prefijo, ?string $origen = null, int $anio = 0): string {
    if ($origen && preg_match('/^[A-Z]+(\d{2}-\d+)/', $origen, $m)) {
        $base = $prefijo . $m[1];
    } else {
        $anio = $anio ?: (int)date('Y');
        $base = $prefijo . substr((string)$anio, -2) . '-' . str_pad((string)generarCorrelativo($pdo, $anio), 4, '0', STR_PAD_LEFT);
    }
    $s = $pdo->prepare("SELECT 1 FROM `$tabla` WHERE numero = ?");
    for ($num = $base, $letra = 'B'; ; $num = $base . '-' . $letra++) {
        $s->execute([$num]);
        if (!$s->fetchColumn()) return $num;
    }
}

/** Número de un registro (para heredarlo). */
function numeroDe(PDO $pdo, string $tabla, $id): ?string {
    if (!$id) return null;
    $s = $pdo->prepare("SELECT numero FROM `$tabla` WHERE id = ?");
    $s->execute([(int)$id]);
    return $s->fetchColumn() ?: null;
}

/**
 * Datos de la Orden de Pago de un proceso, o null si no corresponde pagar
 * (histórico, sin monto o, en una liquidación de anticipo, sin reembolso al empleado).
 */
function datosOrdenPago(PDO $pdo, string $tabla, int $id): ?array {
    $empleado = function (int $empId) use ($pdo): array {
        $s = $pdo->prepare("SELECT id, CONCAT(nombre,' ',apellidos) AS nombre, banco, cuenta_banco FROM empleados WHERE id = ?");
        $s->execute([$empId]);
        $e = $s->fetch() ?: ['id' => null, 'nombre' => '', 'banco' => '', 'cuenta_banco' => ''];
        return ['beneficiario' => $e['nombre'], 'beneficiario_tipo' => 'empleado', 'empleado_id' => $e['id'],
                'banco' => $e['banco'], 'cuenta_bancaria' => $e['cuenta_banco']];
    };
    $fila = function (string $sql) use ($pdo, $id) { $s = $pdo->prepare($sql); $s->execute([$id]); return $s->fetch(); };
    $meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $d = null;

    switch ($tabla) {
        case 'gastos_viaje':
            $r = $fila("SELECT * FROM gastos_viaje WHERE id = ?");
            if ($r) $d = $empleado((int)$r['empleado_id']) + ['monto' => $r['viaticos_anticipados'], 'proyecto_id' => $r['proyecto_id'],
                'proceso_id' => $r['proceso_id'], 'concepto' => "Anticipo {$r['numero']} — {$r['destino']}"];
            break;
        case 'solicitud_gastos':
            $r = $fila("SELECT sg.*, gv.viaticos_anticipados, gv.numero AS gv_numero FROM solicitud_gastos sg
                        LEFT JOIN gastos_viaje gv ON gv.id = sg.viaje_id WHERE sg.id = ?");
            if ($r) {
                // Ligada a un anticipo: solo se paga lo gastado por encima del anticipo (reembolso)
                $monto = $r['viaje_id'] ? $r['monto_total'] - $r['viaticos_anticipados'] : $r['monto_total'];
                $d = $empleado((int)$r['empleado_id']) + ['monto' => $monto, 'proyecto_id' => $r['proyecto_id'],
                    'proceso_id' => $r['proceso_id'], 'solicitud_gastos_id' => $id,
                    'concepto' => $r['viaje_id'] ? "Reembolso de liquidación {$r['numero']} (anticipo {$r['gv_numero']}) — {$r['descripcion']}"
                                                 : "Reembolso {$r['numero']} — {$r['descripcion']}"];
            }
            break;
        case 'ordenes_compra':
            $r = $fila("SELECT oc.*, p.nombre, p.banco, p.banco_cuenta, p.banco_tipo FROM ordenes_compra oc
                        JOIN proveedores p ON p.id = oc.proveedor_id WHERE oc.id = ?");
            if ($r) $d = ['beneficiario' => $r['nombre'], 'beneficiario_tipo' => 'proveedor', 'proveedor_id' => $r['proveedor_id'],
                'banco' => $r['banco'], 'cuenta_bancaria' => $r['banco_cuenta'], 'banco_tipo' => $r['banco_tipo'],
                'monto' => $r['total'], 'proyecto_id' => $r['proyecto_id'], 'orden_compra_id' => $id,
                'concepto' => "Pago de orden de compra {$r['numero']} — {$r['nombre']}"];
            break;
        case 'planilla_periodos':
            $r = $fila("SELECT * FROM planilla_periodos WHERE id = ?");
            if ($r) $d = ['beneficiario' => 'Planilla de empleados', 'beneficiario_tipo' => 'otro', 'monto' => $r['total_neto'],
                'concepto' => "Planilla quincenal {$r['numero']} — " . $meses[$r['mes'] - 1] . " {$r['anio']}, "
                            . ($r['quincena'] === 'primera' ? '1ª' : '2ª') . ' quincena'];
            break;
        case 'planilla_asignacion':
            $r = $fila("SELECT * FROM planilla_asignacion WHERE id = ?");
            if ($r) $d = ['beneficiario' => 'Planilla de asignación', 'beneficiario_tipo' => 'otro', 'monto' => $r['total'],
                'proyecto_id' => $r['proyecto_id'], 'concepto' => "Planilla de asignación {$r['numero']} — " . $meses[$r['mes'] - 1] . " {$r['anio']}"];
            break;
        case 'planilla_beneficios':
            $r = $fila("SELECT * FROM planilla_beneficios WHERE id = ?");
            if ($r) $d = ['beneficiario' => 'Planilla de beneficios', 'beneficiario_tipo' => 'otro', 'monto' => $r['total_neto'],
                'concepto' => "Planilla de beneficios {$r['numero']} — " . ($r['tipo'] === 'decimo_tercero' ? 'Décimo tercer mes' : 'Décimo cuarto mes') . " {$r['anio']}"];
            break;
        case 'liquidaciones':
            $r = $fila("SELECT * FROM liquidaciones WHERE id = ?");
            if ($r) $d = $empleado((int)$r['empleado_id']) + ['monto' => $r['total'], 'concepto' => "Liquidación laboral {$r['numero']}"];
            break;
        case 'prestamos':
            $r = $fila("SELECT * FROM prestamos WHERE id = ?");
            if ($r) $d = $empleado((int)$r['empleado_id']) + ['monto' => $r['monto'], 'concepto' => "Desembolso de préstamo {$r['numero']}"];
            break;
        case 'caja_chica':
            $r = $fila("SELECT * FROM caja_chica WHERE id = ?");
            if ($r) $d = $empleado((int)$r['empleado_id']) + ['monto' => $r['anticipo'], 'concepto' => "Fondo de caja chica {$r['numero']}"];
            break;
    }
    if (!$d || !empty($r['historico']) || round((float)$d['monto'], 2) <= 0) return null;
    return $d + ['numero_origen' => $r['numero']];
}

/** Crea la Orden de Pago de un proceso (una sola por proceso). Devuelve su número o null. */
function generarOrdenPago(PDO $pdo, string $tabla, int $id): ?string {
    $s = $pdo->prepare("SELECT numero FROM ordenes_pago WHERE origen_tabla = ? AND origen_id = ?");
    $s->execute([$tabla, $id]);
    if ($ya = $s->fetchColumn()) return $ya;
    $d = datosOrdenPago($pdo, $tabla, $id);
    if (!$d) return null;

    $num = numeroProceso($pdo, 'ordenes_pago', getConfig($pdo, 'prefijo_op', 'OP'), $d['numero_origen']);
    $pdo->prepare("INSERT INTO ordenes_pago (numero, proceso_id, fecha, beneficiario, beneficiario_tipo, proveedor_id, empleado_id,
            concepto, monto, forma_pago, banco, cuenta_bancaria, banco_tipo, orden_compra_id, solicitud_gastos_id, proyecto_id,
            estado, aprobador1_id, origen_tabla, origen_id)
        VALUES (?,?,CURDATE(),?,?,?,?,?,?,'transferencia',?,?,?,?,?,?,'pendiente',?,?,?)")
        ->execute([$num, $d['proceso_id'] ?? null, $d['beneficiario'], $d['beneficiario_tipo'], $d['proveedor_id'] ?? null,
                   $d['empleado_id'] ?? null, $d['concepto'], round((float)$d['monto'], 2), $d['banco'] ?? null,
                   $d['cuenta_bancaria'] ?? null, $d['banco_tipo'] ?? null, $d['orden_compra_id'] ?? null,
                   $d['solicitud_gastos_id'] ?? null, $d['proyecto_id'] ?? null, $_SESSION['usuario_id'] ?? null, $tabla, $id]);
    $opId = (int)$pdo->lastInsertId();
    registrarParticipacion($pdo, 'compras_pago.php', $opId);   // "Elaborado por": quien autorizó el proceso
    registrarEvento($pdo, 'INSERT', ['modulo' => 'compras_pago.php', 'tabla' => 'ordenes_pago', 'registro_id' => $opId,
        'detalle' => "Orden de pago $num generada automáticamente para {$d['numero_origen']}"]);
    try { avisarPendiente($pdo, 'compras_pago.php', $opId, 'revisar'); }
    catch (Throwable $e) { error_log('Proscala: no se pudo avisar la orden de pago ' . $num . ': ' . $e->getMessage()); }
    return $num;
}

/** ¿Esta petición dispara la orden de pago automática del módulo? */
function accionGeneraOrdenPago(string $modulo): bool {
    $def = ORDEN_PAGO_AUTOMATICA[$modulo] ?? null;
    $act = $_POST['_action'] ?? '';
    if (!$def || $act !== $def['accion']) return false;
    if ($def['estado'] !== null && ($_POST['estado'] ?? '') !== $def['estado']) return false;
    return !($act === 'guardar' && (int)($_POST['id'] ?? 0));   // caja chica: solo al crearla
}

/**
 * Se llama desde requireLogin() antes de que el módulo aplique la acción: si la acción genera la
 * orden de pago, guarda el estado del proceso y retiene la respuesta (ob_start) hasta saber si la
 * OP se generó. Así la autorización y su OP quedan juntas o ninguna (revertirSinOrdenPago).
 */
function prepararOrdenPago(PDO $pdo, string $modulo): void {
    if (!accionGeneraOrdenPago($modulo)) return;
    $id = (int)($_POST['id'] ?? 0);
    $estado = null;
    if ($id) {
        $s = $pdo->prepare("SELECT estado FROM `" . ORDEN_PAGO_AUTOMATICA[$modulo]['tabla'] . "` WHERE id = ?");
        $s->execute([$id]);
        $estado = $s->fetchColumn() ?: null;
    }
    $GLOBALS['_op_previo'] = ['id' => $id, 'estado' => $estado];
    ob_start();
}

/**
 * La orden de pago no se pudo generar (registrarPeticion). Una autorización se revierte (estado
 * anterior, sin el paso "autorizado") y la respuesta pasa a ser el error. Una creación (préstamo,
 * caja chica) se conserva, con el aviso de que falta su orden de pago.
 */
function revertirSinOrdenPago(PDO $pdo, string $modulo, int $id, Throwable $e): void {
    $def = ORDEN_PAGO_AUTOMATICA[$modulo];
    $previo = $GLOBALS['_op_previo'] ?? ['id' => 0, 'estado' => null];
    $esAutorizacion = $previo['id'] > 0;
    if ($esAutorizacion) {
        if ($def['estado'] !== null && $previo['estado'] !== null) {
            $pdo->prepare("UPDATE `{$def['tabla']}` SET estado = ? WHERE id = ?")->execute([$previo['estado'], $id]);
        }
        $pdo->prepare("DELETE FROM aprobaciones WHERE tabla = ? AND registro_id = ? AND paso = 'autorizado'")->execute([$def['tabla'], $id]);
    }
    $motivo = $e instanceof RuntimeException ? $e->getMessage() : 'error en la base de datos';
    $mensaje = $esAutorizacion
        ? "No se pudo generar la orden de pago ($motivo), así que el proceso NO se autorizó. Intente de nuevo en unos momentos; si se repite, avise al administrador."
        : "Se guardó, pero no se pudo generar su orden de pago ($motivo). Avise al administrador para generarla.";
    registrarEvento($pdo, $esAutorizacion ? 'AUTORIZAR' : 'INSERT', ['tabla' => $def['tabla'], 'registro_id' => $id ?: null, 'resultado' => 'error',
        'detalle' => ($esAutorizacion ? 'Autorización revertida: ' : 'Guardado sin orden de pago: ') . 'no se pudo generar la orden de pago automática: ' . $e->getMessage()]);

    // La respuesta del módulo sigue retenida (prepararOrdenPago): se reemplaza
    if (ob_get_level()) {
        $original = json_decode((string)ob_get_clean(), true);
        if (!headers_sent()) header('Content-Type: application/json');
        if ($esAutorizacion) {
            if (!headers_sent()) http_response_code(409);
            echo json_encode(['success' => false, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => true, 'message' => trim(($original['message'] ?? '') . ' ' . $mensaje)] + (array)$original, JSON_UNESCAPED_UNICODE);
        }
    }
}

/** Al terminar la petición (registrarPeticion): genera la OP si la acción autorizó el proceso. */
function ordenPagoTrasPeticion(PDO $pdo, string $modulo, string $act, int $id): void {
    if (!$id || !accionGeneraOrdenPago($modulo)) return;
    generarOrdenPago($pdo, ORDEN_PAGO_AUTOMATICA[$modulo]['tabla'], $id);
}

/**
 * Enlace proceso ↔ orden de pago para el aviso de includes/header.php al ver un registro:
 * en un proceso, sus órdenes de pago; en una orden de pago, su proceso de origen.
 */
function enlacesOrdenPago(PDO $pdo, string $modulo, int $id): array {
    if ($modulo === 'compras_pago.php') {
        $s = $pdo->prepare("SELECT origen_tabla, origen_id FROM ordenes_pago WHERE id = ?");
        $s->execute([$id]);
        $o = $s->fetch();
        foreach (ORDEN_PAGO_AUTOMATICA as $modOrigen => $def) {
            if ($o && $def['tabla'] === $o['origen_tabla']) {
                return [['titulo' => 'Proceso de origen', 'numero' => numeroDe($pdo, $o['origen_tabla'], $o['origen_id']),
                         'url' => $modOrigen . '?action=ver&id=' . $o['origen_id'], 'estado' => '']];
            }
        }
        return [];
    }
    $def = ORDEN_PAGO_AUTOMATICA[$modulo] ?? null;
    if (!$def) return [];
    $s = $pdo->prepare("SELECT id, numero, estado FROM ordenes_pago WHERE origen_tabla = ? AND origen_id = ? ORDER BY id");
    $s->execute([$def['tabla'], $id]);
    return array_map(fn($op) => ['titulo' => 'Orden de pago', 'numero' => $op['numero'],
        'url' => 'compras_pago.php?action=ver&id=' . $op['id'], 'estado' => $op['estado']], $s->fetchAll());
}

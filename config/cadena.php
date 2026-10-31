<?php
// ── Cadena del proceso (includes/cadena_proceso.php) ─────────────────
// Desde cualquier documento, todos los que forman su mismo proceso, paso por paso:
//   compras   → Solicitud → Cotización → Orden(es) de Compra → Nota(s) de Recepción → Orden(es) de Pago
//   viáticos  → Anticipo → Liquidación / Reembolso → Orden(es) de Pago
//   otros     → el proceso (planilla, liquidación laboral, préstamo, caja chica) → su Orden de Pago
// Un paso puede tener varios documentos (p. ej. una orden de compra por proveedor en la compra por
// artículo). Una orden de pago se liga por orden_compra_id / solicitud_gastos_id o por su origen
// (origen_tabla, origen_id: las automáticas). Las anuladas no se muestran.

// Pasos de cada cadena: módulo => [etiqueta, ícono]
const CADENA_COMPRAS  = ['compras_solicitud.php' => ['Solicitud', 'fa-cart-plus'], 'compras_cotizaciones.php' => ['Cotización', 'fa-file-lines'],
                         'compras_orden.php' => ['Orden de Compra', 'fa-file-circle-check'], 'compras_recepcion.php' => ['Nota de Recepción', 'fa-boxes-stacked'],
                         'compras_pago.php' => ['Orden de Pago', 'fa-money-bill-transfer']];
const CADENA_VIATICOS = ['gastos_viajes.php' => ['Anticipo', 'fa-plane'], 'gastos_solicitud.php' => ['Liquidación / Reembolso', 'fa-file-invoice-dollar'],
                         'compras_pago.php' => ['Orden de Pago', 'fa-money-bill-transfer']];
// Procesos que solo generan su orden de pago: módulo => [origen_tabla de ordenes_pago, etiqueta, ícono]
const CADENA_ORIGENES = ['planillas.php'            => ['planilla_periodos',   'Planilla de Sueldos',    'fa-money-check-dollar'],
                         'planillas_asignacion.php' => ['planilla_asignacion', 'Planilla de Asignación', 'fa-list-check'],
                         'planillas_beneficios.php' => ['planilla_beneficios', 'Planilla de Beneficios', 'fa-gift'],
                         'liquidaciones.php'        => ['liquidaciones',       'Liquidación Laboral',    'fa-file-invoice'],
                         'prestamos.php'            => ['prestamos',           'Préstamo',               'fa-hand-holding-dollar'],
                         'caja_chica.php'           => ['caja_chica',          'Caja Chica',             'fa-cash-register']];

/**
 * Cadena del documento $id del módulo $modulo: lista de pasos ['modulo', 'etiqueta', 'icono',
 * 'docs' => [['id', 'numero', 'estado']…]], o [] si el documento no forma parte de una cadena.
 */
function cadenaProceso(PDO $pdo, string $modulo, int $id): array {
    $uno = function (string $sql, array $p = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch() ?: null; };
    $ids = function (string $sql, array $p = []) use ($pdo): array {
        if (in_array([], $p, true)) return [];
        $s = $pdo->prepare($sql); $s->execute(array_merge(...array_map(fn($x) => (array)$x, $p)));
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    };
    $en = fn(array $lista) => $lista ? implode(',', array_fill(0, count($lista), '?')) : 'NULL';

    // Orden de pago: ¿de qué cadena es?
    $op = null;
    if ($modulo === 'compras_pago.php') {
        $op = $uno("SELECT id, orden_compra_id, solicitud_gastos_id, origen_tabla, origen_id FROM ordenes_pago WHERE id = ?", [$id]);
        if (!$op) return [];
    }

    // ── Compras ─────────────────────────────────────────────────────
    $esCompra = isset(CADENA_COMPRAS[$modulo]) && $modulo !== 'compras_pago.php'
             || ($op && ($op['orden_compra_id'] || $op['origen_tabla'] === 'ordenes_compra'));
    if ($esCompra) {
        $sc = $co = $oc = [];
        switch ($modulo) {
            case 'compras_solicitud.php':    $sc = [$id]; break;
            case 'compras_cotizaciones.php': $co = [$id]; break;
            case 'compras_orden.php':        $oc = [$id]; break;
            case 'compras_recepcion.php':
                if ($r = $uno("SELECT orden_compra_id FROM notas_recepcion WHERE id = ?", [$id])) $oc = array_filter([(int)$r['orden_compra_id']]);
                break;
            case 'compras_pago.php':         $oc = [(int)($op['orden_compra_id'] ?: $op['origen_id'])]; break;
        }
        // Hacia arriba: la solicitud de compra
        if (!$sc && $co) $sc = $ids("SELECT solicitud_id FROM cotizaciones WHERE id IN ({$en($co)})", [$co]);
        if (!$sc && $oc) {
            $sc = $ids("SELECT solicitud_id FROM ordenes_compra WHERE id IN ({$en($oc)}) AND solicitud_id IS NOT NULL", [$oc]);
            if (!$sc) $sc = $ids("SELECT c.solicitud_id FROM ordenes_compra o JOIN cotizaciones c ON c.id = o.cotizacion_id WHERE o.id IN ({$en($oc)})", [$oc]);
        }
        $sc = array_values(array_unique(array_filter($sc)));
        // Hacia abajo, desde la solicitud (así aparecen todas las órdenes de compra, p. ej. una por proveedor)
        if ($sc) {
            $co = array_values(array_unique(array_merge($co, $ids("SELECT id FROM cotizaciones WHERE solicitud_id IN ({$en($sc)})", [$sc]))));
            $oc = array_values(array_unique(array_merge($oc, $ids("SELECT id FROM ordenes_compra WHERE solicitud_id IN ({$en($sc)})"
                . ($co ? " OR cotizacion_id IN ({$en($co)})" : ''), $co ? [$sc, $co] : [$sc]))));
        } elseif ($oc && !$co) {
            $co = $ids("SELECT cotizacion_id FROM ordenes_compra WHERE id IN ({$en($oc)}) AND cotizacion_id IS NOT NULL", [$oc]);
        }
        $oc = array_values(array_filter($oc));
        $nr = $oc ? $ids("SELECT id FROM notas_recepcion WHERE orden_compra_id IN ({$en($oc)})", [$oc]) : [];
        $ops = $oc ? $ids("SELECT id FROM ordenes_pago WHERE estado <> 'anulada' AND (orden_compra_id IN ({$en($oc)})
                          OR (origen_tabla = 'ordenes_compra' AND origen_id IN ({$en($oc)})))", [$oc, $oc]) : [];
        if ($op && !in_array((int)$op['id'], $ops, true)) $ops[] = (int)$op['id'];
        return armarCadena($pdo, CADENA_COMPRAS, ['compras_solicitud.php' => $sc, 'compras_cotizaciones.php' => $co,
            'compras_orden.php' => $oc, 'compras_recepcion.php' => $nr, 'compras_pago.php' => $ops]);
    }

    // ── Viáticos ────────────────────────────────────────────────────
    $esViatico = in_array($modulo, ['gastos_viajes.php', 'gastos_solicitud.php'], true)
              || ($op && ($op['solicitud_gastos_id'] || in_array($op['origen_tabla'], ['gastos_viaje', 'solicitud_gastos'], true)));
    if ($esViatico) {
        $gv = $sg = [];
        if ($modulo === 'gastos_viajes.php') $gv = [$id];
        elseif ($modulo === 'gastos_solicitud.php') $sg = [$id];
        elseif ($op['origen_tabla'] === 'gastos_viaje') $gv = [(int)$op['origen_id']];
        else $sg = [(int)($op['solicitud_gastos_id'] ?: $op['origen_id'])];
        if (!$gv && $sg) $gv = $ids("SELECT viaje_id FROM solicitud_gastos WHERE id IN ({$en($sg)}) AND viaje_id IS NOT NULL", [$sg]);
        if ($gv) $sg = array_values(array_unique(array_merge($sg, $ids("SELECT id FROM solicitud_gastos WHERE viaje_id IN ({$en($gv)})", [$gv]))));
        $ops = $ids("SELECT id FROM ordenes_pago WHERE estado <> 'anulada' AND ("
            . ($gv ? "(origen_tabla = 'gastos_viaje' AND origen_id IN ({$en($gv)}))" : '0')
            . ($sg ? " OR solicitud_gastos_id IN ({$en($sg)}) OR (origen_tabla = 'solicitud_gastos' AND origen_id IN ({$en($sg)}))" : '') . ')',
            array_merge($gv ? [$gv] : [], $sg ? [$sg, $sg] : []));
        if ($op && !in_array((int)$op['id'], $ops, true)) $ops[] = (int)$op['id'];
        return armarCadena($pdo, CADENA_VIATICOS, ['gastos_viajes.php' => $gv, 'gastos_solicitud.php' => $sg, 'compras_pago.php' => $ops]);
    }

    // ── Otros procesos con orden de pago ────────────────────────────
    $origen = false;
    if ($op) { foreach (CADENA_ORIGENES as $m => [$tabla]) if ($tabla === $op['origen_tabla']) $origen = $m; }
    elseif (isset(CADENA_ORIGENES[$modulo])) $origen = $modulo;
    if (!$origen) return [];
    [$tabla, $etiqueta, $icono] = CADENA_ORIGENES[$origen];
    $oid = $op ? (int)$op['origen_id'] : $id;
    $ops = $ids("SELECT id FROM ordenes_pago WHERE origen_tabla = ? AND origen_id = ? AND estado <> 'anulada'", [$tabla, $oid]);
    if ($op && !in_array((int)$op['id'], $ops, true)) $ops[] = (int)$op['id'];
    return armarCadena($pdo, [$origen => [$etiqueta, $icono], 'compras_pago.php' => CADENA_COMPRAS['compras_pago.php']],
        [$origen => [$oid], 'compras_pago.php' => $ops]);
}

/** Pasos con sus documentos (número y estado), en el orden de la cadena. */
function armarCadena(PDO $pdo, array $pasos, array $docs): array {
    $cadena = [];
    foreach ($pasos as $modulo => [$etiqueta, $icono]) {
        $lista = array_values(array_unique(array_filter($docs[$modulo] ?? [])));
        $filas = [];
        if ($lista) {
            $tabla = PROCESOS[$modulo]['tabla'] ?? EDICION_PROCESOS[$modulo]['tabla'];
            $extra = $modulo === 'gastos_viajes.php' ? ', tipo' : '';
            $s = $pdo->prepare("SELECT id, numero, estado$extra FROM `$tabla` WHERE id IN (" . implode(',', array_fill(0, count($lista), '?')) . ") ORDER BY numero, id");
            $s->execute($lista);
            $filas = $s->fetchAll();
            foreach ($filas as &$f) if (trim((string)$f['numero']) === '') $f['numero'] = '#' . $f['id'];   // registros antiguos sin número
            unset($f);
        }
        if ($modulo === 'gastos_viajes.php' && $filas) {   // anticipo de viaje o de compra
            $etiqueta = ($filas[0]['tipo'] ?? '') === 'compra' ? 'Anticipo de Compra' : 'Anticipo de Viaje';
            $icono = tipoAnticipoIcon($filas[0]['tipo'] ?? 'viaje');
        }
        $cadena[] = ['modulo' => $modulo, 'etiqueta' => $etiqueta, 'icono' => $icono, 'docs' => $filas];
    }
    return $cadena;
}

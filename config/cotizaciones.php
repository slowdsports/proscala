<?php
// ── Cotizaciones: cuadro comparativo y recomendación ─────────────────
// Una cotización (compras_cotizaciones.php) tiene artículos (cotizaciones_items: los de la
// solicitud de compra, editables) y una oferta por proveedor (cotizaciones_proveedores) con el
// precio unitario de cada artículo que cotizó (cotizaciones_precios; sin fila = no lo cotizó).
// El total de una oferta es la suma de cantidad × precio de lo que cotizó. Las cotizaciones
// anteriores a esta estructura no tienen artículos: se comparan por su monto global.
//
// Recomendación (analizarCotizacion):
//   · Proveedor único: el de menor total entre los que cotizaron todos los artículos (si
//     ninguno los cotizó todos, el que más cubre y, entre esos, el más barato).
//   · Compra por artículo: cada artículo al proveedor con el menor precio unitario; ante un
//     empate, al proveedor único recomendado y luego al de menor total (menos órdenes de compra).

/** Artículos y ofertas de una cotización; cada oferta trae 'precios' => [item_id => precio]. */
function cargarCotizacion(PDO $pdo, int $id): array {
    $s = $pdo->prepare("SELECT * FROM cotizaciones_items WHERE cotizacion_id = ? ORDER BY orden, id");
    $s->execute([$id]);
    $items = $s->fetchAll();
    $s = $pdo->prepare("SELECT cp.*, p.nombre AS prov_nombre, p.rtn AS prov_rtn
        FROM cotizaciones_proveedores cp JOIN proveedores p ON p.id = cp.proveedor_id
        WHERE cp.cotizacion_id = ? ORDER BY cp.id");
    $s->execute([$id]);
    $ofertas = [];
    foreach ($s->fetchAll() as $o) { $o['precios'] = []; $ofertas[(int)$o['id']] = $o; }
    $s = $pdo->prepare("SELECT pr.item_id, pr.cotizacion_proveedor_id, pr.precio_unitario
        FROM cotizaciones_precios pr JOIN cotizaciones_items i ON i.id = pr.item_id WHERE i.cotizacion_id = ?");
    $s->execute([$id]);
    foreach ($s->fetchAll() as $p) {
        if (isset($ofertas[(int)$p['cotizacion_proveedor_id']])) $ofertas[(int)$p['cotizacion_proveedor_id']]['precios'][(int)$p['item_id']] = (float)$p['precio_unitario'];
    }
    return ['items' => $items, 'ofertas' => array_values($ofertas)];
}

/**
 * Artículos de una Orden de Compra hecha desde la cotización: los que cotizó el proveedor, con su
 * precio unitario; con $soloAsignados, solo los que la compra por artículo le asigna. null si la
 * cotización no tiene precios por artículo (la orden usa entonces los de la solicitud de compra).
 * Cada artículo: descripcion, unidad, cantidad, precio_estimado (el precio cotizado).
 */
function itemsOrdenDesdeCotizacion(PDO $pdo, int $coId, int $provId, bool $soloAsignados = false): ?array {
    $cot = cargarCotizacion($pdo, $coId);
    $an  = analizarCotizacion($cot['items'], $cot['ofertas']);
    if (!$an['con_detalle']) return null;
    $of = null;
    foreach ($cot['ofertas'] as $o) if ((int)$o['proveedor_id'] === $provId) $of = $o;
    if (!$of) return [];
    $asignacion = $an['por_articulo']['asignacion'] ?? [];
    $out = [];
    foreach ($cot['items'] as $it) {
        $iid = (int)$it['id'];
        if (!array_key_exists($iid, $of['precios'])) continue;
        if ($soloAsignados && ($asignacion[$iid] ?? 0) !== (int)$of['id']) continue;
        $out[] = ['descripcion' => $it['descripcion'], 'unidad' => $it['unidad'], 'cantidad' => (float)$it['cantidad'],
                  'precio_estimado' => $of['precios'][$iid]];
    }
    return $out;
}

/**
 * Cuadro comparativo de $items (con 'id' y 'cantidad') y $ofertas (con 'id', 'total' y 'precios').
 *   con_detalle  → hay precios por artículo (si no, se compara el monto global de cada oferta)
 *   ofertas      → [oferta_id => ['total' => L., 'cubre' => n artículos cotizados]]
 *   minimos      → [item_id => ['precio' => menor precio unitario, 'ofertas' => [oferta_id…]]]
 *   unico        → ['oferta_id', 'total', 'completo' (cotizó todo), 'faltan' => n] o null
 *   por_articulo → ['asignacion' => [item_id => oferta_id], 'grupos' => [oferta_id => ['items' => [ids], 'total']],
 *                   'total', 'sin_oferta' => [item_id…]] o null
 *   ahorro       → cuánto menos cuesta comprar por artículo que al proveedor único (0 si no aplica)
 */
function analizarCotizacion(array $items, array $ofertas): array {
    $res = ['con_detalle' => false, 'ofertas' => [], 'minimos' => [], 'unico' => null, 'por_articulo' => null, 'ahorro' => 0.0];
    $conDetalle = $items && array_filter($ofertas, fn($o) => !empty($o['precios']));
    $res['con_detalle'] = (bool)$conDetalle;
    $cant = [];
    foreach ($items as $it) $cant[(int)$it['id']] = (float)$it['cantidad'];

    foreach ($ofertas as $o) {
        $oid = (int)$o['id'];
        if ($conDetalle) {
            $total = 0.0; $cubre = 0;
            foreach ($o['precios'] as $iid => $p) {
                if (!isset($cant[$iid])) continue;
                $total += $cant[$iid] * $p;
                $cubre++;
            }
            $res['ofertas'][$oid] = ['total' => round($total, 2), 'cubre' => $cubre];
        } else {
            $res['ofertas'][$oid] = ['total' => round((float)$o['total'], 2), 'cubre' => 0];
        }
    }
    if (!$res['ofertas']) return $res;

    // Proveedor único: cotizó todo (o lo más posible) y es el más barato
    $n = count($cant);
    $orden = $res['ofertas'];
    uksort($orden, function ($a, $b) use ($orden, $conDetalle) {
        if ($conDetalle && $orden[$a]['cubre'] !== $orden[$b]['cubre']) return $orden[$b]['cubre'] <=> $orden[$a]['cubre'];
        return [$orden[$a]['total'], $a] <=> [$orden[$b]['total'], $b];
    });
    $mejor = array_key_first($orden);
    $res['unico'] = ['oferta_id' => $mejor, 'total' => $orden[$mejor]['total'],
                     'completo' => !$conDetalle || $orden[$mejor]['cubre'] === $n, 'faltan' => $conDetalle ? $n - $orden[$mejor]['cubre'] : 0];
    if (!$conDetalle) return $res;

    // Menor precio de cada artículo y asignación por artículo
    $preferencia = array_flip(array_keys($orden));   // desempate: el proveedor único primero, luego por total
    $pa = ['asignacion' => [], 'grupos' => [], 'total' => 0.0, 'sin_oferta' => []];
    foreach ($cant as $iid => $q) {
        $min = null; $quienes = [];
        foreach ($ofertas as $o) {
            if (!array_key_exists($iid, $o['precios'])) continue;
            $p = (float)$o['precios'][$iid];
            if ($min === null || $p < $min - 0.0001) { $min = $p; $quienes = [(int)$o['id']]; }
            elseif (abs($p - $min) <= 0.0001) $quienes[] = (int)$o['id'];
        }
        if ($min === null) { $pa['sin_oferta'][] = $iid; continue; }
        $res['minimos'][$iid] = ['precio' => $min, 'ofertas' => $quienes];
        usort($quienes, fn($a, $b) => $preferencia[$a] <=> $preferencia[$b]);
        $oid = $quienes[0];
        $pa['asignacion'][$iid] = $oid;
        $pa['grupos'][$oid]['items'][] = $iid;
        $pa['grupos'][$oid]['total'] = round(($pa['grupos'][$oid]['total'] ?? 0) + $q * $min, 2);
        $pa['total'] += $q * $min;
    }
    $pa['total'] = round($pa['total'], 2);
    uksort($pa['grupos'], fn($a, $b) => $preferencia[$a] <=> $preferencia[$b]);
    $res['por_articulo'] = $pa;
    if ($res['unico']['completo'] && !$pa['sin_oferta']) $res['ahorro'] = round(max(0, $res['unico']['total'] - $pa['total']), 2);
    return $res;
}

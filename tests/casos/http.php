<?php
// Páginas por HTTP (Apache local): módulos, documentos impresos, búsqueda con permisos, landing y login.
// Crea sesiones de prueba en storage/sesiones y las borra al terminar.

$baseUrl = 'http://localhost/' . basename(RAIZ);
$servidorArriba = @file_get_contents("$baseUrl/login.php") !== false;
$inicioHttp = (string)$pdo->query('SELECT NOW()')->fetchColumn();

/** Crea una sesión de prueba del usuario y devuelve su id. */
function sesionDePrueba(PDO $pdo, array $empresa, int $usuarioId): string {
    $u = $pdo->query("SELECT * FROM usuarios WHERE id = $usuarioId")->fetch();
    $id = 'prueba' . bin2hex(random_bytes(12));
    $datos = ['usuario_id' => (int)$u['id'], 'usuario_nombre' => $u['nombre'], 'usuario_rol' => $u['rol'], 'usuario_email' => $u['email'],
              'usuario_foto' => '', 'empresa_id' => (int)$empresa['id'], 'empresa_db' => $empresa['db_nombre'], 'empresa_nombre' => $empresa['nombre'],
              'ultima_actividad' => time(), 'csrf_token' => 'tokenprueba'];
    // Formato de sesión de PHP (session.serialize_handler = php)
    $txt = '';
    foreach ($datos as $k => $v) $txt .= $k . '|' . serialize($v);
    file_put_contents(RAIZ . "/storage/sesiones/sess_$id", $txt);
    $GLOBALS['_sesiones_prueba'][] = $id;
    return $id;
}
function pedir(string $url, string $sesion = ''): array {
    $ctx = stream_context_create(['http' => ['header' => ($sesion ? "Cookie: PROSCALA=$sesion\r\n" : '') . "X-Requested-With: " . (str_contains($url, 'buscar.php') ? 'XMLHttpRequest' : 'no') . "\r\n",
                                             'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60]]);
    $cuerpo = (string)@file_get_contents($url, false, $ctx);
    preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $m);
    return [(int)($m[1] ?? 0), $cuerpo];
}
/** Errores de PHP visibles en la página (con display_errors desactivado no debería haber). */
function erroresPhp(string $html): string {
    return preg_match('/(Fatal error|Warning|Notice|Deprecated|Parse error)<\/b>:|Uncaught /', $html, $m) ? $m[0] : '';
}

if (!$servidorArriba) {
    prueba('Páginas por HTTP', fn() => throw new Omitir("Apache no responde en $baseUrl"));
    return;
}
$admin = (int)$pdo->query("SELECT u.id FROM usuarios u WHERE u.rol = 'admin' AND u.activo = 1 ORDER BY u.id LIMIT 1")->fetchColumn();
$sesAdmin = sesionDePrueba($pdo, $empresa, $admin);

try {
    prueba('Páginas principales cargan sin errores (como administrador)', function () use ($baseUrl, $sesAdmin) {
        $fallas = [];
        $paginas = ['dashboard.php', 'mis_solicitudes.php', 'perfil.php', 'empleados.php', 'planillas.php', 'gastos_solicitud.php', 'gastos_solicitud.php?action=nuevo',
                    'gastos_viajes.php', 'compras_solicitud.php', 'compras_orden.php', 'compras_pago.php', 'vacaciones.php', 'permisos.php', 'caja_chica.php',
                    'prestamos.php', 'liquidaciones.php', 'catalogos.php', 'configuracion.php', 'usuarios.php', 'roles.php', 'auditoria.php', 'reportes.php',
                    'reportes_legales.php', 'presupuesto.php', 'proyectos.php', 'proveedores.php', 'activos.php', 'memorandos.php', 'actas.php', 'constancias.php', 'asistencia.php'];
        foreach ($paginas as $p) {
            [$cod, $html] = pedir("$baseUrl/$p", $sesAdmin);
            if ($cod !== 200 || !str_contains($html, 'id="main-content"') || ($e = erroresPhp($html))) $fallas[] = "$p (HTTP $cod" . (!empty($e) ? ", $e" : '') . ')';
        }
        afirmar(!$fallas, implode(', ', $fallas));
    });

    prueba('Documentos impresos: los 27 tipos con datos reales', function () use ($baseUrl, $sesAdmin, $pdo) {
        $uno = fn(string $sql) => $pdo->query($sql)->fetchColumn() ?: null;
        $emp = $uno("SELECT id FROM empleados ORDER BY id LIMIT 1");
        $tipos = ['sc' => 'solicitud_compra', 'co' => 'cotizaciones', 'oc' => 'ordenes_compra', 'op' => 'ordenes_pago', 'pl' => 'planilla_periodos', 'plp' => 'planilla_periodos',
                  'plc' => 'planilla_periodos', 'plv' => 'planilla_periodos', 'gv' => 'gastos_viaje', 'sg' => 'solicitud_gastos', 'pa' => 'planilla_asignacion',
                  'pav' => 'planilla_asignacion', 'pb' => 'planilla_beneficios', 'pbp' => 'planilla_beneficios', 'pbv' => 'planilla_beneficios',
                  'proceso' => 'solicitud_compra', 'vac' => 'vacaciones', 'per' => 'permisos', 'cc' => 'caja_chica', 'liq' => 'liquidaciones',
                  'memo' => 'memorandos', 'acta' => 'actas_reunion'];
        $probados = $fallas = $sinDatos = [];
        foreach ($tipos as $tipo => $tabla) {
            $id = $uno("SELECT id FROM `$tabla` ORDER BY id DESC LIMIT 1");
            if (!$id) { $sinDatos[] = $tipo; continue; }
            $probados[$tipo] = $id;
        }
        if ($gva = $uno("SELECT id FROM gastos_viaje WHERE monto_alimentacion > 0 ORDER BY id DESC LIMIT 1")) $probados['gva'] = $gva; else $sinDatos[] = 'gva';
        foreach (['trabajo', 'salario', 'referencia', 'tiempo'] as $c) $probados[$c] = $emp;
        foreach ($probados as $tipo => $id) {
            [$cod, $html] = pedir("$baseUrl/print.php?tipo=$tipo&id=$id", $sesAdmin);
            if ($cod !== 200 || !str_contains($html, 'class="page') || ($e = erroresPhp($html))) $fallas[] = "$tipo #$id (HTTP $cod" . (!empty($e) ? ", $e" : '') . ')';
            // Como máximo 3 firmas de la organización por página (la del proveedor, sig-ext, aparte)
            foreach (array_slice(preg_split('#<div class="page[ "]#', $html), 1) as $n => $pagina) {
                if (($f = preg_match_all('#class="sig-item"#', $pagina)) > 3) $fallas[] = "$tipo #$id: $f firmas en la página " . ($n + 1);
            }
        }
        afirmar(!$fallas, implode(', ', $fallas));
        if ($sinDatos) echo '      (sin datos locales para: ' . implode(', ', $sinDatos) . ")\n";
    });

    prueba('Búsqueda: quien solo ve sus solicitudes no encuentra las ajenas ni otros módulos', function () use ($baseUrl, $pdo, $empresa) {
        $u = $pdo->query("SELECT u.id, u.empleado_id FROM usuarios u JOIN roles r ON r.clave = u.rol AND r.solo_propios = 1
                          WHERE u.activo = 1 AND u.empleado_id IS NOT NULL LIMIT 1")->fetch();
        if (!$u) throw new Omitir('no hay usuarios con "solo sus solicitudes"');
        $ses = sesionDePrueba($pdo, $empresa, (int)$u['id']);
        foreach (['GV', 'SG', 'SC', 'OP', 'OC'] as $q) {
            [$cod, $json] = pedir("$baseUrl/buscar.php?q=$q", $ses);
            iguales(200, $cod, "HTTP de buscar.php?q=$q");
            foreach (json_decode($json, true)['resultados'] ?? [] as $r) {
                preg_match('/^(\w+\.php)\?action=ver&id=(\d+)$/', $r['url'], $m);
                if (!$m) continue;
                afirmar(isset(SOLICITUDES[$m[1]]), "encontró un proceso de {$m[1]}, módulo que no es de sus solicitudes");
                $tabla = SOLICITUDES[$m[1]]['tabla']; $col = SOLICITUDES[$m[1]]['empleado'];
                $suyo = $pdo->query("SELECT 1 FROM `$tabla` WHERE id = {$m[2]} AND ($col = {$u['empleado_id']}
                    OR id IN (SELECT registro_id FROM registro_usuarios WHERE tabla = '$tabla' AND usuario_id = {$u['id']}))")->fetchColumn();
                afirmar($suyo, "encontró {$r['titulo']}, que no es suyo");
            }
        }
    });

    prueba('Abrir un proceso: el dashboard enlaza por id y un número o id inexistente no abre el formulario', function () use ($baseUrl, $sesAdmin, $pdo) {
        [, $html] = pedir("$baseUrl/dashboard.php", $sesAdmin);
        afirmar(!preg_match('/action=ver&(amp;)?id=[A-Za-z]/', $html), 'el dashboard enlaza un proceso por su número');
        $op = $pdo->query("SELECT id FROM ordenes_pago ORDER BY id DESC LIMIT 1")->fetchColumn();
        if ($op) afirmar(str_contains($html, "compras_pago.php?action=ver&amp;id=$op") || str_contains($html, "compras_pago.php?action=ver&id=$op"), 'las órdenes de pago del dashboard no tienen enlace');
        $sc = $pdo->query("SELECT id, numero FROM solicitud_compra ORDER BY id DESC LIMIT 1")->fetch();
        if ($sc) {
            $ctx = stream_context_create(['http' => ['header' => "Cookie: PROSCALA=$sesAdmin\r\n", 'ignore_errors' => true, 'follow_location' => 0]]);
            @file_get_contents("$baseUrl/compras_solicitud.php?action=ver&id=" . urlencode($sc['numero']), false, $ctx);
            $location = preg_grep('/^Location:/i', $http_response_header ?? []);
            afirmar((bool)preg_grep('/[?&]id=' . (int)$sc['id'] . '(&|$)/', $location), "el número {$sc['numero']} no redirige a id={$sc['id']}");
        }
        [$cod] = pedir("$baseUrl/compras_solicitud.php?action=ver&id=999999999", $sesAdmin);
        iguales(302, $cod, 'un id inexistente vuelve a la lista');
    });

    prueba('Token de seguridad vencido: se rechaza sin ejecutar, queda explicado y sesion.php da el vigente', function () use ($baseUrl, $sesAdmin, $pdo) {
        $antes = (int)$pdo->query("SELECT COUNT(*) FROM solicitud_compra")->fetchColumn();
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true,
            'header' => "Cookie: PROSCALA=$sesAdmin\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['csrf_token' => 'vencido', '_action' => 'guardar', 'id' => 0, 'descripcion' => 'x'])]]);
        $r = json_decode((string)@file_get_contents("$baseUrl/compras_solicitud.php", false, $ctx), true) ?: [];
        afirmar(!empty($r['csrf_invalido']) && str_contains($r['message'] ?? '', 'sesión anterior'), 'la respuesta no marca el token vencido');
        iguales($antes, (int)$pdo->query("SELECT COUNT(*) FROM solicitud_compra")->fetchColumn(), 'no se guardó nada');
        $ev = $pdo->query("SELECT resultado, detalle FROM auditoria WHERE modulo = 'compras_solicitud.php' ORDER BY id DESC LIMIT 1")->fetch();
        afirmar($ev && $ev['resultado'] === 'error' && str_contains($ev['detalle'], 'Token de seguridad'), 'la bitácora no explica el rechazo');
        $pdo->exec("DELETE FROM auditoria WHERE modulo = 'compras_solicitud.php' AND detalle LIKE '%Token de seguridad vencido%' AND ip IN ('127.0.0.1', '::1')");
        [, $json] = pedir("$baseUrl/sesion.php", $sesAdmin);
        iguales('tokenprueba', json_decode($json, true)['csrf'] ?? null, 'token vigente de sesion.php');
    });

    prueba('Cotizaciones: precios por artículo, cuadro comparativo, PDF y orden de compra con los precios cotizados', function () use ($baseUrl, $sesAdmin, $pdo) {
        $sc = $pdo->query("SELECT sc.id, sc.estado FROM solicitud_compra sc WHERE (SELECT COUNT(*) FROM solicitud_compra_detalle d WHERE d.solicitud_id = sc.id) >= 2 ORDER BY sc.id DESC LIMIT 1")->fetch();
        $provs = $pdo->query("SELECT id FROM proveedores WHERE activo = 1 ORDER BY id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
        if (!$sc || count($provs) < 3) throw new Omitir('se necesita una solicitud con 2 artículos y 3 proveedores');
        $det = $pdo->query("SELECT id, descripcion, unidad, cantidad FROM solicitud_compra_detalle WHERE solicitud_id = {$sc['id']} ORDER BY id LIMIT 2")->fetchAll();
        $items = array_map(fn($d) => ['sd_id' => $d['id'], 'descripcion' => $d['descripcion'], 'unidad' => $d['unidad'], 'cantidad' => 2], $det);
        $of = fn($p, array $precios) => ['proveedor_id' => $p, 'num_cotizacion' => "Z-$p", 'precios' => $precios, 'total_global' => ''];
        $ofertas = [$of($provs[0], [100, 50]), $of($provs[1], [90, 40]), $of($provs[2], [70, ''])];   // único: el 2.º; artículo 1: el 3.º
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true,
            'header' => "Cookie: PROSCALA=$sesAdmin\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['csrf_token' => 'tokenprueba', '_action' => 'guardar', 'id' => 0, 'solicitud_id' => $sc['id'], 'fecha' => date('Y-m-d'),
                'proveedor_seleccionado_id' => $provs[1], 'items_data' => json_encode($items), 'proveedores_data' => json_encode($ofertas)])]]);
        $r = json_decode((string)@file_get_contents("$baseUrl/compras_cotizaciones.php", false, $ctx), true) ?: [];
        $coId = (int)($r['id'] ?? 0);
        try {
            afirmar(!empty($r['success']) && $coId, 'no se guardó la cotización: ' . ($r['message'] ?? 'sin respuesta'));
            iguales(5, (int)$pdo->query("SELECT COUNT(*) FROM cotizaciones_precios pr JOIN cotizaciones_items i ON i.id = pr.item_id WHERE i.cotizacion_id = $coId")->fetchColumn(), 'precios guardados (el vacío no cuenta)');
            iguales(260.0, (float)$pdo->query("SELECT total FROM cotizaciones_proveedores WHERE cotizacion_id = $coId AND proveedor_id = {$provs[1]}")->fetchColumn(), 'total calculado de la oferta');
            [$cod, $html] = pedir("$baseUrl/compras_cotizaciones.php?action=ver&id=$coId", $sesAdmin);
            afirmar($cod === 200 && str_contains($html, 'Recomendación del sistema') && str_contains($html, 'cot-min') && !erroresPhp($html), "vista de la cotización (HTTP $cod)");
            afirmar(str_contains($html, 'flujo-aprobacion'), 'la cotización no muestra el flujo de aprobación');
            [$cod, $html] = pedir("$baseUrl/print.php?tipo=co&id=$coId", $sesAdmin);
            afirmar($cod === 200 && str_contains($html, 'Compra por artículo') && !erroresPhp($html), "PDF del resumen (HTTP $cod)");
            pedir("$baseUrl/compras_cotizaciones.php?action=editar&id=$coId", $sesAdmin);   // el formulario de edición carga
            [$cod, $html] = pedir("$baseUrl/compras_orden.php?action=nuevo&co_id=$coId&prov_id={$provs[2]}&plan=articulo", $sesAdmin);
            preg_match('#<tbody id="tbody-items">(.*?)</tbody>#s', $html, $tb);
            preg_match_all('/data-field="precio" value="([^"]*)"/', $tb[1] ?? '', $pm);
            iguales(['70'], $pm[1], "precios de la OC por artículo al 3.er proveedor (HTTP $cod)");
        } finally {
            if ($coId) {
                $pdo->exec("DELETE FROM cotizaciones WHERE id = $coId");
                foreach (['registro_usuarios', 'notificaciones', 'aprobaciones'] as $t) $pdo->exec("DELETE FROM $t WHERE tabla = 'cotizaciones' AND registro_id = $coId");
            }
            $pdo->prepare("UPDATE solicitud_compra SET estado = ? WHERE id = ?")->execute([$sc['estado'], $sc['id']]);
            $pdo->exec("DELETE FROM auditoria WHERE modulo = 'compras_cotizaciones.php' AND ip IN ('127.0.0.1', '::1') AND detalle LIKE '%Z-{$provs[0]}%'");
        }
    });

    prueba('Formularios: el JavaScript de las páginas no tiene errores de sintaxis (node --check)', function () use ($baseUrl, $sesAdmin) {
        exec('node --version 2>&1', $v, $c);
        if ($c !== 0) throw new Omitir('Node.js no está instalado');
        $fallas = [];
        foreach (['compras_cotizaciones.php?action=nuevo', 'compras_solicitud.php?action=nuevo', 'compras_orden.php?action=nuevo', 'compras_pago.php?action=nuevo',
                  'gastos_viajes.php?action=nuevo', 'gastos_solicitud.php?action=nuevo', 'catalogos.php', 'planillas.php', 'empleados.php?action=nuevo'] as $p) {
            [, $html] = pedir("$baseUrl/$p", $sesAdmin);
            preg_match_all('#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#si', $html, $m);
            foreach ($m[1] as $k => $js) {
                $tmp = tempnam(sys_get_temp_dir(), 'js') . '.js';
                file_put_contents($tmp, "{\n$js\n}");   // como la navegación sin recarga: dentro de un bloque
                exec('node --check ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
                if ($code !== 0) $fallas[] = "$p (script $k): " . trim(implode(' ', array_slice($out, 0, 4)));
                @unlink($tmp); $out = [];
            }
        }
        afirmar(!$fallas, implode(' | ', $fallas));
    });

    prueba('Expedientes digitales (empleados y proveedores): suben por categoría, se muestran agrupados y no los ve quien no tiene el módulo', function () use ($baseUrl, $sesAdmin, $pdo, $empresa) {
        // módulo => [tabla, categoría, página del expediente, su título]
        $casos = ['empleados.php'   => ['empleados',   'Contrato y adendas',               'empleados.php?action=editar&id=%d',      'Expediente digital'],
                  'proveedores.php' => ['proveedores', 'Antecedentes penales y policiales', 'proveedores.php?action=expediente&id=%d', 'Expediente del proveedor']];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        foreach ($casos as $modulo => [$tabla, $categoria, $pagina, $titulo]) {
            $reg = (int)$pdo->query("SELECT id FROM `$tabla` ORDER BY id LIMIT 1")->fetchColumn();
            if (!$reg) continue;
            $nombre = "zz-$tabla-prueba.png";
            $b = 'zz' . bin2hex(random_bytes(8));
            $campo = fn($n, $v) => "--$b\r\nContent-Disposition: form-data; name=\"$n\"\r\n\r\n$v\r\n";
            $cuerpo = $campo('csrf_token', 'tokenprueba') . $campo('_action', 'subir') . $campo('modulo', $modulo) . $campo('registro_id', $reg)
                    . $campo('categoria', $categoria)
                    . "--$b\r\nContent-Disposition: form-data; name=\"archivos[]\"; filename=\"$nombre\"\r\nContent-Type: image/png\r\n\r\n$png\r\n--$b--\r\n";
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'content' => $cuerpo,
                'header' => "Cookie: PROSCALA=$sesAdmin\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: multipart/form-data; boundary=$b\r\n"]]);
            $r = json_decode((string)@file_get_contents("$baseUrl/adjuntos.php", false, $ctx), true) ?: [];
            $adj = $pdo->query("SELECT * FROM adjuntos WHERE tabla = '$tabla' AND registro_id = $reg AND nombre = '$nombre' ORDER BY id DESC LIMIT 1")->fetch();
            try {
                afirmar(!empty($r['success']) && $adj, "$modulo: no se subió el documento: " . ($r['message'] ?? 'sin respuesta'));
                iguales($categoria, $adj['categoria'], "$modulo: categoría guardada");
                afirmar(str_contains($r['html'] ?? '', $categoria), "$modulo: la lista no agrupa por categoría");
                [$cod, $html] = pedir("$baseUrl/" . sprintf($pagina, $reg), $sesAdmin);
                afirmar($cod === 200 && str_contains($html, $titulo) && str_contains($html, $nombre) && !erroresPhp($html), "$modulo: la página no muestra el expediente (HTTP $cod)");
                [$cod] = pedir("$baseUrl/adjuntos.php?action=ver&id={$adj['id']}", $sesAdmin);
                iguales(200, $cod, "$modulo: quien tiene el módulo descarga el documento");
                // Sin acceso al módulo (p. ej. un empleado que solo ve sus solicitudes): no lo ve
                // (que ya cambió su contraseña inicial: si no, todo lo lleva a Mi Perfil)
                $sin = $pdo->query("SELECT u.id FROM usuarios u WHERE u.activo = 1 AND u.rol <> 'admin' AND u.debe_cambiar_password = 0
                                    AND NOT EXISTS (SELECT 1 FROM rol_modulos m WHERE m.rol = u.rol AND m.modulo = '$modulo') LIMIT 1")->fetchColumn();
                if ($sin) {
                    [$cod] = pedir("$baseUrl/adjuntos.php?action=ver&id={$adj['id']}", sesionDePrueba($pdo, $empresa, (int)$sin));
                    iguales(404, $cod, "$modulo: sin acceso al módulo no se descarga el documento");
                }
            } finally {
                if ($adj) {
                    @unlink(RAIZ . '/assets/uploads/adjuntos/' . (int)$empresa['id'] . '/' . $adj['archivo']);
                    $pdo->exec("DELETE FROM adjuntos WHERE id = {$adj['id']}");
                }
                $pdo->exec("DELETE FROM auditoria WHERE modulo = '$modulo' AND detalle LIKE '%$nombre%'");
            }
        }
    });

    prueba('Planillas: anular una quincena libera el mes para una planilla mensual (enero 2039)', function () use ($baseUrl, $sesAdmin, $pdo) {
        $enviar = function (array $datos) use ($baseUrl, $sesAdmin) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
                'header' => "Cookie: PROSCALA=$sesAdmin\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($datos + ['csrf_token' => 'tokenprueba'])]]);
            return json_decode((string)@file_get_contents("$baseUrl/planillas.php", false, $ctx), true) ?: [];
        };
        $gen = fn(string $tipo) => $enviar(['_action' => 'generar', 'tipo' => $tipo, 'anio' => 2039, 'mes' => 1, 'quincena' => 'primera',
            'fecha_inicio' => '2039-01-01', 'fecha_fin' => $tipo === 'mensual' ? '2039-01-31' : '2039-01-15', 'fecha_pago' => '']);
        if ($pdo->query("SELECT COUNT(*) FROM planilla_periodos WHERE anio = 2039")->fetchColumn()) throw new Omitir('ya hay planillas de 2039');
        try {
            $q = $gen('quincenal');
            afirmar(!empty($q['id']), 'no se generó la quincena: ' . ($q['message'] ?? ''));
            // Con el flujo: no se aprueba sin revisión (ni quien la generó), ni se paga sin aprobar
            afirmar(empty($enviar(['_action' => 'cambiar_estado', 'id' => $q['id'], 'estado' => 'aprobada'])['success']), 'se aprobó sin pasar por el flujo');
            afirmar(empty($enviar(['_action' => 'cambiar_estado', 'id' => $q['id'], 'estado' => 'pagada'])['success']), 'se marcó pagada sin estar aprobada');
            [, $html] = pedir("$baseUrl/planillas.php?action=ver&id={$q['id']}", $sesAdmin);
            afirmar(str_contains($html, 'flujo-aprobacion') && !str_contains($html, "'aprobada','planilla_periodos'"), 'la planilla no muestra el flujo o conserva el botón Aprobar de un paso');
            afirmar(str_contains($gen('mensual')['message'] ?? '', 'ya tiene planilla quincenal'), 'con la quincena vigente, la mensual debía rechazarse');
            afirmar(empty($enviar(['_action' => 'anular', 'id' => $q['id'], 'motivo' => '']) ['success']), 'aceptó anular sin motivo');
            $a = $enviar(['_action' => 'anular', 'id' => $q['id'], 'motivo' => 'Prueba automática: se paga mensual']);
            afirmar(!empty($a['success']), 'no se anuló: ' . ($a['message'] ?? ''));
            iguales('anulada', $pdo->query("SELECT estado FROM planilla_periodos WHERE id = " . (int)$q['id'])->fetchColumn(), 'estado de la quincena');
            $m = $gen('mensual');
            afirmar(!empty($m['id']), 'con la quincena anulada, la mensual debía generarse: ' . ($m['message'] ?? ''));
            [$cod, $html] = pedir("$baseUrl/planillas.php?action=ver&id={$q['id']}", $sesAdmin);
            afirmar($cod === 200 && str_contains($html, 'Planilla anulada') && str_contains($html, 'se paga mensual') && !erroresPhp($html), "vista de la planilla anulada (HTTP $cod)");
        } finally {
            foreach ($pdo->query("SELECT id FROM planilla_periodos WHERE anio = 2039")->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $pdo->exec("DELETE pdp FROM planilla_detalle_proyectos pdp JOIN planilla_detalle pd ON pd.id = pdp.detalle_id WHERE pd.periodo_id = $id");
                $pdo->exec("DELETE FROM planilla_periodos WHERE id = $id");
                foreach (['registro_usuarios', 'notificaciones', 'aprobaciones', 'recordatorios'] as $t) $pdo->exec("DELETE FROM $t WHERE tabla = 'planilla_periodos' AND registro_id = $id");
            }
            $pdo->exec("DELETE FROM auditoria WHERE modulo = 'planillas.php' AND ip IN ('127.0.0.1', '::1') AND (detalle LIKE '%anio=2039%' OR detalle LIKE '%Prueba automática: se paga mensual%')");
        }
    });

    prueba('Catálogo de cuentas: se descargan la plantilla y el catálogo actual en Excel', function () use ($baseUrl, $sesAdmin) {
        foreach (['plantilla', 'catalogo'] as $tipo) {
            [$cod, $xlsx] = pedir("$baseUrl/catalogos.php?export=$tipo", $sesAdmin);
            afirmar($cod === 200 && str_starts_with($xlsx, "PK\x03\x04"), "?export=$tipo (HTTP $cod) no devolvió un .xlsx");
        }
    });

    prueba('Alta rápida desde una lista: crea el proveedor y el banco, y no los duplica', function () use ($baseUrl, $sesAdmin, $pdo) {
        $enviar = function (array $datos) use ($baseUrl, $sesAdmin) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
                'header' => "Cookie: PROSCALA=$sesAdmin\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($datos + ['csrf_token' => 'tokenprueba'])]]);
            return json_decode((string)@file_get_contents("$baseUrl/alta_rapida.php", false, $ctx), true) ?: [];
        };
        $nombre = 'Proveedor de prueba ' . bin2hex(random_bytes(4));
        $creados = ['proveedores' => [], 'cat_bancos' => []];
        try {
            $r = $enviar(['tipo' => 'proveedor', 'nombre' => $nombre, 'rtn' => '', 'categoria' => 'bienes']);
            afirmar(!empty($r['success']) && $r['valor'] === (string)$r['id'], 'no creó el proveedor: ' . ($r['message'] ?? 'sin respuesta'));
            $creados['proveedores'][] = (int)$r['id'];
            $otra = $enviar(['tipo' => 'proveedor', 'nombre' => $nombre]);
            iguales($r['id'], $otra['id'] ?? null, 'el mismo nombre devuelve el proveedor existente');
            $b = $enviar(['tipo' => 'banco', 'nombre' => $nombre]);
            afirmar(!empty($b['success']) && $b['valor'] === $nombre, 'no creó el banco');
            $creados['cat_bancos'][] = (int)$b['id'];
            afirmar(empty($enviar(['tipo' => 'proyecto', 'nombre' => 'x'])['success']), 'aceptó un tipo desconocido');
            afirmar(empty($enviar(['tipo' => 'banco', 'nombre' => '  '])['success']), 'aceptó un nombre vacío');
        } finally {
            foreach ($creados as $tabla => $ids) foreach ($ids as $id) $pdo->exec("DELETE FROM `$tabla` WHERE id = $id");
        }
    });

    prueba('Usuarios: se vincula el usuario a su ficha de empleado, y un empleado no queda en dos usuarios', function () use ($baseUrl, $sesAdmin, $pdo, $pdoMaster, $empresa) {
        $emp = (int)$pdo->query("SELECT id FROM empleados WHERE id NOT IN (SELECT empleado_id FROM usuarios WHERE empleado_id IS NOT NULL) ORDER BY id LIMIT 1")->fetchColumn();
        if (!$emp) throw new Omitir('todos los empleados tienen usuario');
        $enviar = function (array $datos) use ($baseUrl, $sesAdmin) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
                'header' => "Cookie: PROSCALA=$sesAdmin\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($datos + ['csrf_token' => 'tokenprueba', '_action' => 'guardar', 'rol' => 'empleado'])]]);
            return json_decode((string)@file_get_contents("$baseUrl/usuarios.php", false, $ctx), true) ?: [];
        };
        $sufijo = bin2hex(random_bytes(4));
        $emails = ["zz.vinculo.a.$sufijo@prueba.local", "zz.vinculo.b.$sufijo@prueba.local"];
        try {
            // Inactivos: no se envía el correo de activación
            $a = $enviar(['id' => 0, 'nombre' => 'ZZ Vínculo A', 'email' => $emails[0], 'empleado_id' => $emp]);
            afirmar(!empty($a['success']), 'no creó el usuario: ' . ($a['message'] ?? 'sin respuesta'));
            iguales($emp, (int)$pdo->query("SELECT empleado_id FROM usuarios WHERE id = " . (int)$a['id'])->fetchColumn(), 'el usuario no quedó vinculado al empleado');
            $b = $enviar(['id' => 0, 'nombre' => 'ZZ Vínculo B', 'email' => $emails[1], 'empleado_id' => $emp]);
            afirmar(empty($b['success']) && str_contains($b['message'] ?? '', 'ya está vinculado'), 'vinculó el mismo empleado a dos usuarios');
            $r = $enviar(['id' => $a['id'], 'nombre' => 'ZZ Vínculo A', 'email' => $emails[0], 'empleado_id' => '']);
            afirmar(!empty($r['success']) && $pdo->query("SELECT empleado_id FROM usuarios WHERE id = " . (int)$a['id'])->fetchColumn() === null, 'no se pudo desvincular');
            [$cod, $html] = pedir("$baseUrl/usuarios.php", $sesAdmin);
            afirmar($cod === 200 && str_contains($html, 'Empleado vinculado') && !erroresPhp($html), "Usuarios (HTTP $cod) no muestra el campo Empleado vinculado");
        } finally {
            foreach ($emails as $e) {
                $pdo->prepare("DELETE FROM usuarios WHERE email = ?")->execute([$e]);
                $pdoMaster->prepare("DELETE FROM accesos WHERE email = ?")->execute([$e]);
                $pdoMaster->prepare("DELETE FROM recuperaciones WHERE email = ?")->execute([$e]);
            }
            $pdo->exec("DELETE FROM auditoria WHERE modulo = 'usuarios.php' AND ip IN ('127.0.0.1', '::1') AND detalle LIKE '%zz.vinculo.%'");
        }
    });

    prueba('Envío repetido: el mismo envío en pocos segundos se rechaza sin ejecutarse; uno distinto o tras un error, no', function () use ($baseUrl, $sesAdmin, $pdo, $admin) {
        $enviar = function (array $datos) use ($baseUrl, $sesAdmin) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
                'header' => "Cookie: PROSCALA=$sesAdmin\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($datos + ['csrf_token' => 'tokenprueba'])]]);
            $cuerpo = (string)@file_get_contents("$baseUrl/alta_rapida.php", false, $ctx);
            preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $m);
            return ['http' => (int)($m[1] ?? 0)] + (json_decode($cuerpo, true) ?: []);
        };
        $a = 'ZZ Banco repetido ' . bin2hex(random_bytes(4));
        $b = "$a B";
        $inicio = $pdo->query("SELECT NOW()")->fetchColumn();
        try {
            $r = $enviar(['tipo' => 'banco', 'nombre' => $a]);
            afirmar(!empty($r['success']), 'no creó el banco: ' . ($r['message'] ?? 'sin respuesta'));
            $rep = $enviar(['tipo' => 'banco', 'nombre' => $a]);
            afirmar($rep['http'] === 409 && !empty($rep['duplicado']), "el envío repetido no se rechazó (HTTP {$rep['http']})");
            $s = $pdo->prepare("SELECT COUNT(*) FROM auditoria WHERE accion = 'DUPLICADO' AND usuario_id = ? AND modulo = 'alta_rapida.php' AND created_at >= ?");
            $s->execute([$admin, $inicio]);
            afirmar($s->fetchColumn() > 0, 'el rechazo no quedó en la bitácora');

            afirmar(!empty($enviar(['tipo' => 'banco', 'nombre' => $b])['success']), 'rechazó un envío distinto');
            // Un envío que falla se libera: repetirlo vuelve a ejecutarse (y responde su propio error)
            $e1 = $enviar(['tipo' => 'banco', 'nombre' => '  ']);
            $e2 = $enviar(['tipo' => 'banco', 'nombre' => '  ']);
            afirmar(empty($e1['success']) && empty($e2['duplicado']) && ($e2['message'] ?? '') === ($e1['message'] ?? ''), 'el envío fallido quedó bloqueado');
            // Solo se compara con el último: A → B → A no es un duplicado
            afirmar(!empty($enviar(['tipo' => 'banco', 'nombre' => $a])['success']), 'A → otro → A se tomó como duplicado');
        } finally {
            $s = $pdo->prepare("DELETE FROM cat_bancos WHERE nombre IN (?, ?)");
            $s->execute([$a, $b]);
            $s = $pdo->prepare("DELETE FROM auditoria WHERE usuario_id = ? AND modulo = 'alta_rapida.php' AND created_at >= ?");
            $s->execute([$admin, $inicio]);
            $pdo->prepare("DELETE FROM envios_recientes WHERE usuario_id = ? AND modulo = 'alta_rapida.php'")->execute([$admin]);
        }
    });

    prueba('Anticipo a un proveedor o consultor: se guarda sin empleado, su liquidación lo hereda y la orden de pago es para el proveedor', function () use ($baseUrl, $sesAdmin, $pdo) {
        $enviar = function (string $modulo, array $datos) use ($baseUrl, $sesAdmin) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
                'header' => "Cookie: PROSCALA=$sesAdmin\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($datos + ['csrf_token' => 'tokenprueba', '_action' => 'guardar', 'id' => 0])]]);
            return json_decode((string)@file_get_contents("$baseUrl/$modulo", false, $ctx), true) ?: [];
        };
        $prov = $pdo->query("SELECT id, nombre FROM proveedores WHERE activo = 1 ORDER BY id LIMIT 1")->fetch();
        if (!$prov) throw new Omitir('no hay proveedores');
        $emp = (int)$pdo->query("SELECT id FROM empleados WHERE activo = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $gvId = $sgId = 0;
        try {
            $sinElegir = $enviar('gastos_viajes.php', ['tipo' => 'viaje', 'beneficiario_tipo' => 'proveedor', 'proveedor_id' => '', 'empleado_id' => $emp,
                'destino' => 'ZZ prueba', 'fecha_salida' => '2039-02-01', 'fecha_regreso' => '2039-02-03', 'items' => '[]']);
            afirmar(empty($sinElegir['success']) && str_contains($sinElegir['message'] ?? '', 'proveedor'), 'aceptó un anticipo a proveedor sin elegirlo');
            $r = $enviar('gastos_viajes.php', ['tipo' => 'viaje', 'beneficiario_tipo' => 'proveedor', 'proveedor_id' => $prov['id'], 'empleado_id' => $emp,
                'destino' => 'ZZ prueba consultor', 'fecha_salida' => '2039-02-01', 'fecha_regreso' => '2039-02-03', 'monto_alimentacion' => 0,
                'items' => json_encode([['descripcion' => 'Hotel', 'cuenta_id' => '', 'monto' => 500]])]);
            $gvId = (int)($r['id'] ?? 0);
            afirmar(!empty($r['success']) && $gvId, 'no se guardó el anticipo: ' . ($r['message'] ?? 'sin respuesta'));
            $gv = $pdo->query("SELECT empleado_id, proveedor_id FROM gastos_viaje WHERE id = $gvId")->fetch();
            iguales([null, (int)$prov['id']], [$gv['empleado_id'], (int)$gv['proveedor_id']], 'anticipo a nombre del proveedor y sin empleado');
            $op = datosOrdenPago($pdo, 'gastos_viaje', $gvId);
            iguales(['proveedor', (int)$prov['id'], $prov['nombre']], [$op['beneficiario_tipo'], $op['proveedor_id'], $op['beneficiario']], 'la orden de pago del anticipo es para el proveedor');
            foreach (["gastos_viajes.php?action=ver&id=$gvId" => 'Proveedor / consultor', 'gastos_viajes.php' => $prov['nombre'], "print.php?tipo=gv&id=$gvId" => 'Proveedor / Consultor',
                      "gastos_viajes.php?action=editar&id=$gvId" => 'beneficiario_tipo', 'gastos_viaje_auxiliar.php' => $prov['nombre']] as $url => $texto) {
                [$cod, $html] = pedir("$baseUrl/$url", $sesAdmin);
                afirmar($cod === 200 && str_contains($html, htmlspecialchars($texto)) && !erroresPhp($html), "$url (HTTP $cod) no muestra «{$texto}»");
            }
            // La liquidación del anticipo es del mismo proveedor, aunque el formulario diga otra cosa
            $r = $enviar('gastos_solicitud.php', ['fecha' => '2039-02-04', 'beneficiario_tipo' => 'empleado', 'empleado_id' => $emp, 'descripcion' => 'ZZ liquidación consultor',
                'viaje_id' => $gvId, 'items' => json_encode([['fecha' => '2039-02-02', 'descripcion' => 'Hotel', 'cuenta_id' => null, 'monto' => 650]])]);
            $sgId = (int)($r['id'] ?? 0);
            afirmar(!empty($r['success']) && $sgId, 'no se guardó la liquidación: ' . ($r['message'] ?? 'sin respuesta'));
            $sg = $pdo->query("SELECT empleado_id, proveedor_id FROM solicitud_gastos WHERE id = $sgId")->fetch();
            iguales([null, (int)$prov['id']], [$sg['empleado_id'], (int)$sg['proveedor_id']], 'la liquidación hereda el proveedor del anticipo');
            $op = datosOrdenPago($pdo, 'solicitud_gastos', $sgId);
            iguales(['proveedor', 150.0], [$op['beneficiario_tipo'], round((float)$op['monto'], 2)], 'el reembolso (lo gastado sobre el anticipo) es para el proveedor');
            foreach (["gastos_solicitud.php?action=ver&id=$sgId", "print.php?tipo=sg&id=$sgId", 'gastos_solicitud.php', 'dashboard.php'] as $url) {
                [$cod, $html] = pedir("$baseUrl/$url", $sesAdmin);
                afirmar($cod === 200 && str_contains($html, htmlspecialchars($prov['nombre'])) && !erroresPhp($html), "$url (HTTP $cod) no muestra al proveedor");
            }
        } finally {
            foreach (['solicitud_gastos' => $sgId, 'gastos_viaje' => $gvId] as $tabla => $id) {
                if (!$id) continue;
                $proc = (int)$pdo->query("SELECT proceso_id FROM `$tabla` WHERE id = $id")->fetchColumn();
                $pdo->exec($tabla === 'gastos_viaje' ? "DELETE FROM gastos_viaje_detalle WHERE viaje_id = $id" : "DELETE FROM solicitud_gastos_detalle WHERE solicitud_id = $id");
                $pdo->exec("DELETE FROM `$tabla` WHERE id = $id");
                foreach (['registro_usuarios', 'notificaciones', 'aprobaciones', 'recordatorios'] as $t) $pdo->exec("DELETE FROM $t WHERE tabla = '$tabla' AND registro_id = $id");
                if ($proc && $tabla === 'gastos_viaje') $pdo->exec("DELETE FROM procesos_viaticos WHERE id = $proc");
            }
            $pdo->exec("DELETE FROM auditoria WHERE ip IN ('127.0.0.1', '::1') AND (detalle LIKE 'GV creado%' OR detalle LIKE 'Nueva solicitud de gastos%') AND created_at >= NOW() - INTERVAL 10 MINUTE");
        }
    });

    prueba('Consultor: Proveedores le da usuario y puesto Consultor; entra, pide su anticipo a su nombre y solo ve lo suyo', function () use ($baseUrl, $sesAdmin, $pdo, $pdoMaster, $empresa) {
        if (!isset(roles(true)[ROL_CONSULTOR])) throw new Omitir('la base no tiene el puesto Consultor (actualizar_bd.php)');
        $enviar = function (string $modulo, array $datos, string $ses) use ($baseUrl) {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
                'header' => "Cookie: PROSCALA=$ses\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($datos + ['csrf_token' => 'tokenprueba', '_action' => 'guardar', 'id' => 0])]]);
            return json_decode((string)@file_get_contents("$baseUrl/$modulo", false, $ctx), true) ?: [];
        };
        $sufijo = bin2hex(random_bytes(4));
        $email = "zz.consultor.$sufijo@prueba.local";
        $provId = $gvId = $uid = 0;
        $ajeno = (int)$pdo->query("SELECT id FROM gastos_viaje WHERE proveedor_id IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
        try {
            // Proyectos: se le asigna el primero; el segundo no lo puede usar
            $proys = $pdo->query("SELECT id, nombre FROM proyectos WHERE estado = 'activo' ORDER BY id LIMIT 2")->fetchAll();
            $datosProv = ['nombre' => "ZZ Consultor $sufijo", 'email' => $email, 'categoria' => 'consultor', 'activo' => 1, 'identidad' => '0801199012345', 'acceso_sistema' => 1,
                          'proyectos' => $proys ? [$proys[0]['id']] : []];
            $r = $enviar('proveedores.php', $datosProv + ['clave_inicial' => 'corta'], $sesAdmin);
            afirmar(empty($r['success']) && !$pdo->query("SELECT 1 FROM proveedores WHERE email = '$email'")->fetchColumn(), 'aceptó una contraseña inicial corta (o guardó el proveedor igual)');
            $r = $enviar('proveedores.php', $datosProv + ['clave_inicial' => 'Consultor2039'], $sesAdmin);
            $provId = (int)($r['id'] ?? 0);
            afirmar(!empty($r['success']) && $provId, 'no se creó el consultor: ' . ($r['message'] ?? 'sin respuesta'));
            $u = $pdo->query("SELECT * FROM usuarios WHERE proveedor_id = $provId")->fetch();
            $uid = (int)($u['id'] ?? 0);
            afirmar($u && $u['rol'] === ROL_CONSULTOR && $u['email'] === $email && $u['activo'] && $u['debe_cambiar_password'] && password_verify('Consultor2039', $u['password']),
                'el usuario del consultor no quedó con su correo, el puesto Consultor y la contraseña inicial por cambiar');
            afirmar((bool)$pdoMaster->query("SELECT 1 FROM accesos WHERE email = '$email' AND empresa_id = {$empresa['id']}")->fetchColumn(), 'sin acceso en la base central');
            [$cod, $html] = pedir("$baseUrl/proveedores.php", $sesAdmin);
            afirmar(str_contains($html, 'Consultores') && str_contains($html, 'Con acceso') && !erroresPhp($html), 'la lista de proveedores no muestra el consultor con acceso');

            // Entra como el consultor (ya cambió su contraseña)
            $pdo->exec("UPDATE usuarios SET debe_cambiar_password = 0 WHERE id = $uid");
            $ses = sesionDePrueba($pdo, $empresa, $uid);
            [$cod, $html] = pedir("$baseUrl/gastos_viajes.php?action=nuevo", $ses);
            afirmar($cod === 200 && str_contains($html, "ZZ Consultor $sufijo") && !str_contains($html, '-- Seleccione el empleado --</option><option') && !erroresPhp($html), "el formulario del consultor (HTTP $cod) no lo fija a su nombre");
            $anticipo = ['tipo' => 'viaje', 'beneficiario_tipo' => 'empleado', 'empleado_id' => 1, 'destino' => 'ZZ viaje del consultor',
                'fecha_salida' => '2039-03-01', 'fecha_regreso' => '2039-03-02', 'monto_alimentacion' => 0, 'items' => json_encode([['descripcion' => 'Bus', 'cuenta_id' => '', 'monto' => 300]])];
            if ($proys) {
                iguales([(int)$proys[0]['id']], array_map('intval', $pdo->query("SELECT proyecto_id FROM proveedor_proyectos WHERE proveedor_id = $provId")->fetchAll(PDO::FETCH_COLUMN)),
                    'Proveedores no guardó los proyectos del consultor');
                $opcion = fn($p) => '>' . htmlspecialchars($p['nombre']) . '</option>';
                afirmar(str_contains($html, $opcion($proys[0])) && !str_contains($html, '-- General --'), 'el formulario no le ofrece solo su proyecto (o le ofrece General)');
                afirmar(str_contains(pedir("$baseUrl/gastos_solicitud.php?action=nuevo", $ses)[1], $opcion($proys[0])), 'la liquidación no le ofrece su proyecto');
                if (isset($proys[1])) {
                    afirmar(!str_contains($html, $opcion($proys[1])), 'el formulario le ofrece un proyecto que no tiene asignado');
                    $r = $enviar('gastos_viajes.php', $anticipo + ['proyecto_id' => $proys[1]['id']], $ses);
                    afirmar(empty($r['success']) && str_contains($r['message'] ?? '', 'no está asignado'), 'aceptó un proyecto que no tiene asignado');
                }
                $r = $enviar('gastos_viajes.php', $anticipo + ['proyecto_id' => ''], $ses);
                afirmar(empty($r['success']) && str_contains($r['message'] ?? '', 'Seleccione el proyecto'), 'aceptó el anticipo sin proyecto teniendo proyectos asignados');
                $anticipo['proyecto_id'] = $proys[0]['id'];
            }
            $r = $enviar('gastos_viajes.php', $anticipo, $ses);
            $gvId = (int)($r['id'] ?? 0);
            afirmar(!empty($r['success']) && $gvId, 'el consultor no pudo pedir su anticipo: ' . ($r['message'] ?? 'sin respuesta'));
            iguales([null, $provId], array_map(fn($v) => $v === null ? null : (int)$v, array_values($pdo->query("SELECT empleado_id, proveedor_id FROM gastos_viaje WHERE id = $gvId")->fetch())),
                'el anticipo queda a nombre del consultor aunque el formulario diga otra cosa');
            [$cod, $html] = pedir("$baseUrl/gastos_viajes.php", $ses);
            afirmar($cod === 200 && str_contains($html, 'ZZ viaje del consultor'), 'el consultor no ve su anticipo');
            if ($ajeno) {
                $num = (string)$pdo->query("SELECT numero FROM gastos_viaje WHERE id = $ajeno")->fetchColumn();
                afirmar($num === '' || !str_contains($html, ">$num<"), 'el consultor ve anticipos ajenos');
                [$cod] = pedir("$baseUrl/gastos_viajes.php?action=ver&id=$ajeno", $ses);
                afirmar($cod !== 200, "el consultor abrió un anticipo ajeno (HTTP $cod)");
            }
            [$cod, $html] = pedir("$baseUrl/mis_solicitudes.php", $ses);
            afirmar($cod === 200 && str_contains($html, 'ZZ viaje del consultor') && !erroresPhp($html), 'Mis solicitudes del consultor no muestra su anticipo');
            [$cod] = pedir("$baseUrl/proveedores.php", $ses);
            afirmar($cod !== 200, 'el consultor abrió Proveedores');
            [$cod] = pedir("$baseUrl/gastos_solicitud.php?action=nuevo", $ses);
            iguales(200, $cod, 'el consultor abre Liquidaciones / Reembolsos');

            // Quitarle el acceso desactiva su usuario
            $r = $enviar('proveedores.php', ['id' => $provId, 'acceso_sistema' => null] + $datosProv, $sesAdmin);
            afirmar(!empty($r['success']), 'no se guardó al quitar el acceso');
            iguales(0, (int)$pdo->query("SELECT activo FROM usuarios WHERE id = $uid")->fetchColumn(), 'quitar el acceso desactiva el usuario');
        } finally {
            if ($gvId) {
                $proc = (int)$pdo->query("SELECT proceso_id FROM gastos_viaje WHERE id = $gvId")->fetchColumn();
                $pdo->exec("DELETE FROM gastos_viaje_detalle WHERE viaje_id = $gvId");
                $pdo->exec("DELETE FROM gastos_viaje WHERE id = $gvId");
                foreach (['registro_usuarios', 'notificaciones', 'aprobaciones', 'recordatorios'] as $t) $pdo->exec("DELETE FROM $t WHERE tabla = 'gastos_viaje' AND registro_id = $gvId");
                if ($proc) $pdo->exec("DELETE FROM procesos_viaticos WHERE id = $proc");
            }
            if ($uid) { $pdo->exec("DELETE FROM notificaciones WHERE usuario_id = $uid"); $pdo->exec("DELETE FROM auditoria WHERE usuario_id = $uid"); $pdo->exec("DELETE FROM usuarios WHERE id = $uid"); }
            $pdoMaster->exec("DELETE FROM accesos WHERE email = '$email'");
            $pdoMaster->exec("DELETE FROM recuperaciones WHERE email = '$email'");
            if ($provId) $pdo->exec("DELETE FROM proveedores WHERE id = $provId");
            $pdo->exec("DELETE FROM auditoria WHERE ip IN ('127.0.0.1', '::1') AND (detalle LIKE '%ZZ Consultor $sufijo%' OR detalle LIKE '%$email%' OR detalle LIKE 'GV creado%') AND created_at >= NOW() - INTERVAL 10 MINUTE");
        }
    });

    prueba('Prueba de correo: solo el super administrador la abre y envía', function () use ($pdo, $pdoMaster, $empresa, $baseUrl, $admin, $sesAdmin) {
        $s = $pdoMaster->prepare("SELECT email FROM accesos WHERE empresa_id = ? AND superadmin = 1");
        $s->execute([$empresa['id']]);
        $supers = array_map('strtolower', $s->fetchAll(PDO::FETCH_COLUMN));
        $sesSuper = '';
        foreach ($pdo->query("SELECT id, email FROM usuarios WHERE activo = 1 AND debe_cambiar_password = 0")->fetchAll() as $u) {
            if (in_array(strtolower($u['email']), $supers, true)) { $sesSuper = sesionDePrueba($pdo, $empresa, (int)$u['id']); break; }
        }
        if (!$sesSuper) throw new Omitir('la empresa de prueba no tiene un super administrador activo');
        [$cod, $html] = pedir("$baseUrl/prueba_correo.php", $sesSuper);
        afirmar($cod === 200 && str_contains($html, 'Configuración del correo saliente') && !erroresPhp($html), "prueba_correo.php (HTTP $cod) " . erroresPhp($html));
        afirmar(!str_contains($html, (string)(credenciales()['correo']['clave'] ?? '') ?: "\0"), 'la página muestra la contraseña del correo');

        $adminEsSuper = in_array(strtolower((string)$pdo->query("SELECT email FROM usuarios WHERE id = $admin")->fetchColumn()), $supers, true);
        if (!$adminEsSuper) {
            [$cod] = pedir("$baseUrl/prueba_correo.php", $sesAdmin);
            iguales(302, $cod, 'un administrador que no es super administrador no la abre');
        }
        // Se envía solo si el servidor local guarda los correos en archivo (no se mandan correos reales)
        if ((credenciales()['correo']['metodo'] ?? '') !== 'archivo') return;
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'timeout' => 60,
            'header' => "Cookie: PROSCALA=$sesSuper\r\nX-Requested-With: XMLHttpRequest\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['csrf_token' => 'tokenprueba', '_action' => 'enviar', 'para' => 'zz.prueba@prueba.local', 'asunto' => 'ZZ prueba'])]]);
        $r = json_decode((string)@file_get_contents("$baseUrl/prueba_correo.php", false, $ctx), true) ?: [];
        afirmar(!empty($r['success']) && str_contains(implode("\n", $r['traza'] ?? []), 'storage'), 'no se guardó el correo de prueba: ' . ($r['message'] ?? 'sin respuesta'));
        foreach (glob(RAIZ . '/storage/correos/*zz_prueba_prueba_local*.html') as $f) @unlink($f);
    });

    prueba('Página principal, inicio de sesión y recuperar contraseña (sin sesión)', function () use ($baseUrl) {
        foreach (['' => 'Iniciar sesión', 'login.php' => 'RTN de la empresa', 'recuperar.php' => 'Enviar enlace'] as $p => $texto) {
            [$cod, $html] = pedir("$baseUrl/$p");
            afirmar($cod === 200 && str_contains($html, $texto) && !erroresPhp($html), "$p (HTTP $cod)");
        }
        [$cod] = pedir("$baseUrl/dashboard.php");
        iguales(302, $cod, 'un módulo sin sesión redirige al inicio de sesión');
        // Ícono de las pestañas (includes/favicon.php) y sus archivos
        [, $html] = pedir("$baseUrl/login.php");
        afirmar(str_contains($html, 'favicon.ico') && str_contains($html, 'apple-touch-icon.png'), 'el inicio de sesión no declara el ícono de la pestaña');
        [$cod, $ico] = pedir("$baseUrl/favicon.ico");
        afirmar($cod === 200 && str_starts_with($ico, "\x00\x00\x01\x00"), "favicon.ico (HTTP $cod)");
        foreach (['favicon-32.png', 'favicon-192.png', 'apple-touch-icon.png'] as $f) {
            [$cod, $png] = pedir("$baseUrl/assets/images/$f");
            afirmar($cod === 200 && str_starts_with($png, "\x89PNG"), "$f (HTTP $cod)");
        }
        [$cod] = pedir("$baseUrl/templates/print/sc.php");
        iguales(403, $cod, 'las carpetas internas no se sirven');
    });
} finally {
    foreach ($GLOBALS['_sesiones_prueba'] ?? [] as $id) @unlink(RAIZ . "/storage/sesiones/sess_$id");
    // Las visitas de prueba no quedan en la bitácora
    $pdo->prepare("DELETE FROM auditoria WHERE ip IN ('127.0.0.1', '::1') AND created_at >= ? AND (accion IN ('IMPRIMIR', 'ACCESO_DENEGADO') OR modulo = 'alta_rapida.php')")->execute([$inicioHttp]);
}

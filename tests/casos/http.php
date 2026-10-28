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

    prueba('Documentos impresos: los 26 tipos con datos reales', function () use ($baseUrl, $sesAdmin, $pdo) {
        $uno = fn(string $sql) => $pdo->query($sql)->fetchColumn() ?: null;
        $emp = $uno("SELECT id FROM empleados ORDER BY id LIMIT 1");
        $tipos = ['sc' => 'solicitud_compra', 'oc' => 'ordenes_compra', 'op' => 'ordenes_pago', 'pl' => 'planilla_periodos', 'plp' => 'planilla_periodos',
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

    prueba('Página principal, inicio de sesión y recuperar contraseña (sin sesión)', function () use ($baseUrl) {
        foreach (['' => 'Iniciar sesión', 'login.php' => 'RTN de la empresa', 'recuperar.php' => 'Enviar enlace'] as $p => $texto) {
            [$cod, $html] = pedir("$baseUrl/$p");
            afirmar($cod === 200 && str_contains($html, $texto) && !erroresPhp($html), "$p (HTTP $cod)");
        }
        [$cod] = pedir("$baseUrl/dashboard.php");
        iguales(302, $cod, 'un módulo sin sesión redirige al inicio de sesión');
        [$cod] = pedir("$baseUrl/templates/print/sc.php");
        iguales(403, $cod, 'las carpetas internas no se sirven');
    });
} finally {
    foreach ($GLOBALS['_sesiones_prueba'] ?? [] as $id) @unlink(RAIZ . "/storage/sesiones/sess_$id");
    // Las visitas de prueba no quedan en la bitácora
    $pdo->prepare("DELETE FROM auditoria WHERE ip IN ('127.0.0.1', '::1') AND created_at >= ? AND accion IN ('IMPRIMIR', 'ACCESO_DENEGADO')")->execute([$inicioHttp]);
}

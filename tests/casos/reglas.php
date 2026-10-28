<?php
// Reglas del sistema con datos de prueba dentro de transacciones que se revierten:
// jerarquía, delegación, notificaciones, recordatorios, orden de pago atómica, bloqueo por IP,
// respaldo semanal y Excel.

/**
 * Escenario: puestos zz_jefe (aprueba, depende del Administrador), zz_asistente (depende de
 * zz_jefe) y zz_par (aprueba, sin relación con el asistente); un usuario por puesto y un permiso
 * creado por el asistente. Corre $fn dentro de una transacción que siempre se revierte.
 */
function conEscenario(PDO $pdo, callable $fn): void {
    $pdo->beginTransaction();
    try {
        $emp = (int)$pdo->query("SELECT id FROM empleados ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->exec("INSERT INTO roles (clave, nombre, depende_de, aprueba, solo_propios, orden) VALUES
            ('zz_jefe', 'ZZ Jefe', 'admin', 1, 0, 90), ('zz_asistente', 'ZZ Asistente', 'zz_jefe', 0, 0, 91), ('zz_par', 'ZZ Par', 'admin', 1, 0, 92)");
        $pdo->exec("INSERT INTO rol_modulos (rol, modulo) VALUES ('zz_jefe', 'permisos.php'), ('zz_asistente', 'permisos.php')");
        $u = [];
        foreach (['jefe', 'asistente', 'par'] as $p) {
            $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol, activo) VALUES (?,?,?,?,1)")
                ->execute(["ZZ " . ucfirst($p), "zz.$p." . bin2hex(random_bytes(3)) . '@prueba.local', 'x', "zz_$p"]);
            $u[$p] = (int)$pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO permisos (numero, empleado_id, fecha_solicitud, fecha_inicio, fecha_fin, dias, tipo, motivo, estado)
                       VALUES ('ZZPRUEBA-PER', ?, CURDATE(), CURDATE(), CURDATE(), 1, 'personal', 'Prueba automática', 'pendiente')")->execute([$emp]);
        $per = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO registro_usuarios (tabla, registro_id, usuario_id, participacion) VALUES ('permisos', ?, ?, 'creador')")->execute([$per, $u['asistente']]);
        roles(true);
        $fn($u, $per);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        roles(true);
    }
}
$accion = fn(PDO $pdo, int $per, string $paso) => accionesFlujo(estadoFlujo($pdo, 'permisos.php', $per), 'permisos.php')[$paso];

prueba('Jerarquía: solo un superior de quien creó el proceso puede revisarlo', function () use ($pdo, $accion) {
    conEscenario($pdo, function ($u, $per) use ($pdo, $accion) {
        comoUsuario($pdo, $u['jefe']);      iguales(true, $accion($pdo, $per, 'revisar'), 'el jefe (superior) revisa');
        comoUsuario($pdo, $u['asistente']); afirmar($accion($pdo, $per, 'revisar') !== true, 'quien lo creó no puede revisarlo');
        comoUsuario($pdo, $u['par']);       afirmar(str_contains((string)$accion($pdo, $per, 'revisar'), 'organigrama'), 'un puesto que no es superior no puede revisarlo');
    });
});

prueba('Quien revisó no puede autorizar el mismo proceso', function () use ($pdo, $accion) {
    conEscenario($pdo, function ($u, $per) use ($pdo, $accion) {
        comoUsuario($pdo, $u['jefe']);
        registrarPasoFlujo($pdo, 'permisos.php', $per, 'revisado');
        afirmar(str_contains((string)$accion($pdo, $per, 'autorizar'), 'Quien revisó'), 'el revisor no debe poder autorizar');
    });
});

prueba('Delegación: el delegado aprueba "en nombre de" quien delegó, con sus límites', function () use ($pdo, $accion) {
    conEscenario($pdo, function ($u, $per) use ($pdo, $accion) {
        $pdo->prepare("INSERT INTO delegaciones (usuario_id, delegado_id, desde, hasta) VALUES (?,?,CURDATE(),CURDATE())")->execute([$u['jefe'], $u['par']]);
        comoUsuario($pdo, $u['par']);
        afirmar(puedeAcceder('permisos.php'), 'la delegación da acceso al módulo de quien delegó');
        iguales(true, $accion($pdo, $per, 'revisar'), 'el delegado revisa como el jefe');
        registrarPasoFlujo($pdo, 'permisos.php', $per, 'revisado');
        $c = $pdo->query("SELECT comentario FROM aprobaciones WHERE tabla = 'permisos' AND registro_id = $per AND paso = 'revisado'")->fetchColumn();
        iguales('En nombre de ZZ Jefe', $c, 'anotación del paso');
        // Quien delegó revisó → el delegado no puede autorizar (sería la misma persona)
        $pdo->exec("UPDATE aprobaciones SET usuario_id = {$u['jefe']}, comentario = NULL WHERE tabla = 'permisos' AND registro_id = $per");
        afirmar(str_contains((string)$accion($pdo, $per, 'autorizar'), 'Lo revisó quien le delegó'), 'no debe autorizar lo que revisó quien delegó');
        // Las notificaciones de lo que le toca al jefe también llegan al delegado
        $dest = aprobadoresProceso($pdo, 'permisos.php', $per, [$u['asistente']]);
        afirmar(in_array($u['jefe'], $dest, true) && in_array($u['par'], $dest, true), 'avisos al jefe y a su delegado: ' . json_encode($dest));
    });
});

prueba('Delegación vencida o cancelada no da permisos', function () use ($pdo, $accion) {
    conEscenario($pdo, function ($u, $per) use ($pdo, $accion) {
        $pdo->prepare("INSERT INTO delegaciones (usuario_id, delegado_id, desde, hasta) VALUES (?,?,CURDATE() - INTERVAL 5 DAY, CURDATE() - INTERVAL 1 DAY)")->execute([$u['jefe'], $u['par']]);
        $pdo->prepare("INSERT INTO delegaciones (usuario_id, delegado_id, desde, hasta, cancelada) VALUES (?,?,CURDATE(),CURDATE(),1)")->execute([$u['jefe'], $u['par']]);
        comoUsuario($pdo, $u['par']);
        afirmar($accion($pdo, $per, 'revisar') !== true, 'una delegación vencida o cancelada no debe permitir revisar');
    });
});

prueba('Notificaciones: niveles de aprobadores según el organigrama', function () use ($pdo) {
    conEscenario($pdo, function ($u, $per) use ($pdo) {
        $niveles = nivelesAprobadores($pdo, 'permisos.php', $per, [$u['asistente']]);
        iguales([$u['jefe']], $niveles[0] ?? null, 'nivel 1 (superior inmediato)');
        afirmar(isset($niveles[1]) && !in_array($u['jefe'], $niveles[1], true), 'el último nivel son los Administradores');
    });
});

prueba('Recordatorio a los 2 días y escalamiento al siguiente nivel a los 5', function () use ($pdo) {
    conEscenario($pdo, function ($u, $per) use ($pdo) {
        $pdo->exec("UPDATE configuracion SET valor = '2' WHERE clave = 'recordatorio_dias'");
        $pdo->exec("UPDATE configuracion SET valor = '5' WHERE clave = 'escalamiento_dias'");
        $buscar = fn() => array_values(array_filter(recordarPendientes($pdo, false), fn($a) => str_contains($a['proceso'], 'ZZPRUEBA-PER')));
        $edad = fn(int $d) => $pdo->exec("UPDATE registro_usuarios SET created_at = NOW() - INTERVAL $d DAY - INTERVAL 1 HOUR WHERE tabla = 'permisos' AND registro_id = $per");
        $edad(1); iguales([], $buscar(), 'un día: nada');
        $edad(3); $a = $buscar(); iguales(0, $a[0]['nivel'] ?? null, 'tres días: recordatorio'); afirmar(in_array($u['jefe'], $a[0]['usuarios'], true), 'el recordatorio va al jefe');
        $edad(6); $a = $buscar(); iguales(1, $a[0]['nivel'] ?? null, 'seis días: escalado al nivel siguiente'); afirmar(!in_array($u['jefe'], $a[0]['usuarios'], true), 'el escalamiento va por encima del jefe');
        // Ya enviado: no se repite
        $pdo->prepare("INSERT INTO recordatorios (tabla, registro_id, paso, inicio, nivel) VALUES ('permisos', ?, 'revisar', ?, 1)")
            ->execute([$per, date('Y-m-d H:i:s', strtotime(estadoFlujo($pdo, 'permisos.php', $per)['creado']['fecha']))]);
        iguales([], $buscar(), 'un aviso ya enviado no se repite');
    });
});

prueba('Orden de pago atómica: si no se genera la OP, la autorización se revierte', function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $gv = $pdo->query("SELECT id FROM gastos_viaje ORDER BY id DESC LIMIT 1")->fetchColumn();
        if (!$gv) throw new Omitir('no hay anticipos para probar');
        $pdo->exec("UPDATE gastos_viaje SET estado = 'pendiente' WHERE id = $gv");
        $pdo->exec("DELETE FROM aprobaciones WHERE tabla = 'gastos_viaje' AND registro_id = $gv");
        $_POST = ['_action' => 'cambiar_estado', 'estado' => 'aprobada', 'id' => $gv];
        ob_start();                                       // capa propia de la prueba
        prepararOrdenPago($pdo, 'gastos_viajes.php');     // guarda el estado y retiene la respuesta
        echo '{"success":true,"message":"Estado actualizado."}';   // lo que respondería el módulo
        $pdo->exec("UPDATE gastos_viaje SET estado = 'aprobada' WHERE id = $gv");
        $pdo->exec("INSERT INTO aprobaciones (tabla, registro_id, paso, usuario_id) VALUES ('gastos_viaje', $gv, 'autorizado', 1)");
        revertirSinOrdenPago($pdo, 'gastos_viajes.php', (int)$gv, new RuntimeException('prueba'));
        $respuesta = json_decode((string)ob_get_clean(), true);
        iguales('pendiente', $pdo->query("SELECT estado FROM gastos_viaje WHERE id = $gv")->fetchColumn(), 'estado tras revertir');
        iguales(0, (int)$pdo->query("SELECT COUNT(*) FROM aprobaciones WHERE tabla = 'gastos_viaje' AND registro_id = $gv AND paso = 'autorizado'")->fetchColumn(), 'paso autorizado');
        iguales(false, $respuesta['success'] ?? null, 'la respuesta pasa a ser un error');
        afirmar(str_contains($respuesta['message'] ?? '', 'NO se autorizó'), 'el mensaje explica que no se autorizó');
    } finally {
        while (ob_get_level() > 1) ob_end_clean();
        $_POST = [];
        unset($GLOBALS['_op_previo']);
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(200);
    }
});

prueba('Bloqueo por IP: 5 fallos en 15 minutos bloquean 15 minutos', function () use ($pdoMaster) {
    $pdoMaster->beginTransaction();
    try {
        $ip = 'prueba-' . bin2hex(random_bytes(4));
        $fallo = fn(int $minutos) => $pdoMaster->prepare("INSERT INTO intentos_login (ip, created_at) VALUES (?, NOW() - INTERVAL $minutos MINUTE)")->execute([$ip]);
        foreach ([20, 18, 16, 3] as $m) $fallo($m);
        iguales(0, minutosBloqueoIp($pdoMaster, $ip), '4 fallos recientes: sin bloqueo');
        $fallo(2);
        iguales(0, minutosBloqueoIp($pdoMaster, $ip), '5 fallos repartidos en más de 15 minutos: sin bloqueo');
        foreach ([1, 1, 0] as $m) $fallo($m);
        afirmar(minutosBloqueoIp($pdoMaster, $ip) >= 14, '5 fallos en menos de 15 minutos: bloqueada unos 15 minutos');
        limpiarFallosIp($pdoMaster, $ip);
        iguales(0, minutosBloqueoIp($pdoMaster, $ip), 'tras un ingreso correcto se limpia');
    } finally { $pdoMaster->rollBack(); }
});

prueba('Respaldo semanal: uno cada 7 días', function () use ($pdoMaster) {
    $pdoMaster->beginTransaction();
    try {
        $pdoMaster->exec("DELETE FROM respaldos_descargas");
        iguales(true, respaldoSemanalPendiente($pdoMaster), 'sin respaldos: pendiente');
        $pdoMaster->exec("INSERT INTO respaldos_descargas (email, created_at) VALUES ('prueba', NOW() - INTERVAL 3 DAY)");
        iguales(false, respaldoSemanalPendiente($pdoMaster), 'hace 3 días: no');
        $pdoMaster->exec("UPDATE respaldos_descargas SET created_at = NOW() - INTERVAL 8 DAY");
        iguales(true, respaldoSemanalPendiente($pdoMaster), 'hace 8 días: pendiente');
    } finally { $pdoMaster->rollBack(); }
});

prueba('Excel: números, textos y un .xlsx válido', function () {
    foreach (['L. 1,234.56' => 1234.56, '(250.00)' => -250.0, '15' => 15.0, '-3.5' => -3.5] as $t => $n) iguales($n, numeroExcel($t), "número de \"$t\"");
    foreach (['0801199012345', '20/10/2026', 'OC26-0012', '9999-0000', 'Texto'] as $t) iguales(null, numeroExcel($t), "\"$t\" debe quedar como texto");
    $tmp = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($tmp, generarXlsx('Prueba <&>', ['Número', 'Monto'], [['A&B <c>', 'L. 10.00']]));
    $z = new ZipArchive();
    afirmar($z->open($tmp) === true, 'no es un zip válido');
    foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/styles.xml', 'xl/worksheets/sheet1.xml'] as $f) {
        afirmar(@simplexml_load_string((string)$z->getFromName($f)) !== false, "$f no es XML válido");
    }
    $z->close();
    @unlink($tmp);
});

prueba('Búsqueda: catálogo de procesos coherente con los módulos del sistema', function () {
    // buscar.php no se incluye (responde y termina): se lee su catálogo
    preg_match_all("/'(\w+\.php)'\s*=>\s*\['(\w+)'/", (string)file_get_contents(RAIZ . '/buscar.php'), $m, PREG_SET_ORDER);
    afirmar(count($m) >= 15, 'catálogo de búsqueda incompleto');
    $todos = []; foreach (MODULOS as $items) $todos += $items;
    foreach ($m as [, $modulo]) afirmar(isset($todos[$modulo]), "$modulo no es un módulo del menú");
});

require_once RAIZ . '/config/catalogo_cuentas.php';
prueba('Catálogo de cuentas en Excel: la plantilla con el catálogo actual se vuelve a importar sin errores', function () use ($pdo) {
    $tmp = tempnam(sys_get_temp_dir(), 'x');
    try {
        file_put_contents($tmp, plantillaCatalogo($pdo));
        $filas = leerXlsx($tmp);
        iguales(CATALOGO_COLUMNAS, array_values($filas[1]), 'encabezados de la plantilla');
        $v = validarCatalogo($pdo, $filas, false);
        iguales([], array_slice($v['errores'], 0, 3), 'errores al releer el catálogo actual');
        iguales(0, $v['resumen']['nuevas'] ?? -1, 'cuentas nuevas al releer el catálogo actual');
        iguales((int)$pdo->query("SELECT COUNT(*) FROM cuentas_contables")->fetchColumn(), $v['resumen']['total'], 'cuentas leídas');
        // Plantilla vacía: solo encabezados → no hay nada que importar
        file_put_contents($tmp, plantillaCatalogo());
        afirmar(validarCatalogo($pdo, leerXlsx($tmp), false)['errores'], 'una plantilla vacía no debe importarse');
    } finally { @unlink($tmp); }
});

prueba('Catálogo de cuentas en Excel: rechaza archivos que no son la plantilla', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'x');
    $rechaza = function (string $que) use ($tmp) {
        try { leerXlsx($tmp); } catch (RuntimeException $e) { return; }
        throw new AssertionError("aceptó $que");
    };
    try {
        file_put_contents($tmp, "Código,Nombre,Tipo\n1,Caja,activo");          $rechaza('un CSV renombrado');
        $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
        $z->addFromString('word/document.xml', '<w/>'); $z->addFromString('[Content_Types].xml', '<Types/>'); $z->close();
        $rechaza('un ZIP que no es libro de Excel');
        file_put_contents($tmp, generarXlsx('H', ['a'], [['b']]));
        $z->open($tmp); $z->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><worksheet>&e;</worksheet>'); $z->close();
        $rechaza('un XML con entidades externas');
    } finally { @unlink($tmp); }
});

prueba('Catálogo de cuentas en Excel: valida cada celda y aplica todo o nada', function () use ($pdo) {
    $enc = [1 => array_values(CATALOGO_COLUMNAS)];
    $errores = fn(array $filas, bool $desactivar = false) => validarCatalogo($pdo, $enc + $filas, $desactivar)['errores'];
    afirmar(validarCatalogo($pdo, [1 => ['Codigo', 'Nombre', 'Tipo']] + [2 => ['1', 'Caja', 'Activo']], false)['errores'], 'aceptó encabezados distintos');
    $e = $errores([2 => ['ZZ1', 'Caja', 'Activo'], 3 => ['ZZ1', 'Otra', 'Gasto'], 4 => ['ZZ 2', '', 'Gastos'], 5 => ['ZZ3', 'X', 'Gasto', '', 'NOEXISTE'],
                   6 => ['ZZ4', 'X', 'Gasto', '', '', '0', '', 'Tal vez'], 7 => ['ZZ5', 'X', 'Gasto', '', '', '', '', '', 'extra']]);
    foreach (['ya aparece en la fila 2', '«ZZ 2» no es válido', 'columna «Nombre»: es obligatorio', '«Gastos» no es un tipo válido',
              'cuenta padre NOEXISTE no está', 'columna «Nivel»', 'debe ser «Sí» o «No»', 'fuera de las columnas'] as $esperado) {
        afirmar((bool)array_filter($e, fn($x) => str_contains($x, $esperado)), "falta el error: $esperado");
    }
    afirmar((bool)array_filter($errores([2 => ['ZZA', 'A', 'Gasto', '', 'ZZB'], 3 => ['ZZB', 'B', 'Gasto', '', 'ZZA']]), fn($x) => str_contains($x, 'ciclo')), 'no detectó el ciclo');

    $pdo->beginTransaction();
    try {
        $existente = $pdo->query("SELECT codigo FROM cuentas_contables ORDER BY id LIMIT 1")->fetchColumn();
        $filas = $enc + [2 => ['ZZP', 'Grupo de prueba', 'Gasto'], 3 => ['zzh', 'Hija de prueba', 'otro gasto', 'Sub', 'ZZP', '', 'Desc', 'No']];
        if ($existente) $filas[4] = [$existente, 'Renombrada por la prueba', 'Activo'];
        $v = validarCatalogo($pdo, $filas, true);
        iguales([], $v['errores'], 'errores de un archivo correcto');
        aplicarCatalogo($pdo, $v['cuentas'], true);
        $h = $pdo->query("SELECT c.*, p.codigo AS padre FROM cuentas_contables c LEFT JOIN cuentas_contables p ON p.id = c.cuenta_padre_id WHERE c.codigo = 'zzh'")->fetch();
        afirmar($h && $h['padre'] === 'ZZP' && (int)$h['nivel'] === 2 && $h['tipo'] === 'otro_gasto' && !$h['activa'], 'la cuenta hija no quedó como en el archivo');
        if ($existente) iguales('Renombrada por la prueba', $pdo->query("SELECT nombre FROM cuentas_contables WHERE codigo = " . $pdo->quote($existente))->fetchColumn(), 'cuenta existente actualizada por código');
        iguales(0, (int)$pdo->query("SELECT COUNT(*) FROM cuentas_contables WHERE activa = 1 AND codigo NOT IN ('ZZP', " . $pdo->quote((string)$existente) . ")")->fetchColumn(), 'las que no venían quedaron inactivas');
    } finally { $pdo->rollBack(); }
});

prueba('Planillas quincenales y mensuales: días trabajados, deducciones y nombre del período', function () {
    $q1 = ['tipo' => 'quincenal', 'quincena' => 'primera', 'anio' => 2026, 'mes' => 2, 'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-02-15'];
    $q2 = ['tipo' => 'quincenal', 'quincena' => 'segunda', 'anio' => 2026, 'mes' => 2, 'fecha_inicio' => '2026-02-16', 'fecha_fin' => '2026-02-28'];
    $m  = ['tipo' => 'mensual',   'quincena' => 'segunda', 'anio' => 2026, 'mes' => 2, 'fecha_inicio' => '2026-02-01', 'fecha_fin' => '2026-02-28'];
    iguales([15, 15, 30], [diasPeriodoPlanilla($q1), diasPeriodoPlanilla($q2), diasPeriodoPlanilla($m)], 'días de un período completo');
    iguales([1, 1, 2], [factorDeduccionesPlanilla($q1), factorDeduccionesPlanilla($q2), factorDeduccionesPlanilla($m)], 'factor de deducciones');
    iguales(15, diasTrabajadosPlanilla($q1, '2020-05-10'), 'empleado antiguo: quincena completa');
    iguales(30, diasTrabajadosPlanilla($m, null), 'sin fecha de ingreso: mes completo');
    iguales(11, diasTrabajadosPlanilla($q1, '2026-02-05'), 'ingresó el 5: del 5 al 15');
    iguales(11, diasTrabajadosPlanilla($q2, '2026-02-20'), 'ingresó el 20: del 20 al 30 (comercial)');
    iguales(21, diasTrabajadosPlanilla($m, '2026-02-10'), 'mensual, ingresó el 10: del 10 al 30');
    iguales(0, diasTrabajadosPlanilla($q1, '2026-02-20'), 'ingresó después del período: no entra');
    iguales('Mensual (1-28)', nombrePeriodoPlanilla($m), 'nombre de la mensual de febrero');
    iguales('2ª Quincena (16-31)', nombrePeriodoPlanilla($q2), 'nombre de la segunda quincena');
    iguales('Mensual', nombrePeriodoPlanilla($m, true), 'nombre corto');
});

prueba('Edición: quien creó el proceso lo edita hasta que alguien lo revisa; después, solo un superior', function () use ($pdo) {
    conEscenario($pdo, function ($u, $per) use ($pdo) {
        comoUsuario($pdo, $u['asistente']); iguales(true, puedeEditar($pdo, 'permisos.php', $per), 'el creador edita su permiso sin revisar');
        comoUsuario($pdo, $u['par']);       afirmar(puedeEditar($pdo, 'permisos.php', $per) !== true, 'alguien fuera de su rama no lo edita');
        comoUsuario($pdo, $u['jefe']);      iguales(true, puedeEditar($pdo, 'permisos.php', $per), 'el superior lo edita');
        registrarPasoFlujo($pdo, 'permisos.php', $per, 'revisado');
        comoUsuario($pdo, $u['asistente']); afirmar(str_contains((string)puedeEditar($pdo, 'permisos.php', $per), 'Ya fue revisado'), 'revisado: el creador ya no lo edita');
        comoUsuario($pdo, $u['jefe']);      iguales(true, puedeEditar($pdo, 'permisos.php', $per), 'revisado: el superior aún lo corrige');
    });
});
require_once RAIZ . '/config/cotizaciones.php';
prueba('Cotizaciones: recomienda el proveedor único y la compra por artículo según los precios', function () {
    $items = [['id' => 1, 'cantidad' => 2], ['id' => 2, 'cantidad' => 10], ['id' => 3, 'cantidad' => 1]];
    $of = [
        ['id' => 10, 'total' => 0, 'precios' => [1 => 100, 2 => 5, 3 => 50]],   // completo: 200 + 50 + 50 = 300
        ['id' => 20, 'total' => 0, 'precios' => [1 => 90,  2 => 6, 3 => 40]],   // completo: 180 + 60 + 40 = 280
        ['id' => 30, 'total' => 0, 'precios' => [2 => 4]],                       // solo el artículo 2: 40
    ];
    $a = analizarCotizacion($items, $of);
    iguales(true, $a['con_detalle'], 'hay precios por artículo');
    iguales(['oferta_id' => 20, 'total' => 280.0, 'completo' => true, 'faltan' => 0], $a['unico'], 'proveedor único: el más barato entre los que cotizaron todo');
    iguales([1 => 20, 2 => 30, 3 => 20], $a['por_articulo']['asignacion'], 'cada artículo al menor precio');
    iguales(260.0, $a['por_articulo']['total'], 'total comprando por artículo');
    iguales(20.0, $a['ahorro'], 'ahorro frente al proveedor único');
    iguales(1, $a['ofertas'][30]['cubre'], 'artículos que cotizó el proveedor parcial');
    // Empate de precio en un artículo (no en el total): va al proveedor único recomendado (menos órdenes de compra)
    $of[0]['precios'][1] = 90; $of[0]['precios'][3] = 60;   // el 10 queda en 290; el único sigue siendo el 20 (280)
    iguales(20, analizarCotizacion($items, $of)['por_articulo']['asignacion'][1], 'el empate va al proveedor único');
    // Nadie cotizó todo: el que más cubre
    $parcial = analizarCotizacion($items, [['id' => 1, 'total' => 0, 'precios' => [1 => 1]], ['id' => 2, 'total' => 0, 'precios' => [1 => 5, 2 => 5]]]);
    iguales([2, false, 1], [$parcial['unico']['oferta_id'], $parcial['unico']['completo'], $parcial['unico']['faltan']], 'sin oferta completa: el que más cubre');
    iguales([3], $parcial['por_articulo']['sin_oferta'], 'artículo sin ninguna oferta');
    // Cotización anterior con montos globales
    $g = analizarCotizacion([], [['id' => 1, 'total' => 500, 'precios' => []], ['id' => 2, 'total' => 450, 'precios' => []]]);
    iguales([false, 2, null], [$g['con_detalle'], $g['unico']['oferta_id'], $g['por_articulo']], 'montos globales: la oferta más baja');
});
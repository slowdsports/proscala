<?php
// ── Formatos en PDF de los documentos del sistema ──────────────────
// Genera en esta carpeta un PDF por cada documento que imprime print.php, IDÉNTICO al que entrega
// el sistema: usa las mismas plantillas (templates/print/) y el mismo motor que pdf_download.php
// (Edge / Chrome sin ventana). Los datos son de PRUEBA, inventados: organización, empleados,
// proveedores, proyectos y montos ficticios; no se lee ninguna base de una empresa real.
//
// Cómo lo hace: crea una base temporal (proscala_formatos_demo) igual que al dar de alta una
// empresa (empresa_schema.sql, empresa_seed.sql, roles_modulos_inicial.sql), la llena con los
// datos de prueba, imprime cada documento con su plantilla y al terminar borra la base.
//
// Uso (desde la raíz del sistema, solo en la línea de comandos, con MySQL encendido):
//   php formatos/generar_pdf.php                     todos los formatos en formatos/
//   php formatos/generar_pdf.php --salida=<carpeta>  en otra carpeta
//   php formatos/generar_pdf.php --conservar         no borra la base de prueba al terminar
// Si cambia el diseño de una plantilla de impresión, basta con volver a generarlos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('RAIZ', dirname(__DIR__));
const BD_DEMO = 'proscala_formatos_demo';
$_SERVER['DOCUMENT_ROOT'] = RAIZ;
$_SERVER['SCRIPT_NAME']   = '/print.php';
chdir(RAIZ);

// ═══ Modo interno: imprime UN documento en HTML (lo llama el propio script, un proceso por documento,
//     porque las plantillas declaran funciones y variables que no se pueden cargar dos veces) ═══
if (($argv[1] ?? '') === '--html') {
    [, , $tipoDoc, $idDoc, $consulta, $usuarioId] = $argv;
    require RAIZ . '/config/db.php';
    require RAIZ . '/config/auth.php';
    require RAIZ . '/config/functions.php';
    $GLOBALS['pdo'] = $pdo = conectarBD(BD_DEMO);
    $u = $pdo->query("SELECT * FROM usuarios WHERE id = " . (int)$usuarioId)->fetch();
    $_SESSION = ['usuario_id' => (int)$u['id'], 'usuario_nombre' => $u['nombre'], 'usuario_rol' => $u['rol'], 'usuario_email' => $u['email'],
                 'empleado_id' => $u['empleado_id'] ? (int)$u['empleado_id'] : null, 'proveedor_id' => null, 'es_superadmin' => false,
                 'empresa_id' => 0, 'empresa_db' => BD_DEMO, 'empresa_nombre' => 'Demostración'];
    roles(true);
    cargarPermisos($pdo, $u['rol']);
    parse_str($consulta, $_GET);
    $_GET += ['tipo' => $tipoDoc, 'id' => $idDoc];

    // ── Igual que print.php (después de requireLogin y los permisos) ──
    $tipo = $_GET['tipo'];
    $id   = (int)$_GET['id'];
    $plantilla = in_array($tipo, ['trabajo', 'salario', 'referencia', 'tiempo'], true) ? 'constancia' : $tipo;
    $config  = getAllConfig($pdo);
    $org     = $config['nombre_organizacion'] ?? 'Proscala';
    $orgDir  = $config['direccion'] ?? 'Tegucigalpa, Honduras';
    $orgRtn   = $config['rtn'] ?? '';
    $orgTel   = $config['telefono'] ?? '';
    $orgEmail = $config['correo'] ?? '';
    $orgFull  = $config['nombre_completo'] ?? '';
    $orgInicial = mb_strtoupper(mb_substr($org, 0, 1));
    $logoData   = logoEmpresaDataUri($pdo);
    $meses   = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $doc = $detalle = [];
    $title = $subtitle = $docNum = '';
    require_once RAIZ . '/templates/print/_funciones.php';
    require RAIZ . '/templates/print/' . $plantilla . '.php';
    exit;
}

// ═══ Modo normal ═══
$opc = getopt('', ['salida::', 'conservar']);
$SALIDA = rtrim($opc['salida'] ?? __DIR__, '/\\');
if (!is_dir($SALIDA) && !mkdir($SALIDA, 0775, true)) exit("No se pudo crear la carpeta {$SALIDA}\n");

require RAIZ . '/config/db.php';
require RAIZ . '/config/esquema.php';

$navegador = '';
foreach (['C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', 'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
          'C:\Program Files\Google\Chrome\Application\chrome.exe', '/usr/bin/chromium-browser', '/usr/bin/chromium',
          '/usr/bin/google-chrome-stable', '/usr/bin/google-chrome'] as $p) {
    if (is_file($p)) { $navegador = $p; break; }
}
if (!$navegador) exit("No se encontró Edge, Chrome ni Chromium para generar los PDF.\n");

// ── Base de prueba ─────────────────────────────────────────────────
echo "Base de prueba " . BD_DEMO . "...\n";
$pdoMaster->exec("DROP DATABASE IF EXISTS `" . BD_DEMO . "`");
$pdoMaster->exec("CREATE DATABASE `" . BD_DEMO . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    $db = conectarBD(BD_DEMO);
    foreach (['empresa_schema.sql', 'empresa_seed.sql', 'roles_modulos_inicial.sql'] as $archivo) ejecutarArchivoSQL($db, RAIZ . "/database/$archivo");
    $ids = cargarDatosDePrueba($db);

    // [archivo, tipo, id, parámetros extra]
    $docs = [
        ['01 Compras - Solicitud de compra',            'sc',      $ids['sc']],
        ['02 Compras - Resumen de cotizaciones',        'co',      $ids['co']],
        ['03 Compras - Orden de compra',                'oc',      $ids['oc']],
        ['04 Compras - Orden de pago',                  'op',      $ids['op']],
        ['05 Compras - Proceso de compra completo',     'proceso', $ids['sc']],
        ['06 Gastos - Anticipo de viáticos',            'gv',      $ids['gv_viaje']],
        ['07 Gastos - Anticipo para compra',            'gv',      $ids['gv_compra']],
        ['08 Gastos - Voucher de alimentación',         'gva',     $ids['gv_viaje']],
        ['09 Gastos - Solicitud de gastos',             'sg',      $ids['sg']],
        ['10 Gastos - Liquidación de gastos',           'sg',      $ids['sg_liq']],
        ['11 Gastos - Caja chica',                      'cc',      $ids['cc']],
        ['12 Planillas - Planilla de sueldos',          'pl',      $ids['pl']],
        ['13 Planillas - Planilla por proyecto',        'plp',     $ids['pl']],
        ['14 Planillas - Reporte Credimpulsa',          'plc',     $ids['pl']],
        ['15 Planillas - Recibo de quincena',           'plv',     $ids['pl'], 'emp_id=' . $ids['emp_recibo']],
        ['16 Planillas - Planilla de asignación',       'pa',      $ids['pa']],
        ['17 Planillas - Recibo de asignación',         'pav',     $ids['pa'], 'emp_id=' . $ids['emp_recibo']],
        ['18 Planillas - Planilla de beneficios',       'pb',      $ids['pb']],
        ['19 Planillas - Beneficios por proyecto',      'pbp',     $ids['pb']],
        ['20 Planillas - Recibo de beneficio',          'pbv',     $ids['pb'], 'emp_id=' . $ids['emp_recibo']],
        ['21 RRHH - Solicitud de vacaciones',           'vac',     $ids['vac']],
        ['22 RRHH - Permiso',                           'per',     $ids['per']],
        ['23 RRHH - Liquidación de personal',           'liq',     $ids['liq']],
        ['24 RRHH - Constancia de trabajo',             'trabajo',    $ids['emp_constancia'], 'destinatario=A quien interese'],
        ['25 RRHH - Constancia de salario',             'salario',    $ids['emp_constancia'], 'destinatario=Banco de Ejemplo'],
        ['26 RRHH - Referencia laboral',                'referencia', $ids['emp_constancia'], 'destinatario=A quien interese'],
        ['27 RRHH - Constancia de tiempo de servicio',  'tiempo',     $ids['emp_constancia'], 'destinatario=A quien interese'],
        ['28 Administración - Memorándum',              'memo',    $ids['memo']],
        ['29 Administración - Acta de reunión',         'acta',    $ids['acta']],
    ];

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'proscala_formatos_' . uniqid();
    mkdir($tmp);
    $php = escapeshellarg(PHP_BINARY);
    $fallas = 0;
    foreach ($docs as $d) {
        [$nombre, $tipo, $id] = $d;
        $consulta = $d[3] ?? '';
        echo "  $nombre... ";
        $html = shell_exec("$php " . escapeshellarg(__FILE__) . " --html $tipo $id " . escapeshellarg($consulta) . " {$ids['usuario_impresion']}");
        if (!$html || !str_contains($html, '</html>')) { echo "ERROR al imprimir:\n" . trim((string)$html) . "\n"; $fallas++; continue; }
        $archivoHtml = "$tmp/$tipo-$id.html";
        file_put_contents($archivoHtml, $html);
        $pdf = "$SALIDA/$nombre.pdf";
        @unlink($pdf);
        $url = DIRECTORY_SEPARATOR === '\\' ? 'file:///' . str_replace('\\', '/', $archivoHtml) : 'file://' . $archivoHtml;
        // Mismas opciones que pdf_download.php (un perfil por documento)
        aPdf($navegador, $url, $pdf, "$tmp/perfil-$tipo-$id");
        if (is_file($pdf) && filesize($pdf) > 0) echo "listo\n";
        else { echo "ERROR al convertir a PDF\n"; $fallas++; }
    }
    borrarCarpeta($tmp);
    echo $fallas ? "\n$fallas formato(s) con error.\n" : "\n" . count($docs) . " formatos en PDF en $SALIDA\n";
} finally {
    if (!isset($opc['conservar'])) $pdoMaster->exec("DROP DATABASE IF EXISTS `" . BD_DEMO . "`");
    else echo "Base de prueba conservada: " . BD_DEMO . "\n";
}
exit(!empty($fallas) ? 1 : 0);

/**
 * Imprime $url a $pdf con el navegador sin ventana. A veces el navegador escribe el PDF y no termina:
 * se espera a que el archivo exista y deje de crecer (máx. 90 s) y se cierra el proceso con sus hijos.
 */
function aPdf(string $navegador, string $url, string $pdf, string $perfil): void {
    $cmd = escapeshellarg($navegador) . ' --headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage'
         . ' --user-data-dir=' . escapeshellarg($perfil) . ' --print-to-pdf=' . escapeshellarg($pdf)
         . ' --no-pdf-header-footer ' . escapeshellarg($url);
    $nul = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $proc = proc_open($cmd, [['file', $nul, 'r'], ['file', $nul, 'w'], ['file', $nul, 'w']], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) return;
    $inicio = microtime(true);
    $tam = -1;
    while (microtime(true) - $inicio < 90) {
        usleep(300000);
        if (!proc_get_status($proc)['running']) break;
        clearstatcache(true, $pdf);
        $t = is_file($pdf) ? filesize($pdf) : 0;
        if ($t > 0 && $t === $tam) break;   // escrito y estable
        $tam = $t;
    }
    $st = proc_get_status($proc);
    if ($st['running']) {
        if (DIRECTORY_SEPARATOR === '\\') exec('taskkill /T /F /PID ' . (int)$st['pid'] . ' >NUL 2>&1');
        else proc_terminate($proc, 9);
    }
    proc_close($proc);
}

function borrarCarpeta(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

// ═════════════════════════════ DATOS DE PRUEBA ═════════════════════════════
// Todo es inventado. Los números de identidad, RTN y cuentas son ceros/series de ejemplo.
function cargarDatosDePrueba(PDO $db): array {
    $ins = function (string $tabla, array $fila) use ($db): int {
        $cols = array_keys($fila);
        $db->prepare("INSERT INTO `$tabla` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")
           ->execute(array_values($fila));
        return (int)$db->lastInsertId();
    };
    $anio = (int)date('Y');
    $yy = substr((string)$anio, 2);
    $f = fn(int $mes, int $dia) => sprintf('%04d-%02d-%02d', $anio, $mes, $dia);

    // Organización
    $cfg = ['nombre_organizacion' => 'Organización Demo', 'nombre_completo' => 'Asociación de Desarrollo Comunitario Demostración (datos de prueba)',
            'rtn' => '08019000000001', 'telefono' => '+504 2200-0000', 'correo' => 'contacto@ejemplo.org',
            'direccion' => 'Colonia Ejemplo, Calle Principal, Tegucigalpa, Honduras'];
    foreach ($cfg as $k => $v) $db->prepare("UPDATE configuracion SET valor = ? WHERE clave = ?")->execute([$v, $k]);

    // Empleados
    $emp = [];
    $personas = [
        ['dir',  'Ana Gabriela', 'Martínez López',   'Directora Ejecutiva',          'Dirección',       42000, '2015-02-02'],
        ['adm',  'Carlos Eduardo', 'Rodríguez Paz',  'Administrador',                'Administración',  32000, '2017-06-01'],
        ['cont', 'Lucía María', 'Hernández Castro',  'Contadora General',            'Finanzas',        28000, '2018-03-12'],
        ['rrhh', 'Sofía Alejandra', 'Reyes Molina',  'Oficial de Recursos Humanos',  'Recursos Humanos', 24000, '2019-08-05'],
        ['coor', 'Roberto José', 'Sánchez Díaz',     'Coordinador de Proyectos',     'Programas',       30000, '2016-11-14'],
        ['tec',  'Jorge Luis', 'Mejía Flores',       'Técnico de Campo',             'Programas',       18500, '2021-01-18'],
        ['asis', 'María Fernanda', 'Zelaya Ortiz',   'Asistente Administrativa',     'Administración',  15000, '2022-04-04'],
        ['prom', 'Daniel Antonio', 'Cruz Rivera',    'Promotor Comunitario',         'Programas',       14000, '2023-09-01'],
    ];
    foreach ($personas as $i => [$k, $nom, $ape, $cargo, $dep, $sueldo, $ingreso]) {
        $n = $i + 1;
        $emp[$k] = $ins('empleados', ['codigo' => sprintf('EMP-%03d', $n), 'nombre' => $nom, 'apellidos' => $ape,
            'identidad' => sprintf('0801-1990-%05d', $n), 'rtn' => sprintf('0801199000%04d', $n), 'cargo' => $cargo, 'departamento' => $dep,
            'fecha_ingreso' => $ingreso, 'sueldo_mensual' => $sueldo, 'banco' => ['BAC', 'Atlántida', 'FICOHSA'][$i % 3],
            'cuenta_banco' => sprintf('1000000%03d', $n), 'aplica_credimpulsa' => in_array($k, ['tec', 'asis'], true) ? 1 : 0,
            'email' => "empleado$n@ejemplo.org", 'telefono' => sprintf('+504 9900-00%02d', $n), 'direccion' => 'Tegucigalpa, Honduras']);
    }

    // Usuarios del flujo: quien elabora, quien revisa y quien autoriza
    $usr = [];
    foreach ([['asis', 'administracion'], ['adm', 'administracion'], ['dir', 'direccion_ejecutiva'], ['rrhh', 'admin']] as [$k, $rol]) {
        $nombre = $db->query("SELECT CONCAT(nombre,' ',apellidos) FROM empleados WHERE id = {$emp[$k]}")->fetchColumn();
        $usr[$k] = $ins('usuarios', ['nombre' => $nombre, 'email' => "usuario.$k@ejemplo.org", 'password' => 'x', 'rol' => $rol, 'empleado_id' => $emp[$k]]);
    }
    $flujo = function (string $tabla, int $id, bool $autorizado = true) use ($db, $usr) {
        $db->prepare("INSERT INTO registro_usuarios (tabla, registro_id, usuario_id, participacion) VALUES (?,?,?,'creador')")->execute([$tabla, $id, $usr['asis']]);
        $db->prepare("INSERT INTO aprobaciones (tabla, registro_id, paso, usuario_id) VALUES (?,?,'revisado',?)")->execute([$tabla, $id, $usr['adm']]);
        if ($autorizado) $db->prepare("INSERT INTO aprobaciones (tabla, registro_id, paso, usuario_id) VALUES (?,?,'autorizado',?)")->execute([$tabla, $id, $usr['dir']]);
    };

    // Firmas configuradas (como en una empresa en uso)
    foreach (['sc', 'oc', 'op', 'pl', 'pa', 'pb', 'cc'] as $doc) {
        foreach (['Elaborado por', 'Revisado por', 'Autorizado por'] as $i => $et) $ins('firmantes', ['documento' => $doc, 'posicion' => $i + 1, 'etiqueta' => $et]);
    }
    foreach (['gv', 'sg', 'vac', 'per'] as $doc) {
        foreach ([$doc === 'gv' || $doc === 'sg' ? 'Solicitado por' : '', 'Revisado por', 'Autorizado por'] as $i => $et) $ins('firmantes', ['documento' => $doc, 'posicion' => $i + 1, 'etiqueta' => $et]);
    }

    // Proyectos, proveedores y cuentas
    $proy = [
        'agua' => $ins('proyectos', ['codigo' => 'PRY-001', 'nombre' => 'Agua Segura para Comunidades (demo)', 'donante' => 'Donante Ejemplo A',
                  'fecha_inicio' => $f(1, 1), 'fecha_fin' => ($anio + 1) . '-12-31', 'presupuesto_total' => 1500000, 'responsable_id' => $emp['coor']]),
        'edu'  => $ins('proyectos', ['codigo' => 'PRY-002', 'nombre' => 'Educación Rural Inclusiva (demo)', 'donante' => 'Donante Ejemplo B',
                  'fecha_inicio' => $f(1, 1), 'fecha_fin' => ($anio + 1) . '-06-30', 'presupuesto_total' => 900000, 'responsable_id' => $emp['coor']]),
    ];
    $prov = [];
    foreach ([['Suministros Ejemplo S. de R.L.', 'bienes'], ['Comercial Demostración S.A.', 'ambos'], ['Distribuidora Prueba y Cía.', 'bienes']] as $i => [$nom, $cat]) {
        $prov[] = $ins('proveedores', ['codigo' => sprintf('PRV-%03d', $i + 1), 'nombre' => $nom, 'rtn' => sprintf('0801900000%04d', $i + 1),
            'contacto' => 'Contacto de ejemplo', 'telefono' => sprintf('+504 2230-00%02d', $i + 1), 'email' => 'ventas' . ($i + 1) . '@ejemplo.com',
            'direccion' => 'Barrio Ejemplo, Tegucigalpa', 'categoria' => $cat, 'banco' => 'BAC', 'banco_cuenta' => sprintf('2000000%03d', $i + 1), 'banco_tipo' => 'cheques']);
    }
    $cta = [];
    foreach ([['5101', 'Materiales y suministros'], ['5102', 'Viáticos y hospedaje'], ['5103', 'Transporte'], ['5104', 'Alimentación'],
              ['5105', 'Papelería y útiles de oficina'], ['5106', 'Combustible']] as [$cod, $nom]) {
        $cta[$cod] = $ins('cuentas_contables', ['codigo' => $cod, 'nombre' => $nom, 'tipo' => 'gasto']);
    }

    // ── Compras: solicitud → cotizaciones → orden de compra → orden de pago ──
    $items = [['Resma de papel bond tamaño carta', 'Caja', 10, 950], ['Tóner para impresora láser', 'Unidad', 4, 2350],
              ['Folders manila tamaño carta', 'Caja', 5, 320], ['Marcadores para pizarra (paquete de 4)', 'Paquete', 12, 145]];
    $estimado = array_sum(array_map(fn($i) => $i[2] * $i[3], $items));
    $sc = $ins('solicitud_compra', ['numero' => "SC$yy-0001", 'fecha' => $f(3, 4), 'solicitante_id' => $emp['asis'], 'proyecto_id' => $proy['edu'],
        'descripcion' => 'Compra de materiales de oficina para las actividades del primer trimestre',
        'justificacion' => 'Materiales necesarios para los talleres con docentes y la papelería del proyecto (datos de prueba).',
        'urgencia' => 'normal', 'presupuesto_estimado' => $estimado, 'estado' => 'adjudicada', 'aprobador_id' => $emp['dir'], 'fecha_aprobacion' => $f(3, 5)]);
    $scDet = [];
    foreach ($items as [$d, $u, $c, $p]) $scDet[] = $ins('solicitud_compra_detalle', ['solicitud_id' => $sc, 'descripcion' => $d, 'unidad' => $u, 'cantidad' => $c, 'precio_estimado' => $p]);
    $flujo('solicitud_compra', $sc);

    $co = $ins('cotizaciones', ['numero' => "CO$yy-0001", 'solicitud_id' => $sc, 'fecha' => $f(3, 8), 'elaborado_por_id' => $emp['asis'],
        'proveedor_seleccionado_id' => $prov[0], 'justificacion' => 'Ofrece el menor precio total y entrega inmediata (datos de prueba).',
        'estado' => 'aprobada', 'aprobador_id' => $emp['dir']]);
    $coItems = [];
    foreach ($items as $i => [$d, $u, $c]) $coItems[] = $ins('cotizaciones_items', ['cotizacion_id' => $co, 'solicitud_detalle_id' => $scDet[$i], 'orden' => $i + 1, 'descripcion' => $d, 'unidad' => $u, 'cantidad' => $c]);
    $factores = [[0.96, 0.98, 1.00, 0.95], [1.02, 0.95, 1.05, 1.00], [1.05, 1.04, 0.97, 1.08]];
    foreach ($prov as $j => $pid) {
        $total = 0;
        $cp = $ins('cotizaciones_proveedores', ['cotizacion_id' => $co, 'proveedor_id' => $pid, 'num_cotizacion' => sprintf('COT-%04d', 101 + $j),
            'fecha_cotizacion' => $f(3, 6 + $j), 'condiciones' => ['Contado', 'Crédito 15 días', 'Contado'][$j], 'tiempo_entrega' => ['Inmediata', '3 días', '5 días'][$j]]);
        foreach ($items as $i => [, , $c, $p]) {
            $pu = round($p * $factores[$j][$i], 2);
            $total += $pu * $c;
            $ins('cotizaciones_precios', ['item_id' => $coItems[$i], 'cotizacion_proveedor_id' => $cp, 'precio_unitario' => $pu]);
        }
        $db->prepare("UPDATE cotizaciones_proveedores SET total = ? WHERE id = ?")->execute([round($total, 2), $cp]);
    }
    $flujo('cotizaciones', $co);

    $sub = 0;
    foreach ($items as $i => [, , $c, $p]) $sub += round($p * $factores[0][$i], 2) * $c;
    $isv = round($sub * 0.15, 2);
    $oc = $ins('ordenes_compra', ['numero' => "OC$yy-0001", 'cotizacion_id' => $co, 'solicitud_id' => $sc, 'proveedor_id' => $prov[0], 'fecha' => $f(3, 10),
        'fecha_entrega' => $f(3, 12), 'condiciones_pago' => 'Contado contra entrega', 'lugar_entrega' => 'Oficina central (dirección de ejemplo)',
        'subtotal' => $sub, 'impuestos' => $isv, 'total' => $sub + $isv, 'proyecto_id' => $proy['edu'], 'estado' => 'completa', 'aprobador_id' => $emp['dir'],
        'notas' => 'Entregar en horario de oficina (datos de prueba).']);
    foreach ($items as $i => [$d, $u, $c, $p]) {
        $pu = round($p * $factores[0][$i], 2);
        $ins('ordenes_compra_detalle', ['orden_id' => $oc, 'descripcion' => $d, 'unidad' => $u, 'cantidad' => $c, 'precio_unitario' => $pu, 'total' => $pu * $c, 'cuenta_id' => $cta['5105']]);
    }
    $flujo('ordenes_compra', $oc, false);

    $op = $ins('ordenes_pago', ['numero' => "OP$yy-0001", 'fecha' => $f(3, 13), 'beneficiario' => 'Suministros Ejemplo S. de R.L.', 'beneficiario_tipo' => 'proveedor',
        'proveedor_id' => $prov[0], 'concepto' => "Pago de la orden de compra OC$yy-0001: materiales de oficina", 'monto' => $sub + $isv,
        'forma_pago' => 'transferencia', 'factura_proveedor' => '000-001-01-00000123', 'banco' => 'BAC', 'cuenta_bancaria' => '2000000001', 'banco_tipo' => 'cheques',
        'orden_compra_id' => $oc, 'cuenta_id' => $cta['5105'], 'proyecto_id' => $proy['edu'], 'estado' => 'pagada', 'fecha_pago' => $f(3, 14),
        'origen_tabla' => 'ordenes_compra', 'origen_id' => $oc]);
    $flujo('ordenes_pago', $op);

    // ── Anticipos y liquidaciones ──
    $gvViaje = $ins('gastos_viaje', ['numero' => "GV$yy-0001", 'empleado_id' => $emp['tec'], 'firmante1_id' => $emp['tec'], 'proyecto_id' => $proy['agua'],
        'destino' => 'Comunidad Ejemplo, Departamento Demo', 'proposito' => 'Supervisión de la instalación de sistemas de agua y reunión con la junta de agua (datos de prueba).',
        'fecha_salida' => $f(4, 7), 'fecha_regreso' => $f(4, 9), 'viaticos_anticipados' => 4600, 'monto_alimentacion' => 1800, 'estado' => 'liquidada', 'aprobador_id' => $emp['coor']]);
    foreach ([[$f(4, 7), 'Hospedaje (2 noches)', '5102', 2000], [$f(4, 7), 'Alimentación (3 días)', '5104', 1800], [$f(4, 7), 'Transporte ida y regreso', '5103', 800]] as [$fe, $d, $c, $m]) {
        $ins('gastos_viaje_detalle', ['viaje_id' => $gvViaje, 'fecha' => $fe, 'descripcion' => $d, 'cuenta_id' => $cta[$c], 'monto' => $m]);
    }
    $flujo('gastos_viaje', $gvViaje);

    $gvCompra = $ins('gastos_viaje', ['numero' => "GV$yy-0002", 'tipo' => 'compra', 'empleado_id' => $emp['asis'], 'firmante1_id' => $emp['asis'], 'proyecto_id' => $proy['edu'],
        'destino' => 'Ferretería y librería de ejemplo', 'proposito' => 'Compra de materiales menores para el taller con docentes (datos de prueba).',
        'fecha_salida' => $f(5, 5), 'fecha_regreso' => $f(5, 5), 'viaticos_anticipados' => 2500, 'estado' => 'aprobada', 'aprobador_id' => $emp['adm']]);
    foreach ([['Cartulinas y papel de colores', '5105', 900], ['Pegamento, tijeras y cinta', '5105', 650], ['Pilas y extensiones eléctricas', '5101', 950]] as [$d, $c, $m]) {
        $ins('gastos_viaje_detalle', ['viaje_id' => $gvCompra, 'fecha' => $f(5, 5), 'descripcion' => $d, 'cuenta_id' => $cta[$c], 'monto' => $m]);
    }
    $flujo('gastos_viaje', $gvCompra);

    $sg = $ins('solicitud_gastos', ['numero' => "SG$yy-0001", 'fecha' => $f(5, 20), 'empleado_id' => $emp['prom'], 'firmante1_id' => $emp['prom'],
        'descripcion' => 'Reembolso de gastos de la jornada comunitaria de capacitación', 'proyecto_id' => $proy['agua'], 'monto_total' => 1450,
        'estado' => 'aprobada', 'aprobador_id' => $emp['coor'], 'fecha_aprobacion' => $f(5, 21)]);
    foreach ([[$f(5, 18), 'Refrigerio para participantes', '5104', 850, 'Factura 001-0456'], [$f(5, 18), 'Transporte local', '5103', 400, 'Recibo 0789'], [$f(5, 19), 'Fotocopias de material', '5105', 200, 'Factura 002-1123']] as [$fe, $d, $c, $m, $cb]) {
        $ins('solicitud_gastos_detalle', ['solicitud_id' => $sg, 'fecha' => $fe, 'descripcion' => $d, 'cuenta_id' => $cta[$c], 'monto' => $m, 'comprobante' => $cb]);
    }
    $flujo('solicitud_gastos', $sg);

    $sgLiq = $ins('solicitud_gastos', ['numero' => "SG$yy-0002", 'fecha' => $f(4, 11), 'empleado_id' => $emp['tec'], 'firmante1_id' => $emp['tec'],
        'descripcion' => "Liquidación del anticipo GV$yy-0001: viaje a Comunidad Ejemplo", 'proyecto_id' => $proy['agua'], 'viaje_id' => $gvViaje, 'monto_total' => 4380,
        'estado' => 'aprobada', 'aprobador_id' => $emp['coor'], 'fecha_aprobacion' => $f(4, 12)]);
    foreach ([[$f(4, 7), 'Hotel de ejemplo (2 noches)', '5102', 1900, 'Factura 001-0101'], [$f(4, 7), 'Bus interurbano ida y regreso', '5103', 680, 'Boletos 3345-3346'], [$f(4, 9), 'Alimentación del viaje', '5104', 1800, 'Voucher de alimentación']] as [$fe, $d, $c, $m, $cb]) {
        $ins('solicitud_gastos_detalle', ['solicitud_id' => $sgLiq, 'fecha' => $fe, 'descripcion' => $d, 'cuenta_id' => $cta[$c], 'monto' => $m, 'comprobante' => $cb]);
    }
    $flujo('solicitud_gastos', $sgLiq);

    // ── Caja chica ──
    $ccGastos = [[$f(6, 2), '5105', 'Sobres y clips', 3, 45], [$f(6, 4), '5106', 'Combustible para motocicleta', 1, 600], [$f(6, 9), '5104', 'Agua purificada para oficina', 4, 60], [$f(6, 12), '5103', 'Taxi para entrega de documentos', 1, 150]];
    $ccTotal = array_sum(array_map(fn($g) => $g[3] * $g[4], $ccGastos));
    $cc = $ins('caja_chica', ['numero' => "CC$yy-0001", 'empleado_id' => $emp['asis'], 'anticipo' => 3000, 'total_gastos' => $ccTotal, 'saldo' => 3000 - $ccTotal,
        'estado' => 'liquidada', 'aprobador_id' => $usr['adm'], 'fecha_apertura' => $f(6, 1), 'fecha_cierre' => $f(6, 15), 'notas' => 'Fondo de caja chica de junio (datos de prueba).']);
    foreach ($ccGastos as [$fe, $c, $d, $q, $pu]) $ins('caja_chica_detalle', ['caja_id' => $cc, 'fecha' => $fe, 'cuenta_id' => $cta[$c], 'descripcion' => $d, 'cantidad' => $q, 'precio_unitario' => $pu, 'monto' => $q * $pu]);
    $db->prepare("INSERT INTO registro_usuarios (tabla, registro_id, usuario_id, participacion) VALUES ('caja_chica', ?, ?, 'creador')")->execute([$cc, $usr['asis']]);

    // ── Planilla de sueldos (primera quincena de julio) con distribución por proyecto ──
    $pl = $ins('planilla_periodos', ['numero' => "PL$yy-0013", 'anio' => $anio, 'mes' => 7, 'quincena' => 'primera', 'tipo' => 'quincenal',
        'fecha_inicio' => $f(7, 1), 'fecha_fin' => $f(7, 15), 'fecha_pago' => $f(7, 15), 'estado' => 'pagada']);
    $tb = $td = $tn = 0;
    $reparto = ['dir' => [50, 50], 'adm' => [50, 50], 'cont' => [60, 40], 'rrhh' => [50, 50], 'coor' => [70, 30], 'tec' => [100, 0], 'asis' => [40, 60], 'prom' => [100, 0]];
    foreach ($personas as [$k, , , , , $sueldo]) {
        $bruto = round($sueldo / 2, 2);
        $ihss = round(min($sueldo, 9357) * 0.025 / 2, 2);
        $rap  = round(max($sueldo - 9357, 0) * 0.015 / 2, 2);
        $isr  = $sueldo > 21000 ? round(($sueldo - 21000) * 0.15 / 2 * 0.6, 2) : 0;
        $credi = in_array($k, ['tec', 'asis'], true) ? 500 : 0;
        $ded = $ihss + $rap + $isr + $credi;
        $det = $ins('planilla_detalle', ['periodo_id' => $pl, 'empleado_id' => $emp[$k], 'sueldo_quincenal' => $bruto, 'total_bruto' => $bruto,
            'ihss_empleado' => $ihss, 'rap_empleado' => $rap, 'isr' => $isr, 'credimpulsa' => $credi, 'total_deducciones' => $ded, 'sueldo_neto' => $bruto - $ded,
            'ihss_patronal' => round(min($sueldo, 9357) * 0.05 / 2, 2), 'rap_patronal' => round(max($sueldo - 9357, 0) * 0.015 / 2, 2), 'dias_trabajados' => 15]);
        [$pa, $pe] = $reparto[$k];
        if ($pa) $ins('planilla_detalle_proyectos', ['detalle_id' => $det, 'proyecto_id' => $proy['agua'], 'pct_salario' => $pa, 'pct_patronal' => $pa]);
        if ($pe) $ins('planilla_detalle_proyectos', ['detalle_id' => $det, 'proyecto_id' => $proy['edu'], 'pct_salario' => $pe, 'pct_patronal' => $pe]);
        $tb += $bruto; $td += $ded; $tn += $bruto - $ded;
    }
    $db->prepare("UPDATE planilla_periodos SET total_bruto = ?, total_deducciones = ?, total_neto = ? WHERE id = ?")->execute([$tb, $td, $tn, $pl]);
    $flujo('planilla_periodos', $pl);

    // ── Planilla de asignación ──
    $paId = $ins('planilla_asignacion', ['numero' => "PA$yy-0001", 'anio' => $anio, 'mes' => 7, 'descripcion' => 'Asignación por trabajo de campo (datos de prueba)',
        'proyecto_id' => $proy['agua'], 'estado' => 'pagada']);
    $paTotal = 0;
    foreach ([['tec', 'Asignación de campo de julio', 2500], ['prom', 'Asignación de campo de julio', 2000], ['coor', 'Asignación por coordinación de giras', 1500]] as [$k, $c, $m]) {
        $ins('planilla_asignacion_detalle', ['asignacion_id' => $paId, 'empleado_id' => $emp[$k], 'concepto' => $c, 'monto' => $m]);
        $paTotal += $m;
    }
    $db->prepare("UPDATE planilla_asignacion SET total = ? WHERE id = ?")->execute([$paTotal, $paId]);
    $flujo('planilla_asignacion', $paId);

    // ── Planilla de beneficios (décimo cuarto mes) con distribución ──
    $pb = $ins('planilla_beneficios', ['numero' => "PB$yy-0001", 'tipo' => 'decimo_cuarto', 'anio' => $anio, 'fecha_pago' => $f(6, 20), 'estado' => 'pagada',
        'notas' => 'Décimo cuarto mes (datos de prueba).']);
    $bb = $bd = 0;
    foreach ($personas as [$k, , , , , $sueldo]) {
        $deduc = $sueldo > 30000 ? round($sueldo * 0.03, 2) : 0;
        $det = $ins('planilla_beneficios_detalle', ['beneficio_id' => $pb, 'empleado_id' => $emp[$k], 'sueldo_mensual' => $sueldo, 'deduccion' => $deduc, 'neto' => $sueldo - $deduc]);
        [$pa, $pe] = $reparto[$k];
        if ($pa) $ins('planilla_beneficios_detalle_proyectos', ['detalle_id' => $det, 'proyecto_id' => $proy['agua'], 'porcentaje' => $pa]);
        if ($pe) $ins('planilla_beneficios_detalle_proyectos', ['detalle_id' => $det, 'proyecto_id' => $proy['edu'], 'porcentaje' => $pe]);
        $bb += $sueldo; $bd += $deduc;
    }
    $db->prepare("UPDATE planilla_beneficios SET total_bruto = ?, total_deducciones = ?, total_neto = ? WHERE id = ?")->execute([$bb, $bd, $bb - $bd, $pb]);
    $flujo('planilla_beneficios', $pb);

    // ── RRHH ──
    $vac = $ins('vacaciones', ['numero' => "VAC$yy-0001", 'empleado_id' => $emp['cont'], 'fecha_solicitud' => $f(7, 20), 'fecha_inicio' => $f(8, 3),
        'fecha_fin' => $f(8, 7), 'dias' => 5, 'tipo' => 'ordinaria', 'estado' => 'aprobada', 'aprobador_id' => $emp['adm'], 'notas' => 'Vacaciones ordinarias (datos de prueba).']);
    $flujo('vacaciones', $vac);
    $per = $ins('permisos', ['numero' => "PER$yy-0001", 'empleado_id' => $emp['tec'], 'fecha_solicitud' => $f(8, 10), 'fecha_inicio' => $f(8, 12), 'fecha_fin' => $f(8, 12),
        'dias' => 1, 'tipo' => 'personal', 'con_goce' => 1, 'motivo' => 'Trámite personal (datos de prueba).', 'estado' => 'aprobado', 'aprobador_id' => $emp['coor']]);
    $flujo('permisos', $per);
    $sueldoLiq = 14000;
    $liq = $ins('liquidaciones', ['numero' => "LIQ$yy-0001", 'empleado_id' => $emp['prom'], 'fecha_salida' => $f(9, 30), 'motivo' => 'renuncia',
        'anios_servicio' => 2.08, 'meses_anio_actual' => 9, 'sueldo_mensual' => $sueldoLiq, 'dias_pendientes' => 15, 'salarios_pendientes' => 7000,
        'dias_vacaciones' => 6, 'vacaciones_pendientes' => 2800, 'decimo_tercero_prop' => 10500, 'decimo_cuarto_prop' => 3500, 'preaviso' => 0, 'cesantia' => 0,
        'otros_conceptos' => 0, 'total' => 23800, 'notas' => 'Liquidación por renuncia voluntaria (datos de prueba).', 'estado' => 'aprobada',
        'aprobador_id' => $usr['adm'], 'fecha_aprobacion' => $f(10, 2)]);
    $flujo('liquidaciones', $liq);

    // ── Documentos internos ──
    $memo = $ins('memorandos', ['numero' => "MEM$yy-0001", 'tipo' => 'memo', 'fecha' => $f(9, 1), 'de_empleado_id' => $emp['dir'], 'de_cargo' => 'Directora Ejecutiva',
        'para_texto' => "Todo el personal", 'asunto' => 'Horario de atención durante la semana de capacitación',
        'cuerpo' => "Por este medio se informa que durante la semana del 15 al 19 de septiembre la oficina atenderá de 8:00 a.m. a 12:00 m., debido a la capacitación interna del personal.\n\nSe solicita reprogramar las reuniones con anticipación y confirmar su asistencia a la coordinación.\n\n(Documento de prueba: el contenido es inventado.)",
        'estado' => 'emitido']);
    $acta = $ins('actas_reunion', ['numero' => "ACTA$yy-0001", 'fecha' => $f(9, 5), 'hora_inicio' => '09:00:00', 'hora_fin' => '11:30:00',
        'lugar' => 'Sala de reuniones de la oficina central', 'convocado_por' => 'Ana Gabriela Martínez López, Directora Ejecutiva',
        'asunto' => 'Planificación del segundo semestre', 'participantes' => "Ana Gabriela Martínez López — Directora Ejecutiva\nCarlos Eduardo Rodríguez Paz — Administrador\nLucía María Hernández Castro — Contadora General\nRoberto José Sánchez Díaz — Coordinador de Proyectos",
        'agenda' => "1. Avance de los proyectos\n2. Ejecución presupuestaria\n3. Calendario de actividades del semestre",
        'desarrollo' => "Se revisó el avance de los proyectos PRY-001 y PRY-002 y la ejecución presupuestaria al cierre de agosto. Se acordó el calendario de giras de campo para octubre y noviembre.\n\n(Documento de prueba: el contenido es inventado.)",
        'acuerdos' => "1. Presentar el informe trimestral a los donantes antes del 30 de septiembre.\n2. Programar las giras de campo de octubre.\n3. Actualizar el plan de compras del semestre.",
        'proxima_reunion' => $f(10, 3), 'estado' => 'aprobada']);

    return ['sc' => $sc, 'co' => $co, 'oc' => $oc, 'op' => $op, 'gv_viaje' => $gvViaje, 'gv_compra' => $gvCompra, 'sg' => $sg, 'sg_liq' => $sgLiq,
            'cc' => $cc, 'pl' => $pl, 'pa' => $paId, 'pb' => $pb, 'vac' => $vac, 'per' => $per, 'liq' => $liq, 'memo' => $memo, 'acta' => $acta,
            'emp_recibo' => $emp['tec'], 'emp_constancia' => $emp['cont'], 'usuario_impresion' => $usr['rrhh']];
}

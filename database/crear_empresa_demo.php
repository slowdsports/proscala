<?php
// ════════════════════════════════════════════════════════════════════════════
//  Proscala — Empresa de DEMOSTRACIÓN (para presentar el sistema a posibles compradores)
// ════════════════════════════════════════════════════════════════════════════
//  Crea una empresa igual que Empresas → Nueva (base propia, registro en la base central y
//  accesos) y la llena con unos 10 meses de operación ficticia de una ONG: empleados, usuarios
//  de cada puesto, proyectos con presupuesto, proveedores, activos, procesos de compra completos,
//  anticipos con vehículo y reporte de viaje, liquidaciones, caja chica, planillas quincenales,
//  de asignación y de beneficios con su distribución por proyecto, vacaciones, permisos,
//  préstamos, una liquidación laboral, asistencia, memorandos y actas. Cada proceso pasa por el
//  flujo (elaboró, revisó, autorizó) y genera sus órdenes de pago con las funciones del sistema.
//  Quedan procesos pendientes para mostrar las aprobaciones y las notificaciones.
//
//  TODOS LOS DATOS SON INVENTADOS (nombres, identidades, RTN, cuentas, montos y correos con un
//  dominio que no existe). Los usuarios no reciben correos (notificar_correo = 0).
//
//  Uso (solo en la línea de comandos, desde la raíz del sistema):
//    php database/crear_empresa_demo.php                 crea la empresa con los valores de abajo
//    php database/crear_empresa_demo.php --recrear       la borra y la vuelve a crear (datos frescos)
//    Opciones: --nombre="…" --rtn=14 dígitos --db=base --clave=contraseña de los usuarios --dominio=…
//  En el hosting (cPanel), cree antes la base vacía y asígnele el usuario de MySQL del sistema.
// ════════════════════════════════════════════════════════════════════════════
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('RAIZ', dirname(__DIR__));
chdir(RAIZ);
$_SERVER += ['SCRIPT_NAME' => '/crear_empresa_demo.php', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'localhost'];

$opc    = getopt('', ['nombre::', 'rtn::', 'db::', 'clave::', 'dominio::', 'recrear']);
$NOMBRE = $opc['nombre'] ?? 'Fundación Horizonte (DEMO)';
$RTN    = preg_replace('/\D/', '', $opc['rtn'] ?? '08019099000018');
$BD     = $opc['db'] ?? 'proscala_demo';
$CLAVE  = $opc['clave'] ?? 'Demo2026!';
$DOM    = $opc['dominio'] ?? 'horizonte.demo';
if (strlen($RTN) !== 14) exit("El RTN debe tener 14 dígitos.\n");
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $BD)) exit("Nombre de base no válido.\n");
if (strlen($CLAVE) < 6) exit("La contraseña debe tener al menos 6 caracteres.\n");

require RAIZ . '/config/db.php';
require RAIZ . '/config/esquema.php';
require RAIZ . '/config/auth.php';
require RAIZ . '/config/functions.php';

// ── La empresa: base y registro en la central ──────────────────────
$s = $pdoMaster->prepare("SELECT * FROM empresas WHERE rtn = ? OR db_nombre = ?");
$s->execute([$RTN, $BD]);
$previa = $s->fetchAll();
if ($previa && !isset($opc['recrear'])) {
    exit("Ya existe una empresa con el RTN $RTN o la base $BD. Use --recrear para borrarla y crearla de nuevo.\n");
}
foreach ($previa as $e) {
    if ($e['db_nombre'] !== $BD || $e['rtn'] !== $RTN) exit("El RTN o la base ya pertenecen a otra empresa ({$e['nombre']}). No se tocó nada.\n");
    echo "Borrando la empresa de demostración anterior ({$e['nombre']})...\n";
    $pdoMaster->prepare("DELETE FROM accesos WHERE empresa_id = ?")->execute([$e['id']]);
    $pdoMaster->prepare("DELETE FROM empresas WHERE id = ?")->execute([$e['id']]);
    borrarCarpeta(RAIZ . '/assets/uploads/adjuntos/' . (int)$e['id']);
}
try { conectarBD('')->exec("CREATE DATABASE IF NOT EXISTS `$BD` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
catch (PDOException $e) { /* hosting sin permiso para crear bases: debe existir */ }
$db = conectarBD($BD);
$tablas = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
if ($tablas) {
    if (!$previa) exit("La base $BD ya tiene tablas y no es de una empresa de demostración. Use una base vacía.\n");
    vaciarBase($db);
}

$empresaId = 0;
try {
    echo "Creando la base $BD...\n";
    foreach (['empresa_schema.sql', 'empresa_seed.sql', 'roles_modulos_inicial.sql'] as $archivo) ejecutarArchivoSQL($db, RAIZ . "/database/$archivo");
    $pdoMaster->prepare("INSERT INTO empresas (nombre, rtn, db_nombre) VALUES (?,?,?)")->execute([$NOMBRE, $RTN, $BD]);
    $empresaId = (int)$pdoMaster->lastInsertId();

    $GLOBALS['pdo'] = $pdo = $db;
    $_SESSION = ['empresa_id' => $empresaId, 'empresa_db' => $BD, 'empresa_nombre' => $NOMBRE, 'es_superadmin' => false];
    roles(true);
    $resumen = cargarDemo($db, $NOMBRE, $RTN, $CLAVE, $DOM, $empresaId);

    // Accesos de la central: con su correo y el RTN entran a esta empresa
    foreach ($resumen['usuarios'] as $u) $pdoMaster->prepare("INSERT INTO accesos (email, empresa_id) VALUES (?,?)")->execute([$u['email'], $empresaId]);
} catch (Throwable $e) {
    if ($empresaId) {
        $pdoMaster->prepare("DELETE FROM accesos WHERE empresa_id = ?")->execute([$empresaId]);
        $pdoMaster->prepare("DELETE FROM empresas WHERE id = ?")->execute([$empresaId]);
        borrarCarpeta(RAIZ . '/assets/uploads/adjuntos/' . $empresaId);
    }
    vaciarBase($db);
    exit("No se pudo crear la empresa de demostración: " . $e->getMessage() . "\n  en " . $e->getFile() . ':' . $e->getLine() . "\n");
}

echo "\nEmpresa de demostración lista: $NOMBRE\n  RTN para entrar: $RTN   ·   Base: $BD\n  Contraseña de todos los usuarios: $CLAVE\n\n";
foreach ($resumen['usuarios'] as $u) printf("  %-34s %-22s %s\n", $u['email'], $u['puesto'], $u['nombre']);
echo "\n" . implode("\n", $resumen['totales']) . "\n";
exit(0);

// ═════════════════════════════ AUXILIARES ═════════════════════════════

function vaciarBase(PDO $db): void {
    $db->exec("SET FOREIGN_KEY_CHECKS=0");
    foreach ($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_COLUMN) as $t) $db->exec("DROP TABLE `$t`");
    $db->exec("SET FOREIGN_KEY_CHECKS=1");
}

function borrarCarpeta(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}

/** "Foto" dibujada con GD (cielo, cerros y un rótulo): no es una foto real. */
function fotoDemo(string $ruta, string $texto, int $n): void {
    [$w, $h] = [1200, 800];
    $img = imagecreatetruecolor($w, $h);
    [$r, $g, $b] = [[125, 178, 222], [160, 196, 228], [110, 160, 205], [140, 186, 214]][$n % 4];
    for ($y = 0; $y < $h; $y++) {
        $k = $y / $h;
        imageline($img, 0, $y, $w, $y, imagecolorallocate($img, (int)($r + (235 - $r) * $k), (int)($g + (240 - $g) * $k), (int)($b + (240 - $b) * $k)));
    }
    $pts = fn(int $base, int $alto, int $fase) => array_merge(array_merge(...array_map(fn($x) => [$x, (int)($base - $alto * (0.5 + 0.5 * sin(($x + $fase) / 170)))], range(0, $w, 40))), [$w, $h, 0, $h]);
    imagefilledpolygon($img, $pts(520, 160, $n * 90), imagecolorallocate($img, 92, 140, 96));
    imagefilledpolygon($img, $pts(640, 110, $n * 140 + 60), imagecolorallocate($img, 64, 112, 70));
    imagefilledrectangle($img, 0, $h - 90, $w, $h, imagecolorallocate($img, 13, 63, 106));
    $blanco = imagecolorallocate($img, 255, 255, 255);
    imagestring($img, 5, 30, $h - 62, iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto), $blanco);
    imagestring($img, 3, 30, $h - 36, 'Imagen de demostracion (no es una foto real)', $blanco);
    imagejpeg($img, $ruta, 85);
    imagedestroy($img);
}

// ═════════════════════════════ DATOS DE DEMOSTRACIÓN ═════════════════════════════

function cargarDemo(PDO $db, string $nombreOrg, string $rtn, string $clave, string $dom, int $empresaId): array {
    $hoy = date('Y-m-d');
    $ins = function (string $tabla, array $fila) use ($db): int {
        $cols = array_keys($fila);
        $sql = $cols ? "INSERT INTO `$tabla` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")" : "INSERT INTO `$tabla` () VALUES ()";
        $db->prepare($sql)->execute(array_values($fila));
        return (int)$db->lastInsertId();
    };
    $upd = fn(string $tabla, int $id, array $v) => $db->prepare("UPDATE `$tabla` SET " . implode(',', array_map(fn($c) => "`$c` = ?", array_keys($v))) . " WHERE id = ?")
        ->execute([...array_values($v), $id]);
    // Fecha del mes $k meses atrás (0 = este mes), el día $dia (nunca después de hoy salvo $futuro)
    $mes = function (int $k, int $dia, bool $futuro = false) use ($hoy): string {
        $base = strtotime(date('Y-m-01') . " -$k month");
        $d = sprintf('%s-%02d', date('Y-m', $base), min($dia, (int)date('t', $base)));
        return (!$futuro && $d > $hoy) ? $hoy : $d;
    };
    $mas = fn(string $f, int $dias) => date('Y-m-d', strtotime(sprintf('%s %+d day', $f, $dias)));
    $ts  = fn(string $f, string $hora = '09:00') => "$f $hora:00";
    $anio = fn(string $f) => (int)substr($f, 0, 4);
    $pref = fn(string $k, string $d) => getConfig($db, "prefijo_$k", $d);

    // ── Organización ──
    foreach (['nombre_organizacion' => $nombreOrg, 'rtn' => $rtn,
              'nombre_completo' => 'Fundación Horizonte para el Desarrollo Comunitario — empresa de demostración con datos ficticios',
              'direccion' => 'Colonia Palmira, Avenida República de Chile, Tegucigalpa, Honduras',
              'telefono' => '+504 2232-0000', 'correo' => "info@$dom"] as $k => $v) {
        $db->prepare("INSERT INTO configuracion (clave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$k, $v]);
    }
    foreach (['Dirección', 'Administración', 'Finanzas', 'Recursos Humanos', 'Programas'] as $d) $ins('cat_departamentos', ['nombre' => $d]);

    // ── Cuentas contables ──
    $cta = [];
    foreach ([['1201', 'Mobiliario y equipo de oficina', 'activo'], ['1202', 'Equipo de cómputo', 'activo'], ['1203', 'Vehículos', 'activo'],
              ['5101', 'Sueldos y salarios', 'gasto'], ['5102', 'Cargas sociales (IHSS, RAP)', 'gasto'], ['5103', 'Viáticos y hospedaje', 'gasto'],
              ['5104', 'Transporte', 'gasto'], ['5105', 'Alimentación', 'gasto'], ['5106', 'Materiales y suministros', 'gasto'],
              ['5107', 'Papelería y útiles de oficina', 'gasto'], ['5108', 'Combustible y lubricantes', 'gasto'], ['5109', 'Servicios profesionales', 'gasto'],
              ['5110', 'Alquileres', 'gasto'], ['5111', 'Servicios públicos (energía, agua, internet)', 'gasto'], ['5112', 'Mantenimiento de vehículos', 'gasto'],
              ['5113', 'Capacitaciones y talleres', 'gasto'], ['5114', 'Impresiones y publicaciones', 'gasto'], ['5115', 'Materiales de construcción', 'gasto'],
              ['4101', 'Donaciones de cooperantes', 'ingreso']] as [$cod, $nom, $tipo]) {
        $cta[$cod] = $ins('cuentas_contables', ['codigo' => $cod, 'nombre' => $nom, 'tipo' => $tipo, 'nivel' => 1, 'activa' => 1]);
    }

    // ── Empleados ──
    // [clave, nombre, apellidos, cargo, departamento, sueldo, ingreso, contrato, banco, credimpulsa]
    $personas = [
        ['dir',   'Ana Gabriela',    'Martínez López',    'Directora Ejecutiva',               'Dirección',        48000, '2014-02-03', 'indefinido', 'BAC',       0],
        ['adm',   'Carlos Eduardo',  'Rodríguez Paz',     'Gerente Administrativo',            'Administración',   36000, '2016-06-01', 'indefinido', 'Atlántida', 0],
        ['fin',   'Patricia Elena',  'Velásquez Mejía',   'Jefa de Finanzas',                  'Finanzas',         34000, '2017-01-09', 'indefinido', 'FICOHSA',   0],
        ['cont',  'Lucía María',     'Hernández Castro',  'Contadora General',                 'Finanzas',         28000, '2018-03-12', 'indefinido', 'BAC',       0],
        ['rrhh',  'Sofía Alejandra', 'Reyes Molina',      'Oficial de Recursos Humanos',       'Recursos Humanos', 25000, '2019-08-05', 'indefinido', 'Davivienda', 0],
        ['coor1', 'Roberto José',    'Sánchez Díaz',      'Coordinador del Proyecto Agua',     'Programas',        31000, '2016-11-14', 'definido',   'Atlántida', 0],
        ['coor2', 'Karla Johana',    'Ordóñez Bustillo',  'Coordinadora del Proyecto Educación', 'Programas',      31000, '2018-05-02', 'definido',   'BAC',       0],
        ['tec1',  'Jorge Luis',      'Mejía Flores',      'Técnico de Campo',                  'Programas',        19500, '2021-01-18', 'definido',   'Banrural',  0],
        ['tec2',  'Marta Isabel',    'Cálix Ramos',       'Técnica de Campo',                  'Programas',        19500, '2021-07-01', 'definido',   'Banrural',  1],
        ['tec3',  'Luis Fernando',   'Aguilar Turcios',   'Ingeniero Agrónomo',                'Programas',        23000, '2020-02-10', 'definido',   'FICOHSA',   0],
        ['asis',  'María Fernanda',  'Zelaya Ortiz',      'Asistente Administrativa',          'Administración',   16500, '2022-04-04', 'indefinido', 'BAC',       1],
        ['comp',  'Daniel Alberto',  'Funes Carranza',    'Encargado de Compras',              'Administración',   21000, '2020-09-14', 'indefinido', 'Atlántida', 0],
        ['prom1', 'José Manuel',     'Cruz Rivera',       'Promotor Comunitario',              'Programas',        14500, '2023-09-01', 'definido',   'Banrural',  0],
        ['cond',  'Wilmer Antonio',  'Banegas Pineda',    'Conductor',                         'Administración',   13500, '2019-03-18', 'indefinido', 'Occidente', 1],
        ['prom2', 'Elvin Rolando',   'Maradiaga Sosa',    'Promotor Comunitario',              'Programas',        14500, '2023-02-06', 'definido',   'Banrural',  0],
        ['pas',   'Andrea Paola',    'Midence Lagos',     'Asistente de Proyectos (nuevo ingreso)', 'Programas',   15000, $mes(0, 3), 'definido',   'BAC',       0],
    ];
    $techo = (float)getConfig($db, 'ihss_techo_mensual', '11903.13');
    // Deducciones por quincena (el sistema las guarda por empleado y las aplica en cada planilla)
    $deducciones = function (float $sueldo, bool $credi) use ($techo): array {
        return ['ded_ihss_empleado' => round(min($sueldo, $techo) * 0.025 / 2, 2), 'ded_ihss_patronal' => round(min($sueldo, $techo) * 0.05 / 2, 2),
                'ded_rap' => round(max($sueldo - $techo, 0) * 0.015 / 2, 2), 'ded_isr' => calcularISRQuincenal($sueldo / 2),
                'ded_credimpulsa' => $credi ? 450.0 : 0.0];
    };
    $emp = []; $info = [];
    foreach ($personas as $i => [$k, $nom, $ape, $cargo, $dep, $sueldo, $ingreso, $contrato, $banco, $credi]) {
        $n = $i + 1;
        $info[$k] = ['sueldo' => $sueldo, 'ingreso' => $ingreso, 'egreso' => null, 'credi' => (bool)$credi, 'nombre' => "$nom $ape"];
        $emp[$k] = $ins('empleados', ['codigo' => sprintf('EMP-%03d', $n), 'nombre' => $nom, 'apellidos' => $ape,
            'identidad' => sprintf('0801-19%02d-%05d', 70 + $n, 10000 + $n * 37), 'rtn' => sprintf('08011980%06d', 100 + $n),
            'cargo' => $cargo, 'departamento' => $dep, 'tipo_contrato' => $contrato, 'fecha_ingreso' => $ingreso, 'sueldo_mensual' => $sueldo,
            'banco' => $banco, 'cuenta_banco' => sprintf('2%09d', 100200 + $n * 7), 'aplica_ihss' => 1, 'aplica_rap' => 1, 'aplica_isr' => 1,
            'aplica_credimpulsa' => $credi, 'email' => "empleado$n@$dom", 'telefono' => sprintf('+504 9%03d-%04d', 800 + $n, 1000 + $n * 13),
            'direccion' => ['Colonia Kennedy', 'Barrio La Granja', 'Colonia Miraflores', 'Residencial Las Hadas', 'Colonia Hato de Enmedio'][$n % 5] . ', Tegucigalpa',
            'activo' => 1, 'created_at' => $ts($ingreso)] + $deducciones($sueldo, (bool)$credi));
    }
    foreach (array_unique(array_column($personas, 3)) as $c) $ins('cat_cargos', ['nombre' => $c]);

    // Aumentos de salario (historial) y salida de un promotor (liquidación laboral más adelante)
    $aumentos = [['tec1', 18000, 19500, $mes(4, 1)], ['coor2', 29000, 31000, $mes(6, 1)]];
    $info['prom2']['egreso'] = $mes(3, 31);
    $sueldoEn = function (string $k, string $fecha) use ($info, $aumentos): float {
        foreach ($aumentos as [$e, $antes, , $desde]) if ($e === $k && $fecha < $desde) return $antes;
        return $info[$k]['sueldo'];
    };

    // ── Usuarios (todos con la misma contraseña; sin correos) ──
    $usuariosDef = [
        ['admin', 'Administrador del sistema', "admin@$dom",         'admin',               null],
        ['dir',   null,                         "direccion@$dom",     'direccion_ejecutiva', 'dir'],
        ['adm',   null,                         "administracion@$dom",'administracion',      'adm'],
        ['fin',   null,                         "finanzas@$dom",      'finanzas',            'fin'],
        ['cont',  null,                         "contabilidad@$dom",  'contabilidad',        'cont'],
        ['rrhh',  null,                         "rrhh@$dom",          'recursos_humanos',    'rrhh'],
        ['comp',  null,                         "compras@$dom",       'administracion',      'comp'],
        ['asis',  null,                         "asistente@$dom",     'administracion',      'asis'],
        ['coor1', null,                         "coordinador@$dom",   'empleado',            'coor1'],
        ['tec1',  null,                         "tecnico@$dom",       'empleado',            'tec1'],
        ['junta', 'Presidente de la Junta Directiva (demo)', "junta@$dom", 'junta_directiva', null],
    ];
    $usr = []; $usrInfo = [];
    $hash = password_hash($clave, PASSWORD_BCRYPT);
    foreach ($usuariosDef as [$k, $nombre, $email, $rol, $ek]) {
        $nombre ??= $info[$ek]['nombre'];
        $usr[$k] = $ins('usuarios', ['nombre' => $nombre, 'email' => $email, 'password' => $hash, 'rol' => $rol, 'empleado_id' => $ek ? $emp[$ek] : null,
            'activo' => 1, 'pendiente_activacion' => 0, 'debe_cambiar_password' => 0, 'notificar_correo' => 0]);
        $usrInfo[$k] = ['email' => $email, 'nombre' => $nombre, 'puesto' => rolLabel($rol)];
    }
    // Facultades de aprobación: Dirección autoriza; Administración revisa, autoriza y corrige; Finanzas y Contabilidad revisan
    foreach (['dir' => ['revisar', 'autorizar', 'corregir'], 'adm' => ['revisar', 'autorizar', 'corregir'], 'fin' => ['revisar'], 'cont' => ['revisar']] as $k => $fs) {
        foreach ($fs as $f) $ins('usuario_facultades', ['usuario_id' => $usr[$k], 'facultad' => $f, 'alcance' => 'todos']);
    }
    $como = function (string $k) use ($usr, $usrInfo, $emp) {
        $_SESSION['usuario_id'] = $usr[$k]; $_SESSION['usuario_nombre'] = $usrInfo[$k]['nombre'];
        $_SESSION['usuario_email'] = $usrInfo[$k]['email']; $_SESSION['empleado_id'] = $emp[$k] ?? null;
    };
    $como('admin');

    // Firmas configuradas de los documentos
    foreach (['sc', 'oc', 'op', 'pl', 'pa', 'pb', 'cc', 'liq'] as $doc) {
        foreach (['Elaborado por', 'Revisado por', 'Autorizado por'] as $i => $et) $ins('firmantes', ['documento' => $doc, 'posicion' => $i + 1, 'etiqueta' => $et]);
    }
    foreach (['gv', 'sg', 'vac', 'per'] as $doc) {
        foreach (['Solicitado por', 'Revisado por', 'Autorizado por'] as $i => $et) $ins('firmantes', ['documento' => $doc, 'posicion' => $i + 1, 'etiqueta' => $et]);
    }
    $ins('firmantes', ['documento' => 'arv', 'posicion' => 1, 'empleado_id' => $emp['cond'], 'etiqueta' => 'Entregado por (encargado de vehículos)']);

    // ── Flujo de aprobación y bitácora ──
    $bitacora = function (string $k, string $accion, string $modulo, string $tabla, int $id, string $cuando, string $detalle) use ($db, $usr, $usrInfo) {
        $db->prepare("INSERT INTO auditoria (usuario_id, email, accion, modulo, tabla_afectada, registro_id, resultado, ip, detalle, created_at) VALUES (?,?,?,?,?,?,'ok','10.0.0.15',?,?)")
           ->execute([$usr[$k], $usrInfo[$k]['email'], $accion, $modulo, $tabla, $id, $detalle, $cuando]);
    };
    // Creó $creador el $fecha; revisó / autorizó / rechazó (con motivo) en los días siguientes
    $flujo = function (string $modulo, int $id, string $fecha, string $creador, ?string $revisor = null, ?string $autoriza = null, ?array $rechazo = null)
        use ($db, $usr, $ts, $mas, $bitacora) {
        $tabla = PROCESOS[$modulo]['tabla'] ?? EDICION_PROCESOS[$modulo]['tabla'];
        $db->prepare("INSERT IGNORE INTO registro_usuarios (tabla, registro_id, usuario_id, participacion, created_at) VALUES (?,?,?,'creador',?)")
           ->execute([$tabla, $id, $usr[$creador], $ts($fecha, '08:40')]);
        $bitacora($creador, 'INSERT', $modulo, $tabla, $id, $ts($fecha, '08:40'), 'Registro creado');
        $paso = function (string $p, string $k, string $cuando) use ($db, $tabla, $id, $usr) {
            $db->prepare("INSERT INTO aprobaciones (tabla, registro_id, paso, usuario_id, created_at) VALUES (?,?,?,?,?)")->execute([$tabla, $id, $p, $usr[$k], $cuando]);
        };
        if ($revisor) { $c = $ts(min($mas($fecha, 1), date('Y-m-d')), '10:15'); $paso('revisado', $revisor, $c); $bitacora($revisor, 'REVISAR', $modulo, $tabla, $id, $c, 'Revisado'); }
        if ($autoriza) { $c = $ts(min($mas($fecha, 2), date('Y-m-d')), '11:30'); $paso('autorizado', $autoriza, $c); $bitacora($autoriza, 'AUTORIZAR', $modulo, $tabla, $id, $c, 'Autorizado'); }
        if ($rechazo) {
            [$k, $motivo] = $rechazo;
            $c = $ts(min($mas($fecha, 1), date('Y-m-d')), '15:20');
            $paso('rechazado', $k, $c);
            $db->prepare("INSERT INTO rechazos (tabla, registro_id, usuario_id, motivo, created_at) VALUES (?,?,?,?,?)")->execute([$tabla, $id, $usr[$k], $motivo, $c]);
            $bitacora($k, 'RECHAZAR', $modulo, $tabla, $id, $c, "Rechazado: $motivo");
        }
    };
    $pendientes = [];   // [modulo, id, paso, creador]: avisos al final

    // Orden de pago automática de un proceso (con las funciones del sistema) y su flujo hasta $estado
    $ordenPago = function (string $tabla, int $id, string $fecha, string $estado = 'pagada', bool $reembolso = false)
        use ($db, $como, $flujo, $mas, $ts, &$pendientes): ?int {
        $como('cont');
        $num = generarOrdenPago($db, $tabla, $id, $reembolso);
        if (!$num) return null;
        $opId = (int)$db->query("SELECT id FROM ordenes_pago WHERE numero = " . $db->quote($num))->fetchColumn();
        $db->prepare("DELETE FROM registro_usuarios WHERE tabla = 'ordenes_pago' AND registro_id = ?")->execute([$opId]);
        $db->prepare("UPDATE ordenes_pago SET fecha = ?, created_at = ?, estado = ?, fecha_pago = ?, numero_cheque = NULL WHERE id = ?")
           ->execute([$fecha, $ts($fecha, '14:00'), $estado === 'revisada' ? 'pendiente' : $estado, $estado === 'pagada' ? $mas($fecha, 2) : null, $opId]);
        match ($estado) {
            'pagada', 'aprobada' => $flujo('compras_pago.php', $opId, $fecha, 'cont', 'fin', 'dir'),
            'revisada' => $flujo('compras_pago.php', $opId, $fecha, 'cont', 'fin'),
            default    => $flujo('compras_pago.php', $opId, $fecha, 'cont'),
        };
        if ($estado === 'pendiente') $pendientes[] = ['compras_pago.php', $opId, 'revisar', 'cont'];
        if ($estado === 'revisada')  $pendientes[] = ['compras_pago.php', $opId, 'autorizar', 'cont'];
        if ($estado === 'pagada' && $db->query("SELECT COUNT(*) FROM ordenes_pago WHERE fecha_pago IS NOT NULL AND id = $opId")->fetchColumn()) {
            $db->prepare("UPDATE ordenes_pago SET fecha_pago = LEAST(fecha_pago, CURDATE()) WHERE id = ?")->execute([$opId]);
        }
        return $opId;
    };

    // ── Proyectos y presupuesto ──
    $A = (int)date('Y');
    $proy = [];
    foreach ([['agua', 'PRY-001', 'Agua Segura para Comunidades Rurales', 'Cooperante Internacional A (ficticio)', 'Sistemas de agua potable y saneamiento en 12 comunidades de Intibucá y Lempira.', 2400000, 'coor1'],
              ['edu',  'PRY-002', 'Educación Rural Inclusiva',             'Fundación Europea B (ficticia)',       'Formación docente y materiales educativos para 30 escuelas rurales.',          1650000, 'coor2'],
              ['agro', 'PRY-003', 'Seguridad Alimentaria y Huertos Familiares', 'Agencia de Cooperación C (ficticia)', 'Huertos familiares, semilla mejorada y asistencia técnica a 400 familias.',      980000, 'tec3']] as [$k, $cod, $nom, $don, $desc, $ppto, $resp]) {
        $proy[$k] = $ins('proyectos', ['codigo' => $cod, 'nombre' => $nom, 'donante' => $don, 'descripcion' => $desc, 'fecha_inicio' => ($A - 1) . '-01-01',
            'fecha_fin' => ($A + 1) . '-12-31', 'presupuesto_total' => $ppto, 'moneda' => 'HNL', 'responsable_id' => $emp[$resp], 'estado' => 'activo']);
    }
    $presupuesto = ['agua' => ['5101' => 620000, '5102' => 70000, '5103' => 95000, '5104' => 40000, '5105' => 45000, '5108' => 60000, '5115' => 380000, '5113' => 55000],
                    'edu'  => ['5101' => 480000, '5102' => 52000, '5103' => 60000, '5106' => 70000, '5107' => 85000, '5114' => 120000, '5113' => 90000],
                    'agro' => ['5101' => 260000, '5102' => 30000, '5103' => 40000, '5106' => 110000, '5108' => 35000, '5113' => 40000],
                    null   => ['5101' => 520000, '5102' => 58000, '5110' => 144000, '5111' => 60000, '5107' => 30000, '5112' => 45000, '5109' => 80000]];
    foreach ($presupuesto as $p => $lineas) foreach ($lineas as $c => $m) {
        $ins('presupuesto', ['anio' => $A, 'proyecto_id' => $p === '' || $p === null ? null : $proy[$p], 'cuenta_id' => $cta[$c], 'monto_presupuestado' => $m]);
    }
    // Distribución del salario de cada empleado entre proyectos (sin proyecto = gasto general)
    $reparto = ['coor1' => ['agua' => 100], 'coor2' => ['edu' => 100], 'tec1' => ['agua' => 100], 'tec2' => ['agua' => 60, 'agro' => 40], 'tec3' => ['agro' => 100],
                'prom1' => ['edu' => 50, 'agro' => 50], 'prom2' => ['agua' => 100], 'pas' => ['edu' => 100], 'dir' => ['agua' => 30, 'edu' => 30, 'agro' => 20],
                'adm' => ['agua' => 25, 'edu' => 25], 'fin' => ['agua' => 25, 'edu' => 25], 'cont' => ['agua' => 20, 'edu' => 20, 'agro' => 10], 'cond' => ['agua' => 50, 'agro' => 30]];
    foreach ($reparto as $k => $ps) foreach ($ps as $p => $pct) $ins('empleado_proyectos', ['empleado_id' => $emp[$k], 'proyecto_id' => $proy[$p], 'pct_salario' => $pct, 'pct_patronal' => $pct]);

    // ── Proveedores (y un consultor con acceso) ──
    $prov = [];
    foreach ([['libros', 'Distribuidora Escolar Demo S.A.',          'bienes',    'Lic. Rosa Elvir',      'BAC'],
              ['ferre',  'Ferretería El Constructor Demo',           'bienes',    'Sr. Óscar Lagos',      'Atlántida'],
              ['tec',    'Tecnología y Sistemas Demo S. de R.L.',    'bienes',    'Ing. Fabiola Castro',  'FICOHSA'],
              ['impr',   'Imprenta Rápida Demo',                     'ambos',     'Sr. Hugo Matamoros',   'BAC'],
              ['trans',  'Transportes Montaña Demo',                 'servicios', 'Sr. Nelson Varela',    'Banrural'],
              ['agro',   'Agroinsumos del Valle Demo',               'bienes',    'Ing. Celeste Mendoza', 'Davivienda'],
              ['tanq',   'Plásticos y Tanques Demo S.A.',            'bienes',    'Lic. Iván Rosales',    'Occidente']] as $i => [$k, $nom, $cat, $contacto, $banco]) {
        $prov[$k] = $ins('proveedores', ['codigo' => sprintf('PROV-%03d', $i + 1), 'nombre' => $nom, 'rtn' => sprintf('0801900%07d', 4100 + $i * 11),
            'contacto' => $contacto, 'telefono' => sprintf('+504 22%02d-%04d', 30 + $i, 4500 + $i * 21), 'email' => "ventas$i@proveedor.demo",
            'direccion' => 'Tegucigalpa, Honduras (dirección de demostración)', 'categoria' => $cat, 'activo' => 1, 'banco' => $banco,
            'banco_cuenta' => sprintf('7%09d', 300400 + $i * 9), 'banco_tipo' => 'cheques']);
    }
    $prov['cons'] = $ins('proveedores', ['codigo' => 'PROV-008', 'nombre' => 'Mario Andrés Fúnez Paz (consultor)', 'identidad' => '0801-1985-07771',
        'rtn' => '08011985077710', 'contacto' => 'Mario Andrés Fúnez Paz', 'telefono' => '+504 9455-1200', 'email' => "consultor@$dom",
        'direccion' => 'Comayagüela, Honduras', 'categoria' => 'consultor', 'activo' => 1, 'banco' => 'BAC', 'banco_cuenta' => '7300999001', 'banco_tipo' => 'ahorros']);
    $ins('proveedor_proyectos', ['proveedor_id' => $prov['cons'], 'proyecto_id' => $proy['agua']]);
    $usr['cons'] = $ins('usuarios', ['nombre' => 'Mario Andrés Fúnez Paz', 'email' => "consultor@$dom", 'password' => $hash, 'rol' => 'consultor',
        'proveedor_id' => $prov['cons'], 'activo' => 1, 'pendiente_activacion' => 0, 'debe_cambiar_password' => 0, 'notificar_correo' => 0]);
    $usrInfo['cons'] = ['email' => "consultor@$dom", 'nombre' => 'Mario Andrés Fúnez Paz', 'puesto' => 'Consultor'];

    // ── Activos ──
    $activo = function (string $cod, string $desc, string $cat, string $fecha, float $costo, int $vida, string $resp, string $ubic, string $serie = '', ?string $p = null) use ($ins, $emp, $prov) {
        $anios = max(0, (time() - strtotime($fecha)) / 31557600);
        $ins('activos_fijos', ['codigo' => $cod, 'descripcion' => $desc, 'categoria' => $cat, 'fecha_adquisicion' => $fecha, 'costo_adquisicion' => $costo,
            'vida_util_anios' => $vida, 'porcentaje_depreciacion' => round(100 / $vida, 2), 'valor_residual' => round($costo * 0.1, 2),
            'valor_libro' => round(max($costo * 0.1, $costo - $costo * 0.9 * $anios / $vida), 2), 'ubicacion' => $ubic,
            'responsable_id' => $emp[$resp], 'numero_serie' => $serie, 'proveedor_id' => $p ? $prov[$p] : null, 'estado' => 'activo']);
    };
    $activo('VEH-001', 'Pick-up Toyota Hilux doble cabina 4x4 (placa demo PDA-1234)', 'Vehículos', '2022-03-15', 980000, 5, 'cond', 'Oficina central', 'VIN-DEMO-0001');
    $activo('VEH-002', 'Pick-up Nissan Frontier doble cabina 4x4 (placa demo PDB-5678)', 'Vehículos', '2023-08-01', 920000, 5, 'coor1', 'Oficina regional La Esperanza', 'VIN-DEMO-0002');
    $activo('VEH-003', 'Motocicleta Honda XR150 (placa demo M-90123)', 'Vehículos', '2024-02-20', 68000, 5, 'tec1', 'Oficina regional La Esperanza', 'VIN-DEMO-0003');
    $activo('MOB-001', 'Escritorios ejecutivos (lote de 8)', 'Mobiliario y Equipo de Oficina', '2019-01-20', 64000, 10, 'adm', 'Oficina central');
    $activo('MOB-002', 'Mesa de reuniones con 12 sillas', 'Mobiliario y Equipo de Oficina', '2020-06-10', 38500, 10, 'adm', 'Sala de reuniones');
    $activo('EQ-001', 'Proyector multimedia 4000 lúmenes', 'Equipo de Cómputo', '2023-05-04', 21500, 3, 'coor2', 'Sala de reuniones', 'PJ-DEMO-77');
    $activo('EQ-002', 'Planta eléctrica 7.5 kW', 'Equipo de Campo', '2021-11-22', 45000, 8, 'cond', 'Bodega', 'GEN-DEMO-12');
    $activo('EQ-003', 'Kit de análisis de calidad de agua', 'Equipo de Campo', '2024-07-08', 32000, 5, 'tec1', 'Oficina regional La Esperanza', 'KIT-DEMO-5');

    // ═══════════ Procesos, en orden de fecha (la numeración común sigue el calendario) ═══════════
    $eventos = [];
    $en = function (string $fecha, callable $fn) use (&$eventos) { $eventos[] = [$fecha, count($eventos), $fn]; };

    // ── Planillas de sueldos: quincenales, pagadas; la del período en curso, pendiente ──
    $planilla = function (int $an, int $m, string $q, string $estado) use ($db, $ins, $upd, $info, $emp, $sueldoEn, $deducciones, $flujo, $ordenPago, $mas, $ts, $pref, &$pendientes): void {
        $ini = sprintf('%04d-%02d-%s', $an, $m, $q === 'primera' ? '01' : '16');
        $fin = $q === 'primera' ? sprintf('%04d-%02d-15', $an, $m) : date('Y-m-t', strtotime($ini));
        $per = ['tipo' => 'quincenal', 'quincena' => $q, 'anio' => $an, 'mes' => $m, 'fecha_inicio' => $ini, 'fecha_fin' => $fin];
        $creada = min($mas($fin, -3), date('Y-m-d'));
        $pid = $ins('planilla_periodos', ['numero' => numeroProceso($db, 'planilla_periodos', $pref('pl', 'PL'), null, $an), 'anio' => $an, 'mes' => $m,
            'quincena' => $q, 'tipo' => 'quincenal', 'fecha_inicio' => $ini, 'fecha_fin' => $fin, 'fecha_pago' => $fin, 'estado' => 'procesada', 'created_at' => $ts($creada, '08:00')]);
        $tb = $td = $tn = 0;
        foreach ($info as $k => $e) {
            if ($e['ingreso'] > $fin || ($e['egreso'] && $e['egreso'] < $ini)) continue;
            $dias = diasTrabajadosPlanilla($per, $e['ingreso']);
            if (!$dias) continue;
            $sueldo = $sueldoEn($k, $fin);
            $d = $deducciones($sueldo, $e['credi']);
            $bruto = round($sueldo / 30 * $dias, 2);
            $ded = $d['ded_ihss_empleado'] + $d['ded_rap'] + $d['ded_isr'] + $d['ded_credimpulsa'];
            $did = $ins('planilla_detalle', ['periodo_id' => $pid, 'empleado_id' => $emp[$k], 'dias_trabajados' => $dias, 'sueldo_quincenal' => $bruto, 'total_bruto' => $bruto,
                'ihss_empleado' => $d['ded_ihss_empleado'], 'rap_empleado' => $d['ded_rap'], 'isr' => $d['ded_isr'], 'credimpulsa' => $d['ded_credimpulsa'],
                'total_deducciones' => $ded, 'sueldo_neto' => round($bruto - $ded, 2), 'ihss_patronal' => $d['ded_ihss_patronal'], 'rap_patronal' => $d['ded_rap'],
                'observacion' => $dias < 15 ? "Ingresó el " . fmtFecha($e['ingreso']) : null]);
            $db->prepare("INSERT INTO planilla_detalle_proyectos (detalle_id, proyecto_id, pct_salario, pct_patronal) SELECT ?, proyecto_id, pct_salario, pct_patronal FROM empleado_proyectos WHERE empleado_id = ?")
               ->execute([$did, $emp[$k]]);
            $tb += $bruto; $td += $ded; $tn += round($bruto - $ded, 2);
        }
        $upd('planilla_periodos', $pid, ['total_bruto' => round($tb, 2), 'total_deducciones' => round($td, 2), 'total_neto' => round($tn, 2)]);
        if ($estado === 'procesada') {
            $flujo('planillas.php', $pid, $creada, 'rrhh');
            $pendientes[] = ['planillas.php', $pid, 'revisar', 'rrhh'];
            return;
        }
        if ($estado === 'revisada') {
            $flujo('planillas.php', $pid, $creada, 'rrhh', 'adm');
            $pendientes[] = ['planillas.php', $pid, 'autorizar', 'rrhh'];
            return;
        }
        $flujo('planillas.php', $pid, $creada, 'rrhh', 'adm', 'dir');
        $upd('planilla_periodos', $pid, ['estado' => 'aprobada']);
        $ordenPago('planilla_periodos', $pid, $mas($creada, 2), $estado === 'pagada' ? 'pagada' : 'pendiente');
        if ($estado === 'pagada') $upd('planilla_periodos', $pid, ['estado' => 'pagada']);
    };
    $diaHoy = (int)date('j');
    for ($k = 9; $k >= 0; $k--) {
        $base = strtotime(date('Y-m-01') . " -$k month");
        [$an, $m] = [(int)date('Y', $base), (int)date('n', $base)];
        if ($k > 0) {
            $en($mes($k, 12), fn() => $planilla($an, $m, 'primera', 'pagada'));
            $en($mes($k, 27), fn() => $planilla($an, $m, 'segunda', 'pagada'));
        } else {
            $en($mes(0, 12), fn() => $planilla($an, $m, 'primera', $diaHoy >= 15 ? 'pagada' : 'procesada'));
            if ($diaHoy >= 20) $en($mes(0, 27), fn() => $planilla($an, $m, 'segunda', 'revisada'));
        }
    }

    // ── Planillas de asignación (trabajo de campo del proyecto Agua) ──
    $asignacion = function (int $k, string $estado) use ($db, $ins, $upd, $emp, $proy, $mes, $flujo, $ordenPago, $pref, $anio, $mas, $ts, &$pendientes) {
        $f = $mes($k, 25);
        $base = strtotime($f);
        $num = numeroProceso($db, 'planilla_asignacion', $pref('pa', 'PA'), null, $anio($f));
        $id = $ins('planilla_asignacion', ['numero' => $num, 'anio' => (int)date('Y', $base), 'mes' => (int)date('n', $base),
            'descripcion' => 'Asignación por trabajo de campo en comunidades (proyecto Agua)', 'proyecto_id' => $proy['agua'], 'estado' => 'borrador', 'created_at' => $ts($f)]);
        $total = 0;
        foreach ([['tec1', 'Asignación de campo: 14 días en comunidades', 2800], ['tec2', 'Asignación de campo: 12 días en comunidades', 2400],
                  ['prom1', 'Asignación de campo: 10 días en comunidades', 2000], ['cond', 'Asignación por conducción en giras', 1500]] as [$e, $c, $mto]) {
            $ins('planilla_asignacion_detalle', ['asignacion_id' => $id, 'empleado_id' => $emp[$e], 'concepto' => $c, 'monto' => $mto]);
            $total += $mto;
        }
        $upd('planilla_asignacion', $id, ['total' => $total]);
        if ($estado === 'borrador') { $flujo('planillas_asignacion.php', $id, $f, 'rrhh'); $pendientes[] = ['planillas_asignacion.php', $id, 'revisar', 'rrhh']; return; }
        $flujo('planillas_asignacion.php', $id, $f, 'rrhh', 'adm', 'dir');
        $upd('planilla_asignacion', $id, ['estado' => 'aprobada']);
        $ordenPago('planilla_asignacion', $id, $mas($f, 2));
        $upd('planilla_asignacion', $id, ['estado' => 'pagada']);
    };
    foreach ([5, 4, 3, 2, 1] as $k) $en($mes($k, 25), fn() => $asignacion($k, 'pagada'));
    $en($mes(0, 22), fn() => $asignacion(0, 'borrador'));

    // ── Planillas de beneficios: décimo cuarto (junio) y décimo tercero (diciembre, en preparación) ──
    $beneficio = function (string $tipo, int $an, string $fechaPago, string $estado) use ($db, $ins, $upd, $info, $emp, $sueldoEn, $flujo, $ordenPago, $pref, $mas, $ts, $proy, $reparto, &$pendientes) {
        $num = numeroProceso($db, 'planilla_beneficios', $pref('pb', 'PB'), null, $an);
        $creada = min($mas($fechaPago, -6), date('Y-m-d'));
        $id = $ins('planilla_beneficios', ['numero' => $num, 'tipo' => $tipo, 'anio' => $an, 'fecha_pago' => $fechaPago, 'estado' => 'borrador',
            'notas' => $tipo === 'decimo_cuarto' ? 'Décimo cuarto mes de salario (julio a junio).' : 'Décimo tercer mes de salario (enero a diciembre).', 'created_at' => $ts($creada)]);
        $desde = $tipo === 'decimo_cuarto' ? ($an - 1) . '-07-01' : "$an-01-01";
        $tb = 0;
        foreach ($info as $k => $e) {
            if ($e['ingreso'] > $fechaPago || ($e['egreso'] && $e['egreso'] < $fechaPago)) continue;
            $sueldo = $sueldoEn($k, $fechaPago);
            $meses = min(12, max(0, (int)floor((strtotime($fechaPago) - strtotime(max($desde, $e['ingreso']))) / 2629800) + 1));
            $monto = round($sueldo * $meses / 12, 2);
            if ($monto <= 0) continue;
            $did = $ins('planilla_beneficios_detalle', ['beneficio_id' => $id, 'empleado_id' => $emp[$k], 'sueldo_mensual' => $monto, 'deduccion' => 0, 'neto' => $monto]);
            foreach ($reparto[$k] ?? [] as $p => $pct) $ins('planilla_beneficios_detalle_proyectos', ['detalle_id' => $did, 'proyecto_id' => $proy[$p], 'porcentaje' => $pct]);
            $tb += $monto;
        }
        $upd('planilla_beneficios', $id, ['total_bruto' => $tb, 'total_deducciones' => 0, 'total_neto' => $tb]);
        if ($estado === 'borrador') { $flujo('planillas_beneficios.php', $id, $creada, 'rrhh'); $pendientes[] = ['planillas_beneficios.php', $id, 'revisar', 'rrhh']; return; }
        $flujo('planillas_beneficios.php', $id, $creada, 'rrhh', 'adm', 'dir');
        $upd('planilla_beneficios', $id, ['estado' => 'aprobada']);
        $ordenPago('planilla_beneficios', $id, $mas($creada, 3));
        $upd('planilla_beneficios', $id, ['estado' => 'pagada']);
    };
    $junio = ($A . '-06-14') <= $hoy ? $A . '-06-14' : ($A - 1) . '-06-14';
    if ($junio >= $mes(9, 1)) $en($junio, fn() => $beneficio('decimo_cuarto', (int)substr($junio, 0, 4), $junio, 'pagada'));
    if ((int)date('n') >= 11) $en($mes(0, 20), fn() => $beneficio('decimo_tercero', $A, "$A-12-15", 'borrador'));

    // ── Compras: solicitud → cotizaciones → orden de compra → recepción → orden de pago ──
    $compra = function (array $c) use ($db, $ins, $upd, $emp, $prov, $proy, $cta, $flujo, $ordenPago, $pref, $anio, $mas, $ts, &$pendientes) {
        $f = $c['fecha'];
        $items = $c['items'];   // [descripción, unidad, cantidad, precio estimado]
        $sc = $ins('solicitud_compra', ['numero' => numeroProceso($db, 'solicitud_compra', $pref('sc', 'SC'), null, $anio($f)), 'fecha' => $f, 'solicitante_id' => $emp[$c['solicita']],
            'proyecto_id' => $c['proy'] ? $proy[$c['proy']] : null, 'descripcion' => $c['desc'], 'justificacion' => $c['just'], 'urgencia' => $c['urgencia'] ?? 'normal',
            'presupuesto_estimado' => array_sum(array_map(fn($i) => $i[2] * $i[3], $items)), 'estado' => 'pendiente', 'created_at' => $ts($f)]);
        $scDet = [];
        foreach ($items as [$d, $u, $q, $p]) $scDet[] = $ins('solicitud_compra_detalle', ['solicitud_id' => $sc, 'descripcion' => $d, 'unidad' => $u, 'cantidad' => $q, 'precio_estimado' => $p]);
        $hasta = $c['hasta'];
        if ($hasta === 'pendiente') { $flujo('compras_solicitud.php', $sc, $f, $c['creador']); $pendientes[] = ['compras_solicitud.php', $sc, 'revisar', $c['creador']]; return; }
        if ($hasta === 'rechazada') { $flujo('compras_solicitud.php', $sc, $f, $c['creador'], null, null, ['adm', $c['motivo']]); $upd('solicitud_compra', $sc, ['estado' => 'rechazada']); return; }
        $flujo('compras_solicitud.php', $sc, $f, $c['creador'], 'adm', 'dir');
        $upd('solicitud_compra', $sc, ['estado' => 'aprobada', 'aprobador_id' => $emp['dir'], 'fecha_aprobacion' => $mas($f, 2)]);

        // Cotizaciones de tres proveedores; el primero de la lista ofrece el mejor precio
        $fc = $mas($f, 5);
        $numSc = $db->query("SELECT numero FROM solicitud_compra WHERE id = $sc")->fetchColumn();
        $co = $ins('cotizaciones', ['numero' => numeroProceso($db, 'cotizaciones', $pref('co', 'CO'), $numSc), 'solicitud_id' => $sc, 'fecha' => $fc, 'elaborado_por_id' => $emp['comp'],
            'proveedor_seleccionado_id' => $prov[$c['provs'][0]], 'justificacion' => 'Ofrece el menor precio total, cumple las especificaciones y entrega a tiempo.',
            'estado' => 'pendiente', 'created_at' => $ts($fc)]);
        $upd('solicitud_compra', $sc, ['estado' => 'cotizando']);
        $coItems = [];
        foreach ($items as $i => [$d, $u, $q]) $coItems[] = $ins('cotizaciones_items', ['cotizacion_id' => $co, 'solicitud_detalle_id' => $scDet[$i], 'orden' => $i + 1, 'descripcion' => $d, 'unidad' => $u, 'cantidad' => $q]);
        $precios = [];
        foreach ($c['provs'] as $j => $pk) {
            $tot = 0;
            $cp = $ins('cotizaciones_proveedores', ['cotizacion_id' => $co, 'proveedor_id' => $prov[$pk], 'num_cotizacion' => sprintf('COT-%04d', 300 + $sc * 3 + $j),
                'fecha_cotizacion' => $mas($f, 3 + $j), 'condiciones' => ['Crédito 15 días', 'Contado', 'Crédito 30 días'][$j], 'tiempo_entrega' => ['3 días', '5 días', '8 días'][$j]]);
            foreach ($items as $i => [, , $q, $p]) {
                $pu = round($p * [0.95, 1.03, 1.08][$j] * (1 + (($i + $j) % 3 - 1) * 0.01), 2);
                if ($j === 0) $precios[$i] = $pu;
                $ins('cotizaciones_precios', ['item_id' => $coItems[$i], 'cotizacion_proveedor_id' => $cp, 'precio_unitario' => $pu]);
                $tot += $pu * $q;
            }
            $upd('cotizaciones_proveedores', $cp, ['total' => round($tot, 2)]);
        }
        if ($hasta === 'cotizando') { $flujo('compras_cotizaciones.php', $co, $fc, 'comp'); $pendientes[] = ['compras_cotizaciones.php', $co, 'revisar', 'comp']; return; }
        $flujo('compras_cotizaciones.php', $co, $fc, 'comp', 'adm', 'dir');
        $upd('cotizaciones', $co, ['estado' => 'aprobada', 'aprobador_id' => $emp['dir']]);

        // Orden de compra al proveedor seleccionado (ISV 15 %)
        $fo = $mas($fc, 3);
        $sub = 0;
        foreach ($items as $i => [, , $q]) $sub += round($precios[$i] * $q, 2);
        $isv = $c['isv'] ?? true ? round($sub * 0.15, 2) : 0;
        $oc = $ins('ordenes_compra', ['numero' => numeroProceso($db, 'ordenes_compra', $pref('oc', 'OC'), $numSc), 'cotizacion_id' => $co, 'solicitud_id' => $sc,
            'proveedor_id' => $prov[$c['provs'][0]], 'fecha' => $fo, 'fecha_entrega' => $mas($fo, 5), 'condiciones_pago' => 'Crédito 15 días',
            'lugar_entrega' => $c['lugar'] ?? 'Oficina central, Colonia Palmira, Tegucigalpa', 'subtotal' => $sub, 'impuestos' => $isv, 'total' => $sub + $isv,
            'proyecto_id' => $c['proy'] ? $proy[$c['proy']] : null, 'estado' => 'emitida', 'aprobador_id' => $emp['dir'], 'created_at' => $ts($fo)]);
        foreach ($items as $i => [$d, $u, $q]) $ins('ordenes_compra_detalle', ['orden_id' => $oc, 'descripcion' => $d, 'unidad' => $u, 'cantidad' => $q,
            'precio_unitario' => $precios[$i], 'total' => round($precios[$i] * $q, 2), 'cuenta_id' => $cta[$c['cuenta']]]);
        $upd('solicitud_compra', $sc, ['estado' => 'adjudicada']);
        $flujo('compras_orden.php', $oc, $fo, 'comp', 'adm', 'dir');
        // La orden de pago va antes de la recepción
        $opEstado = $c['op'] ?? 'pagada';
        $ordenPago('ordenes_compra', $oc, $mas($fo, 2), $opEstado);
        if ($opEstado !== 'pagada') return;
        $fr = $mas($fo, 6);
        $parcial = !empty($c['parcial']);
        $nr = $ins('notas_recepcion', ['numero' => numeroProceso($db, 'notas_recepcion', $pref('nr', 'NR'), $numSc), 'orden_compra_id' => $oc, 'proveedor_id' => $prov[$c['provs'][0]],
            'fecha' => $fr, 'factura_proveedor' => sprintf('000-001-01-%08d', 4500 + $oc * 7), 'fecha_factura' => $fr, 'receptor_id' => $emp[$c['recibe'] ?? 'asis'],
            'estado' => $parcial ? 'parcial' : 'completa', 'notas' => $parcial ? 'Pendiente la entrega del último artículo; el proveedor confirmó envío.' : null, 'created_at' => $ts($fr)]);
        foreach ($items as $i => [$d, $u, $q]) {
            $rec = $parcial && $i === count($items) - 1 ? (int)floor($q / 2) : $q;
            $ins('notas_recepcion_detalle', ['nota_id' => $nr, 'descripcion' => $d, 'unidad' => $u, 'cantidad_pedida' => $q, 'cantidad_recibida' => $rec,
                'precio_unitario' => $precios[$i], 'total' => round($precios[$i] * $rec, 2), 'estado_item' => $rec < $q ? 'incompleto' : 'bueno']);
        }
        $flujo('compras_recepcion.php', $nr, $fr, $c['recibe'] ?? 'asis');
        $upd('ordenes_compra', $oc, ['estado' => $parcial ? 'parcial' : 'completa']);
    };
    $en($mes(8, 6), fn() => $compra(['fecha' => $mes(8, 6), 'solicita' => 'coor2', 'creador' => 'asis', 'proy' => 'edu', 'cuenta' => '5106', 'provs' => ['libros', 'impr', 'ferre'], 'recibe' => 'asis',
        'desc' => 'Útiles escolares para 30 escuelas rurales', 'just' => 'Kits de útiles para estudiantes de primer ciclo según el plan anual del proyecto Educación.', 'hasta' => 'completa',
        'items' => [['Cuaderno universitario de 100 hojas', 'Unidad', 900, 38], ['Caja de lápices de colores (12)', 'Caja', 450, 62], ['Mochila escolar', 'Unidad', 450, 285], ['Diccionario escolar', 'Unidad', 120, 210]]]));
    $en($mes(6, 9), fn() => $compra(['fecha' => $mes(6, 9), 'solicita' => 'adm', 'creador' => 'comp', 'proy' => null, 'cuenta' => '5106', 'provs' => ['tec', 'impr', 'libros'], 'recibe' => 'adm',
        'desc' => 'Computadoras portátiles para el equipo técnico', 'just' => 'Reposición de equipo con más de 5 años de uso; necesario para los informes a cooperantes.', 'hasta' => 'completa',
        'items' => [['Computadora portátil 14" Core i5, 16 GB, SSD 512 GB', 'Unidad', 3, 21500], ['Mochila para portátil', 'Unidad', 3, 650], ['Licencia de ofimática (1 año)', 'Licencia', 3, 2300]]]));
    $en($mes(4, 7), fn() => $compra(['fecha' => $mes(4, 7), 'solicita' => 'coor1', 'creador' => 'comp', 'proy' => 'agua', 'cuenta' => '5115', 'provs' => ['ferre', 'tanq', 'agro'], 'recibe' => 'tec1',
        'desc' => 'Materiales para sistemas de agua (comunidad El Naranjo)', 'just' => 'Materiales para la red de distribución de 40 viviendas según el diseño aprobado.', 'hasta' => 'completa', 'parcial' => true,
        'lugar' => 'Bodega de la oficina regional, La Esperanza, Intibucá', 'urgencia' => 'urgente',
        'items' => [['Tubo PVC 1" SDR-26 (6 m)', 'Unidad', 220, 185], ['Codo PVC 1" x 90°', 'Unidad', 300, 14], ['Llave de paso de bronce 1/2"', 'Unidad', 45, 240], ['Cemento gris (bolsa 42.5 kg)', 'Bolsa', 80, 245]]]));
    $en($mes(2, 12), fn() => $compra(['fecha' => $mes(2, 12), 'solicita' => 'tec3', 'creador' => 'asis', 'proy' => 'agro', 'cuenta' => '5106', 'provs' => ['agro', 'ferre', 'libros'],
        'desc' => 'Herramientas agrícolas para huertos familiares', 'just' => 'Solicitud sin presupuesto disponible en la línea de materiales este trimestre.', 'hasta' => 'rechazada',
        'motivo' => 'La línea de materiales del proyecto ya está comprometida este trimestre. Reprogramar para el próximo y ajustar cantidades.',
        'items' => [['Azadón con mango', 'Unidad', 200, 165], ['Regadera plástica de 10 L', 'Unidad', 200, 120]]]));
    $en($mes(1, 6), fn() => $compra(['fecha' => $mes(1, 6), 'solicita' => 'coor2', 'creador' => 'asis', 'proy' => 'edu', 'cuenta' => '5114', 'provs' => ['impr', 'libros', 'tec'],
        'desc' => 'Impresión de manuales de formación docente', 'just' => 'Manuales para el diplomado de 120 docentes del segundo semestre.', 'hasta' => 'completa', 'op' => 'revisada',
        'items' => [['Manual del docente, 120 páginas a color', 'Ejemplar', 150, 185], ['Guía del estudiante, 60 páginas', 'Ejemplar', 600, 48]]]));
    $en($mes(0, 4), fn() => $compra(['fecha' => $mes(0, 4), 'solicita' => 'coor1', 'creador' => 'comp', 'proy' => 'agua', 'cuenta' => '5115', 'provs' => ['tanq', 'ferre', 'agro'],
        'desc' => 'Tanques de almacenamiento para 3 comunidades', 'just' => 'Tanques de 2,500 litros para las escuelas de las comunidades beneficiarias.', 'hasta' => 'cotizando',
        'items' => [['Tanque plástico de 2,500 L', 'Unidad', 6, 7800], ['Flotador de 1"', 'Unidad', 6, 320]]]));
    $en($mes(0, 10), fn() => $compra(['fecha' => $mes(0, 10), 'solicita' => 'adm', 'creador' => 'asis', 'proy' => null, 'cuenta' => '5106', 'provs' => ['libros', 'impr', 'tec'],
        'desc' => 'Sillas ergonómicas para la oficina central', 'just' => 'Reposición de 10 sillas dañadas en el área administrativa.', 'hasta' => 'pendiente',
        'items' => [['Silla ergonómica con apoyo lumbar', 'Unidad', 10, 3200]]]));

    // ── Anticipos (viáticos y compras), liquidaciones y reportes de viaje ──
    $fotoNum = 0;
    $anticipo = function (array $g) use ($db, $ins, $upd, $emp, $prov, $proy, $cta, $flujo, $ordenPago, $pref, $anio, $mas, $ts, $empresaId, &$pendientes, &$fotoNum) {
        $f = $g['fecha'];
        $esProv = isset($g['prov']);
        $detalle = $g['detalle'];   // [descripción, cuenta, monto]
        $monto = array_sum(array_column($detalle, 2));
        $proceso = $ins('procesos_viaticos', []);
        $gv = $ins('gastos_viaje', ['numero' => numeroProceso($db, 'gastos_viaje', $pref('gv', 'GV'), null, $anio($f)), 'proceso_id' => $proceso, 'tipo' => $g['tipo'] ?? 'viaje',
            'empleado_id' => $esProv ? null : $emp[$g['emp']], 'proveedor_id' => $esProv ? $prov[$g['prov']] : null, 'firmante1_id' => $esProv ? null : $emp[$g['emp']],
            'proyecto_id' => $proy[$g['proy']], 'destino' => $g['destino'], 'proposito' => $g['proposito'], 'fecha_salida' => $g['salida'], 'fecha_regreso' => $g['regreso'],
            'viaticos_anticipados' => $monto, 'monto_alimentacion' => $g['alim'] ?? 0, 'total_gastos' => 0, 'saldo' => 0, 'estado' => 'pendiente', 'created_at' => $ts($f),
            'requiere_vehiculo' => isset($g['vehiculo']) ? 1 : 0, 'vehiculo_desde' => isset($g['vehiculo']) ? $g['salida'] : null,
            'vehiculo_hasta' => isset($g['vehiculo']) ? $g['regreso'] : null, 'vehiculo_descripcion' => $g['vehiculo'] ?? null]);
        foreach ($detalle as [$d, $c, $m]) $ins('gastos_viaje_detalle', ['viaje_id' => $gv, 'fecha' => $g['salida'], 'descripcion' => $d, 'cuenta_id' => $cta[$c], 'monto' => $m]);
        $creador = $g['creador'];
        if ($g['hasta'] === 'pendiente') { $flujo('gastos_viajes.php', $gv, $f, $creador); $pendientes[] = ['gastos_viajes.php', $gv, 'revisar', $creador]; return; }
        if ($g['hasta'] === 'revisado') { $flujo('gastos_viajes.php', $gv, $f, $creador, 'adm'); $pendientes[] = ['gastos_viajes.php', $gv, 'autorizar', $creador]; return; }
        if ($g['hasta'] === 'rechazado') { $flujo('gastos_viajes.php', $gv, $f, $creador, null, null, ['adm', $g['motivo']]); $upd('gastos_viaje', $gv, ['estado' => 'rechazada']); return; }
        $flujo('gastos_viajes.php', $gv, $f, $creador, 'adm', 'dir');
        $upd('gastos_viaje', $gv, ['estado' => 'aprobada', 'aprobador_id' => $emp['dir']]);
        $ordenPago('gastos_viaje', $gv, $mas($f, 2), $g['op'] ?? 'pagada');
        if (!isset($g['liq'])) return;

        // Liquidación del anticipo (hereda su número) con el reporte de viaje
        $l = $g['liq'];
        $fl = $mas($g['regreso'], 2);
        $total = array_sum(array_column($l['detalle'], 2));
        $numGv = $db->query("SELECT numero FROM gastos_viaje WHERE id = $gv")->fetchColumn();
        $sg = $ins('solicitud_gastos', ['numero' => numeroProceso($db, 'solicitud_gastos', $pref('sg', 'SG'), $numGv), 'proceso_id' => $proceso, 'fecha' => $fl,
            'empleado_id' => $esProv ? null : $emp[$g['emp']], 'proveedor_id' => $esProv ? $prov[$g['prov']] : null, 'firmante1_id' => $esProv ? null : $emp[$g['emp']],
            'descripcion' => "Liquidación del anticipo $numGv: " . $g['destino'], 'proyecto_id' => $proy[$g['proy']], 'viaje_id' => $gv, 'monto_total' => $total,
            'estado' => 'pendiente', 'created_at' => $ts($fl), 'reporte_actividades' => $l['actividades'] ?? null, 'reporte_resultados' => $l['resultados'] ?? null,
            'reporte_observaciones' => $l['observaciones'] ?? null]);
        foreach ($l['detalle'] as $i => [$d, $c, $m]) $ins('solicitud_gastos_detalle', ['solicitud_id' => $sg, 'fecha' => $mas($g['salida'], min($i, 2)), 'descripcion' => $d,
            'cuenta_id' => $cta[$c], 'monto' => $m, 'comprobante' => sprintf('Factura 001-001-01-%06d', 1200 + $sg * 10 + $i)]);
        foreach ($l['fotos'] ?? [] as $texto) {
            $carpeta = RAIZ . "/assets/uploads/adjuntos/$empresaId/solicitud_gastos/$sg";
            if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);
            $archivo = 'foto-demo-' . (++$fotoNum) . '.jpg';
            fotoDemo("$carpeta/$archivo", $texto, $fotoNum);
            $ins('adjuntos', ['tabla' => 'solicitud_gastos', 'registro_id' => $sg, 'categoria' => FOTOS_VIAJE, 'nombre' => "$texto.jpg", 'archivo' => "solicitud_gastos/$sg/$archivo",
                'mime' => 'image/jpeg', 'tamano' => filesize("$carpeta/$archivo"), 'created_at' => $ts($fl, '16:00')]);
        }
        $upd('gastos_viaje', $gv, ['total_gastos' => $total, 'saldo' => round($monto - $total, 2)]);
        if (($l['hasta'] ?? 'pagada') === 'pendiente') { $flujo('gastos_solicitud.php', $sg, $fl, $creador); $pendientes[] = ['gastos_solicitud.php', $sg, 'revisar', $creador]; return; }
        $flujo('gastos_solicitud.php', $sg, $fl, $creador, 'fin', 'dir');
        $upd('solicitud_gastos', $sg, ['estado' => 'aprobada', 'aprobador_id' => $emp['dir'], 'fecha_aprobacion' => $mas($fl, 2)]);
        $upd('gastos_viaje', $gv, ['estado' => 'liquidada']);
        // Gastó más que el anticipo: el reembolso sale con su propia orden de pago (OP…-B)
        if ($total > $monto && $ordenPago('solicitud_gastos', $sg, $mas($fl, 3))) $upd('solicitud_gastos', $sg, ['estado' => 'pagada']);
    };
    $en($mes(7, 3), fn() => $anticipo(['fecha' => $mes(7, 3), 'emp' => 'tec1', 'creador' => 'tec1', 'proy' => 'agua', 'destino' => 'La Esperanza y comunidades de Intibucá',
        'proposito' => 'Diagnóstico de fuentes de agua en 4 comunidades y reuniones con las juntas de agua.', 'salida' => $mes(7, 10), 'regreso' => $mes(7, 13), 'alim' => 1600,
        'vehiculo' => 'Pick-up doble cabina para el traslado del equipo técnico y del kit de análisis de agua por caminos de tierra.', 'hasta' => 'liquidada',
        'detalle' => [['Hospedaje (3 noches)', '5103', 2700], ['Alimentación (4 días)', '5105', 1600], ['Combustible', '5108', 2200]],
        'liq' => ['detalle' => [['Hotel Posada del Valle (3 noches)', '5103', 2850], ['Alimentación del equipo', '5105', 1600], ['Combustible (2 cargas)', '5108', 2480], ['Peaje y estacionamiento', '5104', 160]],
                  'actividades' => "Se visitaron 4 comunidades (El Naranjo, Monte Verde, San Isidro y Las Flores). Se aforaron 7 fuentes de agua y se tomaron muestras para análisis bacteriológico.\nSe sostuvieron reuniones con las 4 juntas de agua (58 participantes, 31 mujeres).",
                  'resultados' => "Se identificaron 3 fuentes con caudal suficiente para el verano. Las juntas firmaron el acta de compromiso de contrapartida (mano de obra no calificada). Se definió el calendario de obras.",
                  'observaciones' => "El acceso a Las Flores requiere vehículo 4x4 en época de lluvia. Se recomienda programar la obra de esa comunidad para la temporada seca.",
                  'fotos' => ['Aforo de la fuente El Naranjo', 'Reunión con la junta de agua de Monte Verde', 'Toma de muestras en San Isidro', 'Recorrido de la línea de conducción']]]));
    $en($mes(5, 14), fn() => $anticipo(['fecha' => $mes(5, 14), 'emp' => 'comp', 'creador' => 'comp', 'tipo' => 'compra', 'proy' => 'agro', 'destino' => 'Compra de semilla y abono en el mercado de Siguatepeque',
        'proposito' => 'Compra de contado de insumos agrícolas para la primera siembra de los huertos familiares.', 'salida' => $mes(5, 16), 'regreso' => $mes(5, 20), 'hasta' => 'liquidada',
        'detalle' => [['Semilla de frijol mejorada (quintal)', '5106', 6800], ['Abono orgánico (sacos)', '5106', 3200]],
        'liq' => ['detalle' => [['Semilla de frijol mejorada (4 quintales)', '5106', 6600], ['Abono orgánico (20 sacos)', '5106', 3150]]]]));
    $en($mes(3, 18), fn() => $anticipo(['fecha' => $mes(3, 18), 'emp' => 'prom1', 'creador' => 'asis', 'proy' => 'edu', 'destino' => 'Gracias, Lempira',
        'proposito' => 'Taller con padres de familia en 3 escuelas.', 'salida' => $mes(3, 22), 'regreso' => $mes(3, 24), 'hasta' => 'rechazado',
        'motivo' => 'Las fechas coinciden con la evaluación trimestral. Reprogramar el taller para la siguiente semana.',
        'detalle' => [['Hospedaje (2 noches)', '5103', 1600], ['Transporte', '5104', 900]]]));
    $en($mes(2, 2), fn() => $anticipo(['fecha' => $mes(2, 2), 'emp' => 'coor2', 'creador' => 'asis', 'proy' => 'edu', 'destino' => 'Choluteca (escuelas de Marcovia y El Triunfo)',
        'proposito' => 'Seguimiento al diplomado docente y entrega de materiales en 6 escuelas.', 'salida' => $mes(2, 8), 'regreso' => $mes(2, 11), 'alim' => 1400,
        'vehiculo' => 'Vehículo para transportar 6 cajas de materiales educativos y a 2 facilitadoras.', 'hasta' => 'liquidada',
        'detalle' => [['Hospedaje (3 noches)', '5103', 3000], ['Alimentación (4 días)', '5105', 1400], ['Combustible', '5108', 2600]],
        'liq' => ['detalle' => [['Hotel Costa del Sol (3 noches, 2 habitaciones)', '5103', 3900], ['Alimentación', '5105', 1400], ['Combustible', '5108', 2750], ['Refrigerios del taller', '5113', 1250]],
                  'actividades' => 'Se visitaron 6 escuelas y se realizó la jornada presencial del diplomado con 42 docentes. Se entregaron 6 bibliotecas de aula.',
                  'resultados' => '42 docentes completaron el módulo 3 (96 % de asistencia). Las 6 escuelas firmaron el acta de recepción de materiales.',
                  'observaciones' => 'Dos escuelas solicitan más libros de lectura inicial. Considerar en la próxima compra.',
                  'fotos' => ['Jornada del diplomado docente en Marcovia', 'Entrega de bibliotecas de aula', 'Docentes de El Triunfo']]]));
    $en($mes(1, 15), fn() => $anticipo(['fecha' => $mes(1, 15), 'prov' => 'cons', 'creador' => 'admin', 'proy' => 'agua', 'destino' => 'Yamaranguila, Intibucá',
        'proposito' => 'Levantamiento topográfico de la línea de conducción (consultoría).', 'salida' => $mes(1, 20), 'regreso' => $mes(1, 23), 'hasta' => 'aprobado',
        'detalle' => [['Hospedaje (3 noches)', '5103', 2400], ['Alimentación (4 días)', '5105', 1200], ['Transporte', '5104', 1100]]]));
    $en($mes(0, 5), fn() => $anticipo(['fecha' => $mes(0, 5), 'emp' => 'tec2', 'creador' => 'asis', 'proy' => 'agua', 'destino' => 'Comunidades de San Francisco de Opalaca',
        'proposito' => 'Supervisión de la obra de captación y capacitación a fontaneros comunitarios.', 'salida' => $mes(0, 12, true), 'regreso' => $mes(0, 15, true), 'alim' => 1200,
        'vehiculo' => 'Pick-up para trasladar herramientas, 10 bolsas de cemento y al fontanero instructor.', 'hasta' => 'aprobado', 'op' => 'revisada',
        'detalle' => [['Hospedaje (3 noches)', '5103', 2700], ['Alimentación (4 días)', '5105', 1200], ['Combustible', '5108', 2400]]]));
    $en($mes(0, 8), fn() => $anticipo(['fecha' => $mes(0, 8), 'emp' => 'tec3', 'creador' => 'asis', 'proy' => 'agro', 'destino' => 'Comayagua y La Paz',
        'proposito' => 'Visitas técnicas a 25 huertos familiares y entrega de semilla de hortalizas.', 'salida' => $mes(0, 18, true), 'regreso' => $mes(0, 20, true),
        'vehiculo' => 'Pick-up para la entrega de semilla y bandejas de germinación.', 'hasta' => 'revisado',
        'detalle' => [['Hospedaje (2 noches)', '5103', 1900], ['Alimentación (3 días)', '5105', 900], ['Combustible', '5108', 1800]]]));
    $en($mes(0, 11), fn() => $anticipo(['fecha' => $mes(0, 11), 'emp' => 'prom1', 'creador' => 'asis', 'proy' => 'edu', 'destino' => 'Gracias, Lempira',
        'proposito' => 'Taller con padres de familia en 3 escuelas (reprogramado).', 'salida' => $mes(0, 24, true), 'regreso' => $mes(0, 26, true), 'hasta' => 'pendiente',
        'detalle' => [['Hospedaje (2 noches)', '5103', 1600], ['Alimentación (3 días)', '5105', 750], ['Transporte', '5104', 900]]]));

    // Solicitudes de gastos (reembolsos sin anticipo)
    $reembolso = function (string $f, string $e, string $creador, string $proyK, string $desc, array $detalle, string $hasta) use ($db, $ins, $upd, $emp, $proy, $cta, $flujo, $ordenPago, $pref, $anio, $mas, $ts, &$pendientes) {
        $total = array_sum(array_column($detalle, 2));
        $sg = $ins('solicitud_gastos', ['numero' => numeroProceso($db, 'solicitud_gastos', $pref('sg', 'SG'), null, $anio($f)), 'fecha' => $f, 'empleado_id' => $emp[$e], 'firmante1_id' => $emp[$e],
            'descripcion' => $desc, 'proyecto_id' => $proy[$proyK], 'monto_total' => $total, 'estado' => 'pendiente', 'created_at' => $ts($f)]);
        foreach ($detalle as $i => [$d, $c, $m]) $ins('solicitud_gastos_detalle', ['solicitud_id' => $sg, 'fecha' => $mas($f, -2 + $i), 'descripcion' => $d, 'cuenta_id' => $cta[$c], 'monto' => $m,
            'comprobante' => sprintf('Recibo %05d', 3100 + $sg * 5 + $i)]);
        if ($hasta === 'pendiente') { $flujo('gastos_solicitud.php', $sg, $f, $creador); $pendientes[] = ['gastos_solicitud.php', $sg, 'revisar', $creador]; return; }
        $flujo('gastos_solicitud.php', $sg, $f, $creador, 'fin', 'dir');
        $upd('solicitud_gastos', $sg, ['estado' => 'aprobada', 'aprobador_id' => $emp['dir'], 'fecha_aprobacion' => $mas($f, 2)]);
        $ordenPago('solicitud_gastos', $sg, $mas($f, 3));
        $upd('solicitud_gastos', $sg, ['estado' => 'pagada']);
    };
    $en($mes(6, 20), fn() => $reembolso($mes(6, 20), 'tec3', 'asis', 'agro', 'Reembolso de gastos de la feria agrícola municipal',
        [['Alquiler de toldo y mesas', '5113', 1800], ['Refrigerio para 60 participantes', '5105', 2100], ['Transporte de materiales', '5104', 650]], 'pagada'));
    $en($mes(3, 9), fn() => $reembolso($mes(3, 9), 'cond', 'asis', 'agua', 'Reembolso de reparación de llanta y cambio de aceite (VEH-002)',
        [['Reparación de llanta', '5112', 350], ['Cambio de aceite y filtro', '5112', 1450]], 'pagada'));
    $en($mes(0, 13), fn() => $reembolso($mes(0, 13), 'cont', 'asis', 'edu', 'Reembolso de envío de documentos a la Fundación B',
        [['Courier internacional de informes', '5104', 1850], ['Copias certificadas', '5107', 400]], 'pendiente'));

    // ── Caja chica: liquidada con gastos de más (reembolso -B) y una abierta ──
    $caja = function (string $f, float $fondo, array $gastos, bool $liquidar) use ($db, $ins, $upd, $emp, $usr, $cta, $ordenPago, $pref, $anio, $mas, $ts, $bitacora) {
        $total = round(array_sum(array_map(fn($g) => $g[3] * $g[4], $gastos)), 2);
        $cc = $ins('caja_chica', ['numero' => numeroProceso($db, 'caja_chica', $pref('cc', 'CC'), null, $anio($f)), 'empleado_id' => $emp['asis'], 'anticipo' => $fondo,
            'total_gastos' => $total, 'saldo' => round($fondo - $total, 2), 'estado' => 'abierta', 'fecha_apertura' => $f, 'created_at' => $ts($f),
            'notas' => 'Fondo para gastos menores de la oficina central.']);
        foreach ($gastos as [$dia, $c, $d, $q, $pu]) $ins('caja_chica_detalle', ['caja_id' => $cc, 'fecha' => min($mas($f, $dia), date('Y-m-d')), 'cuenta_id' => $cta[$c], 'descripcion' => $d,
            'cantidad' => $q, 'precio_unitario' => $pu, 'monto' => round($q * $pu, 2)]);
        $db->prepare("INSERT INTO registro_usuarios (tabla, registro_id, usuario_id, participacion, created_at) VALUES ('caja_chica', ?, ?, 'creador', ?)")->execute([$cc, $usr['asis'], $ts($f)]);
        $bitacora('asis', 'INSERT', 'caja_chica.php', 'caja_chica', $cc, $ts($f), 'Caja chica abierta: fondo ' . lps($fondo));
        $ordenPago('caja_chica', $cc, $f);
        if (!$liquidar) return;
        $fl = $mas($f, 26);
        $upd('caja_chica', $cc, ['estado' => 'liquidada', 'aprobador_id' => $usr['adm'], 'fecha_cierre' => $fl]);
        $bitacora('adm', 'UPDATE', 'caja_chica.php', 'caja_chica', $cc, $ts($fl), 'Caja chica liquidada');
        $ordenPago('caja_chica', $cc, $fl, 'pagada', true);
    };
    $en($mes(4, 2), fn() => $caja($mes(4, 2), 5000, [[2, '5107', 'Resma de papel bond', 6, 135], [4, '5111', 'Recarga de agua purificada (garrafones)', 12, 45],
        [7, '5104', 'Taxi para trámites bancarios', 4, 120], [10, '5106', 'Artículos de limpieza', 1, 860], [14, '5108', 'Combustible para motocicleta', 3, 400],
        [18, '5112', 'Reparación de cerradura de bodega', 1, 950], [21, '5107', 'Tóner para impresora', 1, 1450]], true));
    $en($mes(0, 2), fn() => $caja($mes(0, 2), 5000, [[1, '5107', 'Folders y sobres manila', 1, 380], [3, '5111', 'Agua purificada (garrafones)', 10, 45],
        [6, '5104', 'Taxi para entrega de documentos', 3, 120], [9, '5106', 'Artículos de cafetería', 1, 640]], false));

    // ── Recursos humanos ──
    $vacacion = function (string $f, string $e, string $ini, int $dias, string $estado, ?string $motivo = null) use ($db, $ins, $upd, $emp, $flujo, $mas, $ts, $anio, &$pendientes) {
        $fin = $ini; for ($n = 1; $n < $dias;) { $fin = $mas($fin, 1); if ((int)date('N', strtotime($fin)) < 6) $n++; }
        $id = $ins('vacaciones', ['numero' => generarNumero($db, 'vacaciones', 'numero', getConfig($db, 'prefijo_vac', 'VAC'), $anio($f)), 'empleado_id' => $emp[$e],
            'fecha_solicitud' => $f, 'fecha_inicio' => $ini, 'fecha_fin' => $fin, 'dias' => $dias, 'tipo' => 'ordinaria', 'estado' => 'pendiente', 'created_at' => $ts($f)]);
        // La pide el propio empleado si tiene usuario; si no, Recursos Humanos por él
        $creador = in_array($e, ['tec1', 'coor1', 'asis', 'adm', 'fin', 'cont', 'rrhh', 'comp'], true) ? $e : 'rrhh';
        if ($estado === 'pendiente') { $flujo('vacaciones.php', $id, $f, $creador); $pendientes[] = ['vacaciones.php', $id, 'revisar', $creador]; return; }
        if ($estado === 'rechazada') { $flujo('vacaciones.php', $id, $f, $creador, null, null, ['adm', $motivo]); $upd('vacaciones', $id, ['estado' => 'rechazada']); return; }
        $flujo('vacaciones.php', $id, $f, $creador, 'adm', 'dir');
        $upd('vacaciones', $id, ['estado' => $estado, 'aprobador_id' => $emp['dir']]);
    };
    $en($mes(7, 1), fn() => $vacacion($mes(7, 1), 'cont', $mes(7, 14), 5, 'disfrutada'));
    $en($mes(3, 2), fn() => $vacacion($mes(3, 2), 'coor1', $mes(3, 13), 10, 'disfrutada'));
    $en($mes(2, 5), fn() => $vacacion($mes(2, 5), 'prom1', $mes(2, 15), 5, 'rechazada', 'Coincide con la jornada de capacitación a padres de familia. Proponer otra fecha.'));
    $en($mes(0, 6), fn() => $vacacion($mes(0, 6), 'tec1', date('Y-m-d', strtotime('first monday of next month')), 5, 'aprobada'));
    $en($mes(0, 14), fn() => $vacacion($mes(0, 14), 'fin', date('Y-m-d', strtotime('+3 weeks monday')), 3, 'pendiente'));

    $permiso = function (string $f, string $e, string $ini, int $dias, string $tipo, string $motivo, string $estado) use ($db, $ins, $upd, $emp, $flujo, $mas, $ts, $anio, &$pendientes) {
        $id = $ins('permisos', ['numero' => generarNumero($db, 'permisos', 'numero', getConfig($db, 'prefijo_per', 'PER'), $anio($f)), 'empleado_id' => $emp[$e],
            'fecha_solicitud' => $f, 'fecha_inicio' => $ini, 'fecha_fin' => $mas($ini, $dias - 1), 'dias' => $dias, 'tipo' => $tipo, 'con_goce' => 1, 'motivo' => $motivo,
            'estado' => 'pendiente', 'created_at' => $ts($f)]);
        if ($estado === 'pendiente') { $flujo('permisos.php', $id, $f, 'rrhh'); $pendientes[] = ['permisos.php', $id, 'revisar', 'rrhh']; return; }
        $flujo('permisos.php', $id, $f, 'rrhh', 'adm', 'dir');
        $upd('permisos', $id, ['estado' => 'aprobado', 'aprobador_id' => $emp['adm']]);
    };
    $en($mes(6, 3), fn() => $permiso($mes(6, 3), 'tec2', $mes(6, 3), 2, 'enfermedad', 'Incapacidad por gripe (constancia médica adjunta en físico).', 'aprobado'));
    $en($mes(4, 11), fn() => $permiso($mes(4, 11), 'asis', $mes(4, 12), 1, 'personal', 'Trámite de documentos personales en el Registro Nacional de las Personas.', 'aprobado'));
    $en($mes(1, 9), fn() => $permiso($mes(1, 9), 'cond', $mes(1, 16), 2, 'capacitacion', 'Curso de manejo defensivo (Cruz Roja).', 'aprobado'));
    $en($mes(0, 15), fn() => $permiso($mes(0, 15), 'tec3', date('Y-m-d', strtotime('+10 days')), 1, 'personal', 'Graduación de su hijo.', 'pendiente'));

    // Préstamos a empleados (desembolso con su orden de pago y abonos mensuales)
    $prestamo = function (string $f, string $e, float $monto, int $cuotas, string $desc, int $pagos) use ($db, $ins, $upd, $emp, $ordenPago, $pref, $anio, $mas, $ts, $bitacora) {
        $cuota = round($monto / $cuotas, 2);
        $id = $ins('prestamos', ['numero' => numeroProceso($db, 'prestamos', $pref('pres', 'PRES'), null, $anio($f)), 'empleado_id' => $emp[$e], 'fecha' => $f, 'monto' => $monto,
            'cuotas' => $cuotas, 'monto_cuota' => $cuota, 'saldo' => $monto, 'descripcion' => $desc, 'estado' => 'activo', 'aprobador_id' => $emp['adm'], 'created_at' => $ts($f)]);
        $db->prepare("INSERT INTO registro_usuarios (tabla, registro_id, usuario_id, participacion, created_at) VALUES ('prestamos', ?, (SELECT id FROM usuarios WHERE rol = 'recursos_humanos' LIMIT 1), 'creador', ?)")->execute([$id, $ts($f)]);
        $bitacora('rrhh', 'INSERT', 'prestamos.php', 'prestamos', $id, $ts($f), 'Préstamo otorgado: ' . lps($monto));
        $ordenPago('prestamos', $id, $f);
        $saldo = $monto;
        for ($p = 1; $p <= $pagos; $p++) {
            $fp = date('Y-m-d', strtotime("$f +$p month"));
            if ($fp > date('Y-m-d')) break;
            $abono = min($cuota, $saldo);
            $ins('prestamos_pagos', ['prestamo_id' => $id, 'fecha' => $fp, 'monto' => $abono, 'notas' => "Cuota $p de $cuotas (deducida en planilla)", 'created_at' => $ts($fp)]);
            $saldo = round($saldo - $abono, 2);
        }
        $upd('prestamos', $id, ['saldo' => $saldo, 'estado' => $saldo <= 0 ? 'pagado' : 'activo']);
    };
    $en($mes(9, 20), fn() => $prestamo($mes(9, 20), 'cond', 6000, 6, 'Préstamo personal para reparación de vivienda.', 6));
    $en($mes(6, 25), fn() => $prestamo($mes(6, 25), 'tec1', 10000, 10, 'Préstamo para gastos médicos familiares.', 10));
    $en($mes(1, 28), fn() => $prestamo($mes(1, 28), 'asis', 8000, 8, 'Préstamo para matrícula universitaria.', 8));

    // Historial de salarios
    foreach ($aumentos as [$e, $antes, $despues, $desde]) {
        $en($desde, fn() => $ins('historial_salarial', ['empleado_id' => $emp[$e], 'sueldo_anterior' => $antes, 'sueldo_nuevo' => $despues,
            'cambio_pct' => round(($despues - $antes) / $antes * 100, 2), 'usuario_id' => $usr['rrhh'], 'created_at' => $ts($desde, '10:00')]));
    }

    // Liquidación laboral del promotor que renunció
    $en($mes(3, 31), function () use ($db, $ins, $upd, $emp, $info, $flujo, $ordenPago, $pref, $anio, $mes, $mas) {
        $f = $info['prom2']['egreso'];
        $s = 14500;
        $meses13 = (int)date('n', strtotime($f));
        $meses14 = ($meses13 + 6) % 12 ?: 12;
        $v = ['vacaciones_pendientes' => round($s / 30 * 8, 2), 'decimo_tercero_prop' => round($s * $meses13 / 12, 2), 'decimo_cuarto_prop' => round($s * $meses14 / 12, 2)];
        $id = $ins('liquidaciones', ['numero' => numeroProceso($db, 'liquidaciones', $pref('liq', 'LIQ'), null, $anio($f)), 'empleado_id' => $emp['prom2'], 'fecha_salida' => $f,
            'motivo' => 'renuncia', 'anios_servicio' => round((strtotime($f) - strtotime($info['prom2']['ingreso'])) / 31557600, 2), 'meses_anio_actual' => $meses13,
            'sueldo_mensual' => $s, 'dias_pendientes' => 0, 'salarios_pendientes' => 0, 'dias_vacaciones' => 8, 'preaviso' => 0, 'cesantia' => 0, 'otros_conceptos' => 0,
            'total' => array_sum($v), 'notas' => 'Renuncia voluntaria presentada con 30 días de anticipación.', 'estado' => 'borrador'] + $v);
        $flujo('liquidaciones.php', $id, $f, 'rrhh', 'adm', 'dir');
        $upd('liquidaciones', $id, ['estado' => 'aprobada', 'fecha_aprobacion' => $mas($f, 2)]);
        $ordenPago('liquidaciones', $id, $mas($f, 3));
        $upd('liquidaciones', $id, ['estado' => 'pagada', 'fecha_pago' => min($mas($f, 5), date('Y-m-d'))]);
        $db->prepare("UPDATE empleados SET activo = 0, fecha_egreso = ? WHERE id = ?")->execute([$f, $emp['prom2']]);
    });

    // ── Documentos internos ──
    $en($mes(5, 3), fn() => $ins('memorandos', ['numero' => generarNumero($db, 'memorandos', 'numero', 'MEMO', $anio($mes(5, 3))), 'tipo' => 'memo', 'fecha' => $mes(5, 3),
        'de_empleado_id' => $emp['dir'], 'de_cargo' => 'Directora Ejecutiva', 'para_texto' => 'Coordinadores de proyecto', 'asunto' => 'Entrega de informes trimestrales a los cooperantes',
        'cuerpo' => "Se recuerda que los informes trimestrales narrativos y financieros deben entregarse a la Dirección a más tardar el día 10 del mes siguiente al cierre del trimestre.\n\nLos informes deben incluir el avance de indicadores, la ejecución presupuestaria por línea y el registro fotográfico de las actividades.",
        'estado' => 'emitido']));
    $en($mes(2, 1), fn() => $ins('memorandos', ['numero' => generarNumero($db, 'memorandos', 'numero', 'CIRC', $anio($mes(2, 1))), 'tipo' => 'circular', 'fecha' => $mes(2, 1),
        'de_empleado_id' => $emp['adm'], 'de_cargo' => 'Gerente Administrativo', 'para_texto' => 'Todo el personal', 'asunto' => 'Uso de los vehículos institucionales',
        'cuerpo' => "A partir de esta fecha, todo uso de vehículo institucional debe solicitarse en el anticipo del viaje (opción \"Requiere vehículo\") y entregarse con el Acta de recepción de vehículo firmada.\n\nEl conductor responsable anotará el kilometraje y el combustible al entregar y al recibir el vehículo.",
        'estado' => 'emitido']));
    $en($mes(0, 16), fn() => $ins('memorandos', ['numero' => generarNumero($db, 'memorandos', 'numero', 'COM', $anio($mes(0, 16))), 'tipo' => 'comunicado', 'fecha' => $mes(0, 16),
        'de_empleado_id' => $emp['rrhh'], 'de_cargo' => 'Oficial de Recursos Humanos', 'para_texto' => 'Todo el personal', 'asunto' => 'Calendario de cierre de fin de año',
        'cuerpo' => "Se informa que las oficinas cerrarán del 24 de diciembre al 1 de enero. Las solicitudes de vacaciones para ese período deben ingresarse en el sistema antes del 10 de diciembre.",
        'estado' => 'borrador']));
    $acta = function (string $f, string $asunto, string $participantes, string $agenda, string $desarrollo, string $acuerdos, string $estado) use ($db, $ins, $anio, $mas) {
        $ins('actas_reunion', ['numero' => generarNumero($db, 'actas_reunion', 'numero', 'ACTA', $anio($f)), 'fecha' => $f, 'hora_inicio' => '09:00:00', 'hora_fin' => '11:30:00',
            'lugar' => 'Sala de reuniones de la oficina central', 'convocado_por' => 'Ana Gabriela Martínez López, Directora Ejecutiva', 'asunto' => $asunto,
            'participantes' => $participantes, 'agenda' => $agenda, 'desarrollo' => $desarrollo, 'acuerdos' => $acuerdos, 'proxima_reunion' => $mas($f, 30), 'estado' => $estado]);
    };
    $equipo = "Ana Gabriela Martínez López — Directora Ejecutiva\nCarlos Eduardo Rodríguez Paz — Gerente Administrativo\nPatricia Elena Velásquez Mejía — Jefa de Finanzas\nRoberto José Sánchez Díaz — Coordinador del Proyecto Agua\nKarla Johana Ordóñez Bustillo — Coordinadora del Proyecto Educación";
    $en($mes(6, 6), fn() => $acta($mes(6, 6), 'Planificación del segundo semestre', $equipo, "1. Avance de los proyectos\n2. Ejecución presupuestaria del primer semestre\n3. Calendario de giras",
        "Se revisó el avance de los tres proyectos. Agua Segura lleva 48 % de ejecución, Educación Rural 52 % y Seguridad Alimentaria 41 %. Se acordó reforzar las compras del proyecto Agua antes de la temporada de lluvias.",
        "1. Presentar el plan de compras del semestre en 15 días.\n2. Programar las giras de supervisión de julio y agosto.\n3. Actualizar el presupuesto del proyecto Seguridad Alimentaria.", 'aprobada'));
    $en($mes(1, 3), fn() => $acta($mes(1, 3), 'Revisión de la ejecución presupuestaria', $equipo, "1. Ejecución al cierre del trimestre\n2. Reprogramaciones\n3. Auditoría externa",
        "Finanzas presentó la ejecución por proyecto y por cuenta. Se identificó subejecución en capacitaciones del proyecto Agua y sobreejecución en combustible.",
        "1. Reprogramar L 40,000 de capacitaciones a materiales de construcción.\n2. Controlar el combustible con el acta de vehículos.\n3. Preparar la documentación para la auditoría externa.", 'aprobada'));
    $en($mes(0, 17), fn() => $acta($mes(0, 17), 'Preparación del cierre anual', $equipo, "1. Cierre contable\n2. Décimo tercer mes\n3. Plan operativo del próximo año",
        "Se revisó el calendario de cierre contable y la planilla del décimo tercer mes (en preparación).", "1. Cierre de anticipos pendientes antes del 15 de diciembre.\n2. Borrador del plan operativo en enero.", 'borrador'));

    // ── Ejecutar en orden de fecha ──
    usort($eventos, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    foreach ($eventos as [, , $fn]) $fn();

    // ── Asistencia del mes en curso ──
    $vacTec1 = []; $per = [];
    foreach ($db->query("SELECT empleado_id, fecha_inicio, fecha_fin FROM permisos WHERE estado = 'aprobado'")->fetchAll() as $p) $per[] = $p;
    for ($d = date('Y-m-01'); $d <= date('Y-m-d'); $d = $mas($d, 1)) {
        if ((int)date('N', strtotime($d)) > 5) continue;
        foreach ($info as $k => $e) {
            if ($e['egreso'] || $e['ingreso'] > $d) continue;
            $estado = 'presente'; $entrada = '07:5' . (crc32("$k$d") % 9);
            foreach ($per as $p) if ((int)$p['empleado_id'] === $emp[$k] && $d >= $p['fecha_inicio'] && $d <= $p['fecha_fin']) $estado = 'permiso';
            if ($estado === 'presente' && crc32("t$k$d") % 23 === 0) { $estado = 'tardanza'; $entrada = '08:2' . (crc32("$d$k") % 9); }
            $ins('asistencia', ['empleado_id' => $emp[$k], 'fecha' => $d, 'estado' => $estado, 'hora_entrada' => $estado === 'permiso' ? null : "$entrada:00",
                'hora_salida' => $estado === 'permiso' ? null : '17:0' . (crc32("s$k$d") % 9) . ':00', 'observacion' => $estado === 'tardanza' ? 'Tráfico en el anillo periférico' : null]);
        }
    }

    // ── Notificaciones: solo las de lo que espera revisión o autorización ──
    $db->exec("DELETE FROM notificaciones");
    foreach ($pendientes as [$modulo, $id, $paso, $creador]) {
        $como($creador);
        try { avisarPendiente($db, $modulo, (int)$id, $paso); } catch (Throwable $e) { /* sin aprobadores para ese paso */ }
    }
    $db->exec("UPDATE notificaciones SET created_at = NOW() - INTERVAL FLOOR(RAND() * 72) HOUR");
    // Bitácora: queda la historia simulada (las funciones del sistema anotaron sus eventos con la hora de hoy)
    $db->exec("DELETE FROM auditoria WHERE ip = '127.0.0.1' OR ip = '' OR ip IS NULL");
    unset($GLOBALS['_correos_notificacion']);

    // ── Resumen ──
    $c = fn(string $t, string $w = '1') => (int)$db->query("SELECT COUNT(*) FROM `$t` WHERE $w")->fetchColumn();
    $totales = [
        sprintf('  %d empleados (%d activos), %d usuarios, %d proyectos, %d proveedores, %d activos', $c('empleados'), $c('empleados', 'activo = 1'), $c('usuarios'), $c('proyectos'), $c('proveedores'), $c('activos_fijos')),
        sprintf('  Compras: %d solicitudes, %d cotizaciones, %d órdenes de compra, %d recepciones', $c('solicitud_compra'), $c('cotizaciones'), $c('ordenes_compra'), $c('notas_recepcion')),
        sprintf('  Gastos: %d anticipos, %d liquidaciones / reembolsos, %d cajas chicas, %d órdenes de pago', $c('gastos_viaje'), $c('solicitud_gastos'), $c('caja_chica'), $c('ordenes_pago')),
        sprintf('  Planillas: %d de sueldos, %d de asignación, %d de beneficios', $c('planilla_periodos'), $c('planilla_asignacion'), $c('planilla_beneficios')),
        sprintf('  RRHH: %d vacaciones, %d permisos, %d préstamos, %d liquidación laboral, %d registros de asistencia', $c('vacaciones'), $c('permisos'), $c('prestamos'), $c('liquidaciones'), $c('asistencia')),
        sprintf('  Documentos: %d memorandos y circulares, %d actas · %d notificaciones pendientes', $c('memorandos'), $c('actas_reunion'), $c('notificaciones')),
    ];
    $usuarios = array_values(array_map(fn($u) => $u, $usrInfo));
    return ['usuarios' => $usuarios, 'totales' => $totales];
}

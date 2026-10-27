<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$tipo = $_GET['tipo'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if (!$id || !in_array($tipo, ['sc','oc','op','pl','gv','sg','proceso','pa','pb','vac','per','trabajo','salario','referencia','tiempo','plv','pav','pbv','liq','cc','memo','acta','plc','gva','plp','pbp'])) { http_response_code(400); die('Parámetros inválidos.'); }
if (!puedeVerDocumento($tipo)) { http_response_code(403); die('No tiene permiso para ver este documento.'); }

$config  = getAllConfig($pdo);
$org     = $config['nombre_organizacion'] ?? 'Proscala';
$orgDir  = $config['direccion'] ?? 'Tegucigalpa, Honduras';
$orgRtn   = $config['rtn'] ?? '';
$orgTel   = $config['telefono'] ?? '';
$orgEmail = $config['correo'] ?? '';
$orgFull  = $config['nombre_completo'] ?? '';
$meses   = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

$doc = $detalle = [];
$title = $subtitle = $docNum = '';

switch ($tipo) {

    // ── Solicitud de Compra ───────────────────────────────────────
    case 'sc':
        $s = $pdo->prepare("SELECT sc.*, CONCAT(e.nombre,' ',e.apellidos) AS sol_nombre, p.codigo AS proy_nombre,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM solicitud_compra sc
            JOIN empleados e ON sc.solicitante_id = e.id
            LEFT JOIN proyectos p ON sc.proyecto_id = p.id
            LEFT JOIN empleados a ON sc.aprobador_id = a.id
            WHERE sc.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Solicitud no encontrada.');
        $s2 = $pdo->prepare("SELECT * FROM solicitud_compra_detalle WHERE solicitud_id = ?");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $title  = 'SOLICITUD DE COMPRA';
        $docNum = $doc['numero'];
        break;

    // ── Orden de Compra ───────────────────────────────────────────
    case 'oc':
        $s = $pdo->prepare("SELECT oc.*, p.nombre AS prov_nombre, p.rtn AS prov_rtn, p.direccion AS prov_dir,
            proy.codigo AS proy_nombre,
            sc.numero AS sc_numero,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM ordenes_compra oc
            JOIN proveedores p ON oc.proveedor_id = p.id
            LEFT JOIN proyectos proy ON oc.proyecto_id = proy.id
            LEFT JOIN solicitud_compra sc ON oc.solicitud_id = sc.id
            LEFT JOIN empleados a ON oc.aprobador_id = a.id
            WHERE oc.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Orden de compra no encontrada.');
        $s2 = $pdo->prepare("SELECT ocd.*, cc.nombre AS cta_nombre
            FROM ordenes_compra_detalle ocd
            LEFT JOIN cuentas_contables cc ON ocd.cuenta_id = cc.id
            WHERE ocd.orden_id = ?");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $title  = 'ORDEN DE COMPRA';
        $docNum = $doc['numero'];
        break;

    // ── Orden de Pago ─────────────────────────────────────────────
    case 'op':
        $s = $pdo->prepare("SELECT op.*,
            p.nombre AS prov_nombre,
            oc.numero AS oc_numero,
            sc.numero AS sc_numero,
            CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            CONCAT(a1.nombre,' ',a1.apellidos) AS aprobador1_nombre,
            CONCAT(a2.nombre,' ',a2.apellidos) AS aprobador2_nombre,
            pr.codigo AS proy_codigo, pr.nombre AS proy_nombre,
            cc.codigo AS cuenta_codigo, cc.nombre AS cuenta_nombre,
            sg.numero AS sg_numero, gv.numero AS gv_numero
            FROM ordenes_pago op
            LEFT JOIN proveedores p ON op.proveedor_id = p.id
            LEFT JOIN ordenes_compra oc ON op.orden_compra_id = oc.id
            LEFT JOIN solicitud_compra sc ON oc.solicitud_id = sc.id
            LEFT JOIN empleados e ON op.empleado_id = e.id
            LEFT JOIN empleados a1 ON op.aprobador1_id = a1.id
            LEFT JOIN empleados a2 ON op.aprobador2_id = a2.id
            LEFT JOIN proyectos pr ON op.proyecto_id = pr.id
            LEFT JOIN cuentas_contables cc ON op.cuenta_id = cc.id
            LEFT JOIN solicitud_gastos sg ON op.solicitud_gastos_id = sg.id
            LEFT JOIN gastos_viaje gv ON sg.viaje_id = gv.id
            WHERE op.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Orden de pago no encontrada.');
        // Proceso que originó la OP (planilla, anticipo, préstamo…), si no es una OC o liquidación ya mostrada
        $doc['origen_numero'] = $doc['origen_tabla'] && !in_array($doc['origen_tabla'], ['ordenes_compra', 'solicitud_gastos'], true)
            ? numeroDe($pdo, $doc['origen_tabla'], $doc['origen_id']) : null;
        $title  = 'ORDEN DE PAGO';
        $docNum = $doc['numero'];
        break;

    // ── Planilla Quincenal ────────────────────────────────────────
    case 'pl':
        $s = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla no encontrada.');
        $s2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre,
            e.cargo, e.banco, e.cuenta_banco
            FROM planilla_detalle pd
            JOIN empleados e ON pd.empleado_id = e.id
            WHERE pd.periodo_id = ? ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $mes    = $meses[$doc['mes'] - 1] ?? '';
        $quin   = $doc['quincena'] === 'primera' ? '1ª Quincena (1-15)' : '2ª Quincena (16-31)';
        $title  = 'PLANILLA QUINCENAL';
        $docNum = ($doc['numero'] ? "{$doc['numero']} — " : '') . "{$mes} {$doc['anio']} — {$quin}";
        break;

    // ── Planilla Quincenal distribuida por Proyecto ──────────────────
    case 'plp':
        $s = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla no encontrada.');
        $s2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre, e.cargo
            FROM planilla_detalle pd
            JOIN empleados e ON pd.empleado_id = e.id
            WHERE pd.periodo_id = ? ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $proyMap = proyectosMap($pdo);
        [$repartos, $resumenProy] = distribuirQuincenal($detalle,
            cargarDistribucion($pdo, 'planilla_detalle_proyectos', array_column($detalle, 'id')));
        $mes    = $meses[$doc['mes'] - 1] ?? '';
        $quin   = $doc['quincena'] === 'primera' ? '1ª Quincena (1-15)' : '2ª Quincena (16-31)';
        $title  = 'PLANILLA QUINCENAL POR PROYECTO';
        $docNum = ($doc['numero'] ? "{$doc['numero']} — " : '') . "{$mes} {$doc['anio']} — {$quin}";
        break;

    // ── Reporte de Deducciones Credimpulsa ───────────────────────────
    case 'plc':
        $s = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla no encontrada.');
        $s2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre,
            e.cargo, e.identidad
            FROM planilla_detalle pd
            JOIN empleados e ON pd.empleado_id = e.id
            WHERE pd.periodo_id = ? AND pd.credimpulsa > 0
            ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $mes    = $meses[$doc['mes'] - 1] ?? '';
        $quin   = $doc['quincena'] === 'primera' ? '1ª Quincena (1-15)' : '2ª Quincena (16-31)';
        $title  = 'REPORTE CREDIMPULSA';
        $docNum = ($doc['numero'] ? "{$doc['numero']} — " : '') . "{$mes} {$doc['anio']} — {$quin}";
        break;

    // ── Voucher de Alimentación de Viaje ─────────────────────────
    case 'gva':
        $s = $pdo->prepare("SELECT gv.*,
            CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            e.identidad AS emp_identidad, e.cargo AS emp_cargo,
            e.banco, e.cuenta_banco,
            p.codigo AS proy_nombre,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM gastos_viaje gv
            JOIN empleados e ON gv.empleado_id = e.id
            LEFT JOIN proyectos p ON gv.proyecto_id = p.id
            LEFT JOIN empleados a ON gv.aprobador_id = a.id
            WHERE gv.id = ? AND gv.monto_alimentacion > 0");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Anticipo no encontrado o sin monto de alimentación.');
        $title  = 'VOUCHER DE ALIMENTACIÓN';
        $docNum = $doc['numero'];
        break;

    // ── Gasto de Viaje ─────────────────────────────────────────────
    case 'gv':
        $s = $pdo->prepare("SELECT gv.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, p.codigo AS proy_nombre,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM gastos_viaje gv JOIN empleados e ON gv.empleado_id = e.id
            LEFT JOIN proyectos p ON gv.proyecto_id = p.id
            LEFT JOIN empleados a ON gv.aprobador_id = a.id
            WHERE gv.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Gasto de viaje no encontrado.');
        $s2 = $pdo->prepare("SELECT d.*, cc.codigo AS cuenta_codigo, cc.nombre AS cuenta_nombre
            FROM gastos_viaje_detalle d
            LEFT JOIN cuentas_contables cc ON d.cuenta_id = cc.id
            WHERE d.viaje_id = ? ORDER BY d.id");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $title  = $doc['tipo'] === 'compra' ? 'SOLICITUD DE ANTICIPO PARA COMPRA' : 'SOLICITUD DE ANTICIPO DE VIÁTICOS';
        $docNum = $doc['numero'];
        break;

    // ── Solicitud de Gastos / Reembolsos ──────────────────────────────
    case 'sg':
        $s = $pdo->prepare("SELECT sg.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            e.identidad AS emp_identidad, e.cargo AS emp_cargo,
            p.codigo AS proy_nombre,
            gv.numero AS gv_numero, gv.id AS gv_id, gv.viaticos_anticipados, gv.tipo AS gv_tipo,
            gv.monto_alimentacion, gv.fecha_salida AS gv_fecha_salida,
            gv.fecha_regreso AS gv_fecha_regreso, gv.destino AS gv_destino,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM solicitud_gastos sg
            JOIN empleados e ON sg.empleado_id = e.id
            LEFT JOIN proyectos p ON sg.proyecto_id = p.id
            LEFT JOIN gastos_viaje gv ON sg.viaje_id = gv.id
            LEFT JOIN empleados a ON sg.aprobador_id = a.id
            WHERE sg.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Solicitud de gastos no encontrada.');
        $s2 = $pdo->prepare("SELECT d.*, cc.codigo AS cuenta_codigo, cc.nombre AS cuenta_nombre FROM solicitud_gastos_detalle d
            LEFT JOIN cuentas_contables cc ON d.cuenta_id = cc.id WHERE d.solicitud_id = ? ORDER BY d.fecha, d.id");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $title  = $doc['viaje_id'] ? 'LIQUIDACIÓN DE GASTOS' : 'SOLICITUD DE GASTOS';   // ligada a un anticipo
        $docNum = $doc['numero'];
        break;

    // ── Planilla de Asignación ────────────────────────────────────────
    case 'pa':
        $s = $pdo->prepare("SELECT pa.*, p.codigo AS proy_nombre FROM planilla_asignacion pa
            LEFT JOIN proyectos p ON pa.proyecto_id = p.id WHERE pa.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla de asignación no encontrada.');
        $s2 = $pdo->prepare("SELECT pad.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            e.cargo, e.banco, e.cuenta_banco
            FROM planilla_asignacion_detalle pad JOIN empleados e ON pad.empleado_id = e.id
            WHERE pad.asignacion_id = ? ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $title  = 'PLANILLA DE ASIGNACIÓN';
        $docNum = $doc['numero'];
        break;

    // ── Planilla de Beneficios (13°/14° mes) ──────────────────────────
    case 'pb':
        $s = $pdo->prepare("SELECT * FROM planilla_beneficios WHERE id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla de beneficios no encontrada.');
        $s2 = $pdo->prepare("SELECT pbd.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            e.cargo, e.banco, e.cuenta_banco
            FROM planilla_beneficios_detalle pbd JOIN empleados e ON pbd.empleado_id = e.id
            WHERE pbd.beneficio_id = ? ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $pbTipos = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];
        $title  = 'PLANILLA DE BENEFICIOS — ' . strtoupper($pbTipos[$doc['tipo']] ?? $doc['tipo']);
        $docNum = $doc['numero'];
        break;

    // ── Planilla de Beneficios distribuida por Proyecto ───────────────
    case 'pbp':
        $s = $pdo->prepare("SELECT * FROM planilla_beneficios WHERE id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla de beneficios no encontrada.');
        $s2 = $pdo->prepare("SELECT pbd.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo
            FROM planilla_beneficios_detalle pbd JOIN empleados e ON pbd.empleado_id = e.id
            WHERE pbd.beneficio_id = ? ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $proyMap = proyectosMap($pdo);
        [$repartos, $resumenProy] = distribuirBeneficios($detalle,
            cargarDistribucion($pdo, 'planilla_beneficios_detalle_proyectos', array_column($detalle, 'id')));
        $pbTipos = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];
        $title  = strtoupper($pbTipos[$doc['tipo']] ?? $doc['tipo']) . ' POR PROYECTO';
        $docNum = $doc['numero'];
        break;

    // ── Proceso de Compra Completo ──────────────────────────────────
    case 'proceso':
        $s = $pdo->prepare("SELECT sc.*, CONCAT(e.nombre,' ',e.apellidos) AS sol_nombre, p.codigo AS proy_nombre,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM solicitud_compra sc JOIN empleados e ON sc.solicitante_id = e.id
            LEFT JOIN proyectos p ON sc.proyecto_id = p.id
            LEFT JOIN empleados a ON sc.aprobador_id = a.id WHERE sc.id = ?");
        $s->execute([$id]); $proc_sc = $s->fetch();
        if (!$proc_sc) die('Solicitud de compra no encontrada.');
        $s2 = $pdo->prepare("SELECT * FROM solicitud_compra_detalle WHERE solicitud_id = ?");
        $s2->execute([$id]); $proc_sc_det = $s2->fetchAll();
        $proc_oc = null; $proc_oc_det = [];
        $s3 = $pdo->prepare("SELECT oc.*, p.nombre AS prov_nombre, p.rtn AS prov_rtn,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM ordenes_compra oc JOIN proveedores p ON oc.proveedor_id = p.id
            LEFT JOIN empleados a ON oc.aprobador_id = a.id
            WHERE oc.solicitud_id = ? ORDER BY oc.id DESC LIMIT 1");
        $s3->execute([$id]); $proc_oc = $s3->fetch();
        if ($proc_oc) {
            $s4 = $pdo->prepare("SELECT ocd.*, cc.nombre AS cta_nombre FROM ordenes_compra_detalle ocd
                LEFT JOIN cuentas_contables cc ON ocd.cuenta_id = cc.id WHERE ocd.orden_id = ?");
            $s4->execute([$proc_oc['id']]); $proc_oc_det = $s4->fetchAll();
        }
        $proc_op = null;
        if ($proc_oc) {
            $s5 = $pdo->prepare("SELECT op.*,
                CONCAT(a1.nombre,' ',a1.apellidos) AS aprobador1_nombre,
                CONCAT(a2.nombre,' ',a2.apellidos) AS aprobador2_nombre
                FROM ordenes_pago op
                LEFT JOIN empleados a1 ON op.aprobador1_id = a1.id
                LEFT JOIN empleados a2 ON op.aprobador2_id = a2.id
                WHERE op.orden_compra_id = ? ORDER BY op.id DESC LIMIT 1");
            $s5->execute([$proc_oc['id']]); $proc_op = $s5->fetch();
        }
        $doc    = $proc_sc;
        $title  = 'PROCESO DE COMPRA';
        $docNum = $proc_sc['numero'];
        break;

    // ── Vouchers Individuales de Planilla ─────────────────────────────
    case 'plv':
        $s = $pdo->prepare("SELECT * FROM planilla_periodos WHERE id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla no encontrada.');
        $s2 = $pdo->prepare("SELECT pd.*, CONCAT(e.nombre,' ',e.apellidos) AS empleado_nombre,
            e.cargo, e.banco, e.cuenta_banco, e.identidad, e.sueldo_mensual AS sueldo_base
            FROM planilla_detalle pd
            JOIN empleados e ON pd.empleado_id = e.id
            WHERE pd.periodo_id = ? ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $mes    = $meses[$doc['mes'] - 1] ?? '';
        $quin   = $doc['quincena'] === 'primera' ? '1ª Quincena (1-15)' : '2ª Quincena (16-31)';
        // Distribución por proyecto (el donante requiere el recibo firmado por el empleado)
        $proyMap = proyectosMap($pdo);
        $distV   = cargarDistribucion($pdo, 'planilla_detalle_proyectos', array_column($detalle, 'id'));
        [$repartosV] = distribuirQuincenal($detalle, $distV);
        $title  = 'RECIBOS DE QUINCENA';
        $docNum = ($doc['numero'] ? "{$doc['numero']} — " : '') . "{$mes} {$doc['anio']} — {$quin}";
        break;

    // ── Vouchers Individuales de Asignación ───────────────────────────
    case 'pav':
        $s = $pdo->prepare("SELECT pa.*, p.codigo AS proy_nombre
            FROM planilla_asignacion pa LEFT JOIN proyectos p ON pa.proyecto_id = p.id WHERE pa.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla de asignación no encontrada.');
        $s2 = $pdo->prepare("SELECT pad.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            e.cargo, e.identidad, e.banco, e.cuenta_banco
            FROM planilla_asignacion_detalle pad JOIN empleados e ON pad.empleado_id = e.id
            WHERE pad.asignacion_id = ? ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $title  = 'RECIBOS DE ASIGNACIÓN';
        $docNum = $doc['numero'];
        break;

    // ── Vouchers Individuales de Beneficios ───────────────────────────
    case 'pbv':
        $s = $pdo->prepare("SELECT * FROM planilla_beneficios WHERE id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Planilla de beneficios no encontrada.');
        $s2 = $pdo->prepare("SELECT pbd.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            e.cargo, e.identidad, e.banco, e.cuenta_banco
            FROM planilla_beneficios_detalle pbd JOIN empleados e ON pbd.empleado_id = e.id
            WHERE pbd.beneficio_id = ? ORDER BY e.apellidos, e.nombre");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        $pbvTipos = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];
        $proyMap = proyectosMap($pdo);
        $distV   = cargarDistribucion($pdo, 'planilla_beneficios_detalle_proyectos', array_column($detalle, 'id'));
        [$repartosV] = distribuirBeneficios($detalle, $distV);
        $title  = 'RECIBOS DE BENEFICIO — ' . strtoupper($pbvTipos[$doc['tipo']] ?? $doc['tipo']);
        $docNum = $doc['numero'];
        break;

    // ── Vacaciones ────────────────────────────────────────────────────
    case 'vac':
        $s = $pdo->prepare("SELECT v.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo,
            e.identidad, e.fecha_ingreso,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM vacaciones v JOIN empleados e ON v.empleado_id=e.id
            LEFT JOIN empleados a ON v.aprobador_id=a.id WHERE v.id=?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Solicitud de vacaciones no encontrada.');
        $title  = 'SOLICITUD DE VACACIONES';
        $docNum = $doc['numero'];
        break;

    // ── Permiso ───────────────────────────────────────────────────────
    case 'per':
        $s = $pdo->prepare("SELECT p.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre, e.cargo,
            e.identidad,
            CONCAT(a.nombre,' ',a.apellidos) AS aprobador_nombre
            FROM permisos p JOIN empleados e ON p.empleado_id=e.id
            LEFT JOIN empleados a ON p.aprobador_id=a.id WHERE p.id=?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Permiso no encontrado.');
        $TIPOS_PER = [
            'enfermedad'=>'Enfermedad / Incapacidad','personal'=>'Personal',
            'maternidad'=>'Maternidad','paternidad'=>'Paternidad',
            'luto'=>'Luto / Duelo','capacitacion'=>'Capacitación','otro'=>'Otro',
        ];
        $title  = 'PERMISO — ' . strtoupper($TIPOS_PER[$doc['tipo']] ?? $doc['tipo']);
        $docNum = $doc['numero'];
        break;

    // ── Constancias de RRHH (id = empleado_id) ───────────────────────
    case 'trabajo':
    case 'salario':
    case 'referencia':
    case 'tiempo':
        $s = $pdo->prepare("SELECT *, CONCAT(nombre,' ',apellidos) AS nombre_completo FROM empleados WHERE id=?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Empleado no encontrado.');
        $constFechaEmision  = $_GET['fecha_emision']  ?? date('Y-m-d');
        $constDestinatario  = trim($_GET['destinatario'] ?? '');
        $constNotaAdicional = trim($_GET['nota_adicional'] ?? '');
        $hoy = new DateTime(); $ing = $doc['fecha_ingreso'] ? new DateTime($doc['fecha_ingreso']) : $hoy;
        $aniosSrv = (int)$ing->diff($hoy)->y;
        $mesesSrv = (int)$ing->diff($hoy)->m;
        $TITULOS_CONST = [
            'trabajo'    => 'CONSTANCIA DE TRABAJO',
            'salario'    => 'CONSTANCIA DE SALARIO',
            'referencia' => 'REFERENCIA LABORAL',
            'tiempo'     => 'CONSTANCIA DE TIEMPO DE SERVICIO',
        ];
        $title  = $TITULOS_CONST[$tipo];
        $docNum = date('Y') . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);
        break;

    // ── Caja Chica ────────────────────────────────────────────────────
    case 'cc':
        $s = $pdo->prepare("SELECT cc.*,
            CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            e.cargo, e.identidad, e.departamento,
            u.nombre AS aprobador_nombre
            FROM caja_chica cc
            JOIN empleados e ON cc.empleado_id = e.id
            LEFT JOIN usuarios u ON cc.aprobador_id = u.id
            WHERE cc.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Caja chica no encontrada.');
        $s2 = $pdo->prepare("SELECT d.*,
            COALESCE(CONCAT(cta.codigo,' — ',cta.nombre),'Sin cuenta') AS cuenta_nombre
            FROM caja_chica_detalle d
            LEFT JOIN cuentas_contables cta ON d.cuenta_id = cta.id
            WHERE d.caja_id = ? ORDER BY d.fecha, d.id");
        $s2->execute([$id]); $detalle = $s2->fetchAll();
        // Resumen por cuenta
        $s3 = $pdo->prepare("
            SELECT COALESCE(CONCAT(cta.codigo,' — ',cta.nombre),'Sin cuenta') AS cuenta_nombre,
                   SUM(d.monto) AS subtotal
            FROM caja_chica_detalle d
            LEFT JOIN cuentas_contables cta ON d.cuenta_id = cta.id
            WHERE d.caja_id = ?
            GROUP BY d.cuenta_id, cta.codigo, cta.nombre ORDER BY cta.codigo");
        $s3->execute([$id]); $ccResumen = $s3->fetchAll();
        // Firmante 1 = el empleado de la caja chica
        $empFirmante = [
            'etiqueta'    => $doc['cargo'] ?? 'Responsable de Caja Chica',
            'emp_nombre'  => $doc['emp_nombre'],
        ];
        $title  = 'CAJA CHICA';
        $docNum = $doc['numero'];
        break;

    // ── Liquidación ───────────────────────────────────────────────────
    case 'liq':
        $s = $pdo->prepare("SELECT l.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre,
            e.cargo, e.identidad, e.departamento, e.fecha_ingreso, e.rtn AS emp_rtn,
            CONCAT(u.nombre,' ',u.apellidos) AS aprobador_nombre
            FROM liquidaciones l
            JOIN empleados e ON l.empleado_id = e.id
            LEFT JOIN empleados u ON l.aprobador_id = u.id
            WHERE l.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Liquidación no encontrada.');
        $title  = 'LIQUIDACIÓN DE PERSONAL';
        $docNum = $doc['numero'];
        break;

    // ── Memorándum / Circular / Comunicado ───────────────────────────
    case 'memo':
        $s = $pdo->prepare("SELECT m.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre_full
            FROM memorandos m LEFT JOIN empleados e ON m.de_empleado_id = e.id
            WHERE m.id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Documento no encontrado.');
        $TITULOS_MEMO = ['memo'=>'MEMORÁNDUM','circular'=>'CIRCULAR','comunicado'=>'COMUNICADO'];
        $title  = $TITULOS_MEMO[$doc['tipo']] ?? 'DOCUMENTO INTERNO';
        $docNum = $doc['numero'];
        break;

    // ── Acta de Reunión ───────────────────────────────────────────────
    case 'acta':
        $s = $pdo->prepare("SELECT * FROM actas_reunion WHERE id = ?");
        $s->execute([$id]); $doc = $s->fetch();
        if (!$doc) die('Acta no encontrada.');
        $title  = 'ACTA DE REUNIÓN';
        $docNum = $doc['numero'];
        break;
}

// ── Filtro de voucher individual (emp_id) ─────────────────────────
if (isset($_GET['emp_id']) && in_array($tipo, ['plv','pav','pbv'])) {
    $vEmp    = (int)$_GET['emp_id'];
    $detalle = array_values(array_filter($detalle, fn($d) => ($d['empleado_id'] ?? 0) === $vEmp));
}

// ── Firmantes desde BD ───────────────────────────────────────────
if (!function_exists('loadFirmantes')):
// Firma #1 de anticipos y liquidaciones guardada en el documento (firmante1_id); las firmas
// Revisado / Autorizado las pone firmasFlujo con las personas reales del flujo
function firmante1Documento(PDO $pdo, array $fBase, int $firmante1Id): array {
    $s = $pdo->prepare("SELECT CONCAT(nombre,' ',apellidos) FROM empleados WHERE id=?");
    $s->execute([$firmante1Id]);
    if ($nombre = $s->fetchColumn()) {
        $fBase[0] = ($fBase[0] ?? ['posicion' => 1, 'etiqueta' => 'Solicitado por']);
        $fBase[0]['emp_nombre'] = $nombre;
    }
    return $fBase;
}

// Firmas configuradas de un documento (tabla firmantes; ya no se editan desde el sistema)
function loadFirmantes(PDO $pdo, string $doc): array {
    $s = $pdo->prepare("SELECT f.posicion, f.etiqueta,
        CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre
        FROM firmantes f
        LEFT JOIN empleados e ON f.empleado_id = e.id
        WHERE f.documento = ? ORDER BY f.posicion");
    $s->execute([$doc]); return $s->fetchAll();
}
function sigBlock(array $fs, string $cls = 'cols-3', string $sty = ''): string {
    if (empty($fs)) return '';
    $attr = $sty ? " style=\"{$sty}\"" : '';
    $html = "<div class=\"signatures {$cls}\"{$attr}>";
    foreach ($fs as $f) {
        $nm  = htmlspecialchars($f['emp_nombre'] ?: $f['etiqueta']);
        $rl  = htmlspecialchars($f['etiqueta']);
        $ext = !empty($f['ext']) ? ' sig-ext' : '';
        $html .= "<div class=\"sig-item{$ext}\"><div class=\"sig-line\"></div>"
               . "<div class=\"sig-name\">{$nm}</div>"
               . "<div class=\"sig-role\">{$rl}</div></div>";
    }
    return $html . '</div>';
}
function sigProvBlock(string $name, string $label): string {
    $nm = htmlspecialchars($name);
    $rl = htmlspecialchars($label);
    return "<div class=\"sig-prov-wrap\">"
         . "<div class=\"sig-item sig-ext\">"
         . "<div class=\"sig-line\"></div>"
         . "<div class=\"sig-name\">{$nm}</div>"
         . "<div class=\"sig-role\">{$rl}</div>"
         . "</div></div>";
}
endif; // function_exists('loadFirmantes')

// ── Tabla "Distribución por Proyecto" de los recibos plv / pbv ─────
// $cols: [clave => etiqueta]; la primera clave es el porcentaje.
if (!function_exists('voucherDistTabla')):
function voucherDistTabla(array $rep, array $proyMap, array $cols): string {
    $th  = 'padding:5px 8px;text-align:right;font-weight:700';
    $td  = "padding:6px 8px;text-align:right;font-family:'Courier New',monospace";
    $pctKey = array_key_first($cols);
    $html = '<div class="section-head" style="margin-top:12px">Distribución por Proyecto</div>'
          . '<div class="items-wrap" style="padding:0 28px;margin-top:8px">'
          . '<table style="width:100%;border-collapse:collapse;font-size:8.5pt"><thead>'
          . '<tr style="border-bottom:1.5px solid #cbd5e1;color:#64748b;font-size:7.5pt">'
          . '<th style="padding:5px 8px;text-align:left;font-weight:700">PROYECTO</th>';
    foreach ($cols as $lbl) $html .= "<th style=\"{$th}\">" . htmlspecialchars($lbl) . '</th>';
    $html .= '</tr></thead><tbody>';
    $tot = array_fill_keys(array_keys($cols), 0);
    foreach ($rep as $pid => $r) {
        $html .= '<tr style="border-bottom:1px solid #e2e8f0"><td style="padding:6px 8px;color:#374151">'
               . htmlspecialchars(proyectoLabel($proyMap, (int)$pid, true)) . '</td>';
        foreach ($cols as $k => $_) {
            $html .= "<td style=\"{$td}\">" . ($k === $pctKey ? fmtPct($r[$k]) . '%' : lps($r[$k])) . '</td>';
            $tot[$k] += $r[$k];
        }
        $html .= '</tr>';
    }
    $html .= '<tr style="background:#f0f5fc;font-weight:700;color:#0D3F6A"><td style="padding:6px 8px">Total</td>';
    foreach ($cols as $k => $_) $html .= "<td style=\"{$td}\">" . ($k === $pctKey ? fmtPct($tot[$k]) . '%' : lps($tot[$k])) . '</td>';
    return $html . '</tr></tbody></table></div>';
}
endif;

$fSC = ($tipo === 'sc' || $tipo === 'proceso') ? loadFirmantes($pdo, 'sc') : [];
$fOC = ($tipo === 'oc' || $tipo === 'proceso') ? loadFirmantes($pdo, 'oc') : [];
$fOP = ($tipo === 'op' || $tipo === 'proceso') ? loadFirmantes($pdo, 'op') : [];
$fPL = in_array($tipo, ['pl','plp']) ? loadFirmantes($pdo, 'pl') : [];
$fGV = ($tipo === 'gv' || $tipo === 'gva') ? loadFirmantes($pdo, 'gv') : [];
if (in_array($tipo, ['gv','gva']) && !empty($doc['firmante1_id'])) {
    $fGV = firmante1Documento($pdo, $fGV, $doc['firmante1_id']);
}
$fSG = $tipo === 'sg' ? loadFirmantes($pdo, 'sg') : [];
if ($tipo === 'sg' && !empty($doc['firmante1_id'])) {
    $fSG = firmante1Documento($pdo, $fSG, $doc['firmante1_id']);
}
$fPA = $tipo === 'pa' ? loadFirmantes($pdo, 'pa') : [];
$fPB    = in_array($tipo, ['pb','pbp']) ? loadFirmantes($pdo, 'pb') : [];
$fVAC   = $tipo === 'vac' ? loadFirmantes($pdo, 'vac') : [];
$fPER   = $tipo === 'per' ? loadFirmantes($pdo, 'per') : [];
$fCONST = in_array($tipo, ['trabajo','salario','referencia','tiempo']) ? loadFirmantes($pdo, 'constancia') : [];
$fLIQ   = $tipo === 'liq'  ? loadFirmantes($pdo, 'liq')  : [];
// Firmas Elaborado / Revisado / Autorizado: quienes realmente crearon, revisaron y
// autorizaron el proceso (config/aprobaciones.php); un paso pendiente queda en blanco
if (in_array($tipo, ['sc','proceso'])) $fSC = firmasFlujo($pdo, $fSC, 'compras_solicitud.php', $id);
if ($tipo === 'oc')                    $fOC = firmasFlujo($pdo, $fOC, 'compras_orden.php', $id);
if ($tipo === 'op')                    $fOP = firmasFlujo($pdo, $fOP, 'compras_pago.php', $id);
if ($tipo === 'proceso' && $proc_oc)   $fOC = firmasFlujo($pdo, $fOC, 'compras_orden.php', (int)$proc_oc['id']);
if ($tipo === 'proceso' && $proc_op)   $fOP = firmasFlujo($pdo, $fOP, 'compras_pago.php', (int)$proc_op['id']);
if (in_array($tipo, ['gv','gva']))     $fGV = firmasFlujo($pdo, $fGV, 'gastos_viajes.php', $id);
if ($tipo === 'sg')                    $fSG = firmasFlujo($pdo, $fSG, 'gastos_solicitud.php', $id);
if ($tipo === 'vac')                   $fVAC = firmasFlujo($pdo, $fVAC, 'vacaciones.php', $id);
if ($tipo === 'per')                   $fPER = firmasFlujo($pdo, $fPER, 'permisos.php', $id);
$fCC    = $tipo === 'cc'   ? loadFirmantes($pdo, 'cc')   : [];
$fMEMO  = $tipo === 'memo' ? loadFirmantes($pdo, 'memo') : [];
$fACTA  = $tipo === 'acta' ? loadFirmantes($pdo, 'acta') : [];
$fPLC   = $tipo === 'plc'  ? loadFirmantes($pdo, 'pl')   : [];

$logoData = logoEmpresaDataUri($pdo);
$orgInicial = mb_strtoupper(mb_substr($org, 0, 1));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars("$title — $docNum") ?></title>
<style>
/* ── Reset ──────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 10pt; }
body {
  font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
  color: #1a1a2e;
  background: #fff;
  line-height: 1.45;
}

/* ── Page layout ─────────────────────────────────────────────────── */
<?php $isLandscape = in_array($tipo, ['pl','pb','plp']); ?>
.page {
  width: <?= $isLandscape ? '297mm' : '210mm' ?>;
  min-height: <?= $isLandscape ? '210mm' : '297mm' ?>;
  margin: 0 auto;
  padding: 0;
  display: flex;
  flex-direction: column;
}
@media screen {
  body { background: #e8edf2; padding: 20px 0 40px; }
  .page { box-shadow: 0 4px 30px rgba(0,0,0,.18); background: #fff; }
}
@media print {
  body { background: #fff !important; padding: 0; }
  .page { box-shadow: none; margin: 0; width: 100%; }
  .no-print { display: none !important; }
  @page { margin: 0; size: A4 <?= $isLandscape ? 'landscape' : 'portrait' ?>; }
  /* Ancla el gradiente inferior al borde físico de cada hoja impresa */
  .bottom-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: 4px;
  }
  /* Oculta la copia en el flujo normal para evitar doble renderización */
  .page > .bottom-bar { display: none; }
}

/* ── Top accent bar ──────────────────────────────────────────────── */
.top-bar {
  height: 6px;
  background: linear-gradient(90deg, #1B6BA8 0%, #0D3F6A 60%, #E53935 100%);
}

/* ── Document header ─────────────────────────────────────────────── */
.doc-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  padding: 18px 28px 14px;
  border-bottom: 1px solid #e2e8f0;
  gap: 16px;
}
.org-block {
  display: flex;
  align-items: center;
  gap: 12px;
}
.org-logo {
  height: 52px;
  width: auto;
  object-fit: contain;
  flex-shrink: 0;
}
.org-logo-placeholder {
  height: 52px;
  width: 52px;
  background: #1B6BA8;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-weight: 700;
  font-size: 16pt;
  flex-shrink: 0;
}
.org-info { line-height: 1.35; }
.org-name {
  font-size: 11pt;
  font-weight: 700;
  color: #0D3F6A;
  letter-spacing: .01em;
}
.org-sub {
  font-size: 7.5pt;
  color: #64748b;
  margin-top: 1px;
}
.org-fullname {
  font-size: 6.5pt;
  color: #475569;
  letter-spacing: .01em;
  white-space: nowrap;
  margin-top: 2px;
}

.doc-title-block { text-align: right; flex-shrink: 0; }
.doc-type {
  font-size: 8pt;
  font-weight: 700;
  letter-spacing: .12em;
  text-transform: uppercase;
  color: #1B6BA8;
  margin-bottom: 4px;
  white-space: nowrap;
}
.doc-number {
  font-size: 17pt;
  font-weight: 800;
  color: #0D3F6A;
  letter-spacing: .02em;
  line-height: 1;
  white-space: nowrap;
}
.doc-estado {
  margin-top: 5px;
  display: inline-block;
  padding: 2px 8px;
  border-radius: 20px;
  font-size: 7.5pt;
  font-weight: 700;
  letter-spacing: .06em;
  text-transform: uppercase;
}
.est-pendiente  { background: #fff3cd; color: #856404; }
.est-aprobada   { background: #d1e7dd; color: #0a3622; }
.est-rechazada  { background: #f8d7da; color: #842029; }
.est-pagada     { background: #cff4fc; color: #055160; }
.est-emitida    { background: #cfe2ff; color: #084298; }
.est-procesada  { background: #cfe2ff; color: #084298; }
.est-completa   { background: #d1e7dd; color: #0a3622; }
.est-anulada    { background: #e2e3e5; color: #41464b; }
.est-borrador   { background: #e2e3e5; color: #41464b; }

/* ── Meta section ─────────────────────────────────────────────────── */
.meta-section {
  padding: 14px 28px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
}
.meta-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px 20px;
}
.meta-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
.meta-grid.cols-4 { grid-template-columns: repeat(4, 1fr); }
.meta-item {}
.meta-label {
  font-size: 7pt;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #94a3b8;
  margin-bottom: 2px;
}
.meta-value {
  font-size: 9pt;
  color: #1a1a2e;
  font-weight: 500;
}
.meta-value.mono { font-family: 'Courier New', monospace; }
.meta-full { grid-column: 1 / -1; }

/* ── Section title ────────────────────────────────────────────────── */
.section-head {
  padding: 8px 28px;
  font-size: 7pt;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: #1B6BA8;
  background: #f0f5fc;
  border-top: 1px solid #dbeafe;
  border-bottom: 1px solid #dbeafe;
}

/* ── Items table ──────────────────────────────────────────────────── */
.items-wrap { padding: 0; }
table.items {
  width: 100%;
  border-collapse: collapse;
  font-size: 8.5pt;
}
table.items thead th {
  background: #0D3F6A;
  color: #fff;
  padding: 7px 10px;
  text-align: left;
  font-weight: 600;
  font-size: 7.5pt;
  letter-spacing: .04em;
  white-space: nowrap;
}
table.items thead th.r { text-align: right; }
table.items tbody tr:nth-child(even) { background: #f8fafc; }
table.items tbody tr:hover { background: #eff6ff; }
table.items tbody td {
  padding: 6px 10px;
  border-bottom: 1px solid #e2e8f0;
  vertical-align: top;
}
table.items tbody td.r { text-align: right; font-family: 'Courier New', monospace; }
table.items tbody td.c { text-align: center; }
table.items tfoot td {
  padding: 6px 10px;
  font-size: 8.5pt;
}
table.items tfoot td.r { text-align: right; font-family: 'Courier New', monospace; }
table.items tfoot tr:last-child td {
  border-top: 2px solid #1B6BA8;
  font-weight: 700;
  font-size: 9.5pt;
  color: #0D3F6A;
}

/* ── Amount highlight (Orden de Pago) ─────────────────────────────── */
.amount-block {
  margin: 16px 28px;
  padding: 14px 20px;
  background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
  border-left: 5px solid #1B6BA8;
  border-radius: 0 8px 8px 0;
  display: flex;
  align-items: center;
  gap: 20px;
}
.amount-label {
  font-size: 8pt;
  font-weight: 700;
  letter-spacing: .1em;
  text-transform: uppercase;
  color: #1B6BA8;
}
.amount-value {
  font-size: 22pt;
  font-weight: 800;
  color: #0D3F6A;
  font-family: 'Courier New', monospace;
  letter-spacing: .02em;
}

/* ── Reference chain (trazabilidad) ──────────────────────────────── */
.ref-chain {
  padding: 10px 28px;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  border-bottom: 1px solid #e2e8f0;
}
.ref-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 7.5pt;
  font-weight: 700;
  background: #f0f5fc;
  color: #1B6BA8;
  border: 1px solid #bfdbfe;
}
.ref-arrow { color: #94a3b8; font-size: 9pt; }
.ref-current {
  background: #1B6BA8;
  color: #fff;
  border-color: #1B6BA8;
}

/* ── Signatures ───────────────────────────────────────────────────── */
.signatures {
  margin: 28px 28px 0;
  display: grid;
  gap: 20px;
}
.signatures.cols-3 { grid-template-columns: repeat(3, 1fr); }
.signatures.cols-2 { grid-template-columns: repeat(2, 1fr); }
.signatures.cols-4 { grid-template-columns: repeat(4, 1fr); }
/* Proveedor block — centered, not full-width */
.sig-prov-wrap {
  margin: 40px 28px 0;
  display: flex;
  justify-content: center;
}
.sig-prov-wrap .sig-item {
  width: 38%;
  min-width: 190px;
}
.sig-prov-wrap .sig-line { margin-top: 58px; }
.sig-item.sig-ext .sig-line { border-top-style: dashed; border-top-color: #94a3b8; }
.sig-item.sig-ext .sig-name { color: #475569; }
.sig-item.sig-ext .sig-role { color: #94a3b8; }
.sig-item.sig-ext::before {
  content: 'PROVEEDOR';
  display: block;
  font-size: 6pt;
  font-weight: 700;
  letter-spacing: .1em;
  color: #1B6BA8;
  border: 1px solid #bfdbfe;
  border-radius: 20px;
  padding: 1px 7px;
  text-align: center;
  margin-bottom: 4px;
  width: fit-content;
  margin-left: auto;
  margin-right: auto;
}
.sig-item { text-align: center; }
.sig-line {
  border-top: 1.5px solid #cbd5e1;
  margin-bottom: 5px;
  margin-top: 32px;
}
.sig-name {
  font-size: 8pt;
  font-weight: 600;
  color: #1a1a2e;
}
.sig-role {
  font-size: 7pt;
  color: #64748b;
  margin-top: 1px;
}

/* ── Footer ──────────────────────────────────────────────────────── */
.spacer { flex: 1; }
.doc-footer {
  margin-top: 24px;
  padding: 10px 28px;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.footer-left { font-size: 7pt; color: #94a3b8; }
.footer-right { font-size: 7pt; color: #94a3b8; text-align: right; }
.bottom-bar {
  height: 4px;
  background: linear-gradient(90deg, #E53935 0%, #1B6BA8 100%);
}

/* ── Multi-page wrapper (proceso completo) ───────────────────────── */
#all-pages { display: flex; flex-direction: column; }
@media screen { #all-pages .page + .page { margin-top: 24px; } }
@media print  { #all-pages .page + .page { page-break-before: always; } }

/* ── Toolbar (screen only) ───────────────────────────────────────── */
.print-toolbar {
  position: fixed; bottom: 24px; right: 24px;
  display: flex; gap: 10px; z-index: 999;
}
.btn-print {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 20px; border: none; border-radius: 8px; cursor: pointer;
  font-size: 10pt; font-weight: 600; font-family: inherit;
  box-shadow: 0 3px 14px rgba(0,0,0,.22);
  transition: transform .15s, box-shadow .15s;
}
.btn-print:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.28); }
.btn-print:disabled { opacity: .6; cursor: not-allowed; transform: none; }
.btn-print.primary { background: #1B6BA8; color: #fff; }
.btn-print.secondary { background: #fff; color: #374151; border: 1px solid #d1d5db; }

/* ── Letter format (constancias / RRHH) ──────────────────────── */
.letter-body { padding: 26px 42px; flex: 1; }
.letter-date { text-align: right; font-size: 9pt; color: #374151; margin-bottom: 20px; }
.letter-to { font-size: 9.5pt; color: #1a1a2e; margin-bottom: 6px; }
.letter-to strong { display: block; font-size: 10.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; color: #0D3F6A; }
.letter-separator { border: none; border-top: 1.5px solid #dbeafe; margin: 12px 0 16px; }
.letter-ref { font-size: 8pt; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: #1B6BA8; margin-bottom: 14px; }
.letter-p { font-size: 10pt; line-height: 1.8; text-align: justify; margin-bottom: 14px; color: #1a1a2e; }
.letter-p .emp-name { font-weight: 700; text-transform: uppercase; }
.letter-p .emp-id { font-family: 'Courier New', monospace; font-weight: 600; }
.letter-p .highlight { font-weight: 700; color: #0D3F6A; }
.letter-close { margin-top: 16px; font-size: 9.5pt; color: #374151; }
.signatures.cols-1 { grid-template-columns: 1fr; max-width: 260px; margin-left: auto; margin-right: auto; }
</style>
</head>
<body>

<!-- Screen-only toolbar -->
<div class="print-toolbar no-print" id="toolbar">
  <button class="btn-print secondary" onclick="window.close()">✕ Cerrar</button>
  <?php if (in_array($tipo, ['plv','pav','pbv']) && isset($_GET['emp_id'])): ?>
  <a class="btn-print primary" id="btn-download"
     href="<?= BASE_URL ?>pdf_download.php?tipo=<?= urlencode($tipo) ?>&id=<?= $id ?>&emp_id=<?= (int)$_GET['emp_id'] ?>">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
    Descargar PDF
  </a>
  <?php else: ?>
  <a class="btn-print primary" id="btn-download"
     href="<?= BASE_URL ?>pdf_download.php?tipo=<?= urlencode($tipo) ?>&id=<?= $id ?>">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
    Descargar PDF
  </a>
  <?php endif; ?>
</div>

<?php if ($tipo === 'plv'): ?>
<div id="all-pages">
<?php
$mesNombrePlv = $meses[$doc['mes'] - 1];
$quinceNamePlv = $doc['quincena'] === 'primera' ? '1ª Quincena (1–15)' : '2ª Quincena (16–31)';
foreach ($detalle as $i => $d):
    $safeEmpName = preg_replace('/[^\w]/', '_', $d['empleado_nombre']);
?>
<div class="page voucher" id="voucher-<?= $i ?>" data-empname="<?= htmlspecialchars($safeEmpName) ?>" data-empfull="<?= htmlspecialchars($d['empleado_nombre']) ?>">
  <div class="top-bar"></div>
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type">RECIBO DE QUINCENA</div>
      <div class="doc-number" style="font-size:13pt"><?= htmlspecialchars($mesNombrePlv . ' ' . $doc['anio']) ?></div>
      <div style="font-size:8.5pt;color:#64748b;margin-top:3px"><?= $quinceNamePlv ?></div>
    </div>
  </div>

  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($d['empleado_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($d['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($d['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($d['banco'] ?? '—') ?></div>
      </div>
      <?php if ($d['cuenta_banco']): ?>
      <div class="meta-item">
        <div class="meta-label">No. Cuenta</div>
        <div class="meta-value mono"><?= htmlspecialchars($d['cuenta_banco']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Pago</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px;color:#374151">Sueldo Mensual Base</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#374151"><?= lps($d['sueldo_base'] ?? ($d['total_bruto'] * 2)) ?></td>
        </tr>
        <tr style="border-bottom:2px solid #e2e8f0;background:#f0f5fc">
          <td style="padding:8px 8px;font-weight:700;color:#0D3F6A">Quincena Bruta</td>
          <td style="padding:8px 8px;text-align:right;font-family:'Courier New',monospace;font-weight:700;color:#0D3F6A"><?= lps($d['total_bruto']) ?></td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px 7px 20px;color:#64748b;font-size:8.5pt">IHSS (Empleado)</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626;font-size:8.5pt">(<?= lps($d['ihss_empleado']) ?>)</td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px 7px 20px;color:#64748b;font-size:8.5pt">RAP (Empleado)</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626;font-size:8.5pt">(<?= lps($d['rap_empleado']) ?>)</td>
        </tr>
        <?php if ($d['isr'] > 0): ?>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px 7px 20px;color:#64748b;font-size:8.5pt">ISR</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626;font-size:8.5pt">(<?= lps($d['isr']) ?>)</td>
        </tr>
        <?php endif; ?>
        <?php if ($d['credimpulsa'] > 0): ?>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px 7px 20px;color:#64748b;font-size:8.5pt">Credimpulsa</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626;font-size:8.5pt">(<?= lps($d['credimpulsa']) ?>)</td>
        </tr>
        <?php endif; ?>
        <tr style="border-bottom:2px solid #dc2626">
          <td style="padding:7px 8px;font-weight:600;color:#991b1b">Total Deducciones</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;font-weight:700;color:#dc2626">(<?= lps($d['total_deducciones']) ?>)</td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php $tieneDist = !empty($distV[$d['id']]);
  if ($tieneDist) echo voucherDistTabla($repartosV[$d['id']], $proyMap,
      ['pct_salario' => '%', 'total_bruto' => 'QUINCENA BRUTA', 'total_deducciones' => 'DEDUCCIONES', 'sueldo_neto' => 'NETO']); ?>

  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label">Total a Pagar</div>
      <div class="amount-value"><?= lps($d['sueldo_neto']) ?></div>
    </div>
  </div>

  <?php if (!empty($d['observacion'])): ?>
  <div style="margin:12px 28px 0;padding:10px 14px;border-left:3px solid #1B6BA8;background:#f0f7ff;border-radius:4px">
    <div class="obs-label" style="font-size:7.5pt;font-weight:700;color:#1B6BA8;margin-bottom:3px;text-transform:uppercase;letter-spacing:.05em">Observación</div>
    <div style="font-size:8.5pt;color:#374151"><?= htmlspecialchars($d['observacion']) ?></div>
  </div>
  <?php endif; ?>
  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($d['empleado_nombre'])) ?></strong><?php if ($d['identidad']): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($d['identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($d['sueldo_neto']) ?></strong> correspondiente a la
      <?= $quinceNamePlv ?> del período <strong><?= htmlspecialchars($mesNombrePlv . ' ' . $doc['anio']) ?></strong>,
      en conformidad con el detalle<?= $tieneDist ? ' y la distribución por proyecto' : '' ?> indicado<?= $tieneDist ? 's' : '' ?>.
    </p>
    <div style="display:grid;grid-template-columns:1fr 180px;gap:20px;margin-top:8px">
      <div style="text-align:center">
        <div style="border-top:1.5px solid #64748b;margin-bottom:5px;margin-top:36px"></div>
        <div style="font-size:8pt;font-weight:600"><?= htmlspecialchars($d['empleado_nombre']) ?></div>
        <div style="font-size:7.5pt;color:#64748b">Firma y nombre del empleado</div>
      </div>
      <div>
        <div style="font-size:8pt;color:#64748b;margin-bottom:4px">Fecha de recibo:</div>
        <div style="border-bottom:1px solid #64748b;height:28px"></div>
      </div>
    </div>
  </div>

  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">RECIBO — <?= htmlspecialchars($d['empleado_nombre']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endforeach; ?>
</div><!-- #all-pages plv -->

<?php elseif ($tipo === 'pav'): ?>
<div id="all-pages">
<?php
$mesesPAV = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$periodoLabel = isset($doc['mes']) ? ($mesesPAV[$doc['mes']-1] . ' ' . $doc['anio']) : '';
foreach ($detalle as $i => $d):
    $safeEmpName = preg_replace('/[^\w]/', '_', $d['emp_nombre']);
?>
<div class="page voucher" id="voucher-<?= $i ?>" data-empname="<?= htmlspecialchars($safeEmpName) ?>" data-empfull="<?= htmlspecialchars($d['emp_nombre']) ?>">
  <div class="top-bar"></div>
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type">RECIBO DE ASIGNACIÓN</div>
      <div class="doc-number" style="font-size:13pt"><?= htmlspecialchars($doc['numero']) ?></div>
      <?php if ($periodoLabel): ?><div style="font-size:8.5pt;color:#64748b;margin-top:3px"><?= htmlspecialchars($periodoLabel) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($d['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($d['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($d['cargo'] ?? '—') ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($d['banco'] ?? ''): ?>
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($d['banco']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($d['cuenta_banco'] ?? ''): ?>
      <div class="meta-item">
        <div class="meta-label">No. Cuenta</div>
        <div class="meta-value mono"><?= htmlspecialchars($d['cuenta_banco']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['descripcion'] ?? ''): ?>
      <div class="meta-item" style="grid-column:1/-1">
        <div class="meta-label">Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['descripcion']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Asignación</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:2px solid #e2e8f0;background:#f0f5fc">
          <td style="padding:8px;font-weight:700;color:#0D3F6A">Concepto</td>
          <td style="padding:8px;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($d['concepto']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label">Monto Asignado</div>
      <div class="amount-value"><?= lps($d['monto']) ?></div>
    </div>
  </div>

  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($d['emp_nombre'])) ?></strong><?php if ($d['identidad'] ?? ''): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($d['identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($d['monto']) ?></strong>
      en concepto de <em><?= htmlspecialchars($d['concepto']) ?></em>, en conformidad con lo indicado.
    </p>
    <div style="display:grid;grid-template-columns:1fr 180px;gap:20px;margin-top:8px">
      <div style="text-align:center">
        <div style="border-top:1.5px solid #64748b;margin-bottom:5px;margin-top:36px"></div>
        <div style="font-size:8pt;font-weight:600"><?= htmlspecialchars($d['emp_nombre']) ?></div>
        <div style="font-size:7.5pt;color:#64748b">Firma y nombre del empleado</div>
      </div>
      <div>
        <div style="font-size:8pt;color:#64748b;margin-bottom:4px">Fecha de recibo:</div>
        <div style="border-bottom:1px solid #64748b;height:28px"></div>
      </div>
    </div>
  </div>

  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">ASIGNACIÓN <?= htmlspecialchars($doc['numero']) ?> — <?= htmlspecialchars($d['emp_nombre']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endforeach; ?>
</div><!-- #all-pages pav -->

<?php elseif ($tipo === 'pbv'): ?>
<div id="all-pages">
<?php
$pbvTiposLabel = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes'];
$pbvLabel = $pbvTiposLabel[$doc['tipo']] ?? ucfirst($doc['tipo']);
foreach ($detalle as $i => $d):
    $safeEmpName = preg_replace('/[^\w]/', '_', $d['emp_nombre']);
?>
<div class="page voucher" id="voucher-<?= $i ?>" data-empname="<?= htmlspecialchars($safeEmpName) ?>" data-empfull="<?= htmlspecialchars($d['emp_nombre']) ?>">
  <div class="top-bar"></div>
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type">RECIBO DE BENEFICIO</div>
      <div class="doc-number" style="font-size:11pt"><?= htmlspecialchars($pbvLabel) ?></div>
      <div style="font-size:8.5pt;color:#64748b;margin-top:3px">Año <?= $doc['anio'] ?></div>
    </div>
  </div>

  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($d['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($d['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($d['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <?php if ($d['banco'] ?? ''): ?>
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($d['banco']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($d['cuenta_banco'] ?? ''): ?>
      <div class="meta-item">
        <div class="meta-label">No. Cuenta</div>
        <div class="meta-value mono"><?= htmlspecialchars($d['cuenta_banco']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Pago</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px;color:#374151">Décimo Cuarto</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#374151"><?= lps($d['sueldo_mensual']) ?></td>
        </tr>
        <?php if ($d['deduccion'] > 0): ?>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:7px 8px;color:#64748b">Deducción</td>
          <td style="padding:7px 8px;text-align:right;font-family:'Courier New',monospace;color:#dc2626">(<?= lps($d['deduccion']) ?>)</td>
        </tr>
        <?php endif; ?>
        <tr style="border-bottom:2px solid #16a34a;background:#f0fdf4">
          <td style="padding:8px;font-weight:700;color:#15803d">Total a Pagar (50%)</td>
          <td style="padding:8px;text-align:right;font-family:'Courier New',monospace;font-weight:800;color:#16a34a;font-size:11pt"><?= lps($d['neto']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php $tieneDist = !empty($distV[$d['id']]);
  if ($tieneDist) echo voucherDistTabla($repartosV[$d['id']], $proyMap,
      ['porcentaje' => '%', 'sueldo_mensual' => 'BENEFICIO', 'deduccion' => 'DEDUCCIÓN', 'neto' => 'NETO']); ?>

  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label"><?= htmlspecialchars($pbvLabel) ?></div>
      <div class="amount-value"><?= lps($d['neto']) ?></div>
    </div>
  </div>

  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($d['emp_nombre'])) ?></strong><?php if ($d['identidad'] ?? ''): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($d['identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($d['neto']) ?></strong>
      correspondiente al <em><?= htmlspecialchars($pbvLabel) ?></em> del año <strong><?= $doc['anio'] ?></strong>, en conformidad con lo indicado<?= $tieneDist ? ', incluida la distribución por proyecto' : '' ?>.
    </p>
    <div style="display:grid;grid-template-columns:1fr 180px;gap:20px;margin-top:8px">
      <div style="text-align:center">
        <div style="border-top:1.5px solid #64748b;margin-bottom:5px;margin-top:36px"></div>
        <div style="font-size:8pt;font-weight:600"><?= htmlspecialchars($d['emp_nombre']) ?></div>
        <div style="font-size:7.5pt;color:#64748b">Firma y nombre del empleado</div>
      </div>
      <div>
        <div style="font-size:8pt;color:#64748b;margin-bottom:4px">Fecha de recibo:</div>
        <div style="border-bottom:1px solid #64748b;height:28px"></div>
      </div>
    </div>
  </div>

  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right"><?= htmlspecialchars($pbvLabel) ?> <?= $doc['anio'] ?> — <?= htmlspecialchars($d['emp_nombre']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endforeach; ?>
</div><!-- #all-pages pbv -->

<?php elseif ($tipo === 'gva'): ?>
<!-- ══ VOUCHER DE ALIMENTACIÓN DE VIAJE ══ -->
<div class="page voucher">
  <div class="top-bar"></div>

  <!-- Header -->
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type">VOUCHER DE ALIMENTACIÓN</div>
      <div class="doc-number" style="font-size:13pt"><?= htmlspecialchars($docNum) ?></div>
      <div style="font-size:8pt;color:#64748b;margin-top:3px">
        <?= fmtFecha($doc['fecha_salida']) ?> – <?= fmtFecha($doc['fecha_regreso']) ?>
      </div>
    </div>
  </div>

  <!-- Meta: employee + trip info -->
  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <?php if ($doc['emp_identidad']): ?>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['emp_identidad']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['emp_cargo']): ?>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_cargo']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item">
        <div class="meta-label">Destino</div>
        <div class="meta-value"><?= htmlspecialchars($doc['destino']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Salida</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_salida']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Regreso</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_regreso']) ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['banco']): ?>
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($doc['banco']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['cuenta_banco']): ?>
      <div class="meta-item">
        <div class="meta-label">No. Cuenta</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['cuenta_banco']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Detail: single alimentación line -->
  <div class="section-head">Detalle del Beneficio</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:1px solid #e2e8f0">
          <td style="padding:8px">Anticipo de Viaje de Referencia</td>
          <td style="padding:8px;text-align:right;font-family:'Courier New',monospace;color:#374151"><?= htmlspecialchars($doc['numero']) ?></td>
        </tr>
        <tr style="border-bottom:1px solid #e2e8f0;background:#f0f5fc">
          <td style="padding:8px;font-weight:700;color:#0D3F6A">Viáticos de Alimentación</td>
          <td style="padding:8px;text-align:right;font-family:'Courier New',monospace;font-weight:700;color:#0D3F6A"><?= lps($doc['monto_alimentacion']) ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <!-- Amount highlight -->
  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label">Monto de Alimentación a Pagar</div>
      <div class="amount-value"><?= lps($doc['monto_alimentacion']) ?></div>
    </div>
  </div>

  <!-- Receipt / signature box -->
  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($doc['emp_nombre'])) ?></strong><?php if ($doc['emp_identidad']): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($doc['emp_identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($doc['monto_alimentacion']) ?></strong>
      en concepto de <em>viáticos de alimentación</em> correspondientes al viaje a
      <strong><?= htmlspecialchars($doc['destino']) ?></strong>
      (<?= fmtFecha($doc['fecha_salida']) ?> – <?= fmtFecha($doc['fecha_regreso']) ?>),
      según anticipo <strong><?= htmlspecialchars($doc['numero']) ?></strong>.
    </p>
    <div style="display:grid;grid-template-columns:1fr 180px;gap:20px;margin-top:8px">
      <div style="text-align:center">
        <div style="border-top:1.5px solid #64748b;margin-bottom:5px;margin-top:36px"></div>
        <div style="font-size:8pt;font-weight:600"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
        <div style="font-size:7.5pt;color:#64748b">Firma y nombre del empleado</div>
      </div>
      <div>
        <div style="font-size:8pt;color:#64748b;margin-bottom:4px">Fecha de recibo:</div>
        <div style="border-bottom:1px solid #64748b;height:28px"></div>
      </div>
    </div>
  </div>

  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">ALIMENTACIÓN — <?= htmlspecialchars($doc['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>

<?php elseif ($tipo === 'proceso'): ?>
<div id="all-pages">

<!-- ══ PÁGINA 1: SOLICITUD DE COMPRA ══ -->
<div class="page">
  <div class="top-bar"></div>
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
        <?php if ($orgTel): ?><div class="org-sub">Tel: <?= htmlspecialchars($orgTel) ?></div><?php endif; ?>
        <?php if ($orgEmail): ?><div class="org-sub"><?= htmlspecialchars($orgEmail) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type">SOLICITUD DE COMPRA</div>
      <div class="doc-number"><?= htmlspecialchars($proc_sc['numero']) ?></div>
      <div><span class="doc-estado est-<?= $proc_sc['estado'] ?>"><?= ucfirst($proc_sc['estado']) ?></span></div>
    </div>
  </div>
  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item"><div class="meta-label">Fecha</div><div class="meta-value"><?= fmtFecha($proc_sc['fecha']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Solicitante</div><div class="meta-value"><?= htmlspecialchars($proc_sc['sol_nombre']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Proyecto</div><div class="meta-value"><?= htmlspecialchars($proc_sc['proy_nombre'] ?? '—') ?></div></div>
      <div class="meta-item"><div class="meta-label">Urgencia</div><div class="meta-value"><?= str_replace('_',' ',ucfirst($proc_sc['urgencia'])) ?></div></div>
      <div class="meta-item meta-full"><div class="meta-label">Descripción</div><div class="meta-value"><?= htmlspecialchars($proc_sc['descripcion']) ?></div></div>
      <?php if ($proc_sc['justificacion']): ?><div class="meta-item meta-full"><div class="meta-label">Justificación</div><div class="meta-value"><?= htmlspecialchars($proc_sc['justificacion']) ?></div></div><?php endif; ?>
    </div>
  </div>
  <div class="section-head">Artículos / Servicios Requeridos</div>
  <div class="items-wrap">
    <table class="items">
      <thead><tr><th style="width:2.5em">#</th><th>Descripción</th><th>Unidad</th><th class="r">Cantidad</th><th class="r">Precio Est.</th><th class="r">Total Est.</th></tr></thead>
      <tbody>
        <?php $i=1; $tot=0; foreach ($proc_sc_det as $d): $sub=($d['cantidad']??1)*($d['precio_estimado']??0); $tot+=$sub; ?>
        <tr><td class="c"><?= $i++ ?></td><td><?= htmlspecialchars($d['descripcion']) ?></td><td class="c"><?= htmlspecialchars($d['unidad']??'') ?></td><td class="r"><?= number_format($d['cantidad']??1,2) ?></td><td class="r"><?= lps($d['precio_estimado']??0) ?></td><td class="r"><?= lps($sub) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">PRESUPUESTO ESTIMADO TOTAL</td><td class="r"><?= lps($tot) ?></td></tr></tfoot>
    </table>
  </div>
  <?= sigBlock($fSC) ?>
  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">SOLICITUD DE COMPRA — <?= htmlspecialchars($proc_sc['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>

<?php if ($proc_oc): ?>
<!-- ══ PÁGINA 2: ORDEN DE COMPRA ══ -->
<div class="page">
  <div class="top-bar"></div>
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
        <?php if ($orgTel): ?><div class="org-sub">Tel: <?= htmlspecialchars($orgTel) ?></div><?php endif; ?>
        <?php if ($orgEmail): ?><div class="org-sub"><?= htmlspecialchars($orgEmail) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type">ORDEN DE COMPRA</div>
      <div class="doc-number"><?= htmlspecialchars($proc_oc['numero']) ?></div>
      <div><span class="doc-estado est-<?= $proc_oc['estado'] ?>"><?= ucfirst($proc_oc['estado']) ?></span></div>
    </div>
  </div>
  <div class="ref-chain">
    <span class="ref-pill">📋 <?= htmlspecialchars($proc_sc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">🛒 <?= htmlspecialchars($proc_oc['numero']) ?></span>
  </div>
  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item"><div class="meta-label">Fecha Emisión</div><div class="meta-value"><?= fmtFecha($proc_oc['fecha']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Fecha Entrega</div><div class="meta-value"><?= fmtFecha($proc_oc['fecha_entrega']??'') ?></div></div>
      <div class="meta-item"><div class="meta-label">Condiciones de Pago</div><div class="meta-value"><?= htmlspecialchars($proc_oc['condiciones_pago']??'—') ?></div></div>
      <div class="meta-item"><div class="meta-label">Lugar de Entrega</div><div class="meta-value"><?= htmlspecialchars($proc_oc['lugar_entrega']??'—') ?></div></div>
      <div class="meta-item" style="grid-column:1/3"><div class="meta-label">Proveedor</div><div class="meta-value"><?= htmlspecialchars($proc_oc['prov_nombre']) ?></div></div>
      <div class="meta-item"><div class="meta-label">RTN Proveedor</div><div class="meta-value mono"><?= htmlspecialchars($proc_oc['prov_rtn']??'—') ?></div></div>
    </div>
  </div>
  <div class="section-head">Ítems de la Orden</div>
  <div class="items-wrap">
    <table class="items">
      <thead><tr><th style="width:2.5em">#</th><th>Descripción</th><th>Unidad</th><th class="r">Cantidad</th><th class="r">Precio Unit.</th><th class="r">Total</th></tr></thead>
      <tbody>
        <?php $i=1; foreach ($proc_oc_det as $d): ?>
        <tr><td class="c"><?= $i++ ?></td><td><?= htmlspecialchars($d['descripcion']) ?></td><td class="c"><?= htmlspecialchars($d['unidad']??'') ?></td><td class="r"><?= number_format($d['cantidad']??1,2) ?></td><td class="r"><?= lps($d['precio_unitario']) ?></td><td class="r"><?= lps($d['total']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Subtotal</td><td class="r"><?= lps($proc_oc['subtotal']) ?></td></tr>
        <?php if (($proc_oc['descuento']??0) > 0): ?>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Descuento</td><td class="r">-<?= lps($proc_oc['descuento']) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Impuesto ISV</td><td class="r"><?= lps($proc_oc['impuestos']) ?></td></tr>
        <?php if (($proc_oc['impuesto_turismo']??0) > 0): ?>
        <tr><td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Impuesto Turismo</td><td class="r"><?= lps($proc_oc['impuesto_turismo']) ?></td></tr>
        <?php endif; ?>
        <tr><td colspan="5" style="text-align:right;padding-right:12px">TOTAL</td><td class="r"><?= lps($proc_oc['total']) ?></td></tr>
      </tfoot>
    </table>
  </div>
  <?php
  echo sigBlock($fOC);
  echo sigProvBlock($proc_oc['prov_nombre'] ?? '', 'Nombre, Firma y Sello');
  ?>
  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">ORDEN DE COMPRA — <?= htmlspecialchars($proc_oc['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endif; /* proc_oc */ ?>

<?php if ($proc_op): ?>
<!-- ══ PÁGINA 3: ORDEN DE PAGO ══ -->
<div class="page">
  <div class="top-bar"></div>
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
        <?php if ($orgTel): ?><div class="org-sub">Tel: <?= htmlspecialchars($orgTel) ?></div><?php endif; ?>
        <?php if ($orgEmail): ?><div class="org-sub"><?= htmlspecialchars($orgEmail) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type">ORDEN DE PAGO</div>
      <div class="doc-number"><?= htmlspecialchars($proc_op['numero']) ?></div>
      <div><span class="doc-estado est-<?= $proc_op['estado'] ?>"><?= ucfirst($proc_op['estado']) ?></span></div>
    </div>
  </div>
  <div class="ref-chain">
    <span class="ref-pill">📋 <?= htmlspecialchars($proc_sc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill">🛒 <?= htmlspecialchars($proc_oc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">💳 <?= htmlspecialchars($proc_op['numero']) ?></span>
  </div>
  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item"><div class="meta-label">Fecha</div><div class="meta-value"><?= fmtFecha($proc_op['fecha']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Forma de Pago</div><div class="meta-value"><?= ucfirst($proc_op['forma_pago']) ?></div></div>
      <?php if ($proc_op['numero_cheque']): ?><div class="meta-item"><div class="meta-label">N° Cheque</div><div class="meta-value mono"><?= htmlspecialchars($proc_op['numero_cheque']) ?></div></div><?php endif; ?>
      <?php if ($proc_op['factura_proveedor']): ?><div class="meta-item"><div class="meta-label">N° Factura Proveedor</div><div class="meta-value mono"><?= htmlspecialchars($proc_op['factura_proveedor']) ?></div></div><?php endif; ?>
      <div class="meta-item"><div class="meta-label">Fecha de Pago</div><div class="meta-value"><?= fmtFecha($proc_op['fecha_pago']??'') ?></div></div>
      <div class="meta-item" style="grid-column:1/3"><div class="meta-label">Beneficiario</div><div class="meta-value" style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($proc_op['beneficiario']) ?></div></div>
      <div class="meta-item"><div class="meta-label">Banco</div><div class="meta-value"><?= htmlspecialchars($proc_op['banco']??'—') ?></div></div>
      <div class="meta-item"><div class="meta-label">Cuenta Bancaria</div><div class="meta-value mono"><?= htmlspecialchars($proc_op['cuenta_bancaria']??'—') ?></div></div>
      <div class="meta-item meta-full"><div class="meta-label">Concepto</div><div class="meta-value"><?= htmlspecialchars($proc_op['concepto']) ?></div></div>
    </div>
  </div>
  <div class="amount-block">
    <div><div class="amount-label">Monto a Pagar</div><div class="amount-value"><?= lps($proc_op['monto']) ?></div></div>
  </div>
  <?= sigBlock($fOP) ?>
  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">ORDEN DE PAGO — <?= htmlspecialchars($proc_op['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endif; /* proc_op */ ?>

</div><!-- #all-pages -->
<?php else: /* sc, oc, op, pl, gv — single page */
$sgHasAlim = ($tipo === 'sg' && ($doc['monto_alimentacion'] ?? 0) > 0);
?>
<?php if ($sgHasAlim): ?><div id="all-pages"><?php endif; ?>
<div class="page">
  <div class="top-bar"></div>

  <!-- Header -->
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?>
        <img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?>
        <div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div>
      <?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
        <?php if ($orgTel): ?><div class="org-sub">Tel: <?= htmlspecialchars($orgTel) ?></div><?php endif; ?>
        <?php if ($orgEmail): ?><div class="org-sub"><?= htmlspecialchars($orgEmail) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type"><?= $title ?></div>
      <div class="doc-number"><?= htmlspecialchars($docNum) ?></div>
      <?php
      $estado = $doc['estado'] ?? '';
      $estClass = 'est-' . ($estado ?: 'pendiente');
      if ($estado): ?>
      <div><span class="doc-estado <?= htmlspecialchars($estClass) ?>"><?= ucfirst($estado) ?></span></div>
      <?php endif; ?>
    </div>
  </div>

  <?php /* ══════════════ SOLICITUD DE COMPRA ══════════════ */ if ($tipo === 'sc'): ?>

  <?php if ($doc['sc_numero'] ?? false): ?>
  <div class="ref-chain">
    <span class="ref-pill ref-current">📋 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>
  <?php endif; ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Solicitante</div>
        <div class="meta-value"><?= htmlspecialchars($doc['sol_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Urgencia</div>
        <div class="meta-value"><?= str_replace('_',' ',ucfirst($doc['urgencia'])) ?></div>
      </div>
      <div class="meta-item meta-full">
        <div class="meta-label">Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['descripcion']) ?></div>
      </div>
      <?php if ($doc['justificacion']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Justificación</div>
        <div class="meta-value"><?= htmlspecialchars($doc['justificacion']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Artículos / Servicios Requeridos</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Descripción</th>
          <th>Unidad</th>
          <th class="r">Cantidad</th>
          <th class="r">Precio Est.</th>
          <th class="r">Total Est.</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; $tot=0; foreach ($detalle as $d):
          $sub = ($d['cantidad'] ?? 1) * ($d['precio_estimado'] ?? 0); $tot += $sub; ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td class="c"><?= htmlspecialchars($d['unidad'] ?? '') ?></td>
          <td class="r"><?= number_format($d['cantidad'] ?? 1, 2) ?></td>
          <td class="r"><?= lps($d['precio_estimado'] ?? 0) ?></td>
          <td class="r"><?= lps($sub) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt;">PRESUPUESTO ESTIMADO TOTAL</td>
          <td class="r"><?= lps($tot) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fSC) ?>

  <?php /* ══════════════ ORDEN DE COMPRA ══════════════ */ elseif ($tipo === 'oc'): ?>

  <?php if ($doc['sc_numero']): ?>
  <div class="ref-chain">
    <span class="ref-pill">📋 <?= htmlspecialchars($doc['sc_numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">🛒 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>
  <?php endif; ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha Emisión</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha Entrega</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_entrega'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Condiciones de Pago</div>
        <div class="meta-value"><?= htmlspecialchars($doc['condiciones_pago'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Lugar de Entrega</div>
        <div class="meta-value"><?= htmlspecialchars($doc['lugar_entrega'] ?? '—') ?></div>
      </div>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Proveedor</div>
        <div class="meta-value"><?= htmlspecialchars($doc['prov_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">RTN Proveedor</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['prov_rtn'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre'] ?? '—') ?></div>
      </div>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Ítems de la Orden</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Descripción</th>
          <th>Unidad</th>
          <th class="r">Cantidad</th>
          <th class="r">Precio Unit.</th>
          <th class="r">Total</th>
          <th>Cuenta</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td class="c"><?= htmlspecialchars($d['unidad'] ?? '') ?></td>
          <td class="r"><?= number_format($d['cantidad'] ?? 1, 2) ?></td>
          <td class="r"><?= lps($d['precio_unitario']) ?></td>
          <td class="r"><?= lps($d['total']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cta_nombre'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;font-size:8pt;color:#64748b">Subtotal</td>
          <td class="r"><?= lps($doc['subtotal']) ?></td>
          <td></td>
        </tr>
        <?php if (($doc['descuento']??0) > 0): ?>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;font-size:8pt;color:#64748b">Descuento</td>
          <td class="r">-<?= lps($doc['descuento']) ?></td>
          <td></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;font-size:8pt;color:#64748b">Impuesto ISV</td>
          <td class="r"><?= lps($doc['impuestos']) ?></td>
          <td></td>
        </tr>
        <?php if (($doc['impuesto_turismo']??0) > 0): ?>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px;font-size:8pt;color:#64748b">Impuesto Turismo</td>
          <td class="r"><?= lps($doc['impuesto_turismo']) ?></td>
          <td></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td colspan="5" style="text-align:right;padding-right:12px">TOTAL</td>
          <td class="r"><?= lps($doc['total']) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php
  echo sigBlock($fOC);
  echo sigProvBlock($doc['prov_nombre'] ?? '', 'Nombre, Firma y Sello');
  ?>

  <?php /* ══════════════ ORDEN DE PAGO ══════════════ */ elseif ($tipo === 'op'): ?>

  <?php if ($doc['sc_numero'] || $doc['oc_numero'] || $doc['sg_numero'] || $doc['origen_numero']): ?>
  <div class="ref-chain">
    <?php foreach (['📋' => $doc['sc_numero'], '🛒' => $doc['oc_numero'], '✈' => $doc['gv_numero'], '🧾' => $doc['sg_numero'], '📄' => $doc['origen_numero']] as $ico => $ref): if (!$ref) continue; ?>
      <span class="ref-pill"><?= $ico ?> <?= htmlspecialchars($ref) ?></span>
      <span class="ref-arrow">→</span>
    <?php endforeach; ?>
    <span class="ref-pill ref-current">💳 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>
  <?php endif; ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Forma de Pago</div>
        <div class="meta-value"><?= ucfirst($doc['forma_pago']) ?></div>
      </div>
      <?php if ($doc['numero_cheque']): ?>
      <div class="meta-item">
        <div class="meta-label">Número de Cheque</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['numero_cheque']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['factura_proveedor']): ?>
      <div class="meta-item">
        <div class="meta-label">N° Factura Proveedor</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['factura_proveedor']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <?php if ($doc['proceso_id']): ?>
      <div class="meta-item">
        <div class="meta-label">Proceso</div>
        <div class="meta-value mono"><?= formatProceso((int)$doc['proceso_id']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Beneficiario (<?= ['proveedor' => 'Proveedor', 'empleado' => 'Empleado', 'otro' => 'Otro'][$doc['beneficiario_tipo']] ?? 'Otro' ?>)</div>
        <div class="meta-value" style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($doc['beneficiario']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Banco</div>
        <div class="meta-value"><?= htmlspecialchars($doc['banco'] ?: '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cuenta Bancaria<?= $doc['banco_tipo'] ? ' (' . ucfirst($doc['banco_tipo']) . ')' : '' ?></div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['cuenta_bancaria'] ?: '—') ?></div>
      </div>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= $doc['proy_nombre'] ? htmlspecialchars($doc['proy_codigo'] . ' — ' . $doc['proy_nombre']) : 'General' ?></div>
      </div>
      <div class="meta-item" style="grid-column:3/5">
        <div class="meta-label">Cuenta Contable</div>
        <div class="meta-value"><?= $doc['cuenta_nombre'] ? htmlspecialchars($doc['cuenta_codigo'] . ' — ' . $doc['cuenta_nombre']) : '—' ?></div>
      </div>
      <div class="meta-item meta-full">
        <div class="meta-label">Concepto / Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['concepto']) ?></div>
      </div>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="amount-block">
    <div>
      <div class="amount-label">Monto a Pagar</div>
      <div class="amount-value"><?= lps($doc['monto']) ?></div>
    </div>
  </div>

  <?= sigBlock($fOP) ?>

  <?php /* ══════════════ PLANILLA ══════════════ */ elseif ($tipo === 'pl'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Período</div>
        <div class="meta-value"><?= $meses[$doc['mes'] - 1] . ' ' . $doc['anio'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Quincena</div>
        <div class="meta-value"><?= $doc['quincena'] === 'primera' ? '1ª (1-15)' : '2ª (16-31)' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">No. Empleados</div>
        <div class="meta-value"><?= count($detalle) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Detalle por Empleado</div>
  <div class="items-wrap">
    <table class="items" style="font-size:7.5pt">
      <thead>
        <tr>
          <th style="width:2em">#</th>
          <th>Empleado</th>
          <th>Cargo</th>
          <th class="r">Q. Bruta</th>
          <th class="r">IHSS</th>
          <th class="r">RAP</th>
          <th class="r">ISR</th>
          <th class="r">Credimpulsa</th>
          <th class="r">Ded. Total</th>
          <th class="r">Neto</th>
          <th class="r">IHSS Pat.</th>
          <th class="r">RAP Pat.</th>
          <th>Obs.</th>
          <th>Banco / Cuenta</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $i=1;
        $totBruto=$totIhssE=$totRap=$totIsr=$totCredimpulsa=$totDed=$totNeto=$totIhssP=$totRapP=0;
        foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['empleado_nombre']) ?></td>
          <td style="font-size:7pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r"><?= lps($d['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($d['ihss_empleado']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($d['rap_empleado']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($d['isr']) ?></td>
          <td class="r" style="color:#dc2626"><?= $d['credimpulsa'] > 0 ? lps($d['credimpulsa']) : '<span style="color:#94a3b8">—</span>' ?></td>
          <td class="r" style="color:#dc2626;font-weight:600"><?= lps($d['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($d['sueldo_neto']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($d['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($d['rap_patronal']) ?></td>
          <td style="font-size:7pt;color:#374151"><?= htmlspecialchars($d['observacion'] ?? '') ?></td>
          <td style="font-size:7pt">
            <?= htmlspecialchars($d['banco'] ?? '—') ?>
            <?php if ($d['cuenta_banco']): ?>
            <br><span style="color:#64748b;font-size:6.5pt;font-family:'Courier New',monospace"><?= htmlspecialchars($d['cuenta_banco']) ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php
          $totBruto += $d['total_bruto']; $totIhssE += $d['ihss_empleado'];
          $totRap   += $d['rap_empleado']; $totIsr  += $d['isr'];
          $totCredimpulsa += $d['credimpulsa'];
          $totDed   += $d['total_deducciones']; $totNeto += $d['sueldo_neto'];
          $totIhssP += $d['ihss_patronal']; $totRapP += $d['rap_patronal'];
        endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="font-weight:700;color:#0D3F6A">TOTALES</td>
          <td class="r"><?= lps($totBruto) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($totIhssE) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($totRap) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($totIsr) ?></td>
          <td class="r" style="color:#dc2626"><?= $totCredimpulsa > 0 ? lps($totCredimpulsa) : '—' ?></td>
          <td class="r" style="color:#dc2626;font-weight:700"><?= lps($totDed) ?></td>
          <td class="r" style="color:#16a34a;font-weight:700"><?= lps($totNeto) ?></td>
          <td class="r" style="color:#b45309"><?= lps($totIhssP) ?></td>
          <td class="r" style="color:#b45309"><?= lps($totRapP) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fPL, 'cols-3', 'margin-top:20px') ?>

  <?php /* ══════════════ PLANILLA POR PROYECTO ══════════════ */ elseif ($tipo === 'plp'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Período</div>
        <div class="meta-value"><?= $meses[$doc['mes'] - 1] . ' ' . $doc['anio'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Quincena</div>
        <div class="meta-value"><?= $doc['quincena'] === 'primera' ? '1ª (1-15)' : '2ª (16-31)' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">No. Proyectos</div>
        <div class="meta-value"><?= count($resumenProy) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Resumen por Proyecto</div>
  <div class="items-wrap">
    <table class="items" style="font-size:8pt">
      <thead>
        <tr>
          <th>Proyecto</th><th class="r">Empleados</th><th class="r">Bruto</th><th class="r">Deducciones</th>
          <th class="r">Neto a Pagar</th><th class="r">IHSS Pat.</th><th class="r">RAP Pat.</th><th class="r">Costo Total</th>
        </tr>
      </thead>
      <tbody>
        <?php $tR = ['total_bruto'=>0,'total_deducciones'=>0,'sueldo_neto'=>0,'ihss_patronal'=>0,'rap_patronal'=>0,'costo_total'=>0];
        foreach ($resumenProy as $pid => $r): foreach ($tR as $k => $_) $tR[$k] += $r[$k]; ?>
        <tr>
          <td><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></td>
          <td class="r"><?= $r['empleados'] ?></td>
          <td class="r"><?= lps($r['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($r['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($r['sueldo_neto']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($r['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($r['rap_patronal']) ?></td>
          <td class="r" style="font-weight:700"><?= lps($r['costo_total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="font-weight:700;color:#0D3F6A">TOTALES</td>
          <td class="r"><?= lps($tR['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($tR['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a"><?= lps($tR['sueldo_neto']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($tR['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($tR['rap_patronal']) ?></td>
          <td class="r"><?= lps($tR['costo_total']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php foreach ($resumenProy as $pid => $r): ?>
  <div class="section-head" style="margin-top:12px"><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></div>
  <div class="items-wrap">
    <table class="items" style="font-size:7.5pt">
      <thead>
        <tr>
          <th style="width:2em">#</th><th>Empleado</th><th>Cargo</th>
          <th class="r">% Sal.</th><th class="r">Bruto</th><th class="r">Deducciones</th><th class="r">Neto</th>
          <th class="r">% Pat.</th><th class="r">IHSS Pat.</th><th class="r">RAP Pat.</th><th class="r">Costo Total</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($detalle as $d): if (!isset($repartos[$d['id']][$pid])) continue; $x = $repartos[$d['id']][$pid]; ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['empleado_nombre']) ?></td>
          <td style="font-size:7pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r"><?= number_format($x['pct_salario'], 2) ?>%</td>
          <td class="r"><?= lps($x['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($x['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($x['sueldo_neto']) ?></td>
          <td class="r"><?= number_format($x['pct_patronal'], 2) ?>%</td>
          <td class="r" style="color:#b45309"><?= lps($x['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($x['rap_patronal']) ?></td>
          <td class="r" style="font-weight:600"><?= lps($x['costo_total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="font-weight:700;color:#0D3F6A">SUBTOTAL</td>
          <td class="r"><?= lps($r['total_bruto']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($r['total_deducciones']) ?></td>
          <td class="r" style="color:#16a34a"><?= lps($r['sueldo_neto']) ?></td>
          <td></td>
          <td class="r" style="color:#b45309"><?= lps($r['ihss_patronal']) ?></td>
          <td class="r" style="color:#b45309"><?= lps($r['rap_patronal']) ?></td>
          <td class="r"><?= lps($r['costo_total']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endforeach; ?>

  <?= sigBlock($fPL, 'cols-3', 'margin-top:20px') ?>

  <?php /* ══════════════ REPORTE CREDIMPULSA ══════════════ */ elseif ($tipo === 'plc'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Período</div>
        <div class="meta-value"><?= $meses[$doc['mes'] - 1] . ' ' . $doc['anio'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Quincena</div>
        <div class="meta-value"><?= $doc['quincena'] === 'primera' ? '1ª (1-15)' : '2ª (16-31)' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Empleados con Deducción</div>
        <div class="meta-value"><?= count($detalle) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Detalle de Deducciones Credimpulsa</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2em">#</th>
          <th>Empleado</th>
          <th>N° Identidad</th>
          <th>Cargo</th>
          <th class="r">Deducción Credimpulsa</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; $totCred=0; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['empleado_nombre']) ?></td>
          <td class="c" style="font-family:'Courier New',monospace;font-size:8pt"><?= htmlspecialchars($d['identidad'] ?? '—') ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r" style="color:#dc2626;font-weight:600"><?= lps($d['credimpulsa']) ?></td>
        </tr>
        <?php $totCred += $d['credimpulsa']; endforeach; ?>
        <?php if (!$detalle): ?>
        <tr><td colspan="5" style="text-align:center;padding:16px;color:#94a3b8;font-style:italic">No hay deducciones Credimpulsa para este período.</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px;font-weight:700;color:#0D3F6A">TOTAL CREDIMPULSA</td>
          <td class="r" style="color:#dc2626;font-weight:800;font-size:10pt"><?= lps($totCred) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="amount-block" style="margin:16px 28px">
    <div>
      <div class="amount-label">Total Deducido Credimpulsa</div>
      <div class="amount-value"><?= lps($totCred ?? 0) ?></div>
    </div>
    <div style="margin-left:auto;text-align:right;padding-right:8px">
      <div class="amount-label">Período</div>
      <div style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($docNum) ?></div>
    </div>
  </div>

  <?= sigBlock($fPLC, 'cols-3', 'margin-top:20px') ?>

  <?php /* ══════════════ GASTO DE VIAJE ══════════════ */ elseif ($tipo === 'gv'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Empleado</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Concepto / Insumo a Comprar' : 'Destino' ?></div>
        <div class="meta-value"><?= htmlspecialchars($doc['destino']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Fecha del Anticipo' : 'Fecha de Salida' ?></div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_salida']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Fecha Límite de Liquidación' : 'Fecha de Regreso' ?></div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_regreso']) ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item">
        <div class="meta-label">Monto del Anticipo</div>
        <div class="meta-value mono"><?= lps($doc['viaticos_anticipados']) ?></div>
      </div>
      <?php if ($doc['tipo'] !== 'compra' && !empty($doc['monto_alimentacion']) && $doc['monto_alimentacion'] > 0): ?>
      <div class="meta-item">
        <div class="meta-label">Alimentación</div>
        <div class="meta-value mono"><?= lps($doc['monto_alimentacion']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['proposito']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Justificación de la Compra' : 'Propósito del Viaje' ?></div>
        <div class="meta-value"><?= htmlspecialchars($doc['proposito']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Desglose del Anticipo Solicitado</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Descripción del Gasto</th>
          <th>Cuenta Contable</th>
          <th class="r">Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?php
            $ctaLabel = $d['cuenta_nombre']
                ? trim(($d['cuenta_codigo'] ?? '') . ' ' . $d['cuenta_nombre'])
                : '—';
            echo htmlspecialchars($ctaLabel);
          ?></td>
          <td class="r"><?= lps($d['monto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="text-align:right;padding-right:12px;font-weight:700">Total Anticipo Solicitado</td>
          <td class="r" style="font-weight:700;color:#16a34a"><?= lps($doc['viaticos_anticipados']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fGV) ?>

  <?php /* ══════════════ SOLICITUD DE GASTOS ══════════════ */ elseif ($tipo === 'sg'): ?>

  <?php if ($doc['gv_numero']): ?>
  <div class="ref-chain">
    <span class="ref-pill"><?= ($doc['gv_tipo'] ?? '') === 'compra' ? '📦' : '✈' ?> <?= htmlspecialchars($doc['gv_numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">📄 <?= htmlspecialchars($doc['numero']) ?></span>
  </div>
  <?php endif; ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Empleado</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['gv_numero']): ?>
      <div class="meta-item">
        <div class="meta-label">Anticipo<?= ($doc['gv_tipo'] ?? '') === 'compra' ? ' de Compra' : ' de Viaje' ?></div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['gv_numero']) ?> — <?= lps($doc['viaticos_anticipados']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['descripcion']) ?></div>
      </div>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas / Justificación</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Gastos</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Fecha</th>
          <th>Descripción</th>
          <th>Cuenta Contable</th>
          <th class="r">Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= fmtFecha($d['fecha'] ?? '') ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?php
            $ctaLabel = $d['cuenta_nombre']
                ? trim(($d['cuenta_codigo'] ?? '') . ' ' . $d['cuenta_nombre'])
                : '—';
            echo htmlspecialchars($ctaLabel);
          ?></td>
          <td class="r"><?= lps($d['monto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">TOTAL GASTOS</td>
          <td class="r"><?= lps($doc['monto_total']) ?></td>
        </tr>
        <?php if ($doc['gv_numero']): ?>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">Anticipo de Viaje Recibido</td>
          <td class="r"><?= lps($doc['viaticos_anticipados']) ?></td>
        </tr>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px">
            <?= $doc['monto_total'] <= $doc['viaticos_anticipados'] ? 'Saldo a Reintegrar' : 'Diferencia a Pagar' ?>
          </td>
          <td class="r" style="color:<?= $doc['monto_total'] <= $doc['viaticos_anticipados'] ? '#16a34a' : '#dc2626' ?>;font-weight:700">
            <?= lps(abs($doc['monto_total'] - $doc['viaticos_anticipados'])) ?>
          </td>
        </tr>
        <?php endif; ?>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fSG) ?>

  <?php /* ══════════════ PLANILLA DE ASIGNACIÓN ══════════════ */ elseif ($tipo === 'pa'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Período</div>
        <div class="meta-value"><?php
          $mesesPrint = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
          echo $mesesPrint[$doc['mes']-1] . ' ' . $doc['anio'];
        ?></div>
      </div>
      <?php if ($doc['descripcion']): ?>
      <div class="meta-item" style="grid-column:2/5">
        <div class="meta-label">Descripción</div>
        <div class="meta-value"><?= htmlspecialchars($doc['descripcion']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['proy_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Proyecto</div>
        <div class="meta-value"><?= htmlspecialchars($doc['proy_nombre']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle por Empleado</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2.5em">#</th>
          <th>Empleado</th>
          <th>Cargo</th>
          <th>Concepto de Asignación</th>
          <th class="r">Monto</th>
          <th>Banco / Cuenta</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; $totPA = 0; foreach ($detalle as $d): $totPA += $d['monto']; ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['emp_nombre']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td><?= htmlspecialchars($d['concepto']) ?></td>
          <td class="r"><?= lps($d['monto']) ?></td>
          <td style="font-size:7.5pt"><?= htmlspecialchars($d['banco'] ?? '—') ?>
            <?php if ($d['cuenta_banco']): ?><br><span style="color:#64748b;font-size:6.5pt;font-family:'Courier New',monospace"><?= htmlspecialchars($d['cuenta_banco']) ?></span><?php endif; ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="text-align:right;padding-right:12px;color:#64748b;font-size:8pt">TOTAL ASIGNADO</td>
          <td class="r"><?= lps($totPA) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fPA) ?>

  <?php /* ══════════════ PLANILLA DE BENEFICIOS ══════════════ */ elseif ($tipo === 'pb'): ?>

  <?php $pbTiposLabel = ['decimo_tercero' => 'Décimo Tercer Mes', 'decimo_cuarto' => 'Décimo Cuarto Mes']; ?>
  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Tipo</div>
        <div class="meta-value"><?= $pbTiposLabel[$doc['tipo']] ?? ucfirst($doc['tipo']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Año</div>
        <div class="meta-value"><?= $doc['anio'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">No. Empleados</div>
        <div class="meta-value"><?= count($detalle) ?></div>
      </div>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle por Empleado</div>
  <div class="items-wrap">
    <table class="items" style="font-size:8.5pt">
      <thead>
        <tr>
          <th style="width:2em">#</th>
          <th>Empleado</th>
          <th>Cargo</th>
          <th class="r">Décimo Cuarto</th>
          <th class="r">Deducción</th>
          <th class="r">Total a Pagar (50%)</th>
          <th>Banco / Cuenta</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; $totBrutoPB=$totDedPB=$totNetoPB=0; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['emp_nombre']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r"><?= lps($d['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= $d['deduccion'] > 0 ? lps($d['deduccion']) : '—' ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($d['neto']) ?></td>
          <td style="font-size:7.5pt"><?= htmlspecialchars($d['banco'] ?? '—') ?>
            <?php if ($d['cuenta_banco']): ?><br><span style="color:#64748b;font-size:6.5pt;font-family:'Courier New',monospace"><?= htmlspecialchars($d['cuenta_banco']) ?></span><?php endif; ?></td>
        </tr>
        <?php $totBrutoPB+=$d['sueldo_mensual']; $totDedPB+=$d['deduccion']; $totNetoPB+=$d['neto']; endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="font-weight:700;color:#0D3F6A">TOTALES</td>
          <td class="r"><?= lps($totBrutoPB) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($totDedPB) ?></td>
          <td class="r" style="color:#16a34a;font-weight:700"><?= lps($totNetoPB) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?= sigBlock($fPB, 'cols-3', 'margin-top:20px') ?>

  <?php /* ══════════════ BENEFICIOS POR PROYECTO ══════════════ */ elseif ($tipo === 'pbp'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Tipo</div>
        <div class="meta-value"><?= $pbTipos[$doc['tipo']] ?? ucfirst($doc['tipo']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Año</div>
        <div class="meta-value"><?= $doc['anio'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Pago</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_pago'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">No. Proyectos</div>
        <div class="meta-value"><?= count($resumenProy) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Resumen por Proyecto</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr><th>Proyecto</th><th class="r">Empleados</th><th class="r">Beneficio</th><th class="r">Deducciones</th><th class="r">Neto a Pagar</th></tr>
      </thead>
      <tbody>
        <?php $tRB = ['sueldo_mensual'=>0,'deduccion'=>0,'neto'=>0];
        foreach ($resumenProy as $pid => $r): foreach ($tRB as $k => $_) $tRB[$k] += $r[$k]; ?>
        <tr>
          <td><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></td>
          <td class="r"><?= $r['empleados'] ?></td>
          <td class="r"><?= lps($r['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($r['deduccion']) ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($r['neto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="font-weight:700;color:#0D3F6A">TOTALES</td>
          <td class="r"><?= lps($tRB['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($tRB['deduccion']) ?></td>
          <td class="r" style="color:#16a34a"><?= lps($tRB['neto']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php foreach ($resumenProy as $pid => $r): ?>
  <div class="section-head" style="margin-top:12px"><?= htmlspecialchars(proyectoLabel($proyMap, $pid, true)) ?></div>
  <div class="items-wrap">
    <table class="items" style="font-size:8pt">
      <thead>
        <tr>
          <th style="width:2em">#</th><th>Empleado</th><th>Cargo</th>
          <th class="r">%</th><th class="r">Beneficio</th><th class="r">Deducción</th><th class="r">Neto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i = 1; foreach ($detalle as $d): if (!isset($repartos[$d['id']][$pid])) continue; $x = $repartos[$d['id']][$pid]; ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td><?= htmlspecialchars($d['emp_nombre']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cargo'] ?? '') ?></td>
          <td class="r"><?= number_format($x['porcentaje'], 2) ?>%</td>
          <td class="r"><?= lps($x['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= $x['deduccion'] > 0 ? lps($x['deduccion']) : '—' ?></td>
          <td class="r" style="color:#16a34a;font-weight:600"><?= lps($x['neto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="font-weight:700;color:#0D3F6A">SUBTOTAL</td>
          <td class="r"><?= lps($r['sueldo_mensual']) ?></td>
          <td class="r" style="color:#dc2626"><?= lps($r['deduccion']) ?></td>
          <td class="r" style="color:#16a34a"><?= lps($r['neto']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endforeach; ?>

  <?= sigBlock($fPB, 'cols-3', 'margin-top:20px') ?>

  <?php /* ══════════════ VACACIONES ══════════════ */ elseif ($tipo === 'vac'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Empleado</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Ingreso</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_ingreso'] ?? '') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Tipo</div>
        <div class="meta-value"><?= ucfirst($doc['tipo']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Solicitud</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_solicitud']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Inicio</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_inicio']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Regreso</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_fin']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Días Solicitados</div>
        <div class="meta-value" style="font-size:14pt;font-weight:800;color:#0D3F6A"><?= $doc['dias'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Estado</div>
        <div class="meta-value"><span class="doc-estado est-<?= $doc['estado'] ?>"><?= ucfirst($doc['estado']) ?></span></div>
      </div>
      <?php if ($doc['aprobador_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Aprobado por</div>
        <div class="meta-value"><?= htmlspecialchars($doc['aprobador_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php
  $vacSigs = array_merge(
      [['etiqueta' => 'Solicitado por (Empleado)', 'emp_nombre' => $doc['emp_nombre']]],
      $fVAC
  );
  $vacCols = count($vacSigs) >= 3 ? 'cols-3' : 'cols-2';
  echo sigBlock($vacSigs, $vacCols);
  ?>

  <?php /* ══════════════ PERMISO ══════════════ */ elseif ($tipo === 'per'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Empleado</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Tipo de Permiso</div>
        <div class="meta-value"><?= htmlspecialchars($TIPOS_PER[$doc['tipo']] ?? ucfirst($doc['tipo'])) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Con Goce de Sueldo</div>
        <div class="meta-value"><?= $doc['con_goce'] ? 'Sí' : 'No' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Inicio</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_inicio']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Finalización</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_fin']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Días</div>
        <div class="meta-value" style="font-size:14pt;font-weight:800;color:#0D3F6A"><?= $doc['dias'] ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Estado</div>
        <div class="meta-value"><span class="doc-estado est-<?= $doc['estado'] ?>"><?= ucfirst($doc['estado']) ?></span></div>
      </div>
      <?php if ($doc['motivo']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Motivo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['motivo']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['aprobador_nombre']): ?>
      <div class="meta-item">
        <div class="meta-label">Aprobado por</div>
        <div class="meta-value"><?= htmlspecialchars($doc['aprobador_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['notas']): ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php
  $perSigs = array_merge(
      [['etiqueta' => 'Solicitado por (Empleado)', 'emp_nombre' => $doc['emp_nombre']]],
      $fPER
  );
  $perCols = count($perSigs) >= 3 ? 'cols-3' : 'cols-2';
  echo sigBlock($perSigs, $perCols);
  ?>

  <?php /* ══════════════ CONSTANCIAS ══════════════ */ elseif (in_array($tipo, ['trabajo','salario','referencia','tiempo'])): ?>

  <?php
  $dtE = new DateTime($constFechaEmision);
  $emisionLetras = (int)$dtE->format('j') . ' de ' . $meses[(int)$dtE->format('n') - 1] . ' de ' . $dtE->format('Y');
  $ingFechaLetras = '—';
  if ($doc['fecha_ingreso']) {
      $dtI = new DateTime($doc['fecha_ingreso']);
      $ingFechaLetras = (int)$dtI->format('j') . ' de ' . $meses[(int)$dtI->format('n') - 1] . ' de ' . $dtI->format('Y');
  }
  $tiempoSrv = '';
  if ($aniosSrv > 0) {
      $tiempoSrv = $aniosSrv . ' año' . ($aniosSrv !== 1 ? 's' : '');
      if ($mesesSrv > 0) $tiempoSrv .= ' y ' . $mesesSrv . ' mes' . ($mesesSrv !== 1 ? 'es' : '');
  } elseif ($mesesSrv > 0) {
      $tiempoSrv = $mesesSrv . ' mes' . ($mesesSrv !== 1 ? 'es' : '');
  } else {
      $tiempoSrv = 'menos de un mes';
  }
  $idStr  = $doc['identidad'] ? htmlspecialchars($doc['identidad']) : 'no registrada';
  $nombre = htmlspecialchars(strtoupper($doc['nombre_completo'] ?? ($doc['nombre'] . ' ' . $doc['apellidos'])));
  $cargo  = htmlspecialchars($doc['cargo'] ?? '');
  $salStr = lps($doc['sueldo_mensual'] ?? 0);
  ?>

  <div class="letter-body">
    <div class="letter-date"><?= htmlspecialchars($orgDir ?: 'Tegucigalpa, Honduras') ?>, <?= $emisionLetras ?></div>

    <div class="letter-to">
      <strong><?= $constDestinatario ? htmlspecialchars($constDestinatario) : 'A QUIEN CORRESPONDA' ?>:</strong>
    </div>

    <hr class="letter-separator">

    <div class="letter-ref">Ref.: <?= $title ?></div>

    <?php if ($tipo === 'trabajo'): ?>
    <p class="letter-p">
      Por medio de la presente se hace <strong>CONSTAR</strong> que el/la señor/a
      <span class="emp-name"><?= $nombre ?></span>,
      portador/a de la Tarjeta de Identidad número <span class="emp-id"><?= $idStr ?></span>,
      labora en esta institución desempeñando el cargo de <span class="highlight"><?= $cargo ?></span>,
      a partir del <?= $ingFechaLetras ?>.
    </p>

    <?php elseif ($tipo === 'salario'): ?>
    <p class="letter-p">
      Por medio de la presente se hace <strong>CONSTAR</strong> que el/la señor/a
      <span class="emp-name"><?= $nombre ?></span>,
      portador/a de la Tarjeta de Identidad número <span class="emp-id"><?= $idStr ?></span>,
      labora en esta institución desempeñando el cargo de <span class="highlight"><?= $cargo ?></span>,
      devengando un salario mensual de <span class="highlight"><?= $salStr ?></span>.
    </p>

    <?php elseif ($tipo === 'referencia'): ?>
    <p class="letter-p">
      En respuesta a la solicitud de referencia laboral, nos complace informar que el/la señor/a
      <span class="emp-name"><?= $nombre ?></span>,
      portador/a de la Tarjeta de Identidad número <span class="emp-id"><?= $idStr ?></span>,
      labora en esta institución desde el <?= $ingFechaLetras ?>, desempeñando el cargo de
      <span class="highlight"><?= $cargo ?></span>, con una antigüedad de <span class="highlight"><?= $tiempoSrv ?></span>.
    </p>
    <p class="letter-p">
      Durante su trayectoria institucional, ha demostrado ser una persona responsable, comprometida con las
      funciones asignadas y de buena conducta, por lo que extendemos la presente referencia en los mejores términos.
    </p>

    <?php elseif ($tipo === 'tiempo'): ?>
    <p class="letter-p">
      Por medio de la presente se hace <strong>CONSTAR</strong> que el/la señor/a
      <span class="emp-name"><?= $nombre ?></span>,
      portador/a de la Tarjeta de Identidad número <span class="emp-id"><?= $idStr ?></span>,
      labora en esta institución desde el <?= $ingFechaLetras ?>, acumulando a la presente fecha
      <span class="highlight"><?= $tiempoSrv ?></span> de servicio en el cargo de <span class="highlight"><?= $cargo ?></span>.
    </p>
    <?php endif; ?>

    <p class="letter-p">
      Se extiende la presente constancia a petición de la parte interesada, para los fines que estime convenientes.
    </p>

    <?php if ($constNotaAdicional): ?>
    <p class="letter-p"><?= nl2br(htmlspecialchars($constNotaAdicional)) ?></p>
    <?php endif; ?>

    <p class="letter-close">Atentamente,</p>
  </div>

  <?php
  $constSig = !empty($fCONST) ? [$fCONST[0]] : [['etiqueta' => 'Director Ejecutivo', 'emp_nombre' => '']];
  echo sigBlock($constSig, 'cols-1');
  ?>

  <?php /* ══════════════ CAJA CHICA ══════════════ */ elseif ($tipo === 'cc'): ?>

  <?php
  $ccEstado = $doc['estado'] === 'liquidada' ? 'LIQUIDADA' : 'ABIERTA';
  $saldoAbs = abs($doc['saldo']);
  $esReemb  = $doc['saldo'] < 0;
  ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Responsable</div>
        <div class="meta-value" style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Estado</div>
        <div class="meta-value"><?= $ccEstado ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Apertura</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_apertura']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Cierre</div>
        <div class="meta-value"><?= $doc['fecha_cierre'] ? fmtFecha($doc['fecha_cierre']) : '—' ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Anticipo</div>
        <div class="meta-value mono" style="font-weight:700;font-size:10pt"><?= lps($doc['anticipo']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Total Gastos</div>
        <div class="meta-value mono" style="font-weight:700;font-size:10pt;color:#d97706"><?= lps($doc['total_gastos']) ?></div>
      </div>
      <?php if ($doc['aprobador_nombre']): ?>
      <div class="meta-item" style="grid-column:1/-1">
        <div class="meta-label">Liquidado por</div>
        <div class="meta-value"><?= htmlspecialchars($doc['aprobador_nombre']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['notas']): ?>
      <div class="meta-item" style="grid-column:1/-1">
        <div class="meta-label">Notas</div>
        <div class="meta-value"><?= htmlspecialchars($doc['notas']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Gastos</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th style="width:2em">#</th>
          <th>Fecha</th>
          <th>Cuenta Contable</th>
          <th>Descripción</th>
          <th class="r">Cant.</th>
          <th class="r">P. Unit.</th>
          <th class="r">Monto</th>
        </tr>
      </thead>
      <tbody>
        <?php $i=1; foreach ($detalle as $d): ?>
        <tr>
          <td class="c"><?= $i++ ?></td>
          <td style="white-space:nowrap"><?= fmtFecha($d['fecha']) ?></td>
          <td style="font-size:7.5pt;color:#64748b"><?= htmlspecialchars($d['cuenta_nombre']) ?></td>
          <td><?= htmlspecialchars($d['descripcion']) ?></td>
          <td class="r"><?= number_format($d['cantidad'],2) ?></td>
          <td class="r"><?= lps($d['precio_unitario']) ?></td>
          <td class="r" style="font-weight:600"><?= lps($d['monto']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" style="text-align:right;padding-right:12px;font-weight:700">TOTAL GASTOS</td>
          <td class="r"><?= lps($doc['total_gastos']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="section-head">Resumen por Cuenta Contable</div>
  <div class="items-wrap" style="padding:0 28px">
    <table class="items" style="width:55%;min-width:300px;margin-left:auto">
      <thead>
        <tr>
          <th>Cuenta Contable</th>
          <th class="r">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ccResumen as $cr): ?>
        <tr>
          <td><?= htmlspecialchars($cr['cuenta_nombre']) ?></td>
          <td class="r"><?= lps($cr['subtotal']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td style="font-weight:700">TOTAL GASTOS</td>
          <td class="r"><?= lps($doc['total_gastos']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- Cuadro de saldo -->
  <div style="display:flex;justify-content:flex-end;padding:10px 28px 0">
    <table style="border:1.5px solid #1B6BA8;border-radius:6px;overflow:hidden;min-width:280px;font-size:9pt">
      <tr style="background:#f0f5fc">
        <td style="padding:6px 14px;font-weight:600;color:#1B6BA8">Anticipo recibido</td>
        <td style="padding:6px 14px;font-family:'Courier New',monospace;text-align:right"><?= lps($doc['anticipo']) ?></td>
      </tr>
      <tr>
        <td style="padding:6px 14px;font-weight:600">Total de gastos</td>
        <td style="padding:6px 14px;font-family:'Courier New',monospace;text-align:right"><?= lps($doc['total_gastos']) ?></td>
      </tr>
      <tr style="background:#0D3F6A;color:#fff;font-weight:700">
        <td style="padding:7px 14px"><?= $esReemb ? 'Reembolso a recibir' : 'Saldo a devolver' ?></td>
        <td style="padding:7px 14px;font-family:'Courier New',monospace;text-align:right"><?= lps($saldoAbs) ?></td>
      </tr>
    </table>
  </div>

  <?php
  $ccSigRest = !empty($fCC) ? array_slice($fCC, 1, 2) : [
      ['etiqueta' => 'Administración / Contabilidad', 'emp_nombre' => ''],
      ['etiqueta' => 'Dirección Ejecutiva',            'emp_nombre' => ''],
  ];
  $ccSigs = [$empFirmante, ...$ccSigRest];
  echo sigBlock($ccSigs, 'cols-3', 'margin-top:28px');
  ?>

  <?php /* ══════════════ LIQUIDACIÓN ══════════════ */ elseif ($tipo === 'liq'): ?>

  <?php
  $motivoLabels = [
      'renuncia'              => 'Renuncia voluntaria',
      'despido_injustificado' => 'Despido injustificado (Art. 116 / 120 CT)',
      'despido_justificado'   => 'Despido justificado',
      'vencimiento_contrato'  => 'Vencimiento de contrato',
      'mutuo_acuerdo'         => 'Mutuo acuerdo entre las partes',
      'fallecimiento'         => 'Fallecimiento del trabajador',
  ];
  $motLbl = $motivoLabels[$doc['motivo']] ?? $doc['motivo'];
  $aniosSrv = floor($doc['anios_servicio']);
  $mesesSrv = round(($doc['anios_servicio'] - $aniosSrv) * 12);
  $tiempoSrv = "$aniosSrv año" . ($aniosSrv != 1 ? 's' : '');
  if ($mesesSrv) $tiempoSrv .= ", $mesesSrv mes" . ($mesesSrv != 1 ? 'es' : '');
  ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:11pt;font-weight:700;color:#0D3F6A"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Departamento</div>
        <div class="meta-value"><?= htmlspecialchars($doc['departamento'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['cargo'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Ingreso</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_ingreso']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Fecha de Salida</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha_salida']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Antigüedad</div>
        <div class="meta-value"><?= $tiempoSrv ?></div>
      </div>
      <div class="meta-item" style="grid-column:1/-1">
        <div class="meta-label">Motivo de Salida</div>
        <div class="meta-value"><?= htmlspecialchars($motLbl) ?></div>
      </div>
    </div>
  </div>

  <div class="section-head">Conceptos de Liquidación</div>
  <div class="items-wrap">
    <table class="items">
      <thead>
        <tr>
          <th>Concepto</th>
          <th>Referencia Legal</th>
          <th class="r">Monto</th>
        </tr>
      </thead>
      <tbody>
        <tr style="background:#f0f5fc">
          <td>Sueldo Mensual</td>
          <td style="color:#64748b;font-size:8pt">Base de cálculo</td>
          <td class="r"><?= lps($doc['sueldo_mensual']) ?></td>
        </tr>
        <?php if ($doc['dias_pendientes'] > 0): ?>
        <tr>
          <td>Salarios Pendientes (<?= $doc['dias_pendientes'] ?> días)</td>
          <td style="color:#64748b;font-size:8pt">Días no pagados</td>
          <td class="r"><?= lps($doc['salarios_pendientes']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($doc['dias_vacaciones'] > 0): ?>
        <tr>
          <td>Vacaciones Pendientes (<?= $doc['dias_vacaciones'] ?> días)</td>
          <td style="color:#64748b;font-size:8pt">Art. 346 C.T.</td>
          <td class="r"><?= lps($doc['vacaciones_pendientes']) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
          <td>Décimo Tercer Mes Proporcional</td>
          <td style="color:#64748b;font-size:8pt"><?= round($doc['meses_anio_actual'], 1) ?> meses / 12</td>
          <td class="r"><?= lps($doc['decimo_tercero_prop']) ?></td>
        </tr>
        <tr>
          <td>Décimo Cuarto Mes Proporcional</td>
          <td style="color:#64748b;font-size:8pt"><?= round($doc['meses_anio_actual'], 1) ?> meses / 12</td>
          <td class="r"><?= lps($doc['decimo_cuarto_prop']) ?></td>
        </tr>
        <?php if ($doc['preaviso'] > 0): ?>
        <tr>
          <td>Preaviso</td>
          <td style="color:#64748b;font-size:8pt">Art. 116 C.T.</td>
          <td class="r"><?= lps($doc['preaviso']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($doc['cesantia'] > 0): ?>
        <tr>
          <td>Auxilio de Cesantía</td>
          <td style="color:#64748b;font-size:8pt">Art. 120 C.T.</td>
          <td class="r"><?= lps($doc['cesantia']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($doc['otros_conceptos'] > 0): ?>
        <tr>
          <td>Otros: <?= htmlspecialchars($doc['otros_descripcion'] ?? '') ?></td>
          <td></td>
          <td class="r"><?= lps($doc['otros_conceptos']) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="text-align:right;padding-right:12px;font-weight:700">TOTAL A PAGAR AL TRABAJADOR</td>
          <td class="r"><?= lps($doc['total']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <?php if ($doc['notas']): ?>
  <p style="font-size:.82rem;margin:12px 28px 0;color:#555">
    <strong>Notas:</strong> <?= nl2br(htmlspecialchars($doc['notas'])) ?>
  </p>
  <?php endif; ?>

  <p style="font-size:.8rem;margin:18px 28px 0;text-align:center;color:#777">
    Habiendo recibido conforme el monto total indicado, el trabajador y la institución dan por concluida
    la relación laboral en los términos descritos en el presente documento.
  </p>

  <?php
  $liqSigDefault = [['etiqueta' => 'Director Ejecutivo', 'emp_nombre' => ''],
                    ['etiqueta' => 'Trabajador / Representante Legal', 'emp_nombre' => ''],
                    ['etiqueta' => 'Testigo', 'emp_nombre' => '']];
  $liqSigs = !empty($fLIQ) ? $fLIQ : $liqSigDefault;
  echo sigBlock($liqSigs, 'cols-3', 'margin-top:28px');
  ?>

  <?php /* ══════════════ MEMORÁNDUM / CIRCULAR / COMUNICADO ══════════════ */ elseif ($tipo === 'memo'): ?>

  <?php
  $meses_memo = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  $dtM = new DateTime($doc['fecha']);
  $fechaLetras = (int)$dtM->format('j') . ' de ' . $meses_memo[(int)$dtM->format('n')-1] . ' de ' . $dtM->format('Y');
  $remitente   = $doc['emp_nombre_full'] ?: ($doc['de_cargo'] ?: $org);
  $cargo_rem   = $doc['de_cargo'] ?: '';
  ?>

  <div class="letter-body">
    <div class="letter-date"><?= htmlspecialchars($orgDir ?: 'Tegucigalpa, Honduras') ?>, <?= $fechaLetras ?></div>

    <!-- Bloque PARA / DE / ASUNTO -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:18px;font-size:9.5pt">
      <tr>
        <td style="font-weight:800;width:80px;padding:4px 12px 4px 0;vertical-align:top;color:#0D3F6A;letter-spacing:.04em">PARA:</td>
        <td style="padding:4px 0;vertical-align:top"><?= nl2br(htmlspecialchars($doc['para_texto'])) ?></td>
      </tr>
      <tr>
        <td style="font-weight:800;padding:4px 12px 4px 0;vertical-align:top;color:#0D3F6A;letter-spacing:.04em">DE:</td>
        <td style="padding:4px 0;vertical-align:top">
          <strong><?= htmlspecialchars($remitente) ?></strong>
          <?php if ($cargo_rem): ?><br><span style="font-size:8.5pt;color:#64748b"><?= htmlspecialchars($cargo_rem) ?></span><?php endif; ?>
        </td>
      </tr>
      <tr>
        <td style="font-weight:800;padding:4px 12px 4px 0;vertical-align:top;color:#0D3F6A;letter-spacing:.04em">ASUNTO:</td>
        <td style="padding:4px 0;vertical-align:top;font-weight:700"><?= htmlspecialchars($doc['asunto']) ?></td>
      </tr>
      <tr>
        <td style="font-weight:800;padding:4px 12px 4px 0;vertical-align:top;color:#0D3F6A;letter-spacing:.04em">FECHA:</td>
        <td style="padding:4px 0;vertical-align:top"><?= $fechaLetras ?></td>
      </tr>
    </table>

    <hr class="letter-separator">

    <?php foreach (explode("\n", $doc['cuerpo']) as $linea):
      $l = trim($linea);
      if ($l === '') { echo '<div style="height:8pt"></div>'; continue; } ?>
    <p class="letter-p"><?= htmlspecialchars($l) ?></p>
    <?php endforeach; ?>

    <p class="letter-close" style="margin-top:20px">Atentamente,</p>
  </div>

  <?php
  if (!empty($fMEMO)) {
      echo sigBlock($fMEMO, count($fMEMO) >= 3 ? 'cols-3' : (count($fMEMO) == 2 ? 'cols-2' : 'cols-1'));
  } else {
      $memoSigDef = [['etiqueta' => $cargo_rem ?: 'Remitente', 'emp_nombre' => $remitente]];
      echo sigBlock($memoSigDef, 'cols-1');
  }
  ?>

  <?php /* ══════════════ ACTA DE REUNIÓN ══════════════ */ elseif ($tipo === 'acta'): ?>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item">
        <div class="meta-label">Fecha</div>
        <div class="meta-value"><?= fmtFecha($doc['fecha']) ?></div>
      </div>
      <?php if ($doc['hora_inicio']): ?>
      <div class="meta-item">
        <div class="meta-label">Hora</div>
        <div class="meta-value mono"><?= substr($doc['hora_inicio'],0,5) ?> – <?= substr($doc['hora_fin']??'??:??',0,5) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['lugar']): ?>
      <div class="meta-item" style="<?= !$doc['hora_inicio'] ? 'grid-column:2/4' : '' ?>">
        <div class="meta-label">Lugar</div>
        <div class="meta-value"><?= htmlspecialchars($doc['lugar']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['convocado_por']): ?>
      <div class="meta-item">
        <div class="meta-label">Convocado por</div>
        <div class="meta-value"><?= htmlspecialchars($doc['convocado_por']) ?></div>
      </div>
      <?php endif; ?>
      <div class="meta-item meta-full">
        <div class="meta-label">Asunto</div>
        <div class="meta-value" style="font-weight:700;font-size:10pt"><?= htmlspecialchars($doc['asunto']) ?></div>
      </div>
      <?php if ($doc['proxima_reunion']): ?>
      <div class="meta-item">
        <div class="meta-label">Próxima Reunión</div>
        <div class="meta-value"><?= fmtFecha($doc['proxima_reunion']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php
  $actaSecciones = [
      'participantes' => 'Participantes',
      'agenda'        => 'Agenda',
      'desarrollo'    => 'Desarrollo',
      'acuerdos'      => 'Acuerdos',
  ];
  foreach ($actaSecciones as $campo => $etiqueta):
    if (empty($doc[$campo])) continue; ?>
  <div class="section-head"><?= $etiqueta ?></div>
  <div style="padding:10px 28px;font-size:8.5pt;line-height:1.8;white-space:pre-wrap"><?= htmlspecialchars($doc[$campo]) ?></div>
  <?php endforeach; ?>

  <?php
  $actaSigDefault = [
      ['etiqueta' => 'Elaboró', 'emp_nombre' => ''],
      ['etiqueta' => 'Revisó',  'emp_nombre' => ''],
      ['etiqueta' => 'Aprobó', 'emp_nombre' => ''],
  ];
  $actaSigs = !empty($fACTA) ? $fACTA : $actaSigDefault;
  echo sigBlock($actaSigs, 'cols-3', 'margin-top:28px');
  ?>

  <?php endif; ?>

  <div class="spacer"></div>

  <!-- Footer -->
  <div class="doc-footer">
    <div class="footer-left">
      <?= htmlspecialchars($org) ?><?php if($orgRtn): ?> &nbsp;·&nbsp; RTN <?= htmlspecialchars($orgRtn) ?><?php endif; ?><br>
      Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala
    </div>
    <div class="footer-right">
      <?= $title ?> — <?= htmlspecialchars($docNum) ?>
    </div>
  </div>
  <div class="bottom-bar"></div>
</div>

<?php if ($sgHasAlim): ?>
<!-- ══ VOUCHER DE ALIMENTACIÓN ══ -->
<div class="page">
  <div class="top-bar"></div>
  <div class="doc-header">
    <div class="org-block">
      <?php if ($logoData): ?><img src="<?= $logoData ?>" class="org-logo" alt="<?= htmlspecialchars($org) ?>">
      <?php else: ?><div class="org-logo-placeholder"><?= htmlspecialchars($orgInicial) ?></div><?php endif; ?>
      <div class="org-info">
        <div class="org-name"><?= htmlspecialchars($org) ?></div>
        <?php if ($orgFull): ?><div class="org-fullname"><?= htmlspecialchars($orgFull) ?></div><?php endif; ?>
        <?php if ($orgDir): ?><div class="org-sub"><?= htmlspecialchars($orgDir) ?></div><?php endif; ?>
        <?php if ($orgRtn): ?><div class="org-sub">RTN: <?= htmlspecialchars($orgRtn) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="doc-title-block">
      <div class="doc-type">VOUCHER DE ALIMENTACIÓN</div>
      <div class="doc-number" style="font-size:13pt"><?= htmlspecialchars($doc['numero']) ?></div>
      <div style="font-size:8.5pt;color:#64748b;margin-top:3px">Viáticos de Viaje</div>
    </div>
  </div>

  <div class="meta-section" style="background:#f0f5fc;border-color:#dbeafe">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Empleado</div>
        <div class="meta-value" style="font-size:12pt;font-weight:800;color:#0D3F6A"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">N° Identidad</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['emp_identidad'] ?? '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_cargo'] ?? '—') ?></div>
      </div>
      <?php if ($doc['gv_numero']): ?>
      <div class="meta-item">
        <div class="meta-label">Ref. Anticipo de Viaje</div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['gv_numero']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['gv_destino']): ?>
      <div class="meta-item">
        <div class="meta-label">Destino</div>
        <div class="meta-value"><?= htmlspecialchars($doc['gv_destino']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['gv_fecha_salida']): ?>
      <div class="meta-item">
        <div class="meta-label">Fecha de Salida</div>
        <div class="meta-value"><?= fmtFecha($doc['gv_fecha_salida']) ?></div>
      </div>
      <?php endif; ?>
      <?php if ($doc['gv_fecha_regreso']): ?>
      <div class="meta-item">
        <div class="meta-label">Fecha de Regreso</div>
        <div class="meta-value"><?= fmtFecha($doc['gv_fecha_regreso']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="section-head">Detalle de Alimentación</div>
  <div class="items-wrap" style="padding:0 28px;margin-top:10px">
    <table style="width:100%;border-collapse:collapse;font-size:9pt">
      <tbody>
        <tr style="border-bottom:2px solid #e2e8f0;background:#f0f5fc">
          <td style="padding:8px 8px;font-weight:700;color:#0D3F6A">Concepto</td>
          <td style="padding:8px 8px;font-weight:700;color:#0D3F6A">Viáticos de Alimentación por comisión a <?= htmlspecialchars($doc['gv_destino'] ?? 'destino del viaje') ?></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="amount-block" style="margin:14px 28px">
    <div>
      <div class="amount-label">Monto de Alimentación</div>
      <div class="amount-value"><?= lps($doc['monto_alimentacion']) ?></div>
    </div>
  </div>

  <div style="margin:18px 28px 0;padding:18px 20px;border:1.5px dashed #cbd5e1;border-radius:8px;background:#fafafa">
    <p style="font-size:9pt;line-height:1.7;color:#374151;margin-bottom:16px">
      Yo, <strong><?= htmlspecialchars(strtoupper($doc['emp_nombre'])) ?></strong><?php if ($doc['emp_identidad'] ?? ''): ?>,
      portador/a de la Tarjeta de Identidad número <strong><?= htmlspecialchars($doc['emp_identidad']) ?></strong><?php endif; ?>,
      declaro haber recibido la suma de <strong><?= lps($doc['monto_alimentacion']) ?></strong>
      en concepto de <em>viáticos de alimentación</em> correspondientes a la comisión<?php if ($doc['gv_destino']): ?> a <strong><?= htmlspecialchars($doc['gv_destino']) ?></strong><?php endif; ?><?php if ($doc['gv_fecha_salida'] && $doc['gv_fecha_regreso']): ?>, del <?= fmtFecha($doc['gv_fecha_salida']) ?> al <?= fmtFecha($doc['gv_fecha_regreso']) ?><?php endif; ?>, en conformidad con lo indicado.
    </p>
    <div style="display:grid;grid-template-columns:1fr 180px;gap:20px;margin-top:8px">
      <div style="text-align:center">
        <div style="border-top:1.5px solid #64748b;margin-bottom:5px;margin-top:36px"></div>
        <div style="font-size:8pt;font-weight:600"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
        <div style="font-size:7.5pt;color:#64748b">Firma y nombre del empleado</div>
      </div>
      <div>
        <div style="font-size:8pt;color:#64748b;margin-bottom:4px">Fecha de recibo:</div>
        <div style="border-bottom:1px solid #64748b;height:28px"></div>
      </div>
    </div>
  </div>

  <?= sigBlock($fSG, 'cols-3', 'margin-top:28px') ?>

  <div class="spacer"></div>
  <div class="doc-footer">
    <div class="footer-left"><?= htmlspecialchars($org) ?><br>Generado el <span class="gen-ts"></span> — Sistema de Administración Proscala</div>
    <div class="footer-right">VOUCHER ALIMENTACIÓN — <?= htmlspecialchars($doc['numero']) ?></div>
  </div>
  <div class="bottom-bar"></div>
</div>
<?php endif; /* sgHasAlim */ ?>

<?php if ($sgHasAlim): ?></div><!-- #all-pages sg+alim --><?php endif; ?>

<?php endif; /* not proceso */ ?>

<script src="<?= recurso('assets/vendor/html2pdf/html2pdf.bundle.min.js') ?>"></script>
<script>
<?php
$orientation = in_array($tipo, ['pl','pb']) ? 'landscape' : 'portrait';
$isMultiPageDoc = ($tipo === 'proceso') || ($sgHasAlim ?? false);
$pdfSelector = $isMultiPageDoc ? '#all-pages' : '.page';
$safeNum     = preg_replace('/[^\w\-]/', '_', "$tipo-$docNum");
$filename    = "Proscala-{$safeNum}.pdf";
?>

// ── Hora local del dispositivo en todos los footers ───────────────
(function() {
  const now = new Date();
  const pad = n => String(n).padStart(2, '0');
  const ts  = pad(now.getDate()) + '/' + pad(now.getMonth() + 1) + '/' + now.getFullYear()
            + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes());
  document.querySelectorAll('.gen-ts').forEach(el => el.textContent = ts);
})();

const spinSvg = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="animation:spin 1s linear infinite"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>';
const dlSvg   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>';

// ── html2canvas + text-transform:uppercase pierde los acentos en
// mayúsculas al usar letterRendering con letter-spacing (bug conocido
// de html2canvas). Se resuelve escribiendo el texto ya en mayúsculas
// (JS sí acentúa bien) y desactivando el transform solo durante la captura.
const UPPERCASE_SELECTORS = '.doc-type, .doc-estado, .meta-label, .section-head, .amount-label, .letter-ref, .letter-to strong, .letter-p .emp-name, .obs-label';
function fixAccentedUppercase(root) {
  const nodes = root.querySelectorAll(UPPERCASE_SELECTORS);
  const restore = [];
  nodes.forEach(n => {
    restore.push([n, n.textContent, n.style.textTransform]);
    n.textContent = n.textContent.toLocaleUpperCase('es');
    n.style.textTransform = 'none';
  });
  return () => restore.forEach(([n, text, tt]) => { n.textContent = text; n.style.textTransform = tt; });
}

// ── PDF download (single / proceso) ───────────────────────────────
async function downloadPdf() {
  const btn     = document.getElementById('btn-download');
  const toolbar = document.getElementById('toolbar');
  btn.disabled  = true;
  btn.innerHTML = spinSvg + ' Generando…';
  toolbar.style.visibility = 'hidden';

  const el          = document.querySelector('<?= $pdfSelector ?>');
  const isMultiPage = <?= $isMultiPageDoc ? 'true' : 'false' ?>;

  document.body.style.background = '#fff';
  document.body.style.padding    = '0';
  el.style.margin                = '0';
  el.style.boxShadow             = 'none';
  const restoreCase = fixAccentedUppercase(el);

  const opt = {
    margin:      0,
    filename:    '<?= addslashes($filename) ?>',
    html2canvas: { scale: 2, useCORS: true, logging: false, letterRendering: true },
    jsPDF:       { unit: 'mm', format: 'a4', orientation: '<?= $orientation ?>' },
    pagebreak:   { mode: isMultiPage ? 'css' : 'avoid-all' }
  };

  try {
    await html2pdf().set(opt).from(el).save();
  } finally {
    restoreCase();
    document.body.style.background = '';
    document.body.style.padding    = '';
    el.style.margin                = '';
    el.style.boxShadow             = '';
    toolbar.style.visibility = 'visible';
    btn.disabled  = false;
    btn.innerHTML = dlSvg + ' Descargar PDF';
  }
}

// Spinner keyframe
const style = document.createElement('style');
style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(style);

// Auto-descarga cuando se llega desde pdf_download.php
if (new URLSearchParams(location.search).get('auto') === '1') {
  window.addEventListener('load', () => setTimeout(downloadPdf, 400));
}
</script>
</body>
</html>

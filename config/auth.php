<?php
// La sesión (cookies seguras, inactividad) y las conexiones se preparan en db.php
require_once __DIR__ . '/db.php';

// ── Roles ──────────────────────────────────────────────────────────
// Cada empresa define sus puestos en Roles y Permisos (tabla roles: nombre, de quién
// depende, si aprueba procesos, si solo ve sus solicitudes). El Administrador (y el
// super administrador) tiene acceso total; los demás puestos ven solo los módulos que
// se les asignan (tabla rol_modulos). 'admin' y 'empleado' existen siempre: el sistema
// los usa (administradores de empresa y usuarios creados desde Empleados).
const ROLES_SISTEMA = ['admin', 'empleado'];

// Contraseñas incorrectas seguidas antes de bloquear al usuario (lo desbloquea un Administrador)
const MAX_INTENTOS_LOGIN = 5;
// Largo mínimo de una contraseña nueva (Mi Perfil y restablecer por correo)
const MIN_PASSWORD = 8;

/** Puestos de la empresa: clave => fila de la tabla roles, en el orden del organigrama. */
function roles(bool $recargar = false): array {
    static $roles = null;
    if ($roles === null || $recargar) {
        $roles = [];
        if (!empty($GLOBALS['pdo'])) {
            foreach ($GLOBALS['pdo']->query("SELECT * FROM roles ORDER BY orden, nombre") as $r) $roles[$r['clave']] = $r;
        }
    }
    return $roles;
}

/** clave => nombre de los puestos (selectores). */
function rolesNombres(): array {
    return array_map(fn($r) => $r['nombre'], roles());
}

// ── Módulos (cada archivo PHP) ─────────────────────────────────────
// Agrupados como en el menú lateral, que se arma a partir de esta lista.
const MODULOS = [
    '' => [
        'dashboard.php' => ['fa-gauge-high', 'Dashboard'],
    ],
    'Personal' => [
        'empleados.php'            => ['fa-users',              'Empleados'],
        'planillas.php'            => ['fa-money-check-dollar', 'Planillas Quincenales'],
        'planillas_asignacion.php' => ['fa-list-check',         'Planillas de Asignación'],
        'planillas_beneficios.php' => ['fa-gift',               'Planillas de Beneficios'],
    ],
    'Contabilidad' => [
        'gastos_solicitud.php'      => ['fa-file-invoice-dollar', 'Liquidaciones / Reembolsos'],
        'gastos_viajes.php'         => ['fa-hand-holding-dollar', 'Anticipos'],
        'gastos_viaje_auxiliar.php' => ['fa-scale-balanced',      'Auxiliar de Anticipos'],
    ],
    'Proceso de Compras' => [
        'compras_solicitud.php'    => ['fa-cart-plus',           'Solicitud de Compra'],
        'compras_cotizaciones.php' => ['fa-file-lines',          'Resumen de Cotizaciones'],
        'compras_orden.php'        => ['fa-file-circle-check',   'Orden de Compra'],
        'compras_pago.php'         => ['fa-money-bill-transfer', 'Orden de Pago'],
        'compras_recepcion.php'    => ['fa-boxes-stacked',       'Nota de Recepción'],
        'proveedores.php'          => ['fa-truck',               'Proveedores'],
    ],
    'Proyectos y Presupuesto' => [
        'proyectos.php'   => ['fa-diagram-project', 'Proyectos / Donantes'],
        'presupuesto.php' => ['fa-chart-pie',       'Presupuesto'],
    ],
    'Activos' => [
        'activos.php' => ['fa-laptop', 'Activos Fijos'],
    ],
    'Recursos Humanos' => [
        'vacaciones.php'    => ['fa-umbrella-beach',      'Control de Vacaciones'],
        'permisos.php'      => ['fa-calendar-xmark',      'Permisos y Ausencias'],
        'asistencia.php'    => ['fa-calendar-check',      'Control de Asistencia'],
        'prestamos.php'     => ['fa-hand-holding-dollar', 'Préstamos a Empleados'],
        'constancias.php'   => ['fa-file-contract',       'Constancias y Refs.'],
        'liquidaciones.php' => ['fa-file-invoice',        'Liquidaciones'],
    ],
    'Documentos Internos' => [
        'memorandos.php' => ['fa-file-lines',     'Memorandos y Comunicados'],
        'actas.php'      => ['fa-clipboard-list', 'Actas de Reunión'],
    ],
    'Reportes' => [
        'reportes.php'         => ['fa-chart-bar',   'Reportes Financieros'],
        'reportes_legales.php' => ['fa-file-export', 'Reportes Legales'],
    ],
    'Administración' => [
        'caja_chica.php'    => ['fa-cash-register',  'Caja Chica'],
        'configuracion.php' => ['fa-gear',           'Configuración'],
        'catalogos.php'     => ['fa-book',           'Catálogos'],
        'auditoria.php'     => ['fa-shield-halved',  'Bitácora de Actividad'],
        'usuarios.php'      => ['fa-user-shield',    'Usuarios'],
        'roles.php'         => ['fa-user-lock',      'Roles y Permisos'],
    ],
];

// Solo el Administrador: quien gestiona usuarios o permisos podría darse acceso total
const MODULOS_SOLO_ADMIN = ['usuarios.php', 'roles.php'];

// Archivos de apoyo que no son módulos: abiertos a todos (los de impresión
// validan el documento con puedeVerDocumento; adjuntos.php, el proceso con puedeVerProceso)
const PAGINAS_LIBRES = ['perfil.php', 'print.php', 'pdf_download.php', 'index.php', 'logout.php', 'adjuntos.php', 'sesion.php'];

// Tipos de documento de print.php / pdf_download.php → módulo(s) donde se generan
const DOCUMENTOS_MODULO = [
    'sc'  => ['compras_solicitud.php'], 'oc' => ['compras_orden.php'], 'op' => ['compras_pago.php'],
    'proceso' => ['compras_solicitud.php', 'compras_orden.php', 'compras_pago.php'],
    'pl'  => ['planillas.php'], 'plp' => ['planillas.php'], 'plc' => ['planillas.php'], 'plv' => ['planillas.php'],
    'pa'  => ['planillas_asignacion.php'], 'pav' => ['planillas_asignacion.php'],
    'pb'  => ['planillas_beneficios.php'], 'pbp' => ['planillas_beneficios.php'], 'pbv' => ['planillas_beneficios.php'],
    'gv'  => ['gastos_viajes.php'], 'gva' => ['gastos_viajes.php', 'gastos_viaje_auxiliar.php'],
    'sg'  => ['gastos_solicitud.php'],
    'vac' => ['vacaciones.php'], 'per' => ['permisos.php'], 'liq' => ['liquidaciones.php'],
    'trabajo' => ['constancias.php'], 'salario' => ['constancias.php'], 'referencia' => ['constancias.php'], 'tiempo' => ['constancias.php'],
    'cc'  => ['caja_chica.php'], 'memo' => ['memorandos.php'], 'acta' => ['actas.php'],
];

// Nombre legible de un archivo .php (módulo o página de apoyo) para la bitácora
function moduloLabel(?string $archivo): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = ['index.php' => 'Inicio de sesión', 'logout.php' => 'Cierre de sesión', 'perfil.php' => 'Mi Perfil',
                 'print.php' => 'Impresión de documentos', 'pdf_download.php' => 'Descarga de PDF',
                 'empresas.php' => 'Empresas', 'adjuntos.php' => 'Documentos adjuntos'];
        foreach (MODULOS as $items) foreach ($items as $f => [, $label]) $mapa[$f] = $label;
    }
    return $mapa[$archivo] ?? (string)$archivo;
}

// Acciones de la bitácora → [etiqueta, color de Bootstrap]
const ACCIONES_BITACORA = [
    'LOGIN'           => ['Inicio de sesión',  'info'],
    'LOGIN_FALLIDO'   => ['Inicio fallido',    'danger'],
    'LOGOUT'          => ['Cierre de sesión',  'secondary'],
    'INSERT'          => ['Creación',          'success'],
    'UPDATE'          => ['Modificación',      'warning'],
    'DELETE'          => ['Eliminación',       'danger'],
    'IMPRIMIR'        => ['Impresión',         'primary'],
    'DESCARGAR_PDF'   => ['Descarga PDF',      'primary'],
    'ACCESO_DENEGADO' => ['Acceso denegado',   'dark'],
    'PASSWORD'        => ['Cambio de contraseña', 'warning'],
    'RECUPERAR'       => ['Restablecer contraseña', 'warning'],
    'BLOQUEO'         => ['Usuario bloqueado', 'danger'],
    'DESBLOQUEO'      => ['Usuario desbloqueado', 'info'],
    'REVISAR'         => ['Revisión',          'info'],
    'AUTORIZAR'       => ['Autorización',      'success'],
    'RECHAZAR'        => ['Rechazo',           'danger'],
    'ADJUNTAR'        => ['Documento adjuntado', 'success'],
    'QUITAR_ADJUNTO'  => ['Adjunto quitado',   'danger'],
];

// ── Solicitudes ────────────────────────────────────────────────────
// Módulos cuyos registros pertenecen a quien los crea o edita. Un puesto con
// "solo sus solicitudes" (roles.solo_propios) solo ve y edita las que creó o
// editó (registro_usuarios) y las hechas a su nombre (columna empleado).
//   tabla    → tabla del registro            empleado → columna del empleado al que corresponde
//   docs     → tipos de print.php del registro (su id es el del registro)
//   refs     → parámetros que apuntan a otra solicitud (también deben ser propias)
const SOLICITUDES = [
    'compras_solicitud.php' => ['tabla' => 'solicitud_compra', 'empleado' => 'solicitante_id', 'docs' => ['sc', 'proceso'], 'refs' => []],
    'gastos_viajes.php'     => ['tabla' => 'gastos_viaje',     'empleado' => 'empleado_id',    'docs' => ['gv', 'gva'],      'refs' => []],
    'gastos_solicitud.php'  => ['tabla' => 'solicitud_gastos', 'empleado' => 'empleado_id',    'docs' => ['sg'],             'refs' => ['viaje_id' => 'gastos_viajes.php']],
    'vacaciones.php'        => ['tabla' => 'vacaciones',       'empleado' => 'empleado_id',    'docs' => ['vac'],            'refs' => []],
    'permisos.php'          => ['tabla' => 'permisos',         'empleado' => 'empleado_id',    'docs' => ['per'],            'refs' => []],
];

function rolLabel(string $rol): string {
    return roles()[$rol]['nombre'] ?? ucfirst(str_replace('_', ' ', $rol));
}

function tieneAccesoTotal(): bool {
    return ($_SESSION['usuario_rol'] ?? '') === 'admin' || esSuperadmin();
}

// Carga en la sesión los módulos permitidos al rol y sus opciones (se refresca en cada petición)
function cargarPermisos(PDO $pdo, string $rol): void {
    $s = $pdo->prepare("SELECT modulo FROM rol_modulos WHERE rol = ?");
    $s->execute([$rol]);
    $_SESSION['modulos'] = $s->fetchAll(PDO::FETCH_COLUMN);
    $s = $pdo->prepare("SELECT solo_propios, aprueba FROM roles WHERE clave = ?");
    $s->execute([$rol]);
    $o = $s->fetch() ?: ['solo_propios' => 0, 'aprueba' => 0];
    $_SESSION['solo_propios'] = (bool)$o['solo_propios'];
    $_SESSION['rol_aprueba']  = (bool)$o['aprueba'];
}

// ¿El usuario solo puede ver y editar sus propias solicitudes?
function soloRegistrosPropios(): bool {
    return !tieneAccesoTotal() && !empty($_SESSION['solo_propios']);
}

/**
 * Condición SQL que limita una consulta de un módulo de solicitudes a las del usuario.
 * Devuelve [sql, params]; sin restricción → ['1=1', []].
 */
function filtroPropios(string $modulo, string $alias): array {
    if (!soloRegistrosPropios() || !isset(SOLICITUDES[$modulo])) return ['1=1', []];
    $def = SOLICITUDES[$modulo];
    return ["({$alias}.id IN (SELECT registro_id FROM registro_usuarios WHERE tabla = ? AND usuario_id = ?)
              OR {$alias}.{$def['empleado']} = ?)",
            [$def['tabla'], (int)$_SESSION['usuario_id'], (int)($_SESSION['empleado_id'] ?? 0) ?: -1]];
}

function esRegistroPropio(PDO $pdo, string $modulo, int $id): bool {
    if (!soloRegistrosPropios() || !isset(SOLICITUDES[$modulo])) return true;
    [$cond, $params] = filtroPropios($modulo, 't');
    $s = $pdo->prepare("SELECT 1 FROM " . SOLICITUDES[$modulo]['tabla'] . " t WHERE t.id = ? AND $cond");
    $s->execute(array_merge([$id], $params));
    return (bool)$s->fetchColumn();
}

// Anota que el usuario creó (si nadie lo había hecho) o editó la solicitud / el proceso
function registrarParticipacion(PDO $pdo, string $modulo, int $id): void {
    $tabla = PROCESOS[$modulo]['tabla'] ?? EDICION_PROCESOS[$modulo]['tabla'] ?? null;
    if (!$id || !$tabla || empty($_SESSION['usuario_id'])) return;
    $s = $pdo->prepare("SELECT COUNT(*) FROM registro_usuarios WHERE tabla = ? AND registro_id = ?");
    $s->execute([$tabla, $id]);
    $pdo->prepare("INSERT IGNORE INTO registro_usuarios (tabla, registro_id, usuario_id, participacion) VALUES (?,?,?,?)")
        ->execute([$tabla, $id, $_SESSION['usuario_id'], $s->fetchColumn() ? 'editor' : 'creador']);
}

/** Nombre del firmante #1 a mostrar en un formulario: el ya fijado en la solicitud o quien la llena. */
function firmante1Nombre(PDO $pdo, int $empleadoId = 0): string {
    $empleadoId = $empleadoId ?: (int)($_SESSION['empleado_id'] ?? 0);
    if ($empleadoId) {
        $s = $pdo->prepare("SELECT CONCAT(nombre,' ',apellidos) FROM empleados WHERE id = ?");
        $s->execute([$empleadoId]);
        if ($n = $s->fetchColumn()) return $n;
    }
    return $_SESSION['usuario_nombre'] ?? '';
}

// Bloquea abrir, editar, aprobar o imprimir solicitudes ajenas (roles con "solo sus solicitudes")
function verificarRegistrosPropios(PDO $pdo, string $script): void {
    $denegar = function (string $detalle) use ($pdo, $script) {
        registrarEvento($pdo, 'ACCESO_DENEGADO', ['resultado' => 'error', 'detalle' => $detalle]);
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => 'Solo puede ver y editar sus propias solicitudes.']));
        }
        if (isset(SOLICITUDES[$script])) { header('Location: ' . BASE_URL . $script); exit; }
        http_response_code(403);
        die('No tiene permiso para ver este documento.');
    };

    if (isset(SOLICITUDES[$script])) {
        $datos = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
        if (($datos['_action'] ?? '') === 'cambiar_estado') $denegar("Intentó cambiar el estado de una solicitud en $script");
        $id = (int)($datos['id'] ?? 0);
        if ($id && !esRegistroPropio($pdo, $script, $id)) $denegar("Intentó abrir la solicitud #$id de $script");
        foreach (SOLICITUDES[$script]['refs'] as $param => $modRef) {
            $ref = (int)($datos[$param] ?? 0);
            if ($ref && !esRegistroPropio($pdo, $modRef, $ref)) $denegar("Intentó usar la solicitud #$ref de $modRef");
        }
    } elseif (in_array($script, ['print.php', 'pdf_download.php'], true)) {
        $tipo = $_GET['tipo'] ?? '';
        foreach (SOLICITUDES as $mod => $def) {
            if (in_array($tipo, $def['docs'], true) && !esRegistroPropio($pdo, $mod, (int)($_GET['id'] ?? 0))) {
                $denegar("Intentó imprimir el documento $tipo #" . (int)($_GET['id'] ?? 0));
            }
        }
    }
}

function puedeAcceder(string $pagina): bool {
    if (in_array($pagina, PAGINAS_LIBRES, true)) return true;
    if ($pagina === 'empresas.php') return esSuperadmin();
    if (tieneAccesoTotal()) return true;
    if (in_array($pagina, MODULOS_SOLO_ADMIN, true)) return false;
    return in_array($pagina, $_SESSION['modulos'] ?? [], true);
}

function puedeVerDocumento(string $tipo): bool {
    foreach (DOCUMENTOS_MODULO[$tipo] ?? [] as $modulo) {
        if (puedeAcceder($modulo)) return true;
    }
    return false;
}

// Primera página permitida, en el orden del menú (Mi Perfil si no tiene módulos)
function paginaInicio(): string {
    foreach (MODULOS as $items) {
        foreach (array_keys($items) as $modulo) {
            if (puedeAcceder($modulo)) return $modulo;
        }
    }
    return 'perfil.php';
}

function cerrarSesionYSalir() {
    // Se conserva el aviso para el login (p. ej. "su sesión se cerró por inactividad")
    $aviso = $_SESSION['aviso_login'] ?? null;
    $_SESSION = $aviso ? ['aviso_login' => $aviso] : [];
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'sesion_expirada' => true,
            'message' => $aviso ?? 'Su sesión terminó. Ingrese de nuevo.'], JSON_UNESCAPED_UNICODE));
    }
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

function requireLogin() {
    // Sin empresa en sesión (o sesiones anteriores al modo multiempresa) → login
    if (!isset($_SESSION['usuario_id'], $_SESSION['empresa_id']) || empty($GLOBALS['pdo'])) {
        cerrarSesionYSalir();
    }
    // Refresca rol y estado desde la BD: un cambio de rol, una baja o la
    // desactivación de la empresa aplican de inmediato.
    $s = $GLOBALS['pdo']->prepare("SELECT rol, activo, email, debe_cambiar_password, empleado_id FROM usuarios WHERE id=?");
    $s->execute([$_SESSION['usuario_id']]);
    $u = $s->fetch();
    if (!$u || !$u['activo']) cerrarSesionYSalir();

    $s = $GLOBALS['pdoMaster']->prepare(
        "SELECT a.superadmin, e.activo, e.nombre FROM accesos a JOIN empresas e ON e.id = a.empresa_id
         WHERE a.email = ? AND a.empresa_id = ?");
    $s->execute([$u['email'], $_SESSION['empresa_id']]);
    $a = $s->fetch();
    if (!$a || !$a['activo']) cerrarSesionYSalir();

    $_SESSION['usuario_rol']    = $u['rol'];
    $_SESSION['es_superadmin']  = (bool)$a['superadmin'];
    $_SESSION['empresa_nombre'] = $a['nombre'];
    $_SESSION['empleado_id']    = $u['empleado_id'] ? (int)$u['empleado_id'] : null;
    cargarPermisos($GLOBALS['pdo'], $u['rol']);

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $esAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $esPost = $_SERVER['REQUEST_METHOD'] === 'POST';

    // Contraseña inicial (usuarios creados desde Empleados): debe cambiarla antes de seguir
    if ($u['debe_cambiar_password'] && !in_array($script, ['perfil.php', 'logout.php'], true)) {
        if ($esPost) registrarEvento($GLOBALS['pdo'], 'ACCESO_DENEGADO', ['resultado' => 'error', 'detalle' => "Intentó usar $script sin cambiar su contraseña inicial"]);
        if ($esAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => 'Debe cambiar su contraseña inicial en Mi Perfil.']));
        }
        header('Location: ' . BASE_URL . 'perfil.php?cambiar=1');
        exit;
    }

    if (!puedeAcceder($script)) {
        registrarEvento($GLOBALS['pdo'], 'ACCESO_DENEGADO', ['resultado' => 'error', 'detalle' => 'Intentó abrir ' . $script]);
        if ($esAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'message' => 'No tiene permiso para este módulo.']));
        }
        header('Location: ' . BASE_URL . paginaInicio());
        exit;
    }

    if (soloRegistrosPropios()) verificarRegistrosPropios($GLOBALS['pdo'], $script);

    // Bitácora: toda acción enviada (POST) se registra al terminar la petición, con su resultado
    static $bitacoraLista = false;
    if (!$bitacoraLista && $esPost) {
        $bitacoraLista = true;
        register_shutdown_function('registrarPeticion');
    }

    // Procesos históricos: no se editan ni eliminan (config/historico.php)
    verificarHistorico($GLOBALS['pdo'], $script);
    // Editar o eliminar procesos: rangos superiores, nunca quien los creó (config/edicion.php)
    verificarEdicion($GLOBALS['pdo'], $script);

    // Al ver un proceso: su orden de pago (o, en una orden de pago, su proceso de origen)
    if (!$esPost && ($_GET['action'] ?? '') === 'ver' && (int)($_GET['id'] ?? 0)) {
        $GLOBALS['enlaces_orden_pago'] = enlacesOrdenPago($GLOBALS['pdo'], $script, (int)$_GET['id']);
    }

    // Flujo Creado → Revisado → Autorizado (config/aprobaciones.php)
    if ($esPost && isset(PROCESOS[$script])) validarFlujoAprobacion($GLOBALS['pdo'], $script);

    // Impresión / descarga de documentos (pdf_download.php incluye print.php: se registra una vez)
    static $impresionRegistrada = false;
    if (!$impresionRegistrada && in_array($script, ['print.php', 'pdf_download.php'], true)) {
        $impresionRegistrada = true;
        registrarEvento($GLOBALS['pdo'], $script === 'pdf_download.php' ? 'DESCARGAR_PDF' : 'IMPRIMIR', [
            'tabla' => $_GET['tipo'] ?? null, 'registro_id' => (int)($_GET['id'] ?? 0) ?: null,
            'detalle' => 'Documento: ' . http_build_query($_GET),
        ]);
    }
}

function isLoggedIn() {
    return isset($_SESSION['usuario_id']);
}

function currentUser() {
    return [
        'id'     => $_SESSION['usuario_id'] ?? null,
        'nombre' => $_SESSION['usuario_nombre'] ?? '',
        'rol'    => $_SESSION['usuario_rol'] ?? '',
        'email'  => $_SESSION['usuario_email'] ?? '',
        'foto'   => $_SESSION['usuario_foto']  ?? '',
    ];
}

function hasRole(array $roles) {
    return in_array($_SESSION['usuario_rol'] ?? '', $roles);
}

// Super administrador: gestiona las empresas dadas de alta en Proscala
function esSuperadmin(): bool {
    return !empty($_SESSION['es_superadmin']);
}

// ¿El correo ya está registrado en esa empresa? (un correo puede estar en varias empresas)
function emailEnUso(PDO $pdoMaster, string $email, int $empresaId, string $exceptoEmail = ''): bool {
    $s = $pdoMaster->prepare("SELECT 1 FROM accesos WHERE email = ? AND empresa_id = ? AND email <> ?");
    $s->execute([$email, $empresaId, $exceptoEmail]);
    return (bool)$s->fetchColumn();
}

// ── Usuarios de empleados ──────────────────────────────────────────
// Cada empleado con correo tiene un usuario vinculado (usuarios.empleado_id), con rol
// Empleado y su número de identidad como contraseña inicial (debe cambiarla al entrar).
// Si el correo ya era de un usuario (p. ej. un administrador), solo se vincula.
// Devuelve 'creado', 'vinculado', 'actualizado' o el motivo por el que no se pudo.
function sincronizarUsuarioEmpleado(PDO $pdo, PDO $pdoMaster, int $empresaId, int $empleadoId): string {
    $s = $pdo->prepare("SELECT id, nombre, apellidos, identidad, email, activo FROM empleados WHERE id=?");
    $s->execute([$empleadoId]);
    if (!$e = $s->fetch()) return 'Empleado no encontrado';
    $email  = trim((string)$e['email']);
    $nombre = trim($e['nombre'] . ' ' . $e['apellidos']);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return "Correo inválido ($email)";

    // Ya tiene usuario: se mantienen correo, nombre y estado al día
    $s = $pdo->prepare("SELECT id, email, rol, activo FROM usuarios WHERE empleado_id=?");
    $s->execute([$empleadoId]);
    if ($u = $s->fetch()) {
        if ($email !== '' && strcasecmp($email, $u['email']) !== 0) {
            if (emailEnUso($pdoMaster, $email, $empresaId, $u['email'])) return "El correo $email ya pertenece a otro usuario";
            $pdo->prepare("UPDATE usuarios SET email=? WHERE id=?")->execute([$email, $u['id']]);
            $pdoMaster->prepare("UPDATE accesos SET email=? WHERE email=? AND empresa_id=?")->execute([$email, $u['email'], $empresaId]);
        }
        // La baja del empleado desactiva su usuario; a un Administrador nunca (se gestiona en Usuarios)
        $activo = $u['rol'] === 'admin' ? (int)$u['activo'] : (int)$e['activo'];
        $pdo->prepare("UPDATE usuarios SET nombre=?, activo=? WHERE id=?")->execute([$nombre, $activo, $u['id']]);
        return 'actualizado';
    }

    if ($email === '') return 'Sin correo';
    $s = $pdo->prepare("SELECT id, empleado_id FROM usuarios WHERE email=?");
    $s->execute([$email]);
    if ($x = $s->fetch()) {
        if ($x['empleado_id']) return "El correo $email ya es el usuario de otro empleado";
        $pdo->prepare("UPDATE usuarios SET empleado_id=? WHERE id=?")->execute([$empleadoId, $x['id']]);
        return 'vinculado';
    }
    if (!$e['activo']) return 'Empleado inactivo';
    $clave = preg_replace('/\D/', '', (string)$e['identidad']);
    if ($clave === '') return 'Sin número de identidad (es la contraseña inicial)';
    if (emailEnUso($pdoMaster, $email, $empresaId)) return "El correo $email ya está registrado en la empresa";

    $pdoMaster->prepare("INSERT INTO accesos (email, empresa_id) VALUES (?,?)")->execute([$email, $empresaId]);
    try {
        $pdo->prepare("INSERT INTO usuarios (nombre, email, password, debe_cambiar_password, rol, empleado_id, activo)
                       VALUES (?,?,?,1,'empleado',?,1)")
            ->execute([$nombre, $email, password_hash($clave, PASSWORD_BCRYPT), $empleadoId]);
    } catch (PDOException $ex) {
        $pdoMaster->prepare("DELETE FROM accesos WHERE email=? AND empresa_id=?")->execute([$email, $empresaId]);
        return 'No se pudo crear: ' . $ex->getMessage();
    }
    return 'creado';
}

// RTN hondureño: 14 dígitos. Acepta guiones o espacios al escribirlo.
function normalizarRtn(string $rtn): string {
    return preg_replace('/\D/', '', $rtn);
}

function rtnValido(string $rtn): bool {
    return (bool)preg_match('/^\d{14}$/', $rtn);
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Token de seguridad inválido.']));
    }
}

// ── Bitácora de actividad (tabla auditoria de cada empresa) ─────────
// Registra inicios de sesión, accesos denegados, impresiones y toda acción enviada
// por los usuarios. Nunca interrumpe la operación si la escritura falla.
function registrarEvento(PDO $pdo, string $accion, array $o = []): void {
    try {
        $pdo->prepare("INSERT INTO auditoria (usuario_id, email, accion, modulo, tabla_afectada, registro_id, resultado, ip, detalle)
                       VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([
                array_key_exists('usuario_id', $o) ? $o['usuario_id'] : ($_SESSION['usuario_id'] ?? null),
                $o['email']       ?? ($_SESSION['usuario_email'] ?? null),
                $accion,
                $o['modulo']      ?? basename($_SERVER['SCRIPT_NAME'] ?? ''),
                $o['tabla']       ?? null,
                $o['registro_id'] ?? null,
                $o['resultado']   ?? 'ok',
                $_SERVER['REMOTE_ADDR'] ?? '',
                isset($o['detalle']) ? mb_substr($o['detalle'], 0, 4000) : null,
            ]);
    } catch (Throwable $e) { /* la bitácora no debe impedir el trabajo del usuario */ }
}

// Registro explícito de un módulo (tabla y registro afectados). Si un módulo lo usa,
// la bitácora automática de esa petición no duplica el evento.
function registrarAuditoria(PDO $pdo, string $accion, string $tabla, int $id, string $detalle = '') {
    $GLOBALS['_bitacora_explicita'] = true;
    registrarEvento($pdo, $accion, ['tabla' => $tabla, 'registro_id' => $id ?: null, 'detalle' => $detalle]);
}

// Datos enviados, sin contraseñas ni tokens, para el detalle de la bitácora
function resumenDatosBitacora(array $datos): string {
    $partes = [];
    foreach ($datos as $k => $v) {
        if ($k === '_action' || preg_match('/csrf|pass|clave|token/i', (string)$k)) continue;
        $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string)$v;
        if ($v === '') continue;
        // El número de identidad es la contraseña inicial de los empleados: solo sus últimos 4 dígitos
        if ($k === 'identidad') $v = '…' . substr(preg_replace('/\D/', '', $v), -4);
        $partes[] = $k . '=' . (mb_strlen($v) > 150 ? mb_substr($v, 0, 150) . '…' : $v);
    }
    foreach ($_FILES as $k => $f) {
        if (!empty($f['name']) && !is_array($f['name'])) $partes[] = $k . '=[archivo ' . $f['name'] . ']';
    }
    return implode('; ', $partes);
}

// Se ejecuta al terminar cada petición POST de un usuario con sesión (ver requireLogin)
function registrarPeticion(): void {
    if (empty($GLOBALS['pdo'])) return;
    $res    = $GLOBALS['_resultado'] ?? null;               // [ok|error, mensaje, id] desde jsonOk/jsonErr
    $err    = error_get_last();
    $fatal  = $err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
    $error  = $fatal || ($res && $res[0] === 'error') || http_response_code() >= 400;
    $act    = (string)($_POST['_action'] ?? '');
    $postId = (int)($_POST['id'] ?? 0);

    // Procesos: quién los creó o editó (visibilidad, firma "Elaborado por") y pasos del flujo
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (!$error && isset(PROCESOS[$script])) {
        $regId = $postId ?: (int)($res[2] ?? 0);
        try {
            if (in_array($act, ['guardar', 'crear'], true)) registrarParticipacion($GLOBALS['pdo'], $script, $regId);
            anotarFlujoTrasPeticion($GLOBALS['pdo'], $script, $act, $regId);
        } catch (Throwable $e) {}
    } elseif (!$error && isset(EDICION_PROCESOS[$script]) && in_array($act, ['guardar', 'crear', 'generar'], true)) {
        // Procesos sin flujo (planillas, préstamos…): quién los creó, para la regla de edición
        try { registrarParticipacion($GLOBALS['pdo'], $script, $postId ?: (int)($res[2] ?? 0)); } catch (Throwable $e) {}
    }
    // Proceso editado: vuelve a su estado inicial, sin aprobaciones, y su OP se actualiza (config/edicion.php)
    $paramEdicion = EDICION_PROCESOS[$script]['acciones'][$act] ?? null;
    if (!$error && $paramEdicion && $act !== 'eliminar' && ($idEditado = (int)($_POST[$paramEdicion] ?? 0))) {
        try { aplicarEdicion($GLOBALS['pdo'], $script, $idEditado); } catch (Throwable $e) {}
    }
    // Proceso eliminado: también sus documentos adjuntos (config/adjuntos.php)
    if (!$error && $act === 'eliminar' && isset(EDICION_PROCESOS[$script]) && $postId) {
        try {
            $tablaEl = EDICION_PROCESOS[$script]['tabla'];
            $s = $GLOBALS['pdo']->prepare("SELECT 1 FROM `$tablaEl` WHERE id = ?");
            $s->execute([$postId]);
            if (!$s->fetchColumn()) eliminarAdjuntosDe($GLOBALS['pdo'], $tablaEl, $postId);
        } catch (Throwable $e) {}
    }
    // Proceso autorizado: su Orden de Pago con el mismo correlativo (config/correlativos.php)
    if (!$error && isset(ORDEN_PAGO_AUTOMATICA[$script])) {
        try { ordenPagoTrasPeticion($GLOBALS['pdo'], $script, $act, $postId ?: (int)($res[2] ?? 0)); }
        catch (Throwable $e) {
            registrarEvento($GLOBALS['pdo'], 'INSERT', ['modulo' => 'compras_pago.php', 'resultado' => 'error',
                'detalle' => 'No se pudo generar la orden de pago automática: ' . $e->getMessage()]);
        }
    }

    if (!empty($GLOBALS['_bitacora_explicita'])) return;   // el módulo ya registró el evento

    // Tipo de acción: eliminación, creación/modificación según traiga id, o el nombre de la acción
    if (preg_match('/elimin|borrar|anular|quitar|baja|delete/i', $act))           $accion = 'DELETE';
    elseif ($act === '' || preg_match('/^(guardar|crear|nuevo|registrar|agregar|editar)/i', $act)) $accion = $postId ? 'UPDATE' : 'INSERT';
    else                                                                            $accion = strtoupper($act);

    $mensaje = $fatal ? 'Error interno: ' . $err['message'] : ($res[1] ?? '');
    registrarEvento($GLOBALS['pdo'], $accion, [
        'registro_id' => $postId ?: ((int)($res[2] ?? 0) ?: null),
        'resultado'   => $error ? 'error' : 'ok',
        'detalle'     => trim(($act ? "[$act] " : '') . $mensaje . ' | ' . resumenDatosBitacora($_POST), ' |'),
    ]);
}

require_once __DIR__ . '/aprobaciones.php';
require_once __DIR__ . '/historico.php';
require_once __DIR__ . '/correlativos.php';
require_once __DIR__ . '/edicion.php';
require_once __DIR__ . '/adjuntos.php';

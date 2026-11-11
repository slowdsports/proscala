<?php
// ── Flujo de aprobación: Creado → Revisado → Autorizado ─────────────
// Cada usuario tiene sus propias facultades, independientes entre sí (Usuarios → Facultades;
// tablas usuario_facultades y usuario_facultad_usuarios):
//   revisar   → revisa el proceso o lo rechaza mientras espera revisión;
//   autorizar → lo autoriza o lo rechaza ya revisado;
//   corregir  → edita el proceso que creó otro (config/edicion.php).
// Cada facultad tiene su alcance: todos los puestos por debajo del suyo en el organigrama, o solo
// los usuarios que se elijan (siempre dentro de ese rango: nunca alguien de su mismo nivel o de
// otra rama, salvo los colegas de un puesto que se revisa entre sí). El Administrador y el super
// administrador tienen todas sobre todos. La revisión es obligatoria antes de autorizar, quien
// revisa no autoriza el mismo proceso y nadie revisa, autoriza ni rechaza lo que él mismo creó.
// Los avisos (config/notificaciones.php) siguen las mismas facultades: a quien solo autoriza no le
// llegan los de "revisar".
// Quién creó el proceso está en registro_usuarios; los demás pasos, en aprobaciones.

//   tabla      → tabla del proceso                    docs → tipos de print.php del proceso
//   autorizado → estado que significa "autorizado" (null: autorizar no cambia el estado)
//   rechazado  → estado de rechazo (null: no se rechaza desde el flujo)
//   abiertos   → estados en los que aún se puede revisar, autorizar o rechazar
const PROCESOS = [
    'compras_solicitud.php' => ['tabla' => 'solicitud_compra', 'docs' => ['sc', 'proceso'], 'autorizado' => 'aprobada', 'rechazado' => 'rechazada', 'abiertos' => ['pendiente']],
    'gastos_viajes.php'     => ['tabla' => 'gastos_viaje',     'docs' => ['gv', 'gva'],      'autorizado' => 'aprobada', 'rechazado' => 'rechazada', 'abiertos' => ['pendiente']],
    'gastos_solicitud.php'  => ['tabla' => 'solicitud_gastos', 'docs' => ['sg'],             'autorizado' => 'aprobada', 'rechazado' => 'rechazada', 'abiertos' => ['pendiente']],
    'vacaciones.php'        => ['tabla' => 'vacaciones',       'docs' => ['vac'],            'autorizado' => 'aprobada', 'rechazado' => 'rechazada', 'abiertos' => ['pendiente']],
    'permisos.php'          => ['tabla' => 'permisos',         'docs' => ['per'],            'autorizado' => 'aprobado', 'rechazado' => 'rechazado', 'abiertos' => ['pendiente']],
    'compras_cotizaciones.php' => ['tabla' => 'cotizaciones',  'docs' => ['co'],             'autorizado' => 'aprobada', 'rechazado' => 'rechazada', 'abiertos' => ['pendiente']],
    'compras_orden.php'     => ['tabla' => 'ordenes_compra',   'docs' => ['oc'],             'autorizado' => null,       'rechazado' => null,        'abiertos' => ['emitida', 'parcial', 'completa']],
    // Su estado (completa / parcial / devolución) describe lo recibido: revisar y autorizar no lo cambian
    'compras_recepcion.php' => ['tabla' => 'notas_recepcion',  'docs' => [],                 'autorizado' => null,       'rechazado' => null,        'abiertos' => ['completa', 'parcial', 'devolucion']],
    'compras_pago.php'      => ['tabla' => 'ordenes_pago',     'docs' => ['op'],             'autorizado' => 'aprobada', 'rechazado' => null,        'abiertos' => ['pendiente']],
    // Planillas y liquidaciones laborales: autorizar las aprueba (y genera su orden de pago)
    'planillas.php'            => ['tabla' => 'planilla_periodos',   'docs' => ['pl', 'plp', 'plc', 'plv'], 'autorizado' => 'aprobada', 'rechazado' => null, 'abiertos' => ['borrador', 'procesada']],
    'planillas_asignacion.php' => ['tabla' => 'planilla_asignacion', 'docs' => ['pa', 'pav'],              'autorizado' => 'aprobada', 'rechazado' => null, 'abiertos' => ['borrador']],
    'planillas_beneficios.php' => ['tabla' => 'planilla_beneficios', 'docs' => ['pb', 'pbp', 'pbv'],       'autorizado' => 'aprobada', 'rechazado' => null, 'abiertos' => ['borrador']],
    'liquidaciones.php'        => ['tabla' => 'liquidaciones',       'docs' => ['liq'],                    'autorizado' => 'aprobada', 'rechazado' => null, 'abiertos' => ['borrador']],
];

// ── Facultades de cada usuario ──────────────────────────────────────
const FACULTADES = [
    'revisar'   => ['Revisar',   'Revisa los procesos y los rechaza mientras esperan revisión'],
    'autorizar' => ['Autorizar', 'Autoriza los procesos ya revisados o los rechaza'],
    'corregir'  => ['Corregir',  'Edita los procesos que crearon otros'],
];

/**
 * Facultades de un usuario: [facultad => true (todos los puestos por debajo) | [ids de usuarios elegidos]],
 * solo las que tiene. El Administrador, todas sobre todos; un puesto con "solo sus solicitudes", ninguna.
 * $refrescar vacía la memoria (tras guardarlas en Usuarios, y en las pruebas).
 */
function facultadesUsuario(int $uid, bool $refrescar = false): array {
    static $cache = [];
    if ($refrescar) { $cache = []; rolDeUsuario(0, true); }
    if (!$uid || empty($GLOBALS['pdo'])) return [];
    if (isset($cache[$uid])) return $cache[$uid];
    $pdo = $GLOBALS['pdo'];
    $rol = rolDeUsuario($uid);
    if ($rol === 'admin') return $cache[$uid] = array_fill_keys(array_keys(FACULTADES), true);
    if ($rol === null || !empty(roles()[$rol]['solo_propios'])) return $cache[$uid] = [];
    $f = [];
    try {
        $s = $pdo->prepare("SELECT facultad, alcance FROM usuario_facultades WHERE usuario_id = ?");
        $s->execute([$uid]);
        $sel = $pdo->prepare("SELECT sobre_usuario_id FROM usuario_facultad_usuarios WHERE usuario_id = ? AND facultad = ?");
        foreach ($s->fetchAll() as $r) {
            if ($r['alcance'] === 'todos') { $f[$r['facultad']] = true; continue; }
            $sel->execute([$uid, $r['facultad']]);
            $f[$r['facultad']] = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
        }
    } catch (PDOException $e) { /* base sin las tablas todavía (actualizar_bd.php) */ }
    return $cache[$uid] = $f;
}

/** Puesto de un usuario activo (null si no existe o está inactivo). */
function rolDeUsuario(int $uid, bool $refrescar = false): ?string {
    static $cache = [];
    if ($refrescar) $cache = [];
    if (!$uid) return null;
    if (array_key_exists($uid, $cache)) return $cache[$uid];
    $s = $GLOBALS['pdo']->prepare("SELECT rol FROM usuarios WHERE id = ? AND activo = 1");
    $s->execute([$uid]);
    return $cache[$uid] = ($s->fetchColumn() ?: null);
}

/**
 * ¿El puesto $rol está en el rango para aprobar o corregir lo que crea alguien del puesto $rolCreador?
 * Por encima de él en su rama del organigrama, o su colega si el puesto se revisa entre sí.
 */
function enRango(string $rol, string $rolCreador): bool {
    return $rol === 'admin' || esSuperiorDe($rol, $rolCreador) || ($rol === $rolCreador && puedenAprobarseEntreColegas($rol));
}

/**
 * ¿El usuario $uid puede ejercer $facultad sobre lo creado por $creadorId (de puesto $rolCreador)?
 * Hace falta tener la facultad, estar en el rango del organigrama y, si su alcance es una selección,
 * que quien lo creó esté en ella. Sin creador conocido (procesos antiguos) basta el alcance "todos".
 */
function cubreFacultad(int $uid, string $facultad, ?int $creadorId, ?string $rolCreador): bool {
    $f = facultadesUsuario($uid)[$facultad] ?? null;
    if ($f === null) return false;
    $rol = rolDeUsuario($uid);
    if ($rol === 'admin') return true;
    if (!$creadorId || !$rolCreador || !isset(roles()[$rolCreador])) return $f === true;
    return enRango($rol, $rolCreador) && ($f === true || in_array($creadorId, $f, true));
}

/**
 * ¿Con qué puede el usuario actual ejercer $facultad sobre lo creado por $creadorId? 'propia' (sus
 * facultades), ['id', 'nombre'] de quien le delegó, o null si no puede. Nunca en nombre de quien lo creó.
 */
function habilitacion(string $facultad, ?int $creadorId, ?string $rolCreador) {
    if (tieneAccesoTotal()) return 'propia';
    if (soloRegistrosPropios()) return null;
    if (cubreFacultad((int)($_SESSION['usuario_id'] ?? 0), $facultad, $creadorId, $rolCreador)) return 'propia';
    foreach (delegantesActivos() as $id => $d) {
        if ($id !== $creadorId && cubreFacultad($id, $facultad, $creadorId, $rolCreador)) return ['id' => $id, 'nombre' => $d['nombre']];
    }
    return null;
}

/** ¿Tiene el usuario actual la facultad sobre alguien (propia o por una delegación vigente)? */
function tieneFacultad(string $facultad): bool {
    if (tieneAccesoTotal()) return true;
    if (soloRegistrosPropios()) return false;
    if (isset(facultadesUsuario((int)($_SESSION['usuario_id'] ?? 0))[$facultad])) return true;
    foreach (delegantesActivos() as $id => $_) if (isset(facultadesUsuario($id)[$facultad])) return true;
    return false;
}

/** Por qué el usuario actual no puede ejercer $facultad sobre el proceso de $creador (['usuario_id', 'rol']). */
function motivoSinFacultad(string $facultad, ?array $creador): string {
    $verbo = ['revisar' => 'revisar', 'autorizar' => 'autorizar', 'corregir' => 'corregir'][$facultad];
    if (!tieneFacultad($facultad)) return "Usted no tiene la facultad de $verbo procesos.";
    $cid = (int)($creador['usuario_id'] ?? 0);
    if ($cid && isset(delegantesActivos()[$cid]) && !cubreFacultad((int)$_SESSION['usuario_id'], $facultad, $cid, $creador['rol'] ?? null)) {
        return 'No puede aprobar ni corregir, en nombre de quien le delegó, un proceso que esa persona creó.';
    }
    $rol = $creador['rol'] ?? null;
    if ($rol && isset(roles()[$rol]) && !enRango($_SESSION['usuario_rol'] ?? '', $rol)) {
        return 'Según el organigrama, solo un puesto superior a ' . roles()[$rol]['nombre'] . " puede $verbo este proceso.";
    }
    return "Su facultad de $verbo no incluye los procesos de esta persona (alcance definido en Usuarios).";
}

// ── Delegación de aprobaciones (tabla delegaciones; se configura en Mi Perfil) ──
// Quien tiene facultades puede delegarlas en otro usuario por un rango de fechas (p. ej. sus
// vacaciones). Mientras esté vigente, el delegado ejerce las facultades de quien delegó, con su
// mismo alcance, y entra a los módulos de procesos de su puesto. No puede aprobar lo que creó
// quien delegó ni autorizar lo que esa persona revisó. Cada paso queda "en nombre de" ella.

/** Quienes delegaron hoy sus aprobaciones en el usuario actual: [usuario_id => ['rol', 'nombre', 'modulos']]. */
function delegantesActivos(): array {
    static $cache = [];
    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    if (isset($cache[$uid])) return $cache[$uid];
    $cache[$uid] = [];
    if (!$uid || empty($GLOBALS['pdo'])) return [];
    try {
        $s = $GLOBALS['pdo']->prepare("SELECT d.usuario_id, u.rol, u.nombre FROM delegaciones d JOIN usuarios u ON u.id = d.usuario_id AND u.activo = 1
                                      WHERE d.delegado_id = ? AND d.cancelada = 0 AND CURDATE() BETWEEN d.desde AND d.hasta");
        $s->execute([$uid]);
        $m = $GLOBALS['pdo']->prepare("SELECT modulo FROM rol_modulos WHERE rol = ?");
        foreach ($s->fetchAll() as $d) {
            if (!facultadesUsuario((int)$d['usuario_id'])) continue;
            $m->execute([$d['rol']]);
            $cache[$uid][(int)$d['usuario_id']] = ['rol' => $d['rol'], 'nombre' => $d['nombre'], 'modulos' => $m->fetchAll(PDO::FETCH_COLUMN)];
        }
    } catch (PDOException $e) { /* base sin la tabla todavía (actualizar_bd.php) */ }
    return $cache[$uid];
}

/** ¿Puede abrir el módulo de procesos por una delegación? (puedeAcceder) */
function accesoPorDelegacion(string $modulo): bool {
    if (!isset(PROCESOS[$modulo])) return false;
    foreach (delegantesActivos() as $d) if ($d['rol'] === 'admin' || in_array($modulo, $d['modulos'], true)) return true;
    return false;
}

// ¿El usuario puede revisar o autorizar procesos? (facultad propia o por delegación)
function puedeAprobar(): bool {
    return tieneFacultad('revisar') || tieneFacultad('autorizar');
}

// ── Jerarquía (organigrama de Roles y Permisos: roles.depende_de) ───
// Los procesos de un puesto solo los revisan, autorizan, rechazan o corrigen usuarios de los puestos
// que están por encima de él en su rama del organigrama (y el Administrador, que tiene acceso total),
// según las facultades y el alcance de cada uno.

/** ¿$superior está por encima de $rol en el organigrama (jefe directo o más arriba)? */
function esSuperiorDe(string $superior, string $rol): bool {
    $roles = roles();
    for ($p = $roles[$rol]['depende_de'] ?? null, $n = 0; $p !== null && $n < 50; $p = $roles[$p]['depende_de'] ?? null, $n++) {
        if ($p === $superior) return true;
    }
    return false;
}

/**
 * ¿Los colegas de un mismo puesto se aprueban y corrigen entre sí? (nunca lo propio: eso lo impiden
 * accionesFlujo y puedeEditar). Sí, si el puesto está en la cima del organigrama (no depende de
 * nadie: no hay nadie por encima) o tiene "sus miembros se revisan entre sí" (roles.aprueba_colegas,
 * en Roles y Permisos; p. ej. Administración). Cada uno, además, necesita la facultad.
 */
function puedenAprobarseEntreColegas(string $rol): bool {
    $p = roles()[$rol] ?? null;
    return $p && empty($p['solo_propios']) && (empty($p['depende_de']) || !empty($p['aprueba_colegas']));
}

/**
 * Quién define a quién le toca aprobar el proceso: quien lo creó (['usuario_id', 'rol']), o null.
 * Una orden de pago generada por otro proceso es parte de él: cuenta el creador del proceso de origen
 * (no quien la generó al autorizar, que es un jefe y dejaría la OP solo en manos de sus superiores).
 */
function creadorProceso(PDO $pdo, string $tabla, int $id): ?array {
    if ($tabla === 'ordenes_pago') {
        $s = $pdo->prepare("SELECT origen_tabla, origen_id FROM ordenes_pago WHERE id = ?");
        $s->execute([$id]);
        $o = $s->fetch();
        if ($o && $o['origen_tabla'] && ($c = creadorProceso($pdo, $o['origen_tabla'], (int)$o['origen_id']))) return $c;
    }
    $s = $pdo->prepare("SELECT u.id AS usuario_id, u.rol FROM registro_usuarios r JOIN usuarios u ON u.id = r.usuario_id
                        WHERE r.tabla = ? AND r.registro_id = ? AND r.participacion = 'creador' LIMIT 1");
    $s->execute([$tabla, $id]);
    $c = $s->fetch();
    return $c ? ['usuario_id' => (int)$c['usuario_id'], 'rol' => $c['rol']] : null;
}

/** Puesto de quien creó el proceso (creadorProceso). */
function rolCreador(PDO $pdo, string $tabla, int $id): ?string {
    return creadorProceso($pdo, $tabla, $id)['rol'] ?? null;
}

/**
 * Situación del proceso: ['estado', 'creado', 'revisado', 'autorizado', 'rechazado'], donde cada
 * paso es ['usuario_id', 'nombre', 'fecha'] o null. Null si el proceso no existe.
 */
function estadoFlujo(PDO $pdo, string $modulo, int $id): ?array {
    if (!isset(PROCESOS[$modulo]) || !$id) return null;
    $tabla = PROCESOS[$modulo]['tabla'];
    $s = $pdo->prepare("SELECT estado FROM `$tabla` WHERE id = ?");
    $s->execute([$id]);
    $estado = $s->fetchColumn();
    if ($estado === false) return null;

    $nombre = "COALESCE(CONCAT(e.nombre,' ',e.apellidos), u.nombre)";
    $flujo  = ['id' => $id, 'estado' => $estado, 'creado' => null, 'revisado' => null, 'autorizado' => null, 'rechazado' => null];
    $s = $pdo->prepare("SELECT r.usuario_id, $nombre AS nombre, r.created_at AS fecha, u.rol
        FROM registro_usuarios r JOIN usuarios u ON u.id = r.usuario_id LEFT JOIN empleados e ON e.id = u.empleado_id
        WHERE r.tabla = ? AND r.registro_id = ? AND r.participacion = 'creador' LIMIT 1");
    $s->execute([$tabla, $id]);
    $flujo['creado'] = $s->fetch() ?: null;
    $s = $pdo->prepare("SELECT a.paso, a.usuario_id, $nombre AS nombre, a.created_at AS fecha, a.comentario
        FROM aprobaciones a JOIN usuarios u ON u.id = a.usuario_id LEFT JOIN empleados e ON e.id = u.empleado_id
        WHERE a.tabla = ? AND a.registro_id = ?");
    $s->execute([$tabla, $id]);
    foreach ($s->fetchAll() as $p) $flujo[$p['paso']] = $p;
    return $flujo;
}

/** Qué puede hacer el usuario actual con el proceso: ['revisar' => bool|motivo, ...]. true = permitido. */
function accionesFlujo(array $flujo, string $modulo): array {
    $def     = PROCESOS[$modulo];
    $uid     = (int)($_SESSION['usuario_id'] ?? 0);
    $delegantes = array_keys(delegantesActivos());
    $creadoPor  = (int)($flujo['creado']['usuario_id'] ?? 0);
    $revisadoPor = (int)($flujo['revisado']['usuario_id'] ?? 0);
    $creador = $flujo['creado'] && $creadoPor === $uid;
    $abierto = in_array($flujo['estado'], $def['abiertos'], true) && !$flujo['autorizado'] && !$flujo['rechazado'];
    $origen  = creadorProceso($GLOBALS['pdo'], $def['tabla'], (int)$flujo['id']);
    // Cada paso pide su facultad: revisar (y rechazar sin revisar) o autorizar (y rechazar ya revisado)
    $puede = function (string $facultad) use ($abierto, $creador, $origen) {
        if (!$abierto) return 'El proceso ya no está pendiente.';
        if ($creador)  return 'No puede revisar, autorizar ni rechazar un proceso que usted creó.';
        return habilitacion($facultad, $origen['usuario_id'] ?? null, $origen['rol'] ?? null) !== null ? true : motivoSinFacultad($facultad, $origen);
    };

    $autorizar = $puede('autorizar');
    if ($autorizar === true) $autorizar = match (true) {
        !$flujo['revisado']                       => 'Primero debe ser revisado.',
        $revisadoPor === $uid                     => 'Quien revisó no puede autorizar el mismo proceso.',
        in_array($revisadoPor, $delegantes, true) => 'Lo revisó quien le delegó: la autorización le corresponde a otra persona.',
        // Una OP generada por un proceso editado espera a que el proceso se autorice de nuevo
        $modulo === 'compras_pago.php'            => origenOrdenPagoAutorizado($GLOBALS['pdo'], (int)$flujo['id']),
        default                                   => true,
    };
    $revisar = $puede('revisar');
    if ($revisar === true && $flujo['revisado']) $revisar = 'El proceso ya fue revisado.';
    return [
        'revisar'   => $revisar,
        'autorizar' => $autorizar,
        'rechazar'  => $def['rechazado'] === null ? 'Este proceso no se rechaza desde el flujo.' : $puede($flujo['revisado'] ? 'autorizar' : 'revisar'),
    ];
}

/** Facultad que pide un paso del flujo. */
const FACULTAD_DEL_PASO = ['revisado' => 'revisar', 'autorizado' => 'autorizar', 'rechazado' => null];

function registrarPasoFlujo(PDO $pdo, string $modulo, int $id, string $paso, ?string $comentario = null): void {
    // Aprobado gracias a una delegación: queda "en nombre de" quien delegó
    if ($comentario === null && ($facultad = FACULTAD_DEL_PASO[$paso] ?? null)) {
        $c = creadorProceso($pdo, PROCESOS[$modulo]['tabla'], $id);
        $h = habilitacion($facultad, $c['usuario_id'] ?? null, $c['rol'] ?? null);
        if (is_array($h)) $comentario = 'En nombre de ' . $h['nombre'];
    }
    $pdo->prepare("INSERT INTO aprobaciones (tabla, registro_id, paso, usuario_id, comentario) VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), comentario = VALUES(comentario), created_at = CURRENT_TIMESTAMP")
        ->execute([PROCESOS[$modulo]['tabla'], $id, $paso, $_SESSION['usuario_id'], $comentario]);
}

/** Motivo del rechazo enviado con la petición (obligatorio para rechazar). */
function comentarioRechazo(): string {
    return trim(preg_replace('/\s+/u', ' ', (string)($_POST['comentario_rechazo'] ?? '')));
}

/**
 * Se llama desde requireLogin() en los POST de un proceso. Atiende "revisar" y "autorizar"
 * (procesos sin estado de autorización) y valida los cambios de estado que autorizan o
 * rechazan antes de que el módulo los aplique.
 */
function validarFlujoAprobacion(PDO $pdo, string $modulo): void {
    $act = $_POST['_action'] ?? '';
    if (!in_array($act, ['revisar', 'autorizar', 'cambiar_estado'], true)) return;
    $def    = PROCESOS[$modulo];
    $id     = (int)($_POST['id'] ?? 0);
    $estado = $_POST['estado'] ?? '';

    // Cambios de estado posteriores a la autorización (pagar, liquidar…): los rige el módulo
    if ($act === 'cambiar_estado' && $estado !== $def['autorizado'] && $estado !== $def['rechazado']) return;

    $flujo = estadoFlujo($pdo, $modulo, $id);
    if (!$flujo) jsonErr('Proceso no encontrado.', 404);
    $acc = accionesFlujo($flujo, $modulo);
    $paso = $act === 'revisar' ? 'revisar' : (($act === 'autorizar' || $estado === $def['autorizado']) ? 'autorizar' : 'rechazar');
    if ($acc[$paso] !== true) {
        $GLOBALS['_bitacora_explicita'] = true;   // un solo evento en la bitácora
        registrarEvento($pdo, 'ACCESO_DENEGADO', ['resultado' => 'error', 'registro_id' => $id, 'detalle' => "No pudo $paso: {$acc[$paso]}"]);
        jsonErr($acc[$paso], 403);
    }
    // Rechazar exige el motivo (lo verán todos los que vean el proceso); la evidencia es opcional
    // pero se valida antes de que el módulo cambie el estado
    if ($paso === 'rechazar') {
        if (mb_strlen(comentarioRechazo()) < 5) jsonErr('Escriba el motivo del rechazo (al menos 5 caracteres).');
        foreach (archivosSubidos('evidencia') as $a) {
            if ($motivo = validarAdjunto($a)) jsonErr("Evidencia \"{$a['nombre']}\": $motivo.");
        }
    }

    if ($act === 'revisar') {
        registrarPasoFlujo($pdo, $modulo, $id, 'revisado');
        registrarAuditoria($pdo, 'REVISAR', $def['tabla'], $id, 'Proceso revisado');
        jsonOk(['id' => $id], 'Proceso revisado.');
    }
    if ($act === 'autorizar') {
        if ($def['autorizado'] !== null) jsonErr('Este proceso se autoriza cambiando su estado.');
        registrarPasoFlujo($pdo, $modulo, $id, 'autorizado');
        registrarAuditoria($pdo, 'AUTORIZAR', $def['tabla'], $id, 'Proceso autorizado');
        jsonOk(['id' => $id], 'Proceso autorizado.');
    }
    // cambiar_estado a autorizado / rechazado: lo aplica el módulo; registrarPeticion anota el paso
}

/** Al terminar la petición (registrarPeticion): pasos del flujo según la acción ya aplicada. */
function anotarFlujoTrasPeticion(PDO $pdo, string $modulo, string $act, int $id): void {
    if (!$id || !isset(PROCESOS[$modulo])) return;
    $def = PROCESOS[$modulo];
    if ($act === 'cambiar_estado') {
        $estado = $_POST['estado'] ?? '';
        if ($estado === $def['autorizado']) registrarPasoFlujo($pdo, $modulo, $id, 'autorizado');
        elseif ($estado !== '' && $estado === $def['rechazado']) {
            $motivo = mb_substr(comentarioRechazo(), 0, 2000);
            registrarPasoFlujo($pdo, $modulo, $id, 'rechazado', $motivo);
            // Historial de rechazos (se conserva aunque luego el proceso se corrija) y su evidencia
            $pdo->prepare("INSERT INTO rechazos (tabla, registro_id, usuario_id, motivo) VALUES (?,?,?,?)")
                ->execute([$def['tabla'], $id, $_SESSION['usuario_id'], $motivo]);
            $rechazoId = (int)$pdo->lastInsertId();
            $evidencias = [];
            foreach (archivosSubidos('evidencia') as $a) {
                if (guardarAdjunto($pdo, $def['tabla'], $id, $a, $rechazoId) === true) $evidencias[] = $a['nombre'];
            }
            registrarAuditoria($pdo, 'RECHAZAR', $def['tabla'], $id, 'Motivo: ' . $motivo
                . ($evidencias ? ' | Evidencia: ' . implode(', ', $evidencias) : ''));
        }
    } elseif (in_array($act, ['guardar', 'crear'], true) && (int)($_POST['id'] ?? 0)) {
        // Editar un proceso revisado y aún sin autorizar anula la revisión: debe revisarse de nuevo
        $pdo->prepare("DELETE FROM aprobaciones WHERE tabla = ? AND registro_id = ? AND paso = 'revisado'
                       AND NOT EXISTS (SELECT 1 FROM (SELECT 1 FROM aprobaciones WHERE tabla = ? AND registro_id = ? AND paso = 'autorizado') x)")
            ->execute([$def['tabla'], $id, $def['tabla'], $id]);
    }
}

/**
 * Firmas Elaborado / Revisado / Autorizado del documento con las personas reales del flujo.
 * Un paso que aún no ocurre queda con la línea en blanco. Sin creador registrado (procesos
 * antiguos) se conserva la firma #1 configurada.
 */
function firmasFlujo(PDO $pdo, array $firmas, string $modulo, int $id): array {
    $flujo = estadoFlujo($pdo, $modulo, $id);
    if (!$flujo) return $firmas;
    $pasos = [0 => ['creado', 'Elaborado por'], 1 => ['revisado', 'Revisado por'], 2 => ['autorizado', 'Autorizado por']];
    foreach ($pasos as $i => [$paso, $etiqueta]) {
        $firmas[$i] = $firmas[$i] ?? ['posicion' => $i + 1, 'etiqueta' => '', 'emp_nombre' => ''];
        if ($i === 0) {
            if ($firmas[0]['etiqueta'] === '') $firmas[0]['etiqueta'] = $etiqueta;
            if ($flujo['creado']) $firmas[0]['emp_nombre'] = $flujo['creado']['nombre'];
            continue;
        }
        $firmas[$i]['etiqueta']   = $etiqueta;
        $firmas[$i]['emp_nombre'] = $flujo[$paso]['nombre'] ?? ' ';   // ' ': línea en blanco (sigBlock)
    }
    ksort($firmas);
    return $firmas;
}

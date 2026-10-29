<?php
// ── Flujo de aprobación: Creado → Revisado → Autorizado ─────────────
// Pueden revisar, autorizar y rechazar los puestos marcados "Aprueba procesos" en Roles y
// Permisos (roles.aprueba), además del Administrador y el super administrador. La revisión
// es obligatoria antes de autorizar, quien revisa no autoriza el mismo proceso y nadie
// revisa, autoriza ni rechaza lo que él mismo creó.
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
    'compras_orden.php'     => ['tabla' => 'ordenes_compra',   'docs' => ['oc'],             'autorizado' => null,       'rechazado' => null,        'abiertos' => ['emitida', 'parcial', 'completa']],
    'compras_pago.php'      => ['tabla' => 'ordenes_pago',     'docs' => ['op'],             'autorizado' => 'aprobada', 'rechazado' => null,        'abiertos' => ['pendiente']],
];

// ── Delegación de aprobaciones (tabla delegaciones; se configura en Mi Perfil) ──
// Quien aprueba procesos puede delegar sus aprobaciones en otro usuario por un rango de fechas
// (p. ej. sus vacaciones). Mientras esté vigente, el delegado aprueba como si tuviera el puesto
// de quien delegó: su jerarquía y el acceso a los módulos de procesos. No puede aprobar lo que
// creó quien delegó ni autorizar lo que esa persona revisó. Cada paso queda "en nombre de" ella.

/** ¿Un puesto aprueba procesos? */
function rolApruebaProcesos(string $rol): bool {
    if ($rol === 'admin') return true;
    $r = roles()[$rol] ?? null;
    return $r && $r['aprueba'] && !$r['solo_propios'];
}

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
            if (!rolApruebaProcesos($d['rol'])) continue;
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

// ¿El puesto puede revisar y autorizar procesos? (propio o por delegación)
function puedeAprobar(): bool {
    if (soloRegistrosPropios()) return false;
    return tieneAccesoTotal() || !empty($_SESSION['rol_aprueba']) || (bool)delegantesActivos();
}

// ── Jerarquía (organigrama de Roles y Permisos: roles.depende_de) ───
// Los procesos de un puesto solo los revisan, autorizan, rechazan o corrigen los puestos que
// están por encima de él en su rama del organigrama (y el Administrador, que tiene acceso total).

/** ¿$superior está por encima de $rol en el organigrama (jefe directo o más arriba)? */
function esSuperiorDe(string $superior, string $rol): bool {
    $roles = roles();
    for ($p = $roles[$rol]['depende_de'] ?? null, $n = 0; $p !== null && $n < 50; $p = $roles[$p]['depende_de'] ?? null, $n++) {
        if ($p === $superior) return true;
    }
    return false;
}

/**
 * ¿La jerarquía permite al usuario actual aprobar un proceso creado por alguien del puesto $rolCreador?
 * null si sí; si no, el motivo. Sin creador conocido (procesos antiguos) no hay restricción.
 * Entre colegas del mismo puesto (nunca lo propio: eso lo impiden accionesFlujo y puedeEditar), si
 * el puesto aprueba procesos y: está en la cima del organigrama (no depende de nadie, p. ej.
 * Administrador: no hay nadie por encima), o tiene "sus miembros se revisan entre sí"
 * (roles.aprueba_colegas, en Roles y Permisos; p. ej. Administración).
 */
function puedenAprobarseEntreColegas(string $rol): bool {
    $p = roles()[$rol] ?? null;
    return $p && !empty($p['aprueba']) && (empty($p['depende_de']) || !empty($p['aprueba_colegas']));
}

function motivoJerarquia(?string $rolCreador): ?string {
    if (tieneAccesoTotal() || !$rolCreador || !isset(roles()[$rolCreador])) return null;
    if (esSuperiorDe($_SESSION['usuario_rol'] ?? '', $rolCreador)) return null;
    if (($_SESSION['usuario_rol'] ?? '') === $rolCreador && puedenAprobarseEntreColegas($rolCreador)) return null;
    if (delegacionQueHabilita($rolCreador)) return null;
    return 'Según el organigrama, solo un puesto superior a ' . roles()[$rolCreador]['nombre'] . ' puede aprobar o corregir este proceso.';
}

/** ¿Lo aprueba con su propio puesto (sin delegación)? */
function apruebaConPuestoPropio(?string $rolCreador): bool {
    if (tieneAccesoTotal()) return true;
    if (empty($_SESSION['rol_aprueba'])) return false;
    return !$rolCreador || !isset(roles()[$rolCreador]) || esSuperiorDe($_SESSION['usuario_rol'] ?? '', $rolCreador);
}

/** Quien delegó y permite aprobar un proceso creado por $rolCreador: ['id', 'nombre'] o null. */
function delegacionQueHabilita(?string $rolCreador): ?array {
    foreach (delegantesActivos() as $id => $d) {
        if ($d['rol'] === 'admin' || !$rolCreador || !isset(roles()[$rolCreador]) || esSuperiorDe($d['rol'], $rolCreador)) {
            return ['id' => $id, 'nombre' => $d['nombre']];
        }
    }
    return null;
}

/**
 * Puesto que define quién puede aprobar el proceso: el de quien lo creó. Una orden de pago
 * generada por otro proceso es parte de él: cuenta el creador del proceso de origen (no quien
 * la generó al autorizar, que es un jefe y dejaría la OP solo en manos de sus superiores).
 */
function rolCreador(PDO $pdo, string $tabla, int $id): ?string {
    if ($tabla === 'ordenes_pago') {
        $s = $pdo->prepare("SELECT origen_tabla, origen_id FROM ordenes_pago WHERE id = ?");
        $s->execute([$id]);
        $o = $s->fetch();
        if ($o && $o['origen_tabla'] && ($rol = rolCreador($pdo, $o['origen_tabla'], (int)$o['origen_id']))) return $rol;
    }
    $s = $pdo->prepare("SELECT u.rol FROM registro_usuarios r JOIN usuarios u ON u.id = r.usuario_id
                        WHERE r.tabla = ? AND r.registro_id = ? AND r.participacion = 'creador' LIMIT 1");
    $s->execute([$tabla, $id]);
    return $s->fetchColumn() ?: null;
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

    $base = !puedeAprobar() ? 'Su puesto no puede revisar ni autorizar procesos.'
          : (!$abierto      ? 'El proceso ya no está pendiente.'
          : ($creador       ? 'No puede revisar, autorizar ni rechazar un proceso que usted creó.'
          : (in_array($creadoPor, $delegantes, true) && !apruebaConPuestoPropio(rolCreador($GLOBALS['pdo'], $def['tabla'], (int)$flujo['id']))
                            ? 'No puede aprobar, en nombre de quien le delegó, un proceso que esa persona creó.'
          : (motivoJerarquia(rolCreador($GLOBALS['pdo'], $def['tabla'], (int)$flujo['id'])) ?? true))));
    if ($base !== true)                                            $autorizar = $base;
    elseif (!$flujo['revisado'])                                   $autorizar = 'Primero debe ser revisado.';
    elseif ($revisadoPor === $uid)                                 $autorizar = 'Quien revisó no puede autorizar el mismo proceso.';
    elseif (in_array($revisadoPor, $delegantes, true))             $autorizar = 'Lo revisó quien le delegó: la autorización le corresponde a otra persona.';
    // Una OP generada por un proceso editado espera a que el proceso se autorice de nuevo
    elseif ($modulo === 'compras_pago.php')                        $autorizar = origenOrdenPagoAutorizado($GLOBALS['pdo'], (int)$flujo['id']);
    else                                                           $autorizar = true;
    return [
        'revisar'   => $base !== true ? $base : ($flujo['revisado'] ? 'El proceso ya fue revisado.' : true),
        'autorizar' => $autorizar,
        'rechazar'  => $def['rechazado'] === null ? 'Este proceso no se rechaza desde el flujo.' : $base,
    ];
}

function registrarPasoFlujo(PDO $pdo, string $modulo, int $id, string $paso, ?string $comentario = null): void {
    // Aprobado gracias a una delegación: queda "en nombre de" quien delegó
    if ($comentario === null && in_array($paso, ['revisado', 'autorizado'], true)) {
        $rolCreador = rolCreador($pdo, PROCESOS[$modulo]['tabla'], $id);
        if (!apruebaConPuestoPropio($rolCreador) && ($d = delegacionQueHabilita($rolCreador))) $comentario = 'En nombre de ' . $d['nombre'];
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

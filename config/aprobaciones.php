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

// ¿El puesto puede revisar y autorizar procesos?
function puedeAprobar(): bool {
    if (soloRegistrosPropios()) return false;
    return tieneAccesoTotal() || !empty($_SESSION['rol_aprueba']);
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
 */
function motivoJerarquia(?string $rolCreador): ?string {
    if (tieneAccesoTotal() || !$rolCreador || !isset(roles()[$rolCreador])) return null;
    if (esSuperiorDe($_SESSION['usuario_rol'] ?? '', $rolCreador)) return null;
    return 'Según el organigrama, solo un puesto superior a ' . roles()[$rolCreador]['nombre'] . ' puede aprobar o corregir este proceso.';
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
    $creador = $flujo['creado'] && (int)$flujo['creado']['usuario_id'] === $uid;
    $abierto = in_array($flujo['estado'], $def['abiertos'], true) && !$flujo['autorizado'] && !$flujo['rechazado'];

    $base = !puedeAprobar() ? 'Su puesto no puede revisar ni autorizar procesos.'
          : (!$abierto      ? 'El proceso ya no está pendiente.'
          : ($creador       ? 'No puede revisar, autorizar ni rechazar un proceso que usted creó.'
          : (motivoJerarquia(rolCreador($GLOBALS['pdo'], $def['tabla'], (int)$flujo['id'])) ?? true)));
    if ($base !== true)                                            $autorizar = $base;
    elseif (!$flujo['revisado'])                                   $autorizar = 'Primero debe ser revisado.';
    elseif ((int)$flujo['revisado']['usuario_id'] === $uid)        $autorizar = 'Quien revisó no puede autorizar el mismo proceso.';
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

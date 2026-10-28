<?php
// ── Notificaciones ─────────────────────────────────────────────────
// Avisos de los procesos con flujo de aprobación (config/aprobaciones.php):
//   - a quien le toca revisarlo o autorizarlo: que hay uno esperándolo;
//   - a quien lo creó y al empleado a cuyo nombre está: que fue revisado, autorizado,
//     rechazado (con el motivo) o corregido.
// Se guardan en la tabla notificaciones (campana de la barra superior → notificaciones.php) y
// se envían por correo, salvo que el usuario lo desactive en Mi Perfil. Los correos salen al
// terminar la petición, después de entregar la respuesta, para no hacer esperar al usuario.

// Nombre de un proceso en los avisos
const NOMBRES_PROCESO = [
    'compras_solicitud.php' => 'Solicitud de compra',
    'gastos_viajes.php'     => 'Anticipo',
    'gastos_solicitud.php'  => 'Liquidación / reembolso',
    'vacaciones.php'        => 'Solicitud de vacaciones',
    'permisos.php'          => 'Permiso',
    'compras_orden.php'     => 'Orden de compra',
    'compras_pago.php'      => 'Orden de pago',
];

// Lo pendiente de aprobar se calcula al consultar la campana; se reutiliza durante este tiempo
const PENDIENTES_CACHE = 60;

/** "Solicitud de compra SC26-0201" */
function nombreProceso(PDO $pdo, string $modulo, int $id): string {
    $numero = numeroDe($pdo, PROCESOS[$modulo]['tabla'], $id);
    return NOMBRES_PROCESO[$modulo] . ' ' . ($numero ?: "#$id");
}

/**
 * A quién le toca revisar o autorizar el proceso: los usuarios del puesto superior más cercano
 * (en la rama del organigrama de quien lo creó) que aprueba procesos; si ninguno tiene usuarios
 * activos, los Administradores. Nunca los de $excluir (quien lo creó, quien ya lo revisó).
 */
function aprobadoresProceso(PDO $pdo, string $modulo, int $id, array $excluir, int $saltar = 0): array {
    return conDelegados($pdo, nivelesAprobadores($pdo, $modulo, $id, $excluir)[$saltar] ?? [], $excluir);
}

/**
 * Niveles del organigrama que pueden aprobar el proceso, del más cercano a quien lo creó hacia
 * arriba: [[ids del nivel 1], [ids del nivel 2], …], terminando con los Administradores. Solo
 * cuentan los puestos que aprueban procesos y tienen usuarios activos (sin los de $excluir).
 * El nivel 0 recibe los avisos; los siguientes, el escalamiento (cron/recordatorios.php).
 */
function nivelesAprobadores(PDO $pdo, string $modulo, int $id, array $excluir): array {
    $roles = roles();
    $s = $pdo->prepare("SELECT id FROM usuarios WHERE rol = ? AND activo = 1");
    $usuarios = function (string $rol) use ($s, $excluir): array {
        $s->execute([$rol]);
        return array_values(array_diff(array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)), $excluir));
    };
    $niveles = [];
    $rol = rolCreador($pdo, PROCESOS[$modulo]['tabla'], $id);
    for ($p = $rol ? ($roles[$rol]['depende_de'] ?? null) : null, $n = 0; $p !== null && $p !== 'admin' && $n < 50; $p = $roles[$p]['depende_de'] ?? null, $n++) {
        if (!empty($roles[$p]['aprueba']) && empty($roles[$p]['solo_propios']) && ($ids = $usuarios($p))) $niveles[] = $ids;
    }
    if ($admins = $usuarios('admin')) $niveles[] = $admins;
    return $niveles;
}

/** Agrega a quienes tienen hoy una delegación vigente de esos usuarios (también reciben los avisos). */
function conDelegados(PDO $pdo, array $ids, array $excluir = []): array {
    if (!$ids) return [];
    try {
        $s = $pdo->query("SELECT delegado_id FROM delegaciones WHERE cancelada = 0 AND CURDATE() BETWEEN desde AND hasta
                          AND usuario_id IN (" . implode(',', array_map('intval', $ids)) . ")");
        $ids = array_merge($ids, array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN)));
    } catch (PDOException $e) { /* base sin la tabla todavía */ }
    return array_values(array_diff(array_unique($ids), $excluir));
}

/**
 * Quién sigue el proceso: quien lo creó y el usuario del empleado a cuyo nombre está. En una
 * orden de pago generada por otro proceso, quien creó ese proceso y el empleado beneficiario.
 */
function interesadosProceso(PDO $pdo, string $modulo, int $id): array {
    $tabla = PROCESOS[$modulo]['tabla'];
    $registro = $id;
    $empleado = null;
    if ($tabla === 'ordenes_pago') {
        $s = $pdo->prepare("SELECT origen_tabla, origen_id, empleado_id FROM ordenes_pago WHERE id = ?");
        $s->execute([$id]);
        if ($op = $s->fetch()) {
            $empleado = $op['empleado_id'];
            if ($op['origen_tabla']) [$tabla, $registro] = [$op['origen_tabla'], (int)$op['origen_id']];
        }
    } elseif ($col = SOLICITUDES[$modulo]['empleado'] ?? null) {
        $s = $pdo->prepare("SELECT `$col` FROM `$tabla` WHERE id = ?");
        $s->execute([$id]);
        $empleado = $s->fetchColumn() ?: null;
    }
    $s = $pdo->prepare("SELECT usuario_id FROM registro_usuarios WHERE tabla = ? AND registro_id = ? AND participacion = 'creador'");
    $s->execute([$tabla, $registro]);
    $ids = [(int)$s->fetchColumn()];
    if ($empleado) {
        $s = $pdo->prepare("SELECT id FROM usuarios WHERE empleado_id = ? AND activo = 1");
        $s->execute([$empleado]);
        $ids[] = (int)$s->fetchColumn();
    }
    return $ids;
}

/**
 * Guarda el aviso para cada usuario (nunca para quien hizo la acción) y deja su correo listo
 * para enviarse al terminar la petición.
 */
function notificar(PDO $pdo, array $usuarios, string $tipo, string $titulo, string $mensaje, string $modulo, int $id): void {
    avisar($pdo, $usuarios, $tipo, $titulo, $mensaje, "$modulo?action=ver&id=$id", PROCESOS[$modulo]['tabla'], $id,
        $titulo . ' — ' . nombreProceso($pdo, $modulo, $id), 'Abrir el proceso');
}

/**
 * Aviso a usuarios (campana + correo) que lleva a $url. Para avisos de procesos use notificar();
 * este sirve también para otros (delegaciones, recordatorios). Nunca a quien hace la acción.
 */
function avisar(PDO $pdo, array $usuarios, string $tipo, string $titulo, string $mensaje, string $url, ?string $tabla, ?int $id,
                string $asunto, string $boton = 'Abrir en Proscala'): void {
    $usuarios = array_values(array_diff(array_unique(array_map('intval', $usuarios)), [0, (int)($_SESSION['usuario_id'] ?? 0)]));
    if (!$usuarios) return;
    $ins = $pdo->prepare("INSERT INTO notificaciones (usuario_id, tipo, titulo, mensaje, url, tabla, registro_id) VALUES (?,?,?,?,?,?,?)");
    foreach ($usuarios as $u) $ins->execute([$u, $tipo, $titulo, mb_substr($mensaje, 0, 2000), $url, $tabla, $id]);

    require_once __DIR__ . '/correo.php';
    $h = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $empresa = $_SESSION['empresa_nombre'] ?? '';
    // El enlace pasa por el inicio de sesión: si no hay sesión abierta, al entrar abre la página
    $enlace = urlAbsoluta('login.php?' . http_build_query(['ir' => $url, 'e' => (int)($_SESSION['empresa_id'] ?? 0)]));
    $html = plantillaCorreo($titulo, '<p style="margin:0 0 12px">' . nl2br($h($mensaje)) . '</p>'
        . ($empresa ? '<p style="margin:0;font-size:12px;color:#8ea3b4">Empresa: ' . $h($empresa) . '</p>' : ''), $boton, $enlace);
    $asunto = 'Proscala: ' . $asunto;
    $s = $pdo->query("SELECT email FROM usuarios WHERE id IN (" . implode(',', $usuarios) . ") AND activo = 1 AND notificar_correo = 1 AND email <> ''");
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $email) $GLOBALS['_correos_notificacion'][] = [$email, $asunto, $html];
    if (empty($GLOBALS['_correos_programados'])) {
        $GLOBALS['_correos_programados'] = true;
        register_shutdown_function('enviarCorreosNotificacion');
    }
}

/** Al final de la petición: entrega la respuesta al navegador y luego envía los correos. */
function enviarCorreosNotificacion(): void {
    $correos = $GLOBALS['_correos_notificacion'] ?? [];
    if (!$correos) return;
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    foreach ($correos as [$para, $asunto, $html]) enviarCorreo($para, $asunto, $html);
    $GLOBALS['_correos_notificacion'] = [];   // en el cron se envían por empresa: no repetir
}

/** Avisa a quien le toca el siguiente paso ('revisar' o 'autorizar') del proceso. */
function avisarPendiente(PDO $pdo, string $modulo, int $id, string $paso): void {
    $flujo = estadoFlujo($pdo, $modulo, $id);
    if (!$flujo) return;
    $excluir = [(int)($flujo['creado']['usuario_id'] ?? 0)];
    if ($paso === 'autorizar') $excluir[] = (int)($flujo['revisado']['usuario_id'] ?? 0);
    $proceso = nombreProceso($pdo, $modulo, $id) . ($flujo['creado'] ? ' de ' . $flujo['creado']['nombre'] : '');
    notificar($pdo, aprobadoresProceso($pdo, $modulo, $id, $excluir), 'pendiente',
        $paso === 'revisar' ? 'Pendiente de su revisión' : 'Pendiente de su autorización',
        $paso === 'revisar' ? "$proceso espera su revisión." : "$proceso ya fue revisado y espera su autorización.",
        $modulo, $id);
}

/** Al terminar la petición (registrarPeticion), con la acción ya aplicada: avisos del flujo. */
function notificarFlujo(PDO $pdo, string $modulo, string $act, int $id): void {
    if (!$id || !isset(PROCESOS[$modulo])) return;
    unset($_SESSION['_pendientes']);   // lo pendiente de aprobar pudo cambiar
    $def     = PROCESOS[$modulo];
    $estado  = $_POST['estado'] ?? '';
    $quien   = $_SESSION['usuario_nombre'] ?? 'Un usuario';
    $guardar = in_array($act, ['guardar', 'crear'], true);

    if ($guardar && !(int)($_POST['id'] ?? 0)) {
        avisarPendiente($pdo, $modulo, $id, 'revisar');
    } elseif ($guardar && !empty($GLOBALS['_edicion_sin_revisar'])) {
        // Editado antes de cualquier revisión (config/edicion.php): sigue pendiente igual, sin avisos nuevos
    } elseif ($guardar) {
        notificar($pdo, interesadosProceso($pdo, $modulo, $id), 'corregido', 'Proceso corregido',
            "$quien corrigió " . nombreProceso($pdo, $modulo, $id) . '. Vuelve a revisión.', $modulo, $id);
        avisarPendiente($pdo, $modulo, $id, 'revisar');
    } elseif ($act === 'revisar') {
        notificar($pdo, interesadosProceso($pdo, $modulo, $id), 'revisado', 'Proceso revisado',
            "$quien revisó " . nombreProceso($pdo, $modulo, $id) . '. Queda pendiente de autorización.', $modulo, $id);
        avisarPendiente($pdo, $modulo, $id, 'autorizar');
    } elseif ($act === 'autorizar' || ($act === 'cambiar_estado' && $estado === $def['autorizado'])) {
        notificar($pdo, interesadosProceso($pdo, $modulo, $id), 'autorizado', 'Proceso autorizado',
            "$quien autorizó " . nombreProceso($pdo, $modulo, $id) . '.', $modulo, $id);
    } elseif ($act === 'cambiar_estado' && $def['rechazado'] !== null && $estado === $def['rechazado']) {
        notificar($pdo, interesadosProceso($pdo, $modulo, $id), 'rechazado', 'Proceso rechazado',
            "$quien rechazó " . nombreProceso($pdo, $modulo, $id) . ".\nMotivo: " . comentarioRechazo(), $modulo, $id);
    }
}

/**
 * Recordatorios y escalamiento de procesos pendientes (cron/recordatorios.php, una vez al día).
 * Un paso (revisar o autorizar) que lleva 'recordatorio_dias' esperando se recuerda a quien le toca
 * (nivel 0); a los 'escalamiento_dias' se avisa al siguiente nivel del organigrama, y cada vez que
 * vuelven a pasar esos días, al siguiente (nivel 1, 2…). Cada aviso se envía una vez por paso
 * (tabla recordatorios); si el proceso se corrige y el paso vuelve a empezar, se cuenta de nuevo.
 * Con $enviar = false solo devuelve lo que se enviaría. Devuelve la lista de avisos.
 */
function recordarPendientes(PDO $pdo, bool $enviar = true): array {
    $diasRec = max(1, (int)getConfig($pdo, 'recordatorio_dias', '2'));
    $diasEsc = max($diasRec + 1, (int)getConfig($pdo, 'escalamiento_dias', '5'));
    $ya = $pdo->prepare("SELECT 1 FROM recordatorios WHERE tabla = ? AND registro_id = ? AND paso = ? AND inicio = ? AND nivel = ?");
    $anotar = $pdo->prepare("INSERT IGNORE INTO recordatorios (tabla, registro_id, paso, inicio, nivel) VALUES (?,?,?,?,?)");
    $avisos = [];
    foreach (PROCESOS as $modulo => $def) {
        $marcas = implode(',', array_fill(0, count($def['abiertos']), '?'));
        // Los procesos históricos (anteriores al corte del sistema) no generan recordatorios
        $s = $pdo->prepare("SELECT id, created_at FROM `{$def['tabla']}` WHERE estado IN ($marcas) AND historico = 0");
        $s->execute($def['abiertos']);
        foreach ($s->fetchAll() as $r) {
            $flujo = estadoFlujo($pdo, $modulo, (int)$r['id']);
            if (!$flujo || $flujo['autorizado'] || $flujo['rechazado']) continue;
            $paso   = $flujo['revisado'] ? 'autorizar' : 'revisar';
            $inicio = date('Y-m-d H:i:s', strtotime($flujo['revisado']['fecha'] ?? $flujo['creado']['fecha'] ?? $r['created_at']));
            $dias   = (int)floor((time() - strtotime($inicio)) / 86400);
            if ($dias < $diasRec) continue;
            // Nivel que corresponde hoy: 0 = recordatorio; 1, 2… = escalamiento cada $diasEsc días
            $nivel = $dias >= $diasEsc ? (int)floor($dias / $diasEsc) : 0;
            $excluir = array_filter([(int)($flujo['creado']['usuario_id'] ?? 0), $paso === 'autorizar' ? (int)($flujo['revisado']['usuario_id'] ?? 0) : 0]);
            $niveles = nivelesAprobadores($pdo, $modulo, (int)$r['id'], $excluir);
            $nivel = min($nivel, count($niveles) - 1);
            if ($nivel < 0) continue;   // nadie que pueda aprobarlo
            $ya->execute([$def['tabla'], $r['id'], $paso, $inicio, $nivel]);
            if ($ya->fetchColumn()) continue;

            $proceso = nombreProceso($pdo, $modulo, (int)$r['id']) . ($flujo['creado'] ? ' de ' . $flujo['creado']['nombre'] : '');
            $accion  = $paso === 'revisar' ? 'revisión' : 'autorización';
            if ($nivel === 0) {
                $tipo = 'recordatorio';
                $titulo = 'Recordatorio: pendiente de su ' . $accion;
                $mensaje = "$proceso lleva $dias días esperando su $accion.";
            } else {
                $tipo = 'escalado';
                $titulo = "Proceso detenido: $dias días sin $accion";
                $s2 = $pdo->query("SELECT nombre FROM usuarios WHERE id IN (" . implode(',', $niveles[0]) . ")");
                $mensaje = "$proceso lleva $dias días esperando la $accion de " . implode(', ', $s2->fetchAll(PDO::FETCH_COLUMN))
                         . '. Como superior en el organigrama, usted puede atenderlo o pedir que lo resuelvan.';
            }
            $destinos = conDelegados($pdo, $niveles[$nivel], $excluir);
            $avisos[] = ['proceso' => $proceso, 'paso' => $paso, 'dias' => $dias, 'nivel' => $nivel, 'usuarios' => $destinos];
            if (!$enviar) continue;
            avisar($pdo, $destinos, $tipo, $titulo, $mensaje, "$modulo?action=ver&id={$r['id']}", $def['tabla'], (int)$r['id'],
                $titulo . ' — ' . nombreProceso($pdo, $modulo, (int)$r['id']), 'Abrir el proceso');
            $anotar->execute([$def['tabla'], $r['id'], $paso, $inicio, $nivel]);
        }
    }
    return $avisos;
}

/** Al abrir un proceso: sus avisos para el usuario quedan leídos. */
function marcarNotificacionesLeidas(PDO $pdo, string $tabla, int $id): void {
    $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE usuario_id = ? AND tabla = ? AND registro_id = ? AND leida = 0")
        ->execute([(int)$_SESSION['usuario_id'], $tabla, $id]);
}

/**
 * Procesos que el usuario puede revisar o autorizar ahora (las mismas reglas que el panel del
 * flujo): ['total' => n, 'items' => [...]] con los más recientes primero.
 */
function pendientesDeAprobar(PDO $pdo, int $limite = 15): array {
    $cache = $_SESSION['_pendientes'] ?? null;
    if ($cache && time() - $cache['t'] < PENDIENTES_CACHE) return $cache['datos'];
    $items = [];
    if (puedeAprobar()) {
        foreach (PROCESOS as $modulo => $def) {
            if (!puedeAcceder($modulo)) continue;
            $marcas = implode(',', array_fill(0, count($def['abiertos']), '?'));
            $s = $pdo->prepare("SELECT id, numero, created_at FROM `{$def['tabla']}` WHERE estado IN ($marcas) ORDER BY id DESC LIMIT 100");
            $s->execute($def['abiertos']);
            foreach ($s->fetchAll() as $r) {
                $flujo = estadoFlujo($pdo, $modulo, (int)$r['id']);
                $acc   = accionesFlujo($flujo, $modulo);
                $paso  = $acc['revisar'] === true ? 'revisar' : ($acc['autorizar'] === true ? 'autorizar' : null);
                if (!$paso) continue;
                $items[] = ['paso' => $paso, 'proceso' => NOMBRES_PROCESO[$modulo], 'numero' => $r['numero'],
                            'de' => $flujo['creado']['nombre'] ?? '', 'fecha' => $r['created_at'],
                            'url' => "$modulo?action=ver&id={$r['id']}"];
            }
        }
        usort($items, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    }
    $datos = ['total' => count($items), 'items' => array_slice($items, 0, $limite)];
    $_SESSION['_pendientes'] = ['t' => time(), 'datos' => $datos];
    return $datos;
}

/** Contenido de la campana: lo pendiente de aprobar y los últimos avisos del usuario. */
function resumenNotificaciones(PDO $pdo): array {
    $uid = (int)$_SESSION['usuario_id'];
    $s = $pdo->prepare("SELECT id, tipo, titulo, mensaje, url, leida, created_at AS fecha FROM notificaciones
                        WHERE usuario_id = ? ORDER BY leida, id DESC LIMIT 20");
    $s->execute([$uid]);
    $avisos = $s->fetchAll();
    foreach ($avisos as &$a) { $a['id'] = (int)$a['id']; $a['leida'] = (bool)$a['leida']; }
    $s = $pdo->prepare("SELECT COUNT(*) FROM notificaciones WHERE usuario_id = ? AND leida = 0");
    $s->execute([$uid]);
    return ['pendientes' => pendientesDeAprobar($pdo), 'avisos' => $avisos, 'no_leidas' => (int)$s->fetchColumn()];
}

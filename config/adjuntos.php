<?php
// ── Documentos adjuntos de los procesos ─────────────────────────────
// Facturas, recibos, fotos tomadas con el celular… de cualquier proceso de EDICION_PROCESOS.
// Los sube quien puede ver el proceso (también quien lo creó, aun ya pagado); los archivos
// viven en ADJUNTOS_DIR/<empresa>/<tabla>/<id>/, carpeta cerrada a la web: solo se sirven
// por adjuntos.php, que valida el acceso al proceso.
const ADJUNTOS_DIR    = 'assets/uploads/adjuntos/';
const ADJUNTOS_MAX_MB = 10;
const ADJUNTOS_TIPOS  = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
    'image/heic' => 'heic', 'image/heif' => 'heif',
    'application/pdf' => 'pdf', 'text/xml' => 'xml', 'application/xml' => 'xml',
    'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
];
// Lo que acepta el selector de archivos (el servidor vuelve a validar el contenido)
const ADJUNTOS_ACCEPT = 'image/*,application/pdf,.pdf,.xml,.doc,.docx,.xls,.xlsx,.heic,.heif';

/** Extensión segura del archivo subido según su contenido, o null si no se permite. */
function tipoAdjunto(string $tmp, string $nombre): ?array {
    $mime = mime_content_type($tmp) ?: '';
    if (isset(ADJUNTOS_TIPOS[$mime])) return [$mime, ADJUNTOS_TIPOS[$mime]];
    // Office moderno se detecta a veces como zip, y HEIC (iPhone) como binario genérico
    $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    $porExt = ['docx' => ['application/zip'], 'xlsx' => ['application/zip'],
               'heic' => ['application/octet-stream'], 'heif' => ['application/octet-stream']];
    if (in_array($mime, $porExt[$ext] ?? [], true)) return [array_search($ext, ADJUNTOS_TIPOS, true), $ext];
    return null;
}

/** Archivos recibidos en un campo múltiple (name="campo[]"), como lista de [nombre, tmp, tamano, error]. */
function archivosSubidos(string $campo): array {
    $f = $_FILES[$campo] ?? null;
    if (!$f || !is_array($f['name'])) return [];
    $lista = [];
    foreach ($f['name'] as $i => $nombre) {
        if ($nombre === '' && $f['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
        $lista[] = ['nombre' => trim(basename(str_replace('\\', '/', (string)$nombre))) ?: 'documento',
                    'tmp' => $f['tmp_name'][$i], 'tamano' => (int)$f['size'][$i], 'error' => (int)$f['error'][$i]];
    }
    return $lista;
}

/** ¿Se puede guardar este archivo? null si sí; si no, el motivo. Devuelve también [mime, ext] en $tipo. */
function validarAdjunto(array $a, ?array &$tipo = null): ?string {
    if (in_array($a['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || $a['tamano'] > adjuntosMaxMb() * 1048576) {
        return 'supera ' . adjuntosMaxMb() . ' MB';
    }
    if ($a['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($a['tmp'])) return 'no se recibió completo';
    $tipo = tipoAdjunto($a['tmp'], $a['nombre']);
    return $tipo ? null : 'tipo de archivo no permitido';
}

/**
 * Guarda un archivo subido como adjunto del proceso ($rechazoId: evidencia de un rechazo).
 * true o el motivo por el que no se guardó.
 */
function guardarAdjunto(PDO $pdo, string $tabla, int $id, array $a, ?int $rechazoId = null) {
    if ($motivo = validarAdjunto($a, $tipo)) return $motivo;
    [$mime, $ext] = $tipo;
    $archivo = "$tabla/$id/" . bin2hex(random_bytes(10)) . '.' . $ext;
    $destino = rutaAdjunto(['archivo' => $archivo]);
    if (!is_dir(dirname($destino)) && !@mkdir(dirname($destino), 0755, true)) return 'no se pudo crear la carpeta en el servidor';
    if (!move_uploaded_file($a['tmp'], $destino)) return 'no se pudo guardar';
    $pdo->prepare("INSERT INTO adjuntos (tabla, registro_id, rechazo_id, nombre, archivo, mime, tamano, usuario_id) VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$tabla, $id, $rechazoId, mb_substr($a['nombre'], 0, 255), $archivo, $mime, $a['tamano'], $_SESSION['usuario_id']]);
    return true;
}

/** Módulo (EDICION_PROCESOS) al que pertenece una tabla de proceso. */
function moduloDeTabla(string $tabla): ?string {
    foreach (EDICION_PROCESOS as $modulo => $def) if ($def['tabla'] === $tabla) return $modulo;
    return null;
}

/** ¿Puede el usuario ver (y adjuntar a) este proceso? */
function puedeVerProceso(PDO $pdo, string $modulo, int $id): bool {
    if (!isset(EDICION_PROCESOS[$modulo]) || !$id || !puedeAcceder($modulo)) return false;
    $s = $pdo->prepare("SELECT 1 FROM `" . EDICION_PROCESOS[$modulo]['tabla'] . "` WHERE id = ?");
    $s->execute([$id]);
    return $s->fetchColumn() && esRegistroPropio($pdo, $modulo, $id);
}

/** Quitar un adjunto: quien lo subió mientras el proceso siga abierto; el Administrador siempre. */
function puedeQuitarAdjunto(PDO $pdo, array $a, string $modulo) {
    if (!empty($a['rechazo_id'])) return 'La evidencia de un rechazo forma parte de su constancia y no se puede quitar.';
    if (tieneAccesoTotal()) return true;
    if ((int)$a['usuario_id'] !== (int)($_SESSION['usuario_id'] ?? 0)) return 'Solo quien subió el documento (o el Administrador) puede quitarlo.';
    return motivoCierre($pdo, $modulo, (int)$a['registro_id']) === null ? true
        : 'El proceso ya está cerrado: sus documentos solo los puede quitar el Administrador.';
}

/** Tamaño máximo por archivo (MB): ADJUNTOS_MAX_MB, o menos si el servidor PHP admite menos. */
function adjuntosMaxMb(): float {
    $mb = function (string $v): float {
        $n = (float)$v;
        return match (strtoupper(substr(trim($v), -1))) { 'G' => $n * 1024, 'K' => $n / 1024, 'M' => $n, default => $n / 1048576 };
    };
    return min(ADJUNTOS_MAX_MB, $mb(ini_get('upload_max_filesize')), $mb(ini_get('post_max_size')));
}

function rutaAdjunto(array $a): string {
    return dirname(__DIR__) . '/' . ADJUNTOS_DIR . (int)$_SESSION['empresa_id'] . '/' . $a['archivo'];
}

/** Documentos del proceso (sin las evidencias de rechazo, salvo $conEvidencias). */
function adjuntosDe(PDO $pdo, string $tabla, int $id, bool $conEvidencias = false): array {
    $s = $pdo->prepare("SELECT a.*, u.nombre AS usuario FROM adjuntos a LEFT JOIN usuarios u ON u.id = a.usuario_id
                        WHERE a.tabla = ? AND a.registro_id = ?" . ($conEvidencias ? '' : ' AND a.rechazo_id IS NULL') . " ORDER BY a.id");
    $s->execute([$tabla, $id]);
    return $s->fetchAll();
}

/** Al eliminar un proceso: sus adjuntos y evidencias (registros y archivos) y su historial de rechazos. */
function eliminarAdjuntosDe(PDO $pdo, string $tabla, int $id): void {
    foreach (adjuntosDe($pdo, $tabla, $id, true) as $a) @unlink(rutaAdjunto($a));
    @rmdir(dirname(__DIR__) . '/' . ADJUNTOS_DIR . (int)$_SESSION['empresa_id'] . "/$tabla/$id");
    $pdo->prepare("DELETE FROM adjuntos WHERE tabla = ? AND registro_id = ?")->execute([$tabla, $id]);
    $pdo->prepare("DELETE FROM rechazos WHERE tabla = ? AND registro_id = ?")->execute([$tabla, $id]);
}

function tamanoLegible(int $bytes): string {
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : max(1, round($bytes / 1024)) . ' KB';
}

/** Lista de adjuntos de un proceso (includes/adjuntos.php y adjuntos.php?action=lista). */
function htmlAdjuntos(PDO $pdo, string $modulo, int $id): string {
    $lista = adjuntosDe($pdo, EDICION_PROCESOS[$modulo]['tabla'], $id);
    if (!$lista) return '<div class="text-muted small py-2"><i class="fas fa-paperclip"></i> Aún no hay documentos adjuntos.</div>';
    $html = '<div class="adjuntos-lista">';
    foreach ($lista as $a) $html .= htmlAdjunto($a, puedeQuitarAdjunto($pdo, $a, $modulo) === true);
    return $html . '</div>';
}

/** Tarjeta de un adjunto: vista previa (imágenes) o ícono, nombre, datos y descarga. */
function htmlAdjunto(array $a, bool $quitar = false): string {
    $url  = BASE_URL . 'adjuntos.php?action=ver&id=' . (int)$a['id'];
    $nom  = htmlspecialchars($a['nombre']);
    $esImagen = in_array($a['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    $icono = $a['mime'] === 'application/pdf' ? 'fa-file-pdf text-danger'
        : (str_contains($a['mime'], 'sheet') || str_contains($a['mime'], 'excel') ? 'fa-file-excel text-success'
        : (str_contains($a['mime'], 'word') ? 'fa-file-word text-primary'
        : (str_starts_with($a['mime'], 'image/') ? 'fa-file-image text-info' : 'fa-file-code text-secondary')));
    $vista = $esImagen
        ? '<img src="' . $url . '" alt="' . $nom . '" loading="lazy">'
        : '<i class="fas ' . $icono . '"></i>';
    $btnQuitar = $quitar
        ? '<button type="button" class="btn btn-sm btn-link text-danger p-0 ms-auto" title="Quitar" onclick="Adjuntos.quitar(this, ' . (int)$a['id'] . ')"><i class="fas fa-trash"></i></button>'
        : '';
    return '<div class="adjunto">'
        . '<a class="adjunto-vista" href="' . $url . '" target="_blank" rel="noopener" title="' . $nom . '">' . $vista . '</a>'
        . '<div class="adjunto-info">'
        . '<a href="' . $url . '" target="_blank" rel="noopener" class="adjunto-nombre">' . $nom . '</a>'
        . '<div class="text-muted">' . tamanoLegible((int)$a['tamano']) . ' · ' . htmlspecialchars($a['usuario'] ?? '—')
        . ' · ' . date('d/m/Y H:i', strtotime($a['created_at'])) . '</div>'
        . '</div>'
        . '<div class="d-flex gap-2 align-items-start">'
        . '<a href="' . $url . '&descargar=1" class="btn btn-sm btn-link p-0" title="Descargar"><i class="fas fa-download"></i></a>'
        . $btnQuitar . '</div>'
        . '</div>';
}

/** Historial de rechazos de un proceso, el más reciente primero, cada uno con su evidencia. */
function rechazosDe(PDO $pdo, string $tabla, int $id): array {
    $nombre = "COALESCE(CONCAT(e.nombre,' ',e.apellidos), u.nombre)";
    $s = $pdo->prepare("SELECT r.*, $nombre AS nombre FROM rechazos r JOIN usuarios u ON u.id = r.usuario_id
        LEFT JOIN empleados e ON e.id = u.empleado_id WHERE r.tabla = ? AND r.registro_id = ? ORDER BY r.created_at DESC, r.id DESC");
    $s->execute([$tabla, $id]);
    $rechazos = $s->fetchAll();
    if ($rechazos) {
        $s = $pdo->prepare("SELECT a.*, u.nombre AS usuario FROM adjuntos a LEFT JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.rechazo_id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $rechazos)) . ") ORDER BY a.id");
        $s->execute();
        $ev = [];
        foreach ($s->fetchAll() as $a) $ev[$a['rechazo_id']][] = $a;
        foreach ($rechazos as &$r) $r['evidencias'] = $ev[$r['id']] ?? [];
        unset($r);
    }
    return $rechazos;
}

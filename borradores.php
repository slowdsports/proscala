<?php
// Borradores de los formularios (assets/js/proscala.js → Borradores). Lo que un usuario va llenando se
// guarda solo, unos segundos después de cada cambio, para que no se pierda si cierra la página, se va la
// luz o vence la sesión. Al volver a abrir el formulario se rellena con lo que había escrito. Cada
// borrador es de un usuario y de un formulario (clave "pagina|formulario|registro") y se borra al
// guardar el formulario, al descartarlo o a los BORRADOR_DIAS sin tocarlo. No va a la bitácora.
// No es un módulo.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') define('SIN_ACTIVIDAD', true);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
header('Cache-Control: no-store');

const BORRADOR_MAX  = 512 * 1024;   // datos de un borrador
const BORRADOR_DIAS = 30;
const BORRADORES_POR_USUARIO = 50;

$uid = (int)$_SESSION['usuario_id'];
/** "compras_solicitud.php|form-sc|0" → la página, o null si la clave no es válida. */
$paginaDe = function (string $clave): ?string {
    return preg_match('/^([a-z0-9_]+\.php)\|[A-Za-z0-9_\-]{1,60}\|[A-Za-z0-9_\-]{1,40}$/', $clave, $m) ? $m[1] : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $GLOBALS['_bitacora_explicita'] = true;   // el autoguardado no va a la bitácora
    $clave  = (string)($_POST['clave'] ?? '');
    $pagina = $paginaDe($clave);
    if (!$pagina) jsonErr('Borrador no válido.');
    $act = $_POST['_action'] ?? '';

    if ($act === 'guardar') {
        $url = (string)($_POST['url'] ?? '');
        // Solo una página del sistema (relativa), para que el enlace de Mis solicitudes no lleve fuera
        if (!preg_match('/^[a-z0-9_]+\.php(\?[^\s#]*)?$/', $url) || !str_starts_with($url, $pagina)) $url = $pagina;
        $datos = (string)($_POST['datos'] ?? '');
        if (strlen($datos) > BORRADOR_MAX) jsonErr('El borrador es demasiado grande para guardarse.');
        if (!is_array(json_decode($datos, true))) jsonErr('Borrador no válido.');
        $titulo = trim(preg_replace('/\s+/u', ' ', (string)($_POST['titulo'] ?? '')));
        $titulo = mb_substr($titulo !== '' && mb_check_encoding($titulo, 'UTF-8') ? $titulo : $pagina, 0, 200);
        $pdo->prepare("INSERT INTO borradores (usuario_id, clave, pagina, url, titulo, modal, datos) VALUES (?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE url = VALUES(url), titulo = VALUES(titulo), modal = VALUES(modal), datos = VALUES(datos), updated_at = CURRENT_TIMESTAMP")
            ->execute([$uid, $clave, $pagina, $url, $titulo, !empty($_POST['modal']) ? 1 : 0, $datos]);
        // Tope por usuario: se van los más viejos
        $pdo->prepare("DELETE FROM borradores WHERE usuario_id = ? AND id NOT IN
                       (SELECT id FROM (SELECT id FROM borradores WHERE usuario_id = ? ORDER BY updated_at DESC LIMIT " . BORRADORES_POR_USUARIO . ") x)")
            ->execute([$uid, $uid]);
        jsonOk(['guardado' => date('c')], 'Borrador guardado.');
    }
    if ($act === 'descartar') {
        $pdo->prepare("DELETE FROM borradores WHERE usuario_id = ? AND clave = ?")->execute([$uid, $clave]);
        jsonOk([], 'Borrador descartado.');
    }
    jsonErr('Acción desconocida.');
}

// De vez en cuando se borran los que nadie tocó en BORRADOR_DIAS días
if (random_int(1, 50) === 1) $pdo->exec("DELETE FROM borradores WHERE updated_at < NOW() - INTERVAL " . BORRADOR_DIAS . " DAY");

// Borradores del usuario en una página (para rellenar sus formularios)
$pagina = (string)($_GET['pagina'] ?? '');
if (!preg_match('/^[a-z0-9_]+\.php$/', $pagina)) jsonErr('Página no válida.');
$s = $pdo->prepare("SELECT clave, url, titulo, modal, datos, updated_at FROM borradores
                    WHERE usuario_id = ? AND pagina = ? AND updated_at >= NOW() - INTERVAL " . BORRADOR_DIAS . " DAY");
$s->execute([$uid, $pagina]);
$lista = [];
foreach ($s->fetchAll() as $b) {
    $lista[] = ['clave' => $b['clave'], 'url' => $b['url'], 'titulo' => $b['titulo'], 'modal' => (bool)$b['modal'],
                'actualizado' => date('c', strtotime($b['updated_at'])), 'datos' => json_decode($b['datos'], true)];
}
jsonOk(['borradores' => $lista]);

<?php
if (!defined('BASE_URL')) {
    $docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
    $appRoot  = rtrim(str_replace('\\', '/', realpath(dirname(__DIR__))), '/');
    $relative = (strpos($appRoot, $docRoot) === 0) ? substr($appRoot, strlen($docRoot)) : '';
    define('BASE_URL', ($relative ?: '') . '/');
}
$pagina  = $pagina ?? 'Dashboard';
$csrf    = csrfToken();

// Hydrate foto from DB once per session (handles sessions started before foto column existed)
if (!isset($_SESSION['usuario_foto']) && isset($_SESSION['usuario_id'])) {
    $__s = $pdo->prepare("SELECT foto FROM usuarios WHERE id=?");
    $__s->execute([$_SESSION['usuario_id']]);
    $_SESSION['usuario_foto'] = $__s->fetchColumn() ?: '';
}
$user = currentUser();

$_avatarFoto = $user['foto'] ? BASE_URL . $user['foto'] : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf" content="<?= $csrf ?>">
  <title><?= htmlspecialchars($pagina) ?> | Proscala Admin</title>
  <script>
    // Aplica el tema antes de que el navegador pinte para evitar el flash. data-bs-theme hace que
    // los componentes de Bootstrap (alertas, pestañas, tablas de color…) usen también su paleta
    (function(){
      var t = 'light';
      try { t = localStorage.getItem('proscala-theme') || 'light'; } catch (e) {}
      document.documentElement.setAttribute('data-theme', t);
      document.documentElement.setAttribute('data-bs-theme', t);
    })();
  </script>
  <?php if (!empty($_SESSION['rtn_recordar'])): ?>
  <script>
    // Recién iniciada la sesión: guarda el RTN para precargarlo en el login (máx. 10, el más reciente primero)
    (function(n){
      try {
        var l = JSON.parse(localStorage.getItem('proscala-rtns') || '[]').filter(function(r){ return r.rtn !== n.rtn; });
        l.unshift(n);
        localStorage.setItem('proscala-rtns', JSON.stringify(l.slice(0, 10)));
      } catch (e) {}
    })(<?= json_encode($_SESSION['rtn_recordar'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>);
  </script>
  <?php unset($_SESSION['rtn_recordar']); endif; ?>
  <?php // Librerías servidas desde el propio sistema (assets/vendor), sin depender de CDN externos ?>
  <link rel="stylesheet" href="<?= recurso('assets/vendor/fontawesome/css/all.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/vendor/datatables/dataTables.bootstrap5.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/vendor/bootstrap/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/vendor/flatpickr/flatpickr.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/vendor/select2/select2.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/vendor/select2/select2-bootstrap-5-theme.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/css/proscala.css') ?>">
</head>
<body>

<!-- TOPBAR -->
<nav id="topbar">
  <button class="btn-hamburger" id="btn-toggle-sidebar" title="Menú">
    <i class="fas fa-bars"></i>
  </button>
  <div class="brand">
    <img src="<?= BASE_URL ?>assets/images/proscala.png" alt="Proscala">
  </div>
  <span class="topbar-title">Sistema de Administración Empresarial</span>
  <span class="topbar-empresa" title="Empresa"><i class="fas fa-building"></i> <?= htmlspecialchars($_SESSION['empresa_nombre'] ?? '') ?></span>
  <div class="topbar-right">
    <a href="<?= BASE_URL ?>perfil.php" class="topbar-user d-none d-md-flex" style="text-decoration:none;color:inherit">
      <div class="avatar" style="background:none;padding:0;overflow:hidden;flex-shrink:0">
        <img src="<?= $_avatarFoto ?: BASE_URL . 'assets/images/proscala.png' ?>"
             alt=""
             style="width:27px;height:27px;object-fit:cover;border-radius:50%;display:block;">
      </div>
      <span><?= htmlspecialchars($user['nombre']) ?></span>
      <span class="topbar-role"><?= htmlspecialchars(esSuperadmin() ? 'Super Administrador' : rolLabel($user['rol'])) ?></span>
    </a>
    <?php // Notificaciones: lo pendiente de aprobar y los avisos del usuario (proscala.js → Notificaciones) ?>
    <div class="notif" id="notif">
      <button type="button" id="btn-notif" title="Notificaciones" aria-label="Notificaciones" aria-haspopup="true" aria-expanded="false">
        <i class="fas fa-bell"></i><span class="notif-badge" id="notif-badge" hidden></span>
      </button>
      <div class="notif-panel" id="notif-panel" role="dialog" aria-label="Notificaciones" hidden>
        <div class="notif-cab">
          <strong>Notificaciones</strong>
          <button type="button" class="notif-leer-todas" id="notif-leer-todas" hidden>Marcar todo como leído</button>
        </div>
        <div class="notif-cuerpo" id="notif-cuerpo"><div class="notif-vacio">Cargando…</div></div>
      </div>
    </div>
    <button id="btn-theme" onclick="toggleTheme()" title="Cambiar tema">
      <i class="fas fa-moon" id="theme-icon"></i>
    </button>
    <a href="<?= BASE_URL ?>logout.php" class="btn-logout" title="Cerrar sesión">
      <i class="fas fa-sign-out-alt"></i> <span class="d-none d-md-inline">Salir</span>
    </a>
  </div>
</nav>

<?php include __DIR__ . '/sidebar.php'; ?>

<!-- Fuera de <main>: la navegación sin recarga solo reemplaza el contenido de <main> -->
<div id="toast-container"></div>
<?php
// Respaldo semanal del super administrador (respaldo.php): se descarga en un marco oculto, fuera
// de <main> para que siga aunque se navegue a otra página. Se pide una sola vez por sesión.
$_respaldoSemanal = !empty($_SESSION['respaldo_semanal']) && esSuperadmin();
unset($_SESSION['respaldo_semanal']);
if ($_respaldoSemanal): ?>
<iframe src="<?= BASE_URL ?>respaldo.php?t=<?= urlencode($csrf) ?>" title="Respaldo semanal" hidden></iframe>
<?php endif; ?>

<!-- MAIN CONTENT -->
<main id="main-content">
<?php if ($_respaldoSemanal): ?>
  <div class="alert alert-info py-2 no-print d-flex gap-2 align-items-start" style="font-size:.85rem;">
    <i class="fas fa-box-archive mt-1"></i>
    <div><strong>Respaldo semanal del sistema.</strong> Se está preparando y se descargará solo en unos momentos
      (bases de datos y archivos subidos de todas las empresas). Guárdelo en un lugar seguro fuera del servidor.
      El próximo será dentro de <?= DIAS_RESPALDO_SEMANAL ?> días.</div>
  </div>
<?php endif; ?>
<?php if (!empty($_SESSION['aviso'])): ?>
  <div class="alert alert-warning py-2 no-print" style="font-size:.85rem;"><i class="fas fa-triangle-exclamation"></i> <?= htmlspecialchars($_SESSION['aviso']) ?></div>
<?php unset($_SESSION['aviso']); endif; ?>
<?php if (!empty($GLOBALS['proceso_historico'])): ?>
  <div class="alert alert-secondary py-2 no-print" style="font-size:.85rem;">
    <i class="fas fa-box-archive"></i> <strong>Proceso histórico</strong> (anterior al corte del sistema):
    se puede consultar e imprimir y terminar su trámite, pero no editar ni eliminar.
  </div>
<?php endif; ?>
<?php if (!empty($GLOBALS['enlaces_orden_pago'])): ?>
  <div class="alert alert-info py-2 no-print d-flex flex-wrap gap-3 align-items-center" style="font-size:.85rem;">
    <?php foreach ($GLOBALS['enlaces_orden_pago'] as $_op): ?>
    <span><i class="fas fa-money-bill-transfer"></i> <?= htmlspecialchars($_op['titulo']) ?>:
      <a href="<?= BASE_URL . htmlspecialchars($_op['url']) ?>" class="fw-bold font-mono"><?= htmlspecialchars($_op['numero'] ?? '') ?></a>
      <?= $_op['estado'] ? estadoBadge($_op['estado']) : '' ?></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

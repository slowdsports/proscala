<?php
$cur = basename($_SERVER['PHP_SELF']);
function sidebarItem(string $file, string $icon, string $label, string $cur, string $badge = ''): string {
    if (!puedeAcceder($file)) return '';
    $active = ($cur === $file) ? 'active' : '';
    $b = $badge ? "<span class=\"badge-count\">{$badge}</span>" : '';
    return "<a href=\"" . BASE_URL . "{$file}\" class=\"sidebar-item {$active}\" data-page=\"{$file}\">
        <i class=\"fas {$icon}\"></i> {$label}{$b}
    </a>";
}
// Sección del menú; se oculta si el rol no tiene acceso a ninguno de sus módulos
function sidebarSection(string $title, string ...$items): string {
    $html = implode("\n    ", array_filter($items));
    if ($html === '') return '';
    $t = $title ? "<div class=\"sidebar-section-title\">{$title}</div>\n    " : '';
    return "<div class=\"sidebar-section\">\n    {$t}{$html}\n  </div>";
}
// Secciones de módulos (MODULOS en config/auth.php); "Mi Perfil" va antes de Administración
function sidebarModulos(string $title, array $items, string $cur): string {
    $html = [];
    foreach ($items as $file => [$icon, $label]) $html[] = sidebarItem($file, $icon, $label, $cur);
    return sidebarSection($title, ...$html);
}
?>
<nav id="sidebar">

  <?php // Variables con prefijo propio: este archivo se incluye dentro de cada página y no debe pisar las suyas ?>
  <?php foreach (MODULOS as $_sbTitulo => $_sbItems): ?>
    <?php if ($_sbTitulo === 'Administración'): ?>
  <!-- MI CUENTA -->
  <?= sidebarSection('',
    sidebarItem('perfil.php', 'fa-circle-user', 'Mi Perfil', $cur)) ?>
    <?php endif; ?>
  <?= sidebarModulos($_sbTitulo, $_sbItems, $cur) ?>
  <?php endforeach; ?>

  <!-- SUPER ADMINISTRADOR -->
  <?= sidebarSection('Super Administrador',
    sidebarItem('empresas.php', 'fa-building', 'Empresas', $cur)) ?>

  <div style="height:2rem;"></div>
</nav>

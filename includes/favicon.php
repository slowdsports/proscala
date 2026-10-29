<?php
// Ícono de las pestañas del navegador y de la pantalla de inicio en celulares, en cada página con
// <head> propio (header.php, login, recuperar, página principal, documentos impresos).
// Generados desde assets/images/icono.png (el original): favicon.ico (16, 32 y 48 px),
// favicon-32/192.png con las esquinas transparentes y apple-touch-icon.png (iOS redondea solo).
$_icono = fn(string $ruta) => function_exists('recurso') ? recurso($ruta) : BASE_URL . $ruta;
?>
  <link rel="icon" href="<?= $_icono('favicon.ico') ?>" sizes="16x16 32x32 48x48">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= $_icono('assets/images/favicon-32.png') ?>">
  <link rel="icon" type="image/png" sizes="192x192" href="<?= $_icono('assets/images/favicon-192.png') ?>">
  <link rel="apple-touch-icon" href="<?= $_icono('assets/images/apple-touch-icon.png') ?>">

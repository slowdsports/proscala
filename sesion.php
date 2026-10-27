<?php
// Pulso de sesión (assets/js/proscala.js → Sesion): mientras el usuario trabaja, renueva la
// sesión (requireLogin + db.php actualizan la última actividad). No es un módulo.
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
header('Cache-Control: no-store');
jsonOk(['inactividad' => SESION_INACTIVIDAD], 'Sesión activa.');

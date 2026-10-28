<?php
// ── Proscala: credenciales del servidor (PLANTILLA) ───────────────────
// Copie este archivo como "proscala_config.php" FUERA de la carpeta del sistema y complételo.
// Proscala lo busca, en este orden:
//   1. La ruta de la variable de entorno PROSCALA_CONFIG
//   2. <carpeta que contiene el sistema>/proscala_config.php     ← recomendado
//      (p. ej. sistema en /home/u6613652/web/ahdeco → /home/u6613652/web/proscala_config.php)
//   3. <carpeta superior a esa>/proscala_config.php               (p. ej. /home/u6613652/proscala_config.php)
//   4. config/credenciales.local.php                             (solo para desarrollo local)
// Así las contraseñas no viajan con el código ni con el despliegue (.cpanel.yml).
// Aunque quedara dentro de la web, este archivo no imprime nada: solo devuelve datos.
return [
    // Dirección pública del sistema, sin "/" final (los enlaces de los correos la usan)
    'url' => 'https://sudominio.com',

    // Base de datos (la central; cada empresa usa su propia base con este mismo usuario)
    'db' => [
        'host'    => 'localhost',
        'master'  => 'u6613652_proscala',
        'usuario' => 'u6613652_root',
        'clave'   => 'CAMBIAR',
    ],

    // Correo saliente (restablecer contraseña y avisos del sistema)
    //   metodo: 'smtp' (recomendado) o 'mail' (función mail() del servidor)
    //   seguridad: 'ssl' (puerto 465) o 'tls' (puerto 587)
    'correo' => [
        'metodo'    => 'smtp',
        'host'      => 'mail.sudominio.com',
        'puerto'    => 465,
        'seguridad' => 'ssl',
        'usuario'   => 'no-responder@sudominio.com',
        'clave'     => 'CAMBIAR',
        'remitente' => 'no-responder@sudominio.com',
        'nombre'    => 'Proscala',
    ],

    // Página principal (index.php): a dónde llegan las solicitudes de información y contratación,
    // y datos de contacto que se muestran (vacíos = no se muestran)
    'ventas' => [
        'correo'   => 'ventas@sudominio.com',    // recibe el formulario "Solicitar información"
        'telefono' => '',                        // p. ej. '+504 2222-3333'
        'whatsapp' => '',                        // solo números con código de país, p. ej. '50499998888'
    ],

    // Respaldos (cron/backup.php): carpeta fuera de la web y días que se conservan
    'respaldos' => [
        'dir'           => '/home/u6613652/proscala_respaldos',
        'dias'          => 14,
        'mysqldump'     => 'mysqldump',          // ruta del programa si no está en el PATH
        'avisar_a'      => '',                   // correo que recibe el aviso si un respaldo falla
    ],
];

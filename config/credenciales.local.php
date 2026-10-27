<?php
// Credenciales de desarrollo local (XAMPP). En el servidor NO se usa este archivo:
// ver config/credenciales.ejemplo.php. No contiene secretos reales.
return [
    'url' => 'http://localhost/proscala',
    'db' => [
        'host'    => 'localhost',
        'master'  => 'proscala',
        'usuario' => 'root',
        'clave'   => '',
    ],
    // Local: los correos no salen; se guardan en storage/correos/ para revisarlos
    'correo' => [
        'metodo'    => 'archivo',
        'remitente' => 'no-responder@proscala.local',
        'nombre'    => 'Proscala',
    ],
    'respaldos' => [
        'dir'       => 'C:/xampp/proscala_respaldos',
        'dias'      => 14,
        'mysqldump' => 'C:/xampp/mysql/bin/mysqldump.exe',
        'avisar_a'  => '',
    ],
];

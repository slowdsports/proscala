<?php
// Estructura: sintaxis de los archivos y esquemas de la base de datos

prueba('Sintaxis PHP de todos los archivos del sistema', function () {
    $errores = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RAIZ, FilesystemIterator::SKIP_DOTS));
    $php = escapeshellarg(PHP_BINARY);
    $n = 0;
    foreach ($it as $f) {
        $ruta = str_replace('\\', '/', $f->getPathname());
        if ($f->getExtension() !== 'php' || preg_match('#/(vendor|storage|\.git)/#', $ruta)) continue;
        $n++;
        exec("$php -l " . escapeshellarg($ruta) . ' 2>&1', $salida, $codigo);
        if ($codigo !== 0) $errores[] = basename($ruta) . ': ' . implode(' ', $salida);
        $salida = [];
    }
    afirmar(!$errores, implode("\n      ", $errores));
    afirmar($n > 80, "solo se revisaron $n archivos");
});

prueba('Los esquemas se instalan en una base nueva y quedan sin diferencias', function () use ($pdoMaster) {
    $nombre = 'proscala_prueba_' . bin2hex(random_bytes(3));
    $pdoMaster->exec("CREATE DATABASE `$nombre` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    try {
        $db = conectarBD($nombre);
        // Igual que empresas.php al dar de alta una empresa
        foreach (['empresa_schema.sql', 'empresa_seed.sql', 'roles_modulos_inicial.sql'] as $archivo) ejecutarArchivoSQL($db, RAIZ . "/database/$archivo");
        $cambios = cambiosEsquema($db, leerEsquema(RAIZ . '/database/empresa_schema.sql'));
        afirmar(!$cambios, 'la base recién creada difiere de su esquema: ' . implode('; ', array_column($cambios, 0)));
        iguales('2', (string)$db->query("SELECT valor FROM configuracion WHERE clave = 'recordatorio_dias'")->fetchColumn(), 'configuración inicial recordatorio_dias');
        afirmar((int)$db->query("SELECT COUNT(*) FROM roles")->fetchColumn() > 0, 'sin puestos iniciales');
        // Base central
        $central = 'proscala_prueba_c' . bin2hex(random_bytes(3));
        $pdoMaster->exec("CREATE DATABASE `$central` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        try {
            $dc = conectarBD($central);
            // Sin CREATE DATABASE / USE: se instala en la base temporal
            $tmp = tempnam(sys_get_temp_dir(), 'sql');
            file_put_contents($tmp, preg_replace('/^(CREATE DATABASE|USE)\b.*$/mi', '', (string)file_get_contents(RAIZ . '/database/proscala_master.sql')));
            ejecutarArchivoSQL($dc, $tmp);
            @unlink($tmp);
            $cambios = cambiosEsquema($dc, leerEsquema(RAIZ . '/database/proscala_master.sql'));
            afirmar(!$cambios, 'la base central recién creada difiere de su esquema: ' . implode('; ', array_column($cambios, 0)));
        } finally { $pdoMaster->exec("DROP DATABASE IF EXISTS `$central`"); }
    } finally {
        $pdoMaster->exec("DROP DATABASE IF EXISTS `$nombre`");
    }
});

prueba('La base local está al día con los esquemas (actualizar_bd.php no tiene pendientes)', function () use ($pdo, $pdoMaster) {
    $c1 = cambiosEsquema($pdo, leerEsquema(RAIZ . '/database/empresa_schema.sql'));
    $c2 = cambiosEsquema($pdoMaster, leerEsquema(RAIZ . '/database/proscala_master.sql'));
    afirmar(!$c1 && !$c2, 'pendiente: ' . implode('; ', array_column(array_merge($c1, $c2), 0)) . ' → ejecute php actualizar_bd.php --aplicar');
});

prueba('El servidor cumple los requisitos obligatorios', function () use ($pdoMaster) {
    $faltan = array_filter(requisitosServidor($pdoMaster), fn($q) => !$q[1] && $q[3]);
    afirmar(!$faltan, 'falta: ' . implode(', ', array_column($faltan, 0)));
});

prueba('Zona horaria: MySQL y PHP marcan la misma hora', function () use ($pdo) {
    $mysql = strtotime((string)$pdo->query('SELECT NOW()')->fetchColumn());
    afirmar(abs($mysql - time()) < 120, 'MySQL ' . date('H:i', $mysql) . ' / PHP ' . date('H:i'));
});

prueba('El despliegue protege los datos del servidor (.deployfilter)', function () {
    $f = (string)file_get_contents(RAIZ . '/.deployfilter');
    foreach (['P /assets/uploads/***', 'P /storage/***', '- /tests/', '- /.git/'] as $regla) afirmar(str_contains($f, $regla), "falta la regla \"$regla\"");
    afirmar(str_contains((string)file_get_contents(RAIZ . '/.cpanel.yml'), "--filter='merge .deployfilter'"), '.cpanel.yml no usa .deployfilter');
});

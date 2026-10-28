<?php
// ── Catálogo de cuentas contables: plantilla e importación desde Excel ──
// Catálogos → Catálogo contable. La plantilla trae exactamente las columnas de CATALOGO_COLUMNAS; al
// importar, el archivo se revisa completo (encabezados, cada celda, códigos repetidos, cuentas padre y
// ciclos) antes de escribir nada, y se aplica en una sola transacción: todo o nada. Cada empresa tiene
// su propia base, así que el catálogo importado solo cambia el de la empresa de la sesión.
// Las cuentas se identifican por su código: las que ya existen se actualizan, las nuevas se crean y
// (si se pide) las que no vienen en el archivo se desactivan. Nunca se borran: los procesos las usan.
require_once __DIR__ . '/excel.php';

// Encabezado de cada columna, en este orden (la fila 1 del archivo debe coincidir)
const CATALOGO_COLUMNAS = ['Código', 'Nombre', 'Tipo', 'Subtipo', 'Código de la cuenta padre', 'Nivel', 'Descripción', 'Activa'];
const CATALOGO_TIPOS = ['activo' => 'Activo', 'pasivo' => 'Pasivo', 'capital' => 'Capital', 'ingreso' => 'Ingreso', 'gasto' => 'Gasto',
                        'otro_ingreso' => 'Otro Ingreso', 'otro_gasto' => 'Otro Gasto'];
const CATALOGO_MAX_CUENTAS = 5000;
const CATALOGO_MAX_MB = 5;
const CATALOGO_MAX_NIVEL = 10;

/** Texto comparable: sin mayúsculas, tildes, guiones bajos ni espacios de más. */
function normalizarCatalogo(string $s): string {
    $s = mb_strtolower(trim(preg_replace('/[\s_]+/u', ' ', $s)));
    return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

/** .xlsx de la plantilla; con $pdo, lleva el catálogo actual de la empresa (para editarlo y volver a subirlo). */
function plantillaCatalogo(?PDO $pdo = null): string {
    $filas = [];
    if ($pdo) {
        foreach ($pdo->query("SELECT c.codigo, c.nombre, c.tipo, c.subtipo, p.codigo AS padre, c.nivel, c.descripcion, c.activa
                              FROM cuentas_contables c LEFT JOIN cuentas_contables p ON p.id = c.cuenta_padre_id ORDER BY c.codigo") as $c) {
            $filas[] = [$c['codigo'], $c['nombre'], CATALOGO_TIPOS[$c['tipo']] ?? $c['tipo'], $c['subtipo'] ?? '', $c['padre'] ?? '',
                        (string)$c['nivel'], $c['descripcion'] ?? '', $c['activa'] ? 'Sí' : 'No'];
        }
    }
    return generarXlsx('Catálogo de cuentas', CATALOGO_COLUMNAS, $filas, [
        'texto'  => [0, 4],                                         // códigos: "1.10" o "0101" se conservan
        'listas' => [2 => array_values(CATALOGO_TIPOS), 7 => ['Sí', 'No']],
        'filas'  => CATALOGO_MAX_CUENTAS,
    ]);
}

/**
 * Revisa las filas leídas del archivo (leerXlsx) contra el formato y el catálogo actual.
 * Devuelve ['errores' => [texto], 'cuentas' => [codigo => datos], 'resumen' => [...]].
 */
function validarCatalogo(PDO $pdo, array $filas, bool $desactivarFaltantes): array {
    $errores = [];
    $err = function (int $fila, ?int $col, string $msg) use (&$errores) {
        $errores[] = "Fila $fila" . ($col !== null ? ', columna «' . CATALOGO_COLUMNAS[$col] . '»' : '') . ": $msg";
    };

    // Encabezados: exactamente los de la plantilla, en su orden
    $enc = $filas[1] ?? [];
    $leidos = array_map(fn($i) => normalizarCatalogo($enc[$i] ?? ''), array_keys(CATALOGO_COLUMNAS));
    if ($leidos !== array_map('normalizarCatalogo', CATALOGO_COLUMNAS) || max(array_keys($enc) ?: [0]) >= count(CATALOGO_COLUMNAS)) {
        return ['errores' => ['La primera fila no tiene los encabezados de la plantilla (' . implode(', ', CATALOGO_COLUMNAS)
            . '). Descargue la plantilla y copie sus datos en ella, sin cambiar, agregar ni quitar columnas.'], 'cuentas' => [], 'resumen' => []];
    }
    unset($filas[1]);
    if (!$filas) return ['errores' => ['El archivo no tiene cuentas: agréguelas debajo de los encabezados.'], 'cuentas' => [], 'resumen' => []];
    if (count($filas) > CATALOGO_MAX_CUENTAS) return ['errores' => ['El archivo tiene más de ' . CATALOGO_MAX_CUENTAS . ' cuentas.'], 'cuentas' => [], 'resumen' => []];

    $tipos = [];
    foreach (CATALOGO_TIPOS as $k => $label) { $tipos[normalizarCatalogo($k)] = $k; $tipos[normalizarCatalogo($label)] = $k; }
    $si = ['si', 's', '1', 'x', 'verdadero', 'true', 'activa', ''];
    $no = ['no', 'n', '0', 'falso', 'false', 'inactiva'];

    $cuentas = [];   // CODIGO (mayúsculas, la columna no distingue) => datos
    foreach ($filas as $n => $c) {
        if (max(array_keys($c)) >= count(CATALOGO_COLUMNAS)) { $err($n, null, 'tiene datos fuera de las columnas de la plantilla.'); }
        $codigo = $c[0] ?? '';
        $clave  = mb_strtoupper($codigo);
        $nombre = preg_replace('/\s+/u', ' ', $c[1] ?? '');
        $tipo   = $tipos[normalizarCatalogo($c[2] ?? '')] ?? null;
        $padre  = $c[4] ?? '';
        $nivel  = $c[5] ?? '';
        $activa = normalizarCatalogo($c[7] ?? '');

        if ($codigo === '') $err($n, 0, 'es obligatorio.');
        elseif (!preg_match('/^[0-9A-Za-z][0-9A-Za-z.\-]{0,19}$/', $codigo)) $err($n, 0, "«{$codigo}» no es válido: solo letras, números, puntos y guiones, hasta 20 caracteres.");
        elseif (isset($cuentas[$clave])) $err($n, 0, "el código $codigo ya aparece en la fila {$cuentas[$clave]['fila']}.");
        if ($nombre === '') $err($n, 1, 'es obligatorio.');
        elseif (mb_strlen($nombre) > 200) $err($n, 1, 'tiene más de 200 caracteres.');
        if (($c[2] ?? '') === '') $err($n, 2, 'es obligatorio.');
        elseif (!$tipo) $err($n, 2, '«' . $c[2] . '» no es un tipo válido. Use: ' . implode(', ', CATALOGO_TIPOS) . '.');
        if (mb_strlen($c[3] ?? '') > 100) $err($n, 3, 'tiene más de 100 caracteres.');
        if ($padre !== '' && mb_strtoupper($padre) === $clave) $err($n, 4, 'una cuenta no puede ser su propia cuenta padre.');
        if ($nivel !== '' && (!ctype_digit($nivel) || (int)$nivel < 1 || (int)$nivel > CATALOGO_MAX_NIVEL)) $err($n, 5, 'debe ser un número entero del 1 al ' . CATALOGO_MAX_NIVEL . '.');
        if (mb_strlen($c[6] ?? '') > 2000) $err($n, 6, 'tiene más de 2000 caracteres.');
        if (!in_array($activa, $si, true) && !in_array($activa, $no, true)) $err($n, 7, 'debe ser «Sí» o «No».');

        if ($codigo !== '' && !isset($cuentas[$clave])) {
            $cuentas[$clave] = ['fila' => $n, 'codigo' => $codigo, 'nombre' => $nombre, 'tipo' => $tipo, 'subtipo' => ($c[3] ?? '') ?: null,
                                'padre' => $padre === '' ? null : mb_strtoupper($padre), 'nivel' => $nivel === '' ? null : (int)$nivel,
                                'descripcion' => ($c[6] ?? '') ?: null, 'activa' => in_array($activa, $no, true) ? 0 : 1];
        }
    }

    // Catálogo actual: las cuentas padre pueden ser del archivo o (si no se desactivan) de las existentes
    $actuales = [];
    foreach ($pdo->query("SELECT id, codigo, nivel FROM cuentas_contables") as $r) $actuales[mb_strtoupper($r['codigo'])] = $r;
    foreach ($cuentas as $clave => &$cta) {
        if ($cta['padre'] === null) continue;
        if (!isset($cuentas[$cta['padre']]) && (!isset($actuales[$cta['padre']]) || $desactivarFaltantes)) {
            $err($cta['fila'], 4, "la cuenta padre {$cta['padre']} no está en el archivo" . ($desactivarFaltantes || !isset($actuales[$cta['padre']]) ? '' : ' ni en el catálogo') . '.');
            $cta['padre'] = null;
        }
    }
    unset($cta);
    // Ciclos: la cadena de cuentas padre de cada cuenta debe terminar (A → B → A no)
    $ciclo = false;
    foreach ($cuentas as $clave => $cta) {
        $vistos = [$cta['codigo'] => true];
        for ($p = $cta['padre']; $p !== null && isset($cuentas[$p]); $p = $cuentas[$p]['padre']) {
            if (isset($vistos[$cuentas[$p]['codigo']])) {
                $err($cta['fila'], 4, 'las cuentas padre forman un ciclo (' . implode(' → ', array_keys($vistos)) . ' → ' . $cuentas[$p]['codigo'] . ').');
                $ciclo = true;
                break;
            }
            $vistos[$cuentas[$p]['codigo']] = true;
        }
    }
    // Nivel vacío: el de su cuenta padre + 1 (1 si no tiene)
    $nivelDe = function (string $clave) use (&$nivelDe, &$cuentas, $actuales): int {
        if ($cuentas[$clave]['nivel'] === null) {
            $p = $cuentas[$clave]['padre'];
            $n = $p === null ? 1 : (isset($cuentas[$p]) ? $nivelDe($p) : (int)$actuales[$p]['nivel']) + 1;
            $cuentas[$clave]['nivel'] = min($n, CATALOGO_MAX_NIVEL);
        }
        return $cuentas[$clave]['nivel'];
    };
    if (!$ciclo) foreach (array_keys($cuentas) as $clave) $nivelDe($clave);

    $nuevas = count(array_diff_key($cuentas, $actuales));
    $faltantes = array_diff_key($actuales, $cuentas);
    return ['errores' => $errores, 'cuentas' => $cuentas, 'resumen' => [
        'total' => count($cuentas), 'nuevas' => $nuevas, 'actualizadas' => count($cuentas) - $nuevas,
        'desactivadas' => $desactivarFaltantes ? count($faltantes) : 0, 'sin_cambio' => $desactivarFaltantes ? 0 : count($faltantes),
    ]];
}

/** Aplica un catálogo ya validado (sin errores) en una transacción (o en la que ya esté abierta). */
function aplicarCatalogo(PDO $pdo, array $cuentas, bool $desactivarFaltantes): void {
    $propia = !$pdo->inTransaction();
    if ($propia) $pdo->beginTransaction();
    try {
        $ids = [];
        foreach ($pdo->query("SELECT id, codigo FROM cuentas_contables") as $r) $ids[mb_strtoupper($r['codigo'])] = (int)$r['id'];
        $upd = $pdo->prepare("UPDATE cuentas_contables SET codigo=?, nombre=?, tipo=?, subtipo=?, nivel=?, descripcion=?, activa=? WHERE id=?");
        $ins = $pdo->prepare("INSERT INTO cuentas_contables (codigo, nombre, tipo, subtipo, nivel, descripcion, activa) VALUES (?,?,?,?,?,?,?)");
        foreach ($cuentas as $clave => $c) {
            $v = [$c['codigo'], $c['nombre'], $c['tipo'], $c['subtipo'], $c['nivel'], $c['descripcion'], $c['activa']];
            if (isset($ids[$clave])) $upd->execute([...$v, $ids[$clave]]);
            else { $ins->execute($v); $ids[$clave] = (int)$pdo->lastInsertId(); }
        }
        // Cuentas padre, cuando ya existen todas
        $padre = $pdo->prepare("UPDATE cuentas_contables SET cuenta_padre_id=? WHERE id=?");
        foreach ($cuentas as $clave => $c) $padre->execute([$c['padre'] !== null ? $ids[$c['padre']] : null, $ids[$clave]]);
        if ($desactivarFaltantes) {
            $enArchivo = array_intersect_key($ids, $cuentas);
            $pdo->exec("UPDATE cuentas_contables SET activa = 0 WHERE id NOT IN (" . implode(',', array_map('intval', $enArchivo)) . ")");
        }
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        if ($propia) $pdo->rollBack();
        throw $e;
    }
}

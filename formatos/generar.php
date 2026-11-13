<?php
// ── Formatos en Excel de los documentos del sistema ────────────────
// Genera en esta carpeta un libro de Excel (.xlsx) por cada documento que imprime print.php
// (templates/print/), con el mismo diseño: colores, encabezado, datos generales, tablas de
// detalle, totales, firmas y pie. Los campos quedan en blanco para llenarse; las tablas calculan
// sus totales con fórmulas, las listas (urgencia, forma de pago, tipo…) son desplegables y la
// hoja se imprime en A4 ajustada al ancho de la página.
//
// Uso (desde la raíz del sistema, solo en la línea de comandos):
//   php formatos/generar.php                     formatos genéricos (datos de la organización por llenar)
//   php formatos/generar.php --empresa=<base>    con el nombre, RTN, dirección, logo y prefijos de una empresa
//   php formatos/generar.php --salida=<carpeta>  en otra carpeta
// Si cambia el diseño de una plantilla de impresión, actualice aquí el formato y vuelva a generarlos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/lib/formato.php';

$opc = getopt('', ['empresa::', 'salida::']);
$SALIDA = rtrim($opc['salida'] ?? __DIR__, '/\\');
if (!is_dir($SALIDA) && !mkdir($SALIDA, 0775, true)) exit("No se pudo crear la carpeta {$SALIDA}\n");

// ── Datos de la organización ───────────────────────────────────────
$ORG = ['nombre' => '[Nombre de la organización]', 'completo' => '[Razón social]', 'dir' => '[Dirección]',
        'rtn' => '[RTN]', 'tel' => '[Teléfono]', 'correo' => '[Correo electrónico]', 'logo' => '', 'inicial' => 'LOGO'];
$PREF = ['sc' => 'SC', 'co' => 'CO', 'oc' => 'OC', 'op' => 'OP', 'gv' => 'GV', 'sg' => 'SG', 'pl' => 'PL', 'pa' => 'PA',
         'pb' => 'PB', 'vac' => 'VAC', 'per' => 'PER', 'liq' => 'LIQ', 'cc' => 'CC', 'memo' => 'MEM', 'acta' => 'ACTA'];
if (!empty($opc['empresa'])) {
    require_once dirname(__DIR__) . '/config/db.php';
    $pdo = conectarBD($opc['empresa']);
    $cfg = array_column($pdo->query("SELECT clave, valor FROM configuracion")->fetchAll(), 'valor', 'clave');
    $ORG = ['nombre' => $cfg['nombre_organizacion'] ?? 'Proscala', 'completo' => $cfg['nombre_completo'] ?? '',
            'dir' => $cfg['direccion'] ?? 'Tegucigalpa, Honduras', 'rtn' => $cfg['rtn'] ?? '', 'tel' => $cfg['telefono'] ?? '',
            'correo' => $cfg['correo'] ?? '', 'logo' => '', 'inicial' => ''];
    $ORG['inicial'] = mb_strtoupper(mb_substr($ORG['nombre'], 0, 1));
    $logo = $cfg['logo'] ?? '';
    if ($logo !== '' && str_starts_with($logo, 'assets/uploads/logos/') && is_file(dirname(__DIR__) . '/' . $logo)) $ORG['logo'] = dirname(__DIR__) . '/' . $logo;
    foreach ($PREF as $k => $v) $PREF[$k] = $cfg["prefijo_{$k}"] ?? $v;
}
$DIR_CARTA = $ORG['dir'] !== '' && $ORG['dir'] !== '[Dirección]' ? $ORG['dir'] : 'Tegucigalpa, Honduras';

const FLUJO = ['Elaborado por', 'Revisado por', 'Autorizado por'];
const LINEA_LARGA = '________________________________', LINEA_CORTA = '________________';
const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

function num(string $tipo): string { global $PREF; return ($PREF[$tipo] ?? strtoupper($tipo)) . '__-____'; }
function hoja(XlsxLibro $l, string $nombre, $titulo, string $numero, bool $horizontal = false): Formato {
    global $ORG;
    return new Formato($l, $nombre, $ORG, $titulo, $numero, $horizontal);
}
/** Píldora del documento actual en la cadena: su número sale del encabezado */
function actual(Formato $d, string $icono): array { return ['f' => "\"{$icono} \"&{$d->refNumero}"]; }
/** Total de una línea: cantidad (1 si está vacía) × precio */
function porCantidad(string $cant, string $precio): string { return "IF({$precio}=\"\",\"\",IF({$cant}=\"\",1,{$cant})*{$precio})"; }
/** Referencia a una celda de otra hoja del mismo libro */
function deHoja(Formato $d, string $ref): string { return "'" . str_replace("'", "''", $d->h->nombre) . "'!{$ref}"; }

// ═════════════════════════════ COMPRAS ═════════════════════════════

function hojaSC(Formato $d): void {
    $d->encabezado();
    $d->meta([
        ['l' => 'Fecha', 'k' => 'fecha'], ['l' => 'Solicitante'], ['l' => 'Proyecto'],
        ['l' => 'Urgencia', 'k' => 'lista', 'op' => ['Normal', 'Urgente', 'Muy urgente'], 'v' => 'Normal'],
        ['l' => 'Descripción', 's' => 'full', 'alto' => 28],
        ['l' => 'Justificación', 's' => 'full', 'alto' => 28],
    ]);
    $d->seccion('Artículos / Servicios Requeridos');
    $d->tabla([
        ['t' => '#', 'w' => 1, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Descripción', 'w' => 8, 'k' => 'desc'],
        ['t' => 'Unidad', 'w' => 2, 'k' => 'u', 'a' => 'c'],
        ['t' => 'Cantidad', 'w' => 3, 'k' => 'cant', 'tipo' => 'num'],
        ['t' => 'Precio Est.', 'w' => 4, 'k' => 'precio', 'tipo' => 'monto'],
        ['t' => 'Total Est.', 'w' => 6, 'k' => 'total', 'tipo' => 'monto', 'f' => fn($r) => porCantidad($r('cant'), $r('precio'))],
    ], 10, [['l' => 'PRESUPUESTO ESTIMADO TOTAL', 'desde' => 'total', 'v' => ['total' => 'sum'], 'final' => true]]);
    $d->firmas(FLUJO);
    $d->pie();
}

function hojaOC(Formato $d, ?Formato $sc = null): void {
    $d->encabezado();
    $d->cadena([$sc ? ['f' => '"📋 "&' . deHoja($sc, $sc->refNumero)] : '📋 ' . num('sc'), actual($d, '🛒')]);
    $meta = [
        ['l' => 'Fecha Emisión', 'k' => 'fecha'], ['l' => 'Fecha Entrega', 'k' => 'fecha'],
        ['l' => 'Condiciones de Pago'], ['l' => 'Lugar de Entrega'],
        ['l' => 'Proveedor', 's' => 2], ['l' => 'RTN Proveedor', 'k' => 'mono'],
    ];
    if (!$sc) array_push($meta, ['l' => 'Proyecto'], ['l' => 'Notas', 's' => 'full', 'alto' => 28]);
    $d->meta($meta);
    $d->seccion('Ítems de la Orden');
    $cols = [
        ['t' => '#', 'w' => 1, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Descripción', 'w' => $sc ? 10 : 6, 'k' => 'desc'],
        ['t' => 'Unidad', 'w' => 2, 'k' => 'u', 'a' => 'c'],
        ['t' => 'Cantidad', 'w' => 2, 'k' => 'cant', 'tipo' => 'num'],
        ['t' => 'Precio Unit.', 'w' => 4, 'k' => 'pu', 'tipo' => 'monto'],
        ['t' => 'Total', 'w' => 5, 'k' => 'total', 'tipo' => 'monto', 'f' => fn($r) => porCantidad($r('cant'), $r('pu'))],
    ];
    if (!$sc) $cols[] = ['t' => 'Cuenta', 'w' => 4, 'k' => 'cta', 'e' => fu(7.5, ['c' => GRIS])];
    $t = $d->tabla($cols, 10, [
        ['l' => 'Subtotal', 'desde' => 'total', 'v' => ['total' => 'sum']],
        ['l' => 'Descuento', 'desde' => 'total', 'v' => ['total' => null]],
        ['l' => 'Impuesto ISV', 'desde' => 'total', 'v' => ['total' => null]],
        ['l' => 'Impuesto Turismo', 'desde' => 'total', 'v' => ['total' => null]],
        ['l' => 'TOTAL', 'desde' => 'total', 'v' => ['total' => null], 'final' => true, 'le' => fu(9.5, ['b' => true, 'c' => MARINO])],
    ]);
    [$sub, $desc, $isv, $tur, $tot] = array_map(fn($f) => $t['celda']('total', $f), $t['pie']);
    $d->ponerRef($tot, ['f' => "{$sub}-N({$desc})+N({$isv})+N({$tur})"]);
    $d->firmas(FLUJO);
    $d->pie();
}

function hojaOP(Formato $d, ?Formato $sc = null, ?Formato $oc = null): void {
    $d->encabezado();
    if ($sc && $oc) $d->cadena([['f' => '"📋 "&' . deHoja($sc, $sc->refNumero)], ['f' => '"🛒 "&' . deHoja($oc, $oc->refNumero)], actual($d, '💳')]);
    else $d->cadena(['📄 Documento de origen', actual($d, '💳')]);
    $meta = [
        ['l' => 'Fecha', 'k' => 'fecha'],
        ['l' => 'Forma de Pago', 'k' => 'lista', 'op' => ['Cheque', 'Transferencia', 'Efectivo'], 'v' => 'Transferencia'],
        ['l' => $sc ? 'N° Cheque' : 'Número de Cheque', 'k' => 'mono'], ['l' => 'N° Factura Proveedor', 'k' => 'mono'],
        ['l' => 'Fecha de Pago', 'k' => 'fecha'],
    ];
    if (!$sc) $meta[] = ['l' => 'Proceso', 'k' => 'mono'];
    array_push($meta,
        ['l' => $sc ? 'Beneficiario' : 'Beneficiario (Proveedor / Empleado / Otro)', 's' => 2, 'nueva' => true, 'alto' => 20, 'e' => fu(11, ['b' => true, 'c' => MARINO])],
        ['l' => 'Banco'], ['l' => $sc ? 'Cuenta Bancaria' : 'Cuenta Bancaria (Ahorros / Cheques)', 'k' => 'mono']);
    if (!$sc) array_push($meta, ['l' => 'Proyecto', 's' => 2], ['l' => 'Cuenta Contable', 's' => 2]);
    $meta[] = ['l' => $sc ? 'Concepto' : 'Concepto / Descripción', 's' => 'full', 'alto' => 28];
    if (!$sc) $meta[] = ['l' => 'Notas', 's' => 'full', 'alto' => 28];
    $d->meta($meta);
    $d->monto('Monto a Pagar', null);
    $d->firmas(FLUJO);
    $d->pie();
}

function hojaCO(Formato $d): void {
    $P = 3; $N = 8;
    $d->encabezado();
    $d->cadena(['📋 ' . num('sc'), actual($d, '📑')]);
    $d->meta([
        ['l' => 'Fecha', 'k' => 'fecha'], ['l' => 'Solicitud de Compra', 'k' => 'mono'], ['l' => 'Solicitante'], ['l' => 'Proyecto'],
        ['l' => 'Descripción de la compra', 's' => 'full', 'alto' => 28],
    ]);
    $fs = $d->seccion('');
    $h = $d->h;
    $ini = $d->ini;
    $cN = $ini; $cArt = $ini + 1; $cCant = $ini + 7; $cProv = fn(int $k) => $ini + 9 + 5 * $k;   // cada proveedor: P. unit. (2) y total (3)
    $aux = $d->n + 2;                                                                           // columnas auxiliares ocultas
    for ($c = $d->n + 1; $c <= $aux + $P; $c++) { $h->anchos[$c] = 8; $h->ocultas[$c] = $c > $d->n + 1; }
    $h->colsImpresion = $d->n;

    // Encabezado de dos filas: proveedor (editable) y, debajo, precio unitario y total
    $fa = $d->espacio(20, ['bg' => MARINO]);
    $fb = $d->espacio(14, ['bg' => MARINO]);
    $cab = fu(7, ['b' => true, 'c' => BLANCO]) + ['bg' => MARINO, 'v' => 'center', 'wrap' => true];
    $h->rango($fa, $cN, $fb, $cN, '#', $cab);
    $h->rango($fa, $cArt, $fb, $cArt + 5, 'Artículo', $cab);
    $h->rango($fa, $cCant, $fb, $cCant + 1, 'Cant.', $cab + ['h' => 'right']);
    $nombres = [];
    for ($k = 0; $k < $P; $k++) {
        $c = $cProv($k);
        $h->rango($fa, $c, $fa, $c + 4, 'Proveedor ' . ($k + 1), $cab + ['h' => 'right']);
        $nombres[$k] = $d->ref($fa, $c);
        $h->rango($fb, $c, $fb, $c + 1, 'P. Unit.', fu(6.5, ['c' => 'CBD5E1']) + ['bg' => MARINO, 'h' => 'right', 'v' => 'center']);
        $h->rango($fb, $c + 2, $fb, $c + 4, 'Total', fu(6.5, ['c' => 'CBD5E1']) + ['bg' => MARINO, 'h' => 'right', 'v' => 'center']);
    }

    // Artículos
    $f1 = $d->f;
    for ($i = 0; $i < $N; $i++) {
        $f = $d->espacio(17, ['bg' => $i % 2 ? CLARO : BLANCO, 'b' => ['b' => ['thin', LINEA]]]);
        $h->poner($f, $cN, $i + 1, fu(8, ['c' => GRIS]) + ['h' => 'center', 'v' => 'center']);
        $h->rango($f, $cArt, $f, $cArt + 5, null, fu(8) + ['v' => 'center']);
        $h->rango($f, $cCant, $f, $cCant + 1, null, fu(8) + ['h' => 'right', 'v' => 'center', 'nf' => NF_NUM]);
        $tots = [];
        for ($k = 0; $k < $P; $k++) {
            $c = $cProv($k);
            $pu = $d->ref($f, $c);
            $h->rango($f, $c, $f, $c + 1, null, mono(8) + ['h' => 'right', 'v' => 'center', 'nf' => NF_NUM]);
            $h->rango($f, $c + 2, $f, $c + 4, ['f' => porCantidad($d->ref($f, $cCant), $pu)], mono(8, ['c' => GRIS]) + ['h' => 'right', 'v' => 'center', 'nf' => NF_LPS]);
            $tots[] = $d->ref($f, $c + 2);
        }
        $lista = implode(',', $tots);
        $h->poner($f, $aux, ['f' => "IF(COUNT({$lista})=0,\"\",MIN({$lista}))"]);
        for ($k = 0; $k < $P; $k++) $h->poner($f, $aux + 1 + $k, ['f' => "IF(AND(ISNUMBER({$tots[$k]}),{$tots[$k]}={$d->ref($f, $aux)}),{$tots[$k]},\"\")"]);
    }
    $f2 = $d->f - 1;
    $col = fn(int $c) => $d->ref($f1, $c) . ':' . $d->ref($f2, $c);
    $abs = fn(int $c) => '$' . XlsxHoja::col($c);
    // En verde, el menor precio de cada artículo
    $verde = $d->dxf(['bg' => 'DCFCE7', 'c' => '15803D', 'b' => true]);
    $todos = implode(',', array_map(fn($k) => $abs($cProv($k)) . $f1, range(0, $P - 1)));
    for ($k = 0; $k < $P; $k++) {
        $c = $cProv($k);
        $h->condicional($col($c) . ' ' . $col($c + 2), "AND(ISNUMBER({$abs($c)}{$f1}),{$abs($c)}{$f1}=MIN({$todos}))", $verde);
    }

    // Totales y condiciones de cada oferta
    $ft = $d->espacio(19, ['b' => ['t' => ['medium', AZUL]]]);
    $h->rango($ft, $cN, $ft, $cCant + 1, 'TOTAL OFERTADO', fu(8, ['b' => true, 'c' => MARINO]) + ['h' => 'right', 'v' => 'center']);
    $totales = [];
    for ($k = 0; $k < $P; $k++) {
        $c = $cProv($k);
        $h->rango($ft, $c, $ft, $c + 4, ['f' => "IF(COUNT({$col($c + 2)})=0,\"\",SUM({$col($c + 2)}))"], mono(8, ['b' => true, 'c' => MARINO]) + ['h' => 'right', 'v' => 'center', 'nf' => NF_LPS]);
        $totales[$k] = $d->ref($ft, $c);
    }
    foreach (['Artículos cotizados', 'No. de cotización', 'Condiciones de pago', 'Tiempo de entrega'] as $j => $etq) {
        $f = $d->espacio(14);
        $h->rango($f, $cN, $f, $cCant + 1, $etq, fu(6.8, ['c' => GRIS]) + ['h' => 'right', 'v' => 'center']);
        for ($k = 0; $k < $P; $k++) {
            $c = $cProv($k);
            $v = $j === 0 ? ['f' => "COUNT({$col($c)})&\" de \"&COUNTA({$col($cArt)})"] : null;
            $h->rango($f, $c, $f, $c + 4, $v, fu(6.8, ['c' => GRIS]) + ['h' => 'right', 'v' => 'center']);
        }
    }
    $h->poner($fs, $ini, ['f' => "\"CUADRO COMPARATIVO · \"&COUNTA({$col($cArt)})&\" ARTÍCULO(S) · {$P} PROVEEDORES\""]);
    $fn = $d->espacio(13);
    $h->poner($fn, $ini, 'Precio unitario y total de la línea. En verde, el menor precio de cada artículo. Deje en blanco lo que el proveedor no cotizó.', fu(6.8, ['c' => GRIS]) + ['v' => 'center']);

    // Recomendación: un solo proveedor (la oferta más baja) y compra por artículo (el mejor precio de cada uno)
    $d->seccion('Recomendación del sistema', 9);
    $d->espacio(9);
    // Como el sistema (config/cotizaciones.php): la oferta más baja entre los que cotizaron todos los
    // artículos; si ninguno cotizó todo, la más baja de las recibidas. Celdas auxiliares ocultas.
    $completos = [];
    for ($k = 0; $k < $P; $k++) {
        $h->poner($ft, $aux + 1 + $k, ['f' => "IF(AND(ISNUMBER({$totales[$k]}),COUNT({$col($cProv($k))})=COUNTA({$col($cArt)})),{$totales[$k]},\"\")"]);
        $completos[$k] = $d->ref($ft, $aux + 1 + $k);
    }
    $hayCompletos = 'COUNT(' . implode(',', $completos) . ')>0';
    $candidatos = [];
    for ($k = 0; $k < $P; $k++) {
        $h->poner($ft + 1, $aux + 1 + $k, ['f' => "IF({$hayCompletos},{$completos[$k]},{$totales[$k]})"]);
        $candidatos[$k] = $d->ref($ft + 1, $aux + 1 + $k);
    }
    $tl = implode(',', $totales);
    $minimo = 'MIN(' . implode(',', $candidatos) . ')';
    $quien = "\"\"";
    for ($k = $P - 1; $k >= 0; $k--) $quien = "IF({$candidatos[$k]}={$minimo},{$nombres[$k]},{$quien})";
    $b1 = [$ini, $ini + 9]; $b2 = [$ini + 11, $d->fin];
    $caja = ['bg' => 'F8FBFF'];
    $fr = $d->f;
    $filasCaja = [[11, '6.8t'], [16, 'n'], [18, 'm'], [12, 'x'], [12, 'x2']];
    foreach ($filasCaja as $i => [$alto]) $d->espacio($alto);
    for ($i = 0; $i < $P; $i++) $d->espacio(13);
    $d->espacio(12);
    $frFin = $d->f - 1;
    foreach ([$b1, $b2] as $b) {
        $h->pintar($fr, $b[0], $frFin, $b[1], $caja);
        $h->pintar($fr, $b[0], $fr, $b[1], ['b' => ['t' => ['thin', BORDE_CELESTE]]]);
        $h->pintar($frFin, $b[0], $frFin, $b[1], ['b' => ['b' => ['thin', BORDE_CELESTE]]]);
        $h->pintar($fr, $b[0], $frFin, $b[0], ['b' => ['l' => ['thin', BORDE_CELESTE]]]);
        $h->pintar($fr, $b[1], $frFin, $b[1], ['b' => ['r' => ['thin', BORDE_CELESTE]]]);
    }
    $t = fu(6.8, ['b' => true, 'c' => AZUL]) + ['v' => 'bottom', 'ind' => 1];
    $h->rango($fr, $b1[0], $fr, $b1[1], 'UN SOLO PROVEEDOR', $t);
    $h->rango($fr + 1, $b1[0], $fr + 1, $b1[1], ['f' => "IF(COUNT({$tl})=0,\"\",{$quien})"], fu(10.5, ['b' => true, 'c' => MARINO]) + ['v' => 'center', 'ind' => 1]);
    $h->rango($fr + 2, $b1[0], $fr + 2, $b1[1], ['f' => "IF(COUNT({$tl})=0,\"\",{$minimo})"], mono(12, ['b' => true, 'c' => MARINO]) + ['v' => 'center', 'ind' => 1, 'nf' => NF_LPS, 'h' => 'left']);
    $h->rango($fr + 3, $b1[0], $fr + 4, $b1[1], ['f' => "IF(COUNT({$tl})=0,\"\",IF({$hayCompletos},\"La oferta más baja entre los que cotizaron todos los artículos.\",\"Ningún proveedor cotizó todo: es la oferta más baja de las recibidas.\"))"],
        fu(7, ['c' => GRIS]) + ['v' => 'top', 'ind' => 1, 'wrap' => true]);
    $sumaArt = 'SUM(' . $col($aux) . ')';
    $h->rango($fr, $b2[0], $fr, $b2[1], 'COMPRA POR ARTÍCULO (MEJOR PRECIO DE CADA UNO)', $t);
    $h->rango($fr + 1, $b2[0], $fr + 2, $b2[0] + 6, ['f' => "IF(COUNT({$col($aux)})=0,\"\",{$sumaArt})"], mono(12, ['b' => true, 'c' => MARINO]) + ['v' => 'center', 'ind' => 1, 'nf' => NF_LPS, 'h' => 'left']);
    $h->rango($fr + 1, $b2[0] + 7, $fr + 2, $b2[1], ['f' => "IF(COUNT({$tl})=0,\"\",IF({$minimo}-{$sumaArt}>0,\"ahorra L. \"&FIXED({$minimo}-{$sumaArt},2),\"\"))"],
        fu(7, ['b' => true, 'c' => '15803D']) + ['v' => 'center', 'h' => 'left']);
    for ($k = 0; $k < $P; $k++) {
        $f = $fr + 3 + $k;
        $ganados = $col($aux + 1 + $k);
        $h->rango($f, $b2[0], $f, $b2[1] - 5, ['f' => "IF(COUNT({$ganados})=0,\"\",{$nombres[$k]}&\" — \"&COUNT({$ganados})&\" artículo(s)\")"], fu(7.5) + ['v' => 'center', 'ind' => 1, 'b' => ['b' => ['dashed', BORDE_CELESTE]]]);
        $h->rango($f, $b2[1] - 4, $f, $b2[1], ['f' => "IF(COUNT({$ganados})=0,\"\",SUM({$ganados}))"], mono(7.5) + ['h' => 'right', 'v' => 'center', 'nf' => NF_LPS, 'b' => ['b' => ['dashed', BORDE_CELESTE]]]);
    }
    $h->rango($fr + 3 + $P, $b2[0], $fr + 3 + $P, $b2[1], 'Requiere una orden de compra por cada proveedor.', fu(7, ['c' => GRIS]) + ['v' => 'center', 'ind' => 1]);

    // Proveedor seleccionado
    $d->espacio(12);
    $f1b = $d->f;
    $d->espacio(7); $fl = $d->espacio(12); $fv = $d->espacio(22); $fa2 = $d->espacio(13); $d->espacio(7);
    $h->pintar($f1b, $ini, $d->f - 1, $d->fin, ['bg' => MONTO_FONDO]);
    $h->pintar($f1b, $ini, $d->f - 1, $ini, ['b' => ['l' => ['thick', AZUL]]]);
    $h->rango($fl, $ini + 1, $fl, $ini + 14, 'PROVEEDOR SELECCIONADO', fu(8, ['b' => true, 'c' => AZUL]) + ['v' => 'bottom']);
    $sel = $d->ref($fv, $ini + 1);
    $h->rango($fv, $ini + 1, $fv, $ini + 14, null, fu(13, ['b' => true, 'c' => MARINO]) + ['v' => 'center', 'bg' => BLANCO, 'b' => ['b' => ['thin', FIRMA]]]);
    $monto = '""';
    for ($k = $P - 1; $k >= 0; $k--) $monto = "IF({$sel}={$nombres[$k]},{$totales[$k]},{$monto})";
    $h->rango($fv, $ini + 15, $fa2, $d->fin - 1, ['f' => "IF({$sel}=\"\",\"\",{$monto})"], mono(20, ['b' => true, 'c' => MARINO]) + ['h' => 'right', 'v' => 'center', 'nf' => NF_LPS]);
    $h->rango($fa2, $ini + 1, $fa2, $ini + 14, ['f' => "IF(OR({$sel}=\"\",COUNT({$tl})=0),\"\",IF({$sel}<>{$quien},\"No es el proveedor único recomendado: ver la justificación.\",\"\"))"],
        fu(8, ['c' => AMBAR]) + ['v' => 'center']);
    $d->espacio(4);
    $d->meta([['l' => 'Justificación de la selección', 's' => 'full', 'alto' => 40]]);
    $d->firmas(FLUJO);
    $d->pie();
}

// ═════════════════════════ GASTOS Y VIÁTICOS ═════════════════════════

function hojaGV(Formato $d, bool $compra): void {
    $d->encabezado();
    $m = $d->meta(array_values(array_filter([
        ['l' => 'Empleado / Consultor'],
        ['l' => $compra ? 'Concepto / Insumo a Comprar' : 'Destino'],
        ['l' => $compra ? 'Fecha del Anticipo' : 'Fecha de Salida', 'k' => 'fecha'],
        ['l' => $compra ? 'Fecha Límite de Liquidación' : 'Fecha de Regreso', 'k' => 'fecha'],
        ['l' => 'Proyecto'],
        ['l' => 'Monto del Anticipo', 'k' => 'monto', 'id' => 'monto'],
        $compra ? null : ['l' => 'Alimentación', 'k' => 'monto'],
        ['l' => $compra ? 'Justificación de la Compra' : 'Propósito del Viaje', 's' => 'full', 'alto' => 28],
    ])));
    $d->seccion('Desglose del Anticipo Solicitado');
    $t = $d->tabla([
        ['t' => '#', 'w' => 1, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Descripción del Gasto', 'w' => 11, 'k' => 'desc'],
        ['t' => 'Cuenta Contable', 'w' => 7, 'k' => 'cta', 'e' => fu(7.5, ['c' => GRIS])],
        ['t' => 'Monto', 'w' => 5, 'k' => 'monto', 'tipo' => 'monto'],
    ], 8, [['l' => 'Total Anticipo Solicitado', 'desde' => 'monto', 'v' => ['monto' => 'sum'], 'final' => true,
            'le' => fu(9.5, ['b' => true, 'c' => MARINO]), 've' => ['monto' => ['f' => ['c' => VERDE]]]]]);
    $d->ponerRef($m['monto'], ['f' => "IF(COUNT({$t['rango']('monto')})=0,\"\",{$t['celda']('monto', $t['pie'][0])})"]);
    $d->firmas(['Solicitado por', 'Revisado por', 'Autorizado por']);
    $d->pie();
}

/** Voucher de alimentación: del anticipo (gva) o segunda página de la liquidación (sg) */
function hojaAlimentacion(Formato $d, bool $deLiquidacion): void {
    $d->encabezado([$deLiquidacion ? 'Viáticos de Viaje' : '__/__/____ – __/__/____'], false, 13);
    $d->meta($deLiquidacion ? [
        ['l' => 'Empleado / Consultor', 's' => 2, 'alto' => 20, 'e' => fu(12, ['b' => true, 'c' => MARINO])],
        ['l' => 'N° Identidad / RTN', 'k' => 'mono'], ['l' => 'Cargo'],
        ['l' => 'Ref. Anticipo de Viaje', 'k' => 'mono'], ['l' => 'Destino'],
        ['l' => 'Fecha de Salida', 'k' => 'fecha'], ['l' => 'Fecha de Regreso', 'k' => 'fecha'],
    ] : [
        ['l' => 'Empleado / Consultor', 's' => 2, 'alto' => 20, 'e' => fu(12, ['b' => true, 'c' => MARINO])],
        ['l' => 'N° Identidad / RTN', 'k' => 'mono'], ['l' => 'Cargo'],
        ['l' => 'Destino'], ['l' => 'Fecha de Salida', 'k' => 'fecha'], ['l' => 'Fecha de Regreso', 'k' => 'fecha'], ['l' => 'Proyecto'],
        ['l' => 'Banco'], ['l' => 'No. Cuenta', 'k' => 'mono'],
    ], 4, CELESTE, BORDE_CELESTE);
    $fuerte = ['le' => fu(9, ['b' => true, 'c' => MARINO]), 've' => ['f' => ['b' => true, 'c' => MARINO]]];
    if ($deLiquidacion) {
        $d->seccion('Detalle de Alimentación');
        $d->detalle([['l' => 'Concepto', 'v' => 'Viáticos de Alimentación por comisión a ' . LINEA_LARGA, 'k' => 'texto', 'fondo' => CELESTE, 'borde' => ['medium', LINEA]] + $fuerte], $d->ini + 6);
        $valor = null;
    } else {
        $d->seccion('Detalle del Beneficio');
        $r = $d->detalle([
            ['l' => 'Anticipo de Viaje de Referencia', 'v' => ['f' => $d->refNumero], 'k' => 'mono'],
            ['l' => 'Viáticos de Alimentación', 'k' => 'monto', 'fondo' => CELESTE, 'id' => 'alim'] + $fuerte,
        ]);
        $valor = ['f' => "IF({$r['alim']}=\"\",\"\",{$r['alim']})"];
    }
    $d->monto($deLiquidacion ? 'Monto de Alimentación' : 'Monto de Alimentación a Pagar', $valor, null, 10);
    $b = ['b' => true];
    $d->recibo($deLiquidacion ? [
        ['Yo, ', []], [LINEA_LARGA, $b], [', portador/a de la Tarjeta de Identidad número (o con RTN) ', []], [LINEA_CORTA, $b],
        [', declaro haber recibido la suma de ', []], ['L. ' . LINEA_CORTA, $b], [' en concepto de ', []], ['viáticos de alimentación', ['i' => true]],
        [' correspondientes a la comisión a ', []], [LINEA_CORTA, $b], [', del __/__/____ al __/__/____, en conformidad con lo indicado.', []],
    ] : [
        ['Yo, ', []], [LINEA_LARGA, $b], [', portador/a de la Tarjeta de Identidad número (o con RTN) ', []], [LINEA_CORTA, $b],
        [', declaro haber recibido la suma de ', []], ['L. ' . LINEA_CORTA, $b], [' en concepto de ', []], ['viáticos de alimentación', ['i' => true]],
        [' correspondientes al viaje a ', []], [LINEA_CORTA, $b], [' (__/__/____ – __/__/____), según anticipo ', []], [num('gv'), $b], ['.', []],
    ], 'Firma y nombre del empleado o beneficiario');
    $d->pie(['f' => '"' . ($deLiquidacion ? 'VOUCHER ALIMENTACIÓN' : 'ALIMENTACIÓN') . ' — "&' . $d->refNumero]);
}

function hojaSG(Formato $d, bool $liquidacion): void {
    $d->encabezado();
    if ($liquidacion) $d->cadena(['✈ ' . num('gv'), actual($d, '📄')]);
    $d->meta(array_values(array_filter([
        ['l' => 'Fecha', 'k' => 'fecha'], ['l' => 'Empleado / Consultor'], ['l' => 'Proyecto'],
        $liquidacion ? ['l' => 'Anticipo de Viaje', 'k' => 'mono'] : null,
        ['l' => 'Descripción', 's' => 'full', 'alto' => 28],
        ['l' => 'Notas / Justificación', 's' => 'full', 'alto' => 28],
    ])));
    $d->seccion('Detalle de Gastos');
    $pie = [['l' => 'TOTAL GASTOS', 'desde' => 'monto', 'v' => ['monto' => 'sum'], 'final' => !$liquidacion]];
    if ($liquidacion) {
        $pie[] = ['l' => 'Anticipo de Viaje Recibido', 'desde' => 'monto', 'v' => ['monto' => null]];
        $pie[] = ['l' => null, 'desde' => 'monto', 'v' => ['monto' => null], 'final' => true, 'le' => fu(9.5, ['b' => true, 'c' => MARINO])];
    }
    $t = $d->tabla([
        ['t' => '#', 'w' => 1, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Fecha', 'w' => 3, 'k' => 'fecha', 'tipo' => 'fecha'],
        ['t' => 'Descripción', 'w' => 9, 'k' => 'desc'],
        ['t' => 'Cuenta Contable', 'w' => 6, 'k' => 'cta', 'e' => fu(7.5, ['c' => GRIS])],
        ['t' => 'Monto', 'w' => 5, 'k' => 'monto', 'tipo' => 'monto'],
    ], 12, $pie);
    if ($liquidacion) {
        [$tot, $ant, $dif] = array_map(fn($f) => $t['celda']('monto', $f), $t['pie']);
        $d->ponerRef($d->ref($t['pie'][2], $d->ini), ['f' => "IF({$tot}<=N({$ant}),\"Saldo a Reintegrar\",\"Diferencia a Pagar\")"]);
        $d->ponerRef($dif, ['f' => "ABS({$tot}-N({$ant}))"]);
        $d->h->condicional($dif, "{$tot}<=N({$ant})", $d->dxf(['c' => VERDE]));
        $d->h->condicional($dif, "{$tot}>N({$ant})", $d->dxf(['c' => ROJO_DED]));
    }
    $d->firmas(['Solicitado por', 'Revisado por', 'Autorizado por']);
    $d->pie();
}

function hojaCC(Formato $d): void {
    $d->encabezado();
    $m = $d->meta([
        ['l' => 'Responsable', 's' => 2, 'alto' => 20, 'e' => fu(11, ['b' => true, 'c' => MARINO])],
        ['l' => 'N° Identidad', 'k' => 'mono'], ['l' => 'Estado', 'k' => 'lista', 'op' => ['ABIERTA', 'LIQUIDADA'], 'v' => 'ABIERTA'],
        ['l' => 'Cargo'], ['l' => 'Fecha de Apertura', 'k' => 'fecha'], ['l' => 'Fecha de Cierre', 'k' => 'fecha'],
        ['l' => 'Anticipo', 'k' => 'monto', 'id' => 'ant', 'e' => mono(10, ['b' => true])],
        ['l' => 'Total Gastos', 'k' => 'monto', 'id' => 'tot', 'e' => mono(10, ['b' => true, 'c' => 'D97706'])],
        ['l' => 'Liquidado por', 's' => 'full'],
        ['l' => 'Notas', 's' => 'full', 'alto' => 28],
    ]);
    $d->seccion('Detalle de Gastos');
    $t = $d->tabla([
        ['t' => '#', 'w' => 1, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Fecha', 'w' => 3, 'k' => 'fecha', 'tipo' => 'fecha'],
        ['t' => 'Cuenta Contable', 'w' => 4, 'k' => 'cta', 'e' => fu(7.5, ['c' => GRIS])],
        ['t' => 'Descripción', 'w' => 5, 'k' => 'desc'],
        ['t' => 'Cant.', 'w' => 2, 'k' => 'cant', 'tipo' => 'num'],
        ['t' => 'P. Unit.', 'w' => 4, 'k' => 'pu', 'tipo' => 'monto'],
        ['t' => 'Monto', 'w' => 5, 'k' => 'monto', 'tipo' => 'monto', 'f' => fn($r) => porCantidad($r('cant'), $r('pu')), 'e' => ['f' => ['b' => true]]],
    ], 12, [['l' => 'TOTAL GASTOS', 'desde' => 'monto', 'v' => ['monto' => 'sum'], 'final' => true, 'le' => fu(9.5, ['b' => true, 'c' => MARINO])]]);
    $total = $t['celda']('monto', $t['pie'][0]);
    $d->ponerRef($m['tot'], ['f' => $total]);

    $d->seccion('Resumen por Cuenta Contable');
    $c1 = $d->fin - 12;
    $r = $d->tabla([
        ['t' => 'Cuenta Contable', 'w' => 8, 'k' => 'cta'],
        ['t' => 'Subtotal', 'w' => 5, 'k' => 'sub', 'tipo' => 'monto',
         'f' => fn($x) => "IF({$x('cta')}=\"\",\"\",SUMIF({$t['rango']('cta')},{$x('cta')},{$t['rango']('monto')}))"],
    ], 6, [['l' => 'TOTAL GASTOS', 'desde' => 'sub', 'v' => ['sub' => ['f' => $total]], 'final' => true, 'le' => fu(9.5, ['b' => true, 'c' => MARINO]) + ['h' => 'left']]], ['c1' => $c1]);
    // Cuadro de saldo
    $d->espacio(8);
    $cs = $d->fin - 10;
    $borde = ['medium', AZUL];
    $filas = [['Anticipo recibido', ['f' => "N({$m['ant']})"], ['bg' => CELESTE], fu(9, ['b' => true, 'c' => AZUL])],
              ['Total de gastos', ['f' => $total], [], fu(9, ['b' => true])],
              [['f' => "IF(N({$m['ant']})-{$total}<0,\"Reembolso a recibir\",\"Saldo a devolver\")"], ['f' => "ABS(N({$m['ant']})-{$total})"], ['bg' => MARINO], fu(9, ['b' => true, 'c' => BLANCO])]];
    foreach ($filas as $i => [$etq, $v, $fondo, $fuente]) {
        $f = $d->espacio(19);
        $bordes = ['l' => $borde, 'r' => $borde] + ($i === 0 ? ['t' => $borde] : []) + ($i === 2 ? ['b' => $borde] : []);
        $d->h->pintar($f, $cs, $f, $d->fin, $fondo);
        $d->h->rango($f, $cs, $f, $cs + 5, $etq, $fuente + ['v' => 'center', 'ind' => 1, 'b' => array_diff_key($bordes, ['r' => 1])]);
        $d->h->rango($f, $cs + 6, $f, $d->fin, $v, mono(9, ['b' => $i === 2, 'c' => $i === 2 ? BLANCO : TINTA]) + ['h' => 'right', 'v' => 'center', 'nf' => NF_LPS, 'b' => array_diff_key($bordes, ['l' => 1])]);
    }
    $d->firmas(['Responsable de Caja Chica', 'Administración / Contabilidad', 'Dirección Ejecutiva']);
    $d->pie();
}

// ═════════════════════════════ PLANILLAS ═════════════════════════════

/** Datos de una planilla de sueldos: período, quincena (o tipo) y fecha de pago */
function metaPlanilla(Formato $d, string $ultimo, string $id): array {
    return $d->meta([
        ['l' => 'Período'],
        ['l' => ['f' => "IF({$d->refTitulo}=\"PLANILLA MENSUAL\",\"TIPO\",\"QUINCENA\")"], 'k' => 'lista', 'op' => ['1ª (1-15)', '2ª (16-31)', 'Mensual'], 'v' => '1ª (1-15)'],
        ['l' => 'Fecha de Pago', 'k' => 'fecha'],
        ['l' => $ultimo, 'k' => 'ent', 'id' => $id],
    ]);
}
function rojo(bool $b = false): array { return ['f' => ['c' => ROJO_DED, 'b' => $b]]; }
function verde(bool $b = true): array { return ['f' => ['c' => VERDE, 'b' => $b]]; }
function ambar(): array { return ['f' => ['c' => AMBAR]]; }

function hojaPL(Formato $d): void {
    $d->encabezado();
    $m = metaPlanilla($d, 'No. Empleados', 'emps');
    $d->seccion('Detalle por Empleado');
    $t = $d->tabla([
        ['t' => '#', 'w' => 2, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Empleado', 'w' => 6, 'k' => 'emp'],
        ['t' => 'Cargo', 'w' => 3, 'k' => 'cargo', 'e' => fu(7, ['c' => GRIS])],
        ['t' => 'Q. Bruta', 'w' => 4, 'k' => 'bruta', 'tipo' => 'monto'],
        ['t' => 'IHSS', 'w' => 4, 'k' => 'ihss', 'tipo' => 'monto', 'e' => rojo()],
        ['t' => 'RAP', 'w' => 4, 'k' => 'rap', 'tipo' => 'monto', 'e' => rojo()],
        ['t' => 'ISR', 'w' => 4, 'k' => 'isr', 'tipo' => 'monto', 'e' => rojo()],
        ['t' => 'Credimpulsa', 'w' => 4, 'k' => 'cred', 'tipo' => 'monto', 'e' => rojo()],
        ['t' => 'Ded. Total', 'w' => 4, 'k' => 'ded', 'tipo' => 'monto', 'e' => rojo(true),
         'f' => fn($r) => "IF(COUNT({$r('ihss')}:{$r('cred')})=0,\"\",SUM({$r('ihss')}:{$r('cred')}))"],
        ['t' => 'Neto', 'w' => 4, 'k' => 'neto', 'tipo' => 'monto', 'e' => verde(), 'f' => fn($r) => "IF({$r('bruta')}=\"\",\"\",{$r('bruta')}-SUM({$r('ded')}))"],
        ['t' => 'IHSS Pat.', 'w' => 4, 'k' => 'ihssp', 'tipo' => 'monto', 'e' => ambar()],
        ['t' => 'RAP Pat.', 'w' => 4, 'k' => 'rapp', 'tipo' => 'monto', 'e' => ambar()],
        ['t' => 'Obs.', 'w' => 3, 'k' => 'obs', 'e' => fu(7, ['c' => TEXTO2])],
        ['t' => 'Banco / Cuenta', 'w' => 6, 'k' => 'banco', 'e' => fu(7)],
    ], 15, [['l' => 'TOTALES', 'desde' => 'bruta', 'final' => true, 'le' => fu(7.5, ['b' => true, 'c' => MARINO]) + ['h' => 'left'],
             'v' => array_fill_keys(['bruta', 'ihss', 'rap', 'isr', 'cred', 'ded', 'neto', 'ihssp', 'rapp'], 'sum'),
             've' => ['ihss' => rojo(), 'rap' => rojo(), 'isr' => rojo(), 'cred' => rojo(), 'ded' => rojo(true), 'neto' => verde(), 'ihssp' => ambar(), 'rapp' => ambar()]]],
        ['sz' => 7.5, 'szCab' => 6.5, 'szPie' => 7.5]);
    $d->ponerRef($m['emps'], ['f' => "COUNTA({$t['rango']('emp')})"]);
    $d->firmas(FLUJO, 15);
    $d->pie();
}

function hojaPLP(Formato $d): void {
    $P = 3;
    $d->encabezado();
    $m = metaPlanilla($d, 'No. Proyectos', 'proys');
    $d->seccion('Resumen por Proyecto');
    $kRes = ['bruto', 'ded', 'neto', 'ihssp', 'rapp', 'costo'];
    $res = $d->tabla([
        ['t' => 'Proyecto', 'w' => 21, 'k' => 'proy'],
        ['t' => 'Empleados', 'w' => 5, 'k' => 'emps', 'tipo' => 'ent'],
        ['t' => 'Bruto', 'w' => 5, 'k' => 'bruto', 'tipo' => 'monto'],
        ['t' => 'Deducciones', 'w' => 5, 'k' => 'ded', 'tipo' => 'monto', 'e' => rojo()],
        ['t' => 'Neto a Pagar', 'w' => 5, 'k' => 'neto', 'tipo' => 'monto', 'e' => verde()],
        ['t' => 'IHSS Pat.', 'w' => 5, 'k' => 'ihssp', 'tipo' => 'monto', 'e' => ambar()],
        ['t' => 'RAP Pat.', 'w' => 5, 'k' => 'rapp', 'tipo' => 'monto', 'e' => ambar()],
        ['t' => 'Costo Total', 'w' => 5, 'k' => 'costo', 'tipo' => 'monto', 'e' => ['f' => ['b' => true]]],
    ], $P, [['l' => 'TOTALES', 'desde' => 'bruto', 'final' => true, 'le' => fu(8, ['b' => true, 'c' => MARINO]) + ['h' => 'left'],
             'v' => array_fill_keys($kRes, 'sum'),
             've' => ['ded' => rojo(), 'neto' => verde(false), 'ihssp' => ambar(), 'rapp' => ambar()]]], ['sz' => 8]);
    $d->ponerRef($m['proys'], ['f' => "COUNTA({$res['rango']('proy')})"]);

    for ($p = 0; $p < $P; $p++) {
        $fs = $d->seccion('Proyecto ' . ($p + 1) . ' — código y nombre', 9);
        $t = $d->tabla([
            ['t' => '#', 'w' => 2, 'k' => 'n', 'tipo' => 'n'],
            ['t' => 'Empleado', 'w' => 8, 'k' => 'emp'],
            ['t' => 'Cargo', 'w' => 6, 'k' => 'cargo', 'e' => fu(7, ['c' => GRIS])],
            ['t' => '% Sal.', 'w' => 3, 'k' => 'pcts', 'tipo' => 'pct'],
            ['t' => 'Bruto', 'w' => 6, 'k' => 'bruto', 'tipo' => 'monto'],
            ['t' => 'Deducciones', 'w' => 6, 'k' => 'ded', 'tipo' => 'monto', 'e' => rojo()],
            ['t' => 'Neto', 'w' => 6, 'k' => 'neto', 'tipo' => 'monto', 'e' => verde(), 'f' => fn($r) => "IF({$r('bruto')}=\"\",\"\",{$r('bruto')}-N({$r('ded')}))"],
            ['t' => '% Pat.', 'w' => 3, 'k' => 'pctp', 'tipo' => 'pct'],
            ['t' => 'IHSS Pat.', 'w' => 5, 'k' => 'ihssp', 'tipo' => 'monto', 'e' => ambar()],
            ['t' => 'RAP Pat.', 'w' => 5, 'k' => 'rapp', 'tipo' => 'monto', 'e' => ambar()],
            ['t' => 'Costo Total', 'w' => 6, 'k' => 'costo', 'tipo' => 'monto', 'e' => ['f' => ['b' => true]],
             'f' => fn($r) => "IF({$r('bruto')}=\"\",\"\",{$r('bruto')}+N({$r('ihssp')})+N({$r('rapp')}))"],
        ], 6, [['l' => 'SUBTOTAL', 'desde' => 'bruto', 'final' => true, 'le' => fu(7.5, ['b' => true, 'c' => MARINO]) + ['h' => 'left'],
                'v' => array_fill_keys($kRes, 'sum'), 've' => ['ded' => rojo(), 'neto' => verde(false), 'ihssp' => ambar(), 'rapp' => ambar()]]],
            ['sz' => 7.5, 'szCab' => 7]);
        // El resumen toma el nombre y los subtotales de cada proyecto
        $fr = $res['f1'] + $p;
        $d->ponerRef($res['celda']('proy', $fr), ['f' => $d->ref($fs, $d->ini)]);
        $d->ponerRef($res['celda']('emps', $fr), ['f' => "COUNTA({$t['rango']('emp')})"]);
        foreach ($kRes as $k) $d->ponerRef($res['celda']($k, $fr), ['f' => $t['celda']($k, $t['pie'][0])]);
    }
    $d->firmas(FLUJO, 15);
    $d->pie();
    $d->h->unaPagina = false;
}

function hojaPLC(Formato $d): void {
    $d->encabezado();
    $m = $d->meta([
        ['l' => 'Período', 'id' => 'per'],
        ['l' => 'Quincena', 'k' => 'lista', 'op' => ['1ª (1-15)', '2ª (16-31)', 'Mensual'], 'v' => '1ª (1-15)', 'id' => 'q'],
        ['l' => 'Fecha de Pago', 'k' => 'fecha'],
        ['l' => 'Empleados con Deducción', 'k' => 'ent', 'id' => 'emps'],
    ]);
    $d->seccion('Detalle de Deducciones Credimpulsa');
    $t = $d->tabla([
        ['t' => '#', 'w' => 1, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Empleado', 'w' => 8, 'k' => 'emp'],
        ['t' => 'N° Identidad', 'w' => 5, 'k' => 'id', 'tipo' => 'mono', 'a' => 'c', 'e' => mono(8)],
        ['t' => 'Cargo', 'w' => 5, 'k' => 'cargo', 'e' => fu(7.5, ['c' => GRIS])],
        ['t' => 'Deducción Credimpulsa', 'w' => 5, 'k' => 'cred', 'tipo' => 'monto', 'e' => rojo(true)],
    ], 12, [['l' => 'TOTAL CREDIMPULSA', 'desde' => 'cred', 'v' => ['cred' => 'sum'], 'final' => true, 'le' => fu(9.5, ['b' => true, 'c' => MARINO]),
             've' => ['cred' => mono(10, ['b' => true, 'c' => ROJO_DED])]]]);
    $d->ponerRef($m['emps'], ['f' => "COUNTA({$t['rango']('emp')})"]);
    $d->monto('Total Deducido Credimpulsa', ['f' => $t['celda']('cred', $t['pie'][0])],
        ['Período', ['f' => "{$d->refNumero}&\" — \"&{$m['per']}&\" — \"&{$m['q']}"]], 12);
    $d->firmas(FLUJO, 15);
    $d->pie();
}

function hojaPLV(Formato $d): void {
    $d->encabezado(['1ª / 2ª Quincena'], false, 13, '__________ 20__');
    $m = $d->meta([
        ['l' => 'Empleado', 's' => 2, 'alto' => 20, 'e' => fu(12, ['b' => true, 'c' => MARINO]), 'id' => 'emp'],
        ['l' => 'N° Identidad', 'k' => 'mono'], ['l' => 'Cargo'],
        ['l' => 'Fecha de Pago', 'k' => 'fecha'], ['l' => 'Banco'], ['l' => 'No. Cuenta', 'k' => 'mono'],
    ], 4, CELESTE, BORDE_CELESTE);
    $d->seccion('Detalle de Pago');
    $ded = ['le' => fu(8.5, ['c' => GRIS]) + ['ind' => 3], 'k' => 'neg', 'alto' => 19];
    $r = $d->detalle([
        ['l' => 'Sueldo Mensual Base'],
        ['l' => ['f' => "IF({$d->refTitulo}=\"RECIBO DE PAGO MENSUAL\",\"Sueldo Bruto\",\"Quincena Bruta\")"], 'id' => 'bruta', 'fondo' => CELESTE, 'borde' => ['medium', LINEA],
         'le' => fu(9, ['b' => true, 'c' => MARINO]), 've' => ['f' => ['b' => true, 'c' => MARINO]], 'alto' => 21],
        ['l' => 'IHSS (Empleado)', 'id' => 'ihss'] + $ded,
        ['l' => 'RAP (Empleado)', 'id' => 'rap'] + $ded,
        ['l' => 'ISR', 'id' => 'isr'] + $ded,
        ['l' => 'Credimpulsa', 'id' => 'cred'] + $ded,
        ['l' => 'Total Deducciones', 'id' => 'ded', 'k' => 'neg', 'borde' => ['medium', ROJO_DED],
         'le' => fu(9, ['b' => true, 'c' => '991B1B']), 've' => ['f' => ['b' => true, 'sz' => 9]]],
    ]);
    $d->ponerRef($r['ded'], ['f' => "IF(COUNT({$r['ihss']},{$r['rap']},{$r['isr']},{$r['cred']})=0,\"\",SUM({$r['ihss']},{$r['rap']},{$r['isr']},{$r['cred']}))"]);
    $neto = "IF({$r['bruta']}=\"\",\"\",{$r['bruta']}-N({$r['ded']}))";
    $d->distribucion(['pct' => '%', 'bruta' => 'QUINCENA BRUTA', 'ded' => 'DEDUCCIONES', 'neto' => 'NETO'],
        ['bruta' => "N({$r['bruta']})", 'ded' => "N({$r['ded']})", 'neto' => "N({$r['bruta']})-N({$r['ded']})"]);
    $d->monto('Total a Pagar', ['f' => $neto], null, 10);
    // Observación
    $d->espacio(9);
    $fo = $d->espacio(13); $fv = $d->espacio(17);
    $d->h->pintar($fo, $d->ini, $fv, $d->fin, ['bg' => 'F0F7FF']);
    $d->h->pintar($fo, $d->ini, $fv, $d->ini, ['b' => ['l' => ['medium', AZUL]]]);
    $d->h->rango($fo, $d->ini + 1, $fo, $d->fin - 1, 'OBSERVACIÓN', fu(7.5, ['b' => true, 'c' => AZUL]) + ['v' => 'bottom']);
    $d->h->rango($fv, $d->ini + 1, $fv, $d->fin - 1, null, fu(8.5, ['c' => TEXTO2]) + ['v' => 'center']);
    $b = ['b' => true];
    $d->recibo([['Yo, ', []], [LINEA_LARGA, $b], [', portador/a de la Tarjeta de Identidad número ', []], [LINEA_CORTA, $b],
        [', declaro haber recibido la suma de ', []], ['L. ' . LINEA_CORTA, $b], [' correspondiente a la ', []], ['__________ quincena', []],
        [' del período ', []], ['__________ 20__', $b], [', en conformidad con el detalle y la distribución por proyecto indicados.', []]]);
    $d->pie(['f' => "\"RECIBO — \"&{$m['emp']}"]);
}

function hojaPA(Formato $d): void {
    $d->encabezado();
    $d->meta([['l' => 'Período'], ['l' => 'Descripción', 's' => 3], ['l' => 'Proyecto']]);
    $d->seccion('Detalle por Empleado');
    $d->tabla([
        ['t' => '#', 'w' => 1, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Empleado', 'w' => 5, 'k' => 'emp'],
        ['t' => 'Cargo', 'w' => 3, 'k' => 'cargo', 'e' => fu(7.5, ['c' => GRIS])],
        ['t' => 'Concepto de Asignación', 'w' => 5, 'k' => 'con'],
        ['t' => 'Monto', 'w' => 5, 'k' => 'monto', 'tipo' => 'monto'],
        ['t' => 'Banco / Cuenta', 'w' => 5, 'k' => 'banco', 'e' => fu(7.5)],
    ], 12, [['l' => 'TOTAL ASIGNADO', 'desde' => 'monto', 'v' => ['monto' => 'sum'], 'final' => true]]);
    $d->firmas(FLUJO);
    $d->pie();
}

function hojaPAV(Formato $d): void {
    $d->encabezado(['Período'], false, 13);
    $m = $d->meta([
        ['l' => 'Empleado', 's' => 2, 'alto' => 20, 'e' => fu(12, ['b' => true, 'c' => MARINO]), 'id' => 'emp'],
        ['l' => 'N° Identidad', 'k' => 'mono'], ['l' => 'Cargo'],
        ['l' => 'Proyecto'], ['l' => 'Banco'], ['l' => 'No. Cuenta', 'k' => 'mono'],
        ['l' => 'Descripción', 's' => 'full'],
    ], 4, CELESTE, BORDE_CELESTE);
    $d->seccion('Detalle de Asignación');
    $r = $d->detalle([['l' => 'Concepto', 'k' => 'texto', 'fondo' => CELESTE, 'borde' => ['medium', LINEA], 'id' => 'con',
                       'le' => fu(9, ['b' => true, 'c' => MARINO]), 've' => ['f' => ['b' => true, 'c' => MARINO]]]], $d->ini + 7);
    $d->monto('Monto Asignado', null, null, 10);
    $b = ['b' => true];
    $d->recibo([['Yo, ', []], [LINEA_LARGA, $b], [', portador/a de la Tarjeta de Identidad número ', []], [LINEA_CORTA, $b],
        [', declaro haber recibido la suma de ', []], ['L. ' . LINEA_CORTA, $b], [' en concepto de ', []], [LINEA_LARGA, ['i' => true]],
        [', en conformidad con lo indicado.', []]]);
    $d->pie(['f' => "\"ASIGNACIÓN \"&{$d->refNumero}&\" — \"&{$m['emp']}"]);
}

const TIPOS_BENEFICIO = ['Décimo Cuarto Mes', 'Décimo Tercer Mes'];

function hojaPB(Formato $d, string $refTipo): array {
    $d->encabezado();
    $m = $d->meta([
        ['l' => 'Tipo', 'k' => 'lista', 'op' => TIPOS_BENEFICIO, 'v' => TIPOS_BENEFICIO[0], 'id' => 'tipo'],
        ['l' => 'Año', 'k' => 'ent'], ['l' => 'Fecha de Pago', 'k' => 'fecha'],
        ['l' => $refTipo === 'pbp' ? 'No. Proyectos' : 'No. Empleados', 'k' => 'ent', 'id' => 'cuenta'],
        ...($refTipo === 'pb' ? [['l' => 'Notas', 's' => 'full', 'alto' => 28]] : []),
    ]);
    return $m;
}

function hojaPBPlanilla(Formato $d): void {
    $m = hojaPB($d, 'pb');
    $d->ponerRef($d->refTitulo, ['f' => "\"PLANILLA DE BENEFICIOS — \"&UPPER({$m['tipo']})"]);
    $d->seccion('Detalle por Empleado');
    $t = $d->tabla([
        ['t' => '#', 'w' => 2, 'k' => 'n', 'tipo' => 'n'],
        ['t' => 'Empleado', 'w' => 11, 'k' => 'emp'],
        ['t' => 'Cargo', 'w' => 9, 'k' => 'cargo', 'e' => fu(7.5, ['c' => GRIS])],
        ['t' => 'Beneficio', 'w' => 8, 'k' => 'ben', 'tipo' => 'monto'],
        ['t' => 'Deducción', 'w' => 7, 'k' => 'ded', 'tipo' => 'monto', 'e' => rojo()],
        ['t' => 'Total a Pagar (50%)', 'w' => 8, 'k' => 'neto', 'tipo' => 'monto', 'e' => verde(), 'f' => fn($r) => "IF({$r('ben')}=\"\",\"\",MAX(0,{$r('ben')}-N({$r('ded')})))"],
        ['t' => 'Banco / Cuenta', 'w' => 11, 'k' => 'banco', 'e' => fu(7.5)],
    ], 15, [['l' => 'TOTALES', 'desde' => 'ben', 'final' => true, 'le' => fu(8.5, ['b' => true, 'c' => MARINO]) + ['h' => 'left'],
             'v' => ['ben' => 'sum', 'ded' => 'sum', 'neto' => 'sum'], 've' => ['ded' => rojo(), 'neto' => verde()]]]);
    // El encabezado de la columna sigue al tipo: Décimo Cuarto / Décimo Tercero
    $d->ponerRef($d->ref($t['f1'] - 1, $t['pos']['ben']), ['f' => "IF({$m['tipo']}=\"Décimo Tercer Mes\",\"Décimo Tercero\",\"Décimo Cuarto\")"]);
    $d->ponerRef($m['cuenta'], ['f' => "COUNTA({$t['rango']('emp')})"]);
    $d->firmas(FLUJO, 15);
    $d->pie();
}

function hojaPBP(Formato $d): void {
    $P = 3;
    $m = hojaPB($d, 'pbp');
    $d->ponerRef($d->refTitulo, ['f' => "UPPER({$m['tipo']})&\" POR PROYECTO\""]);
    $d->seccion('Resumen por Proyecto');
    $res = $d->tabla([
        ['t' => 'Proyecto', 'w' => 22, 'k' => 'proy'],
        ['t' => 'Empleados', 'w' => 6, 'k' => 'emps', 'tipo' => 'ent'],
        ['t' => 'Beneficio', 'w' => 10, 'k' => 'ben', 'tipo' => 'monto'],
        ['t' => 'Deducciones', 'w' => 9, 'k' => 'ded', 'tipo' => 'monto', 'e' => rojo()],
        ['t' => 'Neto a Pagar', 'w' => 9, 'k' => 'neto', 'tipo' => 'monto', 'e' => verde()],
    ], $P, [['l' => 'TOTALES', 'desde' => 'ben', 'final' => true, 'le' => fu(8.5, ['b' => true, 'c' => MARINO]) + ['h' => 'left'],
             'v' => ['ben' => 'sum', 'ded' => 'sum', 'neto' => 'sum'], 've' => ['ded' => rojo(), 'neto' => verde(false)]]]);
    $d->ponerRef($m['cuenta'], ['f' => "COUNTA({$res['rango']('proy')})"]);
    for ($p = 0; $p < $P; $p++) {
        $fs = $d->seccion('Proyecto ' . ($p + 1) . ' — código y nombre', 9);
        $t = $d->tabla([
            ['t' => '#', 'w' => 2, 'k' => 'n', 'tipo' => 'n'],
            ['t' => 'Empleado', 'w' => 13, 'k' => 'emp'],
            ['t' => 'Cargo', 'w' => 11, 'k' => 'cargo', 'e' => fu(7.5, ['c' => GRIS])],
            ['t' => '%', 'w' => 4, 'k' => 'pct', 'tipo' => 'pct'],
            ['t' => 'Beneficio', 'w' => 8, 'k' => 'ben', 'tipo' => 'monto'],
            ['t' => 'Deducción', 'w' => 8, 'k' => 'ded', 'tipo' => 'monto', 'e' => rojo()],
            ['t' => 'Neto', 'w' => 10, 'k' => 'neto', 'tipo' => 'monto', 'e' => verde(), 'f' => fn($r) => "IF({$r('ben')}=\"\",\"\",MAX(0,{$r('ben')}-N({$r('ded')})))"],
        ], 6, [['l' => 'SUBTOTAL', 'desde' => 'ben', 'final' => true, 'le' => fu(8, ['b' => true, 'c' => MARINO]) + ['h' => 'left'],
                'v' => ['ben' => 'sum', 'ded' => 'sum', 'neto' => 'sum'], 've' => ['ded' => rojo(), 'neto' => verde(false)]]], ['sz' => 8]);
        $fr = $res['f1'] + $p;
        $d->ponerRef($res['celda']('proy', $fr), ['f' => $d->ref($fs, $d->ini)]);
        $d->ponerRef($res['celda']('emps', $fr), ['f' => "COUNTA({$t['rango']('emp')})"]);
        foreach (['ben', 'ded', 'neto'] as $k) $d->ponerRef($res['celda']($k, $fr), ['f' => $t['celda']($k, $t['pie'][0])]);
    }
    $d->firmas(FLUJO, 15);
    $d->pie();
    $d->h->unaPagina = false;
}

function hojaPBV(Formato $d): void {
    $d->encabezado(['Año ____'], false, 11, TIPOS_BENEFICIO[0]);
    $d->h->lista($d->refNumero, TIPOS_BENEFICIO);
    $m = $d->meta([
        ['l' => 'Empleado', 's' => 2, 'alto' => 20, 'e' => fu(12, ['b' => true, 'c' => MARINO]), 'id' => 'emp'],
        ['l' => 'N° Identidad', 'k' => 'mono'], ['l' => 'Cargo'],
        ['l' => 'Fecha de Pago', 'k' => 'fecha'], ['l' => 'Banco'], ['l' => 'No. Cuenta', 'k' => 'mono'],
    ], 4, CELESTE, BORDE_CELESTE);
    $d->seccion('Detalle de Pago');
    $r = $d->detalle([
        ['l' => ['f' => "IF({$d->refNumero}=\"Décimo Tercer Mes\",\"Décimo Tercero\",\"Décimo Cuarto\")"], 'id' => 'ben'],
        ['l' => 'Deducción', 'k' => 'neg', 'id' => 'ded', 'le' => fu(9, ['c' => GRIS]), 've' => ['f' => ['sz' => 9]]],
        ['l' => 'Total a Pagar (50%)', 'id' => 'neto', 'fondo' => 'F0FDF4', 'borde' => ['medium', VERDE], 'alto' => 22,
         'le' => fu(9, ['b' => true, 'c' => '15803D']), 've' => ['f' => ['b' => true, 'c' => VERDE, 'sz' => 11]]],
    ]);
    $neto = "IF({$r['ben']}=\"\",\"\",MAX(0,{$r['ben']}-N({$r['ded']})))";
    $d->ponerRef($r['neto'], ['f' => $neto]);
    $d->distribucion(['pct' => '%', 'ben' => 'BENEFICIO', 'ded' => 'DEDUCCIÓN', 'neto' => 'NETO'],
        ['ben' => "N({$r['ben']})", 'ded' => "N({$r['ded']})", 'neto' => "N({$r['neto']})"]);
    $d->monto(['f' => "UPPER({$d->refNumero})"], ['f' => $neto], null, 10);
    $b = ['b' => true];
    $d->recibo([['Yo, ', []], [LINEA_LARGA, $b], [', portador/a de la Tarjeta de Identidad número ', []], [LINEA_CORTA, $b],
        [', declaro haber recibido la suma de ', []], ['L. ' . LINEA_CORTA, $b], [' correspondiente al ', []], ['Décimo ________ Mes', ['i' => true]],
        [' del año ', []], ['______', $b], [', en conformidad con lo indicado, incluida la distribución por proyecto.', []]]);
    $d->pie(['f' => "{$d->refNumero}&\" — \"&{$m['emp']}"]);
}

// ═══════════════════════════ RECURSOS HUMANOS ═══════════════════════════

const ESTADOS = ['Pendiente', 'Aprobada', 'Rechazada'];

function hojaVAC(Formato $d): void {
    $d->encabezado();
    $m = $d->meta([
        ['l' => 'Empleado'], ['l' => 'Cargo'], ['l' => 'N° Identidad', 'k' => 'mono'], ['l' => 'Fecha de Ingreso', 'k' => 'fecha'],
        ['l' => 'Tipo', 'k' => 'lista', 'op' => ['Ordinaria', 'Anticipada'], 'v' => 'Ordinaria'],
        ['l' => 'Fecha de Solicitud', 'k' => 'fecha'], ['l' => 'Fecha de Inicio', 'k' => 'fecha'], ['l' => 'Fecha de Regreso', 'k' => 'fecha'],
        ['l' => 'Días Solicitados', 'k' => 'ent', 'alto' => 22, 'e' => fu(14, ['b' => true, 'c' => MARINO])],
        ['l' => 'Estado', 'k' => 'lista', 'op' => ESTADOS, 'v' => 'Pendiente', 'id' => 'est', 'e' => fu(7.5, ['b' => true]) + ['h' => 'center']],
        ['l' => 'Aprobado por'],
        ['l' => 'Notas', 's' => 'full', 'alto' => 28],
    ]);
    $d->colorEstado($m['est']);
    $d->firmas(['Solicitado por (Empleado)', 'Revisado por', 'Autorizado por']);
    $d->pie();
}

function hojaPER(Formato $d): void {
    $tipos = ['Enfermedad / Incapacidad', 'Personal', 'Maternidad', 'Paternidad', 'Luto / Duelo', 'Capacitación', 'Otro'];
    $d->encabezado();
    $m = $d->meta([
        ['l' => 'Empleado'], ['l' => 'Cargo'],
        ['l' => 'Tipo de Permiso', 'k' => 'lista', 'op' => $tipos, 'v' => 'Personal', 'id' => 'tipo'],
        ['l' => 'Con Goce de Sueldo', 'k' => 'lista', 'op' => ['Sí', 'No'], 'v' => 'Sí'],
        ['l' => 'Fecha de Inicio', 'k' => 'fecha'], ['l' => 'Fecha de Finalización', 'k' => 'fecha'],
        ['l' => 'Días', 'k' => 'ent', 'alto' => 22, 'e' => fu(14, ['b' => true, 'c' => MARINO])],
        ['l' => 'Estado', 'k' => 'lista', 'op' => ESTADOS, 'v' => 'Pendiente', 'id' => 'est', 'e' => fu(7.5, ['b' => true]) + ['h' => 'center']],
        ['l' => 'Motivo', 's' => 'full', 'alto' => 28],
        ['l' => 'Aprobado por'],
        ['l' => 'Notas', 's' => 'full', 'alto' => 28],
    ]);
    $d->ponerRef($d->refTitulo, ['f' => "\"PERMISO — \"&UPPER({$m['tipo']})"]);
    $d->colorEstado($m['est']);
    $d->firmas(['Solicitado por (Empleado)', 'Revisado por', 'Autorizado por']);
    $d->pie();
}

function hojaLIQ(Formato $d): void {
    $motivos = ['Renuncia voluntaria', 'Despido injustificado (Art. 116 / 120 CT)', 'Despido justificado', 'Vencimiento de contrato',
                'Mutuo acuerdo entre las partes', 'Fallecimiento del trabajador'];
    $d->encabezado();
    $d->meta([
        ['l' => 'Empleado', 's' => 2, 'alto' => 20, 'e' => fu(11, ['b' => true, 'c' => MARINO])],
        ['l' => 'N° Identidad', 'k' => 'mono'], ['l' => 'Departamento'],
        ['l' => 'Cargo'], ['l' => 'Fecha de Ingreso', 'k' => 'fecha'], ['l' => 'Fecha de Salida', 'k' => 'fecha'], ['l' => 'Antigüedad'],
        ['l' => 'Motivo de Salida', 's' => 'full', 'k' => 'lista', 'op' => $motivos, 'v' => $motivos[0]],
    ]);
    $d->seccion('Conceptos de Liquidación');
    $ref = fu(8, ['c' => GRIS]);
    $t = $d->tabla([
        ['t' => 'Concepto', 'w' => 12, 'k' => 'con'],
        ['t' => 'Referencia Legal', 'w' => 7, 'k' => 'ref', 'e' => $ref],
        ['t' => 'Monto', 'w' => 5, 'k' => 'monto', 'tipo' => 'monto'],
    ], [
        ['con' => 'Sueldo Mensual', 'ref' => 'Base de cálculo', '_e' => ['bg' => CELESTE]],
        ['con' => 'Salarios Pendientes (____ días)', 'ref' => 'Días no pagados'],
        ['con' => 'Vacaciones Pendientes (____ días)', 'ref' => 'Art. 346 C.T.'],
        ['con' => 'Décimo Tercer Mes Proporcional', 'ref' => '____ meses / 12'],
        ['con' => 'Décimo Cuarto Mes Proporcional', 'ref' => '____ meses / 12'],
        ['con' => 'Preaviso', 'ref' => 'Art. 116 C.T.'],
        ['con' => 'Auxilio de Cesantía', 'ref' => 'Art. 120 C.T.'],
        ['con' => 'Otros: ________________________', 'ref' => ''],
    ], [['l' => 'TOTAL A PAGAR AL TRABAJADOR', 'desde' => 'monto', 'v' => ['monto' => null], 'final' => true, 'le' => fu(9.5, ['b' => true, 'c' => MARINO])]]);
    // El sueldo mensual es la base de cálculo: no se suma
    $d->ponerRef($t['celda']('monto', $t['pie'][0]), ['f' => 'SUM(' . $t['celda']('monto', $t['f1'] + 1) . ':' . $t['celda']('monto', $t['f2']) . ')']);
    $d->espacio(9);
    $f = $d->espacio(17);
    $d->h->poner($f, $d->ini, 'Notas:', fu(8.5, ['b' => true, 'c' => '555555']) + ['v' => 'center']);
    $d->h->rango($f, $d->ini + 2, $f, $d->fin, null, fu(8.5, ['c' => '555555']) + ['v' => 'center', 'b' => ['b' => ['thin', FIRMA]]]);
    $d->espacio(10);
    $d->parrafo('Habiendo recibido conforme el monto total indicado, el trabajador y la institución dan por concluida la relación laboral en los términos descritos en el presente documento.',
        8.5, fu(8.5, ['c' => '777777']) + ['h' => 'center'], 1, 0);
    $d->firmas(['Director Ejecutivo', 'Trabajador / Representante Legal', 'Testigo'], 21);
    $d->pie();
}

/** Encabezado de carta: lugar y fecha, destinatario, separador y referencia */
function cartaInicio(Formato $d, string $ref): void {
    global $DIR_CARTA;
    $d->espacio(18);
    $f = $d->espacio(15);
    $d->h->rango($f, $d->ini + 1, $f, $d->fin - 1, "{$DIR_CARTA}, ____ de ____________ de 20__", fu(9, ['c' => TEXTO2]) + ['h' => 'right', 'v' => 'center']);
    $d->espacio(14);
    $f = $d->espacio(17);
    $d->h->rango($f, $d->ini + 1, $f, $d->fin - 1, 'A QUIEN CORRESPONDA:', fu(10.5, ['b' => true, 'c' => MARINO]) + ['v' => 'center']);
    $d->espacio(9, ['b' => ['b' => ['medium', BORDE_CELESTE]]], $d->ini + 1, $d->fin - 1);
    $d->espacio(12);
    $f = $d->espacio(13);
    $d->h->rango($f, $d->ini + 1, $f, $d->fin - 1, 'REF.: ' . $ref, fu(8, ['b' => true, 'c' => AZUL]) + ['v' => 'center']);
    $d->espacio(10);
}

function hojaConstancia(Formato $d, string $tipo, string $titulo): void {
    $d->encabezado();
    cartaInicio($d, $titulo);
    $nom = ['____________________________________', ['b' => true]];
    $id = ['________________', ['b' => true, 'n' => 'Courier New']];
    $hl = fn(string $s) => [$s, ['b' => true, 'c' => MARINO]];
    $fecha = '____ de ____________ de ______';
    $inicio = [['Por medio de la presente se hace ', []], ['CONSTAR', ['b' => true]], [' que el/la señor/a ', []], $nom,
               [', portador/a de la Tarjeta de Identidad número ', []], $id];
    $parrafos = match ($tipo) {
        'trabajo' => [[...$inicio, [', labora en esta institución desempeñando el cargo de ', []], $hl('______________________'), [", a partir del {$fecha}.", []]]],
        'salario' => [[...$inicio, [', labora en esta institución desempeñando el cargo de ', []], $hl('______________________'),
                       [', devengando un salario mensual de ', []], $hl('L. ______________'), ['.', []]]],
        'referencia' => [
            [['En respuesta a la solicitud de referencia laboral, nos complace informar que el/la señor/a ', []], $nom,
             [', portador/a de la Tarjeta de Identidad número ', []], $id, [", labora en esta institución desde el {$fecha}, desempeñando el cargo de ", []],
             $hl('______________________'), [', con una antigüedad de ', []], $hl('____ años y ____ meses'), ['.', []]],
            [['Durante su trayectoria institucional, ha demostrado ser una persona responsable, comprometida con las funciones asignadas y de buena conducta, por lo que extendemos la presente referencia en los mejores términos.', []]],
        ],
        'tiempo' => [[...$inicio, [", labora en esta institución desde el {$fecha}, acumulando a la presente fecha ", []], $hl('____ años y ____ meses'),
                      [' de servicio en el cargo de ', []], $hl('______________________'), ['.', []]]],
    };
    $parrafos[] = [['Se extiende la presente constancia a petición de la parte interesada, para los fines que estime convenientes.', []]];
    foreach ($parrafos as $p) $d->parrafo($p, 10, [], 1, 10);
    $f = $d->espacio(16);
    $d->h->rango($f, $d->ini + 1, $f, $d->fin - 1, 'Atentamente,', fu(9.5, ['c' => TEXTO2]) + ['v' => 'center']);
    $d->firmas(['Recursos Humanos'], 14);
    $d->pie();
}

function hojaMEMO(Formato $d): void {
    global $DIR_CARTA;
    $d->encabezado();
    $d->espacio(18);
    $f = $d->espacio(15);
    $d->h->rango($f, $d->ini + 1, $f, $d->fin - 1, "{$DIR_CARTA}, ____ de ____________ de 20__", fu(9, ['c' => TEXTO2]) + ['h' => 'right', 'v' => 'center']);
    $d->espacio(14);
    $etq = fu(9.5, ['b' => true, 'c' => MARINO]) + ['v' => 'top'];
    $campo = fu(9.5) + ['v' => 'top', 'wrap' => true, 'b' => ['b' => ['thin', LINEA]]];
    foreach ([['PARA:', 30, []], ['DE:', 16, ['f' => ['b' => true]]], ['', 13, ['f' => ['sz' => 8.5, 'c' => GRIS]]], ['ASUNTO:', 18, ['f' => ['b' => true]]], ['FECHA:', 16, []]] as [$l, $alto, $e]) {
        $f = $d->espacio($alto);
        if ($l !== '') $d->h->rango($f, $d->ini + 1, $f, $d->ini + 3, $l, $etq);
        $d->h->rango($f, $d->ini + 4, $f, $d->fin - 1, $l === '' ? 'Cargo' : null,
            array_replace_recursive($campo, $e, $l === 'DE:' ? ['b' => ['b' => null]] : []));
    }
    $d->espacio(12, ['b' => ['b' => ['medium', BORDE_CELESTE]]], $d->ini + 1, $d->fin - 1);
    $d->espacio(14);
    $d->area(260, fu(10) + ['v' => 'top', 'h' => 'justify', 'b' => array_fill_keys(['t', 'b', 'l', 'r'], ['thin', LINEA])]);
    $d->espacio(14);
    $f = $d->espacio(16);
    $d->h->rango($f, $d->ini + 1, $f, $d->fin - 1, 'Atentamente,', fu(9.5, ['c' => TEXTO2]) + ['v' => 'center']);
    $d->firmas(['Remitente'], 14);
    $d->pie();
}

function hojaACTA(Formato $d): void {
    $d->encabezado();
    $d->meta([
        ['l' => 'Fecha', 'k' => 'fecha'], ['l' => 'Hora', 'k' => 'mono', 'v' => '__:__ – __:__'], ['l' => 'Lugar'], ['l' => 'Convocado por'],
        ['l' => 'Asunto', 's' => 'full', 'alto' => 20, 'e' => fu(10, ['b' => true])],
        ['l' => 'Próxima Reunión', 'k' => 'fecha'],
    ]);
    foreach (['Participantes' => 70, 'Agenda' => 70, 'Desarrollo' => 160, 'Acuerdos' => 100] as $sec => $alto) {
        $d->seccion($sec);
        $d->espacio(6);
        $d->area($alto, fu(8.5) + ['b' => array_fill_keys(['t', 'b', 'l', 'r'], ['thin', LINEA])], 0);
        $d->espacio(6);
    }
    $d->firmas(['Elaboró', 'Revisó', 'Aprobó'], 21);
    $d->pie();
    $d->h->unaPagina = false;
}

// ═════════════════════════════ GENERACIÓN ═════════════════════════════

$FORMATOS = [
    ['01 Compras - Solicitud de compra', 'SOLICITUD DE COMPRA', fn($l) => hojaSC(hoja($l, 'Solicitud de compra', 'SOLICITUD DE COMPRA', num('sc')))],
    ['02 Compras - Resumen de cotizaciones', 'RESUMEN DE COTIZACIONES', fn($l) => hojaCO(hoja($l, 'Resumen de cotizaciones', 'RESUMEN DE COTIZACIONES', num('co')))],
    ['03 Compras - Orden de compra', 'ORDEN DE COMPRA', fn($l) => hojaOC(hoja($l, 'Orden de compra', 'ORDEN DE COMPRA', num('oc')))],
    ['04 Compras - Orden de pago', 'ORDEN DE PAGO', fn($l) => hojaOP(hoja($l, 'Orden de pago', 'ORDEN DE PAGO', num('op')))],
    ['05 Compras - Proceso de compra completo', 'PROCESO DE COMPRA', function ($l) {
        $sc = hoja($l, 'Solicitud de compra', 'SOLICITUD DE COMPRA', num('sc')); hojaSC($sc);
        $oc = hoja($l, 'Orden de compra', 'ORDEN DE COMPRA', num('oc')); hojaOC($oc, $sc);
        hojaOP(hoja($l, 'Orden de pago', 'ORDEN DE PAGO', num('op')), $sc, $oc);
    }],
    ['06 Gastos - Anticipo de viáticos', 'SOLICITUD DE ANTICIPO DE VIÁTICOS', fn($l) => hojaGV(hoja($l, 'Anticipo de viáticos', 'SOLICITUD DE ANTICIPO DE VIÁTICOS', num('gv')), false)],
    ['07 Gastos - Anticipo para compra', 'SOLICITUD DE ANTICIPO PARA COMPRA', fn($l) => hojaGV(hoja($l, 'Anticipo para compra', 'SOLICITUD DE ANTICIPO PARA COMPRA', num('gv')), true)],
    ['08 Gastos - Voucher de alimentación', 'VOUCHER DE ALIMENTACIÓN', fn($l) => hojaAlimentacion(hoja($l, 'Voucher de alimentación', 'VOUCHER DE ALIMENTACIÓN', num('gv')), false)],
    ['09 Gastos - Solicitud de gastos', 'SOLICITUD DE GASTOS', fn($l) => hojaSG(hoja($l, 'Solicitud de gastos', 'SOLICITUD DE GASTOS', num('sg')), false)],
    ['10 Gastos - Liquidación de gastos', 'LIQUIDACIÓN DE GASTOS', function ($l) {
        hojaSG(hoja($l, 'Liquidación de gastos', 'LIQUIDACIÓN DE GASTOS', num('sg')), true);
        hojaAlimentacion(hoja($l, 'Voucher de alimentación', 'VOUCHER DE ALIMENTACIÓN', num('sg')), true);
    }],
    ['11 Gastos - Caja chica', 'CAJA CHICA', fn($l) => hojaCC(hoja($l, 'Caja chica', 'CAJA CHICA', num('cc')))],
    ['12 Planillas - Planilla de sueldos', 'PLANILLA QUINCENAL', fn($l) => hojaPL(hoja($l, 'Planilla', ['lista' => ['PLANILLA QUINCENAL', 'PLANILLA MENSUAL']], num('pl') . ' — __________ 20__', true))],
    ['13 Planillas - Planilla por proyecto', 'PLANILLA QUINCENAL POR PROYECTO', fn($l) => hojaPLP(hoja($l, 'Planilla por proyecto', ['lista' => ['PLANILLA QUINCENAL POR PROYECTO', 'PLANILLA MENSUAL POR PROYECTO']], num('pl') . ' — __________ 20__', true))],
    ['14 Planillas - Reporte Credimpulsa', 'REPORTE CREDIMPULSA', fn($l) => hojaPLC(hoja($l, 'Reporte Credimpulsa', 'REPORTE CREDIMPULSA', num('pl')))],
    ['15 Planillas - Recibo de quincena', 'RECIBO DE QUINCENA', fn($l) => hojaPLV(hoja($l, 'Recibo de quincena', ['lista' => ['RECIBO DE QUINCENA', 'RECIBO DE PAGO MENSUAL']], ''))],
    ['16 Planillas - Planilla de asignación', 'PLANILLA DE ASIGNACIÓN', fn($l) => hojaPA(hoja($l, 'Planilla de asignación', 'PLANILLA DE ASIGNACIÓN', num('pa')))],
    ['17 Planillas - Recibo de asignación', 'RECIBO DE ASIGNACIÓN', fn($l) => hojaPAV(hoja($l, 'Recibo de asignación', 'RECIBO DE ASIGNACIÓN', num('pa')))],
    ['18 Planillas - Planilla de beneficios', 'PLANILLA DE BENEFICIOS', fn($l) => hojaPBPlanilla(hoja($l, 'Planilla de beneficios', '', num('pb'), true))],
    ['19 Planillas - Beneficios por proyecto', 'BENEFICIOS POR PROYECTO', fn($l) => hojaPBP(hoja($l, 'Beneficios por proyecto', '', num('pb'), true))],
    ['20 Planillas - Recibo de beneficio', 'RECIBO DE BENEFICIO', fn($l) => hojaPBV(hoja($l, 'Recibo de beneficio', 'RECIBO DE BENEFICIO', ''))],
    ['21 RRHH - Solicitud de vacaciones', 'SOLICITUD DE VACACIONES', fn($l) => hojaVAC(hoja($l, 'Solicitud de vacaciones', 'SOLICITUD DE VACACIONES', num('vac')))],
    ['22 RRHH - Permiso', 'PERMISO', fn($l) => hojaPER(hoja($l, 'Permiso', '', num('per')))],
    ['23 RRHH - Liquidación de personal', 'LIQUIDACIÓN DE PERSONAL', fn($l) => hojaLIQ(hoja($l, 'Liquidación de personal', 'LIQUIDACIÓN DE PERSONAL', num('liq')))],
    ['24 RRHH - Constancia de trabajo', 'CONSTANCIA DE TRABAJO', fn($l) => hojaConstancia(hoja($l, 'Constancia de trabajo', 'CONSTANCIA DE TRABAJO', '20__-____'), 'trabajo', 'CONSTANCIA DE TRABAJO')],
    ['25 RRHH - Constancia de salario', 'CONSTANCIA DE SALARIO', fn($l) => hojaConstancia(hoja($l, 'Constancia de salario', 'CONSTANCIA DE SALARIO', '20__-____'), 'salario', 'CONSTANCIA DE SALARIO')],
    ['26 RRHH - Referencia laboral', 'REFERENCIA LABORAL', fn($l) => hojaConstancia(hoja($l, 'Referencia laboral', 'REFERENCIA LABORAL', '20__-____'), 'referencia', 'REFERENCIA LABORAL')],
    ['27 RRHH - Constancia de tiempo de servicio', 'CONSTANCIA DE TIEMPO DE SERVICIO', fn($l) => hojaConstancia(hoja($l, 'Tiempo de servicio', 'CONSTANCIA DE TIEMPO DE SERVICIO', '20__-____'), 'tiempo', 'CONSTANCIA DE TIEMPO DE SERVICIO')],
    ['28 Administración - Memorándum', 'MEMORÁNDUM', fn($l) => hojaMEMO(hoja($l, 'Memorándum', ['lista' => ['MEMORÁNDUM', 'CIRCULAR', 'COMUNICADO']], num('memo')))],
    ['29 Administración - Acta de reunión', 'ACTA DE REUNIÓN', fn($l) => hojaACTA(hoja($l, 'Acta de reunión', 'ACTA DE REUNIÓN', num('acta')))],
];

$hechos = 0;
foreach ($FORMATOS as [$archivo, $titulo, $construir]) {
    $libro = new XlsxLibro("{$titulo} — Formato");
    $construir($libro);
    $libro->guardar("{$SALIDA}/{$archivo}.xlsx");
    echo "  {$archivo}.xlsx\n";
    $hechos++;
}
echo "{$hechos} formatos en {$SALIDA}\n";

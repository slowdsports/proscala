<?php
// ── Bloques de los formatos en Excel ───────────────────────────────
// Reproduce en una hoja de Excel el diseño común de los documentos impresos (templates/print/):
// barra superior, encabezado con la organización y el tipo de documento, datos generales, títulos
// de sección, tablas de detalle con fórmulas, bloque del monto, firmas, recibos y pie de página.
// La hoja es una cuadrícula de columnas angostas (26 en vertical, 58 en horizontal); la primera y
// la última son el margen lateral (28px del documento). Todo se acomoda combinando columnas.
require_once __DIR__ . '/xlsx.php';

// Colores de las plantillas de impresión (templates/print/_inicio.php)
const AZUL = '1B6BA8', MARINO = '0D3F6A', ROJO = 'E53935', TINTA = '1A1A2E', GRIS = '64748B', GRIS_CLARO = '94A3B8',
      PIZARRA = '475569', TEXTO2 = '374151', LINEA = 'E2E8F0', CLARO = 'F8FAFC', CELESTE = 'F0F5FC',
      BORDE_CELESTE = 'DBEAFE', FIRMA = 'CBD5E1', ROJO_DED = 'DC2626', VERDE = '16A34A', AMBAR = 'B45309',
      BLANCO = 'FFFFFF', MONTO_FONDO = 'E8F1FD';
const NF_LPS = '"L. "#,##0.00', NF_LPS_NEG = '"(L. "#,##0.00")"', NF_FECHA = 'dd/mm/yyyy', NF_NUM = '#,##0.00',
      NF_PCT = '0.00"%"', NF_ENT = '0';

/** Estilo de fuente (Segoe UI, como los documentos) */
function fu(float $sz, array $x = []): array {
    return ['f' => array_replace(['sz' => $sz, 'n' => 'Segoe UI', 'c' => TINTA], $x)];
}
function mono(float $sz, array $x = []): array { return fu($sz, ['n' => 'Courier New'] + $x); }
function may(string $s): string { return mb_strtoupper($s, 'UTF-8'); }   // text-transform: uppercase

final class Formato {
    public XlsxHoja $h;
    public int $f = 1;          // fila actual
    public int $n, $ini, $fin, $ancho;
    public string $refTitulo = '', $refNumero = '';
    private XlsxLibro $libro;

    /**
     * @param string|array $titulo  texto, ['lista' => [...]] (desplegable) o ['f' => fórmula]
     * @param string       $numero  número del documento (editable)
     */
    public function __construct(XlsxLibro $libro, string $hoja, public array $org, public $titulo, public string $numero, bool $horizontal = false) {
        $this->libro = $libro;
        $this->h = $libro->hoja($hoja);
        $this->h->horizontal = $horizontal;
        $this->n = $horizontal ? 58 : 26;
        $this->ini = 2;
        $this->fin = $this->n - 1;
        $this->ancho = $this->n - 2;
        for ($c = 1; $c <= $this->n; $c++) $this->h->anchos[$c] = $horizontal ? 1.9 : 3.33;
    }

    public function ref(int $f, int $c): string { return XlsxHoja::ref($f, $c); }

    /** Valor (y estilo) de una celda por su referencia ("D12") */
    public function ponerRef(string $ref, $valor, array $estilo = []): void {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        $c = 0;
        foreach (str_split($m[1]) as $l) $c = $c * 26 + ord($l) - 64;
        $this->h->poner((int)$m[2], $c, $valor, $estilo);
    }

    /** Colores del estado (como las etiquetas .doc-estado) según el valor elegido */
    public function colorEstado(string $ref): void {
        foreach (['Pendiente' => ['FFF3CD', '856404'], 'Aprobada' => ['D1E7DD', '0A3622'], 'Aprobado' => ['D1E7DD', '0A3622'],
                  'Rechazada' => ['F8D7DA', '842029'], 'Rechazado' => ['F8D7DA', '842029'], 'Anulada' => ['E2E3E5', '41464B']] as $v => [$bg, $c]) {
            $this->h->condicional($ref, "{$ref}=\"{$v}\"", $this->dxf(['bg' => $bg, 'c' => $c, 'b' => true]));
        }
    }
    public function dxf(array $d): int { return $this->libro->estilos->dxf($d); }

    /** Fila vacía de cierto alto, con un estilo opcional en todo el ancho */
    public function espacio(float $pt, array $estilo = [], ?int $c1 = null, ?int $c2 = null): int {
        $this->h->altos[$this->f] = $pt;
        if ($estilo) $this->h->pintar($this->f, $c1 ?? 1, $this->f, $c2 ?? $this->n, $estilo);
        return $this->f++;
    }

    /** Barra de color con degradado (superior: azul → marino → rojo; inferior: rojo → azul) */
    public function barra(array $paradas, float $pt): void {
        $this->h->altos[$this->f] = $pt;
        $this->h->rango($this->f, 1, $this->f, $this->n, null, ['bg' => ['grad' => $paradas, 'deg' => 0]]);
        $this->f++;
    }

    private function valorTitulo($t, int $f, int $c): mixed {
        if (is_array($t) && isset($t['lista'])) {
            $this->h->lista($this->ref($f, $c), $t['lista']);
            return $t['lista'][0];
        }
        return $t;
    }

    // ── Encabezado: organización a la izquierda; tipo, número y datos del documento a la derecha ──
    public function encabezado(array $sub = [], bool $contacto = true, float $szNumero = 17, $numero = null): void {
        $o = $this->org;
        $this->barra([[0, AZUL], [0.6, MARINO], [1, ROJO]], 4.5);
        $this->espacio(10);
        $lineas = [[$o['nombre'], fu(11, ['b' => true, 'c' => MARINO])]];
        if ($o['completo'] !== '') $lineas[] = [$o['completo'], fu(6.5, ['c' => PIZARRA])];
        if ($o['dir'] !== '') $lineas[] = [$o['dir'], fu(7.5, ['c' => GRIS])];
        if ($o['rtn'] !== '') $lineas[] = ['RTN: ' . $o['rtn'], fu(7.5, ['c' => GRIS])];
        if ($contacto && $o['tel'] !== '') $lineas[] = ['Tel: ' . $o['tel'], fu(7.5, ['c' => GRIS])];
        if ($contacto && $o['correo'] !== '') $lineas[] = [$o['correo'], fu(7.5, ['c' => GRIS])];
        $filas = max(count($lineas), 3 + count($sub), 4);
        $f0 = $this->f;
        for ($i = 0; $i < $filas; $i++) $this->h->altos[$f0 + $i] = $i === 0 ? 16 : ($i <= 2 ? 11.5 : 10.5);

        // Logo (o la inicial de la organización en un cuadro azul)
        if ($o['logo'] !== '' && is_file($o['logo'])) {
            [$w, $alto] = getimagesize($o['logo']) ?: [52, 52];
            $this->h->imagenes[] = [$f0, $this->ini, $o['logo'], round($w * 52 / max(1, $alto)), 52, 0, 4];
            $cTexto = $this->ini + (int)ceil(($w * 52 / max(1, $alto) + 12) / 28);
        } else {
            $this->h->rango($f0, $this->ini, $f0 + 3, $this->ini + ($this->n === 26 ? 1 : 2), $o['inicial'],
                fu(mb_strlen($o['inicial']) > 1 ? 9 : 16, ['b' => true, 'c' => BLANCO]) + ['bg' => AZUL, 'h' => 'center', 'v' => 'center']);
            $cTexto = $this->ini + ($this->n === 26 ? 3 : 4);
        }
        // Tipo de documento, número y líneas adicionales (período, fechas…)
        $c1 = $this->n === 26 ? 14 : 32;
        // Datos de la organización: el nombre puede seguir hacia la derecha; los demás se ajustan al
        // espacio libre y saltan de línea (una dirección larga), con la fila más alta
        $anchoPx = ($c1 - $cTexto) * ($this->n === 26 ? 28.3 : 18.4);
        foreach ($lineas as $i => [$texto, $estilo]) {
            if ($i === 0) { $this->h->poner($f0, $cTexto, $texto, $estilo + ['v' => 'center']); continue; }
            $sz = $estilo['f']['sz'];
            $renglones = (int)ceil(mb_strlen($texto) * $sz * 1.333 * 0.55 / $anchoPx);
            if ($renglones > 1) $this->h->altos[$f0 + $i] = max($this->h->altos[$f0 + $i], $renglones * $sz * 1.4 + 2);
            $this->h->rango($f0 + $i, $cTexto, $f0 + $i, $c1 - 1, $texto, $estilo + ['v' => 'center', 'wrap' => true]);
        }

        $this->h->rango($f0, $c1, $f0, $this->fin, $this->valorTitulo($this->titulo, $f0, $c1), fu(8, ['b' => true, 'c' => AZUL]) + ['h' => 'right', 'v' => 'bottom']);
        $this->refTitulo = $this->ref($f0, $c1);
        $this->h->rango($f0 + 1, $c1, $f0 + 2, $this->fin, $numero ?? $this->numero, fu($szNumero, ['b' => true, 'c' => MARINO]) + ['h' => 'right', 'v' => 'center']);
        $this->refNumero = $this->ref($f0 + 1, $c1);
        foreach (array_values($sub) as $i => $s) {
            $this->h->rango($f0 + 3 + $i, $c1, $f0 + 3 + $i, $this->fin, $s, fu(8.5, ['c' => GRIS]) + ['h' => 'right', 'v' => 'center']);
        }
        $this->f = $f0 + $filas;
        $this->espacio(10, ['b' => ['b' => ['thin', LINEA]]]);
    }

    // ── Cadena de documentos relacionados (📋 SC → 🛒 OC → 💳 OP) ──
    public function cadena(array $pildoras): void {
        $this->espacio(6);
        $f = $this->f;
        $this->h->altos[$f] = 17;
        $c = $this->ini;
        $ultima = count($pildoras) - 1;
        foreach ($pildoras as $i => $p) {
            $actual = $i === $ultima;
            $this->h->rango($f, $c, $f, $c + 4, $p, fu(7.5, ['b' => true, 'c' => $actual ? BLANCO : AZUL]) + [
                'bg' => $actual ? AZUL : CELESTE, 'h' => 'center', 'v' => 'center',
                'b' => array_fill_keys(['t', 'b', 'l', 'r'], ['thin', $actual ? AZUL : 'BFDBFE'])]);
            $c += 5;
            if (!$actual) { $this->h->poner($f, $c, '→', fu(9, ['c' => GRIS_CLARO]) + ['h' => 'center', 'v' => 'center']); $c++; }
        }
        $this->f++;
        $this->espacio(6, ['b' => ['b' => ['thin', LINEA]]]);
    }

    // ── Datos generales: cuadrícula de etiquetas y campos (meta-section) ──
    // Cada dato: ['l' => etiqueta (o ['f'=>…]), 'k' => texto|fecha|monto|num|ent|mono|lista, 'op' => [...],
    //   's' => columnas que ocupa o 'full', 'nueva' => empieza fila, 'alto' => pt, 'v' => valor, 'e' => estilo, 'id' => clave]
    public function meta(array $items, int $cols = 4, string $fondo = CLARO, string $borde = LINEA): array {
        $slot = intdiv($this->ancho, $cols);
        $lineas = [[]];
        $pos = 0;
        foreach ($items as $it) {
            $span = ($it['s'] ?? 1) === 'full' ? $cols : (int)($it['s'] ?? 1);
            if ($pos + $span > $cols || (!empty($it['nueva']) && $pos > 0)) { $lineas[] = []; $pos = 0; }
            $lineas[count($lineas) - 1][] = [$it, $pos, $span];
            $pos += $span;
        }
        $f1 = $this->f;
        $this->espacio(10);
        $refs = [];
        foreach ($lineas as $i => $linea) {
            if ($i > 0) $this->espacio(5);
            $fl = $this->f; $fv = $fl + 1;
            $this->h->altos[$fl] = 12;
            $this->h->altos[$fv] = max(array_map(fn($x) => $x[0]['alto'] ?? 17, $linea));
            foreach ($linea as [$it, $p, $span]) {
                $c1 = $this->ini + $p * $slot;
                $c2 = $this->ini + ($p + $span) * $slot - 1 - ($p + $span < $cols ? 1 : 0);
                $etq = is_array($it['l']) ? $it['l'] : may($it['l']);
                $this->h->rango($fl, $c1, $fl, $c2, $etq, fu(7, ['b' => true, 'c' => GRIS_CLARO]) + ['v' => 'bottom']);
                $this->h->rango($fv, $c1, $fv, $c2, $it['v'] ?? null, $this->estiloCampo($it));
                if (($it['k'] ?? '') === 'lista') $this->h->lista($this->ref($fv, $c1), $it['op']);
                if (isset($it['id'])) $refs[$it['id']] = $this->ref($fv, $c1);
            }
            $this->f += 2;
        }
        $this->espacio(10);
        $this->h->pintar($f1, 1, $this->f - 1, $this->n, ['bg' => $fondo]);
        $this->h->pintar($this->f - 1, 1, $this->f - 1, $this->n, ['b' => ['b' => ['thin', $borde]]]);
        return $refs;
    }

    /** Estilo de un campo para llenar: fondo blanco con línea inferior */
    public function estiloCampo(array $it): array {
        $k = $it['k'] ?? 'texto';
        $alto = $it['alto'] ?? 17;
        $e = fu(9) + ['bg' => BLANCO, 'b' => ['b' => ['thin', FIRMA]], 'v' => $alto > 20 ? 'top' : 'center', 'ind' => 1, 'wrap' => $alto > 20];
        $e = match ($k) {
            'fecha' => array_replace_recursive($e, ['nf' => NF_FECHA, 'h' => 'left']),
            'monto' => array_replace_recursive($e, mono(9) + ['nf' => NF_LPS, 'h' => 'left']),
            'num'   => array_replace_recursive($e, ['nf' => NF_NUM, 'h' => 'left']),
            'ent'   => array_replace_recursive($e, ['nf' => NF_ENT, 'h' => 'left']),
            'mono'  => array_replace_recursive($e, mono(9) + ['nf' => '@']),
            default => $e,
        };
        return array_replace_recursive($e, $it['e'] ?? []);
    }

    // ── Título de sección (section-head) ──
    public function seccion($texto, float $margen = 0): int {
        if ($margen) $this->espacio($margen);
        $f = $this->espacio(19, ['bg' => CELESTE, 'b' => ['t' => ['thin', BORDE_CELESTE], 'b' => ['thin', BORDE_CELESTE]]]);
        $this->h->rango($f, $this->ini, $f, $this->fin, is_string($texto) ? may($texto) : $texto, fu(7, ['b' => true, 'c' => AZUL]) + ['v' => 'center']);
        return $f;
    }

    // ── Tabla de detalle (table.items) ──
    // $cols: ['t' => encabezado, 'w' => columnas, 'k' => clave, 'a' => l|c|r, 'tipo' => n|texto|monto|num|ent|fecha|pct|mono,
    //         'f' => fn($r) => fórmula ($r('clave') = celda de la fila), 'e' => estilo de la celda]
    // $filas: cantidad de filas vacías, o lista de filas con valores [clave => valor] (y '_e' => estilo de la fila)
    // $pie:   ['l' => etiqueta, 'desde' => clave de la primera columna con valor, 'v' => [clave => 'sum' | valor | fórmula],
    //          'final' => fila de total (borde superior azul), 'le' => estilo de la etiqueta, 've' => [clave => estilo]]
    // $op:    'sz' (letra), 'szCab', 'szPie' (fila de total), 'alto', 'c1' (columna inicial; sin márgenes pintados)
    public function tabla(array $cols, int|array $filas, array $pie = [], array $op = []): array {
        $c1 = $op['c1'] ?? $this->ini;
        $suma = array_sum(array_column($cols, 'w'));
        $pintar1 = isset($op['c1']) ? $c1 : 1;
        $pintar2 = isset($op['c1']) ? $c1 + $suma - 1 : $this->n;
        if (!isset($op['c1']) && $suma !== $this->ancho) throw new LogicException("La tabla suma {$suma} columnas y el ancho es {$this->ancho}");
        $sz = $op['sz'] ?? 8.5;
        $pos = [];
        $c = $c1;
        foreach ($cols as $col) { $pos[$col['k']] = $c; $c += $col['w']; }
        $alinea = fn($col) => $col['a'] ?? (in_array($col['tipo'] ?? 'texto', ['monto', 'num', 'pct', 'ent'], true) ? 'right' : (($col['tipo'] ?? '') === 'n' ? 'center' : 'left'));
        $al = ['l' => 'left', 'c' => 'center', 'r' => 'right', 'left' => 'left', 'center' => 'center', 'right' => 'right'];

        // Encabezado
        $fc = $this->espacio($op['altoCab'] ?? 20, ['bg' => MARINO], $pintar1, $pintar2);
        foreach ($cols as $col) {
            $this->h->rango($fc, $pos[$col['k']], $fc, $pos[$col['k']] + $col['w'] - 1, $col['t'],
                fu($op['szCab'] ?? 7.5, ['b' => true, 'c' => BLANCO]) + ['bg' => MARINO, 'h' => $al[$alinea($col)], 'v' => 'center', 'wrap' => true]);
        }

        // Filas
        $datos = is_int($filas) ? array_fill(0, $filas, []) : $filas;
        $f1 = $this->f;
        foreach ($datos as $i => $valores) {
            $f = $this->espacio($op['alto'] ?? 17, ['bg' => $i % 2 ? CLARO : BLANCO, 'b' => ['b' => ['thin', LINEA]]], $pintar1, $pintar2);
            if (isset($valores['_e'])) $this->h->pintar($f, $pintar1, $f, $pintar2, $valores['_e']);
            $r = fn(string $k) => $this->ref($f, $pos[$k]);
            foreach ($cols as $col) {
                $tipo = $col['tipo'] ?? 'texto';
                $e = ($tipo === 'monto' || $tipo === 'mono' ? mono($sz) : fu($sz)) + ['h' => $al[$alinea($col)], 'v' => 'center'];
                $e['nf'] = match ($tipo) { 'monto' => NF_LPS, 'num' => NF_NUM, 'pct' => NF_PCT, 'ent', 'n' => NF_ENT, 'fecha' => NF_FECHA, 'mono' => '@', default => null };
                if ($tipo === 'n') $e = array_replace_recursive($e, fu($sz, ['c' => GRIS]));
                $e = array_replace_recursive($e, $col['e'] ?? [], $valores['_e'] ?? []);
                $v = $valores[$col['k']] ?? ($tipo === 'n' ? $i + 1 : (isset($col['f']) ? ['f' => ($col['f'])($r)] : null));
                $this->h->rango($f, $pos[$col['k']], $f, $pos[$col['k']] + $col['w'] - 1, $v, $e);
            }
        }
        $f2 = $this->f - 1;
        $rango = fn(string $k) => $this->ref($f1, $pos[$k]) . ':' . $this->ref($f2, $pos[$k]);

        // Pie (totales)
        $filasPie = [];
        foreach ($pie as $j => $p) {
            $final = !empty($p['final']);
            $f = $this->espacio($p['alto'] ?? ($final ? 19 : 16), $final ? ['b' => ['t' => ['medium', AZUL]]] : [], $pintar1, $pintar2);
            $filasPie[$j] = $f;
            $hasta = $pos[$p['desde']] - 1;
            $le = array_replace_recursive(fu(8, ['c' => GRIS, 'b' => $final]) + ['h' => 'right', 'v' => 'center'], $p['le'] ?? []);
            if ($hasta >= $c1) $this->h->rango($f, $c1, $f, $hasta, $p['l'], $le);
            foreach ($p['v'] as $k => $v) {
                $w = 0;
                foreach ($cols as $col) if ($col['k'] === $k) $w = $col['w'];
                $tipo = 'monto';
                foreach ($cols as $col) if ($col['k'] === $k) $tipo = $col['tipo'] ?? 'texto';
                $szPie = $final ? ($op['szPie'] ?? 9.5) : $sz;
                $e = ($tipo === 'monto' ? mono($szPie) : fu($szPie)) + ['h' => 'right', 'v' => 'center'];
                $e['nf'] = match ($tipo) { 'monto' => NF_LPS, 'num' => NF_NUM, 'pct' => NF_PCT, 'ent' => NF_ENT, default => null };
                if ($final) $e['f'] = array_replace($e['f'], ['b' => true, 'c' => MARINO]);
                $e = array_replace_recursive($e, $p['ve'][$k] ?? []);
                $valor = $v === 'sum' ? ['f' => 'SUM(' . $rango($k) . ')'] : $v;
                $this->h->rango($f, $pos[$k], $f, $pos[$k] + $w - 1, $valor, $e);
            }
        }
        return ['f1' => $f1, 'f2' => $f2, 'pos' => $pos, 'rango' => $rango, 'pie' => $filasPie,
                'celda' => fn(string $k, int $fila) => $this->ref($fila, $pos[$k])];
    }

    // ── Monto destacado (amount-block) ──
    public function monto(string|array $etiqueta, $valor, ?array $derecha = null, float $margen = 12): string {
        $this->espacio($margen);
        $f1 = $this->f;
        $mitad = $derecha ? $this->ini + intdiv($this->ancho, 2) : $this->fin - 1;
        $this->espacio(7);
        $fl = $this->espacio(12);
        $fv = $this->espacio(30);
        $this->espacio(7);
        $this->h->pintar($f1, $this->ini, $this->f - 1, $this->fin, ['bg' => MONTO_FONDO]);
        $this->h->pintar($f1, $this->ini, $this->f - 1, $this->ini, ['b' => ['l' => ['thick', AZUL]]]);
        $this->h->rango($fl, $this->ini + 1, $fl, $mitad, is_string($etiqueta) ? may($etiqueta) : $etiqueta, fu(8, ['b' => true, 'c' => AZUL]) + ['v' => 'bottom']);
        $this->h->rango($fv, $this->ini + 1, $fv, $mitad, $valor, mono(22, ['b' => true, 'c' => MARINO]) + ['nf' => NF_LPS, 'h' => 'left', 'v' => 'center']);
        if ($derecha) {
            $this->h->rango($fl, $mitad + 1, $fl, $this->fin - 1, may($derecha[0]), fu(8, ['b' => true, 'c' => AZUL]) + ['h' => 'right', 'v' => 'bottom']);
            $this->h->rango($fv, $mitad + 1, $fv, $this->fin - 1, $derecha[1], fu(11, ['b' => true, 'c' => MARINO]) + ['h' => 'right', 'v' => 'center']);
        }
        return $this->ref($fv, $this->ini + 1);
    }

    // ── Firmas (sig-line + nombre + cargo) ──
    public function firmas(array $etiquetas, float $margen = 21): void {
        $cols = count($etiquetas);
        $this->espacio($margen);
        $this->espacio(26);
        $fn = $this->espacio(13);
        $fr = $this->espacio(11);
        foreach ($etiquetas as $k => $etq) {
            if ($cols === 1) {
                $a = $this->ini + intdiv($this->ancho - 9, 2); $b = $a + 8;
            } else {
                $slot = intdiv($this->ancho, $cols);
                $a = $this->ini + $k * $slot + 1; $b = $this->ini + ($k + 1) * $slot - 2;
            }
            $this->h->rango($fn, $a, $fn, $b, null, fu(8, ['b' => true]) + ['h' => 'center', 'v' => 'center', 'b' => ['t' => ['medium', FIRMA]]]);
            $this->h->rango($fr, $a, $fr, $b, $etq, fu(7, ['c' => GRIS]) + ['h' => 'center', 'v' => 'top']);
        }
    }

    // ── Pie: organización y documento; barra inferior ──
    public function pie($derecha = null): void {
        $this->espacio(22);
        $f = $this->espacio(13, ['b' => ['t' => ['thin', LINEA]]]);
        $izq = $this->org['nombre'] . ($this->org['rtn'] !== '' ? '  ·  RTN ' . $this->org['rtn'] : '');
        $this->h->poner($f, $this->ini, $izq, fu(7, ['c' => GRIS_CLARO]) + ['v' => 'bottom']);
        $mitad = $this->ini + intdiv($this->ancho, 2);
        $derecha ??= ['f' => "{$this->refTitulo}&\" — \"&{$this->refNumero}"];
        $this->h->rango($f, $mitad, $f + 1, $this->fin, $derecha, fu(7, ['c' => GRIS_CLARO]) + ['h' => 'right', 'v' => 'center']);
        $f2 = $this->espacio(11);
        $this->h->poner($f2, $this->ini, 'Sistema de Administración Proscala', fu(7, ['c' => GRIS_CLARO]) + ['v' => 'top']);
        $this->espacio(8);
        $this->barra([[0, ROJO], [1, AZUL]], 3);
    }

    // ── Detalle de pago de los recibos: filas concepto | monto ──
    // $filas: ['l' => concepto, 'v' => valor, 'k' => monto|neg|texto|mono, 'fondo' => color, 'borde' => [estilo, color],
    //          'le' => estilo de la etiqueta, 've' => estilo del valor, 'id' => clave]
    public function detalle(array $filas, int $partir = 0): array {
        $partir = $partir ?: $this->ini + 1 + intdiv($this->ancho, 2);
        $this->espacio(8);
        $refs = [];
        foreach ($filas as $p) {
            $f = $this->espacio($p['alto'] ?? 20);
            $base = ['bg' => $p['fondo'] ?? BLANCO, 'b' => ['b' => $p['borde'] ?? ['thin', LINEA]], 'v' => 'center'];
            $this->h->pintar($f, $this->ini + 1, $f, $this->fin - 1, $base);
            $this->h->rango($f, $this->ini + 1, $f, $partir - 1, $p['l'], array_replace_recursive(fu(9, ['c' => TEXTO2]) + ['ind' => 1], $p['le'] ?? []));
            $k = $p['k'] ?? 'monto';
            $ve = match ($k) {
                'monto' => mono(9, ['c' => TEXTO2]) + ['nf' => NF_LPS],
                'neg'   => mono(8.5, ['c' => ROJO_DED]) + ['nf' => NF_LPS_NEG],
                'mono'  => mono(9, ['c' => TEXTO2]) + ['nf' => '@'],
                default => fu(9, ['c' => TEXTO2]),
            };
            $this->h->rango($f, $partir, $f, $this->fin - 1, $p['v'] ?? null, array_replace_recursive($ve + ['h' => $k === 'texto' ? 'left' : 'right', 'ind' => 1], $p['ve'] ?? []));
            if (isset($p['id'])) $refs[$p['id']] = $this->ref($f, $partir);
        }
        return $refs;
    }

    // ── Distribución por proyecto de los recibos (voucherDistTabla) ──
    // $cols: [clave => etiqueta]; la primera es el porcentaje; $base: [clave => celda del total del recibo]
    public function distribucion(array $cols, array $base, int $filas = 3): void {
        $this->seccion('Distribución por Proyecto', 9);
        $this->espacio(6);
        $wP = $this->ancho - 2 - 4 * (count($cols) - 1) - 3;
        $c1 = $this->ini + 1;
        $pos = ['proy' => $c1];
        $c = $c1 + $wP;
        $anchos = ['proy' => $wP];
        foreach ($cols as $k => $_) { $pos[$k] = $c; $anchos[$k] = $k === array_key_first($cols) ? 3 : 4; $c += $anchos[$k]; }
        $fh = $this->espacio(17, ['b' => ['b' => ['medium', FIRMA]]], $c1, $this->fin - 1);
        $this->h->rango($fh, $c1, $fh, $c1 + $wP - 1, 'PROYECTO', fu(7.5, ['b' => true, 'c' => GRIS]) + ['v' => 'center', 'ind' => 1]);
        foreach ($cols as $k => $etq) $this->h->rango($fh, $pos[$k], $fh, $pos[$k] + $anchos[$k] - 1, $etq, fu(7.5, ['b' => true, 'c' => GRIS]) + ['h' => 'right', 'v' => 'center']);
        $pct = array_key_first($cols);
        $f1 = $this->f;
        for ($i = 0; $i < $filas; $i++) {
            $f = $this->espacio(18, ['b' => ['b' => ['thin', LINEA]]], $c1, $this->fin - 1);
            $this->h->rango($f, $c1, $f, $c1 + $wP - 1, null, fu(8.5, ['c' => TEXTO2]) + ['v' => 'center', 'ind' => 1]);
            foreach ($cols as $k => $_) {
                $p = $this->ref($f, $pos[$pct]);
                $v = $k === $pct ? null : (isset($base[$k]) ? ['f' => "IF({$p}=\"\",\"\",ROUND({$p}/100*{$base[$k]},2))"] : null);
                $this->h->rango($f, $pos[$k], $f, $pos[$k] + $anchos[$k] - 1, $v, mono(8.5) + ['h' => 'right', 'v' => 'center', 'nf' => $k === $pct ? NF_PCT : NF_LPS]);
            }
        }
        $f2 = $this->f - 1;
        $ft = $this->espacio(18, ['bg' => CELESTE], $c1, $this->fin - 1);
        $this->h->rango($ft, $c1, $ft, $c1 + $wP - 1, 'Total', fu(8.5, ['b' => true, 'c' => MARINO]) + ['v' => 'center', 'ind' => 1]);
        foreach ($cols as $k => $_) {
            $this->h->rango($ft, $pos[$k], $ft, $pos[$k] + $anchos[$k] - 1, ['f' => 'SUM(' . $this->ref($f1, $pos[$k]) . ':' . $this->ref($f2, $pos[$k]) . ')'],
                mono(8.5, ['b' => true, 'c' => MARINO]) + ['h' => 'right', 'v' => 'center', 'nf' => $k === $pct ? NF_PCT : NF_LPS]);
        }
    }

    // ── Constancia de recibido de los recibos: texto, firma del empleado y fecha de recibo ──
    public function recibo(array $texto, string $firma = 'Firma y nombre del empleado'): void {
        $this->espacio(13);
        $f1 = $this->f;
        $this->espacio(13);
        $largo = mb_strlen(implode('', array_column($texto, 0)));
        $fp = $this->espacio(max(30, ceil($largo / ($this->n === 26 ? 82 : 160)) * 15.5 + 8));
        $this->h->rango($fp, $this->ini + 2, $fp, $this->fin - 2, ['rico' => $texto], fu(9, ['c' => TEXTO2]) + ['wrap' => true, 'v' => 'top', 'h' => 'left']);
        $this->espacio(14);
        $fe = $this->espacio(12);
        $fl = $this->espacio(21);
        $fn = $this->espacio(12);
        $fr = $this->espacio(11);
        $this->espacio(13);
        $f2 = $this->f - 1;
        $fechaC1 = $this->fin - 8;
        $this->h->rango($fn, $this->ini + 3, $fn, $fechaC1 - 3, null, fu(8, ['b' => true]) + ['h' => 'center', 'b' => ['t' => ['medium', GRIS]]]);
        $this->h->rango($fr, $this->ini + 3, $fr, $fechaC1 - 3, $firma, fu(7.5, ['c' => GRIS]) + ['h' => 'center', 'v' => 'top']);
        $this->h->rango($fe, $fechaC1, $fe, $this->fin - 2, 'Fecha de recibo:', fu(8, ['c' => GRIS]) + ['v' => 'bottom']);
        $this->h->rango($fl, $fechaC1, $fl, $this->fin - 2, null, fu(9) + ['nf' => NF_FECHA, 'v' => 'bottom', 'b' => ['b' => ['thin', GRIS]]]);
        // Recuadro punteado
        $this->h->pintar($f1, $this->ini, $f2, $this->fin, ['bg' => 'FAFAFA']);
        $this->h->pintar($f1, $this->ini, $f1, $this->fin, ['b' => ['t' => ['dashed', FIRMA]]]);
        $this->h->pintar($f2, $this->ini, $f2, $this->fin, ['b' => ['b' => ['dashed', FIRMA]]]);
        $this->h->pintar($f1, $this->ini, $f2, $this->ini, ['b' => ['l' => ['dashed', FIRMA]]]);
        $this->h->pintar($f1, $this->fin, $f2, $this->fin, ['b' => ['r' => ['dashed', FIRMA]]]);
    }

    /** Párrafo de carta (texto enriquecido); calcula el alto por la cantidad de líneas */
    public function parrafo(array|string $texto, float $sz = 10, array $estilo = [], int $sangria = 1, float $despues = 10): void {
        $runs = is_string($texto) ? [[$texto, []]] : $texto;
        $largo = mb_strlen(implode('', array_column($runs, 0)));
        $c1 = $this->ini + $sangria; $c2 = $this->fin - $sangria;
        $ancho = ($c2 - $c1 + 1) * ($this->n === 26 ? 28.3 : 18.4);
        $porLinea = max(20, floor($ancho / ($sz * 1.333 * 0.6)));
        $lineas = substr_count(implode('', array_column($runs, 0)), "\n") + ceil($largo / $porLinea);
        $f = $this->espacio(max(1, $lineas) * $sz * 1.6 + 6);
        $this->h->rango($f, $c1, $f, $c2, ['rico' => $runs], array_replace_recursive(fu($sz) + ['wrap' => true, 'v' => 'top', 'h' => 'justify'], $estilo));
        if ($despues) $this->espacio($despues);
    }

    /** Área grande para escribir (cuerpo de un memorándum, desarrollo de un acta…) */
    public function area(float $alto, array $estilo = [], int $sangria = 1): string {
        $f = $this->espacio($alto);
        $this->h->rango($f, $this->ini + $sangria, $f, $this->fin - $sangria, null,
            array_replace_recursive(fu(8.5) + ['wrap' => true, 'v' => 'top', 'bg' => BLANCO, 'b' => array_fill_keys(['t', 'b', 'l', 'r'], ['thin', LINEA])], $estilo));
        return $this->ref($f, $this->ini + $sangria);
    }
}

<?php
// Funciones de las plantillas de impresión (firmas, distribución por proyecto)

// Firma #1 de anticipos y liquidaciones guardada en el documento (firmante1_id); las firmas
// Revisado / Autorizado las pone firmasFlujo con las personas reales del flujo
function firmante1Documento(PDO $pdo, array $fBase, int $firmante1Id): array {
    $s = $pdo->prepare("SELECT CONCAT(nombre,' ',apellidos) FROM empleados WHERE id=?");
    $s->execute([$firmante1Id]);
    if ($nombre = $s->fetchColumn()) {
        $fBase[0] = ($fBase[0] ?? ['posicion' => 1, 'etiqueta' => 'Solicitado por']);
        $fBase[0]['emp_nombre'] = $nombre;
    }
    return $fBase;
}

// Firmas configuradas de un documento (tabla firmantes; ya no se editan desde el sistema)
function loadFirmantes(PDO $pdo, string $doc): array {
    $s = $pdo->prepare("SELECT f.posicion, f.etiqueta,
        CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre
        FROM firmantes f
        LEFT JOIN empleados e ON f.empleado_id = e.id
        WHERE f.documento = ? ORDER BY f.posicion");
    $s->execute([$doc]); return $s->fetchAll();
}
/**
 * Firmas de un proceso que se sumó al flujo después de estar en uso (planillas): con pasos del flujo
 * o aún pendiente, las personas reales (firmasFlujo); aprobado antes del flujo, los firmantes
 * configurados de siempre, para no dejar en blanco quién lo revisó y autorizó.
 */
function firmasProceso(PDO $pdo, string $doc, string $modulo, int $id, string $estado): array {
    $base = loadFirmantes($pdo, $doc);
    if (!pasosFlujoDados($pdo, PROCESOS[$modulo]['tabla'], $id) && !in_array($estado, PROCESOS[$modulo]['abiertos'], true)) return $base;
    return firmasFlujo($pdo, $base, $modulo, $id);
}

function sigBlock(array $fs, string $cls = 'cols-3', string $sty = ''): string {
    if (empty($fs)) return '';
    $attr = $sty ? " style=\"{$sty}\"" : '';
    $html = "<div class=\"signatures {$cls}\"{$attr}>";
    foreach ($fs as $f) {
        $nm  = htmlspecialchars($f['emp_nombre'] ?: $f['etiqueta']);
        $rl  = htmlspecialchars($f['etiqueta']);
        $ext = !empty($f['ext']) ? ' sig-ext' : '';
        $html .= "<div class=\"sig-item{$ext}\"><div class=\"sig-line\"></div>"
               . "<div class=\"sig-name\">{$nm}</div>"
               . "<div class=\"sig-role\">{$rl}</div></div>";
    }
    return $html . '</div>';
}
function sigProvBlock(string $name, string $label): string {
    $nm = htmlspecialchars($name);
    $rl = htmlspecialchars($label);
    return "<div class=\"sig-prov-wrap\">"
         . "<div class=\"sig-item sig-ext\">"
         . "<div class=\"sig-line\"></div>"
         . "<div class=\"sig-name\">{$nm}</div>"
         . "<div class=\"sig-role\">{$rl}</div>"
         . "</div></div>";
}

// ── Tabla "Distribución por Proyecto" de los recibos plv / pbv ─────
// $cols: [clave => etiqueta]; la primera clave es el porcentaje.
function voucherDistTabla(array $rep, array $proyMap, array $cols): string {
    $th  = 'padding:5px 8px;text-align:right;font-weight:700';
    $td  = "padding:6px 8px;text-align:right;font-family:'Courier New',monospace";
    $pctKey = array_key_first($cols);
    $html = '<div class="section-head" style="margin-top:12px">Distribución por Proyecto</div>'
          . '<div class="items-wrap" style="padding:0 28px;margin-top:8px">'
          . '<table style="width:100%;border-collapse:collapse;font-size:8.5pt"><thead>'
          . '<tr style="border-bottom:1.5px solid #cbd5e1;color:#64748b;font-size:7.5pt">'
          . '<th style="padding:5px 8px;text-align:left;font-weight:700">PROYECTO</th>';
    foreach ($cols as $lbl) $html .= "<th style=\"{$th}\">" . htmlspecialchars($lbl) . '</th>';
    $html .= '</tr></thead><tbody>';
    $tot = array_fill_keys(array_keys($cols), 0);
    foreach ($rep as $pid => $r) {
        $html .= '<tr style="border-bottom:1px solid #e2e8f0"><td style="padding:6px 8px;color:#374151">'
               . htmlspecialchars(proyectoLabel($proyMap, (int)$pid, true)) . '</td>';
        foreach ($cols as $k => $_) {
            $html .= "<td style=\"{$td}\">" . ($k === $pctKey ? fmtPct($r[$k]) . '%' : lps($r[$k])) . '</td>';
            $tot[$k] += $r[$k];
        }
        $html .= '</tr>';
    }
    $html .= '<tr style="background:#f0f5fc;font-weight:700;color:#0D3F6A"><td style="padding:6px 8px">Total</td>';
    foreach ($cols as $k => $_) $html .= "<td style=\"{$td}\">" . ($k === $pctKey ? fmtPct($tot[$k]) . '%' : lps($tot[$k])) . '</td>';
    return $html . '</tr></tbody></table></div>';
}

<?php
// ── Acta de recepción de vehículo ───────────────────────────────
// Plantilla de impresión (print.php): entrega del vehículo que pidió quien solicitó el anticipo
// (gastos_viaje.requiere_vehiculo). Solo cuando el anticipo ya está revisado y autorizado
// (actaVehiculoDisponible). Los datos del vehículo, el kilometraje y el combustible se anotan a
// mano al entregarlo; la devolución se registra en la misma hoja.

$s = $pdo->prepare("SELECT gv.*, " . SQL_BENEFICIARIO_GASTO . " AS emp_nombre, p.codigo AS proy_nombre,
    COALESCE(e.identidad, pr.rtn) AS emp_identidad, IF(gv.proveedor_id IS NULL, e.cargo, 'Consultor') AS emp_cargo
    FROM gastos_viaje gv " . joinBeneficiarioGasto('gv') . "
    LEFT JOIN proyectos p ON gv.proyecto_id = p.id
    WHERE gv.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Anticipo no encontrado.');
if (empty($doc['requiere_vehiculo'])) die('Este anticipo no solicitó vehículo.');
if (!actaVehiculoDisponible($doc)) die('El Acta de recepción de vehículo se imprime cuando el anticipo ya está revisado y autorizado.');

$title  = 'ACTA DE RECEPCIÓN DE VEHÍCULO';
$docNum = $doc['numero'];
$dias   = diasIncluidos($doc['vehiculo_desde'], $doc['vehiculo_hasta']);

// Firmas: entrega quien administra los vehículos (firmantes configurados del documento 'arv', si los
// hay), recibe el solicitante y da el visto bueno quien autorizó el anticipo
$fArv = loadFirmantes($pdo, 'arv');
$flujoGv = estadoFlujo($pdo, 'gastos_viajes.php', $id);
$firmasArv = [
    ['etiqueta' => $fArv[0]['etiqueta'] ?? 'Entregado por', 'emp_nombre' => $fArv[0]['emp_nombre'] ?? ' '],
    ['etiqueta' => 'Recibido por (solicitante)', 'emp_nombre' => $doc['emp_nombre']],
    ['etiqueta' => 'Vo. Bo. (autorizó el anticipo)', 'emp_nombre' => $flujoGv['autorizado']['nombre'] ?? ' '],
];

$doc['estado'] = '';   // el acta no muestra el estado del anticipo
require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';

// Casilla para marcar a mano
$casilla = fn(string $t) => '<span style="display:inline-flex;align-items:center;gap:5px;margin-right:14px;white-space:nowrap">'
    . '<span style="display:inline-block;width:11px;height:11px;border:1.3px solid #64748b;border-radius:2px"></span>' . htmlspecialchars($t) . '</span>';
?>
<style>
.arv-tabla { width:100%; border-collapse:collapse; font-size:8.5pt; }
.arv-tabla td { border:1px solid #cbd5e1; padding:5px 8px; vertical-align:top; }
/* Compacto: el acta cabe en una hoja */
.section-head { margin-top:8px; padding-top:6px; padding-bottom:6px; }
.signatures { margin-top:14px; }
.arv-tabla td.l { width:22%; background:#f8fafc; font-size:7pt; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#64748b; }
</style>

  <div class="ref-chain">
    <span class="ref-pill"><?= $doc['tipo'] === 'compra' ? '📦' : '✈' ?> Anticipo <?= htmlspecialchars($doc['numero']) ?></span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill">🚗 Solicitud de vehículo</span>
    <span class="ref-arrow">→</span>
    <span class="ref-pill ref-current">📝 Acta de recepción</span>
  </div>

  <div class="meta-section">
    <div class="meta-grid cols-4">
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label">Recibe el vehículo</div>
        <div class="meta-value" style="font-weight:700"><?= htmlspecialchars($doc['emp_nombre']) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label"><?= $doc['proveedor_id'] ? 'RTN' : 'N° Identidad' ?></div>
        <div class="meta-value mono"><?= htmlspecialchars($doc['emp_identidad'] ?: '—') ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Cargo</div>
        <div class="meta-value"><?= htmlspecialchars($doc['emp_cargo'] ?: '—') ?></div>
      </div>
      <div class="meta-item" style="grid-column:1/3">
        <div class="meta-label"><?= $doc['tipo'] === 'compra' ? 'Concepto del anticipo' : 'Destino' ?></div>
        <div class="meta-value"><?= htmlspecialchars($doc['destino']) ?></div>
      </div>
      <div class="meta-item" style="grid-column:3/5">
        <div class="meta-label">Uso autorizado<?= $doc['proy_nombre'] ? ' · Proyecto ' . htmlspecialchars($doc['proy_nombre']) : '' ?></div>
        <div class="meta-value"><?= fmtFecha($doc['vehiculo_desde'] ?? '') ?> — <?= fmtFecha($doc['vehiculo_hasta'] ?? '') ?> (<?= $dias ?> día<?= $dias === 1 ? '' : 's' ?>)</div>
      </div>
      <div class="meta-item meta-full">
        <div class="meta-label">Uso del vehículo</div>
        <div class="meta-value"><?= nl2br(htmlspecialchars($doc['vehiculo_descripcion'] ?? '')) ?></div>
      </div>
    </div>
  </div>

  <div style="padding:10px 28px 0;font-size:8.5pt;line-height:1.65;color:#374151;text-align:justify">
    En la ciudad de ______________________, a las ______ horas del día ____ de ______________ de ________,
    <?= htmlspecialchars($org) ?> hace entrega a <strong><?= htmlspecialchars(mb_strtoupper($doc['emp_nombre'])) ?></strong>
    del vehículo descrito a continuación, para la comisión amparada por el anticipo <strong><?= htmlspecialchars($doc['numero']) ?></strong>.
    Quien lo recibe declara recibirlo en las condiciones aquí anotadas y se compromete a usarlo solo para esa comisión,
    conducirlo con licencia vigente, cumplir las leyes de tránsito y devolverlo en las mismas condiciones.
  </div>

  <div class="section-head">Datos del vehículo</div>
  <div style="padding:8px 28px 0">
    <table class="arv-tabla">
      <tr><td class="l">Marca</td><td></td><td class="l">Modelo</td><td></td></tr>
      <tr><td class="l">Año</td><td></td><td class="l">Color</td><td></td></tr>
      <tr><td class="l">Placa</td><td></td><td class="l">N° de chasis / VIN</td><td></td></tr>
      <tr><td class="l">Código de activo</td><td></td><td class="l">Tarjeta de combustible</td><td></td></tr>
    </table>
  </div>

  <div class="section-head">Entrega y devolución</div>
  <div style="padding:8px 28px 0">
    <table class="arv-tabla">
      <tr style="background:#f0f5fc"><td class="l"></td><td style="font-weight:700;color:#0D3F6A;width:39%">Al entregar</td><td style="font-weight:700;color:#0D3F6A">Al devolver</td></tr>
      <tr><td class="l">Fecha y hora</td><td></td><td></td></tr>
      <tr><td class="l">Kilometraje</td><td></td><td></td></tr>
      <tr><td class="l">Combustible</td>
        <td><?= $casilla('E') . $casilla('¼') . $casilla('½') . $casilla('¾') . $casilla('F') ?></td>
        <td><?= $casilla('E') . $casilla('¼') . $casilla('½') . $casilla('¾') . $casilla('F') ?></td></tr>
    </table>
  </div>

  <div class="section-head">Accesorios y documentos que se entregan</div>
  <div style="padding:8px 28px 0;font-size:8.5pt;line-height:2">
    <?php foreach (['Llanta de repuesto', 'Gato y llave de ruedas', 'Triángulos de seguridad', 'Extintor', 'Botiquín',
                    'Herramientas', 'Tarjeta de circulación', 'Póliza de seguro', 'Llaves (juegos: ___)'] as $acc) echo $casilla($acc); ?>
  </div>

  <div class="section-head">Estado del vehículo (golpes, rayones, fallas)</div>
  <div style="padding:8px 28px 0">
    <table class="arv-tabla">
      <tr><td class="l">Al entregar</td><td style="height:40px"></td></tr>
      <tr><td class="l">Al devolver</td><td style="height:40px"></td></tr>
    </table>
  </div>

  <?= sigBlock($firmasArv) ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

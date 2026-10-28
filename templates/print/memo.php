<?php
// ── Memorándum / Circular / Comunicado ──────────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT m.*, CONCAT(e.nombre,' ',e.apellidos) AS emp_nombre_full
    FROM memorandos m LEFT JOIN empleados e ON m.de_empleado_id = e.id
    WHERE m.id = ?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Documento no encontrado.');
$TITULOS_MEMO = ['memo'=>'MEMORÁNDUM','circular'=>'CIRCULAR','comunicado'=>'COMUNICADO'];
$title  = $TITULOS_MEMO[$doc['tipo']] ?? 'DOCUMENTO INTERNO';
$docNum = $doc['numero'];

// Firmas
$fMEMO = loadFirmantes($pdo, 'memo');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php
  $meses_memo = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  $dtM = new DateTime($doc['fecha']);
  $fechaLetras = (int)$dtM->format('j') . ' de ' . $meses_memo[(int)$dtM->format('n')-1] . ' de ' . $dtM->format('Y');
  $remitente   = $doc['emp_nombre_full'] ?: ($doc['de_cargo'] ?: $org);
  $cargo_rem   = $doc['de_cargo'] ?: '';
  ?>

  <div class="letter-body">
    <div class="letter-date"><?= htmlspecialchars($orgDir ?: 'Tegucigalpa, Honduras') ?>, <?= $fechaLetras ?></div>

    <!-- Bloque PARA / DE / ASUNTO -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:18px;font-size:9.5pt">
      <tr>
        <td style="font-weight:800;width:80px;padding:4px 12px 4px 0;vertical-align:top;color:#0D3F6A;letter-spacing:.04em">PARA:</td>
        <td style="padding:4px 0;vertical-align:top"><?= nl2br(htmlspecialchars($doc['para_texto'])) ?></td>
      </tr>
      <tr>
        <td style="font-weight:800;padding:4px 12px 4px 0;vertical-align:top;color:#0D3F6A;letter-spacing:.04em">DE:</td>
        <td style="padding:4px 0;vertical-align:top">
          <strong><?= htmlspecialchars($remitente) ?></strong>
          <?php if ($cargo_rem): ?><br><span style="font-size:8.5pt;color:#64748b"><?= htmlspecialchars($cargo_rem) ?></span><?php endif; ?>
        </td>
      </tr>
      <tr>
        <td style="font-weight:800;padding:4px 12px 4px 0;vertical-align:top;color:#0D3F6A;letter-spacing:.04em">ASUNTO:</td>
        <td style="padding:4px 0;vertical-align:top;font-weight:700"><?= htmlspecialchars($doc['asunto']) ?></td>
      </tr>
      <tr>
        <td style="font-weight:800;padding:4px 12px 4px 0;vertical-align:top;color:#0D3F6A;letter-spacing:.04em">FECHA:</td>
        <td style="padding:4px 0;vertical-align:top"><?= $fechaLetras ?></td>
      </tr>
    </table>

    <hr class="letter-separator">

    <?php foreach (explode("\n", $doc['cuerpo']) as $linea):
      $l = trim($linea);
      if ($l === '') { echo '<div style="height:8pt"></div>'; continue; } ?>
    <p class="letter-p"><?= htmlspecialchars($l) ?></p>
    <?php endforeach; ?>

    <p class="letter-close" style="margin-top:20px">Atentamente,</p>
  </div>

  <?php
  if (!empty($fMEMO)) {
      echo sigBlock($fMEMO, count($fMEMO) >= 3 ? 'cols-3' : (count($fMEMO) == 2 ? 'cols-2' : 'cols-1'));
  } else {
      $memoSigDef = [['etiqueta' => $cargo_rem ?: 'Remitente', 'emp_nombre' => $remitente]];
      echo sigBlock($memoSigDef, 'cols-1');
  }
  ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

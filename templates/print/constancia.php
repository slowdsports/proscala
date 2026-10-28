<?php
// ── Constancias de RRHH (id = empleado_id) ──────────────────────
// Plantilla de impresión (print.php): datos del documento y su contenido,
// dentro de la página común con el diseño de todos los documentos.

$s = $pdo->prepare("SELECT *, CONCAT(nombre,' ',apellidos) AS nombre_completo FROM empleados WHERE id=?");
$s->execute([$id]); $doc = $s->fetch();
if (!$doc) die('Empleado no encontrado.');
$constFechaEmision  = $_GET['fecha_emision']  ?? date('Y-m-d');
$constDestinatario  = trim($_GET['destinatario'] ?? '');
$constNotaAdicional = trim($_GET['nota_adicional'] ?? '');
$hoy = new DateTime(); $ing = $doc['fecha_ingreso'] ? new DateTime($doc['fecha_ingreso']) : $hoy;
$aniosSrv = (int)$ing->diff($hoy)->y;
$mesesSrv = (int)$ing->diff($hoy)->m;
$TITULOS_CONST = [
    'trabajo'    => 'CONSTANCIA DE TRABAJO',
    'salario'    => 'CONSTANCIA DE SALARIO',
    'referencia' => 'REFERENCIA LABORAL',
    'tiempo'     => 'CONSTANCIA DE TIEMPO DE SERVICIO',
];
$title  = $TITULOS_CONST[$tipo];
$docNum = date('Y') . '-' . str_pad($id, 4, '0', STR_PAD_LEFT);

// Firmas
$fCONST = loadFirmantes($pdo, 'constancia');

require __DIR__ . '/_inicio.php';
require __DIR__ . '/_pagina_inicio.php';
?>

  <?php
  $dtE = new DateTime($constFechaEmision);
  $emisionLetras = (int)$dtE->format('j') . ' de ' . $meses[(int)$dtE->format('n') - 1] . ' de ' . $dtE->format('Y');
  $ingFechaLetras = '—';
  if ($doc['fecha_ingreso']) {
      $dtI = new DateTime($doc['fecha_ingreso']);
      $ingFechaLetras = (int)$dtI->format('j') . ' de ' . $meses[(int)$dtI->format('n') - 1] . ' de ' . $dtI->format('Y');
  }
  $tiempoSrv = '';
  if ($aniosSrv > 0) {
      $tiempoSrv = $aniosSrv . ' año' . ($aniosSrv !== 1 ? 's' : '');
      if ($mesesSrv > 0) $tiempoSrv .= ' y ' . $mesesSrv . ' mes' . ($mesesSrv !== 1 ? 'es' : '');
  } elseif ($mesesSrv > 0) {
      $tiempoSrv = $mesesSrv . ' mes' . ($mesesSrv !== 1 ? 'es' : '');
  } else {
      $tiempoSrv = 'menos de un mes';
  }
  $idStr  = $doc['identidad'] ? htmlspecialchars($doc['identidad']) : 'no registrada';
  $nombre = htmlspecialchars(strtoupper($doc['nombre_completo'] ?? ($doc['nombre'] . ' ' . $doc['apellidos'])));
  $cargo  = htmlspecialchars($doc['cargo'] ?? '');
  $salStr = lps($doc['sueldo_mensual'] ?? 0);
  ?>

  <div class="letter-body">
    <div class="letter-date"><?= htmlspecialchars($orgDir ?: 'Tegucigalpa, Honduras') ?>, <?= $emisionLetras ?></div>

    <div class="letter-to">
      <strong><?= $constDestinatario ? htmlspecialchars($constDestinatario) : 'A QUIEN CORRESPONDA' ?>:</strong>
    </div>

    <hr class="letter-separator">

    <div class="letter-ref">Ref.: <?= $title ?></div>

    <?php if ($tipo === 'trabajo'): ?>
    <p class="letter-p">
      Por medio de la presente se hace <strong>CONSTAR</strong> que el/la señor/a
      <span class="emp-name"><?= $nombre ?></span>,
      portador/a de la Tarjeta de Identidad número <span class="emp-id"><?= $idStr ?></span>,
      labora en esta institución desempeñando el cargo de <span class="highlight"><?= $cargo ?></span>,
      a partir del <?= $ingFechaLetras ?>.
    </p>

    <?php elseif ($tipo === 'salario'): ?>
    <p class="letter-p">
      Por medio de la presente se hace <strong>CONSTAR</strong> que el/la señor/a
      <span class="emp-name"><?= $nombre ?></span>,
      portador/a de la Tarjeta de Identidad número <span class="emp-id"><?= $idStr ?></span>,
      labora en esta institución desempeñando el cargo de <span class="highlight"><?= $cargo ?></span>,
      devengando un salario mensual de <span class="highlight"><?= $salStr ?></span>.
    </p>

    <?php elseif ($tipo === 'referencia'): ?>
    <p class="letter-p">
      En respuesta a la solicitud de referencia laboral, nos complace informar que el/la señor/a
      <span class="emp-name"><?= $nombre ?></span>,
      portador/a de la Tarjeta de Identidad número <span class="emp-id"><?= $idStr ?></span>,
      labora en esta institución desde el <?= $ingFechaLetras ?>, desempeñando el cargo de
      <span class="highlight"><?= $cargo ?></span>, con una antigüedad de <span class="highlight"><?= $tiempoSrv ?></span>.
    </p>
    <p class="letter-p">
      Durante su trayectoria institucional, ha demostrado ser una persona responsable, comprometida con las
      funciones asignadas y de buena conducta, por lo que extendemos la presente referencia en los mejores términos.
    </p>

    <?php elseif ($tipo === 'tiempo'): ?>
    <p class="letter-p">
      Por medio de la presente se hace <strong>CONSTAR</strong> que el/la señor/a
      <span class="emp-name"><?= $nombre ?></span>,
      portador/a de la Tarjeta de Identidad número <span class="emp-id"><?= $idStr ?></span>,
      labora en esta institución desde el <?= $ingFechaLetras ?>, acumulando a la presente fecha
      <span class="highlight"><?= $tiempoSrv ?></span> de servicio en el cargo de <span class="highlight"><?= $cargo ?></span>.
    </p>
    <?php endif; ?>

    <p class="letter-p">
      Se extiende la presente constancia a petición de la parte interesada, para los fines que estime convenientes.
    </p>

    <?php if ($constNotaAdicional): ?>
    <p class="letter-p"><?= nl2br(htmlspecialchars($constNotaAdicional)) ?></p>
    <?php endif; ?>

    <p class="letter-close">Atentamente,</p>
  </div>

  <?php
  $constSig = !empty($fCONST) ? [$fCONST[0]] : [['etiqueta' => 'Director Ejecutivo', 'emp_nombre' => '']];
  echo sigBlock($constSig, 'cols-1');
  ?>

<?php require __DIR__ . '/_pagina_fin.php'; ?>
<?php require __DIR__ . '/_fin.php';

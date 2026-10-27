<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$pagina = 'Constancias y Referencias';

$TIPOS = [
    'trabajo'    => ['label' => 'Constancia de Trabajo',          'icon' => 'fa-id-card',          'desc' => 'Certifica que el empleado trabaja en la organización.'],
    'salario'    => ['label' => 'Constancia de Salario',           'icon' => 'fa-money-check-dollar','desc' => 'Certifica el salario mensual del empleado.'],
    'referencia' => ['label' => 'Referencia Laboral',              'icon' => 'fa-file-signature',    'desc' => 'Carta de referencia para uso externo.'],
    'tiempo'     => ['label' => 'Constancia de Tiempo de Servicio','icon' => 'fa-clock-rotate-left', 'desc' => 'Certifica los años de servicio en la organización.'],
];

$empleados = $pdo->query("SELECT e.*, CONCAT(nombre,' ',apellidos) AS nombre_completo
    FROM empleados e WHERE activo=1 ORDER BY apellidos, nombre")->fetchAll();

$config = getAllConfig($pdo);
$org    = $config['nombre_organizacion'] ?? 'Proscala';
$orgFull= $config['nombre_completo'] ?? '';

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-file-contract"></i> Constancias y Referencias</h1>
</div>

<p style="font-size:.85rem;color:var(--text-2);margin-bottom:1.5rem">
  Genere constancias y cartas de referencia para los empleados. Cada documento se produce como PDF firmado.
</p>

<div class="row g-4">
  <!-- Tipos de constancias -->
  <div class="col-md-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-list-check"></i> Seleccione el tipo de documento</div>
      <div class="card-body d-grid gap-2">
        <?php foreach ($TIPOS as $k => $t): ?>
        <button class="btn btn-outline-secondary text-start tipo-btn" data-tipo="<?= $k ?>"
          style="border-radius:8px;padding:12px 16px">
          <i class="fas <?= $t['icon'] ?> me-2" style="width:18px;color:var(--accent)"></i>
          <strong><?= $t['label'] ?></strong>
          <div style="font-size:.75rem;color:var(--text-3);margin-top:3px;margin-left:26px"><?= $t['desc'] ?></div>
        </button>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Formulario de generación -->
  <div class="col-md-7">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-cog"></i> Configurar documento</div>
      <div class="card-body">
        <form id="form-constancia" target="_blank" method="GET" action="<?= BASE_URL ?>print.php">
          <input type="hidden" name="tipo" id="input-tipo" value="">

          <div class="mb-3">
            <label class="form-label">Tipo seleccionado</label>
            <div id="tipo-display" class="form-control-plaintext text-muted" style="font-style:italic">— Ninguno seleccionado —</div>
          </div>

          <div class="mb-3">
            <label class="form-label">Empleado *</label>
            <select name="id" class="form-select" required id="sel-emp">
              <option value="">— Seleccione empleado —</option>
              <?php foreach ($empleados as $e): ?>
              <option value="<?= $e['id'] ?>"
                data-cargo="<?= htmlspecialchars($e['cargo'] ?? '') ?>"
                data-ingreso="<?= $e['fecha_ingreso'] ?? '' ?>"
                data-sueldo="<?= $e['sueldo_mensual'] ?>">
                <?= htmlspecialchars($e['nombre_completo']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Info del empleado seleccionado -->
          <div id="emp-info" class="mb-3" style="display:none;background:var(--surface-2);border-radius:8px;padding:12px;font-size:.82rem">
            <div class="row g-1">
              <div class="col-6"><strong>Cargo:</strong> <span id="ei-cargo">—</span></div>
              <div class="col-6"><strong>Ingreso:</strong> <span id="ei-ingreso">—</span></div>
              <div class="col-6"><strong>Antigüedad:</strong> <span id="ei-antiguedad">—</span></div>
              <div class="col-6"><strong>Salario:</strong> <span id="ei-salario" class="font-mono">—</span></div>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">Fecha de emisión</label>
            <input type="text" name="fecha_emision" id="inp-fecha" class="form-control date-input" value="<?= date('Y-m-d') ?>">
          </div>

          <div class="mb-3">
            <label class="form-label">Destinatario / A quien va dirigido <small class="text-muted">(opcional)</small></label>
            <input type="text" name="destinatario" class="form-control" placeholder="Ej: A quien corresponda, Banco Atlántida…">
          </div>

          <div class="mb-3">
            <label class="form-label">Nota adicional <small class="text-muted">(opcional)</small></label>
            <textarea name="nota_adicional" class="form-control" rows="2"
              placeholder="Texto libre que se incluirá en el cuerpo de la carta…"></textarea>
          </div>

          <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn-proscala" id="btn-generar" disabled>
              <i class="fas fa-file-pdf"></i> Generar PDF
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="this.closest('form').reset();resetForm()">
              <i class="fas fa-rotate-left"></i> Limpiar
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
// Tipo buttons
document.querySelectorAll('.tipo-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tipo-btn').forEach(b => b.classList.remove('active','btn-proscala'));
    btn.classList.add('active','btn-proscala');
    btn.classList.remove('btn-outline-secondary');
    document.getElementById('input-tipo').value = btn.dataset.tipo;
    document.getElementById('tipo-display').textContent = btn.querySelector('strong').textContent;
    document.getElementById('tipo-display').style.fontStyle = 'normal';
    checkReady();
  });
});

// Empleado info
document.getElementById('sel-emp').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  const info = document.getElementById('emp-info');
  if (!this.value) { info.style.display='none'; checkReady(); return; }
  const ingreso = opt.dataset.ingreso;
  let antiguedad = '—';
  if (ingreso) {
    const ini = new Date(ingreso), hoy = new Date();
    let anios = hoy.getFullYear() - ini.getFullYear();
    if (hoy < new Date(hoy.getFullYear(), ini.getMonth(), ini.getDate())) anios--;
    antiguedad = anios + ' año' + (anios !== 1 ? 's' : '');
  }
  document.getElementById('ei-cargo').textContent     = opt.dataset.cargo || '—';
  document.getElementById('ei-ingreso').textContent   = ingreso ? ingreso.split('-').reverse().join('/') : '—';
  document.getElementById('ei-antiguedad').textContent = antiguedad;
  document.getElementById('ei-salario').textContent   = 'L. ' + parseFloat(opt.dataset.sueldo||0).toLocaleString('en-US',{minimumFractionDigits:2});
  info.style.display = 'block';
  checkReady();
});

function checkReady() {
  const tipoOk = !!document.getElementById('input-tipo').value;
  const empOk  = !!document.getElementById('sel-emp').value;
  document.getElementById('btn-generar').disabled = !(tipoOk && empOk);
}

// Abre la ventana de forma síncrona (dentro del gesto de clic) para que el
// navegador no la trate como popup no solicitado, y avisa si igual la bloquea.
document.getElementById('form-constancia').addEventListener('submit', function(e) {
  e.preventDefault();
  const win = window.open('', '_blank');
  if (!win) {
    alerta('El navegador bloqueó la ventana emergente. Habilita las ventanas emergentes para este sitio e inténtalo de nuevo.', { titulo: 'Ventana bloqueada', tipo: 'error' });
    return;
  }
  const params = new URLSearchParams(new FormData(this));
  win.location.href = this.action + '?' + params.toString();
});

function resetForm() {
  document.querySelectorAll('.tipo-btn').forEach(b => { b.classList.remove('active','btn-proscala'); b.classList.add('btn-outline-secondary'); });
  document.getElementById('input-tipo').value = '';
  document.getElementById('tipo-display').textContent = '— Ninguno seleccionado —';
  document.getElementById('tipo-display').style.fontStyle = 'italic';
  document.getElementById('emp-info').style.display = 'none';
  document.getElementById('btn-generar').disabled = true;
}
JS;
include __DIR__ . '/includes/footer.php';
?>

<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
$pagina = 'Configuración';

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    // ── Logo de la empresa (sale en los documentos impresos) ────────
    if ($_act === 'logo' || $_act === 'quitar_logo') {
        $anterior = logoEmpresa($pdo);
        $nuevo = '';
        if ($_act === 'logo') {
            if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) jsonErr('No se recibió ningún archivo.');
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][mime_content_type($_FILES['logo']['tmp_name'])] ?? null;
            if (!$ext) jsonErr('Solo se permiten imágenes PNG, JPG o WEBP.');
            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) jsonErr('El logo no debe superar 2 MB.');
            $dir = __DIR__ . '/' . LOGOS_DIR;
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $nuevo = 'empresa' . (int)$_SESSION['empresa_id'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dir . $nuevo)) jsonErr('No se pudo guardar el archivo.');
        }
        $pdo->prepare("INSERT INTO configuracion (clave, valor, descripcion) VALUES ('logo', ?, 'Logo de la empresa')
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$nuevo]);
        if ($anterior) @unlink(__DIR__ . '/' . $anterior);
        jsonOk(['logo' => $nuevo ? BASE_URL . LOGOS_DIR . $nuevo : ''], $nuevo ? 'Logo actualizado.' : 'Logo eliminado.');
    }

    // Días de recordatorio y escalamiento de procesos pendientes (cron/recordatorios.php)
    $rec = (int)($_POST['recordatorio_dias'] ?? 2);
    $esc = (int)($_POST['escalamiento_dias'] ?? 5);
    if ($rec < 1 || $rec > 60 || $esc < 1 || $esc > 90) jsonErr('Los días de recordatorio (1 a 60) y de escalamiento (1 a 90) deben ser números válidos.');
    if ($esc <= $rec) jsonErr('El escalamiento debe ocurrir después del recordatorio (más días).');
    foreach ($_POST as $k => $v) {
        // 'logo' solo se cambia subiendo un archivo (arriba), nunca escribiendo una ruta
        // 'rtn' identifica a la empresa en el login: solo lo cambia el super administrador (Empresas)
        if (in_array($k,['csrf_token','_action','logo','rtn'])) continue;
        $pdo->prepare("UPDATE configuracion SET valor=? WHERE clave=?")->execute([trim($v),$k]);
    }
    jsonOk([],'Configuración guardada.');
}

$cfg  = getAllConfig($pdo);
$logo = logoEmpresa($pdo);
include __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><i class="fas fa-gear"></i> Configuración del Sistema</h1>
</div>
<div class="card mb-3">
  <div class="card-body">
    <div class="form-section-title">Logo de la Empresa</div>
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div style="width:220px;height:80px;border:1px dashed var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;background:#fff;">
        <img id="logo-prev" src="<?= $logo ? BASE_URL . $logo : '' ?>" alt="Logo" style="max-width:200px;max-height:70px;<?= $logo ? '' : 'display:none' ?>">
        <span id="logo-vacio" class="text-muted" style="font-size:.8rem;<?= $logo ? 'display:none' : '' ?>">Sin logo</span>
      </div>
      <div>
        <input type="file" id="logo-file" accept="image/png,image/jpeg,image/webp" class="d-none" onchange="subirLogo(this)">
        <button type="button" class="btn-proscala" onclick="document.getElementById('logo-file').click()"><i class="fas fa-upload"></i> Subir logo</button>
        <button type="button" class="btn btn-outline-danger btn-sm ms-1" id="btn-quitar-logo" onclick="quitarLogo()" <?= $logo ? '' : 'style="display:none"' ?>><i class="fas fa-trash"></i> Quitar</button>
        <div class="text-muted mt-1" style="font-size:.75rem;">PNG, JPG o WEBP, máx. 2 MB. Aparece en los documentos impresos y PDF. Mejor con fondo transparente y sin márgenes.</div>
      </div>
    </div>
  </div>
</div>
<div class="card">
  <div class="card-body">
    <form id="form-config">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

      <div class="form-section-title">Datos de la Organización</div>
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Nombre Corto</label><input type="text" name="nombre_organizacion" class="form-control" value="<?= htmlspecialchars($cfg['nombre_organizacion']??'') ?>"></div>
        <div class="col-md-9"><label class="form-label">Nombre Completo</label><input type="text" name="nombre_completo" class="form-control" value="<?= htmlspecialchars($cfg['nombre_completo']??'') ?>"></div>
        <div class="col-md-3"><label class="form-label">RTN</label><input type="text" class="form-control" value="<?= htmlspecialchars($cfg['rtn']??'') ?>" readonly title="Identifica a la empresa al iniciar sesión. Solo lo cambia el super administrador."></div>
        <div class="col-md-3"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?= htmlspecialchars($cfg['telefono']??'') ?>"></div>
        <div class="col-md-3"><label class="form-label">Correo</label><input type="email" name="correo" class="form-control" value="<?= htmlspecialchars($cfg['correo']??'') ?>"></div>
        <div class="col-md-3"><label class="form-label">Moneda</label><select name="moneda" class="form-select"><option value="HNL" <?= ($cfg['moneda']??'HNL')==='HNL'?'selected':'' ?>>Lempiras (HNL)</option><option value="USD" <?= ($cfg['moneda']??'')==='USD'?'selected':'' ?>>Dólares (USD)</option></select></div>
        <div class="col-12"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?= htmlspecialchars($cfg['direccion']??'') ?>"></div>
      </div>

      <div class="form-section-title">Prefijos de Numeración</div>
      <div class="row g-3">
        <?php $prefijos=['prefijo_sg'=>'Sol. Gastos','prefijo_gv'=>'Gastos Viaje','prefijo_pl'=>'Planillas','prefijo_sc'=>'Sol. Compra','prefijo_co'=>'Cotizaciones','prefijo_oc'=>'Orden Compra','prefijo_op'=>'Orden Pago','prefijo_nr'=>'Nota Recepción']; ?>
        <?php foreach($prefijos as $k=>$lbl): ?>
        <div class="col-md-3"><label class="form-label"><?= $lbl ?></label><input type="text" name="<?= $k ?>" class="form-control" maxlength="5" value="<?= htmlspecialchars($cfg[$k]??'') ?>" style="text-transform:uppercase"></div>
        <?php endforeach; ?>
      </div>

      <div class="form-section-title">Procesos pendientes: recordatorios y escalamiento</div>
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Recordar después de (días)</label>
          <input type="number" name="recordatorio_dias" class="form-control" min="1" max="60" value="<?= (int)($cfg['recordatorio_dias'] ?? 2) ?>"></div>
        <div class="col-md-3"><label class="form-label">Escalar después de (días)</label>
          <input type="number" name="escalamiento_dias" class="form-control" min="1" max="90" value="<?= (int)($cfg['escalamiento_dias'] ?? 5) ?>"></div>
        <div class="col-md-6 d-flex align-items-end"><div class="text-muted" style="font-size:.78rem">
          Si un proceso lleva esos días esperando su revisión o autorización, se le recuerda a quien le toca; si sigue esperando,
          se avisa al siguiente nivel del organigrama (y cada vez que vuelven a pasar esos días, al siguiente). Se revisa una vez al día.</div></div>
      </div>

      <div class="mt-4">
        <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar Configuración</button>
      </div>
    </form>
  </div>
</div>
<?php
$extraJs = <<<'JS'
bindAjaxForm('form-config');
function mostrarLogo(url){
  const img=document.getElementById('logo-prev');
  img.src=url; img.style.display=url?'':'none';
  document.getElementById('logo-vacio').style.display=url?'none':'';
  document.getElementById('btn-quitar-logo').style.display=url?'':'none';
}
async function enviarLogo(fd){
  fd.append('csrf_token', document.querySelector('meta[name=csrf]').content);
  try { const r=await post('configuracion.php', fd); mostrarLogo(r.logo); Toast.show(r.message,'success'); }
  catch(e){ Toast.show(e.message,'error'); }
}
function subirLogo(input){
  if(!input.files.length) return;
  const fd=new FormData(); fd.append('_action','logo'); fd.append('logo', input.files[0]);
  enviarLogo(fd); input.value='';
}
async function quitarLogo(){
  if(!await confirmar('¿Quitar el logo de la empresa?', { titulo: 'Quitar logo', aceptar: 'Quitar', peligro: true })) return;
  const fd=new FormData(); fd.append('_action','quitar_logo'); enviarLogo(fd);
}
JS;
include __DIR__ . '/includes/footer.php';
?>

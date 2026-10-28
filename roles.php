<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();
if (!tieneAccesoTotal()) { header('Location: ' . BASE_URL . paginaInicio()); exit; }
$pagina = 'Roles y Permisos';

// Módulos asignables: todos menos los exclusivos del Administrador
$asignables = [];
foreach (MODULOS as $items) foreach ($items as $f => $_) if (!in_array($f, MODULOS_SOLO_ADMIN, true)) $asignables[] = $f;

/** ¿Poner a $rol bajo $padre crearía un ciclo (el padre está debajo de $rol)? */
function creaCiclo(string $rol, ?string $padre): bool {
    $roles = roles();
    for ($p = $padre, $vueltas = 0; $p !== null && $vueltas < 100; $p = $roles[$p]['depende_de'] ?? null, $vueltas++) {
        if ($p === $rol) return true;
    }
    return false;
}

/** Clave interna a partir del nombre: "Coordinación de Proyectos" → coordinacion_de_proyectos */
function claveRol(string $nombre): string {
    $sinTildes = strtr(mb_strtolower($nombre), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $c = trim(preg_replace('/[^a-z0-9]+/', '_', $sinTildes), '_');
    $c = substr($c ?: 'puesto', 0, 26);
    $base = $c;
    for ($i = 2; isset(roles()[$c]); $i++) $c = $base . '_' . $i;
    return $c;
}

if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    // ── Crear o modificar un puesto ─────────────────────────────────
    if ($_act === 'guardar_puesto') {
        $clave  = (string)($_POST['clave'] ?? '');
        $nombre = trim(preg_replace('/\s+/u', ' ', (string)($_POST['nombre'] ?? '')));
        $padre  = (string)($_POST['depende_de'] ?? '') ?: null;
        $roles  = roles();
        if ($clave !== '' && !isset($roles[$clave])) jsonErr('Puesto no encontrado.', 404);
        if (!mb_check_encoding($nombre, 'UTF-8')) jsonErr('El nombre contiene caracteres no válidos.');
        if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 60) jsonErr('El nombre del puesto debe tener entre 2 y 60 caracteres.');
        foreach ($roles as $k => $r) {
            if ($k !== $clave && mb_strtolower($r['nombre']) === mb_strtolower($nombre)) jsonErr("Ya existe un puesto llamado \"{$r['nombre']}\".");
        }
        if ($padre !== null && !isset($roles[$padre])) jsonErr('El puesto superior no existe.');
        if ($clave !== '' && ($padre === $clave || creaCiclo($clave, $padre))) {
            jsonErr('Un puesto no puede depender de sí mismo ni de un puesto que está debajo de él.');
        }
        $aprueba = $clave === 'admin' ? 1 : (isset($_POST['aprueba']) ? 1 : 0);

        if ($clave !== '') {
            $pdo->prepare("UPDATE roles SET nombre = ?, depende_de = ?, aprueba = ? WHERE clave = ?")->execute([$nombre, $padre, $aprueba, $clave]);
            registrarAuditoria($pdo, 'UPDATE', 'roles', 0, "Puesto $nombre ($clave): depende de " . ($padre ? $roles[$padre]['nombre'] : '—') . ', ' . ($aprueba ? 'aprueba procesos' : 'no aprueba procesos'));
            jsonOk([], 'Puesto actualizado.');
        }

        $clave = claveRol($nombre);
        $copiar = (string)($_POST['copiar_de'] ?? '');
        $soloPropios = isset($roles[$copiar]) ? (int)$roles[$copiar]['solo_propios'] : 0;
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO roles (clave, nombre, depende_de, aprueba, solo_propios, orden)
                           SELECT ?, ?, ?, ?, ?, COALESCE(MAX(orden), 0) + 1 FROM roles")
                ->execute([$clave, $nombre, $padre, $aprueba, $soloPropios]);
            // Copiar los módulos de un puesto parecido (opcional)
            if (isset($roles[$copiar]) && $copiar !== 'admin') {
                $pdo->prepare("INSERT INTO rol_modulos (rol, modulo) SELECT ?, modulo FROM rol_modulos WHERE rol = ?")->execute([$clave, $copiar]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            jsonErr('No se pudo crear el puesto: ' . $e->getMessage());
        }
        registrarAuditoria($pdo, 'INSERT', 'roles', 0, "Puesto creado: $nombre ($clave), depende de " . ($padre ? $roles[$padre]['nombre'] : '—')
            . (isset($roles[$copiar]) ? ", módulos copiados de {$roles[$copiar]['nombre']}" : ''));
        jsonOk(['clave' => $clave], 'Puesto creado. Asígnele sus módulos en la tabla de permisos.');
    }

    // ── Eliminar un puesto ──────────────────────────────────────────
    if ($_act === 'eliminar_puesto') {
        $clave = (string)($_POST['clave'] ?? '');
        $roles = roles();
        if (!isset($roles[$clave])) jsonErr('Puesto no encontrado.', 404);
        if (in_array($clave, ROLES_SISTEMA, true)) jsonErr("El puesto {$roles[$clave]['nombre']} lo usa el sistema y no se puede eliminar.");
        $s = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE rol = ?");
        $s->execute([$clave]);
        if ($n = (int)$s->fetchColumn()) jsonErr("No se puede eliminar: $n usuario(s) tienen este puesto. Cámbielos de puesto primero en Usuarios.");
        $pdo->beginTransaction();
        // Los puestos que dependían de él pasan a depender de su superior
        $pdo->prepare("UPDATE roles SET depende_de = ? WHERE depende_de = ?")->execute([$roles[$clave]['depende_de'], $clave]);
        $pdo->prepare("DELETE FROM rol_modulos WHERE rol = ?")->execute([$clave]);
        $pdo->prepare("DELETE FROM roles WHERE clave = ?")->execute([$clave]);
        $pdo->commit();
        registrarAuditoria($pdo, 'DELETE', 'roles', 0, "Puesto eliminado: {$roles[$clave]['nombre']} ($clave)");
        jsonOk([], 'Puesto eliminado.');
    }

    // ── Módulos de cada puesto y "solo sus solicitudes" ─────────────
    $puestos = array_diff_key(roles(), ['admin' => true]);
    $permisos = $_POST['permisos'] ?? [];
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM rol_modulos");
        $ins = $pdo->prepare("INSERT INTO rol_modulos (rol, modulo) VALUES (?, ?)");
        foreach ($puestos as $rol => $_) {
            foreach (array_intersect((array)($permisos[$rol] ?? []), $asignables) as $modulo) {
                $ins->execute([$rol, $modulo]);
            }
        }
        $opc = $pdo->prepare("UPDATE roles SET solo_propios = ? WHERE clave = ?");
        foreach ($puestos as $rol => $_) $opc->execute([isset($_POST['solo_propios'][$rol]) ? 1 : 0, $rol]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        jsonErr('No se pudieron guardar los permisos: ' . $e->getMessage());
    }
    registrarAuditoria($pdo, 'UPDATE', 'rol_modulos', 0, 'Permisos de roles actualizados');
    jsonOk([], 'Permisos guardados. Se aplican de inmediato.');
}

$roles   = roles();
$puestos = array_diff_key($roles, ['admin' => true]);
$actuales = [];
foreach ($pdo->query("SELECT rol, modulo FROM rol_modulos")->fetchAll() as $r) $actuales[$r['rol']][$r['modulo']] = true;
$conteo = $pdo->query("SELECT rol, COUNT(*) FROM usuarios WHERE activo = 1 GROUP BY rol")->fetchAll(PDO::FETCH_KEY_PAIR);

// Organigrama: hijos de cada puesto (un superior inexistente cuenta como raíz)
$hijos = [];
foreach ($roles as $k => $r) $hijos[isset($roles[$r['depende_de'] ?? '']) ? $r['depende_de'] : ''][] = $k;
function ramaOrganigrama(array $hijos, array $roles, array $conteo, string $padre): string {
    if (empty($hijos[$padre])) return '';
    $html = '<ul>';
    foreach ($hijos[$padre] as $k) {
        $n = (int)($conteo[$k] ?? 0);
        $html .= '<li><span class="org-nodo' . ($roles[$k]['aprueba'] ? ' aprueba' : '') . '">'
            . htmlspecialchars($roles[$k]['nombre'])
            . ($roles[$k]['aprueba'] ? ' <i class="fas fa-stamp" title="Aprueba procesos"></i>' : '')
            . ' <small>' . $n . '</small></span>'
            . ramaOrganigrama($hijos, $roles, $conteo, $k) . '</li>';
    }
    return $html . '</ul>';
}
include __DIR__ . '/includes/header.php';
?>
<style>
.tbl-permisos th.rol-col { writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; font-size: .78rem; padding: .5rem .25rem; vertical-align: bottom; text-align: left; }
.tbl-permisos td.chk, .tbl-permisos th.chk { text-align: center; width: 70px; }
.tbl-permisos tr.grupo td { background: var(--surface-2, #f4f6f9); font-weight: 700; font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; }
.tbl-permisos td { font-size: .85rem; vertical-align: middle; }
.tbl-permisos .form-check-input { cursor: pointer; }
.tbl-puestos td { font-size: .85rem; vertical-align: middle; }
/* Organigrama: lista anidada con líneas de conexión */
.organigrama ul { list-style: none; margin: 0; padding-left: 1.25rem; }
.organigrama > ul { padding-left: 0; }
.organigrama li { position: relative; padding: .25rem 0 .25rem 1rem; }
.organigrama li::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; border-left: 1px solid var(--border); }
.organigrama li:last-child::before { bottom: auto; height: 1.05rem; }
.organigrama li::after { content: ''; position: absolute; left: 0; top: 1.05rem; width: .8rem; border-top: 1px solid var(--border); }
.organigrama > ul > li { padding-left: 0; }
.organigrama > ul > li::before, .organigrama > ul > li::after { display: none; }
.org-nodo { display: inline-block; padding: .2rem .6rem; border: 1px solid var(--border); border-radius: var(--r-sm); background: var(--surface); font-size: .82rem; font-weight: 600; }
.org-nodo.aprueba { border-color: rgba(var(--primary-rgb), .45); }
.org-nodo i { color: var(--primary); font-size: .72rem; }
.org-nodo small { margin-left: .2rem; padding: 0 .35rem; border-radius: var(--r-full); background: var(--surface-2); color: var(--text-3); font-weight: 500; }
</style>
<div class="page-header">
  <h1><i class="fas fa-user-lock"></i> Roles y Permisos</h1>
  <div class="d-flex gap-2 flex-wrap">
    <button class="btn btn-outline-primary" onclick="nuevoPuesto()"><i class="fas fa-plus"></i> Nuevo Puesto</button>
    <button class="btn-proscala" onclick="guardarPermisos()"><i class="fas fa-save"></i> Guardar Permisos</button>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-sitemap"></i> Puestos y jerarquía</div>
      <div class="card-body p-0" style="overflow-x:auto;">
        <table class="table table-sm table-hover mb-0 tbl-puestos">
          <thead><tr><th>Puesto</th><th>Depende de</th><th class="text-center">Aprueba procesos</th><th>Sus procesos los aprueban</th><th class="text-center">Usuarios</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($roles as $k => $r): ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['nombre']) ?></strong>
                <?php if ($k === 'admin'): ?><span class="badge bg-danger ms-1">acceso total</span><?php endif; ?>
                <?php if ($r['solo_propios'] && $k !== 'admin'): ?><span class="badge bg-warning text-dark ms-1" title="Solo ve y edita sus propias solicitudes">solo las suyas</span><?php endif; ?></td>
              <td><?= $r['depende_de'] && isset($roles[$r['depende_de']]) ? htmlspecialchars($roles[$r['depende_de']]['nombre']) : '<span class="text-muted">— (nivel superior)</span>' ?></td>
              <td class="text-center"><?= $r['aprueba'] ? '<i class="fas fa-check text-success"></i>' : '<span class="text-muted">—</span>' ?></td>
              <td style="font-size:.78rem;"><?php
                // Superiores de su rama que aprueban (config/aprobaciones.php → esSuperiorDe), más el Administrador
                $aprobadores = [];
                foreach ($roles as $k2 => $r2) if ($k2 !== 'admin' && $r2['aprueba'] && esSuperiorDe($k2, $k)) $aprobadores[] = htmlspecialchars($r2['nombre']);
                echo $k === 'admin' ? '<span class="text-muted">Otro Administrador</span>'
                    : implode(', ', $aprobadores) . ($aprobadores ? ' y ' : '') . '<span class="text-muted">Administrador</span>';
              ?></td>
              <td class="text-center"><?= (int)($conteo[$k] ?? 0) ?></td>
              <td class="text-end" style="white-space:nowrap">
                <button class="btn btn-sm btn-outline-primary py-0 px-2" title="Editar" onclick='editarPuesto(<?= json_encode(['clave' => $k, 'nombre' => $r['nombre'], 'depende_de' => $r['depende_de'], 'aprueba' => (int)$r['aprueba']], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-edit"></i></button>
                <?php if (!in_array($k, ROLES_SISTEMA, true)): ?>
                <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Eliminar" onclick='eliminarPuesto(<?= json_encode($k) ?>, <?= json_encode($r['nombre'], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fas fa-trash"></i></button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-diagram-project"></i> Organigrama
        <span class="ms-auto text-muted fw-normal" style="font-size:.72rem;"><i class="fas fa-stamp"></i> aprueba · número = usuarios activos</span></div>
      <div class="card-body organigrama"><?= ramaOrganigrama($hijos, $roles, $conteo, '') ?></div>
    </div>
  </div>
</div>

<div class="alert alert-info py-2" style="font-size:.82rem;">
  <i class="fas fa-info-circle"></i>
  Marque los módulos que puede abrir cada puesto. El <strong>Administrador</strong> y el <strong>Super Administrador</strong> tienen acceso total.
  <strong>Usuarios</strong> y <strong>Roles y Permisos</strong> son exclusivos del Administrador. Todos los usuarios pueden entrar a <strong>Mi Perfil</strong>.
  Cada puesto puede imprimir los documentos de los módulos a los que tiene acceso.
  Quien crea una solicitud puede <strong>editarla mientras nadie la haya revisado, autorizado ni rechazado</strong>.
  Los puestos que <strong>aprueban procesos</strong> pueden revisarlos, autorizarlos, rechazarlos y corregirlos, nunca los que ellos mismos crearon
  y solo si están <strong>por encima</strong> de quien creó el proceso en el organigrama (el Administrador puede con todos).
  Ejemplo: si Empleado depende de Contabilidad y Contabilidad de Finanzas, los procesos de un empleado los aprueban Contabilidad, Finanzas y sus superiores.
</div>
<div class="card"><div class="card-body p-0" style="overflow-x:auto;">
  <form id="form-permisos">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <table class="table table-sm table-hover mb-0 tbl-permisos">
      <thead>
        <tr>
          <th style="vertical-align:bottom;">Módulo</th>
          <?php foreach ($puestos as $rol => $r): ?>
          <th class="rol-col chk" title="<?= (int)($conteo[$rol] ?? 0) ?> usuario(s) activo(s)"><?= htmlspecialchars($r['nombre']) ?></th>
          <?php endforeach; ?>
        </tr>
        <tr>
          <th class="text-muted fw-normal" style="font-size:.75rem;">Marcar / desmarcar todo el puesto</th>
          <?php foreach ($puestos as $rol => $_): ?>
          <th class="chk"><input type="checkbox" class="form-check-input" title="Todo" onchange="marcarColumna('<?= $rol ?>', this.checked)"></th>
          <?php endforeach; ?>
        </tr>
        <tr class="table-warning">
          <th style="font-size:.8rem;">
            <i class="fas fa-user-lock me-1"></i> Solo sus propias solicitudes
            <div class="fw-normal text-muted" style="font-size:.72rem;">En Solicitud de Compra, Anticipos, Liquidaciones, Vacaciones y Permisos solo ve y edita las que creó, editó o son a su nombre, y no puede aprobarlas.</div>
          </th>
          <?php foreach ($puestos as $rol => $r): ?>
          <th class="chk"><input type="checkbox" class="form-check-input" name="solo_propios[<?= $rol ?>]" value="1" <?= $r['solo_propios'] ? 'checked' : '' ?>></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach (MODULOS as $grupo => $items):
          $items = array_diff_key($items, array_flip(MODULOS_SOLO_ADMIN));
          if (!$items) continue; ?>
        <?php if ($grupo !== ''): ?><tr class="grupo"><td colspan="<?= count($puestos) + 1 ?>"><?= htmlspecialchars($grupo) ?></td></tr><?php endif; ?>
        <?php foreach ($items as $file => [$icon, $label]): ?>
        <tr>
          <td><i class="fas <?= $icon ?> text-muted me-1" style="width:1.1em;"></i> <?= htmlspecialchars($label) ?> <span class="text-muted font-mono" style="font-size:.72rem;"><?= $file ?></span></td>
          <?php foreach ($puestos as $rol => $_): ?>
          <td class="chk"><input type="checkbox" class="form-check-input" data-rol="<?= $rol ?>" name="permisos[<?= $rol ?>][]" value="<?= $file ?>" <?= isset($actuales[$rol][$file]) ? 'checked' : '' ?>></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </form>
</div></div>

<div class="modal fade" id="modal-puesto" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-sitemap"></i> <span id="puesto-titulo">Nuevo puesto</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <form id="form-puesto">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <input type="hidden" name="_action" value="guardar_puesto">
          <input type="hidden" name="clave" id="puesto-clave" value="">
          <div class="mb-3">
            <label class="form-label">Nombre del puesto *</label>
            <input type="text" name="nombre" id="puesto-nombre" class="form-control" maxlength="60" required placeholder="Ej.: Coordinación de Proyectos">
          </div>
          <div class="mb-3">
            <label class="form-label">Depende de</label>
            <select name="depende_de" id="puesto-depende" class="form-select">
              <option value="">— Ninguno (nivel superior) —</option>
              <?php foreach ($roles as $k => $r): ?>
              <option value="<?= $k ?>"><?= htmlspecialchars($r['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">El puesto inmediato superior: define dónde va en el organigrama.</div>
          </div>
          <div class="form-check mb-3">
            <input type="checkbox" name="aprueba" id="puesto-aprueba" class="form-check-input" value="1">
            <label class="form-check-label" for="puesto-aprueba">Aprueba procesos <span class="text-muted">(revisar, autorizar, rechazar y corregir)</span></label>
          </div>
          <div class="mb-1" id="puesto-copiar-wrap">
            <label class="form-label">Copiar los módulos de</label>
            <select name="copiar_de" class="form-select">
              <option value="">— No copiar (sin módulos) —</option>
              <?php foreach ($puestos as $k => $r): ?>
              <option value="<?= $k ?>"><?= htmlspecialchars($r['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn-proscala" onclick="guardarPuesto()"><i class="fas fa-save"></i> Guardar</button>
      </div>
    </div>
  </div>
</div>
<?php
$extraJs = <<<'JS'
function marcarColumna(rol, valor){
  document.querySelectorAll('#form-permisos input[data-rol="'+rol+'"]').forEach(c=>c.checked=valor);
}
async function guardarPermisos(){
  try {
    const r=await post('roles.php', new FormData(document.getElementById('form-permisos')));
    Toast.show(r.message,'success');
  } catch(e){ Toast.show(e.message,'error'); }
}
function abrirPuesto(p) {
  document.getElementById('puesto-titulo').textContent = p.clave ? 'Editar puesto' : 'Nuevo puesto';
  document.getElementById('puesto-clave').value = p.clave || '';
  document.getElementById('puesto-nombre').value = p.nombre || '';
  document.getElementById('puesto-aprueba').checked = !!p.aprueba;
  document.getElementById('puesto-aprueba').disabled = p.clave === 'admin';
  document.getElementById('puesto-copiar-wrap').style.display = p.clave ? 'none' : '';
  // Un puesto no puede depender de sí mismo
  const sel = document.getElementById('puesto-depende');
  [...sel.options].forEach(o => o.hidden = o.value !== '' && o.value === p.clave);
  sel.value = p.depende_de || '';
  new bootstrap.Modal(document.getElementById('modal-puesto')).show();
}
function nuevoPuesto() { abrirPuesto({}); }
function editarPuesto(p) { abrirPuesto(p); }
async function guardarPuesto() {
  try {
    const r = await post('roles.php', new FormData(document.getElementById('form-puesto')));
    Toast.show(r.message, 'success');
    bootstrap.Modal.getInstance(document.getElementById('modal-puesto'))?.hide();
    setTimeout(() => Nav.recargar(), 600);
  } catch (e) { Toast.show(e.message, 'error'); }
}
async function eliminarPuesto(clave, nombre) {
  if (!await confirmar('Los puestos que dependían de él pasarán a depender de su superior.', { titulo: '¿Eliminar el puesto "' + nombre + '"?', aceptar: 'Eliminar', peligro: true })) return;
  const fd = new FormData();
  fd.append('_action', 'eliminar_puesto');
  fd.append('clave', clave);
  fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
  try {
    const r = await post('roles.php', fd);
    Toast.show(r.message, 'success');
    setTimeout(() => Nav.recargar(), 600);
  } catch (e) { Toast.show(e.message, 'error'); }
}
JS;
include __DIR__ . '/includes/footer.php';
?>

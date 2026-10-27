<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/functions.php';
requireLogin();

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$pagina = 'Empleados';

// ── AJAX handlers ─────────────────────────────────────────────────
if (isAjax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $_act = $_POST['_action'] ?? '';

    // Usuario del sistema vinculado al empleado (rol Empleado); devuelve texto para el mensaje
    $sincronizarUsuario = function (int $empId) use ($pdo, $pdoMaster): string {
        try { $r = sincronizarUsuarioEmpleado($pdo, $pdoMaster, (int)$_SESSION['empresa_id'], $empId); }
        catch (Throwable $ex) { $r = 'Error: ' . $ex->getMessage(); }
        return match ($r) {
            'creado'                    => ' Se creó su usuario del sistema (contraseña inicial: número de identidad).',
            'vinculado'                 => ' Se vinculó con su usuario del sistema.',
            'actualizado', 'Sin correo' => '',
            default                     => " No se creó su usuario: {$r}.",
        };
    };

    // ── Convertir en usuarios a todos los empleados activos con correo ──
    if ($_act === 'crear_usuarios') {
        $conteo = ['creado' => 0, 'vinculado' => 0, 'actualizado' => 0];
        $omitidos = [];
        $lista = $pdo->query("SELECT id, CONCAT(nombre,' ',apellidos) AS nombre FROM empleados
                              WHERE activo=1 AND email IS NOT NULL AND email<>'' ORDER BY apellidos, nombre")->fetchAll();
        foreach ($lista as $e) {
            try { $r = sincronizarUsuarioEmpleado($pdo, $pdoMaster, (int)$_SESSION['empresa_id'], (int)$e['id']); }
            catch (Throwable $ex) { $r = 'Error: ' . $ex->getMessage(); }
            if (isset($conteo[$r])) $conteo[$r]++;
            else $omitidos[] = ['empleado' => $e['nombre'], 'motivo' => $r];
        }
        registrarAuditoria($pdo, 'INSERT', 'usuarios', 0,
            "Usuarios desde Empleados: {$conteo['creado']} creados, {$conteo['vinculado']} vinculados, " . count($omitidos) . ' omitidos'
            . ($omitidos ? ' (' . implode('; ', array_map(fn($o) => "{$o['empleado']}: {$o['motivo']}", $omitidos)) . ')' : ''));
        jsonOk(['conteo' => $conteo, 'omitidos' => $omitidos],
            "Usuarios creados: {$conteo['creado']}. Vinculados a usuarios existentes: {$conteo['vinculado']}. Ya tenían usuario: {$conteo['actualizado']}.");
    }

    // ── Employee: Save ─────────────────────────────────────────────
    if ($_act === 'guardar') {
        $eid = (int)($_POST['id'] ?? 0);
        $d = [
            ':nombre'         => trim($_POST['nombre'] ?? ''),
            ':apellidos'      => trim($_POST['apellidos'] ?? ''),
            ':identidad'      => trim($_POST['identidad'] ?? ''),
            ':rtn'            => trim($_POST['rtn'] ?? ''),
            ':cargo'          => trim($_POST['cargo'] ?? ''),
            ':departamento'   => trim($_POST['departamento'] ?? ''),
            ':tipo_contrato'  => $_POST['tipo_contrato'] ?? 'indefinido',
            ':fecha_ingreso'  => $_POST['fecha_ingreso'] ?: null,
            ':sueldo_mensual' => (float)($_POST['sueldo_mensual'] ?? 0),
            ':cuenta_banco'   => trim($_POST['cuenta_banco'] ?? ''),
            ':banco'          => trim($_POST['banco'] ?? ''),
            ':aplica_ihss'         => isset($_POST['aplica_ihss'])        ? 1 : 0,
            ':aplica_rap'          => isset($_POST['aplica_rap'])         ? 1 : 0,
            ':aplica_isr'          => isset($_POST['aplica_isr'])         ? 1 : 0,
            ':aplica_credimpulsa'  => isset($_POST['aplica_credimpulsa']) ? 1 : 0,
            ':ded_ihss_empleado'   => (float)($_POST['ded_ihss_empleado'] ?? 0),
            ':ded_ihss_patronal'   => (float)($_POST['ded_ihss_patronal'] ?? 0),
            ':ded_rap'             => (float)($_POST['ded_rap']           ?? 0),
            ':ded_isr'             => (float)($_POST['ded_isr']           ?? 0),
            ':ded_credimpulsa'     => (float)($_POST['ded_credimpulsa']   ?? 0),
            ':email'          => trim($_POST['email'] ?? ''),
            ':telefono'       => trim($_POST['telefono'] ?? ''),
            ':direccion'      => trim($_POST['direccion'] ?? ''),
            ':activo'         => isset($_POST['activo']) ? 1 : 0,
        ];
        // Distribución por defecto entre proyectos (se copia a cada planilla nueva)
        $dist = parseDistribucion($_POST['dist'] ?? [], ['pct_salario', 'pct_patronal']);
        $guardarDist = function (int $empId) use ($pdo, $dist): void {
            $pdo->prepare("DELETE FROM empleado_proyectos WHERE empleado_id=?")->execute([$empId]);
            $ins = $pdo->prepare("INSERT INTO empleado_proyectos (empleado_id,proyecto_id,pct_salario,pct_patronal) VALUES (?,?,?,?)");
            foreach ($dist as $r) $ins->execute([$empId, $r['proyecto_id'], $r['pct_salario'], $r['pct_patronal']]);
        };
        try {
            if ($eid) {
                // Capturar sueldo anterior antes de actualizar
                $stmtOld = $pdo->prepare("SELECT sueldo_mensual FROM empleados WHERE id=?");
                $stmtOld->execute([$eid]);
                $sueldoAnterior = (float)$stmtOld->fetchColumn();

                $sql = "UPDATE empleados SET nombre=:nombre, apellidos=:apellidos,
                    identidad=:identidad, rtn=:rtn, cargo=:cargo, departamento=:departamento,
                    tipo_contrato=:tipo_contrato, fecha_ingreso=:fecha_ingreso,
                    sueldo_mensual=:sueldo_mensual, cuenta_banco=:cuenta_banco, banco=:banco,
                    aplica_ihss=:aplica_ihss, aplica_rap=:aplica_rap, aplica_isr=:aplica_isr,
                    aplica_credimpulsa=:aplica_credimpulsa,
                    ded_ihss_empleado=:ded_ihss_empleado, ded_ihss_patronal=:ded_ihss_patronal,
                    ded_rap=:ded_rap, ded_isr=:ded_isr, ded_credimpulsa=:ded_credimpulsa,
                    email=:email, telefono=:telefono, direccion=:direccion, activo=:activo
                    WHERE id=:id";
                $d[':id'] = $eid;
                $pdo->prepare($sql)->execute($d);
                // Registrar cambio salarial si el sueldo fue modificado
                $sueldoNuevo = $d[':sueldo_mensual'];
                if (abs($sueldoNuevo - $sueldoAnterior) >= 0.01) {
                    $pct = $sueldoAnterior > 0
                        ? round(($sueldoNuevo - $sueldoAnterior) / $sueldoAnterior * 100, 2)
                        : 100.00;
                    $pdo->prepare("INSERT INTO historial_salarial (empleado_id, sueldo_anterior, sueldo_nuevo, cambio_pct, usuario_id) VALUES (?,?,?,?,?)")
                        ->execute([$eid, $sueldoAnterior, $sueldoNuevo, $pct, currentUser()['id'] ?? null]);
                }
                // Propagar salario y deducciones a planillas no pagadas
                $nuevoSueldo = $d[':sueldo_mensual'];
                $ihssE = $d[':aplica_ihss']        ? $d[':ded_ihss_empleado'] : 0;
                $ihssP = $d[':aplica_ihss']        ? $d[':ded_ihss_patronal'] : 0;
                $rap   = $d[':aplica_rap']          ? $d[':ded_rap']           : 0;
                $isr   = $d[':aplica_isr']          ? $d[':ded_isr']           : 0;
                $cred  = $d[':aplica_credimpulsa']  ? $d[':ded_credimpulsa']   : 0;
                $ded   = $ihssE + $rap + $isr + $cred;
                // sueldo_quincenal y total_bruto se recalculan según días trabajados
                $pdo->prepare("UPDATE planilla_detalle pd
                    JOIN planilla_periodos pp ON pd.periodo_id = pp.id
                    SET pd.sueldo_quincenal     = ROUND(? / 30 * pd.dias_trabajados, 2),
                        pd.total_bruto          = ROUND(? / 30 * pd.dias_trabajados, 2),
                        pd.ihss_empleado=?, pd.ihss_patronal=?,
                        pd.rap_empleado=?, pd.rap_patronal=?,
                        pd.isr=?, pd.credimpulsa=?,
                        pd.total_deducciones=?,
                        pd.sueldo_neto = ROUND(? / 30 * pd.dias_trabajados, 2) - ?
                    WHERE pd.empleado_id=?
                      AND pp.estado != 'pagada'")
                    ->execute([$nuevoSueldo, $nuevoSueldo,
                               $ihssE, $ihssP, $rap, $rap, $isr, $cred, $ded,
                               $nuevoSueldo, $ded, $eid]);
                // Recalcular totales de períodos afectados
                $pdo->prepare("UPDATE planilla_periodos pp
                    SET total_bruto       = (SELECT SUM(total_bruto)       FROM planilla_detalle WHERE periodo_id = pp.id),
                        total_deducciones = (SELECT SUM(total_deducciones)  FROM planilla_detalle WHERE periodo_id = pp.id),
                        total_neto        = (SELECT SUM(sueldo_neto)        FROM planilla_detalle WHERE periodo_id = pp.id)
                    WHERE pp.estado != 'pagada'
                      AND EXISTS (SELECT 1 FROM planilla_detalle WHERE periodo_id = pp.id AND empleado_id = ?)")
                    ->execute([$eid]);
                $guardarDist($eid);
                registrarAuditoria($pdo, 'UPDATE', 'empleados', $eid, 'Actualización empleado: ' . resumenDatosBitacora($_POST));
                jsonOk([], 'Empleado actualizado correctamente.' . $sincronizarUsuario($eid));
            } else {
                // Insert with temp code, then update to match the real auto-increment ID
                $d[':codigo'] = 'EMP_TMP';
                $sqlIns = "INSERT INTO empleados
                    (codigo,nombre,apellidos,identidad,rtn,cargo,departamento,tipo_contrato,
                     fecha_ingreso,sueldo_mensual,cuenta_banco,banco,aplica_ihss,aplica_rap,
                     aplica_isr,aplica_credimpulsa,ded_ihss_empleado,ded_ihss_patronal,
                     ded_rap,ded_isr,ded_credimpulsa,email,telefono,direccion,activo)
                    VALUES
                    (:codigo,:nombre,:apellidos,:identidad,:rtn,:cargo,:departamento,:tipo_contrato,
                     :fecha_ingreso,:sueldo_mensual,:cuenta_banco,:banco,:aplica_ihss,:aplica_rap,
                     :aplica_isr,:aplica_credimpulsa,:ded_ihss_empleado,:ded_ihss_patronal,
                     :ded_rap,:ded_isr,:ded_credimpulsa,:email,:telefono,:direccion,:activo)";
                $pdo->prepare($sqlIns)->execute($d);
                $newId  = (int)$pdo->lastInsertId();
                $codigo = 'EMP' . str_pad($newId, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("UPDATE empleados SET codigo=? WHERE id=?")->execute([$codigo, $newId]);
                // Registrar sueldo inicial
                if ($d[':sueldo_mensual'] > 0) {
                    $pdo->prepare("INSERT INTO historial_salarial (empleado_id, sueldo_anterior, sueldo_nuevo, cambio_pct, usuario_id) VALUES (?,0,?,100,?)")
                        ->execute([$newId, $d[':sueldo_mensual'], currentUser()['id'] ?? null]);
                }
                $guardarDist($newId);
                registrarAuditoria($pdo, 'INSERT', 'empleados', $newId, 'Nuevo empleado: ' . resumenDatosBitacora($_POST));
                jsonOk(['id' => $newId, 'codigo' => $codigo], "Empleado registrado. Código: {$codigo}." . $sincronizarUsuario($newId));
            }
        } catch (PDOException $e) {
            jsonErr('Error al guardar: ' . $e->getMessage());
        }
    }

    // ── Employee: Deactivate ───────────────────────────────────────
    if ($_act === 'eliminar') {
        $eid = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE empleados SET activo=0 WHERE id=?")->execute([$eid]);
        registrarAuditoria($pdo, 'DELETE', 'empleados', $eid, 'Baja empleado');
        $sincronizarUsuario($eid);   // desactiva su usuario del sistema
        jsonOk([], 'Empleado dado de baja.');
    }

    jsonErr('Acción desconocida.');
}

// ── Load catalogs ──────────────────────────────────────────────────
$cargos        = $pdo->query("SELECT * FROM cat_cargos ORDER BY nombre")->fetchAll();
$departamentos = $pdo->query("SELECT * FROM cat_departamentos ORDER BY nombre")->fetchAll();
$bancos        = $pdo->query("SELECT * FROM cat_bancos ORDER BY nombre")->fetchAll();

// ── Load employee for edit ─────────────────────────────────────────
$emp = null;
$histSalarial = [];
if ($action === 'editar' && $id) {
    $stmt = $pdo->prepare("SELECT * FROM empleados WHERE id=?");
    $stmt->execute([$id]);
    $emp = $stmt->fetch();
    if (!$emp) { header('Location: empleados.php'); exit; }
    $stmtH = $pdo->prepare("SELECT hs.*, u.nombre AS registrado_por
        FROM historial_salarial hs
        LEFT JOIN usuarios u ON hs.usuario_id = u.id
        WHERE hs.empleado_id = ? ORDER BY hs.created_at DESC");
    $stmtH->execute([$id]);
    $histSalarial = $stmtH->fetchAll();
}
$empDist = [];
if ($emp) {
    $stmtD = $pdo->prepare("SELECT proyecto_id, pct_salario, pct_patronal FROM empleado_proyectos WHERE empleado_id=? ORDER BY id");
    $stmtD->execute([$emp['id']]);
    $empDist = $stmtD->fetchAll();
}
$proyectosDist = $action === 'list' ? [] : array_values(proyectosMap($pdo));

// ── List ───────────────────────────────────────────────────────────
$empleados = [];
if ($action === 'list') {
    $empleados = $pdo->query("SELECT e.*, u.id AS usuario_id, u.rol AS usuario_rol, u.activo AS usuario_activo,
                                     u.debe_cambiar_password
                              FROM empleados e LEFT JOIN usuarios u ON u.empleado_id = e.id
                              ORDER BY e.apellidos, e.nombre")->fetchAll();
}

// ── Next auto-code (new employee only) ────────────────────────────
$nextCodigo = $emp['codigo'] ?? '';
if (!$emp && $action !== 'list') {
    $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM empleados")->fetchColumn();
    $nextCodigo = 'EMP' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);
}

include __DIR__ . '/includes/header.php';
?>

<input type="hidden" id="g-csrf" value="<?= csrfToken() ?>">

<div class="page-header">
  <h1><i class="fas fa-users"></i> Empleados</h1>
  <?php if ($action === 'list'): ?>
  <div class="d-flex gap-2 flex-wrap">
    <button type="button" class="btn-proscala-outline" onclick="crearUsuariosEmpleados()" title="Crea un usuario con rol Empleado a cada empleado activo con correo">
      <i class="fas fa-user-plus"></i> Crear usuarios del sistema
    </button>
    <a href="?action=nuevo" class="btn-proscala"><i class="fas fa-plus"></i> Nuevo Empleado</a>
  </div>
  <?php else: ?>
  <a href="?" class="btn-proscala-outline"><i class="fas fa-arrow-left"></i> Volver</a>
  <?php endif; ?>
</div>

<?php if ($action === 'list'): ?>
<!-- ── LIST VIEW ──────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-body p-0">
    <table id="tbl-empleados" class="table-proscala w-100">
      <thead>
        <tr>
          <th>Código</th><th>Nombre</th><th>Cargo</th><th>Departamento</th>
          <th>Sueldo Mensual</th><th>Estado</th><th>Usuario</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($empleados as $e): ?>
        <tr>
          <td class="font-mono"><?= htmlspecialchars($e['codigo']) ?></td>
          <td><strong><?= htmlspecialchars($e['apellidos'] . ', ' . $e['nombre']) ?></strong></td>
          <td><?= htmlspecialchars($e['cargo']) ?></td>
          <td><?= htmlspecialchars($e['departamento']) ?></td>
          <td class="font-mono"><?= lps($e['sueldo_mensual']) ?></td>
          <td><?= $e['activo']
                ? '<span class="badge bg-success">Activo</span>'
                : '<span class="badge bg-secondary">Inactivo</span>' ?></td>
          <td style="font-size:.78rem;">
            <?php if ($e['usuario_id']): ?>
              <span class="badge bg-<?= $e['usuario_activo'] ? 'primary' : 'secondary' ?>" title="<?= htmlspecialchars($e['email']) ?>">
                <i class="fas fa-user-check"></i> <?= htmlspecialchars(rolLabel($e['usuario_rol'])) ?>
              </span>
              <?php if ($e['debe_cambiar_password']): ?><span class="text-muted d-block">Sin primer ingreso</span><?php endif; ?>
            <?php elseif (trim((string)$e['email']) === ''): ?>
              <span class="text-muted">Sin correo</span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td>
            <a href="?action=editar&id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" title="Editar">
              <i class="fas fa-edit"></i>
            </a>
            <button class="btn btn-sm btn-outline-danger py-0 px-2 ms-1" title="Dar de baja"
              onclick="deleteRecord('empleados.php', <?= $e['id'] ?>, () => Nav.recargar())">
              <i class="fas fa-user-slash"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Resultado de "Crear usuarios del sistema" -->
<div class="modal fade" id="modal-usuarios-resultado" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-user-plus"></i> Usuarios del sistema</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="usuarios-resultado"></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── FORM VIEW ──────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <i class="fas fa-user-plus"></i>
    <?= $emp
        ? 'Editar Empleado: ' . htmlspecialchars($emp['nombre'] . ' ' . $emp['apellidos'])
        : 'Nuevo Empleado' ?>
  </div>
  <div class="card-body">
    <form id="form-empleado">
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="_action"    value="guardar">
      <input type="hidden" name="id"         value="<?= $emp['id'] ?? 0 ?>">

      <div class="form-section-title">Información Personal</div>
      <div class="row g-3">
        <div class="col-md-2">
          <label class="form-label">Código</label>
          <input type="text" class="form-control font-mono"
                 value="<?= htmlspecialchars($nextCodigo) ?>"
                 readonly title="Generado automáticamente"
                 style="background:#f0f4f8;cursor:default;">
          <small class="text-muted" style="font-size:.73rem;">Automático</small>
        </div>
        <div class="col-md-4">
          <label class="form-label">Nombre(s) *</label>
          <input type="text" name="nombre" class="form-control" required
                 value="<?= htmlspecialchars($emp['nombre'] ?? '') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Apellidos *</label>
          <input type="text" name="apellidos" class="form-control" required
                 value="<?= htmlspecialchars($emp['apellidos'] ?? '') ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label">Estado</label>
          <div class="form-check mt-2">
            <input type="checkbox" name="activo" class="form-check-input" id="activo"
                   <?= ($emp['activo'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="activo">Activo</label>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">No. Identidad</label>
          <input type="text" name="identidad" class="form-control"
                 value="<?= htmlspecialchars($emp['identidad'] ?? '') ?>" placeholder="0801-xxxx-xxxxx">
        </div>
        <div class="col-md-3">
          <label class="form-label">RTN</label>
          <input type="text" name="rtn" class="form-control"
                 value="<?= htmlspecialchars($emp['rtn'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Correo Electrónico</label>
          <input type="email" name="email" class="form-control"
                 value="<?= htmlspecialchars($emp['email'] ?? '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Teléfono</label>
          <input type="text" name="telefono" class="form-control"
                 value="<?= htmlspecialchars($emp['telefono'] ?? '') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Dirección</label>
          <input type="text" name="direccion" class="form-control"
                 value="<?= htmlspecialchars($emp['direccion'] ?? '') ?>">
        </div>
      </div>

      <div class="form-section-title d-flex justify-content-between align-items-center">
        <span>Información Laboral</span>
        <?php if (puedeAcceder('catalogos.php')): ?>
        <a href="<?= BASE_URL ?>catalogos.php?tab=cargos" class="fw-normal" style="font-size:.78rem;text-transform:none;letter-spacing:0;"><i class="fas fa-list"></i> Administrar cargos, departamentos y bancos</a>
        <?php endif; ?>
      </div>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Cargo *</label>
          <select name="cargo" class="form-select" required>
            <option value="">— Seleccionar cargo —</option>
            <?php foreach ($cargos as $c): ?>
            <option value="<?= htmlspecialchars($c['nombre']) ?>"
              <?= ($emp['cargo'] ?? '') === $c['nombre'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($c['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Departamento *</label>
          <select name="departamento" class="form-select" required>
            <option value="">— Seleccionar —</option>
            <?php foreach ($departamentos as $d): ?>
            <option value="<?= htmlspecialchars($d['nombre']) ?>"
              <?= ($emp['departamento'] ?? '') === $d['nombre'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($d['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Tipo de Contrato</label>
          <select name="tipo_contrato" class="form-select">
            <option value="indefinido" <?= ($emp['tipo_contrato'] ?? 'indefinido') === 'indefinido' ? 'selected' : '' ?>>Indefinido</option>
            <option value="definido"   <?= ($emp['tipo_contrato'] ?? '') === 'definido'   ? 'selected' : '' ?>>Tiempo Definido</option>
            <option value="servicio"   <?= ($emp['tipo_contrato'] ?? '') === 'servicio'   ? 'selected' : '' ?>>Servicios Profesionales</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Fecha Ingreso</label>
          <input type="text" name="fecha_ingreso" class="form-control date-input"
                 value="<?= $emp['fecha_ingreso'] ?? '' ?>">
        </div>
      </div>

      <div class="form-section-title">Información Salarial</div>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Sueldo Mensual (L.) *</label>
          <input type="number" name="sueldo_mensual" id="sueldo_mensual" class="form-control"
                 step="0.01" min="0" required
                 value="<?= $emp['sueldo_mensual'] ?? 0 ?>" onchange="calcularPreview()">
        </div>
        <div class="col-md-3">
          <label class="form-label">Banco</label>
          <select name="banco" class="form-select">
            <option value="">— Sin banco —</option>
            <?php foreach ($bancos as $b): ?>
            <option value="<?= htmlspecialchars($b['nombre']) ?>"
              <?= ($emp['banco'] ?? '') === $b['nombre'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($b['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">No. Cuenta Bancaria</label>
          <input type="text" name="cuenta_banco" class="form-control"
                 value="<?= htmlspecialchars($emp['cuenta_banco'] ?? '') ?>">
        </div>
      </div>

      <div class="form-section-title">Deducciones Quincenales</div>
      <div class="row g-2">
        <?php
        $dedRows = [
          ['ihss',        'IHSS',        true,
            [['ded_ihss_empleado','Empleado (L.)'], ['ded_ihss_patronal','Patronal (L.)']]],
          ['rap',         'RAP',         true,
            [['ded_rap','Empleado = Patronal (L.)']]],
          ['isr',         'ISR',         true,
            [['ded_isr','Quincena (L.)']]],
          ['credimpulsa', 'Credimpulsa', false,
            [['ded_credimpulsa','Quincena (L.)']]],
        ];
        foreach ($dedRows as [$key, $label, $defaultOn, $inputs]):
          $checked = ($emp['aplica_'.$key] ?? ($defaultOn ? 1 : 0)) ? 'checked' : '';
        ?>
        <div class="col-12 col-lg-6">
          <div class="border rounded p-2 d-flex align-items-start gap-3">
            <div class="form-check mt-1 mb-0" style="min-width:100px">
              <input type="checkbox" name="aplica_<?= $key ?>" class="form-check-input"
                     id="ch_<?= $key ?>" <?= $checked ?>
                     onchange="toggleDed('<?= $key ?>'); calcularPreview()">
              <label class="form-check-label fw-semibold" for="ch_<?= $key ?>"><?= $label ?></label>
            </div>
            <div id="grp-<?= $key ?>" class="row g-1 flex-grow-1">
              <?php foreach ($inputs as [$name, $lbl]): ?>
              <div class="col">
                <label class="form-label mb-0" style="font-size:.7rem"><?= $lbl ?></label>
                <input type="number" name="<?= $name ?>" id="<?= $name ?>"
                       class="form-control form-control-sm" step="0.01" min="0"
                       value="<?= number_format((float)($emp[$name] ?? 0), 2, '.', '') ?>"
                       oninput="calcularPreview()">
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Payroll preview -->
      <div id="preview-planilla" class="mt-3 p-3 rounded preview-planilla-box" style="display:none;">
        <p class="mb-2 fw-bold" style="font-size:.85rem;color:var(--ah-blue);">
          <i class="fas fa-calculator"></i> Vista Previa Quincenal
        </p>
        <div class="row g-2" style="font-size:.8rem;">
          <div class="col-auto">Bruta: <strong id="prev-bruto">L. 0.00</strong></div>
          <div class="col-auto">IHSS: <strong id="prev-ihss" class="text-danger">L. 0.00</strong></div>
          <div class="col-auto">RAP: <strong id="prev-rap" class="text-danger">L. 0.00</strong></div>
          <div class="col-auto">ISR: <strong id="prev-isr" class="text-danger">L. 0.00</strong></div>
          <div class="col-auto">Credimpulsa: <strong id="prev-cred" class="text-danger">L. 0.00</strong></div>
          <div class="col-auto">Neto: <strong id="prev-neto" class="text-success">L. 0.00</strong></div>
        </div>
      </div>

      <div class="form-section-title">Distribución por Proyecto</div>
      <p class="text-muted mb-2" style="font-size:.8rem;">
        Porcentaje del salario y del costo patronal (IHSS / RAP patronal) que paga cada proyecto.
        Cada columna debe sumar 100%. Puede escribir el % o el monto de la quincena: el otro se calcula solo.
        Se copia a cada planilla nueva y puede ajustarse en la planilla.
      </p>
      <div style="max-width:880px">
        <table class="table table-sm align-middle mb-1" id="tbl-dist" style="font-size:.83rem;">
          <thead>
            <tr>
              <th>Proyecto</th>
              <th style="width:120px" class="text-end">% Salario</th>
              <th style="width:120px" class="text-end">% Patronal</th>
              <th style="width:150px" class="text-end">Monto Quincenal (L.)</th>
              <th style="width:50px"></th>
            </tr>
          </thead>
          <tbody id="dist-body"></tbody>
          <tfoot>
            <tr>
              <td><span data-dist-msg></span></td>
              <td class="text-end font-mono fw-bold" data-tot="pct_salario"></td>
              <td class="text-end font-mono fw-bold" data-tot="pct_patronal"></td>
              <td class="text-end font-mono fw-bold" data-tot="monto"></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="distEmp.add()">
          <i class="fas fa-plus"></i> Agregar proyecto
        </button>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn-proscala"><i class="fas fa-save"></i> Guardar</button>
        <a href="?" class="btn btn-outline-secondary btn-sm">Cancelar</a>
      </div>
    </form>
  </div>
</div>

<?php if (!empty($histSalarial)): ?>
<div class="card mt-3">
  <div class="card-header">
    <i class="fas fa-chart-line"></i> Historial Salarial
    <span class="badge bg-secondary ms-2"><?= count($histSalarial) ?> registros</span>
  </div>
  <div class="card-body p-0">
    <table class="table-proscala w-100" style="font-size:.82rem;">
      <thead>
        <tr>
          <th>Fecha</th>
          <th class="text-end">Sueldo Anterior</th>
          <th class="text-end">Sueldo Nuevo</th>
          <th class="text-center">Variación</th>
          <th>Registrado por</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($histSalarial as $h):
          $esIngreso  = $h['sueldo_anterior'] < 0.01;
          $pct        = (float)$h['cambio_pct'];
          $subida     = $pct >= 0;
          if ($esIngreso) {
              $badge = '<span class="badge bg-primary">Incorporación</span>';
          } elseif ($subida) {
              $badge = '<span class="badge bg-success">↑ +' . number_format($pct, 2) . '%</span>';
          } else {
              $badge = '<span class="badge bg-danger">↓ ' . number_format($pct, 2) . '%</span>';
          }
        ?>
        <tr>
          <td class="text-muted" style="white-space:nowrap"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
          <td class="font-mono text-end"><?= $esIngreso ? '<span class="text-muted">—</span>' : lps($h['sueldo_anterior']) ?></td>
          <td class="font-mono text-end fw-semibold"><?= lps($h['sueldo_nuevo']) ?></td>
          <td class="text-center"><?= $badge ?></td>
          <td class="text-muted"><?= htmlspecialchars($h['registrado_por'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
if ($action === 'list'):
$extraJs = <<<'ENDJS'
initDataTable('#tbl-empleados');

// Convierte en usuarios (rol Empleado) a los empleados activos con correo
async function crearUsuariosEmpleados() {
    if (!await confirmar('Se creará un usuario del sistema, con rol Empleado, para cada empleado activo que tenga correo y aún no tenga usuario.\n\nSu contraseña inicial será su número de identidad (sin guiones) y deberán cambiarla al entrar por primera vez.', { titulo: 'Crear usuarios de empleados', aceptar: 'Crear usuarios' })) return;
    const fd = new FormData();
    fd.append('csrf_token', document.getElementById('g-csrf').value);
    fd.append('_action', 'crear_usuarios');
    try {
        const r = await post('empleados.php', fd);
        const esc = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        let html = '<p>' + esc(r.message) + '</p>';
        if (r.omitidos.length) {
            html += '<p class="mb-1"><strong>No se crearon (' + r.omitidos.length + '):</strong></p><ul class="mb-0" style="font-size:.85rem">'
                  + r.omitidos.map(o => '<li>' + esc(o.empleado) + ': <span class="text-muted">' + esc(o.motivo) + '</span></li>').join('')
                  + '</ul>';
        }
        document.getElementById('usuarios-resultado').innerHTML = html;
        const m = document.getElementById('modal-usuarios-resultado');
        m.addEventListener('hidden.bs.modal', () => Nav.recargar(), { once: true });
        new bootstrap.Modal(m).show();
    } catch (e) { Toast.show(e.message, 'error'); }
}
ENDJS;
else:
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$extraJs = 'const PROYECTOS = ' . json_encode($proyectosDist, $jsonFlags) . ";\n"
         . 'const EMP_DIST = ' . json_encode($empDist, $jsonFlags) . ";\n";
$extraJs .= <<<'ENDJS'
bindAjaxForm('form-empleado', () => { setTimeout(() => Nav.ir('empleados.php'), 1200); });

// Monto quincenal = sueldo mensual / 2 (misma base que al generar la planilla)
const distEmp = new DistEditor(document.getElementById('dist-body'), {
    proyectos: PROYECTOS, campos: ['pct_salario', 'pct_patronal'], prefix: 'dist',
    monto: { campo: 'pct_salario', base: () => (parseFloat(document.getElementById('sueldo_mensual').value) || 0) / 2 }
});
distEmp.set(EMP_DIST);
document.getElementById('sueldo_mensual').addEventListener('input', () => distEmp.refreshMontos());

function toggleDed(type) {
    const chk = document.getElementById('ch_' + type);
    const grp = document.getElementById('grp-' + type);
    if (!grp) return;
    grp.querySelectorAll('input[type="number"]').forEach(inp => inp.disabled = !chk.checked);
    grp.style.opacity = chk.checked ? '1' : '0.4';
}
function calcularPreview() {
    const sueldo = parseFloat(document.getElementById('sueldo_mensual')?.value || 0);
    if (!sueldo) { document.getElementById('preview-planilla').style.display = 'none'; return; }
    const q    = sueldo / 2;
    const chk  = id => document.getElementById(id)?.checked;
    const val  = id => parseFloat(document.getElementById(id)?.value || 0);
    const ihss = chk('ch_ihss')        ? val('ded_ihss_empleado') : 0;
    const rap  = chk('ch_rap')         ? val('ded_rap')           : 0;
    const isr  = chk('ch_isr')         ? val('ded_isr')           : 0;
    const cred = chk('ch_credimpulsa') ? val('ded_credimpulsa')   : 0;
    const neto = q - ihss - rap - isr - cred;
    const fmt  = n => 'L. ' + n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    document.getElementById('prev-bruto').textContent = fmt(q);
    document.getElementById('prev-ihss').textContent  = fmt(ihss);
    document.getElementById('prev-rap').textContent   = fmt(rap);
    document.getElementById('prev-isr').textContent   = fmt(isr);
    document.getElementById('prev-cred').textContent  = fmt(cred);
    document.getElementById('prev-neto').textContent  = fmt(neto);
    document.getElementById('preview-planilla').style.display = 'block';
}
['ihss','rap','isr','credimpulsa'].forEach(toggleDed);
calcularPreview();
ENDJS;
endif;

include __DIR__ . '/includes/footer.php';
?>

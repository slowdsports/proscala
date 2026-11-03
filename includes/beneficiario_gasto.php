<?php
// Beneficiario de un anticipo o de una liquidación/reembolso: un empleado o un proveedor
// (consultores que no son empleados). Lo lee beneficiarioGastoDelFormulario() (config/auth.php).
// Recibe: $benefFila (el registro, o null si es nuevo), $empleados ([id, nombre]) y $proveedores
// ([id, nombre, categoria]), $benefIdEmpleado (id del <select> del empleado) y opcionalmente
// $benefCol (clase de la columna). Un consultor con usuario solo pide a su nombre: no elige.
$benefConsultor = (int)($_SESSION['proveedor_id'] ?? 0);
if ($benefConsultor) {
    $empleados = [];
    $proveedores = array_values(array_filter($proveedores, fn($p) => (int)$p['id'] === $benefConsultor));
    $benefFila = ['empleado_id' => null, 'proveedor_id' => $benefConsultor];
}
$benefEsProveedor = !empty($benefFila['proveedor_id']);
$benefCol ??= 'col-md-5';
$benefOpcion = fn($p) => '<option value="' . (int)$p['id'] . '"' . ((int)($benefFila['proveedor_id'] ?? 0) === (int)$p['id'] ? ' selected' : '') . '>'
    . htmlspecialchars($p['nombre']) . '</option>';
$benefConsultores = array_filter($proveedores, fn($p) => ($p['categoria'] ?? '') === 'consultor');
$benefOtros = array_filter($proveedores, fn($p) => ($p['categoria'] ?? '') !== 'consultor');
?>
<div class="<?= $benefCol ?> beneficiario-gasto">
  <div class="d-flex justify-content-between align-items-center gap-2 mb-1 flex-wrap">
    <label class="form-label mb-0">Beneficiario *</label>
    <div class="btn-group btn-group-sm" role="group" aria-label="Tipo de beneficiario" <?= $benefConsultor ? 'hidden' : '' ?>>
      <input type="radio" class="btn-check" name="beneficiario_tipo" id="<?= $benefIdEmpleado ?>-t-emp" value="empleado" <?= $benefEsProveedor ? '' : 'checked' ?> onchange="Beneficiario.cambiar(this)">
      <label class="btn btn-outline-secondary py-0" for="<?= $benefIdEmpleado ?>-t-emp"><i class="fas fa-user"></i> Empleado</label>
      <input type="radio" class="btn-check" name="beneficiario_tipo" id="<?= $benefIdEmpleado ?>-t-prov" value="proveedor" <?= $benefEsProveedor ? 'checked' : '' ?> onchange="Beneficiario.cambiar(this)">
      <label class="btn btn-outline-secondary py-0" for="<?= $benefIdEmpleado ?>-t-prov"><i class="fas fa-truck"></i> Proveedor / consultor</label>
    </div>
  </div>
  <div class="benef-empleado" <?= $benefEsProveedor ? 'hidden' : '' ?>>
    <select name="empleado_id" id="<?= $benefIdEmpleado ?>" class="form-select" <?= $benefEsProveedor ? '' : 'required' ?>>
      <option value="">-- Seleccione el empleado --</option>
      <?php $benefEmp = $benefFila ? (int)$benefFila['empleado_id'] : (int)($_SESSION['empleado_id'] ?? 0);
      foreach ($empleados as $e): ?>
      <option value="<?= $e['id'] ?>" <?= $benefEmp === (int)$e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="benef-proveedor" <?= $benefEsProveedor ? '' : 'hidden' ?>>
    <select name="proveedor_id" id="<?= $benefIdEmpleado ?>-prov" class="form-select" <?= $benefConsultor ? '' : 'data-alta="proveedor"' ?> <?= $benefEsProveedor ? 'required' : '' ?>>
      <?php if (!$benefConsultor): ?><option value="">-- Seleccione el proveedor o consultor --</option><?php endif; ?>
      <?php if ($benefConsultores && $benefOtros): ?>
      <optgroup label="Consultores"><?= implode('', array_map($benefOpcion, $benefConsultores)) ?></optgroup>
      <optgroup label="Otros proveedores"><?= implode('', array_map($benefOpcion, $benefOtros)) ?></optgroup>
      <?php else: ?>
      <?= implode('', array_map($benefOpcion, $proveedores)) ?>
      <?php endif; ?>
    </select>
    <?php if ($benefConsultor): ?><div class="form-text">Se registra a su nombre.</div><?php endif; ?>
  </div>
</div>

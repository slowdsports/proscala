<?php
// index.php — Interfaz de llenado masivo
// 1) Puede llenar la tabla a mano, o
// 2) Descargar la plantilla Excel, llenarla, y subirla — las filas se cargan
//    automáticamente a la tabla.
// Al pulsar "Calcular Todos" cada fila se envía (una por una) a api.php, que
// consulta el motor real de la STSS y trae el resultado, incluyendo
// opcionalmente el enlace a la constancia oficial (PDF) generada por la STSS.
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Cálculo Masivo de Prestaciones — STSS Honduras</title>
<style>
  :root{--navy:#1F3864;--blue:#2E5597;--lightblue:#DCE6F1;--green:#E2EFDA;--red:#FFC7CE;}
  body{font-family:Arial,Helvetica,sans-serif;margin:24px;color:#222;background:#f7f8fa;}
  h1{color:var(--navy);font-size:20px;margin-bottom:4px;}
  h2{color:var(--navy);font-size:14px;margin:18px 0 8px;}
  p.sub{color:#666;margin-top:0;font-size:13px;max-width:900px;}
  table{border-collapse:collapse;width:100%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.1);}
  th,td{border:1px solid #ccd3dc;padding:6px 8px;font-size:13px;}
  th{background:var(--navy);color:#fff;font-weight:600;position:sticky;top:0;}
  input,select{width:100%;box-sizing:border-box;padding:4px;font-size:13px;border:1px solid #b9c2cf;border-radius:3px;}
  td.input-cell{background:var(--lightblue);}
  td.result-cell{background:#fafafa;text-align:right;font-variant-numeric:tabular-nums;}
  tr.ok td.result-cell{background:var(--green);}
  tr.error td.result-cell{background:var(--red);}
  .estado{font-size:12px;}
  .toolbar{margin:14px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .panel{background:#fff;border:1px solid #ccd3dc;border-radius:6px;padding:14px 16px;margin-bottom:18px;}
  button,.btn{background:var(--blue);color:#fff;border:none;padding:8px 14px;border-radius:4px;cursor:pointer;font-size:13px;text-decoration:none;display:inline-block;}
  button:hover,.btn:hover{background:var(--navy);}
  button.secondary,.btn.secondary{background:#777;}
  button.descarga{background:#2e7d32;}
  button.descarga:hover{background:#1b5e20;}
  #progreso{font-size:13px;color:#444;}
  .total-row td{font-weight:bold;background:#fff3cd;}
  .err-msg{font-size:11px;color:#9c0006;max-width:220px;}
  .avisos{font-size:12px;color:#7a5b00;background:#fff8e1;border:1px solid #ffe082;border-radius:4px;padding:8px 10px;margin-top:8px;white-space:pre-line;}
  .constancia-link{font-size:11px;}
  label.chk{display:flex;align-items:center;gap:6px;font-size:13px;color:#444;width:auto;}
  label.chk input{width:auto;}
</style>
</head>
<body>

<h1>Cálculo Masivo de Prestaciones Laborales — Honduras</h1>
<p class="sub">
  Cada fila se envía al motor real de la <strong>Secretaría de Trabajo y Seguridad Social (STSS)</strong> a través
  de <code>api.php</code>, que hace el cálculo oficial y trae el resultado — no es una fórmula propia, es el mismo
  cálculo que vería en el portal. Se procesan una por una (no en paralelo) para no saturar el servidor de la STSS.
</p>

<div class="panel">
  <h2>1. Plantilla de carga masiva</h2>
  <p class="sub">Descargue la plantilla, llénela con sus empleados (Nombre, DNI, Fecha Ingreso, Fecha Finalización,
    Motivo, Salario — Empresa y Sexo son opcionales) y súbala aquí. Las filas se agregan automáticamente a la tabla
    de abajo.</p>
  <div class="toolbar">
    <a class="btn descarga" href="templates/plantilla_empleados.xlsx" download>⬇ Descargar plantilla Excel</a>
    <input type="file" id="inputArchivo" accept=".xlsx" style="width:auto;">
    <button id="btnSubir" class="secondary">Subir y cargar filas</button>
    <span id="estadoSubida" style="font-size:12px;color:#444;"></span>
  </div>
  <div id="avisosSubida"></div>
</div>

<div class="toolbar">
  <button id="btnAgregar">+ Agregar fila</button>
  <button id="btnCalcular">▶ Calcular Todos</button>
  <button id="btnExportar" class="secondary">⬇ Exportar CSV</button>
  <label class="chk"><input type="checkbox" id="chkConstancia"> Generar enlace a constancia oficial (PDF) —
    esto GUARDA un registro real en la STSS por cada trabajador</label>
  <span id="progreso"></span>
</div>

<table id="tabla">
  <thead>
    <tr>
      <th style="width:26px">#</th>
      <th style="width:150px">Nombre</th>
      <th style="width:110px">DNI / Identidad</th>
      <th style="width:105px">Fecha Ingreso</th>
      <th style="width:105px">Fecha Finalización</th>
      <th style="width:160px">Motivo de Terminación</th>
      <th style="width:100px">Salario Mensual (Lps)</th>
      <th style="width:85px">Preaviso</th>
      <th style="width:85px">Cesantía</th>
      <th style="width:95px">Cesantía Prop.</th>
      <th style="width:95px">Vacac. Prop.</th>
      <th style="width:95px">Aguinaldo Prop.</th>
      <th style="width:85px">14° Prop.</th>
      <th style="width:105px">TOTAL A PAGAR</th>
      <th style="width:90px">Constancia</th>
      <th style="width:130px">Estado</th>
    </tr>
  </thead>
  <tbody id="cuerpo"></tbody>
  <tfoot>
    <tr class="total-row">
      <td colspan="13" style="text-align:right">TOTAL GENERAL (Lps):</td>
      <td id="totalGeneral" style="text-align:right">0.00</td>
      <td colspan="2"></td>
    </tr>
  </tfoot>
</table>

<script>
const MOTIVOS = ["Despido", "Renuncia", "Despido Justificado", "Por Muerte Natural", "Cálculo de Anticipo de Cesantía"];
const cuerpo = document.getElementById('cuerpo');
let filaId = 0;

function nuevaFila(datos = {}) {
  filaId++;
  const tr = document.createElement('tr');
  tr.dataset.id = filaId;
  tr.innerHTML = `
    <td>${filaId}</td>
    <td class="input-cell"><input type="text" class="nombre" value="${datos.nombre||''}"></td>
    <td class="input-cell"><input type="text" class="dni" value="${datos.dni||''}"></td>
    <td class="input-cell"><input type="date" class="fecha_ingreso" value="${datos.fecha_ingreso||''}"></td>
    <td class="input-cell"><input type="date" class="fecha_fin" value="${datos.fecha_fin||''}"></td>
    <td class="input-cell">
      <select class="motivo">${MOTIVOS.map(m=>`<option ${m===datos.motivo?'selected':''}>${m}</option>`).join('')}</select>
    </td>
    <td class="input-cell"><input type="number" step="0.01" class="salario" value="${datos.salario||''}"></td>
    <td class="result-cell preaviso">—</td>
    <td class="result-cell cesantia">—</td>
    <td class="result-cell cesantia_prop">—</td>
    <td class="result-cell vac_prop">—</td>
    <td class="result-cell agui_prop">—</td>
    <td class="result-cell d14_prop">—</td>
    <td class="result-cell total">—</td>
    <td class="result-cell constancia">—</td>
    <td class="estado">Sin calcular</td>
  `;
  tr.dataset.empresa = datos.empresa || '';
  tr.dataset.sexo = datos.sexo || '';
  cuerpo.appendChild(tr);
  return tr;
}

// 5 filas de ejemplo para empezar
for (let i = 0; i < 5; i++) nuevaFila();

document.getElementById('btnAgregar').onclick = () => nuevaFila();

function fmt(n) {
  if (n === null || n === undefined || isNaN(n)) return '—';
  return Number(n).toLocaleString('es-HN', {minimumFractionDigits:2, maximumFractionDigits:2});
}

// ---- Subida de plantilla Excel ----
document.getElementById('btnSubir').onclick = async () => {
  const input = document.getElementById('inputArchivo');
  const estado = document.getElementById('estadoSubida');
  const avisosDiv = document.getElementById('avisosSubida');
  avisosDiv.innerHTML = '';
  if (!input.files.length) {
    estado.textContent = 'Seleccione un archivo .xlsx primero.';
    return;
  }
  estado.textContent = 'Leyendo archivo…';
  const fd = new FormData();
  fd.append('archivo', input.files[0]);
  try {
    const resp = await fetch('upload.php', { method: 'POST', body: fd });
    const json = await resp.json();
    if (!json.ok) throw new Error(json.error || 'Error al leer el archivo');
    json.empleados.forEach(emp => nuevaFila(emp));
    estado.textContent = `✓ ${json.empleados.length} fila(s) agregada(s) a la tabla.`;
    if (json.avisos && json.avisos.length) {
      avisosDiv.innerHTML = `<div class="avisos">${json.avisos.join('\n')}</div>`;
    }
  } catch (e) {
    estado.textContent = '✗ ' + e.message;
  }
};

// ---- Cálculo de cada fila ----
async function calcularFila(tr, conConstancia) {
  const datos = {
    nombre: tr.querySelector('.nombre').value.trim(),
    dni: tr.querySelector('.dni').value.trim(),
    fecha_ingreso: tr.querySelector('.fecha_ingreso').value,
    fecha_fin: tr.querySelector('.fecha_fin').value,
    motivo: tr.querySelector('.motivo').value,
    salario: tr.querySelector('.salario').value,
    empresa: tr.dataset.empresa || undefined,
    sexo: tr.dataset.sexo || undefined,
    con_constancia: conConstancia,
  };
  const estado = tr.querySelector('.estado');

  if (!datos.nombre || !datos.dni || !datos.fecha_ingreso || !datos.fecha_fin || !datos.salario) {
    estado.textContent = 'Fila incompleta (omitida)';
    return;
  }

  estado.textContent = 'Calculando…';
  tr.classList.remove('ok','error');

  try {
    const resp = await fetch('api.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(datos)
    });
    const json = await resp.json();
    if (!json.ok) throw new Error(json.error || 'Error desconocido');

    const r = json.resultado;
    tr.querySelector('.preaviso').textContent = fmt(r.preaviso);
    tr.querySelector('.cesantia').textContent = fmt(r.cesantia);
    tr.querySelector('.cesantia_prop').textContent = fmt(r.cesantia_proporcional);
    tr.querySelector('.vac_prop').textContent = fmt(r.vacaciones_proporcional);
    tr.querySelector('.agui_prop').textContent = fmt(r.aguinaldo_proporcional);
    tr.querySelector('.d14_prop').textContent = fmt(r.decimo_cuarto_proporcional);
    tr.querySelector('.total').textContent = fmt(r.total_a_pagar);
    const celdaConstancia = tr.querySelector('.constancia');
    if (r.url_constancia) {
      celdaConstancia.innerHTML = `<a class="constancia-link" href="${r.url_constancia}" target="_blank">Ver PDF</a>`;
    } else {
      celdaConstancia.textContent = conConstancia ? 'No disp.' : '—';
    }
    tr.dataset.total = r.total_a_pagar || 0;
    tr.classList.add('ok');
    estado.innerHTML = '✓ Calculado';
  } catch (e) {
    tr.classList.add('error');
    estado.innerHTML = `✗ Error<div class="err-msg">${e.message}</div>`;
  }
}

document.getElementById('btnCalcular').onclick = async () => {
  const filas = Array.from(cuerpo.querySelectorAll('tr'));
  const progreso = document.getElementById('progreso');
  const conConstancia = document.getElementById('chkConstancia').checked;
  let i = 0;
  for (const tr of filas) {
    i++;
    progreso.textContent = `Procesando fila ${i} de ${filas.length}…`;
    await calcularFila(tr, conConstancia);
    await new Promise(res => setTimeout(res, 400)); // pequeña pausa entre solicitudes
  }
  progreso.textContent = `Listo: ${filas.length} fila(s) procesadas.`;
  let totalGeneral = 0;
  filas.forEach(tr => totalGeneral += parseFloat(tr.dataset.total || 0));
  document.getElementById('totalGeneral').textContent = fmt(totalGeneral);
};

document.getElementById('btnExportar').onclick = () => {
  const filas = Array.from(cuerpo.querySelectorAll('tr'));
  const encabezados = ['Nombre','DNI','Fecha Ingreso','Fecha Fin','Motivo','Salario',
    'Preaviso','Cesantia','Cesantia Proporcional','Vacaciones Proporcional','Aguinaldo Proporcional',
    'Decimo Cuarto Proporcional','TOTAL A PAGAR','Constancia'];
  const filasCsv = filas.map(tr => [
    tr.querySelector('.nombre').value,
    tr.querySelector('.dni').value,
    tr.querySelector('.fecha_ingreso').value,
    tr.querySelector('.fecha_fin').value,
    tr.querySelector('.motivo').value,
    tr.querySelector('.salario').value,
    tr.querySelector('.preaviso').textContent,
    tr.querySelector('.cesantia').textContent,
    tr.querySelector('.cesantia_prop').textContent,
    tr.querySelector('.vac_prop').textContent,
    tr.querySelector('.agui_prop').textContent,
    tr.querySelector('.d14_prop').textContent,
    tr.querySelector('.total').textContent,
    (tr.querySelector('.constancia a') ? tr.querySelector('.constancia a').href : ''),
  ].map(v => `"${String(v).replace(/"/g,'""')}"`).join(','));
  const csv = [encabezados.join(','), ...filasCsv].join('\n');
  const blob = new Blob(['\uFEFF' + csv], {type:'text/csv;charset=utf-8;'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'calculo_prestaciones_stss.csv';
  a.click();
};
</script>
</body>
</html>

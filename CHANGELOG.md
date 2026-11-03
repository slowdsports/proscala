# Proscala — Historial de versiones

La versión vigente está en el archivo `VERSION` y se muestra en el pie del sistema y en el inicio de sesión.

Formato **MAYOR.MENOR.PARCHE**:
- **MAYOR**: cambios que obligan a reinstalar o migrar datos de forma incompatible.
- **MENOR**: funciones nuevas o cambios visibles para los usuarios.
- **PARCHE**: correcciones que no cambian el uso del sistema.

Cada versión queda registrada en Git con una etiqueta `vX.Y.Z`.

---

## 1.25.0 — 2026-11-03
**Sin registros duplicados por varios clics, y botones que muestran lo que hace el servidor**
- **Seguridad (servidor)**: si un usuario envía exactamente lo mismo dos veces seguidas en el mismo módulo en menos de 10 segundos (p. ej. varios clics en Guardar o Crear), el segundo envío se rechaza sin ejecutar nada, con el aviso "Esta acción ya se envió hace unos segundos y no se repitió", y queda en la bitácora como *Envío repetido bloqueado* (`config/envios.php`, tabla nueva `envios_recientes`).
  - Solo se compara con el último envío del usuario en ese módulo: un envío distinto, o volver a un valor anterior (A → B → A), sí se ejecuta.
  - Si el envío anterior falló (p. ej. faltaba un dato), se puede corregir y reintentar de inmediato.
  - Funciona aunque las peticiones lleguen al mismo tiempo, y los 10 segundos cuentan desde que terminó la anterior.
- **Botones**: al hacer clic en Guardar, Crear, Editar, Eliminar, Autorizar, etc., el botón se deshabilita y cambia a "Agregando…", "Guardando cambios…", "Editando…", "Eliminando…", "Autorizando…"… mientras el servidor procesa. Solo dice "Agregado" / "Cambios guardados" / "Listo" cuando el servidor confirma que se hizo; si falla, vuelve a su texto de inmediato y se muestra el motivo. Presionar Enter en el formulario mientras procesa no lo reenvía (`assets/js/proscala.js`, `BotonEnvio`).
- Requiere ejecutar `actualizar_bd.php` (crea la tabla `envios_recientes`). Mientras no se ejecute, el sistema funciona sin el bloqueo del servidor.

## 1.24.0 — 2026-11-03
**Prueba de correo (herramienta de desarrollador)**
- Nueva página **Prueba de correo** (`prueba_correo.php`), en el menú *Super Administrador* y solo para el super administrador: muestra la configuración del correo saliente del servidor actual (sin la contraseña) y su entorno (PHP, OpenSSL, `mail()`), prueba la conexión al servidor SMTP sin enviar nada y envía un correo de prueba a la dirección indicada.
- Muestra el resultado con el diálogo completo con el servidor SMTP y el error técnico, para diagnosticar fallos (usuario y contraseña ocultos). `enviarCorreo()` acepta un parámetro opcional para devolver ese diálogo.

## 1.23.1 — 2026-11-03
**Las listas desplegables muestran el valor guardado al editar**
- **Corrección**: al editar un registro (p. ej. el banco de un proveedor), algunas listas con buscador aparecían vacías aunque el valor guardado sí estaba elegido (se veía al abrirlas). Ahora toda lista con buscador se actualiza sola cuando el formulario se llena o se reinicia por código, en todo el sistema (`assets/js/proscala.js`).
- En Proveedores, un banco guardado que ya no está en el catálogo de bancos se conserva en la lista al editar.

## 1.23.0 — 2026-11-03
**Expediente digital de los proveedores**
- **Expediente del proveedor** (`proveedores.php?action=expediente`), como el de los empleados: sus datos y sus documentos clasificados por categoría (Identificación / DNI, RTN, Antecedentes penales y policiales, Hoja de vida, Títulos y certificados, Contrato de servicios y adendas, Escritura / registro mercantil y permisos, Solvencias y constancias, Cuenta bancaria, Otros). Se sube desde la computadora, con la cámara del celular o arrastrando los archivos.
- En la lista de proveedores, el botón de la carpeta abre el expediente y una etiqueta muestra cuántos documentos tiene. Al crear o editar un proveedor se pueden elegir documentos que se suben al guardar, y el formulario enlaza a su expediente.
- Solo lo ve quien tiene el módulo Proveedores (información personal: el consultor no ve su propio expediente).
- Pruebas automáticas: la del expediente cubre ahora empleados y proveedores (y elige un usuario que ya cambió su contraseña inicial para probar el acceso denegado).

## 1.22.0 — 2026-11-03
**Consultores con usuario: piden sus propios anticipos y liquidaciones**
- **Proveedores: categoría Consultores**. Al elegirla, el formulario pide el número de identidad y ofrece **"Darle usuario para entrar al sistema"**: su usuario es el correo del proveedor; con contraseña inicial (mínimo 8 caracteres, letras y números) la cambia al entrar, y sin ella recibe por correo el enlace para crearla (con botón para reenviarlo). La lista muestra si el consultor tiene acceso, si está pendiente de activar o si se le quitó. Quitar el acceso, dar de baja al proveedor o cambiarle la categoría desactiva su usuario.
- **Puesto Consultor** en Roles y Permisos (bajo Administración, "solo sus propias solicitudes"), con los módulos Anticipos y Liquidaciones / Reembolsos; su jerarquía y permisos se ajustan como los de cualquier puesto.
- El consultor entra a **Mis solicitudes**, pide anticipos y liquidaciones siempre a su nombre (no puede elegir a otro beneficiario), ve solo los suyos, recibe los avisos de sus procesos y de sus órdenes de pago, y firma como "Elaborado por" en sus documentos.
- En Usuarios del Sistema se distingue el usuario de un consultor. La alta rápida de proveedores también ofrece la categoría Consultor (el acceso se da después en Proveedores).
- **Base de datos**: `proveedores.identidad`, la categoría `consultor` y `usuarios.proveedor_id`; el puesto Consultor se crea una sola vez. Requiere ejecutar `actualizar_bd.php` (estructura 1.22.0).
- Pruebas automáticas: 44 (1 nueva: del alta del consultor con acceso a que pide su anticipo y solo ve lo suyo).

## 1.21.0 — 2026-11-03
**Anticipos y liquidaciones para proveedores o consultores**
- **Anticipos a proveedores o consultores**: en Anticipos y en Liquidaciones / Reembolsos el beneficiario puede ser un **empleado** o un **proveedor / consultor** (para consultores que no son empleados y necesitan viáticos). La lista de proveedores permite dar de alta uno nuevo sin salir del formulario. La liquidación de un anticipo toma siempre el beneficiario del anticipo, y al elegir al proveedor se liga sola a su anticipo pendiente, igual que con un empleado.
- La **orden de pago** automática (y la manual desde la liquidación) se emite al proveedor con sus datos bancarios. Las listas, la vista, los PDF (anticipo, liquidación y voucher de alimentación, con RTN en lugar de identidad), el Auxiliar de Anticipos, el dashboard y la búsqueda muestran al proveedor.
- **Base de datos**: `gastos_viaje` y `solicitud_gastos` tienen `proveedor_id` y el empleado deja de ser obligatorio. Requiere ejecutar `actualizar_bd.php` (estructura 1.21.0).
- Pruebas automáticas: 43 (1 nueva: anticipo y liquidación a nombre de un proveedor, con su orden de pago); la del paso único de octubre ya no depende de si la base lo aplicó.

## 1.20.0 — 2026-11-02
**Correcciones: dashboard, orden de las compras, constancias, correos y listas**
- **Dashboard**: la gráfica "Gastos vs. Pagos" (que comparaba lo mismo dos veces) pasa a **Egresos por tipo, últimos 6 meses**: una barra por mes con lo pagado o aprobado en órdenes de pago, dividido en Compras, Viáticos y gastos, Planillas y Otros, con el total del mes al pasar el cursor. Nuevas tablas **Anticipos recientes** y **Solicitudes de gastos recientes** junto a las de compras y órdenes de pago; cada una solo aparece a quien tiene acceso a su módulo.
- **Compras: la orden de pago va antes de la nota de recepción**. La cadena del proceso queda Solicitud → Cotización → Orden de Compra → Orden de Pago → Nota de Recepción, y la nota de recepción solo se registra sobre una orden de compra que ya tiene su orden de pago (no anulada): la lista de órdenes de compra de la recepción solo trae esas, al guardar se valida, y el botón "Nota Recepción" de la orden de compra se activa cuando existe la orden de pago.
- **Constancias** firmadas por el empleado que las genera (nombre y cargo del usuario en sesión) en lugar de una firma fija.
- **Correos visibles con Cloudflare**: las páginas marcan su contenido con `<!--email_off-->` para que Cloudflare no reemplace los correos por `[email protected]` (`cdn-cgi/l/email-protection`), que dejaba de verse al navegar entre páginas.
- **Primer ingreso**: tras cambiar la contraseña inicial, el sistema lleva a la página de inicio del usuario (el dashboard) y no al sitio público.
- **Listas**: por defecto se ordenan por la columna **Número**, del mayor al menor (orden natural), así el último proceso creado aparece primero.
- Pruebas automáticas: 42 (1 nueva: la nota de recepción exige orden de pago vigente).

## 1.19.0 — 2026-10-30
**Cadena del proceso completa en todos los documentos**
- **Cadena del proceso en todos los documentos** (`config/cadena.php`, `includes/cadena_proceso.php`): desde cualquier documento se ven todos los de su mismo proceso, con el actual resaltado y enlaces a los demás. Compras: Solicitud → Cotización → Orden(es) de Compra → **Nota(s) de Recepción** → Orden(es) de Pago; viáticos: Anticipo → Liquidación / Reembolso → Orden(es) de Pago; planillas, liquidaciones laborales, préstamos y caja chica: el proceso → su Orden de Pago. Ahora también en Cotizaciones, Nota de Recepción, Anticipos, las planillas, Liquidaciones, Préstamos y Caja Chica. Un paso con varios documentos los muestra todos (p. ej. una orden de compra por proveedor en la compra por artículo; antes solo aparecía una) y las órdenes de pago anuladas no se muestran. En el celular los pasos se acomodan en varias filas.
- Pruebas automáticas: 41 (2 nuevas: la cadena es la misma desde cualquier documento, y la muestran todos los módulos).

## 1.18.0 — 2026-10-30
**Flujo de aprobación en las planillas y las liquidaciones laborales**
- **Flujo de aprobación en las planillas y las liquidaciones laborales**: Planillas de Sueldos, de Asignación y de Beneficios, y Liquidaciones, tienen ahora el panel Creado → Revisado → Autorizado en lugar del botón "Aprobar" de un solo paso, con las mismas reglas que los demás procesos (revisión antes de autorizar, jerarquía, nadie aprueba lo propio, delegaciones, avisos, recordatorios y escalamiento; quien la generó la corrige hasta la primera revisión). Autorizar la deja aprobada y genera su orden de pago, como antes; "Marcar pagada" solo es posible una vez aprobada. Una planilla anulada ya no muestra el flujo.
- Los documentos impresos de las planillas firman con las personas reales del flujo; las aprobadas antes de esta versión conservan sus firmantes configurados.
- Generar una planilla de sueldos registra quién la generó y avisa a quienes deben revisarla.

## 1.17.0 — 2026-10-30
**Flujo de aprobación en cotizaciones y notas de recepción**
- **Flujo de aprobación en todo el proceso de compra**: el **Resumen de Cotizaciones** y la **Nota de Recepción** tienen ahora el panel Creado → Revisado → Autorizado, como la solicitud, la orden de compra y la de pago (y los anticipos y liquidaciones, que ya lo tenían). Con las mismas reglas: revisión antes de autorizar, jerarquía del organigrama, nadie aprueba lo propio, delegaciones, avisos, recordatorios y escalamiento, y el creador edita hasta la primera revisión. La cotización autorizada queda aprobada y lista para crear la orden de compra (reemplaza el botón "Aprobar" de un solo paso) y puede rechazarse con motivo; en la nota de recepción, revisar y autorizar no cambian su estado (completa, parcial o devolución). El PDF del resumen de cotizaciones lleva las tres firmas del flujo.
- La prueba de reglas vigila que todo proceso con flujo muestre su panel.

## 1.16.0 — 2026-10-29
**Anular planillas (en lugar de borrarlas)**
- **Anular una planilla** (botón **Anular** al verla, con motivo): se conserva con su detalle como constancia, pero deja de contar en los reportes, en el dashboard y en la regla del mes, así que su período se puede volver a generar (p. ej. pagar con una planilla mensual un mes que se había empezado por quincenas). Sus órdenes de pago aún no pagadas se anulan; una ya pagada se conserva, porque ese dinero salió. Una anulada ya no se edita ni cambia de estado, y editar un empleado no la toca. La anula quien puede corregirla; si está pagada, solo el Administrador, con aviso de no pagar dos veces el mismo período. El motivo queda en las notas y en la bitácora.
- `actualizar_bd.php`, **una sola vez y solo en AHDECO** (pedido expreso): **anula** las planillas quincenales de octubre 2026 (antes las borraba, 1.15.1) para pagar ese mes con una planilla mensual. Sus órdenes de pago aún no pagadas se anulan; la ya pagada se conserva. Queda en la bitácora y una marca impide que se repita.
- Pruebas automáticas: 38 (2 nuevas: la anulación única de octubre, en una transacción que se revierte, y anular una quincena y generar la mensual de punta a punta).
- Base de datos (`actualizar_bd.php`): estado `anulada` en `planilla_periodos`; el índice único (año, mes, quincena) pasa a ser un índice normal: la regla del período la aplica el sistema, que ignora las anuladas.

## 1.15.1 — 2026-10-29
**Octubre 2026 de AHDECO con planilla mensual**
- Corregido: al no poder generar una planilla (p. ej. el mes ya tiene planilla quincenal y se pidió una mensual), la pantalla decía "Error de conexión" en lugar del motivo.
- `actualizar_bd.php`, una sola vez y solo en AHDECO: borraba las planillas quincenales de octubre 2026 con sus órdenes de pago. **Reemplazado en 1.16.0 antes de ejecutarse en producción: ahora se anulan, no se borran.**

## 1.15.0 — 2026-10-29
**Expediente digital del empleado**
- **Expediente digital del empleado** (Empleados → editar): documentos de la persona clasificados por categoría (Identificación, Contrato y adendas, Hoja de vida, Títulos y certificados, Antecedentes, Constancias y referencias, Evaluaciones de desempeño, Acciones de personal, Salud y seguros, Planillas y pagos, Otros). Se elige la categoría y se suben archivos, se toma una foto con el celular o se arrastran; se muestran agrupados por categoría, con vista previa y descarga. Al registrar un empleado nuevo también se pueden adjuntar (cada archivo con su categoría) y se suben al guardar. La lista de empleados indica cuántos documentos tiene cada expediente. Solo lo ven y completan quienes tienen acceso al módulo Empleados; quitar un documento: quien lo subió o el Administrador. Queda en la bitácora. Usa el mismo sistema de documentos adjuntos de los procesos (`config/adjuntos.php` → `EXPEDIENTES`).
- Pruebas automáticas: 36 (1 nueva: expediente digital de punta a punta, incluido el acceso).
- Base de datos (`actualizar_bd.php`): columna `adjuntos.categoria`.

## 1.14.2 — 2026-10-29
**Permiso y Vacaciones con 3 firmas**
- Corregido: los documentos de **Permiso** y **Vacaciones** llevaban 4 firmas (Solicitado por, Elaborado por, Revisado por, Autorizado por). Ahora 3: Solicitado por (el empleado), Revisado por y Autorizado por. Revisados todos los documentos impresos: ninguno pasa de 3 firmas de la organización (la Orden de Compra lleva aparte la del proveedor). La prueba de documentos impresos lo vigila.

## 1.14.1 — 2026-10-29
**Ícono de Proscala en las pestañas del navegador**
- **Ícono de Proscala en las pestañas del navegador** (y al agregar el sistema a la pantalla de inicio del celular), en todas las páginas: sistema, página principal, inicio de sesión, activar cuenta / restablecer contraseña, documentos impresos, página de error y `actualizar_bd.php` (`includes/favicon.php`). Generado desde `assets/images/icono.png` (el original, 1254 px): `favicon.ico` (16, 32 y 48 px) y `favicon-32/192.png` con las esquinas transparentes, y `apple-touch-icon.png` (180 px, con fondo: iOS redondea solo).
- La prueba de las páginas sin sesión revisa también el ícono y sus archivos.
- Sin cambios en la base de datos.

## 1.14.0 — 2026-10-28
**El responsable de la caja chica registra sus gastos día a día**
- **El responsable de una caja chica la edita** mientras esté abierta, para registrar y corregir los gastos día a día, aunque la haya creado él. No la elimina ni cambia el monto del fondo ni el responsable: eso lo hace un superior.
- Corregido: al pagarse la orden de pago de una caja chica (el fondo que se entrega al responsable) la caja quedaba bloqueada para todos, justo cuando empiezan los gastos. Ahora solo la cierra la liquidación; con el fondo pagado, el monto del fondo y el responsable ya no cambian, pero los gastos sí.
- Pruebas automáticas: 35 (1 nueva: edición de la caja chica por su responsable).
- Sin cambios en la base de datos.

## 1.13.0 — 2026-10-28
**Colegas del mismo puesto se revisan entre sí (cima del organigrama y Administración)**
- Corregido: un proceso creado por alguien de un puesto en la cima del organigrama (que aprueba y no depende de nadie, p. ej. Administrador) no lo podía revisar ni corregir nadie: "solo un puesto superior a Administrador…", y no hay ninguno. Ahora ahí los colegas del mismo puesto se revisan, autorizan y corrigen entre sí (nunca lo propio).
- **"Sus miembros se revisan entre sí"** (Roles y Permisos → editar puesto): lo mismo para cualquier puesto que apruebe procesos, aunque no esté en la cima. Activado para **Administración** (en las empresas existentes, `actualizar_bd.php` lo activa una vez; si después se desactiva, se respeta). En la tabla de puestos, "Sus procesos los aprueban" muestra a los colegas cuando aplica. En los demás puestos la regla no cambia.
- Base de datos (`actualizar_bd.php`): columna `roles.aprueba_colegas`.

## 1.12.0 — 2026-10-28
**Cuadro comparativo de cotizaciones por artículo y recomendación de compra**
- **Cuadro comparativo de cotizaciones por artículo**: las cotizaciones ya no se registran con un monto global por proveedor sino como se ve cada cotización: una fila por artículo (cargados de la solicitud de compra, editables, se pueden agregar más) y una columna por proveedor con el precio unitario de cada uno; vacío = no lo cotizó. El total de cada proveedor se calcula solo y el menor precio de cada artículo se marca en verde mientras se escribe. Las cotizaciones anteriores se siguen viendo con su monto global; al editarlas se cargan los artículos de su solicitud.
- **Recomendación del sistema**: el **proveedor único** más barato entre los que cotizaron todos los artículos (o, si ninguno, el que más cubre) y la **compra por artículo**, cada artículo al mejor precio, con el ahorro y los proveedores que requiere. Avisa si el proveedor seleccionado no es el recomendado.
- **Orden de compra desde la cotización con los precios cotizados** (antes, los estimados de la solicitud): botón por cada proveedor de la recomendación; en la compra por artículo, la orden lleva solo los artículos que le tocan a ese proveedor.
- El **PDF del resumen** muestra el cuadro artículo × proveedor (precio unitario y total de cada línea), los totales, la cobertura, las condiciones y la recomendación.
- Pruebas automáticas: 33 (3 nuevas): la recomendación, la cotización por artículo de punta a punta (guardar, ver, PDF y orden de compra) y la sintaxis del JavaScript de los formularios (`node --check`).
- Base de datos (`actualizar_bd.php`): tablas `cotizaciones_items` y `cotizaciones_precios`.

## 1.11.0 — 2026-10-28
**Resumen de cotizaciones en PDF, edición por el creador y correcciones de guardado**
- **Resumen de Cotizaciones en PDF** (botón **PDF Resumen** al ver una cotización): la solicitud de compra de referencia y lo que se pidió, el cuadro comparativo de las ofertas de la menor a la mayor (condiciones, tiempo de entrega y diferencia con la menor), el proveedor seleccionado (con aviso si no es la menor oferta), la justificación y las firmas Elaborado por / Aprobado por. Reemplaza el botón que imprimía la pantalla.
- **Quien crea una solicitud puede editarla** mientras nadie la haya revisado, autorizado ni rechazado (Solicitud de Compra, Anticipos, Liquidaciones / Reembolsos, Vacaciones, Permisos, Orden de Compra y Orden de Pago). Con el primer paso del flujo ya no puede, y la corrección vuelve a ser de un rango superior, como antes. Esa edición del creador no genera avisos de "proceso corregido": para los aprobadores sigue igual de pendiente.
- Corregido (desde 1.9.0): en los formularios de Nuevo / Editar de varios módulos (entre ellos Solicitud de Compra) Guardar no guardaba nada. El botón Excel de las listas fallaba cuando la lista no estaba en la página, el error cortaba el script del módulo y el formulario se enviaba sin AJAX: la página se recargaba y la bitácora registraba la acción como correcta, sin mensaje ni número. `initDataTable` ya no hace nada si la tabla no existe.
- Un formulario abierto antes de cerrar sesión y volver a entrar (por ejemplo en otra pestaña) ya no se pierde: si el token de seguridad venció, el sistema toma el vigente y reenvía solo; si aun así falla, muestra el motivo, y la bitácora registra "Token de seguridad vencido o inválido" (antes, sin explicación).
- Corregido: Solicitud de Compra, Cotizaciones, Orden de Compra, Nota de Recepción y Anticipos mostraban "Error" sin el motivo cuando no se podía guardar.
- Las páginas con sesión se envían siempre como "no guardar en caché" (también para la caché de LiteSpeed del hosting): una página guardada y servida a otra sesión llevaría un token de seguridad ajeno.
- `actualizar_bd.php` (desde el navegador) avisa si la dirección `url` de `proscala_config.php` no es la del sistema: con una dirección equivocada, los enlaces de los correos (activar la cuenta, restablecer la contraseña) llevan a otro sitio.
- Corregido: `actualizar_bd.php` decía "Todo está al día" aunque no hubiera podido revisar alguna base; ahora avisa cuántas fallaron.
- **Pruebas automáticas**: 30 (2 nuevas): token de seguridad vencido y edición por quien creó el proceso; la de documentos impresos incluye el resumen de cotizaciones.
- Sin cambios en la base de datos.

## 1.10.0 — 2026-10-28
**Agregar desde las listas, catálogo contable en Excel y planillas mensuales**
- **Agregar desde la lista desplegable**: en las listas de proveedores (Cotizaciones, Orden de Compra, Orden de Pago, Nota de Recepción, Activos), bancos (Empleados, Proveedores, Orden de Pago), cargos y departamentos (Empleados) aparece al pie "Agregar … nuevo". Abre un modal con lo esencial (lo escrito en la búsqueda pasa al nombre), lo guarda sin salir del formulario y lo deja seleccionado. Si ya existe uno con ese nombre (o RTN, en proveedores) se selecciona ese en lugar de duplicarlo. Lo puede usar quien tenga acceso a algún módulo que use la lista (`alta_rapida.php`).
- **Catálogo contable desde Excel** (Catálogos → Catálogo contable): botón **Plantilla Excel** (columnas fijas, Código como texto, listas desplegables de Tipo y Activa) y descarga del catálogo actual en el mismo formato. **Importar Excel** revisa el archivo completo antes de escribir: solo acepta un libro .xlsx real (no CSV, .xlsm ni otros archivos renombrados, sin entidades externas), hasta 5 MB y 5000 cuentas, con los encabezados exactos de la plantilla; valida cada celda, códigos repetidos, cuentas padre y ciclos, y lista los errores por fila y columna. Sin errores, se aplica en una sola transacción: las cuentas se identifican por código (se crean o actualizan) y, si se marca, las que no vienen en el archivo se desactivan (nunca se borran). Cada empresa importa en su propia base.
- **Planillas quincenales o mensuales** (el módulo pasa a llamarse **Planillas de Sueldos**): al generar se elige el tipo. Cada empleado lleva 15 días (quincenal) o 30 (mensual, mes comercial); quien ingresó durante el período lleva solo los días desde su ingreso y quien ingresa después no entra. El sueldo es sueldo mensual / 30 × días, y los días se corrigen en la planilla (máximo 15 o 30). Las deducciones de cada empleado siguen configurándose por quincena: la planilla mensual las toma al doble. Un mes se paga con una planilla mensual o con sus dos quincenas, no ambas. Documentos, recibos y la orden de pago indican el tipo.
- Corregido: las solicitudes de compra del dashboard abrían el formulario de una solicitud nueva (el enlace llevaba el número en vez del id), y las órdenes de pago del dashboard no tenían enlace. Además, en cualquier proceso, un enlace con el número (`?action=ver&id=SC26-0201`) ahora lleva al proceso, y uno que no existe vuelve a la lista con un aviso en vez de mostrar el formulario.
- Voucher de alimentación de la liquidación de un anticipo: ya no lleva las firmas Elaborado / Revisado / Autorizado; solo la del empleado que recibe el viático.
- Corregido: en Cotizaciones, los nombres de proveedor de las filas agregadas no se escapaban.
- **Pruebas automáticas**: 28 (7 nuevas): alta rápida, plantilla e importación del catálogo contable (incluidos archivos que no son la plantilla), días de las planillas quincenales y mensuales, y enlaces a procesos.
- Base de datos (`actualizar_bd.php`): columna `planilla_periodos.tipo` (las planillas existentes quedan como quincenales).

## 1.9.0 — 2026-10-27
**Búsqueda, Mis solicitudes, delegación, recordatorios, Excel y pruebas automáticas**
- **Búsqueda global** en la barra superior (Ctrl + K o "/"). Encuentra procesos por número o texto, y también empleados, proveedores, activos, proyectos y módulos. Respeta los permisos: solo busca en los módulos que el usuario puede abrir y, si su puesto solo ve sus propias solicitudes, solo encuentra las suyas. Las listas aceptan `?buscar=` para llegar ya filtradas.
- **Mis solicitudes**: lo que cada usuario creó o está a su nombre, con el estado, en qué paso va y a quién espera, el motivo si se rechazó, y botones para crear solicitudes. Es la página de inicio de quien solo ve sus propias solicitudes.
- **Delegación de aprobaciones** (Mi Perfil): quien aprueba procesos delega en otra persona por un rango de fechas (máximo 90 días). El delegado aprueba con la jerarquía y el acceso a módulos de quien delegó, y queda "en nombre de" esa persona. No puede aprobar lo que creó quien delegó ni autorizar lo que esa persona revisó. Recibe también los avisos.
- **Recordatorios y escalamiento** (`cron/recordatorios.php`, diario): a los días configurados se recuerda a quien le toca aprobar; después se avisa al siguiente nivel del organigrama, y luego al siguiente. Cada aviso se envía una vez por paso; los procesos históricos no generan avisos. Los días se configuran en **Configuración**.
- **Exportar a Excel** (.xlsx real, sin librerías externas): botón en todas las listas (las filas filtradas, de todas las páginas) y en las tablas de Reportes Financieros, con los montos como números. Queda en la bitácora.
- **Pruebas automáticas** (`php tests/ejecutar.php`): 21 pruebas que cubren la sintaxis, la instalación limpia de los esquemas, la base al día, la zona horaria, el despliegue, la jerarquía, la delegación, las notificaciones, los recordatorios, la OP atómica, el bloqueo por IP, el respaldo semanal, Excel, las páginas principales, los 26 documentos impresos y los permisos de la búsqueda. La carpeta `tests/` no se despliega.
- Corregido: la configuración de una empresa podía usarse para otra cuando un proceso recorre varias empresas (cron).
- Base de datos (`actualizar_bd.php`): tablas `delegaciones` y `recordatorios`, configuración `recordatorio_dias` y `escalamiento_dias`.

## 1.8.0 — 2026-10-27
**Seguridad, despliegue y base de datos**
- **Zona horaria**: MySQL usa la misma hora que PHP (Honduras) en cada conexión. Fechas de creación, bitácora, notificaciones y vencimientos ya no dependen de la zona del servidor de base de datos.
- **Despliegue que borra archivos viejos**: `.cpanel.yml` usa `rsync --delete`. El servidor queda igual al repositorio, incluido el borrado de los archivos eliminados en versiones anteriores. Los datos del servidor están protegidos en `.deployfilter`.
- **`actualizar_bd.php`**: actualiza la base central y la de cada empresa sin borrar datos. Crea las tablas, columnas e índices que falten según los esquemas y aplica los pasos propios de cada versión. Primero muestra lo pendiente; se ejecuta desde el navegador (con clave de mantenimiento) o desde la terminal, y revisa los requisitos del servidor.
- **Activación de cuentas por correo**: los usuarios nuevos (desde Empleados o Usuarios) reciben un enlace para crear su propia contraseña, válido 7 días. Ya no se usa el número de identidad como contraseña inicial; los usuarios existentes no cambian. En Usuarios se ve quién está **Sin activar** y se le puede reenviar la invitación.
- **Bloqueo por IP**: 5 inicios de sesión fallidos en 15 minutos desde una misma conexión la bloquean 15 minutos. El inicio de sesión tiene además token CSRF.
- **Respaldo semanal del super administrador**: al iniciar sesión se descarga un respaldo completo (base central, base de cada empresa y archivos subidos), uno cada 7 días. Un respaldo incompleto se entrega con aviso, pero no cuenta como el de la semana. La lógica de respaldo se comparte con el cron (`config/respaldo.php`).
- **Orden de pago automática atómica**: si al autorizar no se puede generar la orden de pago, la autorización se revierte y el usuario ve el error. Antes quedaba autorizada sin OP. Las notificaciones de "autorizado" salen solo cuando la acción quedó completa.
- **Índices** por estado y fecha en las tablas de procesos. El dashboard y los reportes filtran por rangos de fechas para usarlos (mismas cifras).
- **Avisos emergentes como texto** (no HTML) y **política de seguridad de contenido (CSP)**: todo se carga desde el propio sistema.
- **Código JavaScript normalizado** en 20 módulos: bloques sin escapes, con los valores de PHP insertados por nombre. El JS resultante es idéntico.
- Eliminadas las tablas sin uso `cuentas_bancarias` y `movimientos_bancarios`, y `composer.phar`.
- **`REQUISITOS.md`**: requisitos del servidor, configuración, instalación, despliegue y trabajos de cron.
- Corregido: fechas en inglés en el dashboard ("27 de October") y en Mi Perfil.

## 1.7.0 — 2026-10-27
**Adjuntar documentos al llenar el formulario**
- Los formularios de los procesos tienen al final la sección **Documentos adjuntos**: liquidaciones / reembolsos, anticipos, solicitud de compra, cotizaciones, orden de compra, orden de pago, nota de recepción, caja chica, vacaciones, permisos, préstamos y liquidaciones laborales.
- Las facturas, cotizaciones o fotos se eligen mientras se llena el formulario (también con la cámara del celular o arrastrándolas). Se ven en una lista donde se pueden quitar y se suben solas al guardar. Ya no hace falta guardar primero y adjuntar después.
- Se suben una por una con las mismas validaciones del panel de adjuntos (tipo de archivo, tamaño máximo, permisos). Las fotos grandes se reducen antes de subirlas. Si algún archivo no se puede adjuntar, el proceso queda guardado igual y el aviso dice cuál faltó, para agregarlo desde el panel.
- En los formularios que se abren en una ventana (vacaciones, permisos, préstamos, liquidaciones), al cancelar se descartan los archivos elegidos.

## 1.6.0 — 2026-10-27
**Página principal y manual de uso**
- **Página principal** (`index.php`, la portada del dominio). Presenta el sistema a quien quiera contratarlo, con el mismo diseño del panel, en modo claro y oscuro y adaptada al celular. Incluye:
  - módulos, flujo de aprobación, ventajas y seguridad;
  - formulario **"Solicitar información"**, que llega por correo y al responderlo la respuesta va directo al interesado;
  - acceso visible para los clientes: **"Iniciar sesión"** en la barra superior, en la portada, en una sección propia y, en celulares, un botón fijo.
  - No depende de la base de datos: sigue en pie aunque la base no responda.
- El **inicio de sesión** pasa a `login.php`. Los enlaces de los correos enviados antes de este cambio (`index.php?ir=…`) siguen funcionando.
- El login tiene un enlace **"Conocer Proscala"** que lleva a la página principal.
- **Manual de uso** (`README.md`) para los usuarios de las organizaciones: ingreso, pantalla, aprobaciones, notificaciones, adjuntos, documentos, guía de cada módulo, administración de la cuenta y preguntas frecuentes.
- Configuración (`proscala_config.php`): nueva sección `ventas` con el correo que recibe el formulario y el teléfono y WhatsApp que se muestran en la página (ver `config/credenciales.ejemplo.php`). Sin ella, el formulario se envía al remitente del correo del sistema.
- `config/base.php`: la parte de la configuración que no usa la base de datos (credenciales, errores) se separó de `config/db.php`.

## 1.5.1 — 2026-10-27
**Correcciones de diseño**
- **Pie de página en celulares**: ya no queda corrido fuera de la pantalla. Ocupa todo el ancho, centrado.
- **Barra superior en celulares**: el nombre de la empresa queda centrado entre el logo y los botones.
- **Foto de perfil**: corregido el error al cambiarla. La carpeta de fotos se crea si no existe. Si el servidor no tiene la extensión GD, la foto se guarda sin recortar (antes fallaba sin mensaje). Se acepta hasta 5 MB, como indica el servidor, y la foto de la barra superior se actualiza al momento.
- **Colores de las alertas según el tema**: en modo oscuro las alertas conservan su color (aviso, información, histórico…) en tonos oscuros; antes todas quedaban grises. Bootstrap ahora sigue el tema del sistema (`data-bs-theme`), así que también se adaptan las filas de color de las tablas (por ejemplo "Solo sus propias solicitudes" en Roles). Los avisos emergentes de error de Mi Perfil ahora sí se muestran en rojo.
- **Pestañas en modo oscuro** (Catálogos y demás): la pestaña activa se ve con los colores del tema.
- **Tablas vacías**: ya no aparece el aviso de error de DataTables cuando una lista no tiene registros. Se muestra el mensaje "No hay…" de la propia lista.

## 1.5.0 — 2026-10-27
**Impresión: una plantilla por documento**
- `print.php` ya no es un archivo de más de 3,500 líneas: ahora solo valida el pedido y entrega el documento con su plantilla en `templates/print/`, una por documento (solicitud de compra, orden de compra, orden de pago, planillas, recibos, anticipos, liquidaciones, vacaciones, permisos, constancias, caja chica, memorandos, actas…).
- Todas las plantillas comparten las mismas partes comunes, así que el diseño es igual en todos los procesos y un cambio de estilo se hace en un solo lugar:
  - `_inicio.php`: estilos y barra Cerrar / Descargar PDF.
  - `_org.php`: encabezado de la organización (antes estaba copiado 10 veces).
  - `_pagina_inicio.php` / `_pagina_fin.php`: página común.
  - `_fin.php`: descarga en PDF.
  - `_funciones.php`: firmas.
- Los documentos se ven exactamente igual que antes. Se comprobó comparando el HTML de los 26 tipos de documento (33 casos, incluidos recibos individuales, liquidación con voucher de alimentación y proceso de compra completo), antes y después del cambio.
- `print.php` y `pdf_download.php` validan los tipos de documento con la misma lista del sistema.
- La carpeta `templates` no se puede abrir desde la web, igual que las demás carpetas internas.

## 1.4.0 — 2026-10-27
**Notificaciones**
- Campana en la barra superior con un consolidado de lo importante para cada usuario:
  - **Por aprobar**: los procesos que puede revisar o autorizar en este momento (con las mismas reglas del flujo y del organigrama).
  - **Avisos**: sus procesos revisados, autorizados, rechazados (con el motivo) o corregidos, y los que esperan su revisión o autorización.
  - Muestra cuántos hay pendientes y sin leer, se actualiza sola cada 2 minutos y tras cada acción, y anuncia los avisos nuevos. Al abrir un proceso, sus avisos quedan leídos; también se puede marcar todo como leído.
- Correo con cada aviso, con un botón que abre el proceso (si no hay sesión abierta, al entrar se va directo a él).
- A quién se avisa:
  - Un proceso nuevo, corregido o una orden de pago generada al autorizar: al puesto superior más cercano que aprueba procesos, según el organigrama de quien lo creó. Si ese puesto no tiene usuarios, a los Administradores.
  - Un proceso revisado: a quien lo creó, y a quien le toca autorizarlo (nunca a quien lo revisó).
  - Un proceso autorizado, rechazado o corregido: a quien lo creó y al empleado a cuyo nombre está.
- En Mi Perfil cada usuario puede dejar de recibir los avisos por correo; en la campana siempre aparecen.
- La consulta automática de la campana no cuenta como actividad: la sesión se sigue cerrando tras 2 h sin uso.
- Al abrir cualquier página con la sesión vencida, después de iniciar sesión se vuelve a esa página.
- Base de datos: tabla `notificaciones` y columna `usuarios.notificar_correo`.

## 1.3.0 — 2026-10-27
**Historial de rechazos con evidencia**
- Cada rechazo queda guardado con quién lo hizo, cuándo y el motivo, en el historial del proceso. Ya no se pierde cuando el proceso se corrige y vuelve a revisión: el panel del flujo muestra el rechazo vigente y los anteriores ("Corregido después").
- Al rechazar se puede adjuntar evidencia: fotos (también con la cámara del celular), PDF u otros documentos. Se muestra junto a su rechazo, la ve quien hizo la solicitud y no se puede quitar (es constancia). Si un archivo de evidencia no es válido, el rechazo no se aplica.
- La bitácora registra cada rechazo con su motivo y la evidencia adjunta.
- Base de datos: tabla `rechazos` y columna `adjuntos.rechazo_id`; los rechazos anteriores pasaron al historial.

## 1.2.0 — 2026-10-27
**Diálogos con el diseño del sistema**
- Las confirmaciones y avisos ya no usan las ventanas del navegador: se muestran en un diálogo propio de Proscala (ícono, título, mensaje y botones con el estilo del sistema, en modo claro y oscuro).
- Las acciones que borran o reemplazan datos se distinguen con botón rojo y un texto claro ("Eliminar", "Quitar", "Reemplazar"…).
- Aplicado en todos los módulos: eliminar registros y catálogos, quitar adjuntos, logo o foto, liquidar caja chica, aprobar actas, emitir memorandos, cancelar préstamos, distribuciones de planillas, crear usuarios de empleados, puestos, desbloquear usuarios, revisar y autorizar procesos.

## 1.1.0 — 2026-10-27
**Librerías locales**
- Todas las librerías se sirven desde el propio sistema (`assets/vendor/`), sin CDN: Bootstrap 5.3.2, jQuery 3.7.1, DataTables 1.13.7 (+ Responsive 2.5.0 y su idioma español), Flatpickr 4.6.13 (antes se cargaba "la última versión" sin fijar), Select2 4.1.0-rc.0 (+ tema Bootstrap 5), Chart.js 4.4.1, Font Awesome 6.5.0, html2pdf y la fuente Inter (antes de Google Fonts).
- El sistema funciona aunque un CDN falle o esté bloqueado, y ya no depende de servicios externos para dibujarse.
- Los archivos estáticos llevan marca de versión: el navegador toma la versión nueva automáticamente.

## 1.0.1 — 2026-10-27
**Limpieza**
- Eliminados archivos que ya no se usaban: el instalador `setup.php`, `pdf_vouchers.php` (roto y sin enlaces), `jszip.min.js`, imágenes viejas (`Imagen1.png`, `logo2.png`, `logo2aa.png`), los esquemas de la versión de una sola empresa (`schema.sql`, `seed_data.sql`, `fix_cuentas.sql`), el corte histórico ya aplicado y todas las migraciones (la base de producción se reemplaza completa). Siguen disponibles en el historial de Git.
- `database/proscala_master.sql` queda como estructura vacía de la base central.
- Corregidos los textos con caracteres dañados en Activos Fijos ("CÃ³digo" → "Código", etc.). Revisados el resto del código y los datos: no hay más casos.

## 1.0.0 — 2026-10-27
Primera versión con control de versiones. Reúne todo lo construido hasta la fecha:

- **Multiempresa**: una base de datos por empresa; inicio de sesión por RTN (recordado en el navegador); el mismo correo puede pertenecer a varias empresas; super administrador que gestiona las empresas; logo propio por empresa en los documentos.
- **Puestos y permisos**: puestos configurables por empresa con jerarquía (organigrama), módulos por puesto, "solo sus propias solicitudes" y "aprueba procesos".
- **Usuarios de empleados**: cada empleado con correo es usuario (contraseña inicial = número de identidad, cambio obligatorio al entrar).
- **Flujo Creado → Revisado → Autorizado** en solicitudes, órdenes de compra y órdenes de pago, con las mismas firmas en los documentos; rechazo con motivo obligatorio.
- **Correlativo compartido**: cada proceso termina en una orden de pago con su mismo número (desde el #200; reinicia cada año); la OP se genera al autorizar.
- **Corte histórico**: procesos anteriores marcados como históricos (consulta e impresión, sin edición).
- **Edición por rangos superiores**: nunca por quien creó el proceso; editar un proceso autorizado lo devuelve a revisión y actualiza su OP.
- **Documentos adjuntos** en todos los procesos (archivos o foto con la cámara del celular).
- **Catálogos** unificados (catálogo contable, cargos, departamentos, bancos).
- **Bitácora de actividad** de todo lo que hace cada usuario.
- **Navegación sin recargar** la página.
- **Seguridad**: credenciales fuera del código (`proscala_config.php`), carpetas internas bloqueadas, HTTPS obligatorio, cookies seguras, sesión que se extiende con la actividad y se cierra tras 2 h inactiva, bloqueo tras 5 contraseñas incorrectas (lo desbloquea un administrador), restablecer contraseña por correo, página de error amable.
- **Respaldos automáticos** por cron (`cron/backup.php`).

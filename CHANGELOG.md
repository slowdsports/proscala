# Proscala — Historial de versiones

La versión vigente está en el archivo `VERSION` y se muestra en el pie del sistema y en el inicio de sesión.

Formato **MAYOR.MENOR.PARCHE**:
- **MAYOR**: cambios que obligan a reinstalar o migrar datos de forma incompatible.
- **MENOR**: funciones nuevas o cambios visibles para los usuarios.
- **PARCHE**: correcciones que no cambian el uso del sistema.

Cada versión queda registrada en Git con una etiqueta `vX.Y.Z`.

---

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

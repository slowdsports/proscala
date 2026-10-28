# Proscala — Requisitos del servidor e instalación

Documento técnico para quien instala y mantiene el sistema. El manual de uso para los usuarios está en `README.md`.

`actualizar_bd.php` revisa estos requisitos cada vez que se ejecuta (sección **Servidor**).

---

## 1. Servidor

| Componente | Requisito | Para qué |
|---|---|---|
| **PHP** | **8.0 o superior** (recomendado 8.2) | El código usa `match`, `str_contains`, `str_starts_with` y tipos de PHP 8 |
| **MySQL / MariaDB** | MySQL 5.7+ o MariaDB 10.3+, con `utf8mb4` | Una base central y una base por empresa |
| **Servidor web** | Apache con `mod_rewrite` y `mod_headers`, y `.htaccess` permitido (`AllowOverride All`) | Bloqueo de carpetas internas, HTTPS obligatorio, encabezados de seguridad |
| **HTTPS** | Certificado SSL válido (Let's Encrypt / AutoSSL de cPanel) | El sistema redirige todo a HTTPS y usa cookies seguras |
| **Cron** | Trabajos de cron (cPanel) | Respaldos y recordatorios diarios |

### Extensiones de PHP

| Extensión | ¿Obligatoria? | Para qué |
|---|---|---|
| `pdo_mysql` | Sí | Conexión a la base de datos |
| `mbstring` | Sí | Textos con acentos y ñ |
| `fileinfo` | Sí | Validar el tipo real de los archivos adjuntos |
| `openssl` | Sí | Correo por SMTP seguro y tokens aleatorios |
| `zip` | Sí | Respaldos (automático y semanal) |
| `zlib` | Sí | Comprimir los respaldos (`.sql.gz`) |
| `json`, `session` | Sí | Vienen con PHP |
| `gd` | Recomendada | Recortar y reducir las fotos de perfil (sin GD se guardan sin recortar) |

### Configuración de PHP recomendada

| Directiva | Valor | Motivo |
|---|---|---|
| `upload_max_filesize` | `10M` o más | Tamaño máximo de cada documento adjunto (el sistema usa el menor entre 10 MB y este valor) |
| `post_max_size` | `12M` o más | Debe ser mayor que `upload_max_filesize` |
| `max_execution_time` | `120` o más | Planillas grandes y generación de documentos |
| `memory_limit` | `256M` o más | Respaldos y reportes |

### Opcional

- **`mysqldump`** en el servidor: respaldos más rápidos y consistentes. Sin él, los respaldos se hacen con PHP.
- **Chrome / Chromium** en el servidor: descarga de PDF generado en el servidor (`pdf_download.php`). Sin él, los documentos se descargan desde el navegador del usuario (botón **Descargar PDF** de cada documento).

---

## 2. Configuración: `proscala_config.php`

Las credenciales viven **fuera** de la carpeta del sistema, en `proscala_config.php` (plantilla en `config/credenciales.ejemplo.php`). Proscala lo busca en la carpeta que contiene el sistema y en la superior.

| Sección | Contenido |
|---|---|
| `url` | Dirección pública del sistema (enlaces de los correos) |
| `db` | Servidor, base central, usuario y contraseña de MySQL |
| `correo` | SMTP para el correo saliente (notificaciones, activación de cuentas, recuperar contraseña) |
| `respaldos` | Carpeta de respaldos (fuera de la web), días que se conservan, aviso por correo si fallan |
| `mantenimiento` | Clave para ejecutar `actualizar_bd.php` desde el navegador (mínimo 16 caracteres) |
| `ventas` | Correo que recibe el formulario de la página principal, teléfono y WhatsApp que se muestran |

---

## 3. Instalación desde cero

1. Cree en cPanel la base central y la base de la primera empresa, con un usuario de MySQL con todos los privilegios sobre ambas.
2. Importe `database/proscala_master.sql` en la base central.
3. Cree `proscala_config.php` a partir de `config/credenciales.ejemplo.php`.
4. Despliegue el código (sección 4).
5. Registre la primera empresa y su super administrador en la base central (o restaure un respaldo). Las empresas siguientes se crean desde **Empresas** (`empresas.php`), que aplica `empresa_schema.sql`, `empresa_seed.sql` y `roles_modulos_inicial.sql`.
6. Configure los trabajos de cron (sección 6).

---

## 4. Despliegue de una versión nueva

El código se sube a GitHub (GitHub Desktop) y se despliega desde cPanel → **Git Version Control → Deploy HEAD Commit**:

1. `.cpanel.yml` copia el sistema con `rsync --delete`: el servidor queda igual al repositorio y se **borran los archivos que ya no existen** en él. Los datos del servidor (archivos subidos, sesiones, respaldos) están protegidos en `.deployfilter`.
2. Si la versión cambia la base de datos (ver `CHANGELOG.md`), ejecute **`actualizar_bd.php`**:
   - Navegador: `https://sudominio.com/actualizar_bd.php`, con la clave de mantenimiento.
   - Terminal de cPanel: `php actualizar_bd.php` (revisa) y `php actualizar_bd.php --aplicar` (aplica).
   Primero muestra lo pendiente sin cambiar nada. Haga un respaldo antes de aplicar.

Para ver qué copiaría y qué borraría el despliegue antes de hacerlo (sin cambiar nada):

```bash
rsync -a --delete --dry-run --itemize-changes --filter='merge .deployfilter' ./ /home/USUARIO/web/SITIO/
```

---

## 5. Base de datos: cómo se actualiza

- `database/proscala_master.sql` y `database/empresa_schema.sql` son siempre la **estructura completa vigente**.
- `actualizar_bd.php` compara cada base con esos archivos y crea las tablas, columnas e índices que falten; nunca borra datos por su cuenta.
- Lo que no es solo agregar (borrar tablas, mover datos, configuración nueva) va en la lista **PASOS DE LA VERSIÓN** de `actualizar_bd.php`, que se reescribe en cada versión.

---

## 6. Trabajos de cron (cPanel → Trabajos de cron)

Ajuste la ruta de PHP (`/usr/local/bin/php` o `/opt/alt/php82/usr/bin/php`) y la del sistema.

| Cuándo | Comando | Qué hace |
|---|---|---|
| Diario, 2:30 a. m. | `php /home/USUARIO/web/SITIO/cron/backup.php >> /home/USUARIO/proscala_respaldos/backup.log 2>&1` | Respaldo de todas las bases y archivos subidos en el servidor |
| Diario, 7:00 a. m. | `php /home/USUARIO/web/SITIO/cron/recordatorios.php >> /home/USUARIO/proscala_respaldos/recordatorios.log 2>&1` | Recuerda los procesos que llevan días esperando y los escala al siguiente nivel |

Además, el super administrador descarga al iniciar sesión un respaldo completo cada 7 días: guárdelo fuera del servidor.

---

## 7. Pruebas automáticas

Antes de cada versión, en el equipo de desarrollo:

```bash
php tests/ejecutar.php
```

Revisa la sintaxis de todos los archivos, la estructura de la base, las reglas de aprobación y notificaciones, la orden de pago automática, los documentos impresos y las páginas principales. La carpeta `tests/` no se despliega al servidor.

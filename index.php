<?php
// ── Página principal pública ───────────────────────────────────────
// Presenta Proscala a quien quiera contratarlo (formulario "Solicitar información") y lleva a
// los clientes al inicio de sesión (login.php). No usa la base de datos: sigue en pie aunque
// la base no responda. El formulario envía un correo a proscala_config.php → 'ventas' → 'correo'.
require_once __DIR__ . '/config/base.php';
require_once __DIR__ . '/config/functions.php';

// Enlaces de correos anteriores a la versión 1.6 (index.php?ir=…): siguen llevando al inicio de sesión
if (isset($_GET['ir'])) {
    header('Location: ' . BASE_URL . 'login.php?' . ($_SERVER['QUERY_STRING'] ?? ''));
    exit;
}

$ventas    = credenciales()['ventas'] ?? [];
$destino   = trim((string)($ventas['correo'] ?? '')) ?: trim((string)(credenciales()['correo']['remitente'] ?? ''));
$telefono  = trim((string)($ventas['telefono'] ?? ''));
$whatsapp  = preg_replace('/\D/', '', (string)($ventas['whatsapp'] ?? ''));
$correoPub = trim((string)($ventas['correo'] ?? ''));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ── Formulario "Solicitar información" ──────────────────────────────
// Antispam: campo trampa oculto, tiempo mínimo para llenarlo y máximo 3 envíos por hora por IP.
$respuesta = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $responder = function (bool $ok, string $mensaje) use (&$respuesta) {
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => $ok, 'message' => $mensaje], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $respuesta = [$ok, $mensaje];
    };
    $campo = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? ''))), 0, $max);
    $nombre = $campo('nombre', 120); $org = $campo('organizacion', 160); $email = $campo('email', 150);
    $tel = $campo('telefono', 40); $tamano = $campo('tamano', 40);
    $mensaje = mb_substr(trim((string)($_POST['mensaje'] ?? '')), 0, 3000);

    if (($_POST['sitio_web'] ?? '') !== '') {
        $responder(true, '¡Gracias! Le escribiremos pronto.');        // trampa: se finge éxito
    } elseif (time() - (int)($_POST['t'] ?? 0) < 3) {
        $responder(false, 'Revise los datos e intente enviar de nuevo.');
    } elseif ($nombre === '' || $org === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $responder(false, 'Escriba su nombre, su organización y un correo válido.');
    } elseif ($destino === '') {
        $responder(false, 'El formulario no está disponible en este momento. Escríbanos por los medios de contacto.');
    } else {
        $dir = __DIR__ . '/storage/contacto';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $reg = $dir . '/' . sha1($_SERVER['REMOTE_ADDR'] ?? '') . '.json';
        $envios = array_filter((array)json_decode((string)@file_get_contents($reg), true), fn($t) => $t > time() - 3600);
        if (count($envios) >= 3) {
            $responder(false, 'Ya recibimos sus mensajes. Le responderemos pronto.');
        } else {
            require_once __DIR__ . '/config/correo.php';
            $fila = fn($l, $v) => $v === '' ? '' : '<tr><td style="padding:4px 12px 4px 0;color:#8ea3b4;white-space:nowrap">' . $l . '</td><td style="padding:4px 0;color:#0e1726">' . $h($v) . '</td></tr>';
            $html = plantillaCorreo('Nueva solicitud de información',
                '<table style="border-collapse:collapse;font-size:14px">' . $fila('Nombre', $nombre) . $fila('Organización', $org)
                . $fila('Correo', $email) . $fila('Teléfono', $tel) . $fila('Tamaño', $tamano) . '</table>'
                . ($mensaje !== '' ? '<p style="margin:16px 0 0;white-space:pre-line">' . $h($mensaje) . '</p>' : '')
                . '<p style="margin:16px 0 0;font-size:12px;color:#8ea3b4">Responda este correo para escribirle directamente.</p>',
                '', '', 'Enviado desde el formulario de la página principal de Proscala.');
            $envio = enviarCorreo($destino, "Proscala: solicitud de información — $org", $html, '', $email);
            if ($envio === true) {
                $envios[] = time();
                @file_put_contents($reg, json_encode(array_values($envios)));
                $responder(true, '¡Gracias, ' . explode(' ', $nombre)[0] . '! Recibimos su solicitud y le escribiremos pronto.');
            } else {
                $responder(false, 'No pudimos enviar su mensaje en este momento. Intente más tarde o use los otros medios de contacto.');
            }
        }
    }
}

// Módulos del sistema (las etiquetas son los nombres que verá en el menú)
$modulos = [
    ['fa-cart-shopping', '#1B6BA8', 'Compras', 'Del pedido al pago, con cada documento enlazado al anterior y el mismo número en todo el proceso.',
        ['Solicitud de compra', 'Cotizaciones', 'Orden de compra', 'Nota de recepción', 'Orden de pago']],
    ['fa-money-check-dollar', '#43A047', 'Planillas', 'Planillas quincenales con IHSS, RAP e ISR calculados, distribución por proyecto y recibos firmables.',
        ['Quincenal', 'Asignación', 'Décimo tercer y cuarto mes', 'Recibos']],
    ['fa-hand-holding-dollar', '#8b5cf6', 'Anticipos y liquidaciones', 'Viáticos y compras con anticipo, su liquidación con facturas y el reembolso que corresponda.',
        ['Anticipos', 'Liquidaciones', 'Reembolsos', 'Auxiliar contable']],
    ['fa-users', '#0EA5E9', 'Recursos humanos', 'Expediente de cada empleado y todo su día a día laboral en un solo lugar.',
        ['Empleados', 'Vacaciones', 'Permisos', 'Asistencia', 'Préstamos', 'Constancias', 'Liquidación laboral']],
    ['fa-diagram-project', '#FFA726', 'Proyectos y presupuesto', 'Cada gasto se asigna a su proyecto o donante y se compara contra el presupuesto aprobado.',
        ['Proyectos / Donantes', 'Presupuesto', 'Ejecución por cuenta']],
    ['fa-chart-pie', '#E53935', 'Reportes', 'Indicadores en el panel principal, reportes financieros y los archivos para IHSS, RAP y SAR.',
        ['Panel de indicadores', 'Reportes financieros', 'Reportes legales']],
    ['fa-cash-register', '#14b8a6', 'Caja chica', 'Fondos de caja chica con sus gastos por cuenta contable y su liquidación.',
        ['Fondos', 'Gastos por cuenta', 'Liquidación']],
    ['fa-laptop', '#64748b', 'Activos fijos', 'Inventario de equipo y bienes con su responsable, ubicación y estado.',
        ['Inventario', 'Responsables', 'Bajas']],
    ['fa-file-lines', '#0D4F82', 'Documentos internos', 'Memorandos, circulares, comunicados y actas de reunión con el formato de la organización.',
        ['Memorandos', 'Circulares', 'Actas de reunión']],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Proscala — Sistema de Administración Empresarial</title>
  <meta name="description" content="Proscala reúne compras, planillas, anticipos, recursos humanos, proyectos y reportes de su organización en un solo sistema web, con flujo de aprobación y documentos listos para imprimir.">
  <meta name="theme-color" content="#0c1728">
  <link rel="icon" href="<?= BASE_URL ?>assets/images/proscala.png">
  <script>
    // Mismo tema que el sistema (se recuerda en este navegador)
    (function () {
      var t = 'light';
      try { t = localStorage.getItem('proscala-theme') || 'light'; } catch (e) {}
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
  <link rel="stylesheet" href="<?= recurso('assets/vendor/fontawesome/css/all.min.css') ?>">
  <link rel="stylesheet" href="<?= recurso('assets/css/landing.css') ?>">
</head>
<body>

<a class="solo-lectores" href="#contenido">Ir al contenido</a>

<!-- ══ Barra superior ══ -->
<header class="nav" id="nav">
  <div class="contenedor">
    <a href="<?= BASE_URL ?>" class="marca" aria-label="Proscala, inicio">
      <img src="<?= BASE_URL ?>assets/images/proscala.png" alt=""><span>Proscala</span>
    </a>
    <ul class="nav-enlaces" id="nav-enlaces">
      <li><a href="#modulos">Módulos</a></li>
      <li><a href="#aprobaciones">Aprobaciones</a></li>
      <li><a href="#seguridad">Seguridad</a></li>
      <li><a href="#contacto">Contratar</a></li>
    </ul>
    <div class="nav-acciones">
      <button type="button" class="btn-icono" id="btn-tema" aria-label="Cambiar entre modo claro y oscuro"><i class="fas fa-moon"></i></button>
      <button type="button" class="btn-icono btn-menu" id="btn-menu" aria-label="Abrir el menú" aria-expanded="false" aria-controls="nav-enlaces"><i class="fas fa-bars"></i></button>
      <a href="<?= BASE_URL ?>login.php" class="btn btn-primario btn-ingresar"><i class="fas fa-right-to-bracket"></i> <span class="texto-largo">Iniciar sesión</span><span class="texto-corto" aria-hidden="true">Entrar</span><span class="solo-lectores"> en Proscala</span></a>
    </div>
  </div>
</header>

<main id="contenido">

<!-- ══ Portada ══ -->
<section class="portada">
  <div class="contenedor">
    <div class="texto">
      <span class="etiqueta"><span class="punto"></span> Sistema web para organizaciones y empresas</span>
      <h1>Toda la administración de su organización, <span class="texto-degradado">en un solo lugar.</span></h1>
      <p class="bajada">Compras, planillas, anticipos, recursos humanos, proyectos y reportes conectados entre sí, con aprobaciones según su organigrama y documentos listos para firmar.</p>
      <div class="acciones">
        <a href="#contacto" class="btn btn-primario btn-grande"><i class="fas fa-paper-plane"></i> Solicitar información</a>
        <a href="<?= BASE_URL ?>login.php" class="btn btn-claro btn-grande"><i class="fas fa-right-to-bracket"></i> Ya soy cliente: iniciar sesión</a>
      </div>
      <ul class="garantias">
        <li><i class="fas fa-check"></i>Sin instalar nada</li>
        <li><i class="fas fa-check"></i>Funciona en el celular</li>
        <li><i class="fas fa-check"></i>Una o varias empresas</li>
      </ul>
    </div>

    <div class="maqueta" aria-hidden="true">
      <div class="ventana">
        <div class="ventana-barra"><span></span><span></span><span></span><div class="url"><i class="fas fa-lock"></i> su-organizacion · Proscala</div></div>
        <div class="ventana-cuerpo">
          <div class="m-lateral">
            <img class="m-logo" src="<?= BASE_URL ?>assets/images/proscala.png" alt="">
            <div class="m-item activo"><i class="fas fa-gauge-high"></i> Dashboard</div>
            <div class="m-item"><i class="fas fa-cart-plus"></i> Compras</div>
            <div class="m-item"><i class="fas fa-money-check-dollar"></i> Planillas</div>
            <div class="m-item"><i class="fas fa-hand-holding-dollar"></i> Anticipos</div>
            <div class="m-item"><i class="fas fa-umbrella-beach"></i> Vacaciones</div>
            <div class="m-item"><i class="fas fa-chart-pie"></i> Presupuesto</div>
            <div class="m-item"><i class="fas fa-chart-bar"></i> Reportes</div>
          </div>
          <div class="m-principal">
            <div class="m-cabecera">Dashboard <span class="m-campana"><i class="fas fa-bell"></i><b>3</b></span></div>
            <div class="m-kpis">
              <div class="m-kpi"><div class="i" style="background:rgba(139,92,246,.18);color:#c4b5fd"><i class="fas fa-users"></i></div><div class="n">48</div><div class="l">Empleados</div></div>
              <div class="m-kpi"><div class="i" style="background:rgba(74,222,128,.15);color:#4ade80"><i class="fas fa-money-bill-transfer"></i></div><div class="n">L. 312K</div><div class="l">Pagos del mes</div></div>
              <div class="m-kpi"><div class="i" style="background:rgba(251,191,36,.15);color:#fbbf24"><i class="fas fa-hourglass-half"></i></div><div class="n">5</div><div class="l">Por aprobar</div></div>
            </div>
            <div class="m-grafica">
              <div class="t">Gastos y pagos — últimos 6 meses</div>
              <div class="m-barras">
                <div><span style="height:30%"></span><span style="height:55%"></span></div>
                <div><span style="height:45%"></span><span style="height:80%"></span></div>
                <div><span style="height:25%"></span><span style="height:92%"></span></div>
                <div><span style="height:60%"></span><span style="height:70%"></span></div>
                <div><span style="height:40%"></span><span style="height:85%"></span></div>
                <div><span style="height:22%"></span><span style="height:58%"></span></div>
              </div>
            </div>
            <div class="m-flujo">
              <span class="m-paso hecho"><i class="fas fa-check"></i> Creado</span><span class="m-linea"></span>
              <span class="m-paso hecho"><i class="fas fa-check"></i> Revisado</span><span class="m-linea"></span>
              <span class="m-paso"><i class="fas fa-signature"></i> Autorizado</span>
            </div>
          </div>
        </div>
      </div>
      <div class="flotante uno"><span class="ic" style="background:rgba(74,222,128,.15);color:#4ade80"><i class="fas fa-circle-check"></i></span><div>Orden de pago autorizada<small>OP26-0214 · hace 2 min</small></div></div>
      <div class="flotante dos"><span class="ic" style="background:rgba(139,92,246,.2);color:#c4b5fd"><i class="fas fa-bell"></i></span><div>Anticipo por revisar<small>Le llegó también por correo</small></div></div>
    </div>
  </div>
</section>

<!-- ══ Cifras ══ -->
<div class="cifras">
  <div class="contenedor">
    <div class="revelar">
      <div class="cifra"><span class="ic"><i class="fas fa-layer-group"></i></span><div><strong>30+ módulos</strong><span>Conectados entre sí</span></div></div>
      <div class="cifra"><span class="ic"><i class="fas fa-print"></i></span><div><strong>26 documentos</strong><span>Listos para imprimir o en PDF</span></div></div>
      <div class="cifra"><span class="ic"><i class="fas fa-sitemap"></i></span><div><strong>Aprobación en 3 pasos</strong><span>Según su organigrama</span></div></div>
      <div class="cifra"><span class="ic"><i class="fas fa-building"></i></span><div><strong>Multiempresa</strong><span>Datos separados por empresa</span></div></div>
    </div>
  </div>
</div>

<!-- ══ Módulos ══ -->
<section class="seccion" id="modulos">
  <div class="contenedor">
    <div class="encabezado revelar">
      <span class="antetitulo">Módulos</span>
      <h2>Todo lo que su equipo administra cada día</h2>
      <p>Cada área trabaja en su módulo, pero la información se comparte: una compra autorizada genera su orden de pago y una planilla alimenta los reportes, sin volver a digitar nada.</p>
    </div>
    <div class="modulos">
      <?php foreach ($modulos as [$icono, $color, $titulo, $texto, $partes]): ?>
      <article class="modulo revelar" style="--c:<?= $color ?>">
        <div class="ic"><i class="fas <?= $icono ?>"></i></div>
        <h3><?= $h($titulo) ?></h3>
        <p><?= $h($texto) ?></p>
        <ul><?php foreach ($partes as $p): ?><li><?= $h($p) ?></li><?php endforeach; ?></ul>
      </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ══ Aprobaciones ══ -->
<section class="seccion alterna" id="aprobaciones">
  <div class="contenedor dos-columnas">
    <div class="bloque-texto revelar">
      <span class="antetitulo">Control y transparencia</span>
      <h2>Cada proceso pasa por las manos correctas</h2>
      <p>Las solicitudes siguen el camino Creado → Revisado → Autorizado. Solo las aprueban los puestos superiores de quien las hizo, y nunca la misma persona que las creó.</p>
      <ul class="lista-puntos">
        <li><span class="ic"><i class="fas fa-sitemap"></i></span><div><strong>Según su organigrama</strong><span>Usted define los puestos, de quién depende cada uno y quiénes aprueban.</span></div></li>
        <li><span class="ic"><i class="fas fa-bell"></i></span><div><strong>Avisos en la campana y por correo</strong><span>Cada persona sabe qué le toca revisar y cuándo se resolvió lo suyo.</span></div></li>
        <li><span class="ic"><i class="fas fa-camera"></i></span><div><strong>Rechazos con motivo y evidencia</strong><span>Quien rechaza explica por qué y puede adjuntar fotos o documentos.</span></div></li>
        <li><span class="ic"><i class="fas fa-signature"></i></span><div><strong>Firmas reales en los documentos</strong><span>"Elaborado, revisado y autorizado por" muestran a las personas que lo hicieron.</span></div></li>
      </ul>
    </div>
    <div class="tarjeta-flujo revelar" aria-label="Ejemplo de un proceso de compra">
      <div class="tf-cab"><div><strong>Solicitud de compra SC26-0231</strong><small>Equipo de cómputo · Proyecto Agua Segura</small></div><span class="insignia">En curso</span></div>
      <div class="tf-pasos">
        <div class="tf-paso hecho"><span class="ic"><i class="fas fa-pen"></i></span><div><strong>Creado</strong><span>Ana Martínez · Asistente de proyecto</span></div><time>Lun 9:12</time></div>
        <div class="tf-paso hecho"><span class="ic"><i class="fas fa-magnifying-glass"></i></span><div><strong>Revisado</strong><span>Carlos Díaz · Coordinador</span></div><time>Lun 11:40</time></div>
        <div class="tf-paso actual"><span class="ic"><i class="fas fa-signature"></i></span><div><strong>Autorización</strong><span>Esperando a Dirección Ejecutiva</span></div><time>Pendiente</time></div>
        <div class="tf-paso final"><span class="ic"><i class="fas fa-money-bill-transfer"></i></span><div><strong>Orden de compra y de pago</strong><span>Continúan el proceso con el mismo número</span></div><time>OC / OP26-0231</time></div>
      </div>
      <div class="tf-aviso"><i class="fas fa-paperclip"></i> Cotizaciones y facturas adjuntas, también con foto desde el celular.</div>
    </div>
  </div>
</section>

<!-- ══ Ventajas ══ -->
<section class="seccion">
  <div class="contenedor">
    <div class="encabezado revelar">
      <span class="antetitulo">Hecho para el trabajo diario</span>
      <h2>Rápido de aprender, cómodo de usar</h2>
    </div>
    <div class="ventajas">
      <div class="ventaja revelar"><i class="fas fa-mobile-screen"></i><h3>Desde cualquier equipo</h3><p>En la computadora, la tableta o el celular, solo con el navegador.</p></div>
      <div class="ventaja revelar"><i class="fas fa-file-pdf"></i><h3>Documentos con su imagen</h3><p>Su logo y datos en cada orden, recibo, constancia o memorando, listos para imprimir o descargar en PDF.</p></div>
      <div class="ventaja revelar"><i class="fas fa-user-lock"></i><h3>Cada quien ve lo suyo</h3><p>Permisos por puesto: el personal ve solo sus solicitudes; los jefes, las de su equipo.</p></div>
      <div class="ventaja revelar"><i class="fas fa-moon"></i><h3>Modo claro y oscuro</h3><p>Un diseño limpio que descansa la vista en jornadas largas.</p></div>
      <div class="ventaja revelar"><i class="fas fa-clock-rotate-left"></i><h3>Bitácora completa</h3><p>Quién hizo qué y cuándo: creaciones, cambios, impresiones e ingresos.</p></div>
      <div class="ventaja revelar"><i class="fas fa-hashtag"></i><h3>Numeración ordenada</h3><p>Cada proceso y su orden de pago comparten el mismo correlativo anual.</p></div>
      <div class="ventaja revelar"><i class="fas fa-landmark"></i><h3>Al día con la ley</h3><p>Deducciones de IHSS, RAP e ISR calculadas y los reportes para preparar sus declaraciones.</p></div>
      <div class="ventaja revelar"><i class="fas fa-users-gear"></i><h3>Usuarios automáticos</h3><p>Cada empleado con correo recibe su usuario para hacer sus propias solicitudes.</p></div>
    </div>
  </div>
</section>

<!-- ══ Seguridad ══ -->
<section class="seccion alterna" id="seguridad">
  <div class="contenedor seguridad">
    <div class="escudo revelar">
      <div class="grande"><i class="fas fa-shield-halved"></i></div>
      <h3>Su información, protegida</h3>
      <p>Cada empresa tiene su propia base de datos, separada de las demás.</p>
      <ul>
        <li><i class="fas fa-check"></i>Conexión cifrada (HTTPS) en todo momento</li>
        <li><i class="fas fa-check"></i>Respaldos automáticos de la información</li>
        <li><i class="fas fa-check"></i>Registro de cada acción en la bitácora</li>
      </ul>
    </div>
    <div class="revelar">
      <div class="bloque-texto" style="margin-bottom:1.6rem">
        <span class="antetitulo">Seguridad</span>
        <h2>Acceso controlado, de principio a fin</h2>
      </div>
      <div class="rejilla-seg">
        <div><i class="fas fa-key"></i><strong>Contraseñas seguras</strong><span>Cambio obligatorio de la contraseña inicial y recuperación por correo.</span></div>
        <div><i class="fas fa-ban"></i><strong>Bloqueo por intentos</strong><span>Tras 5 contraseñas incorrectas, el usuario se bloquea hasta que un administrador lo libere.</span></div>
        <div><i class="fas fa-hourglass-end"></i><strong>Sesiones que expiran</strong><span>La sesión se cierra sola tras 2 horas sin actividad.</span></div>
        <div><i class="fas fa-user-shield"></i><strong>Permisos por puesto</strong><span>Cada puesto abre solo los módulos que usted le asigna.</span></div>
      </div>
    </div>
  </div>
</section>

<!-- ══ Clientes: iniciar sesión ══ -->
<section class="seccion" style="padding-bottom:0">
  <div class="contenedor">
    <div class="ingreso revelar">
      <div>
        <h2>¿Su organización ya usa Proscala?</h2>
        <p>Ingrese con el RTN de su empresa, su correo y su contraseña.</p>
      </div>
      <div class="acciones">
        <a href="<?= BASE_URL ?>login.php" class="btn btn-primario btn-grande"><i class="fas fa-right-to-bracket"></i> Iniciar sesión</a>
        <a href="<?= BASE_URL ?>recuperar.php" class="btn btn-borde btn-grande"><i class="fas fa-key"></i> Olvidé mi contraseña</a>
      </div>
    </div>
  </div>
</section>

<!-- ══ Contacto ══ -->
<section class="seccion" id="contacto">
  <div class="contenedor contacto">
    <div class="bloque-texto revelar">
      <span class="antetitulo">Contratar Proscala</span>
      <h2>Llevemos su administración al siguiente nivel</h2>
      <p>Cuéntenos sobre su organización y le mostraremos cómo Proscala se adapta a sus puestos, sus procesos y su forma de trabajar.</p>
      <ul class="medios">
        <?php if ($correoPub): ?><li><a href="mailto:<?= $h($correoPub) ?>"><span class="ic"><i class="fas fa-envelope"></i></span><div><small>Correo</small><?= $h($correoPub) ?></div></a></li><?php endif; ?>
        <?php if ($telefono): ?><li><a href="tel:<?= $h(preg_replace('/[^\d+]/', '', $telefono)) ?>"><span class="ic"><i class="fas fa-phone"></i></span><div><small>Teléfono</small><?= $h($telefono) ?></div></a></li><?php endif; ?>
        <?php if ($whatsapp): ?><li><a href="https://wa.me/<?= $h($whatsapp) ?>" target="_blank" rel="noopener"><span class="ic"><i class="fab fa-whatsapp"></i></span><div><small>WhatsApp</small>Escríbanos</div></a></li><?php endif; ?>
      </ul>
    </div>

    <form class="formulario revelar" id="form-contacto" method="post" action="<?= BASE_URL ?>#contacto" novalidate>
      <h3>Solicitar información</h3>
      <div class="campos">
        <div class="campo"><label for="c-nombre">Nombre *</label><input id="c-nombre" name="nombre" autocomplete="name" required maxlength="120"></div>
        <div class="campo"><label for="c-org">Organización *</label><input id="c-org" name="organizacion" autocomplete="organization" required maxlength="160"></div>
        <div class="campo"><label for="c-email">Correo *</label><input id="c-email" name="email" type="email" autocomplete="email" required maxlength="150"></div>
        <div class="campo"><label for="c-tel">Teléfono</label><input id="c-tel" name="telefono" type="tel" autocomplete="tel" maxlength="40"></div>
        <div class="campo completo"><label for="c-tamano">Tamaño de la organización</label>
          <select id="c-tamano" name="tamano">
            <option value="">Seleccione…</option>
            <option>1 a 10 empleados</option><option>11 a 50 empleados</option><option>51 a 200 empleados</option><option>Más de 200 empleados</option>
          </select>
        </div>
        <div class="campo completo"><label for="c-msg">¿Qué le gustaría resolver?</label><textarea id="c-msg" name="mensaje" maxlength="3000" placeholder="Por ejemplo: llevar las planillas y las compras de varios proyectos…"></textarea></div>
      </div>
      <div class="trampa" aria-hidden="true"><label>Sitio web <input name="sitio_web" tabindex="-1" autocomplete="off"></label></div>
      <input type="hidden" name="t" value="<?= time() ?>">
      <button type="submit" class="btn btn-primario btn-grande"><i class="fas fa-paper-plane"></i> Enviar solicitud</button>
      <div class="mensaje-form <?= $respuesta ? ($respuesta[0] ? 'ok' : 'error') : '' ?>" id="msg-contacto" role="status" aria-live="polite"><?= $respuesta ? $h($respuesta[1]) : '' ?></div>
      <p class="nota">Usaremos sus datos solo para responderle.</p>
    </form>
  </div>
</section>

</main>

<!-- ══ Pie ══ -->
<footer class="pie">
  <div class="contenedor">
    <div class="arriba">
      <div>
        <a href="<?= BASE_URL ?>" class="marca"><img src="<?= BASE_URL ?>assets/images/proscala.png" alt=""> Proscala</a>
        <p>Sistema de Administración Empresarial para organizaciones que quieren orden, control y transparencia.</p>
      </div>
      <nav aria-label="Pie de página">
        <div><h4>Producto</h4><ul><li><a href="#modulos">Módulos</a></li><li><a href="#aprobaciones">Aprobaciones</a></li><li><a href="#seguridad">Seguridad</a></li></ul></div>
        <div><h4>Clientes</h4><ul><li><a href="<?= BASE_URL ?>login.php">Iniciar sesión</a></li><li><a href="<?= BASE_URL ?>recuperar.php">Recuperar contraseña</a></li><li><a href="#contacto">Contratar</a></li></ul></div>
      </nav>
    </div>
    <div class="abajo"><span>&copy; <?= date('Y') ?> Proscala. Todos los derechos reservados.</span><span>Versión <?= $h(APP_VERSION) ?></span></div>
  </div>
</footer>

<!-- Celulares: iniciar sesión siempre a mano -->
<div class="ingreso-fijo" id="ingreso-fijo">
  <a href="<?= BASE_URL ?>login.php" class="btn btn-primario"><i class="fas fa-right-to-bracket"></i> Iniciar sesión</a>
</div>

<script>
(function () {
  const raiz = document.documentElement;
  // Tema claro / oscuro (el mismo que recuerda el sistema)
  const btnTema = document.getElementById('btn-tema');
  const icono = () => { btnTema.innerHTML = raiz.getAttribute('data-theme') === 'dark' ? '<i class="fas fa-sun"></i>' : '<i class="fas fa-moon"></i>'; };
  icono();
  btnTema.addEventListener('click', () => {
    const t = raiz.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    raiz.setAttribute('data-theme', t);
    try { localStorage.setItem('proscala-theme', t); } catch (e) {}
    icono();
  });

  // Barra con fondo al desplazarse; botón fijo de ingreso al pasar la portada (celulares)
  const nav = document.getElementById('nav'), fijo = document.getElementById('ingreso-fijo');
  const alDesplazar = () => {
    nav.classList.toggle('con-fondo', scrollY > 20);
    fijo.classList.toggle('visible', scrollY > innerHeight * .8 && innerHeight + scrollY < document.body.scrollHeight - 160);
  };
  addEventListener('scroll', alDesplazar, { passive: true });
  alDesplazar();

  // Menú en celulares
  const menu = document.getElementById('nav-enlaces'), btnMenu = document.getElementById('btn-menu');
  const cerrarMenu = () => { menu.classList.remove('abierto'); btnMenu.setAttribute('aria-expanded', 'false'); btnMenu.innerHTML = '<i class="fas fa-bars"></i>'; };
  btnMenu.addEventListener('click', e => {
    e.stopPropagation();
    const abrir = !menu.classList.contains('abierto');
    menu.classList.toggle('abierto', abrir);
    btnMenu.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    btnMenu.innerHTML = abrir ? '<i class="fas fa-xmark"></i>' : '<i class="fas fa-bars"></i>';
    if (abrir) nav.classList.add('con-fondo');
  });
  menu.querySelectorAll('a').forEach(a => a.addEventListener('click', cerrarMenu));
  document.addEventListener('click', e => { if (!menu.contains(e.target)) cerrarMenu(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarMenu(); });

  // Aparición suave de las secciones
  const revelar = document.querySelectorAll('.revelar');
  if ('IntersectionObserver' in window) {
    const obs = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); } }), { rootMargin: '0px 0px -8% 0px' });
    revelar.forEach(el => obs.observe(el));
  } else revelar.forEach(el => el.classList.add('visible'));

  // Formulario de contacto sin recargar la página
  const form = document.getElementById('form-contacto'), msg = document.getElementById('msg-contacto');
  form.addEventListener('submit', async e => {
    e.preventDefault();
    if (!form.reportValidity()) return;
    const btn = form.querySelector('button[type=submit]'), texto = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enviando…';
    try {
      const r = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const d = await r.json();
      msg.className = 'mensaje-form ' + (d.success ? 'ok' : 'error');
      msg.textContent = d.message;
      if (d.success) form.reset();
    } catch (_) {
      msg.className = 'mensaje-form error';
      msg.textContent = 'No pudimos enviar su mensaje. Revise su conexión e intente de nuevo.';
    } finally {
      btn.disabled = false; btn.innerHTML = texto;
    }
  });
})();
</script>
</body>
</html>

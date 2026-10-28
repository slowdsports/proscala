/* Proscala - Sistema de Administración | JS principal */
'use strict';

// ── Toast notifications ──────────────────────────────────────────
// El mensaje se muestra como texto (los mensajes del servidor pueden traer nombres de archivo u
// otros datos escritos por usuarios); { html: true } solo para HTML armado y escapado aquí mismo.
const Toast = {
  show(msg, type = 'info', duration = 4000, { html = false } = {}) {
    let c = document.getElementById('toast-container');
    if (!c) { c = document.createElement('div'); c.id = 'toast-container'; document.body.appendChild(c); }
    const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info', warning: 'fa-triangle-exclamation' };
    if (type === 'danger') type = 'error';   // nombre de Bootstrap
    const el = document.createElement('div');
    el.className = `toast-msg ${type}`;
    const icono = document.createElement('i');
    icono.className = `fas ${icons[type] || icons.info}`;
    const texto = document.createElement('span');
    if (html) texto.innerHTML = msg; else texto.textContent = String(msg ?? '');
    el.append(icono, texto);
    c.appendChild(el);
    setTimeout(() => { el.style.animation = 'fadeOut .4s forwards'; setTimeout(() => el.remove(), 400); }, duration);
  }
};

// ── AJAX helper ──────────────────────────────────────────────────
async function ajax(url, data = null, method = 'GET') {
  const opts = {
    method,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  };
  if (data) {
    if (data instanceof FormData) {
      opts.body = data;
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(data);
    }
  }
  const res = await fetch(url, opts);
  Sesion.contacto();
  if (!res.ok) {
    let body;
    try { body = await res.json(); } catch (_) {}
    if (res.status === 401 && body?.sesion_expirada) Sesion.expirada(body.message);
    throw new Error(body?.message || `HTTP ${res.status}`);
  }
  // Una acción (revisar, autorizar, crear…) puede cambiar lo pendiente de la campana
  if (method === 'POST' && !/notificaciones\.php/.test(url)) Notificaciones.programar();
  const json = await res.json();
  // Formulario guardado con documentos elegidos al llenarlo: se suben antes de seguir (AdjuntosForm)
  if (method === 'POST' && json?.success && !/adjuntos\.php/.test(url)) await AdjuntosForm.alGuardar(data, json);
  return json;
}

// ── Sesión ─────────────────────────────────────────────────────────
// El servidor cierra la sesión tras 2 h sin actividad (config/db.php). Mientras el usuario
// trabaja (teclado, mouse, toques) se envía un pulso cada 5 min que la mantiene abierta;
// poco antes del cierre por inactividad se avisa y se ofrece seguir conectado.
const Sesion = {
  INACTIVIDAD: 2 * 3600 * 1000,
  AVISO: 5 * 60 * 1000,          // avisar 5 min antes del cierre
  PULSO: 5 * 60 * 1000,
  ultimaActividad: Date.now(),
  ultimoContacto: Date.now(),    // última petición al servidor (renueva la sesión)
  avisoVisible: false,
  contacto() {
    this.ultimoContacto = Date.now();
    if (this.avisoEl) { this.avisoEl.remove(); this.avisoEl = null; this.avisoVisible = false; }
  },
  async pulso() {
    try {
      const r = await fetch('sesion.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
      if (r.status === 401) { let b = {}; try { b = await r.json(); } catch (_) {} this.expirada(b.message); return; }
      if (r.ok) this.contacto();
    } catch (_) { /* sin red: se reintenta en el próximo pulso */ }
  },
  expirada(mensaje) {
    if (this.terminada) return;
    this.terminada = true;
    Toast.show(mensaje || 'Su sesión terminó. Ingrese de nuevo.', 'warning', 6000);
    setTimeout(() => { location.href = 'login.php'; }, 2500);
  },
  revisar() {
    if (!document.getElementById('main-content') || this.terminada) return;
    const ahora = Date.now();
    // Trabajó en los últimos minutos y hace rato que no hay contacto: renovar
    if (ahora - this.ultimaActividad < this.PULSO && ahora - this.ultimoContacto >= this.PULSO) this.pulso();
    const sinContacto = ahora - this.ultimoContacto;
    if (sinContacto >= this.INACTIVIDAD) { this.expirada('Su sesión se cerró por inactividad. Ingrese de nuevo.'); return; }
    if (sinContacto >= this.INACTIVIDAD - this.AVISO && !this.avisoVisible) this.mostrarAviso();
  },
  mostrarAviso() {
    this.avisoVisible = true;
    const el = document.createElement('div');
    el.className = 'sesion-aviso';
    el.innerHTML = '<i class="fas fa-clock"></i><div><strong>Su sesión está por cerrarse</strong>'
      + '<div>Por seguridad se cerrará en unos minutos por falta de actividad.</div></div>'
      + '<button type="button" class="btn btn-sm btn-primary">Seguir conectado</button>';
    el.querySelector('button').onclick = () => this.pulso();
    this.avisoEl = document.body.appendChild(el);
  },
};
['keydown', 'mousedown', 'mousemove', 'touchstart', 'scroll', 'wheel'].forEach(ev =>
  window.addEventListener(ev, () => { Sesion.ultimaActividad = Date.now(); }, { passive: true, capture: true }));
setInterval(() => Sesion.revisar(), 30 * 1000);
document.addEventListener('visibilitychange', () => { if (!document.hidden) Sesion.revisar(); });

// ── Notificaciones (campana de la barra superior → notificaciones.php) ──
// Lo que el usuario tiene pendiente de revisar o autorizar y sus avisos (proceso revisado,
// autorizado, rechazado…). Se actualiza al cargar, al navegar, tras cada acción y cada 2 min
// con la pestaña visible; consultarla no mantiene viva la sesión. Un aviso nuevo se anuncia.
const Notificaciones = {
  INTERVALO: 2 * 60 * 1000,
  datos: null,
  ultimoId: null,           // aviso más reciente ya visto (para anunciar los nuevos)
  temporizador: null,
  esc: s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]),

  iniciar() {
    const btn = document.getElementById('btn-notif');
    if (!btn) return;
    btn.addEventListener('click', e => { e.stopPropagation(); this.alternar(); });
    document.getElementById('notif-leer-todas').addEventListener('click', () => this.leer(null));
    document.addEventListener('click', e => { if (!document.getElementById('notif').contains(e.target)) this.alternar(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') this.alternar(false); });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) this.actualizar(); });
    setInterval(() => { if (!document.hidden) this.actualizar(); }, this.INTERVALO);
    this.actualizar();
  },

  // Tras una acción o al navegar: una sola consulta aunque haya varias seguidas
  programar() {
    clearTimeout(this.temporizador);
    this.temporizador = setTimeout(() => this.actualizar(), 800);
  },

  async actualizar() {
    if (!document.getElementById('btn-notif') || Sesion.terminada) return;
    try {
      const r = await fetch('notificaciones.php', { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
      if (r.status === 401) { let b = {}; try { b = await r.json(); } catch (_) {} if (b.sesion_expirada) Sesion.expirada(b.message); return; }
      if (r.ok) this.recibir(await r.json());
    } catch (_) { /* sin red: se reintenta en la próxima consulta */ }
  },

  recibir(d) {
    if (!d?.success) return;
    const nuevos = this.ultimoId === null ? [] : d.avisos.filter(a => !a.leida && a.id > this.ultimoId);
    this.ultimoId = Math.max(this.ultimoId ?? 0, ...d.avisos.map(a => a.id));
    if (nuevos.length === 1) Toast.show(`<strong>${this.esc(nuevos[0].titulo)}</strong><br>${this.esc(nuevos[0].mensaje)}`, 'info', 6000, { html: true });
    else if (nuevos.length > 1) Toast.show(`Tiene ${nuevos.length} notificaciones nuevas.`, 'info', 6000);
    this.datos = d;
    this.pintar();
  },

  alternar(abrir) {
    const panel = document.getElementById('notif-panel'), btn = document.getElementById('btn-notif');
    if (!panel) return;
    abrir = abrir ?? panel.hidden;
    panel.hidden = !abrir;
    btn.setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (abrir) this.actualizar();
  },

  // Marca un aviso (o todos, con id null) como leído
  async leer(id) {
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
    fd.append('_action', id ? 'leer' : 'leer_todas');
    if (id) fd.append('id', id);
    try { this.recibir(await post('notificaciones.php', fd)); } catch (_) {}
  },

  abrir(url, id) {
    this.alternar(false);
    if (id) this.leer(id);
    Nav.ir(url);
  },

  hace(fecha) {
    const t = new Date(String(fecha).replace(' ', 'T'));
    const min = Math.round((Date.now() - t) / 60000);
    if (isNaN(min)) return '';
    if (min < 1) return 'Ahora';
    if (min < 60) return `Hace ${min} min`;
    if (min < 24 * 60) return `Hace ${Math.round(min / 60)} h`;
    if (min < 7 * 24 * 60) { const d = Math.round(min / 1440); return `Hace ${d} día${d === 1 ? '' : 's'}`; }
    return t.toLocaleDateString('es-HN', { day: '2-digit', month: '2-digit', year: 'numeric' });
  },

  pintar() {
    const d = this.datos, esc = this.esc;
    const total = d.pendientes.total + d.no_leidas;
    const badge = document.getElementById('notif-badge');
    badge.hidden = total === 0;
    badge.textContent = total > 99 ? '99+' : total;
    document.getElementById('btn-notif').title = total ? `Notificaciones (${total})` : 'Notificaciones';
    document.getElementById('notif-leer-todas').hidden = d.no_leidas === 0;

    const iconos = {
      revisar: ['fa-magnifying-glass', 'aviso'], autorizar: ['fa-signature', 'aviso'], pendiente: ['fa-hourglass-half', 'aviso'],
      revisado: ['fa-eye', ''], autorizado: ['fa-circle-check', 'exito'], rechazado: ['fa-circle-xmark', 'peligro'], corregido: ['fa-pen', ''],
      delegacion: ['fa-user-clock', ''], recordatorio: ['fa-clock', 'aviso'], escalado: ['fa-arrow-up-right-dots', 'peligro'],
    };
    const item = (tipo, titulo, mensaje, fecha, url, id, noLeida) => {
      const [ic, clase] = iconos[tipo] || ['fa-bell', ''];
      return `<button type="button" class="notif-item${noLeida ? ' no-leida' : ''}" data-url="${esc(url)}" data-id="${id || ''}">
        <span class="notif-icono ${clase}"><i class="fas ${ic}"></i></span>
        <span class="notif-texto"><span class="notif-titulo">${esc(titulo)}</span>
          <span class="notif-mensaje d-block">${esc(mensaje)}</span>
          <span class="notif-fecha d-block">${esc(this.hace(fecha))}</span></span></button>`;
    };

    let html = '';
    if (d.pendientes.total) {
      html += `<div class="notif-seccion">Por aprobar (${d.pendientes.total})</div>`;
      html += d.pendientes.items.map(p => item(p.paso, `${p.numero} · ${p.proceso}`,
        (p.paso === 'revisar' ? 'Por revisar' : 'Por autorizar') + (p.de ? ` · de ${p.de}` : ''), p.fecha, p.url, 0, false)).join('');
      if (d.pendientes.total > d.pendientes.items.length) html += `<div class="notif-mas">Y ${d.pendientes.total - d.pendientes.items.length} más en sus módulos.</div>`;
    }
    if (d.avisos.length) {
      html += `<div class="notif-seccion">Avisos${d.no_leidas ? ` (${d.no_leidas} sin leer)` : ''}</div>`;
      html += d.avisos.map(a => item(a.tipo, a.titulo, a.mensaje, a.fecha, a.url, a.id, !a.leida)).join('');
    }
    const cuerpo = document.getElementById('notif-cuerpo');
    cuerpo.innerHTML = html || '<div class="notif-vacio"><i class="fas fa-bell-slash"></i>No tiene notificaciones.</div>';
    cuerpo.querySelectorAll('.notif-item').forEach(b => b.addEventListener('click', () => this.abrir(b.dataset.url, +b.dataset.id)));
  },
};
document.addEventListener('DOMContentLoaded', () => Notificaciones.iniciar());

// ── Búsqueda global (barra superior → buscar.php) ──────────────────
// Procesos por número o texto, empleados, proveedores y módulos, solo los que el usuario puede
// ver. Ctrl+K o "/" para buscar; flechas y Enter para elegir; Esc para cerrar.
const Busqueda = {
  temporizador: null, control: null, items: [], activo: -1,
  iniciar() {
    const input = document.getElementById('buscador-input');
    if (!input) return;
    this.input = input;
    this.panel = document.getElementById('buscador-panel');
    this.caja = document.getElementById('buscador');
    input.addEventListener('input', () => { clearTimeout(this.temporizador); this.temporizador = setTimeout(() => this.buscar(), 250); });
    input.addEventListener('keydown', e => this.teclas(e));
    input.addEventListener('focus', () => { if (this.items.length) this.mostrar(true); });
    document.getElementById('btn-buscar')?.addEventListener('click', e => { e.stopPropagation(); this.abrir(); });
    document.addEventListener('click', e => { if (!this.caja.contains(e.target)) this.cerrar(); });
    document.addEventListener('keydown', e => {
      const escribiendo = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
      if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !escribiendo)) { e.preventDefault(); this.abrir(); }
    });
  },
  abrir() { this.caja.classList.add('abierto'); this.input.focus(); this.input.select(); },
  cerrar() { this.mostrar(false); this.caja.classList.remove('abierto'); },
  mostrar(si) { this.panel.hidden = !si; this.input.setAttribute('aria-expanded', si ? 'true' : 'false'); },
  async buscar() {
    const q = this.input.value.trim();
    if (q.length < 2) { this.items = []; this.mostrar(false); return; }
    this.control?.abort();
    this.control = new AbortController();
    try {
      const r = await fetch('buscar.php?q=' + encodeURIComponent(q), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: this.control.signal });
      if (r.status === 401) { Sesion.expirada(); return; }
      const d = await r.json();
      Sesion.contacto();
      this.pintar(d.resultados || [], q);
    } catch (e) { if (e.name !== 'AbortError') this.pintar([], q); }
  },
  pintar(resultados, q) {
    const esc = Notificaciones.esc;
    this.items = resultados;
    this.activo = resultados.length ? 0 : -1;
    if (!resultados.length) {
      this.panel.innerHTML = `<div class="buscador-vacio"><i class="fas fa-magnifying-glass"></i> Sin resultados para “${esc(q)}”.</div>`;
    } else {
      let html = '', grupo = null;
      resultados.forEach((r, i) => {
        if (r.grupo !== grupo) { grupo = r.grupo; html += `<div class="buscador-grupo">${esc(grupo)}</div>`; }
        html += `<a class="buscador-item${i === 0 ? ' activo' : ''}" href="${esc(r.url)}" data-i="${i}" role="option">
          <i class="fas ${esc(r.icono || 'fa-file')}"></i><span class="bx"><span class="bt">${esc(r.titulo)}</span>${r.subtitulo ? `<span class="bs">${esc(r.subtitulo)}</span>` : ''}</span>
          ${r.estado ? `<span class="badge bg-secondary text-capitalize">${esc(r.estado)}</span>` : ''}</a>`;
      });
      this.panel.innerHTML = html;
      this.panel.querySelectorAll('.buscador-item').forEach(a => a.addEventListener('click', e => { e.preventDefault(); this.ir(+a.dataset.i); }));
    }
    this.mostrar(true);
  },
  marcar(i) {
    const els = this.panel.querySelectorAll('.buscador-item');
    if (!els.length) return;
    this.activo = (i + els.length) % els.length;
    els.forEach((el, k) => el.classList.toggle('activo', k === this.activo));
    els[this.activo].scrollIntoView({ block: 'nearest' });
  },
  teclas(e) {
    if (e.key === 'ArrowDown') { e.preventDefault(); this.marcar(this.activo + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); this.marcar(this.activo - 1); }
    else if (e.key === 'Enter' && this.activo >= 0) { e.preventDefault(); this.ir(this.activo); }
    else if (e.key === 'Escape') { this.cerrar(); this.input.blur(); }
  },
  ir(i) {
    const r = this.items[i];
    if (!r) return;
    this.cerrar();
    this.input.value = '';
    this.items = [];
    Nav.ir(r.url);
  },
};
document.addEventListener('DOMContentLoaded', () => Busqueda.iniciar());

async function post(url, data) { return ajax(url, data, 'POST'); }

// ── Dark / Light mode ─────────────────────────────────────────────
function toggleTheme() {
  const html    = document.documentElement;
  const isDark  = html.getAttribute('data-theme') === 'dark';
  const next    = isDark ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  html.setAttribute('data-bs-theme', next);   // componentes de Bootstrap (alertas, pestañas…)
  try { localStorage.setItem('proscala-theme', next); } catch (_) {}
  _syncThemeIcon(next);
  document.dispatchEvent(new CustomEvent('proscala:themechange', { detail: { theme: next } }));
}

function _syncThemeIcon(theme) {
  const icon = document.getElementById('theme-icon');
  if (!icon) return;
  if (theme === 'dark') {
    icon.classList.replace('fa-moon', 'fa-sun');
  } else {
    icon.classList.replace('fa-sun', 'fa-moon');
  }
}

// ── Sidebar toggle ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Sync icon with stored theme on load
  _syncThemeIcon(localStorage.getItem('proscala-theme') || 'light');
  const sidebar = document.getElementById('sidebar');
  const main    = document.getElementById('main-content');
  const btn     = document.getElementById('btn-toggle-sidebar');

  // persist state
  const collapsed = localStorage.getItem('sidebar-collapsed') === '1';
  if (collapsed) { sidebar?.classList.add('collapsed'); main?.classList.add('expanded'); }

  btn?.addEventListener('click', () => {
    const isMobile = window.innerWidth < 768;
    if (isMobile) {
      sidebar?.classList.toggle('open');
    } else {
      sidebar?.classList.toggle('collapsed');
      main?.classList.toggle('expanded');
      localStorage.setItem('sidebar-collapsed', sidebar?.classList.contains('collapsed') ? '1' : '0');
    }
  });

  // Menú lateral: conserva su desplazamiento entre cargas completas y deja visible el activo
  try { if (sidebar) sidebar.scrollTop = +sessionStorage.getItem('sidebar-scroll') || 0; } catch (_) {}
  window.addEventListener('beforeunload', () => {
    try { sessionStorage.setItem('sidebar-scroll', sidebar?.scrollTop || 0); } catch (_) {}
  });
  marcarSidebarActivo();
  initSelect2(document);
  animarContenido(document);

  // Sub-menus toggle
  document.querySelectorAll('.sidebar-group-title').forEach(t => {
    t.addEventListener('click', () => {
      const sub = t.nextElementSibling;
      if (sub?.classList.contains('sidebar-submenu')) {
        sub.style.display = sub.style.display === 'none' ? 'block' : 'none';
      }
    });
  });

  // Auto-close sidebar overlay on mobile when clicking outside
  document.addEventListener('click', (e) => {
    if (window.innerWidth < 768 && sidebar?.classList.contains('open')) {
      if (!sidebar.contains(e.target) && e.target !== btn) {
        sidebar.classList.remove('open');
      }
    }
  });
});

// ── Menú lateral: elemento activo ──────────────────────────────────
// Marca el módulo de la URL actual (también tras navegar sin recarga) y lo deja a la vista
function marcarSidebarActivo() {
  const actual = location.pathname.split('/').pop();
  let activo = null;
  document.querySelectorAll('#sidebar .sidebar-item[data-page]').forEach(el => {
    const es = el.dataset.page === actual;
    el.classList.toggle('active', es);
    if (es) activo = el;
  });
  activo?.scrollIntoView({ block: 'nearest' });
}

// ── Componentes de formulario ─────────────────────────────────────
// Flatpickr en los campos de fecha que aún no lo tienen
function initFechas(root) {
  if (typeof flatpickr === 'undefined') return;
  const campos = root.querySelectorAll('.date-input:not(.flatpickr-input)');
  if (campos.length) flatpickr(campos, { locale: 'es', dateFormat: 'Y-m-d', allowInput: true });
}

// Select2: buscador en todos los <select> (excepto .no-search), también en los agregados después
function initSelect2(context) {
  if (typeof $ === 'undefined' || !$.fn.select2) return;
  $(context).find('select').not('.no-search, .select2-hidden-accessible, .dataTables_length select').each(function () {
    const $el = $(this);
    $el.select2({
      theme: 'bootstrap-5',
      language: 'es',
      width: '100%',
      dropdownAutoPosition: true,
      placeholder: $el.data('placeholder') || $el.attr('data-placeholder') || 'Seleccione...',
      allowClear: $el.hasClass('allow-clear'),
      dropdownParent: $el.closest('.modal').length ? $el.closest('.modal') : $(document.body)
    });
  });
  initSelect2.observar();
}
// Reaplica a selects agregados dinámicamente (filas nuevas, modales, etc.)
initSelect2.observador = null;
initSelect2.observar = function () {
  if (this.observador || typeof MutationObserver === 'undefined') return;
  this.observador = new MutationObserver((mutations) => {
    mutations.forEach((m) => {
      m.addedNodes.forEach((node) => {
        if (node.nodeType !== 1) return;
        if (node.matches && node.matches('select')) initSelect2(node.parentNode);
        else if (node.querySelector && node.querySelector('select')) initSelect2(node);
      });
    });
  });
  this.observador.observe(document.body, { childList: true, subtree: true });
};

// ── Navegación sin recargar ───────────────────────────────────────
// Los enlaces internos y los formularios GET cargan la página con fetch y solo reemplazan
// <main id="main-content">: la barra superior, el menú lateral y el pie no se vuelven a dibujar.
// Los scripts de cada página (los de <main> y el bloque data-pagina del pie) se ejecutan dentro
// de un bloque { } para que sus let/const no choquen al volver a la misma página; sus funciones
// siguen siendo globales (las usan los onclick). Lo que la página registra en document/window y
// sus setInterval se deshacen al salir de ella.
const Nav = {
  // Páginas que se abren de forma normal (documentos, PDF, sesión)
  excluir: /\/(print|pdf_download|logout|index|login|recuperar)\.php$/i,
  limpiezas: [],
  enCurso: null,
  captura: null,

  esNavegable(url) {
    return url.origin === location.origin
      && /(\.php|\/)$/i.test(url.pathname)
      && !this.excluir.test(url.pathname)
      && !url.searchParams.has('export');
  },

  async ir(destino, { push = true, mantenerScroll = false } = {}) {
    const url = new URL(destino, location.href);
    if (!this.esNavegable(url) || !document.getElementById('main-content')) { location.href = url.href; return; }
    this.enCurso?.abort();
    const ctrl = this.enCurso = new AbortController();
    const scroll = window.scrollY;
    document.body.classList.add('nav-cargando');
    let res, html;
    try {
      res = await fetch(url.href, { signal: ctrl.signal, headers: { 'X-Proscala-Nav': '1' } });
      Sesion.contacto();
      if (!(res.headers.get('content-type') || '').includes('text/html')) { location.href = url.href; return; }
      html = await res.text();
    } catch (e) {
      if (e.name !== 'AbortError') location.href = url.href;
      return;
    } finally {
      if (this.enCurso === ctrl) document.body.classList.remove('nav-cargando');
    }
    if (this.enCurso !== ctrl) return;

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nuevo = doc.getElementById('main-content');
    if (!nuevo) { location.href = res.url; return; }   // sin layout (p. ej. sesión vencida → login)
    const final = new URL(res.url);                     // tras redirecciones del servidor
    final.hash = url.hash;

    this.limpiar();
    initSelect2.observador?.disconnect();               // Select2 se aplica después de los scripts
    initSelect2.observador = null;
    const main = document.getElementById('main-content');
    main.innerHTML = nuevo.innerHTML;
    document.title = doc.title;
    history[push ? 'pushState' : 'replaceState']({ nav: true }, '', final.href);
    marcarSidebarActivo();
    document.getElementById('sidebar')?.classList.remove('open');
    Notificaciones.programar();   // al abrir un proceso sus avisos quedan leídos

    initFechas(main);
    await this.ejecutarScripts(main, doc);
    Excel.botonesTablas(main);
    initSelect2(main);
    animarContenido(main);
    if (mantenerScroll) window.scrollTo(0, scroll);
    else if (final.hash && document.getElementById(final.hash.slice(1))) document.getElementById(final.hash.slice(1)).scrollIntoView();
    else window.scrollTo(0, 0);
  },

  // Vuelve a cargar la página actual sin recargar el navegador (conserva el desplazamiento)
  recargar() { return this.ir(location.href, { push: false, mantenerScroll: true }); },

  async ejecutarScripts(main, doc) {
    const esJs = s => !s.type || /javascript|ecmascript/i.test(s.type);
    const scripts = [...main.querySelectorAll('script'), ...doc.querySelectorAll('script[data-pagina]')].filter(esJs);
    const codigo = [];
    for (const s of scripts) {
      if (s.src) await this.cargarScript(s.src);
      else codigo.push(s.textContent);
      if (main.contains(s)) s.remove();
    }
    if (!codigo.length) return;
    this.iniciarCaptura(true);
    try {
      const el = document.createElement('script');
      el.textContent = '{\n' + codigo.join('\n;\n') + '\n}';
      document.body.appendChild(el).remove();
    } finally {
      this.terminarCaptura();
    }
  },

  cargarScript(src) {
    const abs = new URL(src, location.href).href;
    if ([...document.scripts].some(s => s.src === abs)) return Promise.resolve();
    return new Promise(ok => {
      const el = document.createElement('script');
      el.src = abs;
      el.onload = el.onerror = ok;
      document.head.appendChild(el);
    });
  },

  // Mientras corre el script de una página, anota sus listeners e intervalos para quitarlos al salir.
  // diferirListo: al cargar por fetch, DOMContentLoaded/load ya ocurrieron → se ejecutan al terminar.
  iniciarCaptura(diferirListo = false) {
    const orig = { doc: document.addEventListener, win: window.addEventListener, intervalo: window.setInterval };
    const listos = [];
    const envolver = (destino, original) => function (tipo, cb, opts) {
      if (diferirListo && (tipo === 'DOMContentLoaded' || tipo === 'load')) { listos.push(cb); return; }
      Nav.limpiezas.push(() => destino.removeEventListener(tipo, cb, opts));
      return original.call(this, tipo, cb, opts);
    };
    document.addEventListener = envolver(document, orig.doc);
    window.addEventListener = envolver(window, orig.win);
    window.setInterval = function (...args) {
      const id = orig.intervalo.apply(window, args);
      Nav.limpiezas.push(() => clearInterval(id));
      return id;
    };
    this.captura = { orig, listos };
  },

  terminarCaptura() {
    if (!this.captura) return;
    const { orig, listos } = this.captura;
    document.addEventListener = orig.doc;
    window.addEventListener = orig.win;
    window.setInterval = orig.intervalo;
    this.captura = null;
    listos.forEach(cb => { try { cb.call(document, new Event('DOMContentLoaded')); } catch (e) { console.error(e); } });
  },

  // Deshace lo que dejó la página anterior, incluidos los elementos que los componentes crean fuera de <main>
  limpiar() {
    this.limpiezas.splice(0).forEach(f => { try { f(); } catch (_) {} });
    const main = document.getElementById('main-content');
    if (window.Chart?.instances) Object.values(Chart.instances).forEach(c => { if (main.contains(c.canvas)) c.destroy(); });
    main.querySelectorAll('.flatpickr-input').forEach(i => i._flatpickr?.destroy());
    if (window.$) {
      if ($.fn.DataTable) $(main).find('table.dataTable').each(function () { try { $(this).DataTable().destroy(); } catch (_) {} });
      if ($.fn.select2) $(main).find('select.select2-hidden-accessible').each(function () { try { $(this).select2('destroy'); } catch (_) {} });
    }
    document.querySelectorAll('.modal-backdrop, .tooltip, .popover').forEach(el => el.remove());
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
  },
};

// Enlaces internos: navegación sin recargar
document.addEventListener('click', e => {
  if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  const a = e.target.closest('a[href]');
  if (!a || a.target || a.hasAttribute('download') || 'noNav' in a.dataset) return;
  const href = a.getAttribute('href');
  if (!href || href.startsWith('#') || /^(javascript|mailto|tel):/i.test(href)) return;
  const url = new URL(a.href);
  if (!Nav.esNavegable(url)) return;
  e.preventDefault();
  Nav.ir(url.href);
});

// Formularios GET (filtros de búsqueda): también sin recargar
document.addEventListener('submit', e => {
  const f = e.target;
  if (e.defaultPrevented || (f.getAttribute('method') || 'get').toLowerCase() !== 'get' || f.target) return;
  const url = new URL(f.getAttribute('action') || location.href, location.href);
  if (!Nav.esNavegable(url)) return;
  e.preventDefault();
  url.search = new URLSearchParams(new FormData(f)).toString();
  Nav.ir(url.href);
});

// Atrás / Adelante del navegador
history.replaceState({ nav: true }, '', location.href);
window.addEventListener('popstate', e => { if (e.state?.nav) Nav.ir(location.href, { push: false }); });

// ── Diálogos del sistema ─────────────────────────────────────────
// Reemplazan a confirm() y alert() del navegador con el diseño de Proscala. Devuelven una
// promesa: `if (!await confirmar('¿Eliminar?', { peligro: true })) return;`
//   opciones: titulo, aceptar (texto del botón), cancelar, peligro (botón rojo), icono, tipo
const Dialogo = {
  modal: null,
  crear() {
    const el = document.createElement('div');
    el.className = 'modal fade dialogo-sistema';
    el.tabIndex = -1;
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = `
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-body">
            <div class="dialogo-icono"><i class="fas"></i></div>
            <h5 class="dialogo-titulo"></h5>
            <div class="dialogo-mensaje"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary dialogo-cancelar"></button>
            <button type="button" class="btn dialogo-aceptar"></button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(el);   // fuera de <main>: sobrevive a la navegación sin recarga
    return el;
  },
  abrir({ mensaje, titulo, aceptar = 'Aceptar', cancelar = 'Cancelar', peligro = false, icono, tipo = 'pregunta', soloAceptar = false }) {
    // Ya hay un diálogo abierto (p. ej. doble clic): la segunda petición se descarta
    if (this.abierto) return Promise.resolve(soloAceptar ? undefined : false);
    this.abierto = true;
    return new Promise(resolver => {
      const el = this.modal || (this.modal = this.crear());
      const iconos = { pregunta: 'fa-circle-question', peligro: 'fa-triangle-exclamation', aviso: 'fa-circle-info', error: 'fa-circle-xmark', exito: 'fa-circle-check' };
      const t = peligro ? 'peligro' : tipo;
      el.dataset.tipo = t;
      el.querySelector('.dialogo-icono i').className = 'fas ' + (icono || iconos[t] || iconos.pregunta);
      el.querySelector('.dialogo-titulo').textContent = titulo || (soloAceptar ? 'Aviso' : 'Confirmar');
      // Texto plano (nunca HTML): los saltos de línea se respetan con CSS
      el.querySelector('.dialogo-mensaje').textContent = mensaje;
      const btnOk = el.querySelector('.dialogo-aceptar'), btnNo = el.querySelector('.dialogo-cancelar');
      btnOk.textContent = aceptar;
      btnOk.className = 'btn dialogo-aceptar ' + (peligro ? 'btn-danger' : 'btn-primary');
      btnNo.textContent = cancelar;
      btnNo.style.display = soloAceptar ? 'none' : '';
      let resultado = false;
      const terminar = valor => { resultado = valor; bs.hide(); };
      const bs = bootstrap.Modal.getOrCreateInstance(el, { backdrop: soloAceptar ? true : 'static', keyboard: true });
      btnOk.onclick = () => terminar(true);
      btnNo.onclick = () => terminar(false);
      el.onkeydown = e => { if (e.key === 'Enter' && e.target.tagName !== 'BUTTON') { e.preventDefault(); terminar(true); } };
      el.addEventListener('shown.bs.modal', () => btnOk.focus(), { once: true });
      el.addEventListener('hidden.bs.modal', () => { this.abierto = false; resolver(soloAceptar ? undefined : resultado); }, { once: true });
      bs.show();
    });
  },
};
/** Pide confirmación con el diseño del sistema. Promesa → true (aceptó) / false. */
function confirmar(mensaje, opciones = {}) { return Dialogo.abrir({ mensaje, ...opciones }); }
/** Aviso con el diseño del sistema (solo botón Aceptar). Promesa que se cumple al cerrarlo. */
function alerta(mensaje, opciones = {}) { return Dialogo.abrir({ mensaje, tipo: 'aviso', aceptar: 'Entendido', soloAceptar: true, ...opciones }); }

function confirmAction(msg, callback) {
  confirmar(msg).then(ok => { if (ok) callback(); });
}

// ── Table row actions (generic) ───────────────────────────────────
function setupTableActions(tableId, callbacks = {}) {
  document.getElementById(tableId)?.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const id     = btn.dataset.id;
    if (callbacks[action]) callbacks[action](id, btn);
  });
}

// ── Dynamic form rows (for detail tables) ─────────────────────────
function addDetailRow(containerId, templateFn, counter) {
  const c = document.getElementById(containerId);
  const idx = c.querySelectorAll('.detail-row').length;
  const row = document.createElement('tr');
  row.className = 'detail-row';
  row.innerHTML = templateFn(idx);
  c.appendChild(row);
  updateDetailRowNumbers(containerId);
  return row;
}

function removeDetailRow(btn) {
  btn.closest('.detail-row')?.remove();
  recalcTotals();
}

function updateDetailRowNumbers(containerId) {
  document.querySelectorAll(`#${containerId} .detail-row`).forEach((r, i) => {
    const num = r.querySelector('.row-num');
    if (num) num.textContent = i + 1;
  });
}

// ── Recalc totals in forms ─────────────────────────────────────────
function recalcTotals() {
  let total = 0;
  document.querySelectorAll('.detail-row .row-monto').forEach(el => {
    total += parseFloat(el.value || 0);
  });
  document.querySelectorAll('.detail-row .row-total').forEach(el => {
    const qty   = parseFloat(el.closest('.detail-row').querySelector('.row-qty')?.value || 1);
    const price = parseFloat(el.closest('.detail-row').querySelector('.row-price')?.value || 0);
    el.value = (qty * price).toFixed(2);
  });
  // Sum all visible totals
  total = 0;
  document.querySelectorAll('.detail-row .row-total, .detail-row .row-monto').forEach(el => {
    total += parseFloat(el.value || 0);
  });
  const totalEl = document.getElementById('grand-total');
  if (totalEl) totalEl.textContent = 'L. ' + total.toLocaleString('es-HN', { minimumFractionDigits: 2 });

  const totalInput = document.getElementById('monto_total');
  if (totalInput) totalInput.value = total.toFixed(2);
}

// ── Qty × price auto-calc ─────────────────────────────────────────
document.addEventListener('input', (e) => {
  if (e.target.matches('.row-qty, .row-price')) {
    const row   = e.target.closest('.detail-row');
    const qty   = parseFloat(row.querySelector('.row-qty')?.value || 1);
    const price = parseFloat(row.querySelector('.row-price')?.value || 0);
    const tot   = row.querySelector('.row-total');
    if (tot) tot.value = (qty * price).toFixed(2);
    recalcTotals();
  }
  if (e.target.matches('.row-monto')) recalcTotals();
});

// ── Form submit with AJAX ──────────────────────────────────────────
function bindAjaxForm(formId, onSuccess) {
  const form = document.getElementById(formId);
  if (!form) return;
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    const orig = btn?.innerHTML;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...'; }
    try {
      const fd  = new FormData(form);
      const res = await post(form.action || window.location.href, fd);
      if (res.success) {
        Toast.show(res.message || 'Guardado exitosamente.', 'success');
        if (onSuccess) onSuccess(res);
      } else {
        Toast.show(res.message || 'Error al guardar.', 'error');
      }
    } catch (err) {
      Toast.show(err.message || 'Error de comunicación con el servidor.', 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = orig; }
    }
  });
}

// ── Status change action ───────────────────────────────────────────
// Flujo de aprobación (includes/flujo_aprobacion.php): revisar, autorizar o rechazar un proceso
async function flujoAccion(url, id, accion, estado, confirmacion) {
  if (confirmacion && !await confirmar(confirmacion, { aceptar: accion === 'revisar' ? 'Revisar' : 'Autorizar' })) return;
  const fd = new FormData();
  fd.append('_action', accion);
  fd.append('id', id);
  if (estado) fd.append('estado', estado);
  fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
  try {
    const r = await post(url, fd);
    Toast.show(r.message || 'Listo.', 'success');
    setTimeout(() => Nav.recargar(), 800);
  } catch (e) { Toast.show(e.message, 'error'); }
}

// Tipos de archivo que aceptan los adjuntos (igual que ADJUNTOS_ACCEPT en config/adjuntos.php)
const ADJUNTOS_ACCEPT = 'image/*,application/pdf,.pdf,.xml,.doc,.docx,.xls,.xlsx,.heic,.heif';

// Rechazar exige el motivo (config/aprobaciones.php) y admite evidencia; queda en el historial del proceso
function flujoRechazar(url, id, estado) {
  let modal = document.getElementById('modal-rechazo');
  if (!modal) {
    modal = document.createElement('div');
    modal.id = 'modal-rechazo';
    modal.className = 'modal fade';
    modal.tabIndex = -1;
    modal.innerHTML = `
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title"><i class="fas fa-times-circle text-danger"></i> Rechazar proceso</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <label class="form-label" for="rechazo-motivo">Motivo del rechazo *</label>
            <textarea id="rechazo-motivo" class="form-control" rows="4" maxlength="2000"
              placeholder="Explique por qué se rechaza: lo verá quien hizo la solicitud y todos los que vean el proceso."></textarea>
            <div class="invalid-feedback">Escriba el motivo del rechazo (al menos 5 caracteres).</div>
            <label class="form-label mt-3 mb-1">Evidencia <span class="text-muted fw-normal">(opcional: fotos, PDF, documentos)</span></label>
            <div class="d-flex gap-2 flex-wrap">
              <label class="btn btn-sm btn-outline-primary mb-0"><i class="fas fa-camera"></i> Tomar foto
                <input type="file" accept="image/*" capture="environment" hidden data-camara></label>
              <label class="btn btn-sm btn-outline-secondary mb-0"><i class="fas fa-paperclip"></i> Adjuntar archivos
                <input type="file" accept="${ADJUNTOS_ACCEPT}" multiple hidden></label>
            </div>
            <ul class="rechazo-evidencias list-unstyled mb-0 mt-2"></ul>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="button" class="btn btn-danger" id="rechazo-confirmar"><i class="fas fa-times"></i> Rechazar</button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(modal);
    modal.addEventListener('shown.bs.modal', () => modal.querySelector('#rechazo-motivo').focus());
    // Evidencia elegida: se lista y se puede quitar antes de enviar
    modal.evidencias = [];
    const lista = modal.querySelector('.rechazo-evidencias');
    modal.pintarEvidencias = () => {
      lista.innerHTML = '';
      modal.evidencias.forEach((ev, i) => {
        const li = document.createElement('li');
        li.className = 'd-flex align-items-center gap-2 small';
        li.innerHTML = '<i class="fas fa-file text-muted"></i><span class="text-truncate flex-grow-1"></span>'
          + '<button type="button" class="btn btn-sm btn-link text-danger p-0" title="Quitar"><i class="fas fa-xmark"></i></button>';
        li.querySelector('span').textContent = ev.file.name;
        li.querySelector('button').onclick = () => { modal.evidencias.splice(i, 1); modal.pintarEvidencias(); };
        lista.appendChild(li);
      });
    };
    modal.querySelectorAll('input[type=file]').forEach(inp => inp.addEventListener('change', () => {
      [...inp.files].forEach(file => modal.evidencias.push({ file, camara: inp.hasAttribute('data-camara') }));
      inp.value = '';
      modal.pintarEvidencias();
    }));
  }
  const txt = modal.querySelector('#rechazo-motivo');
  const btn = modal.querySelector('#rechazo-confirmar');
  txt.value = '';
  txt.classList.remove('is-invalid');
  modal.evidencias = [];
  modal.pintarEvidencias();
  const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
  btn.onclick = async () => {
    const motivo = txt.value.trim();
    if (motivo.length < 5) { txt.classList.add('is-invalid'); txt.focus(); return; }
    btn.disabled = true;
    const fd = new FormData();
    fd.append('_action', 'cambiar_estado');
    fd.append('id', id);
    fd.append('estado', estado);
    fd.append('comentario_rechazo', motivo);
    fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
    for (const ev of modal.evidencias) fd.append('evidencia[]', await Adjuntos.comprimir(ev.file, ev.camara));
    try {
      const r = await post(url, fd);
      bsModal.hide();
      Toast.show(r.message || 'Proceso rechazado.', 'success');
      setTimeout(() => Nav.recargar(), 800);
    } catch (e) { Toast.show(e.message, 'error'); }
    finally { btn.disabled = false; }
  };
  bsModal.show();
}

// ── Documentos adjuntos (includes/adjuntos.php → adjuntos.php) ─────
// Las fotos grandes (cámara del celular) se reducen antes de subirlas: una factura sigue
// legible a 2400 px y pesa una fracción del original.
const Adjuntos = {
  LADO_MAX: 2400,
  async comprimir(file, deCamara) {
    const nombreFoto = () => {
      const d = new Date(), p = n => String(n).padStart(2, '0');
      return `Foto ${p(d.getDate())}-${p(d.getMonth() + 1)}-${d.getFullYear()} ${p(d.getHours())}.${p(d.getMinutes())}.${p(d.getSeconds())}.jpg`;
    };
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || (file.size < 1.5 * 1048576 && !deCamara)) return file;
    try {
      const img = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const k = Math.min(1, this.LADO_MAX / Math.max(img.width, img.height));
      const c = document.createElement('canvas');
      c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
      const ctx = c.getContext('2d');
      ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);   // PNG con transparencia
      ctx.drawImage(img, 0, 0, c.width, c.height);
      const blob = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.85));
      if (!blob || (blob.size >= file.size && !deCamara)) return file;
      const nombre = deCamara ? nombreFoto() : file.name.replace(/\.\w+$/, '') + '.jpg';
      return new File([blob], nombre, { type: 'image/jpeg' });
    } catch (_) { return file; }   // navegador sin soporte: se sube el original
  },
  pintar(panel, r) {
    panel.querySelector('.adj-lista').innerHTML = r.html;
    panel.querySelector('.adj-total').textContent = r.total;
  },
  // Sube archivos ([{file, camara}]) a un proceso: uno por envío, así el límite del servidor
  // aplica a cada uno y no a la suma. alSubir(r) recibe cada respuesta (lista actualizada).
  async subirA(modulo, id, archivos, maxMb, alSubir) {
    const subidos = [], errores = [];
    for (const { file: original, camara } of archivos) {
      const f = await this.comprimir(original, camara);
      if (f.size > maxMb * 1048576) { errores.push(`${original.name}: supera ${maxMb} MB`); continue; }
      const fd = new FormData();
      fd.append('_action', 'subir');
      fd.append('modulo', modulo);
      fd.append('registro_id', id);
      fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
      fd.append('archivos[]', f);
      try {
        const r = await post('adjuntos.php', fd);
        alSubir?.(r);
        if (r.parcial) errores.push(r.message.replace(/^.*No se adjuntaron: /, '').replace(/\.$/, ''));
        else subidos.push(f.name);
      } catch (e) { errores.push(e.message === 'HTTP 413' ? `${f.name}: demasiado grande para el servidor` : e.message.replace(/\.$/, '')); }
    }
    return { subidos, errores };
  },
  avisar({ subidos, errores }) {
    if (subidos.length) Toast.show(subidos.length === 1 ? 'Documento adjuntado.' : `${subidos.length} documentos adjuntados.`, 'success');
    if (errores.length) Toast.show('No se adjuntó: ' + errores.join('; ') + '.', 'error', 8000);
  },
  async enviar(panel, files, deCamara = false) {
    if (!files.length) return;
    const botones = panel.querySelectorAll('.card-header label');
    panel.classList.add('subiendo');
    botones.forEach(b => b.classList.add('disabled'));
    let res;
    try {
      res = await this.subirA(panel.dataset.modulo, panel.dataset.id, files.map(file => ({ file, camara: deCamara })),
        parseFloat(panel.dataset.maxMb) || 10, r => this.pintar(panel, r));
    } finally {
      panel.classList.remove('subiendo');
      botones.forEach(b => b.classList.remove('disabled'));
    }
    this.avisar(res);
  },
  subir(input) {
    const panel = input.closest('.adjuntos-panel');
    this.enviar(panel, [...input.files], input.hasAttribute('capture'));
    input.value = '';
  },
  async quitar(btn, id) {
    if (!await confirmar('El archivo se borrará del proceso.', { titulo: '¿Quitar este documento adjunto?', aceptar: 'Quitar', peligro: true })) return;
    const panel = btn.closest('.adjuntos-panel');
    const fd = new FormData();
    fd.append('_action', 'quitar');
    fd.append('id', id);
    fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
    try {
      const r = await post('adjuntos.php', fd);
      this.pintar(panel, r);
      Toast.show(r.message, 'success');
    } catch (e) { Toast.show(e.message, 'error'); }
  },
};
// ── Adjuntos al llenar un formulario (includes/adjuntos_form.php) ──
// Los archivos quedan en el navegador hasta guardar. Cada módulo envía su formulario con
// post(); al detectar que el envío viene de un formulario con adjuntos pendientes y que se
// guardó bien, post() los sube al proceso (id de la respuesta o del formulario) antes de
// devolver la respuesta, así el módulo navega cuando ya están arriba.
const AdjuntosForm = {
  archivos: new WeakMap(),          // bloque .adjuntos-form → [{file, camara}]
  envio: null,                      // formulario que se está enviando: {form, bloque, accion, t}
  lista(bloque) { if (!this.archivos.has(bloque)) this.archivos.set(bloque, []); return this.archivos.get(bloque); },
  agregar(input) {
    this.sumar(input.closest('.adjuntos-form'), [...input.files], input.hasAttribute('capture'));
    input.value = '';
  },
  sumar(bloque, files, camara = false) {
    const maxMb = parseFloat(bloque.dataset.maxMb) || 10;
    const grandes = [];
    files.forEach(file => {
      // Las fotos se reducen al subirlas: solo se descarta lo que ni así cabría
      if (file.size > maxMb * 1048576 && !/^image\/(jpeg|png|webp)$/.test(file.type)) grandes.push(file.name);
      else this.lista(bloque).push({ file, camara });
    });
    if (grandes.length) Toast.show(`Supera ${maxMb} MB y no se adjuntará: ${grandes.join(', ')}`, 'error', 7000);
    this.pintar(bloque);
  },
  pintar(bloque) {
    const ul = bloque.querySelector('.adjf-lista');
    ul.innerHTML = '';
    this.lista(bloque).forEach(({ file }, i) => {
      const li = document.createElement('li');
      li.className = 'adjf-item';
      const icono = /^image\//.test(file.type) ? 'fa-file-image text-info' : /pdf/.test(file.type) ? 'fa-file-pdf text-danger' : 'fa-file text-muted';
      li.innerHTML = `<i class="fas ${icono}"></i><span class="text-truncate flex-grow-1"></span>`
        + `<small class="text-muted">${file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(file.size / 1024)) + ' KB'}</small>`
        + '<button type="button" class="btn btn-sm btn-link text-danger p-0" title="Quitar"><i class="fas fa-xmark"></i></button>';
      li.querySelector('span').textContent = file.name;
      li.querySelector('button').onclick = () => { this.lista(bloque).splice(i, 1); this.pintar(bloque); };
      ul.appendChild(li);
    });
  },
  // Llamado por ajax() tras un POST correcto: ¿viene del formulario con adjuntos pendientes?
  async alGuardar(data, res) {
    const e = this.envio;
    if (!e || !(data instanceof FormData) || Date.now() - e.t > 5000) return;
    if (e.accion !== null && data.get('_action') !== e.accion) return;
    this.envio = null;
    const pendientes = this.lista(e.bloque);
    const id = parseInt(res.id || e.form.querySelector('[name=id]')?.value || 0, 10);
    if (!pendientes.length || !id) return;
    Toast.show(`Guardado. Subiendo ${pendientes.length === 1 ? 'el documento adjunto' : pendientes.length + ' documentos adjuntos'}…`, 'info', 3000);
    const r = await Adjuntos.subirA(e.bloque.dataset.adjuntosForm, id, pendientes.splice(0), parseFloat(e.bloque.dataset.maxMb) || 10);
    this.pintar(e.bloque);
    Adjuntos.avisar(r);
  },
};
// Anota el formulario que se va a enviar. Los formularios con evento submit se anotan solos (antes
// que el código del módulo); los de un modal que se guardan con un botón llaman a anotar(form).
AdjuntosForm.anotar = function (form) {
  const bloque = form?.querySelector?.('.adjuntos-form');
  if (bloque) this.envio = { form, bloque, accion: form.querySelector('[name=_action]')?.value ?? null, t: Date.now() };
};
document.addEventListener('submit', e => AdjuntosForm.anotar(e.target), true);
// Al cerrar un modal (cancelar, o abrirlo luego para otro registro) se descartan sus pendientes
document.addEventListener('hidden.bs.modal', e => e.target.querySelectorAll?.('.adjuntos-form').forEach(b => {
  AdjuntosForm.lista(b).length = 0;
  AdjuntosForm.pintar(b);
}));

// Arrastrar y soltar archivos sobre el panel o el bloque del formulario (escritorio)
['dragenter', 'dragover'].forEach(ev => document.addEventListener(ev, e => {
  const p = e.target.closest?.('.adjuntos-panel, .adjuntos-form');
  if (p && e.dataTransfer?.types.includes('Files')) { e.preventDefault(); p.classList.add('arrastrando'); }
}));
['dragleave', 'drop'].forEach(ev => document.addEventListener(ev, e => {
  const p = e.target.closest?.('.adjuntos-panel, .adjuntos-form');
  if (!p) return;
  if (ev === 'dragleave' && p.contains(e.relatedTarget)) return;
  p.classList.remove('arrastrando');
  if (ev !== 'drop' || !e.dataTransfer?.files.length) return;
  e.preventDefault();
  if (p.matches('.adjuntos-form')) AdjuntosForm.sumar(p, [...e.dataTransfer.files]);
  else Adjuntos.enviar(p, [...e.dataTransfer.files]);
}));

async function cambiarEstado(url, id, estado, tabla, onDone) {
  const token = document.querySelector('meta[name="csrf"]')?.content || '';
  const fd = new FormData();
  fd.append('_action', 'cambiar_estado');
  fd.append('id', id);
  fd.append('estado', estado);
  fd.append('csrf_token', token);
  try {
    const res = await post(url, fd);
    if (res.success) {
      Toast.show(res.message || 'Estado actualizado.', 'success');
      if (onDone) onDone(res);
    } else {
      Toast.show(res.message || 'No se pudo cambiar el estado.', 'error');
    }
  } catch {
    Toast.show('Error de conexión.', 'error');
  }
}

// ── Print document ────────────────────────────────────────────────
function printDoc(selector = '#print-area') {
  const content = document.querySelector(selector)?.innerHTML;
  if (!content) return window.print();
  const w = window.open('', '_blank');
  w.document.write(`<!DOCTYPE html><html><head>
    <title>Proscala - Documento</title>
    <link rel="stylesheet" href="/Administracion/assets/css/proscala.css">
    <style>body{padding:20px} @media print{.no-print{display:none}}</style>
  </head><body>${content}</body></html>`);
  w.document.close();
  setTimeout(() => { w.print(); }, 500);
}

// ── Date helpers ──────────────────────────────────────────────────
function today() { return new Date().toISOString().split('T')[0]; }

// ── Number formatting ─────────────────────────────────────────────
function lps(n) {
  return 'L. ' + parseFloat(n || 0).toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── DataTable default init ────────────────────────────────────────
// Las listas vacías traen una fila "No hay…" con colspan: DataTables no la acepta (otro número de
// columnas) y avisaba con un alert() del navegador. Se quita esa fila y su texto pasa a ser el
// mensaje de tabla vacía. Cualquier otro aviso de DataTables va a la consola, nunca a un alert().
function initDataTable(selector, opts = {}) {
  if (typeof $ === 'undefined' || !$.fn.DataTable) return;
  $.fn.dataTable.ext.errMode = (s, tn, msg) => console.warn('DataTables:', msg);
  const $tabla = $(selector);
  const $vacia = $tabla.find('tbody > tr').filter((_, tr) => tr.cells.length === 1 && tr.cells[0].colSpan > 1);
  const textoVacio = $vacia.first().text().trim();
  $vacia.remove();
  const idioma = Object.assign({}, window.DATATABLES_ES || {}, textoVacio ? { emptyTable: textoVacio } : {});   // assets/vendor/datatables/es-MX.js
  const dt = $tabla.DataTable(Object.assign({
    language: idioma,
    pageLength: 20,
    responsive: true,
    dom: '<"d-flex justify-content-between align-items-center mb-2"lf>rt<"d-flex justify-content-between align-items-center mt-2"ip>',
  }, opts));
  // ?buscar=texto (desde la búsqueda global): la lista llega filtrada
  const buscar = new URLSearchParams(location.search).get('buscar');
  if (buscar) dt.search(buscar).draw();
  Excel.botonLista(dt);
  return dt;
}

// ── Exportar a Excel (exportar_excel.php → .xlsx) ──────────────────
// Listas (DataTables): las filas que pasan el filtro de búsqueda, de todas las páginas. Otras
// tablas: <table data-exportar="Nombre de la hoja"> recibe su botón en el encabezado de la tarjeta.
// Se omiten las columnas sin título (botones de acción).
const Excel = {
  titulo() { return (document.querySelector('#main-content .page-header h1')?.textContent || document.title).trim(); },
  texto(el) { return (el?.innerText ?? el?.textContent ?? '').replace(/\s+/g, ' ').trim(); },
  async descargar(titulo, columnas, filas) {
    if (!filas.length) { Toast.show('No hay filas para exportar.', 'warning'); return; }
    const fd = new FormData();
    fd.append('csrf_token', document.querySelector('meta[name="csrf"]')?.content || '');
    fd.append('datos', JSON.stringify({ titulo, columnas, filas }));
    try {
      const r = await fetch('exportar_excel.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      if (!r.ok) { let m = ''; try { m = (await r.json()).message; } catch (_) {} throw new Error(m || 'No se pudo generar el archivo.'); }
      const nombre = (r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/)?.[1] || 'Proscala.xlsx';
      const url = URL.createObjectURL(await r.blob());
      const a = Object.assign(document.createElement('a'), { href: url, download: nombre });
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 2000);
      Toast.show(`Exportadas ${filas.length} fila${filas.length === 1 ? '' : 's'} a Excel.`, 'success');
    } catch (e) { Toast.show(e.message, 'error'); }
  },
  boton(onclick) {
    const b = Object.assign(document.createElement('button'), { type: 'button', className: 'btn btn-sm btn-outline-success py-0 px-2 btn-excel', title: 'Exportar a Excel' });
    b.innerHTML = '<i class="fas fa-file-excel"></i> Excel';
    b.addEventListener('click', onclick);
    return b;
  },
  botonLista(dt) {
    const filtro = dt.table().container().querySelector('.dataTables_filter');
    if (!filtro || filtro.querySelector('.btn-excel')) return;
    filtro.classList.add('d-flex', 'align-items-center', 'gap-2');
    filtro.prepend(this.boton(() => {
      const ths = [...dt.table().header().querySelectorAll('th')];
      const cols = ths.map((th, i) => [i, this.texto(th)]).filter(([, t]) => t !== '');
      const filas = [...dt.rows({ search: 'applied' }).nodes()].map(tr => cols.map(([i]) => this.texto(tr.cells[i])));
      this.descargar(this.titulo(), cols.map(([, t]) => t), filas);
    }));
  },
  botonesTablas(root = document) {
    root.querySelectorAll('table[data-exportar]').forEach(tabla => {
      const cab = tabla.closest('.card')?.querySelector('.card-header');
      if (!cab || cab.querySelector('.btn-excel')) return;
      cab.classList.add('d-flex', 'align-items-center', 'gap-2');
      const b = this.boton(() => {
        const ths = [...tabla.querySelectorAll('thead th')];
        const cols = ths.map((th, i) => [i, this.texto(th)]).filter(([, t]) => t !== '');
        const filas = [...tabla.querySelectorAll('tbody tr, tfoot tr')].filter(tr => tr.cells.length >= cols.length)
          .map(tr => cols.map(([i]) => this.texto(tr.cells[i])));
        this.descargar(this.titulo() + ' — ' + tabla.dataset.exportar, cols.map(([, t]) => t), filas);
      });
      b.classList.add('ms-auto');
      cab.appendChild(b);
    });
  },
};
document.addEventListener('DOMContentLoaded', () => Excel.botonesTablas());

// ── Delete with confirmation ──────────────────────────────────────
async function deleteRecord(url, id, onDone) {
  if (!await confirmar('Esta acción no se puede deshacer.', { titulo: '¿Eliminar este registro?', aceptar: 'Eliminar', peligro: true })) return;
  const token = document.querySelector('meta[name="csrf"]')?.content || '';
  try {
    const res = await post(url, { id, _action: 'eliminar', csrf_token: token });
    if (res.success) {
      Toast.show(res.message || 'Registro eliminado.', 'success');
      if (onDone) onDone();
    } else {
      Toast.show(res.message || 'No se pudo eliminar.', 'error');
    }
  } catch { Toast.show('Error de conexión.', 'error'); }
}

// ── Editor de distribución por proyecto (empleados / planillas) ───
// Agrega filas [proyecto + %] a un <tbody>. Totales en celdas [data-tot="campo"]
// y mensaje en [data-dist-msg] dentro de la misma tabla. El primer campo % se
// copia a los demás mientras éstos no se editen a mano.
// Opcional monto: { campo, base: () => L. } agrega una columna de monto que se
// calcula desde el % de `campo` y, si se escribe el monto, recalcula el %.
class DistEditor {
  static labels = { pct_salario: '% Salario', pct_patronal: '% Patronal', porcentaje: '% Beneficio' };

  constructor(tbody, { proyectos, campos, prefix = 'dist', onChange = null, monto = null }) {
    Object.assign(this, { tbody, proyectos, campos, prefix, onChange, monto, n: 0 });
    this.table = tbody.closest('table');
  }

  static pct(v) { return String(+(+v).toFixed(6)); }

  // Recalcula los montos desde los % (p. ej. al cambiar el sueldo)
  refreshMontos() {
    if (!this.monto) return;
    [...this.tbody.rows].forEach(tr => {
      const p = tr.querySelector(`input[data-campo="${this.monto.campo}"]`);
      tr.querySelector('input[data-monto]').value = (this.monto.base() * (parseFloat(p.value) || 0) / 100).toFixed(2);
    });
    this.update();
  }

  set(rows) {
    this.tbody.innerHTML = '';
    (rows || []).forEach(r => this.add(r, true));
    this.update();
  }

  add(r = {}, silent = false) {
    if (!silent && r.proyecto_id === undefined) {
      const t = this.totals();
      this.campos.forEach(c => r[c] = Math.max(0, +(100 - t[c]).toFixed(6)));
    }
    const i = this.n++, tr = document.createElement('tr');
    const sel = document.createElement('select');
    sel.className = 'form-select form-select-sm no-search';
    sel.name = `${this.prefix}[${i}][proyecto_id]`;
    sel.add(new Option('— Proyecto —', ''));
    this.proyectos.forEach(p => {
      const activo = p.estado === 'activo';
      if (!activo && String(p.id) !== String(r.proyecto_id)) return;
      sel.add(new Option(`${p.codigo} — ${p.nombre}${activo ? '' : ' (' + p.estado + ')'}`, p.id));
    });
    sel.value = r.proyecto_id ?? '';
    sel.addEventListener('change', () => this.update());
    tr.insertCell().appendChild(sel);

    const inputs = this.campos.map((c, k) => {
      const inp = document.createElement('input');
      Object.assign(inp, { type: 'number', step: 'any', min: '0', max: '100',
        name: `${this.prefix}[${i}][${c}]`, className: 'form-control form-control-sm text-end font-mono' });
      inp.dataset.campo = c;
      inp.value = r[c] !== undefined ? DistEditor.pct(r[c]) : '';
      if (k > 0 && r.proyecto_id !== undefined && parseFloat(r[c]) !== parseFloat(r[this.campos[0]])) inp.dataset.manual = '1';
      tr.insertCell().appendChild(inp);
      return inp;
    });

    // Columna de monto (solo informativa; el % es lo que se guarda)
    let montoInp = null;
    const pctMonto = this.monto ? inputs[this.campos.indexOf(this.monto.campo)] : null;
    const syncMonto = () => {
      if (montoInp) montoInp.value = (this.monto.base() * (parseFloat(pctMonto.value) || 0) / 100).toFixed(2);
    };
    if (this.monto) {
      montoInp = document.createElement('input');
      Object.assign(montoInp, { type: 'number', step: '0.01', min: '0',
        className: 'form-control form-control-sm text-end font-mono' });
      montoInp.dataset.monto = '1';
      tr.insertCell().appendChild(montoInp);
      syncMonto();
    }

    const propagar = () => inputs.slice(1).forEach(o => { if (!o.dataset.manual) o.value = inputs[0].value; });
    inputs.forEach((inp, k) => inp.addEventListener('input', () => {
      if (k === 0) propagar(); else inp.dataset.manual = '1';
      syncMonto();
      this.update();
    }));
    if (montoInp) montoInp.addEventListener('input', () => {
      const base = this.monto.base();
      if (base <= 0) { Toast.show('Ingrese primero el sueldo mensual.', 'error'); return; }
      pctMonto.value = DistEditor.pct((parseFloat(montoInp.value) || 0) / base * 100);
      if (pctMonto === inputs[0]) propagar();
      this.update();
    });

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'btn btn-sm btn-outline-danger py-0 px-2';
    del.title = 'Quitar proyecto';
    del.innerHTML = '<i class="fas fa-times"></i>';
    del.addEventListener('click', () => { tr.remove(); this.update(); });
    const tdDel = tr.insertCell();
    tdDel.className = 'text-center';
    tdDel.appendChild(del);

    this.tbody.appendChild(tr);
    if (!silent) this.update();
    return tr;
  }

  rows() {
    return [...this.tbody.rows].map(tr => {
      const r = { proyecto_id: tr.querySelector('select').value };
      tr.querySelectorAll('input[data-campo]').forEach(inp => r[inp.dataset.campo] = parseFloat(inp.value) || 0);
      return r;
    }).filter(r => r.proyecto_id);
  }

  totals() {
    const t = Object.fromEntries(this.campos.map(c => [c, 0]));
    this.rows().forEach(r => this.campos.forEach(c => t[c] += r[c]));
    return t;
  }

  error() {
    const rows = this.rows();
    if (!rows.length) return '';
    const ids = rows.map(r => r.proyecto_id);
    if (new Set(ids).size !== ids.length) return 'Un proyecto aparece más de una vez.';
    const t = this.totals();
    for (const c of this.campos) {
      if (Math.abs(t[c] - 100) > 0.001) return `${DistEditor.labels[c] || c} suma ${t[c].toFixed(2)}%; debe sumar 100%.`;
    }
    return '';
  }

  update() {
    const t = this.totals(), hay = this.rows().length > 0;
    this.campos.forEach(c => {
      const cell = this.table.querySelector(`[data-tot="${c}"]`);
      if (!cell) return;
      cell.textContent = t[c].toFixed(2) + '%';
      cell.classList.toggle('text-danger', hay && Math.abs(t[c] - 100) > 0.001);
      cell.classList.toggle('text-success', hay && Math.abs(t[c] - 100) <= 0.001);
    });
    const totMonto = this.table.querySelector('[data-tot="monto"]');
    if (totMonto && this.monto) {
      let suma = 0;
      [...this.tbody.rows].forEach(tr => {
        if (tr.querySelector('select').value) suma += parseFloat(tr.querySelector('input[data-monto]').value) || 0;
      });
      totMonto.textContent = 'L. ' + suma.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      totMonto.title = 'Quincena: L. ' + this.monto.base().toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    const msg = this.table.querySelector('[data-dist-msg]');
    if (msg) {
      const err = this.error();
      msg.textContent = hay ? err : 'Sin proyectos asignados: se reporta 100% como "Sin proyecto".';
      msg.className = err ? 'text-danger' : 'text-muted';
    }
    if (this.onChange) this.onChange(this);
  }
}

// ── Motion: card stagger · count-up · button ripple ──────────────

// Animaciones de entrada del contenido (al cargar y tras cada navegación sin recarga)
function animarContenido(root) {
  // Stagger .card entrance animations via per-card animation-delay
  root.querySelectorAll('.card').forEach((el, i) => {
    el.style.animationDelay = Math.min(i * 0.055, 0.44) + 's';
  });

  // Count-up animation for KPI values (triggered when they enter viewport)
  if (typeof IntersectionObserver !== 'undefined') {
    const cuObs = new IntersectionObserver((entries) => {
      entries.forEach(e => {
        if (e.isIntersecting) { _ahdCountUp(e.target); cuObs.unobserve(e.target); }
      });
    }, { threshold: 0.6 });
    root.querySelectorAll('.kpi-value').forEach(el => cuObs.observe(el));
  }
}

// Ease-out cubic count-up for a single .kpi-value element
function _ahdCountUp(el) {
  const txt  = el.textContent.trim();
  const isLp = txt.includes('L.');
  const raw  = parseFloat(txt.replace(/[^0-9.]/g, ''));
  if (!raw || raw < 2) return;

  const dur = 850, t0 = performance.now();
  (function tick(ts) {
    const p   = Math.min((ts - t0) / dur, 1);
    const val = raw * (1 - Math.pow(1 - p, 3));
    el.textContent = isLp
      ? 'L. ' + val.toLocaleString('es-HN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      : Math.round(val).toLocaleString('es-HN');
    if (p < 1) requestAnimationFrame(tick);
  })(t0);
}

// Ripple effect on button clicks (.btn, .btn-proscala, .btn-proscala-outline)
document.addEventListener('click', e => {
  const btn = e.target.closest('.btn:not(.btn-link), .btn-proscala, .btn-proscala-outline');
  if (!btn) return;
  const rect = btn.getBoundingClientRect();
  const size = Math.max(rect.width, rect.height) * 2.2;
  const spot = document.createElement('span');
  spot.className = 'btn-ripple-el';
  spot.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX - rect.left}px;top:${e.clientY - rect.top}px`;
  if (getComputedStyle(btn).position === 'static') btn.style.position = 'relative';
  btn.style.overflow = 'hidden';
  btn.appendChild(spot);
  setTimeout(() => spot.remove(), 750);
}, true);

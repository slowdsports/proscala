-- ============================================================
-- Proscala - Permisos iniciales de cada puesto
-- Punto de partida; el administrador de cada empresa los ajusta en Roles y Permisos.
-- Lo aplica empresas.php al crear una empresa.
-- ============================================================

INSERT IGNORE INTO rol_modulos (rol, modulo) VALUES
-- Junta Directiva: seguimiento general
('junta_directiva', 'dashboard.php'), ('junta_directiva', 'proyectos.php'), ('junta_directiva', 'presupuesto.php'),
('junta_directiva', 'reportes.php'), ('junta_directiva', 'reportes_legales.php'),
('junta_directiva', 'memorandos.php'), ('junta_directiva', 'actas.php'),

-- Dirección Ejecutiva: toda la operación, sin la configuración del sistema
('direccion_ejecutiva', 'dashboard.php'),
('direccion_ejecutiva', 'empleados.php'), ('direccion_ejecutiva', 'planillas.php'), ('direccion_ejecutiva', 'planillas_asignacion.php'), ('direccion_ejecutiva', 'planillas_beneficios.php'),
('direccion_ejecutiva', 'catalogos.php'), ('direccion_ejecutiva', 'gastos_solicitud.php'), ('direccion_ejecutiva', 'gastos_viajes.php'), ('direccion_ejecutiva', 'gastos_viaje_auxiliar.php'),
('direccion_ejecutiva', 'compras_solicitud.php'), ('direccion_ejecutiva', 'compras_cotizaciones.php'), ('direccion_ejecutiva', 'compras_orden.php'), ('direccion_ejecutiva', 'compras_pago.php'), ('direccion_ejecutiva', 'compras_recepcion.php'), ('direccion_ejecutiva', 'proveedores.php'),
('direccion_ejecutiva', 'proyectos.php'), ('direccion_ejecutiva', 'presupuesto.php'), ('direccion_ejecutiva', 'activos.php'),
('direccion_ejecutiva', 'vacaciones.php'), ('direccion_ejecutiva', 'permisos.php'), ('direccion_ejecutiva', 'asistencia.php'), ('direccion_ejecutiva', 'prestamos.php'), ('direccion_ejecutiva', 'constancias.php'), ('direccion_ejecutiva', 'liquidaciones.php'),
('direccion_ejecutiva', 'memorandos.php'), ('direccion_ejecutiva', 'actas.php'),
('direccion_ejecutiva', 'reportes.php'), ('direccion_ejecutiva', 'reportes_legales.php'), ('direccion_ejecutiva', 'caja_chica.php'),

-- Administración: compras, proveedores, activos y apoyo administrativo
('administracion', 'dashboard.php'), ('administracion', 'empleados.php'), ('administracion', 'catalogos.php'),
('administracion', 'compras_solicitud.php'), ('administracion', 'compras_cotizaciones.php'), ('administracion', 'compras_orden.php'), ('administracion', 'compras_recepcion.php'), ('administracion', 'proveedores.php'),
('administracion', 'proyectos.php'), ('administracion', 'activos.php'),
('administracion', 'vacaciones.php'), ('administracion', 'permisos.php'), ('administracion', 'asistencia.php'),
('administracion', 'memorandos.php'), ('administracion', 'actas.php'), ('administracion', 'caja_chica.php'),

-- Contabilidad: gastos, anticipos, compras y proyectos
-- (igual que el antiguo rol "Compras y Contabilidad", que migra a este puesto)
('contabilidad', 'gastos_solicitud.php'), ('contabilidad', 'gastos_viajes.php'), ('contabilidad', 'gastos_viaje_auxiliar.php'),
('contabilidad', 'compras_solicitud.php'), ('contabilidad', 'compras_cotizaciones.php'), ('contabilidad', 'compras_orden.php'), ('contabilidad', 'compras_pago.php'), ('contabilidad', 'compras_recepcion.php'), ('contabilidad', 'proveedores.php'),
('contabilidad', 'proyectos.php'),

-- Finanzas: tesorería, pagos, presupuesto y reportes
('finanzas', 'dashboard.php'),
('finanzas', 'catalogos.php'), ('finanzas', 'gastos_solicitud.php'), ('finanzas', 'gastos_viajes.php'), ('finanzas', 'gastos_viaje_auxiliar.php'),
('finanzas', 'compras_pago.php'), ('finanzas', 'proveedores.php'),
('finanzas', 'proyectos.php'), ('finanzas', 'presupuesto.php'),
('finanzas', 'planillas.php'), ('finanzas', 'prestamos.php'), ('finanzas', 'caja_chica.php'),
('finanzas', 'reportes.php'), ('finanzas', 'reportes_legales.php'),

-- Recursos Humanos: personal y planillas
('recursos_humanos', 'dashboard.php'),
('recursos_humanos', 'empleados.php'), ('recursos_humanos', 'catalogos.php'), ('recursos_humanos', 'planillas.php'), ('recursos_humanos', 'planillas_asignacion.php'), ('recursos_humanos', 'planillas_beneficios.php'),
('recursos_humanos', 'vacaciones.php'), ('recursos_humanos', 'permisos.php'), ('recursos_humanos', 'asistencia.php'), ('recursos_humanos', 'prestamos.php'), ('recursos_humanos', 'constancias.php'), ('recursos_humanos', 'liquidaciones.php'),
('recursos_humanos', 'memorandos.php');

-- Empleado: sin módulos (solo Mi Perfil) hasta que el administrador le asigne alguno
-- (que solo vea sus propias solicitudes lo fija la tabla roles en empresa_seed.sql)

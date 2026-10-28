-- ============================================================
-- Proscala - Datos iniciales de una empresa nueva
-- Los aplica empresas.php después de empresa_schema.sql.
-- nombre_organizacion se llena con el nombre de la empresa al crearla.
-- ============================================================

INSERT INTO configuracion (clave, valor, descripcion) VALUES
('nombre_organizacion', '', 'Nombre de la organización'),
('nombre_completo', '', 'Nombre completo'),
('rtn', '', 'RTN de la organización'),
('telefono', '', 'Teléfono'),
('correo', '', 'Correo electrónico'),
('direccion', '', 'Dirección'),
('logo', '', 'Logo de la empresa'),
('moneda', 'HNL', 'Moneda principal'),
('ihss_empleado', '2.5', 'Porcentaje IHSS empleado'),
('ihss_patronal', '5.0', 'Porcentaje IHSS patronal'),
('rap_empleado', '1.5', 'Porcentaje RAP empleado'),
('rap_patronal', '1.5', 'Porcentaje RAP patronal'),
('infop', '0', 'Porcentaje INFOP patronal'),
('ihss_techo_mensual', '9357.00', 'Salario techo IHSS mensual'),
('isr_exento_anual', '142771.50', 'Monto exento ISR anual'),
('prefijo_sg', 'SG', 'Prefijo solicitud de gastos'),
('prefijo_gv', 'GV', 'Prefijo gastos de viaje'),
('prefijo_pl', 'PL', 'Prefijo planilla'),
('prefijo_sc', 'SC', 'Prefijo solicitud de compra'),
('prefijo_co', 'CO', 'Prefijo cotizaciones'),
('prefijo_oc', 'OC', 'Prefijo orden de compra'),
('prefijo_op', 'OP', 'Prefijo orden de pago'),
('prefijo_nr', 'NR', 'Prefijo nota de recepción'),
('recordatorio_dias', '2', 'Días que un proceso espera antes de recordar a quien le toca aprobarlo'),
('escalamiento_dias', '5', 'Días que un proceso espera antes de avisar al siguiente nivel del organigrama');

-- Puestos iniciales (se agregan, renombran y ordenan en Roles y Permisos)
INSERT INTO roles (clave, nombre, depende_de, aprueba, solo_propios, orden) VALUES
('admin',               'Administrador',       NULL,                  1, 0, 1),
('junta_directiva',     'Junta Directiva',     NULL,                  1, 0, 2),
('direccion_ejecutiva', 'Dirección Ejecutiva', 'junta_directiva',     1, 0, 3),
('administracion',      'Administración',      'direccion_ejecutiva', 1, 0, 4),
('contabilidad',        'Contabilidad',        'administracion',      1, 0, 5),
('finanzas',            'Finanzas',            'administracion',      1, 0, 6),
('recursos_humanos',    'Recursos Humanos',    'administracion',      0, 0, 7),
('empleado',            'Empleado',            'administracion',      0, 1, 8);

INSERT INTO cat_bancos (nombre) VALUES
('BAC'),('Atlántida'),('FICOHSA'),('Davivienda'),('Banrural'),('Occidente');

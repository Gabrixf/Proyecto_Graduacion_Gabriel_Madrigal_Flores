-- ============================================================
-- Datos de prueba para desarrollo local
-- Importar DESPUÉS de schema.sql
-- ============================================================

USE `lubrimotos_nomina`;

-- ── Usuarios ──────────────────────────────────────────────
-- Contraseña para ambos: "password123" (bcrypt, costo 12)
-- Generar con: password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12])
INSERT INTO `usuarios` (`nombre_usuario`, `contrasena_hash`, `rol`, `activo`) VALUES
('admin',     '$2y$12$8RkFExampleHashAdminXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX', 'admin',    1),
('jperez',    '$2y$12$8RkFExampleHashEmpleadoXXXXXXXXXXXXXXXXXXXXXXXXXX', 'empleado', 1);

-- NOTA: Reemplazar los hashes de ejemplo con hashes reales antes de usar.
-- En PHP: echo password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);

-- ── Puestos ───────────────────────────────────────────────
INSERT INTO `puestos` (`nombre`, `salario_base`, `descripcion`) VALUES
('Mecánico General',   450000.00, 'Realiza mantenimiento y reparación de motocicletas.'),
('Mecánico Especialista', 600000.00, 'Diagnóstico y reparación de sistemas electrónicos.'),
('Vendedor',           380000.00, 'Atención al cliente y venta de repuestos.'),
('Administrador',      750000.00, 'Gestión general de la empresa.'),
('Recepcionista',      350000.00, 'Atención en mostrador y manejo de caja.');

-- ── Empleados ─────────────────────────────────────────────
INSERT INTO `empleados` (
    `id_puesto`, `id_usuario`, `nombre`, `apellidos`, `cedula`,
    `fecha_nacimiento`, `genero`, `estado_civil`, `nacionalidad`,
    `telefono`, `correo`, `fecha_ingreso`, `estado`
) VALUES
(4, 1, 'Emerson',  'Fernández Maroto',  '1-0234-5678', '1980-05-15', 'masculino', 'casado',   'Costarricense', '8888-1111', 'admin@lubrimotos.cr',  '2015-01-10', 'activo'),
(1, 2, 'Juan',     'Pérez Solís',       '1-0345-6789', '1995-08-22', 'masculino', 'soltero',  'Costarricense', '7777-2222', 'jperez@lubrimotos.cr', '2020-03-01', 'activo'),
(1, NULL,'María',  'González Mora',     '1-0456-7890', '1998-12-01', 'femenino',  'soltera',  'Costarricense', '6666-3333', NULL,                   '2021-06-15', 'activo');

-- ── Feriados 2026 (Costa Rica) ────────────────────────────
INSERT INTO `feriados` (`fecha`, `nombre`, `tipo`) VALUES
('2026-01-01', 'Año Nuevo',                              'obligatorio_pago'),
('2026-04-09', 'Jueves Santo',                           'obligatorio_pago'),
('2026-04-10', 'Viernes Santo',                          'obligatorio_pago'),
('2026-04-11', 'Día de Juan Santamaría',                 'obligatorio_pago'),
('2026-05-01', 'Día Internacional del Trabajador',       'obligatorio_pago'),
('2026-07-25', 'Anexión del Partido de Nicoya',          'no_obligatorio_pago'),
('2026-08-02', 'Día de la Virgen de los Ángeles',        'no_obligatorio_pago'),
('2026-08-15', 'Día de la Madre',                        'obligatorio_pago'),
('2026-09-15', 'Día de la Independencia',                'obligatorio_pago'),
('2026-10-12', 'Día de las Culturas',                    'no_obligatorio_pago'),
('2026-12-25', 'Navidad',                                'obligatorio_pago');

-- ── Período de pago de ejemplo ────────────────────────────
INSERT INTO `periodos_pago` (`fecha_inicio`, `fecha_fin`, `estado`) VALUES
('2026-05-01', '2026-05-15', 'abierto'),
('2026-05-16', '2026-05-31', 'abierto');

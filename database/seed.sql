-- ============================================================
-- Datos de prueba para desarrollo local
-- Importar DESPUÉS de schema.sql
-- ============================================================

USE `lubrimotos_nomina`;

-- ── Usuarios ──────────────────────────────────────────────
-- Contraseña para los tres: "password123" (bcrypt, costo 12)
-- Generar con: password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12])
-- id_usuario 1 esta reservado por schema.sql para el usuario centinela "sin_cuenta".
INSERT INTO `usuarios` (`id_usuario`, `nombre_usuario`, `contrasena_hash`, `rol`, `activo`) VALUES
(2, 'superadmin', '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'super_admin', 1),
(3, 'jperez',     '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'empleado',    1),
(4, 'admin',      '$2y$12$9xecBJttUrdlbipjz/MQS.GtTqXQoUskncqsyQh82hmkaMxInPKi.', 'admin',       1);

-- NOTA: Reemplazar los hashes de ejemplo con hashes reales antes de usar.
-- En PHP: echo password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);

-- ── Puestos ───────────────────────────────────────────────
INSERT INTO `puestos` (`nombre`, `salario_base`, `descripcion`) VALUES
('Mecánico General',   450000.00, 'Realiza mantenimiento y reparación de motocicletas.'),
('Mecánico Especialista', 600000.00, 'Diagnóstico y reparación de sistemas electrónicos.'),
('Vendedor',           380000.00, 'Atención al cliente y venta de repuestos.'),
('Administrador',      750000.00, 'Gestión general de la empresa.'),
('Recepcionista',      350000.00, 'Atención en mostrador y manejo de caja.');

-- ── Personas ──────────────────────────────────────────────
-- id_distrito 2 = Carmen (cantón San José) -- Lubrimotos del Sur opera en San José.
INSERT INTO `persona` (
    `id_persona`, `cedula`, `nombre`, `apellidos`, `fecha_nacimiento`,
    `genero`, `estado_civil`, `nacionalidad`, `telefono`, `correo`, `id_distrito`
) VALUES
(1, '1-0234-5678', 'Emerson', 'Fernández Maroto', '1980-05-15', 'masculino', 'casado',  'Costarricense', '8888-1111', 'admin@lubrimotos.cr',  2),
(2, '1-0345-6789', 'Juan',    'Pérez Solís',      '1995-08-22', 'masculino', 'soltero', 'Costarricense', '7777-2222', 'jperez@lubrimotos.cr', 2),
(3, '1-0456-7890', 'María',   'González Mora',    '1998-12-01', 'femenino',  'soltero', 'Costarricense', '6666-3333', '',                     2);

-- ── Empleados ─────────────────────────────────────────────
INSERT INTO `empleados` (
    `id_persona`, `id_puesto`, `id_usuario`, `fecha_ingreso`, `estado`
) VALUES
(1, 4, 2, '2015-01-10', 'activo'),
(2, 1, 3, '2020-03-01', 'activo'),
(3, 1, 1, '2021-06-15', 'activo');

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

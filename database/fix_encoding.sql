-- ============================================================
-- Corrección de encoding UTF-8 corrupto
-- Ejecutar con: mysql -u root lubrimotos_nomina --default-character-set=utf8mb4 < fix_encoding.sql
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ── Puestos ───────────────────────────────────────────────
UPDATE `puestos` SET
    `nombre`      = 'Mecánico General',
    `descripcion` = 'Realiza mantenimiento y reparación de motocicletas.'
WHERE `id_puesto` = 1;

UPDATE `puestos` SET
    `nombre`      = 'Mecánico Especialista',
    `descripcion` = 'Diagnóstico y reparación de sistemas electrónicos.'
WHERE `id_puesto` = 2;

UPDATE `puestos` SET
    `descripcion` = 'Atención al cliente y venta de repuestos.'
WHERE `id_puesto` = 3;

UPDATE `puestos` SET
    `descripcion` = 'Gestión general de la empresa.'
WHERE `id_puesto` = 4;

UPDATE `puestos` SET
    `descripcion` = 'Atención en mostrador y manejo de caja.'
WHERE `id_puesto` = 5;

-- ── Empleados ─────────────────────────────────────────────
UPDATE `empleados` SET
    `apellidos` = 'Fernández Maroto'
WHERE `id_empleado` = 1;

UPDATE `empleados` SET
    `apellidos` = 'Pérez Solís'
WHERE `id_empleado` = 2;

UPDATE `empleados` SET
    `nombre`    = 'María',
    `apellidos` = 'González Mora'
WHERE `id_empleado` = 3;

-- ── Feriados ──────────────────────────────────────────────
UPDATE `feriados` SET `nombre` = 'Año Nuevo'                        WHERE `id_feriado` = 1;
UPDATE `feriados` SET `nombre` = 'Día de Juan Santamaría'           WHERE `id_feriado` = 4;
UPDATE `feriados` SET `nombre` = 'Día Internacional del Trabajador' WHERE `id_feriado` = 5;
UPDATE `feriados` SET `nombre` = 'Anexión del Partido de Nicoya'    WHERE `id_feriado` = 6;
UPDATE `feriados` SET `nombre` = 'Día de la Virgen de los Ángeles'  WHERE `id_feriado` = 7;
UPDATE `feriados` SET `nombre` = 'Día de la Madre'                  WHERE `id_feriado` = 8;
UPDATE `feriados` SET `nombre` = 'Día de la Independencia'          WHERE `id_feriado` = 9;
UPDATE `feriados` SET `nombre` = 'Día de las Culturas'              WHERE `id_feriado` = 10;

SELECT 'Corrección completada.' AS resultado;

-- ============================================================
-- Quita el tipo de ingreso 'comision' de ingresos_nomina
-- (las comisiones quedan fuera del alcance del sistema).
-- Ejecutar con: mysql -u root lubrimotos_nomina --default-character-set=utf8mb4 < quitar_comision.sql
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Las líneas ya registradas como comisión pasan a 'otro' antes de
-- reducir el ENUM; si no, MySQL rechaza el ALTER.
UPDATE `ingresos_nomina`
SET `descripcion` = IF(`descripcion` = '', 'Comisión', `descripcion`),
    `tipo`        = 'otro'
WHERE `tipo` = 'comision';

ALTER TABLE `ingresos_nomina`
    MODIFY `tipo` ENUM('salario_base','horas_extra','bonificacion','feriado','otro') NOT NULL;

-- ============================================================
-- Agrega asistencia.salida_manual (marcar asistencia desde el portal).
-- Ejecutar con: mysql -u root lubrimotos_nomina --default-character-set=utf8mb4 < asistencia_salida_manual.sql
-- ============================================================

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `asistencia`
    ADD COLUMN `salida_manual` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = la salida la escribió el empleado; 0 = la puso el servidor o RRHH'
        AFTER `horas_trabajadas`;

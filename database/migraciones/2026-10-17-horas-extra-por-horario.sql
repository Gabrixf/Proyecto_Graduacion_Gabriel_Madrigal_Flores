-- Migración 2026-10-17: horas extra detectadas por horario.
-- Aplicar sobre una base existente (la que ya tiene schema.sql + seed.sql + demo.php).
-- Cada sección corresponde a una tarea del plan; se agregan en orden.

-- ── Task 1: feriados (Art. 148 vigente) ────────────────────────
UPDATE asistencia a JOIN feriados f ON f.id_feriado = a.id_feriado
   SET a.id_feriado = 1
 WHERE f.fecha IN ('2026-10-12', '2027-10-12', '2026-04-09', '2026-04-10');
DELETE FROM feriados WHERE fecha IN ('2026-10-12', '2027-10-12');
UPDATE feriados SET fecha = '2026-04-02' WHERE fecha = '2026-04-09' AND nombre = 'Jueves Santo';
UPDATE feriados SET fecha = '2026-04-03' WHERE fecha = '2026-04-10' AND nombre = 'Viernes Santo';

-- ── Task 2: horarios con detalle por día ──────────────────────
CREATE TABLE IF NOT EXISTS `horario_dias` (
    `id_horario_dia`   INT      NOT NULL AUTO_INCREMENT,
    `id_horario`       INT      NOT NULL,
    `dia_semana`       TINYINT  NOT NULL COMMENT '1 = lunes ... 7 = domingo (ISO-8601)',
    `hora_entrada`     TIME     NOT NULL,
    `hora_salida`      TIME     NOT NULL,
    `minutos_almuerzo` SMALLINT NOT NULL DEFAULT 0 COMMENT 'Pausa no remunerada; no es jornada',
    PRIMARY KEY (`id_horario_dia`),
    UNIQUE KEY `uq_horario_dia` (`id_horario`, `dia_semana`),
    CONSTRAINT `fk_horario_dias_horario` FOREIGN KEY (`id_horario`) REFERENCES `horarios` (`id_horario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horario de Lubrimotos: L–V 08:00–17:00 (60 min almuerzo) y S 08:00–12:00.
INSERT INTO horarios (nombre, dias_semana)
SELECT 'Taller L-V y sábado medio día', 'L-V + S medio día'
 WHERE NOT EXISTS (SELECT 1 FROM horarios WHERE nombre = 'Taller L-V y sábado medio día');
SET @h := (SELECT id_horario FROM horarios WHERE nombre = 'Taller L-V y sábado medio día');
INSERT IGNORE INTO horario_dias (id_horario, dia_semana, hora_entrada, hora_salida, minutos_almuerzo) VALUES
(@h, 1, '08:00:00', '17:00:00', 60), (@h, 2, '08:00:00', '17:00:00', 60), (@h, 3, '08:00:00', '17:00:00', 60),
(@h, 4, '08:00:00', '17:00:00', 60), (@h, 5, '08:00:00', '17:00:00', 60), (@h, 6, '08:00:00', '12:00:00', 0);
UPDATE empleados SET id_horario = @h;
DELETE FROM horarios WHERE id_horario NOT IN (1, @h) AND id_horario NOT IN (SELECT id_horario FROM empleados);
ALTER TABLE horarios DROP COLUMN hora_entrada, DROP COLUMN hora_salida;

-- ── Task 5: factor de horas extra en feriado/descanso = 3.00 ──
UPDATE parametros_legales SET clave = 'factor_he_especial', valor = 3.0000 WHERE clave = 'factor_he_feri';

-- ── Task 6: solicitudes detectadas por asistencia ─────────────
ALTER TABLE solicitudes
    MODIFY `estado` ENUM('pendiente','aprobada','rechazada','por_justificar') NOT NULL DEFAULT 'pendiente',
    ADD COLUMN `origen` ENUM('empleado','asistencia') NOT NULL DEFAULT 'empleado'
        COMMENT 'asistencia = horas extra detectadas por la salida marcada' AFTER `observacion_admin`;

-- ── Task 11: adicional sencillo por descanso trabajado ────────
ALTER TABLE ingresos_nomina
    MODIFY `tipo` ENUM('salario_base','horas_extra','bonificacion','feriado','descanso_trabajado','otro') NOT NULL;

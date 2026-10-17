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

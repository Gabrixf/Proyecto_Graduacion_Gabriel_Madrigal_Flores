-- Migración 2026-10-18: nómina masiva por período.
-- Cada sección corresponde a una tarea del plan; se agregan en orden.

-- ── Task 1: parámetros legales con vigencia ────────────────────
ALTER TABLE parametros_legales MODIFY `valor` DECIMAL(14,4) NOT NULL;
-- deducciones_nomina.id_parametro tiene FK a esta tabla: desligar antes de borrar las filas viejas.
UPDATE deducciones_nomina SET id_parametro = 1
 WHERE id_parametro IN (SELECT id_parametro FROM (SELECT id_parametro FROM parametros_legales WHERE clave IN ('ccss_obrera', 'ccss_patronal')) AS x);
DELETE FROM parametros_legales WHERE clave IN ('ccss_obrera', 'ccss_patronal');
INSERT INTO parametros_legales (clave, valor, fecha_inicio, fecha_fin) VALUES
('ccss_obrera',   0.1067, '2023-01-01', '2025-12-31'),
('ccss_obrera',   0.1083, '2026-01-01', '9999-12-31'),
('ccss_patronal', 0.2667, '2023-01-01', '2025-12-31'),
('ccss_patronal', 0.2683, '2026-01-01', '9999-12-31'),
('renta_tramo_1', 918000,  '2026-01-01', '2026-12-31'),
('renta_tramo_2', 1347000, '2026-01-01', '2026-12-31'),
('renta_tramo_3', 2364000, '2026-01-01', '2026-12-31'),
('renta_tramo_4', 4727000, '2026-01-01', '2026-12-31'),
('renta_tasa_1', 0.10, '2026-01-01', '2026-12-31'),
('renta_tasa_2', 0.15, '2026-01-01', '2026-12-31'),
('renta_tasa_3', 0.20, '2026-01-01', '2026-12-31'),
('renta_tasa_4', 0.25, '2026-01-01', '2026-12-31'),
('renta_credito_hijo',    1710, '2026-01-01', '2026-12-31'),
('renta_credito_conyuge', 2590, '2026-01-01', '2026-12-31');

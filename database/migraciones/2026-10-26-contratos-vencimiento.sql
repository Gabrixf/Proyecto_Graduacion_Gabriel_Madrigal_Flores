-- Migración 2026-10-26: vencimiento de contratos (Art. 26 y 27 del Código de Trabajo).
-- Plazo fijo: hasta 1 año, o 5 si RRHH marca que requiere preparación técnica.
ALTER TABLE contratos
    MODIFY `fecha_fin` DATE NOT NULL DEFAULT '9999-12-31'
        COMMENT 'Vencimiento (plazo fijo / obra determinada) o cierre por renovación; 9999-12-31 = indefinido',
    ADD COLUMN `requiere_preparacion_tecnica` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = plazo fijo de hasta 5 años en vez de 1 (Art. 26 Código de Trabajo); lo marca RRHH' AFTER `fecha_fin`;

-- Un plazo fijo activo sin vencimiento no existe legalmente: si la persona sigue
-- laborando, el contrato se tiene por indefinido (Art. 26).
UPDATE contratos
   SET tipo_contrato = 'tiempo_indefinido'
 WHERE estado = 'activo'
   AND tipo_contrato IN ('plazo_fijo', 'obra_determinada')
   AND fecha_fin = '9999-12-31';

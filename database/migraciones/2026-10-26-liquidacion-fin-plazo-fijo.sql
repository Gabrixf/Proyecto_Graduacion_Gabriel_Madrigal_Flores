-- Migración 2026-10-26: motivo de liquidación "Vencimiento del plazo fijo" (Art. 26 y 31 del
-- Código de Trabajo). Termina el día del vencimiento: paga vacaciones y aguinaldo, sin preaviso
-- ni cesantía.
ALTER TABLE liquidacion
    MODIFY `motivo` ENUM('renuncia','despido_con_causa','despido_sin_causa','renuncia_justificada','mutuo_acuerdo','jubilacion','muerte','fin_plazo_fijo') NOT NULL;

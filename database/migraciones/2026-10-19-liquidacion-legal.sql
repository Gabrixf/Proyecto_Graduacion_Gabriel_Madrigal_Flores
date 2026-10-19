-- Migración 2026-10-19: liquidación según el Código de Trabajo.
ALTER TABLE liquidacion
    MODIFY `motivo` ENUM('renuncia','despido_con_causa','despido_sin_causa','renuncia_justificada','mutuo_acuerdo','jubilacion','muerte') NOT NULL,
    ADD COLUMN `salario_promedio` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Base Art. 30: promedio mensual de los últimos 6 meses' AFTER `motivo`,
    ADD COLUMN `dias_preaviso`    DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER `salario_promedio`,
    ADD COLUMN `dias_cesantia`    DECIMAL(6,2)  NOT NULL DEFAULT 0.00 AFTER `dias_preaviso`,
    ADD COLUMN `dias_vacaciones`  DECIMAL(5,2)  NOT NULL DEFAULT 0.00 AFTER `dias_cesantia`,
    ADD COLUMN `base_estimada`    TINYINT(1)    NOT NULL DEFAULT 0 COMMENT '1 = sin nóminas en la ventana; se usó el salario del puesto' AFTER `dias_vacaciones`;

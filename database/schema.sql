-- ============================================================
-- Sistema de Gestión de Nómina — Lubrimotos del Sur
-- Esquema de base de datos (21 tablas, normalizado a 3FN)
-- Trabajo Final de Graduación — Gabriel Iván Madrigal Flores
-- Universidad Internacional de las Américas · 2026
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `lubrimotos_nomina`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `lubrimotos_nomina`;

-- ============================================================
-- GRUPO 1: CONFIGURACIÓN Y ACCESO
-- ============================================================

-- 1. puestos
-- Catálogo de puestos de trabajo de la empresa.
CREATE TABLE IF NOT EXISTS `puestos` (
    `id_puesto`    INT            NOT NULL AUTO_INCREMENT,
    `nombre`       VARCHAR(100)   NOT NULL,
    `salario_base` DECIMAL(10,2)  NOT NULL,
    `descripcion`  VARCHAR(255)       NULL DEFAULT NULL,
    PRIMARY KEY (`id_puesto`),
    UNIQUE KEY `uq_puestos_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 2. usuarios
-- Credenciales y roles de acceso al sistema (admin / empleado).
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id_usuario`      INT            NOT NULL AUTO_INCREMENT,
    `nombre_usuario`  VARCHAR(80)    NOT NULL,
    `contrasena_hash` VARCHAR(255)   NOT NULL,
    `rol`             ENUM('admin','empleado') NOT NULL DEFAULT 'empleado',
    `activo`          TINYINT(1)     NOT NULL DEFAULT 1,
    `fecha_creacion`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_usuario`),
    UNIQUE KEY `uq_usuarios_nombre` (`nombre_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- GRUPO 2: EMPLEADOS
-- ============================================================

-- 3. empleados
-- Información laboral y personal del empleado.
-- Los datos bancarios están separados (tabla datos_bancarios) por Ley 8968.
CREATE TABLE IF NOT EXISTS `empleados` (
    `id_empleado`                INT            NOT NULL AUTO_INCREMENT,
    `id_puesto`                  INT            NOT NULL,
    `id_usuario`                 INT                NULL DEFAULT NULL,
    `nombre`                     VARCHAR(100)   NOT NULL,
    `apellidos`                  VARCHAR(100)   NOT NULL,
    `cedula`                     VARCHAR(20)    NOT NULL,
    `fecha_nacimiento`           DATE           NOT NULL,
    `genero`                     ENUM('masculino','femenino','otro') NOT NULL,
    `estado_civil`               ENUM('soltero','casado','union_libre','divorciado','viudo') NOT NULL,
    `nacionalidad`               VARCHAR(50)    NOT NULL,
    `telefono`                   VARCHAR(20)        NULL DEFAULT NULL,
    `correo`                     VARCHAR(150)       NULL DEFAULT NULL,
    `direccion`                  VARCHAR(255)       NULL DEFAULT NULL,
    `provincia`                  VARCHAR(100)       NULL DEFAULT NULL,
    `canton`                     VARCHAR(100)       NULL DEFAULT NULL,
    `distrito`                   VARCHAR(100)       NULL DEFAULT NULL,
    `telefono_emergencia`        VARCHAR(20)        NULL DEFAULT NULL,
    `nombre_contacto_emergencia` VARCHAR(150)       NULL DEFAULT NULL,
    `numero_asegurado_ccss`      VARCHAR(20)        NULL DEFAULT NULL,
    `numero_poliza_ins`          VARCHAR(30)        NULL DEFAULT NULL,
    `fecha_ingreso`              DATE           NOT NULL,
    `fecha_salida`               DATE               NULL DEFAULT NULL,
    `estado`                     ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    PRIMARY KEY (`id_empleado`),
    UNIQUE KEY `uq_empleados_cedula` (`cedula`),
    CONSTRAINT `fk_empleados_puesto`  FOREIGN KEY (`id_puesto`)  REFERENCES `puestos`  (`id_puesto`),
    CONSTRAINT `fk_empleados_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4. datos_bancarios
-- Información bancaria separada por Ley 8968 (Protección de Datos Personales).
CREATE TABLE IF NOT EXISTS `datos_bancarios` (
    `id_datos_bancarios` INT            NOT NULL AUTO_INCREMENT,
    `id_empleado`        INT            NOT NULL,
    `banco`              VARCHAR(100)   NOT NULL,
    `tipo_cuenta`        ENUM('corriente','ahorros') NOT NULL,
    `numero_cuenta`      VARCHAR(30)    NOT NULL,
    `numero_cuenta_iban` VARCHAR(22)        NULL DEFAULT NULL,
    `moneda`             ENUM('CRC','USD')  NOT NULL DEFAULT 'CRC',
    `activa`             TINYINT(1)     NOT NULL DEFAULT 1,
    `fecha_registro`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_datos_bancarios`),
    CONSTRAINT `fk_bancarios_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados` (`id_empleado`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- GRUPO 3: CONFIGURACIÓN DE PERÍODOS Y FERIADOS
-- ============================================================

-- 5. periodos_pago
-- Períodos quincenales de pago.
CREATE TABLE IF NOT EXISTS `periodos_pago` (
    `id_periodo`  INT      NOT NULL AUTO_INCREMENT,
    `fecha_inicio` DATE    NOT NULL,
    `fecha_fin`    DATE    NOT NULL,
    `estado`       ENUM('abierto','cerrado') NOT NULL DEFAULT 'abierto',
    PRIMARY KEY (`id_periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 6. feriados
-- Feriados nacionales de Costa Rica (Código de Trabajo).
CREATE TABLE IF NOT EXISTS `feriados` (
    `id_feriado` INT          NOT NULL AUTO_INCREMENT,
    `fecha`      DATE         NOT NULL,
    `nombre`     VARCHAR(100) NOT NULL,
    `tipo`       ENUM('obligatorio_pago','no_obligatorio_pago') NOT NULL,
    PRIMARY KEY (`id_feriado`),
    UNIQUE KEY `uq_feriados_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- GRUPO 4: SOLICITUDES Y EVENTOS LABORALES
-- ============================================================

-- 7. solicitudes
-- Registro unificado de solicitudes de horas extra, vacaciones y permisos.
CREATE TABLE IF NOT EXISTS `solicitudes` (
    `id_solicitud`       INT            NOT NULL AUTO_INCREMENT,
    `id_empleado`        INT            NOT NULL,
    `tipo`               ENUM('horas_extra','vacaciones','permiso') NOT NULL,
    `fecha_inicio`       DATE           NOT NULL,
    `fecha_fin`          DATE               NULL DEFAULT NULL,
    `horas`              DECIMAL(5,2)       NULL DEFAULT NULL,
    `motivo`             VARCHAR(255)       NULL DEFAULT NULL,
    `estado`             ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
    `fecha_solicitud`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_resolucion`   TIMESTAMP          NULL DEFAULT NULL,
    `id_usuario_resuelve` INT               NULL DEFAULT NULL,
    `observacion_admin`  VARCHAR(255)       NULL DEFAULT NULL,
    PRIMARY KEY (`id_solicitud`),
    CONSTRAINT `fk_solicitudes_empleado`  FOREIGN KEY (`id_empleado`)        REFERENCES `empleados` (`id_empleado`),
    CONSTRAINT `fk_solicitudes_resuelve`  FOREIGN KEY (`id_usuario_resuelve`) REFERENCES `usuarios`  (`id_usuario`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 8. asistencia
-- Registro diario de asistencia por empleado y período.
CREATE TABLE IF NOT EXISTS `asistencia` (
    `id_asistencia`   INT           NOT NULL AUTO_INCREMENT,
    `id_empleado`     INT           NOT NULL,
    `id_periodo`      INT           NOT NULL,
    `id_feriado`      INT               NULL DEFAULT NULL,
    `fecha`           DATE          NOT NULL,
    `hora_entrada`    TIME              NULL DEFAULT NULL,
    `hora_salida`     TIME              NULL DEFAULT NULL,
    `horas_trabajadas` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id_asistencia`),
    UNIQUE KEY `uq_asistencia_empleado_fecha` (`id_empleado`, `fecha`),
    CONSTRAINT `fk_asistencia_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados`    (`id_empleado`),
    CONSTRAINT `fk_asistencia_periodo`  FOREIGN KEY (`id_periodo`)  REFERENCES `periodos_pago` (`id_periodo`),
    CONSTRAINT `fk_asistencia_feriado`  FOREIGN KEY (`id_feriado`)  REFERENCES `feriados`     (`id_feriado`)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 9. horas_extra
-- Registro de horas extra aprobadas.
-- Factor: 1.50 jornada ordinaria / 2.00 feriado (Código de Trabajo CR).
CREATE TABLE IF NOT EXISTS `horas_extra` (
    `id_hora_extra`  INT           NOT NULL AUTO_INCREMENT,
    `id_solicitud`   INT           NOT NULL,
    `id_empleado`    INT           NOT NULL,
    `id_periodo`     INT           NOT NULL,
    `fecha`          DATE          NOT NULL,
    `cantidad_horas` DECIMAL(4,2)  NOT NULL,
    `factor_recargo` DECIMAL(3,2)  NOT NULL DEFAULT 1.50
        COMMENT '1.50 jornada ordinaria / 2.00 feriado',
    PRIMARY KEY (`id_hora_extra`),
    CONSTRAINT `fk_he_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitudes`   (`id_solicitud`),
    CONSTRAINT `fk_he_empleado`  FOREIGN KEY (`id_empleado`)  REFERENCES `empleados`     (`id_empleado`),
    CONSTRAINT `fk_he_periodo`   FOREIGN KEY (`id_periodo`)   REFERENCES `periodos_pago` (`id_periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 10. vacaciones
-- Períodos de vacaciones disfrutadas (después de aprobación).
CREATE TABLE IF NOT EXISTS `vacaciones` (
    `id_vacacion`  INT           NOT NULL AUTO_INCREMENT,
    `id_solicitud` INT           NOT NULL,
    `id_empleado`  INT           NOT NULL,
    `id_periodo`   INT           NOT NULL,
    `fecha_inicio` DATE          NOT NULL,
    `fecha_fin`    DATE          NOT NULL,
    `dias_tomados` DECIMAL(4,1)  NOT NULL,
    PRIMARY KEY (`id_vacacion`),
    CONSTRAINT `fk_vac_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitudes`   (`id_solicitud`),
    CONSTRAINT `fk_vac_empleado`  FOREIGN KEY (`id_empleado`)  REFERENCES `empleados`     (`id_empleado`),
    CONSTRAINT `fk_vac_periodo`   FOREIGN KEY (`id_periodo`)   REFERENCES `periodos_pago` (`id_periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 11. incapacidades
-- Incapacidades médicas (CCSS, INS, particular). No requieren aprobación previa.
CREATE TABLE IF NOT EXISTS `incapacidades` (
    `id_incapacidad`     INT           NOT NULL AUTO_INCREMENT,
    `id_empleado`        INT           NOT NULL,
    `id_periodo`         INT           NOT NULL,
    `tipo`               ENUM('CCSS','INS','particular') NOT NULL,
    `fecha_inicio`       DATE          NOT NULL,
    `fecha_fin`          DATE          NOT NULL,
    `dias`               INT           NOT NULL,
    `documento_respaldo` VARCHAR(255)      NULL DEFAULT NULL
        COMMENT 'Ruta al archivo digital de la boleta médica',
    PRIMARY KEY (`id_incapacidad`),
    CONSTRAINT `fk_inc_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados`     (`id_empleado`),
    CONSTRAINT `fk_inc_periodo`  FOREIGN KEY (`id_periodo`)  REFERENCES `periodos_pago` (`id_periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 12. permisos
-- Permisos con o sin goce salarial (aprobados vía solicitudes).
CREATE TABLE IF NOT EXISTS `permisos` (
    `id_permiso`       INT          NOT NULL AUTO_INCREMENT,
    `id_solicitud`     INT          NOT NULL,
    `id_empleado`      INT          NOT NULL,
    `id_periodo`       INT          NOT NULL,
    `fecha_inicio`     DATE         NOT NULL,
    `fecha_fin`        DATE         NOT NULL,
    `con_goce_salarial` TINYINT(1)  NOT NULL DEFAULT 1
        COMMENT '1 = con goce / 0 = sin goce',
    PRIMARY KEY (`id_permiso`),
    CONSTRAINT `fk_perm_solicitud` FOREIGN KEY (`id_solicitud`) REFERENCES `solicitudes`   (`id_solicitud`),
    CONSTRAINT `fk_perm_empleado`  FOREIGN KEY (`id_empleado`)  REFERENCES `empleados`     (`id_empleado`),
    CONSTRAINT `fk_perm_periodo`   FOREIGN KEY (`id_periodo`)   REFERENCES `periodos_pago` (`id_periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 13. saldo_vacaciones
-- Saldo acumulado de vacaciones por empleado y año (Código de Trabajo CR).
CREATE TABLE IF NOT EXISTS `saldo_vacaciones` (
    `id_saldo`            INT           NOT NULL AUTO_INCREMENT,
    `id_empleado`         INT           NOT NULL,
    `anio`                INT           NOT NULL,
    `dias_ganados`        DECIMAL(5,1)  NOT NULL DEFAULT 0.0,
    `dias_disfrutados`    DECIMAL(5,1)  NOT NULL DEFAULT 0.0,
    `dias_disponibles`    DECIMAL(5,1)  NOT NULL DEFAULT 0.0,
    `fecha_actualizacion` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_saldo`),
    UNIQUE KEY `uq_saldo_empleado_anio` (`id_empleado`, `anio`),
    CONSTRAINT `fk_saldo_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados` (`id_empleado`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- GRUPO 5: NÓMINA Y PAGOS
-- ============================================================

-- 14. nominas
-- Encabezado del cálculo de nómina quincenal por empleado.
CREATE TABLE IF NOT EXISTS `nominas` (
    `id_nomina`         INT            NOT NULL AUTO_INCREMENT,
    `id_empleado`       INT            NOT NULL,
    `id_periodo`        INT            NOT NULL,
    `salario_base`      DECIMAL(10,2)  NOT NULL,
    `total_ingresos`    DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `total_deducciones` DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `salario_bruto`     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `salario_neto`      DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `fecha_calculo`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `estado`            ENUM('borrador','aprobado','pagado') NOT NULL DEFAULT 'borrador',
    PRIMARY KEY (`id_nomina`),
    UNIQUE KEY `uq_nomina_empleado_periodo` (`id_empleado`, `id_periodo`),
    CONSTRAINT `fk_nom_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados`     (`id_empleado`),
    CONSTRAINT `fk_nom_periodo`  FOREIGN KEY (`id_periodo`)  REFERENCES `periodos_pago` (`id_periodo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 15. ingresos_nomina
-- Líneas de ingreso de una nómina (salario base, horas extra, bonificaciones, etc.).
CREATE TABLE IF NOT EXISTS `ingresos_nomina` (
    `id_ingreso` INT            NOT NULL AUTO_INCREMENT,
    `id_nomina`  INT            NOT NULL,
    `tipo`       ENUM('salario_base','horas_extra','bonificacion','comision','feriado','otro') NOT NULL,
    `descripcion` VARCHAR(150)     NULL DEFAULT NULL,
    `monto`      DECIMAL(10,2)  NOT NULL,
    PRIMARY KEY (`id_ingreso`),
    CONSTRAINT `fk_ing_nomina` FOREIGN KEY (`id_nomina`) REFERENCES `nominas` (`id_nomina`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 16. deducciones_nomina
-- Líneas de deducción de una nómina (CCSS, impuesto de renta, embargos, etc.).
-- CCSS obrera: 10.67% / CCSS patronal: 26.33% (Ley CCSS vigente).
CREATE TABLE IF NOT EXISTS `deducciones_nomina` (
    `id_deduccion` INT            NOT NULL AUTO_INCREMENT,
    `id_nomina`    INT            NOT NULL,
    `tipo`         ENUM('CCSS_empleado','CCSS_patronal','impuesto_renta','embargo','otro') NOT NULL,
    `descripcion`  VARCHAR(150)       NULL DEFAULT NULL,
    `porcentaje`   DECIMAL(5,2)       NULL DEFAULT NULL
        COMMENT 'Porcentaje aplicado (ej: 10.67 para CCSS obrera)',
    `monto`        DECIMAL(10,2)  NOT NULL,
    PRIMARY KEY (`id_deduccion`),
    CONSTRAINT `fk_ded_nomina` FOREIGN KEY (`id_nomina`) REFERENCES `nominas` (`id_nomina`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 17. aguinaldo
-- Cálculo anual del aguinaldo (Ley 2412, período 1-dic al 30-nov).
-- Monto = suma de salarios brutos del período ÷ 12.
CREATE TABLE IF NOT EXISTS `aguinaldo` (
    `id_aguinaldo`        INT            NOT NULL AUTO_INCREMENT,
    `id_empleado`         INT            NOT NULL,
    `anio`                INT            NOT NULL,
    `salarios_acumulados` DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    `monto_aguinaldo`     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `fecha_pago`          DATE               NULL DEFAULT NULL,
    `estado`              ENUM('calculado','pagado') NOT NULL DEFAULT 'calculado',
    PRIMARY KEY (`id_aguinaldo`),
    UNIQUE KEY `uq_aguinaldo_empleado_anio` (`id_empleado`, `anio`),
    CONSTRAINT `fk_agu_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados` (`id_empleado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 18. liquidacion
-- Liquidación laboral al término de la relación (preaviso, cesantía, vacaciones, aguinaldo).
CREATE TABLE IF NOT EXISTS `liquidacion` (
    `id_liquidacion`        INT            NOT NULL AUTO_INCREMENT,
    `id_empleado`           INT            NOT NULL,
    `fecha_salida`          DATE           NOT NULL,
    `motivo`                ENUM('renuncia','despido_con_causa','despido_sin_causa','mutuo_acuerdo','jubilacion') NOT NULL,
    `preaviso`              DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `cesantia`              DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `vacaciones_pendientes` DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `aguinaldo_proporcional` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `total_liquidacion`     DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    `fecha_calculo`         TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_liquidacion`),
    UNIQUE KEY `uq_liquidacion_empleado` (`id_empleado`),
    CONSTRAINT `fk_liq_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados` (`id_empleado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- GRUPO 6: EVALUACIÓN Y AUDITORÍA
-- ============================================================

-- 19. evaluaciones
-- Evaluación de rendimiento anual del empleado.
CREATE TABLE IF NOT EXISTS `evaluaciones` (
    `id_evaluacion`   INT           NOT NULL AUTO_INCREMENT,
    `id_empleado`     INT           NOT NULL,
    `fecha_evaluacion` DATE         NOT NULL,
    `periodo_evaluado` INT          NOT NULL COMMENT 'Año evaluado',
    `puntaje_total`   DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
    `observaciones`   VARCHAR(500)      NULL DEFAULT NULL,
    PRIMARY KEY (`id_evaluacion`),
    CONSTRAINT `fk_eval_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados` (`id_empleado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 20. detalle_evaluacion
-- Criterios individuales de cada evaluación (ponderados por peso).
CREATE TABLE IF NOT EXISTS `detalle_evaluacion` (
    `id_detalle`    INT           NOT NULL AUTO_INCREMENT,
    `id_evaluacion` INT           NOT NULL,
    `criterio`      VARCHAR(150)  NOT NULL,
    `puntaje`       DECIMAL(5,2)  NOT NULL,
    `peso`          DECIMAL(5,2)  NOT NULL COMMENT 'Peso porcentual del criterio',
    PRIMARY KEY (`id_detalle`),
    CONSTRAINT `fk_det_evaluacion` FOREIGN KEY (`id_evaluacion`) REFERENCES `evaluaciones` (`id_evaluacion`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 21. auditoria
-- Trazabilidad de operaciones para cumplir con la Ley 8968 (Protección de Datos).
CREATE TABLE IF NOT EXISTS `auditoria` (
    `id_auditoria`   INT           NOT NULL AUTO_INCREMENT,
    `id_usuario`     INT           NOT NULL,
    `tabla_afectada` VARCHAR(80)   NOT NULL,
    `accion`         ENUM('INSERT','UPDATE','DELETE','LOGIN','LOGOUT') NOT NULL,
    `id_registro`    INT               NULL DEFAULT NULL,
    `fecha_accion`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `detalle`        VARCHAR(500)      NULL DEFAULT NULL,
    `ip_origen`      VARCHAR(45)       NULL DEFAULT NULL,
    PRIMARY KEY (`id_auditoria`),
    CONSTRAINT `fk_aud_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DEL ESQUEMA
-- Tablas creadas: 21
-- Grupos: config/acceso (2), empleados (2), periodos/feriados (2),
--         solicitudes/eventos (6), nómina/pagos (5), evaluación/auditoría (4)
-- ============================================================

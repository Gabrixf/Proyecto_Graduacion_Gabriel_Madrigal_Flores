-- ============================================================
-- Sistema de Gestión de Nómina — Lubrimotos del Sur
-- Esquema de base de datos (29 tablas, normalizado a 3FN)
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
    `id_puesto`       INT            NOT NULL AUTO_INCREMENT,
    `id_departamento` INT NOT NULL DEFAULT 1
        COMMENT '1 = Sin asignar (departamento centinela)',
    `nombre`          VARCHAR(100)   NOT NULL,
    `salario_base`    DECIMAL(10,2)  NOT NULL,
    `descripcion` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id_puesto`),
    UNIQUE KEY `uq_puestos_nombre` (`nombre`),
    CONSTRAINT `fk_puestos_departamento` FOREIGN KEY (`id_departamento`) REFERENCES `departamentos` (`id_departamento`)
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

-- Usuario centinela (id=1): representa "sin cuenta de portal" / "aun sin resolver".
-- Inactivo a proposito para que nunca pueda usarse para iniciar sesion.
INSERT IGNORE INTO `usuarios` (`id_usuario`, `nombre_usuario`, `contrasena_hash`, `rol`, `activo`) VALUES
(1, 'sin_cuenta', 'CENTINELA_NO_USAR_PARA_LOGIN', 'empleado', 0);


-- ============================================================
-- GRUPO 2: EMPLEADOS
-- ============================================================

-- 3. empleados
-- Información laboral y personal del empleado.
-- Los datos bancarios están separados (tabla datos_bancarios) por Ley 8968.
CREATE TABLE IF NOT EXISTS `empleados` (
    `id_empleado`           INT            NOT NULL AUTO_INCREMENT,
    `id_persona`            INT            NOT NULL
        COMMENT 'Relacion 1:1 con persona (datos de identidad y contacto)',
    `id_puesto`             INT            NOT NULL,
    `id_usuario` INT NOT NULL DEFAULT 1
        COMMENT '1 = sin cuenta de portal (usuario centinela, inactivo)',
    `id_horario` INT NOT NULL DEFAULT 1
        COMMENT '1 = sin horario asignado (horario centinela)',
    `numero_asegurado_ccss` VARCHAR(20) NOT NULL DEFAULT '',
    `numero_poliza_ins` VARCHAR(30) NOT NULL DEFAULT '',
    `fecha_ingreso`         DATE           NOT NULL,
    `fecha_salida` DATE NOT NULL DEFAULT '9999-12-31'
        COMMENT '9999-12-31 = todavia activo, sin fecha de salida',
    `estado`                ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
    PRIMARY KEY (`id_empleado`),
    UNIQUE KEY `uq_empleados_persona` (`id_persona`),
    CONSTRAINT `fk_empleados_persona`  FOREIGN KEY (`id_persona`) REFERENCES `persona` (`id_persona`),
    CONSTRAINT `fk_empleados_puesto`   FOREIGN KEY (`id_puesto`)  REFERENCES `puestos`  (`id_puesto`),
    CONSTRAINT `fk_empleados_usuario`  FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`),
    CONSTRAINT `fk_empleados_horario`  FOREIGN KEY (`id_horario`) REFERENCES `horarios` (`id_horario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 4. datos_bancarios
-- Información bancaria separada por Ley 8968 (Protección de Datos Personales).
CREATE TABLE IF NOT EXISTS `datos_bancarios` (
    `id_datos_bancarios` INT            NOT NULL AUTO_INCREMENT,
    `id_empleado`        INT            NOT NULL,
    `banco`              VARCHAR(100)   NOT NULL,
    `tipo_cuenta`        ENUM('corriente','ahorros') NOT NULL,
    `numero_cuenta`      VARCHAR(30)    NOT NULL,
    `numero_cuenta_iban` VARCHAR(22) NOT NULL DEFAULT '',
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

-- Feriado centinela (id=1): representa "no aplica / no es feriado". Fecha ficticia
-- fuera de cualquier anio real para que nunca choque con un feriado verdadero.
INSERT INTO `feriados` (`id_feriado`, `fecha`, `nombre`, `tipo`) VALUES
(1, '1000-01-01', 'No aplica', 'no_obligatorio_pago');


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
    `fecha_fin` DATE NOT NULL DEFAULT '9999-12-31',
    `horas` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `motivo` VARCHAR(255) NOT NULL DEFAULT '',
    `estado`             ENUM('pendiente','aprobada','rechazada') NOT NULL DEFAULT 'pendiente',
    `fecha_solicitud`    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_resolucion` DATETIME NOT NULL DEFAULT '9999-12-31 23:59:59'
        COMMENT '9999-12-31 23:59:59 = aun sin resolver',
    `id_usuario_resuelve` INT NOT NULL DEFAULT 1
        COMMENT '1 = aun sin resolver (usuario centinela)',
    `observacion_admin` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id_solicitud`),
    CONSTRAINT `fk_solicitudes_empleado`  FOREIGN KEY (`id_empleado`)        REFERENCES `empleados` (`id_empleado`),
    CONSTRAINT `fk_solicitudes_resuelve`  FOREIGN KEY (`id_usuario_resuelve`) REFERENCES `usuarios`  (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 8. asistencia
-- Registro diario de asistencia por empleado y período.
CREATE TABLE IF NOT EXISTS `asistencia` (
    `id_asistencia`   INT           NOT NULL AUTO_INCREMENT,
    `id_empleado`     INT           NOT NULL,
    `id_periodo`      INT           NOT NULL,
    `id_feriado` INT NOT NULL DEFAULT 1
        COMMENT '1 = no es feriado (feriado centinela)',
    `fecha`           DATE          NOT NULL,
    `hora_entrada` TIME NOT NULL DEFAULT '00:00:00',
    `hora_salida` TIME NOT NULL DEFAULT '00:00:00',
    `horas_trabajadas` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
    PRIMARY KEY (`id_asistencia`),
    UNIQUE KEY `uq_asistencia_empleado_fecha` (`id_empleado`, `fecha`),
    CONSTRAINT `fk_asistencia_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados`    (`id_empleado`),
    CONSTRAINT `fk_asistencia_periodo`  FOREIGN KEY (`id_periodo`)  REFERENCES `periodos_pago` (`id_periodo`),
    CONSTRAINT `fk_asistencia_feriado`  FOREIGN KEY (`id_feriado`)  REFERENCES `feriados`     (`id_feriado`)
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
    `id_parametro`   INT               NOT NULL
        COMMENT 'Parametro legal vigente usado para resolver factor_recargo. Siempre resoluble: no necesita centinela.',
    PRIMARY KEY (`id_hora_extra`),
    CONSTRAINT `fk_he_solicitud`  FOREIGN KEY (`id_solicitud`) REFERENCES `solicitudes`        (`id_solicitud`),
    CONSTRAINT `fk_he_empleado`   FOREIGN KEY (`id_empleado`)  REFERENCES `empleados`          (`id_empleado`),
    CONSTRAINT `fk_he_periodo`    FOREIGN KEY (`id_periodo`)   REFERENCES `periodos_pago`      (`id_periodo`),
    CONSTRAINT `fk_he_parametro`  FOREIGN KEY (`id_parametro`) REFERENCES `parametros_legales` (`id_parametro`)
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
    `documento_respaldo` VARCHAR(255) NOT NULL DEFAULT '',
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
    `descripcion` VARCHAR(150) NOT NULL DEFAULT '',
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
    `descripcion` VARCHAR(150) NOT NULL DEFAULT '',
    `porcentaje` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `monto`        DECIMAL(10,2)  NOT NULL,
    `id_parametro` INT NOT NULL DEFAULT 1
        COMMENT '1 = no aplica parametro legal (parametro centinela, ej. embargo, impuesto_renta)',
    PRIMARY KEY (`id_deduccion`),
    CONSTRAINT `fk_ded_nomina`    FOREIGN KEY (`id_nomina`)    REFERENCES `nominas`           (`id_nomina`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_ded_parametro` FOREIGN KEY (`id_parametro`) REFERENCES `parametros_legales` (`id_parametro`)
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
    `fecha_pago` DATE NOT NULL DEFAULT '9999-12-31'
        COMMENT '9999-12-31 = aun no pagado',
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
    `observaciones` VARCHAR(500) NOT NULL DEFAULT '',
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
    `id_registro` INT NOT NULL DEFAULT 0
        COMMENT '0 = sin registro especifico (ej. LOGIN/LOGOUT)',
    `fecha_accion`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `detalle` VARCHAR(500) NOT NULL DEFAULT '',
    `ip_origen` VARCHAR(45) NOT NULL DEFAULT '',
    PRIMARY KEY (`id_auditoria`),
    CONSTRAINT `fk_aud_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================================
-- GRUPO 7: PARAMETRIZACIÓN Y ESTRUCTURA ORGANIZACIONAL
-- ============================================================

-- 22. departamentos
-- Estructura organizacional de la empresa. Un puesto pertenece a un departamento.
CREATE TABLE IF NOT EXISTS `departamentos` (
    `id_departamento` INT          NOT NULL AUTO_INCREMENT,
    `nombre`          VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id_departamento`),
    UNIQUE KEY `uq_departamentos_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Departamento centinela (id=1): representa "sin asignar".
INSERT INTO `departamentos` (`id_departamento`, `nombre`) VALUES
(1, 'Sin asignar');


-- 23. horarios
-- Catálogo de horarios/turnos de trabajo asignables a un empleado.
CREATE TABLE IF NOT EXISTS `horarios` (
    `id_horario`   INT          NOT NULL AUTO_INCREMENT,
    `nombre`       VARCHAR(100) NOT NULL,
    `hora_entrada` TIME         NOT NULL,
    `hora_salida`  TIME         NOT NULL,
    `dias_semana`  VARCHAR(50)  NOT NULL COMMENT 'Ej: L-V, L-S',
    PRIMARY KEY (`id_horario`),
    UNIQUE KEY `uq_horarios_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horario centinela (id=1): representa "sin horario asignado".
INSERT INTO `horarios` (`id_horario`, `nombre`, `hora_entrada`, `hora_salida`, `dias_semana`) VALUES
(1, 'Sin asignar', '00:00:00', '00:00:00', 'N/A');


-- 24. contratos
-- Historial de contratos laborales por empleado (Código de Trabajo de Costa Rica).
-- Un empleado puede tener varios contratos a lo largo del tiempo (renovaciones).
CREATE TABLE IF NOT EXISTS `contratos` (
    `id_contrato`     INT            NOT NULL AUTO_INCREMENT,
    `id_empleado`     INT            NOT NULL,
    `tipo_contrato`   ENUM('tiempo_indefinido','plazo_fijo','obra_determinada') NOT NULL,
    `salario_pactado` DECIMAL(10,2)  NOT NULL,
    `jornada`         ENUM('tiempo_completo','medio_tiempo') NOT NULL DEFAULT 'tiempo_completo',
    `fecha_inicio`    DATE           NOT NULL,
    `fecha_fin` DATE NOT NULL DEFAULT '9999-12-31'
        COMMENT '9999-12-31 = contrato aun vigente',
    `estado`          ENUM('activo','finalizado') NOT NULL DEFAULT 'activo',
    PRIMARY KEY (`id_contrato`),
    CONSTRAINT `fk_contratos_empleado` FOREIGN KEY (`id_empleado`) REFERENCES `empleados` (`id_empleado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- 25. parametros_legales
-- Parametrización de factores legales (horas extra, CCSS) versionada por vigencia,
-- para que un cambio futuro en la ley no requiera modificar código y se preserve
-- el valor histórico usado en cálculos ya realizados.
CREATE TABLE IF NOT EXISTS `parametros_legales` (
    `id_parametro` INT            NOT NULL AUTO_INCREMENT,
    `clave`        VARCHAR(50)    NOT NULL COMMENT 'Ej: factor_he_ordinaria, factor_he_feriado, ccss_obrera, ccss_patronal',
    `valor`        DECIMAL(6,4)   NOT NULL,
    `fecha_inicio` DATE           NOT NULL,
    `fecha_fin` DATE NOT NULL DEFAULT '9999-12-31'
        COMMENT '9999-12-31 = parametro vigente actualmente',
    PRIMARY KEY (`id_parametro`),
    UNIQUE KEY `uq_parametros_clave_inicio` (`clave`, `fecha_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Parametro centinela (id=1): representa "no aplica" (para deducciones que no
-- provienen de un parametro legal, ej. impuesto_renta, embargo, otro).
INSERT INTO `parametros_legales` (`id_parametro`, `clave`, `valor`, `fecha_inicio`, `fecha_fin`) VALUES
(1, 'no_aplica', 0.0000, '1000-01-01', '9999-12-31');


-- ============================================================
-- GRUPO 8: PERSONAS Y GEOGRAFÍA
-- ============================================================

-- 26. provincias
-- Catálogo de provincias de Costa Rica.
CREATE TABLE IF NOT EXISTS `provincias` (
    `id_provincia` INT          NOT NULL AUTO_INCREMENT,
    `nombre`       VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id_provincia`),
    UNIQUE KEY `uq_provincias_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Provincia centinela (id=1) + las 7 provincias reales de Costa Rica.
INSERT INTO `provincias` (`id_provincia`, `nombre`) VALUES
(1, 'No aplica'),
(2, 'San José'),
(3, 'Alajuela'),
(4, 'Cartago'),
(5, 'Heredia'),
(6, 'Guanacaste'),
(7, 'Puntarenas'),
(8, 'Limón');


-- 27. cantones
-- Catálogo de cantones de Costa Rica. Cada cantón pertenece a una provincia.
CREATE TABLE IF NOT EXISTS `cantones` (
    `id_canton`     INT          NOT NULL AUTO_INCREMENT,
    `id_provincia`  INT          NOT NULL,
    `nombre`        VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id_canton`),
    UNIQUE KEY `uq_cantones_provincia_nombre` (`id_provincia`, `nombre`),
    CONSTRAINT `fk_cantones_provincia` FOREIGN KEY (`id_provincia`) REFERENCES `provincias` (`id_provincia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cantón centinela (id=1) + el cantón cabecera de cada provincia real.
-- NOTA: subconjunto representativo, no el catálogo completo (~84 cantones reales).
INSERT INTO `cantones` (`id_canton`, `id_provincia`, `nombre`) VALUES
(1, 1, 'No aplica'),
(2, 2, 'San José'),
(3, 3, 'Alajuela'),
(4, 4, 'Cartago'),
(5, 5, 'Heredia'),
(6, 6, 'Liberia'),
(7, 7, 'Puntarenas'),
(8, 8, 'Limón');


-- 28. distritos
-- Catálogo de distritos de Costa Rica. Cada distrito pertenece a un cantón.
CREATE TABLE IF NOT EXISTS `distritos` (
    `id_distrito` INT          NOT NULL AUTO_INCREMENT,
    `id_canton`   INT          NOT NULL,
    `nombre`      VARCHAR(100) NOT NULL,
    PRIMARY KEY (`id_distrito`),
    UNIQUE KEY `uq_distritos_canton_nombre` (`id_canton`, `nombre`),
    CONSTRAINT `fk_distritos_canton` FOREIGN KEY (`id_canton`) REFERENCES `cantones` (`id_canton`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Distrito centinela (id=1) + distritos reales conocidos: los 11 del cantón
-- San José (capital) y el distrito cabecera de cada uno de los otros 6 cantones.
-- NOTA: subconjunto representativo para efectos del TFG y de las pruebas locales;
-- antes de un uso en producción real debe cargarse el catálogo completo y oficial
-- de división territorial administrativa (INEC / IFAM), verificando cada nombre.
INSERT INTO `distritos` (`id_distrito`, `id_canton`, `nombre`) VALUES
(1,  1, 'No aplica'),
(2,  2, 'Carmen'),
(3,  2, 'Merced'),
(4,  2, 'Hospital'),
(5,  2, 'Catedral'),
(6,  2, 'Zapote'),
(7,  2, 'San Francisco de Dos Ríos'),
(8,  2, 'Uruca'),
(9,  2, 'Mata Redonda'),
(10, 2, 'Pavas'),
(11, 2, 'Hatillo'),
(12, 2, 'San Sebastián'),
(13, 3, 'Alajuela'),
(14, 4, 'Oriental'),
(15, 5, 'Heredia'),
(16, 6, 'Liberia'),
(17, 7, 'Puntarenas'),
(18, 8, 'Limón');


-- 29. persona
-- Supertipo: datos de identidad y contacto comunes a cualquier persona
-- relacionada con el sistema. Hoy solo se especializa en `empleados`.
CREATE TABLE IF NOT EXISTS `persona` (
    `id_persona`                 INT            NOT NULL AUTO_INCREMENT,
    `cedula`                     VARCHAR(20)    NOT NULL,
    `nombre`                     VARCHAR(100)   NOT NULL,
    `apellidos`                  VARCHAR(100)   NOT NULL,
    `fecha_nacimiento`           DATE           NOT NULL,
    `genero`                     ENUM('masculino','femenino','otro') NOT NULL,
    `estado_civil`               ENUM('soltero','casado','union_libre','divorciado','viudo') NOT NULL,
    `nacionalidad`               VARCHAR(50)    NOT NULL,
    `telefono`                   VARCHAR(20)    NOT NULL DEFAULT '',
    `correo`                     VARCHAR(150)   NOT NULL DEFAULT '',
    `direccion`                  VARCHAR(255)   NOT NULL DEFAULT '',
    `id_distrito` INT NOT NULL DEFAULT 1
        COMMENT '1 = sin distrito asignado (distrito centinela)',
    `telefono_alterno`           VARCHAR(20)    NOT NULL DEFAULT '',
    `correo_alterno`             VARCHAR(150)   NOT NULL DEFAULT '',
    `direccion_alterna`          VARCHAR(255)   NOT NULL DEFAULT '',
    `id_distrito_alterno` INT NOT NULL DEFAULT 1
        COMMENT '1 = sin distrito alterno asignado (distrito centinela)',
    `telefono_emergencia`        VARCHAR(20)    NOT NULL DEFAULT '',
    `nombre_contacto_emergencia` VARCHAR(150)   NOT NULL DEFAULT '',
    PRIMARY KEY (`id_persona`),
    UNIQUE KEY `uq_persona_cedula` (`cedula`),
    CONSTRAINT `fk_persona_distrito`         FOREIGN KEY (`id_distrito`)         REFERENCES `distritos` (`id_distrito`),
    CONSTRAINT `fk_persona_distrito_alterno` FOREIGN KEY (`id_distrito_alterno`) REFERENCES `distritos` (`id_distrito`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- FIN DEL ESQUEMA
-- Tablas creadas: 29
-- Grupos: config/acceso (2), empleados (2), periodos/feriados (2),
--         solicitudes/eventos (6), nómina/pagos (5), evaluación/auditoría (4),
--         parametrización y estructura organizacional (4),
--         personas y geografía (4)
-- ============================================================

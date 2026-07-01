-- =============================================================================
-- 032_hojas_ruta.sql — Hoja de ruta de reparto (nuestra), armada por logística.
--
-- Distinta de ordenes.hoja_ruta (la del proveedor que viene con la carga). Esta
-- es el documento de SALIDA A REPARTO: encabezado (conductor/vehículo/ayudantes,
-- del padrón o texto libre), las órdenes que salen y líneas manuales de artículos
-- fuera del sistema. El scan de reparto la elige de un desplegable y el lote queda
-- asociado (lotes.hoja_ruta_id) → deja asentado quién/cuándo/qué vehículo.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `hojas_ruta` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `numero`                VARCHAR(20)  DEFAULT NULL,
    `fecha`                 DATE         DEFAULT NULL,
    `conductor_empleado_id` INT UNSIGNED DEFAULT NULL,
    `conductor`             VARCHAR(120) DEFAULT NULL,
    `vehiculo_id`           INT UNSIGNED DEFAULT NULL,
    `vehiculo`              VARCHAR(120) DEFAULT NULL,
    `ayudantes`             VARCHAR(255) DEFAULT NULL,
    `destino`               VARCHAR(150) DEFAULT NULL,
    `observaciones`         VARCHAR(600) DEFAULT NULL,
    `estado`                ENUM('abierta','emitida') NOT NULL DEFAULT 'abierta',
    `creado_por`            INT UNSIGNED DEFAULT NULL,
    `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hoja_numero` (`numero`),
    INDEX `idx_hoja_estado` (`estado`),
    CONSTRAINT `fk_hoja_conductor` FOREIGN KEY (`conductor_empleado_id`) REFERENCES `acompanantes` (`id`),
    CONSTRAINT `fk_hoja_vehiculo`  FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`),
    CONSTRAINT `fk_hoja_usuario`   FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hoja_ruta_ordenes` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hoja_id`  INT UNSIGNED NOT NULL,
    `orden_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hoja_orden` (`hoja_id`, `orden_id`),
    INDEX `idx_hro_orden` (`orden_id`),
    CONSTRAINT `fk_hro_hoja`  FOREIGN KEY (`hoja_id`)  REFERENCES `hojas_ruta` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hro_orden` FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hoja_ruta_manual` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hoja_id`         INT UNSIGNED NOT NULL,
    `cliente_origen`  VARCHAR(120) DEFAULT NULL,
    `nro_orden`       VARCHAR(60)  DEFAULT NULL,
    `cliente_destino` VARCHAR(150) DEFAULT NULL,
    `localidad`       VARCHAR(150) DEFAULT NULL,
    `bultos`          INT UNSIGNED DEFAULT NULL,
    `m3`              DECIMAL(10,3) DEFAULT NULL,
    `telefono`        VARCHAR(60)  DEFAULT NULL,
    `observacion`     VARCHAR(255) DEFAULT NULL,
    `posicion`        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_hrm_hoja` (`hoja_id`),
    CONSTRAINT `fk_hrm_hoja` FOREIGN KEY (`hoja_id`) REFERENCES `hojas_ruta` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `lotes`
    ADD COLUMN `hoja_ruta_id` INT UNSIGNED DEFAULT NULL AFTER `conductor_empleado_id`,
    ADD CONSTRAINT `fk_lotes_hoja_ruta` FOREIGN KEY (`hoja_ruta_id`) REFERENCES `hojas_ruta` (`id`);

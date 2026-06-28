-- =============================================================================
-- 018_costos.sql — módulo de costos.
--
--   costos_viaje      → gastos asociados a un viaje (lote): combustible, permiso,
--                       viático, otro.
--   costos_vehiculo   → gastos asociados a un vehículo (mantenimiento, reparación),
--                       no atados a un viaje puntual.
--
-- Insumo para, a futuro, relacionar viajes/servicios con sus costos y rentabilidad.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `costos_viaje` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `lote_id`     INT UNSIGNED  NOT NULL,
    `tipo`        ENUM('combustible','permiso','viatico','otro') NOT NULL,
    `importe`     DECIMAL(12,2) NOT NULL,
    `fecha`       DATE          DEFAULT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `creado_por`  INT UNSIGNED  DEFAULT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cv_lote` (`lote_id`),
    INDEX `idx_cv_fecha` (`fecha`),
    CONSTRAINT `fk_cv_lote`    FOREIGN KEY (`lote_id`)    REFERENCES `lotes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cv_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `costos_vehiculo` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `vehiculo_id` INT UNSIGNED  NOT NULL,
    `tipo`        ENUM('mantenimiento','reparacion','otro') NOT NULL,
    `importe`     DECIMAL(12,2) NOT NULL,
    `fecha`       DATE          DEFAULT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `creado_por`  INT UNSIGNED  DEFAULT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cveh_vehiculo` (`vehiculo_id`),
    INDEX `idx_cveh_fecha` (`fecha`),
    CONSTRAINT `fk_cveh_vehiculo` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`),
    CONSTRAINT `fk_cveh_usuario`  FOREIGN KEY (`creado_por`)  REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 014_acompanantes_vehiculos.sql — catálogos simples para el reparto.
--
-- Acompañantes (ayudantes) y vehículos (unidades) que el depósito elige desde
-- un desplegable al iniciar una SALIDA_REPARTO, en lugar de escribirlos a mano.
-- Cada uno: nombre + una observación opcional. Soft-delete con `activo`.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `acompanantes` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`      VARCHAR(120)  NOT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `activo`      TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_acomp_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehiculos` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`      VARCHAR(120)  NOT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `activo`      TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_veh_activo` (`activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

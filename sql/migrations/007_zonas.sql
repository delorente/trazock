-- =============================================================================
-- 007_zonas.sql — Zonas de reparto.
--
-- Una ZONA agrupa varias localidades de destino (provincia + ciudad). Al abrir un
-- lote de SALIDA_REPARTO, el operador elige una zona; el escáner valida que cada
-- QR escaneado pertenezca a ella (control offline, contra los catálogos cacheados).
--
-- `ciudad` es opcional: vacía/NULL significa "toda la provincia" (caso típico al
-- repartir en provincias lejanas, donde se va a cualquier localidad). Con ciudad
-- puntual se distingue dentro de la provincia base (p. ej. zonas dentro de Tucumán).
--
-- Nota MariaDB 10.3: TIMESTAMP como NULL DEFAULT NULL / CURRENT_TIMESTAMP igual
-- que las migraciones previas.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `zonas` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre`     VARCHAR(80)  NOT NULL,
    `activo`     TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_zona_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `zona_localidades` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `zona_id`   INT UNSIGNED NOT NULL,
    `provincia` VARCHAR(80)  NOT NULL,
    `ciudad`    VARCHAR(120) DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_zona` (`zona_id`),
    CONSTRAINT `fk_zl_zona`
        FOREIGN KEY (`zona_id`) REFERENCES `zonas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

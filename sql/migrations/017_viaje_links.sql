-- =============================================================================
-- 017_viaje_links.sql — engancha el "viaje" (lote) con sus activos por ID, para
-- poder reportar movimientos por vehículo / persona de forma confiable.
--
--   lotes.vehiculo_id   → FK a vehiculos (se conserva lotes.vehiculo como
--                         snapshot de nombre para la hoja de ruta / display).
--   lote_ayudantes      → pivote lote↔acompañante (varios o ninguno). Se conserva
--                         lotes.ayudantes (nombres) como snapshot.
--   El conductor ya es lotes.transportista_id (FK usuarios); lotes.chofer es snapshot.
-- =============================================================================

ALTER TABLE `lotes`
    ADD COLUMN `vehiculo_id` INT UNSIGNED DEFAULT NULL AFTER `vehiculo`,
    ADD CONSTRAINT `fk_lotes_vehiculo`
        FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`);

CREATE TABLE IF NOT EXISTS `lote_ayudantes` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lote_id`        INT UNSIGNED NOT NULL,
    `acompanante_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_lote_acomp` (`lote_id`, `acompanante_id`),
    INDEX `idx_la_acomp` (`acompanante_id`),
    CONSTRAINT `fk_la_lote`  FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_la_acomp` FOREIGN KEY (`acompanante_id`) REFERENCES `acompanantes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

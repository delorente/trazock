-- =============================================================================
-- 034_geocoding_rutas.sql — Secuenciación de rutas (feature D), FASE 1.
--
-- Todo ADITIVO: dos tablas nuevas, ninguna columna/FK sobre tablas existentes.
-- Las pantallas actuales (hoja-ruta-armar / hoja-ruta / hojas-ruta) no se tocan.
--
--   geo_direcciones — caché de geocoding (dirección → lat/lng), desacoplado de la
--     orden porque las direcciones se repiten mucho. Clave = dirección normalizada
--     (Trazock\Models\Destino::norm sobre domicilio|localidad|provincia|cp). Una
--     fila por dirección distinta; `precision` dice con qué granularidad se resolvió
--     (dirección exacta, centroide de localidad/provincia, o no se pudo).
--
--   ruta_secuencia — orden de las paradas de una hoja de ruta, SIN tocar
--     hoja_ruta_ordenes. Una fila por parada (orden del sistema o línea manual),
--     con su posición y la coordenada efectiva. `override_manual`=1 cuando el
--     operador movió el pin a mano: esa coord manda y no se re-geocodifica.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `geo_direcciones` (
    `id`            INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `clave_norm`    VARCHAR(255)   NOT NULL,
    `direccion`     VARCHAR(400)   DEFAULT NULL,
    `lat`           DECIMAL(10, 7) DEFAULT NULL,
    `lng`           DECIMAL(10, 7) DEFAULT NULL,
    `precision`     ENUM('exacta', 'localidad', 'provincia', 'fallida') NOT NULL DEFAULT 'fallida',
    `fuente`        VARCHAR(30)    DEFAULT NULL,
    `geocoded_at`   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_geo_clave` (`clave_norm`),
    INDEX `idx_geo_precision` (`precision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ruta_secuencia` (
    `id`              INT UNSIGNED   NOT NULL AUTO_INCREMENT,
    `hoja_id`         INT UNSIGNED   NOT NULL,
    `tipo`            ENUM('orden', 'manual') NOT NULL DEFAULT 'orden',
    `ref_id`          INT UNSIGNED   NOT NULL,
    `posicion`        INT UNSIGNED   NOT NULL DEFAULT 0,
    `lat`             DECIMAL(10, 7) DEFAULT NULL,
    `lng`             DECIMAL(10, 7) DEFAULT NULL,
    `override_manual` TINYINT(1)     NOT NULL DEFAULT 0,
    `created_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ruta_parada` (`hoja_id`, `tipo`, `ref_id`),
    INDEX `idx_ruta_hoja` (`hoja_id`, `posicion`),
    CONSTRAINT `fk_ruta_hoja` FOREIGN KEY (`hoja_id`) REFERENCES `hojas_ruta` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

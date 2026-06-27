-- =============================================================================
-- 016_facturacion_m1.sql — Milestone 1 de facturación (pre-factura por marca).
--
--   categorias.proveedor_id  → la orden hereda su marca de la categoría.
--   proveedores.*fiscal*     → datos del receptor (a quién se factura).
--   afip_emisor              → datos fiscales del emisor (la Corredora). Fila única.
--   tarifas                  → precio por m³ según provincia + tipo de venta.
--
-- Sin AFIP todavía: esto habilita calcular importes/IVA/total y la pre-factura.
-- =============================================================================

-- M1.1 — marca por categoría
ALTER TABLE `categorias`
    ADD COLUMN `proveedor_id` INT UNSIGNED DEFAULT NULL AFTER `nombre`,
    ADD CONSTRAINT `fk_categorias_proveedor`
        FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`);

-- M1.2 — datos fiscales del receptor (la marca/proveedor)
ALTER TABLE `proveedores`
    ADD COLUMN `razon_social`  VARCHAR(150) DEFAULT NULL AFTER `nombre`,
    ADD COLUMN `cuit`          VARCHAR(13)  DEFAULT NULL AFTER `razon_social`,
    ADD COLUMN `condicion_iva` VARCHAR(40)  DEFAULT NULL AFTER `cuit`,
    ADD COLUMN `domicilio`     VARCHAR(200) DEFAULT NULL AFTER `condicion_iva`;

-- M1.3 — datos fiscales del emisor (la Corredora). Fila única (id = 1).
CREATE TABLE IF NOT EXISTS `afip_emisor` (
    `id`                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `razon_social`       VARCHAR(150)  DEFAULT NULL,
    `cuit`               VARCHAR(13)   DEFAULT NULL,
    `condicion_iva`      VARCHAR(40)   DEFAULT 'Responsable Inscripto',
    `domicilio`          VARCHAR(200)  DEFAULT NULL,
    `iibb`               VARCHAR(40)   DEFAULT NULL,
    `inicio_actividades` DATE          DEFAULT NULL,
    `iva_alicuota`       DECIMAL(5,2)  NOT NULL DEFAULT 21.00,
    `updated_at`         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `afip_emisor` (`id`, `razon_social`, `condicion_iva`, `iva_alicuota`)
VALUES (1, 'Corredora de Servicios S.A.', 'Responsable Inscripto', 21.00)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- M1.4 — tarifario por provincia + tipo de venta
CREATE TABLE IF NOT EXISTS `tarifas` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `provincia`  VARCHAR(80)   NOT NULL,
    `tipo_venta` ENUM('online','local') NOT NULL,
    `precio_m3`  DECIMAL(12,2) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tarifa_prov_tipo` (`provincia`, `tipo_venta`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 015_facturacion.sql — soporte para generar la factura desde el reporte.
--
--   tarifas:           precio por m³ según provincia de destino (tarifario).
--   facturacion_datos: datos fijos del emisor y del receptor (a quién se factura)
--                      + alícuota de IVA. Fila única (id = 1).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `tarifas` (
    `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `provincia`  VARCHAR(80)   NOT NULL,
    `precio_m3`  DECIMAL(12,2) NOT NULL DEFAULT 0,
    `activo`     TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tarifa_provincia` (`provincia`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `facturacion_datos` (
    `id`                    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `emisor_razon_social`   VARCHAR(150)  DEFAULT NULL,
    `emisor_cuit`           VARCHAR(20)   DEFAULT NULL,
    `emisor_iva`            VARCHAR(40)   DEFAULT NULL,
    `emisor_domicilio`      VARCHAR(200)  DEFAULT NULL,
    `receptor_razon_social` VARCHAR(150)  DEFAULT NULL,
    `receptor_cuit`         VARCHAR(20)   DEFAULT NULL,
    `receptor_iva`          VARCHAR(40)   DEFAULT NULL,
    `receptor_domicilio`    VARCHAR(200)  DEFAULT NULL,
    `iva_alicuota`          DECIMAL(5,2)  NOT NULL DEFAULT 21.00,
    `updated_at`            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `facturacion_datos` (`id`, `emisor_razon_social`, `iva_alicuota`)
VALUES (1, 'Corredora de Servicios S.A.', 21.00)
ON DUPLICATE KEY UPDATE `id` = `id`;

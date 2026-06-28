-- =============================================================================
-- 019_facturacion_cliente.sql — configuración de facturación por cliente (marca).
--
--   cliente_facturacion    → por cliente (proveedor): unidad de cobro (m3/bulto/
--                            peso), si cobra por destino o precio único, y el
--                            precio unitario único.
--   cliente_tarifa_destino → precio unitario por provincia (cuando por_destino=1).
--
-- Insumo para el reporte de resultados (ingresos = cantidad × precio). Es
-- independiente del tarifario `tarifas` que usa la pre-factura AFIP (no se toca).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `cliente_facturacion` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `proveedor_id` INT UNSIGNED  NOT NULL,
    `unidad`       ENUM('m3','bulto','peso') NOT NULL DEFAULT 'm3',
    `por_destino`  TINYINT(1)    NOT NULL DEFAULT 1,
    `precio_unico` DECIMAL(12,2) DEFAULT NULL,
    `activo`       TINYINT(1)    NOT NULL DEFAULT 1,
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cf_proveedor` (`proveedor_id`),
    CONSTRAINT `fk_cf_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cliente_tarifa_destino` (
    `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `proveedor_id` INT UNSIGNED  NOT NULL,
    `provincia`    VARCHAR(80)   NOT NULL,
    `precio`       DECIMAL(12,2) NOT NULL DEFAULT 0,
    `created_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_ctd_prov_provincia` (`proveedor_id`, `provincia`),
    CONSTRAINT `fk_ctd_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

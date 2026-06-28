-- =============================================================================
-- 020_cliente_precio_vigencia.sql — precio por cliente CON VIGENCIA por fecha.
--
-- El precio por unidad cambia con el tiempo; cada hoja de ruta debe usar el
-- precio vigente a SU fecha (fecha_carga), sin que un cambio altere lo pasado.
-- `cliente_precio` guarda el historial: una fila por (cliente, destino, desde).
--   provincia = '' → precio único (todos los destinos).
--
-- Reemplaza el uso de cliente_facturacion.precio_unico y cliente_tarifa_destino
-- (esas quedan sin uso pero NO se borran, para no perder datos). cliente_facturacion
-- sigue guardando unidad + por_destino.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `cliente_precio` (
    `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `proveedor_id`  INT UNSIGNED  NOT NULL,
    `provincia`     VARCHAR(80)   NOT NULL DEFAULT '',
    `precio`        DECIMAL(12,2) NOT NULL,
    `vigente_desde` DATE          NOT NULL,
    `created_at`    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cp` (`proveedor_id`, `provincia`, `vigente_desde`),
    INDEX `idx_cp_lookup` (`proveedor_id`, `provincia`, `vigente_desde`),
    CONSTRAINT `fk_cp_proveedor` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migrar lo cargado con 019 (vigencia antigua, aplica a todo el histórico).
INSERT IGNORE INTO `cliente_precio` (`proveedor_id`, `provincia`, `precio`, `vigente_desde`)
SELECT `proveedor_id`, '', `precio_unico`, '2000-01-01'
FROM `cliente_facturacion` WHERE `precio_unico` IS NOT NULL;

INSERT IGNORE INTO `cliente_precio` (`proveedor_id`, `provincia`, `precio`, `vigente_desde`)
SELECT `proveedor_id`, `provincia`, `precio`, '2000-01-01'
FROM `cliente_tarifa_destino`;

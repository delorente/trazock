-- =============================================================================
-- 005_ordenes.sql — Módulo de ingreso por OCR (órdenes / remitos Simmons).
--
-- Suma el concepto de ORDEN (el remito que rastrea el cliente final) sin tocar
-- lo existente. Cada ítem físico de una orden sigue siendo un `producto` (con su
-- QR, su estado_actual y la máquina de estados intactos); la orden los agrupa y
-- guarda los datos de cliente, destino, m³, valor declarado y tipo de venta.
--
--   cargas   — el "lote de ingreso": una captura/confirmación de hojas resumen.
--   ordenes  — una orden por remito (nro_orden = lo que va al QR y al seguimiento).
--   productos (+columnas) — los ítems rastreables, ligados a su orden.
--
-- Nota MariaDB: los TIMESTAMP nulos se declaran NULL DEFAULT NULL (portabilidad
-- 10.3 ↔ MySQL 8, igual que en la 004).
-- =============================================================================

-- -----------------------------------------------------------------------------
-- cargas — lote de ingreso por OCR. Mientras se revisa, los datos viven como
-- JSON editable en `datos_extraidos`; al confirmar se materializan en ordenes
-- + productos.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cargas` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `usuario_id`       INT UNSIGNED    NOT NULL,
    `fecha`            DATE            DEFAULT NULL,
    `estado`           ENUM('borrador','confirmada') NOT NULL DEFAULT 'borrador',
    `datos_extraidos`  LONGTEXT        DEFAULT NULL,
    `cantidad_ordenes` INT UNSIGNED    NOT NULL DEFAULT 0,
    `confirmada_at`    TIMESTAMP       NULL DEFAULT NULL,
    `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_estado` (`estado`),
    INDEX `idx_usuario` (`usuario_id`),
    CONSTRAINT `fk_cargas_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- ordenes — la orden (remito). `nro_orden` es el identificador público que va
-- al QR y al seguimiento. `estado` es derivado de los ítems (se recalcula al
-- transicionar) y se guarda denormalizado para filtrar rápido en Reportes.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ordenes` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `carga_id`         INT UNSIGNED    DEFAULT NULL,
    `nro_orden`        VARCHAR(30)     NOT NULL,
    `nro_remito`       VARCHAR(30)     DEFAULT NULL,
    `fecha_remito`     DATE            DEFAULT NULL,
    `tipo_venta`       ENUM('local','online') DEFAULT NULL,
    `cliente`          VARCHAR(150)    NOT NULL,
    `cliente_apellido` VARCHAR(100)    DEFAULT NULL,
    `telefonos`        VARCHAR(120)    DEFAULT NULL,
    `dest_provincia`   VARCHAR(80)     DEFAULT NULL,
    `dest_localidad`   VARCHAR(120)    DEFAULT NULL,
    `dest_domicilio`   VARCHAR(200)    DEFAULT NULL,
    `dest_cp`          VARCHAR(10)     DEFAULT NULL,
    `valor_declarado`  DECIMAL(12,2)   DEFAULT NULL,
    `m3_total`         DECIMAL(8,2)    DEFAULT NULL,
    `estado`           VARCHAR(20)     NOT NULL DEFAULT 'RECIBIDO',
    `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nro_orden` (`nro_orden`),
    INDEX `idx_provincia` (`dest_provincia`),
    INDEX `idx_estado` (`estado`),
    INDEX `idx_tipo_venta` (`tipo_venta`),
    INDEX `idx_fecha_remito` (`fecha_remito`),
    INDEX `idx_carga` (`carga_id`),
    CONSTRAINT `fk_ordenes_carga`
        FOREIGN KEY (`carga_id`) REFERENCES `cargas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- productos — columnas nuevas (todas nullable: los productos creados por el
-- escaneo legacy quedan intactos). Cada ítem físico de una orden es un producto
-- con codigo = nro_orden-NN, su descripción tabulada y su "ítem X de N".
-- -----------------------------------------------------------------------------
ALTER TABLE `productos`
    ADD COLUMN `orden_id`    INT UNSIGNED      DEFAULT NULL AFTER `categoria_id`,
    ADD COLUMN `descripcion` VARCHAR(150)      DEFAULT NULL AFTER `orden_id`,
    ADD COLUMN `dimensiones` VARCHAR(40)       DEFAULT NULL AFTER `descripcion`,
    ADD COLUMN `m3`          DECIMAL(6,2)      DEFAULT NULL AFTER `dimensiones`,
    ADD COLUMN `secuencia`   SMALLINT UNSIGNED DEFAULT NULL AFTER `m3`,
    ADD INDEX `idx_orden` (`orden_id`),
    ADD CONSTRAINT `fk_productos_orden`
        FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`);

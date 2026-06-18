-- =============================================================================
-- 001_schema.sql — Trazock full schema creation
-- All 10 tables in FK-safe dependency order.
-- Run on a clean database: mysql -u root trazock < sql/migrations/001_schema.sql
-- =============================================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- -----------------------------------------------------------------------------
-- 1. usuarios
-- No FK dependencies. Referenced by: lotes, transiciones, conflictos_producto.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id`              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `usuario`         VARCHAR(50)     NOT NULL,
    `password_hash`   VARCHAR(255)    NOT NULL,
    `nombre_completo` VARCHAR(150)    NOT NULL,
    `rol`             ENUM('admin','gestor','operador','transportista') NOT NULL,
    `activo`          TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_usuario` (`usuario`),
    INDEX `idx_rol_activo` (`rol`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. categorias
-- No FK dependencies. Referenced by: productos, lotes.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `categorias` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nombre`     VARCHAR(100)    NOT NULL,
    `notas`      TEXT            DEFAULT NULL,
    `activo`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. proveedores
-- No FK dependencies. Referenced by: lotes.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `proveedores` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nombre`     VARCHAR(150)    NOT NULL,
    `contacto`   VARCHAR(150)    DEFAULT NULL,
    `notas`      TEXT            DEFAULT NULL,
    `activo`     TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 4. motivos
-- No FK dependencies. Referenced by: lotes.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `motivos` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nombre`        VARCHAR(100)    NOT NULL,
    `tipo`          ENUM('reingreso','devolucion','baja') NOT NULL,
    `editable_libre` TINYINT(1)     NOT NULL DEFAULT 0,
    `activo`        TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    INDEX `idx_tipo_activo` (`tipo`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. productos
-- FK: categoria_id → categorias(id)
-- Self-referential FK: transicion_actual_id → transiciones(id) — added after
-- transiciones is created (FK deferred; set to NULL initially).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `productos` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `codigo`              VARCHAR(100)    NOT NULL,
    `categoria_id`        INT UNSIGNED    DEFAULT NULL,
    `estado_actual`       ENUM('INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NOT NULL,
    `tiene_conflicto`     TINYINT(1)      NOT NULL DEFAULT 0,
    `transicion_actual_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_codigo` (`codigo`),
    INDEX `idx_estado_actual` (`estado_actual`),
    INDEX `idx_categoria_estado` (`categoria_id`, `estado_actual`),
    INDEX `idx_tiene_conflicto` (`tiene_conflicto`),
    CONSTRAINT `fk_productos_categoria`
        FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 6. lotes
-- FKs: categoria_id → categorias, proveedor_id → proveedores,
--      transportista_id → usuarios, motivo_id → motivos,
--      responsable_id → usuarios (NOT NULL)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lotes` (
    `id`                INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36)        NOT NULL,
    `tipo`              ENUM('INGRESO','SALIDA_REPARTO','ENTREGA','REINGRESO','SALIDA_DEVOLUCION','BAJA') NOT NULL,
    `categoria_id`      INT UNSIGNED    DEFAULT NULL,
    `proveedor_id`      INT UNSIGNED    DEFAULT NULL,
    `transportista_id`  INT UNSIGNED    DEFAULT NULL,
    `motivo_id`         INT UNSIGNED    DEFAULT NULL,
    `motivo_libre`      VARCHAR(255)    DEFAULT NULL,
    `responsable_id`    INT UNSIGNED    NOT NULL,
    `observaciones`     TEXT            DEFAULT NULL,
    `numero_remito`     VARCHAR(100)    DEFAULT NULL,
    `timestamp_apertura` TIMESTAMP      DEFAULT NULL,
    `timestamp_cierre`  TIMESTAMP       DEFAULT NULL,
    `timestamp_sync`    TIMESTAMP       DEFAULT NULL,
    `dispositivo_info`  VARCHAR(255)    DEFAULT NULL,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_uuid` (`uuid`),
    INDEX `idx_tipo_cierre` (`tipo`, `timestamp_cierre`),
    INDEX `idx_responsable` (`responsable_id`),
    CONSTRAINT `fk_lotes_categoria`
        FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
    CONSTRAINT `fk_lotes_proveedor`
        FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`),
    CONSTRAINT `fk_lotes_transportista`
        FOREIGN KEY (`transportista_id`) REFERENCES `usuarios` (`id`),
    CONSTRAINT `fk_lotes_motivo`
        FOREIGN KEY (`motivo_id`) REFERENCES `motivos` (`id`),
    CONSTRAINT `fk_lotes_responsable`
        FOREIGN KEY (`responsable_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 7. transiciones
-- FKs: producto_id → productos, lote_id → lotes (NULL allowed),
--      ajustado_por → usuarios (NULL allowed)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `transiciones` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `producto_id`       INT UNSIGNED    NOT NULL,
    `lote_id`           INT UNSIGNED    DEFAULT NULL,
    `estado_desde`      ENUM('INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') DEFAULT NULL,
    `estado_hasta`      ENUM('INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NOT NULL,
    `timestamp_cliente` TIMESTAMP       DEFAULT NULL,
    `timestamp_server`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `es_conflicto`      TINYINT(1)      NOT NULL DEFAULT 0,
    `motivo_conflicto`  VARCHAR(50)     DEFAULT NULL,
    `es_ajuste_manual`  TINYINT(1)      NOT NULL DEFAULT 0,
    `ajustado_por`      INT UNSIGNED    DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_producto_ts` (`producto_id`, `timestamp_cliente`),
    INDEX `idx_lote` (`lote_id`),
    INDEX `idx_es_conflicto` (`es_conflicto`),
    CONSTRAINT `fk_transiciones_producto`
        FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
    CONSTRAINT `fk_transiciones_lote`
        FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`),
    CONSTRAINT `fk_transiciones_ajustado_por`
        FOREIGN KEY (`ajustado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Now that transiciones exists, add the deferred FK from productos.
ALTER TABLE `productos`
    ADD CONSTRAINT `fk_productos_transicion_actual`
        FOREIGN KEY (`transicion_actual_id`) REFERENCES `transiciones` (`id`);

-- -----------------------------------------------------------------------------
-- 8. lote_items
-- FKs: lote_id → lotes (NOT NULL), transicion_id → transiciones (NULL allowed)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lote_items` (
    `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lote_id`          INT UNSIGNED    NOT NULL,
    `codigo_escaneado` VARCHAR(100)    NOT NULL,
    `timestamp_cliente` TIMESTAMP      DEFAULT NULL,
    `transicion_id`    BIGINT UNSIGNED DEFAULT NULL,
    `resultado`        VARCHAR(30)     NOT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_lote_id` (`lote_id`),
    CONSTRAINT `fk_lote_items_lote`
        FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`),
    CONSTRAINT `fk_lote_items_transicion`
        FOREIGN KEY (`transicion_id`) REFERENCES `transiciones` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 9. conflictos_producto
-- FKs: producto_id → productos, transicion_id → transiciones,
--      lote_id → lotes (NULL), revisado_por → usuarios (NULL)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `conflictos_producto` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `producto_id`      INT UNSIGNED    NOT NULL,
    `transicion_id`    BIGINT UNSIGNED NOT NULL,
    `lote_id`          INT UNSIGNED    DEFAULT NULL,
    `tipo`             VARCHAR(50)     NOT NULL,
    `descripcion`      TEXT            NOT NULL,
    `fecha_generacion` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revisado_por`     INT UNSIGNED    DEFAULT NULL,
    `revisado_at`      TIMESTAMP       DEFAULT NULL,
    `nota_resolucion`  TEXT            DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_producto_id` (`producto_id`),
    INDEX `idx_revisado_at` (`revisado_at`),
    CONSTRAINT `fk_conflictos_producto`
        FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
    CONSTRAINT `fk_conflictos_transicion`
        FOREIGN KEY (`transicion_id`) REFERENCES `transiciones` (`id`),
    CONSTRAINT `fk_conflictos_lote`
        FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`),
    CONSTRAINT `fk_conflictos_revisado_por`
        FOREIGN KEY (`revisado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 10. intentos_login
-- No FK dependencies (intentional — log must survive if a user is deleted).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `intentos_login` (
    `id`      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip`      VARCHAR(45)     NOT NULL,
    `usuario` VARCHAR(50)     DEFAULT NULL,
    `exito`   TINYINT(1)      NOT NULL DEFAULT 0,
    `fecha`   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ip_usuario_fecha` (`ip`, `usuario`, `fecha`),
    INDEX `idx_fecha` (`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;

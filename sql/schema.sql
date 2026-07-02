-- =============================================================================
-- sql/schema.sql — Trazock single-shot install
--
-- This file is a literal concatenation of:
--   sql/migrations/001_schema.sql  (all 10 tables)
--   sql/migrations/002_seed_admin.sql (admin seed user)
--
-- Usage (fresh database):
--   mysql -u root trazock < sql/schema.sql
--
-- For incremental migrations, apply files under sql/migrations/ in numeric order.
-- This file contains no SOURCE directives — it is self-contained.
-- =============================================================================


-- =============================================================================
-- BEGIN: 001_schema.sql
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
    `rol`             ENUM('admin','gestor','operador','transportista','logistica','contable') NOT NULL,
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
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `nombre`         VARCHAR(100)    NOT NULL,
    `tipo`           ENUM('reingreso','devolucion','baja') NOT NULL,
    `editable_libre` TINYINT(1)      NOT NULL DEFAULT 0,
    `activo`         TINYINT(1)      NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    INDEX `idx_tipo_activo` (`tipo`, `activo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 5. productos
-- FK: categoria_id → categorias(id)
-- Self-referential FK: transicion_actual_id → transiciones(id) added after
-- transiciones is created.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `productos` (
    `id`                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `codigo`               VARCHAR(100)    NOT NULL,
    `categoria_id`         INT UNSIGNED    DEFAULT NULL,
    `estado_actual`        ENUM('INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NOT NULL,
    `tiene_conflicto`      TINYINT(1)      NOT NULL DEFAULT 0,
    `transicion_actual_id` BIGINT UNSIGNED DEFAULT NULL,
    `created_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
    `id`                 INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `uuid`               CHAR(36)        NOT NULL,
    `tipo`               ENUM('INGRESO','SALIDA_REPARTO','ENTREGA','REINGRESO','SALIDA_DEVOLUCION','BAJA') NOT NULL,
    `categoria_id`       INT UNSIGNED    DEFAULT NULL,
    `proveedor_id`       INT UNSIGNED    DEFAULT NULL,
    `transportista_id`   INT UNSIGNED    DEFAULT NULL,
    `vehiculo`           VARCHAR(80)     DEFAULT NULL,
    `chofer`             VARCHAR(120)    DEFAULT NULL,
    `ayudantes`          VARCHAR(200)    DEFAULT NULL,
    `motivo_id`          INT UNSIGNED    DEFAULT NULL,
    `motivo_libre`       VARCHAR(255)    DEFAULT NULL,
    `responsable_id`     INT UNSIGNED    NOT NULL,
    `observaciones`      TEXT            DEFAULT NULL,
    `numero_remito`      VARCHAR(100)    DEFAULT NULL,
    `timestamp_apertura` TIMESTAMP       NULL DEFAULT NULL,
    `timestamp_cierre`   TIMESTAMP       NULL DEFAULT NULL,
    `timestamp_sync`     TIMESTAMP       NULL DEFAULT NULL,
    `dispositivo_info`   VARCHAR(255)    DEFAULT NULL,
    `created_at`         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
    `timestamp_cliente` TIMESTAMP       NULL DEFAULT NULL,
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

-- Deferred FK: productos.transicion_actual_id → transiciones(id)
ALTER TABLE `productos`
    ADD CONSTRAINT `fk_productos_transicion_actual`
        FOREIGN KEY (`transicion_actual_id`) REFERENCES `transiciones` (`id`);

-- -----------------------------------------------------------------------------
-- 8. lote_items
-- FKs: lote_id → lotes (NOT NULL), transicion_id → transiciones (NULL allowed)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lote_items` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lote_id`           INT UNSIGNED    NOT NULL,
    `codigo_escaneado`  VARCHAR(100)    NOT NULL,
    `timestamp_cliente` TIMESTAMP       NULL DEFAULT NULL,
    `transicion_id`     BIGINT UNSIGNED DEFAULT NULL,
    `resultado`         VARCHAR(30)     NOT NULL,
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
    `revisado_at`      TIMESTAMP       NULL DEFAULT NULL,
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

-- =============================================================================
-- END: 001_schema.sql
-- =============================================================================


-- =============================================================================
-- BEGIN: 002_seed_admin.sql
-- =============================================================================

-- usuario:         admin
-- password (dev):  admin123
--                  *** ROTATE THIS PASSWORD IMMEDIATELY AFTER INSTALL ***
--                  This is a development-only credential. In production, run:
--                    php scripts/crear-admin.php admin "Administrador" <strong-pass> admin
--                  then delete this seed row if you prefer.
--
-- Hash generated with: php -r "echo password_hash('admin123', PASSWORD_DEFAULT);"
-- Algorithm: bcrypt (PHP PASSWORD_DEFAULT), cost 10.
-- Verified: password_verify('admin123', hash) === true

INSERT INTO `usuarios` (`usuario`, `password_hash`, `nombre_completo`, `rol`, `activo`)
VALUES (
    'admin',
    '$2y$10$/xueCFE8jRQcfT98gjJ71.gcjAog0zSwm6zNLdIFaJAt3ONyHfYVS',
    'Administrador',
    'admin',
    1
);

-- =============================================================================
-- END: 002_seed_admin.sql
-- =============================================================================


-- =============================================================================
-- BEGIN: 003_motivo_multitipo.sql
-- =============================================================================

ALTER TABLE `motivos`
    MODIFY `tipo` SET('reingreso','devolucion','baja') NOT NULL;

-- =============================================================================
-- END: 003_motivo_multitipo.sql
-- =============================================================================


-- =============================================================================
-- BEGIN: 004_seguimiento_publico.sql
-- =============================================================================

ALTER TABLE `productos`
    ADD COLUMN `token_publico` CHAR(32) DEFAULT NULL AFTER `transicion_actual_id`,
    ADD UNIQUE KEY `uq_token_publico` (`token_publico`);

CREATE TABLE IF NOT EXISTS `estados_publicos` (
    `estado`      ENUM('EN_TRANSITO','INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NOT NULL,
    `titulo`      VARCHAR(150)  NOT NULL,
    `descripcion` VARCHAR(500)  NOT NULL DEFAULT '',
    `visible`     TINYINT(1)    NOT NULL DEFAULT 1,
    `orden`       SMALLINT      NOT NULL DEFAULT 0,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `estados_publicos` (`estado`, `titulo`, `descripcion`, `visible`, `orden`) VALUES
    ('EN_TRANSITO', 'En camino a nuestro centro de distribución', 'Tu pedido fue despachado hacia nuestro depósito.',                          1, 1),
    ('INGRESADO',   'Recibimos tu producto',     'Tu producto llegó a nuestro centro de distribución y lo estamos preparando para el envío.', 1, 2),
    ('EN_REPARTO',  'En camino a tu domicilio',  'Tu producto salió de nuestro depósito y está en viaje hacia tu domicilio.',                 1, 3),
    ('ENTREGADO',   '¡Entregado!',               'Tu producto fue entregado. ¡Gracias por tu compra!',                                        1, 4),
    ('REINGRESADO', 'Producto en revisión',      'Tu producto volvió a nuestro depósito y lo estamos gestionando. Pronto te contactamos.',    0, 0),
    ('DEVUELTO',    'Producto devuelto',         'Tu producto fue devuelto. Si tenés alguna duda, no dudes en contactarnos.',                 0, 0),
    ('BAJA',        'Pedido finalizado',         'Este pedido ya no se encuentra activo. Si tenés alguna duda, contactanos.',                 0, 0);

-- =============================================================================
-- END: 004_seguimiento_publico.sql
-- =============================================================================


-- =============================================================================
-- BEGIN: 005_ordenes.sql
-- =============================================================================

CREATE TABLE IF NOT EXISTS `cargas` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `usuario_id`       INT UNSIGNED    NOT NULL,
    `categoria_id`     INT UNSIGNED    DEFAULT NULL,
    `fecha`            DATE            DEFAULT NULL,
    `estado`           ENUM('borrador','confirmada') NOT NULL DEFAULT 'borrador',
    `datos_extraidos`  LONGTEXT        DEFAULT NULL,
    `cantidad_ordenes` INT UNSIGNED    NOT NULL DEFAULT 0,
    `confirmada_at`    TIMESTAMP       NULL DEFAULT NULL,
    `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_estado` (`estado`),
    INDEX `idx_usuario` (`usuario_id`),
    INDEX `idx_carga_categoria` (`categoria_id`),
    CONSTRAINT `fk_cargas_usuario`
        FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
    CONSTRAINT `fk_cargas_categoria`
        FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ordenes` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `carga_id`         INT UNSIGNED    DEFAULT NULL,
    `nro_orden`        VARCHAR(30)     NOT NULL,
    `nro_remito`       VARCHAR(30)     DEFAULT NULL,
    `hoja_ruta`        VARCHAR(50)     DEFAULT NULL,
    `transportista_id` INT UNSIGNED    DEFAULT NULL,
    `fecha_carga`      DATE            DEFAULT NULL,
    `fecha_remito`     DATE            DEFAULT NULL,
    `tipo_venta`       ENUM('local','online') DEFAULT NULL,
    `cliente`          VARCHAR(150)    NOT NULL,
    `cliente_apellido` VARCHAR(100)    DEFAULT NULL,
    `telefonos`        VARCHAR(120)    DEFAULT NULL,
    `telefono_wa`      VARCHAR(20)     DEFAULT NULL,
    `dest_provincia`   VARCHAR(80)     DEFAULT NULL,
    `dest_localidad`   VARCHAR(120)    DEFAULT NULL,
    `dest_domicilio`   VARCHAR(200)    DEFAULT NULL,
    `dest_cp`          VARCHAR(10)     DEFAULT NULL,
    `valor_declarado`  DECIMAL(12,2)   DEFAULT NULL,
    `observaciones`    VARCHAR(1000)   DEFAULT NULL,
    `marca`            ENUM('no_entregar','prioridad') DEFAULT NULL,
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
    INDEX `idx_orden_hoja_ruta` (`hoja_ruta`),
    INDEX `idx_orden_transportista` (`transportista_id`),
    INDEX `idx_orden_fecha_carga` (`fecha_carga`),
    INDEX `idx_orden_marca` (`marca`),
    CONSTRAINT `fk_ordenes_carga`
        FOREIGN KEY (`carga_id`) REFERENCES `cargas` (`id`),
    CONSTRAINT `fk_ordenes_transportista`
        FOREIGN KEY (`transportista_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `productos`
    ADD COLUMN `orden_id`    INT UNSIGNED      DEFAULT NULL AFTER `categoria_id`,
    ADD COLUMN `descripcion` VARCHAR(150)      DEFAULT NULL AFTER `orden_id`,
    ADD COLUMN `dimensiones` VARCHAR(40)       DEFAULT NULL AFTER `descripcion`,
    ADD COLUMN `m3`          DECIMAL(6,2)      DEFAULT NULL AFTER `dimensiones`,
    ADD COLUMN `secuencia`   SMALLINT UNSIGNED DEFAULT NULL AFTER `m3`,
    ADD INDEX `idx_orden` (`orden_id`),
    ADD CONSTRAINT `fk_productos_orden`
        FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`);

-- =============================================================================
-- END: 005_ordenes.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 006_etiquetas.sql — marca de "etiqueta impresa" por ítem (ortogonal a
-- la máquina de estados; la badge "ETIQUETADA" del panel se deriva de él).
-- =============================================================================
ALTER TABLE `productos`
    ADD COLUMN `etiquetada_at` TIMESTAMP NULL DEFAULT NULL AFTER `secuencia`,
    ADD INDEX `idx_etiquetada` (`etiquetada_at`);
-- =============================================================================
-- END: 006_etiquetas.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 007_zonas.sql — zonas de reparto (agrupan localidades de destino; el
-- escáner valida cada QR de SALIDA_REPARTO contra la zona elegida). ciudad NULL
-- en una localidad = "toda la provincia".
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
-- =============================================================================
-- END: 007_zonas.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 010_encuestas.sql — encuesta de satisfacción del comprador contra
-- entrega (1 por orden; se responde desde el seguimiento cuando está ENTREGADO).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `encuestas` (
    `id`         INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `orden_id`   INT UNSIGNED     NOT NULL,
    `general`    TINYINT UNSIGNED NOT NULL,
    `tiempo`     TINYINT UNSIGNED NOT NULL,
    `paquete`    TINYINT UNSIGNED NOT NULL,
    `trato`      TINYINT UNSIGNED NOT NULL,
    `comentario` VARCHAR(1000)    DEFAULT NULL,
    `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_encuesta_orden` (`orden_id`),
    INDEX `idx_encuesta_general` (`general`),
    INDEX `idx_encuesta_fecha` (`created_at`),
    CONSTRAINT `fk_encuestas_orden`
        FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- END: 010_encuestas.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 014_acompanantes_vehiculos.sql — catálogos del reparto (desplegables
-- de acompañante/ayudante y vehículo/unidad en la app de escaneo).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `acompanantes` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `nombre`      VARCHAR(120)  NOT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `es_chofer_ld` TINYINT(1)   NOT NULL DEFAULT 0,
    `es_chofer_cd` TINYINT(1)   NOT NULL DEFAULT 0,
    `es_ayudante`  TINYINT(1)   NOT NULL DEFAULT 0,
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
-- =============================================================================
-- END: 014_acompanantes_vehiculos.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 016_facturacion_m1.sql — pre-factura por marca (categoria.proveedor_id,
-- datos fiscales receptor/emisor, tarifario por provincia+tipo).
-- =============================================================================
ALTER TABLE `categorias`
    ADD COLUMN `proveedor_id` INT UNSIGNED DEFAULT NULL AFTER `nombre`,
    ADD CONSTRAINT `fk_categorias_proveedor`
        FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`);

ALTER TABLE `proveedores`
    ADD COLUMN `razon_social`  VARCHAR(150) DEFAULT NULL AFTER `nombre`,
    ADD COLUMN `cuit`          VARCHAR(13)  DEFAULT NULL AFTER `razon_social`,
    ADD COLUMN `condicion_iva` VARCHAR(40)  DEFAULT NULL AFTER `cuit`,
    ADD COLUMN `domicilio`     VARCHAR(200) DEFAULT NULL AFTER `condicion_iva`;

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
-- (La tabla `tarifas` se creó en 016 y se eliminó en 022; no se incluye acá.)
-- =============================================================================
-- END: 016_facturacion_m1.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 017_viaje_links.sql — lote.vehiculo_id + pivote lote_ayudantes (para
-- reportar movimientos por vehículo/persona). Nombres quedan como snapshot.
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
-- =============================================================================
-- END: 017_viaje_links.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 018_costos.sql — costos por viaje (lote) y por vehículo.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `costos_viaje` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `lote_id`     INT UNSIGNED  NOT NULL,
    `tipo`        ENUM('combustible','permiso','viatico','otro') NOT NULL,
    `importe`     DECIMAL(12,2) NOT NULL,
    `fecha`       DATE          DEFAULT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `creado_por`  INT UNSIGNED  DEFAULT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cv_lote` (`lote_id`),
    INDEX `idx_cv_fecha` (`fecha`),
    CONSTRAINT `fk_cv_lote`    FOREIGN KEY (`lote_id`)    REFERENCES `lotes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cv_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `costos_vehiculo` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `vehiculo_id` INT UNSIGNED  NOT NULL,
    `tipo`        ENUM('mantenimiento','reparacion','otro') NOT NULL,
    `importe`     DECIMAL(12,2) NOT NULL,
    `fecha`       DATE          DEFAULT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `creado_por`  INT UNSIGNED  DEFAULT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cveh_vehiculo` (`vehiculo_id`),
    INDEX `idx_cveh_fecha` (`fecha`),
    CONSTRAINT `fk_cveh_vehiculo` FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`),
    CONSTRAINT `fk_cveh_usuario`  FOREIGN KEY (`creado_por`)  REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- END: 018_costos.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 019_facturacion_cliente.sql — config de facturación por cliente (marca)
-- + precios por destino. Insumo del reporte de resultados.
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
-- =============================================================================
-- END: 019_facturacion_cliente.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 020_cliente_precio_vigencia.sql — precio por cliente con vigencia por
-- fecha (provincia = '' → precio único). Reemplaza el uso de precio_unico /
-- cliente_tarifa_destino (que quedan sin uso).
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
-- =============================================================================
-- END: 020_cliente_precio_vigencia.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 021_costos_fijos.sql — costos fijos mensuales (alquiler/sueldo/otro),
-- prorrateados por días al período del reporte de Resultados.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `costos_fijos` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `tipo`        ENUM('alquiler','sueldo','otro') NOT NULL,
    `concepto`    VARCHAR(150)  NOT NULL,
    `importe`     DECIMAL(12,2) NOT NULL,
    `periodo`     CHAR(7)       NOT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `creado_por`  INT UNSIGNED  DEFAULT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cfij_periodo` (`periodo`),
    CONSTRAINT `fk_cfij_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- END: 021_costos_fijos.sql
-- =============================================================================
-- (022 dropea `tarifas`; 023 amplía el enum usuarios.rol — reflejados arriba.)

-- =============================================================================
-- BEGIN: 024_caja_chica.sql — caja chica (contable): ingresos/egresos/adelantos.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `caja_chica` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `tipo`        ENUM('ingreso','egreso','adelanto_chofer','rendicion') NOT NULL,
    `monto`       DECIMAL(12,2) NOT NULL,
    `fecha`       DATE          NOT NULL,
    `concepto`    VARCHAR(150)  NOT NULL,
    `chofer_id`   INT UNSIGNED  DEFAULT NULL,
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `creado_por`  INT UNSIGNED  DEFAULT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cc_fecha` (`fecha`),
    CONSTRAINT `fk_cc_chofer`  FOREIGN KEY (`chofer_id`)  REFERENCES `usuarios` (`id`),
    CONSTRAINT `fk_cc_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- END: 024_caja_chica.sql
-- =============================================================================
-- (025 suma conductores al padrón `acompanantes` — solo datos.)

-- =============================================================================
-- BEGIN: 026_lote_conductor_empleado.sql — conductor del viaje desde el padrón.
-- =============================================================================
ALTER TABLE `lotes`
    ADD COLUMN `conductor_empleado_id` INT UNSIGNED DEFAULT NULL AFTER `transportista_id`,
    ADD CONSTRAINT `fk_lotes_conductor_emp`
        FOREIGN KEY (`conductor_empleado_id`) REFERENCES `acompanantes` (`id`);
-- =============================================================================
-- END: 026_lote_conductor_empleado.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 027_confirmaciones_entrega.sql — aviso de entrega por WhatsApp + respuesta.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `confirmaciones_entrega` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `orden_id`       INT UNSIGNED NOT NULL,
    `estado`         ENUM('enviado','confirmado','reprogramado','error') NOT NULL DEFAULT 'enviado',
    `fecha_entrega`  DATE         DEFAULT NULL,
    `horario`        VARCHAR(40)  DEFAULT NULL,
    `telefono`       VARCHAR(30)  DEFAULT NULL,
    `wa_message_id`  VARCHAR(80)  DEFAULT NULL,
    `error`          VARCHAR(255) DEFAULT NULL,
    `enviado_por`    INT UNSIGNED DEFAULT NULL,
    `enviado_at`     TIMESTAMP    NULL DEFAULT NULL,
    `respondido_at`  TIMESTAMP    NULL DEFAULT NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_confent_orden` (`orden_id`),
    INDEX `idx_confent_estado` (`estado`),
    INDEX `idx_confent_wamsg` (`wa_message_id`),
    INDEX `idx_confent_fecha` (`fecha_entrega`),
    CONSTRAINT `fk_confent_orden`
        FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_confent_usuario`
        FOREIGN KEY (`enviado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- END: 027_confirmaciones_entrega.sql
-- =============================================================================
-- (028 agrega ordenes.telefono_wa — ya incluido en la tabla `ordenes` de arriba.)

-- =============================================================================
-- BEGIN: 029_entrega_remitos.sql — fotos de remitos firmados de las entregas.
-- =============================================================================
CREATE TABLE IF NOT EXISTS `entrega_remitos` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `foto_uuid`  CHAR(36)     NOT NULL,
    `lote_uuid`  CHAR(36)     NOT NULL,
    `lote_id`    INT UNSIGNED DEFAULT NULL,
    `archivo`    VARCHAR(160) NOT NULL,
    `mime`       VARCHAR(40)  NOT NULL DEFAULT 'image/jpeg',
    `bytes`      INT UNSIGNED NOT NULL DEFAULT 0,
    `sha256`     CHAR(64)     DEFAULT NULL,
    `subido_por` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_remito_foto` (`foto_uuid`),
    INDEX `idx_remito_lote_uuid` (`lote_uuid`),
    INDEX `idx_remito_lote_id` (`lote_id`),
    CONSTRAINT `fk_remito_lote`
        FOREIGN KEY (`lote_id`) REFERENCES `lotes` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_remito_usuario`
        FOREIGN KEY (`subido_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- END: 029_entrega_remitos.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 030_prefijos.sql — nombres de prefijo + acceso público por local (token).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `prefijos` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `prefijo`        VARCHAR(40)  NOT NULL,
    `nombre_interno` VARCHAR(120) NOT NULL,
    `nombre_publico` VARCHAR(150) DEFAULT NULL,
    `nombre_cliente` VARCHAR(150) DEFAULT NULL,
    `token`          CHAR(32)     DEFAULT NULL,
    `activo`         TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_prefijo` (`prefijo`),
    UNIQUE KEY `uq_prefijo_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- =============================================================================
-- END: 030_prefijos.sql
-- =============================================================================
-- (031 agrega prefijos.nombre_cliente — ya incluido en la tabla `prefijos` de arriba.)

-- =============================================================================
-- BEGIN: 032_hojas_ruta.sql — hoja de ruta de reparto (armada por logística).
-- =============================================================================
CREATE TABLE IF NOT EXISTS `hojas_ruta` (
    `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `numero`                VARCHAR(20)  DEFAULT NULL,
    `fecha`                 DATE         DEFAULT NULL,
    `conductor_empleado_id` INT UNSIGNED DEFAULT NULL,
    `conductor`             VARCHAR(120) DEFAULT NULL,
    `vehiculo_id`           INT UNSIGNED DEFAULT NULL,
    `vehiculo`              VARCHAR(120) DEFAULT NULL,
    `ayudantes`             VARCHAR(255) DEFAULT NULL,
    `destino`               VARCHAR(150) DEFAULT NULL,
    `observaciones`         VARCHAR(600) DEFAULT NULL,
    `estado`                ENUM('abierta','emitida') NOT NULL DEFAULT 'abierta',
    `creado_por`            INT UNSIGNED DEFAULT NULL,
    `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hoja_numero` (`numero`),
    INDEX `idx_hoja_estado` (`estado`),
    CONSTRAINT `fk_hoja_conductor` FOREIGN KEY (`conductor_empleado_id`) REFERENCES `acompanantes` (`id`),
    CONSTRAINT `fk_hoja_vehiculo`  FOREIGN KEY (`vehiculo_id`) REFERENCES `vehiculos` (`id`),
    CONSTRAINT `fk_hoja_usuario`   FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hoja_ruta_ordenes` (
    `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hoja_id`  INT UNSIGNED NOT NULL,
    `orden_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_hoja_orden` (`hoja_id`, `orden_id`),
    INDEX `idx_hro_orden` (`orden_id`),
    CONSTRAINT `fk_hro_hoja`  FOREIGN KEY (`hoja_id`)  REFERENCES `hojas_ruta` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_hro_orden` FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `hoja_ruta_manual` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hoja_id`         INT UNSIGNED NOT NULL,
    `cliente_origen`  VARCHAR(120) DEFAULT NULL,
    `nro_orden`       VARCHAR(60)  DEFAULT NULL,
    `cliente_destino` VARCHAR(150) DEFAULT NULL,
    `localidad`       VARCHAR(150) DEFAULT NULL,
    `bultos`          INT UNSIGNED DEFAULT NULL,
    `m3`              DECIMAL(10,3) DEFAULT NULL,
    `telefono`        VARCHAR(60)  DEFAULT NULL,
    `observacion`     VARCHAR(255) DEFAULT NULL,
    `posicion`        INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_hrm_hoja` (`hoja_id`),
    CONSTRAINT `fk_hrm_hoja` FOREIGN KEY (`hoja_id`) REFERENCES `hojas_ruta` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `lotes`
    ADD COLUMN `hoja_ruta_id` INT UNSIGNED DEFAULT NULL AFTER `conductor_empleado_id`,
    ADD CONSTRAINT `fk_lotes_hoja_ruta` FOREIGN KEY (`hoja_ruta_id`) REFERENCES `hojas_ruta` (`id`);
-- =============================================================================
-- END: 032_hojas_ruta.sql
-- =============================================================================

-- =============================================================================
-- BEGIN: 034_geocoding_rutas.sql — secuenciación de rutas (feature D), fase 1.
-- Tablas nuevas (aditivo): caché de geocoding + orden de paradas por hoja.
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
-- =============================================================================
-- END: 034_geocoding_rutas.sql
-- =============================================================================

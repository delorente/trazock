-- =============================================================================
-- 030_prefijos.sql — nombres de prefijo de Nº de orden + acceso público por local.
--
-- El prefijo es la parte del nro_orden anterior al primer '-' (ej. 0775-123456 →
-- 0775). Identifica el origen de la venta: 0775 = Ventas Online, el resto = el
-- local donde se compró. Acá se le asigna:
--   - nombre_interno: para el filtro del panel (Reportes).
--   - nombre_publico: el que ve el local en su listado por token.
--   - token: acceso público reseteable al listado de SUS órdenes (NULL = sin acceso).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `prefijos` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `prefijo`        VARCHAR(40)  NOT NULL,
    `nombre_interno` VARCHAR(120) NOT NULL,
    `nombre_publico` VARCHAR(150) DEFAULT NULL,
    `token`          CHAR(32)     DEFAULT NULL,
    `activo`         TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_prefijo` (`prefijo`),
    UNIQUE KEY `uq_prefijo_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

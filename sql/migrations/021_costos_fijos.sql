-- =============================================================================
-- 021_costos_fijos.sql — costos fijos mensuales (alquileres, sueldos, otros).
--
-- Se cargan por mes (periodo 'YYYY-MM'). El reporte de Resultados los prorratea
-- por días al rango consultado y los resta al margen de contribución para dar el
-- resultado neto. (El componente por km de sueldos queda para más adelante.)
-- =============================================================================

CREATE TABLE IF NOT EXISTS `costos_fijos` (
    `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `tipo`        ENUM('alquiler','sueldo','otro') NOT NULL,
    `concepto`    VARCHAR(150)  NOT NULL,
    `importe`     DECIMAL(12,2) NOT NULL,
    `periodo`     CHAR(7)       NOT NULL,           -- 'YYYY-MM'
    `observacion` VARCHAR(255)  DEFAULT NULL,
    `creado_por`  INT UNSIGNED  DEFAULT NULL,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_cfij_periodo` (`periodo`),
    CONSTRAINT `fk_cfij_usuario` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

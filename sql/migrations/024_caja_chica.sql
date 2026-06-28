-- =============================================================================
-- 024_caja_chica.sql — caja chica (la maneja el contable).
--   tipo: ingreso / egreso / adelanto_chofer / rendicion.
--   ingreso y rendicion suman al saldo; egreso y adelanto_chofer restan.
--   chofer_id: solo para adelantos/rendiciones (a qué chofer).
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

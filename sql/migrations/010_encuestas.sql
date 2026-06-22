-- =============================================================================
-- 010_encuestas.sql — encuesta de satisfacción del comprador contra entrega.
--
-- Una encuesta por orden (UNIQUE orden_id): el comprador la responde desde el
-- link de seguimiento cuando su pedido está ENTREGADO. Califica 1-4 (Muy malo /
-- Regular / Bueno / Excelente) la experiencia general y tres aspectos (tiempo,
-- estado del paquete, trato del repartidor) y puede dejar un comentario libre.
-- Si ya respondió, el seguimiento no vuelve a mostrar la encuesta.
--
-- ON DELETE CASCADE: una orden solo se puede borrar si nunca se despachó (todos
-- sus ítems INGRESADO), así que en la práctica una orden con encuesta (ENTREGADO)
-- nunca se borra; el cascade queda por prolijidad referencial.
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

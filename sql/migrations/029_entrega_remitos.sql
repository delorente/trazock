-- =============================================================================
-- 029_entrega_remitos.sql — fotos de remitos firmados de las entregas.
--
-- El transportista saca una o varias fotos del remito firmado al confirmar una
-- ENTREGA en el scan. La imagen se guarda en el filesystem (carpeta REMITOS_DIR);
-- esta tabla guarda solo los metadatos. Se vincula al lote de entrega por su uuid
-- (la foto y el lote pueden sincronizar en cualquier orden desde el celular).
-- `foto_uuid` es idempotente: reintentos de subida no duplican.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `entrega_remitos` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `foto_uuid`  CHAR(36)     NOT NULL,
    `lote_uuid`  CHAR(36)     NOT NULL,
    `lote_id`    INT UNSIGNED DEFAULT NULL,   -- resuelto si el lote ya existe al subir
    `archivo`    VARCHAR(160) NOT NULL,        -- nombre del archivo en REMITOS_DIR
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

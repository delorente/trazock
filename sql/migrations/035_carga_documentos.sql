-- =============================================================================
-- 035_carga_documentos.sql — archiva los documentos (hojas resumen) importados.
--
-- Al procesar una carga por OCR se guarda el archivo original (imagen/PDF) en el
-- filesystem (carpeta DOCUMENTOS_DIR, o <proyecto>/storage/documentos); esta tabla
-- guarda solo los metadatos, ligados a la carga. `uuid` es idempotente. Al borrar
-- una carga (borrador descartado) se eliminan sus documentos (ON DELETE CASCADE).
-- =============================================================================

CREATE TABLE IF NOT EXISTS `carga_documentos` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `carga_id`   INT UNSIGNED NOT NULL,
    `uuid`       CHAR(36)     NOT NULL,
    `archivo`    VARCHAR(160) NOT NULL,        -- nombre del archivo en DOCUMENTOS_DIR
    `mime`       VARCHAR(40)  NOT NULL DEFAULT 'application/octet-stream',
    `bytes`      INT UNSIGNED NOT NULL DEFAULT 0,
    `sha256`     CHAR(64)     DEFAULT NULL,
    `subido_por` INT UNSIGNED DEFAULT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_cdoc_uuid` (`uuid`),
    INDEX `idx_cdoc_carga` (`carga_id`),
    CONSTRAINT `fk_cdoc_carga`
        FOREIGN KEY (`carga_id`) REFERENCES `cargas` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cdoc_usuario`
        FOREIGN KEY (`subido_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

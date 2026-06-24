-- =============================================================================
-- 011_hoja_ruta_transportista.sql — datos clave de ingreso por DOCUMENTO.
--
-- Cada documento (hoja resumen) que se carga trae:
--   - hoja_ruta:       Nº de hoja de ruta (HR) que identifica el documento (OCR).
--   - transportista_id: quién trajo la mercadería al depósito (rol transportista).
--   - fecha_carga:     fecha en que ingresó la mercadería (≤ hoy).
-- Como una carga puede incluir varios documentos (distintos HR/transportista/
-- fecha), estos tres datos se estampan POR ORDEN (cada orden hereda los de su
-- documento), no en la carga. Son obligatorios al confirmar (el HR se valida en
-- la revisión; transportista y fecha se piden al subir cada documento).
-- =============================================================================

ALTER TABLE `ordenes`
    ADD COLUMN `hoja_ruta`        VARCHAR(50)  DEFAULT NULL AFTER `nro_remito`,
    ADD COLUMN `transportista_id` INT UNSIGNED DEFAULT NULL AFTER `hoja_ruta`,
    ADD COLUMN `fecha_carga`      DATE         DEFAULT NULL AFTER `transportista_id`,
    ADD INDEX `idx_orden_hoja_ruta` (`hoja_ruta`),
    ADD INDEX `idx_orden_transportista` (`transportista_id`),
    ADD INDEX `idx_orden_fecha_carga` (`fecha_carga`),
    ADD CONSTRAINT `fk_ordenes_transportista`
        FOREIGN KEY (`transportista_id`) REFERENCES `usuarios` (`id`);

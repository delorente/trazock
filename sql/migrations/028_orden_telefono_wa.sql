-- =============================================================================
-- 028_orden_telefono_wa.sql — teléfono normalizado para WhatsApp.
--
-- `telefonos` queda LITERAL (lo que extrae el OCR, para llamar/mostrar). Este
-- campo nuevo guarda el número en E.164 sin '+' (549XXXXXXXXXX), derivado de
-- forma determinística (tel_e164) al importar la carga y editable en el detalle.
-- NULL/'' = no se pudo derivar un móvil argentino válido (revisar a mano).
-- =============================================================================

ALTER TABLE `ordenes`
    ADD COLUMN `telefono_wa` VARCHAR(20) DEFAULT NULL AFTER `telefonos`;

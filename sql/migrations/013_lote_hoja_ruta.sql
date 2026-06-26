-- =============================================================================
-- 013_lote_hoja_ruta.sql — datos del viaje para la HOJA DE RUTA de un lote de
-- SALIDA_REPARTO (se cargan en la app de escaneo al iniciar el reparto).
--
--   vehiculo:  unidad asignada (ej. "Daily", patente).
--   chofer:    nombre del conductor.
--   ayudantes: nombre(s) del/los ayudante(s) que salen (texto libre).
-- =============================================================================

ALTER TABLE `lotes`
    ADD COLUMN `vehiculo`  VARCHAR(80)  DEFAULT NULL AFTER `transportista_id`,
    ADD COLUMN `chofer`    VARCHAR(120) DEFAULT NULL AFTER `vehiculo`,
    ADD COLUMN `ayudantes` VARCHAR(200) DEFAULT NULL AFTER `chofer`;

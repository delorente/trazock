-- =============================================================================
-- 006_etiquetas.sql — marca de "etiqueta impresa" por ítem.
--
-- La impresión de la etiqueta con QR es un hito operativo (el ítem ya tiene su
-- rótulo físico pegado), ortogonal a la máquina de estados de trazabilidad
-- (INGRESADO → EN_REPARTO → …). Por eso NO se agrega a `estado_actual`: se modela
-- como un timestamp nullable. La badge "ETIQUETADA" del panel se deriva de él
-- (etiquetada_at IS NOT NULL && estado_actual = 'INGRESADO').
--
-- Nota MariaDB 10.3: TIMESTAMP nulo como NULL DEFAULT NULL (igual que la 005).
-- =============================================================================

ALTER TABLE `productos`
    ADD COLUMN `etiquetada_at` TIMESTAMP NULL DEFAULT NULL AFTER `secuencia`,
    ADD INDEX `idx_etiquetada` (`etiquetada_at`);

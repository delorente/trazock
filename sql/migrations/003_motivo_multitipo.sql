-- =============================================================================
-- 003_motivo_multitipo.sql — un motivo puede aplicar a más de un tipo de lote.
-- Cambia motivos.tipo de ENUM (un solo valor) a SET (varios valores).
-- Los valores existentes (un único tipo) quedan válidos como SET de un elemento.
-- =============================================================================

ALTER TABLE `motivos`
    MODIFY `tipo` SET('reingreso','devolucion','baja') NOT NULL;

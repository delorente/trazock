-- =============================================================================
-- 012_orden_observaciones_marca.sql — observaciones y marca operativa por orden.
--
--   observaciones: nota libre (detalles que pasa el cliente: no entregar tal
--                  pedido, priorizar otro, horarios, etc.).
--   marca:         marca operativa EXCLUYENTE para destacar en la planilla:
--                  'no_entregar' (🚫) o 'prioridad' (⚡); NULL = sin marca.
--                  Si una orden está 'no_entregar', el escáner avisa al intentar
--                  escanearla en reparto/entrega.
-- =============================================================================

ALTER TABLE `ordenes`
    ADD COLUMN `observaciones` VARCHAR(1000) DEFAULT NULL AFTER `valor_declarado`,
    ADD COLUMN `marca` ENUM('no_entregar','prioridad') DEFAULT NULL AFTER `observaciones`,
    ADD INDEX `idx_orden_marca` (`marca`);

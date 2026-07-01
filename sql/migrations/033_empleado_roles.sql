-- =============================================================================
-- 033_empleado_roles.sql — roles del empleado del padrón (para alimentar mejor
-- los desplegables): Chofer LD (larga distancia), Chofer CD (corta distancia),
-- Ayudante. Una persona puede tener varios roles.
--   - Chofer LD → conductor de la hoja de ruta de carga (INGRESO en el scan).
--   - Chofer CD → conductor de las hojas de ruta internas (reparto).
--   - Ayudante  → ayudantes de las hojas de ruta internas.
-- Los empleados existentes se marcan con los tres roles (para no romper los
-- desplegables actuales); el admin los refina después.
-- =============================================================================

ALTER TABLE `acompanantes`
    ADD COLUMN `es_chofer_ld` TINYINT(1) NOT NULL DEFAULT 0 AFTER `observacion`,
    ADD COLUMN `es_chofer_cd` TINYINT(1) NOT NULL DEFAULT 0 AFTER `es_chofer_ld`,
    ADD COLUMN `es_ayudante`  TINYINT(1) NOT NULL DEFAULT 0 AFTER `es_chofer_cd`;

UPDATE `acompanantes` SET `es_chofer_ld` = 1, `es_chofer_cd` = 1, `es_ayudante` = 1;

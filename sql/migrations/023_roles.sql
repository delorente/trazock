-- =============================================================================
-- 023_roles.sql — agrega los roles `logistica` y `contable` al enum de usuarios.
--   logistica: carga hojas de ruta (INGRESO), reportes, gestión de órdenes.
--   contable:  costos de viaje, costos fijos y caja chica.
-- `gestor` se mantiene = Supervisor (solo lectura). admin/operador/transportista igual.
-- =============================================================================

ALTER TABLE `usuarios`
    MODIFY COLUMN `rol`
    ENUM('admin','gestor','operador','transportista','logistica','contable') NOT NULL;

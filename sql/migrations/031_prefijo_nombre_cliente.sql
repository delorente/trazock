-- =============================================================================
-- 031_prefijo_nombre_cliente.sql — nombre de cliente que marca el "stock del local".
--
-- Cada local maneja dos tipos de orden dentro de su prefijo:
--   - Stock del local ("Pedido del Local"): vienen a nombre del propio local
--     (ej. cliente = "Local Tucuman").
--   - Ventas del local ("Venta del Local"): a nombre del cliente final.
-- Este campo guarda ese nombre de cliente para distinguir unos de otros en el
-- portal del local (KPIs Pedidos vs Ventas).
-- =============================================================================

ALTER TABLE `prefijos`
    ADD COLUMN `nombre_cliente` VARCHAR(150) DEFAULT NULL AFTER `nombre_publico`;

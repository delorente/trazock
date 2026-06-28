-- =============================================================================
-- 022_drop_tarifas.sql — elimina la tabla `tarifas` (tarifario global por
-- provincia×tipo), que quedó sin uso al pasar a la facturación por cliente
-- (cliente_facturacion + cliente_precio). No tenía datos relevantes.
-- =============================================================================

DROP TABLE IF EXISTS `tarifas`;

-- =============================================================================
-- 026_lote_conductor_empleado.sql — el conductor del viaje pasa a salir del
-- padrón de Empleados (tabla acompanantes), no de usuarios.
--
--   lotes.conductor_empleado_id → FK al padrón (acompanantes). El chofer (nombre)
--   se sigue guardando como snapshot en lotes.chofer. transportista_id queda solo
--   para ENTREGA (el repartidor logueado).
--
-- Backfill: para viajes históricos, mapear el transportista (usuario) al empleado
-- por coincidencia de nombre (los conductores se migraron al padrón en 025).
-- =============================================================================

ALTER TABLE `lotes`
    ADD COLUMN `conductor_empleado_id` INT UNSIGNED DEFAULT NULL AFTER `transportista_id`,
    ADD CONSTRAINT `fk_lotes_conductor_emp`
        FOREIGN KEY (`conductor_empleado_id`) REFERENCES `acompanantes` (`id`);

UPDATE `lotes` l
JOIN `usuarios` u ON u.`id` = l.`transportista_id`
JOIN `acompanantes` a ON LOWER(a.`nombre`) = LOWER(u.`nombre_completo`)
SET l.`conductor_empleado_id` = a.`id`
WHERE l.`tipo` IN ('INGRESO', 'SALIDA_REPARTO', 'SALIDA_DEVOLUCION')
  AND l.`conductor_empleado_id` IS NULL;

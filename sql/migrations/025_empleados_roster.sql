-- =============================================================================
-- 025_empleados_roster.sql — padrón único de empleados (conductores y ayudantes).
--
-- Por ahora reutilizamos la tabla `acompanantes` como el padrón de "Empleados"
-- (un solo listado para ambos roles). Sumamos a los conductores actuales
-- (usuarios con rol transportista) al padrón, para que una misma persona pueda
-- figurar como conductor y como ayudante. Idempotente (no duplica por nombre).
--
-- La integración del scan (elegir el conductor también desde este padrón) y el
-- renombre formal de la tabla quedan para una pasada posterior.
-- =============================================================================

INSERT INTO `acompanantes` (`nombre`, `activo`)
SELECT u.`nombre_completo`, 1
FROM `usuarios` u
WHERE u.`rol` = 'transportista' AND u.`activo` = 1
  AND NOT EXISTS (
      SELECT 1 FROM `acompanantes` a WHERE LOWER(a.`nombre`) = LOWER(u.`nombre_completo`)
  );

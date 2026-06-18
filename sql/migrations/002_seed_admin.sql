-- =============================================================================
-- 002_seed_admin.sql — Admin seed user for Trazock
--
-- usuario:         admin
-- password (dev):  admin123
--                  *** ROTATE THIS PASSWORD IMMEDIATELY AFTER INSTALL ***
--                  This is a development-only credential. In production, run:
--                    php scripts/crear-admin.php admin "Administrador" <strong-pass> admin
--                  then delete this seed row if you prefer.
--
-- Hash generated with: php -r "echo password_hash('admin123', PASSWORD_DEFAULT);"
-- Algorithm: bcrypt (PHP PASSWORD_DEFAULT), cost 10.
-- Verified: password_verify('admin123', hash) === true
-- =============================================================================

INSERT INTO `usuarios` (`usuario`, `password_hash`, `nombre_completo`, `rol`, `activo`)
VALUES (
    'admin',
    '$2y$10$/xueCFE8jRQcfT98gjJ71.gcjAog0zSwm6zNLdIFaJAt3ONyHfYVS',
    'Administrador',
    'admin',
    1
);

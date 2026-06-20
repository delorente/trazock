-- =============================================================================
-- 008_seguimiento_transito.sql — paso público "en tránsito al centro de
-- distribución" como estado EDITABLE desde admin/seguimiento.php.
--
-- Antes el texto estaba hardcodeado en seguimiento/index.php. Ahora vive en
-- `estados_publicos` como una fila más (estado pseudo 'EN_TRANSITO', orden 1,
-- antes de INGRESADO), así el gestor lo edita igual que el resto. No es un estado
-- de producto: ningún producto lo toma; solo aparece como primer paso (siempre
-- completado) en la línea de tiempo del comprador.
-- =============================================================================

-- 1) Sumar el valor al ENUM de la tabla pública (no toca productos/transiciones).
ALTER TABLE `estados_publicos`
    MODIFY COLUMN `estado`
    ENUM('EN_TRANSITO','INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NOT NULL;

-- 2) Correr los pasos del camino feliz para hacerle lugar al nuevo paso 1.
UPDATE `estados_publicos` SET `orden` = 4 WHERE `estado` = 'ENTREGADO';
UPDATE `estados_publicos` SET `orden` = 3 WHERE `estado` = 'EN_REPARTO';
UPDATE `estados_publicos` SET `orden` = 2 WHERE `estado` = 'INGRESADO';

-- 3) Insertar el paso editable (idempotente ante re-ejecución).
INSERT INTO `estados_publicos` (`estado`, `titulo`, `descripcion`, `visible`, `orden`)
VALUES ('EN_TRANSITO',
        'En camino a nuestro centro de distribución',
        'Tu pedido fue despachado hacia nuestro depósito.',
        1, 1)
ON DUPLICATE KEY UPDATE `orden` = VALUES(`orden`);

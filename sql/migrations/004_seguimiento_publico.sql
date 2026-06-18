-- =============================================================================
-- 004_seguimiento_publico.sql — Landing pública de seguimiento para el cliente final.
--
-- 1. productos.token_publico: token opaco e impredecible (32 hex = 128 bits) que
--    identifica al producto en la URL pública SIN exponer el código de barras.
--    Se genera a demanda desde el panel (lazy); NULL hasta que se comparte.
-- 2. estados_publicos: traducción editable de cada estado interno a un texto
--    "público" (título + descripción) que ve el comprador. `visible` decide si el
--    estado aparece en la línea de tiempo; `orden` define el camino feliz.
-- =============================================================================

ALTER TABLE `productos`
    ADD COLUMN `token_publico` CHAR(32) DEFAULT NULL AFTER `transicion_actual_id`,
    ADD UNIQUE KEY `uq_token_publico` (`token_publico`);

CREATE TABLE IF NOT EXISTS `estados_publicos` (
    `estado`      ENUM('INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NOT NULL,
    `titulo`      VARCHAR(150)  NOT NULL,
    `descripcion` VARCHAR(500)  NOT NULL DEFAULT '',
    `visible`     TINYINT(1)    NOT NULL DEFAULT 1,
    `orden`       SMALLINT      NOT NULL DEFAULT 0,
    `updated_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Textos por defecto (editables desde admin/seguimiento.php).
-- Los estados con orden > 0 y visible = 1 forman la línea de tiempo del camino feliz.
INSERT INTO `estados_publicos` (`estado`, `titulo`, `descripcion`, `visible`, `orden`) VALUES
    ('INGRESADO',   'Recibimos tu producto',     'Tu producto llegó a nuestro centro de distribución y lo estamos preparando para el envío.', 1, 1),
    ('EN_REPARTO',  'En camino a tu domicilio',  'Tu producto salió de nuestro depósito y está en viaje hacia tu domicilio.',                 1, 2),
    ('ENTREGADO',   '¡Entregado!',               'Tu producto fue entregado. ¡Gracias por tu compra!',                                        1, 3),
    ('REINGRESADO', 'Producto en revisión',      'Tu producto volvió a nuestro depósito y lo estamos gestionando. Pronto te contactamos.',    0, 0),
    ('DEVUELTO',    'Producto devuelto',         'Tu producto fue devuelto. Si tenés alguna duda, no dudes en contactarnos.',                 0, 0),
    ('BAJA',        'Pedido finalizado',         'Este pedido ya no se encuentra activo. Si tenés alguna duda, contactanos.',                 0, 0);

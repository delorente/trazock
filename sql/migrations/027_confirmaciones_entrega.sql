-- =============================================================================
-- 027_confirmaciones_entrega.sql — aviso de entrega al cliente final por WhatsApp.
--
-- Una fila por orden (UNIQUE orden_id): se crea/actualiza al disparar el aviso
-- desde el panel y se completa con la respuesta cuando el cliente toca un botón
-- (Confirmar/Reprogramar) que llega por el webhook de WhatsApp Cloud API.
--   estado: enviado → confirmado | reprogramado ; error si el envío falló.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `confirmaciones_entrega` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `orden_id`       INT UNSIGNED NOT NULL,
    `estado`         ENUM('enviado','confirmado','reprogramado','error') NOT NULL DEFAULT 'enviado',
    `fecha_entrega`  DATE         DEFAULT NULL,
    `horario`        VARCHAR(40)  DEFAULT NULL,
    `telefono`       VARCHAR(30)  DEFAULT NULL,   -- E.164 usado en el envío (auditoría)
    `wa_message_id`  VARCHAR(80)  DEFAULT NULL,   -- id del mensaje saliente (match del webhook)
    `error`          VARCHAR(255) DEFAULT NULL,
    `enviado_por`    INT UNSIGNED DEFAULT NULL,
    `enviado_at`     TIMESTAMP    NULL DEFAULT NULL,
    `respondido_at`  TIMESTAMP    NULL DEFAULT NULL,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_confent_orden` (`orden_id`),
    INDEX `idx_confent_estado` (`estado`),
    INDEX `idx_confent_wamsg` (`wa_message_id`),
    INDEX `idx_confent_fecha` (`fecha_entrega`),
    CONSTRAINT `fk_confent_orden`
        FOREIGN KEY (`orden_id`) REFERENCES `ordenes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_confent_usuario`
        FOREIGN KEY (`enviado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- 009_carga_categoria.sql — categoría de la carga (línea de producto).
--
-- El gestor elige una categoría al cargar las hojas (p. ej. "Colchones Simmons"
-- vs "Café La Morenita"). Se guarda en la carga y, al confirmar, se estampa en
-- cada producto (productos.categoria_id) y en el lote de INGRESO, para poder
-- filtrar los reportes por línea de producto.
-- =============================================================================

ALTER TABLE `cargas`
    ADD COLUMN `categoria_id` INT UNSIGNED DEFAULT NULL AFTER `usuario_id`,
    ADD INDEX `idx_carga_categoria` (`categoria_id`),
    ADD CONSTRAINT `fk_cargas_categoria`
        FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`);

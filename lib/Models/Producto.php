<?php
declare(strict_types=1);

namespace Trazock\Models;

use PDO;
use Trazock\DB;

final class Producto
{
    // -------------------------------------------------------------------------
    // Métodos usados por el ProcesadorLote (dentro de transacción)
    // -------------------------------------------------------------------------

    /**
     * Busca un producto por código con bloqueo de fila (FOR UPDATE).
     * Debe llamarse dentro de una transacción para serializar el procesamiento
     * de un mismo código entre lotes concurrentes.
     *
     * @return array<string, mixed>|null
     */
    public static function findByCodigoForUpdate(string $codigo): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT id, codigo, categoria_id, orden_id, estado_actual, tiene_conflicto, transicion_actual_id
             FROM productos WHERE codigo = :codigo LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':codigo' => $codigo]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Crea un producto. Devuelve el nuevo id.
     */
    public static function crear(string $codigo, ?int $categoriaId, string $estado): int
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO productos (codigo, categoria_id, estado_actual)
             VALUES (:codigo, :categoria_id, :estado)'
        );
        $stmt->bindValue(':codigo', $codigo);
        $stmt->bindValue(':categoria_id', $categoriaId, $categoriaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':estado', $estado);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }

    /**
     * Fija estado_actual + transicion_actual_id (al aplicar la transición más reciente).
     */
    public static function fijarEstadoActual(int $id, string $estado, int $transicionId): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE productos SET estado_actual = :estado, transicion_actual_id = :tid WHERE id = :id'
        );
        $stmt->execute([':estado' => $estado, ':tid' => $transicionId, ':id' => $id]);
    }

    public static function marcarConflicto(int $id): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE productos SET tiene_conflicto = 1 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public static function limpiarConflicto(int $id): void
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE productos SET tiene_conflicto = 0 WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Consultas de panel
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public static function findByCodigo(string $codigo): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT p.*, c.nombre AS categoria_nombre
             FROM productos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             WHERE p.codigo = :codigo LIMIT 1'
        );
        $stmt->execute([':codigo' => $codigo]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findById(int $id): ?array
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT p.*, c.nombre AS categoria_nombre
             FROM productos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Construye el WHERE + parámetros a partir de los filtros del panel.
     *
     * @param array<string, mixed> $f
     * @return array{0:string, 1:array<string,mixed>}
     */
    private static function whereFiltros(array $f): array
    {
        $where  = [];
        $params = [];

        if (!empty($f['codigo'])) {
            // Busca tanto el código del ítem (nro_orden-NN) como la descripción/código
            // de producto de Simmons (OCR). Placeholders distintos: EMULATE_PREPARES off.
            $where[] = '(p.codigo LIKE :codigo1 OR p.descripcion LIKE :codigo2)';
            $params[':codigo1'] = '%' . $f['codigo'] . '%';
            $params[':codigo2'] = '%' . $f['codigo'] . '%';
        }
        if (!empty($f['categoria_id'])) {
            $where[] = 'p.categoria_id = :cat';
            $params[':cat'] = (int)$f['categoria_id'];
        }
        if (!empty($f['estado'])) {
            $where[] = 'p.estado_actual = :estado';
            $params[':estado'] = $f['estado'];
        }
        if (isset($f['tiene_conflicto']) && $f['tiene_conflicto'] !== '') {
            $where[] = 'p.tiene_conflicto = :conf';
            $params[':conf'] = (int)$f['tiene_conflicto'];
        }
        if (!empty($f['fecha_desde'])) {
            $where[] = 'p.updated_at >= :fdesde';
            $params[':fdesde'] = $f['fecha_desde'] . ' 00:00:00';
        }
        if (!empty($f['fecha_hasta'])) {
            $where[] = 'p.updated_at <= :fhasta';
            $params[':fhasta'] = $f['fecha_hasta'] . ' 23:59:59';
        }

        $sql = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));
        return [$sql, $params];
    }

    /**
     * @param array<string, mixed> $filtros
     * @return array<int, array<string, mixed>>
     */
    public static function buscar(array $filtros, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::whereFiltros($filtros);
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT p.id, p.codigo, p.descripcion, p.estado_actual, p.tiene_conflicto, p.updated_at,
                       c.nombre AS categoria_nombre
                FROM productos p
                LEFT JOIN categorias c ON c.id = p.categoria_id'
             . $where
             . " ORDER BY p.updated_at DESC, p.id DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string, mixed> $filtros
     */
    public static function contar(array $filtros): int
    {
        [$where, $params] = self::whereFiltros($filtros);
        $stmt = DB::getInstance()->prepare('SELECT COUNT(*) FROM productos p' . $where);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Edición de ítems de una orden (agregar/quitar)
    // -------------------------------------------------------------------------

    /** Inserta un ítem físico de una orden (codigo = nro_orden-NN). Devuelve su id. */
    public static function crearItem(
        string $codigo, int $ordenId, ?int $categoriaId, ?string $descripcion,
        ?string $dimensiones, ?float $m3, int $secuencia
    ): int {
        $db   = DB::getInstance();
        $stmt = $db->prepare(
            "INSERT INTO productos
                (codigo, orden_id, categoria_id, descripcion, dimensiones, m3, secuencia, estado_actual)
             VALUES (:codigo, :orden, :cat, :desc, :dim, :m3, :sec, 'INGRESADO')"
        );
        $stmt->bindValue(':codigo', $codigo);
        $stmt->bindValue(':orden', $ordenId, PDO::PARAM_INT);
        $stmt->bindValue(':cat', $categoriaId, $categoriaId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':desc', $descripcion, $descripcion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':dim', $dimensiones, $dimensiones === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':m3', $m3, $m3 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':sec', $secuencia, PDO::PARAM_INT);
        $stmt->execute();
        return (int)$db->lastInsertId();
    }

    /**
     * Borra un producto y todo lo que cuelga de él (lote_items, conflictos,
     * transiciones), respetando las FK. SIN transacción ni validación: lo maneja
     * quien llama (eliminarItem / Orden::eliminar).
     */
    public static function borrarFK(PDO $db, int $id): void
    {
        $db->prepare('DELETE li FROM lote_items li JOIN transiciones t ON t.id = li.transicion_id WHERE t.producto_id = :id')->execute([':id' => $id]);
        $db->prepare('DELETE FROM conflictos_producto WHERE producto_id = :id')->execute([':id' => $id]);
        $db->prepare('UPDATE productos SET transicion_actual_id = NULL WHERE id = :id')->execute([':id' => $id]);
        $db->prepare('DELETE FROM transiciones WHERE producto_id = :id')->execute([':id' => $id]);
        $db->prepare('DELETE FROM productos WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Quita un ítem de su orden. Solo si sigue INGRESADO (no se borran ítems ya
     * despachados/entregados). Devuelve 'ok' | 'no_existe' | 'despachado'.
     */
    public static function eliminarItem(int $id): string
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare('SELECT estado_actual, orden_id FROM productos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) { return 'no_existe'; }
        if ($row['estado_actual'] !== 'INGRESADO') { return 'despachado'; }

        $db->beginTransaction();
        try {
            self::borrarFK($db, $id);
            if (($row['orden_id'] ?? null) !== null) {
                Orden::recalcularM3((int)$row['orden_id']);
            }
            $db->commit();
            return 'ok';
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Reporte "por productos" (una fila por ítem físico de una orden)
    // -------------------------------------------------------------------------

    /** Estados de producto, para el filtro del reporte por productos. */
    public const ESTADOS = ['INGRESADO', 'EN_REPARTO', 'ENTREGADO', 'REINGRESADO', 'DEVUELTO', 'BAJA'];

    /**
     * Cláusula `col IN (:pfx0, …)` con placeholders únicos para un filtro multi-valor
     * (prepares nativos no permiten reusar nombres). Null si no quedan valores.
     *
     * @param array<int,mixed> $vals
     * @param array<string,mixed> $params
     */
    private static function inClause(string $col, array $vals, string $prefijo, array &$params): ?string
    {
        $vals = array_values(array_unique(array_filter(
            array_map(static fn($v) => trim((string)$v), $vals),
            static fn(string $v): bool => $v !== ''
        )));
        if ($vals === []) {
            return null;
        }
        $ph = [];
        foreach ($vals as $i => $v) {
            $k = ':' . $prefijo . $i;
            $ph[] = $k;
            $params[$k] = $v;
        }
        return $col . ' IN (' . implode(', ', $ph) . ')';
    }

    /**
     * @param array<string,mixed> $f
     * @return array{0:string,1:array<string,mixed>}
     */
    private static function whereReporte(array $f): array
    {
        $where  = [];
        $params = [];

        if (!empty($f['q'])) {
            $where[] = '(p.codigo LIKE :q1 OR p.descripcion LIKE :q2 OR o.nro_orden LIKE :q3 OR o.cliente LIKE :q4)';
            $like = '%' . $f['q'] . '%';
            $params[':q1'] = $like; $params[':q2'] = $like; $params[':q3'] = $like; $params[':q4'] = $like;
        }
        // Multi-valor: lote (carga), destino (provincia) y hoja de ruta.
        if (($c = self::inClause('o.carga_id', (array)($f['carga'] ?? []), 'carga', $params)) !== null) {
            $where[] = $c;
        }
        if (($c = self::inClause('o.dest_provincia', (array)($f['provincia'] ?? []), 'prov', $params)) !== null) {
            $where[] = $c;
        }
        if (($c = self::inClause('o.hoja_ruta', (array)($f['hoja_ruta'] ?? []), 'hr', $params)) !== null) {
            $where[] = $c;
        }
        if (!empty($f['transportista'])) {
            $where[] = 'o.transportista_id = :transp';
            $params[':transp'] = (int)$f['transportista'];
        }
        if (!empty($f['zona'])) {
            $where[] = 'EXISTS (SELECT 1 FROM zona_localidades zl
                               WHERE zl.zona_id = :zona
                                 AND zl.provincia = o.dest_provincia
                                 AND (zl.ciudad IS NULL OR zl.ciudad = \'\' OR zl.ciudad = o.dest_localidad))';
            $params[':zona'] = (int)$f['zona'];
        }
        if (!empty($f['estado'])) {
            $where[] = 'p.estado_actual = :estado';
            $params[':estado'] = $f['estado'];
        }
        if (!empty($f['categoria'])) {
            $where[] = 'p.categoria_id = :cat';
            $params[':cat'] = (int)$f['categoria'];
        }
        if (!empty($f['tipo_venta'])) {
            $where[] = 'o.tipo_venta = :tv';
            $params[':tv'] = $f['tipo_venta'];
        }
        // Fecha de CARGA del documento (la que se ingresa al importar; columna DATE).
        if (!empty($f['fecha_desde'])) {
            $where[] = 'o.fecha_carga >= :fd';
            $params[':fd'] = $f['fecha_desde'];
        }
        if (!empty($f['fecha_hasta'])) {
            $where[] = 'o.fecha_carga <= :fh';
            $params[':fh'] = $f['fecha_hasta'];
        }

        // Solo ítems ligados a una orden (los del módulo OCR). El JOIN ya lo asegura.
        return [$where === [] ? '' : (' WHERE ' . implode(' AND ', $where)), $params];
    }

    /**
     * Filas del reporte por productos: un ítem físico por fila, con el contexto de
     * su orden y carga.
     *
     * @param array<string,mixed> $filtros
     * @return array<int, array<string,mixed>>
     */
    public static function reporte(array $filtros, int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = self::whereReporte($filtros);
        $limit  = max(1, min(5000, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT p.id, p.codigo, p.descripcion, p.dimensiones, p.m3, p.secuencia,
                       p.estado_actual, p.etiquetada_at,
                       o.id AS orden_id, o.carga_id, o.nro_orden, o.cliente, o.tipo_venta,
                       o.hoja_ruta, o.fecha_carga,
                       o.dest_provincia, o.dest_localidad, o.created_at AS fecha_ingreso,
                       tu.nombre_completo AS transportista_nombre,
                       cat.nombre AS categoria
                FROM productos p
                JOIN ordenes o ON o.id = p.orden_id
                LEFT JOIN categorias cat ON cat.id = p.categoria_id
                LEFT JOIN usuarios tu ON tu.id = o.transportista_id'
             . $where
             . " ORDER BY o.created_at DESC, o.id DESC, p.secuencia LIMIT {$limit} OFFSET {$offset}";

        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<string,mixed> $filtros
     */
    public static function reporteContar(array $filtros): int
    {
        [$where, $params] = self::whereReporte($filtros);
        $stmt = DB::getInstance()->prepare(
            'SELECT COUNT(*) FROM productos p JOIN ordenes o ON o.id = p.orden_id' . $where
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    // -------------------------------------------------------------------------
    // Etiquetas (un rótulo con QR por ítem físico)
    // -------------------------------------------------------------------------

    /**
     * Ítems de una carga, con el contexto de su orden, listos para etiquetar.
     * Una fila por unidad física, ordenadas por orden y secuencia ("ítem X de N").
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paraEtiquetasPorCarga(int $cargaId): array
    {
        return self::paraEtiquetas('o.carga_id = :id', $cargaId);
    }

    /**
     * Ítems de una sola orden (reimpresión puntual de etiquetas).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paraEtiquetasPorOrden(int $ordenId): array
    {
        return self::paraEtiquetas('p.orden_id = :id', $ordenId);
    }

    /**
     * Ítems de un lote (reimpresión de toda una carga agrupada por su lote de
     * ingreso). Un ítem pertenece al lote si tiene una transición a ese lote.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function paraEtiquetasPorLote(int $loteId): array
    {
        return self::paraEtiquetas(
            'p.id IN (SELECT t.producto_id FROM transiciones t WHERE t.lote_id = :id)',
            $loteId
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function paraEtiquetas(string $cond, int $id): array
    {
        // posicion = ranking contiguo 1..N dentro de la orden (para el "X de N"
        // visible). Es independiente de `secuencia`, que puede tener huecos tras
        // borrar/agregar ítems y NO se renumera porque el `codigo` (= nro_orden-NN,
        // que el QR reconstruye desde sec) depende de ella.
        $sql = 'SELECT p.id, p.codigo, p.secuencia, p.descripcion, p.dimensiones, p.m3,
                       p.estado_actual, p.etiquetada_at,
                       ROW_NUMBER() OVER (PARTITION BY p.orden_id ORDER BY p.secuencia, p.id) AS posicion,
                       o.id AS orden_id, o.nro_orden, o.nro_remito, o.carga_id,
                       o.cliente, o.cliente_apellido,
                       o.dest_provincia, o.dest_localidad,
                       (SELECT COUNT(*) FROM productos p2 WHERE p2.orden_id = p.orden_id) AS total_items
                FROM productos p
                JOIN ordenes o ON o.id = p.orden_id
                WHERE ' . $cond . '
                ORDER BY o.id, p.secuencia, p.id';
        $stmt = DB::getInstance()->prepare($sql);
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll();
    }

    /**
     * Marca como etiquetados (fija etiquetada_at) los ítems de una carga que aún
     * no lo estaban. Idempotente. Devuelve cuántos se marcaron en esta llamada.
     */
    public static function marcarEtiquetadasPorCarga(int $cargaId): int
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE productos p
                JOIN ordenes o ON o.id = p.orden_id
                SET p.etiquetada_at = NOW()
              WHERE o.carga_id = :id AND p.etiquetada_at IS NULL'
        );
        $stmt->execute([':id' => $cargaId]);
        return $stmt->rowCount();
    }

    /** Marca como etiquetados los ítems de un lote aún sin etiquetar. Idempotente. */
    public static function marcarEtiquetadasPorLote(int $loteId): int
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE productos p
                SET p.etiquetada_at = NOW()
              WHERE p.etiquetada_at IS NULL
                AND p.id IN (SELECT t.producto_id FROM transiciones t WHERE t.lote_id = :id)'
        );
        $stmt->execute([':id' => $loteId]);
        return $stmt->rowCount();
    }

    /** Marca como etiquetados los ítems de una sola orden (reimpresión puntual). */
    public static function marcarEtiquetadasPorOrden(int $ordenId): int
    {
        $stmt = DB::getInstance()->prepare(
            'UPDATE productos SET etiquetada_at = NOW()
              WHERE orden_id = :id AND etiquetada_at IS NULL'
        );
        $stmt->execute([':id' => $ordenId]);
        return $stmt->rowCount();
    }

    // -------------------------------------------------------------------------
    // Seguimiento público (token opaco para la landing del cliente final)
    // -------------------------------------------------------------------------

    /**
     * Devuelve el token público del producto, generándolo de forma perezosa la
     * primera vez. El token es impredecible (128 bits) y no expone el código.
     * Reintenta ante la (improbable) colisión con el índice único.
     */
    public static function asegurarToken(int $id): string
    {
        $db   = DB::getInstance();
        $stmt = $db->prepare('SELECT token_publico FROM productos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $actual = $stmt->fetchColumn();

        if ($actual !== false && $actual !== null && $actual !== '') {
            return (string)$actual;
        }

        $update = $db->prepare('UPDATE productos SET token_publico = :token WHERE id = :id');
        for ($intento = 0; $intento < 5; $intento++) {
            $token = bin2hex(random_bytes(16)); // 32 chars hex
            try {
                $update->execute([':token' => $token, ':id' => $id]);
                return $token;
            } catch (\PDOException $e) {
                // 23000 = violación de UNIQUE (colisión); reintentar con otro token.
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new \RuntimeException('No se pudo generar un token de seguimiento único.');
    }

    /**
     * Busca un producto por su token público de seguimiento. Solo devuelve los
     * campos seguros para la cara pública (nunca el código interno ni conflictos).
     *
     * @return array<string, mixed>|null
     */
    public static function findByToken(string $token): ?array
    {
        // El token siempre es hex de 32 caracteres; descartar cualquier otra cosa
        // evita consultas inútiles ante enlaces malformados.
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        $stmt = DB::getInstance()->prepare(
            'SELECT id, estado_actual, created_at, updated_at
             FROM productos WHERE token_publico = :token LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }
}

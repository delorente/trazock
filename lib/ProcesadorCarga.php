<?php
declare(strict_types=1);

namespace Trazock;

use PDO;
use RuntimeException;
use Trazock\Models\Carga;
use Trazock\Models\Orden;

/**
 * ProcesadorCarga — materializa el borrador de una carga (JSON revisado) en
 * `ordenes` + `productos`, dentro de una transacción.
 *
 * Por cada orden: crea la fila en `ordenes` (estado RECIBIDO) y expande sus líneas
 * a `productos` (un producto por unidad), con codigo = nro_orden-NN, su secuencia
 * "ítem X de N", descripción, dimensiones y m³ por unidad, en estado INGRESADO.
 *
 * Idempotente ante duplicados: una orden cuyo nro_orden ya existe se omite.
 */
final class ProcesadorCarga
{
    /**
     * @return array{creadas:int, items:int, omitidas:array<int,string>}
     */
    public static function confirmar(int $cargaId): array
    {
        $carga = Carga::find($cargaId);
        if ($carga === null) {
            throw new RuntimeException('Carga no encontrada.');
        }
        if ($carga['estado'] === 'confirmada') {
            throw new RuntimeException('La carga ya fue confirmada.');
        }
        $datos = json_decode((string)($carga['datos_extraidos'] ?? ''), true);
        if (!is_array($datos) || empty($datos['ordenes']) || !is_array($datos['ordenes'])) {
            throw new RuntimeException('La carga no tiene órdenes para confirmar.');
        }

        $db = DB::getInstance();
        $db->beginTransaction();
        try {
            $creadas = 0;
            $itemsTot = 0;
            $omitidas = [];

            foreach ($datos['ordenes'] as $o) {
                $nro = trim((string)($o['nro_orden'] ?? ''));
                if ($nro === '') { $omitidas[] = '(sin nro de orden)'; continue; }
                if (Orden::existeNroOrden($nro)) { $omitidas[] = $nro; continue; }

                $lineas = is_array($o['items'] ?? null) ? $o['items'] : [];

                // m³ total de la orden = suma de las líneas (dato de facturación).
                $m3Total = 0.0;
                foreach ($lineas as $l) { $m3Total += (float)($l['m3'] ?? 0); }

                $cliente  = trim((string)($o['cliente'] ?? ''));
                $apellido = trim((string)($o['cliente_apellido'] ?? '')) ?: self::apellidoDe($cliente);

                $ordenId = Orden::crear([
                    'carga_id'        => $cargaId,
                    'nro_orden'       => $nro,
                    'nro_remito'      => self::s($o['nro_remito'] ?? null),
                    'fecha_remito'    => self::s($o['fecha_remito'] ?? null),
                    'tipo_venta'      => self::s($o['tipo_venta'] ?? null),
                    'cliente'         => $cliente,
                    'cliente_apellido'=> $apellido,
                    'telefonos'       => self::s($o['telefonos'] ?? null),
                    'dest_provincia'  => self::s($o['dest_provincia'] ?? null),
                    'dest_localidad'  => self::s($o['dest_localidad'] ?? null),
                    'dest_domicilio'  => self::s($o['dest_domicilio'] ?? null),
                    'dest_cp'         => self::s($o['dest_cp'] ?? null),
                    'valor_declarado' => isset($o['valor_declarado']) && $o['valor_declarado'] !== null ? (float)$o['valor_declarado'] : null,
                    'm3_total'        => round($m3Total, 2),
                    'estado'          => 'RECIBIDO',
                ]);

                // Total de ítems físicos de la orden (suma de cantidades).
                $total = 0;
                foreach ($lineas as $l) { $total += max(1, (int)($l['cantidad'] ?? 1)); }

                // Expandir cada línea a N productos (un QR/etiqueta por unidad).
                $sec = 0;
                foreach ($lineas as $l) {
                    $cant   = max(1, (int)($l['cantidad'] ?? 1));
                    $m3Lin  = isset($l['m3']) && $l['m3'] !== null ? (float)$l['m3'] : null;
                    $m3Unit = ($m3Lin !== null && $cant > 0) ? round($m3Lin / $cant, 3) : null;
                    for ($i = 0; $i < $cant; $i++) {
                        $sec++;
                        self::crearProducto(
                            $db,
                            self::codigo($nro, $sec),
                            $ordenId,
                            self::s($l['codigo'] ?? null),
                            self::s($l['dimensiones'] ?? null),
                            $m3Unit,
                            $sec
                        );
                        $itemsTot++;
                    }
                }

                $creadas++;
            }

            Carga::marcarConfirmada($cargaId, $creadas);
            $db->commit();

            return ['creadas' => $creadas, 'items' => $itemsTot, 'omitidas' => $omitidas];
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Inserta un producto (ítem físico) en estado INGRESADO ligado a su orden. */
    private static function crearProducto(
        PDO $db, string $codigo, int $ordenId, ?string $descripcion,
        ?string $dimensiones, ?float $m3, int $secuencia
    ): void {
        $stmt = $db->prepare(
            "INSERT INTO productos
                (codigo, orden_id, descripcion, dimensiones, m3, secuencia, estado_actual)
             VALUES (:codigo, :orden, :desc, :dim, :m3, :sec, 'INGRESADO')"
        );
        $stmt->bindValue(':codigo', $codigo);
        $stmt->bindValue(':orden', $ordenId, PDO::PARAM_INT);
        $stmt->bindValue(':desc', $descripcion, $descripcion === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':dim', $dimensiones, $dimensiones === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':m3', $m3, $m3 === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':sec', $secuencia, PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Código único del ítem: nro_orden-NN (2 dígitos, o más si hiciera falta). */
    private static function codigo(string $nroOrden, int $secuencia): string
    {
        return $nroOrden . '-' . str_pad((string)$secuencia, 2, '0', STR_PAD_LEFT);
    }

    /** Apellido = última palabra del nombre (heurística; editable en la revisión). */
    private static function apellidoDe(string $nombre): string
    {
        $partes = preg_split('/\s+/', trim($nombre)) ?: [];
        return $partes === [] ? '' : (string)end($partes);
    }

    /** Normaliza '' → null para campos de texto opcionales. */
    private static function s(mixed $v): ?string
    {
        if ($v === null) { return null; }
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }
}

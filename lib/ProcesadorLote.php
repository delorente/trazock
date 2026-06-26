<?php
declare(strict_types=1);

namespace Trazock;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use Trazock\Models\Categoria;
use Trazock\Models\Conflicto;
use Trazock\Models\Lote;
use Trazock\Models\Motivo;
use Trazock\Models\Orden;
use Trazock\Models\Producto;
use Trazock\Models\Proveedor;
use Trazock\Models\Transicion;
use Trazock\Models\Usuario;

/**
 * ProcesadorLote — aplica un lote completo siguiendo las reglas R1-R10 de la spec.
 *
 * Garantías:
 *   - Atomicidad: todo el lote se procesa dentro de una transacción MySQL.
 *   - Idempotencia (R1): un uuid repetido devuelve el resultado guardado sin re-procesar.
 *   - El estado actual del producto sigue el timestamp_cliente más reciente (R4),
 *     no el orden de llegada al server.
 *   - Las transiciones ilegales se aplican igual con marca de conflicto (R6).
 */
final class ProcesadorLote
{
    /** Tope de items por lote (spec: seguridad #12). */
    public const MAX_ITEMS = 1000;

    /** Motivo de conflicto por tipo de regla. */
    private const CONF_INEXISTENTE = 'producto_inexistente_en_no_ingreso';
    private const CONF_ILEGAL      = 'transicion_ilegal';

    /**
     * Procesa un lote.
     *
     * @param array<string, mixed> $loteData Payload del cliente (ver spec API).
     * @param array<string, mixed> $usuario  Usuario autenticado: id, rol.
     * @return array<string, mixed> Resumen del resultado.
     *
     * @throws LoteException  Para errores de cliente (400/403/413).
     */
    public static function procesarLote(array $loteData, array $usuario): array
    {
        // --- Validaciones estructurales (fuera de transacción) -------------------
        $tipoStr = (string)($loteData['tipo'] ?? '');
        $tipo    = TipoLote::tryFrom($tipoStr);
        if ($tipo === null) {
            throw new LoteException("Tipo de lote inválido: '{$tipoStr}'.", 400);
        }

        $uuid = (string)($loteData['uuid'] ?? '');
        if ($uuid === '' || !preg_match('/^[0-9a-fA-F-]{36}$/', $uuid)) {
            throw new LoteException('uuid de lote ausente o con formato inválido.', 400);
        }

        $items = $loteData['items'] ?? null;
        if (!is_array($items)) {
            throw new LoteException('El lote no incluye una lista de items.', 400);
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new LoteException(
                'El lote excede el máximo de ' . self::MAX_ITEMS . ' items. Partilo en lotes más chicos.',
                413
            );
        }

        $db = DB::getInstance();
        $db->beginTransaction();

        try {
            // --- R1: idempotencia por uuid -----------------------------------------
            $existente = Lote::findByUuidForUpdate($uuid);
            if ($existente !== null) {
                $resumen = Lote::resumen((int)$existente['id']);
                $db->commit();
                return ['ok' => true, 'idempotente' => true, 'uuid' => $uuid] + $resumen;
            }

            // --- Permiso de rol vs tipo de lote ------------------------------------
            $rol = (string)$usuario['rol'];
            if (!MaquinaEstados::rolPermiteTipo($rol, $tipo)) {
                throw new LoteException(
                    "El rol '{$rol}' no puede generar lotes de tipo {$tipo->value}.",
                    403
                );
            }

            // --- Validación de campos + integridad referencial ---------------------
            $d = self::validarYNormalizar($loteData, $tipo, $usuario);

            // --- Insertar encabezado del lote --------------------------------------
            $loteId        = Lote::crear($d);
            $estadoDestino = $tipo->estadoDestino();
            $categoriaLote = $d['categoria_id'];   // sólo no-null en INGRESO

            // --- Procesar items en orden -------------------------------------------
            $vistosEnLote = [];
            $ordenesTocadas = []; // orden_id => true: para recalcular su estado al final

            foreach ($items as $item) {
                $codigo = trim((string)($item['codigo'] ?? ''));
                if ($codigo === '') {
                    continue; // código vacío: se descarta sin registrar.
                }
                $ts = self::normalizarTimestamp((string)($item['timestamp_cliente'] ?? ''));

                // --- R2: duplicado dentro del mismo lote ---------------------------
                if (isset($vistosEnLote[$codigo])) {
                    Lote::insertarItem($loteId, $codigo, $ts, null, 'ignorado_duplicado_lote');
                    continue;
                }
                $vistosEnLote[$codigo] = true;

                $prod = Producto::findByCodigoForUpdate($codigo);

                // === Producto NUEVO ================================================
                if ($prod === null) {
                    if ($tipo === TipoLote::INGRESO) {
                        // R8: alta normal, sin conflicto.
                        $pid = Producto::crear($codigo, $categoriaLote, Estado::INGRESADO->value);
                        $tid = Transicion::insertar($pid, $loteId, null, Estado::INGRESADO->value, $ts, false, null);
                        Producto::fijarEstadoActual($pid, Estado::INGRESADO->value, $tid);
                        Lote::insertarItem($loteId, $codigo, $ts, $tid, 'aplicado');
                    } else {
                        // R7: alta automática en el estado destino, con conflicto.
                        $estD = $estadoDestino->value;
                        $pid  = Producto::crear($codigo, $categoriaLote, $estD); // categoria_lote es null en no-INGRESO
                        $tid  = Transicion::insertar($pid, $loteId, null, $estD, $ts, true, self::CONF_INEXISTENTE);
                        Producto::fijarEstadoActual($pid, $estD, $tid);
                        Producto::marcarConflicto($pid);
                        Conflicto::crear(
                            $pid, $tid, $loteId, self::CONF_INEXISTENTE,
                            "El código «{$codigo}» no existía y se escaneó en un lote {$tipo->value}. "
                            . "Alta automática en estado {$estD}. Verificar el ingreso previo."
                        );
                        Lote::insertarItem($loteId, $codigo, $ts, $tid, 'aplicado_con_conflicto');
                    }
                    continue;
                }

                // === Producto EXISTENTE ============================================
                $pid           = (int)$prod['id'];
                // Si el producto pertenece a una orden, su estado público puede cambiar.
                if (($prod['orden_id'] ?? null) !== null) {
                    $ordenesTocadas[(int)$prod['orden_id']] = true;
                }
                $estadoDesdeStr = Transicion::estadoEnTimestamp($pid, $ts); // estado en la posición del ts
                $estadoDesde    = $estadoDesdeStr !== null ? Estado::from($estadoDesdeStr) : null;

                // R3: si en la posición del timestamp ya está en el estado destino → ignorar.
                if ($estadoDesdeStr === $estadoDestino->value) {
                    Lote::insertarItem($loteId, $codigo, $ts, null, 'ignorado_mismo_estado');
                    continue;
                }

                // R5/R6: legalidad de la transición desde el estado en su posición temporal.
                $legal           = MaquinaEstados::esTransicionLegal($estadoDesde, $estadoDestino);
                $esConflicto     = !$legal;
                $motivoConflicto = $legal ? null : self::CONF_ILEGAL;

                $tid = Transicion::insertar(
                    $pid, $loteId, $estadoDesdeStr, $estadoDestino->value, $ts, $esConflicto, $motivoConflicto
                );

                // R4: actualizar estado_actual SOLO si esta transición es la más reciente.
                if (!Transicion::existeMasReciente($pid, $ts)) {
                    Producto::fijarEstadoActual($pid, $estadoDestino->value, $tid);
                }

                if ($esConflicto) {
                    Producto::marcarConflicto($pid);
                    $desdeTxt = $estadoDesdeStr ?? 'NUEVO';
                    Conflicto::crear(
                        $pid, $tid, $loteId, self::CONF_ILEGAL,
                        "Transición ilegal {$desdeTxt} → {$estadoDestino->value} para el código «{$codigo}» "
                        . "(lote {$tipo->value}). Se aplicó igual; requiere revisión."
                    );
                    Lote::insertarItem($loteId, $codigo, $ts, $tid, 'aplicado_con_conflicto');
                } else {
                    Lote::insertarItem($loteId, $codigo, $ts, $tid, 'aplicado');
                }
            }

            // Recalcular el estado denormalizado de las órdenes cuyos ítems se movieron
            // (alimenta Reportes y el seguimiento público por Nº de orden).
            foreach (array_keys($ordenesTocadas) as $ordenId) {
                Orden::recalcularEstado($ordenId);
            }

            $resumen = Lote::resumen($loteId);
            $db->commit();

            return ['ok' => true, 'idempotente' => false, 'uuid' => $uuid] + $resumen;
        } catch (LoteException $e) {
            $db->rollBack();
            throw $e;
        } catch (Throwable $e) {
            $db->rollBack();
            // Error inesperado: log interno, mensaje genérico al cliente (500).
            error_log('ProcesadorLote: ' . $e->getMessage());
            throw new LoteException('Error interno al procesar el lote.', 500);
        }
    }

    /**
     * Valida campos obligatorios según el tipo y la integridad referencial.
     * Devuelve el array de campos normalizado para Lote::crear() (irrelevantes en null).
     *
     * @param array<string, mixed> $loteData
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     *
     * @throws LoteException 400 si algo no valida.
     */
    private static function validarYNormalizar(array $loteData, TipoLote $tipo, array $usuario): array
    {
        $intOrNull = static function ($v): ?int {
            if ($v === null || $v === '' || $v === 0 || $v === '0') {
                return null;
            }
            return (int)$v;
        };

        $categoriaId     = $intOrNull($loteData['categoria_id'] ?? null);
        $proveedorId     = $intOrNull($loteData['proveedor_id'] ?? null);
        $transportistaId = $intOrNull($loteData['transportista_id'] ?? null);
        $motivoId        = $intOrNull($loteData['motivo_id'] ?? null);
        $motivoLibre     = trim((string)($loteData['motivo_libre'] ?? '')) ?: null;
        $numeroRemito    = trim((string)($loteData['numero_remito'] ?? '')) ?: null;
        $observaciones   = trim((string)($loteData['observaciones'] ?? '')) ?: null;
        // Datos del viaje para la hoja de ruta (solo SALIDA_REPARTO).
        $vehiculo        = trim((string)($loteData['vehiculo'] ?? '')) ?: null;
        $chofer          = trim((string)($loteData['chofer'] ?? '')) ?: null;
        $ayudantes       = trim((string)($loteData['ayudantes'] ?? '')) ?: null;

        // Base: todo en null; cada tipo rellena lo suyo.
        $d = [
            'uuid'               => (string)$loteData['uuid'],
            'tipo'               => $tipo->value,
            'categoria_id'       => null,
            'proveedor_id'       => null,
            'transportista_id'   => null,
            'vehiculo'           => null,
            'chofer'             => null,
            'ayudantes'          => null,
            'motivo_id'          => null,
            'motivo_libre'       => null,
            'responsable_id'     => (int)$usuario['id'],
            'observaciones'      => $observaciones,
            'numero_remito'      => null,
            'timestamp_apertura' => self::normalizarTimestamp((string)($loteData['timestamp_apertura'] ?? ''), true),
            'timestamp_cierre'   => self::normalizarTimestamp((string)($loteData['timestamp_cierre'] ?? ''), true),
            'dispositivo_info'   => trim((string)($loteData['dispositivo_info'] ?? '')) ?: null,
        ];

        switch ($tipo) {
            case TipoLote::INGRESO:
                if ($categoriaId === null) {
                    throw new LoteException('Un lote de INGRESO requiere categoría.', 400);
                }
                if (!Categoria::existeActiva($categoriaId)) {
                    throw new LoteException('La categoría indicada no existe o está inactiva.', 400);
                }
                $d['categoria_id'] = $categoriaId;
                if ($proveedorId !== null) {
                    if (!Proveedor::existeActivo($proveedorId)) {
                        throw new LoteException('El proveedor indicado no existe o está inactivo.', 400);
                    }
                    $d['proveedor_id'] = $proveedorId;
                }
                // Transportista opcional en INGRESO (quién trajo la mercadería).
                if ($transportistaId !== null) {
                    if (!Usuario::existeActivoConRol($transportistaId, 'transportista')) {
                        throw new LoteException('El transportista indicado no existe, está inactivo o no tiene ese rol.', 400);
                    }
                    $d['transportista_id'] = $transportistaId;
                }
                $d['numero_remito'] = $numeroRemito;
                break;

            case TipoLote::SALIDA_REPARTO:
                if ($transportistaId === null) {
                    throw new LoteException('Un lote de SALIDA_REPARTO requiere transportista.', 400);
                }
                if (!Usuario::existeActivoConRol($transportistaId, 'transportista')) {
                    throw new LoteException('El transportista indicado no existe, está inactivo o no tiene ese rol.', 400);
                }
                $d['transportista_id'] = $transportistaId;
                $d['vehiculo']  = $vehiculo;
                $d['chofer']    = $chofer;
                $d['ayudantes'] = $ayudantes;
                break;

            case TipoLote::ENTREGA:
                // Transportista = usuario logueado (autocompleto).
                $d['transportista_id'] = (int)$usuario['id'];
                break;

            case TipoLote::REINGRESO:
                $d['motivo_id']    = self::validarMotivo($motivoId, 'reingreso', $motivoLibre);
                $d['motivo_libre'] = $motivoLibre;
                break;

            case TipoLote::SALIDA_DEVOLUCION:
                if ($proveedorId === null) {
                    throw new LoteException('Un lote de SALIDA_DEVOLUCION requiere proveedor.', 400);
                }
                if (!Proveedor::existeActivo($proveedorId)) {
                    throw new LoteException('El proveedor indicado no existe o está inactivo.', 400);
                }
                $d['proveedor_id'] = $proveedorId;
                $d['motivo_id']    = self::validarMotivo($motivoId, 'devolucion', $motivoLibre);
                $d['motivo_libre'] = $motivoLibre;
                $d['numero_remito'] = $numeroRemito;
                break;

            case TipoLote::BAJA:
                $d['motivo_id']    = self::validarMotivo($motivoId, 'baja', $motivoLibre);
                $d['motivo_libre'] = $motivoLibre;
                break;
        }

        return $d;
    }

    /**
     * Valida que el motivo exista, esté activo, sea del tipo esperado, y que si es
     * editable_libre se haya provisto motivo_libre.
     *
     * @throws LoteException 400
     */
    private static function validarMotivo(?int $motivoId, string $tipoEsperado, ?string $motivoLibre): int
    {
        if ($motivoId === null) {
            throw new LoteException("Este tipo de lote requiere un motivo de tipo {$tipoEsperado}.", 400);
        }
        $m = Motivo::find($motivoId);
        if ($m === null || (int)$m['activo'] !== 1) {
            throw new LoteException('El motivo indicado no existe o está inactivo.', 400);
        }
        if (!in_array($tipoEsperado, explode(',', (string)$m['tipo']), true)) {
            throw new LoteException("El motivo indicado no aplica al tipo {$tipoEsperado}.", 400);
        }
        if ((int)$m['editable_libre'] === 1 && ($motivoLibre === null || $motivoLibre === '')) {
            throw new LoteException('Este motivo requiere una aclaración por texto libre.', 400);
        }
        return $motivoId;
    }

    /**
     * Normaliza un timestamp ISO-8601 del cliente a 'Y-m-d H:i:s' en UTC.
     *
     * @param bool $obligatorio Si true, lanza 400 cuando está vacío o es inválido.
     *                          Si false (items), usa "ahora" como fallback tolerante.
     * @throws LoteException 400
     */
    private static function normalizarTimestamp(string $iso, bool $obligatorio = false): string
    {
        $iso = trim($iso);
        if ($iso === '') {
            if ($obligatorio) {
                throw new LoteException('Falta un timestamp obligatorio del lote.', 400);
            }
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }
        try {
            $dt = new DateTimeImmutable($iso);
        } catch (Throwable) {
            throw new LoteException("Timestamp con formato inválido: '{$iso}'.", 400);
        }
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}

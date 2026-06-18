<?php
declare(strict_types=1);

namespace Trazock;

/**
 * MaquinaEstados — define las transiciones legales de la spec y los permisos
 * de rol sobre tipos de lote. Es la única autoridad de validación de transiciones
 * (los clientes NO validan; todo es server-side).
 *
 * Los enums Estado y TipoLote viven en lib/Estado.php y lib/TipoLote.php.
 */
final class MaquinaEstados
{
    /**
     * Conjunto exacto de transiciones legales (spec).
     * Cada par es [estado_desde|null, estado_hasta]. `null` = producto nuevo.
     *
     * (nuevo)     → INGRESADO         [INGRESO]
     * INGRESADO   → EN_REPARTO        [SALIDA_REPARTO]
     * INGRESADO   → BAJA              [BAJA]
     * EN_REPARTO  → ENTREGADO         [ENTREGA]
     * EN_REPARTO  → REINGRESADO       [REINGRESO]
     * EN_REPARTO  → BAJA              [BAJA]
     * ENTREGADO   → REINGRESADO       [REINGRESO]
     * REINGRESADO → EN_REPARTO        [SALIDA_REPARTO]
     * REINGRESADO → DEVUELTO          [SALIDA_DEVOLUCION]
     * REINGRESADO → BAJA              [BAJA]
     *
     * @var array<int, array{0: ?string, 1: string}>
     */
    private const TRANSICIONES_LEGALES = [
        [null,          'INGRESADO'],
        ['INGRESADO',   'EN_REPARTO'],
        ['INGRESADO',   'BAJA'],
        ['EN_REPARTO',  'ENTREGADO'],
        ['EN_REPARTO',  'REINGRESADO'],
        ['EN_REPARTO',  'BAJA'],
        ['ENTREGADO',   'REINGRESADO'],
        ['REINGRESADO', 'EN_REPARTO'],
        ['REINGRESADO', 'DEVUELTO'],
        ['REINGRESADO', 'BAJA'],
    ];

    /**
     * Tipos de lote permitidos por rol (spec).
     *
     * @var array<string, string[]>
     */
    private const PERMISOS_ROL = [
        'admin'         => ['INGRESO', 'SALIDA_REPARTO', 'ENTREGA', 'REINGRESO', 'SALIDA_DEVOLUCION', 'BAJA'],
        'gestor'        => ['INGRESO', 'SALIDA_REPARTO', 'ENTREGA', 'REINGRESO', 'SALIDA_DEVOLUCION', 'BAJA'],
        'operador'      => ['INGRESO', 'SALIDA_REPARTO', 'REINGRESO', 'SALIDA_DEVOLUCION', 'BAJA'],
        'transportista' => ['ENTREGA'],
    ];

    /**
     * ¿Es legal pasar de $desde a $hasta según la máquina de estados?
     * $desde = null representa la creación de un producto nuevo.
     */
    public static function esTransicionLegal(?Estado $desde, Estado $hasta): bool
    {
        $d = $desde?->value;
        foreach (self::TRANSICIONES_LEGALES as [$legalDesde, $legalHasta]) {
            if ($legalDesde === $d && $legalHasta === $hasta->value) {
                return true;
            }
        }
        return false;
    }

    /**
     * ¿El rol puede operar un lote de este tipo?
     */
    public static function rolPermiteTipo(string $rol, TipoLote $tipo): bool
    {
        return in_array($tipo->value, self::PERMISOS_ROL[$rol] ?? [], true);
    }

    /**
     * Tipos de lote permitidos para un rol (para construir el dropdown del cliente).
     *
     * @return string[]
     */
    public static function tiposPermitidos(string $rol): array
    {
        return self::PERMISOS_ROL[$rol] ?? [];
    }
}

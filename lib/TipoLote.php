<?php
declare(strict_types=1);

namespace Trazock;

/**
 * Tipos de lote (coinciden con el ENUM de la BD).
 */
enum TipoLote: string
{
    case INGRESO           = 'INGRESO';
    case SALIDA_REPARTO    = 'SALIDA_REPARTO';
    case ENTREGA           = 'ENTREGA';
    case REINGRESO         = 'REINGRESO';
    case SALIDA_DEVOLUCION = 'SALIDA_DEVOLUCION';
    case BAJA              = 'BAJA';

    /**
     * Estado destino al que lleva un lote de este tipo.
     */
    public function estadoDestino(): Estado
    {
        return match ($this) {
            self::INGRESO           => Estado::INGRESADO,
            self::SALIDA_REPARTO    => Estado::EN_REPARTO,
            self::ENTREGA           => Estado::ENTREGADO,
            self::REINGRESO         => Estado::REINGRESADO,
            self::SALIDA_DEVOLUCION => Estado::DEVUELTO,
            self::BAJA              => Estado::BAJA,
        };
    }
}

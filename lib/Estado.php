<?php
declare(strict_types=1);

namespace Trazock;

/**
 * Estados posibles de un producto (coinciden con el ENUM de la BD).
 */
enum Estado: string
{
    case INGRESADO   = 'INGRESADO';
    case EN_REPARTO  = 'EN_REPARTO';
    case ENTREGADO   = 'ENTREGADO';
    case REINGRESADO = 'REINGRESADO';
    case DEVUELTO    = 'DEVUELTO';
    case BAJA        = 'BAJA';
}

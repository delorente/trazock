<?php
declare(strict_types=1);

namespace Trazock;

use RuntimeException;

/**
 * Excepción de procesamiento de lote. Lleva el código HTTP que el endpoint
 * debe devolver (400 datos inválidos, 403 permiso, 413 payload demasiado grande).
 */
final class LoteException extends RuntimeException
{
    public function __construct(
        string $mensaje,
        public readonly int $httpStatus = 400
    ) {
        parent::__construct($mensaje);
    }
}

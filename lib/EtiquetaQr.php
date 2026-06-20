<?php
declare(strict_types=1);

namespace Trazock;

/**
 * EtiquetaQr — payload autocontenido del QR de cada ítem.
 *
 * Formato:  nro_orden|sec/total|provincia|apellido
 *   ej.     ON-0775-1A2B3C4D|2/3|Córdoba|García
 *
 * Es autocontenido a propósito: el escáner del repartidor puede validar el
 * destino aun sin conexión (provincia/apellido legibles en el QR), mientras que
 * la clave real del ítem en la BD es el `codigo` = `nro_orden-NN`, reconstruible
 * desde `nro_orden` + `sec` (NN = sec con 2 dígitos). Ver ProcesadorCarga::codigo.
 */
final class EtiquetaQr
{
    private const SEP = '|';

    /** Arma el payload del QR de un ítem. */
    public static function payload(
        string $nroOrden,
        int $secuencia,
        int $total,
        ?string $provincia,
        ?string $apellido
    ): string {
        return implode(self::SEP, [
            self::limpiar($nroOrden),
            $secuencia . '/' . $total,
            self::limpiar($provincia ?? ''),
            self::limpiar($apellido ?? ''),
        ]);
    }

    /**
     * Parsea un payload escaneado. Devuelve null si no tiene la forma esperada.
     * (Lo usará el escáner para validar destino contra la salida a reparto.)
     *
     * @return array{nro_orden:string, secuencia:int, total:int, codigo:string, provincia:string, apellido:string}|null
     */
    public static function parse(string $raw): ?array
    {
        $p = explode(self::SEP, trim($raw));
        if (count($p) < 2) {
            return null;
        }
        $nro = trim($p[0]);
        if ($nro === '' || !preg_match('#^(\d+)/(\d+)$#', trim($p[1]), $m)) {
            return null;
        }
        $sec = (int)$m[1];

        return [
            'nro_orden'  => $nro,
            'secuencia'  => $sec,
            'total'      => (int)$m[2],
            'codigo'     => self::codigo($nro, $sec),
            'provincia'  => isset($p[2]) ? trim($p[2]) : '',
            'apellido'   => isset($p[3]) ? trim($p[3]) : '',
        ];
    }

    /** Código del ítem desde nro_orden + secuencia (idéntico a ProcesadorCarga). */
    public static function codigo(string $nroOrden, int $secuencia): string
    {
        return $nroOrden . '-' . str_pad((string)$secuencia, 2, '0', STR_PAD_LEFT);
    }

    /** Quita separadores y colapsa espacios para no romper el formato del payload. */
    private static function limpiar(string $v): string
    {
        $v = str_replace(self::SEP, ' ', $v);
        return trim((string)preg_replace('/\s+/', ' ', $v));
    }
}

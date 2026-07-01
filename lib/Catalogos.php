<?php
declare(strict_types=1);

namespace Trazock;

use Trazock\Models\Acompanante;
use Trazock\Models\Categoria;
use Trazock\Models\HojaRuta;
use Trazock\Models\Motivo;
use Trazock\Models\Orden;
use Trazock\Models\Proveedor;
use Trazock\Models\Usuario;
use Trazock\Models\Vehiculo;
use Trazock\Models\Zona;

/**
 * Arma el paquete de catálogos auxiliares que consume la app de escaneo.
 * Incluye los tipos de lote permitidos para el rol del usuario.
 */
final class Catalogos
{
    /**
     * @return array<string, mixed>
     */
    public static function para(string $rol): array
    {
        return [
            'categorias'      => Categoria::activas(),
            'proveedores'     => Proveedor::activos(),
            'motivos'         => Motivo::activosAgrupados(),
            'transportistas'  => Usuario::transportistasActivos(),
            'vehiculos'       => Vehiculo::activos(),
            'acompanantes'    => Acompanante::activos(),
            'zonas'           => Zona::activasConLocalidades(),
            'hojas_ruta'      => HojaRuta::abiertasParaScan(),
            'tipos_permitidos' => MaquinaEstados::tiposPermitidos($rol),
            'ordenes_no_entregar' => Orden::noEntregar(),
            'last_updated'    => date('c'),
        ];
    }
}

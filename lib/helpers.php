<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(?string $value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('flash_set')) {
    /**
     * Guarda un mensaje flash para mostrar tras un redirect (patrón PRG).
     * $tipo: 'success' | 'danger' | 'warning' | 'info' (clases de alerta Bootstrap).
     */
    function flash_set(string $tipo, string $mensaje): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['flash'] = ['tipo' => $tipo, 'mensaje' => $mensaje];
    }
}

if (!function_exists('flash_get')) {
    /**
     * Devuelve y consume el mensaje flash, o null si no hay.
     *
     * @return array{tipo:string, mensaje:string}|null
     */
    function flash_get(): ?array
    {
        if (empty($_SESSION['flash'])) {
            return null;
        }
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
}

if (!function_exists('fmt_fecha')) {
    /**
     * Formatea un datetime guardado en UTC a la zona de visualización (DISPLAY_TZ),
     * en formato dd/mm/yy hh:mm. Devuelve '—' si está vacío.
     */
    function fmt_fecha(?string $utc, string $formato = 'd/m/y H:i'): string
    {
        if ($utc === null || $utc === '' || str_starts_with($utc, '0000')) {
            return '—';
        }
        try {
            $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
            $tz = defined('DISPLAY_TZ') ? DISPLAY_TZ : 'UTC';
            return $dt->setTimezone(new DateTimeZone($tz))->format($formato);
        } catch (Throwable) {
            return (string)$utc;
        }
    }
}

if (!function_exists('lote_num')) {
    /** Número de lote legible: L-AAAA-NNNN (año de creación + id correlativo). */
    function lote_num(int $id, ?string $fechaCreacion = null): string
    {
        $anio = $fechaCreacion && strlen($fechaCreacion) >= 4 ? substr($fechaCreacion, 0, 4) : date('Y');
        return 'L-' . $anio . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('carga_num')) {
    /** Número de carga legible: C-AAAA-NNN (año de creación + id correlativo). */
    function carga_num(int $id, ?string $fechaCreacion = null): string
    {
        $anio = $fechaCreacion && strlen($fechaCreacion) >= 4 ? substr($fechaCreacion, 0, 4) : date('Y');
        return 'C-' . $anio . '-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('estado_badge')) {
    /** Badge de estado de producto (tema oscuro: clase b-ESTADO). */
    function estado_badge(?string $estado): string
    {
        $e = $estado ?? '';
        return '<span class="badge b-' . h($e) . '">' . h($e !== '' ? $e : '—') . '</span>';
    }
}

if (!function_exists('tipo_lote_label')) {
    /** Etiqueta legible de un tipo de lote. */
    function tipo_lote_label(?string $tipo): string
    {
        $map = [
            'INGRESO'           => 'Ingreso',
            'SALIDA_REPARTO'    => 'Salida a reparto',
            'ENTREGA'           => 'Entrega',
            'REINGRESO'         => 'Reingreso',
            'SALIDA_DEVOLUCION' => 'Devolución a proveedor',
            'BAJA'              => 'Baja',
        ];
        return $map[$tipo] ?? (string)$tipo;
    }
}

if (!function_exists('tipo_lote_badge')) {
    /** Badge de tipo de lote (clase b-TIPO + etiqueta legible). */
    function tipo_lote_badge(?string $tipo): string
    {
        return '<span class="badge b-' . h($tipo ?? '') . '">' . h(tipo_lote_label($tipo)) . '</span>';
    }
}

if (!function_exists('rol_badge')) {
    /** Badge de rol de usuario (clase b-rol). */
    function rol_badge(?string $rol): string
    {
        $map = [
            'admin' => 'Admin', 'gestor' => 'Supervisor',
            'operador' => 'Operador', 'transportista' => 'Transportista',
        ];
        return '<span class="badge b-' . h($rol ?? '') . '">' . h($map[$rol] ?? (string)$rol) . '</span>';
    }
}

if (!function_exists('conflicto_tipo_label')) {
    /** Etiqueta legible de un tipo de conflicto. */
    function conflicto_tipo_label(?string $tipo): string
    {
        $map = [
            'transicion_ilegal'                    => 'Transición ilegal',
            'producto_inexistente_en_no_ingreso'   => 'Producto inexistente',
        ];
        return $map[$tipo] ?? (string)$tipo;
    }
}

if (!function_exists('conflicto_badge')) {
    /** Badge de tipo de conflicto (clase b-<tipo>). */
    function conflicto_badge(?string $tipo): string
    {
        return '<span class="badge b-' . h($tipo ?? '') . '">' . h(conflicto_tipo_label($tipo)) . '</span>';
    }
}

if (!function_exists('resultado_badge')) {
    /** Badge para el resultado de un lote_item. */
    function resultado_badge(?string $resultado): string
    {
        $map = [
            'aplicado'                 => 'Aplicado',
            'aplicado_con_conflicto'   => 'Aplicado con conflicto',
            'ignorado_duplicado_lote'  => 'Ignorado (duplicado)',
            'ignorado_mismo_estado'    => 'Ignorado (mismo estado)',
        ];
        $texto = $map[$resultado] ?? (string)$resultado;
        return '<span class="badge b-' . h($resultado ?? '') . '">' . h($texto) . '</span>';
    }
}

if (!function_exists('seguimiento_url')) {
    /**
     * URL ABSOLUTA de la landing pública de seguimiento para un token.
     * Usa APP_URL (no la base relativa al host) porque este enlace se envía al
     * cliente por fuera de la app (WhatsApp / email) y debe resolver siempre.
     */
    function seguimiento_url(string $token): string
    {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return $base . '/seguimiento/?t=' . rawurlencode($token);
    }
}

if (!function_exists('seguimiento_orden_url')) {
    /**
     * URL ABSOLUTA de seguimiento por Nº de orden (flujo principal del comprador).
     * Es el enlace que se le comparte al cliente: no expone datos, solo el estado
     * público. Usa APP_URL para que resuelva fuera de la app (WhatsApp/email).
     */
    function seguimiento_orden_url(string $nroOrden): string
    {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return $base . '/seguimiento/?orden=' . rawurlencode($nroOrden);
    }
}

if (!function_exists('flash_render')) {
    /** Imprime el flash como alerta Bootstrap si existe. */
    function flash_render(): void
    {
        $f = flash_get();
        if ($f === null) {
            return;
        }
        printf(
            '<div class="alert alert-%s alert-dismissible fade show" role="alert">%s'
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button></div>',
            h($f['tipo']),
            h($f['mensaje'])
        );
    }
}

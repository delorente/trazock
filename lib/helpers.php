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
            'logistica' => 'Logística', 'contable' => 'Contable',
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

if (!function_exists('remitos_dir')) {
    /**
     * Carpeta (única, sin subcarpetas) donde se guardan las fotos de remitos
     * firmados de las entregas. Configurable con REMITOS_DIR (conviene una ruta
     * FUERA del webroot en producción); por defecto, storage/remitos del proyecto.
     * Crea la carpeta si no existe. Devuelve la ruta absoluta sin barra final.
     */
    function remitos_dir(): string
    {
        $dir = defined('REMITOS_DIR') && REMITOS_DIR !== ''
            ? (string)REMITOS_DIR
            : dirname(__DIR__) . '/storage/remitos';
        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('tel_e164')) {
    /**
     * Normaliza un teléfono argentino a E.164 (sin el '+'), apto para WhatsApp.
     * `ordenes.telefonos` es texto libre y puede traer varios números, prefijos
     * 0/15, paréntesis, guiones, etc. Tomamos el PRIMER número y lo llevamos a
     * 549 + área + abonado. Best-effort: devuelve null si no parece un móvil
     * argentino plausible (se podrá corregir a mano).
     *
     *   "11 4567-8900"        → 5491145678900
     *   "0381 15 555-1234"    → 5493815551234
     *   "+54 9 11 4567 8900"  → 5491145678900
     */
    function tel_e164(?string $raw): ?string
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        // Primer número de la lista (corta en separadores de "varios teléfonos":
        // / ; , * o el texto " o "/" y ").
        $primero = preg_split('/[\/;,*]| o | y /iu', $raw)[0] ?? $raw;
        $d = preg_replace('/\D+/', '', $primero) ?? '';
        if ($d === '') {
            return null;
        }
        // Pelamos, en orden, los prefijos que NO forman parte del área+abonado:
        //   00 internacional · 54 país · 9 móvil · 0 larga distancia.
        $d = preg_replace('/^00/', '', $d);
        $d = preg_replace('/^54/', '', $d);  // país
        $d = preg_replace('/^9/',  '', $d);  // 9 de móvil (no hay áreas que empiecen en 9)
        $d = preg_replace('/^0/',  '', $d);  // 0 de larga distancia
        // Sacar el "15" de móvil intercalado tras el código de área (2-4 dígitos).
        // El abonado nacional (área + número) siempre suma 10 dígitos; con el 15 son
        // 11/12. OJO: solo si sobran dígitos — nunca tocar un número que ya quedó en
        // 10, porque podría tener un "15" legítimo en el abonado (ej. 3815180324).
        if (strlen($d) === 11 || strlen($d) === 12) {
            $sin15 = preg_replace('/^(\d{2,4})15(\d{6,8})$/', '$1$2', $d, 1) ?? $d;
            if (strlen($sin15) === 10) {
                $d = $sin15;
            }
        }

        if (strlen($d) !== 10) {
            return null; // área + abonado debe ser 10 dígitos
        }
        return '549' . $d;
    }
}

if (!function_exists('local_url')) {
    /**
     * URL ABSOLUTA del listado público de un local (por token de prefijo). Es el
     * link que se le comparte al local para ver el estado de SUS órdenes. Usa
     * APP_URL para que resuelva fuera de la app.
     */
    function local_url(string $token): string
    {
        $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';
        return $base . '/seguimiento/local.php?t=' . rawurlencode($token);
    }
}

if (!function_exists('filtro_multi_valores')) {
    /**
     * Normaliza un filtro multi-valor de $_GET (name="campo[]") a array de strings
     * no vacíos. Tolera que venga como escalar (un solo valor) o ausente.
     *
     * @return array<int, string>
     */
    function filtro_multi_valores(string $clave): array
    {
        $v = $_GET[$clave] ?? [];
        $v = is_array($v) ? $v : [$v];
        return array_values(array_filter(
            array_map(static fn($x) => trim((string)$x), $v),
            static fn(string $x): bool => $x !== ''
        ));
    }
}

if (!function_exists('filtro_multi_dropdown')) {
    /**
     * Filtro multi-valor para formularios GET: botón desplegable con checkboxes
     * (name="<campo>[]"). Comparte estilo con el panel; el contador del rótulo lo
     * actualiza un script con la clase .tz-multi.
     *
     * @param array<int, array{0:int|string, 1:string}> $opciones  [valor, etiqueta]
     * @param array<int, string> $sel  valores seleccionados
     */
    function filtro_multi_dropdown(string $label, string $campo, array $opciones, array $sel): void
    {
        $n = count($sel);
        ?>
        <div>
          <label class="form-label"><?= h($label) ?></label>
          <div class="dropdown tz-multi">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle w-100 d-flex justify-content-between align-items-center"
                    type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-multi-label="<?= h($label) ?>">
              <span class="text-truncate"><?= $n ? h($label) . ' (' . $n . ')' : 'Todas' ?></span>
            </button>
            <div class="dropdown-menu p-2" style="max-height:260px;overflow:auto;min-width:230px">
              <?php if ($opciones === []): ?>
                <div class="text-muted small px-1">Sin opciones aún</div>
              <?php else: foreach ($opciones as $i => [$val, $txt]):
                $val = (string)$val;
                // id único por índice: dos valores que difieren solo en signos/espacios
                // colapsaban al mismo id y se pisaban al tildar (bug "marqué 6, quedaron 4").
                $id  = $campo . '_' . $i;
              ?>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="<?= h($campo) ?>[]" value="<?= h($val) ?>"
                         id="<?= h($id) ?>" <?= in_array($val, $sel, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small text-truncate d-block" for="<?= h($id) ?>"><?= h($txt) ?></label>
                </div>
              <?php endforeach; endif; ?>
            </div>
          </div>
        </div>
        <?php
    }
}

if (!function_exists('filtro_multi_script')) {
    /** Script que actualiza el rótulo de cada .tz-multi con la cantidad tildada. */
    function filtro_multi_script(): void
    {
        ?>
        <script>
        document.querySelectorAll('.tz-multi').forEach(function (dd) {
          var btn = dd.querySelector('[data-multi-label]'); if (!btn) return;
          var base = btn.getAttribute('data-multi-label'), span = btn.querySelector('span');
          dd.addEventListener('change', function () {
            var n = dd.querySelectorAll('input:checked').length;
            span.textContent = n ? base + ' (' + n + ')' : 'Todas';
          });
        });
        </script>
        <?php
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

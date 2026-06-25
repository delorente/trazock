<?php
declare(strict_types=1);

// =============================================================================
// seguimiento/index.php — Landing PÚBLICA de seguimiento para el comprador.
//
// Flujo principal (módulo de órdenes): el comprador ingresa su Nº de orden
// (el del QR/etiqueta, sin token) → /seguimiento/?orden=ON-0775-XXXXXXXX. Se
// muestra el estado público de la orden, derivado de sus ítems. Si el número no
// está aún en la BD, se muestra un pseudo-estado "en tránsito al centro de
// distribución" (el comprador suele tener el número antes de que ingrese el camión).
//
// Compatibilidad: /seguimiento/?t=<32 hex> sigue mostrando el estado de un ÍTEM
// por token opaco (links viejos). En ningún caso se exponen datos del cliente:
// solo el estado público + fecha. Los textos se editan en admin/seguimiento.php.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;
use Trazock\Models\Encuesta;
use Trazock\Models\EstadoPublico;
use Trazock\Models\Orden;
use Trazock\Models\Producto;
use Trazock\Models\Transicion;

// Íconos (Bootstrap Icons) por estado para la línea de tiempo. Decorativos.
$ICONOS = [
    'EN_TRANSITO' => 'truck',
    'INGRESADO'   => 'box-seam',
    'EN_REPARTO'  => 'truck',
    'ENTREGADO'   => 'house-check',
    'REINGRESADO' => 'arrow-counterclockwise',
    'DEVUELTO'    => 'arrow-return-left',
    'BAJA'        => 'dash-circle',
];

/** Cabecera HTML del tema claro público (independiente del panel oscuro). */
function seg_head(string $titulo, string $extraHead = ''): void
{
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/png" href="<?= h(asset('favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= h(asset('favicon.png')) ?>">
    <title><?= h($titulo) ?></title>
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <style>
        body{font-family:'Inter',system-ui,sans-serif;background:#f4f6f9;color:#1f2937;margin:0}
        .seg-wrap{max-width:560px;margin:0 auto;padding:1.5rem 1rem 3rem}
        .seg-brand{display:flex;align-items:center;justify-content:center;margin:.25rem 0 1.5rem}
        .seg-logo{max-width:200px;width:60%;height:auto;border-radius:10px;background:#fff}
        .seg-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        .seg-hero{padding:1.6rem 1.4rem;text-align:center}
        .seg-hero .ic{width:64px;height:64px;border-radius:50%;background:#e7f1ff;color:#0d6efd;display:inline-flex;align-items:center;justify-content:center;font-size:1.9rem;margin-bottom:.8rem}
        .seg-hero h1{font-size:1.4rem;font-weight:700;margin:0 0 .4rem}
        .seg-hero p{font-size:.95rem;color:#4b5563;margin:0;line-height:1.5}
        .seg-nro{font-size:.8rem;color:#6b7280;margin-top:.6rem;font-weight:600;letter-spacing:.02em}
        .seg-meta{font-size:.78rem;color:#9ca3af;margin-top:.9rem}
        .seg-steps{list-style:none;margin:0;padding:1.2rem 1.4rem}
        .seg-step{display:flex;gap:.9rem;position:relative;padding-bottom:1.3rem}
        .seg-step:last-child{padding-bottom:0}
        .seg-step:not(:last-child)::before{content:"";position:absolute;left:15px;top:34px;bottom:2px;width:2px;background:#e5e7eb}
        .seg-step.done:not(:last-child)::before{background:#22c55e}
        .seg-dot{flex:0 0 auto;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;background:#eef0f3;color:#9ca3af;border:2px solid #eef0f3;z-index:1}
        .seg-step.done .seg-dot{background:#22c55e;border-color:#22c55e;color:#fff}
        .seg-step.current .seg-dot{background:#0d6efd;border-color:#0d6efd;color:#fff;box-shadow:0 0 0 4px rgba(13,110,253,.18)}
        .seg-step .body{padding-top:.2rem}
        .seg-step .body .t{font-weight:600;font-size:.98rem;color:#374151}
        .seg-step.pending .body .t{color:#9ca3af}
        .seg-step.current .body .t{color:#0d6efd}
        .seg-step .body .d{font-size:.85rem;color:#6b7280;margin-top:.15rem;line-height:1.45}
        .seg-step .body .f{font-size:.75rem;color:#9ca3af;margin-top:.25rem}
        .seg-foot{text-align:center;font-size:.78rem;color:#9ca3af;margin-top:1.4rem}
        .seg-powered{display:inline-flex;align-items:center;gap:.4rem;margin-top:.35rem;font-size:.72rem;color:#9ca3af}
        .seg-powered .tzbox{width:15px;height:15px;border-radius:4px;background:#0d6efd;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:8px}
        .seg-powered strong{color:#6b7280;font-weight:700}
        .seg-empty{padding:2.4rem 1.4rem;text-align:center}
        .seg-empty .ic{font-size:2.6rem;color:#d1d5db}
        .seg-empty h1{font-size:1.2rem;font-weight:700;margin:.6rem 0 .4rem}
        .seg-empty p{font-size:.9rem;color:#6b7280;margin:0}
        .seg-form{padding:0 1.4rem 1.6rem}
        .seg-input{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:.8rem .9rem;font-size:1rem;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.75rem}
        .seg-input:focus{outline:none;border-color:#0d6efd;box-shadow:0 0 0 3px rgba(13,110,253,.15)}
        .seg-btn{width:100%;border:none;border-radius:10px;background:#0d6efd;color:#fff;font-weight:700;padding:.8rem;font-size:1rem;cursor:pointer}
        .seg-btn:hover{background:#0b5ed7}
        .seg-note{font-size:.8rem;color:#9ca3af;text-align:center;margin-top:.9rem}
    </style>
    <?= $extraHead ?>
</head>
<body>
<div class="seg-wrap">
    <div class="seg-brand"><img src="<?= h(asset('assets/img/logo.jpg')) ?>" alt="Corredora de Servicios S.A." class="seg-logo"></div>
    <?php
}

function seg_foot(): void
{
    ?>
    <div class="seg-foot">
        Seguimiento de tu pedido
        <div class="seg-powered"><span class="tzbox"><i class="bi bi-upc-scan"></i></span> powered by <strong>Trazock</strong></div>
    </div>
</div>
</body>
</html>
    <?php
}

/**
 * Tarjeta de estado público (hero + línea de tiempo). Reutilizada por el flujo
 * por orden y por el de token de ítem.
 *
 * @param array<string,string>      $iconos
 * @param array<string,string>      $fechas  estado => fecha (vacío para órdenes)
 */
function seg_card(string $estadoActual, array $fechas, ?string $ultima, ?string $nro, array $iconos): void
{
    $mapa        = EstadoPublico::mapa();
    $pasos       = EstadoPublico::pasosVisibles();
    $actual      = $mapa[$estadoActual] ?? null;
    $ordenActual = $actual !== null ? (int)$actual['orden'] : 0;
    $iconoActual = $iconos[$estadoActual] ?? 'box-seam';
    ?>
    <div class="seg-card">
        <div class="seg-hero">
            <div class="ic"><i class="bi bi-<?= h($iconoActual) ?>"></i></div>
            <h1><?= h($actual['titulo'] ?? 'Seguimiento de tu pedido') ?></h1>
            <?php if ($actual !== null && $actual['descripcion'] !== ''): ?>
                <p><?= h($actual['descripcion']) ?></p>
            <?php endif; ?>
            <?php if ($nro !== null && $nro !== ''): ?>
                <div class="seg-nro">Orden <?= h($nro) ?></div>
            <?php endif; ?>
            <?php if (!empty($ultima)): ?>
                <div class="seg-meta">Última actualización: <?= h(fmt_fecha($ultima)) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($pasos !== []): ?>
        <hr class="m-0">
        <ul class="seg-steps">
            <?php foreach ($pasos as $paso):
                $e     = (string)$paso['estado'];
                $orden = (int)$paso['orden'];

                if ($ordenActual > 0) {
                    $clase = $orden < $ordenActual ? 'done'
                           : ($orden === $ordenActual ? 'current' : 'pending');
                } else {
                    $clase = isset($fechas[$e]) ? 'done' : 'pending';
                }

                $icono = $iconos[$e] ?? 'circle';
                $fecha = $fechas[$e] ?? null;
            ?>
                <li class="seg-step <?= $clase ?>">
                    <div class="seg-dot">
                        <?php if ($clase === 'done'): ?>
                            <i class="bi bi-check-lg"></i>
                        <?php else: ?>
                            <i class="bi bi-<?= h($icono) ?>"></i>
                        <?php endif; ?>
                    </div>
                    <div class="body">
                        <div class="t"><?= h($paso['titulo']) ?></div>
                        <?php if ($clase !== 'pending' && $paso['descripcion'] !== ''): ?>
                            <div class="d"><?= h($paso['descripcion']) ?></div>
                        <?php endif; ?>
                        <?php if ($fecha !== null && $clase !== 'pending'): ?>
                            <div class="f"><i class="bi bi-clock me-1"></i><?= h(fmt_fecha($fecha)) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php
}

/** Formulario de ingreso del Nº de orden (pantalla inicial pública). */
function seg_form(?string $valor = null): void
{
    ?>
    <div class="seg-card">
        <div class="seg-hero" style="padding-bottom:.4rem">
            <div class="ic"><i class="bi bi-search"></i></div>
            <h1>Seguí tu pedido</h1>
            <p>Ingresá el número de orden que recibiste.</p>
        </div>
        <form class="seg-form" method="get" action="">
            <input class="seg-input" type="text" name="orden" placeholder="0775-XXXXXXXX"
                   value="<?= h($valor ?? '') ?>" autocomplete="off" autofocus required>
            <button class="seg-btn" type="submit"><i class="bi bi-search me-2"></i>Seguir mi pedido</button>
        </form>
    </div>
    <?php
}

/** Emoji + etiqueta de un nivel 1-4 de la encuesta (para resúmenes server-side). */
function enc_nivel(int $v): string
{
    $em = ['', '😞', '😐', '😊', '😃'];
    $lb = ['', 'Muy malo', 'Regular', 'Bueno', 'Excelente'];
    return isset($em[$v]) && $v >= 1 ? $em[$v] . ' ' . $lb[$v] : '—';
}

/**
 * Encuesta de satisfacción embebida (3 pasos). Se muestra solo cuando la orden
 * está ENTREGADO y aún no fue respondida. Envía por fetch al endpoint público.
 */
function seg_encuesta_form(string $nroOrden, string $csrf): void
{
    $aspectos = [
        'tiempo'  => ['Tiempo de entrega',   'clock'],
        'paquete' => ['Estado del paquete',  'box-seam'],
        'trato'   => ['Trato del repartidor', 'person-check'],
    ];
    ?>
    <div class="enc-card" id="encCard">
        <!-- Paso 1: calificación general -->
        <div class="enc-view on" id="encV1">
            <div class="enc-inner">
                <div class="enc-progress"><div class="enc-pd active"></div><div class="enc-pd"></div></div>
                <div class="enc-title">¿Cómo fue tu experiencia?</div>
                <div class="enc-sub">Contanos cómo resultó la entrega de tu pedido. Tu opinión nos ayuda a mejorar el servicio.</div>
                <div class="enc-eg" id="encGeneral">
                    <button type="button" class="enc-eb" data-v="1"><span class="em">😞</span><span class="lb">Muy malo</span></button>
                    <button type="button" class="enc-eb" data-v="2"><span class="em">😐</span><span class="lb">Regular</span></button>
                    <button type="button" class="enc-eb" data-v="3"><span class="em">😊</span><span class="lb">Bueno</span></button>
                    <button type="button" class="enc-eb" data-v="4"><span class="em">😃</span><span class="lb">Excelente</span></button>
                </div>
            </div>
        </div>

        <!-- Paso 2: aspectos + comentario -->
        <div class="enc-view" id="encV2">
            <div class="enc-inner">
                <div class="enc-progress"><div class="enc-pd done"></div><div class="enc-pd active"></div></div>
                <div class="enc-h2">Contanos un poco más</div>
                <div class="enc-h2-sub">Calificá cada aspecto de la entrega</div>
                <?php foreach ($aspectos as $key => [$label, $icono]): ?>
                <div class="enc-aspect">
                    <div class="enc-aspect-label"><i class="bi bi-<?= h($icono) ?>"></i><?= h($label) ?></div>
                    <div class="enc-egs" data-aspect="<?= h($key) ?>">
                        <button type="button" class="enc-ebs" data-v="1"><span class="em">😞</span><span class="lb">Muy malo</span></button>
                        <button type="button" class="enc-ebs" data-v="2"><span class="em">😐</span><span class="lb">Regular</span></button>
                        <button type="button" class="enc-ebs" data-v="3"><span class="em">😊</span><span class="lb">Bueno</span></button>
                        <button type="button" class="enc-ebs" data-v="4"><span class="em">😃</span><span class="lb">Excelente</span></button>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="enc-div"></div>
                <label class="enc-lbl" for="encComentario">Comentario <span style="font-weight:400;letter-spacing:0;text-transform:none;color:#9ca3af">(opcional)</span></label>
                <textarea class="enc-ta" id="encComentario" maxlength="1000" placeholder="¿Querés contarnos algo más? Cualquier detalle nos ayuda a mejorar."></textarea>

                <div class="enc-err" id="encError"></div>
                <button type="button" class="enc-btn" id="encSubmit"><i class="bi bi-send-fill" style="font-size:.8rem"></i>Enviar calificación</button>
            </div>
        </div>

        <!-- Paso 3: gracias -->
        <div class="enc-view" id="encV3">
            <div class="enc-celebrate-bar"></div>
            <div class="enc-ty-inner">
                <div class="enc-ty-ic" id="encTyIc">😊</div>
                <div class="enc-ty-title">¡Gracias por tu opinión!</div>
                <div class="enc-ty-sub">Tu calificación nos ayuda a mejorar el servicio de entrega.</div>
                <div class="enc-summary">
                    <div class="enc-sr"><span class="enc-sr-lbl">Satisfacción general</span><span class="enc-sr-val" id="encTyGen">—</span></div>
                    <div class="enc-sr-divider"></div>
                    <div class="enc-sr"><span class="enc-sr-lbl">Tiempo de entrega</span><span class="enc-sr-val" id="encTyTpo">—</span></div>
                    <div class="enc-sr"><span class="enc-sr-lbl">Estado del paquete</span><span class="enc-sr-val" id="encTyPkg">—</span></div>
                    <div class="enc-sr"><span class="enc-sr-lbl">Trato del repartidor</span><span class="enc-sr-val" id="encTyTrt">—</span></div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function(){
        const EMOJIS = ['','😞','😐','😊','😃'];
        const LABELS = ['','Muy malo','Regular','Bueno','Excelente'];
        const ENDPOINT = <?= json_encode(url('api/encuesta-enviar.php')) ?>;
        const NRO = <?= json_encode($nroOrden) ?>;
        const CSRF = <?= json_encode($csrf) ?>;
        const ratings = {general:0, tiempo:0, paquete:0, trato:0};
        let sending = false;

        function show(n){
            document.querySelectorAll('#encCard .enc-view').forEach(v => v.classList.remove('on'));
            document.getElementById('encV'+n).classList.add('on');
            document.getElementById('encCard').scrollIntoView({behavior:'smooth', block:'start'});
        }
        function fmt(v){ return v ? EMOJIS[v]+' '+LABELS[v] : '—'; }

        // Paso 1: calificación general → avanza a paso 2.
        document.querySelectorAll('#encGeneral .enc-eb').forEach(b => b.addEventListener('click', function(){
            ratings.general = +b.dataset.v;
            document.querySelectorAll('#encGeneral .enc-eb').forEach(x => x.classList.toggle('sel', x === b));
            setTimeout(() => show(2), 320);
        }));

        // Paso 2: aspectos.
        document.querySelectorAll('#encV2 .enc-egs').forEach(grid => {
            const aspecto = grid.dataset.aspect;
            grid.querySelectorAll('.enc-ebs').forEach(b => b.addEventListener('click', function(){
                ratings[aspecto] = +b.dataset.v;
                grid.querySelectorAll('.enc-ebs').forEach(x => x.classList.toggle('sel', x === b));
                document.getElementById('encError').classList.remove('on');
            }));
        });

        // Envío.
        document.getElementById('encSubmit').addEventListener('click', async function(){
            if (sending) return;
            const err = document.getElementById('encError');
            if (!ratings.tiempo || !ratings.paquete || !ratings.trato){
                err.textContent = 'Calificá los tres aspectos antes de enviar.';
                err.classList.add('on');
                return;
            }
            sending = true;
            this.disabled = true;
            err.classList.remove('on');
            try {
                const res = await fetch(ENDPOINT, {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({
                        csrf_token: CSRF, orden: NRO,
                        general: ratings.general, tiempo: ratings.tiempo,
                        paquete: ratings.paquete, trato: ratings.trato,
                        comentario: document.getElementById('encComentario').value.trim()
                    })
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok || !data.ok){
                    throw new Error(data.error || 'No pudimos registrar tu calificación.');
                }
                document.getElementById('encTyIc').textContent  = EMOJIS[ratings.general] || '😊';
                document.getElementById('encTyGen').textContent = fmt(ratings.general);
                document.getElementById('encTyTpo').textContent = fmt(ratings.tiempo);
                document.getElementById('encTyPkg').textContent = fmt(ratings.paquete);
                document.getElementById('encTyTrt').textContent = fmt(ratings.trato);
                show(3);
            } catch(e){
                err.textContent = e.message || 'No pudimos registrar tu calificación. Probá de nuevo.';
                err.classList.add('on');
                sending = false;
                this.disabled = false;
            }
        });
    })();
    </script>
    <?php
}

/** Resumen "ya respondiste" (cuando la orden ya tiene encuesta). */
function seg_encuesta_gracias(array $e): void
{
    ?>
    <div class="enc-card">
        <div class="enc-celebrate-bar"></div>
        <div class="enc-ty-inner">
            <div class="enc-ty-ic"><?= h(['', '😞', '😐', '😊', '😃'][(int)$e['general']] ?? '😊') ?></div>
            <div class="enc-ty-title">¡Gracias por tu opinión!</div>
            <div class="enc-ty-sub">Ya recibimos tu calificación de esta entrega.</div>
            <div class="enc-summary">
                <div class="enc-sr"><span class="enc-sr-lbl">Satisfacción general</span><span class="enc-sr-val"><?= h(enc_nivel((int)$e['general'])) ?></span></div>
                <div class="enc-sr-divider"></div>
                <div class="enc-sr"><span class="enc-sr-lbl">Tiempo de entrega</span><span class="enc-sr-val"><?= h(enc_nivel((int)$e['tiempo'])) ?></span></div>
                <div class="enc-sr"><span class="enc-sr-lbl">Estado del paquete</span><span class="enc-sr-val"><?= h(enc_nivel((int)$e['paquete'])) ?></span></div>
                <div class="enc-sr"><span class="enc-sr-lbl">Trato del repartidor</span><span class="enc-sr-val"><?= h(enc_nivel((int)$e['trato'])) ?></span></div>
                <?php if (!empty($e['comentario'])): ?>
                    <div class="enc-sr-divider"></div>
                    <div class="enc-sr" style="flex-direction:column;align-items:flex-start;gap:4px">
                        <span class="enc-sr-lbl">Tu comentario</span>
                        <span style="font-size:.85rem;color:#1f2937"><?= h((string)$e['comentario']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

// =============================================================================
// Despacho: ?t=<token> → ítem (compat); si no, por Nº de orden (flujo principal).
// =============================================================================
$token = trim((string)($_GET['t'] ?? ''));

if ($token !== '') {
    $prod = Producto::findByToken($token);
    if ($prod === null) {
        http_response_code(404);
        seg_head('Seguimiento no disponible');
        ?>
        <div class="seg-card seg-empty">
            <div class="ic"><i class="bi bi-link-45deg"></i></div>
            <h1>Enlace no disponible</h1>
            <p>El enlace de seguimiento no es válido o ya no está disponible.
               Verificá que hayas copiado la dirección completa.</p>
        </div>
        <?php
        seg_foot();
        exit;
    }
    $estadoActual = (string)$prod['estado_actual'];
    $fechas       = Transicion::fechasPorEstado((int)$prod['id']);
    seg_head('Seguimiento de tu pedido');
    seg_card($estadoActual, $fechas, $prod['updated_at'] ?? null, null, $ICONOS);
    seg_foot();
    exit;
}

// ----- Flujo por Nº de orden -------------------------------------------------
$num = trim((string)($_GET['orden'] ?? ''));

if ($num === '') {
    seg_head('Seguí tu pedido');
    seg_form();
    seg_foot();
    exit;
}

// Búsqueda tolerante: probamos varias formas de lo que tipeó el comprador.
//  - tal cual,
//  - sin el prefijo "ON-"/"ON " (las órdenes se guardan sin él),
//  - sin el sufijo de ítem "-NN" (por si pegaron el código de la etiqueta).
$candidatos = [];
$sinOn = preg_replace('/^\s*ON[\s-]+/i', '', $num);
foreach ([$num, $sinOn] as $cand) {
    $cand = trim((string)$cand);
    if ($cand === '') { continue; }
    $candidatos[] = $cand;
    if (preg_match('/^(.+)-\d{2}$/', $cand, $mm)) { $candidatos[] = $mm[1]; }
}

$orden = null;
foreach (array_values(array_unique($candidatos)) as $cand) {
    $orden = Orden::findByNroOrden($cand);
    if ($orden !== null) { $num = $cand; break; }
}

if ($orden !== null) {
    $ordenId = (int)$orden['id'];
    // Estado público derivado de los ítems, con la fecha de cada paso del timeline.
    $estado = Orden::estadoProductoDerivado($ordenId) ?? 'INGRESADO';
    $fechas = Orden::fechasPorEstado($ordenId);

    // Encuesta de satisfacción: solo con el pedido ENTREGADO.
    $mostrarEncuesta = ($estado === 'ENTREGADO');
    $encuestaPrevia  = null;
    $csrfEncuesta    = '';
    if ($mostrarEncuesta) {
        $encuestaPrevia = Encuesta::findPorOrden($ordenId);
        if ($encuestaPrevia === null) {
            // Sesión pública solo para sembrar el token CSRF del envío.
            Auth::iniciarSesion();
            $csrfEncuesta = Auth::tokenCSRF();
        }
    }

    $extraHead = $mostrarEncuesta
        ? '<link rel="stylesheet" href="' . h(asset('assets/css/encuesta.css')) . '">'
        : '';

    seg_head('Seguimiento ' . $num, $extraHead);
    seg_card($estado, $fechas, $orden['updated_at'] ?? null, $num, $ICONOS);

    if ($mostrarEncuesta) {
        if ($encuestaPrevia !== null) {
            seg_encuesta_gracias($encuestaPrevia);
        } else {
            seg_encuesta_form($num, $csrfEncuesta);
        }
    }
    ?>
    <div class="seg-note"><a href="<?= h(url('seguimiento/')) ?>" style="color:#6b7280;text-decoration:none"><i class="bi bi-arrow-left me-1"></i>Buscar otro pedido</a></div>
    <?php
    seg_foot();
    exit;
}

// No encontrada (o aún no ingresada) → pseudo-estado "en tránsito al centro".
// Reusa el texto editable de EN_TRANSITO (admin/seguimiento.php).
$tr = EstadoPublico::mapa()['EN_TRANSITO'] ?? null;
seg_head('Seguimiento ' . $num);
?>
    <div class="seg-card">
        <div class="seg-hero">
            <div class="ic" style="background:#fff7e6;color:#d97706"><i class="bi bi-truck"></i></div>
            <h1><?= h($tr['titulo'] ?? 'En tránsito al centro de distribución') ?></h1>
            <p><?= h($tr['descripcion'] ?? 'Tu pedido todavía no llegó a nuestro depósito. Cuando lo recibamos vas a poder ver acá el detalle del seguimiento.') ?></p>
            <div class="seg-nro">Orden <?= h($num) ?></div>
        </div>
    </div>
    <div class="seg-note">
        ¿Creés que hay un error? Revisá el número de orden ·
        <a href="<?= h(url('seguimiento/')) ?>" style="color:#6b7280;text-decoration:none">Buscar de nuevo</a>
    </div>
<?php
seg_foot();

<?php
declare(strict_types=1);

// =============================================================================
// seguimiento/index.php — Landing PÚBLICA de seguimiento para el comprador.
//
// Acceso por token opaco: /seguimiento/?t=<32 hex>. Sin login y sin sesión (no
// se inicia sesión ni se emiten cookies para visitantes anónimos). Solo expone
// el texto público del estado del producto; nunca el código interno, el estado
// enum crudo, ni los conflictos. El texto de cada estado se edita en
// admin/seguimiento.php (tabla estados_publicos).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Models\EstadoPublico;
use Trazock\Models\Producto;
use Trazock\Models\Transicion;

$token = trim((string)($_GET['t'] ?? ''));
$prod  = $token !== '' ? Producto::findByToken($token) : null;

// Íconos (Bootstrap Icons) por estado para la línea de tiempo. Decorativos.
$ICONOS = [
    'INGRESADO'   => 'box-seam',
    'EN_REPARTO'  => 'truck',
    'ENTREGADO'   => 'house-check',
    'REINGRESADO' => 'arrow-counterclockwise',
    'DEVUELTO'    => 'arrow-return-left',
    'BAJA'        => 'dash-circle',
];

/** Cabecera HTML del tema claro público (independiente del panel oscuro). */
function seg_head(string $titulo): void
{
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($titulo) ?></title>
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <style>
        body{font-family:'Inter',system-ui,sans-serif;background:#f4f6f9;color:#1f2937;margin:0}
        .seg-wrap{max-width:560px;margin:0 auto;padding:1.5rem 1rem 3rem}
        .seg-brand{display:flex;align-items:center;gap:.55rem;justify-content:center;margin:.5rem 0 1.5rem;color:#0d6efd;font-weight:700;font-size:1.15rem;letter-spacing:-.02em}
        .seg-brand .box{width:28px;height:28px;border-radius:7px;background:#0d6efd;display:flex;align-items:center;justify-content:center;color:#fff}
        .seg-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        .seg-hero{padding:1.6rem 1.4rem;text-align:center}
        .seg-hero .ic{width:64px;height:64px;border-radius:50%;background:#e7f1ff;color:#0d6efd;display:inline-flex;align-items:center;justify-content:center;font-size:1.9rem;margin-bottom:.8rem}
        .seg-hero h1{font-size:1.4rem;font-weight:700;margin:0 0 .4rem}
        .seg-hero p{font-size:.95rem;color:#4b5563;margin:0;line-height:1.5}
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
        .seg-empty{padding:2.4rem 1.4rem;text-align:center}
        .seg-empty .ic{font-size:2.6rem;color:#d1d5db}
        .seg-empty h1{font-size:1.2rem;font-weight:700;margin:.6rem 0 .4rem}
        .seg-empty p{font-size:.9rem;color:#6b7280;margin:0}
    </style>
</head>
<body>
<div class="seg-wrap">
    <div class="seg-brand"><span class="box"><i class="bi bi-upc-scan"></i></span> Trazock</div>
    <?php
}

function seg_foot(): void
{
    ?>
    <div class="seg-foot">Seguimiento de tu pedido · Trazock</div>
</div>
</body>
</html>
    <?php
}

// -----------------------------------------------------------------------------
// Token inexistente o inválido → página neutra (no se distingue "no existe" de
// "mal formado", para no dar pistas a quien prueba tokens).
// -----------------------------------------------------------------------------
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
$mapa         = EstadoPublico::mapa();
$pasos        = EstadoPublico::pasosVisibles();
$fechas       = Transicion::fechasPorEstado((int)$prod['id']);

$actual      = $mapa[$estadoActual] ?? null;
$ordenActual = $actual !== null ? (int)$actual['orden'] : 0;
$iconoActual = $ICONOS[$estadoActual] ?? 'box-seam';

seg_head('Seguimiento de tu pedido');
?>
    <div class="seg-card">
        <div class="seg-hero">
            <div class="ic"><i class="bi bi-<?= h($iconoActual) ?>"></i></div>
            <h1><?= h($actual['titulo'] ?? 'Seguimiento de tu pedido') ?></h1>
            <?php if ($actual !== null && $actual['descripcion'] !== ''): ?>
                <p><?= h($actual['descripcion']) ?></p>
            <?php endif; ?>
            <?php if (!empty($prod['updated_at'])): ?>
                <div class="seg-meta">Última actualización: <?= h(fmt_fecha($prod['updated_at'])) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($pasos !== []): ?>
        <hr class="m-0">
        <ul class="seg-steps">
            <?php foreach ($pasos as $paso):
                $e     = (string)$paso['estado'];
                $orden = (int)$paso['orden'];

                // Estado del paso. Con un estado actual "lineal" (orden > 0) usamos
                // la comparación de orden; si el estado actual es excepcional
                // (reingreso/devuelto/baja → orden 0) marcamos como completados los
                // pasos que el producto efectivamente alcanzó, sin "paso actual".
                if ($ordenActual > 0) {
                    $clase = $orden < $ordenActual ? 'done'
                           : ($orden === $ordenActual ? 'current' : 'pending');
                } else {
                    $clase = isset($fechas[$e]) ? 'done' : 'pending';
                }

                $icono = $ICONOS[$e] ?? 'circle';
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
seg_foot();

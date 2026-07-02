<?php
declare(strict_types=1);

// =============================================================================
// admin/ruta-planificar.php — planificar el recorrido de una hoja de ruta
// (feature D, fase 2). Ordena las paradas (drag o "Sugerir orden"), las muestra
// en un mapa Leaflet con marcadores numerados y polilínea, y permite corregir la
// ubicación arrastrando el pin. Pantalla NUEVA: no toca las hojas existentes.
//
// Se llega desde el botón "Planificar recorrido" de hoja-ruta-armar.php (?id=).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

// CSP propio de ESTA pantalla: reemplaza al global (bootstrap.php) para permitir
// los tiles de OpenStreetMap en el mapa, sin relajar la política del resto de la
// app. Leaflet baja los tiles como <img>, por eso se suma el host a img-src.
if (!headers_sent()) {
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data: https://*.tile.openstreetmap.org; media-src 'self' blob:; "
        . "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; "
        . "connect-src 'self'; worker-src 'self'; manifest-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    );
}

use Trazock\Auth;
use Trazock\Models\HojaRuta;
use Trazock\Models\RutaSecuencia;

$user = Auth::requierePanel(['admin', 'logistica']);

$id   = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$hoja = $id > 0 ? HojaRuta::find($id) : null;

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/hoja-ruta-armar.php') . '?id=' . $id) . '"><i class="bi bi-arrow-left me-1"></i>Volver a la hoja</a>';

if ($hoja === null) {
    panel_header('Planificar recorrido', $user, 'hojas-ruta', '', $volver);
    echo '<div class="alert alert-warning">No se encontró la hoja de ruta.</div>';
    panel_footer();
    exit;
}

// ------------------------------------------------------------------ POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $esAjax = ($_POST['ajax'] ?? '') === '1';

    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        if ($esAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'csrf']);
            exit;
        }
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/ruta-planificar.php') . '?id=' . $id);
        exit;
    }

    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'set_pin') {
        // AJAX: guardar la corrección de un pin arrastrado.
        $tipo = (string)($_POST['tipo'] ?? 'orden');
        $ref  = (int)($_POST['ref_id'] ?? 0);
        $lat  = (float)str_replace(',', '.', (string)($_POST['lat'] ?? ''));
        $lng  = (float)str_replace(',', '.', (string)($_POST['lng'] ?? ''));
        $ok   = $ref > 0 && $lat !== 0.0 && $lng !== 0.0;
        if ($ok) {
            RutaSecuencia::setPin($id, $tipo, $ref, $lat, $lng);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'precision' => 'exacta']);
        exit;
    }

    if ($accion === 'guardar_orden') {
        $orden = array_filter(array_map('trim', explode(',', (string)($_POST['orden'] ?? ''))));
        RutaSecuencia::guardarOrden($id, array_values($orden));
        flash_set('success', 'Recorrido guardado.');
    } elseif ($accion === 'optimizar') {
        RutaSecuencia::optimizar($id);
        flash_set('success', 'Orden sugerido según cercanía. Ajustá lo que quieras y guardá.');
    }
    header('Location: ' . url('admin/ruta-planificar.php') . '?id=' . $id);
    exit;
}

// ------------------------------------------------------------------ GET (render)
$paradas = RutaSecuencia::paradas($id);
$csrf    = Auth::tokenCSRF();

$totBultos = 0;
$totM3     = 0.0;
foreach ($paradas as $p) {
    $totBultos += (int)$p['bultos'];
    $totM3     += (float)$p['m3'];
}

$editable  = $hoja['estado'] === 'abierta';
$estadoUp  = mb_strtoupper((string)$hoja['estado']);
$badgeCls  = $editable ? 'b-ABIERTA' : 'b-EMITIDA';

// Depósito propio (origen del recorrido), si está configurado. La ruta y los km
// arrancan desde acá; si no está seteado, arrancan en la primera parada ubicada.
$depot = (defined('DEPOT_LAT') && defined('DEPOT_LNG') && (string)DEPOT_LAT !== '' && (string)DEPOT_LNG !== '')
    ? ['lat' => (float)DEPOT_LAT, 'lng' => (float)DEPOT_LNG]
    : null;

// Datos de las paradas para el JS del mapa.
$stopsJs = array_map(static fn(array $p): array => [
    'tipo'      => $p['tipo'],
    'ref'       => $p['ref_id'],
    'num'       => $p['num'],
    'cliente'   => $p['cliente'],
    'nro'       => $p['nro_orden'],
    'localidad' => $p['localidad'],
    'lat'       => $p['lat'],
    'lng'       => $p['lng'],
    'precision' => $p['precision'],
    'ubicada'   => $p['ubicada'],
], $paradas);

panel_header('Planificar recorrido', $user, 'hojas-ruta',
    'Hoja ' . (string)$hoja['numero'], $volver);
flash_render();

/** Badge de precisión de ubicación. */
function rp_badge_precision(array $p): string
{
    if ($p['precision'] === 'exacta') {
        return '<span class="badge b-exacta"><i class="bi bi-geo-alt-fill me-1"></i>Exacta</span>';
    }
    if ($p['ubicada']) { // localidad / provincia
        return '<span class="badge b-localidad"><i class="bi bi-geo-alt me-1"></i>Solo localidad</span>';
    }
    return '<span class="badge b-sinubicar"><i class="bi bi-exclamation-triangle-fill me-1"></i>Sin ubicar</span>';
}

/** URL de navegación (Google Maps) hacia la parada, o '' si no está ubicada. */
function rp_como_llegar(array $p): string
{
    if (!$p['ubicada'] || $p['lat'] === null || $p['lng'] === null) {
        return '';
    }
    $dest = rawurlencode((string)$p['lat'] . ',' . (string)$p['lng']);
    return '<a class="stop-nav" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination='
        . $dest . '"><i class="bi bi-sign-turn-right-fill me-1"></i>Cómo llegar</a>';
}
?>
<link rel="stylesheet" href="<?= h(asset('assets/vendor/leaflet/leaflet.css')) ?>">
<style>
/* ── Planificar recorrido (clases propias; los tokens y .sumbar/.mono/.badge ya
      viven en app.css) ── */
.rh-field-l{font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:2px}
.rh-field-v{font-size:13px}
.rh-placeholder{color:var(--muted);font-style:italic}
.b-ABIERTA{background:rgba(59,130,246,.2);color:#60a5fa}
.b-EMITIDA{background:rgba(34,197,94,.2);color:#4ade80}
.b-exacta{background:rgba(34,197,94,.2);color:#4ade80}
.b-localidad{background:rgba(234,179,8,.2);color:#fbbf24}
.b-sinubicar{background:rgba(239,68,68,.2);color:#f87171}

.route-grid{display:grid;grid-template-columns:minmax(320px,2fr) minmax(380px,3fr);gap:1rem;align-items:start;margin-top:1rem}
@media(max-width:1180px){.route-grid{grid-template-columns:1fr}}

.stop-list-card{overflow:hidden}
.stop-list-head{display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;border-bottom:1px solid var(--border)}
.stop-list-head span{font-size:13px;font-weight:600}
.stop-list-hint{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:5px}
.stop-scroll{max-height:600px;overflow-y:auto}
.stop-item{display:flex;gap:11px;align-items:flex-start;padding:11px 14px;border-bottom:1px solid var(--border);background:var(--card)}
.stop-item:last-child{border-bottom:none}
.stop-item.unlocated{background:rgba(239,68,68,.06);border-left:3px solid rgba(239,68,68,.5);padding-left:11px}
.stop-item.dragging{opacity:.45}
.stop-item.drop-target{border-top:2px solid var(--blue)}
.stop-handle{color:var(--muted);cursor:grab;padding-top:6px;flex-shrink:0;font-size:15px}
.stop-handle:active{cursor:grabbing}
.stop-num{width:32px;height:32px;border-radius:8px;background:var(--card2,#2e333d);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;flex-shrink:0}
.stop-item.unlocated .stop-num{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35);color:#f87171}
.stop-body{flex:1;min-width:0}
.stop-top-row{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.stop-client{font-size:13.5px;font-weight:600}
.stop-ord{font-size:11px;color:var(--muted);margin-top:1px}
.stop-meta{display:flex;flex-wrap:wrap;column-gap:12px;row-gap:3px;font-size:12px;color:var(--muted);margin-top:5px}
.stop-meta span{display:inline-flex;align-items:center;gap:5px}
.stop-meta i{font-size:12px}
.stop-warn{font-size:11.5px;color:#f87171;margin-top:6px;display:flex;align-items:center;gap:5px;font-weight:500}
.stop-item{cursor:pointer}
.stop-item.active{background:rgba(59,130,246,.10);box-shadow:inset 3px 0 0 var(--blue)}
.stop-nav{display:inline-flex;align-items:center;font-size:11.5px;color:#60a5fa;text-decoration:none;margin-top:7px;font-weight:500}
.stop-nav:hover{color:#93c5fd;text-decoration:underline}

.map-card{display:flex;flex-direction:column;overflow:hidden;height:100%}
#route-map{position:relative;flex:1;min-height:600px;background:var(--card2,#2e333d)}
.map-note{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);padding:.6rem 1rem;border-top:1px solid var(--border)}
.rp-pin{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#fff;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.5)}
.rp-pin.exacta{background:#22c55e}
.rp-pin.localidad{background:#eab308}
.rp-pin.sinubicar{background:#ef4444}
.rp-depot{width:30px;height:30px;border-radius:8px;background:var(--blue,#3b82f6);color:#fff;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 1px 5px rgba(0,0,0,.5);font-size:14px}
.leaflet-container{background:var(--card2,#2e333d)}
</style>

<!-- Datos de la hoja (solo lectura) -->
<div class="card p-3 mb-1">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.75rem">
    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
      <span class="mono" style="font-size:1.05rem;font-weight:700"><?= h((string)$hoja['numero']) ?></span>
      <span class="badge <?= $badgeCls ?>"><?= h($estadoUp) ?></span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.5rem 1.5rem;flex:1;max-width:520px">
      <div>
        <span class="rh-field-l">Conductor</span>
        <?php $cond = trim((string)($hoja['conductor'] ?? '')); ?>
        <span class="rh-field-v <?= $cond === '' ? 'rh-placeholder' : '' ?>"><?= h($cond !== '' ? $cond : 'sin asignar') ?></span>
      </div>
      <div>
        <span class="rh-field-l">Vehículo</span>
        <?php $veh = trim((string)($hoja['vehiculo'] ?? '')); ?>
        <span class="rh-field-v <?= $veh === '' ? 'rh-placeholder' : '' ?>"><?= h($veh !== '' ? $veh : 'sin asignar') ?></span>
      </div>
    </div>
  </div>
</div>

<div class="route-grid">

  <!-- Columna izquierda: paradas -->
  <div>
    <div class="sumbar">
      <div><div class="sumbar-n"><?= count($paradas) ?></div><div class="sumbar-l">Paradas</div></div>
      <div class="sumbar-div"></div>
      <div><div class="sumbar-n"><?= (int)$totBultos ?></div><div class="sumbar-l">Bultos</div></div>
      <div class="sumbar-div"></div>
      <div><div class="sumbar-n"><?= number_format($totM3, 2, ',', '.') ?></div><div class="sumbar-l">m³</div></div>
      <div class="sumbar-div"></div>
      <div><div class="sumbar-n" id="tzKm">—</div><div class="sumbar-l">Km estimado</div></div>
      <div class="sumbar-div"></div>
      <div><div class="sumbar-n" id="tzTiempo">—</div><div class="sumbar-l">Tiempo estimado</div></div>
    </div>

    <div style="display:flex;gap:.5rem;margin-bottom:.85rem">
      <form method="post" class="flex-fill">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="accion" value="optimizar">
        <button class="btn btn-primary fw-bold w-100"><i class="bi bi-magic me-2"></i>Sugerir orden</button>
      </form>
      <form method="post" class="flex-fill" id="formGuardar">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="accion" value="guardar_orden">
        <input type="hidden" name="orden" id="ordenInput" value="">
        <button class="btn btn-outline-secondary w-100"><i class="bi bi-save2 me-2"></i>Guardar recorrido</button>
      </form>
    </div>

    <div class="card stop-list-card">
      <div class="stop-list-head">
        <span>Paradas (<?= count($paradas) ?>)</span>
        <span class="stop-list-hint"><i class="bi bi-arrows-move"></i>Arrastrá para reordenar</span>
      </div>
      <div class="stop-scroll" id="stopList">
        <?php if ($paradas === []): ?>
          <div class="p-4 text-center" style="color:var(--muted)">Esta hoja no tiene órdenes ni líneas cargadas.</div>
        <?php endif; ?>
        <?php foreach ($paradas as $p): ?>
          <div class="stop-item<?= $p['ubicada'] ? '' : ' unlocated' ?>" draggable="true"
               data-tipo="<?= h($p['tipo']) ?>" data-ref="<?= (int)$p['ref_id'] ?>"
               data-lat="<?= $p['lat'] !== null ? h((string)$p['lat']) : '' ?>"
               data-lng="<?= $p['lng'] !== null ? h((string)$p['lng']) : '' ?>"
               data-precision="<?= h($p['precision']) ?>">
            <i class="bi bi-grip-vertical stop-handle"></i>
            <div class="stop-num"><?= (int)$p['num'] ?></div>
            <div class="stop-body">
              <div class="stop-top-row">
                <span class="stop-client"><?= h($p['cliente']) ?></span>
                <?= rp_badge_precision($p) ?>
              </div>
              <div class="stop-ord mono"><?= h($p['nro_orden'] !== '' ? $p['nro_orden'] : '—') ?></div>
              <div class="stop-meta">
                <span><i class="bi bi-signpost-2"></i><?= h($p['localidad'] !== '' ? $p['localidad'] : '—') ?></span>
                <span><i class="bi bi-box-seam"></i><?= (int)$p['bultos'] ?></span>
                <span><i class="bi bi-boxes"></i><?= number_format((float)$p['m3'], 2, ',', '.') ?></span>
                <?php if (trim((string)$p['telefono']) !== ''): ?>
                <span><i class="bi bi-telephone"></i><?= h($p['telefono']) ?></span>
                <?php endif; ?>
              </div>
              <?= rp_como_llegar($p) ?>
              <?php if (!$p['ubicada']): ?>
              <div class="stop-warn"><i class="bi bi-cursor-fill"></i>Ubicá esta parada en el mapa</div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Columna derecha: mapa -->
  <div class="card map-card">
    <div id="route-map"></div>
    <div class="map-note"><i class="bi bi-info-circle"></i>Arrastrá un pin para corregir la ubicación.</div>
  </div>

</div>

<script>
window.__RUTA__ = {
  id: <?= $id ?>,
  csrf: <?= json_encode($csrf) ?>,
  postUrl: <?= json_encode(url('admin/ruta-planificar.php') . '?id=' . $id) ?>,
  velKmh: <?= RutaSecuencia::VEL_KMH ?>,
  depot: <?= json_encode($depot) ?>,
  stops: <?= json_encode($stopsJs, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="<?= h(asset('assets/vendor/leaflet/leaflet.js')) ?>"></script>
<script src="<?= h(asset('assets/js/admin/ruta-planificar.js')) ?>"></script>
<?php
panel_footer();

<?php
declare(strict_types=1);

// =============================================================================
// admin/hojas-ruta.php — Hojas de ruta de reparto (admin + logística).
// Listado + alta (redirige al editor). Distinta de la hoja de ruta del proveedor
// (campo ordenes.hoja_ruta). Esta es la de salida a reparto que arma logística.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Geocoder;
use Trazock\Models\HojaRuta;

$user = Auth::requierePanel(['admin', 'logistica']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/hojas-ruta.php'));
        exit;
    }
    $accion = (string)($_POST['accion'] ?? '');
    if ($accion === 'nueva') {
        $id = HojaRuta::crear((int)$user['id']);
        header('Location: ' . url('admin/hoja-ruta-armar.php') . '?id=' . $id);
        exit;
    }
    if ($accion === 'geocodificar') {
        // Botón manual (stopgap hasta configurar el cron): geocodifica un tope de
        // direcciones nuevas por click, para no colgar el request (Nominatim va a
        // ~1 req/s). Si quedan más, avisa que se vuelva a tocar.
        @set_time_limit(0);
        $r = Geocoder::procesarPendientes(25, 1100);
        if ($r['pendientes'] === 0) {
            flash_set('info', 'No hay direcciones nuevas para geolocalizar: está todo al día.');
        } else {
            $msg = 'Geolocalizadas ' . $r['procesadas'] . ' dirección(es) nuevas '
                 . '(exactas ' . $r['exactas'] . ', aproximadas ' . $r['aprox'] . ', sin resolver ' . $r['fallidas'] . ').';
            if ($r['restantes'] > 0) {
                flash_set('warning', $msg . ' Quedan ' . $r['restantes'] . ' pendientes: volvé a tocar «Geolocalizar».');
            } else {
                flash_set('success', $msg);
            }
        }
        header('Location: ' . url('admin/hojas-ruta.php'));
        exit;
    }
    if ($accion === 'eliminar') {
        $id = (int)($_POST['id'] ?? 0);
        $h  = $id > 0 ? HojaRuta::find($id) : null;
        if ($h !== null && $h['estado'] === 'abierta') {
            HojaRuta::eliminar($id);
            flash_set('success', 'Hoja de ruta eliminada.');
        } else {
            flash_set('warning', 'Solo se pueden eliminar hojas abiertas.');
        }
    }
    header('Location: ' . url('admin/hojas-ruta.php'));
    exit;
}

$hojas = HojaRuta::listar();
$csrf  = Auth::tokenCSRF();

$acciones = '<form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="' . h($csrf) . '"><input type="hidden" name="accion" value="nueva">'
          . '<button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nueva hoja</button></form>'
          . '<form method="post" class="d-inline ms-2"><input type="hidden" name="csrf_token" value="' . h($csrf) . '"><input type="hidden" name="accion" value="geocodificar">'
          . '<button class="btn btn-outline-secondary btn-sm" title="Geolocalizar las direcciones nuevas para el mapa de recorridos"><i class="bi bi-geo-alt me-1"></i>Geolocalizar direcciones</button></form>';
panel_header('Hojas de ruta', $user, 'hojas-ruta', 'Salida a reparto · ' . count($hojas) . ' hoja(s)', $acciones);
flash_render();
?>
<p class="text-muted small mb-3">Hoja de ruta de <strong>salida a reparto</strong>: agregás las órdenes que salen (y artículos manuales fuera del sistema), y el transportista la elige en el scan al salir. No es la hoja de ruta del proveedor (esa viene con la carga).</p>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Número</th><th>Fecha</th><th>Conductor</th><th>Vehículo</th><th class="text-center">Órdenes</th><th>Estado</th><th class="text-end">Acciones</th></tr>
      </thead>
      <tbody>
      <?php if ($hojas === []): ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No hay hojas de ruta. Creá una con "Nueva hoja".</td></tr>
      <?php endif; ?>
      <?php foreach ($hojas as $h):
        $nOrd = (int)$h['n_ordenes'] + (int)$h['n_manual'];
        $emit = $h['estado'] === 'emitida';
      ?>
        <tr>
          <td class="mono fw-bold"><a href="<?= h(url('admin/hoja-ruta-armar.php') . '?id=' . (int)$h['id']) ?>" style="color:#60a5fa;text-decoration:none"><?= h((string)$h['numero']) ?></a></td>
          <td class="text-muted" style="font-size:13px"><?= h(($h['fecha'] ?? '') ? date('d/m/Y', strtotime((string)$h['fecha'])) : '—') ?></td>
          <td style="font-size:13px"><?= h((string)($h['conductor'] ?? '') !== '' ? (string)$h['conductor'] : '—') ?></td>
          <td style="font-size:13px"><?= h((string)($h['vehiculo'] ?? '') !== '' ? (string)$h['vehiculo'] : '—') ?></td>
          <td class="text-center"><?= $nOrd ?><?= (int)$h['n_manual'] > 0 ? ' <span class="text-muted" style="font-size:11px">(' . (int)$h['n_manual'] . ' man.)</span>' : '' ?></td>
          <td>
            <span class="badge b-<?= $emit ? 'activo' : 'inactivo' ?>"><?= $emit ? 'Emitida' : 'Abierta' ?></span>
            <?php if ((int)$h['n_lotes'] > 0): ?><span class="badge" style="background:rgba(59,130,246,.15);color:#60a5fa" title="Con reparto escaneado"><i class="bi bi-upc-scan"></i></span><?php endif; ?>
          </td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= h(url('admin/hoja-ruta-armar.php') . '?id=' . (int)$h['id']) ?>"><i class="bi bi-pencil me-1"></i>Abrir</a>
            <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="<?= h(url('admin/hoja-ruta.php') . '?hoja=' . (int)$h['id']) ?>" target="_blank" title="Imprimir"><i class="bi bi-printer"></i></a>
            <?php if (!$emit): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
              <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" onclick="tzConfirm(this.closest('form'), '¿Eliminar la hoja «<?= h(addslashes((string)$h['numero'])) ?>»?')"><i class="bi bi-trash"></i></button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
panel_footer();

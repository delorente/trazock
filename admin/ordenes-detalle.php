<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-detalle.php — detalle de una orden: datos, ítems (con estado y
// etiqueta) y vista previa del rótulo con QR. Permite reimprimir etiquetas.
// Se llega desde Reportes (?id=ID). admin/gestor.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\EtiquetaQr;
use Trazock\Models\Orden;
use Trazock\Models\Producto;

$user = Auth::requierePanel(); // admin o gestor

$id    = (int)($_GET['id'] ?? 0);
$orden = $id > 0 ? Orden::find($id) : null;

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-reportes.php')) . '"><i class="bi bi-arrow-left me-1"></i>Reportes</a>';

if ($orden === null) {
    panel_header('Detalle de orden', $user, 'reportes', '', $volver);
    echo '<div class="alert alert-warning">No se encontró la orden.</div>';
    panel_footer();
    exit;
}

$items   = Producto::paraEtiquetasPorOrden($id);
$num     = carga_num((int)($orden['carga_id'] ?? 0), (string)($orden['created_at'] ?? ''));
$tv      = (string)($orden['tipo_venta'] ?? '');
$urlEti  = url('admin/ordenes-etiquetas.php') . '?orden=' . $id;

/** Destino "Localidad · Provincia". */
$loc  = trim((string)($orden['dest_localidad'] ?? ''));
$prov = trim((string)($orden['dest_provincia'] ?? ''));
$destino = trim($loc . ($loc !== '' && $prov !== '' ? ' · ' : '') . $prov) ?: '—';

/** Badge de estado del ítem: ETIQUETADA si tiene etiqueta y sigue INGRESADO. */
function item_estado(array $it): string
{
    $est = (string)($it['estado_actual'] ?? '');
    if (($it['etiquetada_at'] ?? null) !== null && $est === 'INGRESADO') {
        return 'ETIQUETADA';
    }
    return $est;
}

$acciones = $volver . '<a class="btn btn-sm btn-outline-secondary" href="' . h($urlEti) . '"><i class="bi bi-tag me-1"></i>Re-imprimir etiquetas</a>';

panel_header('Detalle de orden', $user, 'reportes', '', $acciones);

$campo = static function (string $label, string $valor): void {
    echo '<div><span style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;display:block">'
        . h($label) . '</span>' . h($valor !== '' ? $valor : '—') . '</div>';
};
?>
<div class="card p-3 mb-3">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.85rem">
    <div>
      <div class="mono" style="font-size:1.15rem;font-weight:700;margin-bottom:6px"><?= h((string)$orden['nro_orden']) ?></div>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
        <?= estado_badge((string)($orden['estado'] ?? '')) ?>
        <?php if ($tv !== ''): ?><span class="badge b-<?= h(strtoupper($tv)) ?>"><?= h(ucfirst($tv)) ?></span><?php endif; ?>
        <span style="font-size:12px;color:var(--muted)"><?= h($num) ?></span>
      </div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:.65rem;font-size:13px">
    <?php
      $campo('Nº Remito', (string)($orden['nro_remito'] ?? ''));
      $campo('Cliente', (string)($orden['cliente'] ?? ''));
      $campo('Teléfonos', (string)($orden['telefonos'] ?? ''));
      $campo('Destino', $destino);
      $campo('Domicilio', (string)($orden['dest_domicilio'] ?? ''));
      $campo('Fecha remito', ($orden['fecha_remito'] ?? '') ? date('d/m/Y', strtotime((string)$orden['fecha_remito'])) : '');
      $campo('Ingreso depósito', fmt_fecha((string)($orden['created_at'] ?? ''), 'd/m/Y · H:i'));
      $campo('Valor declarado', $orden['valor_declarado'] !== null ? '$' . number_format((float)$orden['valor_declarado'], 2, ',', '.') : '');
      $campo('m³ total', number_format((float)($orden['m3_total'] ?? 0), 2, ',', '.') . ' m³');
      $campo('Ítems', count($items) . ' unidad(es)');
    ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:1rem;align-items:start" class="tz-detalle-grid">
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;border-bottom:1px solid var(--border)">
      <span style="font-weight:600;font-size:13px">Ítems (<?= count($items) ?>)</span>
    </div>
    <div style="overflow-x:auto">
      <table class="table table-hover mb-0">
        <thead><tr><th>Código</th><th>Descripción</th><th>Dimensiones</th><th>m³</th><th>Ítem</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td class="mono" style="font-size:12px"><?= h((string)$it['codigo']) ?></td>
            <td><?= h((string)($it['descripcion'] ?? '—')) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= h((string)($it['dimensiones'] ?? '—')) ?></td>
            <td><?= $it['m3'] !== null ? number_format((float)$it['m3'], 3, ',', '.') : '—' ?></td>
            <td style="color:var(--muted)"><?= (int)$it['secuencia'] ?> de <?= (int)$it['total_items'] ?></td>
            <td><?= estado_badge(item_estado($it)) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card p-3">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.75rem">Etiqueta · vista previa</div>
    <?php if ($items !== []):
        $m = $items[0];
        $ape = (string)($m['cliente_apellido'] ?? '');
        $payload = EtiquetaQr::payload((string)$m['nro_orden'], (int)$m['secuencia'], (int)$m['total_items'], (string)($m['dest_provincia'] ?? ''), $ape);
    ?>
    <div class="label-card" style="width:100%">
      <div class="lq" data-qr="<?= h($payload) ?>"></div>
      <div class="lb">
        <div class="ld"><?= h($destino) ?></div>
        <div class="ln"><?= h(trim((string)$orden['cliente']) !== '' ? (string)$orden['cliente'] : $ape) ?></div>
        <div class="li">Ítem <?= (int)$m['secuencia'] ?> de <?= (int)$m['total_items'] ?> · <?= h((string)($m['descripcion'] ?? 'Ítem')) ?></div>
        <div class="lc"><?= h((string)$m['codigo']) ?> · <?= h($num) ?></div>
      </div>
    </div>
    <a class="btn btn-outline-secondary btn-sm w-100 mt-2" href="<?= h($urlEti) ?>"><i class="bi bi-printer me-1"></i>Re-imprimir etiquetas</a>
    <?php else: ?>
    <div class="text-muted" style="font-size:13px">La orden no tiene ítems.</div>
    <?php endif; ?>
  </div>
</div>
<style>@media(max-width:768px){.tz-detalle-grid{grid-template-columns:1fr!important}}</style>

<script src="<?= h(asset('assets/vendor/qrcode-generator/qrcode.min.js')) ?>"></script>
<script src="<?= h(asset('assets/js/etiquetas.js')) ?>"></script>
<?php
panel_footer();

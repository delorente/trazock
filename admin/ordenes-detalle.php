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

// --- POST: editar datos de la orden (PRG + CSRF) -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['id'] ?? 0);
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif ($pid <= 0 || Orden::find($pid) === null) {
        flash_set('danger', 'Orden no encontrada.');
    } else {
        $tvIn  = (string)($_POST['tipo_venta'] ?? '');
        $valor = trim((string)($_POST['valor_declarado'] ?? ''));
        Orden::actualizarDatos($pid, [
            'cliente'          => trim((string)($_POST['cliente'] ?? '')),
            'cliente_apellido' => trim((string)($_POST['cliente_apellido'] ?? '')),
            'telefonos'        => trim((string)($_POST['telefonos'] ?? '')),
            'tipo_venta'       => in_array($tvIn, ['online', 'local'], true) ? $tvIn : null,
            'dest_provincia'   => trim((string)($_POST['dest_provincia'] ?? '')),
            'dest_localidad'   => trim((string)($_POST['dest_localidad'] ?? '')),
            'dest_domicilio'   => trim((string)($_POST['dest_domicilio'] ?? '')),
            'dest_cp'          => trim((string)($_POST['dest_cp'] ?? '')),
            'nro_remito'       => trim((string)($_POST['nro_remito'] ?? '')),
            'fecha_remito'     => trim((string)($_POST['fecha_remito'] ?? '')),
            'valor_declarado'  => $valor !== '' ? str_replace(',', '.', $valor) : null,
        ]);
        flash_set('success', 'Orden actualizada.');
    }
    header('Location: ' . url('admin/ordenes-detalle.php') . '?id=' . $pid);
    exit;
}

$id    = (int)($_GET['id'] ?? 0);
$orden = $id > 0 ? Orden::find($id) : null;

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-reportes.php')) . '"><i class="bi bi-arrow-left me-1"></i>Reportes</a>';

if ($orden === null) {
    panel_header('Detalle de orden', $user, 'reportes', '', $volver);
    echo '<div class="alert alert-warning">No se encontró la orden.</div>';
    panel_footer();
    exit;
}

$items     = Producto::paraEtiquetasPorOrden($id);
$num       = carga_num((int)($orden['carga_id'] ?? 0), (string)($orden['created_at'] ?? ''));
$tv        = (string)($orden['tipo_venta'] ?? '');
$urlEti    = url('admin/ordenes-etiquetas.php') . '?orden=' . $id;
$csrf      = Auth::tokenCSRF();
$historial = Orden::historial($id);

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

$acciones = $volver
    . '<button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEditar"><i class="bi bi-pencil me-1"></i>Editar</button>'
    . '<a class="btn btn-sm btn-outline-secondary" href="' . h($urlEti) . '"><i class="bi bi-tag me-1"></i>Re-imprimir etiquetas</a>';

panel_header('Detalle de orden', $user, 'reportes', '', $acciones);
flash_render();

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
        $payload = EtiquetaQr::payload((string)$m['nro_orden'], (int)$m['secuencia'], (int)$m['total_items'], (string)($m['dest_provincia'] ?? ''), (string)($m['dest_localidad'] ?? ''), $ape);
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
<div class="card p-3 mt-3">
  <div style="font-weight:600;font-size:13px;margin-bottom:.85rem">Historial</div>
  <?php if ($historial === []): ?>
    <div class="text-muted" style="font-size:13px">Sin eventos.</div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:.8rem">
    <?php foreach ($historial as $ev): ?>
      <div style="display:flex;gap:.6rem;align-items:flex-start">
        <div style="width:9px;height:9px;border-radius:50%;background:var(--blue);margin-top:5px;flex-shrink:0"></div>
        <div>
          <div style="font-size:13px;font-weight:500"><?= h($ev['titulo']) ?></div>
          <div style="font-size:12px;color:var(--muted)"><?= h(fmt_fecha($ev['fecha'], 'd/m/Y H:i')) ?><?= $ev['detalle'] !== '' ? ' · ' . h($ev['detalle']) : '' ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<style>@media(max-width:768px){.tz-detalle-grid{grid-template-columns:1fr!important}}</style>

<!-- Modal: editar datos de la orden -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content" action="<?= h(url('admin/ordenes-detalle.php') . '?id=' . $id) ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <div class="modal-header">
        <h5 class="modal-title">Editar orden <span class="mono"><?= h((string)$orden['nro_orden']) ?></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label">Nº remito</label><input class="form-control form-control-sm" name="nro_remito" value="<?= h((string)($orden['nro_remito'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Fecha remito</label><input type="date" class="form-control form-control-sm" name="fecha_remito" value="<?= h((string)($orden['fecha_remito'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Tipo de venta</label>
            <select class="form-select form-select-sm" name="tipo_venta">
              <option value="" <?= $tv === '' ? 'selected' : '' ?>>—</option>
              <option value="online" <?= $tv === 'online' ? 'selected' : '' ?>>Online</option>
              <option value="local" <?= $tv === 'local' ? 'selected' : '' ?>>Local</option>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Cliente</label><input class="form-control form-control-sm" name="cliente" value="<?= h((string)($orden['cliente'] ?? '')) ?>"></div>
          <div class="col-md-3"><label class="form-label">Apellido</label><input class="form-control form-control-sm" name="cliente_apellido" value="<?= h((string)($orden['cliente_apellido'] ?? '')) ?>"></div>
          <div class="col-md-3"><label class="form-label">Teléfonos</label><input class="form-control form-control-sm" name="telefonos" value="<?= h((string)($orden['telefonos'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Provincia</label><input class="form-control form-control-sm" name="dest_provincia" value="<?= h((string)($orden['dest_provincia'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Localidad</label><input class="form-control form-control-sm" name="dest_localidad" value="<?= h((string)($orden['dest_localidad'] ?? '')) ?>"></div>
          <div class="col-md-2"><label class="form-label">CP</label><input class="form-control form-control-sm" name="dest_cp" value="<?= h((string)($orden['dest_cp'] ?? '')) ?>"></div>
          <div class="col-md-2"><label class="form-label">Valor decl.</label><input class="form-control form-control-sm" name="valor_declarado" value="<?= $orden['valor_declarado'] !== null ? h(number_format((float)$orden['valor_declarado'], 2, '.', '')) : '' ?>"></div>
          <div class="col-12"><label class="form-label">Domicilio</label><input class="form-control form-control-sm" name="dest_domicilio" value="<?= h((string)($orden['dest_domicilio'] ?? '')) ?>"></div>
        </div>
        <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>Editás los datos de la orden. El destino de las etiquetas ya impresas no cambia hasta reimprimirlas.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= h(asset('assets/vendor/qrcode-generator/qrcode.min.js')) ?>"></script>
<script src="<?= h(asset('assets/js/etiquetas.js')) ?>"></script>
<?php
panel_footer();

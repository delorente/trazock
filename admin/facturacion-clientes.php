<?php
declare(strict_types=1);

// =============================================================================
// admin/facturacion-clientes.php — facturación por cliente: unidad (m³/bulto/
// peso), si cobra por destino o único, y los PRECIOS CON VIGENCIA (historial).
// El precio aplicado a cada hoja de ruta es el vigente a su fecha. admin/gestor.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\ClienteFacturacion;
use Trazock\Models\Orden;
use Trazock\Models\Proveedor;

$user = Auth::requierePanel(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid    = (int)($_POST['proveedor_id'] ?? 0);
    $accion = (string)($_POST['accion'] ?? '');
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif ($pid <= 0 || Proveedor::find($pid) === null) {
        flash_set('danger', 'Cliente inválido.');
    } elseif ($accion === 'cfg') {
        ClienteFacturacion::guardar($pid, (string)($_POST['unidad'] ?? 'm3'), !empty($_POST['por_destino']));
        flash_set('success', 'Configuración del cliente actualizada.');
    } elseif ($accion === 'precio_add') {
        $prov  = trim((string)($_POST['provincia'] ?? '')); // '' = precio único
        $precio = (float)str_replace(',', '.', (string)($_POST['precio'] ?? '0'));
        $vd     = trim((string)($_POST['vigente_desde'] ?? '')) ?: date('Y-m-d');
        if ($precio <= 0) {
            flash_set('danger', 'El precio debe ser mayor a 0.');
        } else {
            ClienteFacturacion::agregarPrecio($pid, $prov, $precio, $vd);
            flash_set('success', 'Precio agregado.');
        }
    } elseif ($accion === 'precio_del') {
        ClienteFacturacion::eliminarPrecio((int)($_POST['precio_id'] ?? 0), $pid);
        flash_set('success', 'Precio eliminado.');
    }
    header('Location: ' . url('admin/facturacion-clientes.php') . '?proveedor=' . $pid);
    exit;
}

$proveedores = Proveedor::activos();
$sel = (int)($_GET['proveedor'] ?? 0);
$cfg = $sel > 0 ? ClienteFacturacion::get($sel) : null;
$precios = $sel > 0 ? ClienteFacturacion::precios($sel) : [];
$provincias = $sel > 0 ? Orden::provincias() : [];
$csrf = Auth::tokenCSRF();

panel_header('Facturación por cliente', $user, 'facturacion-clientes', 'Unidad y precio (con vigencia) que se le cobra a cada cliente');
flash_render();
?>
<p class="text-muted small mb-3">Por cada cliente (marca): la <strong>unidad</strong> (m³, bulto o peso), si cobra <strong>por destino</strong> o un precio único, y los <strong>precios con vigencia</strong>. Cada hoja de ruta usa el precio vigente a su fecha; cambiar el precio no altera lo ya calculado. Lo usa el reporte de <a href="<?= h(url('admin/rentabilidad.php')) ?>">Resultados</a> (no afecta a Facturación ni a la pre-factura AFIP).</p>

<div class="card mb-3">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light"><tr><th>Cliente</th><th>Unidad</th><th>Modo</th><th class="text-end">Acción</th></tr></thead>
            <tbody>
            <?php foreach ($proveedores as $p):
                $c = ClienteFacturacion::get((int)$p['id']); $activo = (int)($c['activo'] ?? 0) === 1; ?>
                <tr class="<?= $sel === (int)$p['id'] ? 'table-active' : '' ?>">
                    <td><?= h($p['nombre']) ?></td>
                    <td><?= $activo ? h(ClienteFacturacion::UNIDADES[$c['unidad']] ?? $c['unidad']) : '<span class="text-muted">sin configurar</span>' ?></td>
                    <td><?= $activo ? ((int)$c['por_destino'] === 1 ? 'Por destino' : 'Precio único') : '—' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary py-0 px-2" href="?proveedor=<?= (int)$p['id'] ?>">Configurar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($sel > 0 && $cfg !== null): $pNombre = ''; foreach ($proveedores as $p) { if ((int)$p['id'] === $sel) { $pNombre = (string)$p['nombre']; } } $accUrl = url('admin/facturacion-clientes.php') . '?proveedor=' . $sel; ?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" class="card">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="accion" value="cfg">
        <input type="hidden" name="proveedor_id" value="<?= $sel ?>">
        <div class="card-header"><i class="bi bi-gear me-1"></i><strong><?= h($pNombre) ?></strong></div>
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label">Unidad de cobro</label>
                <select class="form-select form-select-sm" name="unidad">
                    <?php foreach (ClienteFacturacion::UNIDADES as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= (string)$cfg['unidad'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Peso todavía no se captura; quedará en 0 hasta cargarlo.</div>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="por_destino" id="cfPorDestino" value="1" <?= (int)$cfg['por_destino'] === 1 ? 'checked' : '' ?>>
                <label class="form-check-label" for="cfPorDestino">Cobra distinto por destino (provincia)</label>
            </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save me-1"></i>Guardar configuración</button></div>
    </form>
  </div>

  <div class="col-lg-7">
    <div class="card">
        <div class="card-header">Precios (con vigencia)</div>
        <div style="padding:.6rem 1rem;border-bottom:1px solid var(--border)">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="accion" value="precio_add">
                <input type="hidden" name="proveedor_id" value="<?= $sel ?>">
                <div class="col-6 col-md-4">
                    <label class="form-label" style="font-size:12px">Destino</label>
                    <select class="form-select form-select-sm" name="provincia">
                        <option value="">Todos (precio único)</option>
                        <?php foreach ($provincias as $prov): ?><option value="<?= h($prov) ?>"><?= h($prov) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" style="font-size:12px">Precio por unidad</label>
                    <div class="input-group input-group-sm"><span class="input-group-text">$</span><input type="number" step="0.01" min="0" class="form-control" name="precio" required></div>
                </div>
                <div class="col-8 col-md-3">
                    <label class="form-label" style="font-size:12px">Vigente desde</label>
                    <input type="date" class="form-control form-control-sm" name="vigente_desde" value="<?= h(date('Y-m-d')) ?>">
                </div>
                <div class="col-4 col-md-2"><button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-plus-lg"></i></button></div>
            </form>
        </div>
        <div style="overflow-x:auto">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Destino</th><th class="text-end">Precio</th><th>Vigente desde</th><th></th></tr></thead>
                <tbody>
                <?php if ($precios === []): ?>
                    <tr><td colspan="4" class="text-muted text-center py-3">Sin precios cargados.</td></tr>
                <?php endif; ?>
                <?php foreach ($precios as $pr): ?>
                    <tr>
                        <td><?= (string)$pr['provincia'] === '' ? '<span class="text-muted">Todos (único)</span>' : h((string)$pr['provincia']) ?></td>
                        <td class="text-end">$ <?= number_format((float)$pr['precio'], 2, ',', '.') ?></td>
                        <td><?= h(date('d/m/Y', strtotime((string)$pr['vigente_desde']))) ?></td>
                        <td class="text-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                <input type="hidden" name="accion" value="precio_del">
                                <input type="hidden" name="proveedor_id" value="<?= $sel ?>">
                                <input type="hidden" name="precio_id" value="<?= (int)$pr['id'] ?>">
                                <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Eliminar" onclick="tzConfirm(this.closest('form'), '¿Eliminar este precio?')"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer text-muted small">Cargá un precio nuevo con su fecha de vigencia cuando cambie; el anterior se conserva para lo ya calculado.</div>
    </div>
  </div>
</div>
<?php endif; ?>
<?php
panel_footer();

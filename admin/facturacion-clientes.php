<?php
declare(strict_types=1);

// =============================================================================
// admin/facturacion-clientes.php — configuración de facturación por cliente:
// unidad (m³/bulto/peso), si cobra por destino o precio único, y los precios.
// La usa el reporte de Resultados. admin/gestor.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\ClienteFacturacion;
use Trazock\Models\Orden;
use Trazock\Models\Proveedor;

$user = Auth::requierePanel(['admin', 'gestor']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['proveedor_id'] ?? 0);
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif ($pid <= 0 || Proveedor::find($pid) === null) {
        flash_set('danger', 'Cliente inválido.');
    } else {
        try {
            $unidad     = (string)($_POST['unidad'] ?? 'm3');
            $porDestino = !empty($_POST['por_destino']);
            $precioUni  = ($_POST['precio_unico'] ?? '') !== '' ? (float)str_replace(',', '.', (string)$_POST['precio_unico']) : null;
            ClienteFacturacion::guardar($pid, $unidad, $porDestino, $precioUni);

            // Precios por destino (si vinieron).
            $provs   = (array)($_POST['provincia'] ?? []);
            $precios = (array)($_POST['precio'] ?? []);
            foreach ($provs as $i => $prov) {
                $prov = trim((string)$prov);
                if ($prov === '') { continue; }
                ClienteFacturacion::guardarTarifaDestino($pid, $prov, (float)str_replace(',', '.', (string)($precios[$i] ?? '0')));
            }
            flash_set('success', 'Facturación del cliente actualizada.');
        } catch (Throwable $e) {
            flash_set('danger', 'No se pudo guardar.');
            error_log('facturacion-clientes.php: ' . $e->getMessage());
        }
    }
    header('Location: ' . url('admin/facturacion-clientes.php') . '?proveedor=' . $pid);
    exit;
}

$proveedores = Proveedor::activos();
$sel = (int)($_GET['proveedor'] ?? 0);
$cfg = $sel > 0 ? ClienteFacturacion::get($sel) : null;
$tarifas = $sel > 0 ? ClienteFacturacion::tarifasDestino($sel) : [];
$provincias = [];
if ($sel > 0) {
    $provincias = array_values(array_unique(array_merge(Orden::provincias(), array_keys($tarifas))));
    sort($provincias, SORT_NATURAL | SORT_FLAG_CASE);
}
$csrf = Auth::tokenCSRF();

panel_header('Facturación por cliente', $user, 'facturacion-clientes', 'Unidad y precio que se le cobra a cada cliente');
flash_render();
?>
<p class="text-muted small mb-3">Definí, por cada cliente (marca), cómo se le factura: la <strong>unidad</strong> (m³, bulto o peso), si el precio es <strong>por destino</strong> o uno solo, y el <strong>precio unitario</strong>. El reporte de <a href="<?= h(url('admin/rentabilidad.php')) ?>">Resultados</a> lo usa para calcular lo facturado. No afecta al reporte de Facturación ni a la pre-factura AFIP.</p>

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
                    <td><?= $activo ? ((int)$c['por_destino'] === 1 ? 'Por destino' : ('Único: $' . number_format((float)($c['precio_unico'] ?? 0), 2, ',', '.'))) : '—' ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-outline-primary py-0 px-2" href="?proveedor=<?= (int)$p['id'] ?>">Configurar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($sel > 0 && $cfg !== null): $pNombre = ''; foreach ($proveedores as $p) { if ((int)$p['id'] === $sel) { $pNombre = (string)$p['nombre']; } } ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="proveedor_id" value="<?= $sel ?>">
    <div class="card-header"><i class="bi bi-receipt me-1"></i>Facturación de <strong><?= h($pNombre) ?></strong></div>
    <div class="card-body">
        <div class="row g-3 align-items-end mb-3">
            <div class="col-md-3">
                <label class="form-label">Unidad de cobro</label>
                <select class="form-select form-select-sm" name="unidad" id="cfUnidad">
                    <?php foreach (ClienteFacturacion::UNIDADES as $k => $v): ?>
                        <option value="<?= h($k) ?>" <?= (string)$cfg['unidad'] === $k ? 'selected' : '' ?>><?= h($v) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Peso todavía no se captura; quedará en 0 hasta cargarlo.</div>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="por_destino" id="cfPorDestino" value="1" <?= (int)$cfg['por_destino'] === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="cfPorDestino">Cobra distinto por destino</label>
                </div>
            </div>
            <div class="col-md-3" id="cfUnicoWrap">
                <label class="form-label">Precio único por unidad</label>
                <div class="input-group input-group-sm" style="max-width:200px">
                    <span class="input-group-text">$</span>
                    <input type="number" step="0.01" min="0" class="form-control" name="precio_unico" value="<?= h($cfg['precio_unico'] !== null ? number_format((float)$cfg['precio_unico'], 2, '.', '') : '') ?>">
                </div>
            </div>
        </div>

        <div id="cfDestinoWrap">
            <h6 class="fw-bold" style="font-size:13px">Precio por destino (provincia)</h6>
            <?php if ($provincias === []): ?>
                <p class="text-muted small">No hay provincias todavía (salen de las órdenes cargadas).</p>
            <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.5rem">
                <?php foreach ($provincias as $i => $prov): ?>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text" style="min-width:130px"><?= h($prov) ?></span>
                        <span class="input-group-text">$</span>
                        <input type="hidden" name="provincia[<?= (int)$i ?>]" value="<?= h($prov) ?>">
                        <input type="number" step="0.01" min="0" class="form-control" name="precio[<?= (int)$i ?>]" value="<?= h(isset($tarifas[$prov]) ? number_format($tarifas[$prov], 2, '.', '') : '0.00') ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-footer"><button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save me-1"></i>Guardar</button></div>
</form>
<script>
(function () {
    var pd = document.getElementById('cfPorDestino');
    function sync() {
        document.getElementById('cfDestinoWrap').style.display = pd.checked ? '' : 'none';
        document.getElementById('cfUnicoWrap').style.display = pd.checked ? 'none' : '';
    }
    if (pd) { pd.addEventListener('change', sync); sync(); }
})();
</script>
<?php endif; ?>
<?php
panel_footer();

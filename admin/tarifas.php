<?php
declare(strict_types=1);

// =============================================================================
// admin/tarifas.php — Tarifario: precio por m³ según provincia de destino.
// Lo aplica el reporte de Facturación para calcular importes. Solo admin.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Orden;
use Trazock\Models\Tarifa;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/tarifas.php'));
        exit;
    }

    try {
        // Filas existentes (precio[] alineado con provincia[]).
        $provincias = (array)($_POST['provincia'] ?? []);
        $precios    = (array)($_POST['precio'] ?? []);
        foreach ($provincias as $i => $prov) {
            $prov = trim((string)$prov);
            if ($prov === '') { continue; }
            $precio = (float)str_replace(',', '.', (string)($precios[$i] ?? '0'));
            Tarifa::guardar($prov, $precio);
        }
        // Alta manual opcional (provincia + precio nuevos).
        $nuevaProv = trim((string)($_POST['nueva_provincia'] ?? ''));
        if ($nuevaProv !== '') {
            $nuevoPre = (float)str_replace(',', '.', (string)($_POST['nuevo_precio'] ?? '0'));
            Tarifa::guardar($nuevaProv, $nuevoPre);
        }
        flash_set('success', 'Tarifario actualizado.');
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo guardar el tarifario.');
        error_log('tarifas.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/tarifas.php'));
    exit;
}

// Provincias a mostrar: las que aparecen en órdenes + las que ya tienen tarifa.
$mapa     = Tarifa::mapa();
$deOrden  = Orden::provincias();
$todas    = array_values(array_unique(array_merge($deOrden, array_keys($mapa))));
sort($todas, SORT_NATURAL | SORT_FLAG_CASE);

$csrf = Auth::tokenCSRF();
panel_header('Tarifario', $user, 'tarifas', count($todas) . ' provincia(s) · precio por m³');
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Precio por m³ según provincia de destino. El reporte de <a href="<?= h(url('admin/ordenes-reportes.php')) ?>">Facturación</a> usa estos valores para calcular el importe de cada destino (m³ × precio). Las provincias salen de las órdenes cargadas; podés agregar otra manualmente al pie.</p>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Provincia de destino</th><th style="width:220px">Precio por m³ ($)</th></tr>
                </thead>
                <tbody>
                <?php if ($todas === []): ?>
                    <tr><td colspan="2" class="text-center text-muted py-4">No hay provincias todavía. Agregá una al pie o cargá órdenes.</td></tr>
                <?php endif; ?>
                <?php foreach ($todas as $i => $prov): ?>
                    <tr>
                        <td>
                            <?= h($prov) ?>
                            <input type="hidden" name="provincia[<?= (int)$i ?>]" value="<?= h($prov) ?>">
                        </td>
                        <td>
                            <div class="input-group input-group-sm" style="max-width:200px">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" min="0" class="form-control" name="precio[<?= (int)$i ?>]"
                                       value="<?= h(isset($mapa[$prov]) ? number_format($mapa[$prov], 2, '.', '') : '0.00') ?>">
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td>
                            <input type="text" class="form-control form-control-sm" name="nueva_provincia" placeholder="Agregar otra provincia… (opcional)" maxlength="80">
                        </td>
                        <td>
                            <div class="input-group input-group-sm" style="max-width:200px">
                                <span class="input-group-text">$</span>
                                <input type="number" step="0.01" min="0" class="form-control" name="nuevo_precio" placeholder="0.00">
                            </div>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="padding:.75rem 1rem;border-top:1px solid var(--border)">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Guardar tarifario</button>
        </div>
    </div>
</form>
<?php
panel_footer();

<?php
declare(strict_types=1);

// =============================================================================
// admin/facturacion-datos.php — datos fijos del emisor y receptor + IVA, que
// encabezan la factura generada desde el reporte. Solo admin. Fila única.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\FacturacionDatos;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/facturacion-datos.php'));
        exit;
    }
    try {
        FacturacionDatos::guardar($_POST);
        flash_set('success', 'Datos de facturación guardados.');
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudieron guardar los datos.');
        error_log('facturacion-datos.php: ' . $e->getMessage());
    }
    header('Location: ' . url('admin/facturacion-datos.php'));
    exit;
}

$d    = FacturacionDatos::get();
$csrf = Auth::tokenCSRF();
panel_header('Datos de facturación', $user, 'facturacion-datos', 'Encabezado de la factura · IVA');
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Estos datos encabezan la factura que se genera desde el reporte de <a href="<?= h(url('admin/ordenes-reportes.php')) ?>">Facturación</a>. El <strong>emisor</strong> sos vos (la Corredora); el <strong>receptor</strong> es a quién se le factura el servicio.</p>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <div class="row g-3">
        <div class="col-md-6">
            <div class="card card-body h-100">
                <h6 class="fw-bold mb-3"><i class="bi bi-building me-1"></i>Emisor</h6>
                <div class="mb-3">
                    <label class="form-label" for="e_rs">Razón social</label>
                    <input class="form-control" id="e_rs" name="emisor_razon_social" maxlength="150" value="<?= h((string)($d['emisor_razon_social'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="e_cuit">CUIT</label>
                    <input class="form-control" id="e_cuit" name="emisor_cuit" maxlength="20" value="<?= h((string)($d['emisor_cuit'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="e_iva">Condición frente al IVA</label>
                    <input class="form-control" id="e_iva" name="emisor_iva" maxlength="40" placeholder="Ej. Responsable Inscripto" value="<?= h((string)($d['emisor_iva'] ?? '')) ?>">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="e_dom">Domicilio</label>
                    <input class="form-control" id="e_dom" name="emisor_domicilio" maxlength="200" value="<?= h((string)($d['emisor_domicilio'] ?? '')) ?>">
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card card-body h-100">
                <h6 class="fw-bold mb-3"><i class="bi bi-person-vcard me-1"></i>Receptor (a quién se factura)</h6>
                <div class="mb-3">
                    <label class="form-label" for="r_rs">Razón social</label>
                    <input class="form-control" id="r_rs" name="receptor_razon_social" maxlength="150" value="<?= h((string)($d['receptor_razon_social'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="r_cuit">CUIT</label>
                    <input class="form-control" id="r_cuit" name="receptor_cuit" maxlength="20" value="<?= h((string)($d['receptor_cuit'] ?? '')) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="r_iva">Condición frente al IVA</label>
                    <input class="form-control" id="r_iva" name="receptor_iva" maxlength="40" placeholder="Ej. Responsable Inscripto" value="<?= h((string)($d['receptor_iva'] ?? '')) ?>">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="r_dom">Domicilio</label>
                    <input class="form-control" id="r_dom" name="receptor_domicilio" maxlength="200" value="<?= h((string)($d['receptor_domicilio'] ?? '')) ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card card-body mt-3">
        <div class="row align-items-end g-3">
            <div class="col-auto">
                <label class="form-label" for="iva">Alícuota de IVA (%)</label>
                <div class="input-group" style="max-width:160px">
                    <input type="number" step="0.01" min="0" max="100" class="form-control" id="iva" name="iva_alicuota" value="<?= h(number_format((float)($d['iva_alicuota'] ?? 21), 2, '.', '')) ?>">
                    <span class="input-group-text">%</span>
                </div>
            </div>
            <div class="col text-muted small">Se aplica sobre el subtotal (m³ × tarifa) de cada factura.</div>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
    </div>
</form>
<?php
panel_footer();

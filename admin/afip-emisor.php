<?php
declare(strict_types=1);

// =============================================================================
// admin/afip-emisor.php — datos fiscales del emisor (la Corredora) + alícuota de
// IVA, que encabezan la factura. Solo admin. Fila única.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\AfipEmisor;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/afip-emisor.php'));
        exit;
    }
    try {
        AfipEmisor::guardar($_POST);
        flash_set('success', 'Datos del emisor guardados.');
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudieron guardar los datos.');
        error_log('afip-emisor.php: ' . $e->getMessage());
    }
    header('Location: ' . url('admin/afip-emisor.php'));
    exit;
}

$d    = AfipEmisor::get();
$csrf = Auth::tokenCSRF();
panel_header('Datos del emisor', $user, 'afip-emisor', 'Encabezan la factura · IVA');
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">Datos fiscales de la Corredora (emisor) que encabezan la factura. El receptor (a quién se factura) son los datos de cada <a href="<?= h(url('admin/proveedores.php')) ?>">proveedor/marca</a>.</p>

<form method="post">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <div class="card card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-building me-1"></i>Emisor</h6>
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label" for="razon">Razón social</label>
                <input class="form-control" id="razon" name="razon_social" maxlength="150" value="<?= h((string)($d['razon_social'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="cuit">CUIT</label>
                <input class="form-control" id="cuit" name="cuit" maxlength="13" placeholder="30-12345678-9" value="<?= h((string)($d['cuit'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="civa">Condición frente al IVA</label>
                <input class="form-control" id="civa" name="condicion_iva" maxlength="40" value="<?= h((string)($d['condicion_iva'] ?? 'Responsable Inscripto')) ?>">
            </div>
            <div class="col-md-8">
                <label class="form-label" for="domicilio">Domicilio comercial</label>
                <input class="form-control" id="domicilio" name="domicilio" maxlength="200" value="<?= h((string)($d['domicilio'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="iibb">Ingresos Brutos</label>
                <input class="form-control" id="iibb" name="iibb" maxlength="40" value="<?= h((string)($d['iibb'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="inicio">Inicio de actividades</label>
                <input type="date" class="form-control" id="inicio" name="inicio_actividades" value="<?= h((string)($d['inicio_actividades'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="iva">Alícuota de IVA (%)</label>
                <div class="input-group">
                    <input type="number" step="0.01" min="0" max="100" class="form-control" id="iva" name="iva_alicuota" value="<?= h(number_format((float)($d['iva_alicuota'] ?? 21), 2, '.', '')) ?>">
                    <span class="input-group-text">%</span>
                </div>
            </div>
        </div>
    </div>
    <div class="mt-3">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Guardar</button>
    </div>
</form>
<?php
panel_footer();

<?php
declare(strict_types=1);

// =============================================================================
// admin/seguimiento.php — Textos públicos de seguimiento (solo admin).
//
// Edita la traducción de cada estado interno al texto que ve el comprador en la
// landing pública (/seguimiento/?t=…). No se crean ni borran filas: hay una por
// cada estado (sembradas en la migración 004); aquí solo se editan título,
// descripción, visibilidad en la línea de tiempo y orden.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\EstadoPublico;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

// Estados válidos (claves de la tabla) para validar el POST.
$ESTADOS_VALIDOS = ['INGRESADO', 'EN_REPARTO', 'ENTREGADO', 'REINGRESADO', 'DEVUELTO', 'BAJA'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/seguimiento.php'));
        exit;
    }

    $estado = (string)($_POST['estado'] ?? '');

    try {
        if (!in_array($estado, $ESTADOS_VALIDOS, true)) {
            flash_set('danger', 'Estado inválido.');
        } else {
            $titulo = trim((string)($_POST['titulo'] ?? ''));
            $desc   = trim((string)($_POST['descripcion'] ?? ''));
            $visible = isset($_POST['visible']);
            $orden  = max(0, (int)($_POST['orden'] ?? 0));

            if ($titulo === '') {
                flash_set('danger', 'El título es obligatorio.');
            } else {
                EstadoPublico::actualizar($estado, $titulo, $desc, $visible, $orden);
                flash_set('success', 'Texto de seguimiento actualizado.');
            }
        }
    } catch (Throwable $e) {
        flash_set('danger', 'No se pudo guardar el cambio.');
        error_log('seguimiento.php: ' . $e->getMessage());
    }

    header('Location: ' . url('admin/seguimiento.php'));
    exit;
}

$estados = EstadoPublico::todas();
$csrf    = Auth::tokenCSRF();

panel_header('Textos de seguimiento', $user, 'seguimiento', 'Lo que ve el cliente en la página pública de seguimiento');
?>
<?php flash_render(); ?>
<p class="text-muted small mb-3">
    Cada estado interno se traduce a este texto público. Los estados marcados como
    <strong>visibles</strong> con orden mayor a 0 forman la línea de tiempo del recorrido
    (ej. Ingresado → En reparto → Entregado). El comprador accede con el enlace que se
    genera desde el detalle de cada producto.
</p>

<div class="row g-3">
    <?php foreach ($estados as $e):
        $est     = (string)$e['estado'];
        $visible = (int)$e['visible'] === 1;
    ?>
    <div class="col-12 col-lg-6">
        <form method="post" class="card p-3 h-100">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="estado" value="<?= h($est) ?>">

            <div class="d-flex align-items-center justify-content-between mb-2">
                <?= estado_badge($est) ?>
                <?php if ($visible && (int)$e['orden'] > 0): ?>
                    <span class="badge b-activo">En la línea de tiempo</span>
                <?php else: ?>
                    <span class="badge b-inactivo">Oculto del recorrido</span>
                <?php endif; ?>
            </div>

            <div class="mb-2">
                <label class="form-label">Título *</label>
                <input class="form-control" name="titulo" maxlength="150" required
                       value="<?= h($e['titulo']) ?>">
            </div>
            <div class="mb-2">
                <label class="form-label">Descripción</label>
                <textarea class="form-control" name="descripcion" rows="2" maxlength="500"
                          placeholder="Texto que lee el cliente para este estado…"><?= h($e['descripcion']) ?></textarea>
            </div>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch"
                           id="vis_<?= h($est) ?>" name="visible" <?= $visible ? 'checked' : '' ?>>
                    <label class="form-check-label small" for="vis_<?= h($est) ?>">Visible al cliente</label>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <label class="form-label mb-0 small text-muted">Orden</label>
                    <input class="form-control form-control-sm" type="number" name="orden" min="0" max="99"
                           style="width:72px" value="<?= (int)$e['orden'] ?>">
                </div>
                <button type="submit" class="btn btn-primary btn-sm ms-auto">
                    <i class="bi bi-check-lg me-1"></i>Guardar
                </button>
            </div>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php
panel_footer();

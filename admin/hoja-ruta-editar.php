<?php
declare(strict_types=1);

// =============================================================================
// admin/hoja-ruta-editar.php — corrección en bloque de datos por HOJA DE RUTA
// (solo admin). Para hojas viejas con datos errados: reasigna transportista,
// corrige la fecha de carga y/o renombra la hoja de ruta en TODAS sus órdenes.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Orden;
use Trazock\Models\Usuario;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/hoja-ruta-editar.php'));
        exit;
    }

    $hoja       = trim((string)($_POST['hoja_ruta'] ?? ''));
    $transpId   = (int)($_POST['transportista_id'] ?? 0);
    $cambiarTr  = isset($_POST['cambiar_transportista']);
    $fecha      = trim((string)($_POST['fecha_carga'] ?? ''));
    $nuevoNom   = trim((string)($_POST['nuevo_nombre'] ?? ''));

    $cambios = [];
    $errores = [];

    if ($hoja === '') {
        $errores[] = 'Elegí una hoja de ruta.';
    }
    if ($cambiarTr) {
        if ($transpId > 0 && !Usuario::existeActivoConRol($transpId, 'transportista')) {
            $errores[] = 'El transportista elegido no es válido.';
        } else {
            $cambios['transportista_id'] = $transpId > 0 ? $transpId : null;
        }
    }
    if ($fecha !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > date('Y-m-d')) {
            $errores[] = 'La fecha de carga es inválida o futura.';
        } else {
            $cambios['fecha_carga'] = $fecha;
        }
    }
    if ($nuevoNom !== '') {
        $cambios['hoja_ruta'] = $nuevoNom;
    }

    if ($errores !== []) {
        flash_set('danger', implode(' ', $errores));
    } elseif ($cambios === []) {
        flash_set('warning', 'No elegiste ningún dato para cambiar.');
    } else {
        try {
            $n = Orden::actualizarPorHojaRuta($hoja, $cambios);
            if ($n === 0) {
                flash_set('warning', 'No se encontraron órdenes para esa hoja de ruta.');
            } else {
                flash_set('success', "Se actualizaron {$n} orden(es) de la hoja de ruta «{$hoja}».");
            }
        } catch (Throwable $e) {
            flash_set('danger', 'No se pudo aplicar el cambio.');
            error_log('hoja-ruta-editar.php: ' . $e->getMessage());
        }
    }
    header('Location: ' . url('admin/hoja-ruta-editar.php'));
    exit;
}

$hojas          = Orden::hojasRuta();
$transportistas = Usuario::transportistasActivos();
$csrf           = Auth::tokenCSRF();

panel_header('Editar hoja de ruta', $user, 'hoja-ruta-editar', 'Corrección en bloque de datos por hoja de ruta');
flash_render();
?>
<div class="row g-3">
  <div class="col-lg-6">
    <?php if ($hojas === []): ?>
      <div class="alert alert-light border">No hay hojas de ruta cargadas todavía.</div>
    <?php else: ?>
    <form method="post" class="card p-3" autocomplete="off" onsubmit="return confirm('¿Aplicar los cambios a TODAS las órdenes de esta hoja de ruta?')">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <div class="mb-3">
        <label class="form-label" for="hoja_ruta">Hoja de ruta *</label>
        <select class="form-select" id="hoja_ruta" name="hoja_ruta" required>
          <option value="">— Elegí una —</option>
          <?php foreach ($hojas as $hr): ?>
            <option value="<?= h($hr) ?>"><?= h($hr) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">El cambio se aplica a todas las órdenes con esta hoja de ruta.</div>
      </div>

      <hr>
      <p class="text-muted small mb-2">Dejá vacío lo que no quieras cambiar.</p>

      <div class="mb-3">
        <div class="form-check mb-1">
          <input class="form-check-input" type="checkbox" id="cambiar_transportista" name="cambiar_transportista" onchange="document.getElementById('transportista_id').disabled=!this.checked">
          <label class="form-check-label" for="cambiar_transportista">Cambiar transportista</label>
        </div>
        <select class="form-select" id="transportista_id" name="transportista_id" disabled>
          <option value="0">(Sin transportista)</option>
          <?php foreach ($transportistas as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['nombre_completo']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Marcá la casilla para reasignar (incluye dejarlo sin transportista).</div>
      </div>

      <div class="mb-3">
        <label class="form-label" for="fecha_carga">Nueva fecha de carga</label>
        <input type="date" class="form-control" id="fecha_carga" name="fecha_carga" max="<?= h(date('Y-m-d')) ?>">
      </div>

      <div class="mb-3">
        <label class="form-label" for="nuevo_nombre">Renombrar hoja de ruta</label>
        <input class="form-control" id="nuevo_nombre" name="nuevo_nombre" maxlength="120" placeholder="Nuevo Nº/nombre (vacío = no cambiar)">
        <div class="form-text">Si la hoja quedó mal escrita. Ojo: si ponés el nombre de otra hoja existente, las órdenes se unifican en esa.</div>
      </div>

      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-pencil-square me-1"></i>Aplicar a la hoja de ruta</button>
    </form>
    <?php endif; ?>
  </div>
  <div class="col-lg-6">
    <div class="alert alert-light border small">
      <i class="bi bi-info-circle me-1"></i>Esta corrección modifica los datos de las órdenes de una hoja de ruta (transportista, fecha de carga, nombre de la hoja). No cambia el estado ni los ítems. Para fijar estados con fecha histórica usá <a href="<?= h(url('admin/estado-masivo.php')) ?>">Estado masivo</a>.
    </div>
  </div>
</div>
<?php
panel_footer();

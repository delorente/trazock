<?php
declare(strict_types=1);

// =============================================================================
// admin/estado-masivo.php — cambio de estado en lote (solo admin).
//
// Para cargas viejas: se pega un listado de Nº de orden + una fecha + un estado,
// y se fija ese estado (con esa fecha histórica) en todas las órdenes del listado.
// Aplica como ajuste manual a todos los ítems de cada orden (Orden::fijarEstadoHistorico).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Orden;

$user = Auth::requierePanel();
Auth::requiereRol('admin');

// Estado destino (vocabulario de producto) → etiqueta legible.
$ESTADOS = [
    'INGRESADO'   => 'Recibido',
    'EN_REPARTO'  => 'En reparto',
    'ENTREGADO'   => 'Entregado',
    'REINGRESADO' => 'Reingresado',
    'DEVUELTO'    => 'Devuelto',
];

$resultado = null;
$prev = ['listado' => '', 'estado' => 'ENTREGADO', 'fecha' => '', 'hora' => '12:00', 'motivo' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prev['listado'] = (string)($_POST['listado'] ?? '');
    $prev['estado']  = (string)($_POST['estado'] ?? '');
    $prev['fecha']   = (string)($_POST['fecha'] ?? '');
    $prev['hora']    = (string)($_POST['hora'] ?? '12:00');
    $prev['motivo']  = (string)($_POST['motivo'] ?? '');

    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif (!isset($ESTADOS[$prev['estado']])) {
        flash_set('danger', 'Elegí un estado válido.');
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $prev['fecha'])) {
        flash_set('danger', 'Elegí una fecha válida.');
    } else {
        $hora = preg_match('/^\d{2}:\d{2}$/', $prev['hora']) ? $prev['hora'] : '12:00';
        // La fecha se ingresa en hora local y se guarda en UTC (como el resto del sistema).
        $tzLocal = new DateTimeZone(defined('DISPLAY_TZ') ? DISPLAY_TZ : 'UTC');
        $dt = DateTime::createFromFormat('Y-m-d H:i', $prev['fecha'] . ' ' . $hora, $tzLocal);
        if ($dt === false) {
            flash_set('danger', 'No se pudo interpretar la fecha/hora.');
        } else {
            $dt->setTimezone(new DateTimeZone('UTC'));
            $tsUtc = $dt->format('Y-m-d H:i:s');

            // Parsear el listado: separa por espacios, comas, ; o saltos de línea.
            $tokens = preg_split('/[\s,;]+/u', trim($prev['listado']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_unique(array_map('trim', $tokens)));

            if ($tokens === []) {
                flash_set('danger', 'Pegá al menos un Nº de orden.');
            } elseif (count($tokens) > 2000) {
                flash_set('danger', 'Demasiadas órdenes en un solo envío (máximo 2000).');
            } else {
                $det = [];
                $okN = 0; $noN = 0; $errN = 0; $itemsN = 0;
                foreach ($tokens as $nro) {
                    $orden = Orden::findByNroOrden($nro);
                    if ($orden === null) {
                        $det[] = ['nro' => $nro, 'estado' => 'no', 'msg' => 'No encontrada'];
                        $noN++;
                        continue;
                    }
                    try {
                        $n = Orden::fijarEstadoHistorico((int)$orden['id'], $prev['estado'], $tsUtc, $prev['motivo'], (int)$user['id']);
                        $det[] = ['nro' => $nro, 'estado' => 'ok', 'msg' => $n . ' ítem(s)'];
                        $okN++; $itemsN += $n;
                    } catch (Throwable $e) {
                        $det[] = ['nro' => $nro, 'estado' => 'err', 'msg' => mb_substr($e->getMessage(), 0, 120)];
                        $errN++;
                        error_log('estado-masivo.php ' . $nro . ': ' . $e->getMessage());
                    }
                }
                $resultado = [
                    'detalle' => $det, 'ok' => $okN, 'no' => $noN, 'err' => $errN, 'items' => $itemsN,
                    'estado_label' => $ESTADOS[$prev['estado']], 'fecha' => $prev['fecha'] . ' ' . $hora,
                ];
            }
        }
    }
}

$csrf = Auth::tokenCSRF();
panel_header('Estado masivo', $user, 'estado-masivo', 'Fijar estado + fecha a un listado de órdenes (cargas viejas)');
flash_render();
?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" class="card p-3" autocomplete="off" onsubmit="return confirm('¿Aplicar el estado a todas las órdenes del listado? Esta acción cambia el estado de cada orden.')">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <div class="mb-3">
        <label class="form-label" for="estado">Estado a fijar *</label>
        <select class="form-select" id="estado" name="estado" required>
          <?php foreach ($ESTADOS as $k => $lbl): ?>
            <option value="<?= h($k) ?>" <?= $prev['estado'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="row g-2 mb-3">
        <div class="col-7"><label class="form-label" for="fecha">Fecha *</label><input type="date" class="form-control" id="fecha" name="fecha" max="<?= h(date('Y-m-d')) ?>" value="<?= h($prev['fecha']) ?>" required></div>
        <div class="col-5"><label class="form-label" for="hora">Hora</label><input type="time" class="form-control" id="hora" name="hora" value="<?= h($prev['hora']) ?>"></div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="motivo">Motivo / nota</label>
        <input class="form-control" id="motivo" name="motivo" maxlength="50" value="<?= h($prev['motivo']) ?>" placeholder="Opcional (def. «Carga histórica»)">
      </div>
      <div class="mb-3">
        <label class="form-label" for="listado">Nº de orden *</label>
        <textarea class="form-control mono" id="listado" name="listado" rows="12" required placeholder="Pegá un Nº por línea (o separados por coma/espacio)&#10;0775-123456&#10;0775-123457&#10;…"><?= h($prev['listado']) ?></textarea>
        <div class="form-text">Uno por línea o separados por coma/espacio. Hasta 2000.</div>
      </div>
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-calendar-check me-1"></i>Aplicar estado al listado</button>
    </form>
    <div class="alert alert-light border small mt-3">
      <i class="bi bi-info-circle me-1"></i>Se aplica a <strong>todos los ítems</strong> de cada orden, datado en la fecha elegida (ajuste manual, queda en el historial). Las órdenes ya deben estar cargadas (subí la carga vieja con el import OCR primero).
    </div>
  </div>

  <div class="col-lg-7">
    <?php if ($resultado === null): ?>
      <div class="card p-4 text-muted text-center">El resultado del procesamiento aparece acá.</div>
    <?php else: ?>
      <div class="card">
        <div class="card-header" style="padding:.6rem 1rem">
          Resultado — <strong><?= h($resultado['estado_label']) ?></strong> al <?= h($resultado['fecha']) ?>
        </div>
        <div class="d-flex gap-3 flex-wrap p-3" style="border-bottom:1px solid var(--border)">
          <div><span class="badge b-activo"><?= (int)$resultado['ok'] ?></span> aplicadas (<?= (int)$resultado['items'] ?> ítems)</div>
          <div><span class="badge b-inactivo"><?= (int)$resultado['no'] ?></span> no encontradas</div>
          <div><span class="badge" style="background:#fee2e2;color:#b91c1c"><?= (int)$resultado['err'] ?></span> con error</div>
        </div>
        <div style="max-height:60vh;overflow:auto">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>Nº orden</th><th>Resultado</th></tr></thead>
            <tbody>
            <?php foreach ($resultado['detalle'] as $d): ?>
              <tr>
                <td class="mono" style="font-size:12px"><?= h($d['nro']) ?></td>
                <td style="font-size:12px">
                  <?php if ($d['estado'] === 'ok'): ?>
                    <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i><?= h($d['msg']) ?></span>
                  <?php elseif ($d['estado'] === 'no'): ?>
                    <span class="text-muted"><i class="bi bi-dash-circle me-1"></i><?= h($d['msg']) ?></span>
                  <?php else: ?>
                    <span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= h($d['msg']) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php
panel_footer();

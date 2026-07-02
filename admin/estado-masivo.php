<?php
declare(strict_types=1);

// =============================================================================
// admin/estado-masivo.php — cambio de estado en lote con FECHA POR ORDEN (solo admin).
//
// Para cargas viejas / entregas retroactivas: se pega un listado donde cada línea es
//   nro_orden,fecha        (ej. 775-286391,06-01-26)
// y se fija el estado elegido en cada orden, datado en SU fecha. El Nº se valida
// tolerando ceros a la izquierda (775-286391 == 0775-00286391). Aplica como ajuste
// manual a todos los ítems de cada orden (Orden::fijarEstadoHistorico).
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

/**
 * Parsea una fecha DD-MM-YY(YY) o DD/MM/YY(YY) (o ISO Y-m-d) a 'Y-m-d'.
 * Año de 2 dígitos → 20xx. Devuelve null si es inválida.
 */
function em_parse_fecha(string $s): ?string
{
    $s = trim($s);
    if (preg_match('#^(\d{1,2})[-/](\d{1,2})[-/](\d{2}|\d{4})$#', $s, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if ($y < 100) { $y += 2000; }
    } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $s, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    } else {
        return null;
    }
    if (!checkdate($mo, $d, $y)) { return null; }
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

$resultado = null;
$prev = ['listado' => '', 'estado' => 'ENTREGADO', 'hora' => '12:00', 'motivo' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prev['listado'] = (string)($_POST['listado'] ?? '');
    $prev['estado']  = (string)($_POST['estado'] ?? '');
    $prev['hora']    = (string)($_POST['hora'] ?? '12:00');
    $prev['motivo']  = (string)($_POST['motivo'] ?? '');

    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif (!isset($ESTADOS[$prev['estado']])) {
        flash_set('danger', 'Elegí un estado válido.');
    } else {
        $hora = preg_match('/^\d{2}:\d{2}$/', $prev['hora']) ? $prev['hora'] : '12:00';
        $tzLocal = new DateTimeZone(defined('DISPLAY_TZ') ? DISPLAY_TZ : 'UTC');
        $hoy = (new DateTime('now', $tzLocal))->format('Y-m-d');

        // Una línea por orden: "nro,fecha". Se ignoran líneas vacías.
        $lineas = preg_split('/\r\n|\r|\n/', trim($prev['listado'])) ?: [];
        $lineas = array_values(array_filter(array_map('trim', $lineas), static fn($l) => $l !== ''));

        if ($lineas === []) {
            flash_set('danger', 'Pegá al menos una línea (nº de orden, fecha).');
        } elseif (count($lineas) > 2000) {
            flash_set('danger', 'Demasiadas líneas en un solo envío (máximo 2000).');
        } else {
            $det = [];
            $okN = 0; $noN = 0; $errN = 0; $itemsN = 0;
            foreach ($lineas as $linea) {
                // Separadores admitidos entre nº y fecha: coma, punto y coma, tab o | .
                $partes = preg_split('/[,;\t|]+/', $linea, 2);
                $nro = trim((string)($partes[0] ?? ''));
                $fechaStr = trim((string)($partes[1] ?? ''));

                if ($nro === '') { continue; }
                if ($fechaStr === '') {
                    $det[] = ['nro' => $nro, 'fecha' => '', 'estado' => 'err', 'msg' => 'Falta la fecha (usá nº,fecha)'];
                    $errN++; continue;
                }
                $fecha = em_parse_fecha($fechaStr);
                if ($fecha === null) {
                    $det[] = ['nro' => $nro, 'fecha' => $fechaStr, 'estado' => 'err', 'msg' => 'Fecha inválida (DD-MM-AA)'];
                    $errN++; continue;
                }
                if ($fecha > $hoy) {
                    $det[] = ['nro' => $nro, 'fecha' => $fecha, 'estado' => 'err', 'msg' => 'Fecha futura'];
                    $errN++; continue;
                }

                $matches = Orden::buscarFlexiblePorNro($nro);
                if ($matches === []) {
                    $det[] = ['nro' => $nro, 'fecha' => $fecha, 'estado' => 'no', 'msg' => 'No encontrada'];
                    $noN++; continue;
                }
                if (count($matches) > 1) {
                    $nros = implode(', ', array_map(static fn($o) => (string)$o['nro_orden'], $matches));
                    $det[] = ['nro' => $nro, 'fecha' => $fecha, 'estado' => 'err', 'msg' => 'Ambiguo: ' . $nros];
                    $errN++; continue;
                }

                $orden = $matches[0];
                // Fecha local + hora → UTC (como el resto del sistema).
                $dt = DateTime::createFromFormat('Y-m-d H:i', $fecha . ' ' . $hora, $tzLocal);
                $tsUtc = $dt ? $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s');
                try {
                    $n = Orden::fijarEstadoHistorico((int)$orden['id'], $prev['estado'], $tsUtc, $prev['motivo'], (int)$user['id']);
                    $det[] = ['nro' => (string)$orden['nro_orden'], 'fecha' => $fecha, 'estado' => 'ok', 'msg' => $n . ' ítem(s)'];
                    $okN++; $itemsN += $n;
                } catch (Throwable $e) {
                    $det[] = ['nro' => $nro, 'fecha' => $fecha, 'estado' => 'err', 'msg' => mb_substr($e->getMessage(), 0, 120)];
                    $errN++;
                    error_log('estado-masivo.php ' . $nro . ': ' . $e->getMessage());
                }
            }
            $resultado = [
                'detalle' => $det, 'ok' => $okN, 'no' => $noN, 'err' => $errN, 'items' => $itemsN,
                'estado_label' => $ESTADOS[$prev['estado']],
            ];
        }
    }
}

$csrf = Auth::tokenCSRF();
panel_header('Estado masivo', $user, 'estado-masivo', 'Fijar un estado con la fecha de cada orden (una por línea: nº, fecha)');
flash_render();
?>
<div class="row g-3">
  <div class="col-lg-5">
    <form method="post" class="card p-3" autocomplete="off" onsubmit="return confirm('¿Aplicar el estado a todas las órdenes del listado, cada una con su fecha?')">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <div class="row g-2 mb-3">
        <div class="col-7">
          <label class="form-label" for="estado">Estado a fijar *</label>
          <select class="form-select" id="estado" name="estado" required>
            <?php foreach ($ESTADOS as $k => $lbl): ?>
              <option value="<?= h($k) ?>" <?= $prev['estado'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-5"><label class="form-label" for="hora">Hora</label><input type="time" class="form-control" id="hora" name="hora" value="<?= h($prev['hora']) ?>"></div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="motivo">Motivo / nota</label>
        <input class="form-control" id="motivo" name="motivo" maxlength="50" value="<?= h($prev['motivo']) ?>" placeholder="Opcional (def. «Corrección de estado»)">
      </div>
      <div class="mb-3">
        <label class="form-label" for="listado">Órdenes con su fecha *</label>
        <textarea class="form-control mono" id="listado" name="listado" rows="12" required placeholder="Una por línea: Nº de orden , fecha&#10;775-286391,06-01-26&#10;775-287115,09-01-26"><?= h($prev['listado']) ?></textarea>
        <div class="form-text">Una por línea, formato <strong>nº,fecha</strong>. Fecha DD-MM-AA (ej. 06-01-26). El nº se busca aunque no tenga los ceros a la izquierda. Hasta 2000.</div>
      </div>
      <button class="btn btn-primary w-100" type="submit"><i class="bi bi-calendar-check me-1"></i>Aplicar</button>
    </form>
    <div class="alert alert-light border small mt-3">
      <i class="bi bi-info-circle me-1"></i>Se aplica a <strong>todos los ítems</strong> de cada orden, datado en la fecha de esa línea (ajuste manual, queda en el historial). Las órdenes ya deben estar cargadas.
    </div>
  </div>

  <div class="col-lg-7">
    <?php if ($resultado === null): ?>
      <div class="card p-4 text-muted text-center">El resultado del procesamiento aparece acá.</div>
    <?php else: ?>
      <div class="card">
        <div class="card-header" style="padding:.6rem 1rem">
          Resultado — <strong><?= h($resultado['estado_label']) ?></strong>
        </div>
        <div class="d-flex gap-3 flex-wrap p-3" style="border-bottom:1px solid var(--border)">
          <div><span class="badge b-activo"><?= (int)$resultado['ok'] ?></span> aplicadas (<?= (int)$resultado['items'] ?> ítems)</div>
          <div><span class="badge b-inactivo"><?= (int)$resultado['no'] ?></span> no encontradas</div>
          <div><span class="badge" style="background:#fee2e2;color:#b91c1c"><?= (int)$resultado['err'] ?></span> con error</div>
        </div>
        <div style="max-height:60vh;overflow:auto">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>Nº orden</th><th>Fecha</th><th>Resultado</th></tr></thead>
            <tbody>
            <?php foreach ($resultado['detalle'] as $d): ?>
              <tr>
                <td class="mono" style="font-size:12px"><?= h($d['nro']) ?></td>
                <td style="font-size:12px"><?= h((string)($d['fecha'] ?? '')) ?></td>
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

<?php
declare(strict_types=1);

// =============================================================================
// admin/lote-detalle.php — encabezado del lote + tabla de items con resultado.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\CostoViaje;
use Trazock\Models\EntregaRemito;
use Trazock\Models\Lote;
use Trazock\Models\Orden;
use Trazock\Models\Usuario;

$user    = Auth::requierePanel(['admin', 'gestor', 'logistica', 'contable']);
$esAdmin = $user['rol'] === 'admin';
$puedeCostos = in_array($user['rol'], ['admin', 'contable'], true); // costos del viaje: admin/contable

// POST: edición de carga (admin) o alta/baja de costos del viaje (admin/gestor). PRG+CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lid    = (int)($_POST['id'] ?? 0);
    $accion = (string)($_POST['accion'] ?? 'carga');
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif (($accion === 'costo_add' || $accion === 'costo_del') && !$puedeCostos) {
        flash_set('danger', 'No tenés permiso para editar costos.');
    } elseif ($accion === 'costo_add') {
        $tipo  = (string)($_POST['costo_tipo'] ?? 'otro');
        $imp   = (float)str_replace(',', '.', (string)($_POST['costo_importe'] ?? '0'));
        $fecha = trim((string)($_POST['costo_fecha'] ?? '')) ?: date('Y-m-d');
        $obs   = trim((string)($_POST['costo_obs'] ?? ''));
        if ($imp <= 0) {
            flash_set('danger', 'El importe del costo debe ser mayor a 0.');
        } else {
            CostoViaje::crear($lid, $tipo, $imp, $fecha, $obs, (int)$user['id']);
            flash_set('success', 'Costo agregado al viaje.');
        }
    } elseif ($accion === 'costo_del') {
        $cid = (int)($_POST['costo_id'] ?? 0);
        if ($cid > 0 && CostoViaje::loteDe($cid) === $lid) {
            CostoViaje::eliminar($cid);
            flash_set('success', 'Costo eliminado.');
        } else {
            flash_set('danger', 'Costo inválido.');
        }
    } elseif (!$esAdmin) {
        flash_set('danger', 'No tenés permiso para editar la carga.');
    } else {
        $cargaId  = (int)($_POST['carga_id'] ?? 0);
        $transpId = (int)($_POST['transportista_id'] ?? 0);
        $fCarga   = trim((string)($_POST['fecha_carga'] ?? ''));
        if ($cargaId <= 0) {
            flash_set('danger', 'Carga inválida.');
        } elseif ($transpId > 0 && !Usuario::existeActivoConRol($transpId, 'transportista')) {
            flash_set('danger', 'Transportista inválido.');
        } elseif ($fCarga !== '' && $fCarga > date('Y-m-d')) {
            flash_set('danger', 'La fecha de carga no puede ser posterior a hoy.');
        } else {
            $n = Orden::actualizarDatosCarga($cargaId, $transpId > 0 ? $transpId : null, $fCarga !== '' ? $fCarga : null);
            flash_set('success', "Datos de carga actualizados en {$n} orden(es).");
        }
    }
    header('Location: ' . url('admin/lote-detalle.php') . '?id=' . $lid);
    exit;
}

$id   = (int)($_GET['id'] ?? 0);
$lote = $id > 0 ? Lote::findById($id) : null;

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/lotes.php')) . '"><i class="bi bi-arrow-left me-1"></i>Volver</a>';

if ($lote === null) {
    panel_header('Lote no encontrado', $user, 'lotes', '', $volver);
    echo '<div class="alert alert-warning">No se encontró el lote solicitado.</div>';
    panel_footer();
    exit;
}

$items   = Lote::items($id);
$ordenes = Lote::ordenes($id); // vacío en lotes legacy sin orden

// Carga (import OCR) de este lote de INGRESO: para editar en bloque sus datos.
$cargaId    = $ordenes !== [] ? (int)($ordenes[0]['carga_id'] ?? 0) : 0;
$cargaDatos = null;
$transportistasAll = [];
$csrf = Auth::tokenCSRF();
if ($lote['tipo'] === 'INGRESO' && $cargaId > 0) {
    $cargaDatos = Orden::datosCarga($cargaId);
    $transportistasAll = Usuario::transportistasActivos();
}

// Costos del viaje (lotes con vehículo: ingreso, reparto, devolución).
$esViaje = in_array($lote['tipo'], ['INGRESO', 'SALIDA_REPARTO', 'SALIDA_DEVOLUCION'], true);
$costos      = $esViaje ? CostoViaje::porLote($id) : [];
$totalCostos = $esViaje ? CostoViaje::totalLote($id) : 0.0;

// Los lotes de INGRESO agrupan los productos de una carga: se pueden reimprimir
// todas sus etiquetas juntas (por si no salieron bien al cargar).
if ($lote['tipo'] === 'INGRESO') {
    $volver .= '<a class="btn btn-sm btn-outline-secondary" href="'
        . h(url('admin/ordenes-etiquetas.php') . '?lote=' . $id)
        . '"><i class="bi bi-tag me-1"></i>Re-imprimir etiquetas</a>';
    if ($esAdmin && $cargaId > 0) {
        $volver .= '<button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modalEditarCarga"><i class="bi bi-pencil me-1"></i>Editar datos de carga</button>';
    }
}

// Salida a reparto: imprimir la hoja de ruta con los datos del viaje.
if ($lote['tipo'] === 'SALIDA_REPARTO') {
    $volver .= '<a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="'
        . h(url('admin/hoja-ruta.php') . '?lote=' . $id)
        . '"><i class="bi bi-printer me-1"></i>Hoja de ruta</a>';
}

/** Celda de campo con label en mayúsculas (estilo prototipo). */
function tz_campo(string $label, ?string $valor, bool $mono = false): void
{
    echo '<div><span style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block">'
        . h($label) . '</span><span' . ($mono ? ' class="mono"' : '') . '>' . h(($valor ?? '') !== '' ? $valor : '—') . '</span></div>';
}

panel_header(lote_num((int)$lote['id'], $lote['created_at']), $user, 'lotes', tipo_lote_label($lote['tipo']), $volver);
flash_render();
?>
<div class="card p-3 mb-3">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
        <?= tipo_lote_badge($lote['tipo']) ?>
        <span class="text-muted mono" style="font-size:12px"><?= h($lote['uuid']) ?></span>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;font-size:13px">
        <?php
        tz_campo('Responsable', $lote['responsable_nombre']);
        if ($ordenes !== []) { tz_campo('Órdenes', (string)count($ordenes)); }
        tz_campo('Categoría', $lote['categoria_nombre']);
        tz_campo('Proveedor', $lote['proveedor_nombre']);
        tz_campo('Transportista', $lote['transportista_nombre']);
        tz_campo('Conductor', $lote['chofer'] ?? null);
        tz_campo('Ayudante(s)', $lote['ayudantes'] ?? null);
        tz_campo('Motivo', $lote['motivo_nombre'] !== null && $lote['motivo_libre']
            ? $lote['motivo_nombre'] . ' (' . $lote['motivo_libre'] . ')' : $lote['motivo_nombre']);
        tz_campo('N° remito', $lote['numero_remito'], true);
        tz_campo('Apertura', fmt_fecha($lote['timestamp_apertura']));
        tz_campo('Cierre', fmt_fecha($lote['timestamp_cierre']));
        tz_campo('Sync server', fmt_fecha($lote['timestamp_sync']));
        tz_campo('Dispositivo', $lote['dispositivo_info']);
        ?>
        <?php if (!empty($lote['observaciones'])): ?>
            <div style="grid-column:1/-1"><span style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block">Observaciones</span><?= h($lote['observaciones']) ?></div>
        <?php endif; ?>
    </div>
</div>

<?php
// Remitos firmados (fotos de la entrega). Se muestran si los hay.
$remitos = EntregaRemito::porLoteUuid((string)$lote['uuid']);
if ($remitos !== []): ?>
<div class="card mb-3">
    <div class="card-header" style="padding:.6rem 1rem"><i class="bi bi-camera-fill me-1"></i>Remitos firmados (<?= count($remitos) ?>)</div>
    <div class="d-flex flex-wrap gap-2" style="padding:1rem">
        <?php foreach ($remitos as $r): $verUrl = url('api/remito-ver.php') . '?uuid=' . rawurlencode((string)$r['foto_uuid']); ?>
            <a href="<?= h($verUrl) ?>" target="_blank" rel="noopener" title="<?= h((string)$r['archivo']) ?>">
                <img src="<?= h($verUrl) ?>" style="width:120px;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($esViaje): $accUrl = url('admin/lote-detalle.php') . '?id=' . $id; ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center" style="padding:.6rem 1rem">
        <span><i class="bi bi-cash-stack me-1"></i>Costos del viaje</span>
        <strong>Total: $ <?= number_format($totalCostos, 2, ',', '.') ?></strong>
    </div>
    <div style="overflow-x:auto">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Tipo</th><th class="text-end">Importe</th><th>Fecha</th><th>Observación</th><th>Cargó</th><th></th></tr></thead>
            <tbody>
            <?php if ($costos === []): ?>
                <tr><td colspan="6" class="text-muted text-center py-3">Sin costos cargados.</td></tr>
            <?php endif; ?>
            <?php foreach ($costos as $c): ?>
                <tr>
                    <td><?= h(CostoViaje::TIPOS[$c['tipo']] ?? (string)$c['tipo']) ?></td>
                    <td class="text-end">$ <?= number_format((float)$c['importe'], 2, ',', '.') ?></td>
                    <td class="text-muted" style="font-size:12px"><?= h(($c['fecha'] ?? '') ? date('d/m/Y', strtotime((string)$c['fecha'])) : '—') ?></td>
                    <td style="font-size:13px"><?= h((string)($c['observacion'] ?? '')) ?></td>
                    <td class="text-muted" style="font-size:12px"><?= h((string)($c['creador'] ?? '')) ?></td>
                    <td class="text-end">
                        <?php if ($puedeCostos): ?>
                        <form method="post" class="d-inline" action="<?= h($accUrl) ?>">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="accion" value="costo_del">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="costo_id" value="<?= (int)$c['id'] ?>">
                            <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2" title="Eliminar"
                                    onclick="tzConfirm(this.closest('form'), '¿Eliminar este costo?')"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($puedeCostos): ?>
    <div style="border-top:1px solid var(--border);padding:.6rem 1rem">
        <form method="post" class="row g-2 align-items-end" action="<?= h($accUrl) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="accion" value="costo_add">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="col-6 col-md-3">
                <label class="form-label" style="font-size:12px">Tipo</label>
                <select class="form-select form-select-sm" name="costo_tipo">
                    <?php foreach (CostoViaje::TIPOS as $k => $v): ?><option value="<?= h($k) ?>"><?= h($v) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:12px">Importe</label>
                <div class="input-group input-group-sm"><span class="input-group-text">$</span>
                    <input type="number" step="0.01" min="0" class="form-control" name="costo_importe" required>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label" style="font-size:12px">Fecha</label>
                <input type="date" class="form-control form-control-sm" name="costo_fecha" value="<?= h(date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>">
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label" style="font-size:12px">Observación</label>
                <input type="text" class="form-control form-control-sm" name="costo_obs" maxlength="255" placeholder="Opcional">
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100" type="submit"><i class="bi bi-plus-lg"></i></button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($ordenes !== []): ?>
<div class="card mb-3">
    <div class="card-header" style="padding:.6rem 1rem">Incluye <?= count($ordenes) ?> orden(es)</div>
    <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
            <thead><tr><th>Nº orden</th><th>Cliente</th><th>Destino</th><th>Ítems</th><th>Estado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($ordenes as $o):
                $loc = trim((string)($o['dest_localidad'] ?? '')); $prov = trim((string)($o['dest_provincia'] ?? ''));
                $dest = trim($loc . ($loc !== '' && $prov !== '' ? ' · ' : '') . $prov) ?: '—';
            ?>
                <tr>
                    <td class="mono" style="font-size:12px"><?= h((string)$o['nro_orden']) ?></td>
                    <td><?= h((string)($o['cliente'] ?? '—')) ?></td>
                    <td style="font-size:13px"><?= h($dest) ?></td>
                    <td style="text-align:center"><?= (int)$o['items'] ?></td>
                    <td><?= estado_badge((string)($o['estado'] ?? '')) ?></td>
                    <td><a class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:11px" href="<?= h(url('admin/ordenes-detalle.php?id=' . (int)$o['id'])) ?>">Ver</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header" style="padding:.6rem 1rem"><?= count($items) ?> ítem(s)<?= $ordenes !== [] ? ' (uno por unidad física)' : ' escaneados' ?></div>
    <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
            <thead><tr><th>Código escaneado</th><th>Hora (cliente)</th><th>Resultado</th><th>Transición</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td class="mono"><?= h($it['codigo_escaneado']) ?></td>
                    <td class="text-muted" style="font-size:12px"><?= h(fmt_fecha($it['timestamp_cliente'])) ?></td>
                    <td><?= resultado_badge($it['resultado']) ?></td>
                    <td style="font-size:12px">
                        <?php if ($it['transicion_id'] !== null): ?>
                            <?= estado_badge($it['estado_desde'] ?? null) ?> <i class="bi bi-arrow-right text-muted" style="font-size:10px"></i> <?= estado_badge($it['estado_hasta']) ?>
                            <?php if ((int)($it['es_conflicto'] ?? 0) === 1): ?><i class="bi bi-exclamation-triangle-fill ms-1" style="color:var(--red)"></i><?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?php if (!empty($it['producto_codigo'])): ?><a style="font-size:12px" href="<?= h(url('admin/producto-detalle.php?codigo=' . urlencode($it['producto_codigo']))) ?>">Ver producto</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php if ($esAdmin && $lote['tipo'] === 'INGRESO' && $cargaId > 0): ?>
<!-- Modal: editar datos de la carga (en bloque, todas sus órdenes) -->
<div class="modal fade" id="modalEditarCarga" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content" action="<?= h(url('admin/lote-detalle.php') . '?id=' . $id) ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="carga_id" value="<?= (int)$cargaId ?>">
      <div class="modal-header">
        <h5 class="modal-title">Editar datos de carga</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Transportista</label>
          <select class="form-select form-select-sm" name="transportista_id">
            <option value="">—</option>
            <?php foreach ($transportistasAll as $t): ?>
              <option value="<?= (int)$t['id'] ?>" <?= (int)($cargaDatos['transportista_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['nombre_completo']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Fecha de carga</label>
          <input type="date" class="form-control form-control-sm" name="fecha_carga" max="<?= h(date('Y-m-d')) ?>" value="<?= h((string)($cargaDatos['fecha_carga'] ?? '')) ?>">
        </div>
        <div class="alert alert-warning py-2 mb-0" style="font-size:12px"><i class="bi bi-exclamation-triangle me-1"></i>Se aplica a <strong>todas las <?= count($ordenes) ?> órden(es)</strong> de esta carga. Si en su momento cargaste documentos con datos distintos, quedarán todos iguales.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-warning" type="submit"><i class="bi bi-check-lg me-1"></i>Guardar</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php
panel_footer();

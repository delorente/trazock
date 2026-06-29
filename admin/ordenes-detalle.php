<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-detalle.php — detalle de una orden: datos, ítems (con estado y
// etiqueta) y vista previa del rótulo con QR. Permite reimprimir etiquetas.
// Se llega desde Reportes (?id=ID). admin/gestor.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\EtiquetaQr;
use Trazock\Models\EntregaRemito;
use Trazock\Models\Orden;
use Trazock\Models\Producto;
use Trazock\Models\Usuario;

$user    = Auth::requierePanel(['admin', 'gestor', 'logistica']); // gestor = Supervisor (solo lectura)
$puedeEditar = in_array($user['rol'], ['admin', 'logistica'], true); // gestor = solo lectura

// Querystring de Reportes para volver con el filtro intacto. Llega como ?vol=… en
// la URL (también en el action de los forms, así viaja en el POST). Siempre se
// reusa anteponiendo la URL fija de Reportes → no hay open-redirect.
$vol = trim((string)($_GET['vol'] ?? $_POST['vol'] ?? ''));
$reporteVolverUrl = url('admin/ordenes-reportes.php') . ($vol !== '' ? '?' . $vol : '');

// --- POST: editar / agregar ítem / quitar ítem / eliminar (PRG + CSRF) -------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oid    = (int)($_POST['id'] ?? 0);
    $accion = (string)($_POST['accion'] ?? 'guardar');
    $volverA = url('admin/ordenes-detalle.php') . '?id=' . $oid . ($vol !== '' ? '&vol=' . urlencode($vol) : '');

    if (!$puedeEditar) {
        flash_set('danger', 'No tenés permiso para modificar órdenes.');
    } elseif (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif ($oid <= 0 || Orden::find($oid) === null) {
        flash_set('danger', 'Orden no encontrada.');
    } elseif ($accion === 'agregar_item') {
        $desc = trim((string)($_POST['descripcion'] ?? ''));
        $dim  = trim((string)($_POST['dimensiones'] ?? ''));
        $cant = max(1, min(99, (int)($_POST['cantidad'] ?? 1)));
        $m3in = trim(str_replace(',', '.', (string)($_POST['m3'] ?? '')));
        $m3   = $m3in !== '' && is_numeric($m3in) ? (float)$m3in : null;
        try {
            $n = Orden::agregarItems($oid, $desc !== '' ? $desc : null, $dim !== '' ? $dim : null, $m3, $cant);
            flash_set('success', $n . ' ítem(s) agregado(s). Reimprimí las etiquetas para actualizar el "de N".');
        } catch (\Throwable $e) {
            flash_set('danger', 'No se pudo agregar el ítem: ' . $e->getMessage());
        }
    } elseif ($accion === 'quitar_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $res = $itemId > 0 ? Producto::eliminarItem($itemId) : 'no_existe';
        if ($res === 'ok') {
            flash_set('success', 'Ítem quitado de la orden.');
        } elseif ($res === 'despachado') {
            flash_set('warning', 'No se puede quitar: el ítem ya salió del depósito.');
        } else {
            flash_set('danger', 'No se encontró el ítem.');
        }
    } elseif ($accion === 'eliminar_orden') {
        $res = Orden::eliminar($oid);
        if ($res === 'ok') {
            flash_set('success', 'Orden eliminada.');
            header('Location: ' . $reporteVolverUrl);
            exit;
        }
        flash_set($res === 'despachada' ? 'warning' : 'danger',
            $res === 'despachada'
                ? 'No se puede eliminar: la orden ya tiene ítems despachados.'
                : 'No se encontró la orden.');
    } elseif ($accion === 'corregir_estado') {
        // Corrección manual del estado de la orden (ajuste manual a todos sus ítems).
        $mapa   = ['RECIBIDO' => 'INGRESADO', 'EN_REPARTO' => 'EN_REPARTO', 'ENTREGADO' => 'ENTREGADO',
                   'REINGRESADO' => 'REINGRESADO', 'DEVUELTO' => 'DEVUELTO', 'BAJA' => 'BAJA'];
        $dest   = (string)($_POST['nuevo_estado'] ?? '');
        $motivo = trim((string)($_POST['motivo'] ?? ''));
        if (!isset($mapa[$dest])) {
            flash_set('danger', 'Estado destino inválido.');
        } elseif ($motivo === '') {
            flash_set('danger', 'El motivo es obligatorio.');
        } else {
            try {
                $n = Orden::corregirEstado($oid, $mapa[$dest], $motivo, (int)$user['id']);
                flash_set('success', "Estado corregido a " . str_replace('_', ' ', $dest) . " ({$n} ítem(s) ajustado(s)).");
            } catch (\Throwable $e) {
                flash_set('danger', 'No se pudo corregir el estado: ' . $e->getMessage());
            }
        }
    } else { // guardar
        $tvIn     = (string)($_POST['tipo_venta'] ?? '');
        $valor    = trim((string)($_POST['valor_declarado'] ?? ''));
        $transpId = (int)($_POST['transportista_id'] ?? 0);
        $fCarga   = trim((string)($_POST['fecha_carga'] ?? ''));
        if ($transpId > 0 && !\Trazock\Models\Usuario::existeActivoConRol($transpId, 'transportista')) {
            flash_set('danger', 'Transportista inválido.');
        } elseif ($fCarga !== '' && $fCarga > date('Y-m-d')) {
            flash_set('danger', 'La fecha de carga no puede ser posterior a hoy.');
        } else {
            $telefonos = trim((string)($_POST['telefonos'] ?? ''));
            // Teléfono WhatsApp: si lo dejan vacío, se re-deriva del literal; si lo
            // escriben a mano, se respeta tal cual (corrección manual).
            $telWaIn = trim((string)($_POST['telefono_wa'] ?? ''));
            $telefonoWa = $telWaIn !== '' ? $telWaIn : (tel_e164($telefonos) ?? '');
            Orden::actualizarDatos($oid, [
                'cliente'          => trim((string)($_POST['cliente'] ?? '')),
                'cliente_apellido' => trim((string)($_POST['cliente_apellido'] ?? '')),
                'telefonos'        => $telefonos,
                'telefono_wa'      => $telefonoWa,
                'tipo_venta'       => in_array($tvIn, ['online', 'local'], true) ? $tvIn : null,
                'transportista_id' => $transpId > 0 ? $transpId : '',
                'fecha_carga'      => $fCarga,
                'dest_provincia'   => trim((string)($_POST['dest_provincia'] ?? '')),
                'dest_localidad'   => trim((string)($_POST['dest_localidad'] ?? '')),
                'dest_domicilio'   => trim((string)($_POST['dest_domicilio'] ?? '')),
                'dest_cp'          => trim((string)($_POST['dest_cp'] ?? '')),
                'nro_remito'       => trim((string)($_POST['nro_remito'] ?? '')),
                'hoja_ruta'        => trim((string)($_POST['hoja_ruta'] ?? '')),
                'fecha_remito'     => trim((string)($_POST['fecha_remito'] ?? '')),
                'valor_declarado'  => $valor !== '' ? str_replace(',', '.', $valor) : null,
                'observaciones'    => trim((string)($_POST['observaciones'] ?? '')),
                'marca'            => in_array((string)($_POST['marca'] ?? ''), Orden::MARCAS, true) ? (string)$_POST['marca'] : '',
            ]);
            flash_set('success', 'Orden actualizada.');
        }
    }
    header('Location: ' . $volverA);
    exit;
}

$id    = (int)($_GET['id'] ?? 0);
$orden = $id > 0 ? Orden::find($id) : null;

// URL de los forms (conserva id + vol para que el filtro viaje en el POST).
$selfUrl = url('admin/ordenes-detalle.php') . '?id=' . $id . ($vol !== '' ? '&vol=' . urlencode($vol) : '');

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h($reporteVolverUrl) . '"><i class="bi bi-arrow-left me-1"></i>Reportes</a>';

if ($orden === null) {
    panel_header('Detalle de orden', $user, 'reportes', '', $volver);
    echo '<div class="alert alert-warning">No se encontró la orden.</div>';
    panel_footer();
    exit;
}

$items     = Producto::paraEtiquetasPorOrden($id);
$num       = carga_num((int)($orden['carga_id'] ?? 0), (string)($orden['created_at'] ?? ''));

// Datos de ingreso por documento (hoja de ruta / transportista / fecha de carga).
$transpNombre = '';
if (!empty($orden['transportista_id'])) {
    $t = Usuario::findById((int)$orden['transportista_id']);
    $transpNombre = (string)($t['nombre_completo'] ?? '');
}
$transportistasAll = Usuario::transportistasActivos();
$tv        = (string)($orden['tipo_venta'] ?? '');
$urlEti    = url('admin/ordenes-etiquetas.php') . '?orden=' . $id;
$csrf      = Auth::tokenCSRF();
$historial = Orden::historial($id);
$remitos   = EntregaRemito::porOrden($id);

/** Destino "Localidad · Provincia". */
$loc  = trim((string)($orden['dest_localidad'] ?? ''));
$prov = trim((string)($orden['dest_provincia'] ?? ''));
$destino = trim($loc . ($loc !== '' && $prov !== '' ? ' · ' : '') . $prov) ?: '—';

// Seguimiento público: enlace por Nº de orden para compartir con el cliente
// (solo muestra el estado público, nunca datos internos).
$segUrl  = seguimiento_orden_url((string)$orden['nro_orden']);
$segMsg  = 'Hola! Podés seguir el estado de tu pedido en este enlace: ' . $segUrl;
$waUrl   = 'https://wa.me/?text=' . rawurlencode($segMsg);
$mailUrl = 'mailto:?subject=' . rawurlencode('Seguimiento de tu pedido') . '&body=' . rawurlencode($segMsg);

/** Badge de estado del ítem: ETIQUETADA si tiene etiqueta y sigue INGRESADO. */
function item_estado(array $it): string
{
    $est = (string)($it['estado_actual'] ?? '');
    if (($it['etiquetada_at'] ?? null) !== null && $est === 'INGRESADO') {
        return 'ETIQUETADA';
    }
    return $est;
}

/** ¿Todos los ítems siguen en depósito? (condición para quitar ítems / eliminar). */
$todoIngresado = true;
foreach ($items as $it) {
    if ((string)($it['estado_actual'] ?? '') !== 'INGRESADO') { $todoIngresado = false; break; }
}

$acciones = $volver;
if ($puedeEditar) {
    $acciones .= '<button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalEditar"><i class="bi bi-pencil me-1"></i>Editar</button>'
        . '<button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#modalCorregirEstado"><i class="bi bi-arrow-repeat me-1"></i>Corregir estado</button>'
        . '<a class="btn btn-sm btn-outline-secondary" href="' . h($urlEti) . '"><i class="bi bi-tag me-1"></i>Re-imprimir etiquetas</a>';
    if ($todoIngresado) {
        $acciones .= '<button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalEliminar"><i class="bi bi-trash me-1"></i>Eliminar orden</button>';
    }
}

panel_header('Detalle de orden', $user, 'reportes', '', $acciones);
flash_render();

$campo = static function (string $label, string $valor): void {
    echo '<div><span style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;display:block">'
        . h($label) . '</span>' . h($valor !== '' ? $valor : '—') . '</div>';
};
?>
<div class="card p-3 mb-3">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.85rem">
    <div>
      <div class="mono" style="font-size:1.15rem;font-weight:700;margin-bottom:6px"><?= h((string)$orden['nro_orden']) ?></div>
      <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center">
        <?= estado_badge((string)($orden['estado'] ?? '')) ?>
        <?php if ($tv !== ''): ?><span class="badge b-<?= h(strtoupper($tv)) ?>"><?= h(ucfirst($tv)) ?></span><?php endif; ?>
        <?php $marca = (string)($orden['marca'] ?? ''); ?>
        <?php if ($marca === 'no_entregar'): ?><span class="badge" style="background:rgba(239,68,68,.2);color:#f87171"><i class="bi bi-x-octagon-fill me-1"></i>No entregar</span>
        <?php elseif ($marca === 'prioridad'): ?><span class="badge" style="background:rgba(245,158,11,.2);color:#fbbf24"><i class="bi bi-lightning-charge-fill me-1"></i>Prioridad</span><?php endif; ?>
        <span style="font-size:12px;color:var(--muted)"><?= h($num) ?></span>
      </div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:.65rem;font-size:13px">
    <?php
      $campo('Nº Remito', (string)($orden['nro_remito'] ?? ''));
      $campo('Hoja de ruta', (string)($orden['hoja_ruta'] ?? ''));
      $campo('Transportista', $transpNombre);
      $campo('Fecha de carga', ($orden['fecha_carga'] ?? '') ? date('d/m/Y', strtotime((string)$orden['fecha_carga'])) : '');
      $campo('Cliente', (string)($orden['cliente'] ?? ''));
      $campo('Teléfonos', (string)($orden['telefonos'] ?? ''));
      $campo('WhatsApp', (string)($orden['telefono_wa'] ?? '') !== '' ? (string)$orden['telefono_wa'] : '— (no apto)');
      $campo('Destino', $destino);
      $campo('Domicilio', (string)($orden['dest_domicilio'] ?? ''));
      $campo('Fecha remito', ($orden['fecha_remito'] ?? '') ? date('d/m/Y', strtotime((string)$orden['fecha_remito'])) : '');
      $campo('Ingreso depósito', fmt_fecha((string)($orden['created_at'] ?? ''), 'd/m/Y · H:i'));
      $campo('Valor declarado', $orden['valor_declarado'] !== null ? '$' . number_format((float)$orden['valor_declarado'], 2, ',', '.') : '');
      $campo('m³ total', number_format((float)($orden['m3_total'] ?? 0), 2, ',', '.') . ' m³');
      $campo('Ítems', count($items) . ' unidad(es)');
    ?>
  </div>
  <?php if (trim((string)($orden['observaciones'] ?? '')) !== ''): ?>
    <div style="margin-top:.85rem;border-top:1px solid var(--border);padding-top:.7rem">
      <span style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:3px"><i class="bi bi-chat-left-text me-1"></i>Observaciones</span>
      <div style="font-size:13px;white-space:pre-wrap"><?= h((string)$orden['observaciones']) ?></div>
    </div>
  <?php endif; ?>
</div>

<div class="card p-3 mb-3">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem">
    <div style="font-weight:600;font-size:13px"><i class="bi bi-geo-alt-fill me-1" style="color:var(--blue,#3b82f6)"></i>Seguimiento público</div>
    <span class="text-muted" style="font-size:12px">Enlace para enviarle al cliente</span>
  </div>
  <div class="input-group input-group-sm mb-2">
    <input class="form-control mono" id="segUrl" value="<?= h($segUrl) ?>" readonly>
    <button class="btn btn-outline-secondary" type="button" id="segCopiar" title="Copiar enlace"><i class="bi bi-clipboard"></i></button>
  </div>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a class="btn btn-sm btn-outline-success" href="<?= h($waUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($mailUrl) ?>"><i class="bi bi-envelope me-1"></i>Email</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= h($segUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Ver página</a>
  </div>
  <p class="text-muted mb-0 mt-2" style="font-size:11px">El cliente ve solo el texto público del estado, nunca el código interno. Esos textos se editan en Administración → Seguimiento.</p>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:1rem;align-items:start" class="tz-detalle-grid">
  <div class="card">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;border-bottom:1px solid var(--border)">
      <span style="font-weight:600;font-size:13px">Ítems (<?= count($items) ?>)</span>
      <?php if ($puedeEditar): ?>
        <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#modalAgregarItem"><i class="bi bi-plus-lg me-1"></i>Agregar ítem</button>
      <?php endif; ?>
    </div>
    <div style="overflow-x:auto">
      <table class="table table-hover mb-0">
        <thead><tr><th>Código</th><th>Descripción</th><th>Dimensiones</th><th>m³</th><th>Ítem</th><th>Estado</th><?php if ($puedeEditar): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td class="mono" style="font-size:12px"><?= h((string)$it['codigo']) ?></td>
            <td><?= h((string)($it['descripcion'] ?? '—')) ?></td>
            <td style="font-size:12px;color:var(--muted)"><?= h((string)($it['dimensiones'] ?? '—')) ?></td>
            <td><?= $it['m3'] !== null ? number_format((float)$it['m3'], 3, ',', '.') : '—' ?></td>
            <td style="color:var(--muted)"><?= (int)($it['posicion'] ?? $it['secuencia']) ?> de <?= (int)$it['total_items'] ?></td>
            <td><?= estado_badge(item_estado($it)) ?></td>
            <?php if ($puedeEditar): ?>
            <td style="text-align:right">
              <?php if ((string)($it['estado_actual'] ?? '') === 'INGRESADO'): ?>
              <form method="post" action="<?= h($selfUrl) ?>" style="display:inline" onsubmit="return confirm('¿Quitar este ítem de la orden? Se borra el producto y su etiqueta.')">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="accion" value="quitar_item">
                <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                <button class="btn btn-sm btn-link text-danger p-0" title="Quitar ítem"><i class="bi bi-x-lg"></i></button>
              </form>
              <?php endif; ?>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card p-3">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:.75rem">Etiqueta · vista previa</div>
    <?php if ($items !== []):
        $m = $items[0];
        $ape = (string)($m['cliente_apellido'] ?? '');
        $payload = EtiquetaQr::payload((string)$m['nro_orden'], (int)$m['secuencia'], (int)$m['total_items'], (string)($m['dest_provincia'] ?? ''), (string)($m['dest_localidad'] ?? ''), $ape);
    ?>
    <div class="label-card" style="width:100%">
      <div class="lq" data-qr="<?= h($payload) ?>"></div>
      <div class="lb">
        <div class="ld"><?= h($destino) ?></div>
        <div class="ln"><?= h(trim((string)$orden['cliente']) !== '' ? (string)$orden['cliente'] : $ape) ?></div>
        <div class="li"><?= h((string)($m['descripcion'] ?? 'Ítem')) ?></div>
        <div class="lc"><span><?= h((string)$m['codigo']) ?></span><span class="lqty"><?= (int)($m['posicion'] ?? $m['secuencia']) ?> de <?= (int)$m['total_items'] ?></span></div>
      </div>
    </div>
    <?php if ($puedeEditar): ?><a class="btn btn-outline-secondary btn-sm w-100 mt-2" href="<?= h($urlEti) ?>"><i class="bi bi-printer me-1"></i>Re-imprimir etiquetas</a><?php endif; ?>
    <?php else: ?>
    <div class="text-muted" style="font-size:13px">La orden no tiene ítems.</div>
    <?php endif; ?>
  </div>
</div>
<div class="card p-3 mt-3">
  <div style="font-weight:600;font-size:13px;margin-bottom:.85rem">Historial</div>
  <?php if ($historial === []): ?>
    <div class="text-muted" style="font-size:13px">Sin eventos.</div>
  <?php else: ?>
  <div style="display:flex;flex-direction:column;gap:.8rem">
    <?php foreach ($historial as $ev): ?>
      <div style="display:flex;gap:.6rem;align-items:flex-start">
        <div style="width:9px;height:9px;border-radius:50%;background:var(--blue);margin-top:5px;flex-shrink:0"></div>
        <div>
          <div style="font-size:13px;font-weight:500"><?= h($ev['titulo']) ?></div>
          <div style="font-size:12px;color:var(--muted)"><?= h(fmt_fecha($ev['fecha'], 'd/m/Y H:i')) ?><?= $ev['detalle'] !== '' ? ' · ' . h($ev['detalle']) : '' ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($remitos !== []): ?>
<div class="card p-3 mt-3">
  <div style="font-weight:600;font-size:13px;margin-bottom:.85rem"><i class="bi bi-camera-fill me-1"></i>Remitos firmados (<?= count($remitos) ?>)</div>
  <div class="d-flex flex-wrap gap-2">
    <?php foreach ($remitos as $r): $verUrl = url('api/remito-ver.php') . '?uuid=' . rawurlencode((string)$r['foto_uuid']); ?>
      <a href="<?= h($verUrl) ?>" target="_blank" rel="noopener" title="<?= h((string)$r['archivo']) ?>">
        <img src="<?= h($verUrl) ?>" style="width:110px;height:110px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<style>@media(max-width:768px){.tz-detalle-grid{grid-template-columns:1fr!important}}</style>

<?php if ($puedeEditar): ?>
<!-- Modal: editar datos de la orden -->
<div class="modal fade" id="modalEditar" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content" action="<?= h($selfUrl) ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="accion" value="guardar">
      <div class="modal-header">
        <h5 class="modal-title">Editar orden <span class="mono"><?= h((string)$orden['nro_orden']) ?></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label">Nº remito</label><input class="form-control form-control-sm" name="nro_remito" value="<?= h((string)($orden['nro_remito'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Hoja de ruta</label><input class="form-control form-control-sm" name="hoja_ruta" value="<?= h((string)($orden['hoja_ruta'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Fecha remito</label><input type="date" class="form-control form-control-sm" name="fecha_remito" value="<?= h((string)($orden['fecha_remito'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Tipo de venta</label>
            <select class="form-select form-select-sm" name="tipo_venta">
              <option value="" <?= $tv === '' ? 'selected' : '' ?>>—</option>
              <option value="online" <?= $tv === 'online' ? 'selected' : '' ?>>Online</option>
              <option value="local" <?= $tv === 'local' ? 'selected' : '' ?>>Local</option>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label">Transportista</label>
            <select class="form-select form-select-sm" name="transportista_id">
              <option value="">—</option>
              <?php foreach ($transportistasAll as $t): ?>
                <option value="<?= (int)$t['id'] ?>" <?= (int)($orden['transportista_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['nombre_completo']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label">Fecha de carga</label><input type="date" class="form-control form-control-sm" name="fecha_carga" max="<?= h(date('Y-m-d')) ?>" value="<?= h((string)($orden['fecha_carga'] ?? '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Cliente</label><input class="form-control form-control-sm" name="cliente" value="<?= h((string)($orden['cliente'] ?? '')) ?>"></div>
          <div class="col-md-3"><label class="form-label">Apellido</label><input class="form-control form-control-sm" name="cliente_apellido" value="<?= h((string)($orden['cliente_apellido'] ?? '')) ?>"></div>
          <div class="col-md-3"><label class="form-label">Teléfonos</label><input class="form-control form-control-sm" name="telefonos" value="<?= h((string)($orden['telefonos'] ?? '')) ?>"></div>
          <?php $telWa = (string)($orden['telefono_wa'] ?? ''); ?>
          <div class="col-md-3">
            <label class="form-label">WhatsApp <i class="bi bi-whatsapp text-success"></i></label>
            <input class="form-control form-control-sm <?= $telWa === '' ? 'is-invalid' : '' ?>" name="telefono_wa" value="<?= h($telWa) ?>" placeholder="vacío = auto del literal">
            <?php if ($telWa === ''): ?><div class="form-text text-danger" style="font-size:11px">No apto para WhatsApp: revisá el teléfono. Vacío al guardar = se re-deriva.</div>
            <?php else: ?><div class="form-text" style="font-size:11px">Formato E.164 (549…). Vacío al guardar = se re-deriva del literal.</div><?php endif; ?>
          </div>
          <div class="col-md-4"><label class="form-label">Provincia</label><input class="form-control form-control-sm" name="dest_provincia" value="<?= h((string)($orden['dest_provincia'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Localidad</label><input class="form-control form-control-sm" name="dest_localidad" value="<?= h((string)($orden['dest_localidad'] ?? '')) ?>"></div>
          <div class="col-md-2"><label class="form-label">CP</label><input class="form-control form-control-sm" name="dest_cp" value="<?= h((string)($orden['dest_cp'] ?? '')) ?>"></div>
          <div class="col-md-2"><label class="form-label">Valor decl.</label><input class="form-control form-control-sm" name="valor_declarado" value="<?= $orden['valor_declarado'] !== null ? h(number_format((float)$orden['valor_declarado'], 2, '.', '')) : '' ?>"></div>
          <div class="col-12"><label class="form-label">Domicilio</label><input class="form-control form-control-sm" name="dest_domicilio" value="<?= h((string)($orden['dest_domicilio'] ?? '')) ?>"></div>
          <?php $marcaEd = (string)($orden['marca'] ?? ''); ?>
          <div class="col-md-4"><label class="form-label">Marca</label>
            <select class="form-select form-select-sm" name="marca">
              <option value="">Sin marca</option>
              <option value="no_entregar" <?= $marcaEd === 'no_entregar' ? 'selected' : '' ?>>🚫 No entregar</option>
              <option value="prioridad" <?= $marcaEd === 'prioridad' ? 'selected' : '' ?>>⚡ Prioridad</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label">Observaciones</label><textarea class="form-control form-control-sm" name="observaciones" rows="2" maxlength="1000" placeholder="Detalles del cliente: no entregar tal pedido, priorizar otro, horarios…"><?= h((string)($orden['observaciones'] ?? '')) ?></textarea></div>
        </div>
        <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>Editás los datos de la orden. El destino de las etiquetas ya impresas no cambia hasta reimprimirlas.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-primary" type="submit">Guardar cambios</button>
      </div>
    </form>
  </div>
</div>
<!-- Modal: agregar ítem a la orden -->
<div class="modal fade" id="modalAgregarItem" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content" action="<?= h($selfUrl) ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="accion" value="agregar_item">
      <div class="modal-header">
        <h5 class="modal-title">Agregar ítem a <span class="mono"><?= h((string)$orden['nro_orden']) ?></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12"><label class="form-label">Descripción</label><input class="form-control form-control-sm" name="descripcion" placeholder="Ej. Colchón 2 plazas" autofocus></div>
          <div class="col-6"><label class="form-label">Dimensiones</label><input class="form-control form-control-sm" name="dimensiones" placeholder="Ej. 1,40 × 1,90"></div>
          <div class="col-3"><label class="form-label">Cantidad</label><input type="number" min="1" max="99" class="form-control form-control-sm" name="cantidad" value="1"></div>
          <div class="col-3"><label class="form-label">m³ (total)</label><input class="form-control form-control-sm" name="m3" placeholder="0,000"></div>
        </div>
        <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>Para una orden que vino cortada en la hoja. Los ítems se ingresan al mismo lote de la orden. Reimprimí las etiquetas: cambia el "de N".</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-success" type="submit"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: corregir estado de la orden -->
<div class="modal fade" id="modalCorregirEstado" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content" action="<?= h($selfUrl) ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="accion" value="corregir_estado">
      <div class="modal-header">
        <h5 class="modal-title">Corregir estado de <span class="mono"><?= h((string)$orden['nro_orden']) ?></span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Nuevo estado</label>
          <select class="form-select form-select-sm" name="nuevo_estado">
            <?php foreach (Orden::ESTADOS as $e): ?>
              <option value="<?= h($e) ?>" <?= $e === (string)($orden['estado'] ?? '') ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $e)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Motivo *</label>
          <textarea class="form-control form-control-sm" name="motivo" rows="3" placeholder="Explicá por qué corregís el estado…" required></textarea>
        </div>
        <div class="alert alert-warning py-2 mb-0" style="font-size:12px"><i class="bi bi-exclamation-triangle me-1"></i>Aplica un <strong>ajuste manual</strong> a los <?= count($items) ?> ítem(s) de la orden y queda registrado con tu usuario en el historial de cada uno. Usalo solo para corregir errores.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-warning" type="submit"><i class="bi bi-check-lg me-1"></i>Corregir estado</button>
      </div>
    </form>
  </div>
</div>

<?php if ($todoIngresado): ?>
<!-- Modal: eliminar orden -->
<div class="modal fade" id="modalEliminar" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content" action="<?= h($selfUrl) ?>">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="accion" value="eliminar_orden">
      <div class="modal-header">
        <h5 class="modal-title text-danger">Eliminar orden</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-1">Vas a eliminar la orden <span class="mono fw-bold"><?= h((string)$orden['nro_orden']) ?></span> y sus <?= count($items) ?> ítem(s).</p>
        <p class="text-muted small mb-0">Esto borra los productos, sus etiquetas y el ingreso. No se puede deshacer. Solo es posible porque ningún ítem salió aún del depósito.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button class="btn btn-danger" type="submit"><i class="bi bi-trash me-1"></i>Eliminar definitivamente</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; /* modales solo admin */ ?>

<script src="<?= h(asset('assets/vendor/qrcode-generator/qrcode.min.js')) ?>"></script>
<script src="<?= h(asset('assets/js/etiquetas.js')) ?>"></script>
<script>
(function () {
  const btn = document.getElementById('segCopiar');
  const inp = document.getElementById('segUrl');
  if (!btn || !inp) return;
  btn.addEventListener('click', function () {
    const ok = () => { const i = btn.querySelector('i'); if (i) { i.className = 'bi bi-check-lg'; setTimeout(() => { i.className = 'bi bi-clipboard'; }, 1500); } };
    if (navigator.clipboard) {
      navigator.clipboard.writeText(inp.value).then(ok).catch(() => { inp.select(); document.execCommand('copy'); ok(); });
    } else { inp.select(); document.execCommand('copy'); ok(); }
  });
})();
</script>
<?php
panel_footer();

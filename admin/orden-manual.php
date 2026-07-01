<?php
declare(strict_types=1);

// =============================================================================
// admin/orden-manual.php — carga de UNA orden completa a mano (llega por fuera del
// OCR, p. ej. por mail). Reusa el motor de materialización (ProcesadorCarga): crea
// una carga borrador con esta única orden y la confirma, generando los ítems con
// QR + lote de INGRESO, idéntico a las importadas. admin/logística.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Carga;
use Trazock\Models\CatalogoProductos;
use Trazock\Models\Categoria;
use Trazock\Models\Destino;
use Trazock\Models\Orden;
use Trazock\Models\Usuario;
use Trazock\ProcesadorCarga;

$user = Auth::requierePanel(['admin', 'logistica']);
$csrf = Auth::tokenCSRF();

$categorias     = Categoria::activas();
$transportistas = Usuario::transportistasActivos();

$errores = [];
$old     = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
        header('Location: ' . url('admin/orden-manual.php'));
        exit;
    }
    // Regenerar el catálogo de productos (incorpora los agregados desde el último cache).
    if (($_POST['accion'] ?? '') === 'regenerar_catalogo') {
        $cat = CatalogoProductos::regenerar();
        flash_set('success', 'Catálogo de productos actualizado: ' . (int)$cat['total'] . ' producto(s).');
        header('Location: ' . url('admin/orden-manual.php'));
        exit;
    }
    $old = $_POST;

    $nro      = trim((string)($_POST['nro_orden'] ?? ''));
    $cliente  = trim((string)($_POST['cliente'] ?? ''));
    $catId    = (int)($_POST['categoria_id'] ?? 0);
    $transpId = (int)($_POST['transportista_id'] ?? 0);

    // Ítems: filas paralelas; se descartan las totalmente vacías.
    $iCod  = (array)($_POST['item_codigo'] ?? []);
    $iDim  = (array)($_POST['item_dim'] ?? []);
    $iCant = (array)($_POST['item_cant'] ?? []);
    $iM3   = (array)($_POST['item_m3'] ?? []);
    $items = [];
    foreach ($iCod as $k => $_) {
        $cod  = trim((string)($iCod[$k] ?? ''));
        $dim  = trim((string)($iDim[$k] ?? ''));
        $cant = (int)($iCant[$k] ?? 0);
        $m3s  = str_replace(',', '.', trim((string)($iM3[$k] ?? '')));
        if ($cod === '' && $dim === '' && $cant <= 0 && $m3s === '') {
            continue; // fila vacía
        }
        $items[] = [
            'codigo'      => $cod !== '' ? $cod : null,
            'dimensiones' => $dim !== '' ? $dim : null,
            'cantidad'    => max(1, $cant),
            'm3'          => $m3s !== '' && is_numeric($m3s) ? (float)$m3s : null,
        ];
    }

    // Validaciones.
    if ($nro === '') {
        $errores[] = 'El Nº de orden es obligatorio.';
    } elseif (Orden::existeNroOrden($nro)) {
        $errores[] = 'Ya existe una orden con el Nº «' . $nro . '».';
    }
    if ($cliente === '') {
        $errores[] = 'El cliente es obligatorio.';
    }
    if ($items === []) {
        $errores[] = 'Agregá al menos un ítem (para generar las etiquetas).';
    }
    if ($catId > 0 && !Categoria::existeActiva($catId)) {
        $catId = 0;
    }

    if ($errores === []) {
        $vd = str_replace(',', '.', trim((string)($_POST['valor_declarado'] ?? '')));
        $orden = [
            'nro_orden'        => $nro,
            'nro_remito'       => trim((string)($_POST['nro_remito'] ?? '')) ?: null,
            'hoja_ruta'        => trim((string)($_POST['hoja_ruta'] ?? '')) ?: null,
            'transportista_id' => $transpId > 0 ? $transpId : null,
            'fecha_carga'      => trim((string)($_POST['fecha_carga'] ?? '')) ?: null,
            'fecha_remito'     => trim((string)($_POST['fecha_remito'] ?? '')) ?: null,
            'cliente'          => $cliente,
            'telefonos'        => trim((string)($_POST['telefonos'] ?? '')) ?: null,
            'dest_provincia'   => trim((string)($_POST['dest_provincia'] ?? '')) ?: null,
            'dest_localidad'   => trim((string)($_POST['dest_localidad'] ?? '')) ?: null,
            'dest_domicilio'   => trim((string)($_POST['dest_domicilio'] ?? '')) ?: null,
            'dest_cp'          => trim((string)($_POST['dest_cp'] ?? '')) ?: null,
            'valor_declarado'  => $vd !== '' && is_numeric($vd) ? (float)$vd : null,
            'items'            => $items,
        ];

        try {
            $cargaId = Carga::crear((int)$user['id'], $catId > 0 ? $catId : null);
            Carga::guardarDatos($cargaId, json_encode(['ordenes' => [$orden]], JSON_UNESCAPED_UNICODE));
            $res = ProcesadorCarga::confirmar($cargaId);
            if (($res['creadas'] ?? 0) < 1) {
                throw new RuntimeException('No se creó la orden (¿Nº duplicado?).');
            }
            flash_set('success', 'Orden «' . $nro . '» creada con ' . (int)$res['items'] . ' ítem(s). Imprimí las etiquetas.');
            header('Location: ' . url('admin/ordenes-confirmacion.php') . '?carga=' . $cargaId);
            exit;
        } catch (\Throwable $e) {
            $errores[] = 'No se pudo crear la orden: ' . $e->getMessage();
        }
    }
}

/** Valor previo de un campo (tras un error de validación). */
function om_old(array $old, string $k, string $def = ''): string
{
    return h((string)($old[$k] ?? $def));
}

$catalogo = CatalogoProductos::catalogo();

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-captura.php')) . '"><i class="bi bi-arrow-left me-1"></i>Nueva carga</a>';
$acciones = $volver
    . '<form method="post" class="d-inline">'
    . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
    . '<input type="hidden" name="accion" value="regenerar_catalogo">'
    . '<button class="btn btn-sm btn-outline-secondary" title="Regenerar el catálogo de productos con lo cargado desde el último cache (' . (int)$catalogo['total'] . ' productos)"><i class="bi bi-arrow-clockwise me-1"></i>Actualizar catálogo</button>'
    . '</form>';
panel_header('Nueva orden manual', $user, 'captura',
    'Cargá a mano una orden que llegó por fuera de los documentos (p. ej. por mail) · catálogo: ' . (int)$catalogo['total'] . ' productos', $acciones);
flash_render();

// Filas de ítems a pintar: las enviadas (si hubo error) o una vacía.
$itemRows = [];
if (!empty($old['item_codigo']) && is_array($old['item_codigo'])) {
    foreach ($old['item_codigo'] as $k => $_) {
        $itemRows[] = [
            'codigo' => (string)($old['item_codigo'][$k] ?? ''),
            'dim'    => (string)($old['item_dim'][$k] ?? ''),
            'cant'   => (string)($old['item_cant'][$k] ?? ''),
            'm3'     => (string)($old['item_m3'][$k] ?? ''),
        ];
    }
}
if ($itemRows === []) { $itemRows[] = ['codigo' => '', 'dim' => '', 'cant' => '1', 'm3' => '']; }
?>
<?php if ($errores !== []): ?>
<div class="alert alert-danger py-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>
  <?= implode('<br>', array_map('h', $errores)) ?>
</div>
<?php endif; ?>

<form method="post" class="card p-3 mb-3" style="max-width:900px">
  <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

  <div class="row g-2">
    <div class="col-md-4">
      <label class="form-label">Categoría / línea</label>
      <select class="form-select form-select-sm" name="categoria_id">
        <option value="">— Sin categoría —</option>
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= (string)($old['categoria_id'] ?? '') === (string)$c['id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label">Nº de orden <span class="text-danger">*</span></label>
      <input class="form-control form-control-sm mono" name="nro_orden" value="<?= om_old($old, 'nro_orden') ?>" required>
    </div>
    <div class="col-md-4">
      <label class="form-label">Nº remito</label>
      <input class="form-control form-control-sm mono" name="nro_remito" value="<?= om_old($old, 'nro_remito') ?>">
    </div>

    <div class="col-md-6">
      <label class="form-label">Cliente <span class="text-danger">*</span></label>
      <input class="form-control form-control-sm" name="cliente" value="<?= om_old($old, 'cliente') ?>" required>
    </div>
    <div class="col-md-6">
      <label class="form-label">Teléfono(s)</label>
      <input class="form-control form-control-sm" name="telefonos" value="<?= om_old($old, 'telefonos') ?>" placeholder="Se normaliza para WhatsApp">
    </div>

    <div class="col-md-3">
      <label class="form-label">Provincia</label>
      <input class="form-control form-control-sm" id="dest_provincia" name="dest_provincia" value="<?= om_old($old, 'dest_provincia') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">Localidad</label>
      <input class="form-control form-control-sm" id="dest_localidad" name="dest_localidad" value="<?= om_old($old, 'dest_localidad') ?>">
    </div>
    <div class="col-md-4">
      <label class="form-label">Domicilio</label>
      <input class="form-control form-control-sm" name="dest_domicilio" value="<?= om_old($old, 'dest_domicilio') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">CP</label>
      <input class="form-control form-control-sm" name="dest_cp" value="<?= om_old($old, 'dest_cp') ?>">
    </div>

    <div class="col-md-3">
      <label class="form-label">Transportista</label>
      <select class="form-select form-select-sm" name="transportista_id">
        <option value="">—</option>
        <?php foreach ($transportistas as $t): ?>
          <option value="<?= (int)$t['id'] ?>" <?= (string)($old['transportista_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= h($t['nombre_completo']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label">Hoja de ruta</label>
      <input class="form-control form-control-sm mono" name="hoja_ruta" value="<?= om_old($old, 'hoja_ruta') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">F. carga</label>
      <input type="date" class="form-control form-control-sm" name="fecha_carga" value="<?= om_old($old, 'fecha_carga', date('Y-m-d')) ?>" max="<?= h(date('Y-m-d')) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">F. remito</label>
      <input type="date" class="form-control form-control-sm" name="fecha_remito" value="<?= om_old($old, 'fecha_remito') ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label">Valor declarado</label>
      <input class="form-control form-control-sm" name="valor_declarado" value="<?= om_old($old, 'valor_declarado') ?>" inputmode="decimal">
    </div>
  </div>

  <!-- Ítems -->
  <div class="mt-3">
    <div class="d-flex align-items-center justify-content-between mb-1">
      <label class="form-label mb-0">Ítems <span class="text-danger">*</span> <span class="text-muted" style="font-size:11px">(un QR por unidad; la cantidad genera esa cantidad de etiquetas)</span></label>
      <button type="button" class="btn btn-sm btn-outline-primary" id="omAddItem"><i class="bi bi-plus-lg me-1"></i>Agregar ítem</button>
    </div>
    <div style="overflow-x:auto">
      <table class="table table-sm mb-0" id="omItems">
        <thead class="table-light"><tr>
          <th>Descripción / código</th><th style="width:160px">Dimensiones</th>
          <th style="width:90px" class="text-center">Cantidad</th><th style="width:100px">m³ (línea)</th><th style="width:36px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($itemRows as $it): ?>
          <tr>
            <td><input class="form-control form-control-sm" name="item_codigo[]" value="<?= h($it['codigo']) ?>" placeholder="Ej. Colchón 2 plazas" data-cat-desc list="catalogo-prod"></td>
            <td><input class="form-control form-control-sm" name="item_dim[]" value="<?= h($it['dim']) ?>" placeholder="190x140x…" data-cat-dim></td>
            <td><input class="form-control form-control-sm text-center" name="item_cant[]" value="<?= h($it['cant'] !== '' ? $it['cant'] : '1') ?>" inputmode="numeric"></td>
            <td><input class="form-control form-control-sm" name="item_m3[]" value="<?= h($it['m3']) ?>" inputmode="decimal" data-cat-m3></td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger p-0 om-del" title="Quitar"><i class="bi bi-x-lg"></i></button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="mt-3 d-flex justify-content-end">
    <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i>Crear orden e ir a etiquetas</button>
  </div>
</form>

<script>window.CATALOGO_PROD = <?= json_encode($catalogo['items'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= h(asset('assets/js/catalogo-productos.js')) ?>"></script>
<script src="<?= h(asset('assets/js/revision-destino.js')) ?>"></script>
<script>
// Autocompletado de destino (mismo resolvedor que la revisión OCR).
(function () {
  var DIC = <?= json_encode(Destino::diccionarioDestinos(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  if (typeof crearResolvedor !== 'function') return;
  var resolver = crearResolvedor(DIC);
  var loc = document.getElementById('dest_localidad');
  var prov = document.getElementById('dest_provincia');
  function aplicar() {
    var r = resolver(loc.value, prov.value);
    if (r.locChg) loc.value = r.localidad;
    if (r.provChg) prov.value = r.provincia;
  }
  if (loc) loc.addEventListener('change', aplicar);
  if (prov) prov.addEventListener('change', aplicar);
})();

// Ítems: agregar/quitar filas.
(function () {
  var tbody = document.querySelector('#omItems tbody');
  var add = document.getElementById('omAddItem');
  function fila() {
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><input class="form-control form-control-sm" name="item_codigo[]" placeholder="Ej. Colchón 2 plazas" data-cat-desc list="catalogo-prod"></td>' +
      '<td><input class="form-control form-control-sm" name="item_dim[]" placeholder="190x140x…" data-cat-dim></td>' +
      '<td><input class="form-control form-control-sm text-center" name="item_cant[]" value="1" inputmode="numeric"></td>' +
      '<td><input class="form-control form-control-sm" name="item_m3[]" inputmode="decimal" data-cat-m3></td>' +
      '<td class="text-end"><button type="button" class="btn btn-sm btn-link text-danger p-0 om-del" title="Quitar"><i class="bi bi-x-lg"></i></button></td>';
    tbody.appendChild(tr);
    if (window.catalogoWire) window.catalogoWire(tr);
  }
  if (add) add.addEventListener('click', fila);
  if (tbody) tbody.addEventListener('click', function (e) {
    var b = e.target.closest('.om-del'); if (!b) return;
    if (tbody.children.length > 1) b.closest('tr').remove();
    else { b.closest('tr').querySelectorAll('input').forEach(function (i) { i.value = i.name === 'item_cant[]' ? '1' : ''; }); }
  });
})();
</script>
<?php
panel_footer();

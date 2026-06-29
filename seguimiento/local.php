<?php
declare(strict_types=1);

// =============================================================================
// seguimiento/local.php — listado PÚBLICO de órdenes de un local, por token.
//
// El admin genera un token por prefijo (admin/prefijos.php). Con ?t=<token> el
// local ve SUS órdenes (las que empiezan con su prefijo) y su estado, sin datos
// del cliente. Filtros: fecha de recepción, estado y búsqueda por Nº de orden.
// Expandible: ítems agrupados (cantidad, descripción, dimensiones).
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Models\Orden;
use Trazock\Models\Prefijo;

$token = trim((string)($_GET['t'] ?? ''));
$pref  = $token !== '' ? Prefijo::findByToken($token) : null;

/** Cabecera HTML del tema claro público. */
function loc_head(string $titulo): void
{
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" type="image/png" href="<?= h(asset('favicon.png')) ?>">
    <title><?= h($titulo) ?></title>
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <style>
        body{font-family:'Inter',system-ui,sans-serif;background:#f4f6f9;color:#1f2937;margin:0}
        .loc-wrap{max-width:920px;margin:0 auto;padding:1.5rem 1rem 3rem}
        .loc-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        .mono{font-variant-numeric:tabular-nums;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
        .loc-badge{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:600;padding:2px 8px;border-radius:999px}
        .loc-items{background:#f8fafc;border-radius:10px}
        table thead th{position:sticky;top:0;background:#fff;z-index:2}
    </style>
</head>
<body>
<div class="loc-wrap">
<?php
}

if ($pref === null) {
    loc_head('Acceso no válido');
    echo '<div class="loc-card p-4 text-center"><i class="bi bi-exclamation-triangle text-warning" style="font-size:2rem"></i>'
       . '<h5 class="mt-2">Link no válido o expirado</h5>'
       . '<p class="text-muted mb-0">Pedí al centro de distribución un nuevo enlace de acceso.</p></div>';
    echo '</div></body></html>';
    exit;
}

$nombre = trim((string)($pref['nombre_publico'] ?? '')) !== '' ? (string)$pref['nombre_publico'] : (string)$pref['nombre_interno'];

$filtros = [
    'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
    'estado'      => trim((string)($_GET['estado'] ?? '')),
    'q'           => trim((string)($_GET['q'] ?? '')),
];

$porPagina = 50;
$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$offset    = ($pagina - 1) * $porPagina;

$total   = Orden::contarPorPrefijo((string)$pref['prefijo'], $filtros);
$paginas = (int)max(1, ceil($total / $porPagina));
$ordenes = Orden::listarPorPrefijo((string)$pref['prefijo'], $filtros, $porPagina, $offset);
$itemsMap = Orden::itemsAgrupadosDeOrdenes(array_map(static fn($o) => (int)$o['id'], $ordenes));

// Estado → etiqueta, color, ícono.
$EST = [
    'RECIBIDO'    => ['Recibido',    '#3b82f6', 'box-seam'],
    'EN_REPARTO'  => ['En reparto',  '#f59e0b', 'truck'],
    'ENTREGADO'   => ['Entregado',   '#22c55e', 'house-check'],
    'REINGRESADO' => ['Reingresado', '#64748b', 'arrow-counterclockwise'],
    'DEVUELTO'    => ['Devuelto',    '#ef4444', 'arrow-return-left'],
];
function loc_badge(string $estado): string
{
    global $EST;
    [$lb, $color, $ic] = $EST[$estado] ?? [$estado, '#64748b', 'dot'];
    return '<span class="loc-badge" style="color:' . $color . ';background:' . $color . '1a">'
        . '<i class="bi bi-' . $ic . '"></i>' . h($lb) . '</span>';
}

// Querystring base (token + filtros) para la paginación.
$qs = http_build_query(array_merge(['t' => $token], array_filter($filtros, static fn($v) => $v !== '')));

loc_head('Órdenes de ' . $nombre);
?>
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-shop me-2"></i>Órdenes de <?= h($nombre) ?></h4>
  <span class="text-muted small"><?= number_format($total, 0, ',', '.') ?> orden(es)</span>
</div>

<form method="get" class="loc-card p-3 mb-3">
  <input type="hidden" name="t" value="<?= h($token) ?>">
  <div class="d-flex gap-2 flex-wrap align-items-end">
    <div><label class="form-label small mb-1">Desde</label><input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>"></div>
    <div><label class="form-label small mb-1">Hasta</label><input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>"></div>
    <div>
      <label class="form-label small mb-1">Estado</label>
      <select class="form-select form-select-sm" name="estado">
        <option value="">Todos</option>
        <?php foreach ($EST as $k => $v): ?>
          <option value="<?= h($k) ?>" <?= $filtros['estado'] === $k ? 'selected' : '' ?>><?= h($v[0]) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="flex:1;min-width:160px"><label class="form-label small mb-1">Buscar Nº de orden</label><input type="text" class="form-control form-control-sm" name="q" value="<?= h($filtros['q']) ?>" placeholder="Nº de orden…"></div>
    <div class="d-flex gap-2">
      <button class="btn btn-primary btn-sm px-3" type="submit">Filtrar</button>
      <?php if ($filtros['fecha_desde'] || $filtros['fecha_hasta'] || $filtros['estado'] || $filtros['q']): ?>
        <a class="btn btn-outline-secondary btn-sm" href="?t=<?= h(rawurlencode($token)) ?>">Limpiar</a>
      <?php endif; ?>
    </div>
  </div>
</form>

<div class="loc-card">
  <div style="overflow-x:auto">
    <table class="table table-hover align-middle mb-0">
      <thead><tr>
        <th style="width:30px"></th><th>Nº orden</th><th class="text-center">Ítems</th><th>Estado</th><th>Fecha y hora</th>
      </tr></thead>
      <tbody>
      <?php if ($ordenes === []): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No hay órdenes para los filtros seleccionados.</td></tr>
      <?php else: foreach ($ordenes as $o):
        $oid   = (int)$o['id'];
        $items = $itemsMap[$oid] ?? [];
        $fest  = (string)($o['fecha_estado'] ?? '') !== '' ? (string)$o['fecha_estado'] : (string)$o['created_at'];
      ?>
        <tr>
          <td><?php if ($items !== []): ?><button class="btn btn-sm btn-link p-0 loc-exp" data-t="<?= $oid ?>" aria-label="Ver ítems"><i class="bi bi-chevron-right"></i></button><?php endif; ?></td>
          <td class="mono"><?= h((string)$o['nro_orden']) ?></td>
          <td class="text-center"><?= (int)$o['cant_items'] ?></td>
          <td><?= loc_badge((string)$o['estado']) ?></td>
          <td class="text-muted" style="font-size:13px"><?= h(fmt_fecha($fest, 'd/m/Y H:i')) ?></td>
        </tr>
        <?php if ($items !== []): ?>
        <tr class="d-none loc-detalle" data-row="<?= $oid ?>">
          <td></td>
          <td colspan="4" class="pt-0">
            <div class="loc-items p-2">
              <table class="table table-sm mb-0" style="background:transparent">
                <thead><tr><th style="width:70px">Cant.</th><th>Descripción</th><th>Dimensiones</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                  <tr>
                    <td class="text-center"><?= (int)$it['cantidad'] ?></td>
                    <td><?= h($it['descripcion'] !== '' ? $it['descripcion'] : '—') ?></td>
                    <td class="text-muted"><?= h($it['dimensiones'] !== '' ? $it['dimensiones'] : '—') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </td>
        </tr>
        <?php endif; ?>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($paginas > 1): ?>
  <div class="d-flex align-items-center justify-content-between p-2" style="border-top:1px solid #e5e7eb">
    <span class="text-muted" style="font-size:12px">Mostrando <?= number_format($offset + 1, 0, ',', '.') ?>–<?= number_format(min($offset + $porPagina, $total), 0, ',', '.') ?> de <?= number_format($total, 0, ',', '.') ?></span>
    <nav><ul class="pagination pagination-sm mb-0">
      <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="?<?= h($qs . '&pagina=' . ($pagina - 1)) ?>">‹</a></li>
      <?php for ($p = 1; $p <= $paginas; $p++): ?>
        <li class="page-item <?= $p === $pagina ? 'active' : '' ?>"><a class="page-link" href="?<?= h($qs . '&pagina=' . $p) ?>"><?= $p ?></a></li>
      <?php endfor; ?>
      <li class="page-item <?= $pagina >= $paginas ? 'disabled' : '' ?>"><a class="page-link" href="?<?= h($qs . '&pagina=' . ($pagina + 1)) ?>">›</a></li>
    </ul></nav>
  </div>
  <?php endif; ?>
</div>

<p class="text-center text-muted mt-3" style="font-size:11px">Listado de solo lectura · Corredora de Servicios</p>
</div>
<script>
document.querySelectorAll('.loc-exp').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const row = document.querySelector('.loc-detalle[data-row="' + btn.dataset.t + '"]');
        if (!row) return;
        const oculto = row.classList.toggle('d-none') === false;
        btn.querySelector('i').className = oculto ? 'bi bi-chevron-down' : 'bi bi-chevron-right';
    });
});
</script>
</body>
</html>
<?php
// Fin.

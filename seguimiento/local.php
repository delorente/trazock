<?php
declare(strict_types=1);

// =============================================================================
// seguimiento/local.php — portal PÚBLICO de órdenes de un local, por token.
//
// Con ?t=<token> el local ve SUS órdenes (las del prefijo asignado) y su estado,
// sin datos del cliente. Filtros: fecha de recepción, estado y búsqueda por Nº de
// orden. Expandible: ítems (cantidad, descripción, dimensiones). El admin genera
// y resetea el token en admin/prefijos.php. Diseño importado de Claude Design.
//   ?t=token&export=xlsx → descarga el listado filtrado.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Trazock\Models\Orden;
use Trazock\Models\Prefijo;

$token = trim((string)($_GET['t'] ?? ''));
$pref  = $token !== '' ? Prefijo::findByToken($token) : null;

$filtros = [
    'fecha_desde' => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta' => trim((string)($_GET['fecha_hasta'] ?? '')),
    'estado'      => trim((string)($_GET['estado'] ?? '')),
    'q'           => trim((string)($_GET['q'] ?? '')),
];

// Estado → etiqueta, clase de pill y ícono.
$EST = [
    'RECIBIDO'    => ['Recibido',    's-blue',  'bi-box-seam'],
    'EN_REPARTO'  => ['En reparto',  's-amber', 'bi-truck'],
    'ENTREGADO'   => ['Entregado',   's-green', 'bi-check-circle-fill'],
    'REINGRESADO' => ['Reingresado', 't-slate', 'bi-arrow-counterclockwise'],
    'DEVUELTO'    => ['Devuelto',    't-slate', 'bi-arrow-return-left'],
];

// --- Export Excel del listado filtrado ---------------------------------------
if ($pref !== null && ($_GET['export'] ?? '') === 'xlsx') {
    $rows = Orden::listarPorPrefijo((string)$pref['prefijo'], $filtros, 5000, 0);
    $nombre = trim((string)($pref['nombre_publico'] ?? '')) !== '' ? (string)$pref['nombre_publico'] : (string)$pref['nombre_interno'];

    $ss = new Spreadsheet();
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Órdenes');
    $sh->setCellValue('A1', 'Órdenes de ' . $nombre);
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sh->fromArray(['Nº orden', 'Cliente', 'Tipo', 'Ítems', 'Estado', 'Fecha y hora'], null, 'A3');
    $sh->getStyle('A3:F3')->getFont()->setBold(true);
    $STR = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
    $nclExp = trim((string)($pref['nombre_cliente'] ?? ''));
    $nclExpN = mb_strtolower($nclExp);
    $fila = 4;
    foreach ($rows as $o) {
        $fest = (string)($o['fecha_estado'] ?? '') !== '' ? (string)$o['fecha_estado'] : (string)$o['created_at'];
        $cli  = trim((string)($o['cliente'] ?? ''));
        $tipo = ($nclExp !== '' && mb_strtolower($cli) === $nclExpN) ? 'Pedido del Local' : 'Venta del Local';
        $sh->setCellValueExplicit('A' . $fila, (string)$o['nro_orden'], $STR);
        $sh->setCellValue('B' . $fila, $cli);
        $sh->setCellValue('C' . $fila, $tipo);
        $sh->setCellValue('D' . $fila, (int)($o['cant_items'] ?? 0));
        $sh->setCellValue('E' . $fila, $EST[(string)$o['estado']][0] ?? (string)$o['estado']);
        $sh->setCellValue('F' . $fila, fmt_fecha($fest, 'd/m/Y H:i'));
        $fila++;
    }
    foreach (range('A', 'F') as $c) { $sh->getColumnDimension($c)->setAutoSize(true); }
    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="ordenes_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($ss))->save('php://output');
    exit;
}

/** Cabecera HTML del portal (tema claro, diseño importado). */
function loc_head(string $titulo): void
{
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="<?= h(asset('favicon.png')) ?>">
<title><?= h($titulo) ?></title>
<link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
<style>
:root{
  --tz-paper:#f4f2ee; --tz-card:#fff; --tz-ink:#1d2127; --tz-ink-soft:#5a626d; --tz-muted:#949aa4;
  --tz-line:#e8e4dd; --tz-line-soft:#f0ece5;
  --tz-blue:#3b82f6; --tz-blue-ink:#2563eb; --tz-blue-tint:rgba(59,130,246,.12);
  --tz-amber:#f59e0b; --tz-amber-ink:#b45309; --tz-amber-tint:rgba(245,158,11,.15);
  --tz-green:#22c55e; --tz-green-ink:#15803d; --tz-green-tint:rgba(34,197,94,.15);
  --tz-slate:#64748b; --tz-slate-ink:#475569; --tz-slate-tint:rgba(100,116,139,.12);
  --tz-violet:#8b5cf6; --tz-violet-ink:#6d28d9; --tz-violet-tint:rgba(139,92,246,.13);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{min-height:100%}
body{font-family:'Inter',system-ui,sans-serif;background:var(--tz-paper);color:var(--tz-ink);-webkit-font-smoothing:antialiased}
.page{max-width:1040px;margin:0 auto;padding:32px 24px 64px}
.lo-header{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:24px}
.lo-id{display:flex;align-items:center;gap:14px}
.lo-icon{width:48px;height:48px;border-radius:12px;background:var(--tz-blue);color:#fff;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;box-shadow:0 4px 14px rgba(59,130,246,.3)}
.lo-id h1{font-size:24px;font-weight:800;letter-spacing:-.02em;line-height:1.2}
.lo-sub{font-size:13px;color:var(--tz-ink-soft);margin-top:2px}
.lo-sub strong{color:var(--tz-ink);font-weight:700}
.lo-actions{display:flex;gap:8px;flex-wrap:wrap}
.lo-btn{display:inline-flex;align-items:center;gap:7px;font-family:inherit;font-size:13px;font-weight:600;padding:9px 15px;border-radius:10px;border:1px solid var(--tz-line);background:#fff;color:var(--tz-ink-soft);cursor:pointer;text-decoration:none;transition:border-color .15s,color .15s,background .15s,box-shadow .15s}
.lo-btn:hover{border-color:#d4cfc7;color:var(--tz-ink);background:var(--tz-line-soft)}
.lo-btn.primary{background:var(--tz-blue);border-color:var(--tz-blue);color:#fff;box-shadow:0 2px 8px rgba(59,130,246,.25)}
.lo-btn.primary:hover{background:var(--tz-blue-ink);border-color:var(--tz-blue-ink);color:#fff}
.lo-btn i{font-size:14px}
.lo-kpis{margin-bottom:22px}
.lo-kpis-caption{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--tz-muted);margin-bottom:10px}
.kpi-row{display:grid;gap:12px}
.kpi-row-main{grid-template-columns:1fr 1fr;margin-bottom:12px}
.kpi-row-status{grid-template-columns:repeat(3,1fr)}
.kpi-card{background:var(--tz-card);border:1px solid var(--tz-line);border-radius:16px;box-shadow:0 1px 2px rgba(20,24,40,.03),0 10px 26px -16px rgba(20,30,60,.16);padding:18px 20px}
.kpi-main{display:flex;align-items:center;gap:14px}
.kpi-main .kpi-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;background:var(--ct);color:var(--ci)}
.kpi-main.kpi-slate{--ct:var(--tz-slate-tint);--ci:var(--tz-slate-ink)}
.kpi-main.kpi-violet{--ct:var(--tz-violet-tint);--ci:var(--tz-violet-ink)}
.kpi-value{font-size:30px;font-weight:800;letter-spacing:-.02em;line-height:1.05}
.kpi-label{font-size:13.5px;font-weight:600;color:var(--tz-ink);margin-top:2px}
.kpi-hint{font-size:11.5px;color:var(--tz-muted);margin-top:2px}
.kpi-status{text-align:center;border-top:3px solid var(--cs)}
.kpi-status .kpi-value{font-size:26px;color:var(--cs-ink)}
.kpi-status .kpi-label{font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--tz-ink-soft);font-weight:600;margin-top:4px}
.kpi-status.st-blue{--cs:var(--tz-blue);--cs-ink:var(--tz-blue-ink)}
.kpi-status.st-amber{--cs:var(--tz-amber);--cs-ink:var(--tz-amber-ink)}
.kpi-status.st-green{--cs:var(--tz-green);--cs-ink:var(--tz-green-ink)}
.lo-filters{background:var(--tz-card);border:1px solid var(--tz-line);border-radius:16px;padding:18px 20px;margin-bottom:18px;box-shadow:0 1px 2px rgba(20,24,40,.03),0 10px 26px -16px rgba(20,30,60,.16)}
.filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr)) auto;gap:14px;align-items:end}
.filter-grid label{display:block;font-size:12px;font-weight:600;color:var(--tz-ink-soft);margin-bottom:6px}
.filter-grid input[type="date"],.filter-grid input[type="text"],.filter-grid select{width:100%;font-family:inherit;font-size:14px;color:var(--tz-ink);border:1.5px solid var(--tz-line);border-radius:10px;padding:9px 12px;background:#fff;outline:none;transition:border-color .15s,box-shadow .15s}
.filter-grid input::placeholder{color:var(--tz-muted)}
.filter-grid input:focus,.filter-grid select:focus{border-color:var(--tz-blue);box-shadow:0 0 0 3px var(--tz-blue-tint)}
.filter-btn{font-family:inherit;font-size:14px;font-weight:700;color:#fff;background:var(--tz-blue);border:none;border-radius:10px;padding:9px 22px;cursor:pointer;white-space:nowrap;transition:background .15s}
.filter-btn:hover{background:var(--tz-blue-ink)}
.filter-clear{align-self:end;font-size:13px;color:var(--tz-ink-soft);text-decoration:none;padding:9px 6px}
@media (max-width:760px){.filter-grid{grid-template-columns:1fr 1fr}.filter-btn{grid-column:1/-1}.kpi-row-main{grid-template-columns:1fr}.kpi-row-status{grid-template-columns:1fr}}
.lo-table{background:var(--tz-card);border:1px solid var(--tz-line);border-radius:16px;overflow:hidden;box-shadow:0 1px 2px rgba(20,24,40,.03),0 10px 26px -16px rgba(20,30,60,.16)}
table{width:100%;border-collapse:collapse;font-size:14px}
thead th{text-align:left;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--tz-muted);padding:13px 16px;border-bottom:1px solid var(--tz-line);background:var(--tz-line-soft)}
tbody td{padding:13px 16px;border-bottom:1px solid var(--tz-line-soft);vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr.lo-row{cursor:pointer;transition:background .12s}
tbody tr.lo-row:hover{background:var(--tz-line-soft)}
.lo-ordno{font-family:'Courier New',monospace;font-weight:700;letter-spacing:.01em}
.lo-chev{display:inline-flex;width:20px;height:20px;align-items:center;justify-content:center;color:var(--tz-blue-ink);transition:transform .15s}
tr.lo-row.open .lo-chev{transform:rotate(90deg)}
.lo-pill{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:20px}
.lo-pill.t-slate{background:var(--tz-slate-tint);color:var(--tz-slate-ink)}
.lo-pill.t-violet{background:var(--tz-violet-tint);color:var(--tz-violet-ink)}
.lo-pill.s-blue{background:var(--tz-blue-tint);color:var(--tz-blue-ink)}
.lo-pill.s-amber{background:var(--tz-amber-tint);color:var(--tz-amber-ink)}
.lo-pill.s-green{background:var(--tz-green-tint);color:var(--tz-green-ink)}
.lo-fecha{color:var(--tz-ink-soft);white-space:nowrap}
.lo-detail{display:none}
.lo-detail.open{display:table-row}
.lo-detail td{padding:0;border-bottom:1px solid var(--tz-line-soft);background:rgba(0,0,0,.015)}
.lo-detail-inner{padding:6px 16px 14px 52px}
.lo-item-row{display:flex;align-items:center;gap:10px;font-size:12.5px;color:var(--tz-ink-soft);padding:5px 0}
.lo-item-row .cod{font-family:'Courier New',monospace;color:var(--tz-ink);font-weight:600;width:48px;flex-shrink:0}
.lo-item-row .dim{color:var(--tz-muted)}
.lo-empty{padding:40px 16px;text-align:center;color:var(--tz-muted)}
.lo-tfoot{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;font-size:12.5px;color:var(--tz-muted);border-top:1px solid var(--tz-line);flex-wrap:wrap;gap:8px}
.lo-pg{display:flex;gap:4px}
.lo-pg a{min-width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;padding:0 6px;border-radius:7px;border:1px solid var(--tz-line);background:#fff;color:var(--tz-ink-soft);font-size:12px;text-decoration:none}
.lo-pg a.active{background:var(--tz-blue);border-color:var(--tz-blue);color:#fff;font-weight:700}
.lo-pg a.disabled{opacity:.4;pointer-events:none}
.lo-foot{text-align:center;font-size:11.5px;color:var(--tz-muted);margin-top:28px;display:flex;align-items:center;justify-content:center;gap:6px}
.lo-foot .lo-logo-mini{width:16px;height:16px;background:var(--tz-blue);border-radius:4px;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:9px}
@media print{body{background:#fff}.lo-actions,.lo-filters,.lo-tfoot,.lo-foot{display:none}.page{padding:0;max-width:none}.kpi-card,.lo-table{box-shadow:none}.lo-detail{display:table-row!important}.lo-chev{display:none}}
</style>
</head>
<body>
<div class="page">
<?php
}

// --- Token inválido -----------------------------------------------------------
if ($pref === null) {
    loc_head('Acceso no válido');
    echo '<div class="lo-table" style="padding:40px;text-align:center">'
       . '<div style="font-size:40px;color:var(--tz-amber)"><i class="bi bi-exclamation-triangle"></i></div>'
       . '<h1 style="font-size:20px;margin-top:10px">Link no válido o expirado</h1>'
       . '<p style="color:var(--tz-ink-soft);margin-top:6px">Pedí al centro de distribución un nuevo enlace de acceso.</p></div>';
    echo '</div></body></html>';
    exit;
}

$nombre = trim((string)($pref['nombre_publico'] ?? '')) !== '' ? (string)$pref['nombre_publico'] : (string)$pref['nombre_interno'];
$nombreCliente = trim((string)($pref['nombre_cliente'] ?? ''));
$nclNorm = function_exists('mb_strtolower') ? mb_strtolower($nombreCliente) : strtolower($nombreCliente);

$porPagina = 12;
$pagina    = max(1, (int)($_GET['pagina'] ?? 1));
$offset    = ($pagina - 1) * $porPagina;

$total   = Orden::contarPorPrefijo((string)$pref['prefijo'], $filtros);
$kpis    = Orden::kpisPorPrefijo((string)$pref['prefijo'], $filtros, $nombreCliente);
$paginas = (int)max(1, ceil($total / $porPagina));
$ordenes = Orden::listarPorPrefijo((string)$pref['prefijo'], $filtros, $porPagina, $offset);
$itemsMap = Orden::itemsAgrupadosDeOrdenes(array_map(static fn($o) => (int)$o['id'], $ordenes));

/** Pill de estado. */
function loc_pill(string $estado): string
{
    global $EST;
    [$lb, $cls, $ic] = $EST[$estado] ?? [$estado, 't-slate', 'bi-dot'];
    return '<span class="lo-pill ' . $cls . '"><i class="bi ' . $ic . '"></i>' . h($lb) . '</span>';
}

// Querystring base (token + filtros) para paginación y export.
$qs       = http_build_query(array_merge(['t' => $token], array_filter($filtros, static fn($v) => $v !== '')));
$exportUrl = '?' . $qs . '&export=xlsx';
$hayFiltro = $filtros['fecha_desde'] || $filtros['fecha_hasta'] || $filtros['estado'] || $filtros['q'];

loc_head('Órdenes de ' . $nombre);
?>

  <header class="lo-header">
    <div class="lo-id">
      <div class="lo-icon"><i class="bi bi-shop"></i></div>
      <div>
        <h1><?= h($nombre) ?></h1>
        <div class="lo-sub"><strong><?= number_format($total, 0, ',', '.') ?></strong> órden(es) en total</div>
      </div>
    </div>
    <div class="lo-actions">
      <a class="lo-btn" href="<?= h($exportUrl) ?>"><i class="bi bi-file-earmark-excel"></i>Exportar Excel</a>
      <button type="button" class="lo-btn primary" onclick="window.print()"><i class="bi bi-printer"></i>Imprimir / PDF</button>
    </div>
  </header>

  <section class="lo-kpis">
    <div class="lo-kpis-caption"><i class="bi bi-funnel"></i>Resumen según el filtro aplicado</div>
    <div class="kpi-row kpi-row-main">
      <div class="kpi-card kpi-main kpi-slate">
        <div class="kpi-icon"><i class="bi bi-box-seam"></i></div>
        <div>
          <div class="kpi-value"><?= number_format($kpis['pedidos'], 0, ',', '.') ?></div>
          <div class="kpi-label">Pedidos del Local</div>
          <div class="kpi-hint">Stock para el local</div>
        </div>
      </div>
      <div class="kpi-card kpi-main kpi-violet">
        <div class="kpi-icon"><i class="bi bi-bag-check"></i></div>
        <div>
          <div class="kpi-value"><?= number_format($kpis['ventas'], 0, ',', '.') ?></div>
          <div class="kpi-label">Ventas del Local</div>
          <div class="kpi-hint">Vendidas a clientes</div>
        </div>
      </div>
    </div>
    <div class="kpi-row kpi-row-status">
      <div class="kpi-card kpi-status st-blue"><div class="kpi-value"><?= (int)$kpis['estados']['RECIBIDO'] ?></div><div class="kpi-label">Recibidas</div></div>
      <div class="kpi-card kpi-status st-amber"><div class="kpi-value"><?= (int)$kpis['estados']['EN_REPARTO'] ?></div><div class="kpi-label">En reparto</div></div>
      <div class="kpi-card kpi-status st-green"><div class="kpi-value"><?= (int)$kpis['estados']['ENTREGADO'] ?></div><div class="kpi-label">Entregadas</div></div>
    </div>
  </section>

  <form class="lo-filters" method="get">
    <input type="hidden" name="t" value="<?= h($token) ?>">
    <div class="filter-grid">
      <div><label>Desde</label><input type="date" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>"></div>
      <div><label>Hasta</label><input type="date" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>"></div>
      <div>
        <label>Estado</label>
        <select name="estado">
          <option value="">Todos</option>
          <?php foreach ($EST as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $filtros['estado'] === $k ? 'selected' : '' ?>><?= h($v[0]) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Buscar Nº de orden</label><input type="text" name="q" value="<?= h($filtros['q']) ?>" placeholder="Nº de orden…"></div>
      <button class="filter-btn" type="submit">Filtrar</button>
    </div>
    <?php if ($hayFiltro): ?><a class="filter-clear" href="?t=<?= h(rawurlencode($token)) ?>">Limpiar filtros</a><?php endif; ?>
  </form>

  <section class="lo-table">
    <table>
      <thead>
        <tr>
          <th style="width:30px"></th>
          <th>Nº orden</th>
          <th>Cliente</th>
          <th>Ítems</th>
          <th>Estado</th>
          <th>Fecha y hora</th>
        </tr>
      </thead>
      <tbody>
      <?php if ($ordenes === []): ?>
        <tr><td colspan="6"><div class="lo-empty">No hay órdenes para los filtros seleccionados.</div></td></tr>
      <?php else: foreach ($ordenes as $i => $o):
        $oid   = (int)$o['id'];
        $items = $itemsMap[$oid] ?? [];
        $fest  = (string)($o['fecha_estado'] ?? '') !== '' ? (string)$o['fecha_estado'] : (string)$o['created_at'];
        $did   = 'd' . $i;
        $tieneItems = $items !== [];
        $cli   = trim((string)($o['cliente'] ?? ''));
        $esStock = $nombreCliente !== '' && (function_exists('mb_strtolower') ? mb_strtolower($cli) : strtolower($cli)) === $nclNorm;
      ?>
        <tr class="lo-row<?= $tieneItems ? '' : ' lo-noexp' ?>"<?= $tieneItems ? ' data-target="' . $did . '" onclick="loToggle(\'' . $did . '\', this)"' : '' ?>>
          <td><?php if ($tieneItems): ?><span class="lo-chev"><i class="bi bi-chevron-right"></i></span><?php endif; ?></td>
          <td class="lo-ordno"><?= h((string)$o['nro_orden']) ?></td>
          <td>
            <?php if ($cli !== ''): ?>
              <span class="lo-pill <?= $esStock ? 't-slate' : 't-violet' ?>"><i class="bi <?= $esStock ? 'bi-box-seam' : 'bi-bag-check' ?>"></i><?= h($cli) ?></span>
            <?php else: ?><span style="color:var(--tz-muted)">—</span><?php endif; ?>
          </td>
          <td><?= (int)$o['cant_items'] ?></td>
          <td><?= loc_pill((string)$o['estado']) ?></td>
          <td class="lo-fecha"><?= h(fmt_fecha($fest, 'd/m/Y H:i')) ?></td>
        </tr>
        <?php if ($tieneItems): ?>
        <tr class="lo-detail" id="<?= $did ?>"><td colspan="6"><div class="lo-detail-inner">
          <?php foreach ($items as $it): ?>
            <div class="lo-item-row">
              <span class="cod">x<?= (int)$it['cantidad'] ?></span>
              <span><?= h($it['descripcion'] !== '' ? $it['descripcion'] : '—') ?></span>
              <?php if ($it['dimensiones'] !== ''): ?><span class="dim">· <?= h($it['dimensiones']) ?></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div></td></tr>
        <?php endif; ?>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
    <?php if ($total > 0): ?>
    <div class="lo-tfoot">
      <span>Mostrando <?= number_format($offset + 1, 0, ',', '.') ?>–<?= number_format(min($offset + $porPagina, $total), 0, ',', '.') ?> de <?= number_format($total, 0, ',', '.') ?></span>
      <?php if ($paginas > 1): ?>
      <div class="lo-pg">
        <a class="<?= $pagina <= 1 ? 'disabled' : '' ?>" href="?<?= h($qs . '&pagina=' . ($pagina - 1)) ?>">‹</a>
        <?php for ($p = 1; $p <= $paginas; $p++): ?>
          <a class="<?= $p === $pagina ? 'active' : '' ?>" href="?<?= h($qs . '&pagina=' . $p) ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a class="<?= $pagina >= $paginas ? 'disabled' : '' ?>" href="?<?= h($qs . '&pagina=' . ($pagina + 1)) ?>">›</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </section>

  <div class="lo-foot"><div class="lo-logo-mini"><i class="bi bi-upc-scan"></i></div>Seguimiento provisto por Trazock</div>

</div>
<script>
function loToggle(id, rowEl){
  var d = document.getElementById(id);
  if (!d) return;
  var isOpen = d.classList.contains('open');
  document.querySelectorAll('.lo-detail.open').forEach(function(el){ el.classList.remove('open'); });
  document.querySelectorAll('tr.lo-row.open').forEach(function(el){ el.classList.remove('open'); });
  if (!isOpen){ d.classList.add('open'); rowEl.classList.add('open'); }
}
</script>
</body>
</html>

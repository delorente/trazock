<?php
declare(strict_types=1);

// =============================================================================
// admin/exportar.php — exporta a Excel el listado de productos filtrado (admin + gestor).
// Modos: ?preview=1 → JSON {count}; ?download=1 → descarga xlsx; sino → página.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Trazock\Auth;
use Trazock\Models\Categoria;
use Trazock\Models\Producto;
use Trazock\Models\Stats;

$user = Auth::requierePanel(['admin', 'gestor', 'logistica']);

$filtros = [
    'codigo'          => trim((string)($_GET['codigo'] ?? '')),
    'categoria_id'    => (string)($_GET['categoria_id'] ?? ''),
    'estado'          => (string)($_GET['estado'] ?? ''),
    'tiene_conflicto' => (string)($_GET['tiene_conflicto'] ?? ''),
    'fecha_desde'     => (string)($_GET['fecha_desde'] ?? ''),
    'fecha_hasta'     => (string)($_GET['fecha_hasta'] ?? ''),
];
$incluirHistorial = ($_GET['historial'] ?? '1') === '1';

// --- Modo preview: JSON con el conteo ----------------------------------------
if (isset($_GET['preview'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['count' => Producto::contar($filtros)], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- Modo descarga: stream del xlsx ------------------------------------------
if (isset($_GET['download'])) {
    $rows = Producto::buscar($filtros, 5000, 0);

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Productos');

    $encabezados = ['Código', 'Categoría', 'Estado actual', 'Conflicto'];
    if ($incluirHistorial) { $encabezados[] = 'Última transición'; }
    $sheet->fromArray($encabezados, null, 'A1');
    $ultimaCol = $incluirHistorial ? 'E' : 'D';
    $sheet->getStyle('A1:' . $ultimaCol . '1')->getFont()->setBold(true);

    $fila = 2;
    foreach ($rows as $p) {
        $sheet->setCellValue('A' . $fila, $p['codigo']);
        $sheet->setCellValue('B' . $fila, $p['categoria_nombre'] ?? '(sin categoría)');
        $sheet->setCellValue('C' . $fila, $p['estado_actual']);
        $sheet->setCellValue('D' . $fila, (int)$p['tiene_conflicto'] === 1 ? 'Sí' : 'No');
        if ($incluirHistorial) { $sheet->setCellValue('E' . $fila, (string)$p['updated_at']); }
        $fila++;
    }
    foreach (range('A', $ultimaCol) as $col) { $sheet->getColumnDimension($col)->setAutoSize(true); }

    if (ob_get_length()) { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="productos_' . date('Ymd_His') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($spreadsheet))->save('php://output');
    exit;
}

// --- Modo página -------------------------------------------------------------
require __DIR__ . '/_layout.php';

$count      = Producto::contar($filtros);
$categorias = Categoria::activas();

panel_header('Exportar datos', $user, 'exportar', 'Genera un Excel con los filtros seleccionados');
?>
<div style="display:grid;grid-template-columns:minmax(280px,340px) 1fr;gap:1rem;align-items:start" class="tz-export-grid">
    <div class="card p-3">
        <div style="font-weight:600;font-size:13px;margin-bottom:1rem">Filtros</div>
        <form id="expForm" method="get" action="<?= h(url('admin/exportar.php')) ?>">
            <input type="hidden" name="download" value="1">
            <div class="mb-3"><label class="form-label">Fecha desde</label><input type="date" class="form-control form-control-sm" name="fecha_desde" value="<?= h($filtros['fecha_desde']) ?>"></div>
            <div class="mb-3"><label class="form-label">Fecha hasta</label><input type="date" class="form-control form-control-sm" name="fecha_hasta" value="<?= h($filtros['fecha_hasta']) ?>"></div>
            <div class="mb-3"><label class="form-label">Categoría</label>
                <select class="form-select form-select-sm" name="categoria_id">
                    <option value="">Todas</option>
                    <?php foreach ($categorias as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === $filtros['categoria_id'] ? 'selected' : '' ?>><?= h($c['nombre']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3"><label class="form-label">Estado</label>
                <select class="form-select form-select-sm" name="estado">
                    <option value="">Todos</option>
                    <?php foreach (Stats::ESTADOS as $e): ?><option value="<?= h($e) ?>" <?= $e === $filtros['estado'] ? 'selected' : '' ?>><?= h($e) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-check mb-4"><input class="form-check-input" type="checkbox" id="ih" name="historial" value="1" <?= $incluirHistorial ? 'checked' : '' ?>><label class="form-check-label" for="ih" style="font-size:13px;color:var(--muted)">Incluir última transición</label></div>
            <button class="btn btn-success w-100 fw-bold" type="submit"><i class="bi bi-file-earmark-excel me-2"></i>Generar Excel</button>
        </form>
    </div>
    <div class="card p-4 d-flex flex-column align-items-center justify-content-center text-center" style="min-height:260px">
        <i class="bi bi-file-earmark-spreadsheet" style="font-size:2.8rem;color:var(--green);opacity:.75"></i>
        <div id="expPreview" style="font-size:1.1rem;font-weight:600;margin-top:.75rem">Se exportarán <span style="color:var(--green)" id="expCount"><?= number_format($count, 0, ',', '.') ?></span> productos</div>
        <div class="text-muted" style="font-size:12px;margin-top:.35rem">Columnas: Código, Categoría, Estado, Conflicto<span id="expHistCol"><?= $incluirHistorial ? ', Última transición' : '' ?></span></div>
    </div>
</div>

<style>@media(max-width:768px){.tz-export-grid{grid-template-columns:1fr!important}}</style>
<script>
const EXP_URL = <?= json_encode(url('admin/exportar.php')) ?>;
const form = document.getElementById('expForm');
function actualizarPreview() {
    const params = new URLSearchParams(new FormData(form));
    params.delete('download'); params.set('preview', '1');
    fetch(EXP_URL + '?' + params.toString(), { credentials: 'same-origin' })
        .then(r => r.json()).then(d => {
            document.getElementById('expCount').textContent = (d.count || 0).toLocaleString('es-AR');
            document.getElementById('expHistCol').textContent = document.getElementById('ih').checked ? ', Última transición' : '';
        }).catch(() => {});
}
form.querySelectorAll('input,select').forEach(el => el.addEventListener('input', actualizarPreview));
</script>
<?php
panel_footer();

<?php
declare(strict_types=1);

// =============================================================================
// admin/lote-detalle.php — encabezado del lote + tabla de items con resultado.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Lote;

$user = Auth::requierePanel();

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

/** Celda de campo con label en mayúsculas (estilo prototipo). */
function tz_campo(string $label, ?string $valor, bool $mono = false): void
{
    echo '<div><span style="font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;display:block">'
        . h($label) . '</span><span' . ($mono ? ' class="mono"' : '') . '>' . h(($valor ?? '') !== '' ? $valor : '—') . '</span></div>';
}

panel_header(lote_num((int)$lote['id'], $lote['created_at']), $user, 'lotes', tipo_lote_label($lote['tipo']), $volver);
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
<?php
panel_footer();

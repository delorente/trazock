<?php
declare(strict_types=1);

// =============================================================================
// admin/factura-imprimir.php — Pre-factura imprimible (BORRADOR, sin valor
// fiscal). Una factura por (marca/proveedor × tipo de venta) con los mismos
// filtros del reporte. HTML + CSS print (PDF desde el navegador). admin/gestor.
//
// Mientras no esté la emisión electrónica AFIP (CAE), es un documento interno.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;
use Trazock\Facturacion;
use Trazock\Models\AfipEmisor;

$user = Auth::requierePanel(['admin', 'gestor']);

$filtros = [
    'q'            => trim((string)($_GET['q'] ?? '')),
    'categoria'    => trim((string)($_GET['categoria'] ?? '')),
    'zona'         => trim((string)($_GET['zona'] ?? '')),
    'carga'        => filtro_multi_valores('carga'),
    'provincia'    => filtro_multi_valores('provincia'),
    'hoja_ruta'    => filtro_multi_valores('hoja_ruta'),
    'transportista'=> trim((string)($_GET['transportista'] ?? '')),
    'estado'       => trim((string)($_GET['estado'] ?? '')),
    'tipo_venta'   => trim((string)($_GET['tipo_venta'] ?? '')),
    'fecha_desde'  => trim((string)($_GET['fecha_desde'] ?? '')),
    'fecha_hasta'  => trim((string)($_GET['fecha_hasta'] ?? '')),
];

$facturas = Facturacion::calcular($filtros);
$emisor   = AfipEmisor::get();

/** Pesos con formato es-AR. */
function fac_money(float $n): string { return '$ ' . number_format($n, 2, ',', '.'); }
/** m³ con 2 decimales. */
function fac_m3(float $n): string { return number_format($n, 2, ',', '.'); }
/** Fechas "a,b" → "dd/mm/aaaa, dd/mm/aaaa". */
function fac_fechas(string $csv): string
{
    $xs = array_values(array_filter(explode(',', $csv), static fn($x) => trim($x) !== ''));
    return implode(', ', array_map(static fn(string $x): string => date('d/m/Y', strtotime($x)), $xs)) ?: '—';
}
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="<?= h(asset('favicon.png')) ?>">
    <title>Pre-factura</title>
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <style>
        *{box-sizing:border-box}
        body{font-family:'Inter',system-ui,sans-serif;color:#111;background:#e9edf2;margin:0;padding:18px}
        .bar{max-width:900px;margin:0 auto 12px;display:flex;gap:.5rem;justify-content:flex-end}
        .btn{border:1px solid #cfd6df;background:#fff;border-radius:8px;padding:.5rem .9rem;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;color:#1f2937}
        .btn-primary{background:#0d6efd;border-color:#0d6efd;color:#fff}
        .sheet{max-width:900px;margin:0 auto 18px;background:#fff;border:1px solid #d4d9e0;padding:22px 24px;position:relative}
        .wm{position:absolute;top:14px;right:18px;border:2px solid #c0392b;color:#c0392b;font-weight:800;font-size:.72rem;letter-spacing:.06em;padding:3px 8px;border-radius:6px;transform:rotate(3deg)}
        .head{display:flex;gap:14px;border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:12px}
        .head img{height:46px;width:auto;border-radius:6px}
        .head .t{flex:1}
        .head .t h1{font-size:1.1rem;margin:0}
        .head .t .sub{font-size:.78rem;color:#555;margin-top:2px}
        .parties{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}
        .parties .b{border:1px solid #cfd6df;border-radius:6px;padding:8px 10px}
        .parties .b .l{font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;color:#777;margin-bottom:3px}
        .parties .b .v{font-size:.82rem;line-height:1.45}
        .parties .b .v strong{font-size:.92rem}
        table{width:100%;border-collapse:collapse;font-size:.8rem}
        th,td{border:1px solid #b9c0c9;padding:4px 7px;text-align:left}
        thead th{background:#eef1f5;font-size:.7rem;text-transform:uppercase}
        td.r,th.r{text-align:right;white-space:nowrap}
        tfoot td{font-weight:700;background:#f6f8fa}
        .foot{margin-top:12px;font-size:.72rem;color:#333;border:1px solid #e0e4ea;border-radius:6px;padding:8px 10px;background:#fbfcfd}
        .foot b{color:#111}
        .det{margin-top:14px}
        .det h2{font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;color:#555;margin:0 0 6px}
        .empty{max-width:900px;margin:0 auto;background:#fff;border:1px solid #d4d9e0;padding:22px;text-align:center;color:#777}
        @media print{
            body{background:#fff;padding:0}
            .no-print{display:none!important}
            .sheet{border:none;max-width:none;padding:0;margin:0}
            .sheet + .sheet{page-break-before:always}
            @page{size:A4;margin:12mm}
        }
    </style>
</head>
<body>
<div class="bar no-print">
    <a class="btn" href="<?= h(url('admin/ordenes-reportes.php') . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">← Volver al reporte</a>
    <?php if ($facturas !== []): ?><button class="btn btn-primary" onclick="window.print()">🖨 Imprimir / PDF</button><?php endif; ?>
</div>

<?php if ($facturas === []): ?>
    <div class="empty">No hay órdenes para los filtros seleccionados.</div>
<?php else: foreach ($facturas as $f): $rec = Facturacion::receptorNombre($f); ?>
    <div class="sheet">
        <div class="wm">BORRADOR · SIN VALOR FISCAL</div>
        <div class="head">
            <img src="<?= h(asset('assets/img/logo.jpg')) ?>" alt="Corredora de Servicios">
            <div class="t">
                <h1>Pre-factura <?= h($f['tipo_comprobante']) ?> — Venta <?= h($f['tipo_label']) ?></h1>
                <div class="sub">Servicio de distribución · Emisión <?= h(date('d/m/Y')) ?></div>
            </div>
        </div>

        <div class="parties">
            <div class="b">
                <div class="l">Emisor</div>
                <div class="v">
                    <strong><?= h((string)($emisor['razon_social'] ?? '') ?: 'Corredora de Servicios S.A.') ?></strong><br>
                    CUIT: <?= h((string)($emisor['cuit'] ?? '') ?: '—') ?> · <?= h((string)($emisor['condicion_iva'] ?? '') ?: '—') ?><br>
                    <?php if (($emisor['domicilio'] ?? '') !== ''): ?><?= h((string)$emisor['domicilio']) ?><br><?php endif; ?>
                    <?php if (($emisor['iibb'] ?? '') !== ''): ?>IIBB: <?= h((string)$emisor['iibb']) ?> · <?php endif; ?>
                    <?php if (($emisor['inicio_actividades'] ?? '') !== ''): ?>Inicio act.: <?= h(date('d/m/Y', strtotime((string)$emisor['inicio_actividades']))) ?><?php endif; ?>
                </div>
            </div>
            <div class="b">
                <div class="l">Facturar a</div>
                <div class="v">
                    <strong><?= h($rec) ?></strong><br>
                    <?php if ($f['sin_marca']): ?>
                        <span style="color:#c0392b">Asigná la marca a la categoría para completar los datos fiscales.</span>
                    <?php else: ?>
                        CUIT: <?= h((string)($f['receptor']['cuit'] ?? '') ?: '—') ?> · <?= h((string)($f['receptor']['condicion_iva'] ?? '') ?: '—') ?><br>
                        <?php if (($f['receptor']['domicilio'] ?? '') !== ''): ?><?= h((string)$f['receptor']['domicilio']) ?><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <table>
            <thead>
                <tr><th>Destino (provincia)</th><th class="r">m³</th><th class="r">Tarifa $/m³</th><th class="r">Importe</th></tr>
            </thead>
            <tbody>
            <?php foreach ($f['destinos'] as $d): ?>
                <tr>
                    <td><?= h($d['provincia']) ?></td>
                    <td class="r"><?= fac_m3((float)$d['m3']) ?></td>
                    <td class="r"><?= (float)$d['tarifa'] > 0 ? fac_money((float)$d['tarifa']) : '<span style="color:#c0392b">sin tarifa</span>' ?></td>
                    <td class="r"><?= fac_money((float)$d['importe']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td class="r" colspan="3">Subtotal</td><td class="r"><?= fac_money((float)$f['subtotal']) ?></td></tr>
                <tr><td class="r" colspan="3">IVA (<?= h(rtrim(rtrim(number_format((float)$f['iva_alicuota'], 2, '.', ''), '0'), '.')) ?>%)</td><td class="r"><?= fac_money((float)$f['iva_monto']) ?></td></tr>
                <tr><td class="r" colspan="3">TOTAL</td><td class="r"><?= fac_money((float)$f['total']) ?></td></tr>
            </tfoot>
        </table>

        <div class="foot">
            <b>Transportista(s):</b> <?= h($f['transportistas'] !== '' ? $f['transportistas'] : '—') ?> ·
            <b>Fecha(s) de carga:</b> <?= h(fac_fechas((string)$f['fechas'])) ?> ·
            <b>Hoja(s) de ruta:</b> <?= h($f['hojas_ruta'] !== '' ? $f['hojas_ruta'] : '—') ?> ·
            <b>Total m³:</b> <?= fac_m3((float)$f['total_m3']) ?>
        </div>

        <?php if ($f['detalle'] !== []): ?>
        <div class="det">
            <h2>Detalle (respaldo)</h2>
            <table>
                <thead>
                    <tr><th>Nº orden</th><th>Nº remito</th><th>Cliente</th><th>Provincia</th><th class="r">m³</th></tr>
                </thead>
                <tbody>
                <?php foreach ($f['detalle'] as $o): ?>
                    <tr>
                        <td><?= h($o['nro_orden']) ?></td>
                        <td><?= h($o['nro_remito'] !== '' ? $o['nro_remito'] : '—') ?></td>
                        <td><?= h($o['cliente']) ?></td>
                        <td><?= h($o['provincia']) ?></td>
                        <td class="r"><?= fac_m3((float)$o['m3']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
<?php endforeach; endif; ?>
</body>
</html>

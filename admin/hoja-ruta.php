<?php
declare(strict_types=1);

// =============================================================================
// admin/hoja-ruta.php — Hoja de ruta imprimible (A4 apaisado, lista para PDF).
//
// Dos modos:
//   ?hoja=<id>          → hoja armada en el panel (HojaRuta): órdenes + manuales.
//   ?lote=<id>|?uuid=…  → legacy: hoja de un lote de SALIDA_REPARTO escaneado.
// Cualquier usuario con sesión.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;
use Trazock\Models\HojaRuta;
use Trazock\Models\Lote;

$user = Auth::validarSesion();
if ($user === null) {
    header('Location: ' . url('admin/login.php'));
    exit;
}

/** Página mínima de error (misma estética clara). */
function hr_error(string $msg): void
{
    ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Hoja de ruta</title>
    <style>body{font-family:system-ui,sans-serif;background:#f4f6f9;color:#1f2937;padding:2rem;text-align:center}</style></head>
    <body><p style="font-size:1rem"><?= h($msg) ?></p></body></html><?php
    exit;
}

$hojaId = (int)($_GET['hoja'] ?? 0);
$vehiculo = ''; $chofer = ''; $ayudantes = '';
$ordenes = []; $manuales = [];
$salida = '';
$salidaDisp = '—';

if ($hojaId > 0) {
    // --- Modo panel ---
    $hoja = HojaRuta::find($hojaId);
    if ($hoja === null) { hr_error('No se encontró la hoja de ruta.'); }
    $num       = (string)$hoja['numero'];
    $vehiculo  = (string)($hoja['vehiculo'] ?? '');
    $chofer    = (string)($hoja['conductor'] ?? '');
    $ayudantes = (string)($hoja['ayudantes'] ?? '');
    $destino   = (string)($hoja['destino'] ?? '');
    $salida    = (string)($hoja['fecha'] ?? '');
    // La fecha de la hoja es un DATE (sin hora): se muestra tal cual, sin conversión
    // de zona horaria (fmt_fecha la correría un día por interpretarla como UTC).
    $salidaDisp = $salida !== '' ? date('d/m/Y', strtotime($salida)) : '—';
    foreach (HojaRuta::ordenesDe($hojaId) as $o) {
        $ordenes[] = [
            'categoria'   => (string)($o['categoria'] ?? ''),
            'nro_orden'   => (string)$o['nro_orden'],
            'cliente'     => trim((string)($o['cliente'] ?? '')) !== '' ? (string)$o['cliente'] : (string)($o['cliente_apellido'] ?? ''),
            'localidad'   => trim((string)($o['dest_localidad'] ?? '') . (($o['dest_localidad'] ?? '') && ($o['dest_provincia'] ?? '') ? ' · ' : '') . (string)($o['dest_provincia'] ?? '')),
            'bultos'      => (int)$o['bultos'],
            'm3'          => (float)$o['m3_total'],
            'telefonos'   => (string)($o['telefonos'] ?? ''),
        ];
    }
    $manuales = HojaRuta::manualesDe($hojaId);
} else {
    // --- Modo legacy (lote de reparto escaneado) ---
    $lote = null;
    $uuid = trim((string)($_GET['uuid'] ?? ''));
    $id   = (int)($_GET['lote'] ?? 0);
    if ($uuid !== '') { $base = Lote::findByUuid($uuid); if ($base !== null) { $id = (int)$base['id']; } }
    if ($id > 0) { $lote = Lote::findById($id); }
    if ($lote === null) {
        hr_error('No se encontró el lote. Si recién enviaste el reparto, esperá unos segundos a que sincronice y recargá.');
    }
    if ($lote['tipo'] !== 'SALIDA_REPARTO') {
        hr_error('La hoja de ruta es solo para lotes de salida a reparto.');
    }
    $num       = lote_num((int)$lote['id'], (string)$lote['created_at']);
    $vehiculo  = (string)($lote['vehiculo'] ?? '');
    $chofer    = trim((string)($lote['chofer'] ?? '')) ?: (string)($lote['transportista_nombre'] ?? '');
    $ayudantes = (string)($lote['ayudantes'] ?? '');
    $destino   = '';
    $salida    = (string)($lote['timestamp_cierre'] ?? $lote['timestamp_apertura'] ?? $lote['created_at']);
    // Acá sí es un datetime en UTC (timestamp del lote): se convierte a la TZ local.
    $salidaDisp = $salida !== '' ? fmt_fecha($salida, 'd/m/Y') : '—';
    foreach (Lote::ordenesParaHojaRuta($id) as $o) {
        $ordenes[] = [
            'categoria'   => (string)($o['categoria'] ?? ''),
            'nro_orden'   => (string)$o['nro_orden'],
            'cliente'     => trim((string)($o['cliente'] ?? '')) !== '' ? (string)$o['cliente'] : (string)($o['cliente_apellido'] ?? ''),
            'localidad'   => trim((string)($o['dest_localidad'] ?? '') . (($o['dest_localidad'] ?? '') && ($o['dest_provincia'] ?? '') ? ' · ' : '') . (string)($o['dest_provincia'] ?? '')),
            'bultos'      => (int)$o['bultos'],
            'm3'          => (float)$o['m3'],
            'telefonos'   => (string)($o['telefonos'] ?? ''),
        ];
    }
}

$totBultos = 0; $totM3 = 0.0;
foreach ($ordenes as $o)  { $totBultos += (int)$o['bultos']; $totM3 += (float)$o['m3']; }
foreach ($manuales as $m) { $totBultos += (int)($m['bultos'] ?? 0); $totM3 += (float)($m['m3'] ?? 0); }
$nLineas = count($ordenes) + count($manuales);
?><!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="<?= h(asset('favicon.png')) ?>">
    <title>Hoja de ruta <?= h($num) ?></title>
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <style>
        *{box-sizing:border-box}
        body{font-family:'Inter',system-ui,sans-serif;color:#111;background:#e9edf2;margin:0;padding:18px}
        .hr-bar{max-width:1300px;margin:0 auto 12px;display:flex;gap:.5rem;justify-content:flex-end}
        .btn{border:1px solid #cfd6df;background:#fff;border-radius:8px;padding:.5rem .9rem;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;color:#1f2937}
        .btn-primary{background:#0d6efd;border-color:#0d6efd;color:#fff}
        .sheet{max-width:1300px;margin:0 auto;background:#fff;border:1px solid #d4d9e0;padding:20px 22px}
        .hr-head{display:flex;align-items:center;gap:14px;border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:12px}
        .hr-head img{height:46px;width:auto;border-radius:6px}
        .hr-head .t{flex:1}
        .hr-head .t h1{font-size:1.15rem;margin:0;letter-spacing:-.01em}
        .hr-head .t .sub{font-size:.78rem;color:#555;margin-top:2px}
        .hr-head .meta{font-size:.72rem;color:#555;text-align:right;line-height:1.5}
        .hr-head .meta .salida-prev{font-size:1rem;font-weight:700;color:#111}
        .hr-via{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px}
        .hr-via .c{border:1px solid #cfd6df;border-radius:6px;padding:6px 9px}
        .hr-via .c .l{font-size:.6rem;text-transform:uppercase;letter-spacing:.05em;color:#777}
        .hr-via .c .v{font-size:.92rem;font-weight:600;min-height:1.1em}
        table{width:100%;border-collapse:collapse;font-size:.78rem}
        th,td{border:1px solid #b9c0c9;padding:4px 6px;text-align:left;vertical-align:middle}
        thead th{background:#eef1f5;font-size:.68rem;text-transform:uppercase;letter-spacing:.02em}
        td.n,th.n{text-align:center;white-space:nowrap}
        tfoot td{font-weight:700;background:#f6f8fa}
        .blankcol{background:#fcfcfd}
        .man td{background:#fffdf5}
        .man-tag{font-size:.6rem;color:#a15c00;font-weight:700}
        .hr-notes{margin-top:12px;font-size:.7rem;color:#333;border:1px solid #e0e4ea;border-radius:6px;padding:8px 10px;background:#fbfcfd}
        .hr-foot{margin-top:14px;display:grid;grid-template-columns:1fr 1fr;gap:14px}
        .hr-foot .box{border:1px solid #cfd6df;border-radius:6px;padding:8px 10px}
        .hr-foot .box .l{font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;color:#666;margin-bottom:.5rem}
        .hr-foot .row2{display:flex;gap:10px}
        .hr-foot .fld{flex:1;font-size:.7rem;color:#555}
        .hr-foot .fld .ln{border-bottom:1px solid #999;height:1.6rem;margin-top:.2rem}
        @media print{
            body{background:#fff;padding:0}
            .no-print{display:none!important}
            .sheet{border:none;max-width:none;padding:0}
            @page{size:A4 landscape;margin:8mm}
        }
    </style>
</head>
<body>
<div class="hr-bar no-print">
    <a class="btn" href="<?= h(url('admin/hojas-ruta.php')) ?>">← Hojas de ruta</a>
    <button class="btn btn-primary" onclick="window.print()">🖨 Imprimir / PDF</button>
</div>

<div class="sheet">
    <div class="hr-head">
        <img src="<?= h(asset('assets/img/logo.jpg')) ?>" alt="Corredora de Servicios">
        <div class="t">
            <h1>Hoja de Ruta — Salida a Reparto</h1>
            <div class="sub"><?= h($num) ?> · <?= $nLineas ?> línea(s) · <?= (int)$totBultos ?> bulto(s)</div>
        </div>
        <div class="meta">
            Emisión: <?= h(fmt_fecha(date('Y-m-d H:i:s'), 'd/m/Y H:i')) ?><br>
            <span class="salida-prev">Salida Prevista: <?= h($salidaDisp) ?></span>
        </div>
    </div>

    <div class="hr-via">
        <div class="c"><div class="l">Unidad asignada</div><div class="v"><?= h(trim($vehiculo) ?: '—') ?></div></div>
        <div class="c"><div class="l">Chofer</div><div class="v"><?= h(trim($chofer) ?: '—') ?></div></div>
        <div class="c"><div class="l">Acompañante(s)</div><div class="v"><?= h(trim($ayudantes) ?: '—') ?></div></div>
        <div class="c"><div class="l">Destino / zona</div><div class="v"><?= h(trim((string)($destino ?? '')) ?: '—') ?></div></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Cliente Origen</th>
                <th class="n">N° Orden</th>
                <th>Cliente Destino</th>
                <th>Localidad</th>
                <th class="n">Btos.</th>
                <th class="n">m³</th>
                <th>N° contacto</th>
                <th class="n">Carga<br>OK/No</th>
                <th class="n">Integridad</th>
                <th class="n">Verif.<br>descarga</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($nLineas === 0): ?>
            <tr><td colspan="10" style="text-align:center;padding:1rem;color:#777">La hoja no tiene órdenes ni líneas.</td></tr>
        <?php endif; ?>
        <?php foreach ($ordenes as $o): ?>
            <tr>
                <td><?= h($o['categoria'] !== '' ? $o['categoria'] : '—') ?></td>
                <td class="n"><?= h($o['nro_orden']) ?></td>
                <td><?= h($o['cliente'] !== '' ? $o['cliente'] : '—') ?></td>
                <td><?= h($o['localidad'] !== '' ? $o['localidad'] : '—') ?></td>
                <td class="n"><?= (int)$o['bultos'] ?></td>
                <td class="n"><?= number_format((float)$o['m3'], 2, ',', '.') ?></td>
                <td><?= h($o['telefonos'] !== '' ? $o['telefonos'] : '—') ?></td>
                <td class="blankcol"></td><td class="blankcol"></td><td class="blankcol"></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($manuales as $m): ?>
            <tr class="man">
                <td><?= h((string)($m['cliente_origen'] ?? '') !== '' ? (string)$m['cliente_origen'] : '—') ?></td>
                <td class="n"><?= h((string)($m['nro_orden'] ?? '') !== '' ? (string)$m['nro_orden'] : '—') ?></td>
                <td><?= h((string)($m['cliente_destino'] ?? '') !== '' ? (string)$m['cliente_destino'] : '—') ?></td>
                <td><?= h((string)($m['localidad'] ?? '') !== '' ? (string)$m['localidad'] : '—') ?></td>
                <td class="n"><?= $m['bultos'] !== null ? (int)$m['bultos'] : '' ?></td>
                <td class="n"><?= $m['m3'] !== null ? number_format((float)$m['m3'], 2, ',', '.') : '' ?></td>
                <td><?= h((string)($m['telefono'] ?? '') !== '' ? (string)$m['telefono'] : '—') ?></td>
                <td class="blankcol"></td><td class="blankcol"></td><td class="blankcol"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" style="text-align:right">Totales</td>
                <td class="n"><?= (int)$totBultos ?></td>
                <td class="n"><?= number_format($totM3, 2, ',', '.') ?></td>
                <td colspan="4"></td>
            </tr>
        </tfoot>
    </table>

    <div class="hr-notes">
        <strong>SR. CHOFER Y/O AYUDANTE:</strong> verificar el correcto cierre del camión antes de salir ·
        dejar en el depósito los remitos conformes y las llaves de la unidad ·
        ante cualquier novedad en la entrega, comunicarse de inmediato con administración.
    </div>

    <div class="hr-foot">
        <div class="box">
            <div class="l">Salida de la unidad</div>
            <div class="row2"><div class="fld">Horario<div class="ln"></div></div><div class="fld">Kilómetros<div class="ln"></div></div></div>
        </div>
        <div class="box">
            <div class="l">Regreso de la unidad</div>
            <div class="row2"><div class="fld">Horario<div class="ln"></div></div><div class="fld">Kilómetros<div class="ln"></div></div></div>
        </div>
        <div class="box">
            <div class="l">Conforme salida depósito</div>
            <div class="fld">Firma y aclaración<div class="ln" style="height:2.2rem"></div></div>
        </div>
        <div class="box">
            <div class="l">Chofer</div>
            <div class="fld">Firma y aclaración<div class="ln" style="height:2.2rem"></div></div>
        </div>
    </div>
</div>
</body>
</html>

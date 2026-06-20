<?php
declare(strict_types=1);

// =============================================================================
// admin/ordenes-confirmacion.php — pantalla posterior a confirmar una carga.
// Resume lo ingresado y ofrece generar las etiquetas con QR (1 por ítem).
// Se llega desde la revisión al confirmar (?carga=ID). admin/gestor.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\EtiquetaQr;
use Trazock\Models\Carga;
use Trazock\Models\Orden;
use Trazock\Models\Producto;

$user = Auth::requierePanel(); // admin o gestor

$cargaId = (int)($_GET['carga'] ?? 0);
$carga   = $cargaId > 0 ? Carga::find($cargaId) : null;

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/ordenes-captura.php')) . '"><i class="bi bi-plus-lg me-1"></i>Nueva carga</a>';

if ($carga === null) {
    panel_header('Confirmación', $user, 'captura', '', $volver);
    echo '<div class="alert alert-warning">No se encontró la carga.</div>';
    panel_footer();
    exit;
}

// Si todavía está en borrador, la confirmación no aplica: volver a la revisión.
if ($carga['estado'] !== 'confirmada') {
    header('Location: ' . url('admin/ordenes-revision.php') . '?carga=' . $cargaId);
    exit;
}

$res    = Orden::resumenCarga($cargaId);
$num    = carga_num($cargaId, (string)($carga['created_at'] ?? ''));
$fecha  = fmt_fecha((string)($carga['confirmada_at'] ?? $carga['created_at'] ?? ''), 'd/m/Y H:i');
$urlEti = url('admin/ordenes-etiquetas.php') . '?carga=' . $cargaId;

// Ítem de muestra para la vista previa de la etiqueta (QR real).
$items   = Producto::paraEtiquetasPorCarga($cargaId);
$muestra = $items[0] ?? null;

panel_header('Carga confirmada', $user, 'captura', '', $volver);
?>
<div class="card p-3 mb-3" style="border-color:rgba(34,197,94,.3);background:rgba(34,197,94,.05)">
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <div style="width:48px;height:48px;border-radius:50%;background:rgba(34,197,94,.15);display:grid;place-items:center;flex-shrink:0">
      <i class="bi bi-check-circle-fill" style="font-size:1.5rem;color:#4ade80"></i>
    </div>
    <div>
      <div style="font-size:16px;font-weight:700;color:#4ade80">Carga <?= h($num) ?> confirmada</div>
      <div class="text-muted" style="font-size:12px">
        <?= h($fecha) ?> &middot; <?= (int)$res['ordenes'] ?> órdenes &middot; <?= (int)$res['items'] ?> ítems &middot;
        <?= h(number_format($res['m3'], 2, ',', '.')) ?> m³ &middot; ingresadas en depósito
      </div>
    </div>
    <div style="margin-left:auto"><span class="badge b-CONFIRMADA" style="font-size:12px;padding:4px 12px">CONFIRMADA</span></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:minmax(280px,340px) 1fr;gap:1rem;align-items:start">
  <div class="card p-3">
    <div style="font-size:13px;font-weight:600;margin-bottom:.85rem"><i class="bi bi-tag-fill me-2" style="color:var(--muted)"></i>Generar etiquetas</div>
    <p style="font-size:13px;color:var(--muted);margin-bottom:1rem">Un rótulo con QR por ítem físico. Total:
      <strong style="color:var(--text)"><?= (int)$res['items'] ?> etiquetas</strong>
      <?php if ($res['etiquetados'] > 0): ?>
        <br><span style="font-size:12px"><i class="bi bi-info-circle me-1"></i><?= (int)$res['etiquetados'] ?> ya impresas anteriormente.</span>
      <?php endif; ?>
    </p>
    <div style="font-size:12px;color:var(--muted);margin-bottom:1rem">
      A4 &middot; 8 etiquetas por hoja &middot; <?= (int)ceil(max(1, $res['items']) / 8) ?> hoja(s) &middot; impresión B&amp;N
    </div>
    <div style="display:grid;gap:.5rem">
      <a class="btn btn-success fw-bold" href="<?= h($urlEti) ?>">
        <i class="bi bi-printer me-2"></i>Generar etiquetas (<?= (int)$res['items'] ?>)</a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= h($urlEti) ?>"><i class="bi bi-eye me-1"></i>Vista previa</a>
    </div>
  </div>

  <div class="card p-3">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:1rem">Vista previa de etiqueta</div>
    <?php if ($muestra !== null):
        $sec    = (int)$muestra['secuencia'];
        $tot    = (int)$muestra['total_items'];
        $prov   = (string)($muestra['dest_provincia'] ?? '');
        $loc    = (string)($muestra['dest_localidad'] ?? '');
        $ape    = (string)($muestra['cliente_apellido'] ?? $muestra['cliente'] ?? '');
        $destino = trim($loc . ($loc && $prov ? ' · ' : '') . $prov) ?: '—';
        $payload = EtiquetaQr::payload((string)$muestra['nro_orden'], $sec, $tot, $prov, $loc, $ape);
    ?>
    <div class="label-card">
      <div class="lq" data-qr="<?= h($payload) ?>"></div>
      <div class="lb">
        <div class="ld"><?= h($destino) ?></div>
        <div class="ln"><?= h(trim((string)$muestra['cliente']) !== '' ? (string)$muestra['cliente'] : $ape) ?></div>
        <div class="li">Ítem <?= $sec ?> de <?= $tot ?> &middot; <?= h((string)($muestra['descripcion'] ?? 'Ítem')) ?></div>
        <div class="lc"><?= h((string)$muestra['codigo']) ?> &middot; <?= h($num) ?></div>
      </div>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-top:.75rem">Impresión B&amp;N &middot; alto contraste &middot; QR autocontenido (destino verificable offline)</div>
    <?php else: ?>
    <div class="text-muted" style="font-size:13px">La carga no tiene ítems para etiquetar.</div>
    <?php endif; ?>
  </div>
</div>

<script src="<?= h(asset('assets/vendor/qrcode-generator/qrcode.min.js')) ?>"></script>
<script src="<?= h(asset('assets/js/etiquetas.js')) ?>"></script>
<?php
panel_footer();

<?php
declare(strict_types=1);

// =============================================================================
// admin/producto-detalle.php — detalle de producto: header + timeline + acciones.
// Acciones (admin + gestor): ajuste manual de estado, marcar conflictos revisados.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/_layout.php';

use Trazock\Auth;
use Trazock\Models\Conflicto;
use Trazock\Models\Producto;
use Trazock\Models\Stats;
use Trazock\Models\Transicion;

$user = Auth::requierePanel();

$codigo = trim((string)($_GET['codigo'] ?? ''));
$prod   = $codigo !== '' ? Producto::findByCodigo($codigo) : null;

// Generación (perezosa) del enlace público de seguimiento — POST + CSRF + PRG.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'generar_token') {
    if (!Auth::validarCSRF((string)($_POST['csrf_token'] ?? ''))) {
        flash_set('danger', 'Sesión inválida. Recargá e intentá de nuevo.');
    } elseif ($prod === null) {
        flash_set('danger', 'Producto no encontrado.');
    } else {
        try {
            Producto::asegurarToken((int)$prod['id']);
            flash_set('success', 'Enlace de seguimiento generado.');
        } catch (Throwable $e) {
            flash_set('danger', 'No se pudo generar el enlace de seguimiento.');
            error_log('producto-detalle.php token: ' . $e->getMessage());
        }
    }
    header('Location: ' . url('admin/producto-detalle.php?codigo=' . urlencode($codigo)));
    exit;
}

$volver = '<a class="btn btn-sm btn-outline-secondary" href="' . h(url('admin/productos.php')) . '"><i class="bi bi-arrow-left me-1"></i>Volver</a>';

if ($prod === null) {
    panel_header('Producto no encontrado', $user, 'productos', '', $volver);
    echo '<div class="alert alert-warning">No se encontró ningún producto con el código <span class="mono">' . h($codigo) . '</span>.</div>';
    panel_footer();
    exit;
}

$historial  = Transicion::historialProducto((int)$prod['id']);
$conflictos = Conflicto::deProducto((int)$prod['id'], true);
$csrf       = Auth::tokenCSRF();

// Seguimiento público: el token puede no existir todavía (se genera a demanda).
$token   = $prod['token_publico'] ?? null;
$segUrl  = $token ? seguimiento_url((string)$token) : '';
$segMsg  = 'Hola! Podés seguir el estado de tu pedido en este enlace: ' . $segUrl;
$waUrl   = 'https://wa.me/?text=' . rawurlencode($segMsg);
$mailUrl = 'mailto:?subject=' . rawurlencode('Seguimiento de tu pedido')
         . '&body=' . rawurlencode($segMsg);

panel_header('Detalle de producto', $user, 'productos', '', $volver);
?>
<?php flash_render(); ?>
<div class="card p-3 mb-3">
    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
        <div>
            <div class="code-lg mb-1"><?= h($prod['codigo']) ?></div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="text-muted" style="font-size:13px"><?= h($prod['categoria_nombre'] ?? '(sin categoría)') ?></span>
                <?= estado_badge($prod['estado_actual']) ?>
                <?php if ((int)$prod['tiene_conflicto'] === 1): ?>
                    <span class="badge b-conflict"><i class="bi bi-exclamation-triangle-fill me-1"></i>Tiene conflictos</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalAjuste"><i class="bi bi-pencil-fill me-1"></i>Ajuste manual</button>
            <?php if ($conflictos !== []): ?>
                <button class="btn btn-sm btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#modalConflictos"><i class="bi bi-check-circle me-1"></i>Marcar revisados (<?= count($conflictos) ?>)</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card p-3 mb-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
        <div class="fw-bold"><i class="bi bi-geo-alt-fill me-1" style="color:var(--blue,#3b82f6)"></i>Seguimiento público</div>
        <span class="text-muted" style="font-size:12px">Enlace para el cliente final</span>
    </div>
    <?php if ($token === null): ?>
        <p class="text-muted small mb-2">Todavía no se generó un enlace de seguimiento para este producto.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="accion" value="generar_token">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-link-45deg me-1"></i>Generar enlace de seguimiento</button>
        </form>
    <?php else: ?>
        <div class="input-group input-group-sm mb-2">
            <input class="form-control mono" id="segUrl" value="<?= h($segUrl) ?>" readonly>
            <button class="btn btn-outline-secondary" type="button" id="segCopiar" title="Copiar enlace"><i class="bi bi-clipboard"></i></button>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a class="btn btn-sm btn-outline-success" href="<?= h($waUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1"></i>WhatsApp</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($mailUrl) ?>"><i class="bi bi-envelope me-1"></i>Email</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= h($segUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Ver página</a>
        </div>
        <p class="text-muted mb-0 mt-2" style="font-size:11px">El cliente ve sólo el texto público del estado; nunca el código interno. Editá esos textos en Administración → Seguimiento.</p>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-header" style="padding:.6rem 1rem">Historial de transiciones</div>
    <div style="overflow-x:auto">
        <table class="table table-hover mb-0">
            <thead><tr><th>Estado</th><th>Fecha</th><th>Lote</th><th>Usuario</th><th>Transportista</th><th>Observaciones</th></tr></thead>
            <tbody>
            <?php if ($historial === []): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Sin transiciones registradas.</td></tr>
            <?php endif; ?>
            <?php foreach ($historial as $t):
                $esConf   = (int)$t['es_conflicto'] === 1;
                $esManual = (int)$t['es_ajuste_manual'] === 1;
                $tsC = strtotime((string)$t['timestamp_cliente']);
                $tsS = strtotime((string)$t['timestamp_server']);
                $difMin = ($tsC && $tsS) ? (int)round(($tsS - $tsC) / 60) : 0;
            ?>
                <tr class="<?= $esConf ? 'table-active' : '' ?>">
                    <td>
                        <?= estado_badge($t['estado_hasta']) ?>
                        <?php if ($esConf): ?><i class="bi bi-exclamation-triangle-fill ms-1" style="color:var(--red)" title="Conflicto"></i><?php endif; ?>
                        <?php if ($esManual): ?><i class="bi bi-pencil-fill ms-1" style="color:var(--yellow)" title="Ajuste manual"></i><?php endif; ?>
                    </td>
                    <td class="text-muted" style="font-size:12px">
                        <?= h(fmt_fecha($t['timestamp_cliente'])) ?>
                        <?php if (abs($difMin) >= 2): ?>
                            <i class="bi bi-exclamation-circle ms-1" style="color:var(--yellow)" title="Hora de servidor: <?= h(fmt_fecha($t['timestamp_server'])) ?> (<?= ($difMin > 0 ? '+' : '') . $difMin ?> min)"></i>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px">
                        <?php if ($esManual): ?>
                            <span class="badge" style="background:rgba(234,179,8,.2);color:#fbbf24">Ajuste manual</span>
                        <?php elseif ($t['lote_id'] !== null): ?>
                            <a class="mono" href="<?= h(url('admin/lote-detalle.php?id=' . (int)$t['lote_id'])) ?>"><?= h(lote_num((int)$t['lote_id'], $t['lote_created'])) ?></a>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td style="font-size:12px"><?= h(($esManual ? $t['ajustado_por_nombre'] : $t['responsable_nombre']) ?? '—') ?></td>
                    <td style="font-size:12px"><?= h($t['transportista_nombre'] ?? '—') ?></td>
                    <td style="font-size:12px">
                        <?php if ($esConf): ?>
                            <div style="color:#f87171"><?= h(conflicto_tipo_label($t['motivo_conflicto'])) ?></div>
                            <?php if (!empty($t['conflicto_revisado_at'])): ?>
                                <div class="text-muted">✓ Revisado<?= !empty($t['conflicto_revisado_por']) ? ' por ' . h($t['conflicto_revisado_por']) : '' ?><?= !empty($t['conflicto_nota']) ? ': ' . h($t['conflicto_nota']) : '' ?></div>
                            <?php endif; ?>
                        <?php elseif ($esManual && !empty($t['motivo_conflicto'])): ?>
                            <?= h($t['motivo_conflicto']) ?>
                        <?php elseif (!empty($t['lote_observaciones'])): ?>
                            <span class="text-muted"><?= h($t['lote_observaciones']) ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal ajuste manual -->
<div class="modal fade" id="modalAjuste" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h6 class="modal-title fw-bold">Ajuste manual de estado</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div id="ajusteError" class="alert alert-danger d-none py-2"></div>
        <div style="padding:.5rem .75rem;border-radius:6px;background:rgba(0,0,0,.2);margin-bottom:.75rem">
          <div style="font-size:11px;color:var(--muted);margin-bottom:4px">Estado actual</div>
          <?= estado_badge($prod['estado_actual']) ?>
        </div>
        <div class="mb-3">
          <label class="form-label">Nuevo estado *</label>
          <select class="form-select" id="ajusteEstado">
            <?php foreach (Stats::ESTADOS as $e): ?>
              <option value="<?= h($e) ?>" <?= $e === $prod['estado_actual'] ? 'disabled' : '' ?>><?= h($e) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2"><label class="form-label">Motivo *</label><textarea class="form-control" id="ajusteMotivo" rows="3" placeholder="Explique el motivo del ajuste manual…"></textarea></div>
        <div class="alert alert-warning py-2 mb-0" style="font-size:12px">El ajuste manual no se marca como conflicto. Queda registrado con tu usuario.</div>
      </div>
      <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-warning btn-sm fw-bold" id="ajusteConfirmar"><i class="bi bi-check-lg me-1"></i>Confirmar cambio</button></div>
  </div></div>
</div>

<!-- Modal conflictos -->
<div class="modal fade" id="modalConflictos" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
      <div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-check-circle-fill me-2" style="color:var(--green)"></i>Conflictos pendientes</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <?php foreach ($conflictos as $c): ?>
          <div class="border rounded p-2 mb-2" data-conflicto="<?= (int)$c['id'] ?>" style="border-color:var(--border)!important">
            <div class="mb-1"><?= conflicto_badge($c['tipo']) ?></div>
            <div class="small text-muted my-1"><?= h($c['descripcion']) ?></div>
            <div class="input-group input-group-sm">
              <input class="form-control" placeholder="Nota de resolución (opcional)" data-nota="<?= (int)$c['id'] ?>">
              <button class="btn btn-outline-success" onclick="resolverConflicto(<?= (int)$c['id'] ?>)">Marcar revisado</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
  </div></div>
</div>

<script>
const TZ = {
    csrf: <?= json_encode($csrf) ?>,
    codigo: <?= json_encode($prod['codigo']) ?>,
    apiAjuste: <?= json_encode(url('api/ajuste-manual.php')) ?>,
    apiConflicto: <?= json_encode(url('api/conflicto-resolver.php')) ?>
};
document.getElementById('ajusteConfirmar').addEventListener('click', function () {
    const estado = document.getElementById('ajusteEstado').value;
    const motivo = document.getElementById('ajusteMotivo').value.trim();
    const errBox = document.getElementById('ajusteError');
    errBox.classList.add('d-none');
    if (!motivo) { errBox.textContent = 'El motivo es obligatorio.'; errBox.classList.remove('d-none'); return; }
    fetch(TZ.apiAjuste, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: TZ.csrf, codigo: TZ.codigo, nuevo_estado: estado, motivo: motivo })
    }).then(r => r.json().then(d => ({ ok: r.ok, d })))
      .then(({ ok, d }) => { if (ok && d.ok) location.reload(); else { errBox.textContent = d.error || 'No se pudo aplicar el ajuste.'; errBox.classList.remove('d-none'); } })
      .catch(() => { errBox.textContent = 'Error de red.'; errBox.classList.remove('d-none'); });
});
function resolverConflicto(id) {
    const nota = (document.querySelector('[data-nota="' + id + '"]') || {}).value || '';
    fetch(TZ.apiConflicto, {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: TZ.csrf, conflicto_id: id, nota: nota })
    }).then(r => r.json().then(d => ({ ok: r.ok, d })))
      .then(({ ok, d }) => { if (ok && d.ok) location.reload(); else alert(d.error || 'No se pudo marcar el conflicto.'); })
      .catch(() => alert('Error de red.'));
}

// Copiar el enlace de seguimiento al portapapeles.
const segCopiarBtn = document.getElementById('segCopiar');
if (segCopiarBtn) {
    segCopiarBtn.addEventListener('click', function () {
        const inp = document.getElementById('segUrl');
        if (!inp) return;
        const ok = () => { const i = segCopiarBtn.querySelector('i'); if (i) { i.className = 'bi bi-check-lg'; setTimeout(() => { i.className = 'bi bi-clipboard'; }, 1500); } };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(inp.value).then(ok).catch(() => { inp.select(); document.execCommand('copy'); ok(); });
        } else {
            inp.select(); document.execCommand('copy'); ok();
        }
    });
}
</script>
<?php
panel_footer();

<?php
declare(strict_types=1);

// =============================================================================
// admin/_layout.php — plantilla del panel (tema oscuro, portado del prototipo).
//
// panel_header($titulo, $user, $activo, $subtitulo = '', $acciones = '')
//   $acciones: HTML opcional alineado a la derecha del título (botones, toggles).
// panel_footer()
// =============================================================================

use Trazock\Auth;
use Trazock\Models\Conflicto;
use Trazock\Models\Encuesta;

/**
 * Menú del panel. Cada ítem declara qué roles lo ven y un ícono de Bootstrap Icons.
 *
 * @return array<int, array{seccion:?string, items:array}>
 */
function panel_menu(): array
{
    return [
        [
            'seccion' => 'Operación',
            'items'   => [
                ['key' => 'dashboard',  'label' => 'Dashboard',   'href' => 'index.php',           'icon' => 'grid-1x2-fill',            'roles' => ['admin', 'gestor', 'logistica']],
                ['key' => 'captura',    'label' => 'Nueva carga', 'href' => 'ordenes-captura.php',  'icon' => 'cloud-upload-fill',        'roles' => ['admin', 'logistica']],
                ['key' => 'productos',  'label' => 'Productos',   'href' => 'productos.php',        'icon' => 'boxes',                    'roles' => ['admin', 'gestor', 'logistica']],
                ['key' => 'lotes',      'label' => 'Lotes',       'href' => 'lotes.php',            'icon' => 'collection-fill',          'roles' => ['admin', 'gestor', 'logistica', 'contable']],
                ['key' => 'conflictos', 'label' => 'Conflictos',  'href' => 'conflictos.php',       'icon' => 'exclamation-triangle-fill','roles' => ['admin', 'gestor', 'logistica'], 'conflicto' => true],
                ['key' => 'reportes',   'label' => 'Reportes',    'href' => 'ordenes-reportes.php', 'icon' => 'bar-chart-fill',           'roles' => ['admin', 'gestor', 'logistica']],
                ['key' => 'movimientos','label' => 'Movimientos', 'href' => 'movimientos.php',      'icon' => 'truck',                    'roles' => ['admin', 'gestor', 'logistica']],
                ['key' => 'costos',     'label' => 'Costos',      'href' => 'costos.php',           'icon' => 'cash-coin',                'roles' => ['admin', 'gestor', 'contable']],
                ['key' => 'costos-fijos','label' => 'Costos fijos','href' => 'costos-fijos.php',     'icon' => 'building',                  'roles' => ['admin', 'gestor', 'contable']],
                ['key' => 'caja-chica', 'label' => 'Caja chica',  'href' => 'caja-chica.php',       'icon' => 'wallet2',                  'roles' => ['admin', 'gestor', 'contable']],
                ['key' => 'rentabilidad','label' => 'Resultados', 'href' => 'rentabilidad.php',     'icon' => 'graph-up-arrow',           'roles' => ['admin', 'gestor', 'contable']],
                ['key' => 'encuestas',  'label' => 'Encuestas',   'href' => 'encuestas.php',        'icon' => 'emoji-smile-fill',         'roles' => ['admin', 'gestor', 'logistica'], 'encuesta' => true],
                ['key' => 'confirmaciones','label' => 'Avisos de entrega','href' => 'confirmaciones.php','icon' => 'whatsapp',           'roles' => ['admin', 'gestor', 'logistica']],
            ],
        ],
        [
            'seccion' => 'Configuración',
            'config'  => true,
            'items'   => [
                ['key' => 'usuarios',     'label' => 'Usuarios',         'href' => 'usuarios.php',     'icon' => 'people-fill',      'roles' => ['admin']],
                ['key' => 'categorias',   'label' => 'Categorías',       'href' => 'categorias.php',   'icon' => 'tag-fill',         'roles' => ['admin']],
                ['key' => 'proveedores',  'label' => 'Proveedores',      'href' => 'proveedores.php',  'icon' => 'truck',            'roles' => ['admin']],
                ['key' => 'zonas',        'label' => 'Zonas',            'href' => 'zonas.php',        'icon' => 'map-fill',         'roles' => ['admin']],
                ['key' => 'vehiculos',    'label' => 'Vehículos',        'href' => 'vehiculos.php',    'icon' => 'truck-front-fill', 'roles' => ['admin']],
                ['key' => 'acompanantes', 'label' => 'Empleados',        'href' => 'acompanantes.php', 'icon' => 'people-fill',     'roles' => ['admin']],
                ['key' => 'motivos',      'label' => 'Motivos',          'href' => 'motivos.php',      'icon' => 'chat-text-fill',   'roles' => ['admin']],
                ['key' => 'facturacion-clientes', 'label' => 'Facturación x cliente', 'href' => 'facturacion-clientes.php', 'icon' => 'cash-coin', 'roles' => ['admin']],
                ['key' => 'afip-emisor',  'label' => 'Datos del emisor', 'href' => 'afip-emisor.php',  'icon' => 'receipt',          'roles' => ['admin']],
                ['key' => 'seguimiento',  'label' => 'Seguimiento',      'href' => 'seguimiento.php',  'icon' => 'geo-alt-fill',     'roles' => ['admin']],
            ],
        ],
    ];
}

/** Iniciales para el avatar (ej. "Ana Martínez" → "AM"). */
function panel_iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/', trim($nombre)) ?: [];
    $i = '';
    foreach (array_slice($partes, 0, 2) as $p) {
        $i .= mb_substr($p, 0, 1);
    }
    return $i !== '' ? mb_strtoupper($i) : '?';
}

function panel_header(string $titulo, array $user, string $activo = '', string $subtitulo = '', string $acciones = ''): void
{
    $rol         = (string)$user['rol'];
    $nConflictos = Conflicto::totalPendientes();
    $nEncuestas  = Encuesta::total();
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>try{if(localStorage.getItem('tzRail')==='1')document.documentElement.classList.add('rail');}catch(e){}</script>
    <link rel="icon" type="image/png" href="<?= h(asset('favicon.png')) ?>">
    <link rel="apple-touch-icon" href="<?= h(asset('favicon.png')) ?>">
    <title><?= h($titulo) ?> — Corredora de Servicios</title>
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/css/app.css')) ?>">
</head>
<body>
<div id="sidebar-overlay" onclick="tzCloseSidebar()"></div>

<nav id="sidebar">
    <div class="sb-head" style="padding:.7rem 1rem;border-bottom:1px solid var(--border);text-align:center">
        <img class="brand-img" src="<?= h(asset('assets/img/logo.jpg')) ?>" alt="Corredora de Servicios S.A." style="max-width:140px;width:100%;height:auto;border-radius:8px;background:#fff;padding:4px">
    </div>
    <div style="flex:1;overflow-y:auto;padding:.5rem 0">
        <button type="button" class="ni" onclick="tzToggleRail()" title="Replegar / expandir menú">
            <i class="bi bi-chevron-double-left ni-railicon"></i><span class="ni-label">Replegar menú</span>
        </button>
        <?php foreach (panel_menu() as $grupo): ?>
            <?php
            $visibles = array_filter($grupo['items'], static fn(array $it): bool => in_array($rol, $it['roles'], true));
            if ($visibles === []) { continue; }
            $esConfig = !empty($grupo['config']);
            $abierto  = false;
            if ($esConfig) {
                foreach ($visibles as $it) { if ($activo === $it['key']) { $abierto = true; break; } }
            }
            ?>
            <?php if ($esConfig): ?>
                <button type="button" class="ni ni-group<?= $abierto ? ' open' : '' ?>" onclick="tzToggleConfig(this)">
                    <i class="bi bi-gear-fill"></i><span class="ni-label"><?= h($grupo['seccion']) ?></span>
                    <i class="bi bi-chevron-down ni-caret ms-auto"></i>
                </button>
                <div class="subnav<?= $abierto ? ' open' : '' ?>" id="cfgSub">
                    <?php foreach ($visibles as $it): ?>
                        <a class="ni <?= $activo === $it['key'] ? 'active' : '' ?>" href="<?= h(url('admin/' . $it['href'])) ?>">
                            <i class="bi bi-<?= h($it['icon']) ?>"></i><span class="ni-label"><?= h($it['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="nd"><?= h($grupo['seccion']) ?></div>
                <?php foreach ($visibles as $it): ?>
                    <a class="ni <?= $activo === $it['key'] ? 'active' : '' ?>" href="<?= h(url('admin/' . $it['href'])) ?>">
                        <i class="bi bi-<?= h($it['icon']) ?>"></i><span class="ni-label"><?= h($it['label']) ?></span>
                        <?php if (!empty($it['conflicto']) && $nConflictos > 0): ?>
                            <span class="badge b-conflict ms-auto"><?= (int)$nConflictos ?></span>
                        <?php endif; ?>
                        <?php if (!empty($it['encuesta']) && $nEncuestas > 0): ?>
                            <span class="badge b-activo ms-auto"><?= (int)$nEncuestas ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div style="border-top:1px solid var(--border);padding:.5rem">
        <div class="d-flex align-items-center gap-2" style="padding:.3rem .4rem .5rem">
            <div class="tz-avatar"><?= h(panel_iniciales((string)$user['nombre_completo'])) ?></div>
            <div class="tz-userbox">
                <div style="font-size:12px;font-weight:600;line-height:1.3"><?= h((string)$user['nombre_completo']) ?></div>
                <?= rol_badge($rol) ?>
            </div>
        </div>
        <a class="ni" style="color:var(--red)" href="<?= h(url('admin/logout.php')) ?>"><i class="bi bi-box-arrow-left"></i><span class="ni-label">Cerrar sesión</span></a>
        <div class="text-center tz-powered" style="font-size:10px;color:var(--muted);padding:.4rem 0 .1rem;opacity:.75">powered by <strong style="color:var(--muted)">Trazock</strong></div>
    </div>
</nav>

<main id="main">
    <div class="d-flex d-md-none align-items-center gap-3 mb-3">
        <button class="btn btn-outline-secondary btn-sm px-2" onclick="tzOpenSidebar()"><i class="bi bi-list" style="font-size:1.1rem"></i></button>
        <img src="<?= h(asset('assets/img/logo.jpg')) ?>" alt="Corredora de Servicios S.A." style="height:30px;width:auto;border-radius:5px;background:#fff;padding:2px 4px">
    </div>

    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
        <div>
            <div class="sec-title"><?= h($titulo) ?></div>
            <?php if ($subtitulo !== ''): ?><div class="text-muted" style="font-size:12px"><?= h($subtitulo) ?></div><?php endif; ?>
        </div>
        <?php if ($acciones !== ''): ?><div class="d-flex align-items-center gap-2 flex-wrap"><?= $acciones ?></div><?php endif; ?>
    </div>
    <?php
}

function panel_footer(): void
{
    ?>
</main>

<!-- Modal de confirmación reutilizable (reemplaza el confirm() del navegador) -->
<div class="modal fade" id="tzConfirmModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h6 class="modal-title fw-bold" id="tzConfirmTitulo">Confirmar</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="tzConfirmMsg" style="font-size:13px"></div>
    <div class="modal-footer"><button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary btn-sm fw-bold" id="tzConfirmOk">Confirmar</button></div>
  </div></div>
</div>

<script src="<?= h(asset('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script>
function tzOpenSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('sidebar-overlay').classList.add('show');}
function tzCloseSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('sidebar-overlay').classList.remove('show');}

// Submenú Configuración (acordeón).
function tzToggleConfig(btn){ btn.classList.toggle('open'); var s=document.getElementById('cfgSub'); if(s){ s.classList.toggle('open'); } }
// Replegar/expandir el sidebar a íconos (persistente).
function tzToggleRail(){ var h=document.documentElement; h.classList.toggle('rail'); try{ localStorage.setItem('tzRail', h.classList.contains('rail')?'1':'0'); }catch(e){} }

// Confirmación con modal: tzConfirm(form, mensaje) → al confirmar, envía el form.
let __tzConfirmForm = null;
function tzConfirm(form, mensaje){
    __tzConfirmForm = form;
    document.getElementById('tzConfirmMsg').textContent = mensaje || '¿Confirmás la acción?';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('tzConfirmModal')).show();
    return false;
}
document.getElementById('tzConfirmOk').addEventListener('click', function(){
    if (__tzConfirmForm) __tzConfirmForm.submit();
});

// Toggle de mostrar/ocultar contraseña: botón con data-toggle-pass="idInput".
document.addEventListener('click', function(e){
    const btn = e.target.closest('[data-toggle-pass]');
    if (!btn) return;
    const inp = document.getElementById(btn.getAttribute('data-toggle-pass'));
    if (!inp) return;
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    const ic = btn.querySelector('i');
    if (ic) ic.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});
</script>
</body>
</html>
    <?php
}

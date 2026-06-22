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
            'seccion' => 'Principal',
            'items'   => [
                ['key' => 'dashboard',  'label' => 'Dashboard',  'href' => 'index.php',      'icon' => 'grid-1x2-fill',           'roles' => ['admin']],
                ['key' => 'productos',  'label' => 'Productos',   'href' => 'productos.php',  'icon' => 'boxes',                   'roles' => ['admin']],
                ['key' => 'lotes',      'label' => 'Lotes',       'href' => 'lotes.php',      'icon' => 'collection-fill',         'roles' => ['admin']],
                ['key' => 'conflictos', 'label' => 'Conflictos',  'href' => 'conflictos.php', 'icon' => 'exclamation-triangle-fill','roles' => ['admin'], 'conflicto' => true],
                ['key' => 'exportar',   'label' => 'Exportar',    'href' => 'exportar.php',   'icon' => 'download',                'roles' => ['admin']],
            ],
        ],
        [
            'seccion' => 'Órdenes',
            'items'   => [
                ['key' => 'captura',  'label' => 'Nueva carga', 'href' => 'ordenes-captura.php', 'icon' => 'cloud-upload-fill', 'roles' => ['admin']],
                ['key' => 'reportes', 'label' => 'Reportes',    'href' => 'ordenes-reportes.php', 'icon' => 'bar-chart-fill', 'roles' => ['admin', 'gestor']],
                ['key' => 'encuestas','label' => 'Encuestas',   'href' => 'encuestas.php',       'icon' => 'emoji-smile-fill', 'roles' => ['admin', 'gestor'], 'encuesta' => true],
            ],
        ],
        [
            'seccion' => 'Administración',
            'items'   => [
                ['key' => 'usuarios',     'label' => 'Usuarios',     'href' => 'usuarios.php',     'icon' => 'people-fill',  'roles' => ['admin']],
                ['key' => 'categorias',   'label' => 'Categorías',   'href' => 'categorias.php',   'icon' => 'tag-fill',     'roles' => ['admin']],
                ['key' => 'proveedores',  'label' => 'Proveedores',  'href' => 'proveedores.php',  'icon' => 'truck',        'roles' => ['admin']],
                ['key' => 'zonas',        'label' => 'Zonas',        'href' => 'zonas.php',        'icon' => 'map-fill',     'roles' => ['admin']],
                ['key' => 'motivos',      'label' => 'Motivos',      'href' => 'motivos.php',      'icon' => 'chat-text-fill','roles' => ['admin']],
                ['key' => 'seguimiento',  'label' => 'Seguimiento',  'href' => 'seguimiento.php',  'icon' => 'geo-alt-fill', 'roles' => ['admin']],
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
    <title><?= h($titulo) ?> — Trazock</title>
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/css/app.css')) ?>">
</head>
<body>
<div id="sidebar-overlay" onclick="tzCloseSidebar()"></div>

<nav id="sidebar">
    <div class="d-flex align-items-center gap-2" style="padding:.8rem 1rem;border-bottom:1px solid var(--border)">
        <div class="tz-brand-box"><i class="bi bi-upc-scan text-white" style="font-size:13px"></i></div>
        <span style="font-weight:700;font-size:1.05rem;letter-spacing:-.02em">Trazock</span>
    </div>
    <div class="d-flex align-items-center gap-2" style="padding:.6rem 1rem;border-bottom:1px solid var(--border)">
        <div class="tz-avatar"><?= h(panel_iniciales((string)$user['nombre_completo'])) ?></div>
        <div>
            <div style="font-size:12px;font-weight:600;line-height:1.3"><?= h((string)$user['nombre_completo']) ?></div>
            <?= rol_badge($rol) ?>
        </div>
    </div>
    <div style="flex:1;overflow-y:auto;padding:.5rem 0">
        <?php foreach (panel_menu() as $grupo): ?>
            <?php
            $visibles = array_filter($grupo['items'], static fn(array $it): bool => in_array($rol, $it['roles'], true));
            if ($visibles === []) { continue; }
            ?>
            <div class="nd"><?= h($grupo['seccion']) ?></div>
            <?php foreach ($visibles as $it): ?>
                <a class="ni <?= $activo === $it['key'] ? 'active' : '' ?>" href="<?= h(url('admin/' . $it['href'])) ?>">
                    <i class="bi bi-<?= h($it['icon']) ?>"></i><?= h($it['label']) ?>
                    <?php if (!empty($it['conflicto']) && $nConflictos > 0): ?>
                        <span class="badge b-conflict ms-auto"><?= (int)$nConflictos ?></span>
                    <?php endif; ?>
                    <?php if (!empty($it['encuesta']) && $nEncuestas > 0): ?>
                        <span class="badge b-activo ms-auto"><?= (int)$nEncuestas ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <div style="border-top:1px solid var(--border);padding:.5rem">
        <a class="ni" style="color:var(--red)" href="<?= h(url('admin/logout.php')) ?>"><i class="bi bi-box-arrow-left"></i>Cerrar sesión</a>
    </div>
</nav>

<main id="main">
    <div class="d-flex d-md-none align-items-center gap-3 mb-3">
        <button class="btn btn-outline-secondary btn-sm px-2" onclick="tzOpenSidebar()"><i class="bi bi-list" style="font-size:1.1rem"></i></button>
        <span style="font-weight:700">Trazock</span>
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

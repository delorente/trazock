<?php
declare(strict_types=1);

// =============================================================================
// scan/index.php — App PWA de escaneo (operador, transportista, admin, gestor).
// Vista única con 4 secciones conmutables por JS: login, selector, config, scanner.
// El server inyecta el estado inicial (sesión + catálogos) en window.TZ_BOOT.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;
use Trazock\Catalogos;

Auth::iniciarSesion();
$user = Auth::validarSesion();

// Scope del Service Worker = ruta base de la app (p. ej. /proyectos/trazock/).
$appPath = rtrim(parse_url(APP_URL, PHP_URL_PATH) ?? '', '/') . '/';

$boot = [
    'apiBase'   => url('api'),
    'scope'     => $appPath,
    'swUrl'     => url('scan/sw.js'),
    'zxingWasm' => asset('assets/vendor/zxing-wasm/zxing_reader.wasm'),
    'usuario'   => null,
    'catalogos' => null,
    'csrf'      => null,
];
if ($user !== null) {
    $boot['usuario']   = ['id' => (int)$user['id'], 'nombre' => $user['nombre_completo'], 'rol' => $user['rol']];
    $boot['catalogos'] = Catalogos::para((string)$user['rol']);
    $boot['csrf']      = Auth::tokenCSRF();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#0f1117">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Corredora">
    <title>Corredora de Servicios — Escaneo</title>
    <link rel="icon" type="image/png" href="<?= h(asset('favicon.png')) ?>">
    <link rel="manifest" href="<?= h(url('scan/manifest.json')) ?>">
    <link rel="apple-touch-icon" href="<?= h(asset('assets/img/icon-192.png')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/css/app.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/css/scan.css')) ?>">
</head>
<body class="tz-scan-body">

<!-- ===== Vista LOGIN ===== -->
<section id="view-login" class="tz-view d-none">
    <div class="tz-phone">
        <div class="text-center mb-4">
            <img src="<?= h(asset('assets/img/logo.jpg')) ?>" alt="Corredora de Servicios S.A." style="max-width:180px;width:75%;height:auto;border-radius:10px;background:#fff;padding:6px;margin-bottom:.5rem">
            <div class="text-muted" style="font-size:12px">App de escaneo · powered by <strong>Trazock</strong></div>
        </div>
        <div id="loginError" class="alert alert-danger py-2 d-none" style="font-size:13px"></div>
        <form id="loginForm" class="card p-4" autocomplete="off" style="width:100%">
            <div class="mb-3"><label class="form-label" for="loginUsuario">Usuario</label><input class="form-control" type="text" id="loginUsuario" required></div>
            <div class="mb-3"><label class="form-label" for="loginPassword">Contraseña</label>
                <div class="input-group">
                    <input class="form-control" type="password" id="loginPassword" required>
                    <button class="btn btn-outline-secondary" type="button" data-toggle-pass="loginPassword" title="Mostrar/ocultar"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <button class="btn btn-primary w-100" type="submit" id="loginBtn" style="padding:.65rem;font-weight:600">Ingresar</button>
            <p class="text-muted text-center mt-3 mb-0" style="font-size:11px">Requiere conexión para iniciar sesión.</p>
        </form>
    </div>
</section>

<!-- ===== Vista SELECTOR ===== -->
<section id="view-selector" class="tz-view d-none">
    <header class="tz-scan-header d-flex justify-content-between align-items-center p-3">
        <img src="<?= h(asset('assets/img/logo.jpg')) ?>" alt="Corredora de Servicios S.A." style="height:26px;width:auto;border-radius:5px;background:#fff;padding:2px 4px">
        <div class="d-flex align-items-center gap-2">
            <span class="conn online" id="connInfo"><span class="dot online"></span>Online</span>
            <button class="btn btn-outline-secondary btn-sm py-0 px-2" id="btnLogout"><i class="bi bi-box-arrow-right"></i></button>
        </div>
    </header>
    <div class="px-3 py-2" style="border-bottom:1px solid var(--border)">
        <div style="font-size:13px;font-weight:600" id="selUsuario"></div>
        <div class="text-muted" id="catalogInfo" style="font-size:11px"></div>
    </div>
    <div class="flex-grow-1 d-flex flex-column justify-content-start gap-3 p-4">
        <button class="btn btn-primary w-100" id="btnNuevoLote" style="padding:1.1rem;font-size:1.1rem;font-weight:700;border-radius:12px"><i class="bi bi-plus-circle-fill me-2"></i>Nuevo lote</button>
        <div class="d-flex align-items-center gap-2">
            <div class="card flex-grow-1 d-flex flex-row align-items-center justify-content-between" style="padding:.6rem .75rem">
                <div>
                    <div style="font-size:12px;font-weight:600">Cola de envío</div>
                    <div class="text-muted" style="font-size:11px"><span id="colaResumen">Sin pendientes</span></div>
                </div>
                <span class="badge d-none" id="colaBadge" style="background:var(--yellow);color:#000;font-size:12px">0</span>
            </div>
            <button class="btn btn-outline-secondary" id="btnVerCola" style="flex-shrink:0"><i class="bi bi-list-ul"></i></button>
        </div>
    </div>
</section>

<!-- ===== Vista CONFIG ===== -->
<section id="view-config" class="tz-view d-none">
    <header class="tz-scan-header d-flex justify-content-between align-items-center p-3">
        <button class="btn btn-outline-secondary btn-sm py-1 px-2" id="btnConfigVolver"><i class="bi bi-arrow-left me-1"></i>Cancelar</button>
        <span class="fw-semibold">Nuevo lote</span>
        <span style="width:80px"></span>
    </header>
    <div class="flex-grow-1 overflow-auto p-3">
        <div id="configError" class="alert alert-danger py-2 d-none" style="font-size:13px"></div>
        <form id="configForm">
            <div class="mb-3"><label class="form-label" for="cfgTipo">Tipo de lote *</label><select class="form-select" id="cfgTipo" required></select></div>
            <div id="cfgCampos"></div>
            <div class="mb-2"><label class="form-label" for="cfgObs">Observaciones</label><textarea class="form-control" id="cfgObs" rows="3" placeholder="Notas adicionales (opcional)…"></textarea></div>
        </form>
    </div>
    <div class="p-3" style="border-top:1px solid var(--border)">
        <button class="btn btn-success w-100" form="configForm" type="submit" style="padding:.9rem;font-size:1rem;font-weight:700;border-radius:10px">Iniciar escaneo <i class="bi bi-arrow-right-circle-fill ms-2"></i></button>
    </div>
</section>

<!-- ===== Vista SCANNER ===== -->
<section id="view-scan" class="tz-view d-none">
    <header class="tz-scan-header tz-scan-sticky p-2" style="border-bottom:1px solid var(--border)">
        <div class="d-flex align-items-center justify-content-between mb-1">
            <div class="d-flex align-items-center gap-2">
                <span class="badge b-INGRESO" id="scanTipo">—</span>
                <span style="font-size:13px;font-weight:600" id="scanContador">0 items</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="conn online" id="scanConn"><span class="dot online"></span></span>
                <button class="btn btn-sm btn-danger py-0 px-2" id="btnCancelarLote" style="font-size:12px"><i class="bi bi-x-circle me-1"></i>Cancelar</button>
            </div>
        </div>
        <div class="text-muted" style="font-size:12px" id="scanResumen"></div>
    </header>

    <div class="tz-cam-wrap">
        <div id="scanReader" class="tz-scan-reader"></div>
        <div class="tz-reticle-overlay">
            <div class="reticle">
                <div class="tk tl"></div><div class="tk tr"></div><div class="tk bl"></div><div class="tk br"></div>
                <div class="scan-line"></div>
            </div>
        </div>
        <div class="tz-cam-controls">
            <button class="tz-cam-btn" id="btnLinterna" title="Linterna"><i class="bi bi-lightning-fill"></i></button>
            <button class="tz-cam-btn" id="btnCambiarCam" title="Cambiar cámara"><i class="bi bi-arrow-repeat"></i></button>
            <button class="tz-cam-btn" id="btnBeep" title="Beep"><i class="bi bi-volume-up-fill"></i></button>
        </div>
        <div class="tz-cam-paused d-none" id="scanPausa">
            <i class="bi bi-pause-circle" style="font-size:2.5rem"></i>
            <div>Lectura pausada por inactividad</div>
            <button class="btn btn-primary" id="btnReanudar"><i class="bi bi-play-fill me-1"></i>Reanudar lectura</button>
        </div>
    </div>

    <div class="tz-scan-feedback" id="scanFeedback"></div>

    <div class="tz-scan-list" id="scanLista"></div>

    <div class="tz-scan-footer p-2">
        <button class="btn btn-success w-100" id="btnEnviarLote" style="padding:.8rem;font-weight:700;border-radius:10px"><i class="bi bi-cloud-upload-fill me-2"></i>Cerrar y enviar lote</button>
    </div>
</section>

<!-- ===== Modal de cola ===== -->
<div class="modal fade" id="modalCola" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
      <div class="modal-header" style="justify-content:space-between">
        <h6 class="modal-title fw-bold">Cola de envío</h6>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-primary btn-sm" id="btnSyncTodo" style="font-size:12px"><i class="bi bi-cloud-upload me-1"></i>Sincronizar todo</button>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body p-0" id="colaLista"><p class="text-muted p-3 mb-0">Sin lotes en cola.</p></div>
  </div></div>
</div>

<!-- ===== Modal sesión expirada ===== -->
<div class="modal fade" id="modalSesion" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h6 class="modal-title fw-bold" style="color:var(--red)"><i class="bi bi-exclamation-triangle-fill me-2"></i>Sesión expirada</h6></div>
      <div class="modal-body" style="font-size:13px">Tu sesión expiró. Volvé a iniciar sesión; los lotes en cola se reenviarán automáticamente.</div>
      <div class="modal-footer"><button type="button" class="btn btn-primary btn-sm" id="btnReloginCola" data-bs-dismiss="modal">Ir al login</button></div>
  </div></div>
</div>

<!-- ===== Spinner / overlay ===== -->
<div id="tzOverlay" class="tz-overlay d-none">
    <div class="spinner-border text-light" role="status"></div>
    <div class="text-light mt-2" id="tzOverlayMsg">Procesando…</div>
</div>

<script>
window.TZ_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= h(asset('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= h(asset('assets/vendor/zxing-wasm/reader.iife.js')) ?>"></script>
<script src="<?= h(asset('assets/vendor/idb/idb.js')) ?>"></script>
<script src="<?= h(asset('assets/js/scan/db.js')) ?>"></script>
<script src="<?= h(asset('assets/js/scan/scanner.js')) ?>"></script>
<script src="<?= h(asset('assets/js/scan/sync.js')) ?>"></script>
<script src="<?= h(asset('assets/js/scan/ui.js')) ?>"></script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register(window.TZ_BOOT.swUrl, { scope: window.TZ_BOOT.scope })
            .catch(function () {
                return navigator.serviceWorker.register(window.TZ_BOOT.swUrl).catch(function (e) { console.warn('SW no registrado:', e); });
            });
    });
}
</script>
</body>
</html>

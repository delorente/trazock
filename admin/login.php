<?php
declare(strict_types=1);

// =============================================================================
// admin/login.php — login del panel web.
// Tras autenticar, redirige según rol: admin/gestor → panel; operador/transportista → /scan/.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;

Auth::iniciarSesion();

// Si ya hay sesión válida, no mostrar login: mandar a donde corresponda.
$actual = Auth::validarSesion();
if ($actual !== null) {
    header('Location: ' . login_destino((string)$actual['rol']));
    exit;
}

/** Pantalla a la que va cada rol tras autenticarse. */
function login_destino(string $rol): string
{
    if ($rol === 'admin')  { return url('admin/index.php'); }
    if ($rol === 'gestor') { return url('admin/ordenes-reportes.php'); } // Supervisor: solo reportes
    return url('scan/'); // operador / transportista
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim((string)($_POST['usuario'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $token    = (string)($_POST['csrf_token'] ?? '');

    if (!Auth::validarCSRF($token)) {
        $error = 'Sesión inválida o expirada. Recargá la página e intentá de nuevo.';
    } elseif ($usuario === '' || $password === '') {
        $error = 'Ingresá usuario y contraseña.';
    } elseif (Auth::login($usuario, $password)) {
        $user = Auth::usuarioActual();
        header('Location: ' . login_destino((string)$user['rol']));
        exit;
    } else {
        // Mensaje genérico: no distingue "usuario inexistente" de "password incorrecta"
        // ni revela el bloqueo por rate limit (no dar pistas a un atacante).
        $error = 'Usuario o contraseña incorrectos. Tras varios intentos fallidos el acceso se bloquea temporalmente.';
    }
}

$csrf = Auth::tokenCSRF();
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar — Corredora de Servicios</title>
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/inter/inter.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('assets/css/app.css')) ?>">
</head>
<body>
<div style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem">
    <div style="width:100%;max-width:360px">
        <div class="text-center mb-4">
            <img src="<?= h(asset('assets/img/logo.jpg')) ?>" alt="Corredora de Servicios S.A." style="max-width:200px;width:70%;height:auto;border-radius:10px;background:#fff;padding:6px;margin-bottom:.6rem">
            <p class="text-muted" style="font-size:12px">Sistema de trazabilidad de stock</p>
        </div>
        <div class="card p-4">
            <?php if ($error !== null): ?>
                <div class="alert alert-danger py-2 mb-3" style="font-size:13px" role="alert"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?></div>
            <?php endif; ?>
            <form method="post" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <div class="mb-3">
                    <label class="form-label" for="usuario">Usuario</label>
                    <input class="form-control" type="text" id="usuario" name="usuario" value="<?= h($_POST['usuario'] ?? '') ?>" autofocus required>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password">Contraseña</label>
                    <div class="input-group">
                        <input class="form-control" type="password" id="password" name="password" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePass" title="Mostrar/ocultar"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button class="btn btn-primary w-100" type="submit" style="padding:.6rem;font-weight:600"><i class="bi bi-box-arrow-in-right me-2"></i>Ingresar</button>
                <div style="border-top:1px solid var(--border);margin:1rem 0 .25rem"></div>
                <a href="<?= h(url('scan/')) ?>" class="text-muted text-center d-block" style="font-size:12px;text-decoration:none"><i class="bi bi-phone me-1"></i>Ir a app de escaneo →</a>
            </form>
        </div>
        <p class="text-center text-muted" style="font-size:11px;margin-top:.75rem">powered by <strong>Trazock</strong> · v1.0</p>
    </div>
</div>
<script>
document.getElementById('togglePass').addEventListener('click', function () {
    const inp = document.getElementById('password');
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    this.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
});
</script>
</body>
</html>

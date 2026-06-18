<?php
declare(strict_types=1);

// =============================================================================
// index.php (raíz) — redirige al login del panel.
// login.php a su vez redirige según el rol (panel para admin/gestor, /scan/ para
// operador/transportista) si ya hay sesión activa.
// =============================================================================

require __DIR__ . '/lib/bootstrap.php';

header('Location: ' . url('admin/login.php'));
exit;

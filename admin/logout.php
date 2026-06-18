<?php
declare(strict_types=1);

// =============================================================================
// admin/logout.php — destruye la sesión y vuelve al login.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Auth;

Auth::logout();

header('Location: ' . url('admin/login.php'));
exit;

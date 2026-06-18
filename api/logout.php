<?php
declare(strict_types=1);

// =============================================================================
// POST /api/logout.php — destruye la sesión.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Api;
use Trazock\Auth;

Api::exigirMetodo('POST');

Auth::logout();

Api::json(['ok' => true], 200);

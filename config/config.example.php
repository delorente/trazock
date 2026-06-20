<?php
declare(strict_types=1);

// =============================================================================
// Database connection
// Copy this file to config/config.php and fill in your real values.
// config/config.php is gitignored and MUST NEVER be committed.
// =============================================================================

// Hostname or IP address of the MySQL/MariaDB server.
define('DB_HOST', 'localhost');

// TCP port for the database server. Default MySQL port is 3306.
define('DB_PORT', 3306);

// Name of the database schema to connect to.
define('DB_NAME', 'trazock');

// Database user with full privileges on DB_NAME.
define('DB_USER', 'root');

// Password for DB_USER. Use a strong password in production; leave empty only
// for local WAMP/MAMP development with the default root account.
define('DB_PASS', '');

// Character set for the connection. Always utf8mb4 to support full Unicode
// (including emoji and 4-byte characters). Do not change this value.
define('DB_CHARSET', 'utf8mb4');

// =============================================================================
// Application
// =============================================================================

// Public base URL of this app, WITHOUT a trailing slash.
// Examples:
//   Dev WAMP:    http://localhost/proyectos/trazock
//   Production:  https://trazock.example.com
// Used in redirects and asset paths throughout the codebase.
define('APP_URL', 'http://localhost/proyectos/trazock');

// Enable verbose error output and display_errors.
// Set to TRUE in development, FALSE in production.
define('APP_DEBUG', true);

// Apply debug flag to PHP error display.
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// =============================================================================
// Session
// =============================================================================

// Name of the session cookie. A project-specific name avoids collisions when
// multiple PHP apps share the same hostname (e.g., localhost under WAMP).
define('SESSION_NAME', 'trazock_session');

// Session idle lifetime in seconds. Default: 30 days (30 * 24 * 3600).
// If the user is inactive for longer than this, the session is invalidated.
// The cookie is re-emitted with a fresh Max-Age on every authenticated request
// (rolling expiry), so active users never get logged out.
define('SESSION_LIFETIME', 2592000); // 30 * 24 * 3600

// Set to TRUE when the app is served over HTTPS (production ALWAYS uses HTTPS).
// Set to FALSE for local HTTP development (e.g., WAMP without SSL).
// WARNING: setting this to FALSE in production means the session cookie will
// be sent over unencrypted connections — never do that in prod.
define('SESSION_COOKIE_SECURE', false); // true in production, false in HTTP dev

// =============================================================================
// Rate limiting (brute-force protection for login)
// =============================================================================

// Maximum number of failed login attempts from the same IP or for the same
// username within the RATE_LIMIT_WINDOW before the account is temporarily
// blocked. The 6th attempt triggers the lockout.
define('RATE_LIMIT_ATTEMPTS', 5);

// Window in seconds during which failed attempts are counted.
// Default: 900 seconds = 15 minutes.
// After this window passes, the attempt counter resets automatically.
define('RATE_LIMIT_WINDOW', 900); // 15 * 60

// =============================================================================
// Anthropic (Claude) — extracción OCR de hojas resumen
// =============================================================================

// API key de Anthropic (console.anthropic.com). NUNCA commitear la real.
define('ANTHROPIC_API_KEY', '');

// Modelo para la extracción. Opus 4.8 lee mejor los dígitos densos (recomendado);
// claude-sonnet-4-6 es más barato si la precisión alcanza.
define('ANTHROPIC_MODEL', 'claude-opus-4-8');

// =============================================================================
// Timezone
// =============================================================================

// Use UTC throughout PHP. The DB layer also sends SET time_zone='+00:00'.
// Display formatting for end users happens at the presentation layer.
date_default_timezone_set('UTC');

// Display timezone: timestamps are stored in UTC but shown to users converted to
// this zone (see the fmt_fecha() helper). Use a valid PHP timezone identifier.
define('DISPLAY_TZ', 'America/Argentina/Buenos_Aires');

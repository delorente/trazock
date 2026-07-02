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

// Modelo para la extracción. Sonnet 4.6 es preciso en los dígitos y barato
// (~centavos por hoja); es el recomendado. Subí a claude-opus-4-8 solo si hiciera
// falta más precisión (cuesta bastante más).
define('ANTHROPIC_MODEL', 'claude-sonnet-4-6');

// Bundle CA — SOLO en dev/Windows, donde el cURL de PHP no trae certificados.
// En producción (Linux) dejar comentado o vacío: usa el CA del sistema.
// define('ANTHROPIC_CA_BUNDLE', 'C:/wamp64/cacert.pem');

// =============================================================================
// Remitos firmados (fotos de entrega)
// =============================================================================
// Carpeta donde se guardan las fotos de remitos firmados que saca el
// transportista al entregar. Una sola carpeta (sin subcarpetas) para ubicarlas a
// mano fácil; los archivos llevan fecha + nº de orden en el nombre. En producción
// conviene una ruta FUERA del webroot (que no sea accesible por URL). Debe existir
// y ser escribible por el usuario web. Si se deja sin definir, usa
// <proyecto>/storage/remitos (protegida por .htaccess).
// define('REMITOS_DIR', '/home/intercongress.ar/storage/remitos');

// =============================================================================
// WhatsApp Business Cloud API (Meta) — aviso de entrega al cliente final
// =============================================================================
// Avisa al comprador antes de la entrega con la fecha/horario y dos botones
// (Confirmar / Reprogramar). Requiere una app de WhatsApp Cloud API en Meta con
// una plantilla aprobada. Ver docs/WHATSAPP.md para el trámite completo. Mientras
// estos valores estén vacíos, el panel muestra un aviso y NO intenta enviar.

// Token permanente (System User) de la app de WhatsApp. NUNCA commitear el real.
define('WA_TOKEN', '');

// ID del número emisor (Phone Number ID, NO el número en sí).
define('WA_PHONE_NUMBER_ID', '');

// Nombre EXACTO de la plantilla aprobada (con 2 botones quick-reply).
define('WA_TEMPLATE', 'aviso_entrega');

// Código de idioma de la plantilla (debe coincidir con el aprobado en Meta).
define('WA_LANG', 'es_AR');

// Versión de Graph API.
define('WA_API_VER', 'v21.0');

// Token de verificación del webhook (lo elegís vos y lo cargás también en Meta).
define('WA_VERIFY_TOKEN', '');

// App Secret de la app de Meta — valida la firma X-Hub-Signature-256 del webhook.
// Si queda vacío, NO se exige firma (solo para pruebas locales).
define('WA_APP_SECRET', '');

// =============================================================================
// Geocoding / secuenciación de rutas (feature D)
// =============================================================================
// Convierte la dirección de destino en coordenada (lat/lng) para ubicar las
// paradas en el mapa y sugerir el orden del recorrido. El geocoding corre
// DIFERIDO (scripts/geocodificar.php), nunca en una página. Todas estas claves
// son OPCIONALES: si no se definen, el código usa los defaults de abajo.

// Proveedor activo. Hoy solo 'nominatim' (OSM, gratis; su licencia permite
// almacenar las coordenadas). Al sumar Mapbox/Google se agrega el driver y se
// cambia acá, sin tocar nada más.
// define('GEOCODER_DRIVER', 'nominatim');

// Base del servicio Nominatim. Por defecto usa el público de OSM. Para volumen
// alto conviene tu propia instancia o un mirror con más cupo.
// define('GEOCODER_NOMINATIM_URL', 'https://nominatim.openstreetmap.org');

// User-Agent identificatorio. Nominatim EXIGE identificarse con un contacto real
// (mail). Si no se define, se deriva uno de APP_URL (aceptable para bajo volumen).
// define('GEOCODER_USER_AGENT', 'Trazock/1.0 (tucorreo@ejemplo.com)');

// Origen del recorrido (depósito) para la sugerencia de orden de paradas. Se usa
// en la FASE 2 (pantalla de planificación); no hace falta para geocodificar.
// define('DEPOT_LAT', -34.6037);
// define('DEPOT_LNG', -58.3816);

// =============================================================================
// Superadmin protegido (acceso de emergencia)
// =============================================================================
// Nombre de usuario que el panel NO permite editar, desactivar ni borrar — tu
// acceso garantizado si alguien cambia la clave del admin. Es un usuario admin
// normal en la base (lo creás con scripts/crear-admin.php) pero blindado en la UI.
// Dejalo comentado para desactivar la protección. El valor real va en
// config/config.php (gitignored), no en este ejemplo.
// define('SUPERADMIN_USER', 'dev');

// =============================================================================
// Timezone
// =============================================================================

// Use UTC throughout PHP. The DB layer also sends SET time_zone='+00:00'.
// Display formatting for end users happens at the presentation layer.
date_default_timezone_set('UTC');

// Display timezone: timestamps are stored in UTC but shown to users converted to
// this zone (see the fmt_fecha() helper). Use a valid PHP timezone identifier.
define('DISPLAY_TZ', 'America/Argentina/Buenos_Aires');

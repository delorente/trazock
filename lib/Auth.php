<?php
declare(strict_types=1);

namespace Trazock;

use Trazock\Models\Usuario;

final class Auth
{
    // -------------------------------------------------------------------------
    // Session bootstrap
    // -------------------------------------------------------------------------

    /**
     * Configure session cookie parameters and start the session.
     * Safe to call multiple times — no-op if session is already active.
     */
    public static function iniciarSesion(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Align PHP GC with the cookie lifetime.
        ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
        ini_set('session.use_strict_mode', '1');

        session_name(SESSION_NAME);

        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => SESSION_COOKIE_SECURE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    // -------------------------------------------------------------------------
    // Authentication
    // -------------------------------------------------------------------------

    /**
     * Attempt to authenticate a user.
     *
     * Flow:
     *   1. Check rate limit (by IP OR usuario, failed attempts in last RATE_LIMIT_WINDOW seconds).
     *   2. Look up the active user.
     *   3. Verify password.
     *   4. On success: clear failed attempts, regenerate session ID, populate $_SESSION.
     *
     * @return bool True on successful authentication, false on any failure.
     */
    public static function login(string $usuario, string $password): bool
    {
        self::iniciarSesion();

        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (self::rateLimited($ip, $usuario)) {
            self::registrarIntento($ip, $usuario, false);
            return false;
        }

        $user = Usuario::findByUsuarioActivo($usuario);

        if ($user === null || !password_verify($password, $user['password_hash'])) {
            self::registrarIntento($ip, $usuario, false);
            return false;
        }

        // Credentials verified — open the authenticated session.
        self::limpiarIntentos($ip, $usuario);

        session_regenerate_id(true);

        $_SESSION['user'] = [
            'id'             => $user['id'],
            'usuario'        => $user['usuario'],
            'nombre_completo'=> $user['nombre_completo'],
            'rol'            => $user['rol'],
            'last_activity'  => time(),
        ];

        // Rotate CSRF token on login so pre-auth and post-auth tokens differ.
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        // Emit the rolling cookie from this initial login.
        self::_emitirCookie();

        self::registrarIntento($ip, $usuario, true);

        return true;
    }

    /**
     * Destroy the active session and clear the session cookie.
     */
    public static function logout(): void
    {
        self::iniciarSesion();

        $_SESSION = [];
        session_destroy();

        // Expire the cookie immediately.
        setcookie(SESSION_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => SESSION_COOKIE_SECURE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    // -------------------------------------------------------------------------
    // Session validation (rolling expiry)
    // -------------------------------------------------------------------------

    /**
     * Validate the current session and refresh the rolling cookie.
     *
     * Returns the user data array if the session is valid and not expired,
     * or null if there is no session or it has been idle past SESSION_LIFETIME.
     *
     * @return array<string, mixed>|null
     */
    public static function validarSesion(): ?array
    {
        self::iniciarSesion();

        if (!isset($_SESSION['user'])) {
            return null;
        }

        $now  = time();
        $last = (int)($_SESSION['user']['last_activity'] ?? 0);

        if ($now - $last > SESSION_LIFETIME) {
            self::logout();
            return null;
        }

        // Update idle timestamp and re-emit cookie with fresh Max-Age.
        $_SESSION['user']['last_activity'] = $now;
        self::_emitirCookie();

        return $_SESSION['user'];
    }

    /**
     * Return the current user data without renewing the session.
     * Returns null if no active session exists.
     *
     * @return array<string, mixed>|null
     */
    public static function usuarioActual(): ?array
    {
        self::iniciarSesion();
        return $_SESSION['user'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Authorization
    // -------------------------------------------------------------------------

    /**
     * Require an authenticated session regardless of role.
     * Redirects to the login page if no session is active.
     */
    public static function requiereLogin(): void
    {
        if (self::validarSesion() === null) {
            header('Location: ' . APP_BASE . '/admin/login.php');
            exit;
        }
    }

    /**
     * Require an authenticated session with one of the specified roles.
     *
     * - No session        → redirect to login.php (UX: user simply isn't logged in)
     * - Wrong role        → HTTP 403 with a minimal inline page (security: unauthorized)
     *
     * Execution always stops (exit) after this method returns false.
     *
     * @param string|string[] $roles
     */
    public static function requiereRol(string|array $roles): void
    {
        $user = self::validarSesion();

        if ($user === null) {
            header('Location: ' . APP_BASE . '/admin/login.php');
            exit;
        }

        $allowed = is_array($roles) ? $roles : [$roles];

        if (!in_array($user['rol'], $allowed, true)) {
            http_response_code(403);
            self::paginaError(
                '403',
                'Acceso denegado',
                'Tu rol (<strong>' . htmlspecialchars($user['rol'], ENT_QUOTES, 'UTF-8')
                . '</strong>) no tiene permiso para acceder a este recurso.'
            );
            exit;
        }
    }

    /**
     * Exigir acceso al panel web (/admin/).
     *
     * - Sin sesión                 → redirige a login.php
     * - operador / transportista   → no tienen panel: redirige a /scan/
     * - admin / gestor             → devuelve los datos del usuario
     *
     * @return array<string, mixed>
     */
    public static function requierePanel(array $roles = ['admin']): array
    {
        $user = self::validarSesion();

        if ($user === null) {
            header('Location: ' . APP_BASE . '/admin/login.php');
            exit;
        }

        $rol = (string)$user['rol'];

        if (in_array($rol, ['operador', 'transportista'], true)) {
            // Roles de la app de escaneo: nunca entran al panel.
            header('Location: ' . APP_BASE . '/scan/');
            exit;
        }

        if (!in_array($rol, $roles, true)) {
            // Rol de panel sin permiso para ESTA página → a su pantalla inicial.
            $home = self::homeDe($rol);
            header('Location: ' . APP_BASE . '/' . $home);
            exit;
        }

        return $user;
    }

    /** Pantalla inicial del panel según el rol (a la que tiene acceso). */
    public static function homeDe(string $rol): string
    {
        return match ($rol) {
            'gestor'   => 'admin/ordenes-reportes.php',
            'contable' => 'admin/costos.php',
            default    => 'admin/index.php', // admin, logistica
        };
    }

    /**
     * El supervisor (rol gestor) ve todo pero NO edita: solo lectura en el panel.
     *
     * @param array<string, mixed> $user
     */
    public static function esSoloLectura(array $user): bool
    {
        return (string)($user['rol'] ?? '') === 'gestor';
    }

    /**
     * ¿Es el "superadmin protegido" (config: SUPERADMIN_USER)? Ese usuario no se
     * puede editar, desactivar ni borrar desde el panel — es el acceso de emergencia
     * del dueño. Si SUPERADMIN_USER no está definido/está vacío, no hay superadmin.
     */
    public static function esSuperadmin(?string $usuario): bool
    {
        $su = defined('SUPERADMIN_USER') ? trim((string)SUPERADMIN_USER) : '';
        return $su !== '' && $usuario !== null && strcasecmp(trim($usuario), $su) === 0;
    }

    /**
     * Renderiza una página de error autocontenida con el tema oscuro.
     * No usa _layout.php para no filtrar el menú a usuarios sin permiso.
     */
    public static function paginaError(string $codigo, string $titulo, string $mensajeHtml): void
    {
        $base = htmlspecialchars(APP_BASE, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</title>'
           . '<link rel="stylesheet" href="' . $base . '/assets/vendor/inter/inter.css">'
           . '<link rel="stylesheet" href="' . $base . '/assets/vendor/bootstrap/bootstrap.min.css">'
           . '<link rel="stylesheet" href="' . $base . '/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">'
           . '<link rel="stylesheet" href="' . $base . '/assets/css/app.css">'
           . '</head><body><div style="display:flex;align-items:center;justify-content:center;min-height:100vh;padding:1rem">'
           . '<div class="card p-4 text-center" style="max-width:400px">'
           . '<div style="font-size:3rem;line-height:1"><i class="bi bi-shield-lock-fill" style="color:var(--red)"></i></div>'
           . '<div style="font-size:2.5rem;font-weight:700;margin-top:.5rem">' . htmlspecialchars($codigo, ENT_QUOTES, 'UTF-8') . '</div>'
           . '<div class="sec-title mb-2">' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</div>'
           . '<p class="text-muted" style="font-size:13px">' . $mensajeHtml . '</p>'
           . '<div class="d-flex gap-2 justify-content-center mt-2">'
           . '<a class="btn btn-primary btn-sm" href="' . $base . '/admin/index.php"><i class="bi bi-house me-1"></i>Inicio</a>'
           . '<a class="btn btn-outline-secondary btn-sm" href="' . $base . '/admin/logout.php"><i class="bi bi-box-arrow-left me-1"></i>Cerrar sesión</a>'
           . '</div></div></div></body></html>';
    }

    // -------------------------------------------------------------------------
    // CSRF
    // -------------------------------------------------------------------------

    /**
     * Return the CSRF token for the current session, generating one if needed.
     */
    public static function tokenCSRF(): string
    {
        self::iniciarSesion();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    /**
     * Validate a CSRF token using a constant-time comparison.
     */
    public static function validarCSRF(?string $token): bool
    {
        self::iniciarSesion();

        if (!is_string($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the IP or username has exceeded the rate limit.
     *
     * Counts failed attempts in the last RATE_LIMIT_WINDOW seconds.
     * Uses OR so both credential-stuffing (one IP, many users) and
     * distributed attacks (many IPs, one user) are caught.
     */
    private static function rateLimited(string $ip, string $usuario): bool
    {
        $stmt = DB::getInstance()->prepare(
            'SELECT COUNT(*) FROM intentos_login
             WHERE (ip = :ip OR usuario = :usuario)
               AND exito = 0
               AND fecha > DATE_SUB(NOW(), INTERVAL :window SECOND)'
        );
        $stmt->execute([
            ':ip'      => $ip,
            ':usuario' => $usuario,
            ':window'  => RATE_LIMIT_WINDOW,
        ]);
        return (int)$stmt->fetchColumn() >= RATE_LIMIT_ATTEMPTS;
    }

    /**
     * Record a login attempt (successful or not).
     */
    private static function registrarIntento(string $ip, ?string $usuario, bool $exito): void
    {
        $stmt = DB::getInstance()->prepare(
            'INSERT INTO intentos_login (ip, usuario, fecha, exito)
             VALUES (:ip, :usuario, NOW(), :exito)'
        );
        $stmt->execute([
            ':ip'      => $ip,
            ':usuario' => $usuario,
            ':exito'   => (int)$exito,
        ]);
    }

    /**
     * Delete all failed attempts for the given IP+username pair.
     * Called only after a successful password_verify() to reset the rate-limit window.
     */
    private static function limpiarIntentos(string $ip, string $usuario): void
    {
        $stmt = DB::getInstance()->prepare(
            'DELETE FROM intentos_login
             WHERE ip = :ip AND usuario = :usuario AND exito = 0'
        );
        $stmt->execute([
            ':ip'      => $ip,
            ':usuario' => $usuario,
        ]);
    }

    /**
     * Re-emit the session cookie with a fresh Max-Age (rolling expiry).
     *
     * PHP's session module only sets the cookie at session_start() time; the
     * browser's Max-Age does not refresh automatically. This explicit setcookie()
     * call resets the expiry on every authenticated request.
     */
    private static function _emitirCookie(): void
    {
        setcookie(SESSION_NAME, session_id(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => SESSION_COOKIE_SECURE,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

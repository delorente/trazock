<?php
declare(strict_types=1);

namespace Trazock;

/**
 * Helpers comunes para los endpoints JSON de /api/.
 */
final class Api
{
    /**
     * Emite una respuesta JSON y corta la ejecución.
     *
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Error JSON estándar {ok:false, error:...}.
     */
    public static function error(string $mensaje, int $status = 400): never
    {
        self::json(['ok' => false, 'error' => $mensaje], $status);
    }

    /**
     * Devuelve el usuario autenticado o corta con 401 JSON.
     *
     * @return array<string, mixed>
     */
    public static function usuarioAutenticado(): array
    {
        $user = Auth::validarSesion();
        if ($user === null) {
            self::error('No autenticado. Iniciá sesión.', 401);
        }
        return $user;
    }

    /**
     * Devuelve el usuario autenticado y verifica que tenga uno de los roles.
     * Corta con 401 si no hay sesión, 403 si el rol no está permitido.
     *
     * @param string[] $roles
     * @return array<string, mixed>
     */
    public static function usuarioConRol(array $roles): array
    {
        $user = self::usuarioAutenticado();
        if (!in_array($user['rol'], $roles, true)) {
            self::error('No tenés permiso para esta acción.', 403);
        }
        return $user;
    }

    /**
     * Valida el token CSRF del cuerpo JSON (acciones del panel). Corta con 403 si falla.
     *
     * @param array<string, mixed> $data
     */
    public static function exigirCSRF(array $data): void
    {
        if (!Auth::validarCSRF((string)($data['csrf_token'] ?? ''))) {
            self::error('Token CSRF inválido. Recargá la página.', 403);
        }
    }

    /**
     * Exige un método HTTP; corta con 405 si no coincide.
     */
    public static function exigirMetodo(string $metodo): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
            self::error("Método no permitido. Usá {$metodo}.", 405);
        }
    }

    /**
     * Lee y decodifica el cuerpo JSON del request.
     *
     * @return array<string, mixed>
     */
    public static function leerJson(): array
    {
        $raw  = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            self::error('Cuerpo de la solicitud inválido: se esperaba JSON.', 400);
        }
        return $data;
    }
}

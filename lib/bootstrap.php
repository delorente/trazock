<?php
declare(strict_types=1);

// =============================================================================
// lib/bootstrap.php — punto de entrada común para todas las páginas y endpoints.
//
// Carga configuración, el autoloader de Composer (PSR-4 Trazock\ + helpers),
// y deja la sesión lista. Incluir SIEMPRE como primera línea de cada página:
//
//     require __DIR__ . '/../lib/bootstrap.php';
//
// =============================================================================

$root = dirname(__DIR__);

require $root . '/config/config.php';
require $root . '/vendor/autoload.php';

// Raíz absoluta del proyecto, disponible para require de plantillas.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}

// Ruta base de la app (componente path de APP_URL, sin barra final).
// Las URLs se construyen relativas al host actual usando esta base, de modo que
// la app funciona igual servida por localhost, una IP de LAN o un túnel (ngrok)
// sin tener que editar APP_URL. Ej.: APP_URL=http://localhost/proyectos/trazock
// → APP_BASE=/proyectos/trazock
if (!defined('APP_BASE')) {
    define('APP_BASE', rtrim((string)(parse_url(APP_URL, PHP_URL_PATH) ?? ''), '/'));
}

// =============================================================================
// Headers de seguridad (Fase 7).
// Se emiten vía PHP para no depender de mod_headers de Apache. En producción
// Nginx también los puede setear (ver config/nginx.conf.example).
//   - CSP estricta de origen propio; 'unsafe-inline' por los scripts/handlers
//     inline del panel y la app de escaneo. media-src 'self' blob: para la cámara.
//   - Permissions-Policy habilita la cámara sólo en el propio origen.
// =============================================================================
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data:; media-src 'self' blob:; "
        . "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; "
        . "connect-src 'self'; worker-src 'self'; manifest-src 'self'; "
        . "frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
    );
}

/**
 * URL (relativa al host) de un asset estático bajo la base de la app.
 * Uso: asset('assets/vendor/bootstrap/bootstrap.min.css')
 *
 * Agrega ?v=<mtime> para cache-busting: cuando el archivo cambia (deploy), la
 * URL cambia y el navegador baja la versión nueva sin tener que hacer F5. El SW
 * del escáner es cache-first por URL completa, así que esto también le sirve.
 */
if (!function_exists('asset')) {
    function asset(string $path): string
    {
        $rel = ltrim($path, '/');
        $url = APP_BASE . '/' . $rel;
        $fs  = dirname(__DIR__) . '/' . $rel;
        if (is_file($fs)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'v=' . filemtime($fs);
        }
        return $url;
    }
}

/**
 * URL (relativa al host) de una ruta de la app.
 * Uso: url('admin/index.php')
 */
if (!function_exists('url')) {
    function url(string $path = ''): string
    {
        return APP_BASE . '/' . ltrim($path, '/');
    }
}

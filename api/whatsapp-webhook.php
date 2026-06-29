<?php
declare(strict_types=1);

// =============================================================================
// /api/whatsapp-webhook.php — webhook de WhatsApp Business Cloud API.
//
// PÚBLICO (Meta lo invoca). NO usa sesión ni CSRF: se protege con
//   · GET  verificación: responde hub.challenge si hub.verify_token == WA_VERIFY_TOKEN
//   · POST firma: valida X-Hub-Signature-256 (HMAC-SHA256 con WA_APP_SECRET)
//
// En el POST, cada respuesta de botón (Confirmar/Reprogramar) referencia por
// context.id el mensaje que enviamos (wa_message_id). Casamos esa fila en
// confirmaciones_entrega y fijamos la respuesta. REPROGRAMAR ⇒ marca la orden
// 'no_entregar' (el escáner ya avisa al intentar entregarla).
//
// Responde 200 siempre que la firma sea válida (aunque el evento no nos interese):
// Meta reintenta ante cualquier no-2xx.
// =============================================================================

require __DIR__ . '/../lib/bootstrap.php';

use Trazock\Whatsapp;
use Trazock\Models\ConfirmacionEntrega;
use Trazock\Models\Orden;

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// --- Verificación del webhook (configuración inicial en Meta) -----------------
if ($metodo === 'GET') {
    $mode      = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $token     = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $esperado  = Whatsapp::verifyToken();

    if ($mode === 'subscribe' && $esperado !== '' && hash_equals($esperado, $token)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

if ($metodo !== 'POST') {
    http_response_code(405);
    exit;
}

// --- Recepción de eventos -----------------------------------------------------
$raw = file_get_contents('php://input') ?: '';
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;

if (!Whatsapp::firmaValida($raw, $sig)) {
    http_response_code(403);
    echo 'Invalid signature';
    exit;
}

// Pase lo que pase de acá en más, contestamos 200: ya validamos el origen.
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo '{"ok":true}';

// El procesamiento real puede seguir tras cerrar la respuesta.
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    exit;
}

/** Normaliza el botón a 'confirmado' | 'reprogramado' | null. */
function confent_resolver_respuesta(string $texto): ?string
{
    $t = mb_strtolower(trim($texto));
    if ($t === '') {
        return null;
    }
    if (str_contains($t, 'reprog')) {
        return 'reprogramado';
    }
    if (str_contains($t, 'confirm')) {
        return 'confirmado';
    }
    return null;
}

try {
    foreach (($data['entry'] ?? []) as $entry) {
        foreach (($entry['changes'] ?? []) as $change) {
            $value = $change['value'] ?? [];
            foreach (($value['messages'] ?? []) as $msg) {
                $ctxId = (string)($msg['context']['id'] ?? '');
                if ($ctxId === '') {
                    continue; // no es respuesta a un mensaje nuestro
                }

                // Botón de plantilla (quick-reply) → type 'button'.
                // Botón interactivo → type 'interactive' + button_reply.
                $texto = '';
                if (($msg['type'] ?? '') === 'button') {
                    $texto = (string)($msg['button']['text'] ?? $msg['button']['payload'] ?? '');
                } elseif (($msg['type'] ?? '') === 'interactive') {
                    $br = $msg['interactive']['button_reply'] ?? [];
                    $texto = (string)($br['title'] ?? $br['id'] ?? '');
                }

                $respuesta = confent_resolver_respuesta($texto);
                if ($respuesta === null) {
                    continue;
                }

                $ordenId = ConfirmacionEntrega::marcarRespuesta($ctxId, $respuesta);
                if ($ordenId !== null && $respuesta === 'reprogramado') {
                    Orden::setMarca($ordenId, 'no_entregar');
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('whatsapp-webhook.php: ' . $e->getMessage());
}
exit;

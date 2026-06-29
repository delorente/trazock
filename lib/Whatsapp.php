<?php
declare(strict_types=1);

namespace Trazock;

/**
 * Whatsapp — cliente mínimo de WhatsApp Business Cloud API (Meta / Graph API).
 *
 * Envía mensajes de PLANTILLA (los únicos permitidos para iniciar conversación
 * fuera de la ventana de 24 h) con tres variables de cuerpo y dos botones de
 * respuesta rápida que la propia plantilla define (Confirmar / Reprogramar). Las
 * respuestas a esos botones llegan por separado al webhook (api/whatsapp-webhook.php).
 *
 * Config-driven (config/config.php, gitignored):
 *   WA_TOKEN            token permanente del System User / app
 *   WA_PHONE_NUMBER_ID  id del número emisor
 *   WA_TEMPLATE         nombre de la plantilla aprobada
 *   WA_LANG             código de idioma de la plantilla (ej. 'es_AR' o 'es')
 *   WA_API_VER          versión de Graph API (ej. 'v21.0')
 *   WA_VERIFY_TOKEN     token de verificación del webhook (GET hub.verify_token)
 *   WA_APP_SECRET       app secret para validar la firma X-Hub-Signature-256
 * Sin estas claves, enviarPlantilla() lanza RuntimeException con un mensaje claro.
 */
final class Whatsapp
{
    private const TIMEOUT_S = 20;

    /** ¿Está configurado el envío (token + número + plantilla)? */
    public static function configurado(): bool
    {
        return self::cfg('WA_TOKEN') !== ''
            && self::cfg('WA_PHONE_NUMBER_ID') !== ''
            && self::cfg('WA_TEMPLATE') !== '';
    }

    /** Lee una constante de config como string ('' si no está definida/vacía). */
    private static function cfg(string $name, string $default = ''): string
    {
        return defined($name) && constant($name) !== '' ? (string)constant($name) : $default;
    }

    /**
     * Envía la plantilla de aviso de entrega a un teléfono E.164.
     *
     * @param string $telE164 destino sin '+': 549XXXXXXXXXX
     * @param array{0:string,1:string,2:string} $vars cuerpo {{1}},{{2}},{{3}} (producto, fecha, horario)
     * @return string id del mensaje saliente (wamid…) para casar la respuesta del webhook
     * @throws \RuntimeException si falta config o la API responde error
     */
    public static function enviarPlantilla(string $telE164, array $vars): string
    {
        if (!self::configurado()) {
            throw new \RuntimeException('WhatsApp no está configurado (faltan WA_TOKEN / WA_PHONE_NUMBER_ID / WA_TEMPLATE en config.php).');
        }
        $ver   = self::cfg('WA_API_VER', 'v21.0');
        $phone = self::cfg('WA_PHONE_NUMBER_ID');
        $lang  = self::cfg('WA_LANG', 'es');

        $body = [
            'messaging_product' => 'whatsapp',
            'to'                => $telE164,
            'type'              => 'template',
            'template'          => [
                'name'     => self::cfg('WA_TEMPLATE'),
                'language' => ['code' => $lang],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => array_map(
                        static fn(string $v): array => ['type' => 'text', 'text' => $v],
                        array_values($vars)
                    ),
                ]],
            ],
        ];

        $url = "https://graph.facebook.com/{$ver}/{$phone}/messages";
        $resp = self::post($url, $body, self::cfg('WA_TOKEN'));

        $id = $resp['messages'][0]['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('WhatsApp aceptó la solicitud pero no devolvió id de mensaje.');
        }
        return $id;
    }

    /**
     * Verifica la firma del webhook (X-Hub-Signature-256) contra WA_APP_SECRET.
     * Si no hay app secret configurado, devuelve true (modo dev/sin verificación).
     */
    public static function firmaValida(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = self::cfg('WA_APP_SECRET');
        if ($secret === '') {
            return true; // sin secret configurado no se exige firma (dev)
        }
        $sig = (string)$signatureHeader;
        if (!str_starts_with($sig, 'sha256=')) {
            return false;
        }
        $esperado = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($esperado, $sig);
    }

    /** Token esperado en la verificación GET del webhook. */
    public static function verifyToken(): string
    {
        return self::cfg('WA_VERIFY_TOKEN');
    }

    /**
     * POST JSON con Bearer token. Mismo patrón de cURL + CA bundle que ExtractorOcr.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function post(string $url, array $body, string $token): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT_S,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Expect:',
            ],
            CURLOPT_POSTFIELDS     => $json,
        ]);
        // CA bundle (dev/Windows): mismo criterio que la integración con Anthropic.
        $ca = (defined('ANTHROPIC_CA_BUNDLE') && ANTHROPIC_CA_BUNDLE !== '')
            ? ANTHROPIC_CA_BUNDLE
            : (is_file(__DIR__ . '/../config/cacert.pem') ? __DIR__ . '/../config/cacert.pem' : '');
        if ($ca !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $raw  = curl_exec($ch);
        $errno = curl_errno($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Error de red llamando a WhatsApp [errno ' . $errno . ($err !== '' ? " ({$err})" : '') . '].');
        }
        $data = json_decode((string)$raw, true);
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) ? ($data['error']['message'] ?? (string)$raw) : (string)$raw;
            throw new \RuntimeException("WhatsApp HTTP {$code}: " . $msg);
        }
        return $data;
    }
}

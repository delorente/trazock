<?php
declare(strict_types=1);

namespace Trazock;

use RuntimeException;

/**
 * ExtractorOcr — extrae las órdenes de una hoja resumen (imagen) usando la API
 * de Anthropic (Claude) con visión + salida estructurada (JSON garantizado por
 * esquema). Se llama por hoja: imagen → ['ordenes' => [...]].
 *
 * El resultado alimenta el borrador editable de la carga; la planilla de revisión
 * lo corrige antes de confirmar. Usa cURL directo contra /v1/messages (una sola
 * llamada autocontenida; sin SDK para no sumar dependencias en el hosting).
 * Requiere ANTHROPIC_API_KEY en config.php.
 */
final class ExtractorOcr
{
    private const ENDPOINT  = 'https://api.anthropic.com/v1/messages';
    private const VERSION   = '2023-06-01';
    private const MAX_LADO  = 2576;   // px lado largo (visión de alta resolución, Opus 4.7+)
    private const TIMEOUT_S = 180;

    private const PROMPT_SISTEMA = <<<'TXT'
Sos un asistente experto en extraer datos de HOJAS DE RUTA / RESÚMENES DE REMITOS de
Simmons (logística de colchones). Cada hoja lista varias ÓRDENES (una por remito).

Por cada orden extraé:
- nro_orden: aparece como "VLO 0775-XXXXXXXX" o similar. Devolvé solo el número con guion (ej. "0775-00312689").
- nro_remito: aparece como "RMC 0328-XXXXXXXX"; puede terminar en una letra (ej. "0328-00993502R").
- fecha_remito: en formato AAAA-MM-DD.
- cliente: nombre completo.
- telefonos: el o los teléfonos (si hay más de uno, separalos con " / ").
- dest_cp, dest_domicilio, dest_localidad, dest_provincia: del campo ENTREGA.
- valor_declarado: el monto con "$", como número (sin separadores de miles).
- items: cada línea de producto, con:
    - codigo: el código tabulado completo (ej. "1RA-SM-CO-BLT-HTL 022 ML-SCF").
    - dimensiones: lo que está entre corchetes (ej. "190X140X00X00").
    - cantidad: entero.
    - m3: número, o null si no figura.

REGLAS CRÍTICAS:
- Leé los NÚMEROS con máximo cuidado, dígito por dígito (orden, remito, teléfono, m³).
  Son lo más importante y no tienen contexto que ayude: NO inventes ni completes dígitos.
- NO incluyas la fila "SUBTOTAL" como ítem.
- Si un dato no está, devolvé null.
- Devolvé estrictamente según el esquema (no agregues texto fuera del JSON).
TXT;

    /**
     * Extrae las órdenes de una hoja resumen: imagen (jpeg/png) o PDF (una o
     * varias páginas). El PDF se manda nativo como bloque `document` (Claude lee
     * cada página); la imagen se normaliza a JPEG con GD.
     *
     * @param string $bytes Bytes binarios del archivo subido.
     * @return array{ordenes: array<int, array<string, mixed>>}
     * @throws RuntimeException
     */
    public static function extraerHoja(string $bytes): array
    {
        if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
            throw new RuntimeException('Falta ANTHROPIC_API_KEY en config.php.');
        }
        $modelo = (defined('ANTHROPIC_MODEL') && ANTHROPIC_MODEL !== '') ? ANTHROPIC_MODEL : 'claude-sonnet-4-6';

        // %PDF en los primeros bytes → documento PDF (puede ser multipágina).
        if (strncmp($bytes, '%PDF', 4) === 0) {
            $fuente = ['type' => 'document', 'source' => [
                'type' => 'base64', 'media_type' => 'application/pdf', 'data' => base64_encode($bytes),
            ]];
            $instruccion = 'Extraé TODAS las órdenes de este documento (puede tener varias páginas u hojas), con sus ítems.';
        } else {
            $jpeg = self::normalizarImagen($bytes);
            $fuente = ['type' => 'image', 'source' => [
                'type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode($jpeg),
            ]];
            $instruccion = 'Extraé TODAS las órdenes de esta hoja resumen, con sus ítems.';
        }

        $body = [
            'model'      => $modelo,
            'max_tokens' => 16000,
            'system'     => self::PROMPT_SISTEMA,
            'messages'   => [[
                'role'    => 'user',
                'content' => [
                    $fuente,
                    ['type' => 'text', 'text' => $instruccion],
                ],
            ]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
        ];

        $resp = self::llamar($body);

        if (($resp['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('La API rechazó la solicitud (refusal).');
        }

        // Salida estructurada: el bloque de texto trae el JSON válido del esquema.
        $texto = null;
        foreach (($resp['content'] ?? []) as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $texto = (string)($bloque['text'] ?? '');
                break;
            }
        }
        if ($texto === null || $texto === '') {
            $sr = $resp['stop_reason'] ?? '?';
            throw new RuntimeException("La API no devolvió contenido extraíble (stop_reason={$sr}).");
        }

        $datos = json_decode($texto, true);
        if (!is_array($datos) || !isset($datos['ordenes']) || !is_array($datos['ordenes'])) {
            throw new RuntimeException('La salida no tiene el formato esperado.');
        }
        return $datos;
    }

    /**
     * Reescala la imagen al lado largo máximo (para alta resolución sin exceder
     * límites de la API) y la re-codifica a JPEG. Requiere la extensión gd.
     */
    private static function normalizarImagen(string $bytes): string
    {
        if (!function_exists('imagecreatefromstring')) {
            // Sin gd: enviamos los bytes tal cual (debe ser jpeg/png válido).
            return $bytes;
        }
        $img = @imagecreatefromstring($bytes);
        if ($img === false) {
            throw new RuntimeException('No se pudo leer la imagen (formato no soportado).');
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $lado = max($w, $h);
        if ($lado > self::MAX_LADO) {
            $escala = self::MAX_LADO / $lado;
            $nw = max(1, (int)round($w * $escala));
            $nh = max(1, (int)round($h * $escala));
            $red = imagescale($img, $nw, $nh);
            if ($red !== false) {
                imagedestroy($img);
                $img = $red;
            }
        }
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string)ob_get_clean();
        imagedestroy($img);
        return $jpeg;
    }

    /**
     * POST a /v1/messages. Devuelve el cuerpo decodificado.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function llamar(array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Reintento ante fallo de transporte: en algunos servidores (libcurl vieja /
        // proxies) curl_exec() devuelve false de forma intermitente en POST grandes.
        $raw   = false;
        $code  = 0;
        $detalle = '';
        for ($intento = 1; $intento <= 2; $intento++) {
            $ch = curl_init(self::ENDPOINT);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT_S,
                CURLOPT_HTTPHEADER     => [
                    'x-api-key: ' . ANTHROPIC_API_KEY,
                    'anthropic-version: ' . self::VERSION,
                    'content-type: application/json',
                    // Desactiva el handshake 'Expect: 100-continue', que con POST grandes
                    // y libcurl viejas puede colgar/cortar la conexión sin error claro.
                    'Expect:',
                ],
                CURLOPT_POSTFIELDS     => $json,
            ]);
            // CA bundle para verificar el TLS. Orden de preferencia: el definido por
            // config (dev/Windows), o el bundle propio versionado con la app
            // (config/cacert.pem). Esto evita errno 60/77 (CACERT_BADFILE) cuando el CA
            // del sistema no es legible para el PHP de la web. Si no hay ninguno, usa el
            // del sistema.
            $ca = (defined('ANTHROPIC_CA_BUNDLE') && ANTHROPIC_CA_BUNDLE !== '')
                ? ANTHROPIC_CA_BUNDLE
                : (is_file(__DIR__ . '/../config/cacert.pem') ? __DIR__ . '/../config/cacert.pem' : '');
            if ($ca !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
            }
            $raw   = curl_exec($ch);
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw !== false) {
                break; // hubo respuesta HTTP (aunque sea 4xx/5xx): se maneja abajo
            }
            $detalle = "errno {$errno}" . ($err !== '' ? " ({$err})" : '');
            if ($intento < 2) {
                usleep(600000); // 0.6 s antes de reintentar
            }
        }

        if ($raw === false) {
            throw new RuntimeException('Error de red llamando a la API [' . $detalle . '].');
        }
        $data = json_decode((string)$raw, true);
        if ($code >= 400 || !is_array($data)) {
            $msg = is_array($data) ? ($data['error']['message'] ?? (string)$raw) : (string)$raw;
            throw new RuntimeException("API HTTP {$code}: " . $msg);
        }
        return $data;
    }

    /**
     * Esquema JSON de salida (una orden por elemento de `ordenes`).
     * Campos opcionales como anyOf[..,null]; objetos con additionalProperties:false.
     *
     * @return array<string, mixed>
     */
    private static function esquema(): array
    {
        $nul = static fn(string $t): array => ['anyOf' => [['type' => $t], ['type' => 'null']]];

        $item = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['codigo', 'dimensiones', 'cantidad', 'm3'],
            'properties' => [
                'codigo'      => ['type' => 'string'],
                'dimensiones' => $nul('string'),
                'cantidad'    => ['type' => 'integer'],
                'm3'          => $nul('number'),
            ],
        ];

        $orden = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'nro_orden', 'nro_remito', 'fecha_remito', 'cliente', 'telefonos',
                'dest_cp', 'dest_domicilio', 'dest_localidad', 'dest_provincia',
                'valor_declarado', 'items',
            ],
            'properties' => [
                'nro_orden'       => ['type' => 'string'],
                'nro_remito'      => $nul('string'),
                'fecha_remito'    => $nul('string'),
                'cliente'         => ['type' => 'string'],
                'telefonos'       => $nul('string'),
                'dest_cp'         => $nul('string'),
                'dest_domicilio'  => $nul('string'),
                'dest_localidad'  => $nul('string'),
                'dest_provincia'  => $nul('string'),
                'valor_declarado' => $nul('number'),
                'items'           => ['type' => 'array', 'items' => $item],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['ordenes'],
            'properties' => ['ordenes' => ['type' => 'array', 'items' => $orden]],
        ];
    }
}

# Aviso de entrega por WhatsApp (Business Cloud API)

Antes de salir a entregar, el panel envía un WhatsApp al cliente final con la
fecha y el horario de la visita y dos botones: **Confirmar** y **Reprogramar**.
La respuesta vuelve al sistema; si el cliente elige *Reprogramar*, la orden queda
marcada como **no entregar** (el escáner avisa al intentar entregarla).

- Disparo: **Reportes** (`admin/ordenes-reportes.php`) → seleccionar órdenes →
  botón **Avisar entrega (WhatsApp)** → elegir fecha + horario.
- Respuestas: **Avisos de entrega** (`admin/confirmaciones.php`).
- Roles: disparar/ver → admin y logística; ver (solo lectura) → supervisor.

## Prerrequisitos en Meta (los hace el cliente/dueño de la marca)

Esto es un trámite externo (como AFIP) y la aprobación de la plantilla puede
tardar días. Hasta tenerlo, el envío real no funciona, pero el sistema ya está
listo y se prueba con el **número de prueba** que da Meta.

1. Crear una app en <https://developers.facebook.com> y agregar el producto
   **WhatsApp**. Asociar una **WhatsApp Business Account (WABA)**.
2. Obtener:
   - **Phone Number ID** del número emisor (no el número en sí) → `WA_PHONE_NUMBER_ID`.
   - **Token permanente** (System User con permisos `whatsapp_business_messaging`
     y `whatsapp_business_management`) → `WA_TOKEN`.
   - **App Secret** (Configuración → Básica) → `WA_APP_SECRET`.
3. Crear una **plantilla de mensaje** (Message Template), categoría *Utility*,
   idioma `es_AR` (o el que uses → `WA_LANG`), con:
   - **Cuerpo** con 3 variables:
     > Nos comunicamos de Corredora de Servicios por la entrega de sus productos
     > {{1}}. Estaremos visitando su domicilio el {{2}}. El horario de entrega es
     > de {{3}}. ¿Nos confirma que habrá una persona mayor de 18 años para
     > recibir la compra? De lo contrario su envío será reprogramado para un
     > próximo viaje a su localidad.
   - **Dos botones de respuesta rápida (Quick reply):** `Confirmar` y `Reprogramar`.
   - El nombre de la plantilla va en `WA_TEMPLATE` (debe coincidir EXACTO).
   - Las variables las completa el sistema: {{1}} marca/producto, {{2}} fecha
     (ej. "lunes 29"), {{3}} horario (ej. "8 a 17 hs").
4. Configurar el **webhook**:
   - URL de devolución: `https://TU-DOMINIO/trazock/api/whatsapp-webhook.php`
   - Verify token: el mismo valor que pongas en `WA_VERIFY_TOKEN`.
   - Suscribir el campo **messages**.

## Configuración en el sistema

En `config/config.php` (gitignored) completar:

```php
define('WA_TOKEN', '...');            // token permanente
define('WA_PHONE_NUMBER_ID', '...');  // phone number id
define('WA_TEMPLATE', 'aviso_entrega');
define('WA_LANG', 'es_AR');
define('WA_API_VER', 'v21.0');
define('WA_VERIFY_TOKEN', '...');     // el mismo que cargás en Meta
define('WA_APP_SECRET', '...');       // valida la firma del webhook
```

## Notas

- Fuera de la ventana de 24 h, Meta solo permite **plantillas** (por eso usamos
  una). Las respuestas de botón sí llegan como mensaje normal al webhook.
- Los teléfonos se normalizan best-effort a E.164 argentino (`tel_e164()`); si un
  número no es válido, ese aviso queda registrado con error y se puede corregir el
  teléfono de la orden y reenviar.
- Reenviar un aviso a una orden reescribe su registro (vuelve a “Sin responder”).

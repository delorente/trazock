# Handoff — Módulo de Órdenes (ingreso por OCR)

Estado al cierre de esta sesión, para continuar en un chat nuevo sin re-descubrir.

## Qué es
El cliente (Simmons, ~1 camión/semana, ~10 hojas) abandonó los códigos de barras ITF
largos. Flujo nuevo: el gestor fotografía las **hojas resumen de remitos** → OCR (Claude
visión) → **planilla de revisión** editable → **Confirmar carga** → crea **órdenes** en
"Recibido en depósito" → genera **etiquetas PDF con QR por ítem** → de ahí sigue el flujo
existente (reparto/entrega/seguimiento). El seguimiento pasa a ser por **Nº de orden**.

## Convenciones del proyecto (respetar)
- PHP 8.3 sin framework, MySQL 8 (dev) / MariaDB 10.3 (prod). Modelos en `lib/Models/`,
  endpoints JSON en `api/` (usar `lib/Api.php`), páginas admin con `admin/_layout.php`
  (`panel_header/panel_footer`, patrón POST+CSRF+PRG como `admin/categorias.php`).
- Assets **locales** desde `assets/vendor/` (sin CDNs; hay CSP estricta de origen propio).
- MariaDB 10.3: TIMESTAMP nulos como `NULL DEFAULT NULL` (no `DEFAULT NULL`).
- Tema oscuro del panel: tokens `--bg/--card/--border/--blue…` (idénticos al diseño).
- Commits con `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## Diseño (Claude Design)
Bundle en `design/project/`. Pantallas del módulo en
`design/project/Módulo de Órdenes - Trazock.html` (captura, revisión, confirmación,
etiqueta, reportes, detalle de orden). Seguimiento público (light) en el mismo archivo
y en `design/project/Seguimiento Trazock.html`. **Recrear con assets locales**, no CDNs.

## Modelo de datos (migración 005, aplicada en dev)
- `cargas`: lote de ingreso. `datos_extraidos` (LONGTEXT JSON borrador editable),
  `estado` borrador|confirmada.
- `ordenes`: `nro_orden` UNIQUE (= ON-0775-XXXXXXXX, va al QR y al seguimiento),
  nro_remito, fecha_remito, tipo_venta(local|online), cliente, cliente_apellido,
  telefonos, dest_(provincia/localidad/domicilio/cp), valor_declarado, m3_total,
  estado (derivado: RECIBIDO/EN_REPARTO/ENTREGADO…).
- `productos` +columnas: orden_id, descripcion (código tabulado), dimensiones, m3,
  secuencia. Cada ítem físico = un producto, `codigo = nro_orden-NN`, estado INGRESADO.

## Construido y VALIDADO
- `sql/migrations/005_ordenes.sql` (+ en `schema.sql`).
- `lib/Models/Carga.php`, `lib/Models/Orden.php` (CRUD + grilla reportes `Orden::buscar`).
- `lib/ExtractorOcr.php`: imagen → `['ordenes'=>[...]]` vía cURL a /v1/messages, salida
  estructurada (json_schema), reescala a 2576px (gd). **Modelo: claude-sonnet-4-6**
  (preciso en dígitos, ~centavos/hoja; NO usar Opus, carísimo). Config:
  `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, y en dev `ANTHROPIC_CA_BUNDLE`=C:/wamp64/cacert.pem
  (cURL de WAMP no trae CA; en prod sin definir).
- `lib/ProcesadorCarga.php`: borrador → ordenes+productos (codigo nro_orden-NN, secuencia,
  m3 por unidad, estado INGRESADO/RECIBIDO), en transacción, omite duplicados.
- Flujo UI: `admin/ordenes-captura.php` (subir hojas → `api/ordenes-hoja.php` procesa
  c/u y acumula en la carga), `admin/ordenes-revision.php` (grilla editable + validación)
  → `api/ordenes-confirmar.php` (ProcesadorCarga). Estilos en `assets/css/app.css`.
- Scripts de prueba: `scripts/test-extraccion.php <img>`, `scripts/test-procesador.php <json>`.
- Verificado: extracción real OK (Nº orden/remito/teléfonos correctos), materialización OK
  (11 órdenes/33 ítems), páginas con auth + smoke test.

## PENDIENTE (en orden sugerido)
1. **Etiquetas PDF + QR** (cierra el circuito de depósito). Diseño: ver sección
   `#etiqueta`/`.label-card` y `.sheet-a4` (A4, 8 por hoja, QR + DESTINO·APELLIDO·ITEM X de X).
   - QR payload autocontenido (para validar destino offline en el escáner):
     `nro_orden|sec/total|provincia|apellido`. El `codigo` (nro_orden-NN) es la clave.
   - Libs PHP libres: generador QR (p.ej. endroid/qr-code o uno propio SVG) + PDF
     (FPDF/Dompdf) o imprimir HTML con `@media print`. Evaluar mínima dependencia.
   - Página `admin/ordenes-confirmacion.php` (post-confirm) + `admin/ordenes-etiquetas.php`
     (hoja imprimible). Marcar productos como ETIQUETADA si se agrega ese estado/flag.
2. **Reportes** (`admin/ordenes-reportes.php` + re-agregar al menú en `_layout.php`):
   grilla con `Orden::buscar` (ya filtra por provincia/estado/tipo_venta/fechas/búsqueda),
   sumbar (Σ m³, #órdenes, #ítems), export Excel (ya hay PhpSpreadsheet — ver
   `admin/exportar.php`), PDF e imprimir (CSS print). Diseño: sección `#reportes`.
3. **Escáner ITF → QR**: en `assets/js/scan/scanner.js` cambiar el formato del detector
   nativo y zxing a **qr_code** (era ITF de 36 díg., ya no existe), y la validación
   `CODIGO_VALIDO` al patrón del QR. Sumar controles en `assets/js/scan/ui.js`:
   destino obligatorio en salida a reparto + error contundente si el QR no es de ese
   destino; aviso al cerrar el lote si una orden quedó con ítems sin escanear; en entrega,
   exigir todos los ítems de la orden. (El pipeline de cámara nativo+zxing ya está.)
4. **Seguimiento por Nº de orden**: refactor de `seguimiento/index.php` → formulario de
   ingreso de Nº de orden (sin token) → `Orden::findByNroOrden` → estado público
   (reusa `estados_publicos`). Pseudo-estado "en tránsito al centro de distribución" si la
   orden no está aún en la BD. NO expone datos del cliente (solo estado+fecha). Diseño:
   `#pv-input/#pv-status/#pv-notfound` (light). El estado de orden se deriva de sus ítems
   (hook en `ProcesadorLote` para recalcular `ordenes.estado` al transicionar productos).
5. **Pendientes operativos previos** (de la 1ra etapa, en producción intercongress.ar):
   rotar admin admin123, y el escáner/seguimiento desplegado es el viejo (token/ITF) —
   redeploy cuando el módulo nuevo esté probado.

## Notas de despliegue
- En prod, PHP `upload_max_filesize`/`post_max_size` ≥ 10 MB (fotos), y que la extracción
  por hoja (~40s) no la corte `max_execution_time`/FPM (el endpoint usa `set_time_limit`).
- `ANTHROPIC_CA_BUNDLE` sin definir en prod (Linux usa CA del sistema).

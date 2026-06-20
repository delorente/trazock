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

## Modelo de datos (migraciones 005 + 006, aplicadas en dev)
- `cargas`: lote de ingreso. `datos_extraidos` (LONGTEXT JSON borrador editable),
  `estado` borrador|confirmada.
- `ordenes`: `nro_orden` UNIQUE (= ON-0775-XXXXXXXX, va al QR y al seguimiento),
  nro_remito, fecha_remito, tipo_venta(local|online), cliente, cliente_apellido,
  telefonos, dest_(provincia/localidad/domicilio/cp), valor_declarado, m3_total,
  estado (derivado: RECIBIDO/EN_REPARTO/ENTREGADO…).
- `productos` +columnas: orden_id, descripcion (código tabulado), dimensiones, m3,
  secuencia. Cada ítem físico = un producto, `codigo = nro_orden-NN`, estado INGRESADO.
- `productos.etiquetada_at` (migración 006): timestamp nullable, "etiqueta impresa".
  Ortogonal a la máquina de estados (NO se agregó a `estado_actual`). La badge
  "ETIQUETADA" del panel se deriva de él (etiquetada_at != NULL && estado INGRESADO).

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
- **Etiquetas PDF + QR (pendiente #1, COMPLETO y validado E2E):**
  - QR REAL escaneable (no el `makeQR` falso del diseño). Lib vendorizada
    `assets/vendor/qrcode-generator/qrcode.min.js` (Kazuhiko Arase, MIT). Render
    client-side a SVG (`assets/js/etiquetas.js`) + impresión navegador `@media print`
    → "Guardar como PDF". Sin dependencia PHP nueva, sin libs PDF.
  - **GOTCHA verificado**: la lib por defecto codifica Latin1/SJIS y corrompe los
    acentos (Córdoba/García). `etiquetas.js` fuerza `qrcode.stringToBytes =
    stringToBytesFuncs['UTF-8']`. Validado decodificando con jsQR (5 payloads,
    incl. acentos y v3/v4) → todos OK.
  - `lib/EtiquetaQr.php`: payload autocontenido `nro_orden|sec/total|provincia|apellido`
    + `parse()` (lo reusará el escáner) + `codigo()`. `helper carga_num()` (C-AAAA-NNN).
  - `Producto::paraEtiquetasPorCarga/PorOrden` (ítems + contexto de orden + total),
    `Producto::marcarEtiquetadasPorCarga`; `Orden::resumenCarga` (órdenes/ítems/m³/etiquetados).
  - `admin/ordenes-confirmacion.php` (post-confirm: resumen + preview etiqueta real +
    botón generar) y `admin/ordenes-etiquetas.php` (hoja A4, 8/hoja, marca etiquetada
    al abrir). Revisión ahora redirige a confirmación al confirmar.
  - CSS `.label-card/.label-sheet/.sheet-page/.label-cell` + `@media print` en app.css.
  - Probado por HTTP con sesión admin: confirmación y etiquetas 200, payloads y
    sec/total correctos, `etiquetada_at` seteado. (Quedó sembrada la carga dev #2 —
    `ON-0775-…` — para inspección visual; borrar con el cleanup de test-procesador.)

## PENDIENTE (en orden sugerido)
1. ~~**Etiquetas PDF + QR**~~ ✅ HECHO — ver "Construido y VALIDADO" arriba.
2. ~~**Reportes**~~ ✅ HECHO. `admin/ordenes-reportes.php`: grilla `Orden::buscar` con
   filtros (provincia/estado/tipo_venta/fechas/búsqueda) + paginación, sumbar
   (`Orden::totales`: Σ órdenes/m³/ítems), export Excel (`?export=xlsx`, PhpSpreadsheet),
   e Imprimir/PDF (CSS print). Menú "Reportes" agregado en `_layout.php`. Detalle:
   `admin/ordenes-detalle.php` (datos + ítems con badge ETIQUETADA + preview QR +
   re-imprimir → `ordenes-etiquetas.php?orden=ID`). `Orden::provincias()/totales()/ESTADOS`.
   - Impresión refactorizada a `.print-area` genérico en app.css (antes ocultaba todo
     salvo `.label-sheet`): `#main > :not(.print-area){display:none}` + `.no-print`.
     La hoja de etiquetas lleva `class="label-sheet print-area"`.
   - Detalle: "Editar orden" (modal + PRG, `Orden::actualizarDatos`) y timeline de
     **Historial** (`Orden::historial`: ingreso + etiquetas + cambios de estado de los
     ítems desde `transiciones`). Los ítems muestran su estado real.
3. ~~**Escáner ITF → QR**~~ ✅ HECHO (falta probar con cámara en celular — ver caveat).
   - `scanner.js`: formato a QR (`FORMATOS=['QRCode']` zxing, `['qr_code']` nativo);
     `CODIGO_VALIDO=/^[^|]+\|\d+\/\d+\|/` (patrón del payload). Pipeline de cámara igual.
   - `ui.js`: `onScan` parsea el QR (`parseQR` espeja `EtiquetaQr::parse`) y guarda el
     `codigo` (nro_orden-NN); los ítems llevan nro_orden/sec/total/prov/ciudad.
   - **Zonas de reparto** (decisión del cliente, reemplaza "destino" simple): subsistema
     nuevo — tablas `zonas`+`zona_localidades` (migración 007), `Models\Zona`, ABM
     `admin/zonas.php` (menú Administración), expuestas en `Catalogos::para`. Una zona
     agrupa localidades (provincia + ciudad **opcional** = toda la provincia). En
     SALIDA_REPARTO el operador elige transportista **+ zona**; cada QR se valida contra
     la zona (`enZona`, normaliza acentos/may.). Si está fuera → **aviso vehemente NO
     bloqueante** (`modalFueraZona`): se puede llevar igual (el ítem queda marcado
     `fuera_zona`); nunca se detiene la operación. El lote guarda un snapshot de las
     localidades para validar offline.
   - Cierre de lote: `ordenesIncompletas` (por sec/total). SALIDA_REPARTO → **aviso con
     confirmación explícita** (checkbox "recibí el aviso" + "Cerrar igual"). ENTREGA →
     **bloquea** (exige todos los ítems de la orden). `modalIncompletas` (modal dinámico).
   - SW: `CACHE_VERSION` bumpeado a `trazock-v3` (forzar update del JS en dispositivos).
   - **CAVEAT**: validado por lógica (parse/zona/incompletas con node) + render/catálogos
     por HTTP. El escaneo real con cámara y la UX del modal NO se pudieron probar acá
     (necesitan celular). Probar en dispositivo antes de redeploy. Los catálogos cacheados
     viejos no traen `zonas` hasta refrescar online (re-login o reconexión).
4. ~~**Seguimiento por Nº de orden**~~ ✅ HECHO y validado E2E.
   - `seguimiento/index.php`: formulario `?orden=` (sin token) → `Orden::findByNroOrden`
     → estado público derivado de los ítems (`Orden::estadoProductoDerivado`, reusa
     `estados_publicos`). Si no está en la BD → pseudo-estado "en tránsito al centro de
     distribución". NO expone datos del cliente (solo estado + fecha + nº). Tema claro,
     reusa el render existente (`seg_card`). Compat: `?t=<token>` sigue mostrando el ítem.
   - Hook en `ProcesadorLote`: junta los `orden_id` de los productos transicionados y
     llama `Orden::recalcularEstado` antes del commit (alimenta Reportes y el seguimiento).
     `Producto::findByCodigoForUpdate` ahora también trae `orden_id`.
   - Vocabulario: orden usa RECIBIDO (= producto INGRESADO); resto igual. Derivación:
     todos ENTREGADO→ENTREGADO, todos DEVUELTO→DEVUELTO, algún EN_REPARTO o entrega
     parcial→EN_REPARTO, algún REINGRESADO→REINGRESADO, resto→RECIBIDO.
   - Validado: lote SALIDA_REPARTO real movió la orden 12 RECIBIDO→EN_REPARTO y el
     seguimiento público pasó a "En camino a tu domicilio" (done/current/pending). (La
     orden 12 quedó en EN_REPARTO en dev por esta prueba.)
5. **Pendientes operativos previos** (de la 1ra etapa, en producción intercongress.ar):
   rotar admin admin123, y el escáner/seguimiento desplegado es el viejo (token/ITF) —
   redeploy cuando el módulo nuevo esté probado.

## Notas de despliegue
- En prod, PHP `upload_max_filesize`/`post_max_size` ≥ 10 MB (fotos), y que la extracción
  por hoja (~40s) no la corte `max_execution_time`/FPM (el endpoint usa `set_time_limit`).
- `ANTHROPIC_CA_BUNDLE` sin definir en prod (Linux usa CA del sistema).

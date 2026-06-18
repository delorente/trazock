# Instrucciones para el agente de IA — Proyecto Trazock

## Contexto del proyecto

Vas a desarrollar **Trazock**, un sistema web de trazabilidad de stock con lectura de códigos por cámara. Es un sistema permanente (no efímero) para uso continuo de empresas que necesitan trackear productos individuales (típicamente colchones, pero genérico) a través de los estados INGRESADO → EN_REPARTO → ENTREGADO, con manejo de REINGRESADO, DEVUELTO y BAJA.

**Spec completa adjunta**: leer `especificaciones-trazock.md` antes de empezar. Es la fuente de verdad. Cualquier ambigüedad: preguntar, no asumir.

**Stack**: PHP 8.3 vanilla, MySQL 8 / MariaDB, Bootstrap 5, JS vanilla, IndexedDB (idb v8), html5-qrcode, PhpSpreadsheet. Servidor objetivo: Vultr con Nginx + PHP-FPM + MySQL 8. Sin frameworks PHP, sin React, sin CDNs.

## Diferencias clave vs proyectos anteriores (NO copiar paradigmas)

Trazock toma como referencia trabajo previo en sistemas relacionados (EvenSign y EvenList) pero tiene paradigmas opuestos en aspectos clave. **No copiar mecánicamente del código previo en estos puntos**:

| Aspecto | EvenList / EvenSign | Trazock |
|---|---|---|
| Autoridad de validación | Cliente | **Server** |
| Inteligencia del cliente | Alta (valida transiciones) | **Mínima (escanea y envía)** |
| Cache de catálogo en cliente | Listado completo | **Mínimo (solo catálogos auxiliares)** |
| Auth | PIN + cache offline de hash | **Usuario/password + cookie 30 días, sin cache de password** |
| Identidad | dispositivo_id por evento/slug | **Usuario fijo** |
| Modelo de trabajo | Item individual | **Lotes (agrupadores)** |
| Conflictos | Idempotencia simple | **Reglas R1-R10 server-side con marca de conflicto** |
| URL de la app | Multi-evento (`/app/{slug}`) | **Única (`/scan/`)** |
| Vida útil del sistema | Efímero (evento) | **Permanente** |

**Lo que SÍ se puede reusar de EvenSign/EvenList:**
- Patrón de `lib/DB.php` (singleton PDO)
- Patrón de `lib/Auth.php` para login + CSRF + rate limit (adaptado a múltiples roles)
- Estructura de modelos en `lib/Models/`
- Patrón de Service Worker (cache-first/network-first/versionado)
- Patrón de cola_sync en IDB (adaptado a cola_lotes)
- UX de scanner con html5-qrcode (antirrebote, feedback háptico/visual)
- Modal de mantenimiento de cola
- Manejo de import/export con PhpSpreadsheet
- CSS de branding y patrones responsivos

Cuando se te indique "tomar como referencia EvenSign/EvenList", abrí el archivo, leelo, adaptá al nuevo paradigma. **No copies ciego.**

## Setup inicial

- Repo nuevo en `/proyectos/trazock/`. NO tocar repos previos.
- `composer.json` con namespace `Trazock\`, dependencias mínimas (`phpoffice/phpspreadsheet`).
- PHP 8.3 strict types en todos los archivos. Comentarios y UI en español. Código en inglés solo si es estándar (variables, funciones).
- Git: commits atómicos por feature, mensajes en español, branch `main`.

## Orden de desarrollo y entregables

**7 fases**. Entregá cada fase para revisión **antes de avanzar**. Cada fase es auditable de forma independiente.

Por cada fase, el entregable incluye:
1. Código commiteado
2. Lista de archivos creados/modificados/eliminados
3. Referencias usadas de proyectos previos: qué archivo se adaptó y a qué
4. Pasos exactos para verificar criterios de aceptación
5. Decisiones tomadas por cuenta propia, con justificación
6. Bloqueos o dudas

---

## FASE 1 — Schema, librerías base, login, primer admin

**Objetivo**: BD funcional, librerías core, login operativo con roles.

### Archivos a crear

- `sql/schema.sql` con todas las tablas de la spec + INSERT de admin de prueba (`admin` / `admin123`, rol `admin`)
- `sql/migrations/` con schema dividido en archivos numerados
- `config/config.example.php` con constantes documentadas
- `config/config.php` (gitignored)
- `composer.json` + `composer.lock`
- `.htaccess` con bloqueo de `/config/`, `/lib/`, `/sql/`
- `lib/DB.php` (singleton PDO, `ERRMODE_EXCEPTION`, `FETCH_ASSOC` default)
- `lib/Auth.php` con: login, logout, validación de sesión, **helper `requiereRol(string|array $roles)` que aborta con 403 si el usuario logueado no tiene el rol requerido**
- `lib/Models/Usuario.php`
- `admin/login.php`
- `admin/index.php` (placeholder con "Bienvenido, [usuario] - rol: [rol]")
- `admin/logout.php`
- `admin/_layout.php` (header con nombre de usuario, rol visible, logout)
- `scripts/crear-admin.php` (CLI para crear un admin desde terminal en caso de no tener acceso al panel)

### Referencia de EvenSign

- `lib/DB.php` → adaptar (probablemente idéntico)
- `lib/Auth.php` → adaptar agregando soporte multi-rol y helper `requiereRol`
- `admin/login.php` → adaptar UI

### Sesión persistente 30 días

Cookie de sesión configurada con `session_set_cookie_params(['lifetime' => 30*24*3600, ...])`. En cada request, renovar `last_activity` y extender la cookie. Si pasaron 30 días sin uso → sesión expirada, requiere re-login.

### Criterios de aceptación Fase 1

- [ ] `mysql < sql/schema.sql` crea todas las tablas sin errores
- [ ] Existe el admin de prueba con rol `admin`
- [ ] `/admin/login.php` permite loguearse
- [ ] Login falla con credenciales incorrectas + mensaje
- [ ] Rate limit funciona: 5 intentos fallidos en 15 min bloquea
- [ ] CSRF activo
- [ ] Cookie de sesión persiste al cerrar y reabrir el navegador
- [ ] `scripts/crear-admin.php usuario nombre password rol` crea usuario desde CLI
- [ ] `.htaccess` bloquea acceso directo a `/config/`, `/lib/`, `/sql/`
- [ ] `Auth::requiereRol('admin')` aborta con 403 si el usuario actual no es admin

---

## FASE 2 — ABM de catálogos con autorización por rol

**Objetivo**: gestión completa de usuarios, categorías, proveedores, motivos. Autorización estricta por rol.

### Archivos a crear/modificar

- `lib/Models/Categoria.php`, `Proveedor.php`, `Motivo.php`
- `admin/usuarios.php` (solo admin: ABM completo con los 4 roles)
- `admin/categorias.php` (solo admin)
- `admin/proveedores.php` (solo admin)
- `admin/motivos.php` (solo admin con tipos `reingreso`, `devolucion`, `baja` + flag `editable_libre`)
- Modificación de `_layout.php`: sidebar con menú filtrado por rol del usuario

### Validaciones

- Usuario: usuario único, password con `password_hash()`, rol obligatorio entre los 4 válidos
- Categoría: nombre único
- Proveedor: nombre obligatorio (sin unicidad estricta, puede haber dos "Simmons" con datos distintos)
- Motivo: nombre + tipo obligatorios. Si `editable_libre=1`, al usarse en un lote se exige texto libre.

### Soft-delete

Toggle `activo` 0/1. Inactivos no aparecen en dropdowns de nuevos lotes pero siguen visibles en histórico. En el ABM, los inactivos al final con badge "Inactivo".

### Criterios de aceptación Fase 2

- [ ] Admin crea usuarios con los 4 roles, todos funcionales
- [ ] Login con usuario de rol `gestor` → ve panel pero NO ve menús de ABM (solo dashboard, productos, lotes, conflictos)
- [ ] Login con rol `operador` → al loguearse en `/admin/`, redirige a `/scan/` (todavía no implementado, dejar placeholder informativo)
- [ ] Login con rol `transportista` → idem operador
- [ ] Gestor accediendo manualmente a `/admin/usuarios.php` → 403
- [ ] Crear categoría/proveedor/motivo funcional, validaciones correctas
- [ ] Soft-delete: inactivar y reactivar funcionan, los inactivos quedan visibles con badge
- [ ] Motivo con `editable_libre=1` se identifica visualmente en el ABM

---

## FASE 3 — Procesador de lotes server-side (núcleo del sistema)

**Objetivo**: implementar `ProcesadorLote.php` con todas las reglas R1-R10. Esta fase es **la más crítica del proyecto**. Si el procesador tiene bugs, todo el sistema produce datos corruptos. Dedicarle el tiempo necesario y probar exhaustivamente antes de seguir.

### Archivos a crear

- `lib/MaquinaEstados.php` — enum/clase con definición estática de transiciones legales. Método `esTransicionLegal(?Estado $desde, Estado $hasta): bool`.
- `lib/ProcesadorLote.php` — clase principal. Método público `procesarLote(array $loteData): array`. Encapsula reglas R1-R10. Usa transacciones.
- `lib/Models/Lote.php`, `Transicion.php`, `Producto.php`
- `api/lote-enviar.php` — endpoint POST que valida payload, valida permisos de rol, invoca `ProcesadorLote::procesarLote()`, retorna resultado
- `api/lotes-pendientes.php` — endpoint GET para recovery: cliente consulta por `uuid` si un lote ya fue procesado y con qué resultado
- `tests/procesador-lote-casos.php` — script PHP standalone (no PHPUnit, no agregamos dependencias) que ejecuta una serie de casos contra una BD de test y reporta resultados en consola. **Es para auditoría manual, no se ejecuta en producción.**

### Lógica de `ProcesadorLote::procesarLote()`

```
1. Iniciar transacción
2. Validar idempotencia (R1): buscar lote por uuid. Si existe, retornar resultado guardado.
3. Validar permisos: rol del usuario vs tipo de lote. Si no, abortar 403.
4. Validar campos obligatorios según tipo de lote.
5. Validar integridad referencial (categoria_id, proveedor_id, etc existen y están activos).
6. Insertar lote en `lotes`.
7. Para cada item del lote, en orden:
   a. Buscar producto por código.
   b. Si NO existe:
      - Si tipo lote = INGRESO → crear producto con categoría del lote, estado INGRESADO, sin conflicto (R8)
      - Si tipo lote ≠ INGRESO → crear producto con estado destino del lote, marcar conflicto `producto_inexistente_en_no_ingreso` (R7)
   c. Si SÍ existe:
      - Determinar `estado_destino` según tipo de lote
      - Si producto.estado_actual === estado_destino y NO es transición que cambia estado → R3, ignorar (registrar en lote_items como `ignorado_mismo_estado`)
      - Si la transición es legal según MaquinaEstados → aplicar normalmente (R5)
      - Si la transición es ilegal → aplicar igual, marcar conflicto (R6)
   d. Para cada transición que se aplica:
      - Insertar en `transiciones`
      - Insertar en `lote_items` con `transicion_id` y `resultado` apropiado
      - Si el `timestamp_cliente` de esta transición es el más reciente del producto → actualizar `producto.estado_actual` y `producto.transicion_actual_id` (R4)
      - Si NO es la más reciente → solo insertar transición, no tocar estado actual
      - Si es conflicto → insertar en `conflictos_producto`, marcar `producto.tiene_conflicto=1`
   e. Detectar duplicados dentro del mismo lote (R2): si el código ya fue procesado en este lote en una iteración anterior, registrar en lote_items como `ignorado_duplicado_lote` sin generar transición.
8. Commit. Si falla cualquier paso, rollback.
9. Retornar resumen: {ok, lote_id, items_procesados, transiciones_aplicadas, items_ignorados, conflictos_generados, detalle:[...]}
```

**Punto crítico — R4 con timestamps retroactivos**:

Cuando llega una transición con `timestamp_cliente` ANTERIOR al `timestamp_cliente` de la última transición del producto, NO se debe actualizar `producto.estado_actual`. Solo se inserta en `transiciones` (queda en el historial). Esto requiere consultar `MAX(timestamp_cliente)` de las transiciones del producto antes de decidir si actualizar.

### Casos de test en `tests/procesador-lote-casos.php`

Mínimo 15 casos cubriendo:

1. Lote INGRESO con 3 códigos nuevos → 3 productos creados en INGRESADO, sin conflicto
2. Mismo lote enviado dos veces → segunda llegada idempotente (R1)
3. Lote con código duplicado en items → un solo registro de transición, otro como `ignorado_duplicado_lote` (R2)
4. Lote INGRESO con código que ya está INGRESADO → ignorado por R3
5. Lote SALIDA_REPARTO con código que está INGRESADO → transición legal aplicada
6. Lote ENTREGA con código que está INGRESADO (sin pasar por REPARTO) → aplicada con conflicto (R6)
7. Lote SALIDA_REPARTO con código nunca visto → producto creado en EN_REPARTO con conflicto (R7)
8. Lote retroactivo: producto está en EN_REPARTO, llega lote INGRESO con timestamp más viejo → transición insertada pero `estado_actual` no cambia (R4)
9. Dos lotes mismo producto: INGRESO en T1, SALIDA_REPARTO en T2, llegan al server en orden inverso → estado final EN_REPARTO (no INGRESADO)
10. Lote BAJA desde INGRESADO → legal
11. Lote BAJA desde EN_REPARTO → legal
12. Lote BAJA desde REINGRESADO → legal
13. Lote REINGRESO desde ENTREGADO (devolución cliente) → legal
14. Lote SALIDA_DEVOLUCION desde REINGRESADO → legal, estado terminal
15. Usuario rol transportista envía lote tipo INGRESO → rechazado 403

El script debe ejecutar cada caso, comparar contra el resultado esperado, e imprimir en consola `[OK] caso N: descripción` o `[FAIL] caso N: esperado X, obtuvo Y`.

### Criterios de aceptación Fase 3

- [ ] `tests/procesador-lote-casos.php` ejecuta los 15 casos y todos retornan OK
- [ ] `MaquinaEstados::esTransicionLegal()` cubre todas las transiciones de la spec, ni una más, ni una menos
- [ ] `lote-enviar.php` retorna 403 si el rol del usuario no permite el tipo de lote enviado
- [ ] `lote-enviar.php` retorna 400 si faltan campos obligatorios según tipo
- [ ] `lote-enviar.php` retorna 400 si referencias inválidas (categoría inexistente o inactiva)
- [ ] `lote-enviar.php` con uuid duplicado retorna el mismo resultado que la primera vez (sin re-procesar)
- [ ] Lotes con >1000 items son rechazados con 413
- [ ] Todo el procesamiento de cada lote ocurre dentro de una transacción MySQL (verificar con `SHOW ENGINE INNODB STATUS` o lectura del código)
- [ ] `productos.estado_actual` se actualiza correctamente respetando R4 (timestamp más reciente, no orden de llegada)
- [ ] Conflictos se insertan en `conflictos_producto` con la descripción correcta del motivo

**Esta fase requiere auditoría especialmente cuidadosa. Recomendá al auditor que ejecute personalmente `tests/procesador-lote-casos.php` y revise el código de `ProcesadorLote.php` línea por línea antes de aprobar.**

---

## FASE 4 — Panel web (admin + gestor)

**Objetivo**: panel completo de gestión, consulta y resolución de conflictos.

### Archivos a crear

- `admin/index.php` — Dashboard con KPIs + tabla cruzada categorías × estados + últimos 10 lotes. Polling 30s. Responsive desktop + mobile.
- `admin/productos.php` — Buscador por código (foco automático, enter ir a detalle) + listado paginado filtrable + export Excel
- `admin/producto-detalle.php` — Header con datos + timeline cronológico + botones "Ajuste manual" y "Marcar conflictos revisados"
- `admin/lotes.php` — Listado filtrable de lotes recientes
- `admin/lote-detalle.php` — Header del lote + tabla de items con resultado
- `admin/conflictos.php` — Cola de conflictos pendientes + toggle ver resueltos + acciones inline
- `admin/exportar.php` — Excel con filtros
- `api/producto-historial.php`, `api/conflicto-resolver.php`, `api/ajuste-manual.php`
- `assets/js/admin/dashboard.js` — Polling
- `lib/Models/` completar lo que falte

### Reglas de visualización

- Admin: ve todos los menús + ABMs
- Gestor: ve dashboard, productos, lotes, conflictos, exportar. NO ve ABMs (usuarios, categorías, proveedores, motivos).
- Header con badge del rol actual

### Ajuste manual de estado

Modal con:
- Estado actual (display)
- Selector de nuevo estado (todos los 6, sin restricción de máquina)
- Motivo: texto libre obligatorio
- Confirmación explícita ("Confirmar cambio de estado")

Al confirmar, crear transición con `es_ajuste_manual=1`, `lote_id=NULL`, `ajustado_por=usuario_actual`. Actualizar `productos.estado_actual` si la transición es la más reciente.

**No** marcar como conflicto un ajuste manual (es decisión deliberada del gestor/admin).

### Resolución de conflictos

Botón "Marcar como revisado" en cada conflicto pendiente:
- Modal con nota opcional
- Setea `revisado_por`, `revisado_at`, `nota_resolucion`
- Si el producto NO tiene más conflictos pendientes, también limpia `productos.tiene_conflicto = 0`

### Responsive mobile

El gestor accede desde celular. Mobile-first:
- Sidebar colapsable
- Tablas con scroll horizontal o conversión a cards en breakpoint sm
- Búsqueda de producto: input grande, scanner via cámara opcional (botón ícono al lado del input) — esto NO es escaneo de lote, solo búsqueda

### Criterios de aceptación Fase 4

- [ ] Login admin → ve todos los menús
- [ ] Login gestor → ve solo los permitidos
- [ ] Dashboard muestra KPIs correctos calculados desde BD
- [ ] Tabla cruzada categorías × estados refleja la realidad
- [ ] Búsqueda por código (caja única) lleva al detalle del producto
- [ ] Timeline de producto muestra todas las transiciones ordenadas
- [ ] Transiciones con `es_conflicto=1` se destacan visualmente
- [ ] Ajuste manual de estado crea transición correcta con `es_ajuste_manual=1`
- [ ] Resolver conflicto: si era el último pendiente, `tiene_conflicto` pasa a 0
- [ ] Cola de conflictos muestra solo pendientes por default, toggle muestra históricos
- [ ] Detalle de lote muestra todos los items con su resultado
- [ ] Export Excel descarga correcto con todos los filtros aplicados
- [ ] Panel se ve usable en celular vertical
- [ ] Auto-refresh dashboard cada 30s sin recargar

---

## FASE 5 — App de escaneo online

**Objetivo**: PWA funcional con conexión activa. Sin offline aún.

### Archivos a crear

- `scan/index.php` — Único archivo con login + selector + scanner. Vistas conmutables vía JS.
- `scan/_layout.php` si conviene factorizar
- `api/login.php` (si no existe ya), `api/logout.php`, `api/catalogos.php`
- `assets/js/scan/ui.js` — Switching de vistas, feedback visual/háptico/sonoro
- `assets/js/scan/scanner.js` — Wrapper de `html5-qrcode` con antirrebote 2s
- `assets/vendor/html5-qrcode/` (local)
- CSS responsive mobile-first

### Vistas en `scan/index.php`

1. **Login** (visible si no hay sesión)
2. **Selector "Nuevo lote"** (visible si sesión + sin lote en curso)
3. **Configuración de lote** (visible al tocar "Nuevo lote")
4. **Pantalla de escaneo** (visible con lote en curso)

Switching JS sin recarga. Cookie de sesión válida → arranca directo en vista 2.

### Tipos de lote por rol

JS determina los tipos disponibles según `usuario.rol` recibido del login. Mostrar solo los permitidos en el dropdown de "Tipo de lote".

### Formulario de configuración de lote

Campos condicionales según tipo (siguiendo tabla de la spec). Validación cliente + server.

### Pantalla de escaneo

Layout celular vertical:
- Header sticky (~10%): tipo + datos resumidos + contador + indicador conectividad + botón ⚠ cancelar lote
- Preview de cámara (~50%) + barra inferior interna (linterna, cambio cámara)
- Lista de últimos 5 ítems (~25%)
- Footer botón grande "Cerrar y enviar lote" (~15%)

### Lógica de escaneo

- Antirrebote 2s sobre mismo código (no genera item duplicado en lote)
- Si código ya está en el lote actual → feedback amarillo + vibración `[200]`, NO agrega
- Si código nuevo → agrega, feedback verde + vibración `[50]`, beep opcional
- Lectura continua (sin pausa del decoder)

### Envío del lote

Al "Cerrar y enviar":
- Cliente POST a `/api/lote-enviar.php` con lote completo
- Si 200: muestra resumen (X items aplicados, Y conflictos generados, Z ignorados), vuelve a vista 2
- Si 4xx/5xx: muestra error, lote NO se descarta (queda visible para reintentar — preparando terreno para fase 6)
- Spinner mientras espera

### Cache de catálogos

- Al login, server retorna catálogos en la respuesta
- Cliente guarda en variable global (todavía no en IDB)
- En fase 6 se moverá a IDB

### Criterios de aceptación Fase 5

- [ ] Operador loguea → ve selector "Nuevo lote"
- [ ] Tipos de lote en dropdown filtrados por rol (operador no ve ENTREGA, transportista solo ve ENTREGA, admin/gestor ven todos)
- [ ] Configuración de lote: campos obligatorios validados antes de pasar a escaneo
- [ ] Permiso de cámara: instrucciones claras si denegado + botón reintentar
- [ ] Escaneo: feedback visual + háptico funcionan
- [ ] Escaneo del mismo código en mismo lote: bloqueado + feedback amarillo
- [ ] Cerrar y enviar lote: POST exitoso → resumen visible
- [ ] Lote rechazado por server (ej. categoría inactiva): error claro, lote NO se pierde
- [ ] Cambio de cámara funciona en devices con múltiples cámaras
- [ ] Linterna funciona en devices que la soporten
- [ ] Logout desde scanner: vuelve a login
- [ ] Sesión persiste 30 días: cerrar pestaña y reabrir → directo al selector
- [ ] App responsive: usable con una mano en celular vertical

---

## FASE 6 — Offline-tolerant: IDB + cola + sync + Service Worker

**Objetivo**: app de escaneo funciona sin conexión, sincroniza al recuperar.

### Archivos a crear

- `assets/vendor/idb/` (idb v8 local)
- `assets/js/scan/db.js` — Wrapper de IDB con stores: `catalogos`, `lote_actual`, `cola_lotes`
- `assets/js/scan/sync.js` — Worker que envía lotes pendientes cada 15s
- `scan/sw.js` — Cache-first assets, network-first HTML, versionado
- `scan/manifest.json` (estático, no dinámico como EvenList)
- Modificación de `scan/index.php` para registrar SW

### IDB stores

- **`catalogos`**: keyPath manual. Records con keys `categorias`, `proveedores`, `motivos`, `transportistas`, `last_updated`. Refrescado en cada login + al detectar conexión si `last_updated > 1h`.
- **`lote_actual`**: keyPath manual, key fija `'current'`. Single record con el lote abierto si hay uno.
- **`cola_lotes`**: keyPath `uuid`. Estados posibles: `pendiente_sync`, `sincronizando`, `sincronizado`, `error_auth`, `error_datos`.

### Worker de sync

```
Cada 15 segundos:
  Si navigator.onLine:
    lotes_pendientes = idb.getAll('cola_lotes') filtrado por estado='pendiente_sync'
    Para cada lote:
      Marcar estado=sincronizando
      POST /api/lote-enviar.php
      Si 200:
        Guardar respuesta + estado=sincronizado
      Si 401:
        Marcar estado=error_auth
        Mostrar modal "Sesión expirada"
        Detener sync
      Si 4xx no-401:
        Marcar estado=error_datos + detalle del error
      Si 5xx o falla red:
        Marcar de vuelta como pendiente_sync, retry next cycle
```

Al re-loguear después de `error_auth`, todos los lotes en ese estado vuelven a `pendiente_sync` automáticamente.

### Modal de cola

Accesible desde badge en el header del scanner. Muestra:
- Lotes pendientes con detalles
- Lotes con error: botón "Reintentar" individual
- Lotes sincronizados recientes con resumen de resultado
- Botón "Sincronizar todo ahora"
- Auto-purga de `sincronizado` con `>7 días`

### Cambios en el flujo de envío

Ya no es POST directo al cerrar. Es:
1. Cerrar lote → guardar en `cola_lotes` con `pendiente_sync`
2. Si online → sync dispara inmediatamente
3. Si offline → queda en cola, mensaje "Lote guardado, se enviará cuando haya conexión"
4. Vuelve a selector "Nuevo lote" con badge actualizado

### Service Worker

Cache-first para `/assets/*` (incluyendo idb, html5-qrcode). Network-first para `/scan/*`. `CACHE_VERSION` bumpeable. Auto-recarga con SW nuevo.

### Cache de catálogos en IDB

Al login, los catálogos recibidos del server se guardan en IDB. Si el dispositivo está offline al abrir la app, usa el último cache con badge visible "Catálogos: actualizados hace Xh" (warning si >24h).

### Criterios de aceptación Fase 6

- [ ] Login online → catálogos en IDB
- [ ] Sin conexión: app sigue abriendo, selector "Nuevo lote" funciona, catálogos cargan desde IDB
- [ ] Sin conexión: lote se completa y queda en `cola_lotes` con `pendiente_sync`. Badge en header refleja el conteo.
- [ ] Reconexión: sync envía la cola automáticamente sin intervención
- [ ] Modal de cola muestra estados correctos con detalles
- [ ] **Test crítico**: lote completo (30 items) escaneado offline → reconectar → se envía correctamente, items aplicados en server
- [ ] **Test crítico**: 5 lotes offline distintos → al reconectar, todos se sincronizan en orden
- [ ] **Test crítico**: lote enviado dos veces (simular reintento) → server retorna mismo resultado por idempotencia
- [ ] Sesión expirada durante sync → modal claro, lote queda como `error_auth`, al re-loguear se reintenta
- [ ] Error de datos (ej. categoría inactivada entre lote abierto y envío) → estado `error_datos`, detalle visible, no se reintenta automáticamente
- [ ] SW: cambio de `CACHE_VERSION` → auto-update visible
- [ ] SW: assets sirven offline en cold start
- [ ] DevTools IDB: estructura de stores correcta, no hay datos sensibles
- [ ] Lotes `sincronizado` con >7 días se purgan automáticamente

---

## FASE 7 — Hardening, mobile polish, documentación

### Tareas

- Revisar todos los outputs por `htmlspecialchars()`
- Confirmar prepared statements en todas las queries (grep `query(`, `exec(`)
- Headers de seguridad: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, CSP estricta con `media-src 'self'` para cámara
- Validación de tipo MIME real en uploads (PhpSpreadsheet → no aceptar cualquier archivo subido)
- Logging de errores: archivo separado, no exponer en producción
- Verificación de timezone consistente (server config + MySQL + JS)
- Hardening del rate limiting (asegurar que cuenta correctamente entre requests concurrentes)
- README en español con:
  - Requisitos del servidor (PHP 8.3, MySQL 8, Nginx, OpenSSL)
  - Instalación paso a paso (clone, composer install, schema.sql, config.php, virtualhost)
  - Configuración HTTPS con Let's Encrypt
  - Creación del primer admin con `scripts/crear-admin.php`
  - Workflow para configurar un cliente desde cero: categorías, proveedores, motivos, usuarios, primer ingreso
  - Cómo usar la PWA en celulares (acceder por URL, login, instalación a home opcional)
  - Manejo de conflictos: cuándo aparecen, cómo se resuelven
  - Troubleshooting (sync no funciona, conflictos masivos, cámara, sesión)
- `reglas-procesamiento.md` con la documentación detallada de R1-R10 tal como están implementadas (para auditoría)
- Script `pruebas-aceptacion.md` con casos end-to-end manuales que un humano puede correr para validar todo el sistema

### Criterios de aceptación Fase 7

- [ ] Ningún output sin escapar (verificación con grep + revisión manual)
- [ ] Todas las queries usan prepared statements
- [ ] Headers de seguridad presentes en respuestas HTML
- [ ] CSP no rompe la app (testar especialmente la cámara)
- [ ] Logs de error no se exponen en producción
- [ ] README permite a alguien nuevo levantar el sistema desde cero
- [ ] Documento `reglas-procesamiento.md` refleja fielmente la implementación
- [ ] Script de pruebas de aceptación cubre flujo completo: ingreso → reparto → entrega + ingreso → reparto → reingreso → reparto → entrega + caso con conflicto

---

## Decisiones que NO debés tomar por tu cuenta

Detener y preguntar si encontrás necesidad de cambiar:

- Schema de BD propuesto (agregar/quitar tablas o columnas)
- Las 10 reglas server-side (R1-R10)
- La máquina de estados (transiciones legales)
- Endpoints de API (URLs, métodos, formato de payload/respuesta)
- Librerías nuevas fuera de las listadas
- Política de auth (usuario/password + cookie 30 días sin cache offline)
- Permisos por rol según la tabla de la spec
- Tamaño máximo de payload por lote (1000 items)
- Estructura de carpetas

## Decisiones menores que SÍ podés tomar

- Nombres de variables, helpers, clases auxiliares
- Estilo CSS específico (mientras respete mobile-first)
- Estructura interna de archivos JS
- Mensajes de error específicos (claros, en español, sin exponer internals)
- Optimizaciones de queries sin cambiar resultados
- Comentarios y documentación inline
- Diseño de UI (mientras respete los layouts descriptos en la spec)
- Iconografía y emojis para feedback visual

## Restricciones absolutas

- **No** copiar archivos de proyectos previos sin adaptar
- **No** servir librerías desde CDN
- **No** usar frameworks (PHP frameworks, React, Vue, etc)
- **No** mezclar inglés y español en mensajes al usuario
- **No** avanzar a la siguiente fase sin entregar la actual para auditoría
- **No** cachear hash de password en cliente (a diferencia de EvenList — Trazock NO hace esto)
- **No** validar transiciones de estado en cliente (todo server-side)
- **No** confiar en datos del cliente sin validar server-side (incluyendo permisos por rol)
- **No** asumir que un lote enviado fue recibido sin confirmación (la cola persiste hasta confirmación 200)
- **No** modificar `productos.estado_actual` sin validar que la transición sea la más reciente por timestamp_cliente (R4)

## Reglas de oro del procesador de lotes

Por la criticidad del componente, repaso:

1. **Toda operación de lote es atómica**: comienza con `BEGIN`, termina con `COMMIT` o `ROLLBACK`.
2. **Idempotencia por uuid**: el mismo lote enviado dos veces produce un solo registro y la misma respuesta.
3. **El estado actual sigue el timestamp del cliente, no el orden de llegada al server.**
4. **Las transiciones ilegales no bloquean**: se aplican igual con marca de conflicto.
5. **Los conflictos no autoresueltos** se mantienen hasta que un usuario los marca como revisados.
6. **El producto físico es la verdad**: si el sistema dice algo distinto, hay conflicto a revisar.

## Formato de entrega de cada fase

```markdown
## Fase N entregada — [nombre de la fase]

### Archivos creados
- ruta/archivo.php — descripción breve

### Archivos modificados (vs fase anterior)
- ruta/archivo.php — qué cambió

### Archivos eliminados
- (si aplica)

### Referencias usadas de proyectos previos
- archivo de EvenSign/EvenList → archivo de Trazock, qué se adaptó

### Decisiones tomadas
- Decisión X: justificación

### Cómo verificar criterios de aceptación
- Criterio 1: pasos exactos
- Criterio 2: pasos exactos

### Dudas o bloqueos
- (si aplica)

### Comentarios sobre fases siguientes
- (si detectaste algo que conviene ajustar)
```

## Recomendación de tiempos relativos

Para que el auditor entienda dónde concentrar atención:

- Fase 1: ~5% del esfuerzo total. Mecánico.
- Fase 2: ~10%. ABMs son repetitivos.
- **Fase 3: ~25%**. Es la fase más densa y crítica. No apurar.
- Fase 4: ~20%. Mucho frontend de panel.
- Fase 5: ~15%. Scanner online.
- Fase 6: ~20%. Offline + SW + cola + edge cases.
- Fase 7: ~5%. Pulido.

Si una fase parece tomar menos del % indicado, probablemente quedaron criterios sin validar. Si toma más, está bien — calidad sobre velocidad.

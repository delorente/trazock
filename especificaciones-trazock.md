# Trazock — Sistema de Trazabilidad de Stock

## Contexto del proyecto

Aplicación web para gestión integral y trazabilidad de productos (típicamente colchones, pero genérico) desde su recepción en depósito hasta su entrega al cliente final, pasando por reparto y devoluciones.

**Arquitectura offline-tolerant con autoridad server-side**: las apps de escaneo funcionan sin conexión registrando lotes locales que se sincronizan al recuperar conexión. La validación de transiciones de estado se hace exclusivamente en el server (los clientes son "bobos": escanean y envían). Esto es una diferencia fundamental respecto a EvenList/EvenSign, donde la inteligencia vivía en el cliente.

**Catálogo abierto**: los códigos de productos no están pre-cargados. El primer escaneo de un código en un lote de INGRESO lo da de alta automáticamente en la categoría del lote.

**Sistema permanente, no efímero**: a diferencia de EvenList, este sistema corre continuamente. Usuarios fijos, sesiones largas (30 días), sin slugs ni instalaciones desechables.

## Stack tecnológico (obligatorio)

- **Backend:** PHP 8.3 vanilla (`declare(strict_types=1)`, enums, readonly properties, constructor property promotion, named arguments).
- **Base de datos:** MySQL 8.0+ / MariaDB 10.5+
- **Frontend:** HTML5 + Bootstrap 5 + JavaScript vanilla
- **Librerías permitidas:**
  - `html5-qrcode` (lectura de QR y códigos de barras lineales, usa `BarcodeDetector` nativo cuando está disponible — sirve también para EAN-13, Code-128, etc, no solo QR)
  - `PhpSpreadsheet` (export Excel)
  - `idb` v8 (wrapper IndexedDB)
- **PWA:** Service Worker + Manifest

Librerías servidas local desde `assets/vendor/`. Sin CDNs.

## Roles del sistema

| Rol | Acceso al panel web | Escaneo (tipos de lote permitidos) |
|---|---|---|
| **Admin** | Panel completo + ABM de usuarios y catálogos + resolución de conflictos | Todos los tipos |
| **Gestor** | Panel completo + resolución de conflictos. Sin ABM de usuarios/catálogos | Todos los tipos |
| **Operador** | Sin acceso al panel | INGRESO, SALIDA_REPARTO, REINGRESO, SALIDA_DEVOLUCION, BAJA |
| **Transportista** | Sin acceso al panel | Solo ENTREGA |

Admin y Gestor también pueden ajustar manualmente el estado de un producto desde el panel (sin escaneo) con confirmación.

## Máquina de estados

Estados:

- **INGRESADO**: en depósito, recibido del proveedor
- **EN_REPARTO**: cargado en vehículo para entrega
- **ENTREGADO**: entregado al cliente final (no terminal, admite devolución)
- **REINGRESADO**: volvió al depósito desde reparto o desde cliente
- **DEVUELTO**: enviado de vuelta al proveedor (terminal)
- **BAJA**: desechado (no terminal, admin/gestor puede revertir)

Transiciones legales:

```
(nuevo)        → INGRESADO         [lote tipo INGRESO]
INGRESADO      → EN_REPARTO        [lote tipo SALIDA_REPARTO]
INGRESADO      → BAJA              [lote tipo BAJA]
EN_REPARTO     → ENTREGADO         [lote tipo ENTREGA]
EN_REPARTO     → REINGRESADO       [lote tipo REINGRESO]
EN_REPARTO     → BAJA              [lote tipo BAJA]
ENTREGADO      → REINGRESADO       [lote tipo REINGRESO]
REINGRESADO    → EN_REPARTO        [lote tipo SALIDA_REPARTO]
REINGRESADO    → DEVUELTO          [lote tipo SALIDA_DEVOLUCION]
REINGRESADO    → BAJA              [lote tipo BAJA]
(cualquiera)   → (cualquiera)      [vía cambio manual desde panel, registra como transición manual]
```

Agrupador virtual **"EN_DEPOSITO"** = `INGRESADO + REINGRESADO`. Solo se usa para filtros y reportes; no es un estado real.

## Reglas de procesamiento server-side

Estas reglas se aplican al recibir cada lote del cliente, en orden de items dentro del lote y en orden de cierre entre lotes.

**R1 — Idempotencia de lote**
Lote con mismo `uuid` llega dos veces (reintento de sync) → segunda llegada se ignora silenciosamente. Logueado.

**R2 — Duplicado de código dentro del mismo lote**
Mismo código escaneado N veces en el mismo lote → se registra una sola transición (la primera). El cliente debe filtrar con antirrebote, server refuerza.

**R3 — Mismo estado repetido entre lotes**
Producto X ya está en estado E, llega lote que lo lleva a estado E → se ignora silenciosamente (no es conflicto). Caso típico: alguien re-escaneó un INGRESO ya hecho. No pasa nada, no se modifica el producto.

**R4 — Aplicación por timestamp del cliente**
El **estado actual del producto** es siempre el resultado de la transición con `timestamp_cliente` más reciente, sin importar el orden de llegada al server. Si llega un lote retroactivo (timestamp viejo), se inserta en el historial pero NO altera el estado actual si hay transiciones más nuevas.

**R5 — Transición legal según máquina de estados**
Si la transición es legal desde el estado en el momento de su timestamp_cliente → se aplica, sin conflicto. Línea de tiempo se reordena correctamente con transiciones retroactivas.

**R6 — Transición ilegal**
Si la transición NO es legal según la máquina de estados desde el estado previo → **se aplica igual**, se registra como `es_conflicto = 1`, y se crea un registro en `conflictos_producto`. El producto queda marcado con `tiene_conflicto = 1` hasta que admin/gestor lo revise.

**R7 — Código nuevo en lote no-INGRESO**
Si llega un código que no existe en `productos` y el lote NO es de tipo INGRESO → **se da de alta el producto** con la categoría del lote (si tiene) o NULL, en el estado destino del lote, y se marca como conflicto `producto_inexistente_en_no_ingreso`. Esto refleja la realidad: el producto físicamente está donde lo escanearon, hubo un error operativo previo.

**R8 — Código nuevo en lote INGRESO**
Si llega un código que no existe y el lote es INGRESO → se da de alta normalmente. Estado: INGRESADO. Sin conflicto.

**R9 — Código existente en lote INGRESO**
Si llega un código que ya existe en `productos` y el lote es INGRESO → se aplica R3 si su estado actual ya es INGRESADO (ignorar), o R6 si su estado actual es otro (conflicto: producto ya existente reingresado sin pasar por REINGRESO).

**R10 — Orden de aplicación entre lotes**
Lotes ordenados por `timestamp_cliente_cierre`. Items dentro del lote ordenados por `timestamp_cliente_escaneo`. Si dos lotes cierran en el mismo segundo (improbable), desempata por `timestamp_server_llegada`.

## Esquema de base de datos

```sql
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    nombre_completo VARCHAR(150) NOT NULL,
    rol ENUM('admin','gestor','operador','transportista') NOT NULL,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rol_activo (rol, activo)
);

CREATE TABLE categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) UNIQUE NOT NULL,
    notas TEXT NULL,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE proveedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    contacto VARCHAR(255) NULL,
    notas TEXT NULL,
    activo TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE motivos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    tipo ENUM('reingreso','devolucion','baja') NOT NULL,
    editable_libre TINYINT(1) DEFAULT 0,  -- 1 = "Otros" con texto libre obligatorio
    activo TINYINT(1) DEFAULT 1,
    INDEX idx_tipo_activo (tipo, activo)
);

CREATE TABLE productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(100) NOT NULL UNIQUE,
    categoria_id INT NULL,
    estado_actual ENUM('INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NOT NULL,
    tiene_conflicto TINYINT(1) DEFAULT 0,
    transicion_actual_id BIGINT NULL,        -- FK a transiciones; la transición que definió estado_actual
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    INDEX idx_estado (estado_actual),
    INDEX idx_categoria_estado (categoria_id, estado_actual),
    INDEX idx_conflicto (tiene_conflicto)
);

CREATE TABLE lotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL UNIQUE,           -- generado en cliente, asegura idempotencia
    tipo ENUM('INGRESO','SALIDA_REPARTO','ENTREGA','REINGRESO','SALIDA_DEVOLUCION','BAJA') NOT NULL,
    categoria_id INT NULL,                   -- obligatorio para INGRESO
    proveedor_id INT NULL,                   -- opcional INGRESO, obligatorio SALIDA_DEVOLUCION
    transportista_id INT NULL,               -- obligatorio SALIDA_REPARTO, ENTREGA (=responsable)
    motivo_id INT NULL,                      -- obligatorio REINGRESO, SALIDA_DEVOLUCION, BAJA
    motivo_libre VARCHAR(500) NULL,          -- si el motivo es editable_libre
    responsable_id INT NOT NULL,             -- usuario que cerró el lote
    observaciones TEXT NULL,
    numero_remito VARCHAR(50) NULL,          -- opcional INGRESO, SALIDA_DEVOLUCION
    timestamp_apertura DATETIME NOT NULL,    -- cliente
    timestamp_cierre DATETIME NOT NULL,      -- cliente
    timestamp_sync DATETIME NOT NULL,        -- server, al recibir
    dispositivo_info VARCHAR(255) NULL,      -- User-Agent del cliente
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id),
    FOREIGN KEY (proveedor_id) REFERENCES proveedores(id),
    FOREIGN KEY (transportista_id) REFERENCES usuarios(id),
    FOREIGN KEY (motivo_id) REFERENCES motivos(id),
    FOREIGN KEY (responsable_id) REFERENCES usuarios(id),
    INDEX idx_tipo_fecha (tipo, timestamp_cierre),
    INDEX idx_responsable (responsable_id)
);

CREATE TABLE transiciones (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    lote_id INT NULL,                        -- NULL si es ajuste manual desde panel
    estado_desde ENUM('INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NULL,  -- NULL = primer estado
    estado_hasta ENUM('INGRESADO','EN_REPARTO','ENTREGADO','REINGRESADO','DEVUELTO','BAJA') NOT NULL,
    timestamp_cliente DATETIME NOT NULL,
    timestamp_server DATETIME DEFAULT CURRENT_TIMESTAMP,
    es_conflicto TINYINT(1) DEFAULT 0,
    motivo_conflicto VARCHAR(50) NULL,       -- 'transicion_ilegal', 'producto_inexistente_en_no_ingreso', etc
    es_ajuste_manual TINYINT(1) DEFAULT 0,   -- 1 si vino del panel, no de un lote
    ajustado_por INT NULL,                   -- usuario que hizo el ajuste manual
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (lote_id) REFERENCES lotes(id),
    FOREIGN KEY (ajustado_por) REFERENCES usuarios(id),
    INDEX idx_producto_ts (producto_id, timestamp_cliente),
    INDEX idx_lote (lote_id),
    INDEX idx_conflicto (es_conflicto)
);

CREATE TABLE lote_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    lote_id INT NOT NULL,
    codigo_escaneado VARCHAR(100) NOT NULL,  -- crudo, tal como vino del escaneo
    timestamp_cliente DATETIME NOT NULL,     -- momento del escaneo
    transicion_id BIGINT NULL,               -- FK a la transición generada (NULL si fue ignorado por R2/R3)
    resultado VARCHAR(30) NOT NULL,          -- 'aplicado', 'ignorado_duplicado_lote', 'ignorado_mismo_estado', 'aplicado_con_conflicto'
    FOREIGN KEY (lote_id) REFERENCES lotes(id),
    FOREIGN KEY (transicion_id) REFERENCES transiciones(id),
    INDEX idx_lote (lote_id)
);

CREATE TABLE conflictos_producto (
    id INT AUTO_INCREMENT PRIMARY KEY,
    producto_id INT NOT NULL,
    transicion_id BIGINT NOT NULL,
    lote_id INT NULL,
    tipo VARCHAR(50) NOT NULL,
    descripcion TEXT NULL,
    fecha_generacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    revisado_por INT NULL,
    revisado_at DATETIME NULL,
    nota_resolucion TEXT NULL,
    FOREIGN KEY (producto_id) REFERENCES productos(id),
    FOREIGN KEY (transicion_id) REFERENCES transiciones(id),
    FOREIGN KEY (lote_id) REFERENCES lotes(id),
    FOREIGN KEY (revisado_por) REFERENCES usuarios(id),
    INDEX idx_producto (producto_id),
    INDEX idx_revisado (revisado_at)
);

CREATE TABLE intentos_login (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    usuario VARCHAR(50) NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    exito TINYINT(1) DEFAULT 0,
    INDEX idx_ip_fecha (ip, fecha)
);
```

**Notas del schema:**

- `productos.transicion_actual_id` denormalización útil para no recalcular el estado actual desde el historial en cada consulta. Se actualiza junto con `estado_actual` cuando se aplica una transición.
- `lote_items.resultado` permite trazar qué pasó con cada escaneo, incluso los que no generaron transición (importante para auditoría).
- `transiciones.es_ajuste_manual` distingue cambios desde el panel (admin/gestor) de los que vinieron por escaneo.
- Los catálogos (`categorias`, `proveedores`, `motivos`) tienen flag `activo` para soft-delete sin perder integridad referencial.

## Estructura de carpetas

```
/proyecto/
├── admin/                       # Panel web (admin + gestor)
│   ├── index.php                # Dashboard de stock
│   ├── login.php
│   ├── productos.php            # Buscador + listado filtrable
│   ├── producto-detalle.php     # Historial completo + acciones
│   ├── lotes.php                # Listado de lotes recientes
│   ├── lote-detalle.php         # Composición y resultado de un lote
│   ├── conflictos.php           # Cola de conflictos (admin + gestor)
│   ├── usuarios.php             # ABM (solo admin)
│   ├── categorias.php           # ABM (solo admin)
│   ├── proveedores.php          # ABM (solo admin)
│   ├── motivos.php              # ABM (solo admin)
│   ├── exportar.php
│   └── _layout.php
├── scan/                        # App PWA de escaneo (operador + transportista + admin + gestor)
│   ├── index.php                # Login + selector de lote + scanner unificado
│   ├── sw.js
│   ├── manifest.json
│   └── _layout.php
├── api/
│   ├── login.php                # POST {usuario, password}
│   ├── logout.php
│   ├── catalogos.php            # GET categorías, proveedores activos, motivos, transportistas
│   ├── lote-enviar.php          # POST lote completo con items
│   ├── lotes-pendientes.php     # GET lotes propios pendientes de respuesta (recovery)
│   ├── producto-historial.php   # GET historial de un producto (panel)
│   ├── conflicto-resolver.php   # POST marcar conflicto como revisado
│   └── ajuste-manual.php        # POST cambio manual de estado desde panel
├── lib/
│   ├── DB.php
│   ├── Auth.php                 # Login, sesión, autorización por rol
│   ├── MaquinaEstados.php       # Validador de transiciones, encapsula reglas R1-R10
│   ├── ProcesadorLote.php       # Aplica un lote completo siguiendo reglas
│   └── Models/
│       ├── Usuario.php
│       ├── Producto.php
│       ├── Lote.php
│       ├── Transicion.php
│       ├── Categoria.php
│       ├── Proveedor.php
│       └── Motivo.php
├── config/
│   ├── config.php
│   └── config.example.php
├── assets/
│   ├── vendor/bootstrap/
│   ├── vendor/html5-qrcode/
│   ├── vendor/idb/
│   └── js/
│       ├── scan/
│       │   ├── db.js            # IDB: cola_lotes + lote_actual
│       │   ├── scanner.js       # html5-qrcode + antirrebote
│       │   ├── sync.js          # Envío de cola al server
│       │   └── ui.js
│       └── admin/
│           └── dashboard.js     # Polling 30s + filtros
├── sql/
│   ├── schema.sql
│   └── migrations/
├── scripts/
│   └── crear-admin.php
├── .htaccess
└── composer.json
```

## Funcionalidades por módulo

### Panel web (`/admin/`)

Responsive desktop + mobile. Sesión persistente con cookie de 30 días.

#### Login (`login.php`)
Usuario/password, rate limit 5/15min por IP+usuario. CSRF activo.

#### Dashboard (`index.php`)
Visible por: admin, gestor.

- Tarjeta KPI: total productos, en depósito (INGRESADO+REINGRESADO), en reparto, entregados (último mes), conflictos pendientes
- Tabla cruzada categorías × estados con conteos. Filtro temporal opcional.
- Listado de últimos 10 lotes procesados con badge de conflictos generados
- Auto-refresh 30s
- Mobile: KPIs apilados, tabla con scroll horizontal

#### Productos (`productos.php`)
Visible por: admin, gestor.

- Búsqueda directa por código (input grande, foco automático): enter → ir a detalle del producto
- Filtros: categoría, estado, rango de fechas (creación o última transición), tiene_conflicto
- Tabla paginada con: código, categoría, estado actual, fecha última transición, badge ⚠ si conflicto
- Botón export Excel del listado filtrado

#### Detalle de producto (`producto-detalle.php`)
Visible por: admin, gestor.

- Header: código, categoría, estado actual, badge conflicto si aplica
- Timeline cronológico de todas las transiciones (desde más nueva a más vieja):
  - Estado, fecha cliente, fecha server (si difieren significativamente, mostrar ambos)
  - Lote asociado (link a detalle de lote) o "ajuste manual por X"
  - Responsable
  - Marca ⚠ si la transición es conflictiva
- Acciones (admin + gestor):
  - "Ajuste manual de estado" → modal con selector de nuevo estado, motivo (texto libre), confirmación
  - "Marcar conflictos como revisados" si tiene conflictos pendientes

#### Lotes (`lotes.php`)
Visible por: admin, gestor.

- Filtros: tipo, responsable, rango de fechas, con/sin conflictos
- Tabla con: fecha cierre, tipo, responsable, cantidad items, cantidad conflictos generados, link a detalle

#### Detalle de lote (`lote-detalle.php`)
Visible por: admin, gestor.

- Header con todos los datos del lote (tipo, responsable, categoría, proveedor, transportista, motivo, observaciones, timestamps)
- Tabla de items: código escaneado, resultado (aplicado/ignorado/conflicto), transición generada con link al producto

#### Conflictos (`conflictos.php`)
Visible por: admin, gestor.

- Listado de conflictos pendientes de revisar (`revisado_at IS NULL`)
- Filtros: tipo de conflicto, categoría, rango de fechas
- Por cada conflicto: producto, transición que lo generó, lote asociado, descripción
- Acciones inline:
  - "Marcar como revisado" con nota opcional
  - "Ajustar estado del producto" (atajo al ajuste manual)
- Toggle "ver resueltos" para histórico

#### ABM Usuarios (`usuarios.php`)
Visible por: solo admin.

CRUD con campos: usuario, nombre completo, rol, password (al crear/cambiar), activo. Validación de usuario único.

#### ABM Categorías, Proveedores, Motivos
Visible por: solo admin.

CRUD simple. Soft-delete (flag activo). Motivos con flag `editable_libre` (define si pide texto libre obligatorio al usarlo).

#### Export (`exportar.php`)
Visible por: admin, gestor.

Excel con filtros: rango de fechas, categoría, estado. Genera planilla con código, categoría, estado actual, fecha última transición, historial resumido (opcional).

### App de escaneo (`/scan/`)

PWA mobile-first. Usable por: admin, gestor, operador, transportista.

#### Login (`scan/index.php` con vista de login)

Mismo `index.php`, sección login con usuario/password. Requiere conexión inicial.

Si la cookie de sesión existe y es válida (30 días desde último uso) → ir directo a vista de scanner. Si no → mostrar login y pedir conexión.

**Sin cache de password offline**: a diferencia de EvenList, este sistema no cachea hash de password. La razón es que los usuarios son fijos y conocidos, no necesitan loguearse en dispositivos nuevos offline. El operador labura siempre con su dispositivo asignado.

#### Vista de scanner

Tres pantallas conmutables sin recargar:

**1. Selector de "Nuevo lote"**

- Botón grande "Nuevo lote"
- Indicador de lotes en cola pendientes de sync (badge con número)
- Botón "Ver cola" → modal con lista de lotes pendientes/enviados/con-error y sus detalles
- Header con usuario logueado + botón logout

**2. Configuración del lote (al tocar "Nuevo lote")**

Formulario con campos según el rol del usuario y el tipo seleccionado:

- Selector "Tipo de lote": dropdown con los tipos permitidos según rol
- Campos condicionales según tipo (siguiendo la tabla de la spec original):
  - INGRESO → categoría obligatoria, proveedor opcional, n° remito opcional
  - SALIDA_REPARTO → transportista obligatorio (dropdown de usuarios rol=transportista activos)
  - ENTREGA → ninguno (transportista = usuario logueado autocompleto)
  - REINGRESO → motivo obligatorio (dropdown de motivos tipo=reingreso), texto libre si motivo es editable_libre
  - SALIDA_DEVOLUCION → proveedor obligatorio, motivo obligatorio, n° remito opcional
  - BAJA → motivo obligatorio
- Campo observaciones (siempre opcional, texto libre)
- Botón "Iniciar escaneo" → guarda lote abierto en IDB con `timestamp_apertura` actual, va a pantalla de escaneo

**Importante**: los catálogos (categorías activas, proveedores activos, motivos activos por tipo, transportistas activos) se cargan desde `/api/catalogos.php` y se cachean en IDB. Se refrescan al login y cada vez que el dispositivo tiene conexión. Si el dispositivo está offline desde hace tiempo, usa el cache anterior con warning visible.

**3. Pantalla de escaneo**

Layout vertical celular:

- **Header sticky** (~10%): tipo de lote + datos resumidos del lote (categoría/transportista/etc), contador "N items", indicador conectividad (🟢🔴🟡), botón ⚠ "Cancelar lote" (con confirmación)
- **Preview de cámara** (~50%): overlay de targeting, barra inferior interna con linterna + cambio cámara
- **Lista de items escaneados** (~25%): scroll vertical mostrando últimos 5 escaneos, cada uno con código, hora y estado visual (✓ recién escaneado)
- **Botón footer** (~15%): "Cerrar y enviar lote" grande, alcanzable con pulgar

**Lógica de escaneo:**

- Antirrebote 2 segundos sobre el mismo código (más permisivo que EvenList porque acá el operador puede escanear el mismo modelo de colchón muchas veces seguido — son códigos distintos)
- Sin pausa explícita del decoder después de cada lectura, lectura continua
- Cada escaneo: agrega item a IDB con `{codigo, timestamp_cliente}`, feedback visual (item aparece en la lista de arriba con fade), vibración corta `[50]`, beep agudo opcional toggleable
- Si el código ya fue escaneado en el mismo lote: feedback amarillo `⚠ ya escaneado en este lote` + vibración `[200]`. NO agrega item duplicado.

**Cerrar y enviar lote:**

- Guarda `timestamp_cierre`, marca lote en IDB como `pendiente_sync`
- Dispara sync inmediato si hay conexión
- Vuelve a pantalla 1 (selector de "Nuevo lote") con confirmación visual

#### Sync de lotes

Worker JS cada 15 segundos:

1. Si hay conexión y hay lotes con estado `pendiente_sync`:
   - Por cada lote, POST a `/api/lote-enviar.php` con lote completo + items
   - Si server responde 200 → marca lote como `sincronizado` en IDB, guarda respuesta (cantidad de conflictos generados)
   - Si server responde 401 → sesión expirada (raro pero posible si pasaron 30 días). Marca lote como `error_auth`, muestra modal "Tu sesión expiró, volvé a loguearte". Al re-loguearse, los lotes con `error_auth` vuelven a `pendiente_sync` automáticamente.
   - Si server responde 4xx no-401 (error de datos) → marca como `error_datos` con detalle. Estos requieren intervención manual desde el modal de cola.
   - Si server responde 5xx o falla la red → mantiene `pendiente_sync`, reintenta en próximo ciclo

**Modal de cola** (accesible desde el badge):
- Lista de lotes con estado: pendiente, sincronizado (mostrar respuesta), error
- Por cada lote: tipo, fecha cierre, cantidad items, estado de sync, botón "Reintentar" si error
- Botón "Sincronizar todo ahora"
- Lotes `sincronizado` se purgan automáticamente después de 7 días (libera IDB)

### API

Todos los endpoints retornan JSON. Sesión PHP requerida (excepto login).

**`POST /api/login.php`**: `{usuario, password}` → setea sesión, retorna `{ok: true, usuario: {id, nombre, rol}, catalogos: {...}}`. Rate limit 5/15min/IP+usuario.

**`POST /api/logout.php`**: destruye sesión.

**`GET /api/catalogos.php`**: retorna categorías activas, proveedores activos, motivos activos agrupados por tipo, transportistas activos. Cliente cachea en IDB.

**`POST /api/lote-enviar.php`**: recibe lote completo:
```json
{
  "uuid": "...",
  "tipo": "INGRESO",
  "categoria_id": 5,
  "proveedor_id": null,
  "transportista_id": null,
  "motivo_id": null,
  "motivo_libre": null,
  "observaciones": "...",
  "numero_remito": null,
  "timestamp_apertura": "2026-05-18T10:00:00Z",
  "timestamp_cierre": "2026-05-18T10:15:00Z",
  "dispositivo_info": "Mozilla/...",
  "items": [
    { "codigo": "ABC123", "timestamp_cliente": "2026-05-18T10:00:15Z" },
    { "codigo": "ABC124", "timestamp_cliente": "2026-05-18T10:00:18Z" }
  ]
}
```

Procesamiento server-side:
1. Verificar idempotencia por `uuid`. Si ya procesado, retornar resultado guardado.
2. Validar permisos del rol del usuario para el tipo de lote.
3. Validar campos obligatorios según tipo.
4. Crear registro en `lotes`.
5. Procesar cada item en orden:
   - Aplicar reglas R1-R10
   - Insertar en `transiciones` y `lote_items`
   - Actualizar `productos.estado_actual` y `tiene_conflicto` si corresponde
   - Insertar en `conflictos_producto` si aplica
6. Retornar `{ok: true, lote_id, items_procesados, transiciones_aplicadas, items_ignorados, conflictos_generados, detalle: [...]}`. El detalle incluye por cada item el resultado para que el cliente lo pueda mostrar.

**`GET /api/lotes-pendientes.php`**: si el cliente sospecha que perdió la respuesta de un lote (recovery), puede consultar por UUID. Retorna si el lote está procesado y con qué resultado.

**`GET /api/producto-historial.php?codigo=...`**: retorna producto + historial de transiciones + conflictos.

**`POST /api/conflicto-resolver.php`**: `{conflicto_id, nota?}` → marca como revisado. Requiere rol admin o gestor.

**`POST /api/ajuste-manual.php`**: `{codigo, nuevo_estado, motivo?}` → crea transición manual. Requiere admin o gestor. Si el código no existe, error.

## IndexedDB (cliente)

Object stores:

- **`catalogos`**: keyPath manual. Records: `categorias`, `proveedores`, `motivos`, `transportistas`, `last_updated`. Refrescado al login y periódicamente.
- **`lote_actual`**: keyPath manual con key `'current'`. Single record con el lote en curso (si hay uno abierto). Se borra al cerrar lote.
- **`cola_lotes`**: keyPath `uuid`. Records: lote completo + items + estado (`pendiente_sync`, `sincronizado`, `error_auth`, `error_datos`) + respuesta del server si aplica.

## Service Worker

- Cache-first para `/assets/*` (Bootstrap, html5-qrcode, idb)
- Network-first para HTML de `/scan/*` y `/admin/*`
- Versionado con `CACHE_VERSION`
- Sin scope dinámico (a diferencia de EvenList)

## Manifest

PWA estándar. Nombre "Trazock", colores corporativos, ícono. Permite instalación a home screen pero no es requisito de uso.

## Consideraciones de seguridad

1. PDO prepared statements en todas las queries.
2. `password_hash()` + `password_verify()` para passwords.
3. CSRF en formularios web del panel admin.
4. Sesión PHP con `httponly`, `secure`, `samesite=lax`. Cookie con duración 30 días renovable.
5. `htmlspecialchars()` en todo output.
6. Rate limiting en login (5/15min/IP+usuario).
7. HTTPS obligatorio.
8. Headers: `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, CSP con `media-src 'self'` y `img-src 'self' data:`.
9. Autorización por rol en cada endpoint. **Cada API valida que el usuario logueado tenga el rol adecuado para esa acción.** No alcanza con estar logueado.
10. Validación server-side de tipo de lote vs rol del usuario en `lote-enviar.php`. Un transportista enviando un lote INGRESO debe ser rechazado con 403.
11. Validación de integridad referencial: categoria_id, proveedor_id, transportista_id, motivo_id deben existir y estar activos.
12. Tamaño máximo de payload por lote: 1000 items. Si se excede, rechazar y obligar a partir el lote.

## Consideraciones de performance

1. Índices definidos en schema. Críticos: `productos(codigo)`, `productos(estado_actual)`, `transiciones(producto_id, timestamp_cliente)`.
2. La denormalización `productos.estado_actual` evita JOIN con transiciones en cada consulta de listado.
3. Búsqueda por código en panel: índice UNIQUE en `productos.codigo` da lookup O(1).
4. Listado de productos paginado server-side (no client-side filtering, no escala).
5. Sync de lotes: un POST por lote completo, no por item. Lote típico será 5-50 items.
6. Cola de catálogos cacheada en cliente: no se piden en cada login si la última actualización es <1h, salvo refresh forzado.
7. Dashboard con polling 30s. KPIs son COUNT con índices, no debería ser lento hasta cientos de miles de productos.

## Casos borde

1. **Lote enviado dos veces** (cliente reintentó por timeout) → R1, idempotencia por uuid. Server retorna mismo resultado.
2. **Operador escanea el mismo código 3 veces seguidas en el mismo lote** → cliente filtra por antirrebote, server refuerza con R2.
3. **Operador escanea código que no existe en lote SALIDA_REPARTO** → R7: alta automática + conflicto. Admin revisará.
4. **Dos operadores hacen ingreso del mismo código offline en lotes distintos** → primer lote crea el producto, segundo lote: R9 → si estado actual es INGRESADO (lo es) y nuevo estado también es INGRESADO → R3, ignorar silenciosamente. Sin conflicto.
5. **Operador escanea como REINGRESO un producto que está EN_REPARTO** → legal, sin conflicto.
6. **Operador escanea como REINGRESO un producto que está INGRESADO** → R6 ilegal, se aplica, conflicto.
7. **Lote con timestamp del cliente alterado (fecha del futuro)** → no hay protección server-side por defecto. Posible enhancement: rechazar lotes con timestamp_cliente > now() + tolerancia (15 min).
8. **Producto con conflicto se le hace ajuste manual desde panel** → ajuste se aplica, conflicto NO se cierra automáticamente (admin decide si fue resolución). Botón explícito "marcar revisado".
9. **Admin elimina (soft-delete) una categoría que tiene productos** → permitido. Categoría inactiva no aparece en dropdown de nuevos lotes pero los productos existentes mantienen el FK.
10. **Usuario transportista se da de baja con lotes pendientes de su autoría** → no se borran, queda histórico. La cuenta queda `activo=0` y no puede loguearse pero los lotes quedan asociados.
11. **Sesión expira mientras hay lotes en cola** → cliente detecta 401 al sincronizar, lotes pasan a `error_auth`, modal pide re-login. Al re-loguear, los lotes vuelven a `pendiente_sync` y se reintenta automáticamente.
12. **Lote con 500 items toma mucho tiempo en procesarse server-side** → procesamiento en transacción. Si excede timeout PHP, retornar 500 → cliente reintenta. Idempotencia garantiza que no se duplique al reintento exitoso.

## Diferencias arquitectónicas vs EvenList

| Aspecto | EvenList | Trazock |
|---|---|---|
| Modelo de uso | Eventos efímeros | Sistema permanente |
| Autoridad de validación | Cliente | Server |
| Inteligencia del cliente | Alta | Mínima ("bobo") |
| Cache de catálogo en cliente | Completo (listado de invitados) | Mínimo (solo catálogos auxiliares: categorías, motivos, etc) |
| Auth | PIN + cache offline de hash | Usuario/password + cookie 30 días |
| Identidad del cliente | dispositivo_id por evento | usuario fijo |
| Conflictos | Idempotencia simple | Reglas complejas R1-R10 + revisión manual |
| Conceptualización del trabajo | Confirmación individual | Lotes (agrupador) |
| URL de la app | `/app/{slug}` (multi-evento) | `/scan/` (única) |
| Tablas | ~6 | ~10 |

## Entregables

1. Código fuente comentado.
2. `sql/schema.sql` + migrations + INSERT admin de prueba (`admin` / `admin123`).
3. `config/config.example.php` documentado.
4. `composer.json`.
5. `README.md` con:
   - Requisitos del servidor
   - Instalación paso a paso
   - Roles y cómo crear usuarios
   - Cómo cargar el primer evento (categorías, proveedores, motivos, usuarios)
   - Cómo usar la PWA en celulares
   - Troubleshooting (sync, conflictos, sesiones)
6. `.htaccess` con bloqueo de directorios sensibles.
7. Documento `reglas-procesamiento.md` con la implementación detallada de R1-R10 (para auditoría).

## Orden de desarrollo sugerido

1. **Fase 1**: Schema + lib base (DB, Auth, MaquinaEstados) + login admin + crear admin de prueba
2. **Fase 2**: ABM de catálogos (usuarios, categorías, proveedores, motivos) + autorización por rol
3. **Fase 3**: `ProcesadorLote.php` con todas las reglas R1-R10 + endpoint `lote-enviar.php` + tests unitarios manuales del procesador con casos borde
4. **Fase 4**: Panel web: dashboard + productos + producto-detalle + lotes + lote-detalle + conflictos + export
5. **Fase 5**: App `/scan/` online primero: login + selector + configuración de lote + scanner + envío directo (sin cola aún)
6. **Fase 6**: Offline en `/scan/`: IDB + cola de lotes + sync worker + Service Worker + cache de catálogos
7. **Fase 7**: Hardening + responsive mobile del panel + docs

## Criterios de aceptación

- [ ] Admin puede crear usuarios con los 4 roles
- [ ] Admin puede crear categorías, proveedores, motivos
- [ ] Login con cada rol redirige al lugar correcto (panel para admin/gestor, scan para operador/transportista)
- [ ] Operador puede abrir un lote de tipo INGRESO, escanear 50 códigos, cerrarlo. Todos quedan registrados en server como INGRESADO.
- [ ] Operador NO puede abrir un lote de tipo ENTREGA (debe ser rechazado por el cliente; si por algún medio llega al server, server rechaza con 403)
- [ ] Transportista NO puede abrir lotes de otro tipo que no sea ENTREGA
- [ ] **Test crítico**: operador hace lote INGRESO offline con 30 colchones, sin conexión 2 horas, recupera conexión → todo se sincroniza correctamente
- [ ] **Test crítico**: dos operadores ingresan el mismo código offline en lotes distintos → primer ingreso crea el producto, segundo se ignora (R3)
- [ ] **Test crítico**: operador escanea como SALIDA_REPARTO un código nunca ingresado → producto se crea automáticamente con estado EN_REPARTO y se marca conflicto (R7)
- [ ] **Test crítico**: transportista escanea como ENTREGA un producto que no está EN_REPARTO → transición se aplica (queda ENTREGADO), se marca conflicto, gestor lo ve en cola
- [ ] **Test crítico**: operador escanea dos veces el mismo código en el mismo lote → solo se registra una vez (R2)
- [ ] **Test crítico**: lote retroactivo (timestamp viejo) no afecta estado actual si hay transiciones más nuevas (R4)
- [ ] Gestor puede ver dashboard, productos, lotes, conflictos. No puede ABM de usuarios/catálogos.
- [ ] Gestor puede resolver conflictos.
- [ ] Admin y Gestor pueden hacer ajuste manual de estado desde panel-detalle de producto.
- [ ] Búsqueda de producto por código devuelve historial completo
- [ ] Export Excel funciona con todos los filtros
- [ ] App responsive en celular vertical: scanner usable con una mano
- [ ] Conflictos visibles en cola con detalle completo
- [ ] Sesión persistente 30 días: cerrar pestaña y reabrir no requiere login
- [ ] Sesión expira correctamente luego de 30 días sin uso
- [ ] Catálogos cacheados en cliente, refrescan al login y cuando hay conexión

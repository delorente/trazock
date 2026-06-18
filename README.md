# Trazock — Sistema de trazabilidad de stock

Sistema web para trazar productos individuales (típicamente colchones, pero genérico)
desde su ingreso al depósito hasta la entrega al cliente, con reparto, reingresos,
devoluciones y bajas. Incluye un **panel web** (admin/gestor) y una **app PWA de
escaneo** (operador/transportista) que funciona sin conexión y sincroniza al recuperar
red. La validación de transiciones es **100% server-side**.

## Stack

- PHP 8.3 (sin frameworks), MySQL 8 / MariaDB 10.5+
- Bootstrap 5, Bootstrap Icons, fuente Inter, JavaScript vanilla, IndexedDB (idb v8), html5-qrcode, PhpSpreadsheet
- Tema oscuro (panel + app de escaneo)
- PWA: Service Worker + Manifest
- Todas las librerías y fuentes servidas localmente desde `assets/vendor/` (sin CDNs)

## Requisitos del servidor

- PHP 8.3 con extensiones `pdo_mysql`, `mbstring`, `gd` (íconos), `zip` (PhpSpreadsheet)
- MySQL 8.0+ o MariaDB 10.5+
- Nginx (o Apache con `mod_rewrite` y `mod_headers`)
- OpenSSL / Let's Encrypt para HTTPS (obligatorio en producción)
- Composer

## Estructura

```
admin/    Panel web (dashboard, productos, lotes, conflictos, ABM, export)
scan/     App PWA de escaneo (index.php, sw.js, manifest.json)
api/      Endpoints JSON (login, catalogos, lote-enviar, etc.)
lib/      Núcleo: DB, Auth, MaquinaEstados, ProcesadorLote, Models/
config/   config.php (gitignored) + config.example.php + nginx.conf.example
assets/   vendor/ (bootstrap, html5-qrcode, idb) + css + js
sql/      schema.sql + migrations/
scripts/  crear-admin.php
tests/    procesador-lote-casos.php (PHP) + e2e-offline.cjs (Chrome)
```

## Instalación paso a paso

1. **Clonar** el repo en el docroot (o configurar un virtualhost que apunte a él).

2. **Dependencias:**
   ```
   composer install
   ```

3. **Base de datos:** crear la base y cargar el schema (incluye el admin de prueba):
   ```
   mysql -u root -e "CREATE DATABASE trazock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   mysql -u root trazock < sql/schema.sql
   ```
   Alternativamente, aplicar las migraciones en orden numérico desde `sql/migrations/`.

4. **Configuración:** copiar y editar:
   ```
   cp config/config.example.php config/config.php
   ```
   Ajustar `DB_*`, `APP_URL`, y en **producción**:
   - `APP_DEBUG` = `false`
   - `SESSION_COOKIE_SECURE` = `true` (requiere HTTPS)

5. **Permisos / servidor web:**
   - Apache: el `.htaccess` ya bloquea `/config`, `/lib`, `/sql`, `/scripts` y dotfiles.
     Habilitar `mod_headers` para los headers de seguridad y `Service-Worker-Allowed`.
   - Nginx: ver `config/nginx.conf.example` (bloqueo de directorios, headers de
     seguridad y `Service-Worker-Allowed` para el Service Worker).

6. **HTTPS (producción):** con Let's Encrypt / certbot:
   ```
   sudo certbot --nginx -d trazock.example.com
   ```

7. **Primer admin:** ya existe `admin` / `admin123` (seed de desarrollo).
   **Rotar de inmediato** o crear uno propio y borrar el seed:
   ```
   php scripts/crear-admin.php miadmin "Mi Nombre" "ClaveFuerte!" admin
   ```

## Roles

| Rol | Panel web | Escaneo |
|---|---|---|
| **admin** | completo + ABM de usuarios y catálogos + conflictos | todos los tipos |
| **gestor** | completo + conflictos (sin ABM) | todos los tipos |
| **operador** | sin acceso al panel | INGRESO, SALIDA_REPARTO, REINGRESO, SALIDA_DEVOLUCION, BAJA |
| **transportista** | sin acceso al panel | sólo ENTREGA |

Al loguearse, admin/gestor van al panel; operador/transportista a `/scan/`.

## Configurar un cliente desde cero

1. Entrar al panel como **admin**.
2. **Categorías** (ej. "Colchones 1 plaza"), **Proveedores**, **Motivos**
   (reingreso/devolución/baja; marcar "texto libre" en los tipo "Otros").
3. **Usuarios:** crear operadores, transportistas y gestores.
4. **Primer ingreso:** el operador abre la app `/scan/`, elige "Nuevo lote" → INGRESO,
   selecciona categoría, escanea los códigos y cierra el lote. Los productos quedan
   `INGRESADO`.

## Uso de la app de escaneo (PWA)

1. En el celular, abrir la URL del sistema + `/scan/` (ej. `https://trazock.example.com/scan/`).
2. Iniciar sesión (requiere conexión la primera vez; luego la sesión dura 30 días).
3. Opcional: "Agregar a pantalla de inicio" para usarla como app.
4. "Nuevo lote" → elegir tipo y completar los datos → "Iniciar escaneo".
5. Escanear: verde = agregado, amarillo = ya estaba en el lote. Vibración y beep opcional.
6. "Cerrar y enviar lote": si hay conexión se envía en el momento; si no, queda en cola
   y se envía solo al recuperar red. El badge "Ver cola" muestra los pendientes.

## Manejo de conflictos

Aparecen cuando la realidad física no coincide con el estado del sistema:

- **Transición ilegal:** se escaneó un producto en un paso que la máquina de estados no
  permite (ej. ENTREGA de algo que estaba INGRESADO). Se aplica igual y se marca.
- **Producto inexistente en lote no-INGRESO:** se escaneó un código nunca ingresado en
  un lote que no es de INGRESO. Se da de alta automáticamente y se marca.

Admin/gestor los ven en **Conflictos** o en el detalle del producto. "Marcar revisado"
(con nota opcional) los resuelve; cuando un producto no tiene más conflictos pendientes,
se limpia su bandera. También se puede hacer un **ajuste manual** de estado (no genera
conflicto, queda registrado con el usuario).

## Tests

```
php tests/procesador-lote-casos.php     # 15 casos de R1–R10 (BD de test)
node tests/e2e-offline.cjs              # flujo offline en Chrome (requiere puppeteer-core)
```

`procesador-lote-casos.php` **trunca** las tablas transaccionales: no correr en
producción.

## Probar desde el celular

La cámara del navegador sólo funciona en `localhost` o por **HTTPS**. Para probar el
escáner desde el celular, lo más simple es un túnel HTTPS con **ngrok**:

1. Con WAMP corriendo (puerto 80), levantá: `ngrok http 80`
2. ngrok da una URL `https://xxxxx.ngrok-free.app`. En el celular abrí la **ruta completa**:
   `https://xxxxx.ngrok-free.app/proyectos/trazock/scan/`
3. (plan free de ngrok) Tocá "Visit Site" en el aviso, logueate y permití la cámara.

Como las URLs de la app son relativas al host, **no hace falta cambiar `config.php`**.
La IP de la LAN (`http://192.168.x.x/...`) sirve para el panel, pero **no para el
escáner** (sin HTTPS no hay cámara). En `/scan/` se puede "Agregar a pantalla de
inicio" para usarla a pantalla completa como app.

## Zona horaria

Los timestamps se guardan en UTC y se muestran convertidos a `DISPLAY_TZ`
(en `config.php`, por defecto `America/Argentina/Buenos_Aires`). Cambiá esa constante
para otra zona.

## Troubleshooting

- **El sync no envía la cola:** verificar conexión; abrir "Ver cola" y "Sincronizar todo
  ahora". Si un lote quedó en *Error de datos*, ver el detalle (ej. categoría inactivada)
  y "Reintentar". Si dice *Sesión expirada*, volver a iniciar sesión: los lotes se
  reenvían solos.
- **La cámara no abre:** dar permiso de cámara al sitio. Requiere HTTPS (o `localhost`).
  Probar el botón de cambiar cámara.
- **El Service Worker no cachea `/assets`:** el server debe enviar
  `Service-Worker-Allowed: /` para `scan/sw.js` (ver `nginx.conf.example`; en Apache,
  `mod_headers`). Sin eso, la cola offline igual funciona (vive en IndexedDB).
- **Conflictos masivos tras un ingreso:** suele ser un operador escaneando un tipo de
  lote equivocado. Revisar en Conflictos y, si corresponde, ajustar estados manualmente.
- **Sesión:** dura 30 días de inactividad (renovable en cada uso). Pasado ese tiempo,
  pide re-login.

## Seguridad

PDO con prepared statements; `password_hash`/`password_verify`; CSRF en formularios del
panel y en acciones de la API; rate limit de login (5/15 min por IP+usuario); sesión
`httponly`+`samesite=lax` (+`secure` en prod); `htmlspecialchars` en todo output;
headers `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`,
`Permissions-Policy` y CSP; autorización por rol en cada endpoint.

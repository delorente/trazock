# Checklist de despliegue a producción — Seguimiento público

Guía para publicar Trazock en producción con la feature de **seguimiento público**
(landing donde el comprador rastrea su producto vía un enlace con token).

Es un deploy liviano: sin workers, cron, colas ni SMTP. Si el hosting tiene
**PHP 8.3 + Composer + HTTPS**, alcanza. El único punto que rompe enlaces de forma
silenciosa es `APP_URL` mal configurada → prioridad en el paso 4.

---

## 1. Pre-requisitos del servidor
- [ ] PHP **8.3** con `pdo_mysql`, `mbstring`, `gd`, `zip` (`php -m` para confirmar)
- [ ] MySQL 8.0+ / MariaDB 10.5+
- [ ] **Composer** disponible (o `vendor/` provisto por otro medio)
- [ ] Acceso SSH (ideal) y poder emitir certificado HTTPS
- [ ] Dominio apuntando al server (DNS A/AAAA listo)

## 2. Código
- [ ] Subir el proyecto al docroot (git pull / deploy)
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `node_modules/` y archivos de test NO son necesarios en prod (`assets/vendor/` sí)

## 3. Base de datos

**Instalación NUEVA:**
- [ ] `CREATE DATABASE trazock CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
- [ ] `mysql -u <user> -p trazock < sql/schema.sql` (ya incluye 003 + 004)

**Base de prod YA existente (deploy incremental):**
- [ ] NO correr `schema.sql`. Aplicar solo migraciones pendientes en orden:
  ```
  mysql -u <user> -p trazock < sql/migrations/004_seguimiento_publico.sql
  ```
  (y `003_motivo_multitipo.sql` si esa base nunca la corrió)
- [ ] Verificar:
  ```sql
  SHOW COLUMNS FROM productos LIKE 'token_publico';            -- debe existir
  SELECT estado, titulo, visible, orden FROM estados_publicos; -- 6 filas
  ```

## 4. Configuración (`config/config.php`) — lo más crítico
- [ ] Crear desde el ejemplo: `cp config/config.example.php config/config.php`
- [ ] `DB_HOST/DB_NAME/DB_USER/DB_PASS` del entorno de prod
- [ ] **`APP_URL`** = dominio real con `https://` y SIN barra final
      (de acá se arma el enlace que se manda al cliente; si queda mal, los enlaces salen rotos)
- [ ] **`APP_DEBUG = false`**
- [ ] **`SESSION_COOKIE_SECURE = true`** (requiere HTTPS)
- [ ] `DISPLAY_TZ` correcto (`America/Argentina/Buenos_Aires`)
- [ ] Confirmar que `config/config.php` no quede accesible por web (lo bloquea `.htaccess`/Nginx)

## 5. Web server + HTTPS
- [ ] **Apache:** `mod_rewrite` y `mod_headers` activos; el `.htaccess` ya bloquea `config/lib/sql/scripts`
- [ ] **Nginx:** aplicar `config/nginx.conf.example` (bloqueo de directorios + headers)
- [ ] Emitir certificado: `sudo certbot --nginx -d trazock.tudominio.com` (o equivalente Apache)
- [ ] Forzar redirección HTTP → HTTPS

## 6. Verificación funcional (en el dominio real)
- [ ] **Panel:** login admin OK
- [ ] **Administración → Seguimiento:** se ven los 6 estados; editar un texto y guardar funciona
- [ ] **Detalle de un producto** real → botón **"Generar enlace de seguimiento"** →
      aparece el enlace, **Copiar / WhatsApp / Email / Ver página**
- [ ] Abrir el enlace público en incógnito / desde el celular:
  - [ ] Carga por HTTPS, muestra estado + línea de tiempo
  - [ ] NO aparece el código interno ni datos sensibles (revisar el HTML de la página)
  - [ ] `…/seguimiento/?t=token-falso` → **404** "Enlace no disponible"
- [ ] El enlace copiado usa el dominio de prod (no `localhost`)

## 7. Post-deploy / seguridad
- [ ] **Rotar el admin de prueba** (`admin` / `admin123`):
  ```
  php scripts/crear-admin.php <usuario> "<Nombre>" "<ClaveFuerte>" admin
  ```
  y borrar el seed de desarrollo
- [ ] **Backups automáticos de la DB** (el seguimiento depende de datos reales)
- [ ] (Opcional, recomendado) **Cloudflare** adelante: HTTPS/CDN para `assets/` + protección básica del endpoint público
- [ ] Confirmar que `display_errors` esté off (no filtrar errores en el endpoint público)

---

### Notas
- La zona horaria del server no importa: la app fuerza `time_zone='+00:00'` por conexión
  y convierte a `DISPLAY_TZ` al mostrar.
- Esta feature no agrega infraestructura nueva (sin SMTP ni API de WhatsApp: el enlace se
  envía manualmente desde el panel).

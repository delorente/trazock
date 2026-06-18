# Pruebas de aceptación — Trazock

Guion de pruebas **manuales** end-to-end para validar el sistema completo. Un humano
puede correrlas en orden. Requiere el sistema instalado y la BD cargada con el schema.

Usuarios de prueba (si usaste el seed / los creaste): `admin/admin123`,
`gestor1/gestor123`, `operador1/oper123`, `trans1/trans123`.

> Sugerencia: antes de empezar, dejá el panel abierto en una pestaña (admin) y la app
> `/scan/` en el celular (o en Chrome con la consola de dispositivos móviles).

---

## A. Roles y redirección

1. Login en `/admin/login.php` como **admin** → ve el panel con todos los menús
   (incluye Usuarios, Categorías, Proveedores, Motivos).
2. Login como **gestor1** → ve panel SIN los ABM (sólo Dashboard, Productos, Lotes,
   Conflictos, Exportar).
3. Como gestor, entrar a mano a `/admin/usuarios.php` → **403**.
4. Login como **operador1** en `/admin/login.php` → redirige a `/scan/`.
5. Login como **trans1** → redirige a `/scan/`.

## B. ABM de catálogos (admin)

6. Crear categoría "Colchones 1 plaza" → aparece. Crear otra con el mismo nombre →
   error de duplicado.
7. Crear un proveedor y un motivo de cada tipo (reingreso/devolución/baja). Marcar uno
   como "texto libre".
8. Inactivar una categoría → queda al final con badge "Inactiva". Reactivarla.

## C. Flujo principal: INGRESO → REPARTO → ENTREGA

9. En `/scan/` como **operador1**: "Nuevo lote" → **INGRESO**, elegir categoría,
   escanear 3 códigos nuevos (ej. C-1, C-2, C-3), "Cerrar y enviar".
   - En el panel → Productos: los 3 aparecen en estado **INGRESADO**.
10. Operador: "Nuevo lote" → **SALIDA_REPARTO**, elegir transportista, escanear C-1 y C-2,
    enviar.
    - Panel: C-1 y C-2 en **EN_REPARTO**; C-3 sigue **INGRESADO**.
11. En `/scan/` como **trans1**: "Nuevo lote" → **ENTREGA** (único tipo disponible),
    escanear C-1, enviar.
    - Panel: C-1 en **ENTREGADO**, sin conflicto. El timeline de C-1 muestra las 3
      transiciones en orden.

## D. Flujo con reingreso

12. Operador: **REINGRESO** (motivo) sobre C-2 (está EN_REPARTO) → legal.
    - Panel: C-2 en **REINGRESADO**.
13. Operador: **SALIDA_REPARTO** sobre C-2 → vuelve a **EN_REPARTO** (legal).
14. trans1: **ENTREGA** sobre C-2 → **ENTREGADO**.

## E. Conflictos

15. trans1: **ENTREGA** sobre C-3 (está INGRESADO, nunca pasó por reparto) → se aplica
    pero genera **conflicto** (transición ilegal).
    - Panel → Dashboard: el KPI "Conflictos pendientes" sube.
    - Panel → Conflictos: aparece el conflicto de C-3 con su descripción.
16. operador1: **SALIDA_REPARTO** escaneando un código nunca visto (ej. X-99) → el
    producto se crea automáticamente en **EN_REPARTO** con conflicto
    "producto inexistente".
17. En Conflictos, "Marcar revisado" en el conflicto de C-3 (con nota) → desaparece de
    pendientes. En el detalle de C-3, la bandera de conflicto se limpia si no quedan
    pendientes.
18. En el detalle de un producto, "Ajuste manual de estado" → cambiar a otro estado con
    motivo. Confirmar. El timeline muestra la transición marcada "Ajuste manual" y el
    estado cambia (sin generar conflicto).

## F. Dashboard, listados y export

19. Dashboard: KPIs y la tabla categoría×estado reflejan lo cargado. Esperar 30 s →
    se refresca solo (o cambiar algo desde la app y ver que actualiza sin recargar).
20. Productos: buscar por código exacto en la caja grande + Enter → va al detalle.
    Filtrar por estado/categoría/conflicto.
21. Lotes: filtrar por tipo y responsable; abrir un detalle de lote y revisar la tabla
    de items con su resultado (aplicado / ignorado / con conflicto).
22. Exportar: desde Productos, "Exportar Excel" → descarga un `.xlsx` con los productos
    filtrados.

## G. Offline (app de escaneo)

23. En la app, activar modo avión / desconectar red. Abrir "Nuevo lote" → INGRESO:
    los catálogos cargan igual (cache). Escanear varios códigos y "Cerrar y enviar".
    - Mensaje "Lote guardado sin conexión…". El badge "Ver cola" muestra 1 pendiente.
24. Reconectar. En ≤15 s (o "Sincronizar todo ahora") el lote pasa a **Sincronizado**;
    en el panel aparecen los productos.
25. **Idempotencia:** desde "Ver cola", "Reintentar"/reenviar no duplica: el server
    responde idempotente y los conteos no cambian.
26. **Sesión:** cerrar y reabrir la pestaña → entra directo al selector (sin re-login),
    porque la cookie dura 30 días.

## H. Seguridad (smoke)

27. Login con contraseña incorrecta → mensaje de error. Repetir 6 veces rápido →
    bloqueo temporal por rate limit.
28. Acceder directo a `/config/config.php`, `/lib/DB.php`, `/sql/schema.sql` → bloqueado.
29. Enviar a `/api/lote-enviar.php` (con sesión de operador) un lote tipo ENTREGA →
    **403**. Un INGRESO sin categoría → **400**.

---

### Resultado esperado

Todos los pasos se comportan como se describe. Las transiciones siguen la máquina de
estados; las ilegales se aplican con conflicto; el estado actual respeta el timestamp
del cliente; y la app de escaneo funciona offline con sincronización idempotente.

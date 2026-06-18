# Reglas de procesamiento de lotes (R1–R10) — implementación

Este documento describe **cómo está implementado** el procesador de lotes
(`lib/ProcesadorLote.php`) y la máquina de estados (`lib/MaquinaEstados.php`,
`lib/Estado.php`, `lib/TipoLote.php`), para auditoría. Es fiel al código, no a la
intención: si difiere de la spec, prevalece lo que dice acá sobre lo que hace el
sistema.

## Componentes

| Archivo | Responsabilidad |
|---|---|
| `lib/Estado.php` | enum `Estado` (6 estados). |
| `lib/TipoLote.php` | enum `TipoLote` (6 tipos) + `estadoDestino()`. |
| `lib/MaquinaEstados.php` | transiciones legales + permisos rol→tipo. |
| `lib/ProcesadorLote.php` | aplica un lote completo, R1–R10, en una transacción. |
| `lib/Models/Transicion.php` | timeline (`estadoEnTimestamp`, `existeMasReciente`) + inserción. |
| `lib/Models/Producto.php` | búsqueda con `FOR UPDATE`, alta, fijar estado, flags de conflicto. |
| `lib/Models/Lote.php` | encabezado, items, idempotencia (`resumen`). |
| `lib/Models/Conflicto.php` | alta y resolución de conflictos. |

## Máquina de estados

Estados: `INGRESADO`, `EN_REPARTO`, `ENTREGADO`, `REINGRESADO`, `DEVUELTO`, `BAJA`.

Estado destino por tipo de lote (`TipoLote::estadoDestino()`):

| Tipo de lote | Estado destino |
|---|---|
| INGRESO | INGRESADO |
| SALIDA_REPARTO | EN_REPARTO |
| ENTREGA | ENTREGADO |
| REINGRESO | REINGRESADO |
| SALIDA_DEVOLUCION | DEVUELTO |
| BAJA | BAJA |

Transiciones legales (exactamente estas 10; `MaquinaEstados::esTransicionLegal`):

```
(nuevo)      → INGRESADO
INGRESADO    → EN_REPARTO
INGRESADO    → BAJA
EN_REPARTO   → ENTREGADO
EN_REPARTO   → REINGRESADO
EN_REPARTO   → BAJA
ENTREGADO    → REINGRESADO
REINGRESADO  → EN_REPARTO
REINGRESADO  → DEVUELTO
REINGRESADO  → BAJA
```

Permisos rol → tipo de lote (`MaquinaEstados::rolPermiteTipo`):

| Rol | Tipos permitidos |
|---|---|
| admin / gestor | todos |
| operador | INGRESO, SALIDA_REPARTO, REINGRESO, SALIDA_DEVOLUCION, BAJA |
| transportista | sólo ENTREGA |

## Flujo de `procesarLote()`

1. **Validaciones estructurales (fuera de transacción):** tipo válido, `uuid` con
   formato, `items` es lista, `count(items) ≤ 1000` (si no, **413**).
2. **BEGIN transacción.**
3. **R1 — Idempotencia.** Se busca el lote por `uuid` con `FOR UPDATE`. Si existe,
   se reconstruye el resumen desde `lote_items`/`transiciones` (`Lote::resumen`) y se
   devuelve con `idempotente: true`. **No se re-procesa.** (Esto va ANTES de validar
   permisos/campos, para que un reenvío siempre devuelva el mismo resultado.)
4. **Permiso de rol vs tipo.** Si el rol no puede el tipo → **403** (rollback).
5. **Campos obligatorios + integridad referencial** según el tipo (ver abajo). Si
   falla → **400** (rollback).
6. **Insert del encabezado** del lote (`lotes`).
7. **Por cada item, en orden de la lista:** se aplican R2–R9 (detalle abajo).
8. **COMMIT.** Cualquier excepción hace **ROLLBACK**.
9. Devuelve `{ok, idempotente, uuid, lote_id, items_procesados,
   transiciones_aplicadas, items_ignorados, conflictos_generados, detalle[]}`.

### Validación de campos por tipo (paso 5)

| Tipo | Obligatorio | Opcional |
|---|---|---|
| INGRESO | categoría (activa) | proveedor (activo), n° remito |
| SALIDA_REPARTO | transportista (rol transportista, activo) | — |
| ENTREGA | — (transportista = usuario logueado) | — |
| REINGRESO | motivo (tipo `reingreso`, activo) | — |
| SALIDA_DEVOLUCION | proveedor (activo) + motivo (tipo `devolucion`, activo) | n° remito |
| BAJA | motivo (tipo `baja`, activo) | — |

Si el motivo tiene `editable_libre=1`, se exige `motivo_libre` (si no → 400).
Los campos no aplicables al tipo se guardan en `NULL` (no se confía en lo que mande
el cliente).

## Procesamiento de cada item (R2–R9)

Sea `ts = timestamp_cliente` del item y `destino = TipoLote::estadoDestino()`.

1. **R2 — Duplicado dentro del lote.** Si el código ya apareció antes en este lote,
   se registra `lote_items.resultado = 'ignorado_duplicado_lote'` sin transición.
2. Se busca el producto por código con `FOR UPDATE` (serializa concurrencia por código).
3. **Producto inexistente:**
   - **R8 — lote INGRESO:** alta con `estado = INGRESADO`, transición `null→INGRESADO`,
     sin conflicto. `resultado = 'aplicado'`.
   - **R7 — lote no-INGRESO:** alta con `estado = destino`, `categoria_id = NULL`
     (los lotes no-INGRESO no llevan categoría), transición `null→destino` marcada
     `es_conflicto=1`, motivo `producto_inexistente_en_no_ingreso`; se inserta en
     `conflictos_producto` y `productos.tiene_conflicto=1`.
     `resultado = 'aplicado_con_conflicto'`.
4. **Producto existente:**
   - Se calcula `estado_desde` = **estado en la posición temporal de `ts`** =
     `estado_hasta` de la transición más reciente con `timestamp_cliente <= ts`
     (`Transicion::estadoEnTimestamp`). `null` si no hay ninguna anterior.
   - **R3 — Mismo estado.** Si `estado_desde === destino` → no hay cambio:
     `resultado = 'ignorado_mismo_estado'`, sin transición.
   - Si no, se evalúa legalidad `esTransicionLegal(estado_desde, destino)`:
     - **R5 — legal:** transición sin conflicto, `resultado = 'aplicado'`.
     - **R6 — ilegal:** transición igualmente aplicada con `es_conflicto=1`, motivo
       `transicion_ilegal`; alta en `conflictos_producto` y `tiene_conflicto=1`.
       `resultado = 'aplicado_con_conflicto'`.
   - **R9 — código existente en lote INGRESO** queda cubierto por lo anterior:
     `destino=INGRESADO`; si `estado_desde=INGRESADO` → R3 (ignorar); si es otro →
     `X→INGRESADO` no es legal → R6 (conflicto).
5. **R4 — Estado actual por timestamp del cliente.** Tras insertar la transición,
   `productos.estado_actual`/`transicion_actual_id` se actualizan **sólo si NO existe
   otra transición del producto con `timestamp_cliente` estrictamente mayor**
   (`Transicion::existeMasReciente`). Es decir, un lote retroactivo se inserta en el
   historial pero no pisa el estado actual si ya hay transiciones más nuevas.

### R10 — Orden de aplicación entre lotes

Cada lote se procesa completo en su propia llamada/transacción, en el orden en que
llega al server (que para la cola del cliente es el orden de cierre). Dentro del lote,
los items se procesan en el orden de la lista (el cliente los ordena por
`timestamp_cliente_escaneo`). El **estado actual** no depende del orden de llegada
sino del `timestamp_cliente` (R4): aunque dos lotes lleguen invertidos, gana la
transición de timestamp más reciente.

## Decisiones de implementación (alcance)

- **`estado_desde` por posición temporal (`<= ts`), no por `estado_actual`.** Esto
  hace que R3 y la legalidad (R5/R6) se evalúen en el punto correcto de la línea de
  tiempo, evita conflictos espurios en lotes retroactivos, y encadena correctamente
  lotes secuenciales que comparten el mismo `timestamp_cliente` (el desempate es por
  `id` de transición, que respeta el orden de llegada).
- **No se reescriben transiciones sucesoras.** Cuando llega una transición retroactiva
  intercalada, las transiciones ya guardadas que quedan "después" no se recalculan. El
  `estado_actual` siempre se deriva del `timestamp_cliente` máximo, que es lo que
  define la verdad del estado presente. La spec menciona "reordenar la línea de tiempo";
  acá se reordena a efectos del estado actual, no se mutan registros históricos.
- **Idempotencia antes que validación.** Un reenvío de un `uuid` ya procesado devuelve
  el resultado guardado aunque, entre el primer envío y el reenvío, una categoría/motivo
  se haya inactivado.
- **Ajuste manual** (`api/ajuste-manual.php`): crea una transición con
  `es_ajuste_manual=1`, `lote_id=NULL`, `ajustado_por=usuario`, **sin** marcar conflicto.
  El motivo libre se guarda en `transiciones.motivo_conflicto` (única columna de texto
  disponible; se trunca a 50 caracteres).

## Cobertura de tests

`tests/procesador-lote-casos.php` ejecuta 15 casos (R1–R10 y casos borde) y reporta
`[OK]`/`[FAIL]` por caso. `tests/e2e-offline.cjs` valida el flujo offline (cola IDB +
sync + idempotencia + error_auth) contra el server real con Chrome.

# Tareas micro-detalladas — Fase 0 (gates G1–G6)

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` (dueño único de stock configurable + poll sin re-inflación + cola de facturas fallidas + reconciliación WC↔Alegra) |
| Documentos base | `proposal.md` §8 · `spec.md` §F/§G · `design.md` §13 · `tasks.md` §5 |
| Alcance de este archivo | **Fase 0 — `T0.1` … `T0.6`** (6 gates, 6 tareas) |
| Naturaleza | **READ-ONLY.** No se escribe ni una línea de código de producción en esta fase. |
| Entorno | Cuenta Alegra real del comerciante (o réplica de prueba) + repo en HEAD + acceso al WP admin del sitio |
| Salida única | `docs/sdd/stock-ownership/phase0-results.md` (**la crea `T0.1`**; cada gate agrega su rama) |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`; baseline **1990 assertions / 0 failed**, verificado en HEAD) · `bash scripts/smoke-test.sh` |
| Versión analizada | 2.6.0 (`alegra-connector.php:6`) · Versión objetivo **2.7.0** |

> **Regla de oro.** Ninguna tarea marcada `BLOQUEADO(Fase 0.x)` arranca hasta que el gate tenga su
> **rama elegida** registrada en `phase0-results.md`. **La Fase 1 NO depende de ningún gate**: se puede
> empezar ya (ver `fase-1-cimientos.md`). El orden de los gates es:
> `T0.1` → `{ T0.2 ∥ T0.3 ∥ T0.4 ∥ T0.5 ∥ T0.6 }`.

> **Regla de seguridad.** La cuenta real maneja credenciales. **Nunca** pegar el token en
> `phase0-results.md`; redactarlo como `<token>`. Los `curl` de abajo usan variables de entorno.

> **`G6` ya está cerrado (Rama B).** `T0.1` sólo lo registra; no bloquea ninguna tarea. Los gates que
> **deciden una rama real** son `G1` (`T2.4`/`T5.4`), `G3` (`T3.4`), `G4` (`T4.10`) y `G5` (`T7.4`);
> `G2` sólo decide si se agrega el símbolo `stock_insufficient` (no cambia la clasificación).

## Mapa de gates

| Task | Gate | Pregunta | REQ | Bloquea | Est. |
|---|---|---|---|---|---|
| `T0.1` | G6 | ¿Cuál es el orden real de hooks de WC (G9)? | REQ-OWN-06 | — (sólo documenta) | 1 h |
| `T0.2` | G1 | ¿Una factura `draft` mueve stock en Alegra y `open` sí? | REQ-OWN-01/04, REQ-POLL-02, REQ-RECON-03 | **`T2.4`, `T5.4`** | 2 h |
| `T0.3` | G2 | ¿Qué status (400 vs 422) y body trae el rechazo por falta de stock? | REQ-QUEUE-02 | **`T4.1`** (sólo el símbolo `stock_insufficient`) | 1 h |
| `T0.4` | G3 | ¿`POST /inventory-adjustments` acepta `reference` y `GET` filtra por `reference`? | REQ-POLL-06 | **`T3.4`** | 1 h |
| `T0.5` | G4 | ¿`wc_get_orders(['paginate'=>true])` devuelve `->total` con HPOS? | REQ-QUEUE-03/07, NFR-04 | **`T4.10`** | 1 h |
| `T0.6` | G5 | ¿Se pueden enumerar los ajustes por ítem en la cuenta viva? | REQ-RECON-03 | **`T7.4`** | 1 h |

---

## Correcciones de cita y hallazgos (re-verificados contra HEAD)

Todas las citas se re-leyeron en HEAD para escribir esta fase. Las que estaban mal o quedaron viejas:

| # | Cita (tasks/design/spec) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `tasks.md:377` G1: guion en `sync-reliability/phase0-results.md:23-54` | **Correcto.** La sección `## G1 — draft vs open y stock` vive en `docs/sdd/sync-reliability/phase0-results.md:23-54` (comandos `:32-54`). | `T0.2` adapta ese guion. |
| C2 | `tasks.md:381` G5: `GET /inventory-adjustments?item_id={id}` | **Correcto.** El mock ya filtra por `item_id` (`alegra-mock.php:765-782`). En la cuenta viva queda por confirmar. | `T0.6`. |
| C3 | `tasks.md:379` G3: "filtro `reference` en `GET /inventory-adjustments` (+ `reference` en el `POST`)" | **Correcto como pregunta.** El mock **hoy no** filtra por `reference` (`alegra-mock.php:765-782`) y el `POST` guarda el `reference` del **ítem**, no el del payload (`:903`). | `T0.4` verifica ambos. |
| C4 | `tasks.md:380` G4: `wc_get_orders(['paginate'=>true])` + HPOS | **Correcto.** El stub **no** soporta `paginate` (`wp-stubs.php:1568-1612`: devuelve array o `ids`, nunca objeto con `->total`). | `T0.5`. |
| C5 | `tasks.md:376` T0.1: "G6 = Rama B documentada" | **Correcto.** Ver `ANALYSIS-double-discount.md` §3 (`:187-209`). | `T0.1` sólo registra. |
| C6 | `spec.md:504` / propuesta: "G1 sigue sin resolverse (`phase0-results.md:12`)" | **Correcto.** En `sync-reliability/phase0-results.md:12` la fila G1 dice `PENDING-LIVE`. | `T0.2` cierra la incógnita. |
| C7 | A4 de `tasks.md` §9.2: "`alegra-connector.php:451` (`invoice_status='draft'`)" | **Desactualizado.** `:451` es `push_inventory_enabled`; `invoice_status` está en **`:462`** (`alegra-connector.php:462`). | `T0.2` cita `:462`. |
| C8 | `tasks.md:378` G2: "forzar 4xx" | **Correcto.** El harness ya tiene inyección de fallos: `alegra_mock_fail()` (`alegra-mock.php:152-159`). | `T0.3` puede reproducirlo en el harness, pero la rama se decide en cuenta viva. |
| C9 | `design.md` C26 / `tasks.md` H4: validador `alegra-mock.php:614-637` | **Correcto.** El validador es `alegra_mock_validate_inventory_adjustment()` `:614-637`; la regla se registra en `:337`; el `POST` aplica el delta en `:880-914` (`:896`). | Contexto de G3/G5. |

**Citas confirmadas exactas (no requieren corrección):** `Inventory_Pusher.php:85-91` (`owner()`,
condición DOBLE), `:303-335` (`adjustment_already_exists`, sin `reference`), `:340-358`
(`build_adjustment_payload`, sin `reference`); `Orders.php:122-126` (override `'open'` sólo
`owner==='invoice'` + `is_paid()`), `:850-913` (`ensure_invoice_open`); `alegra-connector.php:462`
(`invoice_status='draft'`); `alegra-mock.php:765-782` (GET filter `item_id`), `:880-914` (POST
adjustments), `:903` (reference del ítem), `:152-159` (`alegra_mock_fail`); `wp-stubs.php:1568-1612`
(`wc_get_orders`); `Write_Gate.php:156` (`run_explicit`); `.distignore:15` (excluye `scripts/`).

---

## Plantilla de `phase0-results.md` (la crea `T0.1`)

```markdown
# Resultados Fase 0 — `stock-ownership`

Cuenta: <alias de la cuenta de prueba> · Fecha: <YYYY-MM-DD> · HEAD: <sha corto>

| Gate | Rama | Decisión | Evidencia |
|---|---|---|---|
| G1 | A/B/C | ¿draft mueve stock? | stock_inicial / stock_tras_draft / stock_tras_open + request/response |
| G2 | A/B | forma del error de stock | status (400/422) + response.errors |
| G3 | A/B | reference en POST/GET | request/response crudos del POST y del GET filtrado |
| G4 | A/B | paginate + HPOS | `->total` observado + versión WC + HPOS on/off |
| G5 | A/B | ajustes enumerables por ítem | lista cruda de `GET /inventory-adjustments?item_id=` |
| G6 | **B (cerrado)** | orden de hooks de WC | `ANALYSIS-double-discount.md` §3 + `wc-stock-functions.php:75,207,238` |

## G1 — `draft` vs `open`
- `stock_inicial=`
- `stock_tras_draft=`
- `stock_tras_open=`
- `rama=`

## G2 — forma del error de stock
- `status=`
- `response_errors=`
- `rama=`

## G3 — `reference` en ajustes
- `post_acepta_reference=`
- `get_filtra_reference=`
- `reference_por_linea=`
- `rama=`

## G4 — paginación/HPOS
- `wc_version=`
- `hpos_enabled=`
- `paginate_total=`
- `rama=`

## G5 — ajustes enumerables
- `item_id=`
- `ajustes_listados=`
- `rama=`

## G6 — orden de hooks (cerrado)
- `rama=B`
- `evidencia=wc_update_product_stock dispara woocommerce_product_set_stock (:75) dentro del loop (:207); woocommerce_reduce_order_stock corre después (:238).`
```

---

### T0.1 — G6: orden real de hooks de WC + crea `phase0-results.md`

**Objetivo**: registrar `G6` como **Rama B (cerrado)** y crear el archivo único de salida de la Fase 0,
para que los demás gates tengan dónde escribir su rama.

**Descripción técnica**: `G6` es el gate `G9` de `sync-reliability` (orden de hooks) que **nunca se
ejecutó** (`ANALYSIS-double-discount.md` §2.2 F6: `phase0-results.md` de `sync-reliability` lista G1–G8,
no G9). El diseño de este cambio **ya lo da por cerrado**: `design.md` §13 fila G6 dice
**"Cerrado: Rama B"**. La evidencia es del core de WC: `wc_reduce_stock_levels()` (`wc-stock-functions.php:170`)
llama `wc_update_product_stock()` (`:207`) **dentro** del loop, y ese call dispara
`do_action('woocommerce_product_set_stock', $product)` (`:75`) — **ahí** corre
`Inventory_Pusher::on_stock_changed()` (`Inventory_Pusher.php:114-125`). Recién **después** del loop
corre `do_action('woocommerce_reduce_order_stock', $order)` (`:238`). Idéntico en la restauración
(`wc_increase_stock_levels`: `wc_update_product_stock` en `:381` dispara `:75`;
`woocommerce_restore_order_stock` en `:411`). Conclusión: un `Stock_Order_Context` poblado desde
`woocommerce_reduce_order_stock` estaría **vacío** cuando corre el pusher ⇒ **`Stock_Order_Context`
NO se implementa**; la guarda de la apertura manual usa `_alegra_stock_adjusted_at` (T2.2), que **no
depende del orden de hooks** (REQ-OWN-06, NFR-02). No bloquea ninguna tarea.

**Desarrollo técnico**:

- **Archivo a crear:** `docs/sdd/stock-ownership/phase0-results.md`. Usar la plantilla de arriba.
- **Fila G6** (única que se completa en esta tarea):

  ```markdown
  | G6 | **B (cerrado)** | El orden de hooks de WC refuta `Stock_Order_Context`: el pusher corre ANTES de `woocommerce_reduce_order_stock`. La guarda manual usa `_alegra_stock_adjusted_at`, no el contexto request-scoped. | `ANALYSIS-double-discount.md` §3 (`:187-209`); core WC `wc-stock-functions.php:75,207,238,381,411`. |
  ```

- **Las filas G1–G5** se crean con `rama=PENDING-LIVE` y se completan en `T0.2`–`T0.6`.
- **No se escribe código de producción** (READ-ONLY). Sólo el `.md`.
- **No** tocar `docs/sdd/sync-reliability/phase0-results.md` (es de otro cambio; `S4` de `tasks.md` §9.3).

**Resultado esperado**: `docs/sdd/stock-ownership/phase0-results.md` existe con una fila por gate
(G1–G6) y columna `rama`; la fila G6 = **Rama B** con la evidencia del core de WC.

**Dependencias**: ninguna. **No bloquea** ninguna tarea (sólo documenta).

**Trazabilidad**: REQ-OWN-06, NFR-02; `design.md` §2.2.1/§13 (G6); `spec.md` §G "Cerrados"; `ANALYSIS-double-discount.md` §2.2 (F6) y §3; `tasks.md` §5/§9.3 (S3).

**Verificación**:

```bash
test -f docs/sdd/stock-ownership/phase0-results.md && \
grep -nE '^\| G[1-6] ' docs/sdd/stock-ownership/phase0-results.md && \
grep -n 'G6.*B (cerrado)' docs/sdd/stock-ownership/phase0-results.md
```

Debe listar 6 filas de gate y la de G6 con `Rama B`.

**Riesgo**: bajo (sólo documentación). Que se confunda con el `phase0-results.md` de `sync-reliability`:
**guard** = path explícito `docs/sdd/stock-ownership/phase0-results.md`.

**Estimación**: S (1 h).

---

### T0.2 — G1: ¿un `draft` mueve stock en Alegra y `open` sí? · BLOQUEA T2.4 y T5.4

**Objetivo**: determinar con evidencia si una factura en estado **borrador** (`draft`) mueve el stock
de Alegra y si **abrirla** (`open`) lo mueve. Es el gate que decide la rama `adjustment` de D1 (si el
borrador ya mueve, el plugin **no** debe crear la factura automáticamente) y el mecanismo de la
reparación (D4).

**Descripción técnica**: el diseño elige el **dueño único a nivel tienda** (`owner()`,
`Inventory_Pusher.php:85-91`): `invoice` sólo si `push_orders_enabled && open_invoice_on_paid`. El
default del plugin es `invoice_status='draft'` (`alegra-connector.php:462`) y el override `'open'` sólo
se aplica con `owner()==='invoice' && $order->is_paid()` (`Orders.php:122-126`). La premisa "el
borrador **no** mueve stock" es **HYPOTHESIS** (`ANALYSIS-double-discount.md:64`, S1 paso 6;
`sync-reliability/phase0-results.md:12` la deja `PENDING-LIVE`). El mock **hoy** fuerza `status='open'`
(`alegra-mock.php:861`) y **no** modela stock por factura — eso lo arregla `T1.1` (H-A). Cubre
**REQ-OWN-01/04, REQ-POLL-02, REQ-RECON-03**.

**Desarrollo técnico**:

- **Preparación (cuenta viva):** elegir un ítem **inventariable** (`ITEM_ID`) y un cliente válido
  (`CLIENT_ID`, p.ej. el CF). Guardar el stock inicial.

  ```bash
  export ALEGRA_EMAIL="cuenta@ejemplo.com"
  export ALEGRA_TOKEN="<token>"           # NUNCA commitear ni pegar en el .md
  export ITEM_ID="<item-inventariable>"
  export CLIENT_ID="<contacto-valido>"
  BASE="https://api.alegra.com/api/v1"
  AUTH="-u $ALEGRA_EMAIL:$ALEGRA_TOKEN"

  # 0) Stock inicial (debe traer availableQuantity numérico)
  curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'
  ```

- **Paso 1 — factura `draft` (1 unidad) y observar stock.**

  ```bash
  curl -s $AUTH -X POST "$BASE/invoices" -H 'Content-Type: application/json' -d '{
    "status":"draft",
    "client":{"id":"'"$CLIENT_ID"'"},
    "items":[{"id":"'"$ITEM_ID"'","price":1000,"quantity":1}],
    "date":"2026-09-25","dueDate":"2026-09-25"
  }'
  # Anotar el id devuelto como INVOICE_ID. Releer el stock:
  curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'
  ```

- **Paso 2 — abrir la factura y volver a observar.**

  ```bash
  curl -s $AUTH -X PUT "$BASE/invoices/$INVOICE_ID" -H 'Content-Type: application/json' -d '{"status":"open"}'
  curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'
  ```

- **Paso 3 (alternativa UI):** repetir los 3 pasos desde el panel de Alegra y capturar la pantalla de
  inventario; o crear la factura desde WP admin con `invoice_status='draft'` y luego abrirla con
  "Abrir factura".
- **Paso 4 — registrar** en `phase0-results.md`: `stock_inicial`, `stock_tras_draft`, `stock_tras_open`
  y el request/response crudo (token redactado). **Compensar** con un ajuste inverso para dejar el
  stock de la cuenta como estaba (no dejar la cuenta tocada).

- **Decision tree (fijar la rama y escribirla):**
  - **Rama A — `draft` NO mueve y `open` SÍ** (`stock_inicial == stock_tras_draft > stock_tras_open`):
    el diseño aplica **tal cual**. `T2.4` **no** cambia nada (la factura `draft` en `adjustment` es
    inerte); `T5.4` repara por factura **abriéndola**.
  - **Rama B — `draft` SÍ mueve** (`stock_tras_draft < stock_inicial`): `create_invoice()` en
    `adjustment` **NO DEBE** crear la factura automáticamente (la factura sólo se crea por acción
    manual explícita, donde la guarda de `T2.5`/`T2.6` sí aplica). `T2.4` **se activa**; `T5.4`
    **re-baselina** en vez de re-emitir (`design.md` §2.2.1 y §5.3).
  - **Rama C — error/red/la cuenta no permite borradores**: gate **INCONCLUSO**. La rama `invoice`
    queda **BLOQUEADA**; `T2.4` y la reparación por factura de `T5.4` **no arrancan** hasta resolverlo.
    La rama `adjustment` (titular) sigue y no se ve afectada.

**Resultado esperado**: fila `G1` en `phase0-results.md` con `stock_inicial=`, `stock_tras_draft=`,
`stock_tras_open=` y `rama=A|B|C`.

**Dependencias**: `T0.1` (el archivo). Bloquea **`T2.4`** y **`T5.4`** (rama `invoice`).

**Trazabilidad**: REQ-OWN-01/04, REQ-POLL-02, REQ-RECON-03; `design.md` §2.2.1/§3.5/§5.3/§13 (G1);
`spec.md` §F/§G; `ANALYSIS-double-discount.md` §1 (S1) y §3.3.

**Verificación**: los 3 `curl` sobre la cuenta + `grep -n 'G1' docs/sdd/stock-ownership/phase0-results.md`
devuelve la rama y los 3 valores.

**Riesgo**: que el ítem elegido no sea inventariable (sin `inventory.availableQuantity`) y el gate mida
nada. **Guard**: el Paso 0 debe devolver un `availableQuantity` numérico; si no, elegir otro ítem. Si la
cuenta no tiene facturación, marcar **C** y no bloquear el titular (`adjustment`).

**Estimación**: M (2 h).

---

### T0.3 — G2: forma del error de stock · BLOQUEA T4.1 (sólo el símbolo)

**Objetivo**: capturar el **status** (400 vs 422) y el **body** que Alegra devuelve al rechazar una
factura por falta de existencias, para decidir si el clasificador agrega el símbolo `stock_insufficient`
(sin cambiar la clasificación: sigue siendo **permanente**).

**Descripción técnica**: el clasificador puro (`Invoice_Failure::classify()`, `design.md` §4.2) trata
**todo 4xx ≠ 429 como permanente** (`failed_permanent`), que es la decisión **segura**: una falta de
stock **no puede loopear** (REQ-QUEUE-02). El análisis marca la forma del error como **HYPOTHESIS**
(`proposal.md` §8.7; `spec.md:84` "SIN VERIFICAR"): no hay un código de error de existencias
documentado. Si la cuenta viva confirma 400/422 con `response.errors` mapeable, se **PUEDE** agregar el
símbolo `stock_insufficient` **sin cambiar** la clasificación. No hay un código de producción que
cambie por este gate (sólo el valor del `code` simbólico). Cubre **REQ-QUEUE-02**.

**Desarrollo técnico**:

- **Paso 1 — forzar el rechazo por stock (cuenta viva):**

  ```bash
  # Elegir un ítem con stock BAJO conocido y pedir MUCHO más de lo disponible.
  curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'

  curl -s $AUTH -X POST "$BASE/invoices" -H 'Content-Type: application/json' -d '{
    "client":{"id":"'"$CLIENT_ID"'"},
    "items":[{"id":"'"$ITEM_ID"'","price":1000,"quantity":999999}],
    "date":"2026-09-25","dueDate":"2026-09-25"
  }' -w '\nHTTP %{http_code}\n'
  ```

- **Paso 2 — registrar** en `phase0-results.md` el `HTTP <code>` y el body JSON completo (token
  redactado).
- **Paso 3 (opcional, harness) — reproducirlo sin cuenta:** el harness ya tiene inyección de fallos:
  `alegra_mock_fail('POST', '/invoices', 422, ['errors' => [['code' => 'stock']]])`
  (`alegra-mock.php:152-159`). Sirve para fijar el test de `T4.1`, pero **no** reemplaza la captura
  viva: la rama se decide con el status real.

- **Decision tree (fijar la rama y escribirla):**
  - **Rama A — 400/422 sin código específico** (lo esperado): se mantiene "todo 4xx ≠ 429 =
    permanente"; `T4.1` **no** agrega símbolo.
  - **Rama B — 422 con `response.errors` de stock mapeable**: `T4.1` agrega el code simbólico
    `stock_insufficient` (mapeando el campo de stock a ese string), **sin cambiar** la clasificación
    (sigue `failed_permanent`, `retriable=false`).
  - **Rama C — el error no es 4xx** (401/500): el gate queda **INCONCLUSO**; se documenta y `T4.1`
    mantiene la clasificación segura actual.

**Resultado esperado**: fila `G2` con `status=`, `response_errors=` (o "ausente") y `rama=A|B|C`.

**Dependencias**: `T0.1`. Bloquea **`T4.1`** (sólo la decisión del símbolo `stock_insufficient`).

**Trazabilidad**: REQ-QUEUE-02; `design.md` §4.2/§13 (G2); `spec.md` §G; `proposal.md` §8.7.

**Verificación**: `grep -n 'G2' docs/sdd/stock-ownership/phase0-results.md` devuelve el status y el body.

**Riesgo**: que el ítem tenga stock suficiente y la factura se acepte (mide nada). **Guard**: verificar
en el Paso 1 que `availableQuantity` sea **menor** que `999999`; si no, subir la cantidad. No dejar la
cuenta tocada (la factura rechazada no descuenta).

**Estimación**: S (1 h).

---

### T0.4 — G3: `reference` en `POST`/`GET /inventory-adjustments` · BLOQUEA T3.4

**Objetivo**: confirmar **dos** cosas en la cuenta viva: (1) si el `POST /inventory-adjustments` acepta
un campo `reference` en el payload (y lo devuelve/guarda), y (2) si el `GET /inventory-adjustments`
**filtra** por `reference`. Decide el mecanismo de idempotencia de `T3.4` (filtro server-side vs
fallback local).

**Descripción técnica**: REQ-POLL-06 exige que el payload y el pre-chequeo incluyan una `reference`
estable que identifique el movimiento. Hoy **ninguno** la usa:
`build_adjustment_payload()` (`Inventory_Pusher.php:340-358`) no emite `reference`, y
`adjustment_already_exists()` (`:303-335`) matchea por `item+type+quantity` en los últimos 30 ajustes
⇒ un `out 1` viejo puede hacer que un `out 1` nuevo se marque `already_applied` y **no se emita**
(fuga de stock, `ANALYSIS-double-discount.md` §1 S8). **Evidencia previa (de `sync-reliability`):** el
schema documentado del `POST` (`{date, items:[{id,type,quantity,unitCost}], warehouse?}`) **no** lista
un `reference` de entrada, y el `reference` del response por línea es el **del ítem**
(`sync-reliability/phase0-results.md:64-93`, Nota G3; `alegra-mock.php:903`). Por eso este gate es el
que decide: si el `GET` **no** filtra, `T3.4` cae al **fallback** `item_id`+fecha + comparación local
(DR5). El mock **hoy** filtra sólo `item_id` (`alegra-mock.php:765-782`); `T1.2` (H-B) lo extiende
**igual** para que el test sea posible. Cubre **REQ-POLL-06**.

**Desarrollo técnico**:

- **Paso 1 — leer la doc oficial** (`https://developer.alegra.com/reference/post_inventory-adjustments`
  y `.../get_inventory-adjustments`). Anotar si el request documenta `reference` (top-level) y si el
  `GET` documenta el query param `reference`.

- **Paso 2 — POST con `reference` top-level (candidato del `design.md` §3.6):**

  ```bash
  REF="wc-stock-$ITEM_ID-10-9"
  curl -s $AUTH -X POST "$BASE/inventory-adjustments" -H 'Content-Type: application/json' -d '{
    "date":"2026-09-25",
    "reference":"'"$REF"'",
    "items":[{"id":"'"$ITEM_ID"'","type":"out","quantity":1,"unitCost":1}]
  }' -w '\nHTTP %{http_code}\n'
  # Anotar el id devuelto como ADJ_ID y si el response repite el reference del payload.
  ```

- **Paso 3 — GET filtrado por `reference` (el pre-chequeo):**

  ```bash
  curl -s $AUTH "$BASE/inventory-adjustments?reference=$REF" | python3 -m json.tool
  # ¿Devuelve SOLO el ajuste con ese reference, o ignora el filtro y devuelve todo?
  ```

- **Paso 4 — GET por `item_id` y mirar el `reference` por línea:**

  ```bash
  curl -s $AUTH "$BASE/inventory-adjustments?item_id=$ITEM_ID" | python3 -m json.tool
  # ¿items[].reference es el del PAYLOAD o el del ÍTEM?
  ```

- **Paso 5 — registrar** en `phase0-results.md` los request/response crudos (token redactado) y las
  tres banderas `post_acepta_reference`, `get_filtra_reference`, `reference_por_linea`.

- **Decision tree (fijar la rama y escribirla):**
  - **Rama A — el `GET` filtra por `reference`** (y el `POST` guarda el del payload):
    `T3.4` filtra **server-side** por `reference`; el pre-chequeo compara el `reference` del payload.
  - **Rama B — el `GET` NO filtra** (o el `POST` ignora el `reference`): `T3.4` cae al **fallback**
    (`item_id`+fecha + comparación local del `reference` estable), y el mock se extiende **igual**
    (`T1.2`). La `reference` estable en el payload **se implementa de todos modos** (ayuda a auditar y
    al caso "mismo movimiento reintentado").

**Resultado esperado**: fila `G3` con `post_acepta_reference=`, `get_filtra_reference=`,
`reference_por_linea=` y `rama=A|B`.

**Dependencias**: `T0.1`. Bloquea **`T3.4`** (mecanismo de idempotencia). `T1.2` (mock) **no** depende
del gate: se implementa igual.

**Trazabilidad**: REQ-POLL-06; `design.md` §3.6/§13 (G3), DR5; `spec.md` §G; `sync-reliability/phase0-results.md:64-93` (Nota G3 previa).

**Verificación**: `grep -n 'G3' docs/sdd/stock-ownership/phase0-results.md` devuelve las 3 banderas, la
rama y al menos un request/response crudo.

**Riesgo**: dejar stock tocado en la cuenta. **Guard**: usar la cuenta de prueba; después del gate,
compensar con un ajuste inverso (`type` opuesto) y re-verificar el `availableQuantity`. No pegar el
token.

**Estimación**: S (1 h).

---

### T0.5 — G4: `wc_get_orders(['paginate'=>true])` + HPOS · BLOQUEA T4.10

**Objetivo**: confirmar que `wc_get_orders(['paginate' => true, 'limit' => 1])` devuelve un objeto con
`->total` en la versión de WooCommerce del comerciante (con y sin HPOS), para que el conteo del badge y
de la cola sea O(1) cacheado.

**Descripción técnica**: REQ-QUEUE-07/NFR-04 prohíben ejecutar una meta query pesada en cada carga del
admin. El diseño (§4.7) lee el conteo con
`wc_get_orders(['paginate' => true, 'limit' => 1, <meta_query>])->total`. El **stub del harness hoy no
soporta `paginate`**: `wc_get_orders()` (`wp-stubs.php:1568-1612`) aplica `meta_query`/`status`/
`limit`/`return=ids` y devuelve **array**, nunca un objeto con `->total` (eso lo agrega `T1.3`, H-C).
Este gate verifica el **core real**. HPOS importa porque `wc_get_orders` con paginación y `meta_query`
debe devolver `total` igual con las tablas de pedidos de HPOS activas. Cubre **REQ-QUEUE-03/07,
NFR-04**.

**Desarrollo técnico**:

- **Paso 1 — versión de WC y estado de HPOS:**

  ```bash
  wp plugin get woocommerce --field=version --allow-root
  wp option get woocommerce_custom_orders_table_enabled --allow-root
  # Alternativa: wp eval 'var_dump(class_exists("\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil"));'
  ```

- **Paso 2 — `->total` con paginación:**

  ```bash
  wp eval '
    $q = wc_get_orders(["paginate" => true, "limit" => 1, "return" => "objects"]);
    var_dump(is_object($q), isset($q->total) ? $q->total : null, is_array($q->orders ?? null));
  ' --allow-root
  # Esperado Rama A: bool(true) true int(total) bool(true)
  ```

- **Paso 3 — repetir con un `meta_query` representativo del ledger** (para descartar que HPOS rompa el
  `total`):

  ```bash
  wp eval '
    $q = wc_get_orders(["paginate" => true, "limit" => 1,
      "meta_query" => [["key" => "_alegra_invoice_sync_state", "compare" => "EXISTS"]],
      "return" => "objects"]);
    var_dump(is_object($q), isset($q->total) ? $q->total : null);
  ' --allow-root
  ```

- **Paso 4 — registrar** en `phase0-results.md`: `wc_version=`, `hpos_enabled=`, `paginate_total=`.

- **Decision tree (fijar la rama y escribirla):**
  - **Rama A — devuelve `->total`** (con y sin HPOS): `T4.10`/`T4.11` usan el conteo O(1) cacheado.
  - **Rama B — no devuelve `->total`** (o HPOS lo rompe): el conteo se hace con `limit` acotado
    (`limit = batch`) y se cachea; el badge sigue siendo O(1) **amortizado** (se refresca en
    persist/clear/apertura, no por página). `T4.10` implementa ese fallback.

**Resultado esperado**: fila `G4` con `wc_version=`, `hpos_enabled=`, `paginate_total=` (o "ausente") y
`rama=A|B`.

**Dependencias**: `T0.1`. Bloquea **`T4.10`** (mecanismo de conteo).

**Trazabilidad**: REQ-QUEUE-03/07, NFR-04; `design.md` §4.7/§11/§13 (G4), DR9; `spec.md` §G.

**Verificación**: `grep -n 'G4' docs/sdd/stock-ownership/phase0-results.md` devuelve la versión, HPOS y
el `total`.

**Riesgo**: correr `php -r` sin cargar WC da un falso negativo. **Guard**: usar `wp eval` (carga WC); si
no hay WP-CLI, cargar `wp-load.php` y asumir Rama A para WC ≥ 3.0 (donde `paginate` existe).

**Estimación**: S (1 h).

---

### T0.6 — G5: enumerar ajustes por ítem · BLOQUEA T7.4

**Objetivo**: confirmar que `GET /inventory-adjustments?item_id={id}` devuelve los ajustes de un ítem
(enumerables) en la cuenta viva, para decidir si la detección/cuantificación del doble decremento
existente puede ser asistida o si la reparación es puramente manual.

**Descripción técnica**: la reparación de un doble descuento **ya existente** (`design.md` §5.4) es
read-only primero: como el plugin **no** asocia ajuste↔pedido en 2.6.0 (F1/`Stock_Order_Context`
ausente), la detección se apoya en los logs (`Inventory adjustment`,
`Inventory_Pusher.php:259`) + `GET /inventory-adjustments?item_id={id}`. El **mock ya lo hace**
(`alegra-mock.php:765-782` filtra por `item_id`), pero eso no prueba la cuenta real. Cubre
**REQ-RECON-03**.

**Desarrollo técnico**:

- **Paso 1 — listar los ajustes del ítem en la cuenta:**

  ```bash
  curl -s $AUTH "$BASE/inventory-adjustments?item_id=$ITEM_ID" | python3 -m json.tool
  # Anotar cuántos devuelve y con qué campos: id, date, items[].{id,type,quantity}.
  ```

- **Paso 2 — (si hace falta) acotar por fecha** para verificar que se puede cuantificar el doble
  descuento por ventana:

  ```bash
  curl -s $AUTH "$BASE/inventory-adjustments?item_id=$ITEM_ID&start=0&limit=30" | python3 -m json.tool
  ```

- **Paso 3 — registrar** en `phase0-results.md`: `item_id=`, `ajustes_listados=` (nº y ejemplo crudo).

- **Decision tree (fijar la rama y escribirla):**
  - **Rama A — devuelve los ajustes del ítem** (enumerables): `T7.4` puede **detectar y cuantificar**
    el doble decremento de forma asistida (suma de cantidades ajustadas de pedidos facturados) y guiar
    la reparación.
  - **Rama B — no los devuelve / devuelve todo sin filtrar**: `T7.4` es **reparación manual pura**, con
    la UI guiando el cálculo y la compensación (ajuste `in` por producto).

**Resultado esperado**: fila `G5` con `item_id=`, `ajustes_listados=` y `rama=A|B`.

**Dependencias**: `T0.1`. Bloquea **`T7.4`** (detección/cuantificación).

**Trazabilidad**: REQ-RECON-03; `design.md` §5.4/§13 (G5), DR12; `spec.md` §G.

**Verificación**: `grep -n 'G5' docs/sdd/stock-ownership/phase0-results.md` devuelve el ítem y la lista
(o su ausencia).

**Riesgo**: que el ítem elegido no tenga ajustes previos y la lista venga vacía (falso "no enumera").
**Guard**: hacer primero un ajuste de prueba (con `T0.4`) y **después** listar; distinguir "vacío" de
"no soportado" mirando si el endpoint responde 200 con `[]` o ignora el filtro.

**Estimación**: S (1 h).

---

## DoD de la Fase 0

- [ ] `docs/sdd/stock-ownership/phase0-results.md` existe (creado en `T0.1`) con una fila por gate
      (G1–G6) y columna `rama`.
- [ ] Cada gate tiene su **rama** registrada y su **evidencia cruda** (comando/HTTP/captura; token
      redactado).
- [ ] `G1` define la rama de `T2.4`/`T5.4` (o los deja **BLOQUEADOS** con Rama C).
- [ ] `G2` define si `T4.1` agrega el símbolo `stock_insufficient` (sin cambiar la clasificación).
- [ ] `G3` define el mecanismo de `T3.4` (`reference` server-side vs fallback local).
- [ ] `G4` define el mecanismo de conteo de `T4.10`/`T4.11` (`->total` vs `limit` acotado cacheado).
- [ ] `G5` define si `T7.4` es asistida o manual pura.
- [ ] `G6` = **Rama B (cerrado)** documentada con la evidencia del core de WC.
- [ ] Ningún gate escribió código de producción (**READ-ONLY**).
- [ ] Los `BLOQUEADO(Fase 0.x)` de `T2.4`, `T5.4`, `T4.1`, `T3.4`, `T4.10` y `T7.4` están resueltos o
      explícitamente diferidos.
- [ ] **La Fase 1 no esperó a ningún gate** (arrancó en paralelo).

# Tareas — `stock-ownership` (ÍNDICE MAESTRO del plan expandido)

> **Este archivo es el ÍNDICE MAESTRO, no el detalle.** El micro-detalle de cada fase vive en
> `docs/sdd/stock-ownership/tasks/fase-{0..7}-*.md`. Acá están: el estado, el mapa de fases, el orden
> de ejecución real, los prerequisitos de harness, los gates, la matriz REQ→tarea, las correcciones
> consolidadas, el registro de revisión, la aceptación y las decisiones abiertas. **Si querés el
> `archivo:línea` de una tarea, andá al phase file.**
>
> **El titular es el doble descuento (D1) + la re-inflación del poll (D2).** El orden de build pone las
> piezas centrales/riesgosas (**D1 dueño del stock → D2 máquina de estados del poll → D3 cola →
> D4 reconciliación**) **antes** de las cosméticas (selector/nota, pantalla, aviso/badge, fila del
> dashboard).
>
> **Regla de oro (prove-it-catches, obligatoria).** Después de que un test pase: **revertir el fix** →
> `bash scripts/exec-test.sh` → confirmar que **ese** test falla → re-aplicar → verde. Sin esto, el test
> no se acepta. Las **5 tareas centrales** (`T2.1`, `T3.1`, `T3.2`, `T4.3`, `T5.4`) tienen su reversión
> documentada en `docs/RELEASE_2.7.0_VERIFICATION.md` (`T7.8`).
>
> **Regla de oro (Fase 0).** Ninguna tarea marcada `BLOQUEADO(Fase 0.x)` arranca hasta que el gate tenga
> su **rama elegida** registrada en `docs/sdd/stock-ownership/phase0-results.md` (lo crea `T0.1`).
> **Fase 1 NO depende de ningún gate**: se puede empezar ya.

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` (dueño único de stock configurable + poll sin re-inflación + cola de facturas fallidas + reconciliación WC↔Alegra) |
| Versión analizada | 2.6.0 (`alegra-connector.php:6`) |
| Versión objetivo | **2.7.0** (opciones nuevas + cambios de comportamiento ⇒ minor) |
| Estado global | **PLAN EXPANDIDO, LISTO PARA EJECUTAR.** 8 phase files escritos y re-verificados contra HEAD. Revisiones R-F0..R-F7 **PENDIENTES** (ver §8). |
| Micro-tareas | **62** en 8 fases (**6 + 10 + 8 + 6 + 11 + 5 + 8 + 8**) — recount sobre los 8 archivos, coincide con el esqueleto. Sin sub-IDs letrados. |
| Tests | **60** IDs canónicos `T30.*` (§5.3). Viven en `scripts/` (**excluido del ZIP** vía `.distignore:15`). **NO** se agregan carpetas de test al plugin. |
| Baseline | `bash scripts/exec-test.sh` → **1990 assertions, 0 failed** (verificado en HEAD). `bash scripts/smoke-test.sh`. |
| Convención de task ID | `T<fase>.<n>[a-z]` · `[ ]` pendiente · `[x]` hecho · `BLOQUEADO(Fase 0.x)` |
| Convención de test ID | Sección `// === stock-ownership (2.7.0) ===` con IDs **`T30.{fase}{n}`** |
| Relación con otros SDD | **Consume** `sync-reliability` (REQ-INV-07/08, dueño a nivel tienda, guarda de poll) e `inventory` (`Inventory_Writer`); **corrige** lo que quedó a medias y **descarta** `Stock_Order_Context` (G6/G9 refutado) |
| Decisión titular | **D1: opción explícita `alegra_connector_stock_owner` ∈ `{auto,invoice,adjustment}`.** **`auto` = condición DOBLE de 2.6.0** `push_orders_enabled && open_invoice_on_paid ? invoice : adjustment` (`Inventory_Pusher.php:85-91`), **NO** la fórmula simplificada (CORRECCIÓN C1 del diseño; ya corregida en `proposal.md` §3.1 y `spec.md` REQ-OWN-02). |

---

## 0. Mapa de artefactos

| Artefacto | Contenido |
|---|---|
| `proposal.md` | Problema (4 FRONTs), restricciones del comerciante (verbatim), hallazgos A1–A7/B1–B7/C1–C8/D1–D5 |
| `spec.md` | **31 REQ** (OWN-01..07, POLL-01..06, QUEUE-01..09, RECON-01..03, NFR-01..06) en EARS/Gherkin, **104 escenarios** |
| `design.md` | D1–D6, firmas exactas, mapa de archivos, **DR1–DR22**, gates G1–G6, correcciones C1–C28 |
| `tasks.md` | **Este archivo** — índice maestro (sin el micro-detalle) |
| `tasks/fase-0-gates.md` | **Fase 0** — `T0.1`–`T0.6` (gates G1–G6, read-only) |
| `tasks/fase-1-cimientos.md` | **Fase 1** — `T1.1`–`T1.10` (harness D5 + opciones + esqueletos + sección `T30`) |
| `tasks/fase-2-ownership.md` | **Fase 2** — `T2.1`–`T2.8` (D1: dueño del stock) |
| `tasks/fase-3-poll-fix.md` | **Fase 3** — `T3.1`–`T3.6` (D2: poll + baselines + `reference`) |
| `tasks/fase-4-failed-invoice-queue.md` | **Fase 4** — `T4.1`–`T4.11` (D3: ledger + clasificador + call sites + cron) |
| `tasks/fase-5-reconciliation.md` | **Fase 5** — `T5.1`–`T5.5` (D4: reconciliación) |
| `tasks/fase-6-ui-copy.md` | **Fase 6** — `T6.1`–`T6.8` (UI/copy) |
| `tasks/fase-7-compat-repair-release.md` | **Fase 7** — `T7.1`–`T7.8` (compat + corrupción + regresión + release) |
| `phase0-results.md` | Salida única de Fase 0 (**lo crea `T0.1`**; rama de cada gate). **Aún no existe.** |
| `docs/sdd/sync-reliability/` | SDD consumido (dueño a nivel tienda, guarda de poll REQ-INV-07/08) — **no se duplica** |
| `docs/sdd/inventory/` | `Inventory_Writer` ya implementado (REQ-DIV-1) — se reusa |

### 0.1 Cómo leer el detalle

1. Buscá la tarea en el **mapa de fases** (§1).
2. Abrí el **phase file** correspondiente.
3. Cada tarea del phase file trae: objetivo, descripción técnica, `archivo:línea`, resultado esperado,
   dependencias, trazabilidad REQ, verificación (con prove-it-catches), riesgo y estimación.
4. El **orden real** de build está en §2; **el número de tarea NO es el orden**.

---

## 1. Mapa de fases

| Fase | Archivo | Tareas (IDs reales) | N | Foco | Depende de |
|---|---|---|---|---|---|
| 0 | `tasks/fase-0-gates.md` | `T0.1`–`T0.6` | **6** | Gates G1–G6 (read-only) | — |
| 1 | `tasks/fase-1-cimientos.md` | `T1.1`–`T1.10` | **10** | Cimientos: harness D5 (H-A/H-B/H-C/H-E) + opciones + esqueletos + sección `T30` | — (ningún gate) |
| 2 | `tasks/fase-2-ownership.md` | `T2.1`–`T2.8` | **8** | **D1: dueño del stock** (central / titular) | Fase 1; `T2.4` además **G1** |
| 3 | `tasks/fase-3-poll-fix.md` | `T3.1`–`T3.6` | **6** | **D2: máquina de estados del poll + baselines + `reference`** (central) | Fases 1–2; `T3.4` además **G3** |
| 4 | `tasks/fase-4-failed-invoice-queue.md` | `T4.1`–`T4.11` | **11** | **D3: ledger + clasificador + call sites + cron** (central) | Fases 1–3; `T4.1` además **G2**; `T4.10` además **G4** |
| 5 | `tasks/fase-5-reconciliation.md` | `T5.1`–`T5.5` | **5** | **D4: reconciliación** (detección + informe + reparación) | Fases 3–4; `T5.4` además **G1** |
| 6 | `tasks/fase-6-ui-copy.md` | `T6.1`–`T6.8` | **8** | UI/copy (cosmético): selector, pantalla cola, reintento UI, aviso/badge, fila dashboard | Fases 2–5 |
| 7 | `tasks/fase-7-compat-repair-release.md` | `T7.1`–`T7.8` | **8** | D6 compat + corrupción existente + regresión + release 2.7.0 | Fases 0–6; `T7.4` además **G5** |
| **Total** | **8 archivos** | **62 micro-tareas** | **62** | | |

> **Recount (hecho sobre los 8 archivos, no sobre este índice).** `grep` de headers `### T{fase}.{n}` =
> **62** exactos: 6 + 10 + 8 + 6 + 11 + 5 + 8 + 8. No hay sub-IDs letrados de **tarea**; los tests con letra
> son `T30.13b`, `T30.24b`, `T30.24c` y `T30.24d`. El conteo **coincide** con el esqueleto: ningún agente partió una tarea en dos IDs.

### 1.1 Contrato canónico de cada fase (lo que las fases consumidoras NO redefinen)

| Contrato | Lo define | Lo consumen |
|---|---|---|
| `K-HA` modelo de stock por factura en el mock | `T1.1` | `T2.3`, `T3.1`, `T3.3` |
| `K-HB` `reference` en `GET`/`POST /inventory-adjustments` | `T1.2` | `T3.4` |
| `K-HC` `wc_get_orders` con `paginate` (`->orders` + `->total`) | `T1.3` | `T4.10`, `T4.11`, `T6.3` |
| `K-HE` `WC_Order_Item::get_product()` | `T1.4` | `T3.3` |
| `K-OPT` 8 opciones nuevas (4 sembrables + 4 internas) | `T1.5` | `T2.1`, `T2.8`, `T4.7`, `T4.8` |
| `K-SKEL` esqueletos `Invoice_Failure`/`Invoice_Queue`/`Stock_Divergence` | `T1.6`/`T1.7`/`T1.8` | Fases 4/5 |
| `K-OWN` `owner()`, `META_ADJUSTED_AT`, guardas | `T2.1`/`T2.2` | `T3.1`, `T4.9`, `T5.4`, `T6.x` |
| `K-POLL` estados `CONVERGED`/`NO_BASELINE_EQ`/`LOCAL_PENDING` + `$divergence` | `T3.1`/`T3.5` | `T5.1` |

---

## 2. Orden de ejecución (extraído de las DEPENDENCIAS de los phase files, NO del número)

> **El número de tarea NO es el orden de build.** Abajo está el orden real, con los paralelizables
> marcados con `∥`. `*` = tarea bloqueada por un gate de Fase 0.

### Bloque 0 — Gates (Fase 0; read-only)

```
T0.1 (G6 cerrado + crea phase0-results.md)
  → { T0.2 (G1) ∥ T0.3 (G2) ∥ T0.4 (G3) ∥ T0.5 (G4) ∥ T0.6 (G5) }
  → docs/sdd/stock-ownership/phase0-results.md
```

### Bloque 1 — Cimientos (Fase 1; arranca YA, sin gates)

```
T1.1 → T1.2 → T1.3 → T1.4 → { T1.5 ∥ T1.6 ∥ T1.7 ∥ T1.8 } → { T1.9 ∥ T1.10 }
```

- `T1.1`/`T1.2` editan `alegra-mock.php`; `T1.3`/`T1.4` editan `wp-stubs.php` ⇒ **secuenciales por
  archivo**. `T1.5`–`T1.8` son mutuamente independientes. `T1.9` (sección `T30`) y `T1.10` (smoke-load)
  cierran y pueden correr en paralelo.

### Bloque 2 — D1: dueño del stock (Fase 2) [central / titular]

```
T2.1 → { T2.2 ∥ T2.3 ∥ T2.7 } → { T2.5 ∥ T2.4* ∥ T2.8 } → T2.6
```

- `T2.1` (`owner()` + condición DOBLE) es prerequisito de **todo**.
- `T2.2` (marca de ajuste) → `T2.5` (guarda open) → `T2.6` (guarda pago; reusa el helper). **Cadena**, no
  grupo plano: `T2.6` depende de `T2.5`.
- `T2.3` (override `open`) → `T2.4*` (no auto-crear factura en `adjustment`, **Rama B de G1**).
- `T2.7` (limpieza lazy + epoch) → `T2.8` (coerción server-side).
- **Gate de fase:** vender 3 en `invoice` ⇒ **cero** `POST /inventory-adjustments` (`T30.21`).

### Bloque 3 — D2: poll (Fase 3) [central]

```
T3.1 → { T3.2 ∥ T3.4* ∥ T3.5 } → T3.3 → T3.6
```

- `T3.1` (máquina de estados; reemplaza `Products.php:1327-1401`) es la base.
- `T3.2` (baseline en W1) → `T3.3` (baseline al mover stock la factura) son **inseparables**: sin `T3.2`
  el poll re-infla; sin `T3.3` el poll se congela (starvation).
- `T3.4*` (`reference` en la idempotencia) es independiente de `T3.2`/`T3.3`; sólo la rama del filtro
  server-side espera **G3**.
- `T3.5` (`$divergence` + registro) alimenta D4.
- **Gate de fase:** vender 3 → poll → WC **no** sube (`T30.32`).

### Bloque 4 — D3: cola (Fase 4) [central]

```
T4.1 → T4.2 → { T4.3 ∥ T4.4 ∥ T4.6 ∥ T4.10* } → { T4.9 ∥ T4.11 } → T4.5 → T4.7 → T4.8
```

- `T4.1` (clasificador puro) es prerequisito de todo; `T4.2` (ledger CRUD) de casi todo.
- `T4.3` (call site error + re-búsqueda), `T4.4` (rama bloqueada), `T4.6` (`payment_missing`) y `T4.10*`
  (query/conteo, **G4**) son paralelos tras `T4.2`.
- `T4.9` (idempotencia) tras `T4.3`; `T4.11` (conteo cacheado) tras `T4.10`.
- **`T4.5` depende de `T4.11`** (necesita `refresh_count()`), y `T4.7` (cron) depende de `T4.5` + `T4.9`
  ⇒ la cadena real cierra `T4.11 → T4.5 → T4.7 → T4.8`.
- **Gate de fase:** un fallo ⇒ ledger + aparece en la cola + badge (`T30.44`).

### Bloque 5 — D4: reconciliación (Fase 5) [central]

```
{ T5.1 ∥ T5.3 } → T5.2 → T5.4* → T5.5
```

- `T5.1` (record + causa) consume `T3.5`; `T5.3` (des-invertir el gate) es **independiente** de
  `T5.1`/`T5.2` (el phase file lo dice explícito).
- `T5.2` (report) tras `T5.1`.
- `T5.4*` (reparación explícita, un solo mecanismo) está **BLOQUEADO(Fase 0.2 / G1)**.
- `T5.5` (rastro) tras `T5.4`.

### Bloque 6 — UI/copy (Fase 6) [cosmético]

```
{ T6.1 ∥ T6.3 ∥ T6.8 } → { T6.2 ∥ T6.4 ∥ T6.5 ∥ T6.6 ∥ T6.7 }
```

- `T6.1` (selector) → `T6.2` (coerción UI + aviso).
- `T6.3` (pantalla) es prerequisito de `T6.4`/`T6.5`/`T6.6`/`T6.7`.
- `T6.8` (diálogo JS) sólo depende de `T2.5`/`T2.6` (Fase 2) ⇒ puede arrancar temprano.

### Bloque 7 — Compat + corrupción + release (Fase 7)

```
T7.1 → T7.2 → T7.4* → T7.3 → { T7.5 ∥ T7.7 } → T7.6 → T7.8
```

- `T7.4*` (reparación del doble decremento existente) está **BLOQUEADO(Fase 0.6 / G5)**; `T7.3`
  (CHANGELOG) documenta los cambios intencionales **después** de `T7.4`.
- `T7.7` (seguridad) sólo depende de `T6.4`/`T6.5`/`T5.4` ⇒ paralelo a `T7.5`.
- `T7.6` (release 2.7.0) y `T7.8` (aceptación manual) cierran el cambio.

### 2.1 Grupos paralelizables (resumen)

| Grupo | Tareas |
|---|---|
| Gates | `T0.2` ∥ `T0.3` ∥ `T0.4` ∥ `T0.5` ∥ `T0.6` |
| Cimientos | `T1.5` ∥ `T1.6` ∥ `T1.7` ∥ `T1.8`; luego `T1.9` ∥ `T1.10` |
| D1 | `T2.2` ∥ `T2.3` ∥ `T2.7`; luego `T2.5` ∥ `T2.4` ∥ `T2.8` |
| D2 | `T3.2` ∥ `T3.4` ∥ `T3.5` |
| D3 | `T4.3` ∥ `T4.4` ∥ `T4.6` ∥ `T4.10`; luego `T4.9` ∥ `T4.11` |
| D4 | `T5.1` ∥ `T5.3` |
| UI | `T6.1` ∥ `T6.3` ∥ `T6.8`; luego `T6.2` ∥ `T6.4` ∥ `T6.5` ∥ `T6.6` ∥ `T6.7` |
| Release | `T7.5` ∥ `T7.7` |

---

## 3. Prerequisitos transversales de harness — canónico: **H-A, H-B, H-C, H-E**

Los gaps del design §6.1 fueron **re-verificados** contra HEAD. Sin estos, grandes partes del plan son
**inobservables**. Todos viven en `scripts/` (**excluido del ZIP** vía `.distignore:15`).
**H1 y H4 de `sync-reliability` están RESUELTOS en HEAD** (verificado).

| ID | Task | Gap | Archivo (HEAD verificado) | Qué modela / por qué | Bloquea |
|---|---|---|---|---|---|
| **H-A** | `T1.1` | El mock **no** modela stock por factura: `POST /invoices` fuerza `status='open'` y no descuenta `availableQuantity` | `scripts/lib/alegra-mock.php:854-866` (`'status' => 'open'` en `:861`) | Respetar `draft`; al `open`/`paid` descontar por línea (idempotente); `PUT`/`POST .../open` mueven al abrir; flag `alegra_mock_set_draft_moves_stock()` para la Rama B de G1. Sin esto D1/D2 no son testeables. | `T2.3`, `T3.1`, `T3.3` |
| **H-B** | `T1.2` | `GET /inventory-adjustments` **no** filtra por `reference`; el POST guarda la reference del **ítem**, no la del payload | `alegra-mock.php:765-782` (filtra `item_id` y pagina), `:903` (`reference` del ítem) | Filtro `reference` en el GET + guardar la `reference` top-level del body en el stored. Sin esto REQ-POLL-06 es vacuo. | `T3.4` |
| **H-C** | `T1.3` | `wc_get_orders` no soporta `paginate => true` (devuelve array, no objeto con `total`) | `scripts/lib/wp-stubs.php:1568-1612` | Devolver `->orders` + `->total` cuando `paginate=true`; mantener array/`ids`. Sin esto el badge y la pantalla no son O(1). | `T4.10`, `T4.11`, `T6.3` |
| **H-E** | `T1.4` | `WC_Order_Item::get_product()` **falta**; `get_items()` (`:1377`) e `is_paid()` (`:1388`) **ya existen** | `wp-stubs.php:1207-1232` (`WC_Order_Item`), `:1317-1422` (`WC_Order`) | Agregar sólo `get_product()` (resuelve por `variation_id > product_id`). El meta CRUD ya está. | `T3.3` |
| **H-D** | — | Stub de `wp_mail` | grep = 0 en `scripts/lib/wp-stubs.php` | **N/A**: el diseño **no** usa email (§4.7 del design); no se agrega. | — |
| **H1** | — | `wc_update_product_stock()` | **RESUELTO**: `wp-stubs.php:1489-1504` | Ya existe (setea stock, deriva status, dispara hooks). **No se toca.** | — |
| **H4** | — | Ruta `/inventory-adjustments` | **RESUELTO**: `alegra-mock.php:337,614-637,880-914` | Ya existe y aplica el delta (`:896`). **No se toca.** | — |

### 3.1 Alcance de cada seam (por qué importa)

- **`H-A`** es el corazón del titular: sin stock por factura no hay forma de probar que el poll no
  re-infla ni que la factura mueve una sola vez.
- **`H-B`** es el único seam que cambia la semántica de la idempotencia; sin él `reference` no se prueba.
- **`H-C`** es prerequisito del conteo cacheado (badge) y de la paginación de la cola.
- **`H-E`** hace real el baseline de factura (REQ-POLL-02).

> **`scripts/` está excluido del ZIP** (`.distignore:15`). Los tests **no** se distribuyen (NFR-05).

---

## 4. Gates G1–G6 (Fase 0)

Cada gate es **read-only** y su salida va a `docs/sdd/stock-ownership/phase0-results.md` (lo crea `T0.1`).

| Gate | Task | Check exacto | Rama A | Rama B | Bloquea |
|---|---|---|---|---|---|
| **G1** | `T0.2` | En la cuenta: factura `draft` con un ítem de stock conocido → observar `availableQuantity`; abrirla (`PUT status=open`) → observar. Guion: `sync-reliability/phase0-results.md:23-54` (adaptar). | `draft` **no** mueve y `open` sí ⇒ el diseño actual aplica; `T2.4` no crea factura en `adjustment`; `T5.4` repara por factura abriéndola | `draft` **sí** mueve ⇒ `adjustment` **no** crea la factura automática (`T2.4`); `invoice` mueve en la creación; `T5.4` re-baselina | **`T2.4`, `T5.4`** |
| **G2** | `T0.3` | Forzar una factura sin existencias en cuenta viva y capturar status (400 vs 422) y si trae `response.errors` mapeable. | 400/422 sin código específico ⇒ "todo 4xx salvo 429 = permanente" (seguro) | 422 con `response.errors` de stock ⇒ agregar el código simbólico `stock_insufficient` **sin cambiar** la clasificación | **`T4.1`** (sólo el símbolo) |
| **G3** | `T0.4` | `GET /inventory-adjustments`: confirmar si soporta filtro `reference` y si devuelve `reference` por línea; confirmar que el `POST` acepta `reference` en el payload. | Soporta ⇒ filtrar server-side por `reference` | No soporta ⇒ fallback `item_id`+fecha + comparación local; el mock se extiende igual (`T1.2`) | **`T3.4`** |
| **G4** | `T0.5` | `wc_get_orders(['paginate'=>true,'limit'=>1])` devuelve `->total` en el WC soportado + HPOS. | Devuelve `total` ⇒ conteo O(1) cacheado | No ⇒ contar con `limit` acotado y cachear | **`T4.10`** |
| **G5** | `T0.6` | `GET /inventory-adjustments?item_id={id}` en cuenta viva devuelve los ajustes del ítem (enumerables). | Sí ⇒ detección/cuantificación asistida posible | No ⇒ reparación manual pura, con la UI guiando | **`T7.4`** |
| **G6** | `T0.1` | Orden real de hooks de WC (G9). | **CERRADO: Rama B** — `woocommerce_product_set_stock` corre dentro de `wc_update_product_stock`; `woocommerce_reduce_order_stock` después. `Stock_Order_Context` **no** se implementa. | — | — (sólo documenta) |

### 4.1 Tareas bloqueadas por Fase 0 — son **6**, no 5

> **Corrección al esqueleto.** El header viejo listaba "5 tareas `BLOQUEADO(Fase 0.x)`" y se olvidaba de
> **`T5.4`**, que también depende de **G1**. El conteo real es **6**:
> `T2.4`/G1, `T5.4`/G1, `T4.1`/G2, `T3.4`/G3, `T4.10`/G4, `T7.4`/G5.

### 4.2 DoD de Fase 0

- `docs/sdd/stock-ownership/phase0-results.md` existe con una fila por gate (rama + evidencia cruda).
- G1/G3/G5 definen sus decisiones; G2 decide si se agrega `stock_insufficient`; G6 = Rama B documentada.
- Ningún gate escribió producción; **Fase 1 no esperó a ningún gate**.

---

## 5. Matriz de trazabilidad REQ → micro-tarea

> **Fuente:** `Trazabilidad` de cada phase file (autoridad) **∪** la matriz del esqueleto anterior. Se
> consolida como unión para no perder cobertura. **Ningún REQ ni NFR queda sin al menos una tarea.**

### 5.1 Funcionales (25)

| REQ | Micro-tareas | Nota |
|---|---|---|
| REQ-OWN-01 | `T0.2`, `T1.1`, `T2.2`, `T3.1`, `T4.9`, `T5.4` | invariante: una sola vez por movimiento |
| REQ-OWN-02 | `T1.5`, `T2.1`, `T7.1` | opción + `owner()` con condición doble (C1) |
| REQ-OWN-03 | `T2.1`, `T3.1` | `invoice` ⇒ cero ajustes por pedido |
| REQ-OWN-04 | `T0.2`, `T2.3`, `T2.4`, `T2.8`, `T6.2` | `adjustment` ⇒ nunca auto-abre (G1) |
| REQ-OWN-05 | `T2.7` | limpieza lazy + epoch |
| REQ-OWN-06 | `T0.1`, `T2.2`, `T2.5`, `T2.6`, `T6.8` | advertencia + confirmación server-enforced |
| REQ-OWN-07 | `T1.5`, `T2.3`, `T2.8`, `T6.1`, `T6.2` | selector + nota literal + coerción |
| REQ-POLL-01 | `T3.2`, `T3.6` | baseline en el import |
| REQ-POLL-02 | `T0.2`, `T1.1`, `T1.4`, `T3.3` | baseline al mover stock la factura |
| REQ-POLL-03 | `T1.1`, `T3.1` | el poll no pisa WC en `invoice` |
| REQ-POLL-04 | `T3.1`, `T3.6` | nunca sobrescribe un cambio local pendiente |
| REQ-POLL-05 | `T3.1`, `T3.6`, `T7.5` | el poll sigue corriendo (Alegra→WC) |
| REQ-POLL-06 | `T0.4`, `T1.2`, `T3.4`, `T3.6` | `reference` en la idempotencia |
| REQ-QUEUE-01 | `T1.6`, `T4.2`, `T4.3`, `T4.5` | ledger por pedido (CRUD/HPOS) |
| REQ-QUEUE-02 | `T0.3`, `T1.6`, `T4.1`, `T4.4` | clasificador puro |
| REQ-QUEUE-03 | `T0.5`, `T1.3`, `T1.7`, `T4.10`, `T6.3` | pantalla "Facturas por subir" |
| REQ-QUEUE-04 | `T4.9`, `T6.4` | reintento single |
| REQ-QUEUE-05 | `T6.5` | reintento bulk `scope=failed` |
| REQ-QUEUE-06 | `T1.5`, `T4.7`, `T4.8` | cron con backoff + tope |
| REQ-QUEUE-07 | `T0.5`, `T1.3`, `T1.7`, `T4.5`, `T4.10`, `T4.11`, `T6.6` | aviso + badge |
| REQ-QUEUE-08 | `T4.4`, `T4.6`, `T6.3` | distinción de estados (`payment_missing`) |
| REQ-QUEUE-09 | `T4.3`, `T4.9` | idempotencia del reintento |
| REQ-RECON-01 | `T1.8`, `T3.5`, `T5.1`, `T5.2`, `T6.7` | informe de divergencia con causa |
| REQ-RECON-02 | `T5.3`, `T6.7` | des-invertir el gate |
| REQ-RECON-03 | `T0.2`, `T0.6`, `T5.4`, `T5.5`, `T7.4` | reparación explícita (G1/G5) |

### 5.2 No funcionales (6)

| NFR | Micro-tareas | Nota |
|---|---|---|
| NFR-01 | `T3.6`, `T7.5`, `T7.8` | sin regresión factura/pago/webhook/import |
| NFR-02 | `T0.1`, `T3.1`, `T7.5` | distribuido: sin orden de hooks, sin cron real obligatorio |
| NFR-03 | `T1.5`, `T2.8`, `T7.1`, `T7.2`, `T7.3` | compat 2.6.0 + cambios intencionales documentados |
| NFR-04 | `T0.5`, `T1.3`, `T1.7`, `T3.1`, `T4.7`, `T4.10`, `T4.11`, `T6.3`, `T6.5`, `T6.6` | escalabilidad (paginado + conteo cacheado) |
| NFR-05 | `T1.9`, `T1.10`, `T7.6` | ZIP sin `scripts/` |
| NFR-06 | `T2.5`, `T2.6`, `T5.4`, `T6.4`, `T6.8`, `T7.7` | compuertas + nonce/cap + sin secretos |

### 5.3 IDs de test canónicos `T30.*` (60)

| Fase | Tests | N |
|---|---|---|
| 1 | `T30.11`–`T30.17`, `T30.13b` | 8 |
| 2 | `T30.21`, `T30.22`, `T30.24`, `T30.24b`, `T30.24c`, `T30.24d`, `T30.25`, `T30.26`, `T30.28`, `T30.29`, `T30.210` | 11 |
| 3 | `T30.31`–`T30.39`, `T30.310` | 10 |
| 4 | `T30.41`–`T30.411` | 11 |
| 5 | `T30.51`–`T30.56` | 6 |
| 6 | `T30.61`–`T30.67` | 7 |
| 7 | `T30.71`–`T30.77` | 7 |
| **Total** | | **60** |

> **IDs compartidos (no suman al total):** `T30.51` (`T3.5`/`T5.1`), `T30.48` (`T4.9`/`T6.4`),
> `T30.25`/`T30.26` (`T2.5`/`T2.6`/`T6.8`), `T30.24` (`T2.3`/`T2.8`), `T30.65` (`T6.1`/`T6.2`),
> `T30.37` (`T3.1`/`T3.3`, R4/R6 — extendido por B2).

### 5.4 Cobertura de `sync-reliability` (consumido, no duplicado)

| Requerimiento `sync-reliability` | Estado en HEAD | Este cambio |
|---|---|---|
| REQ-INV-07 (informe de divergencia) | No implementado | **REQ-RECON-01/02** (`T5.x`) |
| REQ-INV-08 (nunca ambos mecanismos) | Guard 2 lo suprime con `invoice` | **REQ-OWN-01** (`T2.x`/`T3.x`) + **REQ-RECON-03** (`T5.4`) |
| Guarda de poll (`design.md:873-878`) | **No implementada** | **REQ-POLL-03** (`T3.1`) |
| Dueño a nivel tienda (`design.md:505-620`) | Parcial (implícito) | **REQ-OWN-02** (`T2.1`) |
| `Stock_Order_Context` / Guard 6 | **No implementable** (G9/G6 refutado) | **Se descarta**; se reemplaza por REQ-OWN-06 (`T2.5`/`T2.6`) |
| REQ-INV-02 (un solo escritor `Inventory_Writer`) | Implementado (`Inventory_Writer.php:39`) | Se consume; **REQ-POLL-01** agrega el baseline (`T3.2`) |

### 5.5 ⚠️ Coverage gaps

**Ningún REQ ni NFR queda sin tarea.** Los 31 están mapeados. La cobertura **fina** (1 sola tarea) es
**REQ-QUEUE-05** → `T6.5`; no es un gap (el bulk reusa el chunked de 10/request) y se verifica con
`T30.67`.

### 5.6 Matriz de regresión R1–R20 → test canónico

| R | Riesgo | Test canónico | Fase | Tarea guarda |
|---|---|---|---|---|
| R1 | `auto` mal derivado reintroduce B3 (fórmula simplificada) | `T30.21` | 2 | `T2.1` |
| R2 | El poll re-infla tras una venta con baseline ausente | `T30.32` | 3 | `T3.2` |
| R3 | El poll pisa WC en `invoice` (`invoice_owner` cae al writer) | `T30.33`, `T30.34` | 3 | `T3.1` |
| R4 | Starvation: el poll queda congelado tras abrir la factura | `T30.37` | 3 | `T3.3` |
| R5 | Doble descuento por apertura manual en `adjustment` | `T30.25`, `T30.26` | 2 | `T2.5`, `T2.6` |
| R6 | Doble descuento por factura abierta fuera de WC | `T30.37` (extendido por B2) | 3 | `T3.1`, `T3.3` |
| R7 | Reintento duplica factura tras timeout/5xx | `T30.46`, `T30.48` | 4 | `T4.3`, `T4.9` |
| R8 | El cron loopea un permanente (falta de stock) | `T30.49` | 4 | `T4.1`, `T4.7` |
| R9 | El badge ejecuta una query pesada por página | `T30.411` | 4 | `T4.10` |
| R10 | La cola no escala en tiendas grandes | `T30.62` | 6 | `T6.3` |
| R11 | Cambiar de dueño arrastra `pending` sucio | `T30.29` | 2 | `T2.7` |
| R12 | La reparación emite los dos mecanismos | `T30.54` | 5 | `T5.4` |
| R13 | Baseline con factura creada impaga (`invoice_status=open`) | `T30.36` | 3 | `T3.3` |
| R14 | El import pisa stock con un baseline mentiroso | `T30.31` | 3 | `T3.2` |
| R15 | Divergencia harness↔producción (mock sin stock por factura) | `T30.11` | 1 | `T1.1` |
| R16 | Opciones nuevas ausentes ⇒ el gate bloquea | `T30.15` | 1 | `T1.5` |
| R17 | Un AJAX nuevo no exige nonce/capacidad | `T30.56`, `T30.76` | 5/7 | `T5.4`, `T7.7` |
| R18 | Regresión factura/pago/webhook/import | `T30.77` | 7 | `T7.5` |
| R19 | El reporte "Ventas sin factura" siempre visible rompe la UI | `T30.53`, `T30.66` | 5/6 | `T5.3`, `T6.7` |
| R20 | `_alegra_stock_adjusted_at` marca de más (falso positivo) | `T30.28` | 2 | `T2.2` |

---

## 6. Correcciones consolidadas (audit trail de los 8 phase files)

> Todas las citas se re-leyeron en HEAD al escribir cada fase. Abajo están **sólo las que cambiaron**
> (original → realidad verificada), más las correcciones que el propio índice hace a documentos viejos.
> Las "confirmaciones" (cita == HEAD) quedan en cada phase file.

### 6.1 Correcciones hechas por los agentes de fase

| # | Cita original | Realidad verificada en HEAD | Dónde | Corregida en |
|---|---|---|---|---|
| X1 | `auto` = `invoice` si `push_orders_enabled` (`proposal.md:168-169`, `spec.md:173`) | **FALSO**: es la condición **DOBLE** `push_orders_enabled && open_invoice_on_paid` (`Inventory_Pusher.php:87-90`) | `T2.1` | fase-2 C1 / design C1 |
| X2 | Meta por pedido `_alegra_stock_adjusted` (`design.md` §2.2.1) | **NO implementable**: `push_delta()` no conoce el pedido; sólo el per-producto `_alegra_stock_adjusted_at` | `T2.2`, `T2.5`, `T2.6` | fase-2 C2 |
| X3 | `Orders.php:88-90` depende de `open_invoice_on_paid` | **Confirmado**: se quita la dependencia (queda dueño + pago) | `T2.3` | fase-2 C3 |
| X4 | Reemplazo de `Products.php:1327-1353` (`tasks.md` T3.1 / `design.md` §3.3) | **Impreciso**: el span real a reemplazar es **`1327-1401`** | `T3.1` | fase-3 C4 |
| X5 | `find_existing_invoice()` cierra en `:341` (`spec.md:914,941`; `tasks.md` §9.2 A7) | **Falso**: firma `:309`, `return null;` `:339`, `}` **`:340`** ⇒ rango `:309-340` | `T4.3` | fase-3 (l.61) / fase-4 C1 |
| X6 | `Orders.php:155-173` es la rama `is_wp_error` | **Aproximado**: `:155` es el POST; el `if` va **`:157-173`** | `T4.3`, `T4.4` | fase-4 C2 |
| X7 | `run_payment_reconcile()` arranca en `:89` (`proposal.md`) | **Falso**: arranca en **`:86`** (`:93` lock, `:102` release) | `T4.8` | fase-4 C3 |
| X8 | Schedule del cron en `alegra-connector.php:669-674` (`tasks.md` T4.8) | **Aproximado**: `maybe_self_heal_payment_reconcile()` es **`:667-675`** | `T4.8` | fase-4 C4 |
| X9 | `Public_.php:411-416` (nota + log) | **Impreciso**: nota `:409-413`, log `:415-421`; bloque **`:406-422`** | `T4.3` | fase-4 C5 |
| X10 | `spec.md:95` W1 = `Products.php:2274-2276` | **Angosto**: el cuerpo cierra en **`:2285`** ⇒ `:2274-2285` | `T3.2` | fase-4 C6 |
| X11 | "Nunca-intentado hoy sólo `processing\|completed`" (`design.md` §4.1/§4.4) | **Cierto sólo** para `get_unjournaled_sales()` (`Admin_Dashboard.php:865`); el bulk ya incluye `on-hold` (`:3874`) | `T4.10`, `T5.3` | fase-4 C7 / fase-5 C8 |
| X12 | `Orders.php:525-526` "pago falló" | **Rango ok**, pero `:526` descarta el retorno; la implementación real está en `:560` | `T4.6` | fase-4 C8 |
| X13 | `draft_invoice_not_opened` no figura en la tabla del clasificador (`design.md` §4.2) | **Falta**: se agrega como **permanente** | `T4.1` | fase-4 C9 |
| X14 | Selector "junto a" `templates/admin-settings.php:94` | **Punto real**: después de `:100`, antes de `:101` | `T6.1` | fase-6 C1 |
| X15 | `admin-settings.php` es multi-línea | **Falso**: es **una `<tr>` por línea** (HTML minificado) | `T6.1`, `T6.2` | fase-6 C2 |
| X16 | `admin-settings.php:109-112` (toggle `open_invoice_on_paid`) | El toggle **sólo se renderiza** si `push_orders_enabled=true`; la coerción no puede depender del DOM | `T6.2` | fase-6 C3 |
| X17 | `Admin_Dashboard.php:3166-3168` (patrón `run_explicit`) | **Aproximado**: `ajax_sync_single()` es `:3166-3169` | `T6.4`, `T7.7` | fase-6 C4 |
| X18 | `State_Sync.php:473`/`:488` (aviso) | `:473` es `queue_admin_notice()` **private + transient 60 s**, NO option-backed ⇒ agregar método público | `T6.6`, `T7.3` | fase-6 C5 / fase-7 C10 |
| X19 | `admin-dashboard.php:197-212` (fila "siempre visible") | El "siempre visible" se logra sacando el gate `:903-905` (**`T5.3`**), no el `if` de `:197`; `T6.7` sólo agrega el link | `T6.7` | fase-6 C6 |
| X20 | `uninstall.php:153-156` (locks por prefijo) | **Corrección**: locks `alegra_lock_%` en **`:162-165`**; barrido post meta `_alegra_%` en **`:221-225`** | `T7.2`, `T7.6` | fase-7 C5 |
| X21 | `uninstall.php:75-77` (opciones de inventario) | **Desactualizado**: el bloque real es **`:77-85`** | `T1.5` | fase-1 C9 |
| X22 | Patrón `smoke-load.php:196-200` | **Aproximado**: `check(...)` está en **`:196-212`**; insertar después de `:212` | `T1.10` | fase-1 C12 |
| X23 | H-E = `get_items()`/`get_product()`/`is_paid()` | **Parcial**: `get_items()` (`:1377`) e `is_paid()` (`:1388`) ya existen; falta **sólo** `WC_Order_Item::get_product()` | `T1.4` | fase-1 C7 |
| X24 | Payload viejo `{date, type, quantity, item:{id}}` | Shape **documentado** `{date, items:[{id,type,quantity,unitCost}], warehouse?}`; el viejo se rechaza (test `T29.14`) | `T1.2`, `T3.4` | fase-1 C8 |
| X25 | `alegra-connector.php:451` = `invoice_status='draft'` | **Desactualizado**: `:451` es `push_inventory_enabled`; `invoice_status` = **`:462`** | `T0.2` | fase-0 C7 / `tasks.md` A4 |

### 6.2 Correcciones que este índice hace a documentos viejos (`tasks.md` §9.2 A1–A7)

| # | Cita original | Realidad verificada en HEAD | Dónde |
|---|---|---|---|
| A1 | `sync-reliability/tasks.md:312` H2: `wp-stubs.php:1284` = `WC_Product::save()`, `:1363` = `WC_Order::save()` | **Desactualizado**: `WC_Product::save()` = `:1289`; `WC_Product_Variation` = `:1302`; `WC_Order::save()` = `:1376` | Referencia (no bloquea) |
| A2 | `Controller.php:414`/`:441`/`:523-526` (`acquire_lock`/`release_lock`/`acquire_sync_lock_public`) | **Desactualizado**: `acquire_lock` = `:440`; `release_lock` = `:467`; `acquire_sync_lock_public` = `:549` | `T4.8` |
| A3 | `Public_.php:371` corta la cascada | **Desactualizado**: el chequeo `get_transient('alegra_import_in_progress')` está en `:378` (`:371` es comentario) | `T2.7` |
| A4 | `alegra-connector.php:451` (`invoice_status='draft'`) | **Desactualizado**: `:462` | `T0.2` |
| A5 | `design.md` C22: `alegra-connector.php:507-513` (loop) | **Aproximado**: el loop real es `:509-513` | `T1.5` |
| A6 | `design.md` §2.1: `Inventory_Pusher.php:70-76` (`set_pending`/`clear_pending`) | **Aproximado**: `set_pending` = `:68-71`; `clear_pending` = `:73-76` | `T2.7` |
| A7 | `Orders.php:309-340` (`find_existing_invoice`) | **Inválida**: cierra en **`:340`** (rango `:309-340`). La corrección A7 del esqueleto viejo (que decía `:341`) era **falsa**; la verdad la fijan fase-3/fase-4 (X5) | `T4.3` |

### 6.3 Confirmaciones clave del diseño (C1–C28; re-verificadas)

| # | Claim | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `auto` simplificado (proposal/spec) | **FALSO.** La lógica real es la condición DOBLE (`Inventory_Pusher.php:87-90`) | `T2.1`, REQ-OWN-02 |
| C5/C24 | `invoice_owner` **no** está en `$handled` ⇒ el poll cae al writer | **Confirmado:** `Products.php:1348-1349` | `T3.1` |
| C6 | `needs_reconcile` no cubre baseline ausente | **Confirmado:** `Products.php:1332-1334` | `T3.1`, `T3.2` |
| C7 | W1 no setea ledger | **Confirmado:** `Products.php:2274-2285` | `T3.2` |
| C25 | H1 `wc_update_product_stock()` "ausente" | **DESACTUALIZADO:** el stub existe (`wp-stubs.php:1489-1504`) | D5 (§3) |
| C26 | H4 "sin ruta `/inventory-adjustments`" | **DESACTUALIZADO:** la ruta existe y aplica delta (`alegra-mock.php:337,614-637,880-914`) | D5 (§3) |
| C27 | El síntoma real es **re-inflación**, no starvation | **Confirmado** | D2 (§2) |
| — | `stock_owner`, `_alegra_invoice_sync_state`, `_alegra_stock_adjusted`, `_alegra_invoice_retry`, `_alegra_stock_adjusted_at`, `stock_divergence` | **0 coincidencias** en producción (grep) | Todo el cambio |
| — | `wp_mail` en `includes/`, `admin/`, `public/` | **0 coincidencias** | §3 (H-D N/A) |
| — | `Write_Gate` ya tiene `inventory` en `ENTITY_OPTIONS` (`:43`) y `ENTITY_DEFAULTS` (`:58`) | **Confirmado** (sync-reliability ya lo sembró) | `T1.5` no repite |

---

## 7. Inconsistencias detectadas entre artefactos (flagged)

> Éstas son las que quedaron **después** de escribir los 8 phase files. Las 7 primeras son
> esqueleto-vs-phase-file; las resoluciones ya están aplicadas en este índice. Las **resoluciones de las
> revisiones adversariales** (momus/oracle) están en §7.1.

| # | Inconsistencia | Evidencia | Resolución |
|---|---|---|---|
| I1 | **`find_existing_invoice()` cierra en `:341` vs `:340`.** El `tasks.md` §9.2 A7 viejo decía `:341`; fase-3 (l.61) y fase-4 C1 dicen **`:340`**. | `Orders.php:309-340`; fase-3 l.61; fase-4 C1 | **Canónico `:309-340` (cierra en `:340`).** A7 queda invalidada (ver X5). |
| I2 | **Span del poll: `1327-1353` vs `1327-1401`.** El esqueleto T3.1 citaba `1327-1353`; fase-3 C4 corrige a **`1327-1401`** (incluye el bloque del writer `:1355-1401`). | fase-3 C4; `Products.php:1327-1401` | **Canónico `1327-1401`.** `T3.1` reemplaza ese span. |
| I3 | **H-E: tres métodos vs uno.** El esqueleto §4 decía `get_items()/get_product()/is_paid()`; fase-1 C7 dice que sólo falta `get_product()`. | fase-1 C7; `wp-stubs.php:1377,1388` | **Sólo `get_product()`.** Los otros ya existen. |
| I4 | **Tareas bloqueadas: 5 vs 6.** El header viejo omitía `T5.4` (G1). | §4.1; fase-0 G1 | **Son 6:** `T2.4`, `T5.4`, `T4.1`, `T3.4`, `T4.10`, `T7.4`. |
| I5 | **Grupo paralelo de D1 mal armado.** El esqueleto §3 ponía `{T2.2 ∥ T2.3 ∥ T2.5 ∥ T2.6}` tras `T2.1`; fase-2 dice que `T2.5` depende de `T2.2` y `T2.6` de `T2.5`. | fase-2 `Dependencias` (l.667, 783) | **Cadena real:** `T2.2 → T2.5 → T2.6`; `T2.3`/`T2.7` paralelos. |
| I6 | **Orden de D3.** El esqueleto §3 ponía `T4.5` antes de `T4.7`; fase-4 dice que `T4.5` depende de `T4.11` (que depende de `T4.10`) y `T4.7` de `T4.5`+`T4.9`. | fase-4 `Dependencias` (l.423, 576) | **Cadena real:** `T4.10 → T4.11 → T4.5 → T4.7 → T4.8`. |
| I7 | **Orden de D4.** El esqueleto §3 ponía `T5.1 → T5.2 → T5.3 → T5.4`; fase-5 dice que `T5.3` no depende de `T5.1`/`T5.2`. | fase-5 `Dependencias` (l.289) | **`T5.3` es paralelo a `T5.1`.** |
| I8 | **`T3.4` no es secuencial a `T3.2`/`T3.3`.** El esqueleto lo ponía después de `T3.3`; fase-3 dice que depende de `T2.2`+`T1.2`. | fase-3 `Dependencias` (l.755) | **`T3.4` paralelo a `T3.2`/`T3.3`** (sólo la rama server-side espera G3). |
| I9 | **Matriz REQ del esqueleto ≠ fase files.** Ej.: REQ-OWN-01 el esqueleto mapeaba `T2.2/T3.1/T4.9`; las fases agregan `T0.2/T1.1/T5.4`. | §5.1 (unión) | **Se consolida la unión**; los phase files son autoridad. Ningún REQ queda huérfano. |
| I10 | **Tests compartidos.** `T30.51` (`T3.5`/`T5.1`), `T30.48` (`T4.9`/`T6.4`), `T30.24` (`T2.3`/`T2.8`), `T30.65` (`T6.1`/`T6.2`), `T30.25/26` (`T2.5/6`/`T6.8`). | §5.3 | **Consistente**: no suman al total de 60; sólo se documenta. |

> **Lo que está consistente (no flag):** el conteo de micro-tareas (62) y el de tests (60) coinciden en
> los 8 archivos; los gates G1–G6 y sus bloqueos coinciden entre fase-0 y el resto; las 5 tareas centrales
> (`T2.1`, `T3.1`, `T3.2`, `T4.3`, `T5.4`) coinciden entre el header y fase-7.

### 7.1 Resoluciones de las revisiones adversariales (`REVIEW-momus.md` / `REVIEW-oracle.md`)

> Conflictos **de review** que viven en los phase files. Acá queda la **resolución canónica** que cada
> phase file **DEBE** honrar al implementarse. No cambian el conteo de tareas (62) ni el de tests (60).

| # | Conflicto (review) | Evidencia | Resolución canónica | Tarea |
|---|---|---|---|---|
| I11 | **`failures_hash` con dos formatos (C4).** `T4.10` guarda `md5((string)$count)`; `T6.6` guarda `(string)$count`. | `fase-4:774` vs `fase-6:512` | **Canónico `md5((string) $count)`** en ambos. `T6.6`/`State_Sync::queue_invoice_failure_notice()` escribe el **mismo** md5, no el conteo crudo. Limitación (mismo N, set distinto) documentada en `design.md` §4.7. | `T4.10`, `T6.6` |
| I12 | **`scope=failed` no filtra "failed" (C6).** `T6.5` llama `Invoice_Queue::query(['state' => 'failed'])`, pero `'failed'` no está en `Invoice_Queue::STATES` ⇒ cae a todos los estados + nunca-intentados. | `fase-6:443` vs `fase-4:783-794` | `meta_query()` **DEBE** aceptar el alias `'failed'` expandiéndolo a `['failed_retriable','failed_permanent','payment_missing','blocked']` (o usar esos estados explícitos) y **excluir** los nunca-intentados del bulk de reintento. | `T6.5`, `T4.10` |
| I13 | **`refresh_count()` no se llama al persistir (C7).** `T4.11` exige refrescar tras `persist()`, pero los snippets de `T4.3`/`T4.4`/`T4.6`/`T4.7` **no** la llaman (sólo `T4.5`). | `fase-4:823` vs `:293-307,359-370,452-472,548-563` | Tras **cada** `Invoice_Failure::persist()` (incluidos los del cron `T4.7`), llamar `Invoice_Queue::refresh_count()`. Si no, el aviso/badge de REQ-QUEUE-07 no aparece hasta abrir la cola. | `T4.3`, `T4.4`, `T4.6`, `T4.7` |
| I14 | **`T7.4` cambia el dueño por `update_option` directo (C8), saltando `sanitize_stock_owner()` (T2.8)** ⇒ `open_invoice_on_paid` queda descoercionado. | `fase-7:304` vs `fase-2:875-957` | Cambiar el dueño **pasando por el sanitizador/coerción** (o setear **ambas** opciones en la secuencia), nunca `update_option` crudo. | `T7.4` |
| I15 | **`divergence_cause()` firma incompatible (B5/DEF-2).** `T3.5` pasa `$p` (`int\|''`) a un `?\WC_Order` ⇒ `TypeError` fatal; y las causas `factura_fallida`/`reembolso`/`factura_pendiente` son inalcanzables desde el poll. | `fase-3:814-819` vs `fase-5:119-139` | **Canónico 2 args:** `divergence_cause(string $owner, int\|string $s): string` (sólo `baseline_ausente`/`divergencia_dueno`). Las causas por pedido se resuelven en el **informe** (`report()`), donde sí hay `WC_Order`. Alinear `T3.5`+`T5.1` y `T30.51`. | `T3.5`, `T5.1` |
| I16 | **`alegra_connector_inventory_reference_filter` no sembrada (C5).** `T3.4` la lee pero no está en las 8 opciones ni en `uninstall.php`. | `fase-3:705` vs `fase-1`/`design.md` §10 | **No se agrega opción.** G3 es decisión de **build**: constante `Inventory_Pusher::USE_REFERENCE_FILTER` (Rama A `true`, Rama B `false`). El conteo sigue en **8** opciones. | `T3.4` |
| I17 | **El cron nunca ve el primer fallo (B3/DEF-3).** La query de `T4.7` exige `_alegra_invoice_attempts < max` (INNER JOIN), pero `persist()` (`T4.2`) no inicializa `attempts` ⇒ el fallo nuevo queda excluido para siempre. | `fase-4:516-524` vs `:195-209` | `Invoice_Failure::persist()` **DEBE** escribir `META_ATTEMPTS = 0` si no existe (o la query agrega `NOT EXISTS` en `relation OR`). Test: un fallo recién persistido **DEBE** ser devuelto por `retry_failed_invoices()`. | `T4.2`, `T4.7` |
| I18 | **Doble descuento automático en `adjustment` (B1/DEF-1).** `create_invoice_with_payment()` pasa `'open'` sin consultar `owner()`; el override `:122-126` es irrelevante porque `'open'` ya viene forzado. | `Orders.php:508-512` vs `design.md` §2.2 | `T2.3` **DEBE** condicionar la apertura al dueño: `$open_now = $will_record_payment && Inventory_Pusher::owner() === 'invoice';` y forzar `draft` cuando `owner()!=='invoice'`. Cubrir el path `complete`/bulk (no sólo `create_invoice()`). | `T2.3` |
| I19 | **La secuencia de reparación `T7.4` es inejecutable con `T5.4` (B4/DEF-7).** `T7.4` cambia el dueño a `invoice` y **después** emite un `in`; `T5.4` rechaza si `owner!=='adjustment'`. | `fase-7:302-306` vs `fase-5:358-384` | Definir **un solo** contrato de reparación (flag `repair_mode=legacy_compensation` que permita el ajuste tras el cambio de dueño, o un `ajax_repair_legacy_corruption` separado) con `quantity = qty_doble` del informe, `unitCost`/`warehouse` del pusher y `set_synced(A)` al final. No atómico ⇒ reanudable. | `T5.4`, `T7.4` |
| I20 | **Poll congelado con `S` stale (B2/DEF-4).** Con la factura abierta **fuera** del plugin, `S` queda viejo, `W==A` y `!can_push` ⇒ `LOCAL_PENDING` eterno y divergencia falsa. | `fase-3:214-326` vs `design.md` §11 | Agregar la regla de **convergencia por baseline viejo**: si `S!=='' && $has_a && W===A` ⇒ `set_synced($product_id, $w)` y caer al writer (no `continue`). Test con la factura abierta fuera del plugin. | `T3.1` |

> **`find_existing_invoice` (`:341` vs `:340`), span del poll (`1327-1353` vs `1327-1401`) y conteo de
> bloqueadas (6, no 5)** ya están resueltos en §6.2/§7 (A7/X5, I2, I4) y en `spec.md`/`design.md`.

---

## 8. Registro de revisión

> **Revisiones adversariales corridas.** `REVIEW-momus.md` y `REVIEW-oracle.md` revisaron
> `proposal/spec/design/tasks` + las 8 fases contra HEAD. Veredictos: **momus → REJECT
> (fix-and-resubmit)** y **oracle → UNSOUND**. Ambos convergen en los mismos defectos (doble
> descuento automático en `adjustment`, `TypeError` de `divergence_cause()`, inanición de la cola por
> `attempts` ausente, reparación de corrupción inejecutable). **Resolución: CERRADA** — la resolución
> canónica está en §7.1 (I11–I20) + las correcciones C1–C29; los phase files se alinean a esa
> resolución.

| Revisión | Alcance | Revisor | Estado | Hallazgos |
|---|---|---|---|---|
| **R-MOMUS** | `proposal/spec/design/tasks` + 8 fases | momus | **REJECT → RESUELTO** | B1–B6, C1–C8, 9 coverage gaps → §7.1 (I11–I20) + C1–C29 |
| **R-ORACLE** | `proposal/spec/design/tasks` + 8 fases + HEAD | oracle | **UNSOUND → RESUELTO** | DEF-1..DEF-11, UNIMPLEMENTABLE 1–3 → §7.1 (I11–I20) + C1–C29 |
| R-F0 | Fase 0 (gates) | — | **PENDIENTE** | — |
| R-F1 | Fase 1 (harness/opciones/esqueletos) | — | **PENDIENTE** | — |
| R-F2 | Fase 2 (D1) | — | **PENDIENTE** | — |
| R-F3 | Fase 3 (D2) | — | **PENDIENTE** | — |
| R-F4 | Fase 4 (D3) | — | **PENDIENTE** | — |
| R-F5 | Fase 5 (D4) | — | **PENDIENTE** | — |
| R-F6 | Fase 6 (UI) | — | **PENDIENTE** | — |
| R-F7 | Fase 7 (compat/release) | — | **PENDIENTE** | — |
| R-CROSS | Coherencia entre fases (IDs, opciones, tests) | — | **RESUELTO** | Ver §7 / §7.1 |

---

## 9. Checklist de aceptación (los problemas del comerciante + no regresión)

> Los 4 problemas que reportó el comerciante + el requisito distribuido. Cada fila mapea a tareas y a un
> resultado **verificable en el WP admin**.

| # | Problema / síntoma reportado | Micro-tareas | Resultado verificable (WP admin) |
|---|---|---|---|
| 1 | **Doble descuento: "Vendo 3 y Alegra descuenta 6"** | `T2.1`–`T2.8`, `T6.1`, `T6.2`, `T6.8` | Ajustes muestra "Dueño del stock" con la nota *"Elegí UNO solo…"*; en modo `invoice` una venta descuenta **una vez** y **cero** ajustes; en `adjustment`, abrir/registrar pago exige `confirm_double_discount=1` y deja nota |
| 2 | **El poll re-infla: "El stock vuelve a subir solo"** | `T3.1`–`T3.6`, `T1.1`–`T1.4` | Tras vender 3, un poll posterior **no** sube WC (ni con baseline presente ni ausente); un cambio en Alegra (POS) **sí** baja cuando no hay cambio local pendiente |
| 3 | **Facturas fallidas: "debería quedar como notificación… por subir"** | `T4.1`–`T4.11`, `T6.3`–`T6.6` | La pantalla "Facturas por subir" lista orden/motivo/código/intentos/último/próximo; badge = conteo; aviso dismissible; "Reintentar" (single/bulk) sube y **nunca** duplica; cron opt-in con backoff |
| 4 | **Reconciliación: "no puedo ver ni reparar la divergencia"** | `T5.1`–`T5.5`, `T6.7`, `T7.4` | El informe lista los productos que difieren **con causa**; la fila "Ventas sin factura" se ve siempre; "Reparar" es explícito y emite **un** mecanismo (nunca ambos) |
| 5 | **Sin regresión (distribuido)** | `T7.1`, `T7.2`, `T7.5`, `T7.6`, `T7.7` | `stock_owner=auto` conserva 2.6.0; factura/pago/webhook/import intactos; funciona sin cron real; ZIP sin `scripts/`; sin token en logs |

### 9.1 Restricciones del comerciante (verbatim — se honran tal cual)

1. *"La idea es que todas las ventas del ecommerce sean facturadas en alegra."*
2. *"Necesitamos un plugin totalmente profesional pero tambien debe ser configurable…"*
3. *"…todo debe respetar la escalabilidad."*
4. *"Nuestro plugin es profesional y el dashboard debe seguir con la buena usabilidad, nada de ambiguedades y que el usuario no se complique, debe ser facil de usar."*
5. *"Nada de chapuzadas y de codigo como panitos de agua tibia."*
6. *"Todo debe quedar funcional y todo el proceso debe funcionar correctamente sin problemas, errores, bugs o conflictos."*
7. *"La idea es que funcione en cualquiera"* — plugin **distribuido**, sin supuestos de host.
8. **El poll NO se apaga.** Se arregla, no se desactiva.
9. **No DIAN.** Fuera de alcance. El ZIP excluye `scripts/` (`.distignore:15`).

### 9.2 Mapa problema → verificación manual (`T7.8`)

- **Problema 1** → "Pasos dueño": vender en `invoice` (cero ajustes) y en `adjustment` (confirmación).
- **Problema 2** → "Pasos titular": vender 3 → poll → WC no sube.
- **Problema 3** → "Pasos cola": forzar fallo → lista + badge + aviso + reintento.
- **Problema 4** → "Pasos reconciliación": informe con causa + reparación explícita.
- **Problema 5** → "Pasos regresión": factura/webhook/pago + `inventory_source=woocommerce`.

---

## 10. Decisiones abiertas + default recomendado

### 10.1 Forks con default recomendado (spec §G)

| REQ | Fork | Default recomendado | Micro-tarea |
|---|---|---|---|
| REQ-OWN-02 | Default de `stock_owner`: `auto` vs `invoice` | **`auto`** (compatibilidad total con 2.6.0) | `T1.5`, `T2.1` |
| REQ-OWN-06 | Modo `adjustment`: ¿advertir o bloquear? | **Advertir con confirmación server-enforced** (`confirm_double_discount=1`) + nota + log | `T2.5`, `T2.6`, `T6.8` |
| REQ-QUEUE-06 | ¿Reintento automático encendido por default? | **Apagado (opt-in)** (`invoice_retry_enabled=false`) | `T1.5`, `T4.7`, `T4.8` |
| REQ-QUEUE-08 | ¿La cola incluye `payment_missing`? | **Sí**, como estado distinto | `T4.6`, `T6.3` |
| REQ-RECON-03 | ¿La reparación puede emitir la factura automáticamente? | **Manual con confirmación** (bajo `run_explicit`) | `T5.4` |

### 10.2 Parámetros configurables (defaults)

| # | Opción | Default recomendado | Micro-tarea |
|---|---|---|---|
| 1 | `alegra_connector_stock_owner` | **`auto`** (condición DOBLE) | `T1.5`, `T2.1` |
| 2 | `alegra_connector_invoice_retry_enabled` | **`false`** | `T1.5`, `T4.8` |
| 3 | `alegra_connector_invoice_retry_max_attempts` | **`5`** | `T1.5`, `T4.7` |
| 4 | `alegra_connector_invoice_retry_batch` | **`20`** | `T1.5`, `T4.7` |
| 5 | Backoff del cron | **`[5m, 15m, 1h, 6h, 24h]`** | `T4.7` |
| 6 | Tamaño de la cola (pantalla) | **`20`/página** | `T4.10`, `T6.3` |
| 7 | Bulk de reintento | **`10`/request** (chunked existente) | `T6.5` |
| 8 | Divergencia bounded | **`200`** entradas (FIFO) | `T5.1` |
| 9 | `open_invoice_on_paid` en modo `invoice` | **Forzado ON** (coercionado) | `T2.8`, `T6.2` |

### 10.3 Gates con decisión pendiente (Fase 0)

| Gate | Task | Rama esperada | Tarea bloqueada |
|---|---|---|---|
| G1 | `T0.2` | **A** esperada (`draft` no mueve, `open` sí) | `T2.4`, `T5.4` (rama `invoice`) |
| G2 | `T0.3` | **A** esperada (todo 4xx ≠ 429 = permanente) | `T4.1` (sólo el símbolo) |
| G3 | `T0.4` | **A** esperada (soporta filtro `reference`) | `T3.4` |
| G4 | `T0.5` | **A** esperada (`paginate` devuelve `total`) | `T4.10` |
| G5 | `T0.6` | **A** esperada (ajustes enumerables) | `T7.4` |
| G6 | `T0.1` | **B (cerrado)** | — |

---

## 11. DoD global del cambio

- [ ] Gates G1–G6 con rama registrada en `docs/sdd/stock-ownership/phase0-results.md`.
- [ ] Prerequisitos de harness **H-A, H-B, H-C, H-E** aplicados (todos en `scripts/`, fuera del ZIP).
- [ ] Las **62** micro-tareas `[x]`, cada una con su prove-it-catches documentado (revertir → rojo →
      re-aplicar → verde).
- [ ] `bash scripts/exec-test.sh` → `EXEC-TEST OK` con el conteo real (objetivo **≥ 1990 + nuevos**;
      0 failed).
- [ ] `bash scripts/smoke-test.sh` → `SMOKE OK`; `smoke-load.php` asserta que `Invoice_Failure`,
      `Invoice_Queue` y `Stock_Divergence` cargan por PSR-4.
- [ ] `T7.8` checklist manual firmado + capturas de Ajustes (selector), cola (badge/aviso) y del informe
      de divergencia.
- [ ] Matriz **R1–R20** con test concreto (§5.3).
- [ ] Las **8** opciones nuevas sembradas (4 en `$defaults`; 8 en `$non_autoload`+`uninstall`).
- [ ] **Cambio intencional documentado:** `stock_owner=auto` conserva 2.6.0; reporte "Ventas sin
      factura" siempre visible + `draft`/`void`; coerción de `open_invoice_on_paid`; D1 vs
      `docs/sdd/inventory/DD-8`; baseline de factura (`synced = WC qty`) en `CHANGELOG.md`.
- [ ] Release **2.7.0**: `CHANGELOG.md`, versión en header/README/make-pot, `uninstall.php` con las 8
      opciones, `.pot` regenerado y commiteado, ZIP + `.sha256` commiteados.
- [ ] `docs/RELEASE_2.7.0_VERIFICATION.md` con los 4 problemas + prove-it-catches + rollback.
- [ ] **`scripts/` NO está en el ZIP** (`.distignore:15`; verificado en `T30.75`).

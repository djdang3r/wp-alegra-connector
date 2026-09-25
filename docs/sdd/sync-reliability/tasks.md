# Tareas — Fiabilidad de sincronización: Consumidor Final, inventario y poll (`sync-reliability`)

> **MASTER INDEX del plan expandido.** Este archivo es el **índice maestro** de un plan que ahora vive
> repartido en **10 archivos de fase** (`tasks/fase-0-gates.md` … `tasks/fase-9-regresion-release.md`).
> Acá está: el mapa de artefactos, el mapa de fases con los **IDs reales** (incluidos los splits),
> el orden real de build (que **no** es el número de tarea), los prerequisitos transversales de harness,
> los gates que bloquean, la matriz de trazabilidad REQ→micro-tarea, el **audit trail consolidado** de
> todas las citas corregidas por los agentes que escribieron las fases, el registro de revisión y las
> decisiones abiertas. El detalle micro de cada tarea vive en su archivo de fase; **este índice no lo
> duplica**.
>
> **El titular es FRONT B (sobreventa).** El orden de build pone las piezas centrales/riesgosas
> (`Inventory_Writer`, `Inventory_Pusher`, self-heal del CF, poll con cursor) **antes** de las cosméticas
> (UI honesta, pista de cron).
>
> **Regla de oro (prove-it-catches, obligatoria).** Después de que un test pase: **revertir el fix** →
> `bash scripts/exec-test.sh` → confirmar que **ese** test falla → re-aplicar → verde. Sin esto, el test
> no se acepta. Las **5 tareas centrales** (`T2.1`, `T3.4`, `T5.5`, `T7.1b`, `T8.1`) tienen su reversión
> documentada en `docs/RELEASE_2.6.0_VERIFICATION.md` (T9.6).
>
> **Regla de oro (Fase 0).** Ninguna tarea marcada `BLOQUEADO(Fase 0.x)` arranca hasta que el gate tenga
> su **rama elegida** registrada en `docs/sdd/sync-reliability/phase0-results.md`. **Fase 1 no depende de
> ningún gate**: se puede empezar ya.

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` (Consumidor Final honesto + inventario bidireccional + robustez del poll/cron) |
| Versión analizada | 2.5.1 (`alegra-connector.php:6`) |
| Versión objetivo | **2.6.0** (cambios de comportamiento + opciones nuevas ⇒ minor) |
| Estado global | **PLAN EXPANDIDO — listo para ejecutar.** Gates G1–G8 + 4 tareas `BLOQUEADO(Fase 0.x)` (T3.5/G1, T5.5/G6, T4.4/G8, T2.1-fallback/G7) |
| Micro-tareas | **75** en 10 fases (8+12+8+7+5+8+4+9+6+8) — **recontadas** desde los 10 archivos de fase |
| Splits | Fase 5: `T5.2` → `T5.2a`/`T5.2b` · Fase 7: `T7.1` → `a/b/c` y `T7.2` → `a/b` · Fase 9: `T9.5` → `a/b/c` |
| Tests | Viven en `scripts/` (**excluido del ZIP** vía `.distignore:15`). **NO** se agregan carpetas de test al plugin. |
| Harness | `bash scripts/exec-test.sh` (baseline real **1626 assertions, 0 failed**) · `bash scripts/smoke-test.sh` |
| Convención de task ID | `T<fase>.<n>[a-z]` · `[ ]` pendiente · `[x]` hecho · `BLOQUEADO(Fase 0.x)` |
| Convención de test ID | Sección `// === sync-reliability (2.6.0) ===` con IDs **`T29.{fase}{n}`** (el harness ya usó `T1..T28`) |
| Relación con otros SDD | **Consume** `docs/sdd/inventory/`; **envuelve** `Write_Gate` de `config-gates`; comparte `Logger`/`Runs` de `logs-monitor-import` |
| Decisión titular | **D2: híbrido con dueño de movimiento a nivel tienda** derivado de `push_orders_enabled` **y** `open_invoice_on_paid` (condición doble). **Contradice `docs/sdd/inventory/DD-8`** (ver §0.2). |

---

## 0. Cómo leer este documento

### 0.1 Convenciones

- **CF** = Consumidor Final (`includes/Consumidor_Final.php`).
- **W1** = `Products::apply_inventory_to_product()` (`Products.php:2052-2100`).
- **W2** = bloque inline del poll (`Products.php:1253-1260`).
- **Poll** = `Products::sync_inventory_from_alegra()` (`Products.php:1142`).
- **Import** = `Products::import_from_alegra()` (`Products.php:1295`).
- **Owner** = dueño del movimiento de stock en Alegra: `adjustment` | `invoice`.
- Toda cita `archivo:línea` fue **re-verificada leyendo HEAD** por los agentes que escribieron cada fase
  (§7). Lo no verificado se marca **SIN VERIFICAR / BLOQUEADO**.

### 0.2 La decisión titular (D2) y su contradicción deliberada

El diseño elige el **híbrido con dueño de movimiento a nivel tienda**:

```
owner = (get_option('alegra_connector_push_orders_enabled', false)
         && get_option('alegra_connector_open_invoice_on_paid', true))
            ? 'invoice'
            : 'adjustment'
```

- `owner=adjustment` (**default**, `push_orders=false`): el plugin empuja el delta vía
  `POST /inventory-adjustments`. **Este es el caso que arregla el titular** (con los defaults, ninguna
  factura mueve stock).
- `owner=invoice` (`push_orders=true` **y** `open_invoice_on_paid=true`): la factura es dueña; el plugin
  abre el borrador existente al pagar (`design.md:551-587`).
- **Doble conteo (REQ-INV-08):** **no** se evita "por construcción". Se evita por la **condición doble de
  `owner()`** (sólo elige `invoice` cuando las facturas realmente mueven stock) **más las guardas del
  pusher**: antes de emitir un ajuste se resuelve el pedido asociado y, si su factura está `open`, **no**
  se emite (`reason='invoice_owner'`); la facturación manual se advierte y exige confirmación
  (`design.md:589-608`).

> **Esto contradice `docs/sdd/inventory/DD-8`** ("no cablear `create_inventory_adjustment()`") y su
> principio #4 ("la factura es el único mecanismo"). Motivo: DD-8 asumía que (b) cubriría las ventas;
> con los defaults (`push_orders=false`, `invoice_status=draft`, `alegra-connector.php:451`) (b) **no
> cubre nada** y el titular persiste. La intención de DD-8 (evitar doble conteo) se **preserva** vía el
> dueño a nivel tienda **más** las guardas de la factura manual (`design.md:589-614`). **Se documenta en
> `CHANGELOG.md` y en la release 2.6.0.** Lo registran `T3.5` y `T9.5a`.

---

## 1. Mapa de artefactos

| Artefacto | Contenido |
|---|---|
| `proposal.md` | Problema, restricciones del comerciante, hallazgos A1–A8/B1–B9/C1–C7, criterios de éxito |
| `spec.md` | 30 REQ (CF-01..08, INV-01..08, POLL-01..07, NFR-01..07) en EARS/Gherkin |
| `design.md` | D1–D8, firmas exactas, mapa de archivos, riesgos de diseño **DR1–DR26**, gates G1–G8 |
| `tasks.md` | **Este archivo** — índice maestro del plan expandido |
| `tasks/fase-0-gates.md` | Fase 0 — `T0.1`–`T0.8` (gates G1–G8, read-only) |
| `tasks/fase-1-cimientos.md` | Fase 1 — `T1.1`–`T1.12` (harness H1–H8 + H3b, opciones, `Write_Gate`) |
| `tasks/fase-2-inventory-writer.md` | Fase 2 — `T2.1`–`T2.8` (`Inventory_Writer` D3, W1/W2, D5) |
| `tasks/fase-3-inventory-pusher.md` | Fase 3 — `T3.1`–`T3.7` (`Inventory_Pusher` D2, dueño híbrido) |
| `tasks/fase-4-variaciones.md` | Fase 4 — `T4.1`–`T4.5` (variaciones en update D4, warehouse diferido) |
| `tasks/fase-5-consumidor-final.md` | Fase 5 — `T5.1`–`T5.7` con split `T5.2a/b` (CF engine + conectar + self-heal D1) |
| `tasks/fase-6-cf-ui.md` | Fase 6 — `T6.1`–`T6.4` (CF UI honesta + "Verificar ahora") |
| `tasks/fase-7-poll-budget.md` | Fase 7 — `T7.1a/b/c`, `T7.2a/b`, `T7.3`–`T7.6` (poll budget/cursor D6) |
| `tasks/fase-8-lock-cron.md` | Fase 8 — `T8.1`–`T8.6` (lock shutdown + cron budget + cron real D7) |
| `tasks/fase-9-regresion-release.md` | Fase 9 — `T9.1`–`T9.4`, `T9.5a/b/c`, `T9.6` (regresión R1–R19 + release) |
| `phase0-results.md` | Salida única de Fase 0 (**se crea en T0.1**; rama de cada gate). **Aún no existe.** |
| `docs/sdd/inventory/` | SDD consumido (REQ-DIV-1/3, REQ-FAIL-4..6, REQ-GATE-1..2, REQ-WH-1/4, REQ-TYPE) — **no se duplica** |

---

## 2. Mapa de fases (IDs reales, recontados)

| Fase | Archivo | Micro-tareas (IDs reales) | N | Foco | Depende de |
|---|---|---|---|---|---|
| 0 | `tasks/fase-0-gates.md` | `T0.1`–`T0.8` | **8** | Gates G1–G8 (read-only) | — |
| 1 | `tasks/fase-1-cimientos.md` | `T1.1`–`T1.12` | **12** | Cimientos + harness H1–H8 + H3b + opciones + `Write_Gate` inventory | — (ningún gate) |
| 2 | `tasks/fase-2-inventory-writer.md` | `T2.1`–`T2.8` | **8** | **`Inventory_Writer`** (D3) + W1/W2 + D5 `_stock_status` | Fase 1; `T2.1` fallback además **G7** |
| 3 | `tasks/fase-3-inventory-pusher.md` | `T3.1`–`T3.7` | **7** | **`Inventory_Pusher` + dueño híbrido** (D2) [titular] | Fase 2; `T3.5` además **G1** |
| 4 | `tasks/fase-4-variaciones.md` | `T4.1`–`T4.5` | **5** | Variaciones en update (D4) + warehouse diferido (INV-06) | Fase 2; `T4.4` además **G8** |
| 5 | `tasks/fase-5-consumidor-final.md` | `T5.1`, `T5.2a`, `T5.2b`, `T5.3`–`T5.7` | **8** | **CF: engine + conectar + self-heal 400** (D1) | Fase 1; `T5.5` además **G6** |
| 6 | `tasks/fase-6-cf-ui.md` | `T6.1`–`T6.4` | **4** | CF UI honesta + "Verificar ahora" (D1) | Fase 5 |
| 7 | `tasks/fase-7-poll-budget.md` | `T7.1a`, `T7.1b`, `T7.1c`, `T7.2a`, `T7.2b`, `T7.3`–`T7.6` | **9** | Poll: budget + cursor + `truncated` + `set_syncing` (D6) | Fases 2–3 |
| 8 | `tasks/fase-8-lock-cron.md` | `T8.1`–`T8.6` | **6** | Lock shutdown + cron budget + cron real (D7) | Fase 7 |
| 9 | `tasks/fase-9-regresion-release.md` | `T9.1`–`T9.4`, `T9.5a`, `T9.5b`, `T9.5c`, `T9.6` | **8** | Regresión R1–R19 + release 2.6.0 + aceptación | Fases 0–8 |
| **Total** | | | **75** | | |

**Reconteo:** 8 + 12 + 8 + 7 + 5 + 8 + 4 + 9 + 6 + 8 = **75 micro-tareas** (el skeleton tenía 69; los
agentes agregaron +6 por los splits: **+1** en Fase 5 (`T5.2a/b`), **+3** en Fase 7 (`T7.1a/b/c` +
`T7.2a/b`), **+2** en Fase 9 (`T9.5a/b/c`)).

### 2.1 Mapa canónico de IDs de test `T29.*` (AUTORIDAD)

> **Regla de autoridad (C7–C9).** Este mapa es **canónico y autoritativo**. El índice final de la
> **última micro-tarea de tests de cada fase** (`T5.7`, `T6.4`, `T7.6`, `T8.6`, `T9.5a`) manda sobre
> cualquier ID inline de las micro-tareas anteriores. **Regla de reconciliación:** la lista final de
> tests del archivo de fase es la autoridad; ante cualquier drift, este mapa se ajusta a esa lista (así
> se reconcilió Fase 7). Un ID inline que difiera queda **superseded** y el worker usa el de acá. Total
> canónico: **72 IDs**.

| Fase | Tests (canónico) | N |
|---|---|---|
| 1 | `T29.11`–`T29.18`, `T29.110` | 9 |
| 2 | `T29.21`–`T29.29` | 9 |
| 3 | `T29.31`–`T29.39`, `T29.32b/c`, `T29.34b/c`, `T29.36b`, `T29.37b/c` | 16 |
| 4 | `T29.41`–`T29.45` | 5 |
| 5 | `T29.51`, `T29.52`, `T29.520`, `T29.53`–`T29.59` (índice de `T5.7`) | 10 |
| 6 | `T29.61`–`T29.64`, `T29.640` | 5 |
| 7 | `T29.71`, `T29.72`, `T29.72b`, `T29.73`–`T29.75`, `T29.75b`, `T29.76` (índice de `T7.6`) | 8 |
| 8 | `T29.81`–`T29.85` | 5 |
| 9 | `T29.91`–`T29.95` (índice de `T9.5a`) | 5 |
| **Total** | | **72 IDs** |

### 2.2 Mapa canónico R1–R19 → test `T29.*` (AUTORIDAD de la matriz de Fase 9)

> La matriz de `T9.1` (`fase-9`, sección de la matriz R) debe usar **estos** IDs. Los de abajo son los
> canónicos (reconciliados con §2.1); el agente de Fase 9 reemplaza los suyos por estos.

| R | Riesgo | Test canónico | Fase | Corrección vs `fase-9` actual |
|---|---|---|---|---|
| R1 | El refactor reescribe stock en el deploy | `T29.23`, `T29.24` | 2 | igual |
| R2 | El ajuste doble-descuenta si además se factura | `T29.37` | 3 | era `T29.35` (hooks) |
| R3 | El poll re-infla tras un push fallido | `T29.34` | 3 | igual |
| R4 | `_stock_status` rompe `backorders=no` | `T29.23` | 2 | era `T29.28` (docblock) |
| R5 | Honrar `preserve_fields` cambia comportamiento | `T29.25` | 2 | era `T29.22` (compuertas) |
| R6 | El self-heal duplica la factura | `T29.56` | 5 | era `T29.55` (self-heal) |
| R7 | El self-heal loopea entre requests | `T29.58` | 5 | era `T29.56` (idempotencia) |
| R8 | Barrido CONTAINS incompleto | `T29.51` | 5 | igual |
| R9 | `probe()`/render POSTea por accidente | `T29.52`, `T29.64` | 5/6 | igual |
| R10 | `set_syncing` TTL 300 < poll largo | `T29.74` | 7 | igual |
| R11 | Anidado apaga el flag externo | `T29.74` (borde) | 7 | igual |
| R12 | El shutdown libera un lock ajeno | `T29.81` | 8 | igual |
| R13 | Solapamiento del lock global del cron | `T29.82`, `T29.83` | 8 | igual |
| R14 | `wc_update_product_stock` ausente en WC viejo | `T29.21` | 2 | `T29.21` **debe** incluir el assert del fallback (`function_exists` forzado a `false`), que hoy no tiene test asignado |
| R15 | Opciones nuevas ausentes ⇒ el gate bloquea | `T29.18` | 1 | igual |
| R16 | Divergencia harness↔producción | `T29.11`–`T29.16` | 1 | igual |
| R17 | El cursor del poll se borra para otra entidad | `T29.73` | 7 | igual |
| R18 | Regresión facturación/pagos/webhooks/DIAN | `T29.91`–`T29.94` | 9 | igual |
| R19 | Quitar el `_stock_status` forzado cambia el estado con `woocommerce_notify_no_stock_amount > 0` (FIX-19 / Oracle D10) | `T29.23` (extendido) | 2 | nueva (FIX-19) |

---

## 3. Orden de ejecución (extraído de las dependencias de cada fase, NO del número)

**El número de tarea NO es el orden de build.** El orden real, con los paralelizables marcados:

### Bloque 0 — Gates (read-only, los 8 en paralelo)

```
T0.1 (G1) ∥ T0.2 (G2) ∥ T0.3 (G3) ∥ T0.4 (G4) ∥ T0.5 (G5) ∥ T0.6 (G6) ∥ T0.7 (G7) ∥ T0.8 (G8)
   →  docs/sdd/sync-reliability/phase0-results.md
```

### Bloque 1 — Cimientos (Fase 1; arranca ya, sin gates)

```
T1.1 → T1.2 → { T1.3 ∥ T1.4 ∥ T1.5 ∥ T1.6 } → T1.7 → T1.8 → T1.9 → T1.10 → T1.11 → T1.12
```

- `T1.1`–`T1.6` son **harness** (`scripts/lib/wp-stubs.php`, `scripts/lib/alegra-mock.php`); `T1.2`
  depende del helper que crea `T1.1`. `T1.3`–`T1.6` son mutuamente independientes.
- `T1.7` (opciones), `T1.8` (`Write_Gate` inventory), `T1.9` (Client), `T1.10` (helpers del ledger) son
  producto. `T1.10` es prerequisito de `T3.1`.
- `T1.11` (sección `T29` en `exec-test.php`) y `T1.12` (baseline verde) **cierran** la fase.

### Bloque 2 — `Inventory_Writer` (Fase 2) [central]

```
T2.1 → T2.2 → T2.3 → T2.4 → T2.5 → T2.6 → T2.7 → T2.8
```

- `T2.1` crea la clase; `T2.2` fija las compuertas; `T2.3` (W1) y `T2.4` (W2) convergen (la fase declara
  `T2.3` como dependencia de `T2.4`, así que van en serie).
- `T2.5` (`manage_stock` opt-in) va **después** de `T2.4` (usa su `$opts`).
- **Gate de fase:** `grep -rnE 'set_manage_stock|set_stock_quantity|set_stock_status' includes/ public/ admin/`
  devuelve matches **solo** en `includes/Sync/Inventory_Writer.php` (`T2.7`).

### Bloque 3 — `Inventory_Pusher` (Fase 3) [central / titular]

```
T3.1 → T3.2 → T3.3 → T3.4 → T3.5* ──┐
        └─ T3.6 ─────────────────────┴→ T3.7
```

- `T3.1`/`T3.2` (clase + ledger) dependen de `T1.10` y `T2.1`.
- `T3.3` (hooks) y `T3.4` (interacción con el poll) van después de `T3.2`.
- `T3.6` (informe de divergencia) sólo depende de `T3.1`; puede correr en paralelo a `T3.3`–`T3.5`.
- `T3.7` cierra la fase y depende de `T3.1`–`T3.6` (`fase-3:1185`), **incluido `T3.6`**.
- `T3.5` está `BLOQUEADO(Fase 0.1 / G1)` para el cierre de la rama `invoice`; la rama `adjustment`
  (default) **no** está bloqueada.
- **Gate de fase:** el test del titular (vender 3 → poll → WC no sube) en verde (`T29.34`).

### Bloque 4 — Variaciones + warehouse (Fase 4)

```
{ T4.1 ∥ T4.2 } → T4.3 → T4.4* → T4.5
```

- `T4.1`/`T4.2` dependen de `T2.1` (el update de variación pasa por W1 → writer) y son independientes.
- `T4.3` (chunked) consume el handler resuelto por `T4.2`.
- `T4.4` está `BLOQUEADO(Fase 0.8 / G8)`.

### Bloque 5 — Consumidor Final: engine + self-heal (Fase 5) [central]

```
T5.1 → T5.2a → T5.2b → { T5.3 ∥ T5.4 } → T5.5* → T5.6 → T5.7
```

- `T5.1` (`scan_candidates`/`cache_id`) es prerequisito de `T5.2a` (`probe`/`resolve_readonly`).
- `T5.2b` (`probe_state`/persistencia) consume el array de `T5.2a`.
- `T5.3` (conectar) y `T5.4` (AJAX "Verificar ahora") consumen `T5.2a`/`T5.2b`.
- `T5.5` (self-heal 400) está `BLOQUEADO(Fase 0.6 / G6)` para el detector exacto; la base es idéntica
  en ambas ramas.
- **Gate de fase:** CONTAINS no pierde el CF y el self-heal no duplica factura (`T29.5x`).

### Bloque 6 — CF UI honesta (Fase 6) [cosmética]

```
T6.1 → T6.2 → T6.3 → T6.4
```

- Consume `T5.2a` (`probe()`/`probe_state()`) y `T5.4` (AJAX). Es la última pieza de FRONT A.

### Bloque 7 — Poll budget/cursor (Fase 7)

```
T7.1a → T7.1b → { T7.1c ∥ T7.2a ∥ T7.2b ∥ T7.3 } → T7.5 → T7.4 → T7.6
```

- `T7.1a` (firma + shape) habilita todo; `T7.1b` reescribe el loop.
- `T7.2a` (quitar `set_time_limit`), `T7.2b` (reset de cursor) y `T7.3` (`set_syncing`) dependen de
  `T7.1b` y son paralelos.
- `T7.5` (deadline en `run_inventory_sync`) depende de `T7.1a`; `T7.4` (AJAX) consume `T7.1c`/`T7.2b`/
  `T7.5`; `T7.6` cierra.

### Bloque 8 — Lock/cron (Fase 8) [robustez]

```
T8.1 → T8.2 → T8.3 → { T8.4 ∥ T8.5 } → T8.6
```

- `T8.1` consume el `try/finally` de Fase 7; `T8.2` propaga el deadline global que `T7.1a`/`T7.5` ya
  aceptan; `T8.3` depende de `T8.2`.
- `T8.4` (UI de cron real) y `T8.5` (AS diferido) son paralelos e independientes (pueden arrancar antes).

### Bloque 9 — Regresión y release (Fase 9)

```
T9.1 → T9.2 → T9.3 → T9.4 → T9.5a → T9.5b → T9.5c → T9.6
```

### Grupos paralelizables (resumen)

| Grupo | Tareas |
|---|---|
| Gates | `T0.1` ∥ `T0.2` ∥ `T0.3` ∥ `T0.4` ∥ `T0.5` ∥ `T0.6` ∥ `T0.7` ∥ `T0.8` |
| Seams de harness | `T1.3` ∥ `T1.4` ∥ `T1.5` ∥ `T1.6` |
| Variaciones | `T4.1` ∥ `T4.2` |
| CF engine | `T5.3` ∥ `T5.4` |
| Poll | `T7.1c` ∥ `T7.2a` ∥ `T7.2b` ∥ `T7.3` |
| Cron | `T8.4` ∥ `T8.5` |
| Independientes | `T3.6` (tras `T3.1`) |

---

## 4. Prerequisitos transversales de harness — canónico: **H1–H8 + H3b**

Los gaps del design §9.1 fueron **verificados** contra HEAD. Sin estos, grandes partes del plan son
**inobservables**. Casi todos viven en `scripts/` (**excluido del ZIP** vía `.distignore:15`).
**Excepción:** `T1.8` toca `includes/Write_Gate.php`, que **sí shipea** — ojo al revisar el ZIP.

| ID | Task | Gap | Archivo (HEAD verificado) | Qué modela / por qué | Bloquea |
|---|---|---|---|---|---|
| **`H1`** | `T1.1` | `wc_update_product_stock()` ausente | `scripts/lib/wp-stubs.php` (**0 matches** hoy) | Stub que setea `stock`, **deriva `stock_status`** (`alegra_mock_derive_stock_status()`) y dispara `woocommerce_product_set_stock` / `…variation…`. Sin esto D5 y el pusher no son testeables. | `T2.1`, `T2.8`, `T3.3`, `T3.7` |
| **`H2`** | `T1.2` | `WC_Product::save()` no deriva `_stock_status` | `scripts/lib/wp-stubs.php:1284` (`return $this->id;`) | Deriva `stock_status` cuando `manage_stock` está on. **Corrección C1 (Fase 1/0):** `:1363` es `WC_Order::save()`, **no** una variación; `WC_Product_Variation` (`:1289-1296`) hereda el `save()` de `:1284`. Se edita **solo `:1284`**. | `T2.1`, `T2.8` |
| **`H3`** | `T1.3` | Filtro `identification` **exacto**, no CONTAINS | `scripts/lib/alegra-mock.php:863` (`$num === $ident`) | Modo CONTAINS + setter `alegra_mock_set_contact_identification_mode('contains')`. Sin esto REQ-CF-05 no se puede probar. | `T5.1`, `T5.7` |
| **`H3b`** | `T1.3` | **NUEVO:** `alegra_mock_filter_contacts()` no aplica `start`/`limit` | `scripts/lib/alegra-mock.php:849-867` (devuelve todos) | Paginación observable (`array_slice`) en `/contacts`. Sin esto `scan_candidates` "encuentra" el CF en la página 0 y REQ-CF-05 pasa **sin probar paginación**. Registrado como C9 en Fase 4 y C4/C18 en Fase 1/5. | `T5.1`, `T5.2a`, `T5.7` |
| **`H4`/`H6`** | `T1.4` | Sin ruta `/inventory-adjustments` ni modelo de stock por ítem | `scripts/lib/alegra-mock.php` (**0 matches**); rutas POST en `:703/709/753/767/773` | Ruta que valida `{item.id,type,quantity}` y **aplica el delta** a `alegra_mock_state['items'][id]['inventory']['availableQuantity']`. Sin esto D2(a) no es testeable. | `T3.7`, `T5.7` |
| **`H5`** | `T1.5` | `POST /invoices` no rechaza `client.id` inexistente | `scripts/lib/alegra-mock.php:535-556` (solo valida presencia) | **Opt-in** vía `alegra_mock_set_invoice_client_check(bool)` (default **OFF**, reset en `alegra_mock_reset()`). Si `client.id` no existe ⇒ `400 {message:"El cliente no existe"}`. Estricto por defecto **rompería el baseline** (`exec-test.php:265-326` y `:4748,4775,4792,4809`). | `T5.5`, `T5.7` |
| **`H7`** | `T1.6` | `set_syncing`/transient de import no simulados | `public/Public/Public_.php:426-437`, `:371` | **Corrección C2 (Fase 1):** ya funciona (`set_transient('alegra_import_in_progress',1,300)` en `:433`; `trigger_sync()` lo corta en `:371`; el harness ya stubea transients en `wp-stubs.php:263-276`). **T1.6 es test-only: no toca harness.** | `T7.3`, `T7.6` |
| **`H8`** | `T1.7` | Opciones nuevas no sembradas | `alegra-connector.php:414-457` (`$defaults`), `:463-486` (`$non_autoload`), `uninstall.php` | **Corrección C5/C1 (Fase 1/3):** **6** sembrables en `$defaults` (las 5 + `open_invoice_on_paid`); **las 9** en `$non_autoload` + `uninstall.php`. `register_setting`/UI: dueños explícitos en `fase-1` T1.7 (push→`T3.5`, poll budget/max_pages→`T7.4`, manage_stock→`T2.5`, cron budget→`T8.2`, open_invoice→`T3.5`). | `T2.5`, `T3.2`, `T7.1`, `T8.2`, NFR-04 |
| **`T1.8`** | `T1.8` | `Write_Gate::ENTITY_DEFAULTS` sin `inventory` | `includes/Write_Gate.php:37-46`, `:52-54`, `:123` | **Gap del design §10.** `block_reason()` usa `ENTITY_DEFAULTS[$entity] ?? false` (`:123`); con `push_inventory_enabled` default `true` pero **ausente** en instalaciones existentes, el gate devolvería `entity_disabled` y **bloquearía el ajuste**. Agregar `ENTITY_DEFAULTS['inventory'] = true`. | `T3.1`, `T3.7`, NFR-06 |

### Alcance de cada seam (por qué importa)

- **`H1`** convierte D5 en testeable: sin `wc_update_product_stock` el writer cae al fallback y el
  estado derivado no se puede observar.
- **`H2`** es de **alta superficie**: `save()` lo llaman muchísimos tests; la derivación puede cambiar
  aserciones. Guard: solo deriva con `manage_stock` on y correr el harness tras cada seam.
- **`H3`/`H3b`** son el único seam que cambia la **semántica del filtro**; sin H3b la paginación de
  REQ-CF-05 es vacua.
- **`H4`/`H6`** son el corazón del titular: sin modelo de stock por ítem no hay forma de probar que el
  poll no re-infla.
- **`H5`** hace real el self-heal del 400 (pero **opt-in** para no romper el baseline).
- **`T1.8`** evita un bug de producción introducido por el diseño (gate bloqueando el default).

> **`scripts/` está excluido del ZIP** (`.distignore:15`). Los tests **no** se distribuyen. `T1.8`/`T1.7`/
> `T1.9`/`T1.10` **sí** shipean.

---

## 5. Gates G1–G8 (Fase 0)

Cada gate es **read-only** y su salida va a `docs/sdd/sync-reliability/phase0-results.md` (lo crea `T0.1`).

| Gate | Task | Check exacto | Rama A | Rama B | Rama C | Bloquea |
|---|---|---|---|---|---|---|
| **G1** | `T0.1` | En la cuenta: factura `draft` con un ítem de stock conocido → observar stock; abrirla (`open`) → observar | `draft` **no** mueve y `open` sí ⇒ (b) viable como dueño cuando `push_orders=true`; `T3.5` implementa `open_invoice_on_paid` | `draft` **sí** mueve ⇒ el dueño factura se mantiene pero **se documenta**; `T3.5` evita el doble descuento | error/red/sin borradores ⇒ gate **INCONCLUSO**; `T3.5` queda **BLOQUEADO** (rama `invoice`); la rama `adjustment` sigue | `T3.5` |
| **G2** | `T0.2` | Cambiar **solo** el stock de un ítem en Alegra y ver el Recorder (`templates/admin-webhooks.php`) | `edit-item` dispara ⇒ el webhook da tiempo real | No dispara ⇒ el poll (Fase 7) es el único camino; el update cubre igual | — | `T4.1` (solo la rama de tiempo real) |
| **G3** | `T0.3` | `POST /inventory-adjustments` en la cuenta/docs: confirmar campos (`type`,`quantity`,`item`,`warehouse`,`date`, `unitCost`) y si hay query de idempotencia | Schema coincide ⇒ `push_inventory_enabled` default **`true`** | No coincide ⇒ default **`false`** + reporte; se ajusta el payload (nunca a ciegas, DR14) | — | `T3.1`, `T3.2` |
| **G4** | `T0.4` | Contar ítems activos en Alegra (`GET /items?metadata=true`) | Catálogo chico ⇒ defaults actuales (budget 60, max_pages 0) | Catálogo grande ⇒ se documenta que el poll pausa/reanuda por cursor | — | `T7.1` (solo default) |
| **G5** | `T0.5` | Revisar `DISABLE_WP_CRON` + `wp cron event list` | Ya usa cron real ⇒ recomendación informativa | WP-Cron por tráfico ⇒ recomendación accionable + crontab | — | `T8.4` (solo copy) |
| **G6** | `T0.6` | Forzar un 400 por client id muerto y capturar el body exacto (`response.client` vs mensaje) | Body trae `client` ⇒ el detector usa el campo | Solo mensaje ⇒ el detector cae al regex `/client|cliente/i` | El error no es 400 ⇒ self-heal **no** dispara; `T5.5` **BLOQUEADO** | `T5.5` |
| **G7** | `T0.7` | `php -r 'var_dump(function_exists("wc_update_product_stock"));'` en el WC del comerciante | Existe ⇒ API recomendada | No existe (WC < 3.0, improbable) ⇒ fallback setter+save | — | `T2.1` (solo fallback) |
| **G8** | `T0.8` | Semántica de bodega (R7 del SDD `inventory`, REQ-WH-2) | Hay semántica clara ⇒ se **puede** implementar warehouse-aware (cambia `T4.4`) | **Diferido** (esperado): el poll lee el total; la UI lo advierte | — | `T4.4` |

> **G3 no bloquea por la plataforma, bloquea por el payload.** Si el schema no coincide, el push se
> desactiva por defecto (`push_inventory_enabled=false`) y se reporta; **nunca** se escribe a ciegas
> (DR14).
>
> **G1 es el único gate que decide una rama de código real** (dueño `invoice` vs `adjustment`). Con la
> rama B, el dueño `invoice` se mantiene pero se documenta; la rama `adjustment` (default) **nunca**
> depende de G1.

**DoD de Fase 0:** `phase0-results.md` existe con una fila por gate (rama + evidencia cruda); G1/G3/G6/
G7 definen sus decisiones; ningún gate escribió producción; **Fase 1 no esperó a ningún gate**.

---

## 6. Matriz de trazabilidad REQ → micro-tarea

### Funcionales (23)

| REQ | Micro-tareas | Nota |
|---|---|---|
| REQ-CF-01 | `T5.3` | conectar resuelve el CF read-only bajo `run_explicit` |
| REQ-CF-02 | `T5.2a`, `T5.2b`, `T6.1`, `T6.4` | 3/4 estados honestos; render sin red |
| REQ-CF-03 | `T5.4`, `T6.2` | "Verificar ahora" + nonce/cap |
| REQ-CF-04 | `T5.2a`, `T5.3` (Rama A); `T5.4` (Rama B) | `resolve_readonly` elegida; crear solo explícito |
| REQ-CF-05 | `T1.3` (H3 + H3b), `T5.1` | CONTAINS + paginación + barrido completo |
| REQ-CF-06 | `T0.6`, `T1.5`, `T5.5`, `T5.6` | self-heal 400 + no duplicar |
| REQ-CF-07 | `T5.2a`, `T5.2b`, `T6.1`, `T6.3` | fail-loud con motivo legible |
| REQ-CF-08 | `T5.5`, `T5.7` | solo el 400+CF cambia el camino de factura |
| REQ-INV-01 | `T0.1`, `T0.3`, `T1.4`, `T1.9`, `T1.10`, `T3.1`–`T3.5`, `T3.7` | titular (dueño híbrido) |
| REQ-INV-02 | `T2.1`–`T2.5`, `T2.7`, `T2.8` | `Inventory_Writer` (un solo escritor) |
| REQ-INV-03 | `T0.2`, `T4.1`–`T4.3`, `T4.5` | variaciones en update |
| REQ-INV-04 | `T0.7`, `T1.1`, `T1.2`, `T2.1`, `T2.6`, `T2.8` | `wc_update_product_stock` + backorders |
| REQ-INV-05 | `T2.2`, `T2.3`, `T2.4`, `T2.8` | `preserve_fields` |
| REQ-INV-06 | `T0.8`, `T4.4` | warehouse diferido + aviso |
| REQ-INV-07 | `T3.6` | informe de divergencia |
| REQ-INV-08 | `T0.3`, `T1.4`, `T1.8`, `T3.2`, `T3.5` | no doble conteo |
| REQ-POLL-01 | `T7.1a`, `T7.1b`, `T7.2a`, `T7.2b`, `T7.5` | budget + cursor |
| REQ-POLL-02 | `T0.4`, `T7.1a`, `T7.1b`, `T7.1c`, `T7.4` | tope + `truncated` |
| REQ-POLL-03 | `T8.1`, `T8.2`, `T8.3` | shutdown + cron budget |
| REQ-POLL-04 | `T1.6`, `T7.3` | `set_syncing` sin cascada |
| REQ-POLL-05 | `T0.5`, `T8.4` | cron real en UI |
| REQ-POLL-06 | `T8.2` | no bloquea checkout/facturación (⚠️ cobertura fina: 1 tarea) |
| REQ-POLL-07 | `T8.5` | AS diferido, fallback WP-Cron |

### No funcionales (7)

| NFR | Micro-tareas | Nota |
|---|---|---|
| NFR-01 | `T1.12`, `T5.7`, `T9.1`, `T9.2`, `T9.3`, `T9.4` | sin regresión factura/pago/webhook |
| NFR-02 | `T7.1b`, `T7.2a`, `T7.3`, `T8.2` | poll acotado (pool FPM) |
| NFR-03 | `T5.1`, `T6.3`, `T7.1b`, `T7.2a` | distribuido, sin supuestos de host |
| NFR-04 | `T1.7`, `T5.2b`, `T5.4`, `T6.2`, `T7.1c`, `T7.4`, `T9.5a` | opciones con default + AJAX con `message` |
| NFR-05 | `T9.3`, `T9.5b`, `T9.5c` | ZIP sin `scripts/` |
| NFR-06 | `T1.8`, `T5.3`, `T5.4`, `T6.2` | compuertas + nonce/cap + sin secretos |
| NFR-07 | `T3.2`, `T5.5`, `T5.6`, `T7.2b` | idempotencia |

### Cobertura de `docs/sdd/inventory/` (consumido, no duplicado)

| Requerimiento `inventory` | Estado en HEAD | Este cambio |
|---|---|---|
| REQ-DIV-1 (un solo escritor) | No implementado (`Inventory_Writer` = 0) | **REQ-INV-02** (`T2.x`) |
| REQ-DIV-3 (update no reenvía `inventory`) | Implementado (`Products.php:986-1015`) | Se respeta; **REQ-INV-01** usa `/inventory-adjustments` |
| REQ-FAIL-4..6 (error/reanudable/truncación) | Parcial | **REQ-POLL-01/02** (`T7.x`) |
| REQ-GATE-1..2 (kill switch, lock/cancelación) | Implementado (`:1149,1163,1175`) | Se consume; **REQ-INV-02** unifica compuertas |
| REQ-WH-1/4 (bodega leída = escrita) | No en el poll (`:1238` lee total) | **REQ-INV-06** (`T4.4`, diferido + aviso) |
| REQ-TYPE-1..6 (tipos) | Parcial (W1 distingue) | **REQ-INV-02/03** |
| REQ-INV-1/2 (factura al pagar) | No implementado (`open_invoice_on_paid` = 0) | **REQ-INV-01 rama b** (`T3.5`) |
| REQ-DIV-4 (informe de divergencia) | No implementado | **REQ-INV-07** (`T3.6`) |

### ⚠️ Coverage gaps

**Ningún REQ ni NFR queda sin al menos una micro-tarea.** Los 30 REQ (23 funcionales + 7 NFR) están
mapeados. La única cobertura **fina** (1 sola tarea) es **REQ-POLL-06** → `T8.2` (el presupuesto global
del cron es el mecanismo que garantiza que el poll no bloquee checkout/facturación). No es un gap, pero
conviene verificarlo con el test `T29.82`.

---

## 7. Correcciones consolidadas (audit trail)

Todas las citas fueron re-verificadas leyendo HEAD por los agentes que escribieron cada archivo de fase.
**Original → realidad verificada → dónde impacta.** Total: **100 entradas** (los 4 grupos de agentes
reportaron ~40 correcciones reales; el resto de las entradas son confirmaciones con línea exacta).

### 7.1 Fase 0 (`fase-0-gates.md`, C1–C6)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `design.md:1014` H1 `wc_update_product_stock()` ausente | **Confirmado:** 0 matches en `scripts/`, `includes/`, `public/`, `admin/` | G7 (`T0.7`), H1 (`T1.1`) |
| C2 | `design.md:1002` H2 `wp-stubs.php:1363` (variación) | **Falso:** `:1363` es `WC_Order::save()`. `WC_Product_Variation` (`:1289-1296`) hereda `WC_Product::save()` (`:1284`) | `T1.2` edita **solo `:1284`** |
| C3 | `tasks.md:232` H5 `POST /invoices` solo valida presencia (`:535-556`) | Confirmado `:535-556`, pero estricto por defecto rompe el baseline (`exec-test.php:265-326,4748,4775,4792,4809`) | H5 **opt-in** (`T1.5`) |
| C4 | `tasks.md:227` H3 CONTAINS (`alegra-mock.php:863`) | Confirmado `:863`, pero `alegra_mock_filter_contacts()` (`:849-867`) tampoco aplica `start`/`limit` | **H3b** nueva (`T1.3`) |
| C5 | `tasks.md:305` T1.7 "las 8 opciones en `$defaults`" | El design §11 marca `$defaults`=sí solo para **5** (+ la 9.ª de Fase 3) | `T1.7`: 6 en `$defaults`; 9 en `$non_autoload`+`uninstall.php` |
| C6 | `design.md:1173` G8 "R7 del SDD" | No hay pregunta binaria nueva; la decisión de diferir ya está tomada (`design.md:1179-1183`) | `T0.8` cierra G8 como **diferido** |

### 7.2 Fase 1 (`fase-1-cimientos.md`, C1–C7)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | H2 "`:1284` y `:1363` (variación)" | `:1284` correcto; `:1363` es `WC_Order::save()`; variación hereda | `T1.2` edita solo `:1284` |
| C2 | H7 "`set_syncing`/transient no simulados" | **Falso:** `Public_::set_syncing(true)` ya escribe el transient (`:433`) y `trigger_sync` lo consulta (`:371`); el harness ya stubea transients (`wp-stubs.php:263-276`) | `T1.6` es **test-only** |
| C3 | H5 "rechaza client inexistente" | Correcto como capacidad, pero estricto rompe baseline | H5 **opt-in** default OFF |
| C4 | H3 CONTAINS | `:863` confirmado; `filter_contacts` sin `start`/`limit` | **H3b** (`T1.3`) |
| C5 | T1.7 "`$defaults` con las 8" | `$defaults`=sí solo para 5 (+ la 9.ª de Fase 3) | `T1.7`: 6 en `$defaults`; 9 en `$non_autoload`+`uninstall` |
| C6 | T1.11 "`alegra_test_reset()` limpia metas del ledger" | **Ya lo hace** (`wp_postmeta=[]`, `test-framework.php:148`) | `T1.11` no toca el reset |
| C7 | T1.10 "`Inventory_Pusher` estático o trait" | El design define `final class` con métodos de instancia | `T1.10` crea la superficie estática; `T3.1` completa la clase |

### 7.3 Fase 2 (`fase-2-inventory-writer.md`, C1–C7)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `apply_stock()` usa `wc_update_product_stock($p,$qty,'set',false)` | El 4.º arg existe desde WC 6.2; en 3.0–6.1 PHP ignora el extra; en <3.0 no existe | `T2.1` usa el 4.º arg + fallback |
| C2 | Enum de retorno (design §4.1) vs `docs/INVENTORY_DESIGN.md:750-752` | Otro set (`skipped_opt_out`, `skipped_warehouse_missing`) | Se adopta el **enum del design §4.1** |
| C3 | "los chequeos de `:2006-2008` se mueven al writer" | `:2006-2008` es la condición que gatea la llamada; único caller en `:2008` | `T2.3` mueve la decisión al writer |
| C4 | "W1 es el único lugar con `set_manage_stock`" | También `:2059` (padre), `:2067` (servicio), `:2072` (inventariable) | `T2.3` borra los tres |
| C5 | Forzado `:1259`/`:2099`; docblock `:2031-2034` | Confirmado; el docblock está **refutado** (WC deriva desde 3.0) | `T2.1`, `T2.4`, `T2.6` |
| C6 | "El dry-run ya bloqueaba la escritura de stock en WC" | **Falso:** HEAD escribe `set_stock_quantity()` en W1 (`:2097`) sin mirar `dry_run` | `T2.1`/`T2.2` agregan compuerta `dry_run` (**cambio intencional**) |
| C7 | `resolve_preserve_fields()` accesible desde W2 | Es `private` (`:1937-1941`) pero W2 es de la misma clase | `T2.4` usa `$this->resolve_preserve_fields()` |

### 7.4 Fase 3 (`fase-3-inventory-pusher.md`, C1–C8)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | Payload design §3.4 `{date,type,quantity,item,warehouse}` | **Falso vs schema:** exige top-level `date`+`items[]`, cada ítem `id`,`type`,`unitCost`,`quantity`; `warehouse` opcional | `T3.1`/`T3.2` construyen `{date,items:[{id,type,quantity,unitCost}],warehouse?}` |
| C2 | `on_stock_changed(int $product_id)` (design §10) | **Falso:** WC dispara el hook con el **objeto** `WC_Product`; un `int` daría TypeError | Handlers aceptan objeto y normalizan un id |
| C3 | `register_hooks()` sin argumentos | Con handlers de instancia, un `static` no puede construirlos | Firma `register_hooks(?Client,?Logger)` |
| C4 | `open_invoice_on_paid` en design §12/T3.5 | **Ausente** en la tabla de opciones del design §11 (que lista 8) | **9.ª opción** `alegra_connector_open_invoice_on_paid` (default true); `T1.7` pasa de 8 a **9** |
| C5 | "el dueño invoice se implementa abriendo al pagar" | Ya existe parcial: `create_invoice_with_payment()` pasa `'open'` solo si hay cuenta de pago (`Orders.php:352-360`) | `T3.5` agrega override **independiente** |
| C6 | `Controller::acquire_lock`/`release_lock` usables | Confirmado: `:414`/`:441`; `acquire_sync_lock_public` es `:523-526` (no `:511-525`) | `T3.2` usa `acquire_lock`+`release_lock` |
| C7 | "El poll ya corta la cascada por `set_syncing`" | **Falso en Fase 3:** `set_syncing` alrededor del poll es `T7.3`; HEAD no lo llama | `T3.4` setea `alegra_updating_product_{id}` |
| C8 | `resolve_warehouse_id()` reutilizable | Es `private` (`Products.php:1104-1110`) | El pusher tiene su **propia** copia |

### 7.5 Fase 4 (`fase-4-variaciones.md`, C1–C10)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Products.php:1611-1618` (creación itera hijos) | La rama arranca en `:1613`; `:1611-1612` son comentario; `foreach` `:1615-1617` | `T4.1` usa `:1613-1618`; **no se toca** |
| C2 | `design.md:731` rama existing `:1548-1557` | Confirmado; insertar entre `:1555` y `:1556` | `T4.1` |
| C3 | `tasks.md:368` skip variant `:1515-1517` | Confirmado exacto | `T4.2` reemplaza el bloque |
| C4 | `get_variant_children` sin línea | `:1643-1652`; acepta `itemVariants` (`:1645`) y `subitems` (`:1648`) | `T4.1` lo reusa |
| C5 | `import_variation_from_alegra()` sin línea | `:1766-1825`; GET `:1773`; update `:1782` | `T4.1`/`T4.2` |
| C6 | `Admin_Dashboard.php:2316` (chunked skip) | Confirmado; handler público `Products.php:2584-2587` | `T4.3` |
| C7 | `Products.php:1238` (total) y `:1118-1137` (push bodega) | Confirmado; `apply_warehouse()` solo en create (`:1129-1131`) | `T4.4` **no** toca el poll |
| C8 | `design.md:755` `alegra-mock.php:880` | Confirmado: `:880-885` filtra por `variantParent.id` | `T4.1` |
| C9 | **NUEVA — paginación de contactos** | `filter_contacts` (`:849-867`) no aplica `start`/`limit` | **H3b** (`T1.3`); impacta `T5.1` |
| C10 | Aviso de bodega sin línea | Pestaña **Bodegas** (`#tab-warehouse`), `templates/admin-settings.php:162-180`; insertar en `:178-179` | `T4.4` |

### 7.6 Fase 5 (`fase-5-consumidor-final.md`, C1–C22)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Consumidor_Final.php:181-184` (`limit=5`) | `limit=5` en `:183` | `T5.1`/`T5.2a` no tocan `resolve()` |
| C2 | `:201-213` (loop de match) | Confirmado; `break 2` replica | `T5.1` |
| C3 | `:241-251` (`matches`) | Confirmado (igualdad exacta) | `T5.1` lo reusa |
| C4 | `:263-277` (guard de `create()`) | `:272-277` exige `is_explicit()` o `push_customers_enabled` | `T5.5` no crea fuera del guard |
| C5 | `:279-285` (`write_was_blocked`) | `:282` | `T5.5` |
| C6 | `:392-397` (`invalidate_cache`) | Confirmado exacto | `T5.5` |
| C7 | `:458` (`store_metadata`) | `:458-473` | `T5.1` lo reusa |
| C8 | `:480-491` (`log_error`) | Confirmado | `T6.3` (fail-loud) |
| C9 | `tasks.md:385` `resolve` en `:138` | `:138-232`; **`cache_id()` no existe** (nuevo) | `T5.1` crea `cache_id()`; `resolve()` intacto |
| C10 | `Orders.php:121-129` (solo log) | Confirmado; self-heal **antes** del log, en `:121` | `T5.5` |
| C11 | `Orders.php:266-297` (`find_existing_invoice`) | Confirmado; match por `observations` `/Pedido WooCommerce #(\d+)/` `:289-292` | `T5.5` lo reusa |
| C12 | `Orders.php:1428-1435` (`persist_contact_id`) | Confirmado | `T5.6` borra el meta muerto |
| C13 | `Admin_Dashboard.php:1638-1743` (handler) | `ajax_test_connection()` va de `:1638` a `:1738` | `T5.3` |
| C14 | `:1722-1729` | Confirmado (`:1722` marca conectado; `:1729` send) | `T5.3` inserta entre `:1722` y `:1729` |
| C15 | `Write_Gate.php:41` (`contact`) y `:150` (`run_explicit`) | Confirmado | `T5.3`/`T5.4` |
| C16 | `alegra-mock.php:535-556` (invoice) | Confirmado: **no** verifica que `client.id` exista | **H5** (`T1.5`) |
| C17 | `alegra-mock.php:858-865` | Confirmado `:863` (`$num === $ident`) | **H3** (`T1.3`) |
| C18 | **NUEVA — paginación `/contacts`** | `filter_contacts` no aplica `start`/`limit` | **H3b** (`T1.3`) |
| C19 | `design.md:441` `new Client()` sin guard | `resolve()` sí guarda `class_exists` (`:175-178`) | `T5.2a` agrega el guard |
| C20 | `try_self_heal_dead_client` con `$retried` sin parámetro | La firma no lleva `$retried` | `T5.5` usa transient guard `alegra_cf_self_heal_{order_id}` TTL 60 |
| C21 | Tests que asumen `get_order_notes()` | El stub expone `get_notes(): array` (`wp-stubs.php:1408`) | `T5.5`/`T5.6`/`T5.7` usan `get_notes()` |
| C22 | Notas sin `$order->save()` | El stub `save()` es no-op; `delete_meta_data()` requiere `save()` | `T5.5`/`T5.6` llaman `$order->save()` |

### 7.7 Fase 6 (`fase-6-cf-ui.md`, C1–C8)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `templates/admin-dashboard.php:16-21,130-147` | Confirmado (`:19-21` `is_configured`; fila `:130-147`) | `T6.1` |
| C2 | `spec.md:173` `:135`/`:139` | Confirmado | `T6.1` modelo de 4 estados |
| C3 | Registro AJAX "junto a `:39`" | Confirmado `Admin_Dashboard.php:39` | `T6.2` |
| C4 | `get_script_strings()` en `:652` | Arranca en `:630`, cierra `:824`; `:652` es una línea del array | `T6.2`/`T6.3` agregan claves antes de `:823` |
| C5 | AJAX responde `{success,data:{state,id,message}}` | `wp_send_json_success` envuelve en `data` | `T6.2` consume `r.data.*` |
| C6 | `assets admin (admin.js)` | Archivo real: `admin/assets/js/admin.js` (encolado `:596`) | `T6.2` |
| C7 | **NUEVA** — `render_dashboard()` no pasa `$is_connected` | El template los re-defaults vía `get_option` (`:4-5`) | `T6.1` computa en el template |
| C8 | **NUEVA** — no hay botón "Verificar ahora" hoy | La acción actual solo muestra instrucción (`:143-145`) | `T6.2` crea botón + JS + i18n |

### 7.8 Fase 7 (`fase-7-poll-budget.md`, C1–C10)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Products.php:1290-1292` `finally` | Confirmado | `T7.3`, `T8.1` |
| C2 | `:1171` max_pages 200; `:1172` `set_time_limit(300)`; `:1174` página 1 | Confirmado | `T7.1b`, `T7.1c`, `T7.2a` |
| C3 | Patrón del import `:1305-1322` | Confirmado (cursor `:1305`, budget `:1318`, deadline `:1322`) | `T7.1b`, `T7.2b` |
| C4 | `import_from_alegra` no acepta deadline | Firma real `(int $page=1,int $per_page=30,int $run_id=0)` (`:1295`); el design §10 no lista el cambio | `T8.2` agrega 4.º param opcional |
| C5 | `set_syncing` import true `:1542`, false `:1632` | Confirmado; el poll **no** lo llama | `T7.3` |
| C6 | La cascada la corta el transient, no el static | Confirmado: `on_update_product` solo consulta el transient (`:229`); TTL 300 (`:433`) | `T7.3` refresca por página (DR8) |
| C7 | `ajax_sync_inventory` `:1811-1835` solo `updated` | Confirmado (`:1827-1834`) | `T7.4` |
| C8 | `run_inventory_sync()` `:110-115` no propaga deadline | Confirmado; call site del cron `:211` | `T7.5`, `T8.2` |
| C9 | `set_time_limit` y `register_shutdown_function` | `register_shutdown_function`=0 matches; `set_time_limit(300)`=1 (`:1172`) | `T7.2a`, `T8.1` |
| C10 | Opciones del poll | `_poll_budget`/`_max_pages`/`_pull_cursor`/`_pull_total`=0 matches | `T7.1b`, `T7.2b` |

### 7.9 Fase 8 (`fase-8-lock-cron.md`, C1–C12)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Products.php:1290-1292` `finally` | Confirmado | `T8.1` |
| C2 | `Controller.php:523-526` `acquire_sync_lock_public` | Confirmado; `release_sync_lock_public` `:531-534` | `T8.1` |
| C3 | `:475` clave + `:482` TTL 300 | Confirmado | `T8.1` |
| C4 | `:141` lock global `alegra_cron_global` TTL 600 | Confirmado | `T8.2`, `T8.3` |
| C5 | `:441` valida token al liberar | Confirmado (`:445`) | `T8.1` |
| C6 | `register_shutdown_function` en producción | **0** matches | `T8.1` |
| C7 | `import_from_alegra` no acepta deadline | Confirmado (`:1295`); el design §10 no lista el cambio | `T8.2` 4.º param opcional |
| C8 | `templates/admin-settings.php:466-476` card cron | Confirmado; está en `tab-advanced` (`:252`) | `T8.4` |
| C9 | `DISABLE_WP_CRON`/`wp_doing_cron` | **0** matches | `T8.4` |
| C10 | `uninstall.php:221-227` (AS) | Confirmado (`as_unschedule_all_actions` guardado) | `T8.5` |
| C11 | `.github/workflows/release.yml` `make_latest` | **Ya presente** en `:74` | `T9.5c` (nota) |
| C12 | Opción `alegra_connector_cron_run_budget` | **0** matches | `T8.2` |

### 7.10 Fase 9 (`fase-9-regresion-release.md`, C1–C10)

| # | Cita original | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | Baseline del harness | Corrido: **1626 assertions, 0 failed** (el doc hermano asumía 1289) | `T9.2` |
| C2 | `build-release.sh` valida 3 fuentes de versión | Header `:46`, README `:53`, make-pot `:59`; exit 9 (`:50/:56/:62`) | `T9.5a` |
| C3 | ZIP se construye y commitea local; CI verifica/publica | Confirmado (`build-release.sh:156-164,245-246`; CI `:43-66` no recompila) | `T9.5b`, `T9.5c` |
| C4 | `make_latest: "true"` | **Ya está** en `release.yml:74` | `T9.5c` (no tocar) |
| C5 | `.distignore:15` excluye `scripts/` | Confirmado; también `docs/`, `CHANGELOG.md`, `README.md`, `releases/`, `*.zip`, `*.sha256`, `.github/`, `.omo/` | `T9.3`, `T9.5b` |
| C6 | `build-release.sh` regenera `.pot` **después** del árbol limpio | Confirmado (árbol `:87-94`; `make-pot` `:140`) | Regenerar/commitear el `.pot` **antes** (`T9.5a`) |
| C7 | 3 fuentes de versión | `alegra-connector.php:6`, `README.md:9`, `make-pot.php:22`; `ALEGRA_CONNECTOR_VERSION` se deriva (`:59`) | `T9.5a` |
| C8 | `smoke-load.php` asserta PSR-4 | Patrón en `:196-200`; falta el assert de las clases nuevas | `T9.3` |
| C9 | Baseline de `RELEASE_2.5.0_VERIFICATION.md` | Ese doc registra **1592**; HEAD tiene **1626** | `T9.5a` |
| C10 | `uninstall.php` opciones | Lista `:50-134`; las 8 nuevas no están (las siembra `T1.7`) | `T9.5a` |

---

## 8. Registro de revisión (placeholder)

> **Estado: las revisiones cruzadas están corriendo.** Los 10 archivos de fase fueron escritos por
> grupos de agentes distintos, cada uno dueño de un subconjunto. Completar esta tabla cuando terminen
> las revisiones. Cualquier hallazgo se agrega a §11 (inconsistencias) o a §7 (audit trail).

| Revisión | Alcance | Revisor | Estado | Hallazgos |
|---|---|---|---|---|
| R-F0 | `fase-0-gates.md` | — | **PENDIENTE** | — |
| R-F1 | `fase-1-cimientos.md` | — | **PENDIENTE** | — |
| R-F2 | `fase-2-inventory-writer.md` | — | **PENDIENTE** | — |
| R-F3 | `fase-3-inventory-pusher.md` | — | **PENDIENTE** | — |
| R-F4 | `fase-4-variaciones.md` | — | **PENDIENTE** | — |
| R-F5 | `fase-5-consumidor-final.md` | — | **PENDIENTE** | — |
| R-F6 | `fase-6-cf-ui.md` | — | **PENDIENTE** | — |
| R-F7 | `fase-7-poll-budget.md` | — | **PENDIENTE** | — |
| R-F8 | `fase-8-lock-cron.md` | — | **PENDIENTE** | — |
| R-F9 | `fase-9-regresion-release.md` | — | **PENDIENTE** | — |
| R-CROSS | Coherencia entre fases (IDs, opciones, tests) | — | **EN CURSO** | Ver §11 |

---

## 9. Checklist de aceptación (los 5 problemas del comerciante)

| # | Problema / síntoma reportado | Micro-tareas | Resultado verificable (WP admin) |
|---|---|---|---|
| 1 | **"El dashboard dice 'Consumidor Final — No encontrado' aunque el contacto existe"** | `T5.1`, `T5.2a`, `T5.2b`, `T5.3`–`T5.6`, `T6.1`–`T6.4` | Tras conectar, la fila dice **Disponible** (o **No verificado** honesto); nunca "No encontrado" con el contacto existente; **"Verificar ahora"** funciona sin recargar; un CF borrado se **auto-sana** (`T5.5`) o deja nota accionable (`T5.6`) |
| 2 | **"Vendí y el stock volvió a subir solo → sobreventa"** | `T1.4`, `T2.1`–`T2.8`, `T3.1`–`T3.7` | Tras vender 3 en WC, un poll posterior **no** sube el stock; el ajuste WC→Alegra refleja el delta; **grep de un solo escritor** (`T2.7`) devuelve matches solo en `Inventory_Writer.php` |
| 3 | **"El stock no se actualiza / las variaciones quedan viejas"** | `T2.2`–`T2.4`, `T4.1`–`T4.3`, `T4.5` | Un cambio de stock de variación en Alegra se refleja por update; `preserve_fields` se respeta; `_stock_status` con backorders correcto (`T2.1`) |
| 4 | **"El sitio se ralentiza / el poll nunca termina"** | `T7.1a`–`T7.1c`, `T7.2a`, `T7.2b`, `T7.3`–`T7.5`, `T8.1`–`T8.3` | El poll pausa por budget, persiste cursor, reporta `truncated=true`; el lock se libera ante un fatal; no hay cascada de push (`T7.3`) |
| 5 | **"Reanudar vs reimportar / nunca completa"** | `T7.1a`, `T7.1b`, `T7.2b`, `T7.4`, `T8.4`, `T8.5` | El cursor es visible/reanudable; el poll de catálogo grande termina en varias corridas o pausa con señal; Ajustes muestra el cron real |
| 6 | **Sin regresión (distribuido)** | `T9.1`–`T9.4`, `T5.5`, `T5.7` | DIAN intacto; facturación/clientes/pagos/webhooks siguen; `inventory_source=woocommerce` sigue sin escribir stock |

### Mapa problema → verificación manual (`T9.4`)

- **Problema 1** → `T9.4` "Pasos CF" (conectar, "Verificar ahora", CF borrado → auto-sanado).
- **Problema 2** → `T9.4` "Pasos titular" (vender 3 → poll → WC no sube).
- **Problema 3** → `T9.4` "Pasos variaciones/backorders".
- **Problema 4** → `T9.4` "Pasos poll grande" (truncación visible, reanudar, lock libre).
- **Problema 5** → `T9.4` "Pasos cron real".

> La matriz **R1–R19** (riesgos de ejecución/regresión) vive en `T9.1`
> (`fase-9-regresion-release.md`), con su test concreto por riesgo. **No se duplica acá.**

---

## 10. Decisiones abiertas / BLOCKED-ON-VERIFICATION + default recomendado

### 10.1 Requerimientos marcados por estado en `spec.md`

| REQ | Estado | Decisión | Default recomendado | Micro-tarea |
|---|---|---|---|---|
| REQ-CF-04 | `FORK` | ¿Conectar crea el CF o solo lo resuelve read-only? | **Rama A: `resolve_readonly()`** (GET+match+caché, nunca POST); crear solo por acción explícita | `T5.2a`, `T5.3`, `T5.4` |
| REQ-INV-01 | `FORK` | ¿(a) ajuste WC→Alegra o (b) factura al pagar? | **D2 híbrido:** `owner=adjustment` (default, `push_orders=false`); `owner=invoice` si `push_orders=true` | `T3.1`, `T3.5` |
| REQ-INV-06 | `BLOQUEADO(ON-VERIFICATION)` | Warehouse-aware ahora o diferido | **Diferir + aviso UI** (depende de G8/R7) | `T4.4` |
| REQ-INV-07 | `FORK` | ¿Informe de divergencia? | **Sí, read-only** en el dashboard | `T3.6` |
| REQ-POLL-07 | `FORK` | ¿Action Scheduler? | **Diferir**; fallback WP-Cron cumple | `T8.5` |

### 10.2 Parámetros configurables (defaults para no bloquear)

| # | Decisión | Opciones | Default recomendado | Micro-tarea |
|---|---|---|---|---|
| 1 | **Dueño del movimiento** (`push_orders_enabled` + `open_invoice_on_paid`) | `adjustment` (false) / `invoice` (true) | **`false` ⇒ adjustment.** Arregla el titular con los defaults; el doble conteo se evita por la **condición doble de `owner()`** + las guardas del pusher (`design.md:589-608`), **no** "por construcción" | `T3.1`, `T3.5` |
| 2 | `push_inventory_enabled` | true / false | **`true`** (salvo G3: si el schema no coincide ⇒ `false` + reporte) | `T1.7`, `T3.1` |
| 3 | `inventory_manage_stock_enabled` (legacy `manage_stock=no`) | false / true | **`false`** (respeta el estado actual; opt-in para catálogos legacy) | `T2.5` |
| 4 | `inventory_poll_budget` (s) | 10..300 | **`60`** (host lento / pool chico) | `T7.1b` |
| 5 | `inventory_poll_max_pages` | 0 / N | **`0`** (sin tope; el cursor reanuda) | `T7.1b` |
| 6 | `cron_run_budget` (s) | < 600 | **`540`** (< TTL 600 del lock global) | `T8.2` |
| 7 | `open_invoice_on_paid` (solo `owner=invoice`) | true / false | **`true`** (alineado con `docs/sdd/inventory/`); **cambio intencional** documentado | `T3.5` |
| 8 | Warehouse-aware (REQ-INV-06) | implementar / diferir | **Diferir + aviso UI** (depende de G8/R7) | `T4.4` |
| 9 | Action Scheduler (REQ-POLL-07) | adoptar / diferir | **Diferir**; fallback WP-Cron cumple | `T8.5` |

### 10.3 Gates con decisión pendiente (Fase 0)

| Gate | Task | Default si no se corre / rama esperada | Tarea bloqueada |
|---|---|---|---|
| G1 | `T0.1` | Rama A esperada (`draft` no mueve, `open` sí) | `T3.5` (rama `invoice`) |
| G3 | `T0.3` | Rama A esperada (schema coincide) ⇒ `push_inventory_enabled=true` | `T3.1`, `T3.2` |
| G6 | `T0.6` | Detector por campo `response.client` o regex `/client|cliente/i` | `T5.5` |
| G7 | `T0.7` | Rama A (existe) ⇒ API recomendada; fallback solo defensivo | `T2.1` (fallback) |
| G8 | `T0.8` | Rama B (diferido) esperada | `T4.4` |

---

## 11. Inconsistencias detectadas entre archivos de fase

> Los agentes fueron dueños de archivos distintos; el drift cross-file es esperable. Estas son las
> inconsistencias **reales** halladas al recontar. Ninguna invalida el plan; son correcciones de
> coherencia a aplicar antes/durante la ejecución. **No se modificaron los archivos de fase.**

| # | Inconsistencia | Evidencia | Resolución propuesta |
|---|---|---|---|
| I1 | **8 vs 9 opciones nuevas.** Fase 1 (`T1.7`, H8, DoD) siembra **8**; Fase 3 (C4) introduce la **9.ª** `alegra_connector_open_invoice_on_paid` y dice "T1.7 pasa de 8 a 9". Fase 9 (`T9.5a`) lista solo 8 en el CHANGELOG y en `uninstall.php`. El `design.md` §11 lista 8. | `fase-1:115-126,693-746,1166`; `fase-3:45,649-656`; `fase-9:347-351,359-366`; `design.md` §11 | **T1.7 siembra 9** (6 en `$defaults` incl. `open_invoice_on_paid`; 9 en `$non_autoload`+`uninstall`). **Fase 1 ya corregida.** `T9.5a`/`design.md` §11 deben listar 9 (los corrige el agente de Fase 9/design). |
| I2 | **IDs de test de Fase 5 divergen entre el código inline y el índice final.** El código inline etiqueta `T29.52` (probe), `T29.53` (probe_state), `T29.54` (connect), `T29.55` (verify AJAX), `T29.56` (self-heal), `T29.57` (non-client); el índice canónico de `T5.7` renumera a `T29.520` (probe_state), `T29.53` (connect), `T29.54` (verify AJAX), `T29.55` (self-heal), `T29.56` (idempotency), `T29.57` (non-client), `T29.58` (unresolvable), `T29.59` (normal). | `fase-5:496,612,753,961,1011,1126-1138` | **Canónico = índice de `T5.7`** (10 IDs) y **§2.1**. El worker debe usar esos IDs al escribir los tests; los inline quedan superseded. |
| I3 | **Colisión de IDs de test en Fase 7.** `T29.72` se usa en `T7.1c` (truncated+log) **y** en `T7.2a` (source-scan `set_time_limit`); `T29.75` se usa en `T7.4` (AJAX) **y** en `T7.5` (deadline). | `fase-7:340,376,604,653` | **Canónico = índice de `T7.6`**: `T29.71`, `T29.72`, `T29.72b`, `T29.73`–`T29.75`, `T29.75b`, `T29.76` (8 IDs, §2.1). El AJAX es `T29.75` y el deadline propagado es `T29.75b`; los IDs inline duplicados (`T29.72`/`T29.75`) quedan superseded. El agente de Fase 7 asigna IDs únicos dentro de ese rango. |
| I4 | **La matriz R de Fase 9 usa IDs de test que no matchean las fases dueñas.** R1→`T29.23`/`T29.24` (Fase 2: `T29.23`=backorders, `T29.24`=W1); R2→`T29.35` (Fase 3: `T29.35`=hooks, no dueño invoice); R5→`T29.22` (Fase 2: `T29.22`=compuertas, no preserve); R6→`T29.55` (Fase 5: `T29.55`=self-heal, idempotency=`T29.56`). | `fase-9:78-95` vs `fase-2:831-841`, `fase-3:829-839`, `fase-5:1126-1138` | **Canónico = §2.2** (tabla R1–R19 → test). El agente de Fase 9 reemplaza su matriz por la de §2.2: R2→`T29.37`, R4→`T29.23`, R5→`T29.25`, R6→`T29.56`, R7→`T29.58`; R14→`T29.21` (que debe incluir el assert del fallback). |
| I5 | **Rangos de tests desactualizados en `T9.2`.** Lista `T29.41`–`T29.44` (falta `T29.45`), `T29.51`–`T29.57` (falta `T29.58`/`T29.59`/`T29.520`), `T29.61`–`T29.64` (falta `T29.640`); y `T9.1` dice `T29.91`–`T29.94` mientras `T9.2` dice `T29.91`–`T29.98`. | `fase-9` (T9.1/T9.2) | **Canónico = §2.1** (rangos y 72 IDs). El agente de Fase 9 reemplaza su tabla de rangos: Fase 4 `T29.41`–`T29.45`, Fase 5 `T29.51`–`T29.59` + `T29.520`, Fase 6 `T29.61`–`T29.64` + `T29.640`, Fase 9 `T29.91`–`T29.95`. El `≥1740` es un piso de aserciones; el conteo real lo da el runner. |
| I6 | **`T7.4` depende de `T7.5`, pero el AJAX no pasa deadline.** `T7.4` (AJAX) llama `run_inventory_sync()` sin argumento; `T7.5` solo cambia la firma para el cron (`T8.2`). La dependencia declarada es de orden, no funcional. | `fase-7:599,643-644` | Aclarar que `T7.4` no requiere el deadline de `T7.5`; la dependencia real es de secuencia. |
| I7 | **`open_invoice_on_paid` ausente del design §11.** El `design.md` §11 lista 8 opciones; Fase 3 la agrega como 9.ª. El design debe actualizarse (lo hace el agente de design). | `design.md` §11; `fase-3:45` | Registrar la 9.ª opción en `design.md` §11/`T1.7`; nota de release. |
| I8 | **`H7` no es un cambio de harness.** El skeleton lo listaba como gap de `wp-stubs.php`/`test-framework.php`; Fase 1 C2 demuestra que ya funciona. La lista "H1–H8 + H3b" incluye H7 como prerequisito, pero `T1.6` es **test-only**. | `fase-1:34,643-689` | Documentar H7 como "contrato ya existente, solo test de regresión" (no cuenta como cambio de harness). |
| I9 | **El reset del cursor por `pull_total` nunca se escribe.** `design.md` §7.3 resetea si `cursor >= total`; `design.md` §7.2 **sólo** hace `delete_option('…_pull_total')` al completar, **nunca** un `update_option` del total. Con total default `0`, `cursor >= 0` es **siempre** verdadero ⇒ el cursor se resetea en cada corrida y el resume no funciona. | `design.md` §7.2–§7.3; `fase-7` T7.1.b/T7.2.b; C6 (momus) | **Fix (dueño: Fase 7, agente de `fase-7`):** guardar `total > 0 && cursor >= total` **y** escribir `alegra_connector_inventory_pull_total` en el poll (p. ej. con `GET /items?metadata=true`, o al menos al completar). `tasks.md` sólo lo registra; la edición va en `fase-7`. |
| I10 | **`T7.3` y `T8.1` insertan en el mismo punto** (post-lock, pre-`try`) y cada uno muestra su propio "ANTES/DESPUÉS" de las mismas líneas. No es semánticamente incompatible, pero el orden no está definido. | `fase-7:455-466`; `fase-8:73-106`; C12 (momus) | **Orden canónico:** inmediatamente después de adquirir el lock va **primero** el bloque de `T8.1` (`$lock_key` + `register_shutdown_function`), **después** el de `T7.3` (`$was_syncing` + `set_syncing(true)`), y recién el `try`. El agente de Fase 7/8 inserta en ese orden. |
| I11 | **Colisión de claves en el `$result` del poll.** `T2.4` (Fase 2) agrega `skipped_not_manageable`; `T7.1.a` (Fase 7) reescribe el array con **8 claves** y dice "las 8 claves" ⇒ `T2.5`/`T3.4` incrementan un índice inexistente. `design.md` §7.2 también lista 8 (sin `skipped_not_manageable`). | `fase-2:517-522`; `fase-7:87-100`; `design.md` §7.2; C2 (momus) | **Canónico (fase-2 T2.4):** `$result` tiene **9 claves** — `updated`, `errors`, `pages`, `locked`, `skipped`, `skipped_not_manageable`, `truncated`, `completed`, `cursor`. `T7.1.a` **debe preservar** `skipped_not_manageable` y su assert debe decir 9. |

---

## 12. DoD global del cambio

- [ ] Gates G1–G8 con rama registrada en `docs/sdd/sync-reliability/phase0-results.md`.
- [ ] Prerequisitos de harness **H1–H8 + H3b + `T1.8`** aplicados (casi todos en `scripts/`, fuera del
      ZIP; `T1.8` toca `includes/Write_Gate.php`, que sí shipea).
- [ ] Las **75** micro-tareas `[x]`, cada una con su prove-it-catches documentado (revertir → rojo →
      re-aplicar → verde).
- [ ] `bash scripts/exec-test.sh` → `EXEC-TEST OK` con el conteo real (objetivo ≥ **1626** + nuevos;
      0 failed).
- [ ] `bash scripts/smoke-test.sh` → `SMOKE OK`; `smoke-load.php` asserta que `Inventory_Writer` y
      `Inventory_Pusher` cargan por PSR-4.
- [ ] `T9.4` checklist manual firmado + capturas del dashboard (CF), Ajustes (cron real) y del poll
      truncado.
- [ ] Matriz R1–R19 de `T9.1` con test concreto (reconciliada, ver §11-I4).
- [ ] Las **9** opciones nuevas sembradas (incl. `open_invoice_on_paid`, ver §11-I1).
- [ ] Release **2.6.0**: `CHANGELOG.md` (incluye la nota de **D2 vs `docs/sdd/inventory/DD-8`** y los
      cambios intencionales de `_stock_status`/`preserve_fields`), versión en header/README/make-pot,
      `uninstall.php` con las 9 opciones, `.pot` regenerado y commiteado, ZIP + `.sha256` commiteados,
      workflow `ASSET OK`.
- [ ] `docs/RELEASE_2.6.0_VERIFICATION.md` con los 5 problemas + prove-it-catches + rollback.

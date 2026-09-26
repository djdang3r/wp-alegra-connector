# Fase 7 — Compat 2.6.0 + reparación de corrupción existente + regresión + release 2.7.0 — micro-detalle

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **7** — D6 compat + corrupción existente + regresión + release **2.7.0** |
| Versión objetivo | **2.7.0** (la siguiente a 2.6.0; opciones nuevas + cambios de comportamiento ⇒ minor) |
| Harness | `bash scripts/exec-test.sh` (baseline real **1990 assertions, 0 failed** — `docs/RELEASE_2.6.0_VERIFICATION.md:71`) · `bash scripts/smoke-test.sh` · `bash scripts/build-release.sh` |
| Tareas del esqueleto | `T7.1`–`T7.8` (8 micro-tareas) |
| Depende de | Fases 0–6 (en particular `T2.1`, `T3.1`, `T3.2`, `T4.3`, `T5.4`, y los tests `T30.*`) |
| DoD de la fase | matriz R1–R20 con guarda + test; suite `1990 + nuevos` con `0 failed`; smoke verde; reparación del doble decremento existente read-only primero; `CHANGELOG` con los cambios intencionales; release 2.7.0 publicado (`ASSET OK` + `isLatest: true`); `docs/RELEASE_2.7.0_VERIFICATION.md` firmado |

> **Regla de oro heredada (prove-it-catches).** Cada test nuevo se valida revirtiendo el fix: se corre
> `bash scripts/exec-test.sh`, **ese** test debe fallar, se re-aplica y vuelve a verde. Las **5 tareas
> centrales** (`T2.1`, `T3.1`, `T3.2`, `T4.3`, `T5.4`) tienen su reversión documentada en
> `docs/RELEASE_2.7.0_VERIFICATION.md` (`T7.8`).
>
> **IDs de test.** `scripts/exec-test.php`, sección `// === stock-ownership (2.7.0) ===`, IDs `T30.*`
> (canónicos en `tasks.md` §5.3: **60 IDs**). `T7.x` son IDs de **tarea**.

---

## Correcciones de cita / hallazgos re-verificados en HEAD (Fase 7)

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `Inventory_Pusher.php:85-91` (`owner()` condición doble) | Confirmado textual (`:87-90`): `push_orders_enabled && open_invoice_on_paid ? invoice : adjustment`. | `T7.1` |
| C2 | `Orders.php:88-90` / `:122-126` | Confirmado: `:88-90` exige `owner()==='invoice'` **y** `get_option('open_invoice_on_paid', true)` **y** `is_paid()` para abrir el borrador; `:122-126` fuerza `status_override='open'` con `owner()==='invoice' && is_paid()`. | En `auto`, el `owner()==='invoice'` ya implica `open_invoice_on_paid=true` ⇒ quitar la dependencia de la opción en `:88-90` (`T2.3`) es **no-op** en auto. `T7.1` |
| C3 | `alegra-connector.php:509-513` (loop de defaults) | Confirmado. `:510` `get_option($key) === false` ⇒ **no pisa** valores existentes. | `T7.2` |
| C4 | `Write_Gate.php:182` (`maybe_migrate`) | Confirmado: `:182-220`, guard de versión `:186`; el `inventory` ya está en `ENTITY_OPTIONS` `:43` y `ENTITY_DEFAULTS` `:58`. | `T7.2` |
| C5 | `uninstall.php:50-134` (opciones) + locks por prefijo `:153-156` | **Corrección.** La lista de opciones es `:50-134` (confirmada). El borrado de locks por prefijo `alegra_lock_%` está en **`:162-165`** (no `:153-156`, que es el loop de `DROP TABLE`). El barrido de post meta `_alegra_%` está en **`:221-225`**. | `T7.2`, `T7.6` |
| C6 | `build-release.sh` valida 3 fuentes de versión | Confirmado: header `:46-51`, README `:53-57`, make-pot `:59-63`; aborta con **exit 9**. El chequeo de árbol limpio es `:87-94`; el `.pot` se regenera **después** (`:134-151`) ⇒ hay que commitear el `.pot` **antes** del build. | `T7.6` |
| C7 | 3 fuentes de versión | `alegra-connector.php:6` (`Version: 2.6.0`), `README.md:9` (`Version: 2.6.0`), `scripts/make-pot.php:22` (`$version = '2.6.0'`). `ALEGRA_CONNECTOR_VERSION` se **deriva** del header (`alegra-connector.php:59`), no hay constante que tocar. | `T7.6` |
| C8 | ZIP + `.sha256` + `make_latest` | Confirmado: `build-release.sh:156-164` (staging a `releases/`), `:234-241` (ZIP determinista), `:245-246` (`.sha256`), `:269-302` (tag/publish opt-in). `.github/workflows/release.yml:43-61` verifica, `:63-66` gates, `:68-78` publica, **`:74` `make_latest: "true"`**. CI **no recompila**. | `T7.6` |
| C9 | `.distignore:15` excluye `scripts/` | Confirmado. También `:18 releases/`, `:32 docs/`, `:33 CHANGELOG.md`, `:34 README.md`, `:57 *.zip`, `:58 *.sha256`. | `T7.6`, `T7.5` |
| C10 | `State_Sync.php:473`/`:488` | Confirmado, pero `:473` es `queue_admin_notice()` **private y transient** (60 s). El aviso option-backed se **agrega** (ver `T6.6`). | `T7.3` |
| C11 | `Client.php` y el token en logs | Confirmado: el único log de request es `API Request` (`Client.php:171-174`) y loguea **sólo** `method` + `endpoint`, **no** `headers` (el `Authorization` se arma en `:152`/`:937`). | `T7.7` |
| C12 | Baseline del harness | Confirmado: `docs/RELEASE_2.6.0_VERIFICATION.md:71` registra **1990 assertions, 0 failed**. | `T7.5`, `T7.6` |

**Citas confirmadas exactas:** `Inventory_Pusher.php:32-33,68-76,85-91,157-277,303-335,340-358`;
`Products.php:1327-1379,1404-1423,2274-2285`; `Orders.php:63-193,88-90,122-126,155-173,203-229,309-341,437-449,494-540`;
`Write_Gate.php:37-47,53-59,156,182-220`; `alegra-connector.php:6,59,207,414-468,474-507,509-513,518,667-675`;
`uninstall.php:48-134,162-165,221-225,231-233`; `.distignore:15,18,32-34,57-58`;
`scripts/build-release.sh:46-63,87-94,103-129,131-151,156-164,234-246,269-302`;
`scripts/make-pot.php:22`; `README.md:9`; `CHANGELOG.md:5`;
`.github/workflows/release.yml:43-61,63-66,68-78,74`; `docs/RELEASE_2.6.0_VERIFICATION.md:24-47,71`.

---

## Mapa de cobertura Fase 7 → requerimiento

| Micro-tarea | Qué cubre | Archivos |
|---|---|---|
| `T7.1` | Compat: `auto` = 2.6.0 + `_alegra_stock_synced` sin limpieza | `Inventory_Pusher.php:85-91`, `Orders.php:88-126`, `Products.php:1327-1379` |
| `T7.2` | Ledger aditivo + semilla idempotente + `uninstall.php` | `alegra-connector.php:509-513`, `Write_Gate.php:182`, `uninstall.php:48-134` |
| `T7.3` | CHANGELOG de cambios intencionales | `CHANGELOG.md` |
| `T7.4` | Detección + reparación del doble decremento existente | `Stock_Divergence.php`, `Admin_Dashboard.php`, `GET /inventory-adjustments?item_id=` |
| `T7.5` | Regresión NFR-01/02 (matriz R1–R20 + end-to-end) | `scripts/exec-test.php` |
| `T7.6` | ZIP sin `scripts/` + smoke + release 2.7.0 | `scripts/build-release.sh`, `.distignore`, 3 fuentes de versión, `releases/` |
| `T7.7` | Seguridad: nonce/cap en AJAX nuevos + sin token en logs | `Admin_Dashboard.php`, `API/Client.php`, `Logger` |
| `T7.8` | Aceptación manual + doc de release | `docs/RELEASE_2.7.0_VERIFICATION.md` (**nuevo**) |

---

### T7.1 — Compat: `auto` = 2.6.0 + `_alegra_stock_synced` sin limpieza

**Objetivo**: garantizar que una instalación 2.6.0 que actualiza a 2.7.0 y **no toca nada** se comporta
exactamente igual (`stock_owner=auto`), y que el ledger `_alegra_stock_synced` de 2.6.0 **no** necesita
limpieza ni migración.

**Descripción técnica**: la opción nueva se siembra en `auto` (`T1.5`) y `owner()` (`T2.1`) en `auto`
reproduce la **condición doble** de 2.6.0 (`Inventory_Pusher.php:87-90`, C1). Hay que verificar las **4
combinaciones** de `push_orders_enabled` × `open_invoice_on_paid` y que el cambio de `Orders.php:88-90`
(quitar la dependencia de la opción, `T2.3`) es no-op en `auto` (C2). El poll re-baselina de forma
conservadora (`Products.php:1327-1379`) y **no** se borra `_alegra_stock_synced` (`design.md:1029-1039`).
Cubre NFR-03 y REQ-OWN-02.

**Desarrollo técnico — tabla de verdad de `auto` (la prueba de compat):**

| `push_orders_enabled` | `open_invoice_on_paid` | `owner()` en 2.6.0 | `owner()` con `stock_owner=auto` | Igual |
|---|---|---|---|---|
| `false` | `true` (default) | `adjustment` | `adjustment` | ✅ |
| `false` | `false` | `adjustment` | `adjustment` | ✅ |
| `true` | `true` | `invoice` | `invoice` | ✅ |
| `true` | `false` | `adjustment` | `adjustment` | ✅ |

- **`_alegra_stock_synced` de 2.6.0:** sin limpieza. El poll, en su próxima pasada, resuelve cada ítem
  según la máquina de estados (`design.md:407-423`): `S` presente + `W==S` ⇒ `CONVERGED`; `S` presente +
  `W!=S` ⇒ `LOCAL_PENDING` (no pisa WC); `S==''` + `W==A` ⇒ `NO_BASELINE_EQ`; `S==''` + `W!=A` ⇒
  baseline desde Alegra + reconciliar/divergir.
- **`_alegra_stock_push_pending` sucio:** se limpia lazy en el poll cuando `owner()==='invoice'`
  (`T2.7`, `design.md:314-328`), no con una meta query.
- **`Orders.php:88-90`:** con `auto`, `owner()==='invoice'` sólo si `open_invoice_on_paid=true`; quitar
  la segunda condición no cambia ninguna de las 4 filas.

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — `auto` default + re-baseline conservador + sin limpieza (ELEGIDA)** | Cero cambio sin acción del comerciante; sin migración masiva. |
| B — Migrar `_alegra_stock_synced` a un valor nuevo | **Rechazada:** una query por meta en catálogo grande viola NFR-04. |
| C — Limpiar `_alegra_stock_synced` al activar | **Rechazada:** forzaría re-baselines en masa y podría pisar WC. |

**Resultado esperado**: las 4 combinaciones dan el mismo `owner()` que 2.6.0; una instalación existente
no cambia de comportamiento; `_alegra_stock_synced` se re-baselina sin borrarse.

**Dependencias**: `T2.1` (`owner()`), `T2.3` (`Orders.php:88-90`), `T3.1` (máquina de estados),
`T3.2` (baseline import).

**Trazabilidad**: NFR-03, REQ-OWN-02; `design.md` §7.1/§7.2 (`:1014-1039`); `spec.md:1129-1158`.

**Verificación**: test `T30.71` (compat `auto` = 2.6.0, las 4 combinaciones). **Prove-it-catch:**
hardcodear `owner()` a `adjustment` (o simplificarlo a `push_orders_enabled` solo, defecto C1) ⇒ `T30.71`
falla.

**Riesgo**: que un cambio de refactor en `Orders.php` introduzca una diferencia sutil en `auto`. Se
mitiga con el test de las 4 combinaciones **y** el end-to-end de `T7.5`.

**Estimación**: M (3 h).

---

### T7.2 — Ledger aditivo + semilla idempotente + `uninstall.php`

**Objetivo**: que las 8 opciones nuevas se siembren sin pisar valores elegidos, que el ledger de
fallo sea aditivo (sin migración) y que el desinstalador limpie todo lo nuevo.

**Descripción técnica**: las 4 opciones de negocio (`stock_owner`, `invoice_retry_enabled`,
`invoice_retry_max_attempts`, `invoice_retry_batch`) van a `$defaults` (`alegra-connector.php:414-468`);
las 8 van a `$non_autoload` (`:474-507`) y a `uninstall.php` (`:50-134`). El
loop `:509-513` no pisa
valores (`:510`), y para instalaciones ya activas que no re-activan se usa `Write_Gate::maybe_migrate()`
(`:182`) con el mismo patrón idempotente (guard de versión). El ledger es **aditivo**: un pedido sin meta
no aparece como fallo; los post metas nuevos (`_alegra_invoice_sync_state`, `_alegra_stock_adjusted_at`,
etc.) empiezan con `_alegra_` y ya los barre `uninstall.php:221-225` (C5). Cubre NFR-03.

**Desarrollo técnico**:

1. **Opciones nuevas (8)** — `design.md:1200-1209`:

| Opción | Default | `$defaults` | `$non_autoload` | `uninstall.php` |
|---|---|---|---|---|
| `alegra_connector_stock_owner` | `auto` | sí | sí | sí |
| `alegra_connector_invoice_retry_enabled` | `false` | sí | sí | sí |
| `alegra_connector_invoice_retry_max_attempts` | `5` | sí | sí | sí |
| `alegra_connector_invoice_retry_batch` | `20` | sí | sí | sí |
| `alegra_connector_invoice_failures_count` | `0` | no | sí | sí |
| `alegra_connector_invoice_failures_hash` | `''` | no | sí | sí |
| `alegra_connector_stock_divergence` | `[]` | no | sí | sí |
| `alegra_connector_stock_owner_epoch` | `0` | no | sí | sí |

   > **C5/C29 — el filtro G3 NO es una opción.** El filtro por `reference` se fija con la constante de
   > build `Inventory_Pusher::USE_REFERENCE_FILTER` (`design.md:56`, `:657-658`, `:1132`; `fase-3:630`),
   > **no** con `get_option('alegra_connector_inventory_reference_filter')`. No se siembra, no va a
   > `$defaults`/`$non_autoload`/`uninstall.php`. El conteo queda en **8**.

2. **`uninstall.php`** — agregar las 8 al bloque
   `alegra_connector_uninstall_options()` (junto a `:82`/`:85`), con `delete_option()` explícito (el
   archivo es literal a propósito, `:44-47`). **No**
   hace falta agregar post metas: el barrido `_alegra_%` de `:221-225` ya cubre el ledger y
   `_alegra_stock_adjusted_at`.
3. **`maybe_migrate`** — agregar (o reusar) un guard que siembre las 4 de negocio en
   instalaciones activas; **no** reusar el guard `alegra_connector_gate_migration_version` para no tocar
   el existente (`Write_Gate.php:184-186`); usar un guard propio o el loop de `$defaults` en `activate()`.
4. **Ledger aditivo**: `Invoice_Failure::get()` devuelve `idle`/vacío si no hay meta; la cola no lista
   pedidos sin ledger salvo como "nunca intentado" (estado no almacenado, `design.md:687-688`).
5. **Registro del self-heal del cron de reintento (cierre del gap #8 de `REVIEW-momus.md`).** `T4.8`
   define `maybe_self_heal_invoice_retry()` (`fase-4:643-654`) pero **no** fija su `add_action('init', …)`:
   si sólo se llama en `activate()` (`fase-4:655`), activar el opt-in **después** de activar el plugin
   deja el cron sin agendar (REQ-QUEUE-06). El molde real es `maybe_self_heal_payment_reconcile`,
   enganchado en `init_hooks()` a prioridad 21 (`alegra-connector.php:196`). El método lo **posee**
   `T4.8`; acá se fija el enganche, junto a ése:

   ```php
   // T4.8: self-heal del cron de reintento de facturas (opt-in). Prioridad 22,
   // después del de pagos; idempotente vía wp_next_scheduled().
   add_action('init', [$this, 'maybe_self_heal_invoice_retry'], 22);
   ```

   Y sumar `'alegra_connector_invoice_retry'` al barrido de cron de `uninstall.php:231` (hoy
   `['alegra_connector_cron_sync', 'alegra_connector_daily_maintenance', 'alegra_connector_payment_reconcile']`)
   para que el desinstalador no deje el evento colgado.

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Sembrar sin pisar + ledger aditivo (ELEGIDA)** | Sin migración de datos; seguro en producción. |
| B — Escribir el meta en todos los pedidos al activar | **Rechazada:** NFR-03 prohíbe migración masiva. |
| C — Poner las 8 en `$defaults` | **Rechazada:** las internas no se editan desde la UI; `$non_autoload` alcanza. |

**Resultado esperado**: un pedido sin meta no aparece como fallo; las 8 opciones quedan sembradas sin
sobrescribir valores; `uninstall.php` borra las 8; el ledger no requiere migración.

**Dependencias**: `T1.5` (siembra), `T1.6` (`Invoice_Failure`), `T4.2` (ledger CRUD).

**Trazabilidad**: NFR-03; `design.md` §7.1 y §10 (`:1014-1027`, `:1200-1215`); `spec.md:1129-1158`.

**Verificación**: test `T30.72` (ledger aditivo). **Prove-it-catch:** quitar una opción de
`uninstall.php` o pisar el valor en el loop ⇒ `T30.72`/`T30.15` fallan.

**Riesgo**: que `maybe_migrate` re-siembre y pise una elección. Se mitiga con el guard de versión y el
`get_option(...) === false`.

**Estimación**: S (2 h).

---

### T7.3 — CHANGELOG: cambios intencionales

**Objetivo**: documentar en `CHANGELOG.md` todos los cambios de comportamiento **intencionales** de
2.7.0, para que el comerciante sepa qué cambió y qué no.

**Descripción técnica**: se agrega `## [2.7.0]` arriba del `## [2.6.0] - 2026-09-25` (`CHANGELOG.md:5`),
con el formato existente. Debe mencionar, como mínimo: (1) el reporte "Ventas sin factura" **siempre
visible**; (2) la semántica `draft`/`void`/meta vacío; (3) la coerción de `open_invoice_on_paid`; (4) la
decisión D1 vs `docs/sdd/inventory/DD-8`; (5) el baseline de factura (`synced = WC qty`); (6) la
reparación del doble decremento existente. Cubre NFR-03.

**Desarrollo técnico** — sección nueva (esqueleto del plan):

```markdown
## [2.7.0] - 2026-XX-XX

> **Dueño único del stock, poll honesto, cola de facturas y reconciliación.** El titular es el
> **doble descuento**: con los defaults de 2.6.0 cada venta emitía un ajuste y la factura manual
> volvía a descontar. Ahora el dueño del stock es explícito (`Automático` / `Factura` / `Ajuste`).
> Verificación: `docs/RELEASE_2.7.0_VERIFICATION.md`.

### Changed
- **El dueño del stock es explícito** (`alegra_connector_stock_owner`). `auto` (default) = lógica exacta
  de 2.6.0 ⇒ cero cambio para quien no toca nada.
- **El reporte "Ventas sin factura" se muestra SIEMPRE** (antes se ocultaba con `push_orders_enabled=true`)
  y detecta facturas `draft`/`void`/meta vacío. **Cambio intencional.**
- **`open_invoice_on_paid` se coerciona** según el dueño (ON con `invoice`, OFF con `adjustment`).
- **El poll ya no re-infla ni pisa un cambio local pendiente** (máquina de estados + baseline).

### Added
- Pantalla **"Facturas por subir"** con badge, aviso dismissible y reintento single/bulk.
- **Reconciliación WC↔Alegra** con causa + reparación explícita (nunca los dos mecanismos).
- Detección y reparación asistida del **doble decremento existente**.

### Notes
- Sin cambio de esquema. Opciones nuevas con defaults seguros; **sin migración**.
- **D1 vs `docs/sdd/inventory/DD-8`:** se mantiene la intención de DD-8 (evitar doble conteo) vía el
  dueño único; el baseline de factura fija `synced = WC qty` al abrir la factura.
```

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Sección `## [2.7.0]` con los cambios intencionales (ELEGIDA)** | Trazabilidad para el comerciante y para auditoría. |
| B — Sólo "Added/Changed" sin las notas intencionales | **Rechazada:** NFR-03 exige documentar los cambios de comportamiento. |

**Resultado esperado**: `CHANGELOG.md` contiene `## [2.7.0]`, `inventory-adjustments`, `DD-8`,
"Ventas sin factura" y `draft`/`void`.

**Dependencias**: `T6.7` (reporte siempre visible), `T2.3`/`T2.8` (coerción), `T7.4` (reparación).

**Trazabilidad**: NFR-03; `design.md` §7.4 (`:1047-1051`); `spec.md:1136-1137`.

**Verificación**: `T30.73` (source-scan del CHANGELOG). **Prove-it-catch:** borrar la sección ⇒ `T30.73`
falla.

**Riesgo**: que el CHANGELOG quede desactualizado respecto del comportamiento real. Se escribe **después**
de `T7.4`/`T7.5`.

**Estimación**: S (1 h).

---

### T7.4 — Detección + reparación del doble decremento existente — **BLOQUEADO(Fase 0.6 / G5)**

**Objetivo**: detectar los pedidos ya corruptos (venta + ajuste + factura) y reparar el stock de Alegra
con un ajuste compensatorio `in`, de forma **read-only primero** y **explícita después**.

**Descripción técnica**: el plugin de 2.6.0 **no** asocia ajuste↔pedido (F1 ausente,
`ANALYSIS-double-discount.md:159-172`), así que la detección es por **logs**
(`Inventory adjustment` / `Inventory adjustment failed`, `Inventory_Pusher.php:259`) **+**
`GET /inventory-adjustments?item_id={id}` (G5). Por producto, `qty_doble = Σ(cantidad ajustada de
pedidos facturados)`; Alegra está `qty_doble` por debajo. La reparación emite **un** `POST
/inventory-adjustments {type:'in', quantity: qty_doble}` compensatorio **después** de cambiar el dueño a
`invoice` (para que el ajuste compensatorio no choque con una factura) y **luego** re-baselina
(`S = availableQuantity`). **Nunca automática.** Cubre REQ-RECON-03. **BLOQUEADO(Fase 0.6 / G5):** si el
endpoint no permite enumerar ajustes por ítem, la reparación es manual pura y la UI guía.

**Desarrollo técnico — detección (read-only) + reparación explícita:**

1. **Checks live (read-only, `ANALYSIS-double-discount.md` §6, `:384-417`)** — se corren con `wp-cli`
   antes de tocar nada:

```bash
# C1 — dueño efectivo (2.6.0: adjustment)
wp option get alegra_connector_push_orders_enabled --allow-root   # 0/false
wp option get alegra_connector_push_inventory_enabled --allow-root # 1/true
wp option get alegra_connector_invoice_status --allow-root         # draft
wp eval 'echo \Alegra\Connector\Sync\Inventory_Pusher::owner();' --allow-root  # adjustment

# C2 — el plugin emitió ajustes por ventas (log de disco)
grep -E 'Inventory adjustment' wp-content/uploads/alegra-logs/alegra-sync-*.log

# C3 — ajustes en Alegra por ítem (G5)
curl -s -u "$ALEGRA_EMAIL:$ALEGRA_TOKEN" \
  "https://api.alegra.com/api/v1/inventory-adjustments?item_id=$ITEM_ID&limit=30&order_field=date&order_direction=DESC"

# C4 — medir el doble descuento (decisivo)
curl -s -u "$ALEGRA_EMAIL:$ALEGRA_TOKEN" "https://api.alegra.com/api/v1/items/$ITEM_ID" \
  | python3 -c 'import sys,json; d=json.load(sys.stdin); print(d["inventory"]["availableQuantity"])'
wp post meta get <PRODUCT_ID> _stock --allow-root
wp post meta get <PRODUCT_ID> _alegra_stock_synced --allow-root
wp post meta get <PRODUCT_ID> _alegra_stock_push_pending --allow-root
```

   **Interpretación (C4):** si `availableQuantity ≈ stock_inicial − Σ(cantidad facturada) −
   Σ(cantidad ajustada)`, hay doble descuento; si `_alegra_stock_synced != availableQuantity`, el ledger
   quedó desincronizado por el doble descuento.

2. **Detección en la UI (read-only):** `Stock_Divergence` (o un informe nuevo) lista los productos con
   `qty_doble > 0`, mostrando `qty_doble`, los pedidos candidatos (`_alegra_invoice_id` + ajuste
   emitido) y el `availableQuantity` medido. **No** emite nada.
3. **Cuantificación:** por producto, `qty_doble = Σ(cantidad ajustada de pedidos facturados)`. El
   informe explica que Alegra está `qty_doble` por debajo.
4. **Reparación explícita** (`ajax_repair_stock_divergence`, `T5.4`) — parámetros
   `product_id`, `mechanism ∈ {invoice, adjustment}`, nonce, `manage_woocommerce`; bajo
   `Write_Gate::run_explicit()`. Orden **obligatorio**: (a) cambiar el dueño a `invoice`; (b) emitir
   **un** `in qty_doble` por producto; (c) re-baselinar `S = availableQuantity`; (d) nota + log con el
   delta y el mecanismo. **Nunca los dos mecanismos.**

   **El paso (a) es la SECUENCIA CANÓNICA (cierre de C8), idéntica a la de `T5.4`.** Un
   `update_option('alegra_connector_stock_owner', 'invoice')` crudo **saltea**
   `Admin_Dashboard::sanitize_stock_owner()` (`T2.8`), que aplica la **allowlist** y el **epoch**
   (y borra los conteos cacheados). Además hay que **persistir** el valor: `sanitize_stock_owner()`
   sólo lo **devuelve** (es un `sanitize_callback`, no escribe la option). Y hay que **coercer**
   `push_orders_enabled` y `open_invoice_on_paid` con sus propios sanitizers (T2.8/Oracle#9); si no,
   el comercio queda en `invoice` con `open_invoice_on_paid=false` ⇒ estado "ningún mecanismo mueve
   stock" (B3/DEF-8). El paso (a) exacto (copiar **literal** en `T5.4`):

   ```php
   // (a) SECUENCIA CANÓNICA compartida con T5.4 (idéntica). Dueño → `invoice` POR
   //     EL SANITIZADOR (T2.8): allowlist + epoch + reset de cachés. El sanitizador
   //     sólo DEVUELVE el valor ⇒ hay que persistirlo. Luego se coercen las dos
   //     opciones que lee owner() por sus sanitizers (T2.8/Oracle#9).
   update_option(
       'alegra_connector_stock_owner',
       \Alegra\Connector\Admin\Admin_Dashboard::sanitize_stock_owner('invoice')
   );
   update_option(
       'alegra_connector_push_orders_enabled',
       \Alegra\Connector\Admin\Admin_Dashboard::sanitize_push_orders_enabled(true)
   );
   update_option(
       'alegra_connector_open_invoice_on_paid',
       \Alegra\Connector\Admin\Admin_Dashboard::sanitize_open_invoice_on_paid(true)
   );
   ```

   > **Coordinación con `T5.4` (B4 — ya resuelta).** La reparación compensatoria la ejecuta
   > `ajax_repair_stock_divergence` (`T5.4`) con `mechanism=adjustment&repair_mode=legacy_compensation`:
   > es **owner-independiente** y corre **antes** del guard `owner !== 'adjustment'` (porque el paso (a)
   > deja `owner=invoice`), bajo `run_explicit`, con **un solo** mecanismo. La secuencia (a)→(b)→(c)→(d)
   > reusa `build_adjustment_payload()`/`mark_adjusted()`; el delta sale del informe (`qty_doble`), nunca
   > de `synced`. El paso (a) es la secuencia canónica de arriba (idéntica en `T5.4`).
   >
   > **Dueño (REQ-RECON-03).** `T5.4` exige el dueño en cada rama: `invoice` sólo con `owner=invoice`,
   > `adjustment` (modo `divergence`) sólo con `owner=adjustment`; `legacy_compensation` es la excepción
   > owner-independiente. El helper `Orders::find_paid_order_without_open_invoice()` (coverage gap #6)
   > quedó definido en `T5.4`.
5. **Advertencia** (`ANALYSIS-double-discount.md:373-376`): re-baselinar `S` a un valor **mayor** que WC
   hará que el poll quiera empujar un `out`. Si el comerciante quiere conservar el stock físico de WC,
   debe corregir **Alegra**, no WC, o desactivar `push_inventory_enabled` temporalmente. El informe lo
   dice textual.

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Detección read-only + reparación explícita, un solo mecanismo (ELEGIDA)** | Cumple REQ-RECON-03 y REQ-INV-08; auditable. |
| B — Reparación automática al detectar | **Rechazada:** emite ajustes sin supervisión; riesgo de doble corrección. |
| C — Corregir WC en vez de Alegra | **Rechazada:** el stock físico de WC es la verdad del comerciante; se corrige Alegra. |
| D — Reparar antes de cambiar el dueño | **Rechazada:** el ajuste compensatorio chocaría con la factura. |

**Resultado esperado**: el informe detecta y cuantifica el doble descuento existente sin escribir; la
reparación es explícita, emite **un** ajuste compensatorio `in` por producto, re-baselina y deja rastro;
el informe advierte el riesgo de re-baselinar a un valor mayor que WC.

**Dependencias**: **G5** (`T0.6`, enumerabilidad de `GET /inventory-adjustments?item_id=`), `T5.4`
(`ajax_repair_stock_divergence`), `T7.1` (cambio de dueño), `T5.1`/`T5.2` (`Stock_Divergence`).

**Trazabilidad**: REQ-RECON-03; `design.md` §5.4/§7.3 (`:922-945`, `:1041-1045`); `ANALYSIS-double-discount.md`
§5.2 (`:357-376`), §6 (`:384-417`).

**Verificación**: test `T30.74` (detección/reparación del doble decremento existente). Manual: correr
C1–C4 en la cuenta viva y luego reparar en una réplica. **Prove-it-catch:** quitar el orden
"cambiar dueño → compensar → re-baselinar" ⇒ `T30.74` falla (el test asserta un solo mecanismo).

**Riesgo**: que G5 no permita enumerar ajustes por ítem (Rama B) ⇒ reparación manual guiada. Se mitiga
con la UI que lista `qty_doble` y explica el paso a paso.

**Estimación**: L (6 h).

---

### T7.5 — Regresión NFR-01/02 (factura/pago/webhook/import)

**Objetivo**: probar que nada de lo anterior se rompió: la matriz de riesgos R1–R20 con su guarda y los
end-to-end de factura/pago/webhook/import.

**Descripción técnica**: `tasks.md` §2.2 es la matriz **canónica** R1–R20 → test. Esta tarea la
materializa y agrega los end-to-end que faltan (`T30.77`). No implementa lógica: mapea, corre y
documenta. Cubre NFR-01/02 y REQ-POLL-05.

**Desarrollo técnico — matriz R1–R20 → test + guarda (canónica):**

| R | Riesgo | Test | Fase | Guarda (tarea) |
|---|---|---|---|---|
| R1 | `auto` mal derivado reintroduce B3 | `T30.21` | 2 | `T2.1` |
| R2 | El poll re-infla tras una venta sin baseline | `T30.32` | 3 | `T3.2` |
| R3 | El poll pisa WC en `invoice` | `T30.33`, `T30.34` | 3 | `T3.1` |
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

> **Corrección R6 (gap #9 de `REVIEW-momus.md`).** R6 se mapeaba a `T30.28`, que verifica
> `_alegra_stock_adjusted_at` (ese es el test de **R20**, no de R6). El escenario real de R6 —factura
> abierta **fuera** de WC— es **B2**, y su guarda es la convergencia del poll (`T3.1` + baseline de
> factura `T3.3`), cubierta por el `T30.37` **extendido** (factura abierta fuera del plugin + cambio
> posterior de Alegra que **debe** bajar a WC; sin esto el poll queda congelado y/o reporta divergencia
> falsa). **`tasks.md` §5.6 (`:357`) tiene el mismo mapeo viejo y debe alinearse** (no es de esta fase).

**End-to-end adicionales (`T30.77`):**

- **Factura normal intacta:** un pedido pagado con billing normal y `owner=invoice` factura con el mismo
  payload/flujo de HEAD; sin requests nuevos por la cola ni la reconciliación.
- **Webhook intacto:** un `new-item` válido se importa como hoy (conteo + `_alegra_item_id`).
- **Pago intacto:** un pedido con factura vinculada registra el pago como hoy.
- **`inventory_source=woocommerce` sigue sin escribir stock:** el poll devuelve `skipped=true` y no toca
  `_stock`.
- **`preserve=inventory` (gap #3 de `REVIEW-momus.md`, Fase 3 — NO es de esta fase):** el poll fija
  `set_synced($a)` en `NO_BASELINE_EQ` (`fase-3:293-295`) sin mirar `resolve_preserve_fields()`, cuando
  `design.md:549-564` exige baseline **vacío** con `preserve=inventory`. Ese baseline mentiroso haría
  divergir para siempre al producto (y lo listaría en el informe con `divergencia_dueno`). **Marcado para
  el dueño de `fase-3`**; el test de regresión de esta fase (`T30.77`) debe cubrir el caso.

> **Bloqueantes de `T5.4`/`T7.4` cerrados (B5, gap #5, gap #6, C8).** En `T5.4` la rama `invoice` ahora
> exige `owner()==='invoice'` (REQ-RECON-03), el helper `Orders::find_paid_order_without_open_invoice()`
> está definido y el paso (a) de `legacy_compensation` usa la secuencia canónica persistida + coercionada
> (idéntica acá). `divergence_cause()` quedó en 2 args y las causas por pedido se resuelven en `report()`.

**Conteo objetivo**: baseline **1990 / 0** (`RELEASE_2.6.0_VERIFICATION.md:71`) → objetivo
**≥ 1990 + nuevos**. Los tests nuevos son **`T30.*`** (60 IDs canónicos, `tasks.md` §5.3); con un piso
conservador de ≈2 aserciones por test, el piso es **≥ 2110**, pero **manda el conteo real** del runner
(`EXEC-TEST OK: N assertions passed, 0 failed`). La base **1990/0 no puede bajar**.

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Matriz canónica R1–R20 + end-to-end (ELEGIDA)** | Cada riesgo tiene test y guarda; el "no revert" natural de R18 es la base verde. |
| B — Sólo correr la suite sin matriz | **Rechazada:** no mapea riesgo → test; no se puede auditar la cobertura. |

**Resultado esperado**: `EXEC-TEST OK` con `0 failed`; la matriz R1–R20 completa con test y guarda;
los 4 end-to-end verdes.

**Dependencias**: Fases 1–6 completas.

**Trazabilidad**: NFR-01/02, REQ-POLL-05; `tasks.md` §2.2 (R1–R20), §8; `spec.md:1062-1125`.

**Verificación**: `bash scripts/exec-test.sh | tail -3` → `EXEC-TEST OK: <N> assertions passed, 0 failed`.
**Prove-it-catch:** R18 no tiene reversión natural (guard = base + nuevos verdes); el resto hereda su
reversión de la fase que lo implementa.

**Riesgo**: un mock laxo da falso verde. Se mitiga con los prove-it-catches de cada fase (harness H-A/H-B/
H-C/H-E en Fase 1).

**Estimación**: M (4 h).

---

### T7.6 — ZIP sin `scripts/` + smoke + release 2.7.0

**Objetivo**: construir, commitear y publicar el release **2.7.0** con la convención del repo: 3 fuentes
de versión consistentes, ZIP + `.sha256` commiteados, `make_latest: "true"`, `.pot` regenerado y
`scripts/` fuera del ZIP.

**Descripción técnica**: `build-release.sh` exige `header == README == make-pot.php == argumento`
(`:46-63`, exit 9). `ALEGRA_CONNECTOR_VERSION` se **deriva** del header (`alegra-connector.php:59`) ⇒ no
hay constante que tocar. El `.pot` se regenera y commitea **antes** del build (C6: el script lo regenera
en `:134-151`, después del chequeo de árbol limpio `:87-94`). El build es determinista (`:234-241`),
escribe a `releases/` (`:156-164`) y el `.sha256` (`:245-246`). El tag se pushea (`:269-302`) y CI
verifica (`release.yml:43-61`), corre gates (`:63-66`) y publica con `make_latest: "true"` (`:74`). El
ZIP **no** incluye `scripts/` (`.distignore:15`). Cubre NFR-05.

**Desarrollo técnico**:

1. **Bump a 2.7.0 en las 3 fuentes**:
   - `alegra-connector.php:6` → `Version: 2.7.0`.
   - `README.md:9` → `Version: 2.7.0 | PHP 8.0+ | WP 5.8+ | WC 6.0+`.
   - `scripts/make-pot.php:22` → `$version = '2.7.0';`.
2. **`.pot`**: `php scripts/make-pot.php` y commitear `languages/alegra-connector.pot` **antes** del build
   (no se editan traducciones; sólo se regenera el template).
3. **Re-verificar el baseline antes del release (obligatorio).** Correr el harness sobre el árbol a
   releasear y confirmar `0 failed` y que el conteo **no bajó** de **1990**:

   ```bash
   bash scripts/exec-test.sh | tail -3
   # => EXEC-TEST OK: <N> assertions passed, 0 failed     (N ≥ 1990)
   ```

   Si hay algún `failed` o el conteo cae por debajo de **1990**, **abortar el release**. Es el mismo
   gate que `build-release.sh` corre en `:115-129` (pre-release); correrlo acá deja el conteo explícito
   **antes** de tocar la versión. El objetivo post-cambio es **≥ 1990 + nuevos** (`T7.5`).
4. **`uninstall.php`**: verificar las 8 opciones de `T7.2` — el build no valida esto; el test
   `T30.75`/`T30.15` sí.
5. **Build local**:
   ```bash
   bash scripts/build-release.sh 2.7.0
   ```
   Salida esperada: `Version consistency OK: header == README == make-pot.php == 2.7.0`, `SMOKE OK`,
   `EXEC-TEST OK`, `Wrote releases/alegra-connector-v2.7.0.zip` + `.sha256`.
6. **Commit de artefactos**:
   ```bash
   git add releases/alegra-connector-v2.7.0.zip releases/alegra-connector-v2.7.0.zip.sha256
   git commit -m "chore(release): add the 2.7.0 build artifacts"
   ```
7. **Tag + push** (dispara el workflow):
   ```bash
   git tag -a v2.7.0 -m "Alegra Connector 2.7.0"
   git push origin main && git push origin v2.7.0
   ```

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Versión 2.7.0 (minor) (ELEGIDA)** | Opciones nuevas + cambios de comportamiento ⇒ minor según la convención del repo. |
| B — 2.6.1 (patch) | **Rechazada:** agrega opciones y cambia comportamiento; no es patch. |
| C — 3.0.0 (major) | **Rechazada:** no hay ruptura de contrato para `auto`. |

**Resultado esperado**: `releases/alegra-connector-v2.7.0.zip` + `.sha256` commiteados; ZIP sin
`scripts/`/`docs/`; `.pot` regenerado; release `v2.7.0` publicado como latest con el asset
byte-idéntico.

**Dependencias**: Fases 1–6, `T7.2` (opciones), `T7.3` (CHANGELOG), `T7.5` (gates verdes).

**Trazabilidad**: NFR-05; política de artefactos de `RELEASE_2.6.0_VERIFICATION.md:24-47`;
`design.md` §11 (`:1177-1199`).

**Verificación**:
```bash
# Baseline re-verificado ANTES del release (paso 3): 0 failed y N ≥ 1990.
bash scripts/exec-test.sh | tail -3
( cd releases && sha256sum -c alegra-connector-v2.7.0.zip.sha256 )
unzip -l releases/alegra-connector-v2.7.0.zip | grep -E 'scripts/|docs/' || echo "ZIP limpio"
```
   Criterio: `EXEC-TEST OK: <N> assertions passed, 0 failed` con **N ≥ 1990**; `OK` del `sha256sum -c`
   y sin `scripts/`/`docs/` en el ZIP. Test `T30.75` (ZIP sin
   `scripts/`). **Prove-it-catch:** quitar `scripts/` de `.distignore` ⇒ `T30.75` falla.

**Riesgo**: árbol sucio al construir (exit 4) ⇒ commitear el bump antes; `.pot` regenerado que ensucia
el árbol ⇒ commitearlo antes del build (C6). Que CI recompile (prohibido) ⇒ el workflow sólo verifica y
publica.

**Estimación**: M (3 h).

---

### T7.7 — Seguridad: nonce/cap en AJAX nuevos + sin token en logs

**Objetivo**: que cada AJAX nuevo exija nonce y capacidad y corra bajo la compuerta, y que los logs
nunca expongan el token.

**Descripción técnica**: los AJAX nuevos (`ajax_retry_invoice`, `ajax_ignore_invoice`,
`ajax_repair_stock_divergence`) deben usar el patrón existente: wrapper `run_explicit` (patrón
`Admin_Dashboard.php:3166-3169`) + `check_ajax_referer('alegra_connector_nonce')` +
`current_user_can('manage_woocommerce')` (patrones `:2847-2848`, `:2904-2906`, `:3173-3177`,
`:3869-3870`, `:3910-3911`) + `Write_Gate::run_explicit()` (`Write_Gate.php:156`). En logs, el único log
de request es `API Request` (`API/Client.php:171-174`) y loguea **sólo** `method` + `endpoint`, nunca
`headers` (donde va el `Authorization`, `:152`/`:937`). Los logs nuevos pasan sólo ids/delta/reason.
Cubre NFR-06.

**Desarrollo técnico — patrón obligatorio por AJAX nuevo:**

```php
public function ajax_retry_invoice(): void
{
    \Alegra\Connector\Write_Gate::run_explicit(fn () => $this->ajax_retry_invoice_impl());
}

private function ajax_retry_invoice_impl(): void
{
    check_ajax_referer('alegra_connector_nonce');                       // sin nonce ⇒ muere
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error(['message' => __('No tenés permisos.', 'alegra-connector')]);
    }
    // ...
}
```

**Chequeo de logs (grep):**

```bash
# Ningún log debe contener el token ni el header Authorization
grep -rnE "Authorization|alegra_connector_token" includes/ admin/ public/ logger/ \
  | grep -vE "get_option\('alegra_connector_token'|delete_option|register_setting|sanitize_masked_secret"
```

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — `run_explicit` + nonce + cap en cada AJAX (ELEGIDA)** | Consistente con el resto del plugin; la compuerta sigue siendo autoridad. |
| B — Confiar en el `is-dismissible`/UI | **Rechazada:** un AJAX se llama directo. |

**Resultado esperado**: sin nonce/permiso, cada AJAX nuevo responde error y no toca la API; el kill
switch bloquea todo POST; los logs no filtran el token.

**Dependencias**: `T6.4` (retry single), `T6.5` (bulk), `T5.4` (reparación).

**Trazabilidad**: NFR-06; `design.md` §4.5/§5.3 (`:767-790`, `:906-920`); `spec.md:1210-1234`.

**Verificación**: `T30.76` (nonce/cap + sin token en logs). **Prove-it-catch:** quitar
`check_ajax_referer` de un AJAX nuevo ⇒ `T30.76` falla.

**Riesgo**: que el log de `API Request` incluya `$args`/headers en un refactor futuro. Se mitiga con el
test source-scan de `T30.76`.

**Estimación**: S (2 h).

---

### T7.8 — Aceptación manual WP-admin + doc de release

**Objetivo**: validar en el WP admin real los 4 problemas del comerciante, dejar el mapa de
prove-it-catches de las 5 tareas centrales y el rollback en `docs/RELEASE_2.7.0_VERIFICATION.md`.

**Descripción técnica**: la convención del repo es `docs/RELEASE_<X.Y.Z>_VERIFICATION.md` (existen
`RELEASE_2.4.2`, `RELEASE_2.5.0` y `RELEASE_2.6.0`). El doc sigue el patrón de
`docs/RELEASE_2.6.0_VERIFICATION.md` (política de artefactos `:24-47`, "Lo nuevo" `:51-63`, regresión del
harness `:67-74`, prove-it-catches `:78-...`). Cubre NFR-01. Los **4 problemas** son los de `tasks.md`
§11; las **5 tareas centrales** son `T2.1`, `T3.1`, `T3.2`, `T4.3`, `T5.4`.

**Desarrollo técnico — estructura del doc:**

1. **Encabezado + política de artefactos** (copiar de `RELEASE_2.6.0_VERIFICATION.md:24-47`).
2. **★★★ Lo nuevo de la 2.7.0 — los 4 problemas** (pasos exactos, con "Resultado esperado" y riesgo):

| # | Problema reportado | Pasos (WP admin) | Resultado esperado | Riesgo |
|---|---|---|---|---|
| 1 | **"Vendo 3 y Alegra descuenta 6"** | Ajustes → "Dueño del stock" = Factura; vender 3; verificar **cero** ajustes en el log. Cambiar a Ajuste; vender; abrir la factura a mano → aparece el diálogo y pide `confirm_double_discount`. | En `invoice`, una venta descuenta **una vez** y **cero** ajustes; en `adjustment`, la apertura manual exige confirmación y deja nota. | Crítico |
| 2 | **"El stock vuelve a subir solo"** | Producto con stock 10 en WC y Alegra; vender 3; correr "Sincronizar inventario". | WC **no** sube (queda en 7 o baja); el log muestra el ajuste, no una re-inflación. | Crítico |
| 3 | **"Debería quedar como notificación… por subir"** | Forzar un fallo (p. ej. kill switch o cliente sin resolver); entrar al admin; abrir "Facturas por subir"; reintentar. | La cola lista el pedido con motivo/código/intentos; badge = conteo; aviso dismissible; "Reintentar" sube y **no** duplica. | Alto |
| 4 | **"No puedo ver ni reparar la divergencia"** | Abrir el informe de divergencia; reparar un producto explícitamente. | El informe lista el producto con **causa**; "Reparar" pide confirmación y emite **un** mecanismo (nunca ambos); deja nota+log. | Alto |

3. **Matriz de prove-it-catches** (test → fix revertido → rojo):

| Test | Tarea central | Fix que protege | Reversión que lo pone en rojo |
|---|---|---|---|
| `T30.21` | `T2.1` | `auto` = condición doble de 2.6.0 | Simplificar a `push_orders_enabled` solo (C1) ⇒ `T30.21` falla |
| `T30.32` | `T3.2` | **El titular:** vender 3 → poll → WC no sube | Escribir WC incondicional en el poll ⇒ WC vuelve a 10 |
| `T30.33` | `T3.1` | El poll no pisa WC en `invoice` | Meter `invoice_owner` en `$handled` sin baseline ⇒ `T30.33`/`T30.37` |
| `T30.46` | `T4.3` | Re-búsqueda post-error adopta la factura | Quitar `find_existing_invoice` antes de persistir ⇒ duplica |
| `T30.54` | `T5.4` | La reparación emite **un** mecanismo | Permitir ambos ⇒ `T30.54` falla |

4. **Riesgos de upgrade**: `stock_owner=auto` conserva 2.6.0; reporte "Ventas sin factura" siempre
   visible + `draft`/`void`; coerción de `open_invoice_on_paid`; D1 vs DD-8; sin migración; opciones
   nuevas.
5. **Rollback**: reinstalar 2.6.0 (sin cambio de esquema); el ledger nuevo es aditivo y no rompe 2.6.0.
6. **Referenciar** el doc desde `CHANGELOG.md` (nota de release). **No** agregar el doc al ZIP: `docs/`
   está en `.distignore` (`:32`, C9).

**Opciones evaluadas**:

| Opción | Veredicto |
|---|---|
| **A — Doc `RELEASE_2.7.0_VERIFICATION.md` con 4 problemas + prove-it-catches + rollback (ELEGIDA)** | Convención del repo; trazabilidad humana. |
| B — Sólo el CHANGELOG | **Rechazada:** la aceptación manual y el rollback necesitan su propio doc. |

**Resultado esperado**: el doc existe, mapea cada problema a un resultado verificable, lista el mapa de
prove-it-catches de las 5 centrales y el rollback.

**Dependencias**: `T7.4` (resultados reales de reparación), `T7.5` (matriz), `T7.6` (release).

**Trazabilidad**: NFR-01; `tasks.md` §11 (4 problemas); `RELEASE_2.6.0_VERIFICATION.md` (patrón).

**Verificación**: el doc existe y contiene las secciones de los 4 problemas + prove-it-catches +
rollback. **Prove-it-catch:** no aplica (doc de aceptación); el "guard" es la firma manual.

**Riesgo**: doc desactualizado respecto del comportamiento real ⇒ se escribe **después** de `T7.4`/`T7.5`.

**Estimación**: M (4 h).

---

## Criterios de aceptación (checklist Fase 7)

| # | Resultado verificable | REQ/NFR | Tareas | Tests (`T30.x`) |
|---|---|---|---|---|
| 1 | `stock_owner=auto` reproduce 2.6.0 en las 4 combinaciones; `_alegra_stock_synced` sin limpieza | NFR-03, REQ-OWN-02 | `T7.1` | `T30.71` |
| 2 | 8 opciones sembradas sin pisar; ledger aditivo; `uninstall.php` con las 8 | NFR-03 | `T7.2` | `T30.72`, `T30.15` |
| 3 | `CHANGELOG` con los cambios intencionales (`draft`/`void`, reporte siempre, coerción, D1 vs DD-8) | NFR-03 | `T7.3` | `T30.73` |
| 4 | Detección read-only + reparación explícita (un ajuste `in`, re-baseline, advertencia) | REQ-RECON-03 | `T7.4` | `T30.74` |
| 5 | Matriz R1–R20 + end-to-end verdes; `1990 + nuevos`, 0 failed | NFR-01/02 | `T7.5` | `T30.77` (+ R1–R20) |
| 6 | ZIP 2.7.0 sin `scripts/` + `.sha256`; 3 fuentes de versión; `make_latest: "true"` | NFR-05 | `T7.6` | `T30.75` |
| 7 | AJAX nuevos con nonce/cap + `run_explicit`; sin token en logs | NFR-06 | `T7.7` | `T30.76` |
| 8 | `docs/RELEASE_2.7.0_VERIFICATION.md` con 4 problemas + prove-it-catches + rollback | NFR-01 | `T7.8` | — |

---

## DoD Fase 7 (checklist de cierre)

- [ ] `T7.1`: `T30.71` verde (4 combinaciones de `auto`); `_alegra_stock_synced` sin limpieza.
- [ ] `T7.2`: 8 opciones en `$defaults`/`$non_autoload`/`uninstall.php`; el
      `maybe_self_heal_invoice_retry()` queda enganchado en `init` (prioridad 22); `T30.72` verde.
- [ ] `T7.3`: `CHANGELOG.md` con `## [2.7.0]` y los cambios intencionales; `T30.73` verde.
- [ ] `T7.4`: detección read-only (C1–C4) + reparación explícita; el paso (a) usa la **secuencia canónica**
      (idéntica a `T5.4`): `sanitize_stock_owner('invoice')` **persistido** + coerción de
      `push_orders_enabled`/`open_invoice_on_paid` por sus sanitizers (T2.8); `T30.74` verde; **G5** resuelto.
- [ ] `T7.5`: matriz R1–R20 con guarda + `T30.77`; `EXEC-TEST OK` con `1990 + nuevos`, `0 failed`.
- [ ] `T7.6`: baseline **1990 / 0** re-verificado (`bash scripts/exec-test.sh | tail -3`) **antes** del
      release; `releases/alegra-connector-v2.7.0.zip` + `.sha256` commiteados; ZIP sin `scripts/`/`docs/`;
      `.pot` regenerado y commiteado; tag `v2.7.0`; `ASSET OK` + `isLatest: true`; `make_latest: "true"`
      ya presente en `release.yml:74`.
- [ ] `T7.7`: `T30.76` verde (nonce/cap + sin token en logs).
- [ ] `T7.8`: `docs/RELEASE_2.7.0_VERIFICATION.md` con los 4 problemas + prove-it-catches + rollback.
- [ ] `bash scripts/smoke-test.sh` → `SMOKE OK` (working tree y ZIP extraído).

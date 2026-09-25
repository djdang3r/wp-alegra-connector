# Fase 9 — Regresión + release 2.6.0 + aceptación — micro-detalle

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **9** — Matriz R1–R19, suite completa, aceptación manual y release 2.6.0 |
| Versión objetivo | **2.6.0** (la siguiente a 2.5.1; cambios de comportamiento + opciones nuevas ⇒ minor) |
| Harness | `bash scripts/exec-test.sh` (baseline real **1626 assertions, 0 failed** — verificado corriendo el harness en HEAD) · `bash scripts/smoke-test.sh` |
| Tareas del esqueleto | `T9.1`–`T9.6` (expandidas a 8 micro-tareas: `T9.5` se parte en a/b/c) |
| Depende de | Fases 0–8 completas |
| DoD de la fase | matriz R1–R19 con test concreto; suite completa verde; smoke + PSR-4 de `Inventory_Writer`/`Inventory_Pusher`; aceptación manual de los 5 problemas firmada; release 2.6.0 publicado y verificado (`ASSET OK` + `isLatest: true`) |

> **Regla de oro heredada (prove-it-catches).** Cada test nuevo se valida revirtiendo el fix: se corre
> `bash scripts/exec-test.sh`, **ese** test debe fallar, se re-aplica y vuelve a verde. Las 5 tareas
> centrales (`T2.1`, `T3.4`, `T5.5`, `T7.1`, `T8.1`) tienen su reversión documentada en
> `docs/RELEASE_2.6.0_VERIFICATION.md` (T9.6).
>
> **IDs de test.** `scripts/exec-test.php`, sección `// === sync-reliability (2.6.0) ===`, IDs `T29.9x`
> para esta fase. `T9.x` son IDs de **tarea**.

---

## Correcciones de cita / hallazgos re-verificados en HEAD (Fase 9)

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | Baseline del harness | Corrido en HEAD: **`EXEC-TEST OK: 1626 assertions passed, 0 failed`**. El doc hermano asumía 1289 (base de `logs-monitor-import`). | `T9.2` (objetivo = 1626 + nuevos) |
| C2 | `scripts/build-release.sh` valida 3 fuentes de versión | Confirmado: header `:46`, README `:53`, `make-pot.php` `:59`; aborta con **exit 9** (`:50/:56/:62`). | `T9.5.a` |
| C3 | El ZIP de release se **construye y commitea localmente**; CI solo verifica y publica | Confirmado: `build-release.sh:156-164` (`releases/`), `:245-246` (`.sha256`); `.github/workflows/release.yml:43-61` verifica el artefacto commiteado y `:63-66` corre los gates. **CI no recompila**. | `T9.5.b`, `T9.5.c` |
| C4 | `make_latest: "true"` en el workflow | **Ya está** en `.github/workflows/release.yml:74` (con comillas). El doc de `logs-monitor-import` lo daba por ausente; se agregó en un release posterior. **No hay que tocarlo** en 2.6.0. | `T9.5.c` (nota) |
| C5 | `.distignore:15` excluye `scripts/` | Confirmado (`:15` `scripts/`). También excluye `docs/`, `CHANGELOG.md`, `README.md`, `releases/`, `*.zip`, `*.sha256`, `.github/`, `.omo/`. | `T9.3`, `T9.5.b` |
| C6 | `build-release.sh` regenera el `.pot` **después** del chequeo de árbol limpio | Confirmado: chequeo de árbol limpio `:87-94`; `php scripts/make-pot.php` en `:140`. ⇒ hay que regenerar y commitear el `.pot` **antes** del build. | `T9.5.a`, `T9.5.b` |
| C7 | 3 fuentes de versión | `alegra-connector.php:6` (`Version: 2.5.1`), `README.md:9` (`Version: 2.5.1`), `scripts/make-pot.php:22` (`$version = '2.5.1'`). `ALEGRA_CONNECTOR_VERSION` se **deriva** del header (`alegra-connector.php:59`), no hay constante que tocar. | `T9.5.a` |
| C8 | `smoke-load.php` asserta PSR-4 de una clase nueva | Patrón en `scripts/smoke-load.php:196-200` (`Run_Context class loads` → `class_exists`). Falta el assert de `Inventory_Writer`/`Inventory_Pusher`. | `T9.3` |
| C9 | Baseline de `docs/RELEASE_2.5.0_VERIFICATION.md` | Ese doc registra **1592** aserciones (release 2.5.0). El HEAD actual tiene **1626** (2.5.1 agregó tests). | `T9.5.a` |
| C10 | `uninstall.php` opciones | Lista explícita `:50-134`; las 9 opciones nuevas **no** están todavía (las siembra `T1.7`). El borrado de locks por prefijo está en `:153-156`. | `T9.5.a` |

**Citas confirmadas exactas:** `scripts/exec-test.sh:26` (invoca `exec-test.php`), `:30`
(`EXEC-TEST OK`); `scripts/smoke-test.sh:70-77`; `scripts/build-release.sh:46-63` (consistencia de
versión), `:87-94` (árbol limpio), `:100-113` (smoke), `:115-129` (exec), `:131-144` (`.pot`),
`:156-164` (`releases/`), `:227-232` (Logger en staging), `:236-240` (ZIP determinista), `:245-246`
(`.sha256`), `:269-302` (tag/publish opt-in); `.github/workflows/release.yml:43-61` (verifica),
`:63-66` (gates), `:68-78` (publish), `:74` (`make_latest`); `.distignore:15`; `alegra-connector.php:6,59`;
`README.md:9`; `scripts/make-pot.php:22`; `uninstall.php:48-135,153-156,221-227`;
`scripts/smoke-load.php:196-200`.

---

## Mapa de cobertura Fase 9 → requerimiento

| Micro-tarea | Qué cubre | Archivos |
|---|---|---|
| `T9.1` | Matriz R1–R19 con test concreto | `scripts/exec-test.php` |
| `T9.2` | Suite completa verde + conteo real | `scripts/exec-test.sh`, `scripts/exec-test.php` |
| `T9.3` | Smoke + PSR-4 de las clases nuevas | `scripts/smoke-test.sh`, `scripts/smoke-load.php` |
| `T9.4` | Aceptación manual de los 5 problemas | `docs/RELEASE_2.6.0_VERIFICATION.md` (**nuevo**) |
| `T9.5.a` | CHANGELOG + versión + `uninstall.php` + `.pot` | `CHANGELOG.md`, `alegra-connector.php`, `README.md`, `scripts/make-pot.php`, `uninstall.php`, `languages/*.pot` |
| `T9.5.b` | Build determinista + ZIP + `.sha256` + commit | `releases/` |
| `T9.5.c` | Workflow + tag + verificación `ASSET OK` | `.github/workflows/release.yml` |
| `T9.6` | Prove-it-catches documentados | `docs/RELEASE_2.6.0_VERIFICATION.md` |

---

### T9.1 — Matriz de regresión R1–R19 → test concreto

**Objetivo**: convertir cada riesgo de regresión de `tasks.md §9` en una aserción verificable y con prove-it-catch.

**Descripción técnica**: `tasks.md §9` lista R1–R18 (+ **R19** de FIX-19/Oracle D10). Muchos ya tienen test de fases anteriores; esta
micro-tarea los **mapea** y agrega los end-to-end que faltan (`T29.91`–`T29.94`). Es la matriz
**autoritativa de riesgos de ejecución** (distinta de los `DR1–DR26` de diseño del `design.md §13`).
Cubre NFR-01 y el "sin regresión" de la propuesta §3.3.

**Desarrollo técnico — matriz R → test** (todos en `scripts/exec-test.php`, sección `T29`):

| R | Riesgo | Test concreto (aserción) | Tarea que lo implementa | Prove-it-catches |
|---|---|---|---|---|
| **R1** | El refactor a `Inventory_Writer` reescribe stock en el deploy | `T29.23`/`T29.24`/`T29.25`: `T29.23` fija `backorders=no` idéntico a HEAD; `T29.24` (W1) y `T29.25` (W2) delegan en el writer y producen el mismo resultado; sin migración de datos | `T2.3`, `T2.4` | Revertir W1/W2 al setter directo → `T29.24`/`T29.25` fallan |
| **R2** | El ajuste doble-descuenta si además se factura | `T29.37`/`T29.38`: `push_orders=true` ⇒ pedido pagado nace `open` y **no** se emite ajuste (`T29.37`); `push_orders=false` ⇒ sí se emite (`T29.38`) | `T3.1`, `T3.5` | Hardcodear `owner()='adjustment'` → el test del dueño `invoice` (`T29.37`) falla |
| **R3** | El poll re-infla tras un push fallido | `T29.36`: `pending!=''` ⇒ el poll reintenta el push y **no** escribe WC; `T29.34` fija el titular (vender 3 ⇒ WC no sube) | `T3.4` | Escribir WC incondicional en el poll → `T29.34`/`T29.36` fallan |
| **R4** | `_stock_status` rompe `backorders=no` | `T29.21`/`T29.23`: `backorders=yes` + qty 0 ⇒ `onbackorder` (`T29.21`); `backorders=no` + qty 0 ⇒ `outofstock` idéntico a HEAD (`T29.23`) | `T2.1` | Volver a `set_stock_quantity()+save()` sin derivar → `T29.21` falla |
| **R5** | Honrar `preserve_fields` cambia comportamiento previo | `T29.25`: con `inventory` preservado, W2 (el poll) **no** escribe; sin preservar, sí. `T29.24` cubre W1 (import) | `T2.3`, `T2.4` | Quitar la compuerta `preserve` → `T29.24`/`T29.25` fallan |
| **R6** | El self-heal duplica la factura | `T29.56`: con la factura ya creada, `find_existing_invoice` la recupera y **no** se crea una segunda | `T5.5` | Quitar la pre-búsqueda antes del reintento → `T29.56` falla |
| **R7** | El self-heal loopea entre requests | `T29.58`: si no re-resuelve, borra `_billing_alegra_contact_id` y deja nota; sin loop | `T5.6` | No borrar el meta muerto → `T29.58` falla |
| **R8** | Barrido CONTAINS incompleto ⇒ CF duplicado | `T29.51`: con `limit` lleno de falsos positivos, el barrido pagina y encuentra el CF real; tope ⇒ `unverified` (nunca `not_found`). `T29.55` cubre la re-resolución paginada del self-heal (FIX-18) | `T5.1`, `T5.5` | Quitar la paginación/`matches()` → `T29.51`/`T29.55` fallan |
| **R9** | `probe()`/render POSTea por accidente | `T29.52` + source-scan `T29.64`/`T29.640`: `probe()`/`resolve_readonly()` nunca llaman `create()`; el render no emite requests | `T5.2a`, `T6.4` | Meter un `create()` en `probe()` → `T29.52`/`T29.64` fallan |
| **R10** | `set_syncing` TTL 300 < poll largo ⇒ cascada vuelve | `T29.74`: con budget > 300 simulado, el transient `alegra_import_in_progress` se refresca por página y no hay POST por ítem | `T7.3` | Quitar el refresh por página → `T29.74` ve la cascada y falla |
| **R11** | Anidado apaga el flag externo | `T29.74` borde: con `set_syncing(true)` previo, tras el poll el transient **sigue** | `T7.3` | Quitar `$was_syncing` → `T29.74` falla |
| **R12** | El shutdown handler libera un lock ajeno | `T29.81`: token distinto ⇒ `release_lock` no libera | `T8.1` | Quitar la comparación de token en `release_lock` → `T29.81` falla |
| **R13** | Solapamiento del lock global del cron | `T29.82`/`T29.83`: `cron_run_budget` (540) < TTL 600; deadline propagado; warning al reclamar vencido | `T8.2`, `T8.3` | No propagar el deadline → `T29.82` falla |
| **R14** | `wc_update_product_stock` ausente en WC viejo | `T29.21` (rama A: API presente, `function_exists` + fallback en `apply_stock()`); la rama B (`function_exists=false` ⇒ `set_stock_quantity()+save()`) queda `BLOQUEADO(Fase 0.7 / G7)` — sin test dedicado hasta correr G7 | `T2.1` | Forzar `function_exists=false` sin fallback → la rama B falla |
| **R15** | Opciones nuevas ausentes ⇒ el gate bloquea el ajuste | `T29.18`: con `push_inventory_enabled` ausente, `block_reason('inventory')===null` (default true) | `T1.8` | Quitar `ENTITY_DEFAULTS['inventory']` → `T29.18` falla |
| **R16** | Divergencia harness↔producción (CONTAINS, endpoint) | `T29.11`–`T29.16`: H1–H8 modelan `wc_update_product_stock`, CONTAINS, `/inventory-adjustments`, `client` inexistente, `set_syncing` | `T1.1`–`T1.6` | Revertir cada seam → su test falla |
| **R17** | El cursor del poll se borra para otra entidad / sync manual | `T29.73`: clave namespaced `alegra_connector_inventory_pull_cursor`; reset solo al completar/`from_zero`/total encogido | `T7.2.b` | Quitar el gate de reset → `T29.73` falla |
| **R18** | Regresión en facturación/pagos/webhooks/DIAN | `T29.91`–`T29.94` (end-to-end, abajo) + la base 1626 verde | `T9.1`, `T5.5` | N/A (regresión de no-cambio; guard = base verde) |
| **R19** | Quitar el `_stock_status` forzado cambia el estado con `woocommerce_notify_no_stock_amount > 0` (FIX-19 / Oracle D10) | `T29.23` (Fase 2) **extendido** con `notify_no_stock_amount=2`: `qty=1` + `backorders=no` ⇒ `outofstock` (WC lo deriva en el umbral, `validate_props()`), donde HEAD forzaba `instock`. Nota de cambio intencional en `CHANGELOG` (`T9.5.a`) | `T2.1`–`T2.4`, `T9.5.a` | Volver al status viejo `qty > 0 ? 'instock' : 'outofstock'` → `T29.23` (caso umbral) falla |

**End-to-end adicionales (nuevos, `T29.91`–`T29.94`):**

- `T29.91` **Factura normal intacta**: un pedido con billing normal factura con el mismo payload/flujo
  de HEAD; no hay requests nuevos por la resolución del CF.
- `T29.92` **Webhook intacto**: un `new-item` válido se importa como hoy (conteo + `_alegra_item_id`).
- `T29.93` **Pago intacto**: un pedido con factura vinculada registra el pago como hoy.
- `T29.94` **`inventory_source=woocommerce` sigue sin escribir stock**: el poll devuelve
  `skipped=true` y no toca `_stock`.

**Resultado esperado**: 19 riesgos con test concreto + 4 end-to-end, verdes; R18 sin "revert" natural
(guard = las 1626 base + nuevos siguen verdes).

**Dependencias**: Fases 1–8; T1.11 (sección `T29`).

**Trazabilidad**: R1–R19, NFR-01, NFR-06, NFR-07.

**Verificación**: cada fila de la matriz es la verificación; el prove-it-catch de cada test, la prueba dura.

**Riesgo**: un mock demasiado laxo da falso verde → mitigado por prove-it-catches.

**Estimación**: M (2 h).

---

### T9.2 — Suite completa (conteo real + 0 failed)

**Objetivo**: correr la suite entera (base + Fases 1–9) y registrar el conteo real, con el mapa de prove-it-catches.

**Descripción técnica**: el baseline de HEAD es **1626** aserciones (C1); las Fases 1–9 agregan las
suyas. Esta micro-tarea **no** implementa lógica: ejecuta, cuenta y documenta. Cubre NFR-01.

**Desarrollo técnico**:
1. Correr:
   ```bash
   bash scripts/exec-test.sh
   bash scripts/smoke-test.sh
   ```
2. Registrar el conteo real (`EXEC-TEST OK: N assertions passed, 0 failed`). Objetivo mínimo por fase
   (el worker reemplaza "≥" por el real):
   | Fase | Tests (IDs reales) | N tests | Aserciones nuevas (mín., ≈2/test) |
   |---|---|---|---|
   | 1 (cimientos/harness) | `T29.11`–`T29.18`, `T29.110` | 9 | ≥ 18 |
   | 2 (writer) | `T29.21`–`T29.29` | 9 | ≥ 18 |
   | 3 (pusher/titular) | `T29.31`–`T29.39`, `T29.32b/c`, `T29.34b/c`, `T29.36b`, `T29.37b/c` | 16 | ≥ 32 |
   | 4 (variaciones) | `T29.41`–`T29.45` | 5 | ≥ 10 |
   | 5 (CF) | `T29.51`–`T29.59` + `T29.520` | 10 | ≥ 20 |
   | 6 (CF UI) | `T29.61`–`T29.64` + `T29.640` | 5 | ≥ 10 |
   | 7 (poll) | `T29.71`–`T29.76` | 8 | ≥ 16 |
   | 8 (lock/cron) | `T29.81`–`T29.85` | 5 | ≥ 10 |
   | 9 (regresión) | `T29.91`–`T29.95` | 5 | ≥ 10 |
   | **Total** | | **72 tests** | **≥ 1626 + 144 = 1770** |

   > El total de aserciones es un **piso** conservador (≈2 por test, mismo criterio que la tabla
   > original); el conteo **real** lo da el runner en el paso 2. La base **1626/0** no puede bajar.
3. Guardar el mapa de prove-it-catches (test → fix revertido → falla) en
   `docs/RELEASE_2.6.0_VERIFICATION.md` (T9.6).

**Resultado esperado**: `EXEC-TEST OK` y `SMOKE OK`; conteo real registrado; ningún test sin
prove-it-catch.

**Dependencias**: Fases 1–8; T9.1.

**Trazabilidad**: NFR-01.

**Verificación**: `bash scripts/exec-test.sh | tail -3` → `EXEC-TEST OK: <N> assertions passed, 0 failed`;
`bash scripts/smoke-test.sh | tail -1` → `SMOKE OK`.

**Riesgo**: tests en loops que inflan el conteo → registrar el conteo **real** del runner, no el estático.

**Estimación**: S (1 h).

---

### T9.3 — Smoke + PSR-4 de `Inventory_Writer`/`Inventory_Pusher`

**Objetivo**: que el smoke confirme sintaxis y que las dos clases nuevas cargan por el autoloader PSR-4.

**Descripción técnica**: `smoke-test.sh` corre `php -l` sobre cada `.php` y `smoke-load.php` (asserts de
autoloader). El patrón para una clase nueva está en `smoke-load.php:196-200` (`Run_Context class loads`).
Hay que agregar el assert de las dos clases de Fase 2/3. Cubre NFR-05.

**Desarrollo técnico** — `scripts/smoke-load.php`, junto al bloque `:196-200`:

```php
check(
    'Inventory_Writer class loads',
    class_exists(\Alegra\Connector\Sync\Inventory_Writer::class),
    '— el escritor único debe resolver por el autoloader PSR-4'
);

check(
    'Inventory_Pusher class loads',
    class_exists(\Alegra\Connector\Sync\Inventory_Pusher::class),
    '— el pusher de inventario debe resolver por el autoloader PSR-4'
);
```

Y correr el smoke sobre el ZIP extraído (modo `$1=path-to-zip`, `smoke-test.sh:24-63`) para confirmar
que las clases también están en el artefacto.

**Resultado esperado**: `SMOKE OK`; las dos clases resuelven por PSR-4 tanto en el working tree como en
el ZIP extraído.

**Dependencias**: Fase 2 (`Inventory_Writer`), Fase 3 (`Inventory_Pusher`).

**Trazabilidad**: NFR-05.

**Verificación**: `bash scripts/smoke-test.sh` → `SMOKE OK`; `bash scripts/smoke-test.sh releases/alegra-connector-v2.6.0.zip`
→ `SMOKE OK`. **Prove-it-catches:** renombrar el archivo de `Inventory_Writer.php` → el assert falla.

**Riesgo**: el ZIP debe incluir `includes/Sync/Inventory_Writer.php` y `Inventory_Pusher.php` (no están
en `.distignore`) → verificar con `unzip -l`.

**Estimación**: S (0,5 h).

---

### T9.4 — Aceptación manual en la tienda (los 5 problemas del comerciante)

**Objetivo**: validar en el WP admin real que los 5 problemas reportados quedaron resueltos, con pasos reproducibles.

**Descripción técnica**: los criterios de éxito de la propuesta §7 son observables desde el admin. Esta
micro-tarea es la validación humana (no la reemplaza el harness: depende de UI/tiempos reales). Cubre
REQ-CF-01..07, REQ-INV-01..08, REQ-POLL-01..06, NFR-01. El resultado se firma en
`docs/RELEASE_2.6.0_VERIFICATION.md` con capturas.

**Desarrollo técnico — pasos exactos (WP admin)**:

**Preparación**: instalar 2.6.0 en una **réplica** de la tienda; `Alegra Connector → Configuración`:
conexión testeada; método de sincronización = `Periódica` (o `Periódica + Tiempo Real`); tener un catálogo
de **> 90 ítems** en Alegra (≥ 3 páginas de 30) y un producto con stock 10.

**Problema 1 — "El dashboard dice 'Consumidor Final — No encontrado' aunque el contacto existe"** (falso negativo del CF)
1. `Alegra Connector → Configuración → Conexión` → completar credenciales → **Conectar**.
2. Abrir el dashboard (`Alegra Connector`): la fila del CF dice **Disponible** (o **No verificado** con
   "Verificar ahora" si el chequeo no pudo completarse). **Nunca** "No encontrado" con el CF existente.
3. Pulsar **"Verificar ahora"**: la fila se actualiza sin recargar y muestra `message`.
4. En Alegra, **borrar** el CF (cuenta de prueba); en WC, facturar un pedido que lo use.
5. **Resultado esperado**: la factura se auto-sana (nota "Consumidor Final re-resuelto y factura creada
   (auto-sanado)") o deja un mensaje accionable; **no** falla en silencio; el log tiene la causa.
   **Riesgo: Alto.**

**Problema 2 — "Vendí y el stock volvió a subir solo → sobreventa"** (la re-inflación, el titular)
1. Producto con stock **10** en WC y **10** en Alegra; `push_orders_enabled=false` (default).
2. Vender **3** unidades en WC (stock WC=7). Confirmar que el ajuste WC→Alegra se emite (log).
3. `Alegra Connector → Productos` → **"Sincronizar inventario"**.
4. **Resultado esperado**: el stock de WC **no** sube (queda en 7 o baja); el stock de Alegra refleja 7.
   El log muestra el ajuste, no una re-inflación. **Riesgo: Crítico.**
5. Grep de un solo escritor: `grep -rnE 'set_manage_stock|set_stock_quantity|set_stock_status' includes/ public/ admin/`
   ⇒ matches **solo** en `includes/Sync/Inventory_Writer.php`.

**Problema 3 — "El stock no se actualiza / las variaciones quedan viejas"**
1. Cambiar el stock de un **producto simple** en Alegra; esperar el webhook/import (o correr el import).
   **Resultado esperado**: WC refleja el nuevo stock.
2. Cambiar el stock de una **variación** en Alegra; disparar el webhook/import update del padre.
   **Resultado esperado**: la variación hija se actualiza (no solo el padre).
3. Con `alegra_connector_import_preserve_fields` incluyendo `inventory`, correr el poll: el stock **no**
   se toca. Sin preservar, sí se escribe.
4. Producto con `backorders=yes` y stock 0 ⇒ badge **"onbackorder"**; con `backorders=no` y stock 0 ⇒
   **"outofstock"** (idéntico a HEAD con el umbral por defecto). Con
   `woocommerce_notify_no_stock_amount=2` y `backorders=no`, un producto con qty 1 queda
   **"outofstock"** (cambio intencional, **R19**). **Riesgo: Medio.**

**Problema 4 — "El sitio se ralentiza / el poll nunca termina"** (el lock y la cascada)
1. Con el catálogo grande, bajar en `Configuración → Avanzado` el **tope de lote** del poll (p. ej. 2) y
   el **presupuesto** (p. ej. 20 s); guardar.
2. `Productos` → **"Sincronizar inventario"**.
3. **Resultado esperado**: la respuesta dice "Inventario sincronizado parcialmente… (cursor N)"; el log
   tiene `Inventory poll truncated; resuming next run`; **no** hay fatal 500 ni worker colgado minutos.
4. Con `push_products_enabled=true`, confirmar que el poll **no** dispara un POST por ítem (sin cascada).
5. Provocar un fatal a mitad (o matar el request): el próximo poll corre **sin** esperar 300 s (lock
   liberado por el shutdown handler). **Riesgo: Alto.**

**Problema 5 — "Reanudar vs reimportar / nunca completa"**
1. Tras una corrida truncada, `Productos` muestra el progreso/cursor; una **segunda corrida continúa**
   desde el cursor (no reinicia en la página 1).
2. Repetir hasta que el catálogo complete: la última corrida deja `completed=true` y borra el cursor.
3. `Configuración → Avanzado`: ver la card **"Sincronización con cron real (recomendado)"** con
   `DISABLE_WP_CRON`, la URL real del sitio y la línea WP-CLI. **Riesgo: Medio.**

**Sin regresión (distribuido)**: facturación, clientes, pagos y webhooks siguen funcionando; DIAN
intacto; `inventory_source=woocommerce` sigue sin escribir stock.

**Resultado esperado**: checklist firmado + capturas del dashboard (CF), Ajustes (cron real), el AJAX
del poll truncado y el log del ajuste.

**Dependencias**: Fases 1–8; T9.2.

**Trazabilidad**: los 5 problemas + "sin regresión" (checklist final de `tasks.md §11`).

**Verificación**: cada paso de arriba es la verificación; las capturas se adjuntan a
`docs/RELEASE_2.6.0_VERIFICATION.md`.

**Riesgo**: la réplica difiere de producción → correr con catálogo ≥ 3 páginas y probar también el host
sin `set_time_limit` efectivo (NFR-03).

**Estimación**: L (3 h).

---

### T9.5.a — CHANGELOG + versión 2.6.0 + `uninstall.php` + `.pot`

**Objetivo**: documentar los cambios (incluidos los intencionales y la decisión D2 vs DD-8) y dejar la versión 2.6.0 consistente en las 3 fuentes que el build verifica.

**Descripción técnica**: `build-release.sh` exige `header == README == make-pot.php == argumento`
(`:46-63`, exit 9 si no). `ALEGRA_CONNECTOR_VERSION` se deriva del header (`alegra-connector.php:59`) ⇒
no hay constante que tocar. `uninstall.php` debe borrar las 9 opciones nuevas (T1.7 las siembra).
El `.pot` se regenera y commitea **antes** del build (C6). Cubre NFR-04.

**Desarrollo técnico**:
1. **`CHANGELOG.md`** — agregar arriba (después de `All notable changes…`), con el formato existente:
   ```markdown
   ## [2.6.0] - 2026-XX-XX

   > **Fiabilidad de sincronización: Consumidor Final honesto, inventario bidireccional y poll
   > robusto.** El titular es la **sobreventa**: WC nunca empujaba stock a Alegra y el poll re-inflaba
   > lo vendido. Ahora el plugin es dueño del movimiento de stock (ajuste WC→Alegra con delta) y el
   > poll tiene presupuesto, cursor y `truncated`. Verificación de release:
   > `docs/RELEASE_2.6.0_VERIFICATION.md`.

   ### Changed

   - **El poll ya no re-infla el stock.** WC→Alegra empuja el **delta** vía
     `POST /inventory-adjustments` cuando `push_orders_enabled=false` (default). Con
     `push_orders_enabled=true` la factura es dueña del movimiento. **Nunca** los dos para el mismo
     movimiento (REQ-INV-08).
   - **`_stock_status` se deriva con `wc_update_product_stock()`** respetando `_backorders` y el umbral
     de no-stock. **Cambio intencional:** con `backorders=yes` y stock 0 el estado pasa a
     `onbackorder` (antes `outofstock`). Con `backorders=no` y el umbral por defecto (`0`) el resultado
     es idéntico a 2.5.1; **si `woocommerce_notify_no_stock_amount > 0`**, WC deriva `outofstock` en el
     umbral (p. ej. qty 1 con umbral 2), donde 2.5.1 forzaba `instock`. Ver **R19**.
   - **El poll ahora honra `preserve_fields`:** con `inventory` en la lista de preservados, el poll no
     escribe stock (antes lo ignoraba en silencio). **Cambio intencional** (corrección de bug).
   - **El dashboard distingue "No verificado" de "No encontrado"** para el Consumidor Final; conectar
     resuelve el CF bajo un contexto explícito; "Verificar ahora" re-chequea sin recargar.

   ### Added

   - **`Inventory_Writer`** (escritor único de `_manage_stock`/`_stock`/`_stock_status`) y
     **`Inventory_Pusher`** (push WC→Alegra con ledger `_alegra_stock_synced`/`_alegra_stock_push_pending`).
   - **Poll con presupuesto y cursor:** `alegra_connector_inventory_poll_budget` (60 s),
     `_max_pages` (0 = sin tope), `_pull_cursor`, `_pull_total`; resultado con `truncated/completed/cursor/pages`.
   - **Auto-sanado del 400 por Consumidor Final borrado** (invalida + re-resuelve + reintenta una vez
     reusando `find_existing_invoice`).
   - **Cron real recomendado** en Ajustes: `DISABLE_WP_CRON` + línea de crontab con la URL del sitio.
   - **Lock a prueba de fatales:** `register_shutdown_function` libera `alegra_sync_running_products`.
   - **`cron_run_budget`** (540 s) < TTL 600 del lock global `alegra_cron_global`.

   ### Notes

   - Sin cambio de esquema. Opciones nuevas con defaults seguros; **sin migración**.
   - **Decisión de diseño D2 (dueño híbrido):** contradice deliberadamente `docs/sdd/inventory/DD-8`
     ("no cablear `create_inventory_adjustment()`"). Con los defaults (`push_orders=false`,
     `invoice_status=draft`) la factura **no** movía stock, así que el titular persistía. La intención
     de DD-8 (evitar doble conteo) se preserva vía el dueño a nivel tienda.
   - **Action Scheduler (diferido):** el poll corre por WP-Cron con presupuesto y cursor; sin
     dependencia de AS.
   - Opciones nuevas: `alegra_connector_push_inventory_enabled`,
     `alegra_connector_inventory_manage_stock_enabled`,
     `alegra_connector_inventory_poll_budget`, `alegra_connector_inventory_poll_max_pages`,
     `alegra_connector_cron_run_budget`, `alegra_connector_open_invoice_on_paid`,
     `alegra_connector_inventory_pull_cursor`,
     `alegra_connector_inventory_pull_total`, `alegra_connector_consumidor_final_probe`.
   ```
2. **`alegra-connector.php:6`** — `Version: 2.6.0` (la constante en `:59` se deriva sola).
3. **`README.md:9`** — `Version: 2.6.0 | PHP 8.0+ | WP 5.8+ | WC 6.0+`.
4. **`scripts/make-pot.php:22`** — `$version = '2.6.0';`.
5. **`uninstall.php`** — agregar a `alegra_connector_uninstall_options()` (junto a las existentes, p. ej.
   después de `:119`):
   ```php
   delete_option('alegra_connector_push_inventory_enabled');
   delete_option('alegra_connector_inventory_manage_stock_enabled');
   delete_option('alegra_connector_inventory_poll_budget');
   delete_option('alegra_connector_inventory_poll_max_pages');
   delete_option('alegra_connector_cron_run_budget');
   delete_option('alegra_connector_open_invoice_on_paid');
   delete_option('alegra_connector_inventory_pull_cursor');
   delete_option('alegra_connector_inventory_pull_total');
   delete_option('alegra_connector_consumidor_final_probe');
   ```
   (Las siembra `T1.7`; esta tarea **verifica** que estén. El borrado de locks por prefijo `:153-156` ya
   cubre `alegra_lock_alegra_sync_running_products` y `alegra_lock_alegra_inventory_push_*`.)
6. **`languages/alegra-connector.pot`** — regenerar con `php scripts/make-pot.php` y commitearlo **antes**
   del build (C6). La restricción "NO TOCAR `languages/*`" prohíbe editar traducciones, no regenerar el `.pot`.
7. **Commit** del bump: `git add CHANGELOG.md alegra-connector.php README.md scripts/make-pot.php uninstall.php languages/alegra-connector.pot && git commit -m "chore(release): 2.6.0"`.

**Resultado esperado**: header/README/make-pot en `2.6.0`; `uninstall.php` con las 9 opciones;
`CHANGELOG.md` con `## [2.6.0]` que menciona el titular, los cambios intencionales y D2 vs DD-8; `.pot`
regenerado y commiteado.

**Dependencias**: Fases 1–8 (todas las strings nuevas deben existir antes de regenerar el `.pot`).

**Trazabilidad**: NFR-04; decisión §10-1/§10-7 de `tasks.md`; nota D2 vs DD-8.

**Verificación**: `T29.95` (source-scan): `uninstall.php` contiene las 9 opciones; `alegra-connector.php`
contiene `Version: 2.6.0`; `CHANGELOG.md` contiene `## [2.6.0]`, `inventory-adjustments` y `DD-8`.
**Prove-it-catches:** quitar una opción de `uninstall.php` → `T29.95` falla.

**Riesgo**: que el `.pot` regenerado cambie y ensucie el árbol → commitearlo antes del build. Que el
`CHANGELOG` no se actualice → el source-scan lo cubre.

**Estimación**: M (2 h).

---

### T9.5.b — Build determinista 2.6.0: ZIP + `.sha256` + commit

**Objetivo**: producir y commitear el artefacto de release **byte-idéntico**, verificable por su `.sha256`.

**Descripción técnica**: política de artefactos (`docs/RELEASE_2.5.0_VERIFICATION.md:30-53`): el
mantenedor **construye y commitea** el ZIP localmente; CI **solo verifica y publica** (nunca recompila —
incidente v2.4.0). `build-release.sh` es determinista (`:236-240`) y excluye `scripts/`, `docs/`,
`CHANGELOG.md`, `README.md`, `releases/`, `*.zip`, `*.sha256` (C5). El build corre smoke + exec + `.pot`
como gates y aborta si el árbol está sucio (`:87-94`) o las versiones no coinciden (`:46-63`).

**Desarrollo técnico**:
1. **Bump ya hecho** en T9.5.a (árbol limpio).
2. **Build local**:
   ```bash
   bash scripts/build-release.sh 2.6.0
   ```
   Salida esperada: `Version consistency OK: header == README == make-pot.php == 2.6.0`, `SMOKE OK`,
   `EXEC-TEST OK`, `Wrote releases/alegra-connector-v2.6.0.zip` + `.sha256`.
3. **Commit de artefactos**:
   ```bash
   git add releases/alegra-connector-v2.6.0.zip releases/alegra-connector-v2.6.0.zip.sha256
   git commit -m "chore(release): add the 2.6.0 build artifacts"
   ```
   (Si el build regeneró el `.pot` y quedó distinto, commitearlo también; por eso T9.5.a lo regenera antes.)

**Resultado esperado**: `releases/alegra-connector-v2.6.0.zip` + `.sha256` commiteados; el ZIP no contiene
`scripts/` ni `docs/`.

**Dependencias**: T9.5.a (bump + árbol limpio), T9.2 (gates verdes).

**Trazabilidad**: NFR-05; política de artefactos.

**Verificación**:
```bash
( cd releases && sha256sum -c alegra-connector-v2.6.0.zip.sha256 )
unzip -l releases/alegra-connector-v2.6.0.zip | grep -E 'scripts/|docs/' || echo "ZIP limpio"
```
   Criterio: `OK` del `sha256sum -c` y sin `scripts/`/`docs/` en el ZIP.

**Riesgo**: árbol sucio al construir (el build aborta con exit 4) → commitear el bump antes. El `.pot`
regenerado ensucia el árbol → T9.5.a lo commitea antes.

**Estimación**: S (1 h).

---

### T9.5.c — Tag, workflow y verificación del asset

**Objetivo**: publicar el release 2.6.0 y verificar que el asset es byte-idéntico al ZIP commiteado y que queda como latest.

**Descripción técnica**: el workflow `.github/workflows/release.yml` verifica el artefacto commiteado
contra su `.sha256` (`:43-61`), corre smoke + exec (`:63-66`) y publica con
`softprops/action-gh-release@v2` (`:68-78`). **`make_latest: "true"` ya está en `:74`** (C4): **no hay
que agregarlo** en 2.6.0. Push del tag dispara el workflow.

**Desarrollo técnico**:
1. **Tag y push** (después del commit de artefactos):
   ```bash
   git tag -a v2.6.0 -m "Alegra Connector 2.6.0"
   git push origin main
   git push origin v2.6.0
   ```
2. **CI**: `:43-61` verifica `sha256sum -c` del ZIP commiteado; `:63-66` corre smoke+exec; `:68-78`
   publica el ZIP y el `.sha256` commiteados (sin recompilar). `make_latest: "true"` ya presente.

**Resultado esperado**: release `v2.6.0` publicado en GitHub, marcado como **latest**, con el ZIP y el
`.sha256` adjuntos, byte-idénticos a `releases/`.

**Dependencias**: T9.5.b.

**Trazabilidad**: NFR-05; política de artefactos.

**Verificación (digest asset == ZIP commiteado)**:
```bash
# 1. El ZIP local coincide con su .sha256
( cd releases && sha256sum -c alegra-connector-v2.6.0.zip.sha256 )

# 2. El asset publicado es byte-idéntico al ZIP commiteado
COMMITTED=$(awk '{print $1}' releases/alegra-connector-v2.6.0.zip.sha256)
gh release download v2.6.0 -p 'alegra-connector-v2.6.0.zip' -O /tmp/alegra-2.6.0.zip
DOWNLOADED=$(sha256sum /tmp/alegra-2.6.0.zip | awk '{print $1}')
test "$COMMITTED" = "$DOWNLOADED" && echo "ASSET OK"

# 3. El release está marcado latest
gh release view v2.6.0 --json isLatest,assets --jq '{isLatest, assets: [.assets[].name]}'
```
   Criterio: `ASSET OK` y `isLatest: true`.

**Riesgo**: que CI recompile (prohibido) → el workflow solo verifica/publica. Que el asset no coincida →
el `sha256sum -c` del CI lo detecta y **no publica**. Que `make_latest` llegue como booleano → ya está
comillado en `:74`.

**Estimación**: S (1 h).

---

### T9.6 — Prove-it-catches documentados

**Objetivo**: dejar en `docs/RELEASE_2.6.0_VERIFICATION.md` el mapa test → fix revertido → falla observada, con foco en las 5 tareas centrales.

**Descripción técnica**: la convención del repo es `docs/RELEASE_<X.Y.Z>_VERIFICATION.md` (existen
`RELEASE_2.5.0_VERIFICATION.md` y `RELEASE_2.4.2_VERIFICATION.md`). Las 5 tareas centrales son `T2.1`,
`T3.4`, `T5.5`, `T7.1`, `T8.1`. Cubre NFR-01.

**Desarrollo técnico**:
1. Crear `docs/RELEASE_2.6.0_VERIFICATION.md` con:
   - Encabezado + **política de artefactos** (copiar de `RELEASE_2.5.0_VERIFICATION.md:30-53`).
   - Sección **★★★ Lo nuevo de la 2.6.0**: los 5 problemas (los pasos de T9.4), cada uno con "Resultado esperado".
   - **Matriz de prove-it-catches**: `T29.21` (T2.1), `T29.34` (T3.4), `T29.55` (T5.5), `T29.71` (T7.1),
     `T29.81` (T8.1), más los de T9.1.
   - **Riesgos de upgrade**: cambios intencionales (`_stock_status` con backorders, `preserve_fields`
     honrado), D2 vs DD-8, sin migración, opciones nuevas.
   - **Rollback**: reinstalar 2.5.1 (sin cambio de esquema).
2. Referenciar el doc desde `CHANGELOG.md` (nota de release).
3. **No** agregar el doc al ZIP: `docs/` está en `.distignore` (C5).

**Resultado esperado**: el doc existe, mapea cada problema reportado a un resultado verificable y lista
el mapa de prove-it-catches de las 5 centrales + T9.1.

**Dependencias**: T9.4 (resultados reales), T9.2 (matriz).

**Trazabilidad**: NFR-01.

**Verificación**: `docs/RELEASE_2.6.0_VERIFICATION.md` existe y contiene las secciones de los 5 problemas
+ prove-it-catches + rollback.

**Riesgo**: doc desactualizado respecto del comportamiento real → se escribe después de T9.4.

**Estimación**: M (1,5 h).

---

## Criterios de aceptación (checklist final — los 5 problemas + sin regresión)

| # | Problema reportado / síntoma | Resultado verificable (WP admin) | REQ | Tareas | Tests (T29.x) | Verificación (T9.4) |
|---|---|---|---|---|---|---|
| 1 | **"El dashboard dice 'Consumidor Final — No encontrado' aunque el contacto existe"** (falso negativo del CF) | Tras conectar, la fila dice **Disponible** (o **No verificado** honesto); nunca "No encontrado" con el contacto existente; "Verificar ahora" funciona sin recargar; un CF borrado se auto-sana o deja nota accionable | REQ-CF-01..07 | T5.1–T5.6, T6.1–T6.4 | `T29.51`, `T29.52`, `T29.520`, `T29.53`–`T29.59`, `T29.61`–`T29.64`, `T29.640` | Pasos Problema 1 |
| 2 | **"Vendí y el stock volvió a subir solo → sobreventa"** (la re-inflación) | Tras vender 3 en WC, un poll posterior **no** sube el stock; el ajuste WC→Alegra refleja el delta; con `push_orders=true` la factura es dueña (0 ajustes); grep de un solo escritor | REQ-INV-01..08 | T1.4, T2.1–T2.8, T3.1–T3.7 | `T29.34`, `T29.36`, `T29.37`, `T29.38`, `T29.29` | Pasos Problema 2 |
| 3 | **"El stock no se actualiza / las variaciones quedan viejas"** | Un cambio de stock de variación en Alegra se refleja por update; `preserve_fields` se respeta; `_stock_status` con backorders correcto (y con umbral `notify_no_stock_amount > 0`, R19) | REQ-INV-03, INV-04, INV-05 | T2.1–T2.5, T4.1–T4.3, T4.5 | `T29.21`, `T29.23`, `T29.24`, `T29.25`, `T29.41`–`T29.45` | Pasos Problema 3 |
| 4 | **"El sitio se ralentiza / el poll nunca termina"** (el lock y la cascada) | El poll pausa por budget, persiste cursor, reporta `truncated=true`; el lock se libera ante un fatal; no hay cascada de push | REQ-POLL-01..04, NFR-02 | T7.1–T7.5, T8.1–T8.3 | `T29.71`–`T29.76`, `T29.81`–`T29.83` | Pasos Problema 4 |
| 5 | **"Reanudar vs reimportar / nunca completa"** | El cursor es visible/reanudable; el poll de catálogo grande termina en varias corridas o pausa con señal; Ajustes muestra el cron real | REQ-POLL-01, POLL-02, POLL-05, POLL-07 | T7.1, T7.2, T7.4, T8.4, T8.5 | `T29.71`, `T29.73`, `T29.75`, `T29.84`, `T29.85` | Pasos Problema 5 |
| 6 | **Sin regresión (distribuido)** | DIAN intacto; facturación/clientes/pagos/webhooks siguen; `inventory_source=woocommerce` sigue sin escribir stock | NFR-01, NFR-03, NFR-05 | T9.1–T9.4, T5.5, T5.7 | `T29.91`–`T29.94`, `T29.59` | T9.1 (matriz R1–R19) |

---

## DoD Fase 9 (checklist de cierre)

- [ ] `T9.1`: R1–R19 con test concreto + `T29.91`–`T29.94`; prove-it-catches documentados.
- [ ] `T9.2`: `bash scripts/exec-test.sh` → `EXEC-TEST OK` con el conteo real (objetivo ≥ 1770, piso; 72 tests nuevos); `bash scripts/smoke-test.sh` → `SMOKE OK`.
- [ ] `T9.3`: `smoke-load.php` asserta `Inventory_Writer`/`Inventory_Pusher` por PSR-4; smoke del ZIP verde.
- [ ] `T9.4`: checklist manual de los 5 problemas firmado + capturas.
- [ ] `T9.5.a`: `CHANGELOG.md`, versión 2.6.0 en header/README/make-pot, `uninstall.php` con las 9 opciones, `.pot` regenerado y commiteado.
- [ ] `T9.5.b`: `releases/alegra-connector-v2.6.0.zip` + `.sha256` commiteados; ZIP sin `scripts/`/`docs/`.
- [ ] `T9.5.c`: tag `v2.6.0` pusheado; CI publicó; `ASSET OK` + `isLatest: true`; `make_latest` ya presente en `release.yml:74`.
- [ ] `T9.6`: `docs/RELEASE_2.6.0_VERIFICATION.md` con los 5 problemas + prove-it-catches + rollback.

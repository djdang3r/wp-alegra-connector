# Tareas micro-detalladas — Fase 0 (gates G1–G8)

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` (Consumidor Final honesto + inventario bidireccional + robustez del poll/cron) |
| Documentos base | `proposal.md` §8 · `spec.md` §F · `design.md` §14 · `tasks.md` §5 |
| Alcance de este archivo | **Fase 0 — `T0.1` … `T0.8`** (8 gates, 8 tareas) |
| Naturaleza | **READ-ONLY.** No se escribe ni una línea de código de producción en esta fase. |
| Entorno | Cuenta Alegra real del comerciante (o réplica de prueba) + repo en HEAD + acceso al WP admin del sitio |
| Salida única | `docs/sdd/sync-reliability/phase0-results.md` (**se crea en `T0.1`**; cada gate agrega su rama) |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`; baseline **1626 assertions / 0 failed**) · `bash scripts/smoke-test.sh` |
| Versión analizada | 2.5.1 (`alegra-connector.php:6`) · Versión objetivo **2.6.0** |

> **Regla de oro (heredada del skeleton).** Ninguna tarea marcada `BLOQUEADO(Fase 0.x)` arranca hasta
> que el gate tenga su **rama elegida** registrada en `phase0-results.md`. La **Fase 1 NO depende de
> ningún gate**: se puede empezar ya (ver `fase-1-cimientos.md`).

> **Regla de seguridad.** La cuenta real maneja credenciales. **Nunca** pegar el token en
> `phase0-results.md`; redactarlo como `<token>`. Los `curl` de abajo usan variables de entorno.

## Mapa de gates

| Task | Gate | Pregunta | REQ | Bloquea | Est. |
|---|---|---|---|---|---|
| `T0.1` | G1 | ¿Un borrador (`draft`) mueve stock en Alegra y `open` sí? | REQ-INV-01 (rama b) | **`T3.5`** (sólo la rama `invoice`) | 1 h |
| `T0.2` | G2 | ¿`edit-item` dispara con un cambio **solo de stock**? | REQ-INV-03 | **`T4.1`** (sólo la rama de tiempo real) | 1 h |
| `T0.3` | G3 | ¿Schema exacto de `POST /inventory-adjustments` + idempotencia? | REQ-INV-08 | **`T3.1`, `T3.2`** | 1 h |
| `T0.4` | G4 | ¿Cuántos ítems activos tiene el catálogo? | REQ-POLL-02 | **`T7.1`** (sólo el default) | 0.5 h |
| `T0.5` | G5 | ¿El host corre cron real o WP-Cron por tráfico? | REQ-POLL-05 | **`T8.4`** (sólo el copy) | 0.5 h |
| `T0.6` | G6 | ¿Qué body trae el 400 por client id muerto? | REQ-CF-06 | **`T5.5`** | 1 h |
| `T0.7` | G7 | ¿Existe `wc_update_product_stock()` en el WC del comerciante? | REQ-INV-04 | **`T2.1`** (sólo el fallback) | 0.25 h |
| `T0.8` | G8 | ¿Semántica de bodega (R7 del SDD `inventory`)? | REQ-INV-06 | **`T4.4`** | 0.5 h |

> **Los únicos gates que deciden una rama de código real:** `G1` (dueño `invoice` vs `adjustment`) y
> `G3` (default de `push_inventory_enabled`). El resto son calibración/copy/fallback. **La rama
> `adjustment` (default del titular) nunca depende de G1.**

## Correcciones de cita detectadas al escribir esta fase

Re-verificadas contra HEAD. Afectan a los gates y a las fases que consumen su resultado.

| # | Cita (skeleton/design/spec) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `design.md:1014` H1 "`wc_update_product_stock()` ausente en `scripts/lib/wp-stubs.php`" | **Confirmado:** `grep -rn "wc_update_product_stock" scripts/ includes/ public/ admin/` ⇒ **0 matches**. | G7 (`T0.7`) decide el fallback; H1 (`T1.1`) es el stub. |
| C2 | `design.md:1002` H2 "`wp-stubs.php:1363` (variación)" | **Falso.** `:1363` es `WC_Order::save()`. `WC_Product_Variation` está en `:1289-1296` y **hereda** `WC_Product::save()` (`:1284`). No hay un `save()` propio de variación. | `T1.2` edita **`:1284`** (único). Ver `fase-1-cimientos.md` C1. |
| C3 | `tasks.md:232` H5 "`POST /invoices` sólo valida presencia (`alegra-mock.php:535-556`)" | Confirmado `:535-556`. Pero hacer el chequeo **estricto por defecto** rompería el baseline: `T3.1`/`T3.2` (`exec-test.php:265-326`) facturan con `_billing_alegra_contact_id='c0n-co'/'c0n-mx'` **sin sembrar** el contacto, y hay 4 tests con `api->create_invoice(['client'=>['id'=>'c1']])` (`exec-test.php:4748,4775,4792,4809`). | G6 (`T0.6`) captura el body real; `T1.5` implementa H5 **opt-in** (flag `alegra_mock_set_invoice_client_check`). Ver `fase-1-cimientos.md` C3. |
| C4 | `tasks.md:227` H3 "modo CONTAINS en el mock (`alegra-mock.php:863`)" | Confirmado `:863` (`$num === $ident`). **Pero** `alegra_mock_filter_contacts()` (`:849-867`) **tampoco aplica `start`/`limit`** (devuelve todos). Sin eso, REQ-CF-05 (paginación) no es testeable. | **H3b (nueva):** `T1.3` agrega CONTAINS **y** paginación. Ya registrada como C9 en `fase-4-variaciones.md`. |
| C5 | `tasks.md:305` T1.7 "las 8 opciones en `$defaults`" | El `design.md:1098-1107` marca `$defaults` = sí **sólo** para 5 (las 3 internas se leen con default); la 9.ª (`open_invoice_on_paid`, Fase 3 C4) también va en `$defaults`. | `T1.7`: **6** en `$defaults`; **9** en `$non_autoload` + `uninstall.php`. `register_setting` no es de `T1.7`. |
| C6 | `design.md:1173` G8 "R7 del SDD `inventory`" | El gate no tiene una pregunta binaria nueva: la decisión de diferir ya está tomada en `design.md:1179-1183`; el gate sólo **confirma** que no hay semántica de bodega utilizable. | `T0.8` cierra G8 como **diferido** salvo que aparezca semántica clara. |

**Citas confirmadas exactas (no requieren corrección):** `Client.php:242` (`api_error` con
`['code','response']`), `:761` (`get_inventory_adjustments`), `:770` (`create_inventory_adjustment`);
`alegra-connector.php:451` (`invoice_status='draft'`), `:414-457` (`$defaults`), `:463-486`
(`$non_autoload`); `templates/admin-webhooks.php` (Recorder read-only sobre
`includes/Webhooks/Recorder.php`); `templates/admin-settings.php:468-474` (card "Sincronización
Periódica"); `alegra-mock.php:535-556` (validador de invoice), `:656-658` (ruta GET /contacts), `:849-867`
(`alegra_mock_filter_contacts`), `:863` (match exacto); `uninstall.php:75-77` (opciones de inventario).

---

## Plantilla de `phase0-results.md` (la crea `T0.1`)

```markdown
# Resultados Fase 0 — `sync-reliability`

Cuenta: <alias de la cuenta de prueba> · Fecha: <YYYY-MM-DD> · HEAD: <sha corto>

| Gate | Rama | Decisión | Evidencia |
|---|---|---|---|
| G1 | A/B/C | … | stock antes/después + request/response |
| G2 | A/B | … | captura del Recorder |
| G3 | A/B | … | request/response crudos |
| G4 | A/B | … | metadata.total |
| G5 | A/B | … | DISABLE_WP_CRON + wp cron event list |
| G6 | A/B/C | … | body crudo del 400 |
| G7 | A/B | … | function_exists |
| G8 | A/B | … | doc + GET /items/{id} |
```

---

### T0.1 — G1: ¿un `draft` mueve stock en Alegra y `open` sí? · BLOQUEA T3.5

**Objetivo**: determinar con evidencia si una factura en estado **borrador** (`draft`) mueve el stock
de Alegra y si **abrirla** (`open`) lo mueve. Es el gate que decide si el dueño `invoice` de D2 (rama
b) es viable cuando `push_orders_enabled=true`.

**Descripción técnica**: el diseño elige el **híbrido con dueño a nivel tienda** (D2, `design.md:526-533`):
`owner = (push_orders_enabled && open_invoice_on_paid) ? 'invoice' : 'adjustment'` (`design.md:530`). La
rama `invoice` (`push_orders=true` **y** `open_invoice_on_paid=true`) requiere que un pedido pagado deje
la factura `open` y que **esa apertura descuente stock de forma nativa**. El default del plugin es
`invoice_status='draft'`
(`alegra-connector.php:451`), y la premisa "el borrador no mueve stock" es **R1 del SDD
`inventory` / SIN VERIFICAR**. El mock modela la apertura con `PUT /invoices/{id} {"status":"open"}`
(`alegra-mock.php:791-799`), pero el comportamiento real sólo se comprueba en la cuenta. Cubre
**REQ-INV-01** (rama b) y **REQ-INV-08** (no doble conteo).

**Desarrollo técnico**:

- **Archivos a leer (no editar):** `alegra-connector.php:451` (`invoice_status`), `design.md:526-533`
  (D2), `docs/sdd/inventory/spec.md` R1 (premisa). Salida: `phase0-results.md` (crear).
- **Preparación:** elegir un ítem **inventariable** con stock conocido (`ITEM_ID`) y un cliente válido
  (`CLIENT_ID`, p.ej. el CF). Guardar el stock inicial.

  ```bash
  export ALEGRA_EMAIL="cuenta@ejemplo.com"
  export ALEGRA_TOKEN="<token>"           # NUNCA commitear ni pegar en el .md
  export ITEM_ID="<item-inventariable>"
  export CLIENT_ID="<contacto-valido>"
  BASE="https://api.alegra.com/api/v1"
  AUTH="-u $ALEGRA_EMAIL:$ALEGRA_TOKEN"

  # 0) Stock inicial
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

- **Paso 3 — registrar** en `phase0-results.md`: `stock_inicial`, `stock_tras_draft`, `stock_tras_open`,
  y el request/response crudo (token redactado). Alternativa si la cuenta tiene UI: hacer los mismos 3
  pasos desde el panel y capturar la pantalla de inventario.

- **Decision tree (fijar la rama y escribirla):**
  - **Rama A — `draft` NO mueve y `open` SÍ** (stock_inicial == stock_tras_draft > stock_tras_open):
    (b) es viable como dueño. **`T3.5` implementa `open_invoice_on_paid` tal cual el `design.md`** (un
    pedido pagado nace `open`). El dueño `adjustment` (default) no cambia.
  - **Rama B — `draft` SÍ mueve** (stock_tras_draft < stock_inicial): el dueño `invoice` se mantiene
    pero **se documenta**; `T3.5` debe evitar el doble descuento (no volver a abrir una factura que ya
    movió) y registrar el comportamiento en `CHANGELOG.md`. El default sigue siendo `adjustment`.
  - **Rama C — error/red/la cuenta no permite borradores**: gate **INCONCLUSO**. **`T3.5` queda
    BLOQUEADO** (no arranca la rama `invoice`); la rama `adjustment` (titular) sigue y no se ve afectada.

**Resultado esperado**: `phase0-results.md` creado con la fila `G1` y los 3 valores de stock + la rama
A/B/C. Criterio verificable: el archivo contiene `G1`, `stock_inicial=`, `stock_tras_draft=`,
`stock_tras_open=` y `rama=`.

**Dependencias**: ninguna. Bloquea **`T3.5`** (sólo su rama `invoice`).

**Trazabilidad**: REQ-INV-01 (rama b), REQ-INV-08; R1 del SDD `inventory`; `design.md` §3.3/§14.

**Verificación**: los 3 `curl` sobre la cuenta + `grep -n "G1" docs/sdd/sync-reliability/phase0-results.md`
devuelve la rama y los valores.

**Riesgo**: que el ítem elegido no sea inventariable (sin `inventory.availableQuantity`) y el gate mida
nada. **Guard**: el Paso 0 debe devolver un `availableQuantity` numérico; si no, elegir otro ítem. Si la
cuenta no tiene facturación, marcar **C** y no bloquear el titular.

**Estimación**: M (1 h).

---

### T0.2 — G2: ¿`edit-item` dispara con un cambio solo de stock? · BLOQUEA T4.1 (tiempo real)

**Objetivo**: saber si el webhook `edit-item` de Alegra se dispara cuando **sólo** cambia el stock de un
ítem. Define si el update de variaciones (D4) tiene camino de **tiempo real** o si el poll (Fase 7) es el
único camino.

**Descripción técnica**: `import_single_item_from_alegra()` sólo refresca las variaciones hijas en la
rama de **creación** (`Products.php:1613-1618`); en la rama existing (`:1548-1557`) no itera hijos
(hallazgo B4). D4 (`design.md:717-763`) agrega `refresh_variant_children()` en el update. El
requerimiento **REQ-INV-03** aplica **igual** en ambas ramas; G2 sólo decide si el webhook entrega el
evento en tiempo real o si hay que esperar al poll. El Recorder es read-only
(`templates/admin-webhooks.php:1-5`, tabla `:101-143`).

**Desarrollo técnico**:

- **Paso 1 — confirmar la suscripción.** En WP admin → Ajustes → Webhooks, verificar que `edit-item`
  está en el selector de eventos (`alegra_connector_webhook_selected_events`) y que el webhook está
  registrado. Si no, registrarlo desde la UI y esperar la confirmación.

- **Paso 2 — cambiar SÓLO el stock en Alegra.** Desde el panel de Alegra, editar un ítem y modificar
  **únicamente** `inventory.availableQuantity` (no nombre, no precio, no categoría). Alternativa API:

  ```bash
  # Leer el item, cambiar SOLO el stock por un ajuste (mismo endpoint que usará el pusher)
  curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'
  # Si el schema de G3 ya se confirmó, usar POST /inventory-adjustments; si no, editar desde la UI.
  ```

- **Paso 3 — observar el Recorder.** Abrir la pestaña **Webhooks** del plugin
  (`templates/admin-webhooks.php`) y buscar el evento. Registrar: ¿aparece `edit-item` con entidad
  `item`? ¿cuánto tardó? Capturar la fila cruda (evento + entidad + payload).

- **Decision tree (fijar la rama):**
  - **Rama A — `edit-item` SÍ dispara**: el webhook da **tiempo real**; `T4.1` puede confiar en el
    payload del webhook y el `refresh_variant_children()` corre en el update del webhook. Documentar el
    tiempo de latencia observado.
  - **Rama B — `edit-item` NO dispara** (o dispara sólo con cambios no-stock): el **poll (Fase 7)** es
    el único camino para un cambio solo-de-stock. `T4.1` **igual** refresca variaciones cuando el
    import/update corre (chunked/cron); se documenta que no hay tiempo real.

**Resultado esperado**: fila `G2` en `phase0-results.md` con la rama A/B + captura del evento (o su
ausencia) y la latencia.

**Dependencias**: ninguna. Bloquea **`T4.1`** (sólo la decisión de tiempo real; el código de refresh en
update aplica igual).

**Trazabilidad**: REQ-INV-03; `design.md` §5.1-§5.2/§14.

**Verificación**: captura del Recorder (o ausencia documentada) en `phase0-results.md`; la fila contiene
`G2`, `evento=edit-item|ausente`, `rama=`.

**Riesgo**: que el webhook no esté registrado y el "no dispara" sea un falso negativo. **Guard**: el
Paso 1 confirma la suscripción antes de concluir B.

**Estimación**: M (1 h).

---

### T0.3 — G3: schema exacto de `POST /inventory-adjustments` · BLOQUEA T3.1, T3.2

**Objetivo**: confirmar los campos exactos y la semántica del `POST /inventory-adjustments` de Alegra, y
si existe una query de idempotencia. Decide el **default de `push_inventory_enabled`** y el payload
exacto del pusher (D2 rama a).

**Descripción técnica**: el dueño `adjustment` (default, `push_orders=false`) empuja el delta de stock
vía `Client::create_inventory_adjustment()` (`API/Client.php:770`, hoy `@deprecated` y sin caller). El
design asume `{date, type:'in'|'out', quantity, item:{id}, warehouse?}` (`design.md:562-569`). Si el
schema real no coincide, **nunca** se escribe a ciegas (DR14): el push se desactiva por defecto
(`push_inventory_enabled=false`) y se reporta. El endpoint de consulta ya existe
(`get_inventory_adjustments()`, `Client.php:761`). Cubre **REQ-INV-01** y **REQ-INV-08**.

**Desarrollo técnico**:

- **Paso 1 — leer la doc oficial**: `https://developer.alegra.com/reference/post_inventory-adjustments`.
  Anotar: campos obligatorios, tipos, enum de `type`, si `warehouse` es opcional, y si hay
  `idempotency`/consulta por `item_id`/`date`.

- **Paso 2 — probar en la cuenta (payload candidato del design):**

  ```bash
  # type=out con quantity positiva (delta negativo => out)
  curl -s $AUTH -X POST "$BASE/inventory-adjustments" -H 'Content-Type: application/json' -d '{
    "date":"2026-09-25",
    "type":"out",
    "quantity":3,
    "item":{"id":"'"$ITEM_ID"'"}
  }' -w '\nHTTP %{http_code}\n'

  # Reintento con warehouse (si la cuenta tiene bodega) y con type=in
  curl -s $AUTH -X POST "$BASE/inventory-adjustments" -H 'Content-Type: application/json' -d '{
    "date":"2026-09-25","type":"in","quantity":2,"item":{"id":"'"$ITEM_ID"'"},"warehouse":{"id":"<WH_ID>"}
  }' -w '\nHTTP %{http_code}\n'
  ```

- **Paso 3 — probar la idempotencia/consulta:**

  ```bash
  curl -s $AUTH "$BASE/inventory-adjustments?item_id=$ITEM_ID" | python3 -m json.tool
  # Probar también ?start=0&limit=30 si el endpoint pagina.
  ```

- **Paso 4 — registrar** en `phase0-results.md`: el request y el response crudos de cada variante
  (éxito/400), los campos obligatorios observados, y si la consulta por `item_id` devuelve los ajustes.

- **Decision tree (fijar la rama):**
  - **Rama A — el schema coincide** (`type`/`quantity`/`item.id` aceptados; `warehouse` opcional):
    `push_inventory_enabled` default **`true`**; `T3.1`/`T3.2` implementan el payload del `design.md`
    §3.4 sin cambios. Si la consulta por `item_id` funciona, `T3.2` la usa antes de re-emitir (DR3).
  - **Rama B — el schema NO coincide** (400 por campos faltantes/renombrados, `type` distinto, etc.):
    `push_inventory_enabled` default **`false`** + reporte en `phase0-results.md`; `T3.1` ajusta el
    payload al schema real; el default del plugin se cambia en `T1.7`/`T3.1`. **Nunca** escribir a
    ciegas (DR14).

**Resultado esperado**: fila `G3` con los campos confirmados, el default decidido
(`push_inventory_enabled=true|false`) y los request/response crudos (token redactado).

**Dependencias**: ninguna. Bloquea **`T3.1`** y **`T3.2`** (payload + default).

**Trazabilidad**: REQ-INV-01, REQ-INV-08; DR3/DR14; `design.md` §3.4/§14.

**Verificación**: `phase0-results.md` contiene `G3`, `push_inventory_enabled_default=`, y al menos un
request/response de `/inventory-adjustments`.

**Riesgo**: usar el `ITEM_ID` equivocado o dejar el stock de la cuenta tocado. **Guard**: usar la cuenta
de prueba; después del gate, compensar con un ajuste inverso (`type` opuesto) para dejar el stock como
estaba.

**Estimación**: M (1 h).

---

### T0.4 — G4: tamaño del catálogo · BLOQUEA T7.1 (sólo el default)

**Objetivo**: contar los ítems activos del catálogo real para calibrar los defaults del poll
(`inventory_poll_budget`, `inventory_poll_max_pages`).

**Descripción técnica**: el poll hoy tiene un tope fijo de 200 páginas × 30 = 6.000 ítems y arranca en
la página 1 en cada corrida (hallazgos C1/C2). D6 (`design.md` §7.1) introduce
`inventory_poll_budget` (default **60 s**) y `inventory_poll_max_pages` (default **0** = sin tope; el
cursor reanuda). El tamaño real calibra si esos defaults alcanzan para terminar en una corrida o si el
cursor es la norma. Cubre **REQ-POLL-02**.

**Desarrollo técnico**:

- **Paso 1 — contar con metadata:**

  ```bash
  # Total de ítems activos (metadata.total)
  curl -s $AUTH "$BASE/items?metadata=true&limit=1&status=active" | python3 -m json.tool
  # Si el endpoint ignora status, contar sin filtro y restar los inactivos si se conocen.
  ```

  Alternativa UI: Alegra → Inventario → contador de ítems. Alternativa local:
  `wp eval '$c=new \Alegra\Connector\API\Client(new \Alegra\Connector\Logger\Logger()); $r=$c->get("/items",["metadata"=>true,"limit"=>1]); echo $r["metadata"]["total"];'`.

- **Paso 2 — registrar** el total en `phase0-results.md`.

- **Decision tree (fijar la rama):**
  - **Rama A — catálogo chico** (entra holgado en `budget=60 s`, p.ej. < ~500 ítems): se mantienen los
    defaults del `design.md` §7.1 (`budget=60`, `max_pages=0`). No se cambia nada.
  - **Rama B — catálogo grande** (no entra en 60 s): se documenta que el poll **pausa y reanuda por
    cursor** (comportamiento esperado, no un bug). `T7.1` mantiene `max_pages=0` (sin tope) para que el
    cursor reanude; el comerciante puede subir `budget` desde Ajustes. Si el total supera el viejo tope
    de 6.000, se anota explícitamente que el tope fijo era insuficiente.

**Resultado esperado**: fila `G4` con `total_items=` y la rama A/B + nota de calibración.

**Dependencias**: ninguna. Bloquea **`T7.1`** (sólo el default del budget/tope).

**Trazabilidad**: REQ-POLL-02; `design.md` §7.1/§14.

**Verificación**: `phase0-results.md` contiene `G4` y `total_items=`.

**Riesgo**: `metadata=true` no soportado por la versión de API. **Guard**: paginar con `limit=30` y
contar hasta que una página venga incompleta.

**Estimación**: S (0.5 h).

---

### T0.5 — G5: ¿el host corre cron real? · BLOQUEA T8.4 (sólo el copy)

**Objetivo**: saber si el sitio ya ejecuta un cron real del sistema o si depende del tráfico de
visitantes (WP-Cron). Decide el copy de la recomendación en Ajustes.

**Descripción técnica**: WP-Cron dispara por visitas; en tiendas de poco tráfico el poll puede demorar
horas. REQ-POLL-05 pide mostrar `DISABLE_WP_CRON` + una línea de crontab. El plugin usa `spawn_cron()`
en `Admin_Dashboard.php:4209`. Hoy hay **0** coincidencias de `DISABLE_WP_CRON`/`wp_doing_cron` en
producción (hallazgo C6).

**Desarrollo técnico**:

- **Paso 1 — revisar la constante:**

  ```bash
  wp config get DISABLE_WP_CRON --allow-root 2>/dev/null || grep -n "DISABLE_WP_CRON" wp-config.php
  ```

- **Paso 2 — listar eventos:**

  ```bash
  wp cron event list --fields=hook,next_run_gmt,recurrence | grep alegra
  # Esperado: alegra_connector_cron_sync con recurrencia (p.ej. hourly).
  ```

- **Paso 3 — panel del hosting / crontab (si hay acceso):**

  ```bash
  crontab -l | grep -i wp-cron   # o revisar el panel (cPanel/Plesk)
  ```

- **Paso 4 — registrar** en `phase0-results.md`: valor de `DISABLE_WP_CRON`, presencia de un crontab
  externo, y el próximo run del evento.

- **Decision tree (fijar la rama):**
  - **Rama A — ya usa cron real** (`DISABLE_WP_CRON=true` o hay crontab externo): la recomendación de
    `T8.4` es **informativa**, sin alarma.
  - **Rama B — WP-Cron por tráfico** (`DISABLE_WP_CRON` ausente/false y sin crontab): `T8.4` muestra la
    recomendación **accionable** con `DISABLE_WP_CRON` + la línea de crontab generada con
    `home_url('/wp-cron.php')`.

**Resultado esperado**: fila `G5` con `DISABLE_WP_CRON=`, `crontab_externo=si|no`, `rama=A|B`.

**Dependencias**: ninguna. Bloquea **`T8.4`** (sólo el copy: informativo vs accionable).

**Trazabilidad**: REQ-POLL-05; `design.md` §8.4/§14.

**Verificación**: `phase0-results.md` contiene `G5` y el estado del host.

**Riesgo**: sin acceso al host no se puede confirmar. **Guard**: marcar `unverified` y mostrar la
recomendación como informativa (nunca como error).

**Estimación**: S (0.5 h).

---

### T0.6 — G6: body del 400 por client id muerto · BLOQUEA T5.5

**Objetivo**: capturar el **body exacto** que Alegra devuelve en un 400 causado por un `client.id`
inexistente, para fijar el detector del self-heal. Decide si el detector usa el campo `response.client`
o cae al regex `/client|cliente/i`.

**Descripción técnica**: el self-heal (D1, `design.md:299-376`) sólo dispara con `$result` `WP_Error`
cuyo `code === 400` **y** que mencione al cliente. `Client::request()` arma el error con
`new WP_Error('api_error', $message, ['code'=>$code, 'response'=>$error_data])` (`Client.php:242`), donde
`$error_data` es el body decodificado. Sin el body real, el detector queda adivinado. Cubre
**REQ-CF-06**; DR4/DR5.

**Desarrollo técnico**:

- **Paso 1 — forzar el 400 (vía API, reproducible):**

  ```bash
  curl -s $AUTH -X POST "$BASE/invoices" -H 'Content-Type: application/json' -d '{
    "client":{"id":"dead-id-inexistente"},
    "items":[{"id":"'"$ITEM_ID"'","price":1000,"quantity":1}],
    "date":"2026-09-25","dueDate":"2026-09-25"
  }' -w '\nHTTP %{http_code}\n'
  ```

- **Paso 2 — reproducirlo desde el plugin (cuenta real):** en un pedido de prueba, escribir el meta
  `_billing_alegra_contact_id` con un id borrado y facturar; capturar la respuesta cruda desde el log
  del plugin (`Client.php:241` loguea `code`/`message`) o desde el request.

- **Paso 3 — registrar** en `phase0-results.md` el body completo y el `HTTP <code>` (token redactado).

- **Decision tree (fijar la rama):**
  - **Rama A — el body trae el campo `client`** (p.ej. `{"client":[...]}` o
    `response.client` presente): `T5.5` usa el **campo** para el detector
    (`isset($body['client'])`).
  - **Rama B — sólo mensaje** (p.ej. `{"message":"El cliente no existe"}` sin campo `client`):
    `T5.5` cae al **regex** `/client|cliente/i` sobre `$error->get_error_message()`.
  - **Rama C — el error no es 400** (401/422/5xx): el self-heal **no** dispara; `T5.5` queda
    **BLOQUEADO** y se documenta que no hay señal de cliente muerto detectable.

**Resultado esperado**: fila `G6` con el body crudo y la rama A/B/C. Define la condición exacta de
`try_self_heal_dead_client()` en `T5.5`.

**Dependencias**: ninguna. Bloquea **`T5.5`** (detector exacto; la base del self-heal es idéntica en
ambas ramas).

**Trazabilidad**: REQ-CF-06; DR4/DR5; `design.md` §2.4/§14.

**Verificación**: `phase0-results.md` contiene `G6` y el body JSON del 400.

**Riesgo**: que la cuenta use `name`/`nameObject` en el error y no `client`. **Guard**: capturar el body
crudo, no interpretarlo; el regex es el fallback.

**Estimación**: M (1 h).

---

### T0.7 — G7: versión de WC del comerciante · BLOQUEA T2.1 (sólo el fallback)

**Objetivo**: confirmar que `wc_update_product_stock()` existe en el WooCommerce real del comerciante.
Decide si el escritor único usa la API recomendada o el fallback setter+save.

**Descripción técnica**: D5 (`design.md` §6.1) adopta `wc_update_product_stock($product, $qty, 'set',
false)` como único punto de escritura. El guard `function_exists()` cubre WC < 3.0 (DR12). La versión
del comerciante decide si el fallback es código muerto o camino real. Cubre **REQ-INV-04**.

**Desarrollo técnico**:

- **Paso 1 — comprobar la función:**

  ```bash
  wp eval 'var_dump(function_exists("wc_update_product_stock"));' --allow-root
  # o, si no hay WP-CLI:
  php -r 'define("WP_USE_THEMES", false); require "wp-load.php"; var_dump(function_exists("wc_update_product_stock"));'
  ```

- **Paso 2 — registrar la versión de WooCommerce** (contexto):

  ```bash
  wp plugin get woocommerce --field=version --allow-root
  ```

- **Paso 3 — registrar** en `phase0-results.md`: `function_exists=` y `wc_version=`.

- **Decision tree (fijar la rama):**
  - **Rama A — existe** (WC ≥ 3.0, prácticamente siempre): `T2.1` usa `wc_update_product_stock()`; el
    fallback queda como defensa para hosts viejos, no se testea en producción.
  - **Rama B — no existe** (WC < 3.0, improbable): `T2.1` activa el fallback
    `set_stock_quantity()+save()`; se documenta como camino real y se prioriza su test (`T29.21`).

**Resultado esperado**: fila `G7` con `function_exists=true|false` y `wc_version=`.

**Dependencias**: ninguna. Bloquea **`T2.1`** (sólo la decisión del fallback).

**Trazabilidad**: REQ-INV-04; DR12; `design.md` §4.2/§14.

**Verificación**: `phase0-results.md` contiene `G7` y el booleano.

**Riesgo**: correr `php -r` sin cargar WC da un falso `false`. **Guard**: usar `wp eval` o cargar
`wp-load.php`; si no, leer la versión de WC y asumir `true` para ≥ 3.0.

**Estimación**: S (0.25 h).

---

### T0.8 — G8: semántica de bodega (R7 del SDD `inventory`) · BLOQUEA T4.4

**Objetivo**: confirmar si existe semántica de bodega utilizable en Alegra para decidir si el poll se
hace warehouse-aware o se difiere con aviso en la UI.

**Descripción técnica**: el poll lee sólo `inventory.availableQuantity` total (`Products.php:1238`)
mientras el push escribe bodega (`:1118-1137`). REQ-INV-06 depende de R7 del SDD `inventory` (semántica
de bodega **SIN VERIFICAR**). El `design.md:1179-1183` ya decidió **diferir** salvo semántica clara; este
gate lo confirma con evidencia.

**Desarrollo técnico**:

- **Paso 1 — leer R7/REQ-WH-2** en `docs/sdd/inventory/` (design §R7, spec REQ-WH-2) y anotar la
  incógnita exacta.

- **Paso 2 — inspeccionar la respuesta real:**

  ```bash
  curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A20 '"inventory"'
  # Buscar inventory.warehouses: [{id, availableQuantity}]
  ```

- **Paso 3 — registrar** si hay `inventory.warehouses` con `availableQuantity` por bodega y si
  `warehouse_enabled`/`warehouse_id` están configurados en el plugin.

- **Decision tree (fijar la rama):**
  - **Rama A — hay semántica clara** (`warehouses[].availableQuantity` presente y documentada): se
    **puede** implementar warehouse-aware detrás de flag. `T4.4` deja de estar bloqueado y agrega la
    lectura por bodega (o se difiere con nota si el comerciante no la pide).
  - **Rama B — ausente/ambigua** (lo esperado): **DIFERIDO**. `T4.4` sólo agrega el aviso en Ajustes de
    que el poll usa el total; **no** se implementa la lectura por bodega.

**Resultado esperado**: fila `G8` con la rama A/B y la evidencia (`inventory.warehouses` o su ausencia).

**Dependencias**: ninguna. Bloquea **`T4.4`**.

**Trazabilidad**: REQ-INV-06; R7/REQ-WH-2 del SDD `inventory`; `design.md` §15/§14.

**Verificación**: `phase0-results.md` contiene `G8` y `warehouses=`.

**Riesgo**: que la cuenta no tenga bodegas y el resultado sea un falso "ausente". **Guard**: si no hay
bodega configurada en la cuenta, cerrar como **B (diferido)** igualmente (es el default del diseño).

**Estimación**: S (0.5 h).

---

## DoD de la Fase 0

- [ ] `docs/sdd/sync-reliability/phase0-results.md` existe (creado en `T0.1`) con una fila por gate.
- [ ] Cada gate tiene su **rama** registrada y su evidencia cruda (comando/HTTP/captura).
- [ ] `G1` define la rama de `T3.5` (o lo deja BLOQUEADO).
- [ ] `G3` define el default de `push_inventory_enabled` (`T1.7`/`T3.1`).
- [ ] `G6` define el detector exacto de `T5.5`.
- [ ] `G7` confirma el fallback de `T2.1`.
- [ ] Ningún gate escribió código de producción (READ-ONLY).
- [ ] Los `BLOQUEADO(Fase 0.x)` de `T3.5`, `T4.1` (tiempo real), `T3.1`/`T3.2`, `T7.1`, `T8.4`, `T5.5`,
      `T2.1` (fallback), `T4.4` están resueltos o explícitamente diferidos.
- [ ] **Fase 1 no esperó a ningún gate** (arrancó en paralelo).

# Especificación — Fiabilidad de sincronización: Consumidor Final, inventario y poll (`sync-reliability`)

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` (Consumidor Final honesto + inventario bidireccional + robustez del poll/cron) |
| Documento base | `docs/sdd/sync-reliability/proposal.md` |
| Formato | Requerimientos EARS (Cuando/Si → el sistema DEBE) con escenarios Given/When/Then verificables desde el WP admin o el archivo de log |
| Estados | `ACTIVO` · `BLOQUEADO(ON-VERIFICATION)` (espera Fase 0) · `FORK` (el diseño elige, el resultado es el especificado) |
| Versión analizada | 2.5.1 (`alegra-connector.php:6`) |
| Verificación | Cada `archivo:línea` fue re-verificado con lectura directa contra HEAD antes de escribir este documento |
| Relación | **Consume** requerimientos de `docs/sdd/inventory/` (no los duplica); **envuelve** `Write_Gate` de `config-gates`; comparte `Logger`/`Runs` de `logs-monitor-import` |

> **Reglas de lectura.**
> 1. Cada requerimiento tiene **al menos un escenario** verificable desde el WP admin o el archivo de
>    log. No hay escenarios que requieran mirar la base de datos.
> 2. Los `BLOQUEADO(ON-VERIFICATION)` no se cierran hasta tener el resultado de Fase 0; cada uno
>    declara su **rama A / rama B** y el **check exacto**.
> 3. Los `FORK` son requerimientos cuyo **resultado** es fijo pero cuyo **mecanismo** decide el
>    diseño (p. ej. FRONT B (a) ajuste vs (b) factura). El escenario verifica el resultado, no el
>    mecanismo.
> 4. Los claims de código citan `archivo:línea`; lo no verificado se marca **SIN VERIFICAR**.
> 5. Los IDs son estables y se citan desde `design.md` y `tasks.md`.
> 6. **Fortaleza RFC 2119:** **DEBE** = MUST/SHALL (obligatorio) · **DEBERÍA** = SHOULD
>    (recomendado) · **PUEDE** = MAY (opcional).
> 7. **No duplicar `docs/sdd/inventory/`.** Donde una capacidad ya está especificada allí, este
>    documento la **referencia** y declara si este cambio la **implementa** (ver §E).

---

## Correcciones de cita (re-verificadas)

La propuesta ya traía un bloque de correcciones (su §0). Al re-verificar **cada** cita contra el
código en HEAD, se confirma que **el resto de las citas es exacto**, con las siguientes
correcciones y matices. Se anotan porque `design.md`/`tasks.md` los consumen.

| Cita de la propuesta | Cita real verificada en HEAD | Nota |
|---|---|---|
| `Products.php:1611-1618` (rama de creación itera hijos) | **`Products.php:1613-1618`** | `:1611-1612` son el comentario; el `if ($is_variable)` arranca en `:1613`. Corregido. |
| `Public_.php:73,289` (cascada de push por `save()`) | **`Public_.php:73`** (hook) + **`:226-234`** (`on_update_product`) | `:73` registra `woocommerce_update_product`; el handler real es `:226-234` y **no** guarda con `is_syncing` (sólo con el transient `alegra_updating_product_` en `:229`). `:289` es el guard `is_syncing` de **otro** handler (`on_order_paid_reconcile`), no del push de productos. Corregido. |
| `set_syncing` "solo en import `Products.php:1542`" | **`Products.php:1542`** (true) + **`:1632`** (false); **también** `Customers.php:316,374` | El claim sobre el poll (no llama `set_syncing`) es correcto; la frase "solo en import" es imprecisa. Corregido. |
| `Products.php:2031-2034` (claim de que WC no deriva `_stock_status`) | **Confirmado** | Es el docblock de `apply_inventory_to_product()`; el claim está **REFUTADO** por el core de WC (ver REQ-INV-04). |
| `Controller.php:482` (lock `alegra_sync_running_products`, TTL 300) | **Confirmado** | La clave se arma en `Controller.php:475` (`'alegra_sync_running_' . $type`); el TTL 300 está en `:482`. |
| `Products.php:1253-1260` (bloque W2 del poll) | **Confirmado** | `set_stock_quantity` `:1255`, `set_stock_status` `:1259`, `save()` `:1260`. |
| `Consumidor_Final.php:44-67` / `:74-77` (peek / is_configured) | **Confirmado** | `peek_id()` `:44-67`; `is_configured()` `:74-77`. |
| `Consumidor_Final.php:480-491` (fallo silencioso) | **Confirmado** | `log_error()` `:480-491`; `:476-478` es el docblock. |
| `Orders.php:97-117` / `:119` / `:121-129` / `:1428-1435` | **Confirmado** | Pre-búsqueda de idempotencia `:97-117`; `create_invoice` POST `:119`; solo-loguea `:121-129`; `persist_contact_id()` `:1428-1435`. |

**Deriva ajena, detectada (no es cita de esta propuesta, pero conviene saberla):** el SDD hermano
`docs/sdd/logs-monitor-import/spec.md` afirma que `Runs::track` es la única llamada en
`Controller.php:150`. En HEAD, `:150` es un **comentario** y el run del cron se abre con
`Run_Context::wrap('cron_sync_all', …)` en **`Controller.php:153`**. No se toca en este cambio;
`tasks.md` de este SDD debe citar `:153` si necesita el contrato del run.

**Hechos confirmados por grep en producción (excluye `scripts/`):**

- `open_invoice_on_paid`, `inventory_manage_stock_enabled`, `inventory_dry_run`,
  `inventory_pull_cursor`, `Inventory_Writer` → **0 coincidencias**. El SDD `inventory` es
  DESIGNED-ONLY (propuesta §9, confirmado).
- `wc_update_product_stock` → **0 coincidencias**. La corrección de `_stock_status` no está hecha.
- `register_shutdown_function` en `Controller.php`/`Products.php` → **0 coincidencias**. Confirma C3.
- `DISABLE_WP_CRON` / `wp_doing_cron` en producción → **0 coincidencias**. Confirma C6.
- `.distignore:15` excluye `scripts/` del ZIP de release. Confirma la restricción §2.6.
- `Products.php:1149,1157,1163,1175,1211` ya implementan kill switch, fuente de inventario, lock,
  cancelación y manejo de `WP_Error` en el poll. **El SDD `inventory` los marca ACTIVO con
  evidencia stale (`Products.php:447-545`); en HEAD ya existen.** Ver §E.

---

## Convenciones

- **CF** = Consumidor Final (`includes/Consumidor_Final.php`).
- **Conectar** = acción explícita del comerciante que guarda credenciales y prueba la conexión:
  `Admin_Dashboard::ajax_test_connection` (`Admin_Dashboard.php:1638`), registrada en `:39`.
- **Poll** = `Products::sync_inventory_from_alegra()` (`Products.php:1142`).
- **Import** = `Products::import_from_alegra()` (`Products.php:1295`).
- **W1** = `Products::apply_inventory_to_product()` (`Products.php:2052-2100`).
- **W2** = bloque inline del poll (`Products.php:1253-1260`).
- **`Inventory_Writer`** = escritor único de `_manage_stock`/`_stock`/`_stock_status`
  (`includes/Sync/Inventory_Writer.php`), **a crear** (0 matches hoy). Definido en
  `docs/sdd/inventory/` REQ-DIV-1.
- **`preserve_fields`** = `alegra_connector_import_preserve_fields`
  (`Products::resolve_preserve_fields()`, `Products.php:1937-1941`).
- **Compuerta de escritura** = `Write_Gate` (`includes/Write_Gate.php`); contexto explícito =
  `Write_Gate::run_explicit()` (`:150`); compuerta por entidad `contact` = opción
  `alegra_connector_push_customers_enabled` (`:41`).
- **Lock del poll** = `alegra_sync_running_products`, TTL 300 s (`Controller.php:475,482`).
- **Lock global del cron** = `alegra_cron_global`, TTL 600 s (`Controller.php:141`).
- **Rate limiter** = 150 req/min compartido (`API/Client.php:954`).
- **Origen** (para logs): `manual` · `chunked` · `cron` · `webhook` (contrato de
  `logs-monitor-import/spec.md`).
- **`truncated`** = flag booleano en el resultado del poll que indica que quedó trabajo pendiente.
- **Catálogo grande** = catálogo que no entra en el presupuesto de una corrida (más de una página
  de 30 ítems).

---

## A. Consumidor Final — UI honesta y auto-sanación (FRONT A)

> No reabre `config-gates/REQ-RB-1` (el render ya usa `is_configured()`/`peek_id()` y **no** debe
> crear). Este cambio **agrega** la resolución en conectar, el estado honesto, "Verificar ahora",
> la auto-sanación y la seguridad del filtro CONTAINS.

### REQ-CF-01 — Conectar resuelve y cachea el CF bajo `run_explicit()` `ACTIVO`

**Cuando** el comerciante ejecuta la acción explícita de **conectar** (`ajax_test_connection`,
`Admin_Dashboard.php:1638`), el sistema **DEBE** resolver el CF dentro de un contexto
`Write_Gate::run_explicit()` y, si lo encuentra o lo crea, **DEBE** poblar la caché
(`Consumidor_Final::get_or_create_id()` / la ruta que elija el diseño). **NO DEBE** tocar el camino
de facturación: la resolución vive en la acción de conectar, no en `Orders::create_invoice()`.

Hoy conectar guarda credenciales y marca `alegra_connector_connection_tested=true`
(`Admin_Dashboard.php:1722`) pero **nunca** resuelve el CF → la caché queda vacía y el dashboard
dice "No encontrado" (`templates/admin-dashboard.php:20,139`).

```gherkin
Escenario: Conectar resuelve el CF y lo deja cacheado
  Dado el CF existente en Alegra y la caché del plugin vacía
  Cuando el comerciante completa el formulario y pulsa "Conectar"
  Entonces el plugin resuelve el CF bajo un contexto explícito
  Y la caché del CF queda poblada (la opción/transient tiene el id)
  Y el dashboard muestra "Consumidor Final: Disponible"

Escenario (negativo): el render del dashboard no resuelve ni crea
  Dado el CF sin cache
  Cuando se renderiza templates/admin-dashboard.php
  Entonces NO se emite ningún GET ni POST /contacts
  Y el estado se muestra como "No verificado" (ver REQ-CF-02)

Escenario (invariante): facturar no cambia por este requerimiento
  Dado un pedido con billing normal
  Cuando se factura desde "Pedidos"
  Entonces el flujo de factura es el de siempre
  Y la resolución del CF sólo ocurrió (si ocurrió) en la acción de conectar
```

**Evidencia:** `Admin_Dashboard.php:1638-1738` (handler, `:1722` marca conectado), `:39` (registro),
`Consumidor_Final.php:91-113` (`get_or_create_id`), `Write_Gate.php:150` (`run_explicit`).

---

### REQ-CF-02 — El dashboard distingue "No verificado" de "No encontrado" `ACTIVO`

**Cuando** el dashboard renderiza la fila del CF (`templates/admin-dashboard.php:130-147`), el
sistema **DEBE** mostrar un estado honesto con **tres** valores distinguibles:
(a) **Disponible** (cacheado); (b) **No verificado** (conectado pero nunca chequeado, o el chequeo
no pudo completarse); (c) **No encontrado** (un chequeo **completo** concluyó que no existe).
**NO DEBE** mostrar "No encontrado" cuando en realidad nunca se verificó.

Hoy el único estado "honesto" es "No verificado (sin conexión)" (`:135`, cuando `!$is_connected`);
con conexión y caché vacía muestra "No encontrado en Alegra" (`:139`) aunque el contacto exista.

```gherkin
Escenario: Conectado pero sin verificar muestra "No verificado", no "No encontrado"
  Dado alegra_connector_connection_tested=true
  Y la caché del CF vacía y ninguna verificación previa
  Cuando el comerciante abre el dashboard
  Entonces la fila del CF dice "No verificado" (o equivalente)
  Y NO dice "No encontrado en Alegra"
  Y ofrece la acción "Verificar ahora"

Escenario (borde): verificación fallida por red no miente
  Dado que la verificación del CF falla por error de red
  Cuando el comerciante abre el dashboard
  Entonces la fila dice "No verificado" (no "No encontrado")
  Y se ve un motivo accionable

Escenario: verificación completa sin contacto sí dice "No encontrado"
  Dado que un chequeo completo (sin truncación, ver REQ-CF-05) no halló el CF
  Cuando el comerciante abre el dashboard
  Entonces la fila dice "No encontrado en Alegra"
```

**Evidencia:** `templates/admin-dashboard.php:16-21,130-147` (`:135` no-conexión, `:139` no-encontrado),
`Consumidor_Final.php:74-77` (`is_configured`).

---

### REQ-CF-03 — "Verificar ahora" ejecuta la misma resolución explícita `ACTIVO`

**Cuando** el comerciante pulsa **"Verificar ahora"** en la fila del CF, el sistema **DEBE** correr
la **misma** resolución explícita que conectar (bajo `Write_Gate::run_explicit()`), **DEBE**
reportar el resultado en la UI (encontrado / no encontrado / no verificado) y **DEBE** dejar el
estado del dashboard actualizado sin recargar manualmente.

```gherkin
Escenario: Verificar ahora encuentra el CF
  Dado el CF existente en Alegra y la caché vacía
  Cuando el comerciante pulsa "Verificar ahora"
  Entonces la UI reporta que el CF está disponible
  Y la fila del dashboard pasa a "Disponible"

Escenario: Verificar ahora no lo encuentra y lo dice
  Dado que el CF no existe en Alegra (chequeo completo)
  Cuando el comerciante pulsa "Verificar ahora"
  Entonces la UI reporta "No encontrado" con la instrucción de crearlo
  Y NO se crea nada si el diseño eligió el modo read-only (ver REQ-CF-04)

Escenario (borde): Verificar ahora con red caída no miente
  Dado que la API de Alegra no responde
  Cuando el comerciante pulsa "Verificar ahora"
  Entonces la UI reporta "No verificado" con el motivo
  Y NO reporta "No encontrado"

Escenario (seguridad): el AJAX exige nonce y capacidad
  Dado un request sin nonce válido o sin manage_options
  Cuando se invoca la acción "Verificar ahora"
  Entonces la respuesta es un error de permiso
  Y no se emite ninguna llamada a Alegra
```

**Evidencia:** `templates/admin-dashboard.php:142-146` (acción actual sólo muestra instrucción),
`Admin_Dashboard.php:39` (patrón de registro de AJAX), `Write_Gate.php:150`.

---

### REQ-CF-04 — Conectar nunca crea el CF salvo pedido explícito `FORK`

**Cuando** el diseño elige **`resolve_readonly()`** (GET + match + caché, nunca POST), la acción de
conectar **NO DEBE** emitir `POST /contacts`; **SI** el diseño mantiene la creación, ésta **DEBE**
ocurrir **sólo** dentro de `run_explicit()` y **DEBE** respetar la compuerta de entidad `contact`
(`Write_Gate.php:41`; guard existente `Consumidor_Final.php:272-277`). En cualquier caso, el render
del dashboard **NO DEBE** crear (invariante de `config-gates/REQ-RB-1`).

**Rama A — `resolve_readonly()` (recomendada):** conectar sólo busca y cachea.
**Rama B — creación explícita:** conectar puede crear el CF bajo `run_explicit()`, nunca en automático.

```gherkin
Escenario (Rama A): conectar no crea el contacto
  Dado el CF inexistente en Alegra
  Y el diseño adoptó resolve_readonly()
  Cuando el comerciante pulsa "Conectar"
  Entonces se emite GET /contacts
  Y NO se emite POST /contacts
  Y el dashboard queda en "No encontrado" (verificado)

Escenario (Rama B): la creación explícita sí puede crear
  Dado el CF inexistente y el kill switch inactivo
  Y el diseño adoptó creación explícita
  Cuando el comerciante pulsa "Conectar" (o "Verificar ahora" + crear)
  Entonces se emite POST /contacts una vez
  Y la caché queda poblada

Escenario (invariante, ambas ramas): con kill switch activo no hay POST
  Dado el kill switch activo
  Cuando el comerciante intenta verificar/crear el CF
  Entonces NO se emite POST /contacts
```

**Evidencia:** `Consumidor_Final.php:263-277` (guard de creación), `:279-285` (`write_was_blocked`),
`Write_Gate.php:41,108-125` (`block_reason`), `:150` (`run_explicit`).

---

### REQ-CF-05 — El filtro CONTAINS no puede perder el CF en silencio `ACTIVO`

**Cuando** se resuelve el CF, el sistema **DEBE** garantizar que un resultado truncado del filtro
server-side `identification` (que en Alegra es **CONTAINS**, no igualdad) **no** se interprete como
"no existe" y **no** dispare una creación espuria. La verificación de igualdad exacta
(`Consumidor_Final::matches()`, `:241-251`) **DEBE** mantenerse, y el barrido **DEBE** cubrir todos
los candidatos del filtro (subir el `limit` y/o paginar) antes de concluir "no encontrado". Sólo un
barrido **completo** sin match exacto habilita el estado "No encontrado" y (si aplica) la creación.

Hoy se pide `limit=5` (`:181-184`); con falsos positivos CONTAINS que ocupen los 5 slots, el CF real
nunca se ve y el código cae en `create()` (`:219`) o en "No encontrado" (`templates/admin-dashboard.php:139`).

```gherkin
Escenario: Falsos positivos CONTAINS no hacen perder el CF real
  Dado que GET /contacts?identification=222222222222 devuelve 5 contactos que CONTIENEN el número
  Y el CF real está en el resultado 6 (fuera del primer lote)
  Cuando se resuelve el CF
  Entonces el barrido continúa (pagina o sube el limit)
  Y encuentra el CF real por igualdad exacta
  Y NO se crea un contacto duplicado

Escenario (borde): sólo un barrido completo concluye "no encontrado"
  Dado que el filtro no devuelve el CF en ningún lote
  Cuando termina el barrido completo
  Entonces el estado es "No encontrado" (verificado)
  Y el log registra que el barrido fue completo

Escenario (borde): una página llena no se toma como "fin"
  Dado que el último lote recibido tiene exactamente el tamaño de página
  Cuando se resuelve el CF
  Entonces se pide un lote más antes de concluir
```

**Evidencia:** `Consumidor_Final.php:181-184` (`limit=5`), `:201-213` (loop de match), `:241-251`
(`matches`), `:219` (create).

---

### REQ-CF-06 — Auto-sanar la caché podrida en un 400 por client id muerto `ACTIVO`

**Cuando** el POST de factura devuelve un **400 causado por un client id muerto** (el CF se borró o
cambió en Alegra), el sistema **DEBE** invalidar la caché del CF (`invalidate_cache()`,
`Consumidor_Final.php:392-397`), re-resolver el CF, y **reintentar la factura una sola vez**,
reusando la pre-búsqueda de idempotencia (`Orders::find_existing_invoice()`, `Orders.php:97-117`) para
**no duplicar** la factura. Si el reintento falla, **DEBE** dejar un mensaje accionable (nota de
pedido y/o log), nunca un fallo silencioso.

Hoy `Orders.php:121-129` sólo loguea el `WP_Error`; el meta `_billing_alegra_contact_id` se persiste
**antes** del POST (`prepare_invoice_data()` → `persist_contact_id()`, `Orders.php:1428-1435`; POST
en `:119`), así que un id muerto queda clavado en el pedido.

```gherkin
Escenario: 400 por cliente muerto invalida, re-resuelve y reintenta una vez
  Dado un pedido con _billing_alegra_contact_id apuntando a un contacto borrado en Alegra
  Cuando se factura el pedido y Alegra responde 400 por ese client id
  Entonces la caché del CF se invalida
  Y el CF se re-resuelve
  Y la factura se reintenta UNA vez
  Y la nota/log indica que se auto-sanó

Escenario (borde): no se duplica la factura si el primer POST sí entró
  Dado que el primer POST creó la factura pero la respuesta se perdió
  Cuando la auto-sanación corre
  Entonces find_existing_invoice() encuentra la factura existente
  Y NO se crea una segunda factura
  Y el pedido queda con el _alegra_invoice_id recuperado

Escenario (borde): reintento fallido deja mensaje accionable
  Dado que el CF no puede re-resolverse (contacto inexistente)
  Cuando falla el reintento
  Entonces el pedido registra una nota accionable
  Y el archivo de log contiene la causa
```

**Evidencia:** `Orders.php:119` (POST), `:121-129` (sólo log), `:97-117` (pre-búsqueda),
`:1428-1435` (`persist_contact_id`), `Consumidor_Final.php:392-397` (`invalidate_cache`),
`:226` (invalidate al fallar la creación).

---

### REQ-CF-07 — El fallo de resolución del CF es visible (fail-loud) `ACTIVO`

**Cuando** la resolución del CF falla, el sistema **DEBE** dejar señal **visible** para el
comerciante (estado "No verificado" en el dashboard y/o mensaje accionable), además de la entrada
de log. **NO DEBE** quedar sólo en el archivo de log.

Hoy `Consumidor_Final::log_error()` (`:480-491`) escribe **sólo** al log; la UI nunca se entera.

```gherkin
Escenario: Fallo de resolución visible en el dashboard
  Dado que la resolución del CF falla (red o 400)
  Cuando el comerciante abre el dashboard
  Entonces ve un estado "No verificado" con un motivo legible
  Y el archivo de log tiene la causa técnica

Escenario (negativo): resolución exitosa no genera alarma
  Dado que el CF se resuelve correctamente
  Cuando el comerciante abre el dashboard
  Entonces ve "Disponible"
  Y no aparece ningún aviso de fallo
```

**Evidencia:** `Consumidor_Final.php:480-491` (`log_error`), `templates/admin-dashboard.php:130-147`.

---

### REQ-CF-08 — Sin regresión en el camino de factura/cliente `ACTIVO`

**Cuando** se cierra este cambio, los flujos de **factura** y **cliente** **DEBEN** seguir con su
contrato y su comportamiento actuales, salvo la auto-sanación de REQ-CF-06. La resolución del CF
**NO DEBE** insertarse en `Orders::prepare_invoice_data()` ni en `Customers`.

```gherkin
Escenario (invariante): facturar un pedido normal no cambia
  Dado un pedido pagado con billing normal
  Cuando se factura
  Entonces el payload y el flujo son los de HEAD
  Y no hay llamadas nuevas a /contacts por la resolución de conectar

Escenario (invariante): el cliente existente se sigue resolviendo igual
  Dado un cliente con billing identificable
  Cuando se factura su pedido
  Entonces se resuelve el cliente como hoy
  Y la auto-sanación del CF sólo interviene ante un 400 por client id muerto
```

**Evidencia:** `Orders.php:63-150` (`create_invoice`), `:1072` (`prepare_invoice_data`),
`docs/sdd/inventory/REQ-INV-*` (facturación fuera de este FRONT).

---

## B. Inventario bidireccional — cerrar la re-inflación (FRONT B)

> **Este cambio implementa** los requerimientos de `docs/sdd/inventory/` que se citan en cada REQ
> (REQ-DIV-1, REQ-DIV-3, REQ-FAIL-4..6, REQ-GATE-1..2, REQ-WH-1/4, REQ-TYPE) y **no los duplica**.
> No reabre REQ-MIG (migración opt-in) ni R7 (bodegas).

### REQ-INV-01 — Una venta de WC se refleja en Alegra: fin de la re-inflación `FORK`

**Cuando** se completa una venta en WooCommerce (el stock de WC baja de N a N−k), el sistema **DEBE**
lograr que el stock del ítem correspondiente en **Alegra** refleje esa venta dentro de la misma
ventana de sincronización (≤ 1 ciclo de poll), de modo que una corrida posterior del poll **NO**
vuelva a subir el stock de WC por encima del valor vendido. El **resultado observable** es fijo;
el **mecanismo** lo elige el diseño:

- **(a)** ajuste WC→Alegra con **delta tracking** (`POST /inventory-adjustments`,
  `API/Client.php:770`); o
- **(b)** **abrir la factura** de Alegra al pagarse el pedido para que Alegra descuente de forma
  nativa (modelo "factura atada al pedido", `docs/sdd/inventory/REQ-INV-1`).

**Criterio de éxito observable:** tras vender k unidades, el stock de WC **nunca sube**; el stock de
Alegra (o la factura abierta) refleja la venta. **Nunca** se aplican (a) y (b) para el mismo
movimiento (ver REQ-INV-08).

Hoy el poll escribe **incondicionalmente** el valor de Alegra (`Products.php:1238-1260`) porque WC
nunca empuja stock (el objeto `inventory` sólo se envía en creación, `Products.php:1004-1015`;
`sync_simple_product()` pasa `$is_create=false` → `update_item`, `:217-221`).

```gherkin
Escenario (el titular): vender no resucita stock
  Dado un producto con stock 10 en WC y 10 en Alegra
  Cuando se venden 3 unidades en WC (stock WC=7)
  Y corre el poll de inventario
  Entonces el stock de WC NO sube (se mantiene en 7 o baja)
  Y el stock de Alegra refleja la venta (7), o existe la factura abierta que la descuenta

Escenario (Rama a): el ajuste WC→Alegra refleja el delta
  Dado un producto con stock 10 y el diseño en modo ajuste con delta
  Cuando se venden 3 unidades en WC
  Entonces se emite POST /inventory-adjustments con la diferencia
  Y Alegra queda en 7
  Y un poll posterior deja WC en 7

Escenario (Rama b): la factura abierta descuenta nativamente
  Dado un pedido pagado y el diseño en modo factura-atada
  Cuando se abre/crea la factura en Alegra
  Entonces el stock de Alegra baja por las unidades vendidas
  Y un poll posterior deja WC en el valor vendido

Escenario (borde): venta sin facturación configurada
  Dado push_orders_enabled=false y el diseño en modo (b)
  Cuando se vende en WC
  Entonces el sistema NO miente: reporta que el movimiento no llegó a Alegra
  Y la reconciliación opt-in (a) PUEDE cubrirlo si el comerciante la activa
```

**Evidencia:** `Products.php:1004-1015` (inventory sólo en create), `:217-221`
(`sync_simple_product`), `:1238-1260` (poll escribe sin delta), `API/Client.php:770`
(`create_inventory_adjustment`), `docs/sdd/inventory/REQ-DIV-3` (el stock no va por `PUT /items`).

**BLOQUEADO(ON-VERIFICATION) si el diseño elige (b):** ver §F (R1: ¿el borrador mueve stock?).

---

### REQ-INV-02 — Un solo escritor: `Inventory_Writer` `ACTIVO`

**Cuando** se cierra este cambio, **DEBE** existir exactamente **una** ruta de producción que
escriba `_manage_stock`/`_stock`/`_stock_status`: `includes/Sync/Inventory_Writer.php`. W1
(`apply_inventory_to_product()`, `Products.php:2052-2100`) y W2 (poll inline, `:1253-1260`)
**DEBEN** delegar en él, con **el mismo conjunto de compuertas** (fuente de inventario, tipo de
producto, servicio, nulos/negativos, `preserve_fields`, opt-out). Un grep de los setters **DEBE**
devolver matches sólo en `Inventory_Writer.php`.

Esto implementa `docs/sdd/inventory/REQ-DIV-1`; hoy hay **dos** escritores con compuertas
divergentes (W1 respeta `preserve_fields` en `:2006-2008`; W2 no).

```gherkin
Escenario (estático): un solo escritor
  Cuando se busca set_manage_stock|set_stock_quantity|set_stock_status en producción
  Entonces todos los matches están en includes/Sync/Inventory_Writer.php

Escenario: W1 y W2 delegan con las mismas compuertas
  Dado un producto con manage_stock=false y preserve_fields incluyendo inventory
  Cuando lo toca el import (W1) y el poll (W2)
  Entonces ninguno escribe stock directamente
  Y ambos pasan por Inventory_Writer::apply()

Escenario (borde): el kill switch sigue siendo autoridad
  Dado el kill switch activo
  Cuando corre el poll
  Entonces Inventory_Writer no escribe
  Y el resultado reporta skipped
```

**Evidencia:** W1 `Products.php:2052-2100`; W2 `:1253-1260`; grep `Inventory_Writer` = 0 hoy;
`docs/sdd/inventory/REQ-DIV-1`.

---

### REQ-INV-03 — El camino de UPDATE refresca el stock de variaciones `ACTIVO`

**Cuando** llega un webhook/import **UPDATE** de un `variantParent` (o de una variación), el sistema
**DEBE** refrescar el stock de cada variación hija por el camino de update. Hoy **sólo** la rama de
**creación** itera los hijos (`Products.php:1613-1618`), `type=variant` se saltea en el import por
ítem (`:1515-1517`) y en el chunked (`Admin_Dashboard.php:2316`), y
`update_product_from_alegra()` (`:1943`) no recorre los hijos → un cambio de stock de una variación
en Alegra **no** se refleja si el padre ya existe.

```gherkin
Escenario: Un cambio de stock de variación se refleja por update
  Dado un producto variable existente con la variación V vinculada a un item de Alegra
  Cuando Alegra cambia el stock de V y llega el webhook/import update del padre
  Entonces la variación V en WC recibe el nuevo stock
  Y no sólo se actualiza el padre

Escenario (borde): la creación sigue funcionando
  Dado un variantParent nuevo
  Cuando se importa por primera vez
  Entonces se crean las variaciones con su stock (comportamiento actual)

Escenario (borde): un `type=variant` no se saltea en silencio
  Dado que llega un item de tipo variant
  Cuando se procesa
  Entonces se aplica al hijo correspondiente (o se loguea por qué no)
  Y no se descarta sin señal
```

**Evidencia:** `Products.php:1613-1618` (create), `:1515-1517` (skip variant), `:1943`
(`update_product_from_alegra`), `Admin_Dashboard.php:2316`.

**BLOQUEADO(ON-VERIFICATION):** si `edit-item` dispara con un cambio **solo de stock** (§F) define si
el webhook da tiempo real o si el poll es el único camino; el requerimiento (refrescar en update)
aplica igual en ambos casos.

---

### REQ-INV-04 — `_stock_status` derivado y backorders respetados `ACTIVO`

**Cuando** se escribe stock, el sistema **NO DEBE** forzar `$qty > 0 ? 'instock' : 'outofstock'`
(`Products.php:1259`, `:2099`) ignorando `_backorders` y el umbral de no-stock. **DEBE** derivar el
estado con `wc_update_product_stock()` (que dispara hooks y deriva el estado) **o** respetar
`_backorders` + `_stock` + umbral. Con `backorders=no` el resultado **DEBE** ser idéntico al actual.

El docblock `Products.php:2031-2034` afirma que WC no deriva `_stock_status`; es **REFUTADO**:
`WC_Product::save()` llama a `validate_props()`
(`woocommerce/includes/abstracts/abstract-wc-product.php:1547`), que desde WC 3.0.0 deriva
`instock`/`onbackorder`/`outofstock` desde cantidad + backorders + umbral (`:1517-1538`).

```gherkin
Escenario: backorders=yes con stock 0 queda onbackorder
  Dado un producto con backorders=yes y stock 0
  Cuando el poll/import escribe el stock 0
  Entonces el _stock_status es 'onbackorder'
  Y NO 'outofstock'

Escenario: backorders=no conserva el comportamiento actual
  Dado un producto con backorders=no y stock 0
  Cuando el poll/import escribe el stock 0
  Entonces el _stock_status es 'outofstock'
  Y el resultado es idéntico al de HEAD

Escenario (borde): se disparan los hooks de stock de WC
  Dado que el diseño eligió wc_update_product_stock()
  Cuando se actualiza el stock
  Entonces se disparan los hooks estándar de WC de cambio de stock
  Y las integraciones que escuchan esos hooks reciben el evento
```

**Evidencia:** `Products.php:1259` y `:2099` (forzado), `:2031-2034` (claim refutado),
`abstract-wc-product.php:1517-1538,1547` (derivación del core).

---

### REQ-INV-05 — El poll honra `preserve_fields` `ACTIVO`

**Cuando** `inventory` está en `alegra_connector_import_preserve_fields`, el poll **NO DEBE** escribir
`_manage_stock`/`_stock`/`_stock_status`. Hoy el poll (`Products.php:1224-1260`) no chequea
`$preserve`; W1 sí lo hace (`:2006-2008`), así que la configuración "preservar inventario" se
respeta en el import pero **no** en el poll.

```gherkin
Escenario: preserve inventory detiene al poll
  Dado alegra_connector_import_preserve_fields = ['inventory']
  Y un producto WC con stock 7
  Y Alegra reporta availableQuantity=10
  Cuando corre el poll
  Entonces el producto WC sigue con stock 7
  Y el resultado no cuenta ese ítem como actualizado

Escenario (negativo): sin preservar, el poll escribe
  Dado que 'inventory' NO está en preserve_fields
  Y un producto WC con stock 7
  Y Alegra reporta availableQuantity=10
  Cuando corre el poll
  Entonces el producto WC queda en stock 10
```

**Evidencia:** `Products.php:1224-1260` (poll sin `$preserve`), `:1937-1941`
(`resolve_preserve_fields`), `:2006-2008` (W1 sí respeta).

---

### REQ-INV-06 — Poll warehouse-aware (flag o diferido) `BLOQUEADO(ON-VERIFICATION)`

**SI** el diseño incluye el modo warehouse-aware, **CUANDO** `warehouse_enabled=true` y hay bodega
elegida, el poll **DEBE** leer `inventory.warehouses[id==warehouse_id].availableQuantity` (la misma
bodega que el push escribe, `Products.php:1118-1137`), no sólo el total. **SI** el diseño lo
difiere, **DEBE** declararlo explícitamente y la UI **DEBE** advertir que el poll lee el total.

Hoy el poll lee sólo `inventory.availableQuantity` (`Products.php:1238`). Esto depende de R7 del SDD
`inventory` (semántica de bodega ausente), **SIN VERIFICAR** (ver §F).

```gherkin
Escenario (si se implementa): se lee la bodega elegida
  Dado warehouse_enabled=true y warehouse_id='3'
  Y el item tiene warehouses=[{id:'1',availableQuantity:'20'},{id:'3',availableQuantity:'150'}]
  Y inventory.availableQuantity=170
  Cuando el poll procesa el item
  Entonces WC recibe stock 150

Escenario (si se difiere): la UI lo declara honestamente
  Dado que el diseño difirió warehouse-aware
  Cuando el comerciante abre Ajustes con bodega configurada
  Entonces ve una advertencia de que el poll usa el total
  Y no cree que la bodega está siendo respetada
```

**Evidencia:** `Products.php:1238` (lee total), `:1118-1137` (push escribe bodega),
`docs/sdd/inventory/REQ-WH-1/4` y R7.

---

### REQ-INV-07 — Informe de divergencia (flag/opcional) `FORK`

**CUANDO** el diseño habilita el informe de divergencia (o cuando `push_orders_enabled=false` y hay
ventas sin factura), el sistema **DEBE** listar los pedidos vendidos sin `_alegra_invoice_id` y
advertir que el stock de Alegra no refleja esas ventas. Esto implementa
`docs/sdd/inventory/REQ-DIV-4`.

```gherkin
Escenario: Se listan las ventas no facturadas
  Dado push_orders_enabled=false
  Y 3 pedidos en 'processing' sin _alegra_invoice_id
  Cuando el comerciante abre el informe de divergencia
  Entonces ve los 3 pedidos
  Y una advertencia de que el stock de Alegra no refleja esas ventas

Escenario (negativo): sin divergencia no alarma
  Dado que todos los pedidos vendidos tienen factura
  Cuando se abre el informe
  Entonces no se listan pedidos
  Y no aparece advertencia
```

**Evidencia:** `Orders.php:63-150` (`create_invoice`), `docs/sdd/inventory/REQ-DIV-4` (BLOQUEADO por R1).

---

### REQ-INV-08 — Nunca (a) y (b) para el mismo movimiento (no doble conteo) `ACTIVO`

**Cuando** el diseño combina (a) ajuste y (b) factura, el sistema **DEBE** garantizar que un mismo
movimiento de venta se refleje **una sola vez** en Alegra. **NO DEBE** aplicar el ajuste WC→Alegra
sobre un movimiento ya descontado por la factura (doble-descuento), ni al revés.

```gherkin
Escenario: No hay doble descuento
  Dado el diseño con (b) como primario y (a) como reconciliación opt-in
  Cuando se vende una unidad y la factura la descuenta
  Entonces el ajuste (a) NO se emite para esa misma unidad
  Y el stock de Alegra baja exactamente 1

Escenario (borde): la reconciliación (a) tiene su propio lock e idempotencia
  Dado que (a) corre como reconciliación
  Cuando dos corridas solapan
  Entonces una sola aplica el delta
  Y la otra se saltea por lock
```

**Evidencia:** `API/Client.php:770` (`create_inventory_adjustment`), `docs/sdd/inventory/REQ-DIV-1/3`,
propuesta §5.2 y §8.1.

---

## C. Robustez del poll / cron (FRONT C)

> El poll ya implementa kill switch, fuente de inventario, lock y manejo de `WP_Error`
> (`Products.php:1149,1157,1163,1211`). Este FRONT agrega **presupuesto, cursor, tope, flag
> `truncated`, liberación del lock, `set_syncing`, cron real y la garantía de no bloquear el
> checkout**. Comparte `Logger`/`Runs` con `logs-monitor-import` (fail-loud).

### REQ-POLL-01 — Presupuesto de wall-clock + cursor persistido `ACTIVO`

**Cuando** corre el poll (`sync_inventory_from_alegra()`, `Products.php:1142`), el sistema **DEBE**
respetar un **presupuesto de wall-clock configurable** y persistir un **cursor** de reanudación.
**NO DEBE** arrancar siempre en la página 1 (`:1174`) ni depender de `set_time_limit(300)`
(`:1172`). El patrón es el del import (`:1305-1322`: cursor `:1305`, budget `:1318`, deadline `:1322`).

```gherkin
Escenario: Un catálogo grande pausa por presupuesto y reanuda
  Dado un catálogo que no entra en el presupuesto de una corrida
  Cuando corre el poll
  Entonces se detiene al agotar el presupuesto (no por fatal 500)
  Y persiste el cursor de la última página completa
  Y el log registra la pausa con el cursor

Escenario: La próxima corrida arranca desde el cursor
  Dado un poll que quedó pausado en la página 5
  Cuando corre el poll de nuevo
  Entonces la primera llamada GET /items usa start del cursor
  Y no reinicia en la página 1

Escenario (borde): un poll completo limpia el cursor
  Dado un catálogo que entra en el presupuesto
  Cuando el poll termina
  Entonces el cursor queda limpio
  Y no se reporta pausa
```

**Evidencia:** `Products.php:1142`, `:1171-1174`, `:1172` (`set_time_limit(300)`),
`:1305-1322` (patrón del import).

---

### REQ-POLL-02 — Tope de lote configurable + flag `truncated` + log explícito `ACTIVO`

**Cuando** el poll alcanza el tope de lote/página **configurable** sin terminar el catálogo, el
resultado **DEBE** incluir `truncated=true` y **DEBE** escribir un log explícito con cuántos ítems
quedaron pendientes. **NO DEBE** truncar en silencio. Hoy el tope es fijo (200 páginas, `:1171`), el
resultado no tiene `truncated` (`:1144`) y la salida por `count($items) < 30` (`:1282-1284`) no
distingue "fin" de "corte".

```gherkin
Escenario: Truncación reportada y logueada
  Dado un catálogo de más de (tope × 30) ítems
  Cuando el poll alcanza el tope de lote
  Entonces el resultado tiene truncated=true
  Y el log indica que quedó trabajo pendiente y cuántos ítems
  Y el cursor permite continuar la próxima corrida

Escenario (negativo): un poll completo no marca truncated
  Dado un catálogo que entra en el tope
  Cuando el poll termina
  Entonces truncated es false
  Y el log no reporta truncación

Escenario (borde): el tope es configurable desde la UI/opción
  Dado un comerciante que baja el tope de lote
  Cuando corre el poll
  Entonces el corte respeta el valor configurado
  Y no un 200 fijo
```

**Evidencia:** `Products.php:1144` (result sin `truncated`), `:1171` (`max_pages=200`),
`:1282-1284` (salida por página incompleta).

---

### REQ-POLL-03 — El lock se libera ante un fatal (o TTL menor) `ACTIVO`

**Cuando** un error fatal interrumpe el poll, el lock `alegra_sync_running_products`
(TTL 300 s, `Controller.php:475,482`) **DEBE** liberarse, vía `register_shutdown_function` **o** un
TTL suficientemente corto, para que el próximo poll corra. Hoy un fatal saltea el `finally`
(`Products.php:1290-1292`) y deja el lock tomado hasta 300 s.

```gherkin
Escenario: Un fatal no deja el lock colgado
  Dado un poll en curso que sufre un fatal de PHP
  Cuando termina el request
  Entonces el lock 'alegra_sync_running_products' queda liberado (o con TTL corto)
  Y el próximo poll corre sin esperar 300 s

Escenario: La liberación normal sigue funcionando
  Dado un poll que termina normalmente
  Cuando sale
  Entonces el lock se libera en el finally
  Y el log no reporta lock colgado

Escenario (borde): el lock global del cron no se solapa
  Dado el lock global 'alegra_cron_global' (TTL 600 s, Controller.php:141)
  Cuando una corrida excede los 600 s
  Entonces no se produce un segundo run solapado
  O se reporta el solapamiento
```

**Evidencia:** `Products.php:1290-1292` (finally), `Controller.php:475,482` (lock/TTL),
`:141` (lock global 600 s), grep `register_shutdown_function` = 0.

---

### REQ-POLL-04 — `set_syncing(true/false)` alrededor del poll (sin cascada) `ACTIVO`

**Cuando** corre el poll, el sistema **DEBE** llamar `Public_::set_syncing(true)` al entrar y
`set_syncing(false)` al salir (en un `finally`). Con `push_products_enabled`, cada `save()` del poll
dispara `woocommerce_update_product` (`Public_.php:73` → `on_update_product`, `:226-234`) y produce
un POST por ítem; el guard `is_syncing` corta esa cascada. Hoy sólo el import lo hace
(`Products.php:1542,1632`; también `Customers.php:316,374`).

```gherkin
Escenario: Sin cascada de push durante el poll
  Dado push_products_enabled=true
  Cuando corre el poll y guarda N productos
  Entonces NO se emite un POST a Alegra por cada ítem
  Y el número de requests a Alegra no crece con el tamaño del catálogo

Escenario: El flag se resetea aunque haya error
  Dado un poll que falla a mitad
  Cuando termina el request
  Entonces set_syncing queda en false
  Y el próximo flujo normal no queda bloqueado

Escenario (borde): un import anidado no rompe el flag
  Dado que el poll corre dentro de un contexto ya syncing
  Cuando termina
  Entonces no apaga el flag del contexto externo
```

**Evidencia:** `Products.php:1542,1632` (import), `Public_.php:73,226-234` (hook/handler sin
`is_syncing`), `:289` (contraste: otro handler sí guarda), `:426-444` (`set_syncing`/`is_syncing`).

---

### REQ-POLL-05 — Recomendación de cron real en Ajustes `ACTIVO`

**Cuando** el comerciante abre la página de Ajustes, el sistema **DEBE** mostrar la recomendación de
cron real: `DISABLE_WP_CRON` + una línea de crontab concreta, para que el poll no dependa del
tráfico del visitante. Hoy hay **0** coincidencias de `DISABLE_WP_CRON`/`wp_doing_cron` en
producción.

```gherkin
Escenario: Ajustes muestra la recomendación de cron real
  Cuando el comerciante abre Ajustes (pestaña Sincronización)
  Entonces ve la constante DISABLE_WP_CRON con su valor recomendado
  Y ve una línea de crontab copiable con la ruta real del sitio
  Y una explicación de por qué conviene (no depender del tráfico)

Escenario (borde): con cron real configurado no alarma en falso
  Dado que el comerciante ya usa un cron real
  Cuando abre Ajustes
  Entonces la recomendación se muestra como informativa
  Y no como un error
```

**Evidencia:** grep `DISABLE_WP_CRON`/`wp_doing_cron` = 0; `templates/admin-settings.php:66-77`
(sección de sincronización), `Admin_Dashboard.php:4209` (`spawn_cron`).

---

### REQ-POLL-06 — El poll no bloquea checkout ni facturación (invariante con test) `ACTIVO`

**Cuando** corre el poll, el sistema **DEBE** ejecutarlo fuera del request del visitante (WP-Cron
`spawn_cron()` o cron real) y **NO DEBE** bloquear el checkout ni la facturación. Es un **invariante
declarado** y **DEBE** tener un test. El rate limiter (150 req/min, `API/Client.php:954`) es
compartido: el presupuesto del poll (REQ-POLL-01) **DEBE** reducir la contención con la facturación.

```gherkin
Escenario: Checkout durante un poll no se bloquea
  Dado un poll en curso
  Cuando un cliente completa un checkout
  Entonces el checkout completa con latencia normal
  Y no espera a que el poll termine

Escenario: Facturación no falla por rate limit del poll
  Dado un poll en curso con presupuesto acotado
  Cuando se factura un pedido
  Entonces la factura se crea (o reintenta) sin un 429 no manejado
  Y el poll no agota la cuota compartida

Escenario (estático): el poll no corre inline en el request del visitante
  Entonces el poll se dispara por WP-Cron spawn o cron real
  Y no por un hook de página/checkout
```

**Evidencia:** `Admin_Dashboard.php:4209` (`spawn_cron`), `API/Client.php:954` (rate limit),
`Products.php:1142` (entry point del poll).

---

### REQ-POLL-07 — Action Scheduler (flag) `FORK`

**SI** Action Scheduler está disponible, el diseño **PUEDE** mover el poll a lotes encadenados; **SI
no** está disponible, **DEBE** mantener WP-Cron como fallback. La ausencia de AS **NO DEBE** romper
el poll.

```gherkin
Escenario (si AS está disponible): lotes encadenados
  Dado que Action Scheduler está activo
  Cuando el poll encuentra un catálogo grande
  Entonces encadena lotes por AS
  Y cada lote respeta el presupuesto y el cursor

Escenario (fallback): sin AS, WP-Cron sigue funcionando
  Dado que Action Scheduler no está disponible
  Cuando corre el cron
  Entonces el poll corre por WP-Cron con presupuesto y cursor
  Y no se produce un fatal por la ausencia de AS
```

**Evidencia:** `uninstall.php:221` (única mención de Action Scheduler, en limpieza),
`Admin_Dashboard.php:4209` (spawn_cron).

---

## D. Requerimientos no funcionales

### NFR-01 — Sin regresión en facturación, pagos y webhooks `ACTIVO`

**Cuando** se cierra el cambio, los flujos de **factura**, **pago** y **webhook** **DEBEN** seguir
funcionando con su contrato actual. La auto-sanación del CF (REQ-CF-06) **DEBE** ser la única
alteración del camino de factura y **NO DEBE** duplicar facturas.

```gherkin
Escenario: La factura normal sigue igual
  Dado un pedido pagado con billing normal
  Cuando se factura
  Entonces el payload/flujo son los de HEAD
  Y no hay requests nuevos por la auto-sanación

Escenario: El webhook sigue procesando
  Dado un webhook 'new-item' válido
  Cuando se procesa
  Entonces el ítem se importa como hoy

Escenario: El pago se sigue registrando
  Dado un pedido con factura vinculada
  Cuando se completa el pago
  Entonces se registra el pago como hoy
```

**Evidencia:** `Orders.php:63-150`, `Public_.php:287-307` (`on_order_paid_reconcile`),
`docs/sdd/logs-monitor-import/NFR-02`.

---

### NFR-02 — El poll no aumenta la latencia del sitio (pool PHP-FPM) `ACTIVO`

**Cuando** corre el poll, **NO DEBE** ocupar un worker PHP-FPM por minutos sin cota: el presupuesto
de wall-clock (REQ-POLL-01) **DEBE** acotar la duración de cada corrida y el cursor **DEBE** permitir
reanudar. El poll **NO DEBE** correr en el request del visitante (REQ-POLL-06).

```gherkin
Escenario: Cada corrida del poll está acotada
  Dado un catálogo grande y un host con pool FPM chico
  Cuando corre el poll
  Entonces cada corrida termina dentro del presupuesto
  Y no deja un worker ocupado indefinidamente

Escenario: La latencia del sitio no se degrada
  Dado un poll en curso
  Cuando un visitante navega la tienda
  Entonces la latencia se mantiene en el rango normal
```

**Evidencia:** `Products.php:1142,1172`, `Admin_Dashboard.php:4209` (spawn no bloqueante).

---

### NFR-03 — Plugin distribuido: sin supuestos de host `ACTIVO`

**Cuando** el plugin corre en cualquier host, el poll **DEBE** funcionar aunque el host ignore
`set_time_limit`, no haya cron real ni Action Scheduler, y el pool FPM sea chico. La UI **DEBE**
degradar con honestidad ("no verificado") cuando no puede comprobar.

```gherkin
Escenario: El poll funciona con set_time_limit ignorado
  Dado un host que ignora set_time_limit
  Cuando corre el poll en un catálogo grande
  Entonces el corte es por presupuesto propio + cursor
  Y no depende de extender el límite

Escenario: La UI degrada con honestidad
  Dado un host donde la verificación del CF no puede completarse
  Cuando el comerciante abre el dashboard
  Entonces ve "No verificado"
  Y no un estado inventado
```

**Evidencia:** `Products.php:1172` (set_time_limit), propuesta §3.3, `logs-monitor-import/NFR-05`.

---

### NFR-04 — Compatibilidad hacia atrás para una instalación existente `ACTIVO`

**Cuando** se actualiza una instalación existente, las **opciones nuevas** (budget/tope/cursor del
poll, flag de reconciliación, etc.) **DEBEN** sembrarse con defaults seguros **sin pisar** valores
elegidos, siguiendo el patrón `Write_Gate::maybe_migrate()` (`Write_Gate.php:176`) y el loop de
defaults (`alegra-connector.php:488-492`). Los contratos de los AJAX **DEBEN** mantener
`success`/`data` y **DEBEN** agregar `message` legible. Los cambios intencionales de comportamiento
(honrar `preserve_fields` en el poll, derivar `_stock_status`) **DEBEN** documentarse en
`CHANGELOG.md`.

```gherkin
Escenario: Una instalación existente no cambia de comportamiento por los defaults
  Dado un sitio actualizado con opciones ya configuradas
  Cuando corre el plugin
  Entonces las opciones nuevas toman defaults seguros
  Y ningún valor elegido por el comerciante se sobrescribe

Escenario: El contrato AJAX se mantiene
  Dado un consumidor que hoy lee r.success y r.data
  Cuando responde el AJAX de verificación
  Entonces success y data siguen presentes
  Y message está presente en ambas ramas

Escenario (borde): el cambio intencional está documentado
  Entonces CHANGELOG.md describe que el poll ahora honra preserve_fields
  Y que _stock_status se deriva respetando backorders
```

**Evidencia:** `Write_Gate.php:176-214` (`maybe_migrate`), `alegra-connector.php:425-492`
(defaults/non-autoload), `Products.php:1937-1941` (preserve).

---

### NFR-05 — El ZIP de release excluye `scripts/` `ACTIVO`

**Cuando** se construye el ZIP de release, **NO DEBE** contener `scripts/` ni tests/harness. La
exclusión está en `.distignore:15` y la aplica `scripts/build-release.sh`.

```gherkin
Escenario (estático): el ZIP no contiene scripts
  Cuando se construye el release
  Entonces el ZIP no incluye scripts/
  Y no incluye tests/ ni harness
  Y .distignore sigue excluyendo scripts/
```

**Evidencia:** `.distignore:15`, `scripts/build-release.sh`, propuesta §2.6.

---

### NFR-06 — Seguridad: compuertas y sin secretos en logs `ACTIVO`

**Cuando** se ejecutan las acciones nuevas (verificar/crear CF, auto-sanación, ajuste de inventario),
el sistema **DEBE** respetar `Write_Gate` (kill switch + entidad) y **DEBE** exigir nonce y capacidad
en cada AJAX. Los logs **NO DEBEN** exponer token ni credenciales.

```gherkin
Escenario: La compuerta sigue siendo autoridad
  Dado el kill switch activo
  Cuando se dispara cualquier escritura nueva (CF o ajuste)
  Entonces no se emite ningún POST a Alegra
  Y se registra el motivo

Escenario: Los AJAX nuevos exigen nonce y capacidad
  Dado un request sin nonce o sin permiso
  Cuando se invoca "Verificar ahora" u otra acción nueva
  Entonces la respuesta es un error de permiso
  Y no se toca la API

Escenario (borde): los logs no filtran el token
  Cuando se escribe una entrada de log con contexto de API
  Entonces el token/credenciales no aparecen en el texto del log
```

**Evidencia:** `Write_Gate.php:108-125`, `Admin_Dashboard.php:1640-1646` (nonce/capacidad),
`docs/sdd/logs-monitor-import/NFR-07`.

---

### NFR-07 — Idempotencia y ausencia de efectos duplicados `ACTIVO`

**Cuando** se reintenta una operación (auto-sanación del CF, ajuste de inventario, poll reanudado),
el sistema **NO DEBE** producir efectos duplicados (factura doble, doble descuento de stock,
re-procesamiento de páginas).

```gherkin
Escenario: La auto-sanación no duplica facturas
  Dado un POST que entró en Alegra pero cuya respuesta se perdió
  Cuando corre la auto-sanación
  Entonces find_existing_invoice() recupera la factura
  Y no se crea una segunda

Escenario: El ajuste de inventario es idempotente
  Dado un delta ya aplicado
  Cuando se reintenta el ajuste
  Entonces no se aplica dos veces

Escenario (borde): reanudar el poll no reprocesa
  Dado un poll pausado con cursor
  Cuando reanuda
  Entonces no reprocesa las páginas ya completadas
```

**Evidencia:** `Orders.php:97-117`, `API/Client.php:770`, `Products.php:1305-1322` (patrón de cursor).

---

## E. Trazabilidad (requerimiento → propuesta)

> La propuesta no tiene una sección literal "What Changes"; el mapeo apunta a **§3.1 Alcance
> (dentro del alcance)**, a los **hallazgos §4 (A1-A8, B1-B9, C1-C7)** y al **enfoque §5**.

| Grupo | Requerimientos | §3.1 (Alcance) | Hallazgos §4 | Enfoque §5 |
|---|---|---|---|---|
| A — Consumidor Final | REQ-CF-01..08 | Resolver CF en conectar bajo `run_explicit`; estado honesto; "Verificar ahora"; auto-sanar Caso D; `resolve_readonly()` | A1, A2, A3, A4, A5, A6, A7, A8 | 5 |
| B — Inventario | REQ-INV-01..08 | Cerrar re-inflación (a/b); `Inventory_Writer`; variaciones en update; `_stock_status`/backorders; `preserve_fields`; warehouse-aware; informe de divergencia | B1, B2, B3, B4, B5, B6, B7, B8, B9 | 1, 2 |
| C — Poll/cron | REQ-POLL-01..07 | Budget + cursor; tope + `truncated` + log; lock en shutdown; `set_syncing`; Action Scheduler; cron real en UI | C1, C2, C3, C4, C5, C6, C7 | 3, 4, 6, 7 |
| D — No funcionales | NFR-01..07 | §3.3 Restricción transversal (distribuido, sin regresión, ZIP sin `scripts/`) | §6 Riesgo / compatibilidad | 5, 6 |

### Cobertura de los requerimientos de `docs/sdd/inventory/` (consumidos, no duplicados)

| Requerimiento `inventory` | Estado en HEAD | Este cambio |
|---|---|---|
| REQ-DIV-1 (un solo escritor) | No implementado (`Inventory_Writer` = 0) | **REQ-INV-02** lo implementa |
| REQ-DIV-3 (push no reenvía `inventory` en update) | Implementado (`Products.php:986-1015`) | Se respeta; **REQ-INV-01** usa el camino documentado (`/inventory-adjustments`) |
| REQ-FAIL-4..6 (error de API, reanudable, truncación) | Parcial: error de API y cancelación sí (`:1211,1175`); cursor/`truncated` **no** | **REQ-POLL-01/02** |
| REQ-GATE-1..2 (kill switch, lock/cancelación) | Implementado (`:1149,1163,1175`) | Se consume; **REQ-INV-02** unifica compuertas |
| REQ-WH-1/4 (bodega leída = bodega escrita) | No implementado en el poll (`:1238` lee total) | **REQ-INV-06** (flag/diferido) |
| REQ-TYPE-1..6 (tipos de producto) | Parcial (W1 distingue variable/servicio) | **REQ-INV-02/03** unifican y refrescan variaciones |
| REQ-INV-1/2 (factura al pagar) | No implementado (`open_invoice_on_paid` = 0) | **REQ-INV-01 rama (b)** si el diseño la elige |
| REQ-DIV-4 (informe de divergencia) | No implementado | **REQ-INV-07** (flag/opcional) |

### Decisiones del comerciante (§2 de la propuesta) → requerimiento

| Decisión | Requerimiento |
|---|---|
| 1. Dashboard profesional y sin ambigüedades | REQ-CF-02, REQ-CF-03, REQ-CF-07 |
| 2. Nada de chapuzas | REQ-CF-05, REQ-INV-02, REQ-POLL-01..03 |
| 3. Todo funcional sin bugs ni conflictos | REQ-INV-01, REQ-INV-08, NFR-01, NFR-07 |
| 4. Que funcione en cualquiera (distribuido) | NFR-02, NFR-03, REQ-POLL-07 |
| 5. Sin DIAN/facturación electrónica | Fuera de alcance (§3.2); REQ-CF-08 lo preserva |
| 6. El ZIP excluye `scripts/` | NFR-05 |

---

## F. Bloqueados por verificación

Cada bloqueo declara el **check exacto** de Fase 0 y sus **ramas**. Ningún `BLOQUEADO` se cierra
sin el resultado.

| Requerimiento | Incógnita | Check exacto | Rama A | Rama B |
|---|---|---|---|---|
| REQ-INV-01 (rama b) | **R1: ¿un borrador (`draft`) mueve stock en Alegra y `open` sí?** | En la cuenta del comerciante: crear una factura `draft` por un ítem con stock conocido y observar el stock; luego abrirla (`open`) y observar. Alternativa: doc de Alegra `POST /invoices` / `open`. | El borrador **no** mueve stock y `open` sí → (b) es viable como primario | El borrador **sí** mueve stock → (b) se replantea; (a) pasa a primario |
| REQ-INV-03 | **¿`edit-item` dispara con un cambio SOLO de stock?** | Cambiar únicamente el stock de un ítem en Alegra y observar el webhook en el Recorder (`templates/admin-webhooks.php`) / la tabla de eventos. | `edit-item` **sí** dispara → el webhook da tiempo real y refresca variaciones | `edit-item` **no** dispara → el poll (REQ-POLL-01) es el único camino; el update debe cubrir variaciones igual |
| REQ-INV-06 | **R7: semántica de bodega ausente** (`docs/sdd/inventory/REQ-WH-2`) | Ver `docs/sdd/inventory/` R7. | Se implementa warehouse-aware | Se difiere y la UI lo declara (REQ-INV-06 escenario 2) |
| REQ-POLL-02 | **Tamaño real del catálogo del comerciante** (para el default del tope) | Contar ítems activos en Alegra (`GET /items` paginado o el contador de Alegra) y estimar el tope seguro. | Tope bajo (varias corridas) | Tope alto (una corrida) |
| REQ-POLL-05 | **¿El host del comerciante corre un cron real?** | Revisar `DISABLE_WP_CRON`, `wp cron event list` y el panel del hosting. | Ya usa cron real → recomendación informativa | WP-Cron por tráfico → recomendación accionable + copia de crontab |

### Cerrados / moot

| Incógnita | Estado | Motivo |
|---|---|---|
| Host real del CDN de imágenes | **MOOT** | La versión 2.5.1 hizo la allowlist **permisiva por defecto** (`alegra_connector_restrict_image_hosts` default `false`, `alegra-connector.php:443`); el bloqueo por host dejó de ser el default. No afecta este cambio. |
| Filtro `identification` es CONTAINS | **Confirmado por doc** (propuesta A4) | `matches()` (`Consumidor_Final.php:241-251`) ya exige igualdad exacta; REQ-CF-05 agrega la cobertura del lote. |
| `Runs::track` único en `Controller.php:150` | **Corregido** | En HEAD es `Run_Context::wrap` en `:153`; ver §Correcciones. No afecta requerimientos de este cambio. |

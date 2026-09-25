# Propuesta — Fiabilidad de sincronización: Consumidor Final, inventario y poll (`sync-reliability`)

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` (Consumidor Final honesto + inventario bidireccional + robustez del poll/cron) |
| Tipo | Corrección de bugs en producción (sobreventa) + honestidad de UI + endurecimiento de procesos largos |
| Versión analizada | 2.5.1 (`alegra-connector.php:6`) |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Estado | Propuesta — **el bug de sobreventa (FRONT B) es el titular**; quedan verificaciones en vivo (Fase 0) |
| Regla | Todo claim de código cita `archivo:línea`; lo no verificado se marca **SIN VERIFICAR / BLOQUEADO** |

> **Correcciones de cita al diagnóstico de entrada.** El diagnóstico venía con rangos aproximados;
> tras verificar con lectura directa, las correcciones son:
>
> - `Consumidor_Final.php:44-77` (peek) → `peek_id()` real es **`:44-67`** e `is_configured()` es
>   **`:74-77`**. El rango citado abarca ambos; se citan por separado.
> - `Consumidor_Final.php:476-490` (fallo silencioso) → `log_error()` real es **`:480-491`**;
>   `:476-478` es el docblock. El fallo va **solo al archivo de log**, nunca a la UI.
> - `Products.php:1255` (W2 escritor del poll) → el bloque de escritura real es **`:1253-1260`**
>   (`set_stock_quantity` en `:1255`, `set_stock_status` en `:1259`, `save()` en `:1260`). El método
>   `sync_inventory_from_alegra()` arranca en **`:1142`**.
> - `commit a5323bb (v2.4.0)` → **confirmado**: `git tag --contains` lo ubica en `v2.4.0`. Matiz: el
>   header de versión **en ese commit todavía decía `2.3.11`**; el bump a 2.4.0 es un commit de
>   release posterior. La regresión es real: el diff de `a5323bb` cambia `is_available()` por
>   `is_configured()` en el dashboard.
> - `Products.php:2031-2034` (el código afirma que WC no deriva `_stock_status`) → **cita exacta**, y
>   el claim está **REFUTADO**: `WC_Product::save()` llama a `validate_props()`
>   (`woocommerce/includes/abstracts/abstract-wc-product.php:1547`), que desde WC 3.0.0 deriva
>   `instock`/`onbackorder`/`outofstock` desde cantidad + backorders + umbral
>   (`abstract-wc-product.php:1517-1538`). Ver Tema B, B5.
>
> El resto de las citas fue **verificado** y es exacto (ver §4).

---

## 1. El problema, en lenguaje del dueño de tienda

> **"Vendí y el stock volvió a subir solo. Termino sobrevendiendo mercancía que ya no tengo."**

Ese es el titular. Se descompone en **tres frentes** verificables en el código.

### FRONT A — El dashboard miente sobre el Consumidor Final (y hay caché podrida)

El panel de "Estado de facturación" muestra **"Consumidor Final — No encontrado en Alegra"** aunque
el contacto **sí existe** y la facturación funciona. La causa es una regresión de UI, no de datos:

- `templates/admin-dashboard.php:20` llama a `Consumidor_Final::is_configured()`, que es un **peek
  de caché** (`peek_id()`, `Consumidor_Final.php:44-67`; `is_configured()`, `:74-77`). No toca la red.
- El flujo de **conectar** (`Admin_Dashboard.php:1638`, `ajax_test_connection`) guarda credenciales y
  marca `alegra_connector_connection_tested=true` (`:1722`) pero **nunca resuelve el CF**: la caché
  queda vacía.
- El commit `a5323bb` (v2.4.0) cambió en el dashboard `is_available()` (lookup vivo, puede crear)
  por `is_configured()` (peek). Resultado: el dashboard dice "No encontrado" para siempre.

**La query NO está rota.** `GET /contacts?identification=222222222222&limit=5`
(`Consumidor_Final.php:181-184`) es válida y documentada. El filtro `identification` de Alegra es un
**match CONTAINS** (documentado), así que `matches()` exige igualdad exacta (`:241-251`) —correcto—
pero los falsos positivos **consumen los 5 slots** del `limit`.

**Riesgo de caché podrida (Caso D — VERIFICADO).** Si el CF se borra en Alegra, el plugin sigue
sirviendo el id cacheado (la opción no tiene TTL, `Consumidor_Final.php:28`; el peek la devuelve tal
cual, `:61-64`) → la factura devuelve **400** → y **no hay auto-sanación**: `Orders.php:121-129`
solo loguea el `WP_Error`. Peor: el meta del pedido `_billing_alegra_contact_id` se **persiste antes
del POST** (`prepare_invoice_data()` → `persist_contact_id()`, `Orders.php:1428-1435`, con el POST en
`:119`), así que un id muerto queda clavado en el pedido.

**Fallo silencioso.** Un fallo de resolución del CF va **solo al archivo de log**
(`Consumidor_Final::log_error()`, `:480-491`); la UI del admin nunca se entera.

### FRONT B — El stock de WC nunca se empuja a Alegra → el poll re-infla (SOBREVENTA)

El escenario, con números:

| Paso | Alegra | WooCommerce | ¿Qué pasó? |
|---|---|---|---|
| Inicio | 10 | 10 | Sano. |
| Venta de 3 en WC | 10 | 7 | WC descuenta; **Alegra nunca se entera**. |
| Corre el poll (Alegra → WC) | 10 | **10** | El poll **re-infla** las 3 vendidas. |
| Otro cliente compra 4 | 10 | 6 | WC cree que hay 10, pero físicamente hay 7. |
| **Resultado** | 10 | 6 | **Sobreventa / stock fantasma.** |

La causa raíz: **WC nunca empuja stock a Alegra**. `prepare_simple_product_data()` envía el objeto
`inventory` **solo en creación** (`Products.php:1004-1015`); en update se omite por completo (hotfix
R2/R3), y `sync_simple_product()` pasa `$is_create=false` y llama `update_item`
(`Products.php:217-221`). Entonces el poll (`sync_inventory_from_alegra()`, `Products.php:1142-1293`)
escribe **incondicionalmente** el valor de Alegra (`:1253-1260`) y re-infla lo ya vendido. **Este es
el bug de sobreventa / stock fantasma.**

### FRONT C — El poll no termina nunca en catálogos grandes (y puede ralentizar el sitio)

WP-Cron **no** corre el poll dentro del request del visitante: `spawn_cron()` lanza un proceso
`wp-cron.php` separado de forma no bloqueante (comportamiento del core de WordPress; el plugin usa
`spawn_cron()` en `Admin_Dashboard.php:4209`). El único camino inline es `ALTERNATE_WP_CRON`. Así que
**no cuelga** el sitio — pero:

- **Puede ralentizarlo** si el pool PHP-FPM es chico (un worker ocupado minutos) y **puede demorar o
  hacer fallar la facturación automática** por el **rate limiter compartido** (150 req/min,
  `API/Client.php:954`).
- **El problema real es de correctitud:** el poll **no tiene presupuesto ni cursor**, tiene un tope
  fijo de **200 páginas × 30 = 6.000 ítems que trunca en silencio** (`Products.php:1171`) y **arranca
  en la página 1 en cada corrida** (`:1174`) → en un catálogo grande **nunca termina**.

### Impacto

- **Funcional (crítico):** sobreventa real. El comerciante vende lo que no tiene porque el poll
  resucita stock vendido. El fix no puede esperar.
- **De confianza:** el dashboard dice "Consumidor Final — No encontrado" cuando el contacto existe;
  la UI miente sobre un estado que el comerciante no puede corregir desde ahí.
- **Operativo:** en catálogos grandes el poll nunca completa y no lo reporta; sin señal, el soporte
  queda ciego.
- **Distribuido:** los tres frentes pegan **más** en tiendas reales (catálogo grande, ventas
  concurrentes), que es el público objetivo del plugin.

---

## 2. Restricciones del comerciante (verbatim — se honran tal cual)

1. *"Nuestro plugin es profesional y el dashboard debe seguir con la buena usabilidad, nada de
   ambigüedades y que el usuario no se complique, debe ser fácil de usar."*
2. *"Nada de chapuzadas y de código como pañitos de agua tibia."*
3. *"Todo debe quedar funcional y todo el proceso debe funcionar correctamente sin problemas, errores,
   bugs o conflictos."*
4. *"La idea es que funcione en cualquiera"* — plugin **distribuido**, sin supuestos de host.
5. *"No necesitamos lo de la facturación electrónica ni DIAN"* — fuera de alcance.
6. El ZIP de release **excluye `scripts/`** — nada de tests/harness distribuido.

---

## 3. Alcance

### 3.1 Dentro del alcance

**FRONT A — Consumidor Final (UI honesta + auto-sanación de caché)**

- Resolver el CF en la **acción explícita de conectar** (`ajax_test_connection`,
  `Admin_Dashboard.php:1638`) **dentro de `Write_Gate::run_explicit()`** (`Write_Gate.php:150`), para
  que el dashboard diga la verdad — **sin tocar el camino de facturación**.
- Hacer **honesto** el estado del dashboard: distinguir **"no verificado"** de **"no encontrado"**,
  con una acción **"Verificar ahora"**.
- **Auto-sanar la caché podrida (Caso D):** invalidar + reintentar cuando la factura devuelve un 400
  causado por un **client id muerto** (reusando la pre-búsqueda de idempotencia existente,
  `Orders.php:97-117`, para no duplicar la factura).
- Evaluar un **`resolve_readonly()`** (GET + match + caché, **sin crear**) para que conectar **nunca
  haga POST /contacts** (respeta la compuerta de entidad `contact`,
  `Write_Gate.php:41` + `Consumidor_Final.php:272-277`).

**FRONT B — Inventario bidireccional (cerrar la re-inflación)**

- **Cerrar el loop de re-inflación.** El diseño **elige y justifica** entre:
  - **(a)** empujar un **ajuste de inventario WC → Alegra** en cada cambio de stock de WC (con
    **delta tracking** para no doble-descontar), vía `POST /inventory-adjustments`
    (`API/Client.php:770`); o
  - **(b)** **crear/abrir la factura de Alegra al pagarse** el pedido, para que Alegra descuente de
    forma nativa (modelo "factura atada al pedido" del SDD `inventory`).
- **Unificar los dos escritores** detrás de un único **`Inventory_Writer`** (SDD REQ-DIV-1): W1
  `apply_inventory_to_product()` (`Products.php:2052-2100`) y W2 inline del poll (`:1253-1260`).
- **Refrescar el stock de variaciones** en el camino de **update** del webhook/import (hoy solo la
  rama de creación itera los hijos, `Products.php:1611-1618`; `type=variant` se saltea en
  `:1515-1517` y `Admin_Dashboard.php:2316`).
- **Corregir `_stock_status`/backorders:** dejar de forzar `$qty > 0 ? instock : outofstock`
  (`Products.php:1259`, `:2099`) y usar `wc_update_product_stock()` **o** respetar backorders +
  umbral de no-stock.
- **Honrar `preserve_fields` en el poll** (`Products.php:1224-1260` hoy lo ignora).
- **(Opcional / detrás de flag)** poll **warehouse-aware** (`:1238` hoy lee solo el total).

**FRONT C — Robustez del poll/cron**

- **Presupuesto de wall-clock + cursor persistido** en el poll (copiar el patrón del import,
  `Products.php:1305-1322`).
- **Tope de lote configurable** + flag **`truncated`** + **log explícito**.
- **Liberar el lock en shutdown** (`register_shutdown_function`) o bajar el TTL.
- **`set_syncing(true/false)`** alrededor del loop del poll.
- Evaluar mover el poll a **lotes encadenados con Action Scheduler**.
- Mostrar una **recomendación de cron real** en la UI de ajustes (`DISABLE_WP_CRON` + línea de
  crontab).

### 3.2 Fuera del alcance (explícito)

- **DIAN / facturación electrónica:** nada de `stamp`, `paymentForm` ni esquema fiscal.
- **Rediseño de los flujos de factura/cliente:** no se reescriben. Solo se agrega la resolución del
  CF en **conectar** y la **auto-sanación del 400** en el camino de factura; la facturación sigue
  igual.
- **Flujo de pagos (`payments`):** no se toca.
- **Rediseño general del dashboard:** solo se toca la fila del Consumidor Final y la pista de cron en
  Ajustes.
- **Multisite** más allá de lo que ya soporta el plugin.
- **Carpetas de test / harness `scripts/`:** no se agregan ni se distribuyen.

### 3.3 Restricción transversal

El plugin es **distribuido**: todo debe funcionar para **cualquier** tamaño de catálogo y cualquier
host (con o sin `set_time_limit` habilitado, con WP-Cron o cron real, con o sin Action Scheduler).
**Sin regresión** para instalaciones existentes. Cuando un cambio altere comportamiento previo
(honrar `preserve_fields`, derivar `_stock_status` con backorders), la propuesta lo declara como
**cambio intencional** con nota de release.

---

## 4. Los hallazgos, agrupados por tema

Leyenda: **[BUG]** = comportamiento incorrecto / promesa incumplida · **[FEATURE]** = falta control o
visibilidad · **[HYG]** = limpieza · **[REFUTADO]** = el código afirma algo falso.

### Tema A — Consumidor Final (FRONT A)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| A1 | El dashboard usa `is_configured()` (peek) y muestra "No encontrado" aunque el CF exista. | `templates/admin-dashboard.php:20`; `Consumidor_Final.php:44-67,74-77` | **[BUG]** crítico |
| A2 | El flujo de conectar nunca resuelve/puebla la caché del CF. | `Admin_Dashboard.php:1638` (handler), `:1722` (marca conectado, sin CF) | **[BUG]** |
| A3 | Regresión introducida en `a5323bb` (v2.4.0): `is_available()` → `is_configured()`. | diff `a5323bb -- templates/admin-dashboard.php`; `git tag --contains a5323bb` → `v2.4.0` | **[BUG]** |
| A4 | El filtro `identification` es CONTAINS; los falsos positivos consumen `limit=5`. `matches()` exige igualdad exacta. | `Consumidor_Final.php:181-184`, `:241-251`; doc Alegra `GET /contacts` | **[BUG]** latente |
| A5 | Caché podrida (Caso D): CF borrado en Alegra → id viejo servido para siempre; opción sin TTL. | `Consumidor_Final.php:28,61-64`; `invalidate_cache()` `:392-397` solo se llama en override (`:433-443`) o al fallar la creación (`:226`) | **[BUG]** |
| A6 | Sin auto-sanación: la factura 400 por cliente muerto solo loguea; el meta se persiste antes del POST. | `Orders.php:119` (POST), `:121-129` (solo log); `persist_contact_id()` `:1428-1435` | **[BUG]** crítico |
| A7 | El fallo de resolución del CF es invisible en la UI (solo archivo de log). | `Consumidor_Final.php:480-491` | **[BUG]** UX |
| A8 | El dashboard no ofrece forma de verificar el CF desde ahí. | `templates/admin-dashboard.php:130-147` (solo muestra estado/instrucción) | **[FEATURE]** UX |

### Tema B — Inventario bidireccional (FRONT B)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| B1 | **WC nunca empuja stock a Alegra**: `inventory` solo en creación; update lo omite. | `Products.php:1004-1015`; `sync_simple_product()` `:217-221`; `apply_warehouse()` `:1129-1131` | **[BUG]** crítico (causa raíz de B2) |
| B2 | **El poll re-infla**: escribe el valor de Alegra sin delta ni push previo. | `Products.php:1238-1260` | **[BUG]** crítico (sobreventa) |
| B3 | **Dos escritores** con compuertas divergentes; REQ-DIV-1 no se cumple. | W1 `Products.php:2052-2100`; W2 `:1253-1260` | **[BUG]** arquitectónico |
| B4 | El update del webhook/import **no refresca stock de variaciones**; `type=variant` se saltea. | `Products.php:1611-1618` (solo create); `:1515-1517`; `Admin_Dashboard.php:2316` | **[BUG]** |
| B5 | El código afirma que WC no deriva `_stock_status`; **es falso** desde WC 3.0.0. El forzado ignora backorders y umbral. | claim `Products.php:2031-2034`; forzado `:1259,:2099`; refutación `abstract-wc-product.php:1517-1538,1547` | **[REFUTADO]** / **[BUG]** |
| B6 | El poll **ignora `preserve_fields`**: "preservar inventario" se sobrescribe igual. | `Products.php:1224-1260` (sin chequeo de `$preserve`) | **[BUG]** |
| B7 | El poll **no puede habilitar `_manage_stock`**: saltea productos con `manage_stock=false`. | `Products.php:1249-1251` | **[BUG]** |
| B8 | `create_inventory_adjustment()` existe pero está `@deprecated` y sin cablear. | `API/Client.php:770` | **[FEATURE]** |
| B9 | El poll lee solo el total `availableQuantity`; el push escribe bodega. | poll `Products.php:1238`; push `:1118-1137` | **[BUG]** (opcional/flag) |

### Tema C — Poll / cron (FRONT C)

| # | Hallazgo | Evidencia | Tipo |
|---|---|---|---|
| C1 | Sin presupuesto, sin cursor: arranca en página 1 en cada corrida. | `Products.php:1174`; `result` sin `truncated` `:1144` | **[BUG]** crítico |
| C2 | Tope fijo 200 páginas × 30 = 6.000 ítems, truncación silenciosa. | `Products.php:1171`; salida por `count($items) < 30` `:1282-1284` | **[BUG]** |
| C3 | Un fatal saltea el `finally` → el lock `alegra_sync_running_products` (TTL 300 s) queda tomado. | `Products.php:1290-1292`; lock `Controller.php:482` | **[BUG]** |
| C4 | El lock global del cron es 600 s pero la corrida (import 240 s + poll 300 s + clientes/categorías/órdenes/prune) puede excederlo → solapamiento. | `Controller.php:141`; budget import `Products.php:1318`; `set_time_limit(300)` `:1172` | **[BUG]** |
| C5 | El poll no llama `set_syncing(true)`: con `push_products_enabled`, cada `save()` dispara `woocommerce_update_product` → un POST por ítem. | `Public_.php:73,289`; `set_syncing` solo en import `Products.php:1542` | **[BUG]** cascada |
| C6 | No hay guarda `wp_doing_cron()` (0 coincidencias) ni recomendación de cron real en la UI. | grep `wp_doing_cron`/`DISABLE_WP_CRON` = 0 matches | **[FEATURE]** |
| C7 | El rate limiter (150 req/min) es compartido con la facturación: un poll grande demora/falla facturas. | `API/Client.php:954` | **[BUG]** de proceso |

---

## 5. Enfoque (alto nivel — el detalle va en `design`)

1. **Un solo escritor de stock (`Inventory_Writer`).** W1 y W2 se colapsan en un único punto que
   decide bodega, nulos, negativos, servicios, tipos y `preserve_fields`. Grep-able: los setters de
   stock solo aparecen en `includes/Sync/Inventory_Writer.php`.
2. **Cerrar la re-inflación con una decisión explícita (el fork central).** El diseño **elige y
   justifica** entre:
   - **(a) Ajuste WC → Alegra con delta tracking.** El plugin empuja `POST /inventory-adjustments`
     (`Client.php:770`) con `type=in|out` y la **diferencia** del cambio de stock, con su propio
     lock e idempotencia. Funciona **aunque no se facture**. Riesgo: es un **segundo escritor del
     stock de Alegra** y puede **doble-contar** si además se abre la factura (SDD `inventory` §5.8).
   - **(b) Factura atada al pedido.** Al pagarse el pedido se **crea/abre** la factura de Alegra y
     Alegra descuenta **de forma nativa** (modelo recomendado por el SDD `inventory` §5.1). Requiere
     que la factura quede `open` (el borrador **no** mueve stock — premisa **SIN VERIFICAR**, R1 del
     SDD) y depende de que la facturación esté configurada.
   - **Criterio de decisión:** (b) es el camino documentado y sin doble conteo, pero **solo cubre
     ventas que se facturan**; (a) cubre cualquier movimiento de WC. El diseño puede combinar:
     **(b) como primario cuando la facturación está activa, (a) como reconciliación opt-in** (nunca
     ambos para el mismo movimiento).
3. **Poll con presupuesto y cursor.** Reusar el patrón del import (`Products.php:1305-1322`):
   deadline de wall-clock, cursor persistido, tope configurable, flag `truncated` y log. Nada de
   `set_time_limit` como parche.
4. **Lock a prueba de fatales.** `register_shutdown_function` para liberar el lock, o TTL menor;
   `set_syncing(true/false)` alrededor del loop.
5. **Consumidor Final honesto.** Resolver el CF en **conectar** (bajo `run_explicit()`), exponer
   "Verificar ahora", distinguir "no verificado" de "no encontrado", y **auto-sanar** la caché podrida
   reusando la pre-búsqueda de idempotencia para no duplicar facturas.
6. **Fail-loud.** Toda truncación, lock tomado, rate-limit o fallo de CF deja señal **visible** (UI
   y/o log con mensaje legible), nunca silencio.
7. **Cron real recomendado.** Ajustes muestra `DISABLE_WP_CRON` + una línea de crontab, para que el
   poll no dependa del tráfico del visitante.

---

## 6. Impacto

### Archivos/áreas afectadas

| Área | Impacto | Descripción |
|---|---|---|
| `includes/Consumidor_Final.php` | Modificado | `resolve_readonly()`, auto-sanación de caché podrida, estado honesto |
| `includes/Sync/Inventory_Writer.php` | **Nuevo** | Escritor único de `_manage_stock`/`_stock`/`_stock_status` |
| `includes/Sync/Products.php` | Modificado | Usar `Inventory_Writer`; poll con budget/cursor/`truncated`; `preserve_fields`; refresh de variaciones; `set_syncing`; `_stock_status`/backorders |
| `includes/Sync/Orders.php` | Modificado (mínimo) | Auto-sanación del 400 por cliente muerto (reusa `find_existing_invoice`); opción (b) si el diseño la elige |
| `admin/Admin/Admin_Dashboard.php` | Modificado | Conectar bajo `run_explicit()` + resolución del CF; AJAX "Verificar ahora"; tope de lote del poll; pista de cron real |
| `templates/admin-dashboard.php` | Modificado | Fila del CF honesta + "Verificar ahora" |
| `templates/admin-settings.php` | Modificado | Recomendación de cron real (`DISABLE_WP_CRON` + crontab); opciones del poll |
| `includes/API/Client.php` | Modificado/Reusado | Cablear `create_inventory_adjustment()` si el diseño elige (a); sin cambio de firma |
| `includes/Sync/Controller.php` | Modificado | Programación/lotes del poll; TTL del lock; shutdown handler |
| `alegra-connector.php` | Modificado | Defaults/registro de opciones nuevas |
| `languages/*`, `*/index.php` | **NO TOCAR** | Restricción explícita |
| `scripts/*` | **NO DISTRIBUIR** | El ZIP excluye `scripts/` |

### Riesgo

| Riesgo | Prob. | Mitigación |
|---|---|---|
| El ajuste WC→Alegra (opción a) doble-descuenta si también se abre la factura | Media | Delta tracking + lock + idempotencia; **nunca** (a) y (b) para el mismo movimiento; flag opt-in |
| La auto-sanación re-POSTea y duplica la factura | Media | Reusar la pre-búsqueda de idempotencia (`Orders.php:97-117`) antes de reintentar |
| `resolve_readonly()` en conectar hace un POST /contacts | Baja | GET+match+caché; nunca `create()`; respeta la compuerta `contact` (`Consumidor_Final.php:272-277`) |
| Refactor a escritor único reescribe stock en el deploy | Baja | Solo cambia rutas de código, no datos; sin migración masiva |
| Derivar `_stock_status` con backorders cambia estados existentes | Baja | Cambio **intencional** y declarado; con `backorders=no` el resultado es idéntico al actual |
| Honrar `preserve_fields` en el poll cambia comportamiento previo | Baja | Es la corrección de un bug (hoy se ignora en silencio); nota de release |
| Budget/cursor del poll mal calibrado en hosts lentos | Media | Defaults conservadores + configurable; reanudable, nunca fatal |
| Action Scheduler no está disponible en algunos hosts | Baja | Mantener WP-Cron como fallback; AS solo si existe |

### Compatibilidad hacia atrás

- Los contratos de los AJAX (`success`/`data`) se mantienen; se agrega `message` legible.
- La corrección de `_stock_status` y el respeto a `preserve_fields` son **cambios intencionales** de
  comportamiento (bugs), documentados en `CHANGELOG.md`.
- En `inventory_source=woocommerce` el plugin **sigue sin escribir stock**; la opción (a) solo aplica
  cuando Alegra es la fuente o bajo un flag explícito de reconciliación.
- Las opciones nuevas se siembran con defaults seguros (patrón `Write_Gate::maybe_migrate`,
  `Write_Gate.php:176`), sin pisar valores elegidos.

### Preocupación de plugin distribuido

Nada puede depender de `set_time_limit` efectivo, de cron real, de Action Scheduler ni de un pool
PHP-FPM grande. El poll debe funcionar **aunque el host ignore `set_time_limit`**: por eso el corte es
por presupuesto propio + cursor, no por extender el límite. La resolución del CF no puede depender de
que el filtro server-side funcione: `matches()` ya verifica del lado del cliente. La UI debe degradar
con honestidad ("no verificado") cuando no puede comprobar, en vez de mentir.

### Migración

- **FRONT A y C:** sin migración de datos. Opciones nuevas con defaults.
- **FRONT B:** el refactor a `Inventory_Writer` no migra datos; cambia el camino de escritura. La
  auto-sanación de caché del CF es bajo demanda (no toca datos existentes salvo invalidar el id muerto
  cuando corresponda).

---

## 7. Criterios de éxito

Observables desde el WP admin, sin mirar la base:

1. **El CF se resuelve al conectar.** Tras conectar, el dashboard muestra "Consumidor Final:
   Disponible" (o "No verificado" honesto si el chequeo no pudo hacerse), nunca "No encontrado" con
   el contacto existente.
2. **"Verificar ahora" funciona.** La acción re-verifica el CF y reporta el resultado en la UI.
3. **Caché podrida auto-sanada.** Si el CF se borra en Alegra, un reintento de factura no falla en
   silencio: invalida + reintenta (una vez) y, si no puede, deja un mensaje accionable.
4. **No hay re-inflación (el titular).** Tras una venta en WC, un poll posterior **no** sube el stock
   de WC: el valor se mantiene o baja, nunca resucita lo vendido.
5. **Un solo escritor.** Grep de `set_manage_stock|set_stock_quantity|set_stock_status` en producción
   devuelve matches **solo** en `Inventory_Writer.php`.
6. **Variaciones al día.** Un cambio de stock en Alegra sobre una variación se refleja por el camino
   de webhook/import update, no solo al crear el padre.
7. **`_stock_status` correcto.** Un producto con `backorders=yes` y stock 0 queda `onbackorder`, no
   `outofstock`; con `backorders=no` el resultado es el actual.
8. **`preserve_fields` se respeta.** Con `inventory` en la lista de preservados, el poll **no** escribe
   stock.
9. **Poll de catálogo grande termina o pausa con señal.** Muestra avance, respeta el presupuesto,
   persiste el cursor, reporta `truncated=true` si no terminó, y **no** produce fatal 500.
10. **El lock no queda colgado.** Ante un fatal, el lock se libera (shutdown handler) o su TTL es corto;
    el próximo poll corre.
11. **Sin cascada.** Con `push_products_enabled`, el poll **no** dispara un POST a Alegra por ítem.
12. **Cron real recomendado.** Ajustes muestra `DISABLE_WP_CRON` + línea de crontab.
13. **Sin regresión:** DIAN intacto; facturación, clientes y pagos siguen funcionando; `woocommerce`
    como fuente sigue sin escribir stock.

---

## 8. Preguntas abiertas (forks reales)

1. **FRONT B — ¿(a) ajuste WC→Alegra con delta, o (b) factura al pagar?** El fork central. La
   recomendación del SDD `inventory` es (b); (a) cubre ventas no facturadas pero arriesga doble
   conteo. **Decide el diseño**, con la regla "nunca ambos para el mismo movimiento".
2. **Caso D — auto-sanar inline o marcar para reintento.** ¿El 400 por cliente muerto dispara
   invalidar+reintentar en el mismo request (riesgo de doble POST si la pre-búsqueda falla), o marca
   el pedido para un reintento seguro por el sweep de pagos? Recomendación: reintento **una vez**
   reusando `find_existing_invoice`.
3. **`resolve_readonly()` en conectar.** Si el GET encuentra el CF → cachear. Si el GET **falla** (red),
   ¿mostrar "no verificado" (honesto) o "no encontrado"? Recomendación: **"no verificado"**.
4. **Defaults del poll (budget/tope de lote).** Deben ser seguros en hosts lentos y en catálogos
   grandes. ¿Valores? Decisión del diseño/comerciante.
5. **Action Scheduler vs WP-Cron encadenado.** ¿Se adopta AS cuando está disponible, con fallback a
   WP-Cron? Afecta la portabilidad distribuida.
6. **Poll warehouse-aware.** ¿Se incluye ahora detrás de flag o se difiere? Depende de R7 del SDD
   (semántica de bodega ausente) — **SIN VERIFICAR**.
7. **`_stock_status`: `wc_update_product_stock()` vs setter + backorders.** El primero es más correcto
   (dispara hooks, deriva estado) pero más pesado; el segundo es un cambio mínimo. Decide el diseño.
8. **¿`edit-item` dispara con cambio solo de stock?** **SIN VERIFICAR** (el repo trae un inspector
   read-only justamente porque es desconocido). Define si el poll es el único camino o si el webhook
   da tiempo real.

---

## 9. Relación con otros SDD

- **`docs/sdd/inventory`** — está **DESIGNED-ONLY**: todas las tareas `[ ]`, **no existe
  `Inventory_Writer.php`** y **no existen** las opciones `inventory_manage_stock_enabled`,
  `inventory_dry_run`, `inventory_pull_cursor` ni `open_invoice_on_paid` (0 coincidencias en
  producción). Este cambio **consume** sus requerimientos de fiabilidad (REQ-DIV-1, REQ-DIV-3,
  REQ-FAIL-4..6, REQ-GATE-1..2, REQ-WH-1/4, REQ-TYPE) y **no reabre** la decisión de migración
  opt-in (REQ-MIG) ni de bodegas (R7), que quedan como están.
- **`docs/sdd/config-gates`** — `Write_Gate::run_explicit()` (`Write_Gate.php:150`) y la compuerta por
  entidad ya existen; este cambio **envuelve** la acción de conectar en ese contexto y respeta la
  compuerta `contact`. No reabre sus decisiones.
- **`docs/sdd/logs-monitor-import`** — su principio "un solo pipeline instrumentado" y "fail-loud"
  aplican al poll: toda truncación/pausa/lock debe dejar señal. Comparte el `Logger` y `Runs`; no
  cambia su lógica.
- **`docs/sdd/payments`** — el flujo de pagos **no se toca**; comparte el rate limiter
  (`API/Client.php:954`), así que el presupuesto del poll de FRONT C **reduce** la contención con la
  facturación automática.

# Resultados Fase 0 — `sync-reliability`

Cuenta: N/D (sin acceso a la cuenta real en este entorno) · Fecha: 2026-09-25 · HEAD: d7d1c12 (2.5.1)

> **Alcance de esta corrida.** Los gates que **no** requieren la cuenta real se resolvieron leyendo
> HEAD y la documentación oficial de Alegra (G3, G6). Los gates que dependen de la cuenta del
> comerciante (G1, G2, G4, G5, G7, G8) quedan marcados `PENDING-LIVE` con el comando exacto para
> ejecutarlos. Ningún gate escribió código de producción.

| Gate | Rama | Decisión | Evidencia |
|---|---|---|---|
| G1 | PENDING-LIVE | Sin acceso a la cuenta; no se puede observar el stock. La rama `adjustment` (default) no depende de G1. | `alegra-connector.php:451` (`invoice_status='draft'`); `design.md:526-533` (D2). Comandos abajo. |
| G2 | PENDING-LIVE | Sin acceso a la cuenta; no se puede leer el Recorder. | `templates/admin-webhooks.php`; `Public_.php` (suscripción). Comandos abajo. |
| G3 | **A** | El schema **coincide** con el `design.md` §3.4 (`date` + `items[]{id,type,quantity,unitCost}` + `warehouse?`). `push_inventory_enabled_default=true`. **OJO: el shape K-H4 de `fase-1-cimientos.md` (`item:{id}` + `type`/`quantity` top-level) NO coincide con el schema documentado; ver Nota G3.** | Doc oficial `POST /inventory-adjustments` (OpenAPI, abajo). |
| G4 | PENDING-LIVE | Sin acceso a la cuenta; `total_items` desconocido. Defaults `budget=60`, `max_pages=0` se mantienen. | `design.md` §7.1. Comando abajo. |
| G5 | PENDING-LIVE | Sin acceso al host/WP-CLI; `DISABLE_WP_CRON` y `crontab_externo` sin verificar. | `Admin_Dashboard.php:4209` (`spawn_cron()`); 0 matches de `DISABLE_WP_CRON` en prod. Comandos abajo. |
| G6 | **B** | El mock/`Client` arma el error como `WP_Error('api_error', $message, ['code'=>400,'response'=>$body])` y el body modelado por H5 es `{"code":400,"message":"El cliente no existe"}` — **sin** campo `client`. El detector cae al **regex `/client|cliente/i`** sobre `get_error_message()`. | `Client.php:234-242`; `alegra-mock.php:535-556`; H5 (`fase-1` T1.5). Body abajo. |
| G7 | PENDING-LIVE (A esperada) | `wc_update_product_stock()` es API del **core de WC** (≥ 3.0, 2017); el plugin no la define (0 matches). En el harness falta (H1 la agrega). `function_exists`/`wc_version` del comerciante sin verificar. | `grep -rn wc_update_product_stock scripts/ includes/ public/ admin/` ⇒ 0. Comando abajo. |
| G8 | PENDING-LIVE (B esperada) | Sin acceso a la cuenta no se puede leer `inventory.warehouses`. El `design.md` §15 ya decide **DIFERIDO** salvo semántica clara ⇒ se cierra como B (diferido) por default. `warehouses=` sin verificar. | `design.md:1179-1183` (§15); `Products.php:1238` (lee el total). Comando abajo. |

---

## G1 — `draft` vs `open` y stock

- `stock_inicial=` PENDING-LIVE
- `stock_tras_draft=` PENDING-LIVE
- `stock_tras_open=` PENDING-LIVE
- `rama=` PENDING-LIVE (A/B/C)

Comandos exactos (cuenta real; token NUNCA pegado acá):

```bash
export ALEGRA_EMAIL="cuenta@ejemplo.com"
export ALEGRA_TOKEN="<token>"
export ITEM_ID="<item-inventariable>"
export CLIENT_ID="<contacto-valido>"
BASE="https://api.alegra.com/api/v1"
AUTH="-u $ALEGRA_EMAIL:$ALEGRA_TOKEN"

# 0) Stock inicial (debe traer availableQuantity numérico)
curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'

# 1) Factura draft (1 unidad). Anotar INVOICE_ID.
curl -s $AUTH -X POST "$BASE/invoices" -H 'Content-Type: application/json' -d '{
  "status":"draft","client":{"id":"'"$CLIENT_ID"'"},
  "items":[{"id":"'"$ITEM_ID"'","price":1000,"quantity":1}],
  "date":"2026-09-25","dueDate":"2026-09-25"
}'
curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'

# 2) Abrir la factura y releer
curl -s $AUTH -X PUT "$BASE/invoices/$INVOICE_ID" -H 'Content-Type: application/json' -d '{"status":"open"}'
curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'
```

## G2 — `edit-item` con cambio solo de stock

- `evento=edit-item|ausente` PENDING-LIVE
- `rama=` PENDING-LIVE (A/B)

Comandos: cambiar **solo** `inventory.availableQuantity` desde el panel de Alegra (o vía el ajuste de
G3), y mirar la pestaña **Webhooks** del plugin (`templates/admin-webhooks.php`, Recorder read-only).

## G3 — schema de `POST /inventory-adjustments` (DOCS — resuelto)

**Fuente oficial:** `https://developer.alegra.com/reference/post_inventory-adjustments`
(OpenAPI `Inventory Adjustment Post`; descargado 2026-09-25).

Campos del request:

- Obligatorios: `date` (date), `items` (array, no vacío).
- Cada `items[]`: `id` (string), `type` (`in`|`out`), `unitCost` (number), `quantity` (number) —
  **los 4 obligatorios**.
- Opcionales: `warehouse` (`{id,...}`; si se omite usa la bodega principal), `observations`,
  `costCenter`, `resolution`, `prefix`, `number`.

Response 200: `{id, date, observations, warehouse, items[{id,name,quantity,type,unitCost,reference}],
costCenter, idResolution, prefix, number}`.

Idempotencia: el POST **no** documenta un campo `reference` de entrada (el `reference` del response es
el del ítem). No hay query de idempotencia documentada ⇒ `T3.2` filtra por `item_id`+fecha (DR20).

- `push_inventory_enabled_default=true`
- `rama=A` (el schema coincide con `design.md:732-746`)

> **Nota G3 (discrepancia con `fase-1-cimientos.md` K-H4/T1.4).** El contrato K-H4 del task file
> modela el payload como `{date, type:'in'|'out', quantity, item:{id}, warehouse?}`. El schema
> **documentado** (y el del `design.md` §3.4) es `{date, items:[{id,type,quantity,unitCost}],
> warehouse?}`. El mock de `T1.4` se implementó **exactamente como lo pide el task file** (shape K-H4),
> que es lo que consume el test `T29.14`. Consecuencia: **antes de `T3.1` hay que alinear el validador
> del mock con el shape documentado** (`items[]` + `unitCost`), tal como el propio `Riesgo` de `T1.4`
> ya anticipa ("si G3 revela campos extra, se ajusta el validador antes de T3.1"). Decisión del
> usuario: confirmar si se corrige el mock en Fase 3 o ya en Fase 1.

## G4 — tamaño del catálogo

- `total_items=` PENDING-LIVE
- `rama=` PENDING-LIVE (A/B)

```bash
curl -s $AUTH "$BASE/items?metadata=true&limit=1&status=active" | python3 -m json.tool
```

## G5 — cron real vs WP-Cron

- `DISABLE_WP_CRON=` PENDING-LIVE
- `crontab_externo=` PENDING-LIVE (si|no)
- `rama=` PENDING-LIVE (A/B)

```bash
wp config get DISABLE_WP_CRON --allow-root 2>/dev/null || grep -n "DISABLE_WP_CRON" wp-config.php
wp cron event list --fields=hook,next_run_gmt,recurrence | grep alegra
crontab -l | grep -i wp-cron
```

## G6 — body del 400 por client id muerto (CODE/MOCK — resuelto)

- `rama=B`
- Body modelado por el harness (H5, `T1.5`):

```json
{"code":400,"message":"El cliente no existe"}
```

`Client::request()` (`Client.php:234-242`) convierte ese body en
`WP_Error('api_error', 'El cliente no existe', ['code'=>400, 'response'=>{"code":400,"message":"El cliente no existe"}])`.
Como el body **no** trae el campo `client`, el detector de `try_self_heal_dead_client()` cae al
**regex** (`stripos($msg,'cliente') !== false`). Coincide con `design.md:349-350`.

> Caveat: el body **real** de Alegra sólo se confirma con la cuenta (G6 live). Si el body real trajera
> `response.client`, el detector usaría el campo; el diseño ya cubre ambas ramas (`design.md:349`).

## G7 — `wc_update_product_stock()`

- `function_exists=` PENDING-LIVE (A esperada)
- `wc_version=` PENDING-LIVE

```bash
wp eval 'var_dump(function_exists("wc_update_product_stock"));' --allow-root
wp plugin get woocommerce --field=version --allow-root
```

En HEAD el plugin **no** define la función (`grep` = 0); es API del core de WC desde 3.0.0, así que la
rama esperada es **A** (usa la API recomendada); el fallback `set_stock_quantity()+save()` queda como
defensa para hosts viejos.

## G8 — semántica de bodega

- `warehouses=` PENDING-LIVE
- `rama=B` (diferido, por decisión de `design.md` §15)

```bash
curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A20 '"inventory"'
```

Sin semántica clara (o sin bodega configurada) ⇒ **DIFERIDO**: el poll lee el total; `T4.4` sólo
agrega el aviso en Ajustes.

---

## DoD Fase 0

- [x] `phase0-results.md` existe con una fila por gate.
- [x] G3 resuelto desde la doc oficial (rama A, `push_inventory_enabled_default=true`).
- [x] G6 resuelto desde el código/mock (rama B, regex fallback).
- [ ] G1/G2/G4/G5/G7/G8 requieren la cuenta/host del comerciante (`PENDING-LIVE`); comandos arriba.
- [x] Ningún gate escribió código de producción.
- [x] Fase 1 no esperó a ningún gate.

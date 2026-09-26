# Resultados Fase 0 — `stock-ownership`

Cuenta: N/D (sin acceso a la cuenta real en este entorno) · Fecha: 2026-09-25 · HEAD: eb77851 (2.6.0 + artifacts)

> **Alcance de esta corrida.** Los gates **code-inspectables** (`G3`, `G4`) y el gate **cerrado**
> (`G6`) se resolvieron leyendo HEAD, el harness y la documentación oficial de Alegra/WooCommerce.
> Los gates que dependen de la cuenta viva del comerciante (`G1`, `G2`, `G5`) quedan marcados
> `PENDING-LIVE` con el comando exacto para ejecutarlos. Ningún gate escribió código de producción.

| Gate | Rama | Decisión | Evidencia |
|---|---|---|---|
| G1 | A/B/C | ¿draft mueve stock? | stock_inicial / stock_tras_draft / stock_tras_open + request/response |
| G2 | A/B | forma del error de stock | status (400/422) + response.errors |
| G3 | **B** | reference en POST/GET | Doc oficial: **ni** el `POST` documenta `reference` de entrada **ni** el `GET` documenta el query param `reference`; el `reference` del response es el del ítem. `T3.4` cae al fallback local. |
| G4 | **A** | paginate + HPOS | Core WC: `wc_get_orders()` devuelve `stdClass{orders,total,max_num_pages}` con `paginate=true` y es HPOS-safe por diseño. |
| G5 | A/B | ajustes enumerables por ítem | lista cruda de `GET /inventory-adjustments?item_id=` |
| G6 | **B (cerrado)** | orden de hooks de WC | `ANALYSIS-double-discount.md` §3 (`:187-209`); core WC `wc-stock-functions.php:75,207,238,381,411` |

---

## G1 — `draft` vs `open`

- `stock_inicial=` PENDING-LIVE
- `stock_tras_draft=` PENDING-LIVE
- `stock_tras_open=` PENDING-LIVE
- `rama=` PENDING-LIVE (A/B/C)

Comandos exactos (cuenta viva; token NUNCA pegado acá, redactado como `<token>`):

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

Decision tree: **A** `stock_inicial == stock_tras_draft > stock_tras_open`; **B** `stock_tras_draft < stock_inicial`;
**C** error/red/sin borradores ⇒ INCONCLUSO (bloquea `T2.4`/`T5.4` rama `invoice`; la rama `adjustment` titular sigue).

Contexto HEAD (la premisa "draft no mueve" es HYPOTHESIS hasta correr el comando):
`Inventory_Pusher.php:85-91` (condición DOBLE), `alegra-connector.php:462` (`invoice_status='draft'`),
`Orders.php:122-126` (override `'open'` sólo `owner==='invoice' && is_paid()`).

## G2 — forma del error de stock

- `status=` PENDING-LIVE
- `response_errors=` PENDING-LIVE
- `rama=` PENDING-LIVE (A/B/C)

```bash
# Elegir un ítem con stock BAJO conocido y pedir MUCHO más de lo disponible.
curl -s $AUTH "$BASE/items/$ITEM_ID" | python3 -m json.tool | grep -A3 '"inventory"'

curl -s $AUTH -X POST "$BASE/invoices" -H 'Content-Type: application/json' -d '{
  "client":{"id":"'"$CLIENT_ID"'"},
  "items":[{"id":"'"$ITEM_ID"'","price":1000,"quantity":999999}],
  "date":"2026-09-25","dueDate":"2026-09-25"
}' -w '\nHTTP %{http_code}\n'
```

Decision tree: **A** 400/422 sin código específico ⇒ `T4.1` no agrega símbolo; **B** 422 con
`response.errors` de stock mapeable ⇒ `T4.1` agrega `stock_insufficient` **sin cambiar** la
clasificación (sigue `failed_permanent`, `retriable=false`); **C** no-4xx ⇒ INCONCLUSO.

Reproducible en el harness sin cuenta (no reemplaza la captura viva):
`alegra_mock_fail('POST', '/invoices', 422, ['errors' => [['code' => 'stock']]])` (`alegra-mock.php:152-159`).

## G3 — `reference` en ajustes (DOCS/CODE — resuelto)

- `post_acepta_reference=` **no documentado** (el request sólo lista `date`, `observations`, `warehouse`,
  `costCenter`, `items[]{id,type,unitCost,quantity}`, `resolution`, `prefix`, `number`)
- `get_filtra_reference=` **no documentado** (el GET sólo lista `start`, `limit`, `order_direction`,
  `order_field`, `number`, `date`, `warehouse_id`, `metadata`)
- `reference_por_linea=` **el del ÍTEM** (`items[].reference`, p.ej. `"LOEM9483"`)
- `rama=` **B** (fallback local)

**Fuente oficial:** `https://developer.alegra.com/reference/get_inventory-adjustments`
(OpenAPI `Inventario` 1.0, descargado 2026-09-25) — el `reference` **no** aparece ni como propiedad del
requestBody del `POST` ni como query param del `GET`; sólo en el schema de response por línea.

**Evidencia code-inspectable (mock):** el `POST` guardaba el `reference` del **ítem** (`alegra-mock.php:903`),
no el del payload; el `GET` sólo filtraba `item_id` (`:765-782`). `T1.2` (H-B) extiende el mock **igual**
(guarda el `reference` top-level y filtra por él) para que el fallback de `T3.4` sea testeable; el gate
no depende de que la cuenta real lo soporte.

**Consecuencia:** `T3.4` filtra **localmente** (`item_id` + fecha + comparación del `reference` estable, DR5);
el `reference` estable en el payload se implementa de todos modos (auditoría + reintento del mismo movimiento).

## G4 — paginación/HPOS (CODE/DOCS — resuelto)

- `wc_version=` N/D (sin host; core ≥ 3.0 tiene `paginate`)
- `hpos_enabled=` N/D (HPOS-safe por diseño)
- `paginate_total=` **presente** (`stdClass->total`)
- `rama=` **A**

**Fuente oficial:** `woocommerce/includes/wc-order-functions.php` (trunk, 2026-09-25):

```php
/**
 * @return WC_Order[]|stdClass Number of pages and an array of order objects if
 *                             paginate is true, or just an array of values.
 */
function wc_get_orders( $args ) {
    ...
    $query = new WC_Order_Query( $args );
    return $query->get_orders();
}
```

El docblock de la función dice explícitamente *"This function should be used for order retrieval so
that when we move to custom tables, functions still work"* ⇒ es la API HPOS-safe y `paginate=true`
devuelve `stdClass{orders,total,max_num_pages}`. El stub del harness **no** lo soportaba
(`wp-stubs.php:1568-1612`, devolvía array): lo cierra `T1.3` (H-C).

**Consecuencia:** `T4.10`/`T4.11` usan el conteo O(1) cacheado con `wc_get_orders(['paginate'=>true,
'limit'=>1, <meta_query>])->total`.

Comando live (WP-CLI, para confirmar contra el host):

```bash
wp plugin get woocommerce --field=version --allow-root
wp option get woocommerce_custom_orders_table_enabled --allow-root
wp eval '$q = wc_get_orders(["paginate" => true, "limit" => 1, "return" => "objects"]); var_dump(is_object($q), $q->total ?? null);' --allow-root
```

## G5 — ajustes enumerables

- `item_id=` PENDING-LIVE
- `ajustes_listados=` PENDING-LIVE
- `rama=` PENDING-LIVE (A/B)

```bash
curl -s $AUTH "$BASE/inventory-adjustments?item_id=$ITEM_ID" | python3 -m json.tool
# Anotar cuántos devuelve y con qué campos: id, date, items[].{id,type,quantity}.
curl -s $AUTH "$BASE/inventory-adjustments?item_id=$ITEM_ID&start=0&limit=30" | python3 -m json.tool
```

Decision tree: **A** devuelve los ajustes del ítem (enumerables) ⇒ `T7.4` asistida; **B** no filtra ⇒
`T7.4` reparación manual pura. El mock **ya** filtra por `item_id` (`alegra-mock.php:765-782`), lo que
no prueba la cuenta real.

## G6 — orden de hooks (cerrado)

- `rama=B`
- `evidencia=wc_update_product_stock dispara woocommerce_product_set_stock (:75) dentro del loop (:207); woocommerce_reduce_order_stock corre después (:238).`

El diseño lo da por cerrado (`design.md` §13, fila G6). El pusher
(`Inventory_Pusher::on_stock_changed()`, `:114-125`) corre **antes** de
`woocommerce_reduce_order_stock` ⇒ un `Stock_Order_Context` poblado desde ese hook estaría vacío y
**NO se implementa**. La guarda manual usa el meta por producto `_alegra_stock_adjusted_at` (T2.2),
que **no** depende del orden de hooks (REQ-OWN-06, NFR-02). Ver `ANALYSIS-double-discount.md` §3
(`:187-209`) y core WC `wc-stock-functions.php:75,207,238,381,411`.

---

## DoD Fase 0

- [x] `phase0-results.md` existe con una fila por gate (G1–G6) y columna `rama`.
- [x] G3 resuelto desde la doc oficial (rama B, fallback local).
- [x] G4 resuelto desde el core de WC (rama A, `->total` + HPOS-safe).
- [x] G6 = Rama B (cerrado) con la evidencia del core de WC.
- [ ] G1/G2/G5 requieren la cuenta viva del comerciante (`PENDING-LIVE`); comandos arriba.
- [x] Ningún gate escribió código de producción (READ-ONLY).
- [x] Fase 1 no esperó a ningún gate.

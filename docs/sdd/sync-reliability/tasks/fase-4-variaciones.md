# Fase 4 — D4: variaciones en el camino de UPDATE + warehouse diferido (micro-detalle)

| Campo | Valor |
|---|---|
| Cambio | `sync-reliability` |
| Documentos base | `proposal.md` · `spec.md` · `design.md` (D4 §5, §15) · `tasks.md` |
| Tareas que expande | **T4.1–T4.5** (skeleton `tasks.md:363-376`) |
| Decisión de diseño | **D4** (`design.md:715-792`) · **REQ-INV-06** diferido (`design.md:1177-1183`) |
| Versión objetivo | **2.6.0** |
| Estado | T4.1, T4.2, T4.3, T4.5 `ACTIVO` · **T4.4 `BLOQUEADO(Fase 0.8 / G8)`** |
| Harness | `bash scripts/exec-test.sh` (→ `scripts/exec-test.php`; stubs `scripts/lib/wp-stubs.php`, mock `scripts/lib/alegra-mock.php`) |
| Depende de | Fase 2 (`Inventory_Writer` + W1 delegando). T4.1/T4.2 usan W1 → writer. |

> **Regla de oro (heredada del skeleton).** Después de que un test pase, **revertir el fix**, correr
> `bash scripts/exec-test.sh` y confirmar que **ese** test falla. Volver a aplicar el fix. Sin
> `prove-it-catches` el test no se acepta.

> **Todos los `file:line` fueron re-verificados en HEAD.** Las citas mal del skeleton/design se
> corrigen y se listan abajo.

---

## Correcciones de cita (verificadas en HEAD para Fase 4)

| # | Cita original (skeleton/design) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `spec.md:38` / `design.md` citan `Products.php:1611-1618` (creación itera hijos) | La rama de creación arranca en **`:1613`** (`if ($is_variable) {`); `:1611-1612` son el comentario. El `foreach` de hijos va de **`:1615` a `:1617`**. | T4.1 usa `:1613-1618`; la rama de creación **no se toca** (regresión). |
| C2 | `design.md:731` cita la rama existing en `:1548-1557` | Confirmado: `if ($existing_id)` `:1548`, `$this->update_product_from_alegra(...)` `:1554`, `assign_product_category` `:1555`, `return 'updated'` `:1556`. | T4.1 inserta la llamada **entre `:1555` y `:1556`**. |
| C3 | `tasks.md:368` cita `Products.php:1515-1517` (skip variant) | Confirmado exacto: `:1515` `if ($type === 'variant') {`, `:1516` `return 'skipped';`, `:1517` `}`. | T4.2 reemplaza ese bloque. |
| C4 | `design.md:750` cita `get_variant_children` sin línea | El método está en **`Products.php:1643-1652`**. Acepta `itemVariants` (`:1645`) y `subitems` (`:1648`). | T4.1 lo reusa. |
| C5 | `design.md:788` cita `import_variation_from_alegra()` sin línea | El método va de **`Products.php:1766` a `:1825`**. Hace `GET /items/{child}` en **`:1773`**; si existe → `update_product_from_alegra` **`:1782`** (→ W1 → writer). | T4.1/T4.2 lo usan; el fetch explícito es sólo para payloads sin hijos. |
| C6 | `tasks.md:369` cita `Admin_Dashboard.php:2316` (chunked skip) | Confirmado: `:2314` `$item_type = $item['type'] ?? 'simple';`, `:2315` comentario, `:2316` `if ($item_type === 'variant') { continue; }`. El handler público está en `Products.php:2584-2587` (`import_single_item_public`). | T4.3 reemplaza el `continue` por la llamada al handler. |
| C7 | `tasks.md:370` cita `Products.php:1238` (lee total) y `:1118-1137` (push bodega) | Confirmado: `:1238` `$new_qty = (int) $item['inventory']['availableQuantity'];`; `apply_warehouse()` `:1118-1137` (sólo en create, `:1129-1131`). | T4.4 **no** toca el poll; sólo agrega el aviso UI. |
| C8 | `design.md:755` cita `alegra-mock.php:880` (`variantParent_id`) | Confirmado: `alegra_mock_filter_items()` filtra por `variantParent.id` en **`:880-885`**. | T4.1 puede testear el fetch explícito. |
| C9 | **NUEVA — harness de paginación de contactos** | `alegra_mock_filter_contacts()` (`alegra-mock.php:849-867`) **NO** aplica `start`/`limit`: devuelve **todos** los contactos filtrados. El mock de `/items` sí slicea `start`/`limit`, pero **sólo** cuando `metadata=true` (`:663-669`). | **REQ-CF-05 (Fase 5) no es testeable sin este seam.** Se registra como **H3b** y se implementa junto a H3 (`T1.3`). Impacta T5.1, no T4.x, pero se documenta acá para no perderlo. |
| C10 | `tasks.md:370` cita `templates/admin-settings.php` para el aviso de bodega, sin línea | Los controles de bodega viven en la pestaña **Bodegas** (`#tab-warehouse`), `templates/admin-settings.php:162-180`. `warehouse_enabled` `:166`; `warehouse_id` `:169`/`:177`. | T4.4 inserta el aviso dentro del bloque `:178-179` (antes de cerrar `</table>`). |

**Citas confirmadas exactas (no requieren corrección):** `Products.php:1488` (`import_single_item_from_alegra`), `:1546` (`get_product_by_alegra_id`), `:1937-1941` (`resolve_preserve_fields`), `:1943-2026` (`update_product_from_alegra`), `:2006-2008` (W1 respeta preserve/fuente), `:2052-2100` (`apply_inventory_to_product`, W1), `:2584-2587` (`import_single_item_public`), `:2658` (`get_product_by_alegra_id`); `Admin_Dashboard.php:2305` (`$is_last`), `:2309-2332` (loop chunked), `:2326` (`import_single_item_public`); `scripts/lib/alegra-mock.php:70` (`alegra_mock_seed_item`), `:968-998` (`alegra_mock_materialize_variant_children`, flag `$GLOBALS['alegra_mock_variant_children_in_response']` en `:996`).

---

## Fase 4 — Objetivo

- Un cambio de stock de una **variación** en Alegra se refleja en WC por el camino de **update**
  (webhook/import), no sólo al crear el padre. (REQ-INV-03)
- Un ítem `type=variant` **suelto** se aplica a su variación hija o se loguea por qué no — nunca se
  descarta en silencio. (REQ-INV-03)
- El chunked ("Traer desde Alegra") **rutea** las variantes por el mismo handler, en vez de saltearlas.
  (REQ-INV-03)
- Warehouse-aware queda **DIFERIDO** (G8/R7): el poll sigue leyendo el total, pero Ajustes **advierte**
  cuando hay bodega configurada. (REQ-INV-06)

**DoD de la fase:** REQ-INV-03 verde; el update de un `variantParent` refresca hijos; el `type=variant`
suelto no se saltea mudo; el chunked rutea variantes; el aviso de bodega se renderiza; no hay
regresión en la **creación** de variantes (`Products.php:1613-1618` intacto).

---

### T4.1 — `refresh_variant_children()` en el camino de update

**Objetivo**: que el update de un `variantParent` (o de un `kit`) refresque el stock de cada variación
hija, no sólo el del padre.

**Descripción técnica**: hoy `import_single_item_from_alegra()` (`Products.php:1488`), en la rama de
producto existente (`:1548-1557`), llama `update_product_from_alegra($product,$item)` y retorna
`'updated'` **sin iterar los hijos**. La rama de **creación** sí itera (`:1613-1618`).
`update_product_from_alegra()` (`:1943`) no recorre hijos. Consecuencia: si el padre ya existe, un
cambio de stock de una variación en Alegra **nunca** llega a WC por el update (sólo por el poll, que no
es el camino de este FRONT). Decisión **D4** (`design.md:717-763`); cubre **REQ-INV-03**.

**Desarrollo técnico**

Archivo: `includes/Sync/Products.php`.

**1) Método nuevo** — insertar junto a `get_variant_children()` (`:1643`), antes o después de él:

```php
/**
 * Refresca las variaciones hijas de un variantParent/kit existente (D4).
 *
 * Reusa `import_variation_from_alegra()` (que hace GET /items/{child} y, si
 * existe, update_product_from_alegra → W1 → Inventory_Writer).
 *
 * Si el payload del webhook no trae `itemVariants`/`subitems`, se traen con
 * GET /items?variantParent_id={id}. SIN VERIFICAR en producción (G2): el mock
 * soporta el filtro (alegra-mock.php:880).
 */
private function refresh_variant_children(int $parent_id, array $item): void
{
    $children = $this->get_variant_children($item);

    if (empty($children)) {
        // El payload no trae hijos (típico de un webhook de edit-item).
        $alegra_parent = (string) ($item['id'] ?? '');
        if ($alegra_parent !== '') {
            $fetched = $this->api->get_items([
                'variantParent_id' => $alegra_parent,
                'limit'            => 100,
            ]);
            if (!is_wp_error($fetched) && is_array($fetched)) {
                $children = $fetched;
            } else {
                $this->logger->warning('No se pudieron traer las variaciones del padre', [
                    'parent' => $alegra_parent,
                    'error'  => is_wp_error($fetched) ? $fetched->get_error_message() : 'bad_response',
                ]);
            }
        }
    }

    foreach ($children as $child) {
        if (is_array($child)) {
            $this->import_variation_from_alegra($parent_id, $child);
        }
    }
}
```

**2) Call site** — rama existing de `import_single_item_from_alegra()`.

**ANTES** (`Products.php:1548-1557`):
```php
            if ($existing_id) {
                $product = wc_get_product($existing_id);
                if ($product) {
                    // AC-07: make sure the indexed map is populated even for a
                    // product found through the (legacy) postmeta fallback.
                    \Alegra\Connector\Entity_Map::map('item', $alegra_id, 'product', (int) $existing_id);
                    $this->update_product_from_alegra($product, $item);
                    $this->assign_product_category((int) $existing_id, $item);
                    return 'updated';
                }
            }
```

**DESPUÉS:**
```php
            if ($existing_id) {
                $product = wc_get_product($existing_id);
                if ($product) {
                    // AC-07: make sure the indexed map is populated even for a
                    // product found through the (legacy) postmeta fallback.
                    \Alegra\Connector\Entity_Map::map('item', $alegra_id, 'product', (int) $existing_id);
                    $this->update_product_from_alegra($product, $item);
                    $this->assign_product_category((int) $existing_id, $item);

                    // D4: refrescar variaciones TAMBIÉN en update.
                    if ($product->is_type('variable')
                        || $type === 'variantParent'
                        || $type === 'kit'
                    ) {
                        $this->refresh_variant_children((int) $existing_id, $item);
                    }

                    return 'updated';
                }
            }
```

**Orden de operaciones (no invertir):**
1. `Entity_Map::map()` (idempotente).
2. `update_product_from_alegra($product, $item)` — actualiza el padre (precio, nombre, etc.). Si el
   padre es `variable`, W1 (`:2058-2061`) le fuerza `set_manage_stock(false)` y sale; el stock vive en
   las variaciones.
3. `assign_product_category()`.
4. `refresh_variant_children()` — por cada hijo: `import_variation_from_alegra()` (`:1766`) → si el
   hijo ya existe en WC, `update_product_from_alegra($variation, $item)` (`:1782`) → W1 → writer.

**Notas de diseño (importantes para el worker):**
- **El fetch explícito es condicional.** `get_variant_children()` (`:1643`) ya devuelve los hijos si el
  payload trae `itemVariants`/`subitems`. El `GET /items?variantParent_id` sólo corre cuando el payload
  viene **sin** hijos (payload de webhook). El mock soporta el filtro (`alegra-mock.php:880-885`) y
  además tiene el flag `$GLOBALS['alegra_mock_variant_children_in_response']` (`:996`) para forzar el
  caso "sin hijos en el payload".
- **SIN VERIFICAR (producción):** que un payload real de `edit-item`/`new-item` traiga o no
  `itemVariants`. Por eso el diseño trae los hijos cuando faltan; el requerimiento (refrescar en update)
  se cumple en **ambas** ramas de G2 (`design.md:791`).
- **No se toca la creación.** El bloque `:1613-1618` queda **idéntico** (regresión cero).
- **Anti-loop:** `import_variation_from_alegra` → `update_product_from_alegra` setea el transient
  `alegra_updating_product_{variation_id}` (`:1954`) y `on_update_product` lo consulta
  (`public/Public/Public_.php:229`), así que no hay cascada por `save()`.

**Resultado esperado**
- Dado un producto variable existente con la variación V vinculada a un item de Alegra, cuando Alegra
  cambia el stock de V y llega el update del padre, **la variación V recibe el nuevo stock**.
- La creación de un `variantParent` nuevo sigue creando las variaciones con stock (comportamiento
  actual).
- Si el payload no trae hijos, se emite **un** `GET /items?variantParent_id={id}` (no uno por hijo) y
  luego `GET /items/{child}` por hijo existente (`import_variation_from_alegra:1773`).

**Dependencias**: T2.1 (`Inventory_Writer`), T2.3 (W1 delega), T1.4 (mock `/items` con inventario).
T4.1 depende de **G2 sólo para la rama de tiempo real** (si `edit-item` no dispara con un cambio de
stock, el poll es el único camino, pero el update cubre igual — `design.md:791`).

**Trazabilidad**: REQ-INV-03 (y REQ-INV-02 vía W1/writer).

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.41 update of a variantParent refreshes child variation stock (D4)', function (): void {
    alegra_test_reset();

    // Padre ya existente en WC (variable) + variación hija mapeada.
    $parent = alegra_make_variable_product(1000, ['color' => ['name' => 'Color', 'options' => ['Rojo']]], [
        1001 => ['variation_attributes' => ['attribute_color' => 'Rojo']],
    ]);
    update_post_meta(1000, '_alegra_item_id', 'par-1');
    update_post_meta(1001, '_alegra_item_id', 'var-1');
    \Alegra\Connector\Entity_Map::map('item', 'par-1', 'product', 1000);
    \Alegra\Connector\Entity_Map::map('item', 'var-1', 'product', 1001);

    // El item del padre (update) NO trae itemVariants → fuerza el fetch explícito.
    alegra_mock_seed_item('par-1', ['name' => 'P', 'type' => 'variantParent', 'inventory' => ['availableQuantity' => 9]]);
    alegra_mock_seed_item('var-1', [
        'name' => 'P / Rojo', 'type' => 'variant', 'variantParent' => ['id' => 'par-1'],
        'inventory' => ['availableQuantity' => 3],
    ]);

    $p = make_products();
    $r = alegra_call_private($p, 'import_single_item_from_alegra', [
        'id' => 'par-1', 'name' => 'P', 'type' => 'variantParent',
        // sin itemVariants a propósito
    ], 0);

    TestRunner::assertSame('updated', $r, 'the parent must be updated');
    $variation = wc_get_product(1001);
    TestRunner::assertSame(3, (int) $variation->get_stock_quantity(), 'the child variation must receive the new stock');
    TestRunner::assertTrue(alegra_mock_count('GET', '/items') >= 2, 'the explicit child fetch must run when the payload has no children');
});
```
> El seed real es `alegra_mock_seed_item(string $id, array $data = [])` (`alegra-mock.php:70`).
> `alegra_make_variable_product()` está en `test-framework.php:260`. Ajustar el shape de `inventory` al
> que consume W1 (`inventory.availableQuantity`, `Products.php:2074`).

**Prove-it-catches**: quitar el bloque `if (...) { $this->refresh_variant_children(...); }` de la rama
existing → la variación queda con el stock viejo → `T29.41` rojo. Quitar el fetch explícito (dejar sólo
`get_variant_children`) → `T29.41` rojo (el payload no trae hijos).

**Riesgo**: **DR13** — un `GET /items/{child}` por hijo en cada update de padre (costo). Guard: el fetch
de la **lista** es uno solo y sólo si faltan hijos; `import_variation_from_alegra` ya hacía el `GET` por
hijo (comportamiento preexistente de la rama de creación). Si el payload trae hijos, no hay fetch extra.

**Estimación**: M (2 h).

---

### T4.2 — `type=variant` suelto: resolver el padre, nunca skip mudo

**Objetivo**: que un ítem `type=variant` que llega solo (webhook `edit-item` de una variación) se aplique
a su variación hija, o deje un log explícito si no puede.

**Descripción técnica**: hoy el import por ítem saltea todo `type=variant` en
`Products.php:1515-1517` con un `return 'skipped'` **mudo**: no se aplica al hijo ni se loguea. Un
webhook de una variación nunca se refleja. Decisión **D4** (`design.md:766-782`); cubre **REQ-INV-03**
("no se descarta sin señal").

**Desarrollo técnico**

Archivo: `includes/Sync/Products.php`.

**1) Quitar el bloque temprano** (`Products.php:1514-1517`):
```php
        // Skip variant children - they're imported with their parent
        if ($type === 'variant') {
            return 'skipped';
        }
```

**2) Insertar la resolución DENTRO del `try`**, después del guard de re-entrada
(`Public_::set_syncing`, `:1538-1543`) y **antes** de `$existing_id = $this->get_product_by_alegra_id($alegra_id);`
(`:1546`):

```php
            // D4: un item variant suelto se aplica a su variación hija.
            // Se resuelve DENTRO del try para que el finally libere el guard de
            // sincronización. Nunca se saltea en silencio.
            if ($type === 'variant') {
                $parent_alegra_id = (string) ($item['variantParent']['id'] ?? '');
                if ($parent_alegra_id === '') {
                    $this->logger->warning('Item variant sin variantParent; no se puede aplicar', [
                        'alegra_id' => $alegra_id,
                    ]);
                    return 'skipped';
                }
                $parent_wc = $this->get_product_by_alegra_id($parent_alegra_id);
                if (!$parent_wc) {
                    $this->logger->warning('Item variant con padre no importado; se ignora con señal', [
                        'alegra_id' => $alegra_id,
                        'parent'    => $parent_alegra_id,
                    ]);
                    return 'skipped';
                }
                $this->import_variation_from_alegra((int) $parent_wc, $item);   // update ⇒ W1
                return 'updated';
            }
```

**Refinamiento sobre el design (deliberado, se documenta).** El design muestra el bloque
"reemplazando `:1515-1517`" (es decir, **antes** del guard `set_syncing`). Se mueve **dentro** del
`try` para que: (a) el `finally` (`:1629-1634`) libere el guard si algo tira; (b) el tombstone guard
(`:1519-1535`) siga aplicando; (c) el `run_id`/`should_stop` (`:1504-1507`) ya se haya chequeado. El
resultado observable es el mismo del design. **No** cambia la firma ni el contrato de retorno
(`bool|string`).

**Resultado esperado**
- Un `type=variant` con `variantParent.id` de un padre ya importado se aplica al hijo (stock vía W1) y
  devuelve `'updated'`.
- Un `type=variant` **sin** `variantParent.id` devuelve `'skipped'` y deja
  `warning('Item variant sin variantParent...')`.
- Un `type=variant` con padre **no importado** devuelve `'skipped'` y deja
  `warning('Item variant con padre no importado...')`.
- **Ningún** camino es silencioso.

**Dependencias**: T2.1, T2.3 (W1 → writer). Independiente de T4.1 (se puede hacer antes o después;
ambos comparten `import_variation_from_alegra`).

**Trazabilidad**: REQ-INV-03.

**Verificación** (runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.42 a loose type=variant item is applied to its child or logged, never silently skipped', function (): void {
    alegra_test_reset();

    // Padre ya importado.
    $parent = alegra_make_variable_product(1000, ['color' => ['name' => 'Color', 'options' => ['Rojo']]], [
        1001 => ['variation_attributes' => ['attribute_color' => 'Rojo']],
    ]);
    update_post_meta(1000, '_alegra_item_id', 'par-1');
    update_post_meta(1001, '_alegra_item_id', 'var-1');
    \Alegra\Connector\Entity_Map::map('item', 'par-1', 'product', 1000);
    \Alegra\Connector\Entity_Map::map('item', 'var-1', 'product', 1001);

    alegra_mock_seed_item('var-1', [
        'name' => 'P / Rojo', 'type' => 'variant',
        'variantParent' => ['id' => 'par-1'],
        'inventory' => ['availableQuantity' => 5],
    ]);

    $p = make_products();
    $r = alegra_call_private($p, 'import_single_item_from_alegra', [
        'id' => 'var-1', 'type' => 'variant', 'variantParent' => ['id' => 'par-1'],
    ], 0);

    TestRunner::assertSame('updated', $r, 'a loose variant with a known parent must be applied');
    TestRunner::assertSame(5, (int) wc_get_product(1001)->get_stock_quantity(), 'the child stock must be updated');

    // Sin variantParent → skipped con señal (no mudo).
    $r2 = alegra_call_private($p, 'import_single_item_from_alegra', ['id' => 'var-2', 'type' => 'variant'], 0);
    TestRunner::assertSame('skipped', $r2, 'a variant without a parent must be skipped');
    TestRunner::assertTrue(alegra_test_last_log_contains('variantParent'), 'the skip must be logged with a reason');
});
```
> `alegra_test_last_log_contains()` es un helper del harness; si no existe, usar el patrón de captura
> de log que ya usan los tests de `logs-monitor-import` (el `Logger` escribe en el array del stub). El
> worker debe verificar el helper real antes de escribir el test.

**Prove-it-catches**: reponer `if ($type === 'variant') { return 'skipped'; }` al principio → el primer
`assertSame('updated', $r)` falla.

**Riesgo**: que un payload viejo no traiga `variantParent` (guard + log). Que el padre no esté
importado todavía (log + `skipped`; el chunked lo reintenta en otra corrida).

**Estimación**: S/M (1.5 h).

---

### T4.3 — El chunked rutea las variantes por el handler

**Objetivo**: que "Traer desde Alegra" (chunked) deje de saltear `type=variant` en silencio y lo rutee
por `import_single_item_public()`, que ya resuelve el padre (T4.2).

**Descripción técnica**: `Admin_Dashboard::ajax_sync_page()` saltea las variantes con un `continue`
mudo en `:2316`. Con T4.2, `import_single_item_from_alegra()` ya sabe resolver un `type=variant` suelto
(y devolver `'updated'`/`'skipped'`). Por eso el `continue` se reemplaza por la llamada al handler.
Decisión **D4** (`design.md:785-786`); cubre **REQ-INV-03**.

**Desarrollo técnico**

Archivo: `admin/Admin/Admin_Dashboard.php`, dentro del loop `foreach ($items as $item)`
(`:2309-2332`).

**ANTES** (`:2314-2316`):
```php
                $item_type = $item['type'] ?? 'simple';
                // Skip variants - imported with their parent
                if ($item_type === 'variant') { continue; }
```

**DESPUÉS** (`:2314-2316` se elimina; el `$item_type` sigue usándose en el filtro de `:2321`):
```php
                $item_type = $item['type'] ?? 'simple';
                // D4: las variantes sueltas se rutean por el handler, que resuelve
                // el variantParent (import_single_item_from_alegra). Ya no hay skip mudo.
```

El resto del loop queda igual: el `$r = $products_sync->import_single_item_public($item, $run_id);`
(`:2326`) ya maneja `'updated'`/`'skipped'` (`:2329-2330`).

**Orden de operaciones:**
1. `$item_type` se lee (necesario para el filtro `variantParent` de `:2321`).
2. Se quita el `continue` de variantes.
3. El filtro de `:2321` (si el comerciante pidió `type=variantParent`, descarta los no-`variantParent`)
   sigue igual; una variante suelta con ese filtro se cuenta como `skipped` (`:2322-2323`).
4. `import_single_item_public()` (`Products.php:2584`) → `import_single_item_from_alegra()` (T4.2).

**Resultado esperado**
- Un `type=variant` en una página del chunked ya **no** se descarta: se rutea y devuelve
  `'updated'`/`'skipped'`, contabilizado en `$state['updated']`/`$state['skipped']`.
- El `$index` avanza igual (no se saltea el offset).
- El filtro `type=variantParent` sigue descartando lo que no es padre.

**Dependencias**: T4.2 (el handler debe resolver variantes). Sin T4.2, rutear una variante al handler
caería en el `return 'skipped'` temprano (no rompe, pero no cumple REQ-INV-03).

**Trazabilidad**: REQ-INV-03.

**Verificación** (source-scan + runtime, `scripts/exec-test.php`):
```php
TestRunner::test('T29.43 the chunked handler routes variants instead of skipping them (D4)', function (): void {
    $src = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'admin/Admin/Admin_Dashboard.php');
    TestRunner::assertStringNotContains("if (\$item_type === 'variant') { continue; }", $src, 'the silent variant skip must be gone');
    TestRunner::assertStringContains('import_single_item_public($item, $run_id)', $src, 'variants must be routed through the handler');
});
```

Runtime (opcional, si el worker quiere cobertura de comportamiento): sembrar un batch state con un
ítem `type=variant` y capturar `ajax_sync_page`, assertando que `$state['skipped']` o `$state['updated']`
se mueve (no que se ignora). El worker debe verificar el shape real de `alegra_batch_state` antes de
escribirlo (ver `Admin_Dashboard.php:2068+`).

**Prove-it-catches**: reponer `if ($item_type === 'variant') { continue; }` → el source-scan falla.

**Riesgo**: que el handler de T4.2 no esté aplicado y la variante se cuente como `skipped` (correcto,
no mudo). Que el `$index`/offset se desalinee (no: el `continue` no avanzaba `$index` de forma
especial; el `$index++` está al principio del loop, `:2310`).

**Estimación**: S (1 h).

---

### T4.4 — Warehouse-aware DIFERIDO + aviso en Ajustes · BLOQUEADO(Fase 0.8 / G8)

**Objetivo**: que el comerciante sepa que, con una bodega configurada, el poll sigue leyendo el **total**
de Alegra y no la bodega elegida.

**Descripción técnica**: hoy el poll lee `inventory.availableQuantity` (total, `Products.php:1238`)
mientras el **push** (productos/facturas) escribe la bodega configurada (`apply_warehouse()`
`:1118-1137`; factura `Orders.php:1122-1125`). Si el item tiene stock repartido entre bodegas, el poll
puede escribir un valor distinto al de la bodega elegida (REQ-INV-06). El diseño **difiere**
warehouse-aware (R7 del SDD `inventory`, SIN VERIFICAR; `design.md:1177-1183`) y **exige** el aviso
honesto. Cubre **REQ-INV-06** (escenario 2). **Condicional a G8**: si G8 cierra en "se implementa",
esta tarea cambia de alcance (ver "Rama A / Rama B").

**Desarrollo técnico**

Archivo: `templates/admin-settings.php`, pestaña **Bodegas** (`#tab-warehouse`, `:162-180`).

Insertar **antes** de `</table>` (`:179`), dentro del bloque `:162-180`:

```php
<?php
$ac_wh_enabled = (bool) get_option('alegra_connector_warehouse_enabled', false);
$ac_wh_id      = (string) get_option('alegra_connector_warehouse_id', '');
if ($ac_wh_enabled && $ac_wh_id !== '' && $ac_wh_id !== '0'):
?>
<tr><td colspan="2">
<div class="ac-notice warning" style="margin:0;">
<strong><?php esc_html_e('Aviso sobre el inventario por bodega','alegra-connector');?></strong><br>
<?php esc_html_e('Tenés una bodega configurada. Los productos y facturas se envían a esa bodega, pero la sincronización de inventario (Alegra → WooCommerce) todavía lee el stock TOTAL del artículo, no el de la bodega elegida. Si repartís stock entre varias bodegas, el número que ves en WooCommerce puede no coincidir con la bodega configurada.','alegra-connector');?>
</div>
</td></tr>
<?php endif;?>
```

**Por qué en la pestaña Bodegas y no en Sincronización:** es donde el comerciante configura
`warehouse_enabled`/`warehouse_id` (`:166`/`:169`/`:177`); el aviso aparece pegado a la causa. El
`design.md:960` ubicaba la tarjeta de cron en Avanzado, pero el aviso de bodega corresponde a Bodegas.
Se documenta como corrección de ubicación (C10).

**Resultado esperado**
- Con `warehouse_enabled=true` y `warehouse_id` no vacío/no `'0'`, la pestaña Bodegas muestra el aviso
  "Tenés una bodega configurada..." en tono `warning` (no `error`).
- Sin bodega configurada (o `warehouse_id=''`/`'0'`), el aviso **no** aparece (sin falso positivo).
- El aviso **no** es un error bloqueante: es informativo.

**Rama A / Rama B (G8, `T0.8`)**
- **Rama A** (se implementa warehouse-aware): esta tarea cambia: en vez del aviso, el poll lee
  `inventory.warehouses[id==warehouse_id].availableQuantity`. Queda **fuera** de este plan (no está
  diseñada la resolución de bodega en el poll) y se registra en `phase0-results.md`.
- **Rama B** (diferido, **la esperada** por `design.md:1173,1179-1183`): se implementa el aviso tal
  cual. **No cerrar la tarea sin el resultado de T0.8 registrado.**

**Dependencias**: **T0.8 (G8)** para el cierre de la rama. Independiente del resto de Fase 4.

**Trazabilidad**: REQ-INV-06 (escenario 2, diferido declarado).

**Verificación** (source-scan, `scripts/exec-test.php`):
```php
TestRunner::test('T29.44 the warehouse notice is rendered in settings when a warehouse is configured', function (): void {
    $tpl = (string) file_get_contents($GLOBALS['alegra_plugin_root'] . 'templates/admin-settings.php');
    TestRunner::assertStringContains("alegra_connector_warehouse_enabled", $tpl, 'the notice must be conditional on the warehouse flag');
    TestRunner::assertStringContains('stock TOTAL del artículo', $tpl, 'the notice must state that the poll reads the total');
});
```
Manual: Ajustes → Bodegas → activar bodega + elegir una → guardar → recargar → el aviso aparece.
Desactivar la bodega → el aviso desaparece.

**Prove-it-catches**: quitar el `if ($ac_wh_enabled && $ac_wh_id !== '' ...)` → el source-scan del
texto condicional falla (el aviso se mostraría siempre).

**Riesgo**: que el comerciante crea que la bodega **sí** se respeta (mitigado por el texto explícito).
Que el aviso alarma de más (tono `warning`, no `error`; sólo con bodega configurada).

**Estimación**: S/M (1.5 h).

---

### T4.5 — Tests de variaciones (cierre de fase)

**Objetivo**: consolidar la cobertura de REQ-INV-03 y el `prove-it-catches` de la fase.

**Descripción técnica**: agrupa los tests `T29.41`–`T29.44`, más un test de **no regresión** de la
creación de variantes. Cubre REQ-INV-03.

**Desarrollo técnico**

Archivo: `scripts/exec-test.php`, sección `// === sync-reliability (2.6.0) ===` (T1.11).

Tests a dejar verdes (detalle en cada tarea):
| Test | Qué fija | Tarea |
|---|---|---|
| `T29.41` | El update del padre refresca el stock del hijo (con y sin `itemVariants` en el payload). | T4.1 |
| `T29.42` | `type=variant` suelto → hijo, o `skipped` + log (nunca mudo). | T4.2 |
| `T29.43` | Source-scan: el chunked rutea variantes (no `continue`). | T4.3 |
| `T29.44` | Source-scan: aviso de bodega condicional. | T4.4 |
| `T29.45` | **No regresión:** crear un `variantParent` nuevo sigue creando hijos con stock (`:1613-1618` intacto). | T4.5 |

Esqueleto del test de no regresión:
```php
TestRunner::test('T29.45 creating a new variantParent still creates its children with stock (no regression)', function (): void {
    alegra_test_reset();
    alegra_mock_seed_item('par-new', [
        'name' => 'Nuevo', 'type' => 'variantParent',
        'itemVariants' => [
            ['id' => 'var-new', 'variantAttributes' => [['id' => 'a1', 'value' => 'Rojo']],
             'inventory' => ['availableQuantity' => 7]],
        ],
    ]);
    alegra_mock_seed_item('var-new', [
        'name' => 'Nuevo / Rojo', 'type' => 'variant', 'variantParent' => ['id' => 'par-new'],
        'inventory' => ['availableQuantity' => 7],
    ]);

    $p = make_products();
    $r = alegra_call_private($p, 'import_single_item_from_alegra', [
        'id' => 'par-new', 'name' => 'Nuevo', 'type' => 'variantParent',
        'itemVariants' => [['id' => 'var-new']],
    ], 0);

    TestRunner::assertTrue($r === true, 'the parent must be created');
    $child_id = $p->get_product_by_alegra_id ?? null; // usar el helper real del harness
    // Assertar que existe una variación con _alegra_item_id = 'var-new' y stock 7.
    TestRunner::assertSame(7, (int) wc_get_product(/* id de la variación */)->get_stock_quantity(), 'the child must be created with stock');
});
```
> El worker debe usar el lookup real (`\Alegra\Connector\Entity_Map::lookup('item', 'var-new')` o
> `get_post_meta` por `_alegra_item_id`) y no inventar un helper. El `get_product_by_alegra_id` es
> `private` (`:2658`); usar `alegra_call_private` o el `Entity_Map`.

**Resultado esperado**
- `T29.41`–`T29.45` verdes; `bash scripts/exec-test.sh` sin `failed` nuevos.
- Cada test central (`T29.41`, `T29.42`) con su `prove-it-catches` documentado.

**Dependencias**: T4.1–T4.4.

**Trazabilidad**: REQ-INV-03.

**Verificación**: `bash scripts/exec-test.sh` → `EXEC-TEST OK` con los `T29.4x` verdes. Manual: editar
el stock de una variación en Alegra → disparar el webhook/import update del padre → WC refleja.

**Prove-it-catches**: documentado en cada tarea; el de fase es revertir `refresh_variant_children`
(T4.1) → `T29.41` rojo.

**Riesgo**: tests que dependan del orden de páginas del mock (mitigado por seeds explícitos). Tests que
asuman un helper inexistente (el worker verifica `scripts/lib/test-framework.php` antes).

**Estimación**: M (2 h).

---

## DoD Fase 4

- REQ-INV-03 verde (update refresca hijos; `type=variant` no mudo; chunked rutea).
- REQ-INV-06 declarado diferido + aviso UI (condicional a G8).
- La creación de variantes (`Products.php:1613-1618`) queda **intacta** (regresión cero).
- El fetch explícito `GET /items?variantParent_id` corre **sólo** si el payload no trae hijos.
- `T29.41`–`T29.45` verdes con `prove-it-catches` aplicados.
- **H3b** (paginación de `/contacts` en el mock) registrado como dependencia de Fase 5.

## Índice de tests nuevos (Fase 4)

| Test | Tipo | Archivo |
|---|---|---|
| `T29.41 update of a variantParent refreshes child variation stock (D4)` | runtime | `scripts/exec-test.php` |
| `T29.42 a loose type=variant item is applied to its child or logged, never silently skipped` | runtime | `scripts/exec-test.php` |
| `T29.43 the chunked handler routes variants instead of skipping them (D4)` | source-scan | `scripts/exec-test.php` |
| `T29.44 the warehouse notice is rendered in settings when a warehouse is configured` | source-scan | `scripts/exec-test.php` |
| `T29.45 creating a new variantParent still creates its children with stock (no regression)` | runtime | `scripts/exec-test.php` |

## Trazabilidad tarea → requerimiento

| Requerimiento | Tareas |
|---|---|
| REQ-INV-03 | T4.1, T4.2, T4.3, T4.5 |
| REQ-INV-06 | T4.4 (diferido + aviso) |
| REQ-INV-02 (consumido) | T4.1, T4.2 (vía W1 → `Inventory_Writer`) |

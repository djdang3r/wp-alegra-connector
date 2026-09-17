# Diseño Técnico — Subsistema de Inventario

| Campo | Valor |
|---|---|
| Cambio | `inventory` |
| Documento base | `docs/INVENTORY_DESIGN.md` (corregido) · `spec.md` |
| Versión | 2.3.3 (`alegra-connector.php:6`) |
| Naturaleza | Diseño técnico (no implementación) |

> Claims de código con `archivo:línea`. Claims de Alegra con URL. Lo no verificado,
> **SIN VERIFICAR**. Las decisiones marcadas `BLOQUEADO` dependen de la Fase 0
> (`tasks.md`).

---

## 1. Arquitectura de la solución

### 1.1 Principios

1. **Un solo escritor.** `Inventory_Writer::apply()` es el único lugar del plugin
   que llama `set_manage_stock()`, `set_stock_quantity()` y `set_stock_status()`.
2. **Alegra manda o WC manda, nunca ambos.** `inventory_source` decide; el modo
   WC-wins no escribe stock en WC.
3. **La ausencia de dato no es cero.** `availableQuantity` ausente ⇒ SKIP, no 0.
4. **La factura es el único mecanismo que mueve el stock de Alegra.** Nunca
   ajustes automáticos desde WC en Alegra-wins.
5. **Opt-in para tocar la gestión de stock de WC.** Migración separada de la
   fuente.

### 1.2 Componentes

```
                 ┌─────────────────────────────┐
                 │  Controller::run_cron_sync  │
                 │  (Controller.php:90)        │
                 └──────────────┬──────────────┘
                                │ si sync_products && inventory_source=alegra
                                ▼
   Admin button ──► ┌───────────────────────────────┐ ◄── WP-CLI action
   (nuevo AJAX)     │ Products::sync_inventory_     │     alegra_sync_inventory_from_alegra
                    │ from_alegra() (Products:447)  │
                    └───────────────┬───────────────┘
                                    │ por item (GET /items?mode=advanced)
                                    ▼
                    ┌───────────────────────────────┐
                    │ Inventory_Writer::apply()      │  ◄── Products::update_product_
                    │ (includes/Sync/Inventory_      │      from_alegra() (Products:1152)
                    │  Writer.php)  [ÚNICO ESCRITOR] │
                    └───────────────┬───────────────┘
                                    │ set_manage_stock + set_stock_quantity
                                    │ + set_stock_status + save()
                                    ▼
                              WC Product / Variation
```

### 1.3 Decisión de estado de factura (D1)

```
create_invoice(order, status_override)
        │
        ▼
prepare_invoice_data(order, override)   (Orders.php:592)
        │
        ├─ override != null ──────────────► override
        │
        ├─ open_invoice_on_paid == true
        │  Y order_is_paid(order) ────────► 'open'
        │
        └─ default ──────────────────────► get_option('invoice_status','draft')
```

Y en paralelo, la **apertura diferida**:

```
woocommerce_payment_complete / woocommerce_order_status_completed
        │  (registrados FUERA del gating de push — Public_.php:49-59)
        ▼
Public_::on_order_paid_open_invoice(order_id)
        │
        ▼
Orders::on_order_paid_open_invoice(order_id)
        │
        ├─ _alegra_invoice_id vacío ─► no hacer nada (NO factura)
        └─ existe ───────────────────► ensure_invoice_open(id)  (Orders.php:322)
```

---

## 2. Árbol de decisión del pull

```
¿Kill_Switch::is_active()?  (AGREGAR — hoy ausente en Products.php:447)
├── SÍ → abortar (log)
└── NO
    ¿inventory_source == 'woocommerce'?
    ├── SÍ → skip total (Products.php:453)
    └── NO
        ¿lock 'products' tomado? → abortar (Products.php:459)
        │
        for page in 1..max_pages:
          ¿transient 'alegra_sync_cancelled'? → break (Products.php:471)
          GET /items?start&limit=30&mode=advanced  (Products.php:495)
          ¿WP_Error? → guardar cursor + salir (NO break seco)
          ¿vacío? → fin
          for item:
            ¿inventory ausente? → servicio: manage=no, sin stock
            ¿availableQuantity ausente/null? → SKIP+WARN
            ¿warehouse_id válido?
              ¿está en warehouses[]? → qty = warehouses[id].availableQuantity
              ¿no está? → SKIP+WARN (nunca 0)
            ¿warehouse_enabled con id ''/0? → C6: tratar como sin bodega + WARN
            else → qty = inventory.availableQuantity
            ¿qty < 0? → clamp 0 + WARN
            ¿_alegra_manage_stock_opt_out? → SKIP (excluido)
            resolver entidad WC (producto / variación / padre)
            Inventory_Writer::apply(product, item, opts)
```

---

## 3. Contratos de código

### 3.1 `Inventory_Writer` (nuevo)

**Archivo:** `includes/Sync/Inventory_Writer.php`
**Namespace:** `Alegra\Connector\Sync`

```php
final class Inventory_Writer {
    public function __construct(private ?Logger\Logger $logger = null) {}

    /**
     * @param array $opts {
     *   manage_stock_enabled: bool,   // de la opción de migración
     *   warehouse_id: string,         // ya normalizado ('' si no aplica)
     *   dry_run: bool,
     * }
     * @return string updated|skipped_no_qty|skipped_service|skipped_opt_out
     *                |skipped_warehouse_missing|skipped_not_manageable|error
     */
    public function apply(\WC_Product $product, array $item, array $opts = []): string;

    /** @return array{0:int|null,1:string} [qty, reason] */
    private function resolve_quantity(array $item, string $warehouse_id): array;

    private function apply_stock(\WC_Product $p, int $qty): void;
}
```

**`apply_stock()` (el único escritor de stock en todo el plugin):**

```php
$p->set_manage_stock(true);
$p->set_stock_quantity($qty);
$p->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
$p->save();
```

**Regla de grep:** `set_manage_stock|set_stock_quantity|set_stock_status` no debe
aparecer en ningún otro archivo de producción.

**Política de errores:** `apply()` captura excepciones de WC y devuelve `error`
con log; nunca propaga una excepción que corte el bucle del pull.

### 3.2 `Products::sync_inventory_from_alegra()` (modificar)

**Archivo/línea:** `includes/Sync/Products.php:447`

| Cambio | Detalle |
|---|---|
| Kill switch | Agregar guard al inicio y dentro del bucle de páginas (modelo `Products.php:608,648`). |
| Escritura | Reemplazar `Products.php:524-532` por `(new Inventory_Writer($this->logger))->apply($product, $item, $opts)`. |
| `$opts` | `manage_stock_enabled` = opción migración; `warehouse_id` = `resolve_warehouse_id()` **normalizado**; `dry_run` = opción dry-run. |
| Cursor | `alegra_connector_inventory_pull_cursor`; reanudar y persistir por página (patrón `Products.php:614-626,717`). |
| Error API | Guardar cursor y salir, no `break` perdiendo progreso (`Products.php:501-504`). |
| Resultado | Ampliar a `updated`, `skipped`, `skipped_reasons` (mapa), `pages`, `truncated`, `locked`, `dry_run`. |

### 3.3 `Products::update_product_from_alegra()` (modificar)

**Archivo/línea:** `includes/Sync/Products.php:1152`

- **Eliminar** `Products.php:1203-1209` (escritura directa de stock).
- **Delegar** en `Inventory_Writer::apply($product, $item, $opts)` con los mismos
  `$opts` (misma política de bodega/migración/opt-out).
- Efecto: `import_from_alegra()` deja de ser un segundo escritor; el guard
  `get_manage_stock()` desaparece como criterio.

### 3.4 Push WC→Alegra (modificar — R2 `BLOQUEADO`)

**Archivos/métodos:** `prepare_simple_product_data()` (simple),
`prepare_variable_product_data()` (variable — ver NOTA VARIANT abajo) y
`apply_warehouse()` en `includes/Sync/Products.php`.

- Agregar parámetro `bool $is_create = false`.
- Si `$is_create === false`: **omitir** `inventory.initialQuantity` (y el
  `initialQuantity` de `apply_warehouse()`).
- Call sites: `create_item()` pasa `true` (`Products.php:152,251`); `update_item()`
  pasa `false` (`Products.php:128,141,196,230`).
- **C4:** si `get_manage_stock() === false` o `_alegra_manage_stock_opt_out='yes'`,
  no enviar el bloque `inventory` en absoluto.
- **Rama alternativa (R2 seguro):** no se cambia; se documenta que
  `initialQuantity` no afecta el stock actual.

> **NOTA R3 (2026-09-17) — decisión endurecida.** Implementado: en el update se
> omite el objeto `inventory` **completo**, no solo `initialQuantity`. Motivo:
> la doc marca `unit`, `unitCost` e `initialQuantity` como obligatorios *cuando
> el objeto está presente*, así que un `{unit}` parcial es indocumentado (400 o
> lectura de `initialQuantity` ausente como 0). `PUT /items/{id}` es parcial
> (*"Solo enviar los campos que cambiarán"*,
> https://developer.alegra.com/reference/items__updateitem) e `inventory` no es
> obligatorio. **Cambio de diseño:** el stock no se toca por `PUT /items`; el
> camino documentado es `POST /inventory-adjustments`
> (https://developer.alegra.com/reference/post_inventory-adjustments). Cablearlo
> queda para Fase 5; este hotfix solo omite `inventory` en updates. Tests:
> `exec-test.php` T-hotfix-2/3/4/5.

> **NOTA FIX (2026-09-17) — enum de escritura + `unitCost`.** El `POST /items` de
> creación enviaba `type='simple'` (enum de **lectura** de `GET /items`) cuando
> el enum de **escritura** es `product|service|variantParent|kit`
> (https://developer.alegra.com/reference/post_items,
> https://developer.alegra.com/reference/items__createitem). Corregido a
> `product` en `prepare_simple_product_data()`. Además se agregó
> `inventory.unitCost` (obligatorio según la doc cuando `inventory` está
> presente), tomado de `_wc_cog_cost`/`_cost` con fallback `0`. El mock no
> validaba el schema, por eso el defecto era invisible. Tests: `exec-test.php`
> T1.3/T1.4/T1.5, T-hotfix-1.

> **NOTA VARIANT (2026-09-17) — el push de producto variable, IMPLEMENTADO.**
> Ya no es un gap: el path devuelve `variable_product_unsupported` **eliminado**.
> Modelo documentado e implementado en `Products::sync_variable_product()`,
> `prepare_variable_product_data()` y helpers:
>
> - **Parent:** UN item `type=variantParent` con `variantAttributes`
>   (`[{id, options:[{id}]}]`, mín 1) + `itemVariants` (opcional, máx 100). Los
>   `id` son de atributos/opciones **existentes** en Alegra.
>   (`https://developer.alegra.com/reference/items__createitem.md`,
>   `https://developer.alegra.com/reference/post_items.md`). **No** se envía
>   `subitems` (solo `kit`) ni `inventory` a nivel padre.
> - **Resolución de atributos:** `collect_variation_attribute_defs()` lee
>   `_product_attributes` (el mismo store que escribe el import); por cada
>   atributo de variación se busca el atributo Alegra por nombre normalizado
>   (trim + minúsculas + espacios colapsados → casing/formato distinto matchea) o
>   se crea con `POST /variant-attributes {name, options:[{value}]}`; las
>   opciones faltantes se agregan con `PUT /variant-attributes/{id}` (las
>   existentes conservan su `id`). El catálogo se carga **una vez por request**
>   (`alegra_variant_attribute_index()`), no por variación.
> - **Hijos:** NO se crean standalone. Son items `variant` que Alegra genera
>   desde `itemVariants`; se mapean a las variaciones WC por firma de combinación
>   (`attrId:optionId`) desde la respuesta y, si la respuesta no trae hijos, vía
>   `GET /items?variantParent_id={id}`.
> - **Inventario por variación:** solo si `get_manage_stock()` y **solo en
>   create** (R3), y solo con bodega configurada (el inventario de una variante
>   es solo `warehouses`). En update se omite por completo.
> - **Update (`PUT /items/{id}`):** reenvía `variantAttributes` + `itemVariants`;
>   las variaciones existentes llevan su `id` (permite agregar variantes nuevas
>   sin `id`). `UNVERIFIED`: si Alegra interpreta `itemVariants` en el PUT como
>   aditivo; se envía la lista completa con ids.
> - **Decisiones de borde:** atributo no creable ⇒ falla el push completo antes
>   de escribir (código `variant_attribute_create_failed`); valor de variación no
>   resoluble ⇒ `variable_product_attribute_missing`; producto sin variaciones ⇒
>   `variable_product_no_variations`; >100 variaciones ⇒
>   `variable_product_too_many_variants` (no se trunca); variación no
>   inventariable ⇒ sin bloque `inventory`.
> - **Mock:** `scripts/lib/alegra-mock.php` valida `variantAttributes`/
>   `itemVariants`, rechaza `subitems` fuera de `kit`, implementa
>   `GET/POST/PUT /variant-attributes` y materializa los hijos. Tests:
>   `exec-test.php` T14.1–T14.10 y T-hotfix-4/5.

### 3.5 Facturación (modificar — A6)

| Elemento | Ubicación | Cambio |
|---|---|---|
| Resolución de estado | `Orders.php:614` | Regla de 3 pasos (sección 1.3). |
| Helper pago | `Orders.php` (nuevo) | `order_is_paid(\WC_Order): bool`. |
| Handler apertura | `Orders.php` (nuevo) | `on_order_paid_open_invoice(int): void` → `ensure_invoice_open()` (`Orders.php:322`). |
| Registro de hooks | `Public_.php:49-59` | Agregar `woocommerce_payment_complete` y `woocommerce_order_status_completed` **fuera** del `if (push_orders_enabled)`. |
| Delegado público | `Public_.php` (nuevo método) | `on_order_paid_open_invoice` delega a `Sync\Orders`. |
| Override existente | `Orders.php:247` | **No cambiar**; hereda la regla nueva. |
| `ensure_invoice_open` | `Orders.php:322` | **No cambiar**; solo invocar. |

**Interacción con lo ya publicado (`invoice_status=draft`):** la opción conserva
su default `draft`, pero cambia de semántica a "estado mientras el pedido no está
pago". Con `open_invoice_on_paid=true` (default), un pedido pagado nace `open`.
Un pedido no pagado sigue naciendo `draft` — el default manual no se rompe.

### 3.6 Opciones y metas

| Clave | Tipo | Default | Registro | Lectura |
|---|---|---|---|---|
| `alegra_connector_inventory_manage_stock_enabled` | bool | `false` | `Admin_Dashboard::register_settings` (patrón `Admin_Dashboard.php:340`) | `sync_inventory_from_alegra`, `update_product_from_alegra` |
| `alegra_connector_inventory_dry_run` | bool | `true` | idem | idem |
| `alegra_connector_inventory_pull_cursor` | int | `0` | interno | idem |
| `alegra_connector_open_invoice_on_paid` | bool | `true` | idem | `Orders::prepare_invoice_data` |
| `_alegra_manage_stock_opt_out` (meta producto) | `'yes'`/`''` | `''` | — | `Inventory_Writer` |
| `_alegra_item_missing_since` (meta producto) | int | — | — | informe C3 |

### 3.7 Exposición del pull

| Entrada | Ubicación | Gate |
|---|---|---|
| Cron | `Controller.php:107-124` — tras `import_from_alegra()`, si `inventory_source=alegra` llamar al pull | `sync_method ∈ {cron,both}` + `sync_products` |
| Botón admin | `Admin_Dashboard` — AJAX `alegra_sync_inventory` (nonce + `manage_woocommerce`) | manual |
| WP-CLI | `add_action('alegra_sync_inventory_from_alegra', ...)` en bootstrap | manual |

---

## 4. Flujo de datos

### 4.1 Pull (Alegra → WC)

```
GET /items?start=N&limit=30&mode=advanced
  → [{id, type, inventory:{availableQuantity, warehouses:[{id,availableQuantity}]}, ...}]
  → para cada item:
       get_product_by_alegra_id(item.id)   (Products.php:1652)
       Inventory_Writer::apply(product, item, opts)
         → resuelve qty (bodega/total/nulo/negativo)
         → resuelve entidad (producto/variación)
         → set_manage_stock + set_stock_quantity + set_stock_status + save
  → set_transient('alegra_sync_progress', ...)
```

### 4.2 Push (WC → Alegra)

```
sync_to_alegra(product)
  → prepare_*_product_data(product, is_create)
      → inventory:{unit, initialQuantity}?    // bloque completo SOLO si is_create (R3)
      → apply_warehouse(data, product)        // initialQuantity solo si is_create
  → create_item(data) | update_item(id, data)
```

### 4.3 Factura y stock de Alegra

```
Venta en WC
  → push_orders_enabled ? crear factura automática : botón manual
  → factura nace draft (no pago) u open (pago)   [REQ-INV-2]
  → al pagarse: abrir borrador existente          [REQ-INV-1]
  → la factura open descuenta stock en Alegra     [R1 SIN VERIFICAR]
  → el pull copia el nuevo stock a WC             [REQ-SRC-1]
```

---

## 5. Concurrencia y locking

| Lock | Clave | TTL | Tomado por |
|---|---|---|---|
| Global cron | `alegra_cron_global` | 600 | `Controller::run_cron_sync()` (`Controller.php:76`) |
| Sync productos | `alegra_sync_running_products` | 300 | `acquire_sync_lock_public('products')` (`Products.php:459`) |
| Factura por pedido | `alegra_invoice_lock_{id}` | 30 | `create_invoice()` (`Orders.php:42`) |
| Guard por entidad | `alegra_sync_guard_{type}_{id}` | 30 | `Public_::trigger_sync()` (`Public_.php:300`) |
| Poll facturas | `alegra_sync_running_orders` | 300 | `Orders.php:1326` |

- `acquire_lock()` usa `add_option()` como compare-and-swap real
  (`Controller.php:327-346`).
- **Nuevo:** el pull **no** debe crear un lock propio distinto; reutiliza
  `products` para no permitir dos pulls concurrentes ni un pull concurrente con
  el import.
- **Riesgo de deadlock:** el cron toma `alegra_cron_global` y luego el pull toma
  `products`. Mantener ese orden en todas las entradas.

---

## 6. Manejo de errores

| Situación | Comportamiento |
|---|---|
| Kill switch activo | Abortar, sin escritura, log. |
| Lock tomado | Devolver `locked=true`, sin escritura. |
| `GET /items` WP_Error | Guardar cursor, salir sin excepción, log `error`. |
| Excepción por item | `Inventory_Writer` devuelve `error`, el bucle continúa, contador `errors++`. |
| `availableQuantity` ausente/null/basura | SKIP + WARN, sin escritura. |
| `availableQuantity` negativo | Clamp a 0 + `outofstock` + WARN. |
| Bodega ausente | SKIP + WARN, sin escritura. |
| Producto no vinculado | No tocar. |
| `_alegra_manage_stock_opt_out` | No tocar, reportar. |

**Principio:** el pull **nunca** convierte un error en una escritura destructiva
(0, outofstock, manage=yes).

---

## 7. Migración

### 7.1 Mecánica

1. **Opt-in:** `inventory_manage_stock_enabled` default `false`. Sin él, el pull
   solo reporta.
2. **Dry-run:** `inventory_dry_run` default `true`. Genera informe de diferencias.
3. **Confirmación:** el comerciante revisa el informe y desactiva el dry-run.
4. **Aplicación real:** el pull escribe.
5. **Opt-out por producto:** `_alegra_manage_stock_opt_out` para dropship/digital.

### 7.2 Informe de dry-run (estructura)

| Producto | WC manage | WC stock | Alegra (bodega/total) | Acción propuesta |
|---|---|---|---|---|
| Camiseta | no | 12 | 7 | manage=yes, stock=7, instock |
| Gorra | no | 0 | (servicio) | manage=no, sin stock |
| Pantalón | no | 5 | (bodega ausente) | SKIP + WARN |

### 7.3 Guardas de migración

- No escribir si el producto no está vinculado (`_alegra_item_id` vacío).
- WARN + confirmación si `_manage_stock='yes'` con stock ≠ 0 y el valor difiere.
- Nunca escribir 0 por ausencia/negativo (clamp solo si el dato existe y es
  negativo).

---

## 8. Interacción con el comportamiento ya publicado

| Comportamiento publicado | Efecto del cambio |
|---|---|
| `invoice_status=draft` default (`alegra-connector.php:386`) | Se conserva; cambia su semántica a "mientras no está pago". |
| Facturación manual default (`alegra-connector.php:372`) | Se conserva; los hooks de **apertura** se agregan fuera del gating (no facturan). |
| `create_invoice_with_payment()` fuerza `open` con `payment_account_id` (`Orders.php:247`) | Sin cambio; ahora también nace `open` si el pedido está pagado. |
| `import_from_alegra()` escribe stock (`Products.php:1203`) | Deja de escribir directamente; delega en el escritor único. |
| Push envía `initialQuantity` (y luego `inventory` parcial) en update (`Products.php:282,361,440`) | **Resuelto (R3):** el bloque `inventory` completo se omite en update; solo se envía en create. |
| `sync_inventory_from_alegra()` sin kill switch (`Products.php:447-545`) | Se le agrega kill switch. |
| `create_inventory_adjustment()` sin llamadores (`Client.php:648`) | Sigue sin cablearse (solo reconciliación manual documentada). |
| Webhook `delete-item` (`Handlers.php:76`) | Se referencia en C3; no cambia. |
| `alegra_sync_inventory_from_alegra` documentada y no registrada (`RELEASE_2.1.9_DEPLOY.md:359`) | Se registra. |

---

## 9. Decisiones de diseño

| # | Decisión | Justificación |
|---|---|---|
| DD-1 | Helper `Inventory_Writer` nuevo en `includes/Sync/` | Único punto de escritura, testeable y grep-able. |
| DD-2 | Pull reutiliza el lock `products` | Evita carrera con el import. |
| DD-3 | Hooks de apertura fuera del gating de push | R-HOOK: el modo manual debe poder abrir borradores. |
| DD-4 | Cursor persistente en el pull | R-PULL: reanudación tras fallo. |
| DD-5 | Clamp de negativos a 0 | Alegra permite negativos; WC no debe propagarlos. |
| DD-6 | `warehouse_id` `''`/`'0'` ⇒ sin bodega | C6: el `<select>` usa `value="0"` (`admin-settings.php:164`). |
| DD-7 | Opt-out por producto, no global | C1: dropship/digital conviven con inventariables. |
| DD-8 | No cablear `create_inventory_adjustment()` | Evitar doble conteo con la factura (sección 5.8). |

## 10. Requerimientos bloqueados y sus ramas

| Requerimiento | Verificación | Rama A | Rama B |
|---|---|---|---|
| REQ-DIV-3 | R2 + R3 | **Resuelto (R3):** se omite `inventory` completo en update (cubre ambas ramas de R2) | idem — sin cambio de código adicional |
| REQ-INV-1, REQ-INV-2 | R1 | `draft` no mueve stock ⇒ implementar apertura | `draft` sí mueve ⇒ cancelar |
| REQ-DIV-4 | R1 | `draft` no mueve ⇒ informe + advertencia | `draft` sí mueve ⇒ informe informativo |
| REQ-WH-2, REQ-WH-3 | R7 | Ausente = no asignada ⇒ SKIP+WARN | Ausente = cero ⇒ evaluar escribir 0 (aún se recomienda SKIP) |
| REQ-CN-1 | R-CN | Restaura ⇒ test de regresión | No restaura ⇒ reportar + evaluar ajuste compensatorio |

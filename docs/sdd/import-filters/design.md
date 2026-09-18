# Diseño Técnico — Filtros de importación de productos (`import-filters`)

| Campo | Valor |
|---|---|
| Cambio | `import-filters` |
| Documento base | `proposal.md` · `spec.md` |
| Versión | 2.3.7 (`alegra-connector.php:6`) |
| Naturaleza | Diseño técnico (no implementación) |

> Claims de código con `archivo:línea`. Claims de Alegra con URL.

---

## 1. Principios

1. **Sin filtros = comportamiento actual.** El helper reproduce el bloque de
   `status` de `Admin_Dashboard.php:1644-1646` sin cambios.
2. **El total refleja lo que se importa.** El metadata de `ajax_sync_start` usa
   los filtros explícitos, pero **no** el `status` implícito por defecto (para no
   alterar el total de hoy).
3. **Un solo helper** construye los parámetros; página y metadata lo comparten.
4. **No se toca** cron, webhooks, Ruta A ni `Products::import_from_alegra`.
5. **Estado por corrida** en el transient `alegra_batch_state`; sin opciones
   persistentes (no tocar `uninstall.php`).

## 2. Flujo

```
[Productos] botón "Traer desde Alegra" (.alegra-quick-sync + data-requires-filter="1")
        │  admin-products.php:91
        ▼
[Modal filtros]  ← AJAX alegra_get_item_categories (start/limit)
        │  "Aplicar y traer" / "Traer todo sin filtros"
        │  set AlegraConnector.pendingFilters + data-filter-confirmed
        ▼
admin.js :: initSyncNow (admin.js:118) re-dispara el click existente
        │  POST alegra_sync_start {sync_type:'products', filters}
        ▼
Admin_Dashboard::ajax_sync_start (:1578)
        │  sanitize_item_filters($_POST['filters'])
        │  total = GET /items?metadata=true&limit=1 + filtros EXPLÍCITOS
        │  $state['filters'] = $filters ; set_transient('alegra_batch_state')
        ▼
Admin_Dashboard::ajax_sync_page (:1618)  [loop]
        │  $api_params = ['start','limit','mode'] + build_item_filter_params($f, true)
        │  GET /items
        │  foreach item: descarte variantParent si aplica
        │  import_single_item_public($item)
        ▼
        (Products/Cron/Webhooks sin cambios)
```

## 3. Detalle técnico

### 3.1 Forma de `filters`

```php
[
  'idItemCategory' => 'cat-9',     // '' = todos
  'status'         => 'default',   // default|active|inactive
  'inventariable'  => true,        // bool
  'query'          => 'camisa',    // '' = sin búsqueda
  'type'           => 'simple',    // ''|simple|kit|variantParent
]
```

### 3.2 `sanitize_item_filters()`

Método privado en `Admin_Dashboard`. `sanitize_text_field` para
`idItemCategory`/`query`; `in_array` para `status`/`type`;
`filter_var(..., FILTER_VALIDATE_BOOLEAN)` para `inventariable`; defaults
`''`/`'default'`/`false`. Entrada inválida se degrada a "todos".

### 3.3 `build_item_filter_params(array $f, bool $include_default_status = true)`

```php
private function build_item_filter_params(array $f, bool $include_default_status = true): array
{
    $p = [];
    if (($f['idItemCategory'] ?? '') !== '') {
        $p['idItemCategory'] = $f['idItemCategory'];
    }
    if (in_array($f['status'] ?? 'default', ['active', 'inactive'], true)) {
        $p['status'] = $f['status'];
    } elseif ($include_default_status
        && !get_option('alegra_connector_sync_inactive_products', false)) {
        $p['status'] = 'active'; // comportamiento actual, SOLO en la página
    }
    if (!empty($f['inventariable'])) {
        $p['inventariable'] = 'true'; // string: la API espera boolean true
    }
    if (($f['query'] ?? '') !== '') {
        $p['query'] = $f['query'];
    }
    if (in_array($f['type'] ?? '', ['simple', 'kit'], true)) {
        $p['type'] = $f['type'];
    }
    return $p;
}
```

- `ajax_sync_start` (metadata): `build_item_filter_params($f, false)`.
- `ajax_sync_page`: `build_item_filter_params($f, true)`.

### 3.4 `ajax_sync_start` (`Admin_Dashboard.php:1578`)

- Leer `$_POST['filters']` (JSON) con `json_decode(wp_unslash(...), true)`.
- `$filters = $this->sanitize_item_filters(is_array($raw) ? $raw : []);`
- Para `products`: `$params = ['metadata' => 'true', 'limit' => 1] + $this->build_item_filter_params($filters, false);`
- Guardar `'filters' => $filters` en `$state`.

### 3.5 `ajax_sync_page` (`Admin_Dashboard.php:1640-1667`)

- `$filters = is_array($state['filters'] ?? null) ? $state['filters'] : [];`
- `$api_params = ['start' => $start, 'limit' => $per_page, 'mode' => 'advanced'] + $this->build_item_filter_params($filters, true);`
- Reemplaza el bloque `if (!get_option('alegra_connector_sync_inactive_products'...))`.
- Descarte en cliente (tras `if ($item_type === 'variant') { continue; }`):

```php
if (($filters['type'] ?? '') === 'variantParent' && $item_type !== 'variantParent') {
    $state['skipped'] = ($state['skipped'] ?? 0) + 1;
    continue;
}
```

### 3.6 `ajax_get_item_categories` (Fase 2)

- `check_ajax_referer('alegra_connector_nonce')` + `current_user_can('manage_woocommerce')`.
- Bucle de páginas con `get_item_categories(['start' => $s, 'limit' => 30])`,
  hasta `count < 30` o 10 páginas.
- Devuelve `{ categories: [{id,name}], has_more: bool }`.
- Registro junto a `Admin_Dashboard.php:52-53`.

### 3.7 JS (`admin/assets/js/admin.js`)

1. Botón Productos (`admin-products.php:91`): conservar `alegra-quick-sync`,
   añadir `data-requires-filter="1"` y etiqueta "Traer desde Alegra".
2. Al inicio del handler de `initSyncNow` (`admin.js:118`):

```js
if ($(this).data('requires-filter') && !$(this).data('filter-confirmed')) {
    AlegraConnector.openImportFilterModal($(this));
    return;
}
$(this).removeData('filter-confirmed');
```

3. En `alegra_sync_start` (`admin.js:198`) añadir
   `filters: JSON.stringify(AlegraConnector.pendingFilters || {})`.
4. `initImportFilters()`: abre el modal, carga categorías, y al aplicar setea
   `AlegraConnector.pendingFilters`, `$btn.data('filter-confirmed', true)` y
   `$btn.trigger('click')`.
5. `init()` (`admin.js:43`): añadir `this.initImportFilters();`.
6. El dashboard no tiene `data-requires-filter` → **intacto**.

### 3.8 UI (modal)

En `templates/admin-products.php`, antes de `footer.php` (~línea 262), reutilizando
`.ac-modal-overlay`. Campos: `#ac-filter-category`, `#ac-filter-type`,
`#ac-filter-status`, `#ac-filter-inventariable`, `#ac-filter-query`; botón
primario `#ac-filter-apply` y enlace `#ac-filter-all`.

### 3.9 `$connected`

`render_products_page` (`Admin_Dashboard.php:1107`) define
`$connected = (bool) get_option('alegra_connector_connection_tested');` antes de
incluir el template.

### 3.10 Exclusión de campos al actualizar

- **Configuración única:** `alegra_connector_import_preserve_fields` (Ajustes →
  Sincronización). No hay override en el modal manual; así no hay ambigüedad.
- `Products::resolve_preserve_fields()` lee el ajuste.
- `update_product_from_alegra($product, $item, bool $is_new = false)`: con
  `$is_new=true` la lista se ignora (un producto nuevo se crea completo).
- Guardas por campo: `price`, `name`, `description`, `inventory`, `sku`, `images`.
  `assign_variation_sku()` respeta `sku` porque escribe `_sku` directamente.
- Claves válidas: `description|name|price|images|inventory|sku`.
- Aplica a todas las rutas: manual (Productos), Importar, botón "Traer", cron y
  webhooks.

## 4. Compatibilidad

| Elemento | Cambio |
|---|---|
| `.alegra-quick-sync` (dashboard) | Ninguno |
| `ajax_sync_start` sin `filters` | `$state['filters'] = []` → params actuales |
| `ajax_sync_page` | +1 llamada al helper; resto igual |
| Cron / webhooks / Ruta A | Ninguno |
| Opciones persistentes | Ninguna (sin cambios en `uninstall.php`) |

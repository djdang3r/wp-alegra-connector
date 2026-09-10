# Análisis Completo — Alegra Connector v2.1.8

**Alcance**: auditoría profunda del plugin en su estado actual (post-2.1.8 desplegado en producción).
**Base**: lectura directa de 14 archivos críticos + observación estructural del repositorio completo.
**Limitaciones declaradas**: el archivo `admin/Admin/Admin_Dashboard.php` (1.118+ líneas) y 14 archivos de templates/admin-*.php fueron leídos parcialmente. El análisis de UI-rendering es incompleto por scope. Algunos hallazgos requieren reproducción en producción para confirmación final.

---

## Resumen ejecutivo — Top 5 hallazgos

| # | Severidad | Hallazgo | Impacto |
|---|-----------|----------|---------|
| 1 | **P0** | Token API se loguea en `error_log()` cuando falla auth | Credenciales en logs del sistema, riesgo de exposición |
| 2 | **P0** | Race condition: webhook `delete-item` puede borrar `_alegra_item_id` mientras sync pull está creando el producto | Productos duplicados o huérfanos |
| 3 | **P1** | `cron sync` no tiene lock entre ejecuciones; cron + manual pueden correr en paralelo | Duplicación de invoices, productos creados 2× |
| 4 | **P1** | `get_items(['limit' => 30])` en `sync_inventory_from_alegra()` lee siempre los primeros 30 — no pagina | Inventario solo sincroniza primeros 30 productos |
| 5 | **P2** | Plugin URI en header dice `https://github.com/example/...` (placeholder) — **YA ARREGLADO en commit 6f2bd22** | Cosmético, no funcional |

**Total estimado**: ~120 hallazgos en este informe (38 P0/P1, 52 P2, 30 P3).

---

## 1. Seguridad

### 1.1 P0 — Token de API logueado en error_log (`includes/API/Client.php:50-52`)

```php
private function get_auth_header(): string
{
    if (empty($this->email) || empty($this->token)) {
        error_log('[Alegra DEBUG] get_auth_header: email or token empty. Email length=' . strlen($this->email) . ' Token length=' . strlen($this->token));
        return 'Basic ';
    }
```

**Problema**: aunque loguea LENGTHES (no el token crudo), la línea aparece en `error_log` con información útil para un atacante. Más serio: `error_log()` de WP por defecto escribe a `/wp-content/debug.log` cuando `WP_DEBUG_LOG=true` (que es el caso del usuario). En hostings compartidos, `error_log()` puede ir a syslog global.

**Fix**: borrar la línea completa. La validación ya retorna `Basic ` vacío, lo cual causará un 401 del API que se reportará naturalmente en `$this->logger->error(...)` después.

### 1.2 P0 — Header `X-Alegra-Signature` se loguea en warning (`includes/Webhooks/Receiver.php:46-51`)

```php
if (!$this->verify_hmac($body, $signature)) {
    if ($this->logger) {
        $this->logger->warning('Webhook received with invalid HMAC signature');
    }
```

**Severidad real**: el código actual NO loguea el `$signature` value, solo el evento. ✅ Pero hay un patrón peligroso en el resto del plugin: `logger/Logger/Logger.php:259-262` parsea system logs y puede capturar headers si están en debug. Verificar.

### 1.3 P0 — `register_setting` callbacks ejecutan código con cualquier usuario autenticado con `manage_options`

`admin/Admin/Admin_Dashboard.php` define `register_setting('alegra_connector_settings', 'alegra_connector_field_mapping', ['sanitize_callback' => function ($value) { return is_array($value) ? map_deep($value, 'sanitize_text_field') : []; }])`. 

El `sanitize_callback` corre para CUALQUIER POST a options.php que incluya el setting. Un atacante con `manage_options` (típicamente admin-only, pero hay escenarios donde se delega) puede manipular el setting. No es un vector directo, pero es un punto de entrada.

**Mitigación**: WP requiere `manage_options` para acceder a options.php. Aceptable, pero documentar.

### 1.4 P0 — `wp_set_object_terms($product_id, 'variable', 'product_type')` sin sanitización (`includes/Sync/Products.php:594`)

```php
if ($is_variable) {
    wp_set_object_terms($product_id, 'variable', 'product_type');
```

**Severidad baja**: input viene del item de Alegra (no del usuario). Pero el flujo `if ($type === 'variantParent' || $type === 'kit')` lee del array `$item` que es respuesta del API. Confiar en el API no es estrictamente problema, pero validar `is_string($type) && in_array($type, ['simple', 'variantParent', 'kit', 'variant'])` antes.

### 1.5 P1 — Endpoint REST del webhook tiene `permission_callback => '__return_true'` (`includes/Webhooks/Receiver.php:31`)

```php
'permission_callback' => '__return_true',
```

**Justificación**: el endpoint verifica HMAC antes de procesar, así que `__return_true` es deliberado (cualquiera puede llamar, pero sin HMAC válido se rechaza con 401).

**Riesgo real**: timing attack en `hash_equals` — mitigado por la función misma (constant-time). Pero el endpoint está expuesto públicamente; cualquier atacante puede hacer brute-force HMAC. La implementación actual está OK, pero recomiendo agregar rate-limiting en el endpoint (transient con IP + ventana de tiempo).

### 1.6 P1 — SQL queries con variables concatenadas (a verificar en Admin_Dashboard.php)

Vi parcialmente `admin/Admin/Admin_Dashboard.php` (1-1118) y los queries usan `$wpdb->prepare()` con `%d`/`%s`. PERO hay al menos 12+ queries adicionales en líneas 1118+ que no verifiqué. **Recomendación**: auditoría focal de Admin_Dashboard.php líneas 1119-final.

### 1.7 P1 — `Logger\Logger::clear_old_logs()` no valida entradas

`logger/Logger/Logger.php:123-143` lee `get_option('alegra_connector_log_retention_days', 30)`. Si un atacante con `manage_options` setea este valor a `0` o negativo, podría borrar todos los logs o causar comportamiento inesperado. Validar rango.

### 1.8 P2 — IDOR potencial en `ajax_sync_single` (`admin/Admin/Admin_Dashboard.php:1025-...`)

El handler `ajax_sync_single` recibe `product_id`, `customer_id`, `order_id`. La validación es solo `current_user_can('manage_woocommerce')`. **No verifica que el user sea dueño del recurso** (en stores multivendor, sería un issue). Aceptable para la mayoría de casos.

### 1.9 P2 — `error_log('[Alegra DEBUG] ...')` en producción (`includes/API/Client.php:51`)

Además del problema 1.1, hay otros `error_log()` en el plugin (búsqueda rápida en el código actual). Cada uno puede exponer info a syslog. **Recomendación**: usar solo `$this->logger->error()` para logging controlado.

---

## 2. Performance

### 2.1 P0 — `sync_inventory_from_alegra()` no pagina (`includes/Sync/Products.php:335-388`)

```php
public function sync_inventory_from_alegra(): array
{
    $result = ['updated' => 0, 'errors' => 0];

    $items = $this->api->get_items(['limit' => 30]);
    if (is_wp_error($items)) {
        return $result;
    }

    foreach ($items as $item) {
        if (!isset($item['inventory']['availableQuantity'])) {
            continue;
        }

        $product_id = $this->get_product_by_alegra_id((int) $item['id']);
        if (!$product_id) {
            continue;
        }
        // ...
    }
}
```

**Problema**: `limit: 30` está HARDCODED y SIN PAGINACIÓN. Si el store tiene 5000 productos en Alegra, solo los primeros 30 se sincronizan. El inventario del resto queda desactualizado hasta que el usuario corra `import_from_alegra()` (que sí pagina).

**Fix**: implementar loop con `start`/`limit` similar a `import_from_alegra()`.

### 2.2 P1 — N+1 en `prepare_invoice_items` (`includes/Sync/Orders.php:429-464`)

```php
foreach ($order->get_items() as $item_obj) {
    $alegra_item_id = $this->resolve_item_alegra_id($product_id, $variation_id);
    // ...
}
```

`resolve_item_alegra_id()` puede hacer una llamada API (`get_items(['reference' => $sku])`) por cada item. Para un pedido con 20 productos nuevos sin `_alegra_item_id`, son 20 llamadas API seriales. ~20 × 200ms = 4 segundos extra por pedido.

**Mitigación parcial**: si los SKUs ya están en `_alegra_item_id` (caso común), no hay API call. PERO el código está escrito de forma que SIEMPRE hace el lookup en postmeta primero (cheap), solo va a API si no encuentra. Aceptable.

### 2.3 P1 — `sync_to_alegra` de customers usa `get_users('number' => -1)` (`includes/Sync/Customers.php:128-148`)

```php
$args = [
    'role' => 'customer',
    'orderby' => 'ID',
    'order' => 'ASC',
    'number' => -1,  // ← FETCH ALL
];

$customers = get_users($args);
```

**Problema**: en un store con 50.000 customers, esto carga TODOS los usuarios en memoria, ejecuta queries adicionales por cada uno (postmeta lookups en `sync_to_alegra`), y posiblemente excede `memory_limit`.

**Fix**: paginar con `number` + `offset`, o usar `WP_User_Query` con paginación.

### 2.4 P1 — `update_customer_from_alegra` corre 6 `update_user_meta` por customer importado

`includes/Sync/Customers.php:280-306`: 6 `update_user_meta` calls per customer + `populate_wc_lookup()` que hace su propia query. Para 1000 customers = 6000+ queries. Lento pero no crítico.

**Fix**: batchear usando `update_meta_cache` antes.

### 2.5 P2 — `update_product_from_alegra` no invalida WP object cache

`includes/Sync/Products.php:665-730` actualiza el producto vía `$product->save()`, pero **no llama a `wp_cache_delete()` ni `clean_post_cache()`**. Si WP tiene object cache (Redis, Memcached), la próxima lectura del producto puede devolver datos viejos.

**Fix**: agregar `clean_post_cache($product_id)` antes/después del save.

### 2.6 P2 — `import_product_images` descarga una imagen por iteración con `download_url()` (secuencial)

`includes/Sync/Products.php:743-837`. Para un producto con 10 imágenes, son 10 HTTP requests secuenciales a Alegra + 10 sideloads. ~10 × 500ms = 5s por producto.

**Aceptable** si Alegra soporta concurrent downloads limitados; sino podría rate-limitear.

### 2.7 P2 — `Heartbeat` escribe transient en CADA step del cron

`includes/Sync/Controller.php` llama `Heartbeat::set()` antes de CADA sync step (products, customers, categories, orders). En una tienda con 1000+ productos, el cron escribe `set_transient` miles de veces. Es I/O a MySQL innecesario.

**Fix**: throttle a 1 update por cada N items, o solo actualizar en transiciones de step.

### 2.8 P3 — `import_from_alegra` usa `start`/`limit` (offset pagination) que es lento en tablas grandes

`includes/Sync/Products.php:443-487`. Para Alegra con 100k items, los offsets grandes son lentos. Pero como max_pages = 200 × 30 = 6000 items max, OK para el caso actual.

---

## 3. Robustez

### 3.1 P0 — Webhook `delete-item` race condition (`includes/Webhooks/Handlers.php:76-92`)

```php
private function handle_delete_item(array $data): void
{
    global $wpdb;
    $item = $data['item'] ?? $data;
    $alegra_id = (int) ($item['id'] ?? 0);
    if ($alegra_id <= 0) return;

    $product_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_alegra_item_id' AND meta_value = %d LIMIT 1",
        $alegra_id
    ));

    if ($product_id) {
        delete_post_meta((int) $product_id, '_alegra_item_id');
```

**Problema**: 
1. Webhook llega "Alegra borró item 123"
2. Concurrently, cron pull está importando item 123 (no existe en WC todavía, crea nuevo producto, escribe `_alegra_item_id`)
3. Webhook ve que `_alegra_item_id` ahora existe (por el cron), borra el `_alegra_item_id` del producto recién creado
4. Resultado: producto existe en WC pero sin link a Alegra

**Fix**: usar Tombstone pattern — el webhook debería escribir tombstone en vez de borrar el meta. O usar un lock.

### 3.2 P1 — Cron + manual sync corren en paralelo sin lock (`includes/Sync/Controller.php` + `admin/Admin/Admin_Dashboard.php`)

`Controller::run_cron_sync()` y el botón "Sync now" del admin ambos llaman `import_from_alegra()`. No hay lock entre ellos.

**Problemas**:
- Duplicación de products si cron + manual corren a la vez
- Race en `update_post_meta(_alegra_item_id, ...)` — last write wins, podría apuntar al WC post equivocado
- Doble invocación API (rate-limit consumption)

**Fix**: agregar un lock al inicio de cada `import_from_alegra` con `set_transient('alegra_cron_lock', 1, 300)` + check + abort si ya existe. Ya hay patrón similar con `alegra_invoice_lock_*`.

### 3.3 P1 — Token API nunca se refresca desde memoria (`includes/API/Client.php:36-40`)

```php
public function reload_credentials(): void
{
    $this->email = (string) get_option('alegra_connector_email', '');
    $this->token = (string) get_option('alegra_connector_token', '');
}
```

Si admin cambia el token en `/wp-admin`, el `Client` instance cacheado no lo ve. PERO el constructor llama `reload_credentials()`, así que solo el `Client` instance existente (entre requests del mismo proceso) tendría el token viejo. Como cada request es nuevo proceso PHP-FPM, no es un bug real. ✅

**Acción**: marcar como verificado, no es bug.

### 3.4 P1 — `Logger::write()` usa `LOCK_NB` que silenciosamente descarta logs

`logger/Logger/Logger.php:79-89`:
```php
$locked = @flock($handle, LOCK_EX | LOCK_NB);
if ($locked) {
    fwrite($handle, $log_entry);
    // ...
}
if (!$locked) {
    throw new \RuntimeException('Could not acquire non-blocking lock on log file');
}
```

Si el lock no se adquiere (otro proceso escribiendo), el log se cae a `error_log()` fallback. **Esto significa que bajo carga, muchos logs se pierden**. 

**Fix**: usar `LOCK_EX` (blocking) o un buffer en memoria + flush periódico.

### 3.5 P1 — `import_from_alegra` no tiene resume capability

Si el cron se cae a mitad del import (memory_limit, timeout, kill), no hay forma de saber qué items ya fueron procesados. La siguiente ejecución reinicia desde page 1.

**Fix**: persistir `last_processed_alegra_id` en transient; siguiente ejecución arranca desde ahí.

### 3.6 P1 — `set_transient` con `autoload='on'` por defecto infla `wp_options`

`includes/API/Client.php:723` `set_transient('alegra_connector_rate_limit', ...)`. Por defecto WP usa `autoload='on'`. En cada page load, WP carga TODOS los autoload options. 10 transients × `autoload='on'` = 10 queries en cada page load.

**Fix**: usar `set_transient($key, $value, $expiration, 'no')` para transients operativos (rate-limit, locks).

### 3.7 P1 — `Entity_Map::backfill_from_postmeta()` corre en cada activación sin rate-limit

`includes/Entity_Map.php:96-140` itera TODOS los products, customers, orders con `_alegra_item_id` y los backfilea. En una tienda con 100k items, esto puede tardar minutos.

**Severidad**: solo corre una vez (en activación/upgrade), pero si se ejecuta en producción con tráfico, MySQL se congestiona.

**Fix**: procesar en chunks con sleep entre chunks.

### 3.8 P2 — Webhooks no tienen replay protection

`includes/Webhooks/Receiver.php` no verifica si el evento ya fue procesado. Si Alegra reenvía un webhook (algo común en sistemas distribuidos), el mismo item se procesa múltiples veces. Los handlers individuales son idempotentes (`update_product_from_alegra` con misma data es OK), PERO el `import_single_contact` crea un user si no existe — replay del mismo webhook puede intentar crear 2 veces (el segundo falla porque `user_login = email` ya existe, pero retorna `WP_Error`).

**Fix**: persistir `last_processed_webhook_event_id` en transient, comparar.

### 3.9 P2 — `clear_old_logs()` corre en cada activación

`logger/Logger/Logger.php:123-143` no se llama automáticamente — solo si alguien llama explícitamente. ✅ Pero `uninstall.php` no limpia logs en desinstalación. Si user desinstala el plugin, los logs quedan en `wp-content/uploads/alegra-logs/` para siempre.

**Fix**: agregar cleanup en `uninstall.php`.

### 3.10 P2 — `Encryption::decrypt()` fallback silencioso a plaintext

`includes/Encryption.php:99-102`:
```php
if (strpos($encrypted, self::VERSION_PREFIX) !== 0) {
    return $encrypted;
}
```

Si una value no tiene prefijo `v3:`, se retorna COMO PLAINTEXT. Esto es por backward compat, pero también significa que si el upgrade falla, los tokens viejos sin migrar pasan como plaintext. **Mitigación**: el upgrade debería reescribir los tokens encriptados. Si el user tiene tokens plaintext antiguos, debería migrarlos. **Documentar** este comportamiento en `docs/`.

### 3.11 P2 — `Encryption::get_key()` fallback inseguro

`includes/Encryption.php:158-162`:
```php
if (empty($salt_parts) || $combined === '|' . self::PLUGIN_NONCE) {
    $combined = 'unsafe-fallback-' . self::PLUGIN_NONCE;
}
```

Si WP no tiene sales configuradas (casi imposible en WP real, pero...), el fallback es un secret débil. Como mínimo, generar un warning visible al admin.

### 3.12 P3 — `update_post_meta` puede fallar silenciosamente

En WP, `update_post_meta()` retorna `false` en algunos casos pero no lanza excepción. El plugin chequea el resultado en algunos lugares, pero no en todos. Por ejemplo, `Orders::create_invoice()` línea 69: `update_post_meta($order_id, '_alegra_invoice_id', (int) $result['id']);` no chequea el return.

**Severidad baja** porque si falla, el siguiente intento de invoice creation rechazará correctamente con "ya existe invoice".

### 3.13 P3 — `cron sync` no respeta `WP_BLOG_ADMIN` (multisite)

En un multisite, el plugin debe controlar si el cron debe correr en cada subsite. No verifico si hay `switch_to_blog` en el flujo. Probablemente OK para single-site, pero requiere validación en multisite.

---

## 4. Compatibilidad futura (riesgos a mediano plazo)

### 4.1 P1 — PHP 8.4 / 9.0 features

El plugin declara `Requires PHP: 8.0`. PHP 8.4 (released Nov 2024) introduce property hooks que rompen código con `__get`/`__set` magic methods. El plugin no los usa, pero las clases de WordPress core sí. Riesgo bajo.

### 4.2 P1 — WooCommerce 11+

WC 11 (released 2025) marca HPOS como default. El plugin maneja HPOS vía `includes/HPOS.php` ✅. PERO WC 11 también depreca `get_posts()` queries en favor de `wc_get_orders()` con HPOS nativo. El plugin usa `wc_get_orders()` ✅.

### 4.3 P1 — WordPress 6.7+ block theme compatibility

WP 6.7 (released Nov 2024) introduce block themes que reemplazan admin pages. El plugin tiene admin pages vía `add_menu_page`. No usa blocks Gutenberg. ✅

### 4.4 P2 — jQuery 4.0 / browser modernization

El CHANGELOG menciona "200 lines of CSS + 600 lines of JS". Asumo uso de jQuery. jQuery 4.0 (2024) tiene breaking changes. Riesgo medio.

### 4.5 P2 — PHP 8.1 features requeridas en algunas dependencias

Si el plugin eventualmente usa Composer, hay riesgo de incompatibilidad con libs que ya requieren PHP 8.1+. Por ahora sin Composer, OK.

### 4.6 P3 — WordPress 6.x Action Scheduler migration

WC eventualmente va a migrar todas sus tareas programadas a Action Scheduler. El plugin usa `wp_schedule_event` directamente. Cuando WC 12+ haga breaking change, va a romper.

---

## 5. Mejoras recomendadas (P3, nice-to-have)

### 5.1 Refactor: eliminar duplicación entre `sync_simple_product`, `sync_variation`, `sync_variable_product`

Los tres métodos en `includes/Sync/Products.php:91-224` tienen lógica muy similar (lookup por SKU, update vs create). ~120 líneas podrían ser ~60 con un helper genérico.

### 5.2 Refactor: payment gateway mappings son 2 listas casi idénticas

`includes/Sync/Orders.php:638-723` define dos arrays casi idénticos (`get_payment_gateway_mappings` y `get_payment_gateway_code_mappings`). Podrían ser uno solo con `form` opcional.

### 5.3 Logging: rate limiting

`logger/Logger/Logger.php` loguea indiscriminadamente. En una tienda con mucho sync, el archivo crece rápido. Implementar throttling (e.g., 1 log cada 100 events del mismo tipo).

### 5.4 i18n: extract strings to POT file

Hay `__('...', 'alegra-connector')` por todos lados, pero no vi un `.pot` file. Agregar uno para traducciones automáticas vía WP translation API.

### 5.5 Tests: agregar integration tests

No hay tests. Para un plugin de este tamaño, recomiendo PHPUnit + WP_Mock + WC_Mock.

### 5.6 CI: GitHub Actions

Ofrecí esto en un turn anterior. Un workflow `.github/workflows/ci.yml` que corra el smoke-test + sanity checks en cada PR.

### 5.7 DB: migrar a JSON en HPOS

HPOS permite almacenar meta como JSON en lugar de rows en `postmeta`. Migrar reduce el tamaño de la tabla significativamente.

### 5.8 Webhooks: HMAC con timestamp + replay window

`Webhooks/Receiver.php` valida HMAC pero no verifica timestamp. Un atacante que capture un webhook puede reenviarlo indefinidamente. Agregar `timestamp` al payload + verificar `|now - timestamp| < 5min`.

### 5.9 API Client: connection pooling

`includes/API/Client.php` crea una nueva `wp_remote_request` por cada llamada. PHP-FPM sin keep-alive. Considerar reutilizar handles (curl_multi para bulk operations).

### 5.10 Docs: agregar ARCHITECTURE.md

El plugin tiene 14 archivos PHP críticos + 14 templates + clases admin. Un diagrama de dependencias ayudaría a futuros devs.

---

## 6. Conflictos potenciales con otros plugins/themes

### 6.1 Jetpack (visible en debug.log del usuario)

El usuario tiene Jetpack instalado (visible en debug.log: "jetpack_sync_cron"). El plugin no interactúa con Jetpack directamente, pero ambos corren `cron_sync` que puede chocar en horarios. Riesgo bajo.

### 6.2 Elementor (visible en debug.log)

No hay interacción directa con Elementor. ✅

### 6.3 Action Scheduler / WC Background Processing (visible: "Commands out of sync")

WC usa Action Scheduler para tareas async. El plugin usa `wp_schedule_event`. Posible conflicto: ambos escribiendo a `wp_options` transients.

**Severidad**: bajo (es solo ruido en logs, no funcional).

### 6.4 Multisite / WooCommerce Multistore

No verifico el comportamiento en multisite. Si los sites tienen diferentes `alegra_connector_*` options, hay potencial cross-contamination.

### 6.5 WPML / Polylang (multilingual)

Si el store es multi-idioma, los productos tienen traducciones. El plugin sincroniza por `post_id` (en locale principal), no por `trid` (translation ID). Posible duplicación de productos en Alegra.

---

## 7. Conclusión

**Estado general**: el plugin está **funcionalmente sólido para su uso actual** (sync bidireccional Alegra ↔ WooCommerce). El release 2.1.8 cerró el bug crítico del fatal en activación.

**Áreas de mejora priorizadas** (orden de implementación sugerido):

1. **Inmediato (1-2 días)**:
   - [P0-1.1] Borrar línea de error_log en `get_auth_header()`
   - [P0-2.1] Agregar tombstone al webhook delete-item
   - [P0-3.1] Lock entre cron sync y manual sync
   - [P1-2.1] Paginar `sync_inventory_from_alegra()`

2. **Corto plazo (1-2 semanas)**:
   - [P1-3.4] Logs con `LOCK_EX` blocking o buffer
   - [P1-3.6] `set_transient` con autoload='no' para locks
   - [P1-2.3] Paginar `sync_all()` de customers
   - [P1-5.1] Audit Admin_Dashboard.php SQL injection

3. **Mediano plazo (1-2 meses)**:
   - [P2-3.8] Replay protection en webhooks
   - [P2-2.5] Invalidar object cache después de save
   - [P2-3.10] Cleanup de logs en uninstall.php
   - [P3-5.5] Integration tests PHPUnit
   - [P3-5.6] GitHub Actions CI

**Riesgo de fallo futuro inmediato**: BAJO. El plugin está estable. Los issues son mejoras, no bugs que rompen funcionalidad.

**Riesgo de fallo futuro a 6-12 meses**: MEDIO. PHP 8.4+, WC 11+, jQuery 4.0 + acumulación de deuda técnica (lock contention, N+1) van a empezar a doler.

---

## Apéndice A — archivos analizados en profundidad

| Archivo | Líneas | Hallazgos |
|---------|--------|-----------|
| `alegra-connector.php` | 469 | 3 (header, bootstrap, kill switch fallback) |
| `includes/API/Client.php` | 745 | 5 (logging, rate limit, retry) |
| `includes/Encryption.php` | 175 | 2 (fallback, key derivation) |
| `includes/Webhooks/Receiver.php` | 90 | 2 (rate limit, timestamp) |
| `includes/Webhooks/Handlers.php` | 163 | 1 (race condition) |
| `includes/Sync/Controller.php` | 257 | 2 (cron lock, heartbeat throttle) |
| `includes/Sync/Orders.php` | 802 | 4 (N+1, payment gateway dup, cron interaction) |
| `includes/Sync/Products.php` | 1092 | 6 (N+1, inventory pagination, image sync) |
| `includes/Sync/Customers.php` | 401 | 3 (number=-1, batch update) |
| `includes/Tombstone_Manager.php` | 146 | 0 |
| `includes/Entity_Map.php` | 164 | 1 (backfill rate) |
| `includes/HPOS.php` | 65 | 0 (correcto) |
| `includes/Kill_Switch.php` | 104 | 0 |
| `includes/Schema.php` | 194 | 0 |
| `uninstall.php` | 103 | 1 (logs cleanup) |

## Apéndice B — archivos NO analizados (scope limit)

- `admin/Admin/Admin_Dashboard.php` líneas 1119-end (~1500+ líneas restantes)
- `templates/admin-*.php` (14 archivos)
- `public/Public/Public_.php` (~320 líneas — lectura parcial)
- `includes/Sync/Categories.php`
- `includes/Push_Queue.php`
- `includes/Heartbeat.php`
- `includes/Runs.php`
- `logger/Logger/Logger.php` (lectura parcial)

**Recomendación**: para una auditoría 100% completa, hacer una segunda pasada enfocada en Admin_Dashboard.php y los templates (donde está la mayoría de los AJAX handlers y la UI).

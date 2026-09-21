# Diseño Técnico — Puertas de configuración y enforcement de escrituras

| Campo | Valor |
|---|---|
| Cambio | `config-gates` |
| Documentos | `proposal.md` · `spec.md` · `tasks.md` |
| Versión analizada | 2.3.11 (`alegra-connector.php:6`) |
| Naturaleza | Diseño técnico (no implementación) |
| Regla | Toda decisión nombra archivo, método y opción; lo no verificado es **SIN VERIFICAR / BLOQUEADO** |

> Este diseño es **cerrado**: dos desarrolladores deben implementar lo mismo. Donde hay una
> elección, está tomada y justificada. Donde depende de Fase 0, se declaran las dos ramas y el
> punto exacto de bifurcación.
>
> **Corrección de rutas.** `includes/API/KillSwitch.php` → **`includes/Kill_Switch.php`**;
> `includes/Frontend/Public_.php` → **`public/Public/Public_.php`**; `includes/Frontend/State_Sync.php`
> → **`includes/State_Sync.php`**; `includes/API/{Products,Customers,Categories}.php` **no
> existen** (son `includes/Sync/{Products,Customers,Categories}.php`).

---

## 0. Mapa de cambios

| # | Archivo | Símbolo | Tipo |
|---|---|---|---|
| CORE | `includes/API/Client.php` | `request()` (`:101-111`), `is_gate_blocked_response()` (nuevo), `write_was_blocked()` (nuevo) | editar |
| CORE | `includes/Write_Gate.php` | clase nueva `Write_Gate` (`entity_for`, `block_reason`, `run_explicit`, `maybe_migrate`) | crear |
| 1 | `alegra-connector.php` | `$defaults` (`:403-424`), `$non_autoload` (`:430-439`), hook `plugins_loaded` (junto a `Schema::migrate()`, `:201`) | editar |
| 2 | `admin/Admin/Admin_Dashboard.php` | `register_settings()` (`:380-564`): registrar opciones nuevas, quitar registración gemela de mappings, eliminar `add_settings_section()` (`:566-571`) | editar |
| 3 | `templates/admin-settings.php` | `:78-81` defaults de `sync_*`; agregar controles de reconciliación y de clientes | editar |
| 4 | `public/Public/Public_.php` | `:65-67` (reconcile hooks), `:72-78` (customer hooks), `handle_sync_request` (REST) | editar |
| 5 | `includes/Sync/Orders.php` | `reconcile_payment_only()` (`:456`), `reconcile_missing_payments()` (`:493`) | editar |
| 6 | `includes/State_Sync.php` | `register_hooks()` (`:37-39,54-57`) | editar |
| 7 | `includes/Consumidor_Final.php` | `get_id()` (`:42`), `resolve()` (`:87`), `create()` (`:212`) | editar |
| 8 | `templates/admin-dashboard.php` | `:18` (`is_available()`) | editar |
| 9 | `admin/Admin/Admin_Dashboard.php` | `ajax_sync_page` (`:1832`), `ajax_sync_pending_page` (`:2825`), `ajax_disconnect` (`:2897`), `ajax_sync_now` (`:1622`), `ajax_run_cron_now` (`:3134`) | editar |
| 10 | `includes/Sync/Products.php` | `import_single_item_from_alegra()` (`:1419`), `sync_all()` (`:1225`) | editar/borrar |
| 11 | `includes/Sync/Customers.php` | `import_single_contact()` (`:328`), `sync_all()` (`:172`) | editar/borrar |
| 12 | `includes/Sync/Categories.php` | `sync_all()` (`:60`) | borrar |
| 13 | `uninstall.php` | `:48-123` | editar |
| 14 | `scripts/smoke-load.php` | `:575-576` (aserción de sección) | editar |
| 15 | `scripts/exec-test.php` | tests nuevos (Fases 1-5) | editar |

---

## 1. Principios

1. **Un solo punto de verdad.** La decisión "¿puede escribirse en Alegra?" vive en
   `Write_Gate::block_reason()` y se evalúa en `Client::request()`. Ningún otro lugar decide.
2. **El kill switch es duro.** Activo ⇒ bloquea **todo**, incluso la acción explícita del
   comerciante. No hay bypass.
3. **La configuración gobierna lo automático; la acción explícita del comerciante, no.**
   Sin acción explícita, nada se escribe sin opción habilitada.
4. **Fail-safe por defecto.** El contexto por defecto es **automático** (más restrictivo).
   Un camino explícito debe declararse explícitamente (`run_explicit`).
5. **Bloquear no es fallar.** El bloqueo devuelve un marcador, no un `WP_Error`; se loguea,
   no se reintenta.
6. **Sin regresión.** El marcador dry-run (`Client.php:110`) no cambia; los chequeos por
   callback se conservan; el harness queda verde.
7. **La migración no adivina.** Siembra sólo opciones ausentes y respeta valores existentes.

---

## 2. Núcleo: `Write_Gate` + `Client::request()`

### 2.1 Dónde vive el chequeo

El chequeo vive en **`Client::request()`**, exactamente **después** del guard de dry-run
(`Client.php:106-111`) y **antes** del throttle (`:114`). Es el único lugar por donde pasan
todas las escrituras (verificado: `wp_remote_request` sólo aparece en `Client.php:158`).

```php
// includes/API/Client.php — request(), reemplaza el bloque :103-111
$verb = strtoupper($method);
if ($verb !== 'GET') {
    // 1) Dry-run primero: comportamiento actual intacto (REQ-COMP-1).
    if (get_option('alegra_connector_dry_run', false)) {
        if ($this->logger) {
            $this->logger->warning('[DRY RUN] Blocked ' . $verb . ' ' . $endpoint, ['payload' => $data]);
        }
        return ['dry_run' => true, 'blocked' => $verb . ' ' . $endpoint];
    }
    // 2) Puerta de escritura: kill switch + habilitación por entidad (REQ-ENF-1/2).
    $entity = \Alegra\Connector\Write_Gate::entity_for($verb, $endpoint);
    $reason = \Alegra\Connector\Write_Gate::block_reason($entity);
    if ($reason !== null) {
        if ($this->logger) {
            $this->logger->warning('[WRITE GATE] Blocked ' . $verb . ' ' . $endpoint, [
                'entity' => $entity,
                'reason' => $reason,
            ]);
        }
        return [
            'blocked_by_gate' => true,
            'reason'          => $reason,
            'entity'          => $entity,
            'blocked'         => $verb . ' ' . $endpoint,
        ];
    }
}
```

### 2.2 Taxonomía de entidades y opción que gobierna

`Write_Gate::entity_for(string $method, string $endpoint): string` normaliza el endpoint
(sin querystring) por **prefijo** y devuelve una de estas claves:

```php
private const ENTITY_OPTIONS = [
    'invoice'     => 'alegra_connector_push_orders_enabled',
    'credit_note' => 'alegra_connector_push_orders_enabled',
    'payment'     => 'alegra_connector_payment_reconcile_enabled',
    'contact'     => 'alegra_connector_push_customers_enabled',
    'item'        => 'alegra_connector_push_products_enabled',
    'category'    => 'alegra_connector_push_products_enabled',
    'webhook'     => null,   // sólo explícito
    'other'       => null,   // sólo explícito
];
private const ENTITY_DEFAULTS = ['payment' => true]; // default real de la opción
```

| Entidad | Prefijo de endpoint | Opción (automático) | Default | Explícito permitido (KS off) |
|---|---|---|---|---|
| `invoice` | `/invoices` | `push_orders_enabled` | `false` | sí |
| `credit_note` | `/credit-notes` | `push_orders_enabled` | `false` | sí |
| `payment` | `/payments` | `payment_reconcile_enabled` | `true` | sí |
| `contact` | `/contacts` | `push_customers_enabled` (nueva) | `false` | sí |
| `item` | `/items` | `push_products_enabled` | `false` | sí |
| `category` | `/item-categories` | `push_products_enabled` | `false` | sí |
| `webhook` | `/webhooks/subscriptions` | — (sólo explícito) | — | sí |
| `other` | `/price-lists`, `/variant-attributes`, `/taxes`, `/inventory-adjustments`, `/estimates` | — (sólo explícito) | — | sí |

`entity_for` usa `preg_match('#^/invoices(/|$)#', $path)` y análogos, con `$path =
strtok($endpoint, '?')`. El orden importa: `/items` antes que `/item-categories` **no**
colisiona porque el patrón exige `/` o fin de cadena. `/invoices/{id}/open` cae en `invoice`.

### 2.3 Contexto explícito vs automático

`Write_Gate` mantiene un contador estático de profundidad:

```php
private static int $explicit_depth = 0;
public static function is_explicit(): bool { return self::$explicit_depth > 0; }
public static function begin_explicit(): void { self::$explicit_depth++; }
public static function end_explicit(): void { self::$explicit_depth = max(0, self::$explicit_depth - 1); }
public static function run_explicit(callable $fn): mixed
{
    self::begin_explicit();
    try { return $fn(); } finally { self::end_explicit(); }
}
```

**Default = automático** (fail-safe). Sólo estos puntos declaran contexto explícito:

| Punto de entrada | Archivo | Envoltorio |
|---|---|---|
| `ajax_sync_single` | `Admin_Dashboard.php:2273` | `run_explicit` |
| `ajax_bulk_sync` | `:2399` | `run_explicit` |
| `ajax_sync_pending_orders` | `:2765` | `run_explicit` |
| `ajax_sync_pending_page` | `:2825` | `run_explicit` |
| `ajax_record_payment` | `:2170` | `run_explicit` |
| `ajax_open_invoice` | `:2126` | `run_explicit` |
| `ajax_register_webhooks` | `:2537` | `run_explicit` |
| `ajax_delete_webhooks` | `:2710` | `run_explicit` |
| `ajax_disconnect` (cleanup de webhooks) | `:2897` | `run_explicit` |
| REST `handle_sync_request` | `Public_.php` | `run_explicit` |

**No** se envuelven (son automáticos): los hooks de `Public_` (`on_new_order`,
`on_payment_complete`, `on_order_paid_reconcile`, `on_new_product`, `on_new_customer`), los
de `State_Sync`, el cron (`run_cron_sync`, `run_payment_reconcile`) y el render del dashboard.

**Nota de producción vs harness.** En producción `wp_send_json_*` termina el request con
`wp_die()`; el `finally` no corre, pero el request muere y el flag es irrelevante. En el
harness `wp_send_json_*` lanza `Alegra_Test_JSON_Response` (capturado por
`alegra_capture_json`), así que el `finally` **sí** corre y el contador vuelve a 0. No hay
fuga de contexto entre tests.

### 2.4 Qué retorna y cómo se distingue de un error real

`block_reason(string $entity): ?string` devuelve `null` (permitido) o uno de:

| `reason` | Significado |
|---|---|
| `kill_switch` | `Kill_Switch::is_active()` es true (bloquea todo) |
| `entity_disabled` | contexto automático y la opción de la entidad está apagada |
| `not_explicit` | contexto automático y la entidad es `webhook`/`other` (sin opción) |

```php
public static function block_reason(string $entity): ?string
{
    if (\Alegra\Connector\Kill_Switch::is_active()) {
        return 'kill_switch';
    }
    if (self::is_explicit()) {
        return null;
    }
    $option = self::ENTITY_OPTIONS[$entity] ?? null;
    if ($option === null) {
        return 'not_explicit';
    }
    $default = self::ENTITY_DEFAULTS[$entity] ?? false;
    return get_option($option, $default) ? null : 'entity_disabled';
}
```

**Distinción de error:** el bloqueo es un **array** con `blocked_by_gate = true`; un error real
sigue siendo `WP_Error` (`Client.php:220`). Se agregan dos helpers a `Client`:

```php
public static function is_gate_blocked_response(mixed $result): bool
{
    return is_array($result) && !empty($result['blocked_by_gate']);
}
public static function write_was_blocked(mixed $result): bool
{
    return self::is_dry_run_response($result) || self::is_gate_blocked_response($result);
}
```

**Regla de call sites:** todo lugar que hoy usa `Client::is_dry_run_response($r)` para no
persistir estado falso debe pasar a `Client::write_was_blocked($r)`. Sitios verificados que
hoy chequean dry-run: `Orders.php:416,808,1136`, `Products.php:246`, `Admin_Dashboard.php:2237,2733`,
`State_Sync.php:157,305`, `Consumidor_Final.php:217`. Los sitios de escritura que **no**
chequean dry-run (`Products.php:174,263,2425,2486`; `Customers.php:37,51,74,618`;
`Categories.php:40,46`; `Orders.php:113,589,854,2001`; `Admin_Dashboard.php:2565`) deben
recibir el guard `write_was_blocked` en el mismo PR de Fase 1.

### 2.5 Composición con dry-run

Orden fijo: **dry-run → puerta → throttle → red**.
- `dry_run = true` ⇒ marcador dry-run (gana sobre la puerta; REQ-COMP-1).
- `dry_run = false` + puerta bloquea ⇒ marcador de puerta.
- Ambos ⇒ **cero** HTTP en cualquier caso.
- `GET` nunca se evalúa por la puerta (igual que dry-run).

### 2.6 Reconciliación con los chequeos por callback existentes

**Decisión: se CONSERVAN, no se eliminan.** Los chequeos de `Kill_Switch::is_active()` en
`Controller.php:87,119`, `Orders.php:535,2044,2088`, `Products.php:1093,1266,1306`,
`Customers.php:218,247` y `Categories.php:94,106` quedan como **fast-path** (evitan locks,
loops y trabajo) y como **defensa en profundidad**. Se agrega un comentario en cada uno:
`// Fast-path: la autoridad es Client::request() (Write_Gate).`
El early-return de `run_cron_sync` por `sync_method` (`Controller.php:129-135`) también se
conserva (gobierna el pull entrante, no las escrituras).

---

## 3. Honestidad de configuración

### 3.1 Opciones nuevas (registro, defaults, siembra, uninstall)

| Opción | Tipo | Default | `register_setting` | `$defaults` | `$non_autoload` | `uninstall.php` |
|---|---|---|---|---|---|---|
| `alegra_connector_payment_reconcile_enabled` | bool | `true` | sí (settings) | sí | sí | sí |
| `alegra_connector_payment_reconcile_batch` | int 1..100 | `20` | sí (settings) | sí | sí | sí |
| `alegra_connector_push_customers_enabled` | bool | `false` | sí (settings) | sí | no (lo lee el frontend) | sí |
| `alegra_connector_gate_migration_version` | int | `1` | no | no | sí | sí |

`register_setting` (en `Admin_Dashboard::register_settings()`, junto a `:408`):
```php
register_setting('alegra_connector_settings', 'alegra_connector_payment_reconcile_enabled', [
    'sanitize_callback' => 'rest_sanitize_boolean',
    'default' => true,
]);
register_setting('alegra_connector_settings', 'alegra_connector_payment_reconcile_batch', [
    'sanitize_callback' => fn($v) => max(1, min(100, (int) $v)),
    'default' => 20,
]);
register_setting('alegra_connector_settings', 'alegra_connector_push_customers_enabled', [
    'sanitize_callback' => 'rest_sanitize_boolean',
    'default' => false,
]);
```

UI (`templates/admin-settings.php`): en el tab **Avanzado** agregar el checkbox
`name="alegra_connector_payment_reconcile_enabled"` y el number
`name="alegra_connector_payment_reconcile_batch"`; en el tab **Sincronización**, después del
bloque de productos (`:99-102`), agregar el checkbox
`name="alegra_connector_push_customers_enabled"`.

### 3.2 Fix de los cuatro `sync_*` (REQ-CFG-2)

En `templates/admin-settings.php:78-81`, cambiar el default del `checked()` de `true` a
`false`:
```php
// antes: checked(get_option('alegra_connector_sync_products', true));
checked(get_option('alegra_connector_sync_products', false));
```
Idéntico para `sync_customers`, `sync_orders`, `sync_categories`. Esto alinea
UI (`false`) con runtime (`Controller.php:171,208,236,262`) y con activación
(`alegra-connector.php:409-412`).

### 3.3 Fix del doble registro de mappings (REQ-CFG-3)

**Cambio 1 — quitar del grupo Settings.** Eliminar `Admin_Dashboard.php:454-458`
(`field_mapping`) y `:459-463` (`tax_mapping`). Así la whitelist del `option_page`
`alegra_connector_settings` ya no los incluye y `options.php` no los toca al guardar Settings.

**Cambio 2 — endurecer el grupo Mapping.** Reemplazar `:563-564` por:
```php
register_setting('alegra_connector_mapping', 'alegra_connector_field_mapping', [
    'sanitize_callback' => function ($value) {
        if (!is_array($value)) {
            return (array) get_option('alegra_connector_field_mapping', []);
        }
        return map_deep($value, 'sanitize_text_field');
    },
]);
register_setting('alegra_connector_mapping', 'alegra_connector_tax_mapping', [
    'sanitize_callback' => function ($value) {
        if (!is_array($value)) {
            return (array) get_option('alegra_connector_tax_mapping', []);
        }
        return map_deep($value, 'sanitize_text_field');
    },
]);
```
**Por qué doble defensa:** quitar del grupo Settings resuelve el caso de hoy; el callback que
preserva ante `null` protege contra cualquier otro `option_page` que incluya la opción sin
postear todos sus campos. `update_option($opt, null)` → filtro → valor existente ⇒ no-op.

### 3.4 Migración (REQ-CFG-2, REQ-CFG-4)

Nueva opción `alegra_connector_gate_migration_version`. `Write_Gate::maybe_migrate()` se llama
desde `alegra-connector.php` en el mismo hook `plugins_loaded` donde hoy corre `Schema::migrate()`
(prioridad 5, `:201`), y en `activate()` después de sembrar `$defaults`:

```php
public static function maybe_migrate(): void
{
    if ((int) get_option('alegra_connector_gate_migration_version', 0) >= 1) {
        return;
    }
    // 1. Barrido de pagos controlable.
    if (get_option('alegra_connector_payment_reconcile_enabled') === false) {
        add_option('alegra_connector_payment_reconcile_enabled', true, '', 'no');
    }
    if (get_option('alegra_connector_payment_reconcile_batch') === false) {
        add_option('alegra_connector_payment_reconcile_batch', 20, '', 'no');
    }
    // 2. sync_*: cerrar la brecha UI/runtime en instalaciones que no las tienen.
    foreach (['sync_products', 'sync_customers', 'sync_orders', 'sync_categories'] as $k) {
        $opt = 'alegra_connector_' . $k;
        if (get_option($opt) === false) {
            add_option($opt, false, '', 'no');
        }
    }
    // 3. Toggle de clientes: preservar el comportamiento previo (rama A/B de Fase 0.3).
    if (get_option('alegra_connector_push_customers_enabled') === false) {
        add_option(
            'alegra_connector_push_customers_enabled',
            (bool) get_option('alegra_connector_push_products_enabled', false),
            '',
            'yes'
        );
    }
    update_option('alegra_connector_gate_migration_version', 1);
}
```

### 3.5 Reconciliación y refunds (REQ-ENF-1/2, REQ-CFG-1)

- **Hooks de pago en tiempo real** (`Public_.php:65-67`): se mantienen siempre registrados,
  pero `on_order_paid_reconcile` (`:278`) agrega al inicio, después del guard de
  re-entrancia, `if (!get_option('alegra_connector_payment_reconcile_enabled', true)) return;`.
  El kill switch **no** hace falta acá: lo aplica la puerta en `request()` (el `create_payment`
  de `Orders.php:396` devuelve el marcador de bloqueo y `reconcile_payment_only` no debe
  setear `_alegra_payment_id`). Aun así, para el fast-path, se agrega también
  `if (Kill_Switch::is_active()) return;`.
- **Barrido horario** (`Orders.php:493-499`): ya chequea el flag; se conserva. El chequeo de
  kill switch en el loop (`:535`) se conserva.
- **Refunds / payment-method** (`State_Sync.php`): se agrega el gate de entidad en
  `handle_refund` (entidad `credit_note`) y `handle_payment_method_change` (entidad `invoice`)
  **dentro de la puerta**, no hace falta un chequeo manual: `create_credit_note` /
  `update_invoice` pasan por `request()`. Para el fast-path, agregar
  `if (Kill_Switch::is_active()) return;` al inicio de cada handler.
  **Decisión:** los hooks de `State_Sync` siguen registrados siempre (un refund debe poder
  procesarse cuando el comerciante lo pide), pero la **escritura** la decide la puerta.

> **Punto de decisión (Fase 0.3).** La rama del gate para `credit_note`/`invoice` es
> `push_orders_enabled`. Si el comerciante tiene `push_orders_enabled = false` (modo manual)
> y hace un refund, la puerta bloquearía la nota de crédito **automática**. Es correcto por
> diseño (el comerciante no habilitó escrituras de pedidos), pero puede sorprender. La nota
> de release debe explicarlo y el botón manual de refund (si existe) debe usar `run_explicit`.

### 3.6 "Run now" honesto (REQ-CFG-5)

- `ajax_sync_now` (`Admin_Dashboard.php:1622`): antes de llamar `run_cron_sync` (`:1631`),
  si `sync_method ∉ {cron, both}` → `wp_send_json_error(['message' => __('La sincronización
  periódica está desactivada (método: %s). Cambiala a "Periódica" o "Periódica + Tiempo Real"
  para ejecutar ahora.', ...)])`. Si el kill switch está activo → mensaje de desconexión.
- `ajax_run_cron_now` (`:3134`): idéntica guarda **antes** de encolar
  `alegra_connector_cron_sync_now` (`:3161`).
- El `run_cron_sync` conserva su early-return (`Controller.php:129-135`) como defensa.

### 3.7 `payment_reconcile_batch` (REQ-CFG-1)

Hoy `Orders.php:499` lee `get_option('alegra_connector_payment_reconcile_batch', 20)` y lo
usa como tamaño de lote del sweep. El registro con sanitizer `max(1,min(100,(int)$v))` lo
hace controlable. **No** se cambia su semántica ni su uso.

---

## 4. Robustez

### 4.1 Consumidor Final nunca crea en un render (REQ-RB-1)

En `includes/Consumidor_Final.php`:
- Nuevo `public static function peek_id(): string|false` = pasos 1-3 de `get_id()`
  (override manual, transient, option), **sin** llamar `resolve()`.
- Nuevo `public static function is_configured(): bool` = `self::peek_id() !== false`.
- `get_id()` (`:42`) se conserva pero se renombra a `get_or_create_id()` y se actualizan sus
  llamadores. `resolve()` (`:87`) y `create()` (`:212`) sólo se invocan desde ahí.
- **Guarda dura en `create()`**: al inicio,
  `if (!\Alegra\Connector\Write_Gate::is_explicit()) { self::log_error('...requiere acción explícita'); return false; }`.
  Esto hace imposible crear desde un render aunque alguien vuelva a llamar a `get_id()`.
- `templates/admin-dashboard.php:18`: reemplazar `\Alegra\Connector\Consumidor_Final::is_available()`
  por `\Alegra\Connector\Consumidor_Final::is_configured()`.

### 4.2 Cancelación por lote e importadores (REQ-RB-2)

- `ajax_sync_page` (`Admin_Dashboard.php:1832`): al inicio de **cada iteración** del lote,
  ```php
  if (get_transient('alegra_sync_cancelled')) {
      delete_transient('alegra_sync_progress');
      wp_send_json_success(['done' => true, 'cancelled' => true, 'message' => __('Sincronización cancelada.', 'alegra-connector')]);
  }
  ```
- `ajax_sync_pending_page` (`:2825`): idéntico, antes de procesar el batch.
- `Products::import_single_item_from_alegra` (`:1419`) y `Customers::import_single_contact`
  (`:328`): agregar al inicio
  ```php
  if (\Alegra\Connector\Kill_Switch::is_active() || get_transient('alegra_sync_cancelled')) {
      return new \WP_Error('kill_switch_active', 'Plugin desconectado o sincronización cancelada');
  }
  ```
  (mismo contrato que `Products.php:1266` / `Customers.php:218`).
- `ajax_cancel_sync` (`:2532`) ya setea el transient: sin cambios.

### 4.3 `ajax_disconnect` honesto (REQ-RB-3)

Reordenar `Admin_Dashboard.php:2897-2955`:

1. `check_ajax_referer` + `current_user_can('manage_options')` (sin cambios).
2. **Borrar webhooks primero**, envuelto en `Write_Gate::run_explicit()`:
   ```php
   $subscriptions = (array) get_option('alegra_connector_webhook_subscriptions', []);
   $deleted = 0; $blocked = 0; $simulated = 0;
   Write_Gate::run_explicit(function () use ($subscriptions, &$deleted, &$blocked, &$simulated) {
       foreach ($subscriptions as $sub) {
           $id = $sub['id'] ?? '';
           if ($id === '' || !$this->api) { continue; }
           $r = $this->api->delete_webhook_subscription((string) $id);
           if (Client::is_gate_blocked_response($r)) { $blocked++; }
           elseif (Client::is_dry_run_response($r)) { $simulated++; }
           elseif (!is_wp_error($r)) { $deleted++; }
       }
   });
   ```
3. **Después** activar el kill switch (`Kill_Switch::activate('user_disconnected')`).
4. Limpiar transients/cron y opciones (sin cambios).
5. Responder con `webhooks_deleted => $deleted`, y si `$blocked>0`/`$simulated>0` incluir
   `blocked`/`simulated` y un mensaje acorde (no reportar como borrado lo que no se borró).

### 4.4 Webhook público (REQ-ENF-1)

`Receiver.php` (`:33-37`) no cambia su `permission_callback` (Alegra no firma; el token en la
URL es la única credencial). El **kill switch** ya cubre las escrituras que un webhook pudiera
disparar (hoy sólo muta estado local). Se agrega una guarda temprana opcional: si el kill
switch está activo, `handle()` responde 200 (para no desregistrar la suscripción) y **no**
procesa el evento, registrando `Webhook ignored: kill switch active`. Esto es defensa, no
cambio de contrato.

---

## 5. Higiene

### 5.1 Opciones sólo-escritura (REQ-HYG-1)

Eliminar las escrituras y dejar de crearlas:
- `Admin_Dashboard.php:1599` (`alegra_connector_items_count`) y `:1602`
  (`alegra_connector_contacts_count`): se escriben desde el resultado de `test_connection`
  y nunca se leen. **Borrar** ambas líneas.
- `Admin_Dashboard.php:2943` (`alegra_connector_disconnected_at`): nunca se lee. **Borrar**
  la línea (el estado de desconexión ya se comunica por `Kill_Switch::reason()` /
  `alegra_connector_disconnected_reason`).
- **Conservar** los `delete_option` correspondientes en `uninstall.php:87,88,112` para limpiar
  instalaciones viejas.

### 5.2 Métodos muertos de `Client` (REQ-HYG-1, BLOQUEADO Fase 0.2)

Si Fase 0.2 confirma **cero** consumidores externos, borrar de `includes/API/Client.php`:
`delete_item_category`, `void_credit_note`, `update_credit_note`, `delete_credit_note`,
`update_payment`, `delete_payment`, `void_payment`, `open_payment`, `create_price_list`,
`update_price_list`, `delete_price_list`, `create_inventory_adjustment`, `create_estimate`,
`update_invoice_retentions`.
**No** borrar `delete_contact` (llamado en `Customers.php:618`) ni `update_item_category`
(llamado en `Categories.php:40`). Conteo verificado: `Client` tiene **84** métodos públicos;
los 14 sin llamador salen del conteo.

### 5.3 `sync_all()` muertos (REQ-HYG-1)

Borrar `Sync/Products.php:1225`, `Sync/Customers.php:172`, `Sync/Categories.php:60`
(0 llamadores en producción verificado). Los benchmarks locales (`scripts/benchmark*`) que
definen sus propios `old_sync_all`/`new_sync_all` no se tocan.

### 5.4 `uninstall.php` (REQ-HYG-1)

Agregar en `alegra_connector_uninstall_options()`:
```php
delete_option('alegra_connector_payment_reconcile_enabled');
delete_option('alegra_connector_payment_reconcile_batch');
delete_option('alegra_connector_push_customers_enabled');
delete_option('alegra_connector_gate_migration_version');
```

### 5.5 Secciones decorativas (REQ-HYG-2, BLOQUEADO Fase 0.5)

Eliminar `Admin_Dashboard.php:566-571` (6 `add_settings_section()`). Actualizar la aserción
de `scripts/smoke-load.php:575-576` (que hoy exige
`add_settings_section('alegra_connector_billing_section')`) para que verifique lo que
realmente importa (que el template renderiza el campo de billing) o para que no exija la
sección. **No** se llama `do_settings_sections()`: sin `add_settings_field` registraría
secciones vacías y no cambiaría nada.

---

## 6. Migración / compatibilidad (por cambio)

| Cambio | Qué ve una instalación existente | Regresión | Mitigación |
|---|---|---|---|
| Kill switch como puerta real (REQ-ENF-1) | Con el plugin desconectado, los pushes manuales se **bloquean** (antes pasaban) | **Sí, intencional** | Mensaje claro en AJAX/REST; log; botón "limpiar kill switch"; nota de release |
| Gate por entidad (REQ-ENF-2) | Con `push_orders_enabled=false`, los refunds automáticos dejan de emitir nota de crédito | **Sí** | Documentado; el refund manual usa `run_explicit` |
| `payment_reconcile_enabled` (REQ-CFG-1) | Se siembra `true` si falta ⇒ el barrido sigue igual; aparece el control en la UI | No | Fase 0.4 rama A |
| `sync_*` a `false` en UI (REQ-CFG-2) | La UI muestra destildado lo que el runtime ya trataba como `false` | No (sólo UI) | Migración siembra ausentes en `false` |
| Mappings (REQ-CFG-3) | Guardar Settings ya no borra el mapping | No (fix) | — |
| `push_customers_enabled` (REQ-CFG-4) | Se siembra con el valor de `push_products_enabled` ⇒ los clientes siguen subiéndose igual | No | Fase 0.3 rama A/B |
| `Consumidor_Final` (REQ-RB-1) | El render deja de crear el contacto; se crea en la primera facturación explícita | Menor (el contacto aparece más tarde) | El flujo de facturación sigue creándolo |
| Cancelación (REQ-RB-2) | Los lotes se detienen al cancelar | No | — |
| `ajax_disconnect` (REQ-RB-3) | Borra webhooks antes de matar; reporta verdad | No | — |
| Higiene (REQ-HYG-1/2) | Nada visible salvo que un tercero llamara a un método borrado | Posible (externo) | Fase 0.2 |

**Versión objetivo:** 2.4.0 (cambio de comportamiento + migración ⇒ minor, no patch).

---

## 7. Interacciones con idempotencia y dry-run

1. **El gate no rompe la idempotencia.** Los guards de idempotencia existentes
   (`_alegra_invoice_id`, `_alegra_payment_id`, locks, `Tombstone_Manager`) siguen decidiendo
   *si* se intenta la escritura; el gate decide *si se permite*. Un bloqueo no setea los
   metas (los call sites usan `write_was_blocked`).
2. **El dry-run no se reemplaza.** Sigue siendo el primer chequeo y devuelve exactamente el
   mismo array (`Client.php:110`); las aserciones de `exec-test.php:1872,1960` no cambian.
3. **Un bloqueo no reintenta.** El marcador no es `WP_Error`, así que el loop de reintentos
   (`Client.php:157-233`) nunca lo ve.
4. **Un bloqueo no consume rate limit.** El gate corre antes del throttle (`:114`).
5. **El gate es determinista.** `Kill_Switch::is_active()` y `get_option()` son síncronos;
   no hay condición de carrera nueva.
6. **Reentrancia del contexto explícito.** `run_explicit` es reentrante (contador); un handler
   explícito que internamente llame otro explícito no resetea el contexto antes de tiempo.

---

## 8. Matriz de archivos (resumen operativo)

| Archivo | Acción | Requerimientos |
|---|---|---|
| `includes/Write_Gate.php` | **crear** | ENF-1/2/3, CFG-1/2/4, COMP-1 |
| `includes/API/Client.php` | editar (`request`, helpers) + borrar 14 métodos | ENF-1/2/3, HYG-1 |
| `alegra-connector.php` | editar (`$defaults`, `$non_autoload`, hook de migración) | CFG-1/2/4 |
| `admin/Admin/Admin_Dashboard.php` | editar (registro, secciones, handlers, disconnect) | CFG-1/3/5, RB-2/3, HYG-1/2 |
| `templates/admin-settings.php` | editar (defaults, controles nuevos) | CFG-1/2/4 |
| `public/Public/Public_.php` | editar (reconcile/customer hooks, REST) | ENF-1/2, CFG-1/4 |
| `includes/Sync/Orders.php` | editar (`reconcile_payment_only`) | ENF-1/2, CFG-1 |
| `includes/State_Sync.php` | editar (guards) | ENF-1/2 |
| `includes/Consumidor_Final.php` | editar (`peek_id`, `is_configured`, guard en `create`) | RB-1 |
| `templates/admin-dashboard.php` | editar (`:18`) | RB-1 |
| `includes/Sync/{Products,Customers}.php` | editar (importadores) + borrar `sync_all` | RB-2, HYG-1 |
| `includes/Sync/Categories.php` | borrar `sync_all` | HYG-1 |
| `uninstall.php` | editar (opciones nuevas) | HYG-1 |
| `scripts/smoke-load.php` | editar (aserción de sección) | HYG-2 |
| `scripts/exec-test.php` | agregar tests | todos |

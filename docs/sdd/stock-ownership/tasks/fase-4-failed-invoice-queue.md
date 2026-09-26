# Fase 4 — D3: ledger de fallos + clasificador + call sites + cron (micro-detalle)

| Campo | Valor |
|---|---|
| Cambio | `stock-ownership` |
| Documentos | `proposal.md` · `spec.md` · `design.md` · `tasks.md` |
| Fase | **4** — Cola de facturas fallidas: ledger por pedido, clasificador puro, call sites de persistencia, pantalla, cron con backoff, idempotencia y conteo cacheado |
| Versión analizada | 2.6.0 (`alegra-connector.php:6`) |
| Versión objetivo | **2.7.0** |
| Harness | `bash scripts/exec-test.sh` · `bash scripts/smoke-test.sh` · `smoke-load.php` (PSR-4 de las 3 clases nuevas) |
| Tareas del esqueleto | `T4.1`–`T4.11` (11 micro-tareas) |
| Depende de | Fase 1 (opciones `T1.5`, esqueletos `T1.6`/`T1.7`/`T1.8`, harness H-C `T1.3`, sección `T30` `T1.9`/`T1.10`) · Fase 2 (`owner()` `T2.1`) · Fase 3 (poll `T3.1`–`T3.3`, `reference` `T3.4`, `$divergence` `T3.5`) |
| Gates | `T4.1` además **G2** (sólo el símbolo `stock_insufficient`) · `T4.10` además **G4** (`paginate=>true`) |
| DoD de la fase | un fallo terminal queda en el ledger, aparece en la cola y en el badge; el reintento (single/bulk/cron) sube la factura y **nunca** crea una segunda; el conteo es O(1) cacheado. Tests canónicos `T30.41`–`T30.411` (11 IDs) verdes con prove-it-catches |

> **Regla de oro heredada (prove-it-catches).** Cada test nuevo se valida revirtiendo el fix: se corre
> `bash scripts/exec-test.sh`, **ese** test debe fallar, se re-aplica y vuelve a verde. Sin eso, el test
> no se acepta. Las tareas centrales de esta fase (`T4.3`) documentan su reversión en
> `docs/RELEASE_2.7.0_VERIFICATION.md` (`T7.8`).
>
> **IDs de test.** Los tests viven en `scripts/exec-test.php`, sección
> `// === stock-ownership (2.7.0) ===` (creada en `T1.9`), con IDs `T30.4x` (fase 4). La lista canónica
> (§2.1 de `tasks.md`) es: `T30.41`, `T30.42`, `T30.43`, `T30.44`, `T30.45`, `T30.46`, `T30.47`,
> `T30.48`, `T30.49`, `T30.410`, `T30.411` (**11** IDs). Un ID inline distinto queda **superseded**.
> `T4.x` son IDs de **tarea**, no de test.
>
> **Estado de HEAD (relevante).** El autoloader custom (`alegra-connector.php:95-134`) mapea
> `Alegra\Connector\Sync\X` → `includes/Sync/X.php` (subdir `Sync` en `:118`), así que las tres clases
> nuevas resuelven sin tocar el autoloader. `Invoice_Failure`, `Invoice_Queue` y `Stock_Divergence`
> **no existen** hoy (`find` = 0). El poll ya trae la máquina de estados de Fase 3
> (`Products.php:1327-1353`), pero `owner()` **todavía** no lee `alegra_connector_stock_owner`
> (`Inventory_Pusher.php:85-91`): `T2.1` es prerequisito real de `T4.9`/`T4.10`.

---

## Correcciones de cita / hallazgos re-verificados en HEAD (Fase 4)

| # | Cita (spec/design/tasks) | Realidad verificada en HEAD | Impacto |
|---|---|---|---|
| C1 | `find_existing_invoice()` cierra en `:341` (`spec.md:914,941`; `tasks.md` §9.2 A7) | **Falso.** `:309` firma, `:339` `return null;`, **`:340`** `}`. El rango correcto es `:309-340` (como dice `design.md` §4.3). | `T4.3` |
| C2 | `Orders.php:155-173` es la rama `is_wp_error` de `create_invoice()` (`design.md` §4.3) | **Aproximado.** `:155` es el POST `$this->api->create_invoice($data);`; el `if (is_wp_error($result))` arranca en **`:157`** y cierra en `:173`. La rama exacta es `:157-173`. | `T4.3`, `T4.4` |
| C3 | `run_payment_reconcile()` arranca en `:89` (`proposal.md` §Correcciones) | **Falso.** Arranca en **`:86`** (`:86` firma; `:93` `acquire_lock`; `:102` `release_lock`). El rango `:86-104` de `spec.md` REQ-QUEUE-06 es el correcto. | `T4.8` |
| C4 | Schedule del cron en `alegra-connector.php:669-674` (`tasks.md` T4.8) | **Aproximado.** La función es `maybe_self_heal_payment_reconcile()` **`:667-675`**; `:669` es `$hook = ...` y `:674` es `wp_schedule_event(time()+300, 'hourly', $hook)`. Citar `:667-675`. | `T4.8` |
| C5 | `Public_.php:411-416` es la nota + log de fallo automático (`proposal.md` Tema C, C2) | **Impreciso.** La nota está en `:409-413` y el log en `:415-421`; el bloque completo es **`:406-422`** (dentro de `trigger_sync()`, `:373`). `:411-416` cae a mitad de ambos. | `T4.3` (call site automático) |
| C6 | `spec.md:95` W1 = `Products.php:2274-2276` | **Demasiado angosto.** La firma está en `:2274` pero el cuerpo cierra en **`:2285`**; el rango útil es `:2274-2285` (el que usan `design.md` §3.4 y `tasks.md`). | Contexto de Fase 3 |
| C7 | "Nunca-intentado hoy sólo `processing\|completed`" (`design.md` §4.1; `tasks.md` §9.3 S2) | **Cierto sólo para `get_unjournaled_sales()`** (`Admin_Dashboard.php:865`). El bulk chunked **ya** incluye `on-hold` (`ajax_sync_pending_start`, `:3874`). El cambio intencional aplica a `get_unjournaled_sales()` y a `Invoice_Queue`. | `T4.10` |
| C8 | `Orders.php:525-526` "pago falló" (`design.md` §4.3 #4) | **Confirmado el rango**, pero `:525` es `if ($will_record_payment) {` y `:526` llama `record_payment_for_invoice(...)` **descartando el retorno**; la implementación real está en `:560`. `T4.6` debe capturar ese retorno. | `T4.6` |
| C9 | Códigos de datos del clasificador (`design.md` §4.2) | **Verificados:** `invoice_in_progress` (`Orders.php:76`), `draft_invoice_not_opened` (`:870`), `invoice_still_draft` (`:910`), `customer_unresolved` (`:1087,:1230`), `invoice_item_unlinked` (`:1670`), `invoice_shipping_unlinked` (`:1769`), `invoice_fee_unlinked` (`:1822`). `draft_invoice_not_opened` **no** figura en la tabla del diseño ⇒ se agrega como **permanente**. | `T4.1` |
| C10 | Clases nuevas `Invoice_Failure`/`Invoice_Queue` | **No existen** en HEAD (`find` = 0). El autoloader (`alegra-connector.php:95-134`, subdir `Sync` en `:118`) las resuelve por PSR-4 sin cambios. | `T1.6`/`T1.7`, `T4.x` |

**Citas confirmadas exactas:** `Inventory_Pusher.php:85-91` (`owner()`), `:32-33` (`META_SYNCED`/`META_PENDING`), `:68-71`/`:73-76` (`set_pending`/`clear_pending`), `:170-173` (Guard 2 `invoice_owner`), `:249-251` (`create_inventory_adjustment`), `:259` (log `Inventory adjustment failed`), `:303-335` (`adjustment_already_exists`), `:340-358` (`build_adjustment_payload`); `Client.php:141` (`rate_limited`), `:163`/`:248` (`json_error`), `:242` (`api_error`), `:257` (`max_retries`), `:300` (`write_was_blocked`), `:308` (`is_retryable_wp_error`), `:177-255` (loop de reintento); `Orders.php:63-193` (`create_invoice`), `:66-79` (lock por pedido), `:82-107` (guard `_alegra_invoice_id`), `:133-153` (pre-búsqueda AC-14), `:203-229` (`persist_invoice_result`), `:437-449` (`find_open_invoice_for_order`), `:494`/`:512`/`:525-526` (`create_invoice_with_payment`), `:850-913` (`ensure_invoice_open`); `Write_Gate.php:43`/`:58`/`:156`/`:182`; `Logger.php:198-241` (patrón option-backed); `State_Sync.php:473`/`:488-504`; `Admin_Dashboard.php:106-234` (`add_admin_menu`), `:862-887` (`get_unjournaled_sales`), `:2845-2895` (`ajax_open_invoice_impl`), `:2897`/`:2902-3028` (`ajax_record_payment`), `:3166`/`:3171` (`ajax_sync_single`), `:3305`/`:3310` (`ajax_bulk_sync`), `:3867-3901` (`ajax_sync_pending_start`), `:3903-3979` (`ajax_sync_pending_page_impl`), `:3898`/`:3936`/`:3964` (transient `alegra_pending_invoice_batch`); `Controller.php:72-74` (registro del sweep de pagos), `:86-104` (`run_payment_reconcile`), `:154`/`:164` (lock global + shutdown), `:321`/`:375`/`:386` (`sync_entity`), `:440`/`:467` (`acquire_lock`/`release_lock`), `:549` (`acquire_sync_lock_public`); `Run_Context.php:81` (`wrap`); `alegra-connector.php:6` (2.6.0), `:418` (`push_orders_enabled` default false), `:455-456` (poll budget/max_pages), `:462` (`invoice_status=draft`), `:474-507`/`:509-513` (`$non_autoload` + loop), `:567` (`cron_hooks`), `:667-675` (schedule del sweep de pagos).

---

## Mapa de cobertura Fase 4 → requerimiento

| Micro-tarea | Qué cubre | Archivos |
|---|---|---|
| `T4.1` | Clasificador puro retriable/permanente/blocked | `includes/Sync/Invoice_Failure.php` (NUEVO) |
| `T4.2` | `persist()`/`clear()`/`get()` del ledger (7 metas, CRUD/HPOS) | `includes/Sync/Invoice_Failure.php` |
| `T4.3` | Call site de error + re-búsqueda post-error | `includes/Sync/Orders.php:157-173` |
| `T4.4` | Rama bloqueada (dry-run vs gate) | `includes/Sync/Orders.php` (tras `:155`) |
| `T4.5` | Éxito limpia el ledger + refresca el conteo | `includes/Sync/Orders.php:203-229` |
| `T4.6` | `payment_missing` (factura OK, pago falló) | `includes/Sync/Orders.php:525-526` |
| `T4.7` | `retry_failed_invoices()` (query + backoff + tope) | `includes/Sync/Orders.php` (NUEVO público) |
| `T4.8` | `run_invoice_retry()` + hook + schedule + Monitor | `includes/Sync/Controller.php`; `alegra-connector.php` |
| `T4.9` | Idempotencia del reintento (nunca segunda factura) | `includes/Sync/Orders.php:66-79,82-107,437-449` |
| `T4.10` | `Invoice_Queue::query/refresh_count/count` (G4) | `includes/Sync/Invoice_Queue.php` (NUEVO) |
| `T4.11` | Conteo cacheado option-backed (badge O(1)) | `alegra-connector.php`; `Invoice_Queue.php` |

---

### T4.1 — Clasificador puro `Invoice_Failure::classify()`

**Objetivo**: decidir, en un **solo lugar** compartido por el cron y la UI, si un resultado de subida es `failed_retriable`, `failed_permanent`, `blocked` o `resolved`, y si corresponde persistirlo.

**Descripción técnica**: hoy no existe clasificación: `Client::request()` mapea todo error HTTP a `WP_Error('api_error', …, ['code'=>$code,'response'=>$error_data])` (`Client.php:242`) y deja `rate_limited` (`:141`), `json_error` (`:163,:248`) y `max_retries` (`:257`) como codes propios. `Client::write_was_blocked()` (`:300`) detecta los markers `dry_run`/`blocked_by_gate` (arrays, no `WP_Error`). El clasificador es una **función pura** sin efectos: no escribe metas ni llama a la API. REQ-QUEUE-02; design §4.2.

**Desarrollo técnico** — `includes/Sync/Invoice_Failure.php` (NUEVO; namespace `Alegra\Connector\Sync`, resuelto por el autoloader `alegra-connector.php:118`):

ANTES: **no existe**.

DESPUÉS (esqueleto de `T1.6` completado):
```php
<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

final class Invoice_Failure
{
    /**
     * Clasificador puro. NO persiste, NO llama a la API.
     *
     * @param array|\WP_Error $result Resultado de un write (Client::create_invoice, etc.).
     * @return array{state:string,code:string,message:string,retriable:bool,persist:bool}
     */
    public static function classify(array|\WP_Error $result): array
    {
        // 1) Marker de bloqueo (array): dry-run NO persiste; gate ⇒ blocked.
        if (is_array($result) && \Alegra\Connector\API\Client::write_was_blocked($result)) {
            if (\Alegra\Connector\API\Client::is_dry_run_response($result)) {
                return ['state' => 'blocked', 'code' => 'dry_run', 'message' => '', 'retriable' => false, 'persist' => false];
            }
            $reason = (string) ($result['reason'] ?? 'unknown');
            return ['state' => 'blocked', 'code' => 'blocked:' . $reason, 'message' => '', 'retriable' => false, 'persist' => true];
        }

        // 1.b) Marker de skip (array): manual-only ⇒ NO retriable, NO persiste
        // (Oracle#10/DEF-10, `fase-2:576-587`). El `adjustment_manual_only` de
        // `T2.4` devuelve `['skipped'=>true, 'manual_only'=>true, 'reason'=>…]`;
        // sin esta rama temprana cae al fallback `failed_retriable`/`persist=true`
        // y el cron lo reintenta hasta el tope. `skipped` NO es `blocked`
        // (`Client::write_was_blocked()` sólo mira `dry_run`/`blocked_by_gate`).
        if (is_array($result) && !empty($result['skipped'])) {
            return ['state' => 'skipped', 'code' => (string) ($result['reason'] ?? 'skipped'),
                    'message' => '', 'retriable' => false, 'persist' => false];
        }

        // 2) Éxito (array con id): resolved (limpia el ledger).
        if (is_array($result) && isset($result['id']) && (string) $result['id'] !== '') {
            return ['state' => 'resolved', 'code' => '', 'message' => '', 'retriable' => false, 'persist' => false];
        }

        // 3) WP_Error.
        if ($result instanceof \WP_Error) {
            $code    = (string) $result->get_error_code();
            $message = wp_strip_all_tags((string) $result->get_error_message());
            $message = function_exists('mb_substr') ? mb_substr($message, 0, 500) : substr($message, 0, 500);

            // Red / timeout / 429 / 5xx / JSON / lock ⇒ retriable.
            if (in_array($code, ['rate_limited', 'http_request_failed', 'http_request_timeout', 'json_error', 'max_retries'], true)) {
                return ['state' => 'failed_retriable', 'code' => $code, 'message' => $message, 'retriable' => true, 'persist' => true];
            }
            if ($code === 'invoice_in_progress') {
                return ['state' => 'failed_retriable', 'code' => 'lock', 'message' => $message, 'retriable' => true, 'persist' => true];
            }

            $data = $result->get_error_data();
            $http = is_array($data) ? (int) ($data['code'] ?? 0) : 0;

            if ($code === 'api_error' && ($http === 429 || $http >= 500)) {
                return ['state' => 'failed_retriable', 'code' => (string) $http, 'message' => $message, 'retriable' => true, 'persist' => true];
            }
            if ($code === 'api_error' && in_array($http, [400, 401, 403, 404, 409, 422], true)) {
                return ['state' => 'failed_permanent', 'code' => (string) $http, 'message' => $message, 'retriable' => false, 'persist' => true];
            }

            // Datos: permanentes. `draft_invoice_not_opened` se agrega (C9).
            $permanent = ['customer_unresolved', 'invoice_item_unlinked', 'invoice_shipping_unlinked',
                          'invoice_fee_unlinked', 'invoice_still_draft', 'draft_invoice_not_opened'];
            if (in_array($code, $permanent, true)) {
                return ['state' => 'failed_permanent', 'code' => 'data:' . $code, 'message' => $message, 'retriable' => false, 'persist' => true];
            }

            // Desconocido ⇒ retriable (seguro: el cron lo reintenta con tope).
            return ['state' => 'failed_retriable', 'code' => $code, 'message' => $message, 'retriable' => true, 'persist' => true];
        }

        // Fallback conservador.
        return ['state' => 'failed_retriable', 'code' => 'unknown', 'message' => '', 'retriable' => true, 'persist' => true];
    }
}
```

**Opciones / decisión G2.** Todo `4xx ≠ 429` es **permanente** (seguro: no loopea). Si G2 confirma 422 con `response.errors` de existencias, se agrega el símbolo `stock_insufficient` **sin cambiar** la clasificación (sigue permanente). `T4.1` está `BLOQUEADO(Fase 0.3 / G2)` **sólo por ese símbolo**.

**Resultado esperado**: `classify()` devuelve el shape de 5 claves; un `WP_Error('api_error', …, ['code'=>503])` ⇒ `failed_retriable`; un `['dry_run'=>true]` ⇒ `persist=false`; un `['id'=>'x']` ⇒ `resolved`; un `['skipped'=>true, 'manual_only'=>true, 'reason'=>'adjustment_manual_only']` ⇒ `state=skipped`, `retriable=false`, `persist=false` (Oracle#10/DEF-10).

**Dependencias**: `T1.6` (esqueleto). Prerequisito de `T4.2`–`T4.7`.

**Trazabilidad**: REQ-QUEUE-02; `design.md` §4.2; hallazgo C7; Oracle#10/DEF-10 (`fase-2:576-587`).

**Verificación**: `T30.41` (retriable), `T30.42` (permanente), `T30.43` (blocked vs dry-run vs **skipped**). Sub-assert Oracle#10 (cross-fase, `fase-2:622-624`): `classify(['skipped'=>true, 'manual_only'=>true, 'reason'=>'adjustment_manual_only'])` ⇒ `retriable === false` y `persist === false`; un pedido con ese resultado **no** aparece en `retry_failed_invoices()`. **Prove-it-catches:** quitar la rama `api_error` 4xx ≠ 429 → `T30.42` falla; quitar `persist=false` del dry-run → `T30.43` falla; quitar la rama `skipped` (cae al fallback) → `T30.43` falla.

**Riesgo**: un code nuevo de Alegra cae a "desconocido ⇒ retriable" y podría loopear hasta el tope (5). Mitigado por el tope (`T4.7`). `draft_invoice_not_opened` es una adición al diseño (C9), documentarla en `CHANGELOG.md`. Un resultado `skipped` **nunca** se persiste ni se reintenta (manual-only).

**Estimación**: M (1,5 h).

---

### T4.2 — `persist()` / `clear()` / `get()` del ledger (CRUD/HPOS)

**Objetivo**: persistir el resultado de un fallo en 7 metas del pedido vía CRUD (HPOS-safe) y limpiarlas en el éxito sin tocar `_alegra_invoice_id`.

**Descripción técnica**: hoy "pendiente" se infiere de la **ausencia** de `_alegra_invoice_id` (`Admin_Dashboard.php:870`, `:3886`); no hay estado durable (`grep _alegra_invoice_sync_state` = 0). Los 7 metas exactos están en `spec.md:96-97` y `design.md` §4.1. Todo con `update_meta_data()` + `save()` (nunca SQL directo). REQ-QUEUE-01.

**Desarrollo técnico** — `includes/Sync/Invoice_Failure.php`, métodos nuevos:

```php
public const META_STATE     = '_alegra_invoice_sync_state';
public const META_CODE      = '_alegra_invoice_error_code';
public const META_MESSAGE   = '_alegra_invoice_error_message';
public const META_RETRIABLE = '_alegra_invoice_error_retriable';
public const META_ATTEMPTS  = '_alegra_invoice_attempts';
public const META_LAST      = '_alegra_invoice_last_attempt';
public const META_NEXT      = '_alegra_invoice_next_retry';

/**
 * @param array{state:string,code:string,message:string,retriable:bool,persist:bool} $c
 */
public static function persist(\WC_Order $order, array $c, ?int $next_retry_ts = null): void
{
    if (empty($c['persist'])) {
        return; // dry-run: no se persiste (REQ-QUEUE-02).
    }
    $order->update_meta_data(self::META_STATE, (string) $c['state']);
    $order->update_meta_data(self::META_CODE, (string) $c['code']);
    $order->update_meta_data(self::META_MESSAGE, (string) $c['message']);
    $order->update_meta_data(self::META_RETRIABLE, !empty($c['retriable']) ? '1' : '0');
    // B3/REQ-QUEUE-06: `WP_Meta_Query` con `META_ATTEMPTS < max` usa INNER JOIN;
    // un pedido recién fallado SIN esta fila queda excluido del cron para
    // SIEMPRE. Sembrarla en 0 en el primer fallo. No se pisa el valor existente
    // para no perder la cuenta entre reintentos (T4.7).
    if (!$order->meta_exists(self::META_ATTEMPTS)) {
        $order->update_meta_data(self::META_ATTEMPTS, 0);
    }
    $order->update_meta_data(self::META_LAST, gmdate('Y-m-d H:i:s'));
    if ($next_retry_ts !== null) {
        $order->update_meta_data(self::META_NEXT, gmdate('Y-m-d H:i:s', $next_retry_ts));
    }
    $order->save();
}

/** Éxito: limpia el ledger y marca resolved. NO toca `_alegra_invoice_id`. */
public static function clear(\WC_Order $order): void
{
    $order->update_meta_data(self::META_STATE, 'resolved');
    foreach ([self::META_CODE, self::META_MESSAGE, self::META_RETRIABLE, self::META_NEXT, self::META_ATTEMPTS] as $k) {
        $order->delete_meta_data($k);
    }
    $order->save();
}

/** @return array<string,string> */
public static function get(\WC_Order $order): array
{
    return [
        'state'     => (string) $order->get_meta(self::META_STATE, true),
        'code'      => (string) $order->get_meta(self::META_CODE, true),
        'message'   => (string) $order->get_meta(self::META_MESSAGE, true),
        'retriable' => (string) $order->get_meta(self::META_RETRIABLE, true),
        'attempts'  => (string) $order->get_meta(self::META_ATTEMPTS, true),
        'last'      => (string) $order->get_meta(self::META_LAST, true),
        'next'      => (string) $order->get_meta(self::META_NEXT, true),
    ];
}
```

**Nota de diseño.** `attempts` (`_alegra_invoice_attempts`) lo incrementa `T4.7`; `persist()` **lo siembra en `0` sólo si no existe** (B3) y no pisa el valor existente para no perder la cuenta entre reintentos. `clear()` deja `state=resolved` y borra el resto **incluido `attempts`**, para que la cola lo excluya sin perder el marcador de éxito y para que un fallo posterior arranque una cuenta nueva.

**Resultado esperado**: tras `persist()`, `get()` devuelve los 7 valores; tras `clear()`, `state=resolved` y `_alegra_invoice_id` intacto.

**Dependencias**: `T4.1`.

**Trazabilidad**: REQ-QUEUE-01; `spec.md:96-97`; `design.md` §4.1; hallazgo C1.

**Verificación**: `T30.44` (ledger persistido y sobrevive al request), `T30.45` (éxito limpia). **Prove-it-catches:** usar `update_post_meta()` en lugar de CRUD → `T30.44` falla bajo HPOS del stub; no limpiar en `clear()` → `T30.45` falla.

**Riesgo**: bajo. El ledger es aditivo: pedidos sin meta no aparecen en la cola (REQ-QUEUE-08).

**Estimación**: S (1 h).

---

### T4.3 — Call site de error + re-búsqueda post-error

**Objetivo**: que un fallo de `create_invoice()` deje el ledger; pero **antes** de persistir un fallo retriable/desconocido, re-buscar la factura por si el POST commiteó y se perdió la respuesta.

**Descripción técnica**: `create_invoice()` maneja el error en `:157-173` (no `:155-173`, ver C2): loguea y retorna el `WP_Error`. El cliente reintenta el POST internamente sin idempotency key (`Client.php:177-255`), así que un timeout puede haber dejado la factura creada. La re-búsqueda usa `find_existing_invoice()` (`:309-340`, C1) con el `client_id` ya calculado en `:137`. Si la encuentra ⇒ se adopta como éxito (`persist_invoice_result`, `:203-229`). REQ-QUEUE-01/09; `design.md` §4.3 #1.

**Desarrollo técnico** — `includes/Sync/Orders.php`, rama `:157-173`:

ANTES (`:157-173`):
```php
            if (is_wp_error($result)) {
                // D1 / REQ-CF-06: auto-sanado de la caché podrida (400 por client id
                // muerto). Sólo dispara con 400 + mención de cliente + id CF + una vez.
                $healed = $this->try_self_heal_dead_client($order, $data, $result);
                if ($healed !== null) {
                    return $healed;
                }

                if ($this->logger) {
                    $this->logger->error('Invoice creation failed', [
                        'order_id' => $order_id,
                        'error'    => $result->get_error_message(),
                    ]);
                }

                return $result;
            }
```

DESPUÉS:
```php
            if (is_wp_error($result)) {
                // D1 / REQ-CF-06: auto-sanado de la caché podrida (400 por client id
                // muerto). Sólo dispara con 400 + mención de cliente + id CF + una vez.
                $healed = $this->try_self_heal_dead_client($order, $data, $result);
                if ($healed !== null) {
                    return $healed;
                }

                // REQ-QUEUE-09: el POST pudo haber commiteado (timeout/5xx). Re-buscar
                // ANTES de persistir el fallo; si existe, adoptarla (no duplicar).
                $classification = Invoice_Failure::classify($result);
                if (!empty($classification['retriable']) || $classification['state'] === 'failed_retriable') {
                    $client_id = (string) ($data['client']['id'] ?? '');
                    $existing  = $this->find_existing_invoice($order, $client_id);
                    if ($existing !== null && (string) ($existing['id'] ?? '') !== '') {
                        $this->persist_invoice_result($order, (string) $existing['id'], $existing);
                        $order->add_order_note(sprintf(
                            __('[Alegra] Factura #%s recuperada tras un fallo de red; no se creó una nueva.', 'alegra-connector'),
                            (string) $existing['id']
                        ));
                        return ['id' => (string) $existing['id'], 'already_exists' => true, 'recovered' => true];
                    }
                }

                Invoice_Failure::persist($order, $classification);
                Invoice_Queue::refresh_count();   // REQ-QUEUE-07: badge/aviso al instante.

                if ($this->logger) {
                    $this->logger->error('Invoice creation failed', [
                        'order_id' => $order_id,
                        'error'    => $result->get_error_message(),
                    ]);
                }

                return $result;
            }
```

**Orden de operaciones:** auto-sanado (CF) → clasificar → si retriable, re-buscar → si existe, `persist_invoice_result()` (limpia ledger) y retornar éxito → si no, `persist()` → log → return.

**Resultado esperado**: un timeout con POST commiteado deja el pedido `resolved` con el id recuperado y **sin** una segunda factura; un fallo real de red deja `failed_retriable` con motivo y código.

**Dependencias**: `T4.1`, `T4.2`, `T4.10`/`T4.11` (`refresh_count()`). El call site automático (`Public_.php:406-422`, C5) hereda el ledger sin cambios propios.

**Trazabilidad**: REQ-QUEUE-01/09; `design.md` §4.3 #1; `spec.md` REQ-QUEUE-09; hallazgos C1/C2/C5.

**Verificación**: `T30.46` — un `POST /invoices` que entra pero cuya respuesta se pierde ⇒ la re-búsqueda adopta la factura y no se crea la segunda. **Prove-it-catches:** quitar la re-búsqueda post-error → `T30.46` falla.

**Riesgo**: una re-búsqueda extra (1 GET `/invoices?client_id=…`) por cada fallo retriable. Acotada a `limit=30` y sólo en el camino de error.

**Estimación**: M (1,5 h).

---

### T4.4 — Rama bloqueada: dry-run no persiste, gate ⇒ `blocked`

**Objetivo**: distinguir un bloqueo por dry-run (no persistir) de un bloqueo por gate/kill-switch (`blocked`), hoy **mudo**.

**Descripción técnica**: `create_invoice()` sólo chequea `is_wp_error($result)`; un marker de bloqueo es un **array** (`Client::request()`, `:113` dry-run, `:126-131` gate), así que se trata como éxito y no deja rastro. `Client::write_was_blocked()` (`:300`) unifica la detección. El diseño lo señala explícitamente (`design.md` §4.3 #2). REQ-QUEUE-02.

**Desarrollo técnico** — `includes/Sync/Orders.php`, entre el POST (`:155`) y el `isset($result['id'])` (`:175`):

ANTES (`:155-175`):
```php
            $result = $this->api->create_invoice($data);

            if (is_wp_error($result)) {
                /* … rama T4.3 … */
            }

            if (isset($result['id'])) {
```

DESPUÉS (insertar antes del `if (isset($result['id']))`):
```php
            // REQ-QUEUE-02: un marker de bloqueo es un ARRAY, no un WP_Error.
            // Dry-run NO se persiste; gate/kill-switch ⇒ `blocked` (no loopear).
            if (API\Client::write_was_blocked($result)) {
                $classification = Invoice_Failure::classify($result);
                Invoice_Failure::persist($order, $classification); // persist=false en dry-run
                if (!empty($classification['persist'])) {
                    Invoice_Queue::refresh_count();   // REQ-QUEUE-07.
                }
                if ($this->logger) {
                    $this->logger->warning('Invoice creation blocked by config', [
                        'order_id' => $order_id,
                        'state'    => $classification['state'],
                        'code'     => $classification['code'],
                    ]);
                }
                return $result;
            }

            if (isset($result['id'])) {
```

**Resultado esperado**: con `dry_run` activo, el pedido **no** aparece en la cola ni genera aviso; con kill-switch/entidad deshabilitada, queda `blocked` y el cron **nunca** lo reintenta.

**Dependencias**: `T4.1`, `T4.2`, `T4.10`/`T4.11` (`refresh_count()`).

**Trazabilidad**: REQ-QUEUE-02/08; `design.md` §4.3 #2; `spec.md` REQ-QUEUE-02 (escenarios dry-run y blocked).

**Verificación**: `T30.43` — dry-run ⇒ ledger vacío; gate ⇒ `blocked`. **Prove-it-catches:** persistir el dry-run → `T30.43` falla.

**Riesgo**: bajo. El `return $result` conserva el contrato de `create_invoice()` para los call sites existentes.

**Estimación**: S (1 h).

---

### T4.5 — `persist_invoice_result()` limpia el ledger (conteo diferido)

**Objetivo**: que todo camino de éxito saque el pedido de la cola **sin** pagar una meta query en el camino más frecuente.

**Descripción técnica**: `persist_invoice_result()` (`:203-229`) es el punto único de éxito (creación, pre-búsqueda AC-14, apertura de borrador). Hoy escribe `_alegra_invoice_id`/`_alegra_invoice_number`/`_alegra_invoice_status` y `save()`, pero no toca el ledger. Se le agrega `Invoice_Failure::clear()`. **NO** se llama `Invoice_Queue::refresh_count()` acá (DEF-11): el éxito es el camino más frecuente y una meta query por factura creada viola NFR-04. El conteo se refresca de forma **diferida**: al abrir la pantalla (`T6.3`) y en el batch del cron (`T4.7`). REQ-QUEUE-01/07.

**Desarrollo técnico** — `includes/Sync/Orders.php:203-229`:

ANTES (`:224-229`):
```php
        $order->save();

        // AC-07: write the indexed mapping so a later lookup never scans
        // wp_postmeta.meta_value (O(N²) on a large catalog).
        \Alegra\Connector\Entity_Map::map('invoice', $invoice_id, 'order', (int) $order->get_id());
    }
```

DESPUÉS:
```php
        $order->save();

        // REQ-QUEUE-01: éxito ⇒ sale de la cola. El badge se recalcula de forma
        // DIFERIDA (apertura de la pantalla / cron, DEF-11): no una meta query
        // pesada por cada factura creada (NFR-04).
        Invoice_Failure::clear($order);

        // AC-07: write the indexed mapping so a later lookup never scans
        // wp_postmeta.meta_value (O(N²) on a large catalog).
        \Alegra\Connector\Entity_Map::map('invoice', $invoice_id, 'order', (int) $order->get_id());
    }
```

**Resultado esperado**: tras un éxito, `get()` devuelve `state=resolved` sin `code`/`next`; `alegra_connector_invoice_failures_count` baja en el próximo `refresh_count()` (apertura de la cola o cron), no en el acto.

**Dependencias**: `T4.2`. El conteo lo refrescan `T4.7` (batch) y `T6.3` (apertura).

**Trazabilidad**: REQ-QUEUE-01/07; `design.md` §4.3 #3; DEF-11 (`REVIEW-oracle.md`).

**Verificación**: `T30.45` — éxito deja `state=resolved` sin `code`/`next`; tras `Invoice_Queue::refresh_count()` el conteo refleja el cambio. **Prove-it-catches:** quitar `clear()` → `T30.45` falla.

**Riesgo**: el badge queda stale hasta abrir la cola o correr el cron; aceptado (DEF-11, NFR-04 prioriza no hacer una query por factura creada).

**Estimación**: S (0,75 h).

---

### T4.6 — `payment_missing` en `create_invoice_with_payment()`

**Objetivo**: cuando la factura sube pero el pago no, registrar `payment_missing` (retriable) como estado **distinto**, para que aparezca en la cola.

**Descripción técnica**: `:525-526` llama `record_payment_for_invoice()` y **descarta el retorno** (C8). `record_payment_for_invoice()` (`:560`) devuelve `array|\WP_Error`. Se captura: si es `WP_Error` (o marker de bloqueo) se persiste `payment_missing` con `retriable=true`, para que el sweep de pagos o el reintento manual lo recuperen. Decisión del fork REQ-QUEUE-08: **sí**, la cola incluye `payment_missing`. `design.md` §4.3 #4.

**Desarrollo técnico** — `includes/Sync/Orders.php:525-526`:

ANTES:
```php
        if ($will_record_payment) {
            $this->record_payment_for_invoice($order, (string) $invoice_result['id']);
        } elseif (!in_array($payment_account, ['', '0'], true)
```

DESPUÉS:
```php
        if ($will_record_payment) {
            $payment = $this->record_payment_for_invoice($order, (string) $invoice_result['id']);

            // REQ-QUEUE-08: la factura subió pero el pago no ⇒ estado distinto,
            // retriable (el sweep de pagos o el reintento manual lo recuperan).
            if (is_wp_error($payment) || \Alegra\Connector\API\Client::write_was_blocked($payment)) {
                $c = [
                    'state'     => 'payment_missing',
                    'code'      => is_wp_error($payment) ? (string) $payment->get_error_code() : (string) ($payment['reason'] ?? 'blocked'),
                    'message'   => is_wp_error($payment) ? wp_strip_all_tags($payment->get_error_message()) : '',
                    'retriable' => true,
                    'persist'   => true,
                ];
                Invoice_Failure::persist($order, $c);
                Invoice_Queue::refresh_count();   // REQ-QUEUE-07.
                if ($this->logger) {
                    $this->logger->warning('Invoice created but payment failed', [
                        'order_id' => (int) $order->get_id(),
                        'code'     => $c['code'],
                    ]);
                }
            }
        } elseif (!in_array($payment_account, ['', '0'], true)
```

**Resultado esperado**: con la cuenta configurada y el pago fallando, el pedido queda `payment_missing` (no `failed_*`) y se lista como estado distinto; el éxito del pago limpia el ledger vía `persist_invoice_result`/`clear`.

**Dependencias**: `T4.1`, `T4.2`, `T4.10`/`T4.11` (`refresh_count()`).

**Trazabilidad**: REQ-QUEUE-08; `design.md` §4.3 #4; `spec.md` REQ-QUEUE-08; hallazgo C8.

**Verificación**: `T30.47` — factura OK + pago falló ⇒ `payment_missing`; la cola lo muestra distinto. **Prove-it-catches:** volver a descartar el retorno → `T30.47` falla.

**Riesgo**: que `payment_missing` se confunda con `failed_*` en los filtros; la pantalla lo trata como estado propio (T6.3).

**Estimación**: S (1 h).

---

### T4.7 — `Orders::retry_failed_invoices()` (query acotada + backoff + tope)

**Objetivo**: reintentar **sólo** los `failed_retriable` vencidos, con backoff exponencial y tope de intentos; al tope ⇒ `failed_permanent`. Permanentes/bloqueados **nunca** auto-reintentan.

**Descripción técnica**: no existe hoy ningún reintento de facturas (`grep` = 0); el único sweep outbound es el de pagos (`Controller.php:86-104`). La query usa el ledger (T4.2) con `meta_query`; el batch sale de `alegra_connector_invoice_retry_batch` (default **20**, `T1.5`). El tope sale de `alegra_connector_invoice_retry_max_attempts` (default **5**). El reintento corre en contexto **automático** (la compuerta aplica: si bloquea ⇒ `blocked`, no loopear). REQ-QUEUE-06; `design.md` §4.6.

**Desarrollo técnico** — `includes/Sync/Orders.php`, método público nuevo:

```php
private const RETRY_BACKOFF = [300, 900, 3600, 21600, 86400]; // [5m,15m,1h,6h,24h]

/**
 * @return array{checked:int,retried:int,resolved:int,failed:int,errors:int,skipped?:string}
 */
public function retry_failed_invoices(): array
{
    $max      = max(1, (int) get_option('alegra_connector_invoice_retry_max_attempts', 5));
    $batch    = max(1, (int) get_option('alegra_connector_invoice_retry_batch', 20));
    $now      = time();

    $ids = wc_get_orders([
        'status'     => ['processing', 'completed', 'on-hold'],
        'limit'      => $batch,
        'return'     => 'ids',
        'orderby'    => 'date',
        'order'      => 'ASC',
        'meta_query' => [
            'relation' => 'AND',
            ['key' => Invoice_Failure::META_STATE, 'value' => 'failed_retriable'],
            // B3: `persist()` siembra META_ATTEMPTS=0 en el primer fallo, así el
            // INNER JOIN de WP_Meta_Query SÍ matchea el pedido recién fallado.
            ['key' => Invoice_Failure::META_ATTEMPTS, 'value' => $max, 'compare' => '<', 'type' => 'NUMERIC'],
            ['relation' => 'OR',
                ['key' => Invoice_Failure::META_NEXT, 'compare' => 'NOT EXISTS'],
                ['key' => Invoice_Failure::META_NEXT, 'value' => gmdate('Y-m-d H:i:s', $now), 'compare' => '<=', 'type' => 'DATETIME'],
            ],
        ],
    ]);

    $out = ['checked' => 0, 'retried' => 0, 'resolved' => 0, 'failed' => 0, 'errors' => 0];

    foreach ($ids as $id) {
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order) { continue; }
        if (\Alegra\Connector\Kill_Switch::is_active()) { $out['skipped'] = 'kill_switch'; break; }
        if (\Alegra\Connector\Run_Context::should_stop()) { $out['skipped'] = 'cancelled'; break; }

        $out['checked']++;
        $attempts = (int) $order->get_meta(Invoice_Failure::META_ATTEMPTS, true) + 1;
        $order->update_meta_data(Invoice_Failure::META_ATTEMPTS, $attempts);

        $result = $this->create_invoice_with_payment($order); // contexto automático

        if (!is_wp_error($result) && !\Alegra\Connector\API\Client::write_was_blocked($result)) {
            // persist_invoice_result() ya limpió el ledger (T4.5).
            $out['retried']++;
            $out['resolved']++;
            continue;
        }

        $c = Invoice_Failure::classify($result);
        if ($c['state'] === 'blocked' || $c['retriable'] === false) {
            $c['state'] = $c['state'] === 'blocked' ? 'blocked' : 'failed_permanent';
            Invoice_Failure::persist($order, $c);
            $out['failed']++;
            continue;
        }

        if ($attempts >= $max) {
            $c['state']     = 'failed_permanent';
            $c['retriable'] = false;
            Invoice_Failure::persist($order, $c);
        } else {
            $delay = self::RETRY_BACKOFF[min($attempts - 1, count(self::RETRY_BACKOFF) - 1)];
            Invoice_Failure::persist($order, $c, $now + $delay);
        }
        $out['retried']++;
        $out['failed']++;
    }

    // REQ-QUEUE-07 / NFR-04: un solo refresco por batch (no uno por pedido).
    if ($out['checked'] > 0) {
        Invoice_Queue::refresh_count();
    }

    return $out;
}
```

**Orden de operaciones:** query (retriable + vencido + `attempts<max`) → por pedido: kill switch/stop → `attempts++` → `create_invoice_with_payment()` → éxito ⇒ resolved → no-retriable/blocked ⇒ persistir terminal → retriable con tope ⇒ `failed_permanent` → retriable sin tope ⇒ `next_retry = now + backoff`.

**Resultado esperado**: sólo `failed_retriable` vencidos se reintentan; el 5.º fallo pasa a `failed_permanent`; un permanente/bloqueado nunca se toca.

**Dependencias**: `T4.1`, `T4.2`, `T4.5`, `T4.9`, `T4.10`/`T4.11` (`refresh_count()` del batch). Consumido por `T4.8`.

**Trazabilidad**: REQ-QUEUE-06; `design.md` §4.6; `spec.md` REQ-QUEUE-06; matriz R8.

**Verificación**: `T30.49` — un retriable vencido se reintenta; **un pedido recién persistido como `failed_retriable` (sin `_alegra_invoice_attempts`) DEBE ser devuelto por la query (B3)**; un permanente no; tras `max` intentos ⇒ `failed_permanent`; `next_retry` respeta el backoff. **Prove-it-catches:** quitar el filtro `META_STATE=failed_retriable` → `T30.49` falla (tocaría permanentes); quitar el sembrado de `META_ATTEMPTS` en `persist()` → `T30.49` falla (el recién fallado no aparece).

**Riesgo**: una query con `meta_query` costosa en tiendas grandes ⇒ `limit=batch` (20) y el índice de meta de HPOS. `next_retry` en `DATETIME` GMT.

**Estimación**: L (2,5 h).

---

### T4.8 — `Controller::run_invoice_retry()` + registro + schedule + Monitor

**Objetivo**: exponer el sweep como entry point con kill switch, lock global y `Run_Context`, registrado en el Monitor, opt-in (default off).

**Descripción técnica**: el molde exacto es `run_payment_reconcile()` (`Controller.php:86-104`, C3), registrado en `register_cron_hook()` (`:72-74`). El schedule del sweep de pagos vive en `alegra-connector.php:667-675` (C4). El `cron_hooks` de desactivación está en `:567`. `Run_Context::wrap()` (`Run_Context.php:81`) crea el run que el Monitor lista. REQ-QUEUE-06.

**Desarrollo técnico** — `includes/Sync/Controller.php`:

ANTES (`:72-74`, dentro de `register_cron_hook()`):
```php
        if (!has_action('alegra_connector_payment_reconcile', [$this, 'run_payment_reconcile'])) {
            add_action('alegra_connector_payment_reconcile', [$this, 'run_payment_reconcile']);
        }
```

DESPUÉS (agregar después):
```php
        if (!has_action('alegra_connector_invoice_retry', [$this, 'run_invoice_retry'])) {
            add_action('alegra_connector_invoice_retry', [$this, 'run_invoice_retry']);
        }
```

Método nuevo (junto a `run_payment_reconcile`, `:86-104`):
```php
/**
 * Reintento horario de facturas fallidas retriables (REQ-QUEUE-06).
 * Opt-in: `alegra_connector_invoice_retry_enabled` (default false).
 *
 * @return array{checked?:int,retried?:int,resolved?:int,failed?:int,errors?:int,skipped?:string}
 */
public function run_invoice_retry(): array
{
    if (Kill_Switch::is_active()) {
        return ['skipped' => 'kill_switch'];
    }
    if (!get_option('alegra_connector_invoice_retry_enabled', false)) {
        return ['skipped' => 'disabled'];
    }

    $lock = self::acquire_lock('alegra_invoice_retry', 300);
    if ($lock === false) {
        return ['skipped' => 'locked'];
    }
    register_shutdown_function(static fn () => self::release_lock('alegra_invoice_retry', $lock));

    try {
        return Run_Context::wrap('invoice_retry', fn ($run_id) => $this->orders->retry_failed_invoices());
    } finally {
        self::release_lock('alegra_invoice_retry', $lock);
    }
}
```

Schedule — `alegra-connector.php`, junto a `maybe_self_heal_payment_reconcile()` (`:667-675`, C4):
```php
public function maybe_self_heal_invoice_retry(): void
{
    $hook = 'alegra_connector_invoice_retry';
    if (!get_option('alegra_connector_invoice_retry_enabled', false)) {
        wp_clear_scheduled_hook($hook);   // apagado ⇒ sin evento
        return;
    }
    if (wp_next_scheduled($hook) === false) {
        wp_schedule_event(time() + 300, 'hourly', $hook);
    }
}
```
Llamarlo en `activate()` (junto a `maybe_self_heal_payment_reconcile()`, `:529`) y en el hook de `plugins_loaded`/admin init donde ya se auto-sana el de pagos. Agregar `'alegra_connector_invoice_retry'` a `$cron_hooks` (`:567`) para que la desactivación lo limpie.

**Resultado esperado**: con `invoice_retry_enabled=true`, el cron horario corre el sweep y aparece un run `invoice_retry` en el Monitor; con el opt-in en false, `run_invoice_retry()` devuelve `skipped=disabled` y no hay evento agendado.

**Dependencias**: `T4.7`; `Run_Context`/`Runs` (existentes, `Run_Context.php:81`, `Runs.php:46`); opciones de `T1.5`.

**Trazabilidad**: REQ-QUEUE-06; `design.md` §4.6; `spec.md` REQ-QUEUE-06; hallazgos C3/C4.

**Verificación**: `T30.410` — el Monitor muestra el run `invoice_retry`; kill switch y lock se respetan; opt-in default false. **Prove-it-catches:** quitar el guard de `invoice_retry_enabled` → `T30.410` falla (correría sin opt-in).

**Riesgo**: crear documentos fiscales sin supervisión ⇒ opt-in default **false**. Sin cron real, el botón manual sigue disponible (NFR-02).

**Estimación**: M (1,5 h).

---

### T4.9 — Idempotencia del reintento (nunca segunda factura)

**Objetivo**: que un reintento (single/bulk/cron) nunca duplique la factura, reusando las guardas existentes.

**Descripción técnica**: las guardas ya existen: lock por pedido (`Orders.php:66-79`), guard `_alegra_invoice_id` (`:82-107`), pre-búsqueda AC-14 (`:133-153`), `find_open_invoice_for_order()` (`:437-449`), y la re-búsqueda post-error (`T4.3`). El AJAX single nuevo (T6.4) chequea `_alegra_invoice_id` **antes** de llamar y reporta "ya facturado". Dos reintentos en paralelo: uno toma el lock. REQ-QUEUE-09; `design.md` §4.5.

**Desarrollo técnico** — el contrato de idempotencia (sin código nuevo de fondo; se documenta el orden):

```
ajax_retry_invoice(order_id):
  1. check_ajax_referer + current_user_can('manage_woocommerce')   [NFR-06]
  2. $order = wc_get_order($order_id)
  3. if _alegra_invoice_id !== ''  ⇒ Invoice_Failure::clear($order);
                                     responder {state:'resolved', message:'ya facturado'}; FIN
  4. Controller::sync_entity('order', $order_id, 'complete')  → create_invoice_with_payment()
       create_invoice(): acquire_lock('alegra_invoice_lock_'.$id, 30)   [:66-79]
                         guard _alegra_invoice_id                       [:82-107]
                         find_open_invoice_for_order()                  [:437-449]
                         find_existing_invoice() (AC-14)                [:133-153]
  5. clasificar el resultado y persistir (T4.3/T4.5)
```

**Resultado esperado**: si `_alegra_invoice_id` existe ⇒ no se llama a la API y el pedido queda `resolved`; dos reintentos concurrentes ⇒ uno solo crea; un timeout con POST commiteado ⇒ `T4.3` lo adopta.

**Dependencias**: `T4.1`, `T4.2`, `T4.3`. Consumido por `T4.7`, `T6.4` (single), `T6.5` (bulk).

**Trazabilidad**: REQ-QUEUE-09; `design.md` §4.5; `spec.md` REQ-QUEUE-09; matriz R7.

**Verificación**: `T30.48` — un pedido ya facturado no se reintenta; dos reintentos en paralelo ⇒ una sola factura. **Prove-it-catches:** quitar el guard de `_alegra_invoice_id` del AJAX → `T30.48` falla.

**Riesgo**: el lock por pedido TTL 30 s; si el POST dura más, otro worker podría entrar. Mitigado porque la pre-búsqueda AC-14 corre antes del POST.

**Estimación**: M (1,5 h).

---

### T4.10 — `Invoice_Queue::query/refresh_count/count` (BLOQUEADO G4)

**Objetivo**: una única query paginada que devuelve los pedidos de la cola (4 estados + nunca-intentados) y un conteo O(1) para el badge.

**Descripción técnica**: hoy no hay lista. La query base es `get_unjournaled_sales()` (`Admin_Dashboard.php:862-887`, sólo `NOT EXISTS` y sólo `processing|completed` en `:865`). El diseño (`design.md` §4.4) define: status `[processing, completed, on-hold]`; `meta_query` OR (ledger en `failed_retriable|failed_permanent|blocked|payment_missing`) + nunca-intentado (`_alegra_invoice_id` ausente/vacío **y** sin ledger); `limit=20`, `paged=N`, `orderby date DESC`. `T4.10` está `BLOQUEADO(Fase 0.5 / G4)`: si `wc_get_orders(['paginate'=>true])` no devuelve `->total`, el conteo cae a `limit` acotado + caché. El stub H-C (`wp-stubs.php:1568-1611`, `T1.3`) debe soportar `paginate`. Nota S2/I3/C7: el bulk ya incluye `on-hold` (`:3874`); acá se agrega a `get_unjournaled_sales`/`Invoice_Queue`. REQ-QUEUE-03/07; NFR-04.

**Desarrollo técnico** — `includes/Sync/Invoice_Queue.php` (NUEVO):

```php
<?php
declare(strict_types=1);

namespace Alegra\Connector\Sync;

if (!defined('ABSPATH')) {
    exit;
}

final class Invoice_Queue
{
    public const PER_PAGE = 20;
    private const STATES  = ['failed_retriable', 'failed_permanent', 'blocked', 'payment_missing'];

    /**
     * @param array{state?:string,from?:string,to?:string,s?:string} $filters
     * @return array{ids:array<int,int>,total:int}
     */
    public static function query(array $filters = [], int $page = 1, int $per_page = self::PER_PAGE): array
    {
        $meta = self::meta_query($filters);
        $args = [
            'status'     => ['processing', 'completed', 'on-hold'],
            'limit'      => $per_page,
            'paged'      => max(1, $page),
            'paginate'   => true,                 // G4: ->orders + ->total
            'orderby'    => 'date',
            'order'      => 'DESC',
            'meta_query' => $meta,
        ];
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $args['date_created'] = trim(($filters['from'] ?? '') . '...' . ($filters['to'] ?? ''), '.');
        }
        $res = wc_get_orders($args);
        if (is_object($res) && isset($res->orders)) {
            $ids = array_map(static fn ($o) => (int) $o->get_id(), $res->orders);
            return ['ids' => $ids, 'total' => (int) ($res->total ?? count($ids))];
        }
        // G4 Rama B: sin `paginate`, `wc_get_orders` devuelve `WC_Order[]` (no ids).
        // Mapear el objeto a su id: `array_map('intval', $objetos)` casteaba cada
        // `WC_Order` a `1` (DEF-11, G4 Rama B roto). Se acepta también un int por
        // si algún caller usó `return=ids`.
        $ids = [];
        foreach ((array) $res as $o) {
            $ids[] = is_object($o) ? (int) $o->get_id() : (int) $o;
        }
        return ['ids' => $ids, 'total' => count($ids)];
    }

    /** G4 rama A: conteo O(1). G4 rama B: limit acotado + caché. */
    public static function refresh_count(): int
    {
        $args = [
            'status'     => ['processing', 'completed', 'on-hold'],
            'limit'      => 1,
            'paginate'   => true,
            'return'     => 'ids',
            'meta_query' => self::meta_query([]),
        ];
        $res = wc_get_orders($args);
        if (is_object($res) && isset($res->total)) {
            $count = (int) $res->total;
        } else {
            // G4 Rama B (sin `paginate`): conteo acotado a 200. Es una APROXIMACIÓN
            // documentada (DEF-11): con >200 fallos el badge queda en 200 (truncado,
            // no silencioso). La ruta real (Rama A) usa `->total`, O(1) exacto.
            // Opcional futuro: paginar el conteo si el harness cae siempre a Rama B.
            $count = count((array) wc_get_orders([
                'status'     => ['processing', 'completed', 'on-hold'],
                'limit'      => 200,
                'return'     => 'ids',
                'meta_query' => self::meta_query([]),
            ]));
        }

        update_option('alegra_connector_invoice_failures_count', $count, false);
        update_option('alegra_connector_invoice_failures_hash', self::failure_hash($count), false);
        return $count;
    }

    public static function count(): int
    {
        return max(0, (int) get_option('alegra_connector_invoice_failures_count', 0));
    }

    /** Alias de filtro (C6): `failed` = los DOS estados terminales. */
    private const STATE_ALIASES = [
        'failed' => ['failed_retriable', 'failed_permanent'],
    ];

    /**
     * Hash canónico del aviso dismissible (C4). Fuente única para T4.10/T6.6:
     * SIEMPRE `md5((string) $count)`. T6.6 delega acá, no escribe `(string) $count`.
     */
    public static function failure_hash(int $count): string
    {
        return md5((string) $count);
    }

    /** @return list<string> Estados efectivos del filtro ([] = sin filtro). */
    private static function resolve_states(string $state): array
    {
        if ($state === '') {
            return [];
        }
        if (isset(self::STATE_ALIASES[$state])) {
            return self::STATE_ALIASES[$state];
        }
        return in_array($state, self::STATES, true) ? [$state] : [];
    }

    private static function meta_query(array $filters): array
    {
        $states = self::resolve_states((string) ($filters['state'] ?? ''));
        if ($states !== []) {
            // Filtro explícito por estado ⇒ SÓLO el ledger. Excluye los
            // nunca-intentados del bulk de reintento (C6): no hay qué reintentar.
            return ['key' => Invoice_Failure::META_STATE, 'value' => $states, 'compare' => 'IN'];
        }
        $ledger = ['key' => Invoice_Failure::META_STATE, 'value' => self::STATES, 'compare' => 'IN'];
        // Nunca-intentado = `_alegra_invoice_id` AUSENTE **o** VACÍO (`''`) **y** sin
        // ledger. `NOT EXISTS` solo NO alcanza: un meta guardado como `''` existe y
        // quedaría afuera (DEF-11; `design.md` §4.4: "ausente **o** vacío"). El stub
        // distingue `= ''` de `NOT EXISTS` (`wp-stubs.php:1509-1565`).
        $never  = ['relation' => 'AND',
            ['relation' => 'OR',
                ['key' => '_alegra_invoice_id', 'compare' => 'NOT EXISTS'],
                ['key' => '_alegra_invoice_id', 'value' => '', 'compare' => '='],
            ],
            ['key' => Invoice_Failure::META_STATE, 'compare' => 'NOT EXISTS'],
        ];
        return ['relation' => 'OR', $ledger, $never];
    }
}
```

**Resultado esperado**: `query()` devuelve la página (20) con ids + total; `refresh_count()` deja `alegra_connector_invoice_failures_count`; un pedido nunca-intentado aparece como tal y un fallo con estado aparece con su estado.

**Nota `state=failed` (C6) y hash (C4).** `meta_query()` acepta el alias `'failed'` (= `failed_retriable` + `failed_permanent`) y, con un filtro explícito de estado, **no** OR-ea los nunca-intentados (el bulk de reintento no tiene qué reintentar en ellos). `T6.5` puede seguir pasando `['state' => 'failed']`. El hash del aviso sale **sólo** de `Invoice_Queue::failure_hash()`; `T6.6` delega en él (no escribe `(string) $count`).

**Dependencias**: `T1.3` (stub `paginate`), `T1.7` (esqueleto), `T4.2`. Consumido por `T6.3` (pantalla) y `T4.11`.

**Trazabilidad**: REQ-QUEUE-03/07; NFR-04; `design.md` §4.4; matriz R10; hallazgos C7/I3/S2.

**Verificación**: `T30.411` — con N pedidos fallidos, `query()` pagina y `refresh_count()` == total; `paginate` no soportado ⇒ fallback acotado. **Prove-it-catches:** quitar el `meta_query` del ledger → `T30.411` falla (listaría todo).

**Riesgo**: `meta_query` OR con `NOT EXISTS` puede ser costoso en catálogos grandes ⇒ paginado (20) + conteo cacheado. En HPOS, `wc_get_orders` con `paginate` usa el store correcto.

**Estimación**: M (1,5 h).

---

### T4.11 — Conteo cacheado option-backed

**Objetivo**: que el badge y el aviso lean un conteo cacheado y **nunca** ejecuten una query pesada por carga del admin.

**Descripción técnica**: el cron escribe fallos sin request de usuario, así que el patrón debe ser option-backed (como `alegra_connector_logger_write_failed`, `Logger.php:198-241`). Opciones nuevas: `alegra_connector_invoice_failures_count` (int) y `_failures_hash` (string), ambas non-autoload (`T1.5`, `alegra-connector.php:474-507`). `refresh_count()` (T4.10) se llama en el **persist de un fallo** (raro) y en la **apertura de la pantalla**; el camino de **éxito NO** refresca (DEF-11: diferido); el badge (T6.6) lee la option. REQ-QUEUE-07; NFR-04.

**Desarrollo técnico** — ya definido en `T4.10` (`refresh_count`/`count`). Puntos de llamada:

| Evento | Dónde | Llamada |
|---|---|---|
| Persist de un fallo (single) | `T4.3`/`T4.4`/`T4.6` | `Invoice_Queue::refresh_count()` tras `Invoice_Failure::persist()` |
| Persist de fallos (batch cron) | `T4.7` | `refresh_count()` **una vez** tras el `foreach` (NFR-04) |
| Éxito | `T4.5` (`Orders.php:203-229`) | **Diferido (DEF-11):** sin query; el conteo se refresca al abrir la pantalla (`T6.3`) o en el cron (`T4.7`) |
| Aviso dismissible | `T6.6` | `update_option(..., Invoice_Queue::failure_hash($count), ...)` (C4) |
| Apertura de la pantalla | `T6.3` (`render_invoice_queue_page`) | `refresh_count()` una vez |
| Badge | `T6.6` (`add_admin_menu`) | `Invoice_Queue::count()` (lee la option) |

**Resultado esperado**: el badge muestra N sin query; al resolver el último fallo, tras el `refresh_count()` diferido (apertura de la pantalla o cron), `count()` = 0 y el aviso desaparece.

**Dependencias**: `T4.10`, `T1.5` (opciones non-autoload).

**Trazabilidad**: REQ-QUEUE-07; NFR-04; `design.md` §4.7; `Logger.php:198-241`; matriz R9.

**Verificación**: `T30.411` — `count()` es O(1) y refleja el último `refresh_count()`. **Prove-it-catches:** leer el conteo con una query en `count()` → `T30.411` falla (query por página).

**Riesgo**: un conteo stale si nadie refresca tras un cambio externo; la pantalla refresca al abrirse y el cron al terminar.

**Estimación**: S (1 h).

---

## DoD Fase 4 (checklist de cierre)

- [ ] `T4.1`: `Invoice_Failure::classify()` con las 5 claves; reglas retriable/permanente/blocked/dry-run; `draft_invoice_not_opened` permanente (C9); símbolo `stock_insufficient` según G2.
- [ ] `T4.2`: los 7 metas vía CRUD; `persist()` siembra `_alegra_invoice_attempts=0` si falta (B3); `clear()` no toca `_alegra_invoice_id` y resetea `attempts`.
- [ ] `T4.3`: re-búsqueda post-error (`find_existing_invoice` `:309-340`) **antes** de persistir un retriable; timeout con POST commiteado ⇒ `resolved`.
- [ ] `T4.4`: `write_was_blocked()` (`Client.php:300`); dry-run no persiste; gate ⇒ `blocked`.
- [ ] `T4.5`: éxito limpia el ledger; el conteo se refresca de forma **diferida** (pantalla/cron, DEF-11).
- [ ] `T4.6`: `payment_missing` capturando el retorno de `record_payment_for_invoice()` (`:560`).
- [ ] `T4.7`: sólo `failed_retriable` vencidos (incluye el recién fallado con `attempts=0`, B3); backoff `[5m,15m,1h,6h,24h]`; tope 5 ⇒ `failed_permanent`; permanentes/bloqueados nunca auto; `refresh_count()` una vez por batch.
- [ ] `T4.8`: hook `alegra_connector_invoice_retry` en `register_cron_hook()` (`:72-74`); schedule `hourly` opt-in; `Run_Context::wrap('invoice_retry', …)`; limpiado en `:567`.
- [ ] `T4.9`: guard `_alegra_invoice_id` + lock por pedido + AC-14 + `find_open_invoice_for_order()`; el AJAX reporta "ya facturado".
- [ ] `T4.10`: `Invoice_Queue::query/refresh_count/count` con `status=[processing,completed,on-hold]`; `paginate=>true` (G4); nunca-intentado = `_alegra_invoice_id` ausente **o** `''` (DEF-11); fallback G4 Rama B mapea `->get_id()` (DEF-11) y documenta el tope 200; alias `'failed'` (C6) y `failure_hash()` único (C4).
- [ ] `T4.11`: conteo cacheado en `alegra_connector_invoice_failures_count` (non-autoload); refrescado en persist de fallo/apertura (éxito **diferido**, DEF-11), nunca por página.
- [ ] Los **11 IDs canónicos** (`T30.41`–`T30.411`) verdes con prove-it-catches.
- [ ] **Cross-ref G2** (`T0.3`): la forma del error de stock define sólo el símbolo, no la clasificación.
- [ ] **Cross-ref G4** (`T0.5`): `paginate=>true` devuelve `->total`; si no, fallback acotado cacheado.

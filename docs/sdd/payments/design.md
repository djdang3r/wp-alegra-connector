# Diseño Técnico — Pagos, Reconciliación y Ajustes

| Campo | Valor |
|---|---|
| Cambio | `payments` |
| Documentos | `proposal.md` · `spec.md` · `tasks.md` |
| Versión analizada | 2.3.10 (`alegra-connector.php:6`) / constante `2.3.7` (`:29`) |
| Regla | Toda decisión nombra archivo, método y comportamiento actual vs propuesto |

> Este diseño es **cerrado**: dos desarrolladores deben implementar lo mismo.
> Donde hay una elección, está tomada y justificada. Donde depende de Fase 0, se
> declaran las dos ramas y el punto exacto de bifurcación.
>
> **Principio rector:** WooCommerce es la **fuente de verdad** del pago. El plugin
> pregunta a WC (`is_paid()`, `get_date_paid()`, `get_total()`,
> `get_payment_method()`); nunca infiere el pago por el nombre de la pasarela ni por
> lo que la pasarela muestra en su propio panel.

---

## 0. Mapa de cambios

| # | Archivo | Símbolo | Tipo |
|---|---|---|---|
| 1 | `admin/Admin/Admin_Dashboard.php` | `ajax_sync_single()` (`:2216` acción `create` → `complete`) | editar |
| 1 | `includes/Sync/Orders.php` | `create_invoice_with_payment()` (`:279`), extraer `record_payment_for_invoice()` | editar/extraer |
| 1 | `admin/Admin/Admin_Dashboard.php` | `ajax_bulk_sync()` (`:2332`) | editar |
| 1 | `includes/Sync/Orders.php` | `sync_recent()` (`:720`), `prepare_payment_data()` (`:1467`) | editar |
| — | `admin/Admin/Admin_Dashboard.php` | `ajax_sync_pending_page()` (`:2763`) | **sin cambio** (ya usa `create_invoice_with_payment`) |
| 2 | `includes/Sync/Orders.php` | `get_payment_method_code()` (`:1518`), `getPaymentMethodForGateway()` (`:1536`), `get_payment_gateway_code_mappings()` (`:1552`) | editar/unificar |
| 3 | `includes/Sync/Orders.php` | `ensure_invoice_open()` (`:400`), `API\Client::open_invoice()` (`Client.php:693`) | editar (BLOQUEADO Fase 0.8) |
| 4 | `templates/admin-settings.php` | bloque del `<select>` (`:337-350`) + label + banner (`:9-11`) | editar |
| 4 | `admin/Admin/Admin_Dashboard.php` | `register_settings()` (`:288-364`) → extraer `sanitize_alegra_id()` y `bank_account_select_options()` | editar/extraer |
| 5 | `public/Public/Public_.php` | `__construct()` (`:37-71`), nuevo `on_order_paid_reconcile()` | editar/agregar |
| 5 | `includes/Sync/Orders.php` | nuevo `reconcile_payment_only()`, extraer `record_payment_for_invoice()` | agregar/extraer |
| 6 | `includes/Sync/Controller.php` | nuevo `run_payment_reconcile()` | agregar |
| 6 | `alegra-connector.php` | scheduling del cron | editar |
| 7 | `includes/Billing_Fields.php` | `render_checkout_fields()` (`:499-501`) | editar |
| 7 | `includes/Checkout_Integration.php` | `block_options()` (`:188-202`) | editar |
| 8 | `alegra-connector.php` | `ALEGRA_CONNECTOR_VERSION` (`:29`) | editar |
| 8 | `scripts/build-release.sh` | preflight de versión | editar |
| 8 | `.github/workflows/release.yml` | nuevo | agregar |
| 8 | `README.md` | línea 29 | editar |

---

## 1. Causa raíz confirmada — "Facturar" no registra el pago

### 1.1 Comportamiento actual (bug confirmado)

El botón del detalle del pedido ("Crear factura") ejecuta:

```php
// Admin_Dashboard.php:2216
case 'order': $result = $sync_controller->sync_entity('order', $entity_id, 'create'); break;
```

```php
// Controller.php:309-313
switch ($action) {
    case 'create':
        return $this->orders->create_invoice($order);          // ← SIN lógica de pago
    case 'complete':
        return $this->orders->create_invoice_with_payment($order);
```

`Orders::create_invoice()` (`Orders.php:47`) crea la factura y **termina**. No hay
`prepare_payment_data`, no hay `POST /payments`, no hay `_alegra_payment_id`. Por eso la
factura queda **"Por Cobrar"**.

Los caminos que **sí** registran pago (todos con la cuenta `'5'` funcionando):

| Camino | Entrada | Termina en |
|---|---|---|
| "Facturar pendientes" por página | `ajax_sync_pending_page` (`Admin_Dashboard.php:2763`) | `create_invoice_with_payment()` |
| Evento de pago WC | `woocommerce_payment_complete` / `woocommerce_order_status_completed` → `sync_single_order('complete')` (`Controller.php:312-313`) | `create_invoice_with_payment()` |
| Botón "Registrar pago" | `ajax_record_payment` (`Admin_Dashboard.php:2093`) | `create_payment` |

**El diagnóstico anterior (clobber del `<select>` a `'0'`) era incorrecto:** la cuenta es
`'5'`, está guardada y se lee bien. El clobber es un bug latente (§4).

### 1.2 Comportamiento propuesto

**Decisión 1 — "Facturar" usa el camino completo.** `Admin_Dashboard.php:2216` pasa de
`'create'` a `'complete'`:

```php
// Admin_Dashboard.php:2216
case 'order': $result = $sync_controller->sync_entity('order', $entity_id, 'complete'); break;
```

El botón se rotula **"Facturar"** (hoy dice "Crear factura" en
`templates/admin-order-detail.php`); el rótulo se unifica para reflejar que la acción
factura y, si corresponde, cobra. Lo que cambia es el **comportamiento**, no un botón
nuevo.

**Decisión 2 — el flag `record_payment` se elimina.** Ya no hay dos botones: la decisión
"¿registro pago?" la toma `$order->is_paid()` **dentro** de
`create_invoice_with_payment`. Un pedido no pagado igual crea la factura (y no paga).

**Decisión 3 — guard `is_paid()` explícito.** `create_invoice_with_payment`
(`Orders.php:289-291`) pasa de:

```php
$will_record_payment = !in_array($payment_account, ['', '0'], true)
    && (string) $order->get_meta('_alegra_payment_id', true) === '';
```

a:

```php
$will_record_payment = !in_array($payment_account, ['', '0'], true)
    && (string) $order->get_meta('_alegra_payment_id', true) === ''
    && $order->is_paid();                       // ← WC es la fuente de verdad
```

**Efecto:** si el pedido no está pagado, la factura se crea con el estado configurado y
**no** se postea pago. La rama de `ensure_invoice_open` (`:314-319`) solo se activa con
`$will_record_payment === true`, así que no hay cambios colaterales.

**Decisión 4 — si no se paga, se avisa.** Cuando `!$order->is_paid()` y la cuenta está
configurada y no hay pago previo, se agrega una nota de pedido explicando que el pago no
se registró porque el pedido no figura pagado en WooCommerce (REQ-MAN-1).

**Decisión 5 — refactor sin duplicar.** El bloque de pago de
`create_invoice_with_payment` (`Orders.php:306-383`) se extrae a
`record_payment_for_invoice()` (ver §5.3), reutilizado por el camino manual, el
automático y el barrido.

### 1.3 Bulk y pendientes

- **Bulk** (`ajax_bulk_sync`, `:2332`): para `order`, `'create'` → `'complete'`.
- **Pendientes** (`sync_recent`, `Orders.php:720`): `create_invoice($order)` →
  `create_invoice_with_payment($order)`.

### 1.4 Migración / compatibilidad

- Pedidos no pagados facturados con la versión vieja **pudieron** haber recibido un pago
  indebido. No se revierte nada; el guard `is_paid()` evita que siga pasando.
- `ajax_sync_pending_page` (`:2763`) usaba `create_invoice_with_payment` sobre estados
  `processing|completed|on-hold`. Con el guard, `on-hold` deja de registrar pago
  (correcto: no está pagado). Documentar en CHANGELOG.
- El test `T12.4` (`exec-test.php:1683-1691`) usa `sync_entity('order', id, 'create')`
  → sigue sin pago. Verde.

---

## 2. Fuente de datos de pago y mapeo de pasarela

### 2.1 Datos que se leen de WooCommerce

| Dato | API de WC | Destino en el `POST /payments` |
|---|---|---|
| ¿Pagado? | `$order->is_paid()` | decide si se postea |
| Monto | `$order->get_total()` | `invoices[0].amount` |
| Fecha | `$order->get_date_paid()` | `date` (`Y-m-d`) |
| Pasarela/método | `$order->get_payment_method()` | `paymentMethod` (mapeado, §2.2) |
| Título del método | `$order->get_payment_method_title()` | `observations` / nota de pedido |

### 2.2 Comportamiento actual

`Orders::prepare_payment_data()` (`:1467-1487`):

```php
return [
    'date' => date('Y-m-d'),                                 // ← fecha inventada
    'bankAccount' => ['id' => $account_id],
    'invoices' => [[ 'id' => $invoice_id, 'amount' => (float) $order->get_total() ]],
    'paymentMethod' => $this->get_payment_method_code($order),
];
```

Dos defectos:

1. **Fecha:** `date('Y-m-d')` en vez de `$order->get_date_paid()`.
2. **Método:** el mapeo está duplicado con fallbacks distintos:

```php
// Orders.php:1518-1530
private function get_payment_method_code(\WC_Order $order): string {
    ...
    return 'transfer';                    // fallback A
}

// Orders.php:1536-1547
public function getPaymentMethodForGateway(string $gateway_slug): string {
    ...
    return 'cash';                        // fallback B (¡distinto!)
}
```

Tabla actual (`:1552-1591`): `mercadopago`, `woocommerce-mercado-pago`,
`woo-mercado-pago` → `'credit-card'`.

### 2.3 Verificación contra la API de Alegra

El enum válido de `paymentMethod` es:
`cash`, `check`, `transfer`, `deposit`, `credit-card`, `debit-card`
(fuente: <https://developer.alegra.com/reference/post_payments-1.md>).

- **Mercado Pago → `credit-card` es VÁLIDO.**
- **`transfer` y `cash` también son válidos.** La inconsistencia no rompe hoy, pero
  significa que el mismo gateway desconocido produce métodos distintos según qué
  función se llame.

### 2.4 Comportamiento propuesto

**(a) Fecha desde WC.**

```php
private function payment_date(\WC_Order $order): string
{
    $paid = $order->get_date_paid();
    if ($paid instanceof \DateTimeInterface) {
        return $paid->format('Y-m-d');
    }
    if ($this->logger) {
        $this->logger->warning('Payment date missing on a paid order; using today', [
            'order_id' => $order->get_id(),
        ]);
    }
    return date('Y-m-d');
}
```

**(b) Un único resolvedor de método.** Ambas funciones delegan en una sola:

```php
/**
 * Mapea el slug de pasarela de WC al paymentMethod de Alegra.
 * Fallback ÚNICO y documentado: 'transfer'.
 * Enum válido: cash|check|transfer|deposit|credit-card|debit-card.
 */
public function resolve_alegra_payment_method(string $gateway_slug): string
{
    foreach ($this->get_payment_gateway_code_mappings() as $slug => $code) {
        if ($slug !== '' && strpos($gateway_slug, $slug) !== false) {
            return $code;
        }
    }
    if ($this->logger) {
        $this->logger->warning('Unknown payment gateway; falling back to transfer', [
            'gateway' => $gateway_slug,
        ]);
    }
    return 'transfer';
}

private function get_payment_method_code(\WC_Order $order): string
{
    return $this->resolve_alegra_payment_method($order->get_payment_method());
}

public function getPaymentMethodForGateway(string $gateway_slug): string
{
    return $this->resolve_alegra_payment_method($gateway_slug);
}
```

**Garantía:** para cualquier slug, ambas funciones devuelven el **mismo** valor y ese
valor pertenece al enum oficial.

**(c) `prepare_payment_data` propuesto.**

```php
private function prepare_payment_data(\WC_Order $order, string $invoice_id): array
{
    $account_id = (string) get_option('alegra_connector_payment_account_id', '');
    if (in_array($account_id, ['', '0'], true)) {
        return [];
    }

    $amount = (float) $order->get_total();

    // El pago es total: si el saldo de la factura difiere, se reporta (no se ajusta).
    $this->assert_full_payment_matches_balance($order, $invoice_id, $amount);

    $observations = trim(sprintf(
        'Pedido #%d — %s',
        $order->get_id(),
        (string) $order->get_payment_method_title()
    ));

    return [
        'date' => $this->payment_date($order),
        'bankAccount' => ['id' => $account_id],
        'invoices' => [[ 'id' => $invoice_id, 'amount' => $amount ]],
        'paymentMethod' => $this->resolve_alegra_payment_method($order->get_payment_method()),
        'observations' => $observations,
    ];
}
```

**(d) Discrepancia monto vs saldo.**

```php
private function assert_full_payment_matches_balance(\WC_Order $order, string $invoice_id, float $amount): void
{
    $invoice = $this->api->get_invoice($invoice_id);
    if (is_wp_error($invoice) || !is_array($invoice)) {
        return; // no bloquea el pago por no poder leer el saldo
    }
    $balance = isset($invoice['balance']) ? (float) $invoice['balance'] : null;
    if ($balance !== null && abs($balance - $amount) > 0.01) {
        $order->add_order_note(sprintf(
            __('[Alegra] Aviso: el total del pedido (%1$s) no coincide con el saldo de la factura #%2$s (%3$s). Se registró el pago por el total del pedido; revisá la factura.', 'alegra-connector'),
            number_format($amount, 2, '.', ''),
            $invoice_id,
            number_format($balance, 2, '.', '')
        ));
        if ($this->logger) {
            $this->logger->warning('Payment amount differs from invoice balance', [
                'order_id' => $order->get_id(), 'invoice_id' => $invoice_id,
                'amount' => $amount, 'balance' => $balance,
            ]);
        }
    }
}
```

### 2.5 Migración / compatibilidad

- Pagos nuevos llevan la fecha real del pedido. Pagos viejos no se tocan.
- El cambio de fallback (`cash` → `transfer`) solo afecta a pasarelas desconocidas
  nuevas; no altera ninguna pasarela ya mapeada.

---

## 3. Modo borrador — abrir la factura antes de pagar

### 3.1 Alcance real

Este caso **no aplica al comerciante que reportó el bug**: sus facturas se crean
**`open`** (configuró `invoice_status` en consecuencia), así que la factura ya era
pagable y el pago igual no se registró — lo que confirma que la causa es el camino
manual (§1), no el estado de la factura.

Aplica a comerciantes con `invoice_status = draft`: Alegra solo acepta pagos sobre
facturas abiertas.

### 3.2 Comportamiento actual (sospechoso)

`Orders::ensure_invoice_open()` (`:400-433`) lee la factura y, si está en `draft`,
llama `API\Client::open_invoice()` (`Client.php:693-696`) → `POST /invoices/{id}/open`.

**Problema:** la documentación de Alegra describe `POST /invoices/{id}/open` como
**"revertir la anulación"** (un-void), **no** como borrador→abierto.
Fuente: <https://developer.alegra.com/reference/post_invoices-id-open.md>.
La alternativa documentada para editar el estado es `PUT /invoices/{id}` con
`{"status":"open"}` (<https://developer.alegra.com/reference/put_invoices-id.md>), pero
**hay que verificar en vivo** que acepte `status`.

### 3.3 Comportamiento propuesto — BLOQUEADO Fase 0.8

- **Rama A (`POST /invoices/{id}/open` sí abre un borrador):** `ensure_invoice_open` se
  mantiene tal cual.
- **Rama B (`/open` es un-void):** `ensure_invoice_open` pasa a usar
  `PUT /invoices/{id}` con `{"status":"open"}` (nuevo
  `API\Client::update_invoice_status()`), y el test `T-DRAFT-1` cubre ambos caminos.

En ambos casos, el contrato de `ensure_invoice_open` no cambia: recibe un invoice id y
devuelve la factura abierta o `WP_Error`.

### 3.4 Idempotencia

Sin cambios: si la factura ya está `open`, `ensure_invoice_open` es no-op (`:413-416`).

---

## 4. Cuenta de destino (Hallazgo latente) y ajustes

### 4.1 Comportamiento actual (bug latente)

`templates/admin-settings.php:337-350`:

```php
<select name="alegra_connector_payment_account_id">
    <option value="0">-- Sin cuenta (no se registraran pagos) --</option>
    <?php foreach($alegra_bank_accounts as $ba): $id=(string)($ba['id']??'');?>
    <option value="<?php echo esc_attr($id);?>"
        <?php selected((string)get_option('alegra_connector_payment_account_id',''),$id);?>>
        <?php echo esc_html(($ba['name']??'Banco').' (ID: '.$id.')');?>
    </option>
    <?php endforeach;?>
</select>
```

- La opción `value="0"` va **primera y sin `selected`**.
- Si el id guardado no está entre las opciones (fetch incompleto, cuenta
  renombrada/borrada), **ninguna** opción tiene `selected` → el navegador selecciona la
  primera → el POST envía `0`.
- El sanitizador (`Admin_Dashboard.php:294-304`) acepta `'0'` (`ctype_digit('0')` =
  `true`) → **sobrescribe el id con `'0'`**.
- Los lectores tratan `'0'` como no configurado (`Orders.php:289-291`, `:1469-1474`,
  `Admin_Dashboard.php:2122-2125`) → pago salteado en silencio.
- Entrada inválida devuelve el valor previo (`:303`) **sin avisar**, y la plantilla
  muestra "Configuración guardada correctamente" (`:9-11`).
- El label dice "Cuenta bancaria" pero la lista incluye **cajas** (Fase 0.3).

**Importante:** en la tienda del reportante **no ocurrió** (la cuenta es `'5'` y está
`selected`). Se corrige por robustez.

### 4.2 Comportamiento propuesto

#### (a) Armado puro y testeable de opciones

Agregar a `Admin_Dashboard` (junto a `sanitize_masked_secret`, ~línea 288):

```php
/**
 * Construye las opciones del <select> de cuenta de destino garantizando que el
 * valor guardado SIEMPRE quede seleccionado. Si el id guardado no está en la
 * lista, se inyecta una opción sintética.
 *
 * @param array<int,array<string,mixed>> $accounts Cuentas de /bank-accounts.
 * @param string $stored Valor actual de la opción (id numérico, p. ej. '5').
 * @return array<int,array{value:string,label:string,selected:bool}>
 */
public static function bank_account_select_options(array $accounts, string $stored): array
{
    $stored = trim($stored);
    $none_selected = in_array($stored, ['', '0'], true);

    $options = [[
        'value'    => '0',
        'label'    => __('-- Sin cuenta (no se registraran pagos) --', 'alegra-connector'),
        'selected' => $none_selected,
    ]];

    $ids = [];
    foreach ($accounts as $ba) {
        $id = (string) ($ba['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $ids[] = $id;
        $name = (string) ($ba['name'] ?? 'Cuenta');
        $options[] = [
            'value'    => $id,
            'label'    => $name . ' (ID: ' . $id . ')',
            'selected' => ($stored === $id),
        ];
    }

    // El id guardado no vino en la lista: opción sintética seleccionada.
    if (!$none_selected && !in_array($stored, $ids, true)) {
        $options[] = [
            'value'    => $stored,
            'label'    => sprintf(
                __('Cuenta guardada (no sincronizada) — ID: %s', 'alegra-connector'),
                $stored
            ),
            'selected' => true,
        ];
    }

    return $options;
}
```

La plantilla pasa a iterar este método y el `if` se amplía para renderizar el `<select>`
cuando hay id guardado aunque la lista venga vacía:

```php
<?php if (!empty($alegra_bank_accounts) || !in_array((string) get_option('alegra_connector_payment_account_id',''), ['','0'], true)): ?>
<select name="alegra_connector_payment_account_id">
    <?php foreach (Admin_Dashboard::bank_account_select_options(
        $alegra_bank_accounts,
        (string) get_option('alegra_connector_payment_account_id','')
    ) as $opt): ?>
    <option value="<?php echo esc_attr($opt['value']); ?>" <?php selected($opt['selected']); ?>>
        <?php echo esc_html($opt['label']); ?>
    </option>
    <?php endforeach; ?>
</select>
<?php else: /* input de texto */ ?>
```

**Garantía formal:** si `stored ∉ {'','0'}`, la lista de opciones contiene exactamente una
entrada con `value === stored` y `selected === true` (real o sintética). Por lo tanto el
navegador **no puede** caer en `0`.

#### (b) Sanitizador que avisa

Extraer el closure `$alegra_id_sanitizer` (`Admin_Dashboard.php:294-304`) a un método
estático:

```php
public static function sanitize_alegra_id($value, string $option_name): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $is_uuid = (bool) preg_match(
        '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/',
        $value
    );
    if ($is_uuid || ctype_digit($value)) {
        return $value;                       // id numérico ('5') o UUID legado
    }

    add_settings_error(
        'alegra_connector_settings',
        $option_name . '_invalid',
        sprintf(
            __('El valor de %1$s ("%2$s") no es un ID válido; se conservó el valor anterior.', 'alegra-connector'),
            $option_name,
            $value
        ),
        'error'
    );

    return (string) get_option($option_name, '');
}
```

`register_settings()` (`:363-364`) usa
`fn($v) => self::sanitize_alegra_id($v, 'alegra_connector_payment_account_id')`. El mismo
método se usa para `warehouse_id` y `payment_term_id`, sin cambiar su semántica de
aceptación.

**Superficie del aviso.** En `templates/admin-settings.php:9-11` reemplazar el banner
incondicional por:

```php
<?php settings_errors('alegra_connector_settings'); ?>
<?php if (isset($_GET['settings-updated']) && $_GET['settings-updated']
          && empty(get_settings_errors('alegra_connector_settings'))): ?>
<div class="ac-notice success"><?php esc_html_e('Configuración guardada correctamente.','alegra-connector');?></div>
<?php endif; ?>
```

Orden importante: `settings_errors()` **consume** el transient de errores; debe llamarse
antes de `get_settings_errors()`.

#### (c) Default de activación

En el callback de activación (`alegra-connector.php`):

```php
if (get_option('alegra_connector_payment_account_id', null) === null) {
    add_option('alegra_connector_payment_account_id', '');
}
```

Y en `register_setting(...)` (`:363-364`) agregar `'default' => ''`.

#### (d) Label "banco o caja"

En `templates/admin-settings.php`, el `<th>`/label de
`alegra_connector_payment_account_id` pasa a **"Cuenta de destino para pagos (banco o
caja)"** (REQ-CFG-5). El texto "Cuenta bancaria" se elimina.

### 4.3 Migración / compatibilidad

- **Instalaciones con id válido (numérico o UUID):** no cambian; el select ahora lo
  muestra siempre (real o sintético).
- **Instalaciones ya clobbeadas a `'0'`:** el bug no se puede deshacer solo; el
  comerciante debe re-elegir su cuenta (que ahora aparece correctamente). El diseño **no**
  inventa un id desde `/bank-accounts` automáticamente (sería adivinar).
- **Otras opciones de ID:** ganan el aviso de rechazo, no cambian qué aceptan.

### 4.4 Idempotencia

Sin impacto: no hay POST a Alegra en esta sección.

---

## 5. Reconciliación del pago posterior

### 5.1 Comportamiento actual

- Todos los hooks de pedido viven dentro de
  `if (get_option('alegra_connector_push_orders_enabled', false))` (`Public_.php:49-59`),
  default `false`.
- `woocommerce_order_status_processing` **no está registrado**.
- `poll_invoice_statuses()` (`Orders.php:1751-1844`) es **Alegra→WC** (completa el pedido
  cuando Alegra dice pagado); nunca empuja un pago de WC a Alegra.
- No hay reintento ni barrido.
- `create_invoice_with_payment()` (`Orders.php:279-386`) **ya resuelve** el caso "factura
  existe, pago falta", pero solo se invoca desde `ajax_sync_pending_page` y los hooks
  gateados.

### 5.2 Hooks siempre-activos + reconciliación sin creación

En `Public_::__construct`, **fuera** del gate (`Public_.php:59`, después del bloque):

```php
// Reconciliación de pagos: SIEMPRE activa, independiente de push_orders_enabled.
// NO crea facturas; solo registra el pago sobre una factura ya vinculada.
add_action('woocommerce_payment_complete',        [$this, 'on_order_paid_reconcile'], 10, 1);
add_action('woocommerce_order_status_processing', [$this, 'on_order_paid_reconcile'], 10, 1);
add_action('woocommerce_order_status_completed',  [$this, 'on_order_paid_reconcile'], 10, 1);
```

**Por qué un método nuevo (`on_order_paid_reconcile`) y no reusar `on_payment_complete`:**
el test `T12.1` (`exec-test.php:1659`) assertea que `on_payment_complete` NO está
registrado cuando la opción está ausente. Usar un método distinto preserva ese contrato y
separa "crear factura" de "reconciliar pago".

Handler:

```php
public function on_order_paid_reconcile(int $order_id): void
{
    if (self::$is_syncing) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order instanceof \WC_Order) {
        return;
    }

    $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
    $payment_id = (string) $order->get_meta('_alegra_payment_id', true);

    // Guard exacto (REQ-REC-2): factura vinculada Y sin pago Y pagado.
    if ($invoice_id === '' || $payment_id !== '' || !$order->is_paid()) {
        return;
    }

    $account = (string) get_option('alegra_connector_payment_account_id', '');
    if (in_array($account, ['', '0'], true)) {
        $order->add_order_note(__(
            '[Alegra] El pedido está pagado y tiene factura, pero no hay cuenta de destino configurada; el pago NO se registró. Configurala en Ajustes > Avanzado.',
            'alegra-connector'
        ));
        if ($this->logger) {
            $this->logger->warning('Payment reconciliation skipped: no payment account configured', [
                'order_id'   => $order_id,
                'invoice_id' => $invoice_id,
            ]);
        }
        return;
    }

    $orders = new Sync\Orders($this->api, $this->logger);
    $orders->reconcile_payment_only($order);
}
```

**No-creación garantizada:** `reconcile_payment_only()` nunca llama a `create_invoice()`
ni a `create_invoice_with_payment()`. Si no hay factura vinculada, el guard retorna
antes. REQ-REC-5 queda satisfecho por construcción.

### 5.3 `Orders::reconcile_payment_only()` y `record_payment_for_invoice()`

El bloque de pago de `create_invoice_with_payment` (`Orders.php:306-383`) se extrae a un
método privado reutilizable:

```php
/**
 * Registra el pago de una factura YA existente. NO crea facturas.
 * Idempotente: meta + pre-búsqueda + lock.
 *
 * @return array|\WP_Error Resultado del pago o WP_Error.
 */
private function record_payment_for_invoice(\WC_Order $order, string $invoice_id): array|\WP_Error
{
    // (cuerpo extraído de create_invoice_with_payment:306-383)
    // 1. existing meta guard
    // 2. ensure_invoice_open si está draft (§3)
    // 3. find_existing_payment (idempotencia)
    // 4. prepare_payment_data (§2) + api->create_payment
    // 5. persist meta / nota / log
}

public function reconcile_payment_only(\WC_Order $order): array|\WP_Error
{
    $order_id   = (int) $order->get_id();
    $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
    $payment_id = (string) $order->get_meta('_alegra_payment_id', true);

    if ($invoice_id === '' || $payment_id !== '' || !$order->is_paid()) {
        return ['skipped' => true, 'reason' => 'guard'];
    }

    $account = (string) get_option('alegra_connector_payment_account_id', '');
    if (in_array($account, ['', '0'], true)) {
        return ['skipped' => true, 'reason' => 'no_account'];
    }

    // Lock por pedido: dos disparos concurrentes no duplican.
    $lock_key = 'alegra_payment_lock_' . $order_id;
    $token = Controller::acquire_lock($lock_key, 60);
    if ($token === false) {
        return ['skipped' => true, 'reason' => 'locked'];
    }

    try {
        return $this->record_payment_for_invoice($order, $invoice_id);
    } finally {
        Controller::release_lock($lock_key, $token);
    }
}
```

`create_invoice_with_payment` (`:279-386`) queda:

```php
$payment_account = (string) get_option('alegra_connector_payment_account_id', '');
$will_record_payment = !in_array($payment_account, ['', '0'], true)
    && (string) $order->get_meta('_alegra_payment_id', true) === ''
    && $order->is_paid();

$invoice_result = $this->create_invoice($order, $will_record_payment ? 'open' : null);
if (is_wp_error($invoice_result)) { return $invoice_result; }
if (!isset($invoice_result['id']) || (string) $invoice_result['id'] === '') { return $invoice_result; }

if ($will_record_payment) {
    $this->record_payment_for_invoice($order, (string) $invoice_result['id']);
} elseif (!in_array($payment_account, ['', '0'], true)
          && (string) $order->get_meta('_alegra_payment_id', true) === '') {
    // Hay cuenta configurada y no hay pago previo, pero el pedido no está pagado.
    $order->add_order_note(__(
        '[Alegra] El pedido no figura pagado en WooCommerce; se creó la factura pero no se registró pago.',
        'alegra-connector'
    ));
}

return $invoice_result;
```

**Beneficio:** una sola implementación del POST /payments y su idempotencia, usada por el
camino manual, el automático y el barrido.

### 5.4 Doble-disparo en modo automático

Con `push_orders_enabled = true`, los hooks gateados (`on_payment_complete` →
`trigger_sync('order', id, 'complete')`) y los siempre-activos (`on_order_paid_reconcile`)
pueden dispararse para el mismo pedido.

- `trigger_sync` es **síncrono** (`Public_.php:311-313`).
- Orden en `woocommerce_payment_complete`: primero el gateado (registrado en `:51`),
  luego el siempre-activo (registrado después). El gateado setea `_alegra_payment_id`; el
  siempre-activo ve el meta y retorna.
- Aun si se invirtiera el orden, el guard de meta + pre-búsqueda + lock hacen que solo
  uno postee.
- Se conserva el gate para la **creación** de facturas: en modo manual, el siempre-activo
  nunca crea.

### 5.5 Migración / compatibilidad

- Instalaciones en modo manual empiezan a reconciliar pagos automáticamente al pagarse un
  pedido que ya tenía factura. Es el comportamiento pedido.
- Instalaciones en modo automático: sin cambio neto; el siempre-activo es una red de
  seguridad idempotente.
- Pedidos viejos con factura y sin pago **no** se tocan por los hooks; los cubre el
  barrido (§6).

---

## 6. Barrido de reintento (sweep)

### 6.1 Cron

- **Hook:** `alegra_connector_payment_reconcile`.
- **Frecuencia:** `hourly`.
- **Registro:** en activación y con auto-reparación en `plugins_loaded` (mismo patrón que
  `Maintenance.php:41`):

```php
add_action('plugins_loaded', function (): void {
    if (!wp_next_scheduled('alegra_connector_payment_reconcile')) {
        wp_schedule_event(time() + 300, 'hourly', 'alegra_connector_payment_reconcile');
    }
});
add_action('alegra_connector_payment_reconcile', [$controller, 'run_payment_reconcile']);
```

- **Desprogramación:** en `deactivate` y en `uninstall.php` (`alegra-connector.php:460`).
- **Interruptor:** `alegra_connector_payment_reconcile_enabled`, default `true`.

### 6.2 Handler

```php
// Controller.php
public function run_payment_reconcile(): array
{
    if (Kill_Switch::is_active()) {
        $this->logger->info('Payment reconcile skipped: kill switch active');
        return ['reconciled' => 0, 'skipped' => 'kill_switch'];
    }

    $lock = self::acquire_lock('alegra_payment_reconcile', 300);
    if ($lock === false) {
        $this->logger->info('Payment reconcile skipped: another run is in progress');
        return ['reconciled' => 0, 'skipped' => 'locked'];
    }

    try {
        return $this->orders->reconcile_missing_payments();
    } finally {
        self::release_lock('alegra_payment_reconcile', $lock);
    }
}
```

### 6.3 Query y lógica

```php
// Orders.php
public function reconcile_missing_payments(): array
{
    if (!get_option('alegra_connector_payment_reconcile_enabled', true)) {
        return ['reconciled' => 0, 'skipped' => 'disabled'];
    }

    $limit = max(1, (int) get_option('alegra_connector_payment_reconcile_batch', 20));

    $order_ids = wc_get_orders([
        'limit'   => $limit,
        'status'  => ['processing', 'completed'],   // pagados (is_paid())
        'orderby' => 'date',
        'order'   => 'DESC',
        'return'  => 'ids',
        'meta_query' => [
            ['key' => '_alegra_invoice_id', 'value' => '', 'compare' => '!='],
            ['key' => '_alegra_payment_id', 'value' => '', 'compare' => '='],
        ],
    ]);

    $result = ['checked' => 0, 'reconciled' => 0, 'errors' => 0];
    foreach ((array) $order_ids as $order_id) {
        if (Kill_Switch::is_active()) {
            break; // parada en vuelo
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof \WC_Order) {
            continue;
        }
        $result['checked']++;
        $r = $this->reconcile_payment_only($order);
        if (is_wp_error($r)) {
            $result['errors']++;
        } elseif (!empty($r['skipped'])) {
            continue;
        } else {
            $result['reconciled']++;
        }
    }

    $this->logger->info('Payment reconcile completed', $result);
    return $result;
}
```

- **Batching:** `alegra_connector_payment_reconcile_batch`, default 20 (misma lógica que
  `orders_poll_batch`, `Orders.php:1775`).
- **Ventana:** no se limita por fecha; `limit` + `orderby date DESC` acota el trabajo por
  corrida y el barrido converge en pocas horas.
- **Kill-switch:** doble chequeo (inicio y por iteración), como `poll_invoice_statuses`
  (`Orders.php:1756,1800`).
- **Lock:** `alegra_payment_reconcile` (global) + `alegra_payment_lock_<id>` (por pedido).

### 6.4 Idempotencia del barrido

Un pedido ya reconciliado sale de la query en la siguiente corrida (su
`_alegra_payment_id` deja de ser `''`). Un fallo recuperable donde Alegra sí registró el
pago se resuelve con `find_existing_payment`.

### 6.5 Migración / compatibilidad

- Se agenda un cron nuevo; en sitios que ya tienen el plugin, `plugins_loaded` lo agenda
  en el próximo request. Al desactivar, se limpia.
- Tiendas sin cuenta configurada: el barrido encuentra pedidos pero
  `reconcile_payment_only` devuelve `no_account` (el hook ya dejó la nota). No genera
  tráfico de API de pago.

---

## 7. Placeholder del checkout (Hallazgo 7)

### 7.1 Clásico

`Billing_Fields::render_checkout_fields()` (`:499-501`) inyecta
`$entry['options'] = self::options_for($key, $field)`. Propuesto:

```php
if ($wc_type === 'select') {
    $entry['options'] = ['' => __('Seleccione…', 'alegra-connector')]
        + self::options_for($key, $field);
}
```

- El operador `+` preserva las claves existentes; `''` no colisiona con `RC`, `CC`, etc.
- Con el campo opcional, `''` significa "sin dato" y dispara el fallback de Consumidor
  Final. Con `require_data`, la validación de WooCommerce rechaza `''`.
- La ruta de registro/account (`:561-569`) **ya** antepone el placeholder; no se toca.

### 7.2 Blocks

`Checkout_Integration::block_options()` (`:188-202`) propuesto:

```php
$options = [];
if ($key === 'idtype') {
    $options[] = ['value' => '', 'label' => __('Seleccione…', 'alegra-connector')];
}
foreach ($map as $value => $label) {
    $options[] = ['value' => (string) $value, 'label' => (string) $label];
}
```

**BLOQUEADO por Fase 0.5** — si la API de Blocks rechaza `value=''`:
- **Rama B:** no anteponer la opción; registrar el campo sin preselección y validar `''`
  en el guardado (`Billing_Fields::on_checkout_post` / `on_register_post`). Documentar en
  el CHANGELOG que Blocks no muestra placeholder.

### 7.3 JS

`Checkout_Integration.php:259` localiza `selectPlaceholder` que nunca se usa. **Decisión:**
mantener la localización; no es requisito funcional. No se toca
`assets/js/alegra-checkout-conditions.js` salvo que la rama B de Blocks necesite inyectar
el placeholder por JS.

### 7.4 Migración / compatibilidad

- El valor guardado no cambia; solo el default visual del formulario.
- Usuarios que dejaban el default "RC" sin querer ahora deben elegir explícitamente (si el
  campo es requerido) o quedan en "sin dato". Es el comportamiento correcto.

---

## 8. Versión y release (Hallazgo 8)

### 8.1 Fuente de verdad de la versión

`alegra-connector.php:29` propuesto:

```php
// La versión se lee del encabezado del plugin: una sola fuente de verdad.
// get_file_data() está disponible en tiempo de carga del plugin.
$_alegra_plugin_data = get_file_data(__FILE__, ['Version' => 'Version']);
define('ALEGRA_CONNECTOR_VERSION', $_alegra_plugin_data['Version'] !== '' ? $_alegra_plugin_data['Version'] : '0.0.0');
unset($_alegra_plugin_data);
```

- Elimina la clase de bug "constante stale".
- `get_file_data()` existe en WP core. El stub del harness
  (`scripts/lib/wp-stubs.php`) **no** la define: agregarla al stub (o fallback regex). Ver
  tarea T7.1.
- El encabezado se bumpea a la versión del release (p. ej. `2.4.0`).

### 8.2 `scripts/build-release.sh`

Agregar preflight (después de validar semver, ~línea 34):

```bash
HEADER_VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([0-9A-Za-z.\-]*\).*/\1/p' alegra-connector.php | head -1)"
if [[ "$HEADER_VERSION" != "$VERSION" ]]; then
    echo "error: version mismatch — header='$HEADER_VERSION' argument='$VERSION'" >&2
    exit 9
fi
```

- Falla antes de escribir el ZIP.

### 8.3 `.github/workflows/release.yml` (nuevo)

```yaml
name: Release
on:
  push:
    tags: ['v*']
permissions:
  contents: write
jobs:
  release:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3' }
      - name: Gates
        run: |
          bash scripts/smoke-test.sh
          bash scripts/exec-test.sh
      - name: Build
        run: bash scripts/build-release.sh "${GITHUB_REF_NAME#v}"
      - uses: softprops/action-gh-release@v2
        with:
          files: |
            releases/*.zip
            releases/*.sha256
```

### 8.4 README

`README.md:29`:

```diff
-1. Download the latest release from the `releases` folder
+1. Download the latest release from https://github.com/djdang3r/wp-alegra-connector/releases/latest
```

### 8.5 Migración / compatibilidad

- **Upgraders atascados con JS viejo:** al corregir la constante, el cache-buster cambia y
  el navegador baja el JS nuevo. Requiere un release con versión nueva (p. ej. 2.4.0).
- **Backfill de tags 2.3.8–2.3.10:** si se identifica el commit, crear tags retroactivos;
  si no, **rama alternativa:** publicar solo desde el próximo release y documentar el
  historial en `CHANGELOG.md`.
- **No se borra** `releases/` (compatibilidad con el link viejo).

---

## 9. Idempotencia — garantías globales

Un pago **nunca** se postea dos veces. Barreras, en orden:

1. **Guard de meta:** `_alegra_payment_id !== ''` → salir (aplica en
   `record_payment_for_invoice`, `reconcile_payment_only`, hooks y barrido).
2. **Pre-búsqueda en Alegra:** `find_existing_payment($invoice_id, $client_id)`
   (`Orders.php:244-277`) recupera un pago ya commitido cuya respuesta se perdió; escribe
   el id y no postea.
3. **Lock por pedido:** `alegra_payment_lock_<order_id>` (`Controller::acquire_lock`, TTL
   60s) serializa disparos concurrentes del mismo pedido.
4. **Lock global del barrido:** `alegra_payment_reconcile` (TTL 300s) evita dos barridos
   solapados.
5. **Lock de factura:** `create_invoice` ya usa `alegra_invoice_lock_<id>`
   (`Orders.php:50-63`) para no crear facturas duplicadas.

Prueba de la garantía: REQ-REC-6 y `T-REC-6` (dos disparos → un solo `POST /payments`).

---

## 10. Orden de implementación (resumen)

1. **Fase 0** — verificaciones en vivo (bloquea REC-3, DRAFT-1, CHK-2).
2. **Fase 1 — "Facturar" registra el pago** (causa raíz confirmada, sin dependencias de
   Fase 0): `:2216` → `complete`; guard `is_paid()`; refactor
   `record_payment_for_invoice`; bulk y pendientes.
3. **Fase 2 — Fuente de datos y mapeo de pasarela:** fecha desde WC, resolvedor único,
   discrepancia de saldo, modo borrador (rama según Fase 0.8).
4. **Fase 3 — Hooks de reconciliación** fuera del gate.
5. **Fase 4 — Barrido** cron.
6. **Fase 5 — Endurecimiento de ajustes y cuenta de destino** (clobber latente, label,
   sanitizador, default).
7. **Fase 6 — Checkout.**
8. **Fase 7 — Versión y release.**
9. **Fase 8 — Regresión** — harness verde + prove-it-catches.

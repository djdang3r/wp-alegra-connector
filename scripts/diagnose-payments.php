<?php
/**
 * Alegra Connector — Diagnóstico de pagos (SOLO LECTURA).
 *
 * Responde "¿por qué este pedido no tiene pago en Alegra?" sin tocar nada:
 * no escribe opciones ni meta, y a la API solo le pega GET (GET /company,
 * GET /invoices/{id}).
 *
 * Formas de ejecutarlo:
 *
 *   1) WP-CLI (recomendado):
 *        wp eval-file wp-content/plugins/alegra-connector/scripts/diagnose-payments.php -- --order=123
 *        wp eval-file wp-content/plugins/alegra-connector/scripts/diagnose-payments.php
 *
 *   2) Comando WP-CLI (si el archivo se carga desde el plugin):
 *        wp alegra-diagnose --order=123
 *
 *   3) Navegador (sin WP-CLI): copiá este archivo a la raíz de WordPress y abrí
 *        https://TU-SITIO/diagnose-payments.php?order=123
 *      Requiere sesión iniciada con permiso manage_woocommerce (o manage_options).
 *
 * Sin --order usa el pedido pagado más reciente.
 */

if (!defined('ABSPATH')) {
    $alegra_diag_dir = __DIR__;
    for ($alegra_diag_i = 0; $alegra_diag_i < 8; $alegra_diag_i++) {
        if (is_readable($alegra_diag_dir . '/wp-load.php')) {
            require_once $alegra_diag_dir . '/wp-load.php';
            break;
        }
        $alegra_diag_parent = dirname($alegra_diag_dir);
        if ($alegra_diag_parent === $alegra_diag_dir) {
            break;
        }
        $alegra_diag_dir = $alegra_diag_parent;
    }

    if (!defined('ABSPATH')) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "No se encontró wp-load.php. Ejecutá este archivo dentro de WordPress.\n");
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "No se encontró wp-load.php. Copiá este archivo a la raíz de WordPress.\n";
        }
        exit(1);
    }

    if (PHP_SAPI !== 'cli') {
        $alegra_diag_allowed = function_exists('current_user_can')
            && (current_user_can('manage_woocommerce') || current_user_can('manage_options'));
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !$alegra_diag_allowed) {
            if (function_exists('status_header')) {
                status_header(403);
            }
            header('Content-Type: text/plain; charset=utf-8');
            echo "Acceso denegado: iniciá sesión con un usuario con permiso manage_woocommerce.\n";
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }
}

if (!class_exists('Alegra_Connector_Payment_Diagnostic')) {

    final class Alegra_Connector_Payment_Diagnostic
    {
        private const PLUGIN_FILE = 'alegra-connector/alegra-connector.php';

        public static function run(int $order_id = 0): void
        {
            self::line('==================================================');
            self::line(' Alegra Connector - Diagnostico de pagos (solo lectura)');
            self::line(' Fecha: ' . (function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s')));
            self::line('==================================================');
            self::line();

            self::report_plugin();
            self::report_config();
            $api = self::report_connection();

            if (!function_exists('wc_get_order')) {
                self::section('4) Pedido');
                self::line('WooCommerce no esta activo; no se puede inspeccionar el pedido.');
                return;
            }

            if ($order_id <= 0) {
                $order_id = self::most_recent_paid_order();
                self::line('(sin --order; usando el pedido pagado mas reciente)');
                self::line();
            }

            self::report_order($order_id, $api);
            self::report_sweep();
        }

        private static function report_plugin(): void
        {
            self::section('1) Plugin y version');

            $file = self::plugin_file();
            $header = self::header_version($file);
            $constant = defined('ALEGRA_CONNECTOR_VERSION') ? (string) ALEGRA_CONNECTOR_VERSION : '(no definida: plugin inactivo)';
            $stored = (string) get_option('alegra_connector_version', '(sin valor)');

            self::kv('Plugin activo', self::plugin_active() ? 'si' : 'NO');
            self::kv('Ruta del plugin', $file);
            self::kv('Version (header del archivo)', $header);
            self::kv('Constante ALEGRA_CONNECTOR_VERSION', $constant);
            self::kv('Opcion alegra_connector_version', $stored);

            if ($header !== '' && $constant !== '(no definida: plugin inactivo)' && $header !== $constant) {
                self::line('  AVISO: el header y la constante NO coinciden (posible constante vieja).');
            }
            self::line();
        }

        private static function report_config(): void
        {
            self::section('2) Configuracion de pagos');

            $account = (string) get_option('alegra_connector_payment_account_id', '');
            $configured = !in_array($account, ['', '0'], true);

            self::kv('Cuenta de destino (valor crudo)', $account === '' ? '(vacio)' : $account);
            self::kv('Cuenta considerada configurada', $configured ? 'si' : 'NO (vacio o "0" = sin cuenta)');
            self::kv('invoice_status', (string) get_option('alegra_connector_invoice_status', 'draft'));
            self::kv('push_orders_enabled', self::bool_opt('alegra_connector_push_orders_enabled', false));
            self::kv('dry_run (modo de prueba)', self::bool_opt('alegra_connector_dry_run', false));
            self::kv('customer_resolution_mode', (string) get_option('alegra_connector_customer_resolution_mode', 'auto'));
            self::kv('payment_reconcile_enabled', self::bool_opt('alegra_connector_payment_reconcile_enabled', true));
            self::kv('payment_reconcile_batch', (string) get_option('alegra_connector_payment_reconcile_batch', 20));
            self::kv('connection_tested (opcion)', self::bool_opt('alegra_connector_connection_tested', false));
            self::line();
        }

        private static function report_connection(): ?object
        {
            self::section('3) Conexion con Alegra');

            $api = self::api_client();
            if ($api === null) {
                self::line('No se pudo crear el cliente de API (plugin inactivo o clases ausentes).');
                self::line();
                return null;
            }

            $email = (string) get_option('alegra_connector_email', '');
            $token = (string) get_option('alegra_connector_token', '');
            if ($email === '' || $token === '') {
                self::line('Sin credenciales (email/token) configuradas; no se consulta la API.');
                self::line();
                return $api;
            }

            $company = $api->get_company();
            if (is_wp_error($company)) {
                self::kv('GET /company', 'ERROR: ' . $company->get_error_message());
            } else {
                self::kv('GET /company', sprintf(
                    'OK - %s / %s / %s',
                    (string) ($company['name'] ?? '?'),
                    (string) ($company['country'] ?? '?'),
                    (string) ($company['email'] ?? '?')
                ));
            }
            self::line();
            return $api;
        }

        private static function report_order(int $order_id, ?object $api): void
        {
            self::section('4) Pedido #' . $order_id);

            $order = wc_get_order($order_id);
            if (!$order) {
                self::line('No existe un pedido con ese id.');
                self::line();
                return;
            }

            $paid = $order->get_date_paid();
            $is_paid = $order->is_paid();
            $total = (float) $order->get_total();
            $method = (string) $order->get_payment_method();
            $method_title = (string) $order->get_payment_method_title();
            $invoice_id = (string) $order->get_meta('_alegra_invoice_id', true);
            $payment_id = (string) $order->get_meta('_alegra_payment_id', true);
            $contact_id = (string) $order->get_meta('_billing_alegra_contact_id', true);
            $invoice_status = (string) $order->get_meta('_alegra_invoice_status', true);

            self::kv('Estado WooCommerce', (string) $order->get_status());
            self::kv('is_paid()', $is_paid ? 'true' : 'false');
            self::kv('get_date_paid()', $paid instanceof \DateTimeInterface ? $paid->format('Y-m-d H:i:s') : '(sin fecha de pago)');
            self::kv('get_total()', number_format($total, 2, '.', ''));
            self::kv('get_payment_method()', $method === '' ? '(vacio)' : $method);
            self::kv('get_payment_method_title()', $method_title === '' ? '(vacio)' : $method_title);
            self::line();
            self::line('  Meta del pedido:');
            self::kv('_alegra_invoice_id', $invoice_id === '' ? '(vacio)' : $invoice_id);
            self::kv('_alegra_payment_id', $payment_id === '' ? '(vacio)' : $payment_id);
            self::kv('_billing_alegra_contact_id', $contact_id === '' ? '(vacio)' : $contact_id);
            self::kv('_alegra_invoice_status', $invoice_status === '' ? '(vacio)' : $invoice_status);
            self::line();

            $account = (string) get_option('alegra_connector_payment_account_id', '');
            $account_configured = !in_array($account, ['', '0'], true);

            self::line('  Medio de pago mapeado a Alegra:');
            self::kv('resolve_alegra_payment_method()', self::resolve_method($method, $api));
            self::line();

            self::line('  Payload que el plugin enviaria (NO se envia):');
            $payload = self::build_payload($order, $account, $account_configured, $invoice_id, $method, $method_title);
            if ($payload === null) {
                self::line('  (vacio: falta la cuenta de destino)');
            } else {
                foreach (explode("\n", (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) as $line) {
                    self::line('  ' . $line);
                }
            }
            self::line();

            $invoice = self::report_invoice($api, $invoice_id);
            self::report_verdict($order, $is_paid, $account_configured, $invoice_id, $payment_id, $invoice, $invoice_status);
        }

        private static function report_invoice(?object $api, string $invoice_id): ?array
        {
            self::line('  Estado de la factura en Alegra:');
            if ($invoice_id === '') {
                self::line('  (el pedido no tiene _alegra_invoice_id)');
                self::line();
                return null;
            }
            if ($api === null) {
                self::line('  (sin cliente de API; no se consulta)');
                self::line();
                return null;
            }

            $invoice = $api->get_invoice($invoice_id);
            if (is_wp_error($invoice)) {
                self::kv('GET /invoices/' . $invoice_id, 'ERROR: ' . $invoice->get_error_message());
                self::line();
                return null;
            }

            self::kv('status', (string) ($invoice['status'] ?? '?'));
            self::kv('total', self::num($invoice['total'] ?? null));
            self::kv('balance', self::num($invoice['balance'] ?? null));
            self::kv('totalPaid', self::num($invoice['totalPaid'] ?? null));
            self::line();
            return is_array($invoice) ? $invoice : null;
        }

        private static function report_verdict($order, bool $is_paid, bool $account_configured, string $invoice_id, string $payment_id, ?array $invoice, string $invoice_status): void
        {
            self::section('Veredicto');

            if ($invoice_id === '') {
                if (!$is_paid) {
                    self::line('SIN PAGO ESPERADO: el pedido no figura pagado en WooCommerce y no tiene factura.');
                } elseif (!$account_configured) {
                    self::line('SIN PAGO: el pedido esta pagado pero NO hay cuenta de destino; al Facturar se crearia la factura sin pago.');
                } else {
                    self::line('SIN PAGO: el pedido esta pagado y no tiene factura. "Facturar" crearia la factura y registraria el pago.');
                }
                self::line();
                return;
            }

            if ($payment_id !== '') {
                self::line('OK: el pedido ya tiene pago registrado (_alegra_payment_id = ' . $payment_id . ').');
                self::line();
                return;
            }

            if (!$is_paid) {
                self::line('SIN PAGO ESPERADO: el pedido no figura pagado en WooCommerce.');
                self::line();
                return;
            }

            if (!$account_configured) {
                self::line('SIN PAGO: falta la cuenta de destino (alegra_connector_payment_account_id vacio o "0").');
                self::line('El plugin no puede registrar el pago; deja nota + warning. Configura la cuenta y reintenta.');
                self::line();
                return;
            }

            $status = strtolower((string) ($invoice['status'] ?? $invoice_status));
            if ($status === 'draft') {
                self::line('SIN PAGO: la factura esta en borrador. El plugin la abre antes de pagar; el barrido horario deberia adjuntarlo.');
                self::line();
                return;
            }

            $balance = isset($invoice['balance']) ? (float) $invoice['balance'] : null;
            if ($balance !== null && $balance <= 0.01) {
                self::line('La factura ya figura pagada en Alegra (balance ' . self::num($balance) . ').');
                self::line();
                return;
            }

            if ($invoice === null && $status === '') {
                self::line('SIN PAGO: no se pudo leer la factura en Alegra (ver el error de GET /invoices).');
                self::line('Revisa las credenciales y que la factura exista antes de sacar conclusiones.');
                self::line();
                return;
            }

            self::line('SIN PAGO: pedido pagado + cuenta configurada + factura abierta con saldo.');
            self::line('El barrido horario (alegra_connector_payment_reconcile) deberia adjuntarlo. Revisa el cron y los logs.');
            self::line();
        }

        private static function report_sweep(): void
        {
            self::section('5) Pedidos con factura y sin pago (consulta del barrido, hasta 20)');

            $ids = wc_get_orders([
                'limit'   => 20,
                'status'  => ['processing', 'completed'],
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key'     => '_alegra_invoice_id',
                        'value'   => '',
                        'compare' => '!=',
                    ],
                    [
                        'relation' => 'OR',
                        [
                            'key'     => '_alegra_payment_id',
                            'value'   => '',
                            'compare' => '=',
                        ],
                        [
                            'key'     => '_alegra_payment_id',
                            'compare' => 'NOT EXISTS',
                        ],
                    ],
                ],
            ]);

            if (empty($ids)) {
                self::line('(ninguno)');
                self::line();
                return;
            }

            foreach ((array) $ids as $id) {
                $order = wc_get_order($id);
                if (!$order) {
                    continue;
                }
                self::line(sprintf(
                    '  #%d  status=%s  pagado=%s  total=%s  factura=%s',
                    (int) $id,
                    (string) $order->get_status(),
                    $order->is_paid() ? 'si' : 'no',
                    number_format((float) $order->get_total(), 2, '.', ''),
                    (string) $order->get_meta('_alegra_invoice_id', true)
                ));
            }
            self::line();
        }

        private static function build_payload($order, string $account, bool $account_configured, string $invoice_id, string $method, string $method_title): ?array
        {
            if (!$account_configured || $invoice_id === '') {
                return null;
            }

            $paid = $order->get_date_paid();
            $date = $paid instanceof \DateTimeInterface ? $paid->format('Y-m-d') : date('Y-m-d');

            $payload = [
                'date'        => $date,
                'bankAccount' => ['id' => $account],
                'invoices'    => [[
                    'id'     => $invoice_id,
                    'amount' => (float) $order->get_total(),
                ]],
                'paymentMethod' => self::resolve_method($method, null),
            ];

            $title = trim($method_title);
            if ($title !== '') {
                $payload['observations'] = 'Pedido #' . (int) $order->get_id() . ' - ' . $title;
            }

            return $payload;
        }

        private static function resolve_method(string $method, ?object $api): string
        {
            if (!class_exists('\Alegra\Connector\Sync\Orders')) {
                return '(no disponible: plugin inactivo)';
            }
            try {
                $orders = new \Alegra\Connector\Sync\Orders($api, null);
                return $orders->resolve_alegra_payment_method($method);
            } catch (\Throwable $e) {
                return '(error: ' . $e->getMessage() . ')';
            }
        }

        private static function most_recent_paid_order(): int
        {
            $ids = wc_get_orders([
                'limit'   => 10,
                'status'  => ['processing', 'completed'],
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'ids',
            ]);
            foreach ((array) $ids as $id) {
                $order = wc_get_order($id);
                if ($order && $order->is_paid()) {
                    return (int) $id;
                }
            }
            return (int) (($ids[0] ?? 0));
        }

        private static function api_client(): ?object
        {
            if (function_exists('alegra_connector')) {
                try {
                    $instance = alegra_connector();
                    if (is_object($instance) && method_exists($instance, 'get_api')) {
                        return $instance->get_api();
                    }
                } catch (\Throwable $e) {
                    // fall through to the direct construction below
                }
            }
            if (class_exists('\Alegra\Connector\API\Client')) {
                return new \Alegra\Connector\API\Client();
            }
            return null;
        }

        private static function plugin_file(): string
        {
            if (defined('ALEGRA_CONNECTOR_FILE')) {
                return (string) ALEGRA_CONNECTOR_FILE;
            }
            $base = defined('WP_PLUGIN_DIR') ? (string) WP_PLUGIN_DIR : rtrim((string) ABSPATH, '/') . '/wp-content/plugins';
            return rtrim($base, '/') . '/' . self::PLUGIN_FILE;
        }

        private static function header_version(string $file): string
        {
            if (function_exists('get_file_data') && is_readable($file)) {
                $data = get_file_data($file, ['Version' => 'Version']);
                if (is_array($data) && !empty($data['Version'])) {
                    return (string) $data['Version'];
                }
            }
            if (is_readable($file)) {
                $contents = (string) file_get_contents($file);
                if (preg_match('/^[ \t\/*#@]*Version:\s*(.+?)\s*$/mi', $contents, $m)) {
                    return trim((string) $m[1]);
                }
            }
            return '?';
        }

        private static function plugin_active(): bool
        {
            if (!function_exists('is_plugin_active')) {
                $file = rtrim((string) ABSPATH, '/') . '/wp-admin/includes/plugin.php';
                if (is_readable($file)) {
                    require_once $file;
                }
            }
            return function_exists('is_plugin_active') && is_plugin_active(self::PLUGIN_FILE);
        }

        private static function bool_opt(string $key, bool $default): string
        {
            return get_option($key, $default) ? 'true' : 'false';
        }

        private static function num($value): string
        {
            return $value === null ? '?' : number_format((float) $value, 2, '.', '');
        }

        private static function section(string $title): void
        {
            self::line('--- ' . $title . ' ---');
        }

        private static function kv(string $key, string $value): void
        {
            self::line(sprintf('  %-42s %s', $key . ':', $value));
        }

        private static function line(string $text = ''): void
        {
            echo $text . "\n";
        }
    }
}

if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
    \WP_CLI::add_command('alegra-diagnose', static function ($args, $assoc_args): void {
        $order = isset($assoc_args['order']) ? (int) $assoc_args['order'] : 0;
        Alegra_Connector_Payment_Diagnostic::run($order);
    });
}

$alegra_diag_is_command = false;
if (defined('WP_CLI') && WP_CLI && !empty($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
    foreach ($GLOBALS['argv'] as $alegra_diag_arg) {
        if ($alegra_diag_arg === 'alegra-diagnose') {
            $alegra_diag_is_command = true;
            break;
        }
    }
}

if (!$alegra_diag_is_command) {
    $alegra_diag_order = 0;
    $alegra_diag_tokens = [];
    if (isset($args) && is_array($args)) {
        $alegra_diag_tokens = array_merge($alegra_diag_tokens, $args);
    }
    if (!empty($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
        $alegra_diag_tokens = array_merge($alegra_diag_tokens, $GLOBALS['argv']);
    }
    if (isset($_GET['order'])) {
        $alegra_diag_tokens[] = 'order=' . $_GET['order'];
    }

    for ($alegra_diag_j = 0; $alegra_diag_j < count($alegra_diag_tokens); $alegra_diag_j++) {
        $alegra_diag_tok = (string) $alegra_diag_tokens[$alegra_diag_j];
        if (preg_match('/^--?order=(.+)$/', $alegra_diag_tok, $alegra_diag_m)) {
            $alegra_diag_order = (int) $alegra_diag_m[1];
            break;
        }
        if ($alegra_diag_tok === '--order' || $alegra_diag_tok === '-o') {
            $alegra_diag_order = (int) ($alegra_diag_tokens[$alegra_diag_j + 1] ?? 0);
            break;
        }
    }

    Alegra_Connector_Payment_Diagnostic::run($alegra_diag_order);
}

<?php
/**
 * Alegra Connector — Inspector de webhooks (SOLO LECTURA).
 *
 * Muestra las ultimas entregas de webhooks que recibio el plugin, con su
 * payload crudo, y responde la pregunta que bloquea el diseno de inventario:
 * ¿Alegra manda `edit-item` (con inventario) cuando cambia el stock?
 *
 * No escribe en Alegra ni cambia estado: solo lee la opcion local donde el
 * receptor guarda un buffer acotado con las ultimas 50 entregas.
 *
 * Formas de ejecutarlo:
 *
 *   1) WP-CLI (recomendado):
 *        wp eval-file wp-content/plugins/alegra-connector/scripts/inspect-webhooks.php -- --limit=20
 *        wp eval-file wp-content/plugins/alegra-connector/scripts/inspect-webhooks.php -- --event=edit-item
 *        wp eval-file wp-content/plugins/alegra-connector/scripts/inspect-webhooks.php -- --item=865
 *
 *   2) Navegador (sin WP-CLI): copia este archivo a la raiz de WordPress y abre
 *        https://TU-SITIO/inspect-webhooks.php?event=edit-item
 *      Requiere sesion con permiso manage_woocommerce (o manage_options).
 *
 * Argumentos:
 *   --limit=<n>      cuantas entregas mostrar (por defecto 20)
 *   --event=<slug>   filtrar por subject (ej. edit-item)
 *   --item=<id>      filtrar por el id del item del payload
 */

if (!defined('ABSPATH')) {
    $alegra_insp_dir = __DIR__;
    for ($alegra_insp_i = 0; $alegra_insp_i < 8; $alegra_insp_i++) {
        if (is_readable($alegra_insp_dir . '/wp-load.php')) {
            require_once $alegra_insp_dir . '/wp-load.php';
            break;
        }
        $alegra_insp_parent = dirname($alegra_insp_dir);
        if ($alegra_insp_parent === $alegra_insp_dir) {
            break;
        }
        $alegra_insp_dir = $alegra_insp_parent;
    }

    if (!defined('ABSPATH')) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "No se encontro wp-load.php. Ejecuta este archivo dentro de WordPress.\n");
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "No se encontro wp-load.php. Copia este archivo a la raiz de WordPress.\n";
        }
        exit(1);
    }

    if (PHP_SAPI !== 'cli') {
        $alegra_insp_allowed = function_exists('current_user_can')
            && (current_user_can('manage_woocommerce') || current_user_can('manage_options'));
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !$alegra_insp_allowed) {
            if (function_exists('status_header')) {
                status_header(403);
            }
            header('Content-Type: text/plain; charset=utf-8');
            echo "Acceso denegado: inicia sesion con un usuario con permiso manage_woocommerce.\n";
            exit;
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
    }
}

if (!class_exists('Alegra_Connector_Webhook_Inspector')) {

    final class Alegra_Connector_Webhook_Inspector
    {
        /** Fully-qualified recorder class (loaded by the plugin autoloader). */
        private const RECORDER = '\Alegra\Connector\Webhooks\Recorder';

        public static function run(int $limit = 20, string $event = '', string $item = ''): void
        {
            self::line('==================================================');
            self::line(' Alegra Connector - Inspector de webhooks (solo lectura)');
            self::line(' Fecha: ' . (function_exists('current_time') ? current_time('mysql') : date('Y-m-d H:i:s')));
            self::line('==================================================');
            self::line();

            if (!class_exists(self::RECORDER)) {
                self::line('El plugin Alegra Connector no esta activo (su autoloader no cargo).');
                self::line('Activa el plugin y vuelve a correr el inspector.');
                return;
            }

            $recorder = self::RECORDER;
            $all = $recorder::all();

            self::kv('Entregas guardadas', (string) count($all) . ' (tope ' . (string) $recorder::MAX_ENTRIES . ')');
            self::kv('Filtro event', $event === '' ? '(todos)' : $event);
            self::kv('Filtro item', $item === '' ? '(todos)' : $item);
            self::kv('Limite de salida', (string) $limit);
            self::line();

            $rows = self::filter($all, $event, $item);
            if (count($rows) > $limit) {
                $rows = array_slice($rows, -$limit);
            }
            $rows = array_reverse($rows); // newest first for display

            if ($rows === []) {
                self::line('No hay entregas que coincidan con los filtros.');
                self::line('Hace la prueba en vivo y vuelve a correr el inspector.');
                self::line();
                self::print_reminder();
                return;
            }

            self::print_summary($rows, $recorder);
            self::print_payloads($rows);
            self::print_check($rows, $recorder);
        }

        /**
         * Keep entries matching the event and item filters, oldest first.
         *
         * @param array<int,array<string,mixed>> $all
         * @return array<int,array<string,mixed>>
         */
        private static function filter(array $all, string $event, string $item): array
        {
            $recorder = self::RECORDER;
            $out = [];
            foreach ($all as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if ($event !== '' && (string) ($entry['subject'] ?? '') !== $event) {
                    continue;
                }
                if ($item !== '' && $recorder::item_id((string) ($entry['body'] ?? '')) !== $item) {
                    continue;
                }
                $out[] = $entry;
            }
            return $out;
        }

        /**
         * @param array<int,array<string,mixed>> $rows
         */
        private static function print_summary(array $rows, string $recorder): void
        {
            self::section('1) Resumen de entregas (' . count($rows) . ')');
            self::line(sprintf('  %-3s %-19s %-14s %s', '#', 'Fecha', 'Subject', 'Entidad'));
            foreach ($rows as $i => $row) {
                $body = (string) ($row['body'] ?? '');
                $entity = $recorder::entity_summary($body);
                self::line(sprintf(
                    '  %-3d %-19s %-14s %s',
                    $i + 1,
                    (string) ($row['time'] ?? '?'),
                    (string) ($row['subject'] ?? '?'),
                    $entity === '' ? '(sin entidad reconocida)' : $entity
                ));
            }
            self::line();
        }

        /**
         * @param array<int,array<string,mixed>> $rows
         */
        private static function print_payloads(array $rows): void
        {
            self::section('2) Payloads crudos (JSON)');
            foreach ($rows as $i => $row) {
                $body = (string) ($row['body'] ?? '');
                $truncated = (bool) ($row['truncated'] ?? false);
                self::line(sprintf(
                    '  [%d] %s  subject=%s  ip=%s%s',
                    $i + 1,
                    (string) ($row['time'] ?? '?'),
                    (string) ($row['subject'] ?? '?'),
                    ((string) ($row['ip'] ?? '')) === '' ? '(desconocida)' : (string) $row['ip'],
                    $truncated ? '  [TRUNCADO de ' . (string) ($row['bytes'] ?? '?') . ' bytes]' : ''
                ));

                $decoded = json_decode($body, true);
                if (is_array($decoded)) {
                    $json = (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } else {
                    $json = $body;
                }
                foreach (explode("\n", $json) as $line) {
                    self::line('    ' . $line);
                }
                self::line();
            }
        }

        /**
         * @param array<int,array<string,mixed>> $rows
         */
        private static function print_check(array $rows, string $recorder): void
        {
            self::section('3) Chequeo critico: ¿edit-item trae inventario?');

            $edit_items = [];
            foreach ($rows as $row) {
                if (is_array($row) && (string) ($row['subject'] ?? '') === 'edit-item') {
                    $edit_items[] = $row;
                }
            }

            if ($edit_items === []) {
                self::line('  ATENCION: no hay NINGUNA entrega de edit-item en la ventana guardada.');
                self::line('  Sin una entrega de edit-item no se puede responder la pregunta.');
                self::line('  Hace la prueba en vivo y vuelve a correr el inspector.');
                self::line();
                self::print_reminder();
                return;
            }

            foreach ($edit_items as $i => $row) {
                $body = (string) ($row['body'] ?? '');
                $has = $recorder::has_inventory_available_quantity($body);
                $qty = $recorder::available_quantity($body);
                $entity = $recorder::entity_summary($body);
                self::line(sprintf(
                    '  [%d] %s  inventory.availableQuantity = %s%s',
                    $i + 1,
                    $entity === '' ? 'edit-item' : $entity,
                    $has ? 'SI' : 'NO',
                    $has ? ' (valor: ' . self::scalar($qty) . ')' : ''
                ));
            }
            self::line();

            self::line('  VEREDICTO: ' . $recorder::inventory_verdict($rows));
            self::line();
        }

        private static function print_reminder(): void
        {
            self::section('Procedimiento de prueba');
            self::line('  1) En Configuracion, suscribi el evento edit-item (selector de eventos).');
            self::line('  2) Anota el stock de un producto en Alegra.');
            self::line('  3) Anula una factura que haya descontado ese producto (o hace un ajuste de inventario).');
            self::line('  4) Volve a correr este inspector y busca una entrega edit-item.');
            self::line('  5) Lee el veredicto de la seccion 3.');
            self::line();
        }

        private static function scalar(mixed $value): string
        {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if ($value === null) {
                return 'null';
            }
            return (string) $value;
        }

        private static function section(string $title): void
        {
            self::line('--- ' . $title . ' ---');
        }

        private static function kv(string $key, string $value): void
        {
            self::line(sprintf('  %-24s %s', $key . ':', $value));
        }

        private static function line(string $text = ''): void
        {
            echo $text . "\n";
        }
    }
}

if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
    \WP_CLI::add_command('alegra-inspect-webhooks', static function ($args, $assoc_args): void {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 20;
        $event = isset($assoc_args['event']) ? (string) $assoc_args['event'] : '';
        $item  = isset($assoc_args['item']) ? (string) $assoc_args['item'] : '';
        Alegra_Connector_Webhook_Inspector::run($limit, $event, $item);
    });
}

$alegra_insp_is_command = false;
if (defined('WP_CLI') && WP_CLI && !empty($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
    foreach ($GLOBALS['argv'] as $alegra_insp_arg) {
        if ($alegra_insp_arg === 'alegra-inspect-webhooks') {
            $alegra_insp_is_command = true;
            break;
        }
    }
}

if (!$alegra_insp_is_command) {
    $alegra_insp_tokens = [];
    if (isset($args) && is_array($args)) {
        $alegra_insp_tokens = array_merge($alegra_insp_tokens, $args);
    }
    if (!empty($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
        $alegra_insp_tokens = array_merge($alegra_insp_tokens, $GLOBALS['argv']);
    }
    if (isset($_GET['limit'])) {
        $alegra_insp_tokens[] = 'limit=' . $_GET['limit'];
    }
    if (isset($_GET['event'])) {
        $alegra_insp_tokens[] = 'event=' . $_GET['event'];
    }
    if (isset($_GET['item'])) {
        $alegra_insp_tokens[] = 'item=' . $_GET['item'];
    }

    $alegra_insp_limit = 20;
    $alegra_insp_event = '';
    $alegra_insp_item = '';

    for ($alegra_insp_j = 0; $alegra_insp_j < count($alegra_insp_tokens); $alegra_insp_j++) {
        $alegra_insp_tok = (string) $alegra_insp_tokens[$alegra_insp_j];
        if (preg_match('/^--?limit=(.+)$/', $alegra_insp_tok, $alegra_insp_m)) {
            $alegra_insp_limit = (int) $alegra_insp_m[1];
            continue;
        }
        if (preg_match('/^--?event=(.+)$/', $alegra_insp_tok, $alegra_insp_m)) {
            $alegra_insp_event = (string) $alegra_insp_m[1];
            continue;
        }
        if (preg_match('/^--?item=(.+)$/', $alegra_insp_tok, $alegra_insp_m)) {
            $alegra_insp_item = (string) $alegra_insp_m[1];
            continue;
        }
        if ($alegra_insp_tok === '--limit') {
            $alegra_insp_limit = (int) ($alegra_insp_tokens[$alegra_insp_j + 1] ?? 20);
            $alegra_insp_j++;
            continue;
        }
        if ($alegra_insp_tok === '--event') {
            $alegra_insp_event = (string) ($alegra_insp_tokens[$alegra_insp_j + 1] ?? '');
            $alegra_insp_j++;
            continue;
        }
        if ($alegra_insp_tok === '--item') {
            $alegra_insp_item = (string) ($alegra_insp_tokens[$alegra_insp_j + 1] ?? '');
            $alegra_insp_j++;
            continue;
        }
    }

    if ($alegra_insp_limit <= 0) {
        $alegra_insp_limit = 20;
    }

    Alegra_Connector_Webhook_Inspector::run($alegra_insp_limit, $alegra_insp_event, $alegra_insp_item);
}

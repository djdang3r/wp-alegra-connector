<?php
/**
 * Alegra Connector — Auditoría de integridad de inventario (SOLO LECTURA).
 *
 * Imprime el diagnóstico de integridad de stock: los gates del sync, la
 * comparación `WC stock` vs `availableQuantity` de Alegra por producto
 * vinculado, y el ledger de divergencias registradas. NO escribe NADA: no
 * emite ajustes, no toca stock, no toma locks.
 *
 * Formas de ejecutarlo:
 *
 *   1) WP-CLI (recomendado) — desde la raiz de WordPress:
 *        wp eval-file scripts/audit-inventory-integrity.php
 *        wp eval-file scripts/audit-inventory-integrity.php -- --limit=50
 *        wp eval-file wp-content/plugins/alegra-connector/scripts/audit-inventory-integrity.php
 *        wp alegra-audit-inventory
 *
 *   2) Navegador (sin WP-CLI): copia este archivo a la raiz de WordPress y abre
 *        https://TU-SITIO/audit-inventory-integrity.php
 *      Requiere sesion con permiso manage_woocommerce (o manage_options).
 *
 * Argumentos:
 *   --limit=<n>      cuantos items de Alegra comparar (por defecto 200)
 *
 * Nota de despliegue: `scripts/` esta excluido del ZIP de release
 * (`.distignore`), por lo que esta herramienta es ops-only y no viaja al sitio
 * del comerciante.
 */

if (!defined('ABSPATH')) {
    $alegra_audit_dir = __DIR__;
    for ($alegra_audit_i = 0; $alegra_audit_i < 8; $alegra_audit_i++) {
        if (is_readable($alegra_audit_dir . '/wp-load.php')) {
            require_once $alegra_audit_dir . '/wp-load.php';
            break;
        }
        $alegra_audit_parent = dirname($alegra_audit_dir);
        if ($alegra_audit_parent === $alegra_audit_dir) {
            break;
        }
        $alegra_audit_dir = $alegra_audit_parent;
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
        $alegra_audit_allowed = function_exists('current_user_can')
            && (current_user_can('manage_woocommerce') || current_user_can('manage_options'));
        if (!function_exists('is_user_logged_in') || !is_user_logged_in() || !$alegra_audit_allowed) {
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

if (!class_exists('Alegra_Connector_Inventory_Audit')) {

    final class Alegra_Connector_Inventory_Audit
    {
        /** Fully-qualified report class (loaded by the plugin autoloader). */
        private const REPORT = '\Alegra\Connector\Sync\Inventory_Integrity_Report';

        public static function run(int $limit = 200): void
        {
            if (!class_exists(self::REPORT)) {
                echo "El plugin Alegra Connector no esta activo (su autoloader no cargo).\n";
                echo "Activa el plugin y vuelve a correr la auditoría.\n";
                return;
            }

            $logger = class_exists('\Alegra\Connector\Logger\Logger')
                ? new \Alegra\Connector\Logger\Logger()
                : null;
            $api = class_exists('\Alegra\Connector\API\Client')
                ? new \Alegra\Connector\API\Client($logger)
                : null;

            /** @var \Alegra\Connector\Sync\Inventory_Integrity_Report $report */
            $class = self::REPORT;
            $report = new $class($api, $logger);
            echo $report->render_text($limit);
        }
    }
}

if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
    \WP_CLI::add_command('alegra-audit-inventory', static function ($args, $assoc_args): void {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 200;
        Alegra_Connector_Inventory_Audit::run($limit);
    });
}

// Direct / browser fallback (skip when WP-CLI already dispatched the command).
$alegra_audit_is_command = false;
if (defined('WP_CLI') && WP_CLI && !empty($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
    foreach ($GLOBALS['argv'] as $alegra_audit_arg) {
        if ($alegra_audit_arg === 'alegra-audit-inventory') {
            $alegra_audit_is_command = true;
            break;
        }
    }
}

if (!$alegra_audit_is_command) {
    $alegra_audit_tokens = [];
    if (isset($args) && is_array($args)) {
        $alegra_audit_tokens = array_merge($alegra_audit_tokens, $args);
    }
    if (!empty($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
        $alegra_audit_tokens = array_merge($alegra_audit_tokens, $GLOBALS['argv']);
    }
    if (isset($_GET['limit']) && is_scalar($_GET['limit'])) {
        $alegra_audit_tokens[] = 'limit=' . (string) $_GET['limit'];
    }

    $alegra_audit_limit = 200;
    for ($alegra_audit_j = 0; $alegra_audit_j < count($alegra_audit_tokens); $alegra_audit_j++) {
        $alegra_audit_tok = (string) $alegra_audit_tokens[$alegra_audit_j];
        if (preg_match('/^--?limit=(.+)$/', $alegra_audit_tok, $alegra_audit_m)) {
            $alegra_audit_limit = (int) $alegra_audit_m[1];
            continue;
        }
        if ($alegra_audit_tok === '--limit') {
            $alegra_audit_limit = (int) ($alegra_audit_tokens[$alegra_audit_j + 1] ?? 200);
            $alegra_audit_j++;
            continue;
        }
    }

    if ($alegra_audit_limit <= 0) {
        $alegra_audit_limit = 200;
    }

    Alegra_Connector_Inventory_Audit::run($alegra_audit_limit);
}

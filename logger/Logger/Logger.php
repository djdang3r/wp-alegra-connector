<?php
/**
 * Logger Class
 *
 * @package Alegra\Connector\Logger
 */

declare(strict_types=1);

namespace Alegra\Connector\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class Logger
{
    private string $log_dir = '';
    private string $log_file = '';
    private int $retention_days = 30;
    private bool $dir_ready = false;

    /**
     * Logs live under wp-content/uploads/alegra-logs/ and contain customer PII.
     *
     * The directory ships an Apache `.htaccess` deny and an `index.php` stub, but
     * **Nginx ignores `.htaccess`**. On Nginx (or any server that does not honour
     * `.htaccess`) add an explicit location deny, for example:
     *
     *     location ^~ /wp-content/uploads/alegra-logs/ { deny all; }
     *
     * As defence in depth the log filename also carries a random per-install
     * suffix so the path is not guessable.
     *
     * AC-62: the constructor used to call wp_upload_dir() + is_dir() +
     * wp_mkdir_p() + file_exists() on EVERY request, frontend included. That
     * work is now deferred to ensure_dir(), which runs on the first actual
     * write/read. A normal page view pays nothing.
     */
    public function __construct()
    {
        $this->retention_days = (int) get_option('alegra_connector_log_retention_days', 30);
    }

    /**
     * Resolve the uploads directory and (re)assert the deny files on first use.
     */
    private function ensure_dir(): void
    {
        if ($this->dir_ready) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $this->log_dir = $upload_dir['basedir'] . '/alegra-logs';

        if (!is_dir($this->log_dir)) {
            wp_mkdir_p($this->log_dir);
        }

        // Re-assert the deny files on EVERY boot, not only when the directory is
        // first created: a pre-existing directory — or one where the files were
        // deleted — would otherwise be publicly accessible.
        $this->protect_log_dir();

        $this->log_file = $this->log_dir . '/alegra-sync-' . $this->get_log_suffix() . '-' . date('Y-m-d') . '.log';
        $this->dir_ready = true;
    }

    /**
     * (Re)write the .htaccess deny and the index.php stub when missing.
     */
    private function protect_log_dir(): void
    {
        if (!is_dir($this->log_dir)) {
            return;
        }

        // Apache 2.2 + 2.4 compatible deny.
        $htaccess = $this->log_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n");
        }

        // Directory listing / direct execution guard.
        $index = $this->log_dir . '/index.php';
        if (!file_exists($index)) {
            @file_put_contents($index, "<?php // Silence is golden.\n");
        }
    }

    /**
     * Stable random per-install suffix used to make the log path non-guessable.
     */
    private function get_log_suffix(): string
    {
        $suffix = (string) get_option('alegra_connector_log_suffix', '');
        if (preg_match('/^[a-f0-9]{12}$/', $suffix)) {
            return $suffix;
        }

        try {
            $suffix = substr(bin2hex(random_bytes(6)), 0, 12);
        } catch (\Throwable $e) {
            $suffix = substr(md5((string) (defined('AUTH_SALT') ? AUTH_SALT : __FILE__) . microtime()), 0, 12);
        }

        update_option('alegra_connector_log_suffix', $suffix, false);
        return $suffix;
    }

    private function write(string $level, string $message, array $context = []): void
    {
        $this->ensure_dir();

        $timestamp = current_time('Y-m-d H:i:s');
        $context_str = !empty($context) ? ' ' . wp_json_encode($context) : '';
        $log_entry = sprintf(
            "[%s] [%s] %s%s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $context_str
        );

        try {
            // Ensure log dir is writable before opening (avoid hanging flock)
            $log_dir = dirname($this->log_file);
            if (!is_dir($log_dir)) {
                throw new \RuntimeException('Log directory missing: ' . $log_dir);
            }
            if (!is_writable($log_dir)) {
                throw new \RuntimeException('Log directory not writable: ' . $log_dir);
            }
            if (file_exists($this->log_file) && !is_writable($this->log_file)) {
                throw new \RuntimeException('Log file not writable: ' . $this->log_file);
            }

            $handle = fopen($this->log_file, 'a');
            if ($handle === false) {
                throw new \RuntimeException('Cannot open log file: ' . $this->log_file);
            }

            // Use LOCK_EX (blocking) so log entries are never silently dropped.
            // Contention is rare (log writes are infrequent) and the worst-case wait
            // is a few ms — invisible next to the API call that triggered the log.
            $locked = flock($handle, LOCK_EX);
            if ($locked) {
                fwrite($handle, $log_entry);
                fflush($handle);
                flock($handle, LOCK_UN);
            }
            fclose($handle);

            if (!$locked) {
                // Fallback if flock() returned false (rare — only on filesystem
                // errors). Gated by WP_DEBUG so it cannot flood the server log.
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Alegra Logger] flock failed: ' . $log_entry);
                }
            }
        } catch (\Throwable $e) {
            // Fallback: never let a logging failure break the import flow.
            // Gated by WP_DEBUG so an unwritable uploads dir cannot flood logs.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Alegra Logger] ' . $e->getMessage() . ' | ' . trim($log_entry));
            }
        }
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write('CRITICAL', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $this->write('DEBUG', $message, $context);
        }
    }

    public function clear_old_logs(?int $retention_days = null): int
    {
        $this->ensure_dir();
        // AC-71: fall back to the retention configured at construction instead
        // of a hardcoded 30, so the property is actually used.
        $retention_days ??= $this->retention_days;
        $count = 0;
        $cutoff = strtotime('- ' . $retention_days . ' days');
        $files = glob($this->log_dir . '/*.log');

        foreach ($files as $file) {
            if (is_file($file)) {
                $modified = filemtime($file);
                if ($modified < $cutoff) {
                    if (unlink($file)) {
                        $count++;
                    }
                }
            }
        }

        $this->info('Cleared old logs', ['count' => $count, 'retention_days' => $retention_days]);

        return $count;
    }

    public function get_logs(int $limit = 100, string $level = '', string $type = ''): array
    {
        $this->ensure_dir();
        $logs = [];
        $files = glob($this->log_dir . '/*.log');

        if (empty($files)) {
            return $logs;
        }

        usort($files, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $count = 0;
        foreach ($files as $file) {
            if ($count >= $limit) {
                break;
            }

            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false && $count < $limit) {
                $parsed = $this->parse_log_line($line);
                if ($parsed === null) {
                    continue;
                }

                if (!empty($level) && $parsed['level'] !== $level) {
                    continue;
                }

                $logs[] = $parsed;
                $count++;
            }

            fclose($handle);
        }

        return array_reverse($logs);
    }

    private function parse_log_line(string $line): ?array
    {
        $pattern = '/\[([^\]]+)\] \[([^\]]+)\] (.+)/';
        if (preg_match($pattern, $line, $matches)) {
            $context = [];
            if (preg_match('/\{.*\}$/', $line, $context_match)) {
                $context = json_decode($context_match[0], true) ?: [];
            }

            return [
                'timestamp' => $matches[1],
                'level' => $matches[2],
                'message' => trim(str_replace(json_encode($context), '', $matches[3])),
                'context' => $context,
            ];
        }

        return null;
    }

    public function download_log(string $filename = ''): void
    {
        $this->ensure_dir();
        if (empty($filename)) {
            // $this->log_file carries the randomized per-install suffix.
            $filename = $this->log_file;
        }

        $file_path = $this->log_dir . '/' . basename($filename);

        if (!file_exists($file_path)) {
            wp_die(esc_html__('Archivo de log no encontrado.', 'alegra-connector'));
        }

        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Content-Length: ' . filesize($file_path));

        readfile($file_path);
        exit;
    }

    public function get_log_files(): array
    {
        $this->ensure_dir();
        $files = glob($this->log_dir . '/*.log');
        $result = [];

        foreach ($files as $file) {
            if (is_file($file)) {
                $result[] = [
                    'name' => basename($file),
                    'size' => size_format(filesize($file)),
                    'modified' => date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
        }

        usort($result, function ($a, $b) {
            return strcmp($b['modified'], $a['modified']);
        });

        return $result;
    }

    /**
     * Get system/WordPress debug log entries
     */
    public function get_system_logs(int $limit = 50): array
    {
        $logs = [];

        // WordPress debug log
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $debug_file = is_string(WP_DEBUG_LOG) ? WP_DEBUG_LOG : WP_CONTENT_DIR . '/debug.log';
        } else {
            $debug_file = WP_CONTENT_DIR . '/debug.log';
        }

        if (file_exists($debug_file) && is_readable($debug_file)) {
            $lines = $this->tail_file($debug_file, $limit);
            foreach ($lines as $line) {
                $parsed = $this->parse_system_log_line($line);
                if ($parsed) {
                    $parsed['source'] = 'WordPress';
                    $logs[] = $parsed;
                }
            }
        }

        return $logs;
    }

    /**
     * Read last N lines of a file efficiently
     */
    private function tail_file(string $filepath, int $lines = 50): array
    {
        $result = [];
        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return $result;
        }

        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);
        $buffer = '';
        $line_count = 0;

        while ($position >= 0 && $line_count < $lines) {
            $chunk_size = min(4096, $position);
            if ($chunk_size <= 0) break;
            $position -= $chunk_size;
            fseek($handle, $position);
            $chunk = fread($handle, $chunk_size);
            if ($chunk === false) break;
            $buffer = $chunk . $buffer;
        }

        fclose($handle);

        return array_slice(explode("\n", $buffer), -$lines);
    }

    /**
     * Parse a PHP/WordPress system log line
     */
    private function parse_system_log_line(string $line): ?array
    {
        $line = trim($line);
        if (empty($line)) return null;

        // PHP error pattern: [dd-Mon-yyyy HH:MM:SS UTC] PHP Level: message
        if (preg_match('/^\[([^\]]+)\]\s*(?:PHP\s+)?(\w[\w\s]*?):\s*(.+)$/', $line, $m)) {
            $type = trim($m[2]);
            $level = 'INFO';
            if (stripos($type, 'Fatal') !== false || stripos($type, 'Parse') !== false) $level = 'CRITICAL';
            elseif (stripos($type, 'Error') !== false) $level = 'ERROR';
            elseif (stripos($type, 'Warning') !== false) $level = 'WARNING';
            elseif (stripos($type, 'Notice') !== false || stripos($type, 'Deprecated') !== false) $level = 'INFO';

            return [
                'timestamp' => $m[1],
                'level' => $level,
                'message' => mb_substr($m[3], 0, 500),
            ];
        }

        // Stack trace or continuation line
        if (preg_match('/^(Stack trace:|#\d+\s|thrown in)/', $line)) {
            return [
                'timestamp' => '',
                'level' => 'DEBUG',
                'message' => mb_substr($line, 0, 500),
            ];
        }

        // Any other line
        return [
            'timestamp' => '',
            'level' => 'DEBUG',
            'message' => mb_substr($line, 0, 500),
        ];
    }
}
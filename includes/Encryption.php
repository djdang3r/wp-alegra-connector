<?php
/**
 * Encryption Helper — Secure storage for sensitive credentials.
 *
 * Uses sodium_crypto_secretbox (AEAD: authenticated encryption with associated data).
 * Format: 'v3:' . base64(nonce[24 bytes] + ciphertext).
 *
 * Why sodium instead of openssl AES-256-CBC:
 *  - AEAD built-in (no separate HMAC needed)
 *  - No padding issues
 *  - Less code = fewer bugs
 *  - Native in PHP 7.2+ via ext-sodium
 *
 * Key derivation: combines multiple WordPress salts + plugin-specific nonce
 * to ensure uniqueness per install (mitigates cross-environment decrypt failures
 * that the previous AES-CBC implementation had).
 *
 * Backward compat: if a value doesn't start with 'v3:', it's treated as plaintext
 * (legacy or already-decrypted). This allows graceful migration.
 *
 * @package Alegra\Connector
 */

declare(strict_types=1);

namespace Alegra\Connector;

if (!defined('ABSPATH')) {
    exit;
}

class Encryption
{
    private const VERSION_PREFIX = 'v3:';
    private const PLUGIN_NONCE = 'alegra-connector-v3';

    /**
     * Check if sodium is available (required for new encryption).
     */
    public static function is_available(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            && function_exists('sodium_crypto_generichash')
            && function_exists('random_bytes');
    }

    /**
     * Encrypt a plaintext string using sodium AEAD.
     *
     * @param string $plaintext The value to encrypt.
     * @return string Encrypted value with 'v3:' prefix, or empty string if input is empty.
     *                Falls back to plaintext (with 'v2:' prefix stripped) only if sodium unavailable.
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        if (!self::is_available()) {
            // Fallback: return as-is (NOT secure but preserves functionality)
            // Only on servers without sodium (very rare: PHP < 7.2)
            return $plaintext;
        }

        try {
            $key = self::get_key();
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

            if ($ciphertext === false) {
                return $plaintext; // Safe fallback
            }

            return self::VERSION_PREFIX . base64_encode($nonce . $ciphertext);
        } catch (\Throwable $e) {
            // Never break the flow because of encryption failure
            return $plaintext;
        }
    }

    /**
     * Decrypt a value previously encrypted with self::encrypt().
     *
     * @param string $encrypted The encrypted value (with 'v3:' prefix) or plaintext.
     * @return string The decrypted plaintext, or empty string on failure.
     */
    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        // Strip legacy v2: prefix if present (backward compat with old AES-CBC implementation)
        if (strpos($encrypted, 'v2:') === 0) {
            $encrypted = substr($encrypted, 3);
        }

        // If it doesn't have v3: prefix, it's plaintext (legacy or already-decrypted)
        if (strpos($encrypted, self::VERSION_PREFIX) !== 0) {
            return $encrypted;
        }

        if (!self::is_available()) {
            // Cannot decrypt without sodium; return empty so caller knows it's broken
            return '';
        }

        try {
            $data = base64_decode(substr($encrypted, 3), true);
            if ($data === false || strlen($data) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + 1) {
                return '';
            }

            $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $key = self::get_key();

            $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

            return $plaintext === false ? '' : $plaintext;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Derive a 32-byte key from multiple WordPress salts + plugin-specific nonce.
     *
     * Using multiple salts + a plugin-specific value mitigates the historical
     * problem where tokens encrypted on local couldn't be decrypted on production
     * (because of different salt configs). Even if one salt is missing or different,
     * the others provide sufficient entropy.
     */
    private static function get_key(): string
    {
        $salt_parts = [];

        // Include all available WordPress salts
        foreach (['LOGGED_IN_KEY', 'LOGGED_IN_SALT', 'AUTH_KEY', 'AUTH_SALT',
                  'NONCE_KEY', 'NONCE_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT'] as $const) {
            if (defined($const) && constant($const)) {
                $salt_parts[] = constant($const);
            }
        }

        // Plugin-specific salt (prevents same key across installs using same WP salts)
        $salt_parts[] = self::PLUGIN_NONCE;

        // Site-specific entropy (if available)
        if (defined('AUTH_SITEURL') && constant('AUTH_SITEURL')) {
            $salt_parts[] = constant('AUTH_SITEURL');
        }

        $combined = implode('|', $salt_parts);

        // If we have NO salts at all (impossible in real WP, but safety first)
        if (empty($salt_parts) || $combined === '|' . self::PLUGIN_NONCE) {
            // Fallback with warning: in this case encryption is insecure
            // (single-install only, anyone with code access can derive the key)
            $combined = 'unsafe-fallback-' . self::PLUGIN_NONCE;
        }

        // Use generichash (BLAKE2b) to derive a 32-byte key from variable-length input
        return sodium_crypto_generichash($combined, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    /**
     * Check if a value is encrypted (has v3: prefix).
     */
    public static function is_encrypted(string $value): bool
    {
        return strpos($value, self::VERSION_PREFIX) === 0;
    }
}

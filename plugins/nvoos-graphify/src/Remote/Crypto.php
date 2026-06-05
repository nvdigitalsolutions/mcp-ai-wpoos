<?php
/**
 * Encryption helper for sensitive remote source credentials.
 *
 * Thin AES-256-GCM encryption wrapper for storing sensitive config
 * values (tokens, passwords) in the database.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Remote;

/**
 * Thin encryption helper using AES-256-GCM when OpenSSL is available.
 *
 * @since 1.0.0
 */
final class Crypto
{
    /**
     * Cipher algorithm used for encryption.
     *
     * @var string
     */
    public const CIPHER = 'aes-256-gcm';

    /**
     * Encrypt a plaintext string.
     *
     * Uses AES-256-GCM via openssl_encrypt if available, otherwise falls back
     * to base64 encoding (for environments without OpenSSL).
     *
     * @since 1.0.0
     * @param string $plaintext The value to encrypt.
     * @return string Base64-encoded ciphertext (or base64 of plaintext as fallback).
     */
    public static function encrypt(string $plaintext): string
    {
        if (empty($plaintext)) {
            return '';
        }

        if (! function_exists('openssl_encrypt') || ! in_array(self::CIPHER, openssl_get_cipher_methods(), true)) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            return 'b64:' . base64_encode($plaintext);
        }

        $key    = self::getKey();
        $ivLen  = openssl_cipher_iv_length(self::CIPHER);
        $iv     = openssl_random_pseudo_bytes($ivLen);
        $tag    = '';

        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if (false === $encrypted) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
            return 'b64:' . base64_encode($plaintext);
        }

        // Pack: iv_len(1) + iv + tag(16) + ciphertext.
        $packed = pack('C', $ivLen) . $iv . $tag . $encrypted;
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
        return 'gcm:' . base64_encode($packed);
    }

    /**
     * Decrypt a ciphertext string produced by encrypt().
     *
     * @since 1.0.0
     * @param string $ciphertext Encrypted value from encrypt().
     * @return string|false Plaintext on success, false on failure.
     */
    public static function decrypt(string $ciphertext): string|false
    {
        if (empty($ciphertext)) {
            return '';
        }

        // Base64 fallback.
        if (0 === strpos($ciphertext, 'b64:')) {
            // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
            return base64_decode(substr($ciphertext, 4));
        }

        if (0 !== strpos($ciphertext, 'gcm:')) {
            // Unknown format — return as-is (legacy plaintext stored before encryption was added).
            return $ciphertext;
        }

        if (! function_exists('openssl_decrypt')) {
            return false;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $packed = base64_decode(substr($ciphertext, 4));
        if (false === $packed || strlen($packed) < 18) {
            return false;
        }

        $ivLen     = ord($packed[0]);
        $iv        = substr($packed, 1, $ivLen);
        $tag       = substr($packed, 1 + $ivLen, 16);
        $encrypted = substr($packed, 1 + $ivLen + 16);
        $key       = self::getKey();

        $plaintext = openssl_decrypt($encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plaintext;
    }

    /**
     * Determine whether a config key is sensitive and should be encrypted.
     *
     * @since 1.0.0
     * @param string $key Config field key.
     * @return bool True if the key contains a sensitive pattern.
     */
    public static function isSensitiveKey(string $key): bool
    {
        $key      = strtolower($key);
        $patterns = array('token', 'password', 'secret', 'api_key', 'apikey', 'passwd', 'credential');
        foreach ($patterns as $pattern) {
            if (strpos($key, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Derive a 32-byte encryption key from WordPress salts.
     *
     * @since 1.0.0
     * @return string 32-byte binary key.
     */
    private static function getKey(): string
    {
        $authKey       = defined('AUTH_KEY') ? AUTH_KEY : 'nvoos-graphify-auth-key-fallback';
        $secureAuthKey = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'nvoos-graphify-secure-key-fallback';

        // Derive via HKDF-like construction using SHA-256.
        $salt    = 'nvoos-graphify-v1|' . get_option('siteurl', '');
        $ikm     = $authKey . '|' . $secureAuthKey;
        return hash_hmac('sha256', $salt, $ikm, true); // 32 bytes.
    }
}

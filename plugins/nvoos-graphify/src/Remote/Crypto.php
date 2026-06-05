<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote;

/**
 * AES-256-GCM credential storage stub.
 *
 * Full encryption/decryption implemented by the `nvoos-graphify-remote` addon.
 * The core plugin stores remote source configs as plain JSON in the
 * `nvoos_graphify_remote_sources` table.
 *
 * @since 1.0.0
 */
class Crypto
{
    /**
     * Check whether a config key contains sensitive data.
     *
     * @param string $key Config key name.
     * @return bool
     */
    public static function isSensitiveKey( string $key ): bool {
        $sensitive = array( 'api_key', 'token', 'password', 'secret', 'client_secret', 'private_key' );
        foreach ( $sensitive as $pattern ) {
            if ( false !== stripos( $key, $pattern ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Encrypt a value (stub — returns plaintext).
     *
     * @param string $value The value to encrypt.
     * @return string
     */
    public static function encrypt( string $value ): string {
        return $value;
    }

    /**
     * Decrypt a value (stub — returns input unchanged).
     *
     * @param string $value The value to decrypt.
     * @return string
     */
    public static function decrypt( string $value ): string {
        return $value;
    }
}

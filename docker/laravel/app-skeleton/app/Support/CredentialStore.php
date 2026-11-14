<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Sealed storage for per-connection credentials.
 *
 * Credentials are the one payload that must never round-trip to a browser, so
 * this seam exists to make the safe path the easy one: values go in as
 * plaintext, come out encrypted, and a read that cannot be decrypted is
 * treated as absent rather than fatal.
 *
 * Shape: a JSON object of `field => ciphertext`, one Laravel `Crypt` value per
 * field. Per-field rather than whole-blob so a single rotated or corrupted
 * value does not lose the rest.
 *
 * Three deliberate choices:
 *
 *  - **Blank values are skipped.** An empty credential is not a credential, and
 *    "blank means keep the stored one" is the console's update contract — so a
 *    blank field must not overwrite anything.
 *  - **Undecryptable values are dropped, not thrown.** A rotated `APP_KEY` or a
 *    tampered row degrades to "no credential" instead of a 500 on a list page.
 *  - **`has()` is a presence check, not a health check.** It reads the map's
 *    keys without decrypting, so listing N connections stays cheap. Use
 *    `decrypt()` when the value actually has to work.
 *
 * The WordPress runtime keeps its own AES-256-CBC `V2` format. ADR-8 says the
 * two backends never share connections, so there is no migration path to build
 * and no reason to reimplement their cipher here.
 */
final class CredentialStore
{
    /**
     * Seal a credential map.
     *
     * @param array<string, mixed> $fields Field name to plaintext value.
     * @return string JSON object of field name to ciphertext.
     */
    public function encrypt(array $fields): string
    {
        $sealed = array();

        foreach ($fields as $key => $value) {
            if (! is_string($value) || '' === trim($value)) {
                continue;
            }

            $sealed[(string) $key] = Crypt::encryptString(trim($value));
        }

        return (string) json_encode((object) $sealed);
    }

    /**
     * Open a sealed map, dropping values that cannot be decrypted.
     *
     * @return array<string, string> Field name to plaintext value.
     */
    public function decrypt(?string $payload): array
    {
        $opened = array();

        foreach ($this->sealedFields($payload) as $key => $ciphertext) {
            if (! is_string($ciphertext)) {
                continue;
            }

            try {
                $opened[$key] = Crypt::decryptString($ciphertext);
            } catch (DecryptException) {
                // Tampered, or sealed under a key this install no longer has.
                continue;
            }
        }

        return $opened;
    }

    /**
     * Whether any credential is stored.
     *
     * Does not decrypt: this is "something is sealed here", not "it opens".
     */
    public function has(?string $payload): bool
    {
        return array() !== $this->sealedFields($payload);
    }

    /**
     * The masked projection a read surface may return.
     *
     * Deliberately uniform: no length, no tail, no field values. A mask that
     * varies by credential leaks information about it.
     */
    public function mask(?string $payload): string
    {
        return $this->has($payload) ? "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}" : '';
    }

    /**
     * The sealed map, or an empty array for anything unreadable.
     *
     * @return array<string, mixed>
     */
    private function sealedFields(?string $payload): array
    {
        if (null === $payload || '' === $payload) {
            return array();
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : array();
    }
}

<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Hash;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Throwable;

/**
 * The operator's root security key — Proposal 149 §188.
 *
 * The field's own contract, from the WordPress catalogue:
 *
 * > A secure key for sensitive operations (minimum 32 characters, recommended
 * > 64+). Store this securely — it cannot be recovered if lost.
 *
 * and on the enable flag:
 *
 * > Adds an extra layer of security for administrative operations like deleting
 * > assistants, changing critical settings, or accessing sensitive data. Acts as
 * > a "second password" for high-risk actions.
 *
 * WordPress **scores** this key but never enforces it (`get_option` appears once,
 * in a self-test heuristic), so there is no parity behaviour to copy. The
 * description is the specification, and §188 enforces it at the operator write
 * path — see `RootKeyGate`.
 *
 * ## Stored as a hash, never as the secret
 *
 * The field is `sensitive`, so every read returns the mask; that hides the value
 * from the UI but not from the database. A "second password" sitting in
 * plaintext in `nvoos_settings` is a password in a database, and OWASP's
 * credential-storage guidance applies to it exactly as it would to a user's.
 * `set()` therefore stores `Hash::make($plain)`, and `verify()` uses
 * `Hash::check` — constant-time by construction.
 *
 * A value written before this class existed (or by an import) is plaintext. It is
 * accepted with `hash_equals` and **re-hashed on the next successful
 * verification**, so an upgraded deployment does not lock its operator out and
 * the secret stops being readable at rest.
 *
 * ## Why it reads the store directly
 *
 * `SettingsService::write()` is the thing this key protects, so going through it
 * would be circular. Reads and writes here use the platform store the container
 * binds to `nvoos.settings.platform`, which is the layer operator-scope settings
 * live in.
 *
 * ## Loss is expected, and has an escape hatch
 *
 * The field says the key "cannot be recovered if lost", and that is true: only a
 * hash is stored. `nvoos:root-key --set` is the break-glass path, and it is the
 * reason enabling this control cannot brick a deployment — see `RootKeyCommand`.
 */
final class RootKey
{
    /** The field's stated minimum. Anything shorter is refused, not truncated. */
    public const MIN_LENGTH = 32;

    /** The setting that stores the hash. */
    public const STORAGE_KEY = 'root_security_key';

    /** The flag that turns the gate on. */
    public const ENABLE_KEY = 'enable_root_security_key';

    /**
     * @param SettingsStoreInterface $platform The operator-scope layer.
     */
    public function __construct(private readonly SettingsStoreInterface $platform)
    {
    }

    /**
     * Is a key stored at all?
     */
    public function configured(): bool
    {
        try {
            $stored = (string) $this->platform->get('root_security_key', '');
        } catch (Throwable) {
            return false;
        }

        return '' !== $stored;
    }

    /**
     * Is the control switched on?
     */
    public function enabled(): bool
    {
        try {
            return filter_var(
                $this->platform->get('enable_root_security_key', false),
                FILTER_VALIDATE_BOOLEAN
            );
        } catch (Throwable) {
            // Unreadable settings must not gate the operator out of their own
            // deployment: the failure mode of a gate is denial of service.
            return false;
        }
    }

    /**
     * Store a new key, hashed.
     *
     * @throws RootKeyInvalid The value is shorter than the field's minimum.
     */
    public function set(string $plain): void
    {
        $plain = trim($plain);

        if (strlen($plain) < self::MIN_LENGTH) {
            throw new RootKeyInvalid(sprintf(
                'The root security key must be at least %d characters.',
                self::MIN_LENGTH
            ));
        }

        $this->platform->set('root_security_key', Hash::make($plain));
    }

    /**
     * Forget the key. The enable flag is left alone; `enabled()` alone no longer
     * gates anything because `configured()` is false.
     */
    public function clear(): void
    {
        $this->platform->set('root_security_key', '');
    }

    /**
     * Verify a candidate against the stored hash.
     *
     * A legacy plaintext value is compared in constant time and upgraded in place.
     * Empty input is never a match, so an unset key cannot be satisfied by
     * submitting nothing.
     */
    public function verify(string $candidate): bool
    {
        if ('' === $candidate) {
            return false;
        }

        try {
            $stored = (string) $this->platform->get('root_security_key', '');
        } catch (Throwable) {
            return false;
        }

        if ('' === $stored) {
            return false;
        }

        if (! $this->looksHashed($stored)) {
            if (! hash_equals($stored, $candidate)) {
                return false;
            }

            // Transparent upgrade: the plaintext stops existing at rest.
            try {
                $this->set($candidate);
            } catch (RootKeyInvalid) {
                // A legacy value below the minimum still verified; leave it.
            }

            return true;
        }

        return Hash::check($candidate, $stored);
    }

    /**
     * Does the stored value look like a password hash rather than a secret?
     *
     * bcrypt is `$2y$…`, Argon2 is `$argon2id$…`. Anything else is treated as a
     * pre-hash value and handled by the upgrade path above.
     */
    private function looksHashed(string $stored): bool
    {
        return str_starts_with($stored, '$2y$')
            || str_starts_with($stored, '$2a$')
            || str_starts_with($stored, '$argon2');
    }
}

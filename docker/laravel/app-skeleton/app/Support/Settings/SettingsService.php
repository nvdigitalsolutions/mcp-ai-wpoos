<?php

namespace App\Support\Settings;

use App\Support\SettingsSchema;
use App\Support\Security\RootKey;
use App\Support\Security\RootKeyGate;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

/**
 * The one settings reader/writer (149 R1, §16).
 *
 * Two presentations, two stores, one rulebook:
 *
 *  - a field's `scope` decides which LAYER stores it. `operator` scope lives in
 *    the platform table; `tenant`/`user` scope lives in the estate table. That
 *    is what stops a customer administrator's form from rewriting a platform
 *    default, and it is why the store itself is deliberately scope-blind.
 *  - a field's `storage` decides which KEY the runtime reads. The settings
 *    surface and the storage schema have never been the same vocabulary on this
 *    product (`google_api_key` → `gemini_api_key`), so the map belongs here
 *    rather than in either name.
 *  - a surface's write projection decides which fields it may accept at all:
 *    `console` is its visible set, `admin` is tenant/user scope, `ops` is
 *    everything. Reading a control is not permission to accept it.
 *
 * Both stores are `SettingsStoreInterface`, so this class cannot tell them
 * apart except by construction. The container binds the estate-aware store to
 * the interface and the platform store to `nvoos.settings.platform`.
 *
 * Not built yet: `user` scope. No field uses it, and a user layer needs a third
 * table plus a decision about session users versus assistant users. When one
 * appears, add the layer here and in the migration — do not silently route it
 * to the estate layer.
 */
final class SettingsService
{
    public const SURFACE_CONSOLE = 'console';

    public const SURFACE_ADMIN = 'admin';

    public const SURFACE_OPS = 'ops';

    public function __construct(
        private readonly SettingsStoreInterface $estate,
        private readonly SettingsStoreInterface $platform,
    ) {
    }

    /**
     * The values a surface may see, masked where the field is sensitive.
     *
     * The surface decides the field set; the field's scope decides the layer.
     * An operator-scope field therefore reads the platform value even when the
     * caller is an estate, which is what makes the S-1 disabled field show the
     * truth instead of the estate's empty fallback.
     *
     * @return array<string, mixed>
     */
    public function values(string $surface): array
    {
        $values = [];

        foreach (SettingsSchema::forSurface($surface) as $field) {
            $values[$field['key']] = $this->read($field);
        }

        return $values;
    }

    /**
     * Read one field by key, through its own layer.
     *
     * `values()` projects a whole surface, which is right for a form and wrong
     * for a caller that needs two or three keys on a hot path — `values('admin')`
     * reads every field on the surface. Returns null for a key the catalogue does
     * not have, so a caller cannot silently read a renamed setting as empty.
     *
     * (The count used to be written out here. It was wrong by one after §110
     * added a field without updating it, which is what a number in a docblock
     * does — so it is a description now.)
     */
    public function value(string $key): mixed
    {
        $field = SettingsSchema::field($key);

        return null === $field ? null : $this->read($field);
    }

    /**
     * Apply a payload, returning the surface keys that were actually written.
     *
     * Keys outside the surface's write projection are IGNORED, not rejected —
     * the same rule the console projection uses. A 422 would confirm that a
     * hidden field exists, and the visible keys in a mixed payload would be
     * lost for no security gain.
     *
     * @param array<string, mixed> $changes
     * @return list<string>
     */
    public function write(array $changes, string $surface): array
    {
        $writable = [];

        foreach (SettingsSchema::writableFor($surface) as $field) {
            $writable[$field['key']] = $field;
        }

        // 149 §188 — with no key stored the gate has no secret to verify, so the
        // only writes it can honestly permit are the ones that establish the
        // credential. Narrow the payload here, before the writer sees it: a field
        // travelling alongside the first key is refused rather than written for
        // free on the strength of the key it accompanies.
        if ($this->isBootstrapWrite($changes, $surface)) {
            $changes = array_intersect_key($changes, array_flip(array(
                RootKey::STORAGE_KEY,
                RootKey::ENABLE_KEY,
            )));
        }

        $this->assertOperatorWriteAllowed($changes, $surface);

        $applied = [];

        foreach ($changes as $key => $value) {
            if (! is_string($key) || ! isset($writable[$key])) {
                continue;
            }

            $field = $writable[$key];

            // A masked secret, or an empty secret field, means "unchanged".
            if ($field['sensitive'] && ($value === SettingsSchema::MASKED_PLACEHOLDER || $value === '' || $value === null)) {
                continue;
            }

            // 149 §188 — the root security key is a password, so it is stored as a
            // hash by the service that owns it. Writing the plaintext through the
            // store would leave a second password readable at rest.
            if (RootKey::STORAGE_KEY === (string) $field['storage']) {
                $this->rootKey()->set((string) $value);

                $applied[] = $key;

                continue;
            }

            $this->store($field)->set((string) $field['storage'], $this->coerce($field, $value));
            $applied[] = $key;
        }

        return $applied;
    }

    /**
     * Is this the write that establishes the first key?
     *
     * @param array<string, mixed> $changes
     */
    private function isBootstrapWrite(array $changes, string $surface): bool
    {
        return self::SURFACE_OPS === $surface
            && ! $this->rootKey()->configured()
            && array_key_exists(RootKey::STORAGE_KEY, $changes);
    }

    /**
     * Gate operator writes on the root security key — Proposal 149 §188.
     *
     * One exemption, and only one: storing the **first** key. Without it the
     * control could never be configured from the panel — enabling the flag with no
     * key would refuse the write that sets the key, and the only way out would be
     * the CLI. `write()` has already narrowed that payload to the key and its
     * flag, so the exemption cannot carry other fields past the gate.
     *
     * @param array<string, mixed> $changes
     */
    private function assertOperatorWriteAllowed(array $changes, string $surface): void
    {
        if (self::SURFACE_OPS !== $surface) {
            return;
        }

        if ($this->isBootstrapWrite($changes, $surface)) {
            return;
        }

        app(RootKeyGate::class)->assertUnlocked();
    }

    private function rootKey(): RootKey
    {
        return app(RootKey::class);
    }

    /**
     * Read one field through its own layer, masking secrets.
     *
     * @param array<string, mixed> $field
     */
    private function read(array $field): mixed
    {
        $value = $this->store($field)->get(
            (string) ($field['storage'] ?? $field['key']),
            $field['default'] ?? null,
        );

        if ($field['sensitive']) {
            return is_string($value) && '' !== $value
                ? SettingsSchema::MASKED_PLACEHOLDER
                : '';
        }

        return $value;
    }

    /**
     * The layer a field belongs to.
     *
     * @param array<string, mixed> $field
     */
    private function store(array $field): SettingsStoreInterface
    {
        return 'operator' === ($field['scope'] ?? 'tenant')
            ? $this->platform
            : $this->estate;
    }

    /**
     * @param array<string, mixed> $field
     * @return mixed
     */
    private function coerce(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'checkbox' => (bool) $value,
            'number' => $this->number($field, $value),
            'select' => $this->select($field, $value),
            'url' => $this->url($value),
            'email' => $this->email($value),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * @param array<string, mixed> $field
     */
    private function number(array $field, mixed $value): int|float
    {
        if (! is_numeric($value)) {
            abort(422, sprintf('%s must be numeric.', $field['label']));
        }

        $number = $field['type'] === 'number' && ($field['step'] ?? 1) < 1
            ? (float) $value
            : (int) $value;

        if (isset($field['min']) && $number < $field['min']) {
            abort(422, sprintf('%s must be at least %s.', $field['label'], $field['min']));
        }

        if (isset($field['max']) && $number > $field['max']) {
            abort(422, sprintf('%s must be at most %s.', $field['label'], $field['max']));
        }

        return $number;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function select(array $field, mixed $value): string
    {
        $value = (string) $value;
        $options = $field['options'] ?? [];

        if (! array_key_exists($value, $options)) {
            // A value already stored is preserved even when the catalogue no
            // longer offers it: a retired model slug must not make every other
            // setting on the page unsaveable. A CHANGE to an unsupported value
            // is still refused.
            $current = $this->store($field)->get(
                (string) ($field['storage'] ?? $field['key']),
                $field['default'] ?? null,
            );

            if ((string) $current === $value) {
                return $value;
            }

            abort(422, sprintf('%s is not a supported value.', $field['label']));
        }

        return $value;
    }

    private function url(mixed $value): string
    {
        $value = trim((string) $value);

        if ('' !== $value && filter_var($value, FILTER_VALIDATE_URL) === false) {
            abort(422, 'The URL is not valid.');
        }

        return $value;
    }

    private function email(mixed $value): string
    {
        $value = trim((string) $value);

        if ('' !== $value && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            abort(422, 'The email address is not valid.');
        }

        return $value;
    }
}

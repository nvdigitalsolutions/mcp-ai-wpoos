<?php

namespace App\Support\Settings;

use RuntimeException;

/**
 * The ported WordPress settings catalogue — Proposal 149 §62.
 *
 * `resources/settings/catalogue.json` is generated from the plugin's own
 * section classes (`get_fields()`, `get_subtab_groups()`, `get_tab()`), not
 * hand-transcribed: 585 fields across 12 tabs and 79 sections is more than a
 * person can copy accurately, and a transcription error here is invisible until
 * a setting silently stops saving.
 *
 * The nine fields this runtime shipped with stay in `SettingsSchema::coreFields()`
 * as literals, because they carry presentation facts the generator must not
 * invent: `scope: operator` on `ollama_endpoint`, and the storage remaps
 * (`google_api_key` → `gemini_api_key`, `ollama_endpoint` →
 * `ollama_endpoint_url`). The generated set is additive and skips every key and
 * storage key those nine already own.
 *
 * What the generator deliberately does not port, and why:
 *
 * - `html` rows — informational markup, not values.
 * - `hidden` rows — nonces and internal state a form should not collect.
 * - `custom` rows — they name a bespoke renderer with no schema behind it.
 * - `multiselect` rows — the store holds a list, and a single control would
 *   change the stored shape (the same rule §7b applies to the WordPress side).
 * - the `__hidden__` tab — its fields mirror `general/custom_filters`.
 * - the empty-tab section (`plugins_integration`) — WordPress cannot render it
 *   either.
 *
 * Everything generated is `scope: tenant` and `console: hidden`. Tenant because
 * an estate administrator is the parity target; hidden because the console is a
 * reduced product surface and rendering 585 controls there is not a product
 * decision this port gets to make. Re-tiering a field is a one-line edit in the
 * JSON plus a review of who should own it.
 */
final class SettingsCatalogue
{
    /**
     * Decoded catalogue, cached for the request.
     *
     * @var array{tabs: array<string, string>, fields: list<array<string, mixed>>}|null
     */
    private static ?array $catalogue = null;

    /**
     * Absolute path to the generated catalogue.
     */
    public static function path(): string
    {
        return resource_path('settings/catalogue.json');
    }

    /**
     * The ported fields, in catalogue order.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        return self::catalogue()['fields'];
    }

    /**
     * The ported tabs, in catalogue order.
     *
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return self::catalogue()['tabs'];
    }

    /**
     * Drop the cached catalogue.
     *
     * A test seam: a test that rewrites the file needs the next read to see it.
     */
    public static function flush(): void
    {
        self::$catalogue = null;
    }

    /**
     * @return array{tabs: array<string, string>, fields: list<array<string, mixed>>}
     */
    private static function catalogue(): array
    {
        if (null !== self::$catalogue) {
            return self::$catalogue;
        }

        $path = self::path();
        $raw  = is_file($path) ? file_get_contents($path) : false;

        if (false === $raw) {
            // Fail closed. A settings surface that silently drops to nine fields
            // looks like data loss to the administrator and hides the real fault.
            throw new RuntimeException('The settings catalogue is missing: '.$path);
        }

        $decoded = json_decode($raw, true);

		if (
			! is_array($decoded)
			|| ! isset($decoded['tabs'], $decoded['fields'])
			|| ! is_array($decoded['tabs'])
			|| ! is_array($decoded['fields'])
		) {
            throw new RuntimeException('The settings catalogue is not valid: '.$path);
        }

        self::$catalogue = $decoded;

        return self::$catalogue;
    }
}

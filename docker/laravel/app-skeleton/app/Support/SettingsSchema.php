<?php

namespace App\Support;

use App\Support\Settings\InapplicableSettings;
use App\Support\Settings\SettingsCatalogue;
use App\Support\Settings\UnimplementedSettings;

/**
 * The settings catalogue the console renders.
 *
 * Mirrors the plugin's `GET /mcp-ai/v1/settings/schema` shape so the same
 * SettingsPage works on both runtimes. Session keys are the only writable
 * surface in Phase 1; provider keys are masked on read and never echoed.
 *
 * Every field declares two presentation facts (149 §14, D-6):
 *
 * - `scope`: who the setting belongs to — `operator` (NV staff / shared
 *   infrastructure), `tenant` (the estate's administrator) or `user`.
 * - `console`: what the reduced product surface does with it — `visible`,
 *   `readonly` or `hidden`.
 *
 * And one storage fact:
 *
 * - `storage`: the key the runtime actually reads. The settings surface is not
 *   the storage schema — the WordPress twin has always mapped the API key
 *   `google_api_key` onto the stored option `gemini_api_key`, and
 *   `ollama_endpoint` onto `ollama_endpoint_url`. Keeping that map here (rather
 *   than renaming either side) is what makes the form honest: a field exists to
 *   change a value the provider client already reads.
 *
 * Scope is not an authorisation check by itself; the route middleware decides
 * who reaches the endpoint. It records ownership so the admin projection can
 * stay complete while the console projection is reduced, and so the writer can
 * route an operator-scope value to the platform layer instead of the estate's.
 * `fields()` is the full catalogue: security snapshots and `keys()` depend on
 * it, so filtering happens in `forSurface()`, never here.
 */
final class SettingsSchema
{
    public const MASKED_PLACEHOLDER = '**************';

    /**
     * The tab strip: the three core tabs keep their established labels, and the
     * ported catalogue supplies the rest and their order (§62).
     *
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        $tabs = SettingsCatalogue::tabs();

        $tabs['general']   = 'General';
        $tabs['providers'] = 'Providers';
        $tabs['advanced']  = 'Advanced';

        return $tabs;
    }

    /**
     * The full catalogue: the nine core fields, then the ported WordPress
     * surface.
     *
     * Core first so the existing field order — and every test that pins a
     * position — is unchanged by the port.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        return array_merge(self::coreFields(), SettingsCatalogue::fields());
    }

    /**
     * The fields this runtime shipped with, before the §62 port.
     *
     * These stay literal on purpose: `scope: operator` and the two storage
     * remaps are decisions the generator cannot infer from the plugin's field
     * arrays.
     *
     * @return list<array<string, mixed>>
     */
    private static function coreFields(): array
    {
        return [
            [
                'key' => 'default_provider',
                'storage' => 'default_provider',
                'scope' => 'tenant',
                'console' => 'visible',
                'type' => 'select',
                'section_id' => 'general',
                'section' => 'Model defaults',
                'tab' => 'general',
                'label' => 'Default provider',
                'description' => 'Provider used when an assistant does not pin one.',
                'sensitive' => false,
                'options' => [
                    'openai' => 'OpenAI',
                    'gemini' => 'Google Gemini',
                    'anthropic' => 'Anthropic',
                    'ollama' => 'Ollama (local)',
                ],
                'default' => 'openai',
            ],
            [
                'key' => 'default_model',
                'storage' => 'default_model',
                'scope' => 'tenant',
                'console' => 'visible',
                'type' => 'text',
                'section_id' => 'general',
                'section' => 'Model defaults',
                'tab' => 'general',
                'label' => 'Default model',
                'description' => 'Model slug used when an assistant does not pin one.',
                'sensitive' => false,
                'default' => 'gpt-4o-mini',
            ],
            [
                'key' => 'max_tokens',
                'storage' => 'max_tokens',
                'scope' => 'tenant',
                'console' => 'visible',
                'type' => 'number',
                'section_id' => 'general',
                'section' => 'Model defaults',
                'tab' => 'general',
                'label' => 'Max tokens',
                'description' => 'Upper bound for a single completion.',
                'sensitive' => false,
                'default' => 4096,
                'min' => 1,
                'max' => 200000,
                'step' => 1,
            ],
            [
                'key' => 'temperature',
                'storage' => 'temperature',
                'scope' => 'tenant',
                'console' => 'visible',
                'type' => 'number',
                'section_id' => 'general',
                'section' => 'Model defaults',
                'tab' => 'general',
                'label' => 'Temperature',
                'description' => 'Sampling temperature (0 = deterministic).',
                'sensitive' => false,
                'default' => 0.7,
                'min' => 0,
                'max' => 2,
                'step' => 0.1,
            ],
            [
                'key' => 'openai_api_key',
                'storage' => 'openai_api_key',
                'scope' => 'tenant',
                'console' => 'visible',
                'type' => 'password',
                'section_id' => 'provider_keys',
                'section' => 'Provider credentials',
                'tab' => 'providers',
                'label' => 'OpenAI API key',
                'description' => 'Stored encrypted; never returned in plain text.',
                'sensitive' => true,
            ],
            [
                'key' => 'google_api_key',
                // Stored under the name the provider client reads. The
                // WordPress twin has the same asymmetry (FIELD_MAP), so the
                // console payload is identical on both runtimes.
                'storage' => 'gemini_api_key',
                'scope' => 'tenant',
                'console' => 'visible',
                'type' => 'password',
                'section_id' => 'provider_keys',
                'section' => 'Provider credentials',
                'tab' => 'providers',
                'label' => 'Gemini API key',
                'description' => 'Stored encrypted; never returned in plain text.',
                'sensitive' => true,
            ],
            [
                'key' => 'anthropic_api_key',
                'storage' => 'anthropic_api_key',
                'scope' => 'tenant',
                'console' => 'visible',
                'type' => 'password',
                'section_id' => 'provider_keys',
                'section' => 'Provider credentials',
                'tab' => 'providers',
                'label' => 'Anthropic API key',
                'description' => 'Stored encrypted; never returned in plain text.',
                'sensitive' => true,
            ],
            [
                // S-5: operator scope. This is a URL the server fetches, so a
                // tenant administrator choosing it is an SSRF primitive. A
                // customer still reaches Ollama safely by selecting the
                // `ollama` provider and a model — provider choice and model
                // remain tenant-scope; only the address is not.
                'key' => 'ollama_endpoint',
                'storage' => 'ollama_endpoint_url',
                'scope' => 'operator',
                'console' => 'hidden',
                'type' => 'url',
                'section_id' => 'provider_keys',
                'section' => 'Provider credentials',
                'tab' => 'providers',
                'label' => 'Ollama endpoint',
                'description' => 'Base URL of the Ollama server the platform fetches. Managed by NV Digital.',
                'sensitive' => false,
                'default' => 'http://localhost:11434',
            ],
            [
                'key' => 'enable_logging',
                'storage' => 'enable_logging',
                'scope' => 'operator',
                'console' => 'hidden',
                'type' => 'checkbox',
                'section_id' => 'runtime',
                'section' => 'Runtime',
                'tab' => 'advanced',
                'label' => 'Enable logging',
                'description' => 'Write request and tool diagnostics to the application log.',
                'sensitive' => false,
                'default' => false,
            ],
            [
                'key' => 'machine_tools',
                'storage' => 'machine_tools',
                'scope' => 'operator',
                'console' => 'hidden',
                'type' => 'text',
                'section_id' => 'machine_access',
                'section' => 'Machine access',
                'tab' => 'advanced',
                'label' => 'Machine-executable tools',
                'description' => 'Comma-separated tool slugs a machine principal may run (159 slice 4). '
                    .'Each slug must be registered and servable; unknown slugs are ignored. '
                    .'A tool still needs the assistant to list it, and its own annotations can veto unattended use.',
                'sensitive' => false,
                'default' => '',
            ],
            // ── Turn budget (149 §179) ──────────────────────────────
            //
            // The two ceilings that bound one agentic turn. Zero means off in
            // both dimensions, so an estate that never opens this section behaves
            // exactly as it did before the ceilings existed.
            //
            // Tenant scope: how much a turn may spend is the estate's own cost
            // policy, the same judgement that puts the tool quotas on /admin.
            // The deployment-wide cap is the operator's `budgets` surface.
            [
                'key' => 'turn_max_call_tokens',
                'storage' => 'turn_max_call_tokens',
                'scope' => 'tenant',
                'console' => 'hidden',
                'type' => 'number',
                'section_id' => 'turn_budget',
                'section' => 'Turn budget',
                'tab' => 'advanced',
                'label' => 'Per-call token ceiling',
                'description' => 'Largest request this estate will send: prompt plus requested completion. '
                    .'A call over it is refused before it leaves. 0 disables the ceiling.',
                'sensitive' => false,
                'default' => 0,
                'min' => 0,
                'max' => 2000000,
                'step' => 500,
            ],
            [
                'key' => 'turn_max_turn_tokens',
                'storage' => 'turn_max_turn_tokens',
                'scope' => 'tenant',
                'console' => 'hidden',
                'type' => 'number',
                'section_id' => 'turn_budget',
                'section' => 'Turn budget',
                'tab' => 'advanced',
                'label' => 'Per-turn token ceiling',
                'description' => 'Total tokens one turn may spend across all of its tool round-trips. '
                    .'The loop stops before the call that would cross it. 0 disables the ceiling.',
                'sensitive' => false,
                'default' => 0,
                'min' => 0,
                'max' => 100000000,
                'step' => 1000,
            ],
            [
                'key' => 'turn_warn_at',
                'storage' => 'turn_warn_at',
                'scope' => 'tenant',
                'console' => 'hidden',
                'type' => 'number',
                'section_id' => 'turn_budget',
                'section' => 'Turn budget',
                'tab' => 'advanced',
                'label' => 'Warn at',
                'description' => 'Fraction of a ceiling at which the turn records a warning in its transcript. '
                    .'0.8 warns at 80%%. The turn is not stopped.',
                'sensitive' => false,
                'default' => 0.8,
                'min' => 0,
                'max' => 1,
                'step' => 0.05,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::fields(), 'key');
    }

    /**
     * The catalogue as one presentation sees it.
     *
     * Three surfaces, three questions:
     *
     * - `admin` is the estate's full form. It includes operator-scope fields so
     *   the administrator can see what is managed for them (S-1 renders those
     *   disabled), which is why this is the default.
     * - `console` is the reduced product surface: a field marked `hidden` is
     *   omitted entirely rather than returned as null — null still discloses
     *   that the setting exists.
     * - `ops` is the platform form: operator-scope fields only. A tenant field
     *   belongs to an estate, and `/ops` has no estate selected, so showing it
     *   there would edit the `default` estate by accident.
     *
     * Filtering here rather than in `fields()` keeps `keys()` and
     * `sensitiveKeys()` complete, which is what the security snapshot path
     * needs.
     *
     * `admin` also drops the `inapplicable` keys (Proposal 149 §104) — settings
     * that configure WordPress software this runtime does not have. They are not
     * rendered at all, because a disabled control still asserts that the
     * capability could be built here.
     *
     * @return list<array<string, mixed>>
     */
    public static function forSurface(string $surface = 'admin'): array
    {
        // A key declared inapplicable configures software that is not here, so it
        // must not render on ANY surface. Applying the rule only to the default
        // branch let it leak onto /ops, where `SettingsProjectionTest` asserts the
        // rendered set and the writable set are the same set — and it left a
        // hidden key writable by a crafted payload. One filter, every surface.
        $renderable = array_values(array_filter(
            self::fields(),
            static fn (array $field): bool => ! InapplicableSettings::isInapplicable((string) $field['key']),
        ));

        if ('console' === $surface) {
            return array_values(array_filter(
                $renderable,
                static fn (array $field): bool => 'hidden' !== ($field['console'] ?? 'visible'),
            ));
        }

        if ('ops' === $surface) {
            return array_values(array_filter(
                $renderable,
                static fn (array $field): bool => 'operator' === ($field['scope'] ?? 'tenant'),
            ));
        }

        return $renderable;
    }

    /**
     * Tabs that still carry at least one field on this surface.
     *
     * The console derives its tab strip from the field list, so a tab emptied
     * by the projection disappears without a client change.
     *
     * @return array<string, string>
     */
    public static function tabsForSurface(string $surface = 'admin'): array
    {
        $present = array_column(self::forSurface($surface), 'tab');

        return array_filter(
            self::tabs(),
            static fn (string $label, string $id): bool => in_array($id, $present, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The catalogue a surface may WRITE.
     *
     * Read and write projections are different questions, and conflating them
     * is how a reduced surface becomes a UI fiction:
     *
     * - `console` may write only what it is shown, and not what it is shown
     *   read-only. Operator-scope fields are already absent from its projection.
     * - `admin` renders the full form, including operator-scope fields (S-1:
     *   disabled with a hint), but may write only tenant/user scope. Rendering
     *   a control is not permission to accept it.
     *
     * `admin` also refuses the `inert` keys from `UnimplementedSettings`
     * (Proposal 156). They are tenant scope and they render, but no code reads
     * them, so accepting a write would be the same fiction as a control that
     * does nothing — the value would persist and change no behaviour.
     * - `ops` is the platform operator: operator-scope fields only. Those
     *   values are routed to the platform layer by the writer, so one edit
     *   applies to every estate instead of to whichever estate `/ops` resolved.
     *
     * @return list<array<string, mixed>>
     */
    public static function writableFor(string $surface = 'admin'): array
    {
        if ('console' === $surface) {
            return array_values(array_filter(
                self::forSurface('console'),
                static fn (array $field): bool => 'readonly' !== ($field['console'] ?? 'visible'),
            ));
        }

        if ('ops' === $surface) {
            // Rendered and writable are the same set on /ops, which is why the
            // inapplicable exclusion lives in `forSurface()` rather than here.
            return self::forSurface('ops');
        }

        return array_values(array_filter(
            self::fields(),
            static fn (array $field): bool => 'operator' !== ($field['scope'] ?? 'tenant')
                && ! UnimplementedSettings::isInert((string) $field['key'])
                && ! InapplicableSettings::isInapplicable((string) $field['key']),
        ));
    }

    /**
     * The field with this surface key, or null.
     *
     * @return array<string, mixed>|null
     */
    public static function field(string $key): ?array
    {
        foreach (self::fields() as $field) {
            if ($key === $field['key']) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The key the runtime reads for a surface field.
     *
     * Defaults to the surface key, so adding a field without a `storage` entry
     * is a no-op mapping rather than a crash.
     */
    public static function storageKey(string $key): string
    {
        $field = self::field($key);

        return (string) ($field['storage'] ?? $key);
    }

    /**
     * The stored credential key for a provider slug.
     *
     * Used by diagnostics and the security posture, which iterate providers
     * rather than form fields. Before this existed, three call sites each carried
     * their own copy of the `gemini` → `google_api_key` assumption — and two of
     * them read a key that nothing wrote.
     */
    public static function providerCredentialKey(string $provider): string
    {
        return match ($provider) {
            'openai' => 'openai_api_key',
            'gemini' => 'gemini_api_key',
            'anthropic' => 'anthropic_api_key',
            default => $provider.'_api_key',
        };
    }

    /**
     * Keys whose values must never be written to a durable record (backups,
     * snapshots, logs). Only the masked placeholder may be persisted.
     *
     * @return list<string>
     */
    public static function sensitiveKeys(): array
    {
        return array_column(
            array_filter(self::fields(), static fn (array $field): bool => (bool) ($field['sensitive'] ?? false)),
            'key',
        );
    }
}

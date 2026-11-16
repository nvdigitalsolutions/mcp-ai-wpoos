<?php

namespace App\Support;

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
 * Scope is not an authorisation check by itself; the route middleware decides
 * who reaches the endpoint. It records ownership so the admin projection can
 * stay complete while the console projection is reduced. `fields()` is the
 * full catalogue: security snapshots and `keys()` depend on it, so filtering
 * happens in `forSurface()`, never here.
 */
final class SettingsSchema
{
    public const MASKED_PLACEHOLDER = '**************';

    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            'general' => 'General',
            'providers' => 'Providers',
            'advanced' => 'Advanced',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fields(): array
    {
        return [
            [
                'key' => 'default_provider',
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
                'key' => 'ollama_endpoint',
                'scope' => 'tenant',
                'console' => 'visible',
                'type' => 'url',
                'section_id' => 'provider_keys',
                'section' => 'Provider credentials',
                'tab' => 'providers',
                'label' => 'Ollama endpoint',
                'description' => 'Base URL of the local Ollama server.',
                'sensitive' => false,
                'default' => 'http://localhost:11434',
            ],
            [
                'key' => 'enable_logging',
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
     * `admin` is the full form. `console` is the reduced product surface: a
     * field marked `hidden` is omitted entirely rather than returned as null —
     * null still discloses that the setting exists.
     *
     * Filtering here rather than in `fields()` keeps `keys()` and
     * `sensitiveKeys()` complete, which is what the security snapshot path
     * needs.
     *
     * @return list<array<string, mixed>>
     */
    public static function forSurface(string $surface = 'admin'): array
    {
        if ('console' !== $surface) {
            return self::fields();
        }

        return array_values(array_filter(
            self::fields(),
            static fn (array $field): bool => 'hidden' !== ($field['console'] ?? 'visible'),
        ));
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

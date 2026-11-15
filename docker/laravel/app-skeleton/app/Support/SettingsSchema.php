<?php

namespace App\Support;

/**
 * The settings catalogue the console renders.
 *
 * Mirrors the plugin's `GET /mcp-ai/v1/settings/schema` shape so the same
 * SettingsPage works on both runtimes. Session keys are the only writable
 * surface in Phase 1; provider keys are masked on read and never echoed.
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

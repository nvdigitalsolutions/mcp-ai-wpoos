<?php

namespace Tests\Feature;

use App\Support\Settings\SettingsCatalogue;
use App\Support\Settings\SettingsService;
use App\Support\SettingsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 149 §62 — the ported WordPress settings catalogue.
 *
 * The catalogue is generated from the plugin's section classes, so the risk is
 * not a typo in one field; it is a structural lie that renders as a form. These
 * tests pin the invariants a generator can silently break: duplicate keys,
 * duplicate storage, a tab that has no entry in the strip, a select whose
 * default is not one of its options, and — the one this port actually hit — a
 * generated field that writes the same stored key as a core field.
 *
 * Counts are floors, not equalities: adding fields to the WordPress surface
 * should not fail this file, only regenerating away half of them should.
 */
class SettingsCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_loads(): void
    {
        $this->assertFileExists(SettingsCatalogue::path());

        $this->assertGreaterThanOrEqual(500, count(SettingsCatalogue::fields()));
        $this->assertGreaterThanOrEqual(10, count(SettingsCatalogue::tabs()));
    }

    public function test_every_field_declares_the_keys_the_renderer_needs(): void
    {
        $required = array(
            'key', 'storage', 'scope', 'console', 'type',
            'section_id', 'section', 'tab', 'label', 'sensitive',
        );

        foreach (self::catalogue() as $field) {
            foreach ($required as $property) {
                $this->assertArrayHasKey(
                    $property,
                    $field,
                    sprintf('Field "%s" is missing "%s".', (string) ($field['key'] ?? '?'), $property)
                );
            }

            $this->assertContains(
                $field['type'],
                array('text', 'textarea', 'number', 'checkbox', 'select', 'password', 'url', 'email'),
                sprintf('Field "%s" has an unsupported type "%s".', $field['key'], $field['type'])
            );

            $this->assertContains($field['scope'], array('tenant', 'operator', 'user'));
            $this->assertContains($field['console'], array('visible', 'readonly', 'hidden'));
        }
    }

    public function test_surface_keys_are_unique(): void
    {
        $keys = array_column(SettingsSchema::fields(), 'key');

        $duplicates = array_keys(array_filter(array_count_values($keys), static fn (int $n): bool => $n > 1));

        $this->assertSame([], $duplicates, 'Two controls would bind the same form state: '.implode(', ', $duplicates));
    }

    public function test_storage_keys_are_unique(): void
    {
        $storages = array_map(
            static fn (array $field): string => (string) ($field['storage'] ?? $field['key']),
            SettingsSchema::fields()
        );

        $duplicates = array_keys(array_filter(array_count_values($storages), static fn (int $n): bool => $n > 1));

        // `google_api_key` stores under `gemini_api_key`; porting the plugin's
        // `gemini_api_key` field as well would render two controls for one value.
        $this->assertSame([], $duplicates, 'Two fields write the same stored key: '.implode(', ', $duplicates));
    }

    public function test_every_field_belongs_to_a_declared_tab(): void
    {
        $tabs = SettingsSchema::tabs();

        foreach (SettingsSchema::fields() as $field) {
            $this->assertArrayHasKey(
                $field['tab'],
                $tabs,
                sprintf('Field "%s" is in tab "%s", which the strip does not declare.', $field['key'], $field['tab'])
            );
        }
    }

    public function test_selects_offer_their_default(): void
    {
        foreach (SettingsSchema::fields() as $field) {
            if ('select' !== $field['type']) {
                continue;
            }

            $this->assertNotEmpty($field['options'] ?? [], sprintf('Select "%s" has no options.', $field['key']));

            $this->assertArrayHasKey(
                (string) ($field['default'] ?? ''),
                $field['options'],
                sprintf(
                    'Select "%s" defaults to "%s", which is not one of its options.',
                    $field['key'],
                    (string) ($field['default'] ?? '')
                )
            );
        }
    }

    public function test_number_bounds_are_coherent(): void
    {
        foreach (SettingsSchema::fields() as $field) {
            if ('number' !== $field['type']) {
                continue;
            }

            $this->assertTrue(
                is_numeric($field['default'] ?? 0),
                sprintf('Number "%s" has a non-numeric default.', $field['key'])
            );

            if (isset($field['min'], $field['max'])) {
                $this->assertLessThanOrEqual(
                    $field['max'],
                    $field['min'],
                    sprintf('Number "%s" has min greater than max.', $field['key'])
                );
            }
        }
    }

    public function test_a_limit_is_not_mistaken_for_a_secret(): void
    {
        $sensitive = SettingsSchema::sensitiveKeys();

        // The generator matches credential suffixes, not the substring "token":
        // `max_tokens` and `token_limit` are limits, and masking them would hide
        // ordinary settings from the administrator.
        $this->assertNotContains('max_tokens', $sensitive);
        $this->assertNotContains('token_limit', $sensitive);

        $this->assertContains('openai_api_key', $sensitive);
        // The surface key, not the storage key: `google_api_key` stores under
        // `gemini_api_key`, which is why the generated set skips the latter.
        $this->assertContains('google_api_key', $sensitive);
    }

    public function test_every_sensitive_field_is_a_password_input_or_a_named_credential(): void
    {
        $suffixes = array(
            '_api_key', '_secret', '_password', '_token',
            '_client_secret', '_access_token', '_refresh_token', '_private_key',
        );
        $exact    = array('api_key', 'password', 'secret');

        foreach (SettingsSchema::fields() as $field) {
            if (! $field['sensitive']) {
                continue;
            }

            $named = in_array($field['key'], $exact, true);

            foreach ($suffixes as $suffix) {
                $named = $named || str_ends_with($field['key'], $suffix);
            }

            $this->assertTrue(
                $named || 'password' === $field['type'],
                sprintf(
                    'Field "%s" is sensitive but is neither a password input nor a named credential.',
                    $field['key']
                )
            );
        }
    }

    public function test_the_ported_fields_reach_the_admin_surface_only(): void
    {
        $admin   = array_column(SettingsSchema::forSurface('admin'), 'key');
        $console = array_column(SettingsSchema::forSurface('console'), 'key');

        // A ported field is a tenant setting on the admin panel...
        $this->assertContains('chat_assistant_bubble_color', $admin);

        // ...and the console projection stays exactly as it shipped.
        $this->assertNotContains('chat_assistant_bubble_color', $console);
        $this->assertLessThan(count($admin), count($console));
    }

    public function test_a_ported_field_writes_through_the_one_writer(): void
    {
        $applied = app(SettingsService::class)->write(
            array(
                'chat_assistant_bubble_color' => '#123456',
                'default_model' => 'gpt-4o-mini',
            ),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertContains('chat_assistant_bubble_color', $applied);
        $this->assertContains('default_model', $applied);
    }

    public function test_a_ported_secret_is_masked_and_never_echoed(): void
    {
        app(SettingsService::class)->write(
            array('mailjet_api_key' => 'mj-live-secret'),
            SettingsService::SURFACE_ADMIN
        );

        $values = app(SettingsService::class)->values(SettingsService::SURFACE_ADMIN);

        $this->assertArrayHasKey('mailjet_api_key', $values);
        $this->assertSame(SettingsSchema::MASKED_PLACEHOLDER, $values['mailjet_api_key']);
        $this->assertNotContains('mj-live-secret', $values);
    }

    public function test_a_masked_secret_is_not_rewritten(): void
    {
        app(SettingsService::class)->write(
            array('mailjet_api_key' => 'mj-live-secret'),
            SettingsService::SURFACE_ADMIN
        );

        // Re-submitting the mask is the "leave it alone" contract.
        $applied = app(SettingsService::class)->write(
            array('mailjet_api_key' => SettingsSchema::MASKED_PLACEHOLDER),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertSame(array(), $applied);
        $this->assertSame(
            'mj-live-secret',
            app(\Nvoos\Core\Domain\Contract\SettingsStoreInterface::class)->get('mailjet_api_key')
        );
    }

    public function test_an_operator_field_is_still_not_writable_on_the_admin_surface(): void
    {
        $applied = app(SettingsService::class)->write(
            array('ollama_endpoint' => 'http://elsewhere:11434'),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertSame(array(), $applied);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function catalogue(): array
    {
        return SettingsCatalogue::fields();
    }
}

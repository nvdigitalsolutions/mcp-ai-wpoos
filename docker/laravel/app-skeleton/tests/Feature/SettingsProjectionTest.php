<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SettingsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Tests\TestCase;

/**
 * 149 §14 — the settings projection (D-6).
 *
 * The console is a plan-scoped product surface, so the API returns the fields
 * it declares and nothing else. A hidden field is omitted rather than nulled:
 * null still discloses that the setting exists, and a client that renders
 * `null` as an empty control offers a write the server will ignore — the
 * "hiding a button is UX, not authorization" failure in reverse.
 *
 * `SettingsSchema::fields()` stays complete because the security snapshot path
 * reads `keys()` / `sensitiveKeys()`; only the presentation projections
 * reduce it.
 */
class SettingsProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_every_field_declares_a_scope_and_a_console_state(): void
    {
        foreach (SettingsSchema::fields() as $field) {
            $this->assertContains(
                $field['scope'] ?? null,
                ['operator', 'tenant', 'user'],
                sprintf('Field "%s" has no valid scope.', $field['key'] ?? '')
            );

            $this->assertContains(
                $field['console'] ?? null,
                ['visible', 'readonly', 'hidden'],
                sprintf('Field "%s" has no valid console state.', $field['key'] ?? '')
            );
        }
    }

    public function test_the_console_schema_omits_every_operator_scope_field(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $fields = $this->getJson('/api/v1/settings/schema')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->json('data.fields');

        $payloadKeys = array_column($fields, 'key');
        $operatorKeys = array_column(
            array_filter(
                SettingsSchema::fields(),
                static fn (array $field): bool => 'operator' === ($field['scope'] ?? 'tenant')
            ),
            'key'
        );

        $this->assertNotSame([], $operatorKeys, 'The fixture needs at least one operator-scope field.');
        $this->assertSame(
            [],
            array_values(array_intersect($operatorKeys, $payloadKeys)),
            'Operator-scope settings must not reach the console.'
        );

        // Concrete anchor, so the generated rule above cannot pass on an empty set.
        $this->assertContains('enable_logging', $operatorKeys);
        $this->assertNotContains('enable_logging', $payloadKeys);
        $this->assertContains('default_model', $payloadKeys);
    }

    public function test_the_console_drops_a_tab_emptied_by_the_projection(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $tabs = $this->getJson('/api/v1/settings/schema')
            ->assertOk()
            ->json('data.tabs');

        $this->assertArrayHasKey('general', $tabs);
        $this->assertArrayHasKey('providers', $tabs);

        // 'advanced' held only enable_logging, which is operator-scope.
        $this->assertArrayNotHasKey('advanced', $tabs);
    }

    public function test_the_admin_projection_keeps_the_whole_catalogue(): void
    {
        $this->assertSame(SettingsSchema::keys(), array_column(SettingsSchema::forSurface('admin'), 'key'));
        $this->assertSame(SettingsSchema::tabsForSurface('admin'), SettingsSchema::tabs());

        $this->assertContains(
            'enable_logging',
            array_column(SettingsSchema::forSurface('admin'), 'key'),
            'The admin is the superset: the projection must not remove the field from the catalogue.'
        );
    }

    /**
     * S-5. The endpoint is a URL the SERVER fetches, so it is platform
     * configuration. Closing it here does not stop a customer using Ollama:
     * `default_provider` and `default_model` stay tenant-scope.
     */
    public function test_the_ollama_endpoint_is_operator_scope(): void
    {
        $field = SettingsSchema::field('ollama_endpoint');

        $this->assertIsArray($field);
        $this->assertSame('operator', $field['scope']);
        $this->assertSame('hidden', $field['console']);
        $this->assertSame('ollama_endpoint_url', $field['storage']);

        $this->assertNotContains(
            'ollama_endpoint',
            array_column(SettingsSchema::forSurface('console'), 'key'),
            'A server-fetched URL must not be console-writable.'
        );

        // `options` is key => label, so membership is a key check. Using
        // assertContains here asserted against the labels and would only pass
        // if the label happened to equal the slug.
        $this->assertArrayHasKey(
            'ollama',
            SettingsSchema::field('default_provider')['options'],
            'The customer must still be able to SELECT Ollama.'
        );
    }

    public function test_the_admin_write_projection_excludes_operator_scope(): void
    {
        $writable = array_column(SettingsSchema::writableFor('admin'), 'key');

        $this->assertNotContains('enable_logging', $writable);
        $this->assertNotContains('ollama_endpoint', $writable);
        $this->assertContains('default_model', $writable);
        $this->assertContains('openai_api_key', $writable);

        $this->assertContains(
            'enable_logging',
            array_column(SettingsSchema::writableFor('ops'), 'key'),
            'The operator surface is where an operator-scope field is edited.'
        );

        // And the mirror image: a tenant field on /ops would edit the default
        // estate while looking like a platform setting.
        $this->assertNotContains('default_model', array_column(SettingsSchema::writableFor('ops'), 'key'));
        $this->assertSame(
            array_column(SettingsSchema::forSurface('ops'), 'key'),
            array_column(SettingsSchema::writableFor('ops'), 'key'),
            'On /ops the rendered set and the writable set are the same set.'
        );
    }

    public function test_the_console_values_omit_hidden_fields(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        // Operator-scope keys live in the platform layer, so the fixture seeds
        // them there. Writing them through the estate store would put a value
        // in a layer the console never consults for that scope.
        app('nvoos.settings.platform')->set('enable_logging', true);

        $values = $this->getJson('/api/v1/settings/values')
            ->assertOk()
            ->json('data.values');

        $this->assertArrayNotHasKey('enable_logging', $values);
        $this->assertArrayHasKey('default_provider', $values);
    }

    public function test_the_console_index_omits_hidden_fields(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        app('nvoos.settings.platform')->set('enable_logging', true);

        $payload = $this->getJson('/api/v1/settings')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('enable_logging', $payload);
    }

    public function test_a_hidden_field_cannot_be_written_through_the_console(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $store = app(SettingsStoreInterface::class);
        app('nvoos.settings.platform')->set('enable_logging', false);

        $this->postJson('/api/v1/settings/values', [
            'enable_logging' => true,
            'default_model' => 'gpt-4.1-mini',
        ])->assertOk();

        $this->assertFalse(
            (bool) $store->get('enable_logging', false),
            'A hidden field must be ignored, not written.'
        );

        $this->assertSame(
            'gpt-4.1-mini',
            $store->get('default_model'),
            'Ignoring the hidden key must not reject the rest of the request.'
        );
    }

    public function test_the_full_catalogue_still_feeds_snapshots(): void
    {
        $this->assertContains('enable_logging', SettingsSchema::keys());
        $this->assertContains('openai_api_key', SettingsSchema::sensitiveKeys());
    }
}

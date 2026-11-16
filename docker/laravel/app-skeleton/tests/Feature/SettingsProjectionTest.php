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

    public function test_the_console_values_omit_hidden_fields(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        app(SettingsStoreInterface::class)->set('enable_logging', true);

        $values = $this->getJson('/api/v1/settings/values')
            ->assertOk()
            ->json('data.values');

        $this->assertArrayNotHasKey('enable_logging', $values);
        $this->assertArrayHasKey('default_provider', $values);
    }

    public function test_the_console_index_omits_hidden_fields(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        app(SettingsStoreInterface::class)->set('enable_logging', true);

        $payload = $this->getJson('/api/v1/settings')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('enable_logging', $payload);
    }

    public function test_a_hidden_field_cannot_be_written_through_the_console(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $store = app(SettingsStoreInterface::class);
        $store->set('enable_logging', false);

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

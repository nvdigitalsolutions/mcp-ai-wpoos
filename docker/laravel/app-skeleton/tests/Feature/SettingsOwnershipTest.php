<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\SettingsSchema;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §16 — settings ownership.
 *
 * Before this slice the settings table was install-wide and keyed only by
 * `key`, so two estates on one deployment shared `default_model` and
 * `openai_api_key`. Nothing caught it because the only writer was the console,
 * which never pins a tenant. The moment `/admin` grows a settings form, an
 * estate writing a value and another estate reading it is a cross-tenant data
 * leak (OWASP API1), not a presentation quirk.
 *
 * Four invariants are pinned here:
 *
 *  1. an estate's value is invisible to another estate;
 *  2. operator-scope settings live in the platform layer and no tenant surface
 *     can write them;
 *  3. the settings surface key and the storage key are allowed to differ, and
 *     the runtime reads the storage key;
 *  4. a sensitive value is masked even through the admin projection.
 *
 * What this file does NOT cover: the console API still resolves the default
 * estate, because nothing pins a tenant on `/api/v1`. Closing that needs a
 * tenant-selection rule for a user who belongs to several estates; it is
 * recorded as a follow-up in the plan rather than faked here.
 */
class SettingsOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_an_estate_cannot_read_another_estates_value(): void
    {
        TenantContext::set('estate-a');
        app(SettingsService::class)->write(
            ['default_model' => 'model-a'],
            SettingsService::SURFACE_ADMIN
        );

        $this->assertDatabaseHas('nvoos_tenant_settings', [
            'tenant_id' => 'estate-a',
            'key' => 'default_model',
        ]);

        TenantContext::set('estate-b');

        $observed = app(SettingsService::class)->values(SettingsService::SURFACE_ADMIN)['default_model'];

        $this->assertNotSame('model-a', $observed, 'Estate A\'s value must not leak.');
        $this->assertSame(
            (string) config('oos.default_model', 'gpt-4o-mini'),
            $observed,
            'Estate B falls back to the deployment default, not to another estate.'
        );
    }

    public function test_the_admin_surface_writes_the_estate_layer(): void
    {
        TenantContext::set('estate-a');

        $applied = app(SettingsService::class)->write(
            ['default_model' => 'tenant-model'],
            SettingsService::SURFACE_ADMIN
        );

        $this->assertSame(['default_model'], $applied);
        $this->assertDatabaseHas('nvoos_tenant_settings', [
            'tenant_id' => 'estate-a',
            'key' => 'default_model',
        ]);
        $this->assertDatabaseMissing('nvoos_settings', ['key' => 'default_model']);
    }

    public function test_an_admin_surface_cannot_write_an_operator_scope_field(): void
    {
        app('nvoos.settings.platform')->set('enable_logging', false);

        $applied = app(SettingsService::class)->write(
            ['enable_logging' => true],
            SettingsService::SURFACE_ADMIN
        );

        $this->assertSame([], $applied, 'Rendering a disabled control is not permission to accept it.');
        $this->assertFalse((bool) app('nvoos.settings.platform')->get('enable_logging'));
    }

    public function test_the_ops_surface_writes_operator_scope_to_the_platform_layer(): void
    {
        $applied = app(SettingsService::class)->write(
            ['ollama_endpoint' => 'http://ollama.internal:11434'],
            SettingsService::SURFACE_OPS
        );

        $this->assertSame(['ollama_endpoint'], $applied);
        $this->assertDatabaseHas('nvoos_settings', ['key' => 'ollama_endpoint_url']);
        $this->assertDatabaseMissing('nvoos_tenant_settings', ['key' => 'ollama_endpoint_url']);

        $this->assertSame(
            'http://ollama.internal:11434',
            app(SettingsStoreInterface::class)->getApiBaseUrl('ollama'),
            'The provider client must read the key the settings surface writes.'
        );
    }

    /**
     * `/ops` is not tenant-scoped, so a tenant field there would edit the
     * `default` estate while looking like a platform setting.
     */
    public function test_the_ops_surface_cannot_write_estate_scope(): void
    {
        $applied = app(SettingsService::class)->write(
            ['default_model' => 'platform-mistake'],
            SettingsService::SURFACE_OPS
        );

        $this->assertSame([], $applied);
        $this->assertDatabaseMissing('nvoos_tenant_settings', ['key' => 'default_model']);
        $this->assertDatabaseMissing('nvoos_settings', ['key' => 'default_model']);
    }

    public function test_the_platform_layer_is_shared_by_every_estate(): void
    {
        app('nvoos.settings.platform')->set('enable_logging', true);

        TenantContext::set('estate-a');
        $a = app(SettingsService::class)->values(SettingsService::SURFACE_ADMIN)['enable_logging'];

        TenantContext::set('estate-b');
        $b = app(SettingsService::class)->values(SettingsService::SURFACE_ADMIN)['enable_logging'];

        $this->assertTrue((bool) $a);
        $this->assertTrue((bool) $b);
    }

    public function test_the_gemini_key_is_stored_under_the_name_the_provider_reads(): void
    {
        TenantContext::set('estate-a');

        app(SettingsService::class)->write(
            ['google_api_key' => 'gemini-secret'],
            SettingsService::SURFACE_ADMIN
        );

        $this->assertDatabaseHas('nvoos_tenant_settings', [
            'tenant_id' => 'estate-a',
            'key' => 'gemini_api_key',
        ]);

        $this->assertSame(
            'gemini-secret',
            app(SettingsStoreInterface::class)->get('gemini_api_key'),
            'The surface key and the storage key differ on purpose; the runtime reads the storage key.'
        );
    }

    public function test_a_secret_is_masked_on_every_surface(): void
    {
        TenantContext::set('estate-a');
        app(SettingsService::class)->write(
            ['openai_api_key' => 'sk-live-secret'],
            SettingsService::SURFACE_ADMIN
        );

        $values = app(SettingsService::class)->values(SettingsService::SURFACE_ADMIN);

        $this->assertSame(SettingsSchema::MASKED_PLACEHOLDER, $values['openai_api_key']);
        $this->assertNotSame('sk-live-secret', $values['openai_api_key']);
    }

    public function test_an_empty_secret_means_unchanged(): void
    {
        TenantContext::set('estate-a');

        app(SettingsService::class)->write(
            ['openai_api_key' => 'sk-live-secret'],
            SettingsService::SURFACE_ADMIN
        );

        $applied = app(SettingsService::class)->write(
            ['openai_api_key' => ''],
            SettingsService::SURFACE_ADMIN
        );

        $this->assertSame([], $applied);
        $this->assertSame('sk-live-secret', app(SettingsStoreInterface::class)->get('openai_api_key'));
    }

    public function test_the_console_cannot_write_the_ollama_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/settings/values', [
            'ollama_endpoint' => 'http://attacker.example',
            'default_provider' => 'ollama',
        ])->assertOk();

        $this->assertDatabaseMissing('nvoos_settings', ['key' => 'ollama_endpoint_url']);

        $this->assertSame(
            'ollama',
            app(SettingsStoreInterface::class)->get('default_provider'),
            'Ignoring the operator-scope key must not reject the rest of the payload.'
        );
    }

    public function test_the_console_cannot_write_enable_logging(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        app('nvoos.settings.platform')->set('enable_logging', false);

        $this->postJson('/api/v1/settings/values', ['enable_logging' => true])->assertOk();

        $this->assertFalse((bool) app('nvoos.settings.platform')->get('enable_logging'));
    }

    public function test_the_console_reads_the_estate_value_the_admin_wrote(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        TenantContext::set('default');
        app(SettingsService::class)->write(
            ['default_model' => 'shared-model'],
            SettingsService::SURFACE_ADMIN
        );

        $values = $this->getJson('/api/v1/settings/values')
            ->assertOk()
            ->json('data.values');

        $this->assertSame('shared-model', $values['default_model']);
    }
}

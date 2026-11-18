<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Settings as AdminSettings;
use App\Filament\Operator\Pages\Settings as OperatorSettings;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §17 — the two settings pages.
 *
 * S-1 asked whether `/admin` should omit operator-scope fields or render them
 * disabled under a hint. The decision was "hint": omission leaves an
 * administrator unable to tell whether a setting exists, is broken, or is hidden
 * from them, and all three are worse than "NV Digital manages this".
 *
 * The tests pin the two things that make the hint safe:
 *
 *  - the field is rendered (so the page is not a half-form);
 *  - the field is not written (so the hint is not the only control).
 *
 * `/ops` is the mirror image: operator-scope fields only. That panel has no
 * estate selected, so a tenant field there would silently edit the `default`
 * estate while looking like a platform setting.
 */
class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_an_estate_member_reaches_the_settings_page(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/settings')
            ->assertOk();
    }

    public function test_a_guest_is_sent_to_the_admin_login(): void
    {
        $this->get('/admin/default/settings')->assertRedirect('/admin/login');
    }

    public function test_the_operator_scope_fields_render_with_the_hint(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/settings')
            ->assertOk()
            ->assertSee('Enable logging')
            // 149 §66: infrastructure the estate must not control still renders,
            // so the administrator can see that it exists and who owns it.
            ->assertSee('Root Security Key')
            ->assertSee('Managed by NV Digital');
    }

    public function test_saving_writes_the_estate_layer(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(AdminSettings::class)
            ->fillForm(array(
                'default_model' => 'estate-model',
                'temperature' => 0.3,
            ))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('nvoos_tenant_settings', array(
            'tenant_id' => Tenant::DEFAULT_ID,
            'key' => 'default_model',
        ));

        $this->assertDatabaseMissing('nvoos_settings', array('key' => 'default_model'));
    }

    public function test_a_wordpress_description_renders_as_plain_text(): void
    {
        $response = $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/settings')
            ->assertOk();

        // 149 §67: the catalogue keeps WordPress's HTML, the panel renders text.
        $response->assertSee('Advanced: Intercepts direct media file requests', false);
        $response->assertDontSee('&lt;strong&gt;Advanced:', false);

        // A link keeps its destination instead of being dropped with the tag.
        $response->assertSee('Hugging Face Settings (https://huggingface.co/settings/tokens)', false);

        // The tools tab's Google credential aside is emphasis, not markup.
        $response->assertSee('(Pro enables multiple connections via Remote Sites)', false);
        $response->assertDontSee('&lt;em&gt;(Pro enables multiple connections via Remote Sites)', false);
    }

    public function test_the_admin_page_cannot_write_an_operator_scope_field(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        // Bypass the disabled component on purpose: a crafted Livewire payload
        // is the threat the hint must not be the only answer to. The service
        // filters by writableFor('admin'), which excludes operator scope.
        Livewire::test(AdminSettings::class)
            ->set('data.enable_logging', true)
            ->call('save');

        $this->assertDatabaseMissing('nvoos_settings', array('key' => 'enable_logging'));
    }

    public function test_a_customer_administrator_cannot_reach_the_operator_page(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/ops/settings')
            ->assertForbidden();
    }

    public function test_an_operator_saves_platform_settings(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        TenantContext::forget();

        $this->actingAs($this->operatorMember());

        Livewire::test(OperatorSettings::class)
            ->fillForm(array(
                'ollama_endpoint' => 'http://ollama.internal:11434',
                'enable_logging' => true,
            ))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('nvoos_settings', array('key' => 'ollama_endpoint_url'));
        $this->assertDatabaseHas('nvoos_settings', array('key' => 'enable_logging'));

        // Platform settings never land in an estate layer.
        $this->assertDatabaseMissing('nvoos_tenant_settings', array('key' => 'ollama_endpoint_url'));
    }

    public function test_the_operator_page_does_not_render_estate_fields(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        TenantContext::forget();

        $this->actingAs($this->operatorMember());

        Livewire::test(OperatorSettings::class)
            ->assertFormFieldExists('ollama_endpoint')
            // 149 §66: the platform owns the security infrastructure, and the
            // estate's access policy is not a platform setting.
            ->assertFormFieldExists('root_security_key')
            ->assertFormFieldDoesNotExist('require_authentication_all')
            ->assertFormFieldDoesNotExist('default_model')
            ->assertFormFieldDoesNotExist('openai_api_key');
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    /**
     * A member of the vendor estate with `manage_options` — the Q-1 predicate.
     */
    private function operatorMember(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::OPERATOR_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }
}

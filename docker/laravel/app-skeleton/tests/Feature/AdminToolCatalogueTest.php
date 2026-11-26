<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Tools;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\ToolCatalogue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §22 — the tool catalogue.
 *
 * Two things are pinned here that matter beyond the page:
 *
 *  - `/api/v1/tools` keeps its exact payload shape. It is a published contract:
 *    the SPA's `ToolsClient` and `ToolPicker` read it, and `ToolPicker` is how an
 *    administrator gives an assistant a tool it does not have yet. A "projection"
 *    of that payload would break the product, which is why §22 corrected the
 *    matrix instead of reducing it.
 *  - the filter runs server-side, so the page reports what is actually servable
 *    rather than what the client chose to render.
 */
class AdminToolCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_the_catalogue(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/tools')
            ->assertOk()
            ->assertSee('Tool catalogue')
            ->assertSee('servable');
    }

    public function test_the_search_narrows_the_catalogue(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(Tools::class)
            ->set('search', 'generate_uuid')
            ->assertSee('generate_uuid')
            ->assertDontSee('get_site_summary');
    }

    public function test_clearing_the_filters_restores_the_catalogue(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(Tools::class)
            ->set('search', 'generate_uuid')
            ->set('readiness', 'ready')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('readiness', '');
    }

    public function test_a_readiness_with_no_members_shows_the_empty_state(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(Tools::class)
            ->set('readiness', 'quarantined')
            ->assertSee('No tool matches that filter');
    }

    public function test_the_filter_matches_slug_name_and_description(): void
    {
        $rows = array(
            array('slug' => 'alpha_tool', 'name' => 'Alpha', 'description' => 'Does alpha things', 'readiness' => 'ready'),
            array('slug' => 'beta_tool', 'name' => 'Beta', 'description' => 'Does beta things', 'readiness' => 'pure'),
        );

        $this->assertSame(array('alpha_tool'), array_column(ToolCatalogue::filter($rows, 'alpha'), 'slug'));
        $this->assertSame(array('beta_tool'), array_column(ToolCatalogue::filter($rows, 'Beta'), 'slug'));
        $this->assertSame(array('alpha_tool'), array_column(ToolCatalogue::filter($rows, 'alpha things'), 'slug'));
        $this->assertCount(2, ToolCatalogue::filter($rows));
    }

    public function test_the_filter_matches_readiness(): void
    {
        $rows = array(
            array('slug' => 'alpha_tool', 'name' => 'Alpha', 'description' => '', 'readiness' => 'ready'),
            array('slug' => 'beta_tool', 'name' => 'Beta', 'description' => '', 'readiness' => 'pure'),
        );

        $this->assertSame(array('beta_tool'), array_column(ToolCatalogue::filter($rows, '', 'pure'), 'slug'));
        $this->assertSame(array(), ToolCatalogue::filter($rows, '', 'quarantined'));
    }

    /**
     * The console payload is a client contract, not an internal shape.
     */
    public function test_the_console_payload_keeps_its_shape(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $payload = $this->getJson('/api/v1/tools')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(array(
                'data' => array(
                    'tools' => array(
                        array(
                            'slug',
                            'name',
                            'description',
                            'required_capability',
                            'readiness',
                            'annotations',
                        ),
                    ),
                    'total',
                    'servable',
                    'endpoint_contract_version',
                ),
            ))
            ->json('data');

        $this->assertSame(count($payload['tools']), $payload['total']);
        $this->assertLessThanOrEqual($payload['total'], $payload['servable']);
        $this->assertGreaterThan(0, $payload['total'], 'The registry must not be empty in the test environment.');
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}

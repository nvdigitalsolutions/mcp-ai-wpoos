<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Professions;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ProfessionCatalogue;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §23 — the profession catalogue on `/admin`.
 *
 * Three things are pinned that matter beyond this page:
 *
 *  - the console row shape is unchanged by the extraction into
 *    `App\Support\ProfessionCatalogue`. `GET /api/v1/professions` is a
 *    published contract read by the SPA's profession selector and by the team
 *    catalogue, so moving the mapper must not move the payload.
 *  - the page has no form. Professions are code-owned reference data and
 *    `save_profession` is not registered on this runtime; adding a form would
 *    silently require the contract row to change from `read-only` to `full`,
 *    and this test is what makes that a deliberate edit.
 *  - filtering is server-side, so the header cannot claim more rows than the
 *    runtime can serve.
 */
class AdminProfessionCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_the_catalogue_and_its_version(): void
    {
        $manifest = $this->generatedCatalogue();

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/professions')
            ->assertOk()
            ->assertSee('Profession catalogue')
            ->assertSee('Accountant')
            ->assertSee((string) $manifest['catalogue_version'])
            ->assertSee('Read-only');
    }

    public function test_the_search_narrows_the_catalogue(): void
    {
        $this->bootPanel();

        Livewire::test(Professions::class)
            ->set('search', 'ui_ux_designer')
            ->assertSee('UX Designer')
            ->assertDontSee('Accountant');
    }

    public function test_the_category_filter_narrows_the_catalogue(): void
    {
        $this->bootPanel();

        Livewire::test(Professions::class)
            ->set('category', 'creative')
            ->assertSee('UX Designer')
            ->assertDontSee('Accountant');
    }

    public function test_clearing_the_filters_restores_the_catalogue(): void
    {
        $this->bootPanel();

        Livewire::test(Professions::class)
            ->set('search', 'ui_ux_designer')
            ->set('category', 'creative')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('category', '');
    }

    public function test_a_category_with_no_members_shows_the_empty_state(): void
    {
        $this->bootPanel();

        Livewire::test(Professions::class)
            ->set('category', 'not_a_category')
            ->assertSee('No profession matches that filter');
    }

    /**
     * The filter matches slug, title, category and description, and an empty
     * filter is a no-op rather than a match-nothing.
     */
    public function test_the_filter_matches_every_advertised_field(): void
    {
        $rows = array(
            array(
                'slug' => 'alpha_role',
                'title' => 'Alpha Role',
                'category' => 'Creative Services',
                'category_slug' => 'creative',
                'description' => 'Does alpha things',
                'tools_count' => 2,
            ),
            array(
                'slug' => 'beta_role',
                'title' => 'Beta Role',
                'category' => 'Technical',
                'category_slug' => 'technical',
                'description' => 'Does beta things',
                'tools_count' => 1,
            ),
        );

        $this->assertSame(array('alpha_role'), array_column(ProfessionCatalogue::filter($rows, 'alpha'), 'slug'));
        $this->assertSame(array('beta_role'), array_column(ProfessionCatalogue::filter($rows, 'Beta'), 'slug'));
        $this->assertSame(array('alpha_role'), array_column(ProfessionCatalogue::filter($rows, 'creative'), 'slug'));
        $this->assertSame(array('beta_role'), array_column(ProfessionCatalogue::filter($rows, 'beta things'), 'slug'));
        $this->assertSame(array('beta_role'), array_column(ProfessionCatalogue::filter($rows, '', 'technical'), 'slug'));
        $this->assertCount(2, ProfessionCatalogue::filter($rows));
    }

    /**
     * `categories()` is derived from the rows, so the filter cannot offer a
     * category with nothing behind it. A record with no category is omitted
     * rather than offered as an unlabelled option.
     */
    public function test_categories_are_derived_from_the_rows(): void
    {
        $rows = array(
            array('category_slug' => 'technical', 'category' => 'Technical'),
            array('category_slug' => 'creative', 'category' => 'Creative Services'),
            array('category_slug' => 'technical', 'category' => 'Technical'),
            array('category_slug' => '', 'category' => ''),
        );

        $this->assertSame(
            array('creative' => 'Creative Services', 'technical' => 'Technical'),
            ProfessionCatalogue::categories($rows)
        );
    }

    /**
     * `tools` is a sum of per-profession recommendations, not a distinct count:
     * the same tool is recommended by several professions and collapsing it
     * would understate the catalogue.
     */
    public function test_totals_sum_the_recommended_tool_counts(): void
    {
        $rows = array(
            array('tools_count' => 2),
            array('tools_count' => 3),
        );

        $this->assertSame(array('total' => 2, 'tools' => 5), ProfessionCatalogue::totals($rows));
    }

    /**
     * The console payload is a client contract, not an internal shape. The
     * extraction into `ProfessionCatalogue` must be invisible to it.
     */
    public function test_the_console_payload_keeps_its_shape(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $manifest = $this->generatedCatalogue();

        $this->getJson('/api/v1/professions')
            ->assertOk()
            ->assertJsonPath('total', (int) $manifest['professions'])
            ->assertJsonPath('catalogue_version', (string) $manifest['catalogue_version'])
            ->assertJsonStructure(array(
                'professions' => array(
                    array('slug', 'title', 'category', 'category_slug', 'description', 'expertise', 'tools_count'),
                ),
            ))
            ->assertJsonMissingPath('professions.0.role_description');
    }

    /**
     * The app must serve the generated base catalogue, not the package starter.
     *
     * A regression to `nvoos/laravel`'s nine-row default would otherwise be
     * invisible: the page and the API would simply get smaller, and every
     * count here would still agree with itself.
     */
    public function test_the_app_serves_the_generated_base_catalogue(): void
    {
        $manifest = $this->generatedCatalogue();

        $this->assertGreaterThan(
            9,
            (int) $manifest['professions'],
            'The generated snapshot must be larger than the package starter set.'
        );

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/professions')
            ->assertOk()
            ->assertJsonPath('total', (int) $manifest['professions']);
    }

    /**
     * A page that cannot accept a payload is a page that cannot grow a second
     * writer by accident. Adding form state here means the contract row has to
     * move from `read-only` to something that declares a writer.
     */
    public function test_the_page_accepts_no_payload(): void
    {
        $traits = class_uses_recursive(Professions::class);

        $this->assertArrayNotHasKey(\Filament\Forms\Concerns\InteractsWithForms::class, $traits);
        $this->assertFalse(
            is_subclass_of(Professions::class, \Filament\Forms\Contracts\HasForms::class),
            'The profession catalogue is read-only; a form would need the contract row to declare a writer.'
        );
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/professions')
            ->assertForbidden();
    }

    public function test_an_estateless_admin_is_refused_the_page(): void
    {
        $admin = User::factory()->admin()->withoutEstate()->create();

        $this->actingAs($admin)
            ->get('/admin/default/professions')
            ->assertForbidden();
    }

    /**
     * The manifest of the generated snapshot shipped with the app.
     *
     * Tests derive counts and versions from it rather than pinning literals:
     * the numbers change whenever the WordPress reference data changes, and a
     * pinned literal would need editing on every such change.
     *
     * @return array<string, mixed>
     */
    private function generatedCatalogue(): array
    {
        $path = resource_path('professions/manifest.json');

        $this->assertFileExists($path, 'The profession snapshot is missing; run php bin/sync-professions.php.');

        $manifest = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('catalogue_version', $manifest);
        $this->assertArrayHasKey('professions', $manifest);

        return $manifest;
    }

    private function bootPanel(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}

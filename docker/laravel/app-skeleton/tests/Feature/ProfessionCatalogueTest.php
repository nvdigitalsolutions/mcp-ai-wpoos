<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-g Phase 4 — the profession catalogue endpoint.
 *
 * The console's assistant editor reads this to offer the max-three primary
 * professions, so the shape has to match the team-catalogue member shape.
 */
class ProfessionCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_index_lists_the_whole_catalogue_by_title(): void
    {
        $manifest = json_decode((string) file_get_contents(resource_path('professions/manifest.json')), true);

        $response = $this->getJson('/api/v1/professions')->assertOk();

        $response
            ->assertJsonPath('total', (int) $manifest['professions'])
            ->assertJsonStructure([
                'professions' => [
                    ['slug', 'title', 'category', 'category_slug', 'description', 'expertise', 'tools_count'],
                ],
            ]);

        // The shipped base version with the estate overlay's fingerprint
        // appended when an estate has written one (149 §170); empty in a fresh
        // test app, so this is the manifest's own version at the prefix.
        $this->assertStringStartsWith(
            (string) $manifest['catalogue_version'],
            (string) $response->json('catalogue_version')
        );

        $rows   = $response->json('professions');
        $titles = array_column($rows, 'title');
        $sorted = $titles;

        // The catalogue's order is `strcasecmp` on the titles (see
        // ProfessionRepository::titles()), so assert that comparator rather
        // than an approximation of it.
        usort($sorted, 'strcasecmp');

        $this->assertSame($sorted, $titles, 'The catalogue is ordered by title.');

        // `ui_ux_designer` exists in the WordPress base catalogue; the
        // package starter used `ux_designer`, so this also proves the app is
        // serving the generated snapshot rather than the starter.
        $slugs = array_column($rows, 'slug');

        $this->assertContains('accountant', $slugs);
        $this->assertContains('ui_ux_designer', $slugs);
    }

    public function test_index_maps_the_category_label(): void
    {
        $response = $this->getJson('/api/v1/professions')->assertOk();

        $bySlug = collect($response->json('professions'))->keyBy('slug');

        $this->assertSame('Creative Services', $bySlug['ui_ux_designer']['category']);
        $this->assertSame('creative', $bySlug['ui_ux_designer']['category_slug']);
        $this->assertSame(8, $bySlug['ui_ux_designer']['tools_count']);
    }

    public function test_show_returns_the_full_record(): void
    {
        $this->getJson('/api/v1/professions/ui_ux_designer')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'ui_ux_designer')
            ->assertJsonPath('data.title', 'UI/UX Designer')
            ->assertJsonPath('data.category', 'Creative Services')
            ->assertJsonStructure([
                'data' => ['role_description', 'warnings', 'knowledge_base', 'recommended_tools'],
            ]);
    }

    public function test_an_unknown_slug_is_404(): void
    {
        $this->getJson('/api/v1/professions/not_a_role')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_the_catalogue_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/professions')->assertUnauthorized();
    }
}

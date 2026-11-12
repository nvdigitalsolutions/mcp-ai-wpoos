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

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_index_lists_the_whole_catalogue_by_title(): void
    {
        $this->getJson('/api/v1/professions')
            ->assertOk()
            ->assertJsonPath('total', 9)
            ->assertJsonPath('catalogue_version', '1.0.0')
            ->assertJsonPath('professions.0.slug', 'accountant')
            ->assertJsonPath('professions.0.title', 'Accountant')
            ->assertJsonStructure([
                'professions' => [
                    ['slug', 'title', 'category', 'category_slug', 'description', 'expertise', 'tools_count'],
                ],
            ]);
    }

    public function test_index_maps_the_category_label(): void
    {
        $response = $this->getJson('/api/v1/professions')->assertOk();

        $bySlug = collect($response->json('professions'))->keyBy('slug');

        $this->assertSame('Creative Services', $bySlug['ux_designer']['category']);
        $this->assertSame('creative', $bySlug['ux_designer']['category_slug']);
        $this->assertSame(3, $bySlug['ux_designer']['tools_count']);
    }

    public function test_show_returns_the_full_record(): void
    {
        $this->getJson('/api/v1/professions/ux_designer')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.slug', 'ux_designer')
            ->assertJsonPath('data.title', 'UX Designer')
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

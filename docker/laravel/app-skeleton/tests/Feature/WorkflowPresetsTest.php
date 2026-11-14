<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\WorkflowPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-h H1 — workflow preset catalogue.
 *
 * Generates the contract the console's `WorkflowPresetsClient` reads: an
 * unwrapped `{ ok, categories, presets }` list and an `{ ok, preset }` install.
 */
class WorkflowPresetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowPresets::flush();
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_the_shipped_catalogue_loads(): void
    {
        $presets = WorkflowPresets::all();

        $this->assertNotSame([], $presets, 'resources/workflow-presets.json must ship with the app.');
        $this->assertNotSame([], WorkflowPresets::categories());

        foreach ($presets as $id => $preset) {
            $this->assertArrayHasKey('name', $preset, $id);
            $this->assertArrayHasKey('category', $preset, $id);
            $this->assertArrayHasKey('nodes', $preset, $id);
            $this->assertArrayHasKey('edges', $preset, $id);
        }
    }

    public function test_index_lists_categories_and_strips_graphs(): void
    {
        $this->actAsAdmin();

        $body = $this->getJson('/api/v1/pro/workflow-presets')->assertOk()->json();

        $this->assertTrue($body['ok']);
        $this->assertNotSame([], $body['categories']);
        $this->assertCount(count(WorkflowPresets::all()), $body['presets']);

        foreach ($body['presets'] as $summary) {
            $this->assertSame(
                ['id', 'name', 'description', 'category', 'icon', 'tags'],
                array_keys($summary)
            );
        }
    }

    public function test_every_preset_category_has_a_label(): void
    {
        $labels = WorkflowPresets::categories();

        foreach (WorkflowPresets::all() as $id => $preset) {
            $this->assertArrayHasKey(
                (string) $preset['category'],
                $labels,
                "Preset {$id} uses a category with no label."
            );
        }
    }

    public function test_install_returns_the_full_graph(): void
    {
        $this->actAsAdmin();

        $id = array_key_first(WorkflowPresets::all());
        $expected = WorkflowPresets::find($id);

        $body = $this->postJson("/api/v1/pro/workflow-presets/{$id}/install")->assertOk()->json();

        $this->assertTrue($body['ok']);
        $this->assertSame($id, $body['preset']['id']);
        $this->assertSame($expected['nodes'], $body['preset']['nodes']);
        $this->assertSame($expected['edges'], $body['preset']['edges']);
    }

    public function test_install_of_an_unknown_preset_is_404_and_fails_closed(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/workflow-presets/no-such-preset/install')
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'invalid_preset');
    }

    public function test_an_editor_cannot_reach_the_preset_catalogue(): void
    {
        Sanctum::actingAs(User::factory()->editor()->create());

        $this->getJson('/api/v1/pro/workflow-presets')->assertForbidden();
    }
}

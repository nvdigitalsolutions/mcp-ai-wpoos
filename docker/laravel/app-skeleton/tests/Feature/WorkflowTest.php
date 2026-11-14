<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-h H2 — workflow graph storage and CRUD.
 *
 * The payloads here are copied from what the console sends and reads
 * (`spa-v2/src/api/workflow-builder.ts`), so a shape change on either side
 * breaks this suite rather than the page.
 */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private const GRAPH = [
        'nodes' => [
            ['id' => 'node_1', 'type' => 'input', 'data' => ['label' => 'Start']],
            ['id' => 'node_2', 'type' => 'tool', 'data' => ['label' => 'Fetch', 'toolSlug' => 'list_students']],
        ],
        'edges' => [
            ['id' => 'edge_1', 'source' => 'node_1', 'target' => 'node_2'],
        ],
    ];

    private function actAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_is_empty_before_any_workflow_exists(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/v1/orchestration/workflows')
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_create_then_list_returns_a_bare_summary_array(): void
    {
        $this->actAsAdmin();

        $created = $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Onboarding',
            'graph' => self::GRAPH,
            'tags' => ['ops', 'onboarding'],
        ])->assertOk()->json();

        $this->assertIsInt($created['id']);

        $list = $this->getJson('/api/v1/orchestration/workflows')->assertOk()->json();

        $this->assertCount(1, $list);
        $this->assertSame([
            'id' => $created['id'],
            'title' => 'Onboarding',
            'version' => '1.0.0',
            'tags' => ['ops', 'onboarding'],
        ], array_intersect_key($list[0], array_flip(['id', 'title', 'version', 'tags'])));
    }

    public function test_show_round_trips_the_graph_byte_identically(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Round trip',
            'graph' => self::GRAPH,
        ])->json('id');

        $detail = $this->getJson("/api/v1/orchestration/workflows/{$id}")->assertOk()->json();

        $this->assertSame(self::GRAPH, $detail['graph']);
        $this->assertSame('Round trip', $detail['title']);
        $this->assertArrayHasKey('modified', $detail);
    }

    public function test_update_saves_the_graph_and_bumps_the_patch_version(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Versioned',
            'graph' => self::GRAPH,
        ])->json('id');

        $graph = self::GRAPH;
        $graph['nodes'][] = ['id' => 'node_3', 'type' => 'agent', 'data' => ['label' => 'Summarise']];

        $this->putJson("/api/v1/orchestration/workflows/{$id}", ['graph' => $graph])
            ->assertOk()
            ->assertJsonPath('version', '1.0.1');

        $detail = $this->getJson("/api/v1/orchestration/workflows/{$id}")->assertOk()->json();

        $this->assertSame($graph, $detail['graph']);

        $this->putJson("/api/v1/orchestration/workflows/{$id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('version', '1.0.2');

        $this->assertSame('Renamed', $this->getJson("/api/v1/orchestration/workflows/{$id}")->json('title'));
    }

    public function test_delete_trashes_rather_than_destroys(): void
    {
        $this->actAsAdmin();

        $id = $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Trash me',
            'graph' => self::GRAPH,
        ])->json('id');

        $this->deleteJson("/api/v1/orchestration/workflows/{$id}")
            ->assertOk()
            ->assertJsonPath('message', 'Workflow trashed.');

        $this->assertSame([], $this->getJson('/api/v1/orchestration/workflows')->json());

        // WordPress's get_item resolves a trashed post too, so the record and
        // its graph stay readable rather than vanishing.
        $this->getJson("/api/v1/orchestration/workflows/{$id}")->assertOk();
    }

    public function test_unknown_workflow_is_404_with_the_wordpress_envelope(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/v1/orchestration/workflows/999999')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found')
            ->assertJsonPath('message', 'Workflow not found.');
    }

    public function test_create_requires_a_name(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/orchestration/workflows', ['graph' => self::GRAPH])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_data');
    }

    public function test_duplicate_node_ids_are_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Duplicates',
            'graph' => [
                'nodes' => [
                    ['id' => 'node_1'],
                    ['id' => 'node_1'],
                ],
                'edges' => [],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_graph')
            ->assertJsonPath('message', 'Duplicate node id "node_1".');
    }

    public function test_dangling_edges_are_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Dangling',
            'graph' => [
                'nodes' => [['id' => 'node_1']],
                'edges' => [['source' => 'node_1', 'target' => 'node_missing']],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_graph')
            ->assertJsonPath('message', 'Edge #1 references a missing target node.');
    }

    public function test_cycles_are_rejected_at_save_time_with_the_offending_edge(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Cyclic',
            'graph' => [
                'nodes' => [['id' => 'a'], ['id' => 'b']],
                'edges' => [
                    ['source' => 'a', 'target' => 'b'],
                    ['source' => 'b', 'target' => 'a'],
                ],
            ],
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_graph');

        $this->assertStringContainsString('cycle', $response->json('message'));
    }

    public function test_an_editor_cannot_reach_the_workflow_surface(): void
    {
        Sanctum::actingAs(User::factory()->editor()->create());

        $this->getJson('/api/v1/orchestration/workflows')->assertForbidden();
    }
}

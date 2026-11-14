<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-h H3 — execution, run history and the per-node runner.
 *
 * Tool nodes are exercised against the real registry (`generate_uuid` needs no
 * arguments), so this covers the registry's capability gate and canonical
 * envelope as well as the executor.
 */
class WorkflowRunTest extends TestCase
{
    use RefreshDatabase;

    private function actAsAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * Create a workflow and return its id.
     *
     * @param array<string, mixed> $graph
     */
    private function workflow(array $graph): int
    {
        return (int) $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Executable',
            'graph' => $graph,
        ])->json('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function twoNodeGraph(): array
    {
        return [
            'nodes' => [
                [
                    'id' => 'node_1',
                    'type' => 'tool',
                    'data' => ['toolSlug' => 'generate_uuid', 'arguments' => []],
                ],
                [
                    'id' => 'node_2',
                    'type' => 'condition',
                    'data' => ['expression' => 'node_1.data.count === 1'],
                ],
            ],
            'edges' => [['source' => 'node_1', 'target' => 'node_2']],
        ];
    }

    public function test_executing_a_two_node_graph_records_a_completed_run(): void
    {
        $this->actAsAdmin();
        $id = $this->workflow($this->twoNodeGraph());

        $body = $this->postJson("/api/v1/orchestration/workflows/{$id}/execute")
            ->assertOk()
            ->json();

        $this->assertTrue($body['success']);
        $this->assertSame('completed', $body['status']);
        $this->assertSame(1, $body['tool_calls']);

        // 090-i — the run writes one command-centre activity event. Recorded in
        // WorkflowRunner so a triggered run produces the same entry.
        $this->assertDatabaseHas('nvoos_activity_events', array(
            'type' => 'workflow.completed',
        ));
        $this->assertSame('completed', $body['results']['node_1']['status']);
        $this->assertSame('completed', $body['results']['node_2']['status']);

        $run = DB::table('nvoos_workflow_runs')->find($body['run_id']);

        $this->assertNotNull($run);
        $this->assertSame('completed', $run->status);
        $this->assertSame($id, (int) $run->workflow_id);
        $this->assertSame(1, (int) $run->tool_calls);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration);
    }

    public function test_a_missing_tool_fails_the_run_and_records_the_node_error(): void
    {
        $this->actAsAdmin();

        $id = $this->workflow([
            'nodes' => [
                ['id' => 'node_1', 'type' => 'tool', 'data' => ['toolSlug' => 'no_such_tool']],
                ['id' => 'node_2', 'type' => 'output'],
            ],
            'edges' => [['source' => 'node_1', 'target' => 'node_2']],
        ]);

        $body = $this->postJson("/api/v1/orchestration/workflows/{$id}/execute")
            ->assertStatus(500)
            ->json();

        $this->assertFalse($body['success']);
        $this->assertSame('failed', $body['status']);
        $this->assertSame('failed', $body['results']['node_1']['status']);
        $this->assertSame('skipped', $body['results']['node_2']['status']);
        $this->assertStringContainsString('no_such_tool', (string) $body['message']);

        $run = DB::table('nvoos_workflow_runs')->find($body['run_id']);

        $this->assertSame('failed', $run->status);
        $this->assertNotEmpty($run->error);
    }

    public function test_the_run_list_is_a_bare_array_and_filters_by_workflow(): void
    {
        $this->actAsAdmin();

        $first = $this->workflow($this->twoNodeGraph());
        $second = $this->workflow($this->twoNodeGraph());

        $this->postJson("/api/v1/orchestration/workflows/{$first}/execute")->assertOk();
        $this->postJson("/api/v1/orchestration/workflows/{$second}/execute")->assertOk();

        $all = $this->getJson('/api/v1/orchestration/runs')->assertOk()->json();
        $this->assertCount(2, $all);
        $this->assertSame(
            [
                'id',
                'workflow_id',
                'status',
                'source',
                'trigger_id',
                'tool_calls',
                'started_at',
                'finished_at',
                'duration',
            ],
            array_keys($all[0])
        );

        $filtered = $this->getJson("/api/v1/orchestration/runs?workflow_id={$first}")->assertOk()->json();
        $this->assertCount(1, $filtered);
        $this->assertSame($first, $filtered[0]['workflow_id']);
    }

    public function test_a_run_detail_carries_the_per_node_events(): void
    {
        $this->actAsAdmin();
        $id = $this->workflow($this->twoNodeGraph());

        $runId = $this->postJson("/api/v1/orchestration/workflows/{$id}/execute")->json('run_id');

        $detail = $this->getJson("/api/v1/orchestration/runs/{$runId}")->assertOk()->json();

        $this->assertSame($runId, $detail['id']);
        $this->assertArrayHasKey('node_1', $detail['events']);
        $this->assertArrayHasKey('node_2', $detail['events']);
        $this->assertSame(1, $detail['events']['node_1']['output']['data']['count']);
    }

    public function test_an_unknown_run_is_404(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/v1/orchestration/runs/999999')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_executing_an_unknown_workflow_is_404(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/orchestration/workflows/999999/execute')
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    public function test_a_single_tool_node_runs_through_the_registry(): void
    {
        $this->actAsAdmin();

        $body = $this->postJson('/api/v1/pro/workflow-builder/nodes/execute', [
            'node_type' => 'tool',
            'node_id' => 'node_7',
            'workflow_id' => '1',
            'tool_name' => 'generate_uuid',
            'tool_arguments' => [],
        ])->assertOk()->json();

        $this->assertTrue($body['success']);
        $this->assertSame('node_7', $body['node_id']);
        $this->assertCount(1, $body['output']['data']['uuids']);
    }

    public function test_a_single_unknown_tool_node_is_400(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/workflow-builder/nodes/execute', [
            'node_type' => 'tool',
            'node_id' => 'node_7',
            'tool_name' => 'no_such_tool',
        ])->assertStatus(400)->assertJsonPath('success', false);
    }

    public function test_action_nodes_are_501_on_this_runtime(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/workflow-builder/nodes/execute', [
            'node_type' => 'action',
            'node_id' => 'node_7',
            'command' => 'publish',
        ])->assertStatus(501)->assertJsonPath('code', 'not_implemented');
    }

    public function test_an_agent_node_without_a_prompt_is_422(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/workflow-builder/nodes/execute', [
            'node_type' => 'agent',
            'node_id' => 'node_7',
            'prompt' => '',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_node');
    }

    public function test_an_unknown_node_type_is_422(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/workflow-builder/nodes/execute', [
            'node_type' => 'telepathy',
            'node_id' => 'node_7',
        ])->assertStatus(422)->assertJsonPath('code', 'invalid_node');
    }

    public function test_an_editor_cannot_execute(): void
    {
        Sanctum::actingAs(User::factory()->editor()->create());

        $this->getJson('/api/v1/orchestration/runs')->assertForbidden();
        $this->postJson('/api/v1/pro/workflow-builder/nodes/execute', ['node_id' => 'x'])
            ->assertForbidden();
    }
}

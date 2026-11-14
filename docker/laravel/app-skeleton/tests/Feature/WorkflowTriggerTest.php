<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-h — `trigger → execute`.
 *
 * Covers both halves: the manual fire endpoint and the `cron_schedule`
 * evaluation behind `nvoos:fire-triggers`. Time is frozen with Carbon so a
 * "fires once per slot" assertion does not depend on the clock.
 */
class WorkflowTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
        Carbon::setTestNow('2026-11-14 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function workflow(): int
    {
        return (int) $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Triggered',
            'graph' => [
                'nodes' => [
                    ['id' => 'node_1', 'type' => 'tool', 'data' => ['toolSlug' => 'generate_uuid']],
                ],
                'edges' => [],
            ],
        ])->json('id');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function trigger(array $overrides = []): int
    {
        return (int) $this->postJson('/api/v1/triggers', array_merge([
            'name' => 'Nightly',
            'type' => 'cron_schedule',
            'config' => ['schedule' => 'every_minute'],
            'workflow_id' => $this->workflow(),
            'enabled' => true,
        ], $overrides))->json('id');
    }

    public function test_firing_a_trigger_runs_its_workflow_and_records_the_source(): void
    {
        $triggerId = $this->trigger();

        $body = $this->postJson("/api/v1/orchestration/triggers/{$triggerId}/fire")
            ->assertOk()
            ->json();

        $this->assertTrue($body['success']);
        $this->assertSame('completed', $body['status']);

        $run = DB::table('nvoos_workflow_runs')->find($body['run_id']);

        $this->assertSame('trigger', $run->source);
        $this->assertSame($triggerId, (int) $run->trigger_id);
        $this->assertNotNull(DB::table('nvoos_triggers')->find($triggerId)->last_fired_at);
    }

    public function test_the_payload_becomes_the_run_input(): void
    {
        $workflowId = (int) $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Reads input',
            'graph' => [
                'nodes' => [
                    ['id' => 'node_1', 'type' => 'condition', 'data' => ['expression' => 'input.count > 0']],
                ],
                'edges' => [],
            ],
        ])->json('id');

        $triggerId = $this->trigger(['workflow_id' => $workflowId]);

        $body = $this->postJson(
            "/api/v1/orchestration/triggers/{$triggerId}/fire",
            ['input' => ['count' => 3]]
        )->assertOk()->json();

        $this->assertTrue($body['results']['node_1']['output']);

        $detail = $this->getJson("/api/v1/orchestration/runs/{$body['run_id']}")->json();

        $this->assertSame(['count' => 3], $detail['input']);
        $this->assertSame('trigger', $detail['source']);
        $this->assertSame($triggerId, $detail['trigger_id']);
    }

    public function test_a_disabled_trigger_is_refused(): void
    {
        $triggerId = $this->trigger(['enabled' => false]);

        $this->postJson("/api/v1/orchestration/triggers/{$triggerId}/fire")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'trigger_disabled');

        $this->assertSame(0, DB::table('nvoos_workflow_runs')->count());
    }

    public function test_a_trigger_without_a_workflow_is_refused(): void
    {
        $triggerId = $this->trigger(['workflow_id' => null]);

        $this->postJson("/api/v1/orchestration/triggers/{$triggerId}/fire")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'trigger_without_workflow');
    }

    public function test_a_trigger_pointing_at_a_trashed_workflow_is_404(): void
    {
        $workflowId = $this->workflow();
        $triggerId = $this->trigger(['workflow_id' => $workflowId]);

        $this->deleteJson("/api/v1/orchestration/workflows/{$workflowId}")->assertOk();

        // A trashed workflow is still readable (WordPress parity), so the
        // trigger still fires it. Remove it for real to exercise the 404.
        DB::table('nvoos_posts')->where('id', $workflowId)->delete();

        $this->postJson("/api/v1/orchestration/triggers/{$triggerId}/fire")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'workflow_not_found');
    }

    public function test_an_unknown_trigger_is_404(): void
    {
        $this->postJson('/api/v1/orchestration/triggers/999999/fire')->assertNotFound();
    }

    public function test_the_command_fires_a_due_trigger_once_per_slot(): void
    {
        $triggerId = $this->trigger();

        $this->artisan('nvoos:fire-triggers')->assertSuccessful();

        $this->assertSame(1, DB::table('nvoos_workflow_runs')->count());

        // Same minute, same slot: nothing new.
        $this->artisan('nvoos:fire-triggers')->assertSuccessful();

        $this->assertSame(1, DB::table('nvoos_workflow_runs')->count());

        // Next slot.
        Carbon::setTestNow('2026-11-14 12:01:00');
        $this->artisan('nvoos:fire-triggers')->assertSuccessful();

        $this->assertSame(2, DB::table('nvoos_workflow_runs')->count());

        $runs = DB::table('nvoos_workflow_runs')->pluck('trigger_id')->all();
        $this->assertSame([$triggerId, $triggerId], array_map('intval', $runs));
    }

    public function test_the_command_skips_a_trigger_that_is_not_due(): void
    {
        $this->trigger([
            'config' => ['schedule' => 'daily'],
        ]);

        // Fired at noon; the daily slot was midnight, so it is not due again.
        $this->artisan('nvoos:fire-triggers')->assertSuccessful();
        $this->artisan('nvoos:fire-triggers')->assertSuccessful();

        $this->assertSame(1, DB::table('nvoos_workflow_runs')->count());
    }

    public function test_the_command_skips_a_disabled_trigger(): void
    {
        $this->trigger(['enabled' => false]);

        $this->artisan('nvoos:fire-triggers')->assertSuccessful();

        $this->assertSame(0, DB::table('nvoos_workflow_runs')->count());
    }

    public function test_the_command_accepts_a_cron_expression_and_a_minute_interval(): void
    {
        $this->trigger(['config' => ['cron' => '*/5 * * * *']]);
        $this->trigger(['config' => ['every_minutes' => 1]]);

        $this->artisan('nvoos:fire-triggers')->assertSuccessful();

        $this->assertSame(2, DB::table('nvoos_workflow_runs')->count());
    }

    public function test_an_unparseable_schedule_is_never_due(): void
    {
        $this->trigger(['config' => ['schedule' => 'whenever-i-feel-like-it']]);

        $this->artisan('nvoos:fire-triggers')
            ->expectsOutputToContain('No triggers are due.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('nvoos_workflow_runs')->count());
    }

    public function test_dry_run_lists_without_firing(): void
    {
        $this->trigger();

        $this->artisan('nvoos:fire-triggers', ['--dry-run' => true])
            ->expectsOutputToContain('would fire')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('nvoos_workflow_runs')->count());
    }

    public function test_an_editor_cannot_fire_a_trigger(): void
    {
        $triggerId = $this->trigger();

        Sanctum::actingAs(User::factory()->editor()->create());

        $this->postJson("/api/v1/orchestration/triggers/{$triggerId}/fire")->assertForbidden();
    }
}

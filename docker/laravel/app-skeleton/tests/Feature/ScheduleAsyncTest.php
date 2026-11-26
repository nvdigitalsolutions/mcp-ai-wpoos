<?php

namespace Tests\Feature;

use App\Jobs\RunSchedule;
use App\Models\User;
use App\Support\Schedules\ScheduleRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The asynchronous half of the manual trigger (090-b).
 *
 * `POST /schedules/{id}/trigger` must acknowledge the run, never execute it:
 * a handler that calls a provider outlives the proxy's read timeout, so the
 * request answers 202 + run id and the console polls
 * `GET /schedules/{id}/runs/{runId}` for the outcome.
 *
 * `Queue::fake()` is what makes that testable at all — `phpunit.xml` forces
 * QUEUE_CONNECTION=sync, so an unfaked dispatch executes inside the request
 * and the "queued, not executed" property would be invisible.
 */
class ScheduleAsyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function schedule(array $overrides = []): string
    {
        $id = $overrides['id'] ?? 'nightly-report';
        $now = now();

        DB::table('nvoos_schedules')->insert(array_merge([
            'id' => $id,
            'name' => 'Nightly report',
            'schedule_type' => 'cron',
            'enabled' => true,
            'next_run_at' => null,
            'last_run_at' => null,
            'priority' => 10,
            'tags' => json_encode([]),
            'run_count' => 0,
            'handler' => 'list_projects',
            'payload' => json_encode([]),
            'cron_expression' => '0 0 * * *',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return (string) $id;
    }

    private function runRow(int $id): ?object
    {
        return DB::table('nvoos_schedule_runs')->find($id);
    }

    /**
     * Open a run through the runner, the way the trigger does.
     */
    private function beginRun(string $scheduleId): int
    {
        return app(ScheduleRunner::class)->begin(DB::table('nvoos_schedules')->find($scheduleId));
    }

    // ------------------------------------------------------- the queued contract

    public function test_trigger_queues_the_run_without_executing_it(): void
    {
        Queue::fake();
        $id = $this->schedule();

        $response = $this->postJson("/api/v1/pro/schedules/{$id}/trigger")
            ->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('queued', true)
            ->assertHeader('Retry-After');

        Queue::assertPushed(RunSchedule::class);

        $run = $this->runRow((int) $response->json('run_id'));

        $this->assertNotNull($run, 'The acknowledgement must be backed by a run row.');
        $this->assertSame('pending', $run->status);
        $this->assertNull($run->finished_at);
        $this->assertSame(0, (int) DB::table('nvoos_schedules')->where('id', $id)->value('run_count'));
    }

    public function test_the_job_executes_the_opened_run_in_place(): void
    {
        Queue::fake();
        $id = $this->schedule();
        $runId = (int) $this->postJson("/api/v1/pro/schedules/{$id}/trigger")->json('run_id');

        (new RunSchedule($id, $runId))->handle(app(ScheduleRunner::class));

        $run = $this->runRow($runId);

        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->error);
        $this->assertSame(1, (int) DB::table('nvoos_schedules')->where('id', $id)->value('run_count'));
    }

    public function test_the_job_abandons_a_run_whose_schedule_vanished(): void
    {
        $id = $this->schedule();
        $runId = $this->beginRun($id);

        DB::table('nvoos_schedules')->where('id', $id)->delete();

        (new RunSchedule($id, $runId))->handle(app(ScheduleRunner::class));

        $run = $this->runRow($runId);

        $this->assertSame('failure', $run->status);
        $this->assertStringContainsString('no longer exists', (string) $run->error);
    }

    // ------------------------------------------------------------------- guards

    public function test_a_second_trigger_is_refused_while_one_is_in_flight(): void
    {
        Queue::fake();
        $id = $this->schedule();

        $this->postJson("/api/v1/pro/schedules/{$id}/trigger")->assertStatus(202);

        $this->postJson("/api/v1/pro/schedules/{$id}/trigger")
            ->assertStatus(409)
            ->assertJsonPath('code', 'schedule_already_running');

        $this->assertSame(1, DB::table('nvoos_schedule_runs')->count());
    }

    public function test_a_disabled_schedule_is_refused(): void
    {
        Queue::fake();
        $id = $this->schedule(['enabled' => false]);

        $this->postJson("/api/v1/pro/schedules/{$id}/trigger")
            ->assertStatus(409)
            ->assertJsonPath('code', 'schedule_disabled');

        $this->assertSame(0, DB::table('nvoos_schedule_runs')->count());
    }

    public function test_an_unknown_schedule_is_a_404(): void
    {
        $this->postJson('/api/v1/pro/schedules/nope/trigger')->assertStatus(404);
    }

    // ------------------------------------------------------------- status route

    public function test_run_status_reports_the_lifecycle(): void
    {
        Queue::fake();
        $id = $this->schedule();
        $runId = (int) $this->postJson("/api/v1/pro/schedules/{$id}/trigger")->json('run_id');

        $this->getJson("/api/v1/pro/schedules/{$id}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('finished', false);

        (new RunSchedule($id, $runId))->handle(app(ScheduleRunner::class));

        $this->getJson("/api/v1/pro/schedules/{$id}/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('finished', true)
            ->assertJsonPath('error', null);
    }

    public function test_run_status_is_a_404_for_an_unknown_run(): void
    {
        $id = $this->schedule();

        $this->getJson("/api/v1/pro/schedules/{$id}/runs/999")
            ->assertStatus(404)
            ->assertJsonPath('code', 'run_not_found');
    }

    /**
     * A run id belonging to another schedule must not be readable through this one.
     */
    public function test_run_status_is_scoped_to_its_schedule(): void
    {
        $first = $this->schedule(['id' => 'first']);
        $second = $this->schedule(['id' => 'second']);
        $runId = $this->beginRun($first);

        $this->getJson("/api/v1/pro/schedules/{$second}/runs/{$runId}")
            ->assertStatus(404)
            ->assertJsonPath('code', 'run_not_found');
    }

    // ------------------------------------------------------------------- reaper

    public function test_the_reaper_marks_stale_runs_timeout_and_advances_the_clock(): void
    {
        $id = $this->schedule();
        $runId = $this->beginRun($id);

        DB::table('nvoos_schedule_runs')->where('id', $runId)
            ->update(['started_at' => Carbon::now()->subHour()]);

        $reaped = app(ScheduleRunner::class)->reapStale();

        $this->assertSame(1, $reaped);

        $run = $this->runRow($runId);

        $this->assertSame('timeout', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('did not report back', (string) $run->error);

        // The clock moved, or the schedule would stay permanently due.
        $this->assertNotNull(DB::table('nvoos_schedules')->where('id', $id)->value('next_run_at'));
    }

    public function test_the_reaper_leaves_a_fresh_run_alone(): void
    {
        $id = $this->schedule();
        $runId = $this->beginRun($id);

        $this->assertSame(0, app(ScheduleRunner::class)->reapStale());
        $this->assertSame('pending', $this->runRow($runId)->status);
    }

    public function test_the_tick_reaps_before_running(): void
    {
        // Not due, so the only thing the tick has to do is reap.
        $id = $this->schedule(['next_run_at' => Carbon::now()->addHour()]);
        $runId = $this->beginRun($id);

        DB::table('nvoos_schedule_runs')->where('id', $runId)
            ->update(['started_at' => Carbon::now()->subHour()]);

        $this->artisan('nvoos:run-schedules')->assertExitCode(0);

        $this->assertSame('timeout', $this->runRow($runId)->status);
    }

    /**
     * A reaped run is a failed outcome, not an in-flight one: it must not sit
     * in the "still running" bucket, and it must not depress the rate twice.
     */
    public function test_fleet_stats_count_a_timeout_as_a_failure_and_ignore_in_flight(): void
    {
        Queue::fake();
        $id = $this->schedule();
        $staleRun = $this->beginRun($id);

        DB::table('nvoos_schedule_runs')->where('id', $staleRun)
            ->update(['started_at' => Carbon::now()->subHour()]);

        app(ScheduleRunner::class)->reapStale();

        // A second, live run stays in flight and must not be counted.
        $this->beginRun($id);

        $this->getJson('/api/v1/pro/schedules/fleet-stats')
            ->assertOk()
            ->assertJsonPath('stats.total_runs', 1)
            ->assertJsonPath('stats.timeout_runs', 1)
            ->assertJsonPath('stats.failure_runs', 1)
            ->assertJsonPath('stats.success_rate', 0);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Schedules\ScheduleRunner;
use App\Support\Schedules\ScheduleTiming;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The schedule tick (090-b §14).
 *
 * Before this, `nvoos_schedules` was a list nothing ever executed: presets
 * installed and sat idle. These tests pin the two properties that make the
 * surface real — a schedule is timed before its first run, and a due
 * schedule executes once per occurrence with a recorded outcome.
 */
class ScheduleRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

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

    public function test_bootstrap_times_untimed_schedules_without_running_them(): void
    {
        $id = $this->schedule();

        $timed = app(ScheduleRunner::class)->bootstrap(Carbon::parse('2026-11-14 10:00:00'));

        $this->assertSame(1, $timed);
        $this->assertNotNull(DB::table('nvoos_schedules')->where('id', $id)->value('next_run_at'));
        $this->assertSame(0, DB::table('nvoos_schedule_runs')->count());

        // The first occurrence is in the future — creating is not firing.
        $this->assertSame([], app(ScheduleRunner::class)->due(Carbon::parse('2026-11-14 10:00:00')));
    }

    public function test_a_due_schedule_runs_once_and_advances_its_clock(): void
    {
        $id = $this->schedule(['next_run_at' => Carbon::parse('2026-11-14 09:00:00')]);
        $now = Carbon::parse('2026-11-14 10:00:00');

        $due = app(ScheduleRunner::class)->due($now);
        $this->assertCount(1, $due);

        $result = app(ScheduleRunner::class)->run($due[0], 1, $now);

        $this->assertSame('success', $result['status']);

        $row = DB::table('nvoos_schedules')->where('id', $id)->first();
        $this->assertSame(1, (int) $row->run_count);
        $this->assertNotNull($row->last_run_at);
        $this->assertTrue(Carbon::parse((string) $row->next_run_at)->greaterThan($now));

        $run = DB::table('nvoos_schedule_runs')->where('schedule_id', $id)->first();
        $this->assertSame('success', $run->status);
        $this->assertNotNull($run->finished_at);

        // The clock moved, so the next tick finds nothing due.
        $this->assertSame([], app(ScheduleRunner::class)->due($now));
    }

    public function test_an_unregistered_handler_is_a_recorded_failure(): void
    {
        $id = $this->schedule([
            'handler' => 'wp_mcp_ai_pro_schedule_exec',
            'next_run_at' => Carbon::parse('2026-11-14 09:00:00'),
        ]);

        $now = Carbon::parse('2026-11-14 10:00:00');
        $due = app(ScheduleRunner::class)->due($now);
        $result = app(ScheduleRunner::class)->run($due[0], 1, $now);

        $this->assertSame('failure', $result['status']);
        $this->assertStringContainsString('not registered', (string) $result['error']);

        // A failure still advances the clock: one bad run must not wedge the tick.
        $row = DB::table('nvoos_schedules')->where('id', $id)->first();
        $this->assertTrue(Carbon::parse((string) $row->next_run_at)->greaterThan($now));
        $this->assertSame('failure', DB::table('nvoos_schedule_runs')->value('status'));
    }

    public function test_disabled_schedules_are_never_due(): void
    {
        $this->schedule([
            'enabled' => false,
            'next_run_at' => Carbon::parse('2026-11-14 09:00:00'),
        ]);

        $this->assertSame([], app(ScheduleRunner::class)->due(Carbon::parse('2026-11-14 10:00:00')));
    }

    public function test_the_command_runs_due_schedules(): void
    {
        $id = $this->schedule(['next_run_at' => now()->subMinute()]);

        $this->artisan('nvoos:run-schedules')->assertExitCode(0);

        $this->assertSame(1, (int) DB::table('nvoos_schedules')->where('id', $id)->value('run_count'));
        $this->assertSame(1, DB::table('nvoos_schedule_runs')->count());
    }

    public function test_the_command_is_a_dry_run_when_asked(): void
    {
        $this->schedule(['next_run_at' => now()->subMinute()]);

        $this->artisan('nvoos:run-schedules', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, DB::table('nvoos_schedule_runs')->count());
    }

    public function test_wordpress_intervals_map_to_cron(): void
    {
        $this->assertSame('0 0 * * *', ScheduleTiming::fromWordPressInterval('daily'));
        $this->assertSame('0 */12 * * *', ScheduleTiming::fromWordPressInterval('twicedaily'));
        $this->assertSame('0 0 * * 1', ScheduleTiming::fromWordPressInterval('weekly'));
        $this->assertSame('0 0 1 * *', ScheduleTiming::fromWordPressInterval('wp_mcp_ai_monthly'));
        $this->assertNull(ScheduleTiming::fromWordPressInterval('every-five-minutes'));
        $this->assertNull(ScheduleTiming::nextRunAt('not a cron'));
    }

    public function test_the_manual_trigger_still_works_and_records_history(): void
    {
        $id = $this->schedule();

        $this->postJson("/api/v1/pro/schedules/{$id}/trigger")
            ->assertStatus(202)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('queued', true);

        // QUEUE_CONNECTION=sync in phpunit.xml, so the queued job ran inside the
        // request and the history is already terminal.
        $this->getJson("/api/v1/pro/schedules/{$id}/history")
            ->assertOk()
            ->assertJsonPath('history.0.status', 'success');

        $this->assertSame(1, (int) DB::table('nvoos_schedules')->where('id', $id)->value('run_count'));
    }
}

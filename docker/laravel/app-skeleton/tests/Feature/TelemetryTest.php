<?php

namespace Tests\Feature;

use App\Support\ActivityRecorder;
use App\Support\HealthSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Telemetry stores for the command-centre overview (090-i).
 *
 * The uptime KPI has one rule worth stating explicitly: no samples means
 * **unknown** (null), not zero. A `0%` beside four real KPIs reads as an
 * outage, which is exactly why the surface stayed closed until the store
 * existed.
 */
final class TelemetryTest extends TestCase
{
    use RefreshDatabase;

    private function sampler(): HealthSampler
    {
        return app(HealthSampler::class);
    }

    private function activity(): ActivityRecorder
    {
        return app(ActivityRecorder::class);
    }

    private function insertSample(bool $healthy, ?Carbon $at = null): void
    {
        DB::table('nvoos_health_samples')->insert(array(
            'check' => 'core',
            'healthy' => $healthy,
            'recorded_at' => $at ?? now(),
        ));
    }

    public function test_a_single_healthy_sample_reads_as_full_uptime(): void
    {
        $this->sampler()->sample();

        $this->assertSame(100.0, $this->sampler()->uptimePercent());
    }

    public function test_the_scheduled_command_records_a_sample(): void
    {
        $this->artisan('nvoos:sample-health')->assertSuccessful();

        $this->assertSame(1, DB::table('nvoos_health_samples')->count());
    }

    public function test_uptime_is_unknown_without_samples(): void
    {
        $this->assertNull($this->sampler()->uptimePercent());
    }

    public function test_uptime_averages_the_window(): void
    {
        $this->insertSample(true);
        $this->insertSample(true);
        $this->insertSample(false);
        $this->insertSample(false);

        $this->assertSame(50.0, $this->sampler()->uptimePercent());
    }

    public function test_samples_outside_the_window_are_ignored(): void
    {
        $this->insertSample(false, now()->subDays(3));
        $this->insertSample(true);

        $this->assertSame(100.0, $this->sampler()->uptimePercent(24));
    }

    public function test_prune_drops_only_expired_samples(): void
    {
        $this->insertSample(true, now()->subDays(40));
        $this->insertSample(true);

        $this->assertSame(1, $this->sampler()->prune(30));
        $this->assertSame(1, DB::table('nvoos_health_samples')->count());
    }

    public function test_an_activity_event_round_trips_in_the_feed_shape(): void
    {
        $this->activity()->record('workflow.completed', 'Done', 7, array('run_id' => 3));

        $recent = $this->activity()->recent();

        $this->assertCount(1, $recent);
        $this->assertSame('workflow.completed', $recent[0]['type']);
        $this->assertSame(7, $recent[0]['agent_id']);
        $this->assertSame('Done', $recent[0]['message']);
        // The client's `ActivityEvent.timestamp` is a unix second, not a string.
        $this->assertIsInt($recent[0]['timestamp']);
        $this->assertGreaterThan(0, $recent[0]['timestamp']);
    }

    public function test_recent_events_are_newest_first_and_limited(): void
    {
        foreach (array('first', 'second', 'third') as $message) {
            $this->activity()->record('test.event', $message);
        }

        $recent = $this->activity()->recent(2);

        $this->assertCount(2, $recent);
        $this->assertSame('third', $recent[0]['message']);
        $this->assertSame('second', $recent[1]['message']);
    }

    public function test_an_event_without_an_agent_reports_null(): void
    {
        $this->activity()->record('system.tick');

        $recent = $this->activity()->recent();

        $this->assertNull($recent[0]['agent_id']);
        $this->assertSame('', $recent[0]['message']);
    }

    public function test_prune_drops_only_expired_events(): void
    {
        DB::table('nvoos_activity_events')->insert(array(
            'type' => 'old',
            'message' => '',
            'created_at' => now()->subDays(40),
        ));

        $this->activity()->record('fresh');

        $this->assertSame(1, $this->activity()->prune(30));
        $this->assertSame(1, DB::table('nvoos_activity_events')->count());
    }
}

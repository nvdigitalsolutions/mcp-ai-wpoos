<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Diagnostics\RoutingExportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 149 §78 — the routing-export backoff gauge.
 *
 * The diagnostics page has always shown the retry time as a sentence. A
 * sentence cannot be scraped, so `/api/v1/diagnostics/overview` now carries
 * the same state as fields. The properties worth pinning:
 *
 *  - `backing_off` is true only inside the window, and `retry_after` is null
 *    when it is false — never a timestamp that has already passed;
 *  - an `ok` run clears it, because the failure count is derived from history;
 *  - an unconfigured exporter reports `configured: false` rather than a
 *    healthy-looking "not backing off".
 */
class RoutingExportGaugeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same stateful-SPA setup as Phase1ConsoleTest: Sanctum only starts the
        // session for requests that look first-party.
        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_overview_reports_a_backing_off_exporter(): void
    {
        $this->configureExport();
        $this->failOnce('2026-11-19 10:00:00');

        Carbon::setTestNow('2026-11-19 10:00:30');

        $response = $this->getJson('/api/v1/diagnostics/overview')->assertOk();

        $response
            ->assertJsonPath('data.routing_export.configured', true)
            ->assertJsonPath('data.routing_export.backing_off', true)
            ->assertJsonPath('data.routing_export.consecutive_failures', 1);

        $retryAfter = (string) $response->json('data.routing_export.retry_after');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $retryAfter,
            'The gauge must be an ISO-8601 instant a scraper can parse.'
        );

        // Jitter is disabled in configureExport(), so the window is exactly 60s.
        $this->assertSame(
            Carbon::parse('2026-11-19 10:01:00')->getTimestamp(),
            Carbon::parse($retryAfter)->getTimestamp()
        );
    }

    public function test_the_gauge_clears_once_the_window_has_elapsed(): void
    {
        $this->configureExport();
        $this->failOnce('2026-11-19 10:00:00');

        // Past the 60s window: the next tick will try, so the gauge is off and
        // the retry time is gone rather than stale.
        Carbon::setTestNow('2026-11-19 10:02:00');

        $this->getJson('/api/v1/diagnostics/overview')
            ->assertOk()
            ->assertJsonPath('data.routing_export.backing_off', false)
            ->assertJsonPath('data.routing_export.retry_after', null)
            ->assertJsonPath('data.routing_export.consecutive_failures', 1);
    }

    public function test_a_successful_push_resets_the_gauge(): void
    {
        $this->configureExport();
        $store = app(RoutingExportStore::class);

        $this->failOnce('2026-11-19 10:00:00');
        $store->record('ok', 1, 1);

        Carbon::setTestNow('2026-11-19 10:00:30');

        $this->getJson('/api/v1/diagnostics/overview')
            ->assertOk()
            ->assertJsonPath('data.routing_export.backing_off', false)
            ->assertJsonPath('data.routing_export.retry_after', null)
            ->assertJsonPath('data.routing_export.consecutive_failures', 0);
    }

    public function test_an_unconfigured_exporter_is_reported_as_not_configured(): void
    {
        config(['oos.routing.export.endpoint' => '']);

        $this->failOnce('2026-11-19 10:00:00');

        Carbon::setTestNow('2026-11-19 10:00:30');

        $this->getJson('/api/v1/diagnostics/overview')
            ->assertOk()
            ->assertJsonPath('data.routing_export.configured', false)
            ->assertJsonPath('data.routing_export.backing_off', false)
            ->assertJsonPath('data.routing_export.retry_after', null)
            ->assertJsonPath('data.routing_export.consecutive_failures', 0);
    }

    private function configureExport(): void
    {
        config([
            'oos.routing.export.endpoint' => 'https://collector.test/v1/logs',
            'oos.routing.export.backoff_base' => 60,
            'oos.routing.export.backoff_max' => 3600,
            // Deterministic window: the gauge is under test, not the jitter.
            'oos.routing.export.backoff_jitter' => 0.0,
        ]);
    }

    private function failOnce(string $at): void
    {
        Carbon::setTestNow($at);

        app(RoutingExportStore::class)->record('failed', 0, 0, 'collector down');
    }
}

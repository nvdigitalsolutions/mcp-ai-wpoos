<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Diagnostics\RoutingExportStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 149 §74 — the scheduled OTLP push.
 *
 * The properties under test are the ones that make a scheduled exporter safe:
 *
 *  - a successful push advances the watermark, so a collector sees each event
 *    once;
 *  - a failed push does **not** advance it, so the next run retries exactly the
 *    unacknowledged rows;
 *  - retention refuses to delete unexported rows while an endpoint is
 *    configured — a broken collector grows the table rather than losing data;
 *  - the diagnostics page shows the last attempt, including its error.
 */
class RoutingExportPushTest extends TestCase
{
    use RefreshDatabase;

    private const METRICS = 'nvoos_routing_metrics';

    private const EXPORTS = 'nvoos_routing_exports';

    public function test_a_successful_push_sends_pending_rows_and_advances_the_watermark(): void
    {
        $this->configureEndpoint();
        Http::fake(['*collector.test*' => Http::response('', 200)]);

        $this->insertMetric();
        $this->insertMetric(['kind' => 'provider_hop', 'provider' => 'gemini', 'from_provider' => 'openai', 'from_model' => '', 'to_model' => '']);

        $store = app(RoutingExportStore::class);

        $this->artisan('nvoos:push-routing-metrics')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(static function ($request): bool {
            $body = $request->data();

            return str_contains($request->url(), 'collector.test')
                && isset($body['resourceLogs'][0]['scopeLogs'][0]['logRecords'])
                && 2 === count($body['resourceLogs'][0]['scopeLogs'][0]['logRecords']);
        });

        $this->assertSame(2, $store->watermark());
        $this->assertSame([], $store->pending());

        $last = $store->last();
        $this->assertSame('ok', (string) $last->status);
        $this->assertSame(2, (int) $last->sent);
        $this->assertSame(2, (int) $last->last_metric_id);
    }

    public function test_a_failed_push_leaves_the_rows_pending(): void
    {
        $this->configureEndpoint();
        Http::fake(['*collector.test*' => Http::response('collector down', 503)]);

        $this->insertMetric();

        $store = app(RoutingExportStore::class);

        $this->artisan('nvoos:push-routing-metrics')->assertFailed();

        $this->assertSame(0, $store->watermark());
        $this->assertCount(1, $store->pending());

        $last = $store->last();
        $this->assertSame('failed', (string) $last->status);
        $this->assertStringContainsString('503', (string) $last->error);
    }

    public function test_a_second_push_sends_only_new_rows(): void
    {
        $this->configureEndpoint();
        Http::fake(['*collector.test*' => Http::response('', 200)]);

        $this->insertMetric();
        $this->artisan('nvoos:push-routing-metrics')->assertSuccessful();

        $this->insertMetric();

        $this->artisan('nvoos:push-routing-metrics')->assertSuccessful();

        Http::assertSentCount(2);

        $last = app(RoutingExportStore::class)->last();
        $this->assertSame(1, (int) $last->sent);
        $this->assertSame(2, (int) $last->last_metric_id);
    }

    public function test_without_an_endpoint_nothing_is_sent_and_nothing_is_recorded(): void
    {
        config(['oos.routing.export.endpoint' => '']);
        Http::fake();

        $this->insertMetric();

        $this->artisan('nvoos:push-routing-metrics')
            ->expectsOutputToContain('not configured')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table(self::EXPORTS)->count());
    }

    public function test_a_dry_run_sends_and_records_nothing(): void
    {
        $this->configureEndpoint();
        Http::fake(['*collector.test*' => Http::response('', 200)]);

        $this->insertMetric();

        $this->artisan('nvoos:push-routing-metrics', ['--dry-run' => true])
            ->expectsOutputToContain('pending')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, DB::table(self::EXPORTS)->count());
    }

    public function test_retention_keeps_unexported_rows_when_export_is_configured(): void
    {
        $this->configureEndpoint();

        $this->insertMetric(['created_at' => now()->subDays(40)]);
        $this->insertMetric(['created_at' => now()->subDays(40)]);

        // Only the first row has been acknowledged by a collector.
        app(RoutingExportStore::class)->record('ok', 1, 1);

        $this->artisan('nvoos:prune-routing-metrics', ['--days' => 30])
            ->assertSuccessful();

        $remaining = DB::table(self::METRICS)->pluck('id')->all();
        $this->assertSame([2], $remaining, 'The unexported row must survive retention.');
    }

    public function test_retention_prunes_by_age_when_export_is_not_configured(): void
    {
        config(['oos.routing.export.endpoint' => '']);

        $this->insertMetric(['created_at' => now()->subDays(40)]);
        $this->insertMetric(['created_at' => now()->subDays(40)]);

        $this->artisan('nvoos:prune-routing-metrics', ['--days' => 30])
            ->assertSuccessful();

        $this->assertSame(0, DB::table(self::METRICS)->count());
    }

    public function test_the_diagnostics_page_reports_the_last_export(): void
    {
        $this->configureEndpoint();
        app(RoutingExportStore::class)->record('failed', 0, 0, 'HTTP 500: boom');

        $this->actingAs($this->operatorMember())
            ->get('/ops/diagnostics')
            ->assertOk()
            ->assertSee('OTLP export (last)')
            ->assertSee('HTTP 500');
    }

    public function test_the_export_carries_a_stable_event_id(): void
    {
        $this->configureEndpoint();
        Http::fake(['*collector.test*' => Http::response('', 200)]);

        $this->insertMetric();
        $id = (int) DB::table(self::METRICS)->max('id');

        $this->artisan('nvoos:push-routing-metrics')->assertSuccessful();

        Http::assertSent(static function ($request) use ($id): bool {
            $record = $request->data()['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0] ?? [];

            foreach ((array) ($record['attributes'] ?? []) as $attribute) {
                if ('nvoos.routing.event_id' === ($attribute['key'] ?? '')) {
                    return (string) $id === (string) ($attribute['value']['intValue'] ?? '');
                }
            }

            return false;
        });
    }

    public function test_a_failed_push_backs_off_exponentially(): void
    {
        $this->configureEndpoint();
        $this->configureBackoff(60, 3600);

        Carbon::setTestNow('2026-11-19 10:00:00');

        $this->insertMetric();
        Http::fakeSequence('*collector.test*')
            ->push('down', 503)
            ->push('down', 503)
            ->push('down', 503);

        $this->artisan('nvoos:push-routing-metrics')->assertFailed();
        $this->assertSame(1, app(RoutingExportStore::class)->consecutiveFailures());

        // Inside the first backoff window (60s): no request.
        Carbon::setTestNow('2026-11-19 10:00:30');
        $this->artisan('nvoos:push-routing-metrics')
            ->expectsOutputToContain('Backing off')
            ->assertSuccessful();
        Http::assertSentCount(1);

        // Past it: a second attempt, which also fails.
        Carbon::setTestNow('2026-11-19 10:01:01');
        $this->artisan('nvoos:push-routing-metrics')->assertFailed();
        Http::assertSentCount(2);
        $this->assertSame(2, app(RoutingExportStore::class)->consecutiveFailures());

        // Second window is 120s from the second failure: still waiting at +60s.
        Carbon::setTestNow('2026-11-19 10:02:01');
        $this->artisan('nvoos:push-routing-metrics')
            ->expectsOutputToContain('Backing off')
            ->assertSuccessful();
        Http::assertSentCount(2);

        // --force is the operator override.
        $this->artisan('nvoos:push-routing-metrics', ['--force' => true])->assertFailed();
        Http::assertSentCount(3);
    }

    public function test_a_successful_push_resets_the_backoff(): void
    {
        $this->configureEndpoint();
        $this->configureBackoff(60, 3600);

        Carbon::setTestNow('2026-11-19 10:00:00');

        $this->insertMetric();
        Http::fakeSequence('*collector.test*')
            ->push('down', 503)
            ->push('', 200)
            ->push('', 200);

        $this->artisan('nvoos:push-routing-metrics')->assertFailed();

        Carbon::setTestNow('2026-11-19 10:01:01');
        $this->artisan('nvoos:push-routing-metrics')->assertSuccessful();
        $this->assertSame(0, app(RoutingExportStore::class)->consecutiveFailures());

        $this->insertMetric();
        Carbon::setTestNow('2026-11-19 10:01:02');
        $this->artisan('nvoos:push-routing-metrics')->assertSuccessful();

        Http::assertSentCount(3);
    }

    public function test_backoff_can_be_disabled(): void
    {
        $this->configureEndpoint();
        $this->configureBackoff(0, 3600);

        Carbon::setTestNow('2026-11-19 10:00:00');

        $this->insertMetric();
        Http::fakeSequence('*collector.test*')
            ->push('down', 503)
            ->push('down', 503);

        $this->artisan('nvoos:push-routing-metrics')->assertFailed();
        $this->artisan('nvoos:push-routing-metrics')->assertFailed();

        Http::assertSentCount(2);
    }

    private function configureEndpoint(): void
    {
        config([
            'oos.routing.export.endpoint' => 'https://collector.test/v1/logs',
            'oos.routing.export.token' => 'test-token',
            'oos.routing.export.timeout' => 5,
        ]);
    }

    private function configureBackoff(int $base, int $max): void
    {
        config([
            'oos.routing.export.backoff_base' => $base,
            'oos.routing.export.backoff_max' => $max,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides Column overrides.
     */
    private function insertMetric(array $overrides = []): void
    {
        DB::table(self::METRICS)->insert(array_merge([
            'kind' => 'model_fallback',
            'provider' => 'openai',
            'from_provider' => null,
            'from_model' => 'gpt-5',
            'to_model' => 'gpt-5-mini',
            'succeeded' => true,
            'created_at' => now(),
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function operatorMember(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::OPERATOR_ID)
            ->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }
}

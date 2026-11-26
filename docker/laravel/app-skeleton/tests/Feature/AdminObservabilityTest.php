<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Observability\TraceRuns;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 149 §84 — observability (`/ops/observability`).
 *
 * The trace store had a writer and no reader. These tests pin what the reader
 * must get right: a run is its `end` record (absent means incomplete), the
 * newest runs come first, the capture master switch hides everything, and the
 * export gauge reports the same state the diagnostics API does.
 */
class AdminObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'nvoos_harness_trace_records';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        Carbon::setTestNow('2026-11-19 12:00:00');

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_renders_with_capture_on(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/observability')
            ->assertOk()
            ->assertSee('Observability')
            ->assertSee('Run capture')
            ->assertSee('enabled');
    }

    public function test_a_completed_run_is_listed_with_its_summary(): void
    {
        // No assistant row for id 42: the page must still render the run, with
        // the fallback label, rather than drop a row it cannot name.
        $this->record(42, 'run-1', 'start');
        $this->record(42, 'run-1', 'routing', ['router' => 'fixed', 'tool_count' => 0, 'tools' => []]);
        $this->record(42, 'run-1', 'end', [
            'provider_calls' => 2,
            'duration_ms' => 1234,
            'cost' => ['cost_usd' => 0.05],
            'cancelled' => false,
        ]);

        $this->actingAs($this->operator())
            ->get('/ops/observability')
            ->assertOk()
            ->assertSee('Assistant #42')
            ->assertSee('completed')
            ->assertSee('1,234 ms')
            ->assertSee('0.0500');
    }

    public function test_a_run_without_an_end_record_is_incomplete(): void
    {
        $this->record(42, 'run-dead', 'start');
        $this->record(42, 'run-dead', 'iteration');

        $runs = app(TraceRuns::class)->recent();

        $this->assertCount(1, $runs);
        $this->assertSame('incomplete', $runs[0]['status']);
        $this->assertNull($runs[0]['cost_usd']);
        $this->assertSame('Assistant #42', $runs[0]['assistant']);
    }

    public function test_newest_runs_come_first_and_the_limit_holds(): void
    {
        $this->record(1, 'run-old', 'end', ['cancelled' => false], '2026-11-18 09:00:00');
        $this->record(1, 'run-new', 'end', ['cancelled' => false], '2026-11-19 09:00:00');

        $runs = app(TraceRuns::class)->recent();

        $this->assertSame('run-new', $runs[0]['run_id']);
        $this->assertSame('run-old', $runs[1]['run_id']);
        $this->assertCount(1, app(TraceRuns::class)->recent(1));
    }

    public function test_capture_disabled_hides_runs_and_explains(): void
    {
        config(['oos.security.trace_capture' => false]);

        $this->record(1, 'run-1', 'end', ['cancelled' => false]);

        $this->actingAs($this->operator())
            ->get('/ops/observability')
            ->assertOk()
            ->assertSee('disabled')
            ->assertSee('oos.security.trace_capture')
            ->assertDontSee('run-1');
    }

    public function test_the_export_gauge_is_reported(): void
    {
        config([
            'oos.routing.export.endpoint' => 'https://collector.test/v1/logs',
            'oos.routing.export.backoff_base' => 60,
            'oos.routing.export.backoff_max' => 3600,
            'oos.routing.export.backoff_jitter' => 0.0,
        ]);

        app(\App\Support\Diagnostics\RoutingExportStore::class)->record('failed', 0, 0, 'collector down');

        $this->actingAs($this->operator())
            ->get('/ops/observability')
            ->assertOk()
            ->assertSee('failed')
            ->assertSee('backing off');
    }

    public function test_an_estate_administrator_cannot_reach_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$customer->id => ['role' => 'owner']]);

        $this->actingAs($customer)
            ->get('/ops/observability')
            ->assertForbidden();
    }

    public function test_a_run_names_the_estate_its_assistant_belongs_to(): void
    {
        Tenant::query()->create(array(
            'id' => 'estate-a',
            'name' => 'Estate A',
            'slug' => 'estate-a',
        ));

        $this->record(42, 'run-estate', 'start', array(), null, 'estate-a');
        $this->record(42, 'run-estate', 'end', array('provider_calls' => 1), null, 'estate-a');

        $this->actingAs($this->operator())
            ->get('/ops/observability')
            ->assertOk()
            ->assertSee('Estate')
            ->assertSee('Estate A');
    }

    public function test_a_run_without_an_estate_still_renders_as_the_default(): void
    {
        // Pre-tenancy rows are `default` by the column default (Proposal 152 §3
        // D3); the page must say so rather than leave the cell blank.
        $this->record(42, 'run-legacy', 'start');

        $this->actingAs($this->operator())
            ->get('/ops/observability')
            ->assertOk()
            ->assertSee('Default estate');
    }

    private function record(int $assistantId, string $runId, string $type, array $payload = [], ?string $at = null, ?string $tenantId = null): void
    {
        DB::table(self::TABLE)->insert([
            'assistant_id' => $assistantId,
            'tenant_id' => $tenantId ?? Tenant::DEFAULT_ID,
            'run_id' => $runId,
            'artifact' => 'run.jsonl',
            'payload' => json_encode(array_merge(['type' => $type, 'run_id' => $runId], $payload)),
            'created_at' => $at ?? now()->toDateTimeString(),
        ]);
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }
}

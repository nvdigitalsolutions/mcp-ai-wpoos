<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\Metrics;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Metrics\MetricCatalogue;
use App\Support\Metrics\MetricsReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Nvoos\Laravel\Adapter\DatabaseTraceStore;
use Nvoos\Laravel\Adapter\TokenUsage;
use Tests\TestCase;

/**
 * Proposal 155 / §99 — `/ops/metrics`, the derived catalogue.
 *
 * The properties worth pinning are the ones that keep the page honest:
 *
 *  - every definition is rendered, including the ten this runtime cannot produce;
 *  - a produced figure is the figure the store holds (the same columns the cost
 *    pages read), not a re-derivation;
 *  - **a dash is not a zero** — an unproduced metric shows its reason, and a
 *    trace-derived metric is blank when the window holds no trace records at all,
 *    because capture is per assistant and opt-in.
 */
class AdminMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        Carbon::setTestNow('2026-11-19 12:00:00');

        config(['sanctum.stateful' => array('localhost', '127.0.0.1')]);
        $this->withHeader('Referer', 'http://localhost');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_renders_every_definition(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/metrics')
            ->assertOk()
            ->assertSee('Metrics')
            ->assertSee('token_usage.total_cost_usd')
            ->assertSee('tool.execution.count')
            ->assertSee('stream.ttfb_ms')
            ->assertSee('eval.suite.pass_rate')
            // The header counts, from the catalogue rather than a literal here.
            ->assertSee(MetricCatalogue::count().' definitions');
    }

    /**
     * The catalogue is the WordPress vocabulary verbatim: 21 definitions.
     */
    public function test_the_vocabulary_is_the_wordpress_one(): void
    {
        $ids = array_column(MetricCatalogue::definitions(), 'id');

        $this->assertCount(21, $ids);
        $this->assertSame($ids, array_unique($ids), 'Metric ids must be unique.');

        foreach (array('chat.', 'token_usage.', 'tool.', 'stream.', 'eval.') as $prefix) {
            $this->assertNotEmpty(
                array_filter($ids, static fn (string $id): bool => 0 === strpos($id, $prefix)),
                "The {$prefix} pack is missing from the catalogue."
            );
        }
    }

    public function test_token_metrics_are_the_totals_the_cost_pages_read(): void
    {
        $this->spend(100, 40, 0.25);
        $this->spend(60, 20, 0.15);

        $report = app(MetricsReport::class)->report(30);
        $byId = array_column($report['rows'], null, 'id');

        $this->assertSame(2.0, $byId['chat.turn.count']['value']);
        $this->assertSame(160.0, $byId['token_usage.prompt_tokens']['value']);
        $this->assertSame(60.0, $byId['token_usage.completion_tokens']['value']);
        $this->assertEqualsWithDelta(0.40, (float) $byId['token_usage.total_cost_usd']['value'], 1e-6);
    }

    public function test_tool_metrics_are_derived_from_the_trace_records(): void
    {
        $this->traceTool('create_post', 'success', 10.0);
        $this->traceTool('delete_post', 'error', 30.0);
        $this->traceEnd(1200.0, 2, false);

        $report = app(MetricsReport::class)->report(30);
        $byId = array_column($report['rows'], null, 'id');

        $this->assertSame(2.0, $byId['tool.execution.count']['value']);
        $this->assertSame(1.0, $byId['tool.execution.success.count']['value']);
        $this->assertSame(1.0, $byId['tool.execution.error.count']['value']);
        $this->assertEqualsWithDelta(20.0, (float) $byId['tool.execution.duration_ms']['value'], 1e-6);

        $this->assertEqualsWithDelta(1200.0, (float) $byId['chat.turn.duration_ms']['value'], 1e-6);
        $this->assertEqualsWithDelta(2.0, (float) $byId['chat.agentic.iterations']['value'], 1e-6);
    }

    /**
     * "Nothing recorded" and "nothing ran" are different facts, and only one of
     * them is a zero.
     */
    public function test_trace_metrics_are_blank_when_capture_is_off(): void
    {
        // Token rows exist; no trace records at all.
        $this->spend(10, 5, 0.01);

        $report = app(MetricsReport::class)->report(30);
        $byId = array_column($report['rows'], null, 'id');

        $this->assertSame(1.0, $byId['chat.turn.count']['value'], 'The token figures are unaffected.');
        $this->assertNull($byId['tool.execution.count']['value'], 'No trace records means no tool figure, not zero.');
        $this->assertNull($byId['chat.turn.duration_ms']['value']);
        $this->assertSame(0, (int) $report['traces']['records']);
    }

    public function test_the_callout_explains_why_trace_rows_are_blank(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(Metrics::class)
            ->assertOk()
            ->assertSee('No trace records in this window');
    }

    /**
     * An unproduced metric must not be a zero — a zero is a claim.
     */
    public function test_unproduced_metrics_carry_no_value_and_a_reason(): void
    {
        $report = app(MetricsReport::class)->report(30);

        foreach ($report['rows'] as $row) {
            if (MetricCatalogue::STATE_UNPRODUCED !== $row['state']) {
                continue;
            }

            $this->assertNull($row['value'], "{$row['id']} has no producer but carries a value.");
            $this->assertNotSame('', (string) $row['note'], "{$row['id']} gives no reason.");
        }
    }

    public function test_the_period_select_changes_the_window(): void
    {
        // Outside 7 days, inside 30 and 90.
        $this->spend(100, 40, 0.25, Carbon::now()->subDays(20));

        $recent = array_column(app(MetricsReport::class)->report(7)['rows'], null, 'id');
        $older = array_column(app(MetricsReport::class)->report(30)['rows'], null, 'id');

        $this->assertSame(0.0, $recent['chat.turn.count']['value']);
        $this->assertSame(1.0, $older['chat.turn.count']['value']);
    }

    public function test_an_unknown_period_falls_back_to_the_default(): void
    {
        $report = app(MetricsReport::class)->report(9999);

        $this->assertSame(30, (int) $report['window']['days']);
    }

    public function test_the_page_requires_the_operator_panel(): void
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        $this->actingAs($user)
            ->get('/ops/metrics')
            ->assertForbidden();
    }

    private function spend(int $prompt, int $completion, float $cost, ?Carbon $at = null): void
    {
        DB::table(TokenUsage::TABLE)->insert(array(
            'tenant_id' => Tenant::DEFAULT_ID,
            'assistant_id' => 0,
            'user_id' => 1,
            'model_id' => 'spike-1',
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost' => $cost,
            'metadata' => json_encode(array('provider' => 'spike')),
            'created_at' => $at ?? now(),
        ));
    }

    private function traceTool(string $slug, string $outcome, float $durationMs): void
    {
        $this->traceRecord(array(
            'type' => 'tool',
            'tool' => $slug,
            'tool_call_id' => 'call_'.$slug,
            'duration_ms' => $durationMs,
            'outcome' => $outcome,
        ));
    }

    private function traceEnd(float $durationMs, int $iterations, bool $cancelled): void
    {
        $this->traceRecord(array(
            'type' => 'end',
            'duration_ms' => $durationMs,
            'iterations' => $iterations,
            'cancelled' => $cancelled,
            'provider_calls' => 2,
        ));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function traceRecord(array $payload): void
    {
        DB::table(DatabaseTraceStore::TABLE)->insert(array(
            'assistant_id' => 1,
            'run_id' => 'run-'.uniqid(),
            'artifact' => 'run.jsonl',
            'payload' => json_encode($payload),
            'created_at' => now(),
        ));
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}

<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\TokenAnalytics;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Reports\TokenAnalyticsReport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 149 §83 — token analytics (`trends`, `patterns`, `anomalies`).
 *
 * The maths is pinned with synthetic series rather than by asserting numbers
 * computed by the same code: a perfect ramp must read as rising with full
 * confidence, a flat window as stable, and a spike as an anomaly while a quiet
 * day is not. The guards are tested too — "not enough data" is a result, not a
 * failure.
 */
class AdminTokenAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'nvoos_token_usage';

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

    public function test_the_page_renders(): void
    {
        $this->insert('2026-11-19 09:00:00', 100);

        $this->actingAs($this->operator())
            ->get('/ops/token-analytics')
            ->assertOk()
            ->assertSee('Token analytics')
            ->assertSee('Daily tokens');
    }

    public function test_the_series_fills_empty_days_with_zeros(): void
    {
        $this->insert('2026-11-18 09:00:00', 100);

        $series = app(TokenAnalyticsReport::class)->series(7, null);

        $this->assertCount(7, $series);
        $this->assertSame(100, $series['2026-11-18']['tokens']);
        $this->assertSame(0, $series['2026-11-17']['tokens']);
    }

    public function test_a_ramp_reads_as_rising_with_full_confidence(): void
    {
        foreach ([10, 20, 30, 40, 50, 60, 70] as $index => $tokens) {
            $this->insert(Carbon::parse('2026-11-13 09:00:00')->addDays($index)->toDateTimeString(), $tokens);
        }

        $trend = app(TokenAnalyticsReport::class)->trend(7, null);

        $this->assertSame('rising', $trend['direction']);
        $this->assertSame(100.0, (float) $trend['confidence'], 'A perfect line explains all the variation.');
        $this->assertEqualsWithDelta(40.0, $trend['average_daily'], 1e-6);
        $this->assertEqualsWithDelta(280.0, $trend['projected_7d'], 1e-6);
    }

    public function test_a_flat_series_reads_as_stable(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->insert(Carbon::parse('2026-11-13 09:00:00')->addDays($i)->toDateTimeString(), 50);
        }

        $trend = app(TokenAnalyticsReport::class)->trend(7, null);

        $this->assertSame('stable', $trend['direction']);
        $this->assertSame(0.0, (float) $trend['confidence'], 'No variation means no line to be confident about.');
    }

    public function test_a_single_active_day_is_not_a_trend(): void
    {
        $this->insert('2026-11-19 09:00:00', 500);

        $trend = app(TokenAnalyticsReport::class)->trend(7, null);

        $this->assertSame('insufficient', $trend['direction']);
        $this->assertSame(1, $trend['active_days']);
    }

    public function test_a_spike_is_an_anomaly_but_a_quiet_day_is_not(): void
    {
        foreach (['2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17'] as $date) {
            $this->insert($date.' 09:00:00', 100);
        }

        $this->insert('2026-11-18 09:00:00', 0);
        $this->insert('2026-11-19 09:00:00', 1000);

        $result = app(TokenAnalyticsReport::class)->anomalies(7, null);

        $this->assertNull($result['reason']);
        $this->assertCount(1, $result['points'], 'Only the spike is listed.');

        $point = $result['points'][0];

        $this->assertSame('2026-11-19', $point['date']);
        $this->assertSame(1000, $point['tokens']);
        $this->assertGreaterThanOrEqual(TokenAnalyticsReport::ANOMALY_Z, $point['z']);
        $this->assertContains($point['severity'], ['low', 'medium', 'high']);
    }

    public function test_anomalies_need_history_to_mean_anything(): void
    {
        $this->insert('2026-11-18 09:00:00', 100);
        $this->insert('2026-11-19 09:00:00', 900);

        $result = app(TokenAnalyticsReport::class)->anomalies(7, null);

        $this->assertNotNull($result['reason']);
        $this->assertSame([], $result['points']);
    }

    public function test_patterns_find_the_peak_hour_and_weekday(): void
    {
        // 2026-11-17 is a Tuesday, 2026-11-18 a Wednesday.
        $this->insert('2026-11-17 09:00:00', 500);
        $this->insert('2026-11-18 15:00:00', 100);

        $patterns = app(TokenAnalyticsReport::class)->patterns(7, null);

        $this->assertSame(9, $patterns['peak_hours'][0]);
        $this->assertSame('Tuesday', $patterns['peak_days'][0]);
        $this->assertContains($patterns['usage_type'], ['consistent', 'sporadic', 'bursty']);
    }

    public function test_the_page_shows_the_trend_cards_with_data(): void
    {
        $this->insert('2026-11-18 09:00:00', 100);
        $this->insert('2026-11-19 09:00:00', 200);

        $response = $this->actingAs($this->operator())->get('/ops/token-analytics');

        $response->assertOk()
            ->assertSee('Trend')
            ->assertSee('Confidence')
            ->assertSee('Projected')
            ->assertSee('Patterns');
    }

    public function test_the_period_changes_the_window(): void
    {
        $this->insert('2026-11-19 09:00:00', 100);
        $this->insert('2026-09-01 09:00:00', 100);

        $component = Livewire::actingAs($this->operator())->test(TokenAnalytics::class);

        $this->assertCount(30, $component->instance()->series());

        $component->set('period', 90);
        $this->assertCount(90, $component->instance()->series());
    }

    public function test_an_estate_administrator_cannot_reach_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$customer->id => ['role' => 'owner']]);

        $this->actingAs($customer)
            ->get('/ops/token-analytics')
            ->assertForbidden();
    }

    private function insert(string $at, int $totalTokens): void
    {
        DB::table(self::TABLE)->insert([
            'user_id' => 1,
            'model_id' => 'gpt-4o-mini',
            'prompt_tokens' => $totalTokens,
            'completion_tokens' => 0,
            'total_tokens' => $totalTokens,
            'cost' => $totalTokens / 1000,
            'metadata' => json_encode(['provider' => 'openai']),
            'created_at' => $at,
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

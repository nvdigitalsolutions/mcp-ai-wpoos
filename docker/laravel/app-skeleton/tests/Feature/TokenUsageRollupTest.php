<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Nvoos\Laravel\Adapter\TokenUsage;
use Nvoos\Laravel\Adapter\TokenUsageRollup;
use Tests\TestCase;

/**
 * 149 §80 — the daily token usage rollup.
 *
 * The properties that make it safe to schedule and safe to prune against:
 *
 *  - a day aggregates by provider and model, summing the same columns the cost
 *    pages read;
 *  - re-running a day is idempotent — upsert on the key, not append;
 *  - a window never includes today, because a day is only complete when it ends;
 *  - a day with no raw rows (already pruned) writes nothing, so a late re-run
 *    cannot zero an existing aggregate;
 *  - `trend()` prefers raw rows for a day that still has them and falls back to
 *    the aggregate for a day retention has emptied.
 */
class TokenUsageRollupTest extends TestCase
{
    use RefreshDatabase;

    private const RAW = 'nvoos_token_usage';

    private const DAILY = 'nvoos_token_usage_daily';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_day_is_aggregated_by_provider_and_model(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');

        $this->insertUsage('2026-11-18 09:00:00', 'gpt-5', 'openai', 100, 20, 0.5);
        $this->insertUsage('2026-11-18 10:00:00', 'gpt-5', 'openai', 50, 10, 0.25);
        $this->insertUsage('2026-11-18 11:00:00', 'gemini-2', 'gemini', 10, 5, 0.01);

        $result = app(TokenUsageRollup::class)->rollupDay(CarbonImmutable::parse('2026-11-18'));

        $this->assertSame(['groups' => 2, 'rows' => 3], $result);
        $this->assertSame(2, DB::table(self::DAILY)->count());

        $openai = DB::table(self::DAILY)->where('provider', 'openai')->first();

        $this->assertSame('2026-11-18', substr((string) $openai->day, 0, 10));
        $this->assertSame('gpt-5', (string) $openai->model_id);
        $this->assertSame(2, (int) $openai->events);
        $this->assertSame(150, (int) $openai->prompt_tokens);
        $this->assertSame(30, (int) $openai->completion_tokens);
        $this->assertSame(180, (int) $openai->total_tokens);
        $this->assertEqualsWithDelta(0.75, (float) $openai->cost, 1e-6);
    }

    public function test_rerunning_a_day_is_idempotent(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');

        $this->insertUsage('2026-11-18 09:00:00', 'gpt-5', 'openai', 100, 20, 0.5);

        $rollup = app(TokenUsageRollup::class);
        $day = CarbonImmutable::parse('2026-11-18');

        $rollup->rollupDay($day);
        $rollup->rollupDay($day);

        $this->assertSame(1, DB::table(self::DAILY)->count());
        $this->assertEqualsWithDelta(
            0.5,
            (float) DB::table(self::DAILY)->value('cost'),
            1e-6,
            'Re-running a day must replace its aggregate, not add a second copy.'
        );
    }

    public function test_the_window_rolls_up_complete_days_only(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');

        $this->insertUsage('2026-11-18 09:00:00', 'gpt-5', 'openai', 100, 20, 0.5);
        $this->insertUsage('2026-11-19 09:00:00', 'gpt-5', 'openai', 100, 20, 0.5);

        $this->artisan('nvoos:rollup-token-usage', ['--days' => 1])
            ->expectsOutputToContain('2026-11-18')
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::DAILY)->count());
        $this->assertSame(
            '2026-11-18',
            substr((string) DB::table(self::DAILY)->value('day'), 0, 10),
            'Today is incomplete and must not be aggregated.'
        );
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');

        $this->insertUsage('2026-11-18 09:00:00', 'gpt-5', 'openai', 100, 20, 0.5);

        $this->artisan('nvoos:rollup-token-usage', ['--date' => '2026-11-18', '--dry-run' => true])
            ->expectsOutputToContain('would be rolled up')
            ->assertSuccessful();

        $this->assertSame(0, DB::table(self::DAILY)->count());
    }

    public function test_an_impossible_date_is_rejected(): void
    {
        $this->artisan('nvoos:rollup-token-usage', ['--date' => '2026-02-31'])
            ->expectsOutputToContain('not a YYYY-MM-DD date')
            ->assertFailed();

        $this->assertSame(0, DB::table(self::DAILY)->count());
    }

    public function test_the_trend_falls_back_to_the_rollup_for_pruned_days(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');

        $this->insertUsage('2026-11-19 09:00:00', 'gpt-5', 'openai', 10, 5, 0.25);

        $oldDay = now()->subDays(40)->toDateString();

        // The raw rows for that day are gone; only the aggregate remains.
        DB::table(self::DAILY)->insert([
            'day' => $oldDay,
            'provider' => 'openai',
            'model_id' => 'gpt-5',
            'events' => 7,
            'prompt_tokens' => 70,
            'completion_tokens' => 35,
            'total_tokens' => 105,
            'cost' => 1.5,
            'updated_at' => now(),
        ]);

        $trend = app(TokenUsage::class)->trend(45, null);

        $labels = $trend['labels'];
        $data = $trend['datasets'][0]['data'];

        $oldIndex = array_search($oldDay, $labels, true);
        $this->assertNotFalse($oldIndex, 'The window must include the rolled-up day.');
        $this->assertEqualsWithDelta(1.5, $data[$oldIndex], 1e-6);

        $todayIndex = array_search('2026-11-19', $labels, true);
        $this->assertEqualsWithDelta(
            0.25,
            $data[$todayIndex],
            1e-6,
            'A day with raw rows must use them, not the aggregate.'
        );
    }

    private function insertUsage(
        string $at,
        string $model,
        string $provider,
        int $prompt,
        int $completion,
        float $cost
    ): void {
        DB::table(self::RAW)->insert([
            'user_id' => 1,
            'model_id' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost' => $cost,
            'metadata' => json_encode(['provider' => $provider]),
            'created_at' => $at,
        ]);
    }
}

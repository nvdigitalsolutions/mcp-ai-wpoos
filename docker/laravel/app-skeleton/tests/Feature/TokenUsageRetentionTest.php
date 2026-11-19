<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 149 §76 — token usage retention.
 *
 * The properties worth pinning are the ones that decide whether cost history
 * survives:
 *
 *  - retention deletes only rows outside the window, and keeps the whole
 *    cutoff day rather than a rolling 720 hours;
 *  - `--dry-run` counts the whole window without deleting;
 *  - `0` deletes nothing, never "everything";
 *  - a large window is deleted in chunks, so one statement never spans the
 *    whole history.
 */
class TokenUsageRetentionTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'nvoos_token_usage';

    public function test_pruning_deletes_only_rows_outside_the_window(): void
    {
        $this->insert(['created_at' => now()->subDays(40)]);
        $this->insert(['created_at' => now()->subDays(5)]);

        $this->artisan('nvoos:prune-token-usage', ['--days' => 30])
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame(
            1,
            DB::table(self::TABLE)->where('created_at', '>=', now()->subDays(10))->count()
        );
    }

    public function test_a_dry_run_counts_without_deleting(): void
    {
        $this->insert(['created_at' => now()->subDays(40)]);

        $this->artisan('nvoos:prune-token-usage', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('would be deleted')
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_zero_retention_disables_pruning(): void
    {
        $this->insert(['created_at' => now()->subDays(400)]);

        $this->artisan('nvoos:prune-token-usage', ['--days' => 0])
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count());
    }

    public function test_the_config_window_is_the_default_when_no_option_is_given(): void
    {
        config(['oos.token_usage.retention_days' => 30]);

        $this->insert(['created_at' => now()->subDays(40)]);
        $this->insert(['created_at' => now()->subDays(5)]);

        $this->artisan('nvoos:prune-token-usage')->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame(
            1,
            DB::table(self::TABLE)->where('created_at', '>=', now()->subDays(10))->count()
        );
    }

    public function test_the_whole_cutoff_day_is_kept(): void
    {
        Carbon::setTestNow('2026-11-19 12:00:00');

        $cutoff = now()->subDays(30)->startOfDay();

        $this->insert(['created_at' => $cutoff->copy()->subSecond()]);
        $this->insert(['created_at' => $cutoff->copy()]);

        $this->artisan('nvoos:prune-token-usage', ['--days' => 30])
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count(), 'The cutoff day itself must survive.');
    }

    public function test_pruning_deletes_across_multiple_chunks(): void
    {
        config(['oos.token_usage.prune_chunk' => 2]);

        for ($i = 0; $i < 5; $i++) {
            $this->insert(['created_at' => now()->subDays(40)]);
        }

        $this->insert(['created_at' => now()->subDays(5)]);

        $this->artisan('nvoos:prune-token-usage', ['--days' => 30])
            ->assertSuccessful();

        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame(
            1,
            DB::table(self::TABLE)->where('created_at', '>=', now()->subDays(10))->count()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insert(array $overrides = []): void
    {
        DB::table(self::TABLE)->insert(array_merge([
            'user_id' => 1,
            'model_id' => 'gpt-test',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
            'cost' => 0.001,
            'metadata' => json_encode(['provider' => 'openai']),
            'created_at' => now(),
        ], $overrides));
    }
}

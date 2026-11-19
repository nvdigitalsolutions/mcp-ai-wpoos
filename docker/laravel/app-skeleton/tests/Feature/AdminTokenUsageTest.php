<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\TokenUsage;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 149 §82 — token usage by user (`/ops/token-usage`).
 *
 * The page is only meaningful because §81 writes the rows it aggregates, so the
 * tests use the raw table directly and pin the three properties the page adds:
 * the aggregation itself, the period window, and the labels that stop a missing
 * user or a missing column from looking like a bug.
 */
class AdminTokenUsageTest extends TestCase
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
        $this->actingAs($this->operator())
            ->get('/ops/token-usage')
            ->assertOk()
            ->assertSee('Token usage')
            ->assertSee('Period');
    }

    public function test_usage_is_aggregated_per_user_highest_spend_first(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Operator']);
        $bob = User::factory()->create(['name' => 'Bob Builder']);

        $this->insert($alice->id, 100, 50, 0.02);
        $this->insert($alice->id, 10, 5, 0.001);
        $this->insert($bob->id, 200, 100, 0.05);

        $rows = Livewire::actingAs($this->operator())
            ->test(TokenUsage::class)
            ->instance()
            ->rows();

        $this->assertCount(2, $rows);

        $this->assertSame('Bob Builder', $rows[0]['name'], 'Highest spend sorts first.');
        $this->assertSame(1, $rows[0]['events']);
        $this->assertSame(300, $rows[0]['total_tokens']);
        $this->assertEqualsWithDelta(0.05, $rows[0]['cost'], 1e-6);

        $this->assertSame('Alice Operator', $rows[1]['name']);
        $this->assertSame(2, $rows[1]['events']);
        $this->assertSame(110, $rows[1]['prompt_tokens']);
        $this->assertSame(55, $rows[1]['completion_tokens']);
        $this->assertSame(165, $rows[1]['total_tokens']);
        $this->assertEqualsWithDelta(0.021, $rows[1]['cost'], 1e-6);
    }

    public function test_the_period_changes_the_window(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Operator']);
        $bob = User::factory()->create(['name' => 'Bob Builder']);

        $this->insert($alice->id, 100, 50, 0.02, now()->subDays(40));
        $this->insert($bob->id, 200, 100, 0.05);

        $component = Livewire::actingAs($this->operator())->test(TokenUsage::class);

        $this->assertCount(1, $component->instance()->rows(), '30 days excludes the 40-day-old row.');

        $component->set('period', 7);
        $this->assertCount(1, $component->instance()->rows());

        $component->set('period', 90);
        $this->assertCount(2, $component->instance()->rows(), '90 days includes it again.');
    }

    public function test_an_unknown_period_falls_back_to_thirty_days(): void
    {
        $this->insert(User::factory()->create()->id, 10, 5, 0.001, now()->subDays(40));

        $component = Livewire::actingAs($this->operator())->test(TokenUsage::class);
        $component->set('period', 3650);

        $this->assertSame(30, $component->instance()->period);
        $this->assertCount(0, $component->instance()->rows());
    }

    public function test_an_unauthenticated_turn_is_labelled_anonymous(): void
    {
        $this->insert(0, 10, 5, 0.001);

        $rows = Livewire::actingAs($this->operator())->test(TokenUsage::class)->instance()->rows();

        $this->assertSame('Anonymous', $rows[0]['name']);
    }

    public function test_an_estate_administrator_cannot_reach_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$customer->id => ['role' => 'owner']]);

        $this->actingAs($customer)
            ->get('/ops/token-usage')
            ->assertForbidden();
    }

    private function insert(int $userId, int $prompt, int $completion, float $cost, ?Carbon $at = null): void
    {
        DB::table(self::TABLE)->insert([
            'user_id' => $userId,
            'model_id' => 'gpt-4o-mini',
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost' => $cost,
            'metadata' => json_encode(['provider' => 'openai']),
            'created_at' => $at ?? now(),
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

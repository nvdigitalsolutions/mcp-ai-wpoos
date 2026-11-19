<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Budgets\BudgetStore;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Estate budgets — Proposal 154.
 *
 * The properties a budget has to have to be a budget rather than a report:
 *
 *  - the check runs **before** the provider call, so an exhausted estate is
 *    refused rather than billed and told afterwards;
 *  - the **soft band warns and allows**, because a single hard line gives no
 *    notice before an estate's chat stops;
 *  - it **fails closed** when spend cannot be read — an unreadable counter
 *    under-reports, and under-reporting is over-spending;
 *  - an estate's limit is **its own**: Proposal 153's scope, applied to the new
 *    model, is what makes that true by construction.
 */
class BudgetEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private const SPEND_TABLE = 'nvoos_token_usage';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-19 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_no_budget_allows_the_turn(): void
    {
        $this->assertTurnCompleted($this->chat($this->owner()));
    }

    public function test_an_estate_at_its_limit_is_refused(): void
    {
        $user = $this->owner();

        $this->budget(Budget::DAILY, 0.01);
        $this->spend(0.02);

        $response = $this->chat($user)->assertStatus(429);

        $response->assertJsonPath('code', 'budget_exceeded');
        $response->assertJsonPath('window', Budget::DAILY);
        $this->assertEqualsWithDelta(0.02, (float) $response->json('spend'), 1e-6);
        $this->assertEqualsWithDelta(0.01, (float) $response->json('limit'), 1e-6);
    }

    public function test_an_estate_below_its_limit_is_allowed(): void
    {
        $user = $this->owner();

        $this->budget(Budget::DAILY, 10.0);
        $this->spend(0.02);

        $this->assertTurnCompleted($this->chat($user));
    }

    /**
     * The soft band exists so an estate hears about the limit before chat stops.
     */
    public function test_the_warning_band_warns_and_allows(): void
    {
        Log::spy();

        $user = $this->owner();

        $this->budget(Budget::DAILY, 1.0, 0.5);
        $this->spend(0.6);

        $this->assertTurnCompleted($this->chat($user));

        Log::shouldHaveReceived('warning')
            ->with('budget.warn', Mockery::type('array'))
            ->once();
    }

    public function test_a_disabled_budget_is_ignored(): void
    {
        $user = $this->owner();

        $this->budget(Budget::DAILY, 0.01, 0.8, enabled: false);
        $this->spend(5.0);

        $this->assertTurnCompleted($this->chat($user));
    }

    /**
     * A zero limit is a misconfiguration, not an instruction to block every
     * turn: failing it closed would take an estate offline over a typo.
     */
    public function test_a_zero_limit_means_no_limit(): void
    {
        $user = $this->owner();

        $this->budget(Budget::DAILY, 0.0);
        $this->spend(5.0);

        $this->assertTurnCompleted($this->chat($user));
    }

    public function test_yesterdays_spend_does_not_count_against_a_daily_budget(): void
    {
        $user = $this->owner();

        $this->budget(Budget::DAILY, 1.0);
        $this->spend(5.0, '2026-11-18 23:59:59');

        $this->assertTurnCompleted($this->chat($user));
    }

    public function test_a_monthly_budget_includes_the_whole_month(): void
    {
        $user = $this->owner();

        $this->budget(Budget::MONTHLY, 1.0);
        $this->spend(5.0, '2026-11-01 00:00:01');

        $this->chat($user)->assertStatus(429);
    }

    /**
     * Proposal 153's scope is what makes this true by construction: the guard
     * asks for "the current estate's budgets", never for a budget by id.
     */
    public function test_another_estates_budget_does_not_apply(): void
    {
        Tenant::query()->create(array('id' => 'estate-b', 'name' => 'Estate B', 'slug' => 'estate-b'));

        TenantContext::set('estate-b');
        $this->budget(Budget::DAILY, 0.01);
        TenantContext::forget();

        // Spend on estate B, over its limit.
        $this->spend(5.0, null, 'estate-b');

        // A caller in `default`, which has no budget at all.
        $this->assertTurnCompleted($this->chat($this->owner()));
    }

    /**
     * Fail closed: an unreadable budget must refuse, because a counter that
     * under-reports over-spends.
     */
    public function test_an_unreadable_budget_refuses_the_turn(): void
    {
        $user = $this->owner();

        $this->mock(BudgetStore::class, function ($mock): void {
            $mock->shouldReceive('forCurrentEstate')->andThrow(new \RuntimeException('database is down'));
        });

        $response = $this->chat($user)->assertStatus(503);

        $response->assertJsonPath('code', 'budget_unverifiable');
    }

    /**
     * The second spending entry point: the assistant test probe runs a real turn.
     */
    public function test_the_assistant_test_endpoint_is_guarded_too(): void
    {
        $user = $this->owner();
        $assistantId = $this->assistant($user);

        $this->budget(Budget::DAILY, 0.01);
        $this->spend(0.02);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/assistants/{$assistantId}/test", array('message' => 'Hello'))
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'budget_exceeded');
    }

    private function assertTurnCompleted($response): void
    {
        $response->assertOk();

        $this->assertStringContainsString('[DONE]', $response->streamedContent());
    }

    /**
     * One chat turn as the given user, against a real assistant on the spike
     * provider so the turn completes without a network call.
     */
    private function chat(User $user)
    {
        $assistantId = $this->assistant($user);

        return $this->actingAs($user, 'sanctum')->post('/api/v1/chat-client', array(
            'messages' => array(array('role' => 'user', 'content' => 'Hello')),
            'assistant_id' => $assistantId,
            'options' => array(),
        ));
    }

    private function assistant(User $user): int
    {
        return (int) $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/assistants', array(
                'title' => 'Budget subject',
                'system_prompt' => 'Be brief.',
                'provider' => 'spike',
                'model' => 'spike-1',
            ))
            ->assertCreated()
            ->json('id');
    }

    private function budget(string $window, float $limit, float $warnAt = 0.8, bool $enabled = true): Budget
    {
        return Budget::query()->create(array(
            'window' => $window,
            'limit_usd' => $limit,
            'warn_at' => $warnAt,
            'enabled' => $enabled,
        ));
    }

    private function spend(float $cost, ?string $at = null, string $tenantId = Tenant::DEFAULT_ID): void
    {
        DB::table(self::SPEND_TABLE)->insert(array(
            'tenant_id' => $tenantId,
            'user_id' => 1,
            'model_id' => 'spike-1',
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
            'cost' => $cost,
            'metadata' => json_encode(array('provider' => 'spike')),
            'created_at' => $at ?? now(),
        ));
    }

    private function owner(): User
    {
        return User::factory()->admin()->create();
    }
}

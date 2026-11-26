<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\TokenUsage as TokenUsagePage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Nvoos\Core\Domain\Event\CostCalculated;
use Nvoos\Laravel\Adapter\TokenUsage;
use Nvoos\Laravel\Adapter\TokenUsageRollup;
use Tests\TestCase;

/**
 * 149 §86 / Proposal 152 — token telemetry is estate-scoped.
 *
 * Before this, `/api/v1/cost/*` sat in the `manage_options` group and read
 * `nvoos_token_usage` with no scope, `top_users` resolved to names. An estate
 * administrator could read every other estate's spend and the names of the
 * people spending it. The same shape as §18's workflow-run leak, but reachable
 * on a live route.
 *
 * The tests pin the whole path, because a leak is never one function:
 *
 *  - the **writer** stamps the estate from `TenantContext` (D4);
 *  - the **estate readers** answer one estate, names included;
 *  - the **operator page** still answers every estate (D5);
 *  - the **rollup** keys by estate, so two estates' same-model day are two rows
 *    and a re-run rewrites both instead of one overwriting the other (D6).
 */
class TokenUsageTenancyTest extends TestCase
{
    use RefreshDatabase;

    private const RAW = 'nvoos_token_usage';

    private const DAILY = 'nvoos_token_usage_daily';

    private const ESTATE_A = 'estate-a';

    private const ESTATE_B = 'estate-b';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        Carbon::setTestNow('2026-11-19 12:00:00');

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');

        foreach ([self::ESTATE_A => 'Estate A', self::ESTATE_B => 'Estate B'] as $id => $name) {
            Tenant::query()->create(array(
                'id' => $id,
                'name' => $name,
                'slug' => $id,
            ));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * The leak, stated as it would be exploited.
     */
    public function test_an_estate_administrator_reads_only_their_own_estate(): void
    {
        $alice = User::factory()->admin()->inEstate(self::ESTATE_A, 'owner')
            ->create(array('name' => 'Alice Estate A'));
        $bob = User::factory()->admin()->inEstate(self::ESTATE_B, 'owner')
            ->create(array('name' => 'Bob Estate B'));

        $this->insert(self::ESTATE_A, $alice->id, 100, 50, 0.02);
        $this->insert(self::ESTATE_B, $bob->id, 900, 450, 0.50);

        Sanctum::actingAs($alice);

        $response = $this->withHeader('X-Tenant-Id', self::ESTATE_A)
            ->getJson('/api/v1/cost/dashboard-summary?days=30')
            ->assertOk()

            // Totals: A's 150 tokens and $0.02, not the deployment's 1,500 / $0.52.
            ->assertJsonPath('summary.total_tokens', 150);

        $this->assertEqualsWithDelta(
            0.02,
            (float) $response->json('summary.by_provider.openai'),
            1e-6,
            'By-provider must be the estate\'s spend.'
        );

        $this->assertEqualsWithDelta(
            0.02,
            (float) $response->json('summary.total_cost'),
            1e-6,
            'The estate summary must not include another estate\'s spend.'
        );

        // `top_users` resolves ids to names, so a leaked row leaks a person.
        $names = array_column(array_values((array) $response->json('summary.top_users')), 'name');

        $this->assertContains('Alice Estate A', $names);
        $this->assertNotContains('Bob Estate B', $names);
        $this->assertStringNotContainsString(
            'Bob Estate B',
            (string) $response->getContent(),
            'No part of the payload may carry another estate\'s user.'
        );
    }

    /**
     * The same scope applies to the two chart endpoints, which read their own
     * path through `summary()` / the rollup.
     */
    public function test_the_chart_endpoints_are_scoped_too(): void
    {
        $alice = User::factory()->admin()->inEstate(self::ESTATE_A, 'owner')->create();
        $bob = User::factory()->admin()->inEstate(self::ESTATE_B, 'owner')->create();

        $this->insert(self::ESTATE_A, $alice->id, 100, 0, 0.02);
        $this->insert(self::ESTATE_B, $bob->id, 900, 0, 0.50);

        Sanctum::actingAs($alice);
        $this->withHeader('X-Tenant-Id', self::ESTATE_A);

        $trend = $this->getJson('/api/v1/cost/trend?days=7')->assertOk()->json('data');
        $this->assertEqualsWithDelta(
            0.02,
            array_sum($trend['datasets'][0]['data']),
            1e-6,
            'The trend series must sum to one estate, not the deployment.'
        );

        $byProvider = $this->getJson('/api/v1/cost/by-provider?days=30')->assertOk()->json('data');
        $this->assertEqualsWithDelta(
            0.02,
            array_sum($byProvider['datasets'][0]['data']),
            1e-6
        );
    }

    /**
     * An operator surface must keep the whole deployment in view — the scope is
     * a choice at the call site, not a blanket filter (D5).
     */
    public function test_the_operator_page_sees_every_estate(): void
    {
        $alice = User::factory()->admin()->inEstate(self::ESTATE_A, 'owner')
            ->create(array('name' => 'Alice Estate A'));
        $bob = User::factory()->admin()->inEstate(self::ESTATE_B, 'owner')
            ->create(array('name' => 'Bob Estate B'));

        $this->insert(self::ESTATE_A, $alice->id, 100, 50, 0.02);
        $this->insert(self::ESTATE_B, $bob->id, 900, 450, 0.50);

        $rows = Livewire::actingAs($this->operator())
            ->test(TokenUsagePage::class)
            ->instance()
            ->rows();

        $names = array_column($rows, 'name');

        $this->assertContains('Alice Estate A', $names);
        $this->assertContains('Bob Estate B', $names);
        $this->assertSame(
            1500,
            (int) array_sum(array_column($rows, 'total_tokens')),
            'The operator view must aggregate every estate.'
        );
    }

    /**
     * D6 — two estates publishing the same model on the same day are two rows.
     * The old `(day, provider, model_id)` key would collide and the second
     * upsert would overwrite the first: silent data loss, not a scoping
     * preference.
     */
    public function test_the_rollup_keeps_two_estates_apart(): void
    {
        $this->insertAt(self::ESTATE_A, '2026-11-18 09:00:00', 'gpt-5', 'openai', 100, 20, 0.50);
        $this->insertAt(self::ESTATE_B, '2026-11-18 10:00:00', 'gpt-5', 'openai', 200, 40, 1.00);

        $rollup = app(TokenUsageRollup::class);
        $day = CarbonImmutable::parse('2026-11-18');

        $rollup->rollupDay($day);

        $this->assertSame(2, DB::table(self::DAILY)->count(), 'One row per estate, not one per model.');

        $a = DB::table(self::DAILY)->where('tenant_id', self::ESTATE_A)->first();
        $b = DB::table(self::DAILY)->where('tenant_id', self::ESTATE_B)->first();

        $this->assertEqualsWithDelta(0.50, (float) $a->cost, 1e-6);
        $this->assertEqualsWithDelta(1.00, (float) $b->cost, 1e-6);
        $this->assertSame(120, (int) $a->total_tokens);
        $this->assertSame(240, (int) $b->total_tokens);
    }

    /**
     * A re-run must rewrite both estates' rows, not one of them.
     */
    public function test_rerunning_the_rollup_does_not_overwrite_one_estate(): void
    {
        $this->insertAt(self::ESTATE_A, '2026-11-18 09:00:00', 'gpt-5', 'openai', 100, 20, 0.50);
        $this->insertAt(self::ESTATE_B, '2026-11-18 10:00:00', 'gpt-5', 'openai', 200, 40, 1.00);

        $rollup = app(TokenUsageRollup::class);
        $day = CarbonImmutable::parse('2026-11-18');

        $rollup->rollupDay($day);
        $rollup->rollupDay($day);

        $this->assertSame(2, DB::table(self::DAILY)->count());
        $this->assertSame(1, DB::table(self::DAILY)->where('tenant_id', self::ESTATE_A)->count());
        $this->assertSame(1, DB::table(self::DAILY)->where('tenant_id', self::ESTATE_B)->count());

        $this->assertEqualsWithDelta(
            0.50,
            (float) DB::table(self::DAILY)->where('tenant_id', self::ESTATE_A)->value('cost'),
            1e-6
        );
        $this->assertEqualsWithDelta(
            1.00,
            (float) DB::table(self::DAILY)->where('tenant_id', self::ESTATE_B)->value('cost'),
            1e-6
        );
    }

    /**
     * The trend survives retention per estate: with the raw rows gone, the
     * aggregate for one estate must not stand in for both.
     */
    public function test_the_trend_falls_back_to_one_estates_rollup(): void
    {
        $oldDay = '2026-10-10';

        $this->insertDaily(self::ESTATE_A, $oldDay, 2.50);
        $this->insertDaily(self::ESTATE_B, $oldDay, 7.50);

        $usage = app(TokenUsage::class);

        $scoped = $usage->trend(45, self::ESTATE_A);
        $index = array_search($oldDay, $scoped['labels'], true);

        $this->assertNotFalse($index);
        $this->assertEqualsWithDelta(
            2.50,
            $scoped['datasets'][0]['data'][$index],
            1e-6,
            'One estate\'s rollup must not be added to another\'s.'
        );

        $platform = $usage->trend(45, null);
        $platformIndex = array_search($oldDay, $platform['labels'], true);

        $this->assertNotFalse($platformIndex);
        $this->assertEqualsWithDelta(10.00, $platform['datasets'][0]['data'][$platformIndex], 1e-6);
    }

    /**
     * D3 — a row that records no estate is `default`, never guessed.
     */
    public function test_a_row_without_an_estate_is_attributed_to_default(): void
    {
        app(TokenUsage::class)->trackUsage(1, 'gpt-test', 10, 5, array('provider' => 'openai'));

        $this->assertSame(
            Tenant::DEFAULT_ID,
            (string) DB::table(self::RAW)->value('tenant_id')
        );
    }

    /**
     * D4 end-to-end — the listener reads the request's estate into metadata and
     * the adapter writes it as a column.
     */
    public function test_the_writer_stamps_the_pinned_estate(): void
    {
        TenantContext::set(self::ESTATE_A);

        event(new CostCalculated(
            costData: [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
            ],
            assistantId: 7,
            userId: 1,
            response: [],
        ));

        $this->assertSame(
            self::ESTATE_A,
            (string) DB::table(self::RAW)->value('tenant_id')
        );
    }

    private function insert(
        string $tenantId,
        int $userId,
        int $prompt,
        int $completion,
        float $cost
    ): void {
        $this->insertAt($tenantId, '2026-11-19 09:00:00', 'gpt-4o-mini', 'openai', $prompt, $completion, $cost, $userId);
    }

    private function insertAt(
        string $tenantId,
        string $at,
        string $model,
        string $provider,
        int $prompt,
        int $completion,
        float $cost,
        int $userId = 1
    ): void {
        DB::table(self::RAW)->insert(array(
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'model_id' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost' => $cost,
            'metadata' => json_encode(array('provider' => $provider)),
            'created_at' => $at,
        ));
    }

    private function insertDaily(string $tenantId, string $day, float $cost): void
    {
        DB::table(self::DAILY)->insert(array(
            'tenant_id' => $tenantId,
            'day' => $day,
            'provider' => 'openai',
            'model_id' => 'gpt-5',
            'events' => 1,
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'total_tokens' => 15,
            'cost' => $cost,
            'updated_at' => now(),
        ));
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

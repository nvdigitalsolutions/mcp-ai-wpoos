<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Budgets;
use App\Models\Budget;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 154 slice B — `/admin/budgets`, the estate's view of its own limit.
 *
 * Read-only on purpose (D8): a limit the limited party can raise is a
 * self-throttle, not a control, so the operator writes (`AdminBudgetsTest`) and
 * the estate reads. The properties pinned here are the ones that make reading
 * worth doing:
 *
 *  - the page is scoped by `Budget`'s tenant scope, so it cannot show one
 *    estate's limit to another;
 *  - the callout fires from the guard's own verdict, so "over budget" here means
 *    the runtime is refusing turns — not that a number looks high;
 *  - there is no write path at all, which is how read-only is enforced rather
 *    than merely styled.
 */
class EstateBudgetsPageTest extends TestCase
{
    use RefreshDatabase;

    private const SPEND_TABLE = 'nvoos_token_usage';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Carbon::setTestNow('2026-11-19 12:00:00');

        config(['sanctum.stateful' => array('localhost', '127.0.0.1')]);
        $this->withHeader('Referer', 'http://localhost');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_renders_for_the_default_estate(): void
    {
        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 10.0);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID))
            ->get('/admin/default/budgets')
            ->assertOk()
            ->assertSee('Spend and limits')
            ->assertSee('Daily');
    }

    /**
     * The scope is the model's, not a filter on this query: another estate's
     * limit must not appear at all.
     */
    public function test_only_the_current_estates_budgets_are_listed(): void
    {
        $this->estate('estate-b');

        $own = $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 10.0);
        $foreign = $this->budget('estate-b', Budget::MONTHLY, 99.0);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(Budgets::class)
            ->assertCanSeeTableRecords(array($own))
            ->assertCanNotSeeTableRecords(array($foreign));
    }

    /**
     * The whole point of the page: the estate learns it is over before a user's
     * chat turn returns 429.
     */
    public function test_an_exhausted_budget_raises_the_over_budget_callout(): void
    {
        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 0.01);
        $this->spend(0.05);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));

        Livewire::test(Budgets::class)
            ->assertOk()
            ->assertSee('Over budget')
            ->assertSee('500.0%');
    }

    public function test_the_warning_band_raises_the_approaching_callout(): void
    {
        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 1.0, 0.5);
        $this->spend(0.6);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));

        Livewire::test(Budgets::class)
            ->assertOk()
            ->assertSee('Approaching a limit')
            ->assertDontSee('Over budget');
    }

    /**
     * Read-only is enforced by having no write path. A disabled button would
     * imply a permission the estate does not have.
     */
    public function test_the_page_offers_no_write_action(): void
    {
        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 10.0);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));

        Livewire::test(Budgets::class)
            ->assertOk()
            ->assertDontSee('Set a budget')
            ->assertDontSee('Delete')
            ->assertDontSee('Edit');
    }

    public function test_an_estate_with_no_limit_sees_the_empty_state(): void
    {
        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));

        Livewire::test(Budgets::class)
            ->assertOk()
            ->assertSee('No limit is set for this estate')
            ->assertDontSee('Over budget');
    }

    /**
     * The estate has to be able to tell which agent a refusal is about — a
     * nameless limit reads like the whole estate is blocked.
     */
    public function test_an_assistant_limit_is_listed_with_its_scope(): void
    {
        $assistantId = $this->assistant(Tenant::DEFAULT_ID);

        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 5.0);
        $this->budget(Tenant::DEFAULT_ID, Budget::MONTHLY, 50.0, assistantId: $assistantId);

        $this->actingAs($this->estateAdmin(Tenant::DEFAULT_ID));

        Livewire::test(Budgets::class)
            ->assertOk()
            ->assertSee('Whole estate')
            ->assertSee('Support triage');
    }

    private function budget(
        string $tenantId,
        string $window,
        float $limit,
        float $warnAt = 0.8,
        bool $enabled = true,
        int $assistantId = 0,
    ): Budget {
        $budget = new Budget();

        $budget->tenant_id = $tenantId;
        $budget->assistant_id = $assistantId;
        $budget->window = $window;
        $budget->limit_usd = $limit;
        $budget->warn_at = $warnAt;
        $budget->enabled = $enabled;
        $budget->save();

        return $budget;
    }

    /**
     * A real assistant in the named estate, through the sanctioned writer.
     */
    private function assistant(string $tenantId, string $title = 'Support triage'): int
    {
        $previous = TenantContext::current();
        TenantContext::set($tenantId);

        try {
            return (int) app(AssistantWriter::class)
                ->create(array('title' => $title, 'system_prompt' => 'Be brief.'))
                ->getKey();
        } finally {
            TenantContext::set($previous);
        }
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

    private function estate(string $tenantId): Tenant
    {
        return Tenant::query()->firstOrCreate(
            array('id' => $tenantId),
            array(
                'name' => 'Estate B',
                'slug' => $tenantId,
                'is_operator' => false,
            )
        );
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail($tenantId)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}

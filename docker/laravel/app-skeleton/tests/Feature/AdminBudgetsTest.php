<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\Budgets;
use App\Models\Budget;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\Budgets\BudgetStore;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 154 slice B — `/ops/budgets`, the operator's half of the budget.
 *
 * §92 made the runtime refuse a turn when an estate is over its limit; until this
 * page, setting the limit was a database row. The properties pinned here are the
 * ones that make the page trustworthy rather than merely present:
 *
 *  - the numbers it prints are `BudgetStore::verdict()` — the guard's own call —
 *    so a badge cannot say "fine" while enforcement refuses;
 *  - a write names the estate explicitly (the page exists to act on *another*
 *    estate) and is refused when the estate does not exist;
 *  - every write leaves an audit row: Livewire traffic is excluded from the
 *    request-level audit, so a limit changed here would otherwise be untraceable.
 */
class AdminBudgetsTest extends TestCase
{
    use RefreshDatabase;

    private const SPEND_TABLE = 'nvoos_token_usage';

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

    public function test_the_page_lists_every_estates_budgets(): void
    {
        $this->estate('estate-b');

        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 10.0);
        $this->budget('estate-b', Budget::MONTHLY, 250.0);

        $this->actingAs($this->operator())
            ->get('/ops/budgets')
            ->assertOk()
            ->assertSee('Budgets')
            ->assertSee('Default estate')
            ->assertSee('Estate B')
            ->assertSee('Daily')
            ->assertSee('Monthly');
    }

    /**
     * The callout is the page's reason for existing at a glance: an operator
     * should not have to read rows to learn that the runtime is refusing turns.
     */
    public function test_an_exhausted_budget_is_called_out_with_its_ratio(): void
    {
        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 0.01);
        $this->spend(0.05);

        $this->actingAs($this->operator())
            ->get('/ops/budgets')
            ->assertOk()
            ->assertSee('1 budget(s) exhausted')
            // 0.05 against a 0.01 limit: the same percentage the store computes.
            ->assertSee('500.0%');
    }

    public function test_a_budget_under_its_warning_band_is_not_called_out(): void
    {
        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 10.0, 0.8);
        $this->spend(0.02);

        $this->actingAs($this->operator())
            ->get('/ops/budgets')
            ->assertOk()
            ->assertDontSee('budget(s) exhausted')
            ->assertSee('0.2%');
    }

    public function test_creating_a_budget_writes_it_for_the_chosen_estate(): void
    {
        $this->estate('estate-b');

        $this->actingAs($this->operator());

        Livewire::test(Budgets::class)
            ->callAction('create', data: array(
                'tenant_id' => 'estate-b',
                'window' => Budget::DAILY,
                'limit_usd' => 25.5,
                'warn_at' => 0.9,
                'enabled' => true,
            ))
            ->assertNotified();

        $budget = Budget::query()->withoutGlobalScopes()->sole();

        $this->assertSame('estate-b', $budget->tenant_id);
        $this->assertSame(Budget::DAILY, $budget->window);
        $this->assertEqualsWithDelta(25.5, (float) $budget->limit_usd, 1e-6);
        $this->assertEqualsWithDelta(0.9, (float) $budget->warn_at, 1e-6);
        $this->assertTrue((bool) $budget->enabled);
    }

    /**
     * A tampered Livewire payload must not create a limit under an estate that
     * does not exist: it would never apply to a real user and would sit in the
     * table looking like a control.
     *
     * Two layers refuse it — the select's option set (which is what fires here)
     * and the explicit `Tenant::find()` in `store()` behind it, so replacing the
     * options closure with something permissive would not open a hole.
     */
    public function test_creating_a_budget_for_an_unknown_estate_is_refused(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(Budgets::class)
            ->callAction('create', data: array(
                'tenant_id' => 'no-such-estate',
                'window' => Budget::DAILY,
                'limit_usd' => 10.0,
                'warn_at' => 0.8,
                'enabled' => true,
            ))
            ->assertHasFormErrors(array('tenant_id'));

        $this->assertSame(0, Budget::query()->withoutGlobalScopes()->count());
    }

    /**
     * `(tenant_id, window)` is unique, so setting an existing pair is an edit —
     * the page's modal description says so, and this pins it.
     */
    public function test_setting_an_existing_pair_replaces_the_limit(): void
    {
        $this->estate('estate-b');
        $this->budget('estate-b', Budget::DAILY, 10.0);

        $this->actingAs($this->operator());

        Livewire::test(Budgets::class)
            ->callAction('create', data: array(
                'tenant_id' => 'estate-b',
                'window' => Budget::DAILY,
                'limit_usd' => 40.0,
                'warn_at' => 0.8,
                'enabled' => true,
            ));

        $this->assertSame(1, Budget::query()->withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(
            40.0,
            (float) Budget::query()->withoutGlobalScopes()->sole()->limit_usd,
            1e-6
        );
    }

    /**
     * The estate and the window identify the budget, so the edit form cannot
     * carry them — a rename is a delete-and-create.
     */
    public function test_editing_a_budget_changes_the_limit_and_keeps_the_identity(): void
    {
        $this->estate('estate-b');
        $budget = $this->budget('estate-b', Budget::MONTHLY, 100.0);

        $this->actingAs($this->operator());

        Livewire::test(Budgets::class)
            ->callTableAction('edit', $budget, data: array(
                'limit_usd' => 150.0,
                'warn_at' => 0.5,
                'enabled' => false,
            ));

        $budget->refresh();

        $this->assertSame('estate-b', $budget->tenant_id);
        $this->assertSame(Budget::MONTHLY, $budget->window);
        $this->assertEqualsWithDelta(150.0, (float) $budget->limit_usd, 1e-6);
        $this->assertEqualsWithDelta(0.5, (float) $budget->warn_at, 1e-6);
        $this->assertFalse((bool) $budget->enabled);
    }

    /**
     * A narrowed limit is a different row from the estate-wide one, and it names
     * the assistant it narrows to.
     */
    public function test_creating_an_assistant_budget_writes_the_scope(): void
    {
        $assistantId = $this->assistant(Tenant::DEFAULT_ID, 'Support triage');

        $this->actingAs($this->operator());

        Livewire::test(Budgets::class)
            ->callAction('create', data: array(
                'tenant_id' => Tenant::DEFAULT_ID,
                'assistant_id' => $assistantId,
                'window' => Budget::DAILY,
                'limit_usd' => 2.0,
                'warn_at' => 0.8,
                'enabled' => true,
            ))
            ->assertNotified();

        $budget = Budget::query()->withoutGlobalScopes()->sole();

        $this->assertSame($assistantId, (int) $budget->assistant_id);
        $this->assertSame(Tenant::DEFAULT_ID, $budget->tenant_id);
    }

    /**
     * An estate-wide limit and an assistant limit coexist for the same window —
     * the case the original `(tenant, window)` key made impossible.
     */
    public function test_an_estate_and_an_assistant_can_both_limit_the_same_window(): void
    {
        $assistantId = $this->assistant(Tenant::DEFAULT_ID, 'Support triage');

        $this->actingAs($this->operator());

        $page = Livewire::test(Budgets::class);

        foreach (array(0, $assistantId) as $scope) {
            $page->callAction('create', data: array(
                'tenant_id' => Tenant::DEFAULT_ID,
                'assistant_id' => $scope,
                'window' => Budget::DAILY,
                'limit_usd' => 5.0,
                'warn_at' => 0.8,
                'enabled' => true,
            ));
        }

        $this->assertSame(2, Budget::query()->withoutGlobalScopes()->count());
    }

    /**
     * Two layers refuse a foreign assistant, and both are worth pinning: the
     * select's option set (which is what fires through the form) and the store's
     * own check (which holds if the options closure is ever loosened).
     */
    public function test_the_form_refuses_a_budget_naming_an_assistant_from_another_estate(): void
    {
        $this->estate('estate-b');

        // In `default`; the budget below names `estate-b`.
        $assistantId = $this->assistant(Tenant::DEFAULT_ID, 'Support triage');

        $this->actingAs($this->operator());

        Livewire::test(Budgets::class)
            ->callAction('create', data: array(
                'tenant_id' => 'estate-b',
                'assistant_id' => $assistantId,
                'window' => Budget::DAILY,
                'limit_usd' => 5.0,
                'warn_at' => 0.8,
                'enabled' => true,
            ))
            ->assertHasFormErrors(array('assistant_id'));

        $this->assertSame(0, Budget::query()->withoutGlobalScopes()->count());
    }

    public function test_the_store_refuses_a_budget_naming_an_assistant_from_another_estate(): void
    {
        $this->estate('estate-b');

        $assistantId = $this->assistant(Tenant::DEFAULT_ID, 'Support triage');

        $this->expectException(InvalidArgumentException::class);

        // A limit whose assistant is in another estate would never match a turn
        // while still looking configured, so it must not be written at all.
        app(BudgetStore::class)->put('estate-b', Budget::DAILY, 5.0, 0.8, true, $assistantId);
    }

    public function test_the_scope_column_names_the_assistant(): void
    {
        $assistantId = $this->assistant(Tenant::DEFAULT_ID, 'Support triage');

        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 5.0);
        $this->budget(Tenant::DEFAULT_ID, Budget::MONTHLY, 50.0, assistantId: $assistantId);

        $this->actingAs($this->operator())
            ->get('/ops/budgets')
            ->assertOk()
            ->assertSee('Whole estate')
            ->assertSee('Support triage');
    }

    public function test_deleting_a_budget_removes_it(): void
    {
        $budget = $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 10.0);

        $this->actingAs($this->operator());

        Livewire::test(Budgets::class)
            ->callTableAction('delete', $budget);

        $this->assertSame(0, Budget::query()->withoutGlobalScopes()->count());
    }

    public function test_every_write_leaves_an_audit_row(): void
    {
        $this->estate('estate-b');

        $this->actingAs($this->operator());

        $page = Livewire::test(Budgets::class);

        $page->callAction('create', data: array(
            'tenant_id' => 'estate-b',
            'window' => Budget::DAILY,
            'limit_usd' => 10.0,
            'warn_at' => 0.8,
            'enabled' => true,
        ));

        $budget = Budget::query()->withoutGlobalScopes()->sole();

        $page->callTableAction('edit', $budget, data: array(
            'limit_usd' => 20.0,
            'warn_at' => 0.8,
            'enabled' => true,
        ));

        $budget->refresh();

        $page->callTableAction('delete', $budget);

        $actions = DB::table('nvoos_admin_audit')
            ->where('resource', 'budget')
            ->orderBy('id')
            ->pluck('action')
            ->all();

        $this->assertSame(array('created', 'updated', 'deleted'), $actions);
    }

    public function test_the_page_requires_the_operator_panel(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/ops/budgets')
            ->assertForbidden();
    }

    /**
     * The page and the guard read one function; if this ever diverges, a badge
     * could disagree with a refusal.
     */
    public function test_the_page_reads_the_guards_own_verdict(): void
    {
        $this->budget(Tenant::DEFAULT_ID, Budget::DAILY, 1.0, 0.5);
        $this->spend(0.6);

        $this->actingAs($this->operator());

        Livewire::test(Budgets::class)
            ->assertOk()
            ->assertSee('60.0%');
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
     * A real assistant in the named estate.
     *
     * Created through `AssistantWriter` rather than the API: the operator is a
     * member of two estates, so an estate-less API call answers 409 and the test
     * would be exercising the tenant resolver instead of the page.
     */
    private function assistant(string $tenantId, string $title = 'Budget subject'): int
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

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}

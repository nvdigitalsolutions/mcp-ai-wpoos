<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\CommandCentre;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §106 — the panel's command centre.
 *
 * The slice existed to close one gap and opened another: the overview could not
 * be put on an estate page while its feed was platform-wide, so
 * `nvoos_activity_events` gained an estate first. This test therefore asserts
 * two different things:
 *
 *  - **the port** — the same six figures the console renders, on a panel page;
 *  - **the scope** — none of them may be another estate's. A KPI that leaks is
 *    worse than a KPI that is missing, because it looks right.
 *
 * The honesty devices are asserted too, deliberately: an unsampled uptime must
 * render a dash with a reason, never `0`, and an empty feed must say "nothing
 * recorded" rather than imply nothing happened.
 */
final class AdminCommandCentreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_it_requires_the_tenant_panel(): void
    {
        $this->actingAs(User::factory()->create(array('role' => 'subscriber')));

        $this->assertFalse(CommandCentre::canAccess());
    }

    public function test_it_renders_the_current_estates_figures(): void
    {
        $this->assistant(Tenant::DEFAULT_ID, 'Own agent', 'publish');
        $this->tokenRow(Tenant::DEFAULT_ID, 500);
        $this->event(Tenant::DEFAULT_ID, 'workflow.completed', 'own event');

        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(CommandCentre::class)
            ->assertSee('Command centre')
            ->assertSee('Own agent')
            ->assertSee('own event')
            ->assertSee('500');
    }

    public function test_another_estates_figures_never_appear(): void
    {
        $this->estate('estate-b');

        $this->assistant(Tenant::DEFAULT_ID, 'Own agent', 'publish');
        $this->assistant('estate-b', 'Foreign agent', 'publish');

        $this->tokenRow(Tenant::DEFAULT_ID, 500);
        $this->tokenRow('estate-b', 9999);

        $this->event(Tenant::DEFAULT_ID, 'workflow.completed', 'own event');
        $this->event('estate-b', 'workflow.completed', 'foreign event');

        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(CommandCentre::class)
            ->assertSee('Own agent')
            ->assertSee('own event')
            ->assertDontSee('Foreign agent')
            ->assertDontSee('foreign event')
            // The foreign estate's consumption must not be summed into this one.
            ->assertDontSee('9,999');
    }

    public function test_an_unsampled_uptime_is_a_dash_with_a_reason_not_a_zero(): void
    {
        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(CommandCentre::class)
            ->assertSee('no sample recorded yet')
            ->assertSee('—');
    }

    public function test_uptime_reports_the_healthy_share_once_sampled(): void
    {
        DB::table('nvoos_health_samples')->insert(array(
            array('check' => 'core', 'healthy' => true, 'recorded_at' => now()),
            array('check' => 'core', 'healthy' => false, 'recorded_at' => now()),
        ));

        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(CommandCentre::class)->assertSee('50.0%');
    }

    public function test_a_silent_estate_says_nothing_was_recorded(): void
    {
        $this->actingAsEstate(Tenant::DEFAULT_ID);

        Livewire::test(CommandCentre::class)
            ->assertSee('No activity recorded')
            ->assertSee('Nothing recorded in this estate.');
    }

    /**
     * The report is deterministic about its estate: the ambient context is not
     * a second scope. `Assistant` carries a `tenant` global scope, so a report
     * for acme read from a request pinned to globex must still describe acme.
     */
    public function test_the_report_uses_the_estate_it_is_given_not_the_ambient_one(): void
    {
        $this->estate('estate-b');

        $this->assistant('estate-b', 'Foreign agent', 'publish');

        TenantContext::set(Tenant::DEFAULT_ID);

        $report = app(\App\Support\CommandCentre\CommandCentreReport::class)->report('estate-b');

        $this->assertSame(1, $report['kpis']['total_agents']);
        $this->assertSame('Foreign agent', array_values($report['agent_statuses'])[0]['title']);
    }

    private function actingAsEstate(string $tenantId): void
    {
        $tenant = $this->estate($tenantId);

        $this->actingAs($this->estateAdmin($tenantId));
        Filament::setTenant($tenant);
        TenantContext::set($tenantId);
    }

    private function estate(string $tenantId): Tenant
    {
        return Tenant::query()->firstOrCreate(
            array('id' => $tenantId),
            array(
                'name' => 'Estate '.$tenantId,
                'slug' => $tenantId,
                'is_operator' => false,
            )
        );
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function assistant(string $tenantId, string $title, string $status): Assistant
    {
        // `tenant_id` is deliberately not in `$fillable` — the estate is never
        // supplied by a request body — so a fixture that passes it to `create()`
        // gets the column default and the row silently rejoins `default`. Set it
        // on the instance instead, or the test is green for the wrong reason.
        $assistant = new Assistant(array(
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => $status,
        ));

        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }

    private function tokenRow(string $tenantId, int $total): void
    {
        DB::table('nvoos_token_usage')->insert(array(
            'tenant_id' => $tenantId,
            'user_id' => 1,
            'model_id' => 'test-model',
            'prompt_tokens' => $total,
            'completion_tokens' => 0,
            'total_tokens' => $total,
            'cost' => 0,
            'created_at' => now(),
        ));
    }

    private function event(string $tenantId, string $type, string $message): void
    {
        DB::table('nvoos_activity_events')->insert(array(
            'tenant_id' => $tenantId,
            'type' => $type,
            'message' => $message,
            'created_at' => now(),
        ));
    }
}

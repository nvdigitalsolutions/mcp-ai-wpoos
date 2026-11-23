<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\MemoryHealthPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Memory\MemoryHealth;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Agent-memory health — Proposal 149 §182.
 *
 * Two kinds of assertion, deliberately separate:
 *
 * - the **summary** tests seed rows and check the counts, because the predicates
 *   are the part that could silently mean something else (an expired row counted
 *   as dormant, a foreign estate leaking into a total);
 * - the **band** tests call `verdict()` on plain arrays, because the thresholds are
 *   arithmetic and do not need a database to prove.
 */
final class AdminMemoryHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(array('sanctum.stateful' => array('localhost', '127.0.0.1')));
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_summary_is_scoped_to_one_estate(): void
    {
        $this->estate('estate-b');

        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1');
        $this->seedMemory('estate-b', 'agent-1');

        $summary = $this->health()->summary(Tenant::DEFAULT_ID);

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['agents'][0]['total']);
    }

    public function test_expired_memories_are_counted_separately_from_live(): void
    {
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1');
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', array(
            'expires_at' => now()->subDay(),
        ));

        $summary = $this->health()->summary(Tenant::DEFAULT_ID);

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['live']);
        $this->assertSame(1, $summary['expired']);
        $this->assertSame(50, $summary['expired_pct']);
        // Half the store never being retrievable again is the critical band.
        $this->assertSame('critical', $this->health()->verdict($summary));
    }

    public function test_dormant_counts_live_rows_only_and_respects_the_window(): void
    {
        // Live but untouched for 40 days: dormant at a 30-day window.
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', array('updated_at' => now()->subDays(40)));
        // Expired and untouched: not dormant, because nothing can retrieve it.
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', array(
            'updated_at' => now()->subDays(40),
            'expires_at' => now()->subDay(),
        ));
        // Live and recent: not dormant.
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1');

        $this->assertSame(1, $this->health()->summary(Tenant::DEFAULT_ID, 30)['dormant']);
        $this->assertSame(0, $this->health()->summary(Tenant::DEFAULT_ID, 90)['dormant']);
    }

    public function test_the_window_is_the_tenant_setting_and_has_a_reader(): void
    {
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', array('updated_at' => now()->subDays(40)));
        $this->actingAsEstate();

        $this->app->make(SettingsService::class)->write(
            array('memory_dormancy_days' => 90),
            SettingsService::SURFACE_ADMIN
        );

        Livewire::test(MemoryHealthPage::class)
            ->assertSee('90 days')
            // 40 days is inside a 90-day window, so the single row is not dormant.
            ->assertSee('0 untouched for 90 days');
    }

    public function test_the_empty_store_reads_as_no_data_rather_than_healthy(): void
    {
        $this->actingAsEstate();

        $component = Livewire::test(MemoryHealthPage::class)
            ->assertSee('No data')
            ->assertDontSee('Healthy');

        $this->assertSame('empty', $component->instance()->verdict());
    }

    public function test_growth_compares_the_last_thirty_days_with_the_window_before(): void
    {
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1');
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1');
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1');
        $this->seedMemory(Tenant::DEFAULT_ID, 'agent-1', array('created_at' => now()->subDays(45)));

        $summary = $this->health()->summary(Tenant::DEFAULT_ID);

        $this->assertSame(3, $summary['created_recent']);
        $this->assertSame(1, $summary['created_previous']);
        $this->assertSame(200, $summary['growth_pct']);
    }

    public function test_the_bands_are_the_documented_ones(): void
    {
        $health = $this->health();

        $base = array('total' => 10, 'live' => 10, 'expired' => 0, 'dormant' => 0, 'expired_pct' => 0, 'dormant_pct' => 0);

        $this->assertSame('empty', $health->verdict(array('total' => 0)));
        $this->assertSame('ok', $health->verdict($base));

        // Any expired row warns: nothing sweeps them, so the count is not noise.
        $this->assertSame('warn', $health->verdict(array_merge($base, array(
            'expired' => 1, 'live' => 9, 'expired_pct' => 10,
        ))));

        // Half the live rows untouched is the warn floor.
        $this->assertSame('warn', $health->verdict(array_merge($base, array('dormant' => 5, 'dormant_pct' => 50))));

        // Three quarters is the critical floor.
        $this->assertSame('critical', $health->verdict(array_merge($base, array('dormant' => 8, 'dormant_pct' => 80))));

        // Half the store expired is critical even with nothing dormant.
        $this->assertSame('critical', $health->verdict(array_merge($base, array(
            'expired' => 5, 'live' => 5, 'expired_pct' => 50,
        ))));
    }

    public function test_the_page_requires_the_tenant_panel(): void
    {
        $this->actingAs(User::factory()->create(array('role' => 'subscriber')));

        $this->assertFalse(MemoryHealthPage::canAccess());
    }

    private function health(): MemoryHealth
    {
        return $this->app->make(MemoryHealth::class);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedMemory(string $tenantId, string $agentId, array $overrides = array()): string
    {
        $id = (string) Str::uuid();

        DB::table('nvoos_memories')->insert(array_merge(array(
            'id' => $id,
            'tenant_id' => $tenantId,
            'agent_id' => $agentId,
            'title' => 'A remembered fact',
            'content' => 'The refund window is thirty days.',
            'tags' => '[]',
            'tier' => 'recall',
            'importance' => 0.6,
            'wing' => null,
            'room' => null,
            'ttl' => null,
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ), $overrides));

        return $id;
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

    private function actingAsEstate(): void
    {
        $tenant = $this->estate(Tenant::DEFAULT_ID);
        $user = User::factory()->admin()->create();

        $tenant->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        $this->actingAs($user);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }
}

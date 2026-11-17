<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\SiteHealthPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Health\SiteHealth;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proposal 149 §31 — runtime health on `/ops`.
 *
 * The probes are pinned by their *observables*: a real cache round-trip, failed
 * job depth, an overdue schedule. That is the point of the surface — a health
 * page that only reports "configured" tells an operator nothing they did not
 * already know from the env file.
 */
class AdminSiteHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_runs_every_probe(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/site-health')
            ->assertOk()
            ->assertSee('Runtime health')
            ->assertSee('Database')
            ->assertSee('Cache')
            ->assertSee('Queue')
            ->assertSee('Scheduler')
            ->assertSee('Tool registry')
            ->assertSee('Security posture')
            ->assertSee('Re-run on every load');
    }

    public function test_there_are_eight_probes_and_totals_add_up(): void
    {
        $probes = SiteHealth::probes();
        $totals = SiteHealth::totals($probes);

        $this->assertCount(8, $probes);
        $this->assertSame(8, $totals['total']);
        $this->assertSame(
            $totals['total'],
            $totals['good'] + $totals['recommended'] + $totals['critical']
        );
    }

    public function test_the_database_probe_sees_a_working_connection(): void
    {
        $probe = $this->probe('database');

        $this->assertSame(SiteHealth::GOOD, $probe['status']);
        $this->assertStringContainsString('runtime table', $probe['summary']);
    }

    public function test_the_cache_probe_performs_a_real_round_trip(): void
    {
        $probe = $this->probe('cache');

        $this->assertSame(SiteHealth::GOOD, $probe['status']);
        $this->assertStringContainsString('round-trip ok', $probe['summary']);
    }

    public function test_failed_jobs_downgrade_the_queue_probe_and_ten_is_critical(): void
    {
        $this->failJob();

        $this->assertSame(SiteHealth::RECOMMENDED, $this->probe('queue')['status']);

        for ($i = 0; $i < 9; ++$i) {
            $this->failJob();
        }

        $this->assertSame(SiteHealth::CRITICAL, $this->probe('queue')['status']);
    }

    /**
     * Overdue schedules are the only evidence that the runner is not alive.
     */
    public function test_an_overdue_schedule_is_critical(): void
    {
        DB::table('nvoos_schedules')->insert(array(
            'id' => 'sched-1',
            'name' => 'Nightly',
            'schedule_type' => 'cron',
            'enabled' => true,
            'next_run_at' => now()->subHour(),
            'priority' => 10,
            'run_count' => 0,
            'handler' => 'App\\Jobs\\Noop',
            'cron_expression' => '* * * * *',
            'created_at' => now(),
            'updated_at' => now(),
        ));

        $probe = $this->probe('scheduler');

        $this->assertSame(SiteHealth::CRITICAL, $probe['status']);
        $this->assertStringContainsString('overdue', (string) $probe['summary']);
    }

    public function test_a_disabled_schedule_is_not_overdue(): void
    {
        DB::table('nvoos_schedules')->insert(array(
            'id' => 'sched-2',
            'name' => 'Paused',
            'schedule_type' => 'cron',
            'enabled' => false,
            'next_run_at' => now()->subDay(),
            'priority' => 10,
            'run_count' => 0,
            'handler' => 'App\\Jobs\\Noop',
            'cron_expression' => '* * * * *',
            'created_at' => now(),
            'updated_at' => now(),
        ));

        $this->assertNotSame(SiteHealth::CRITICAL, $this->probe('scheduler')['status']);
    }

    /**
     * Debug mode is a warning, and a missing app key is critical — the worst
     * wins, so a later warning cannot downgrade an earlier critical.
     */
    public function test_security_reports_debug_and_missing_key(): void
    {
        config(['app.debug' => true]);
        $this->assertSame(SiteHealth::RECOMMENDED, $this->probe('security')['status']);

        config(['app.key' => '']);
        $this->assertSame(SiteHealth::CRITICAL, $this->probe('security')['status']);
    }

    public function test_overall_returns_the_worst_status(): void
    {
        $this->assertSame(SiteHealth::GOOD, SiteHealth::overall(array(
            array('status' => SiteHealth::GOOD),
            array('status' => SiteHealth::GOOD),
        )));

        $this->assertSame(SiteHealth::RECOMMENDED, SiteHealth::overall(array(
            array('status' => SiteHealth::GOOD),
            array('status' => SiteHealth::RECOMMENDED),
        )));

        $this->assertSame(SiteHealth::CRITICAL, SiteHealth::overall(array(
            array('status' => SiteHealth::RECOMMENDED),
            array('status' => SiteHealth::CRITICAL),
        )));
    }

    public function test_the_page_offers_no_action(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/site-health')
            ->assertOk()
            ->assertDontSee('Fix')
            ->assertDontSee('Re-check');
    }

    public function test_a_customer_admin_is_refused_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$customer->id => ['role' => 'owner']]);

        $this->actingAs($customer)
            ->get('/ops/site-health')
            ->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function probe(string $id): array
    {
        foreach (SiteHealth::probes() as $probe) {
            if ($id === $probe['id']) {
                return $probe;
            }
        }

        $this->fail('No probe named '.$id);
    }

    private function failJob(): void
    {
        DB::table('failed_jobs')->insert(array(
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
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

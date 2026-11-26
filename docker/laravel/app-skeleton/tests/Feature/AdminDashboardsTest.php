<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboards;
use App\Models\Tenant;
use App\Models\User;
use App\Support\DashboardCatalogue;
use App\Support\Dashboards\DashboardGatherer;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboards page — Proposal 149 §113.
 *
 * The page is the last buildable row on the board, and almost all of what it
 * does is a decision about **absence**:
 *
 *  - a dashboard this runtime cannot feed is **not rendered at all**, because a
 *    disabled control promises an enabled state the reader can reach and there is
 *    nothing here to switch on;
 *  - a dashboard whose store exists but whose toolkit is off **is** rendered, with
 *    the toggle named, because that state the reader can fix;
 *  - the numbers come from the same gatherer the REST endpoint calls, so the page
 *    and the console cannot disagree;
 *  - a metric that was not computed renders an em dash, never `0`.
 *
 * The classroom fixture is seeded per test rather than in `setUp()`, so the empty
 * store is a real empty store and not one this test had to dismantle.
 */
final class AdminDashboardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_the_page_requires_the_panel(): void
    {
        // A viewer is refused the panel outright (AdminAccessPolicy), and the page
        // follows the panel rather than inventing a second predicate.
        $this->assertFalse(Dashboards::canAccess());

        $this->actingAsPanelUser('viewer');

        $this->assertFalse(Dashboards::canAccess());
    }

    public function test_an_owner_can_open_the_page(): void
    {
        $this->actingAsPanelUser('owner');

        $this->assertTrue(Dashboards::canAccess());
    }

    // ── Absence ──────────────────────────────────────────────────────────────

    public function test_only_servable_dashboards_are_offered(): void
    {
        // The catalogue declares seven. Six have no store on this runtime, so the
        // page must not offer them — not even as a greyed card.
        $this->assertCount(7, DashboardCatalogue::all());
        $this->assertSame(['eca'], DashboardCatalogue::servable());

        $this->actingAsPanelUser('owner');

        Livewire::test(Dashboards::class)
            ->assertOk()
            ->assertSet('dashboard', 'eca')
            ->assertSee('ECA')
            ->assertDontSee('Connector health')
            ->assertDontSee('Member metrics')
            ->assertDontSee('CRE Debt Portfolio')
            ->assertDontSee('AI Observability');
    }

    public function test_a_servable_dashboard_with_its_toolkit_off_is_shown_with_the_toggle(): void
    {
        // The distinction the whole page turns on: `eca` has a store here, so its
        // off state is fixable and worth explaining. `connectors` has no store, so
        // it is not mentioned at all.
        $this->actingAsPanelUser('owner');

        Livewire::test(Dashboards::class)
            ->assertOk()
            ->assertSee('is switched off for this estate')
            ->assertSee('enable_eca_management')
            ->assertSee('Settings')
            ->assertDontSee('Connector health');
    }

    // ── The dashboard ────────────────────────────────────────────────────────

    public function test_the_toolkit_toggle_reveals_the_metrics_charts_and_list(): void
    {
        $this->actingAsPanelUser('owner');
        $this->enableEca();
        $this->seedClassroom();

        Livewire::test(Dashboards::class)
            ->assertOk()
            ->assertDontSee('is switched off for this estate')
            ->assertSee('Activities')
            ->assertSee('Confirmed')
            ->assertSee('Capacity Used')
            ->assertSee('Enrolments by Day')
            ->assertSee('Activities by Type')
            // The seeded activity, through the shared table component.
            ->assertSee('Robotics Club')
            ->assertSee('Wednesday');
    }

    public function test_the_metrics_are_the_gatherers_metrics(): void
    {
        $this->actingAsPanelUser('owner');
        $this->enableEca();
        $this->seedClassroom();

        $expected = app(DashboardGatherer::class)->gather('eca', []);

        $this->assertNotNull($expected);

        // ClassroomSeeder: one activity, two confirmed enrolments, capacity 16.
        $this->assertSame(1, $expected['total_activities']);
        $this->assertSame(2, $expected['total_enrolments']);
        $this->assertSame(0, $expected['total_waitlisted']);
        $this->assertSame(12.5, $expected['capacity_usage']);

        Livewire::test(Dashboards::class)
            ->assertOk()
            ->assertSee('12.50%')
            ->assertSee('Robotics Club');
    }

    public function test_the_day_filter_narrows_the_page(): void
    {
        $this->actingAsPanelUser('owner');
        $this->enableEca();
        $this->seedClassroom();

        Livewire::test(Dashboards::class)
            ->assertOk()
            ->assertSee('Robotics Club')
            ->set('day', 'Monday')
            ->assertOk()
            ->assertDontSee('Robotics Club')
            // The table's empty state keeps its header, so the reader can still
            // see what they filtered on (§55.5).
            ->assertSee('No activity matches the current filters.');
    }

    public function test_the_type_filter_narrows_the_page(): void
    {
        $this->actingAsPanelUser('owner');
        $this->enableEca();
        $this->seedClassroom();

        Livewire::test(Dashboards::class)
            ->assertSee('Robotics Club')
            ->set('eca_type', 'sport_squad')
            ->assertOk()
            ->assertDontSee('Robotics Club');
    }

    // ── Empty and missing ────────────────────────────────────────────────────

    public function test_an_empty_store_keeps_the_axis_and_says_where_a_chart_is_empty(): void
    {
        $this->actingAsPanelUser('owner');
        $this->enableEca();

        // No fixture at all: a store that exists and holds nothing. The metrics go
        // to zero — that is a measurement — the day axis stays stable so a quiet
        // week is not an empty box, the type distribution has nothing to plot, and
        // the table keeps its header with an empty body.
        Livewire::test(Dashboards::class)
            ->assertOk()
            ->assertSee('Activities')
            ->assertSee('Monday')
            ->assertSee('Nothing to plot for the current filters.')
            ->assertSee('No activity matches the current filters.')
            ->assertDontSee('is switched off for this estate');
    }

    public function test_a_missing_metric_renders_a_dash_not_a_zero(): void
    {
        $page = new Dashboards();

        $this->assertSame('—', $page->format(null));
        $this->assertSame('—', $page->format(''));
        $this->assertSame('0', $page->format(0));
        $this->assertSame('12.50%', $page->format(12.5, 'percent'));
        $this->assertSame('$1,235', $page->format(1235.4, 'money'));
        $this->assertSame('1,235', $page->format(1235, 'number'));
    }

    public function test_bars_are_ranked_and_scaled_to_the_largest(): void
    {
        $page = new Dashboards();

        $bars = $page->bars(['Wednesday' => 2, 'Monday' => 8, 'Friday' => 0]);

        $this->assertSame(['Monday', 'Wednesday', 'Friday'], array_column($bars, 'label'));
        $this->assertSame(100.0, $bars[0]['width']);
        $this->assertSame(25.0, $bars[1]['width']);
        $this->assertSame(0.0, $bars[2]['width']);

        // The value travels as text, so the encoding is never colour alone.
        $this->assertSame('8', $bars[0]['display']);

        $this->assertSame([], $page->bars([]));
    }

    public function test_a_zero_distribution_renders_bars_rather_than_vanishing(): void
    {
        // A seeded day axis with no enrolments is seven zero bars, not an empty
        // chart: "nothing on any day" is an answer, and a missing chart is not.
        $bars = (new Dashboards())->bars(['Monday' => 0, 'Tuesday' => 0]);

        $this->assertCount(2, $bars);
        $this->assertSame(0.0, $bars[0]['width']);
        $this->assertSame('0', $bars[0]['display']);
    }

    // ── The boundary the page does not own ───────────────────────────────────

    public function test_a_non_servable_dashboard_still_answers_404_on_the_data_endpoint(): void
    {
        // The page hides it; the endpoint refuses it. Hiding is presentation and
        // the refusal is the boundary (S-3).
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/pro/dashboards/connectors/data')->assertStatus(404);

        // A retired id resolves to it, so the same 404 applies.
        $this->getJson('/api/v1/pro/dashboards/ezuite/data')->assertStatus(404);

        // And the one dashboard that is servable on this runtime needs no
        // allowance beyond its own toolkit.
        $this->getJson('/api/v1/pro/dashboards/eca/data')->assertOk();
    }

    public function test_the_bound_filter_guard_names_only_the_keys_the_page_declares(): void
    {
        $page = new Dashboards();

        $this->assertTrue($page->bound('day'));
        $this->assertTrue($page->bound('eca_type'));
        $this->assertFalse($page->bound('member_id'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function seedClassroom(): void
    {
        $this->seed(\Database\Seeders\ClassroomSeeder::class);
    }

    /**
     * Enter the customer panel as a member with the given estate role.
     */
    private function actingAsPanelUser(string $role): User
    {
        $tenant = Tenant::query()->firstOrCreate(
            array('id' => Tenant::DEFAULT_ID),
            array('name' => 'Default estate', 'slug' => Tenant::DEFAULT_ID, 'is_operator' => false)
        );

        $user = User::factory()->create();
        $user->tenants()->syncWithoutDetaching(array($tenant->id => array('role' => $role)));

        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);
        TenantContext::set($tenant->id);

        return $user;
    }

    private function enableEca(): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        app(SettingsService::class)->write(
            array('enable_eca_management' => true),
            SettingsService::SURFACE_ADMIN
        );
    }
}

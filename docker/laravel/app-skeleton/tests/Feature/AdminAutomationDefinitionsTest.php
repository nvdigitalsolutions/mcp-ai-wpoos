<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\AutomationDefinitions;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Automation\AutomationCatalogue;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Tests\TestCase;

/**
 * Proposal 149 §30 — automation definitions on `/admin`.
 *
 * Two properties matter beyond "the page renders":
 *
 *  - a trigger's webhook URL is reduced to its host. Webhook providers put the
 *    secret in the path, so echoing the URL would turn a read-only page into a
 *    credential dump.
 *  - both record sets are estate-scoped. `nvoos_triggers` only gained a tenant
 *    column in §29; before that a panel over it would have listed every
 *    estate's triggers.
 */
class AdminAutomationDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_workflows_and_triggers(): void
    {
        $this->workflow('Nightly report');
        $this->trigger('Nightly trigger');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/automations')
            ->assertOk()
            ->assertSee('Automation definitions')
            ->assertSee('Nightly report')
            ->assertSee('Nightly trigger')
            ->assertSee('Read-only');
    }

    public function test_the_search_narrows_both_sections(): void
    {
        $this->workflow('Nightly report');
        $this->workflow('Weekly digest');
        $this->trigger('Nightly trigger');
        $this->trigger('Digest trigger');

        $this->bootPanel();

        Livewire::test(AutomationDefinitions::class)
            ->set('search', 'nightly')
            ->assertSee('Nightly report')
            ->assertSee('Nightly trigger')
            ->assertDontSee('Weekly digest')
            ->assertDontSee('Digest trigger');
    }

    public function test_clearing_the_search_restores_both_sections(): void
    {
        $this->bootPanel();

        Livewire::test(AutomationDefinitions::class)
            ->set('search', 'nightly')
            ->call('clearFilters')
            ->assertSet('search', '');
    }

    public function test_an_empty_catalogue_shows_both_empty_states(): void
    {
        $this->bootPanel();

        Livewire::test(AutomationDefinitions::class)
            ->assertSee('No workflow matches that filter')
            ->assertSee('No trigger matches that filter');
    }

    /**
     * The security assertion: the host is useful, the path is a secret.
     */
    public function test_a_webhook_url_is_reduced_to_its_host(): void
    {
        $this->trigger(
            'Slack trigger',
            'webhook',
            'https://hooks.example.test/services/T000/B000/SECRETTOKEN'
        );

        $this->bootPanel();

        Livewire::test(AutomationDefinitions::class)
            ->assertSee('hooks.example.test')
            ->assertDontSee('SECRETTOKEN');
    }

    public function test_a_trigger_from_another_estate_is_not_listed(): void
    {
        $this->triggerIn('estate-b', 'Foreign trigger');

        $this->bootPanel();

        Livewire::test(AutomationDefinitions::class)
            ->assertDontSee('Foreign trigger');
    }

    public function test_the_page_offers_no_write_or_fire_control(): void
    {
        $this->trigger('Nightly trigger');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/automations')
            ->assertOk()
            ->assertDontSee('New workflow')
            ->assertDontSee('Fire now')
            ->assertDontSee('Delete');
    }

    public function test_the_workflow_row_reports_graph_size(): void
    {
        $this->workflow('Nightly report');

        TenantContext::set(Tenant::DEFAULT_ID);

        $rows = AutomationCatalogue::rows(
            $this->app->make(ContentStoreInterface::class)
        );

        $this->assertCount(1, $rows['workflows']);
        $this->assertSame(1, $rows['workflows'][0]['nodes']);
        $this->assertSame(0, $rows['workflows'][0]['edges']);
        $this->assertSame('Nightly report', $rows['workflows'][0]['title']);
    }

    public function test_totals_split_enabled_from_disabled_triggers(): void
    {
        $this->trigger('Live one');
        $this->trigger('Paused one', 'webhook', null, false);

        TenantContext::set(Tenant::DEFAULT_ID);

        $totals = AutomationCatalogue::totals(AutomationCatalogue::rows(
            $this->app->make(ContentStoreInterface::class)
        ));

        $this->assertSame(0, $totals['workflows']);
        $this->assertSame(2, $totals['triggers']);
        $this->assertSame(1, $totals['enabled']);
        $this->assertSame(1, $totals['disabled']);
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$viewer->id => ['role' => 'viewer']]);

        $this->actingAs($viewer)
            ->get('/admin/default/automations')
            ->assertForbidden();
    }

    private function bootPanel(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());

        // The panel middleware pins this on every `/admin` request; a Livewire
        // component test does not run middleware, so the test pins it.
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }

    /**
     * Create a workflow through the API, so it lands in the estate-scoped
     * content store exactly as a console-created workflow would.
     */
    private function workflow(string $title): int
    {
        // `actingAs` on the web guard, never `Sanctum::actingAs`: the latter
        // changes the DEFAULT guard, and Filament resolves its tenant against
        // the panel guard — a Sanctum-authenticated request then looks
        // unauthenticated to the panel and `/admin` answers 302.
        if (null === auth()->user()) {
            $this->actingAs($this->estateAdmin());
        }

        return (int) $this->postJson('/api/v1/orchestration/workflows', [
            'name' => $title,
            'graph' => [
                'nodes' => [
                    ['id' => 'node_1', 'type' => 'tool', 'data' => ['toolSlug' => 'generate_uuid']],
                ],
                'edges' => [],
            ],
        ])->assertOk()->json('id');
    }

    private function trigger(
        string $name,
        string $type = 'cron_schedule',
        ?string $webhookUrl = null,
        bool $enabled = true
    ): int {
        return $this->triggerIn(Tenant::DEFAULT_ID, $name, $type, $webhookUrl, $enabled);
    }

    private function triggerIn(
        string $tenantId,
        string $name,
        string $type = 'webhook',
        ?string $webhookUrl = null,
        bool $enabled = true
    ): int {
        if (Tenant::DEFAULT_ID !== $tenantId) {
            Tenant::query()->firstOrCreate(
                ['id' => $tenantId],
                ['name' => ucfirst($tenantId), 'slug' => $tenantId, 'is_operator' => false]
            );
        }

        return (int) DB::table('nvoos_triggers')->insertGetId([
            'tenant_id' => $tenantId,
            'name' => $name,
            'type' => $type,
            'config' => json_encode('cron_schedule' === $type ? ['schedule' => 'every_minute'] : []),
            'workflow_id' => null,
            'enabled' => $enabled,
            'webhook_url' => $webhookUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

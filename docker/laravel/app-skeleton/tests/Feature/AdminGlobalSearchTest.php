<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Assistants\AssistantResource;
use App\Filament\Admin\Resources\Teams\TeamResource;
use App\Models\Assistant;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\TeamServiceInterface;
use Tests\TestCase;

/**
 * Proposal 149 §55 step 3 — global search over the two tenant resources.
 *
 * Two things were wrong before this step, and both were measured in a browser
 * rather than assumed:
 *
 *  1. Filament searches only `$recordTitleAttribute` by default, so an
 *     assistant could be found by name and nothing else.
 *  2. The result URL resolved to `?tableAction=view`, but neither resource has
 *     a `view` page or action — only `edit` — so clicking a result navigated to
 *     the index and opened nothing. A dead link that looks like a working one.
 *
 * The cross-estate tests are the important ones. Filament's global search uses
 * `getEloquentQuery()`, which is scoped by a **per-model global scope
 * registered when the panel boots tenancy**; `Livewire::test()` and a direct
 * provider call do not run the middleware that does it, so the scope is booted
 * explicitly in `setUp()` — otherwise the scoping assertion could pass for the
 * wrong reason.
 *
 * Booting tenancy has a second, easy-to-miss effect: `Panel::bootTenancy()` also
 * registers a `creating` hook that associates every new tenant-owned record with
 * `Filament::getTenant()`. That is the right rule for a panel write, but it means
 * a fixture that builds estate B's row while the panel points at estate A is
 * silently re-homed to A — and then the cross-estate assertion would pass
 * because the foreign row was never foreign. Every fixture write therefore goes
 * through `writingAs()`.
 */
final class AdminGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::getPanel('admin')->bootTenancy();
    }

    public function test_both_resources_search_more_than_the_title(): void
    {
        $this->assertSame(array('title', 'slug', 'description'), AssistantResource::getGloballySearchableAttributes());
        $this->assertSame(array('name', 'slug', 'description'), TeamResource::getGloballySearchableAttributes());
    }

    public function test_a_result_opens_the_records_edit_action(): void
    {
        // `AssistantResource::getUrl()` needs the resolved tenant: the route is
        // `admin/{tenant}/assistants`, so an un-booted panel throws
        // `UrlGenerationException: Missing parameter: tenant` rather than
        // returning a URL to assert on.
        $this->bootPanel();

        $assistant = $this->assistantIn(Tenant::DEFAULT_ID, 'Refunds bot', array(
            'description' => 'Handles refunds and escalations.',
        ));

        $url = AssistantResource::getGlobalSearchResultUrl($assistant);

        $this->assertNotNull($url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // `view` is Filament's default and has nothing behind it on this
        // resource; the record must open the action that exists.
        $this->assertSame('edit', $query['tableAction'] ?? null);
        $this->assertSame((string) $assistant->getKey(), (string) ($query['tableActionRecord'] ?? ''));
    }

    public function test_an_assistant_is_found_by_its_description(): void
    {
        $this->bootPanel();

        $this->assistantIn(Tenant::DEFAULT_ID, 'Support bot', array(
            'description' => 'Answers questions about refunds and chargebacks.',
        ));

        $this->assertSame(array('Support bot'), $this->assistantTitles('chargebacks'));
    }

    public function test_an_assistant_is_found_by_its_slug(): void
    {
        $this->bootPanel();

        $this->assistantIn(Tenant::DEFAULT_ID, 'Support bot');

        $this->assertSame(array('Support bot'), $this->assistantTitles('support-bot'));
    }

    public function test_search_does_not_cross_the_estate_boundary(): void
    {
        $this->bootPanel();

        $this->assistantIn(Tenant::DEFAULT_ID, 'Own bot', array('description' => 'Refund handling.'));
        $this->assistantIn('estate-b', 'Foreign bot', array('description' => 'Refund handling too.'));

        $this->assertSame(array('Own bot'), $this->assistantTitles('refund'));
    }

    /**
     * The same boundary for teams. Their query has a second rule (membership),
     * which an administrator bypasses, so this isolates the estate scope.
     */
    public function test_team_search_does_not_cross_the_estate_boundary(): void
    {
        $this->bootPanel();

        $this->teamIn(Tenant::DEFAULT_ID, 'Own team', 'Refund handling.');
        $this->teamIn('estate-b', 'Foreign team', 'Refund handling too.');

        $names = TeamResource::getGlobalSearchResults('refund')
            ->map(static fn (GlobalSearchResult $result): string => (string) $result->title)
            ->values()
            ->all();

        $this->assertSame(array('Own team'), $names);
    }

    /**
     * The panel's provider is what the topbar field calls. It filters by
     * `canGloballySearch()` (which is `canAccess()` + attributes) and returns
     * categories, so this proves the wiring rather than the resource method.
     */
    public function test_the_panel_provider_returns_the_record_under_its_resource(): void
    {
        $this->bootPanel();

        $this->assistantIn(Tenant::DEFAULT_ID, 'Provider bot');

        $results = Filament::getPanel('admin')->getGlobalSearchProvider()?->getResults('Provider bot');

        $this->assertNotNull($results);

        $categories = $results->getCategories();

        // The heading is `Resource::getPluralModelLabel()` verbatim, and this
        // panel's resources use lowercase plural labels for the rest of their
        // copy ("assistants", "teams"), so the category key is lowercase too.
        $this->assertArrayHasKey('assistants', $categories->all());

        $titles = $categories['assistants']
            ->map(static fn (GlobalSearchResult $result): string => (string) $result->title)
            ->all();

        $this->assertSame(array('Provider bot'), $titles);
    }

    /**
     * The estate scope, the tenant and an authenticated member — the state the
     * panel middleware establishes before a resource query runs.
     */
    private function bootPanel(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs(User::factory()->admin()->create());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    /**
     * @return list<string>
     */
    private function assistantTitles(string $search): array
    {
        return AssistantResource::getGlobalSearchResults($search)
            ->map(static fn (GlobalSearchResult $result): string => (string) $result->title)
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assistantIn(string $tenantId, string $title, array $data = array()): Assistant
    {
        return $this->writingAs(
            $tenantId,
            static fn (): Assistant => app(AssistantWriter::class)->create($data + array('title' => $title))
        );
    }

    private function teamIn(string $tenantId, string $name, string $description = ''): Team
    {
        return $this->writingAs($tenantId, function () use ($name, $description): Team {
            $result = app(TeamServiceInterface::class)->create(array(
                'name' => $name,
                'description' => $description,
                'owner_id' => (int) auth()->id(),
            ));

            $this->assertTrue($result['created'] ?? false, (string) ($result['error'] ?? ''));

            return Team::query()->findOrFail((int) $result['team']['id']);
        });
    }

    /**
     * Create a fixture row as the given estate, then restore the panel's.
     *
     * `bootTenancy()` registered a `creating` hook that re-homes a new record to
     * `Filament::getTenant()` (see the class docblock). In production that is
     * the panel's rule; in a fixture that is building the *other* estate's row it
     * would erase the thing under test.
     *
     * @template T
     *
     * @param callable():T $write
     *
     * @return T
     */
    private function writingAs(string $tenantId, callable $write): mixed
    {
        $tenant = Tenant::query()->firstOrCreate(
            array('id' => $tenantId),
            array('name' => ucfirst($tenantId), 'slug' => $tenantId, 'is_operator' => false)
        );

        $panelTenant = Filament::getTenant();

        TenantContext::set($tenantId);
        Filament::setTenant($tenant);

        try {
            return $write();
        } finally {
            TenantContext::forget();
            Filament::setTenant($panelTenant);
        }
    }
}

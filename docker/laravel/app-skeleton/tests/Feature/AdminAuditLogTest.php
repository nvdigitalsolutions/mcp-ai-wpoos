<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\EstateCredentialsPage;
use App\Filament\Operator\Pages\AuditLog;
use App\Models\AdminAudit;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Admin\AuditTrail;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Tests\TestCase;

/**
 * Proposal 149 §25 — the admin audit trail.
 *
 * Two properties matter more than the page:
 *
 *  - both row kinds are visible. Request rows answer "who tried and was
 *    refused"; record rows answer "what changed". A page that showed only the
 *    second would hide every 403/422, which is the half of a trail that matters
 *    when something went wrong.
 *  - a destructive panel action writes a record row at all. Panel traffic goes
 *    over Livewire's `/livewire/update`, which `AuditAdminRequests` skips as UI
 *    mechanics, so a revoke that did not call the logger would leave no trace.
 */
class AdminAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_shows_both_row_kinds(): void
    {
        $this->requestRow('admin', 'default', 'POST', 'admin/default/teams', 200);
        $this->recordRow('admin', 'default', 'teams', '7', 'update');

        $this->actingAs($this->operator())
            ->get('/ops/audit-log')
            ->assertOk()
            ->assertSee('Admin audit trail')
            ->assertSee('teams update 7')
            ->assertSee('POST admin/default/teams')
            ->assertSee('Append-only');
    }

    public function test_the_kind_filter_separates_requests_from_record_changes(): void
    {
        $this->requestRow('admin', 'default', 'POST', 'admin/default/teams', 200);
        $this->recordRow('admin', 'default', 'teams', '7', 'update');

        $this->auditPage()
            ->set('kind', 'record')
            ->assertSee('teams update 7')
            ->assertDontSee('POST admin/default/teams');

        $this->auditPage()
            ->set('kind', 'request')
            ->assertSee('POST admin/default/teams')
            ->assertDontSee('teams update 7');
    }

    public function test_the_outcome_filter_separates_refusals(): void
    {
        $this->requestRow('admin', 'default', 'POST', 'admin/default/teams', 200);
        $this->requestRow('admin', 'default', 'POST', 'admin/default/assistants', 403);

        $this->auditPage()
            ->set('status', 'refused')
            ->assertSee('POST admin/default/assistants')
            ->assertDontSee('POST admin/default/teams');
    }

    public function test_the_operator_estate_filter_selects_rows_with_no_estate(): void
    {
        $this->requestRow('ops', null, 'PATCH', 'ops/settings', 200);
        $this->recordRow('admin', 'default', 'teams', '7', 'update');

        $this->auditPage()
            ->set('estate', 'operator')
            ->assertSee('PATCH ops/settings')
            ->assertDontSee('teams update 7');
    }

    public function test_the_search_matches_resource_and_action(): void
    {
        $this->recordRow('admin', 'default', 'teams', '7', 'update');
        $this->recordRow('ops', null, 'credentials', '3', 'rotate');

        $this->auditPage()
            ->set('search', 'credentials')
            ->assertSee('credentials rotate 3')
            ->assertDontSee('teams update 7');
    }

    public function test_the_panel_filter_narrows_to_one_panel(): void
    {
        $this->recordRow('admin', 'default', 'teams', '7', 'update');
        $this->recordRow('ops', null, 'credentials', '3', 'rotate');

        $this->auditPage()
            ->set('panel', 'ops')
            ->assertSee('credentials rotate 3')
            ->assertDontSee('teams update 7');
    }

    public function test_clearing_the_filters_restores_the_trail(): void
    {
        $this->auditPage()
            ->set('panel', 'ops')
            ->set('estate', 'operator')
            ->set('kind', 'record')
            ->set('status', 'refused')
            ->set('search', 'credentials')
            ->call('clearFilters')
            ->assertSet('panel', '')
            ->assertSet('estate', '')
            ->assertSet('kind', '')
            ->assertSet('status', '')
            ->assertSet('search', '');
    }

    public function test_an_empty_trail_shows_the_empty_state(): void
    {
        $this->auditPage()
            ->assertSee('No audit row matches that filter');
    }

    public function test_the_page_offers_no_write_action(): void
    {
        $this->recordRow('admin', 'default', 'teams', '7', 'update');

        $this->actingAs($this->operator())
            ->get('/ops/audit-log')
            ->assertOk()
            ->assertDontSee('Delete')
            ->assertDontSee('Retry');
    }

    public function test_totals_count_record_rows_and_refusals(): void
    {
        $this->requestRow('admin', 'default', 'POST', 'admin/default/teams', 200);
        $this->requestRow('admin', 'default', 'POST', 'admin/default/assistants', 422);
        $this->recordRow('admin', 'default', 'teams', '7', 'update');

        $totals = AuditTrail::totals(AuditTrail::rows());

        $this->assertSame(3, $totals['total']);
        $this->assertSame(1, $totals['record']);
        $this->assertSame(1, $totals['refused']);
        $this->assertSame(1, $totals['estates']);
    }

    /**
     * The destructive action §24 shipped now leaves a record row, scoped to the
     * estate the panel resolved — not to ambient request state.
     */
    public function test_revoking_a_credential_writes_a_scoped_audit_row(): void
    {
        $assistant = $this->assistantInEstate(Assistant::DEFAULT_TENANT, 'Estate Agent');
        $this->issue($assistant);

        $credentialId = (string) DB::table('personal_access_tokens')->value('id');

        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(EstateCredentialsPage::class)
            ->call('confirmRevoke', $credentialId, (int) $assistant->id)
            ->call('revoke');

        $row = AdminAudit::query()->where('resource', 'credentials')->first();

        $this->assertNotNull($row, 'A revoke must leave a record row.');
        $this->assertSame('revoke', (string) $row->action);
        $this->assertSame($credentialId, (string) $row->record_id);
        $this->assertSame('admin', (string) $row->panel);
        $this->assertSame(Assistant::DEFAULT_TENANT, (string) $row->tenant_id);
        $this->assertStringNotContainsString('token', strtolower((string) json_encode($row->after)));
    }

    public function test_a_non_operator_is_refused_the_page(): void
    {
        $customer = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($customer->id => array('role' => 'owner')));

        $this->actingAs($customer)
            ->get('/ops/audit-log')
            ->assertForbidden();
    }

    /**
     * A Livewire page test needs an authenticated operator and a current panel;
     * without either the component renders the 403 page and every `assertSee`
     * fails against HTML that has nothing to do with the page.
     */
    private function auditPage(): Testable
    {
        $this->actingAs($this->operator());
        Filament::setCurrentPanel(Filament::getPanel('operator'));

        return Livewire::test(AuditLog::class);
    }

    private function requestRow(string $panel, ?string $estate, string $method, string $path, int $status): void
    {
        DB::table('nvoos_admin_audit')->insert(array(
            'tenant_id' => $estate,
            'user_id' => null,
            'panel' => $panel,
            'method' => $method,
            'path' => $path,
            'route' => null,
            'status' => $status,
            'payload' => null,
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ));
    }

    private function recordRow(string $panel, ?string $estate, string $resource, string $recordId, string $action): void
    {
        DB::table('nvoos_admin_audit')->insert(array(
            'tenant_id' => $estate,
            'user_id' => null,
            'panel' => $panel,
            'method' => 'PUT',
            'path' => $resource.'/'.$recordId,
            'route' => null,
            'status' => 200,
            'payload' => null,
            'resource' => $resource,
            'record_id' => $recordId,
            'action' => $action,
            'before' => null,
            'after' => null,
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ));
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

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function assistantInEstate(string $tenantId, string $title): Assistant
    {
        $assistant = Assistant::query()->create(array(
            'title' => $title,
            'slug' => strtolower(str_replace(' ', '-', $title)),
            'status' => 'publish',
        ));

        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }

    private function issue(Assistant $assistant): void
    {
        if (null === auth()->user()) {
            $this->actingAs(User::factory()->admin()->create());
        }

        TenantContext::set((string) $assistant->tenant_id);

        $this->app->make(AuthProviderInterface::class)->issueCredential($assistant->id);

        TenantContext::set(null);
    }
}

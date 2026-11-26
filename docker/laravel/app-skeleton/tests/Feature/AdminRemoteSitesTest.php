<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\RemoteSitesPage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RemoteSites\RemoteSiteStore;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Tests\Support\FakeHttpClient;
use Tests\TestCase;

/**
 * Proposal 149 §42 — remote sites on `/admin`.
 *
 * The panel presentation is a SUBSET: list, test, enable/disable, delete. These
 * tests exist for the properties that make the surface safe, not for its layout:
 *
 *  - the panel tenant decides what is listed, not ambient request state;
 *  - a foreign connection cannot be tested, disabled or deleted, even with a
 *    tampered armed state — `test` matters most because it sends the row's
 *    sealed credentials to the URL the row names;
 *  - no secret is rendered, and the endpoint drops its query and fragment
 *    because a provider can put a secret there;
 *  - every action an operator can take on a connection leaves an audit row,
 *    since Livewire traffic is excluded from the request-level audit;
 *  - `manage_options` is required, matching the REST surface.
 */
final class AdminRemoteSitesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_page_lists_the_estates_connections_without_the_secret(): void
    {
        $this->connection(Tenant::DEFAULT_ID, 'Client site');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/remote-sites')
            ->assertOk()
            ->assertSee('Remote sites')
            ->assertSee('Client site')
            ->assertSee('client.test')
            ->assertSee('stored')
            ->assertDontSee('hunter2');
    }

    /**
     * The endpoint is shown as host + path. A query string is where a provider
     * puts a token, so it is dropped rather than echoed (the §30 rule).
     */
    public function test_the_endpoint_is_rendered_without_query_or_fragment(): void
    {
        $this->connection(
            Tenant::DEFAULT_ID,
            'Tokenised site',
            'https://client.test/site?token=supersecret#section'
        );

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/remote-sites')
            ->assertOk()
            ->assertSee('client.test/site')
            ->assertDontSee('supersecret')
            ->assertDontSee('section');
    }

    public function test_another_estates_connection_is_not_listed(): void
    {
        $this->connection('estate-b', 'Foreign site');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/remote-sites')
            ->assertOk()
            ->assertDontSee('Foreign site')
            ->assertSee('No connections in this estate yet');
    }

    /**
     * The console owns authoring; a create form here would be the wizard twice.
     */
    public function test_the_page_offers_no_create_or_edit_control(): void
    {
        $this->connection(Tenant::DEFAULT_ID, 'Client site');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/remote-sites')
            ->assertOk()
            ->assertDontSee('Create connection')
            ->assertDontSee('Save changes');
    }

    public function test_testing_a_connection_records_the_outcome_and_an_audit_row(): void
    {
        $id = $this->connection(Tenant::DEFAULT_ID, 'Client site');

        $http = new FakeHttpClient();
        $http->queue(200, array('id' => 1, 'name' => 'Admin'));
        $this->app->instance(HttpClientInterface::class, $http);

        $this->bootPanel();

        Livewire::test(RemoteSitesPage::class)
            ->call('testConnection', $id)
            ->assertSee('Test passed');

        $this->assertSame(
            'ok',
            DB::table('nvoos_remote_sites')->where('id', $id)->value('last_test_status')
        );

        $row = DB::table('nvoos_admin_audit')
            ->where('resource', 'remote-sites')
            ->where('action', 'tested')
            ->first();

        $this->assertNotNull($row, 'A test is an effect and must be audited.');
        $this->assertSame('default', (string) $row->tenant_id);
        $this->assertStringNotContainsString('hunter2', (string) $row->after);
        $this->assertSame('client.test', (string) json_decode((string) $row->after, true)['host']);
    }

    public function test_a_rejected_password_is_reported_and_recorded_as_failed(): void
    {
        $id = $this->connection(Tenant::DEFAULT_ID, 'Client site');

        $http = new FakeHttpClient();
        $http->queue(401, array('code' => 'rest_forbidden'));
        $this->app->instance(HttpClientInterface::class, $http);

        $this->bootPanel();

        Livewire::test(RemoteSitesPage::class)
            ->call('testConnection', $id)
            ->assertSee('Test failed')
            ->assertSee('rejected the credentials');

        $this->assertSame(
            'failed',
            DB::table('nvoos_remote_sites')->where('id', $id)->value('last_test_status')
        );
    }

    /**
     * `test` sends the row's sealed credentials to the URL the row names, so a
     * foreign id must be refused before any outbound request is attempted.
     */
    public function test_a_foreign_connection_cannot_be_tested(): void
    {
        $id = $this->connection('estate-b', 'Foreign site');

        $http = new FakeHttpClient();
        $this->app->instance(HttpClientInterface::class, $http);

        $this->bootPanel();

        Livewire::test(RemoteSitesPage::class)
            ->call('testConnection', $id)
            ->assertSee('not in this estate');

        $this->assertSame(array(), $http->requests, 'A refused test must not reach the network.');
        $this->assertNull(
            DB::table('nvoos_remote_sites')->where('id', $id)->value('last_tested_at')
        );
    }

    public function test_deleting_is_two_step_and_removes_on_confirm(): void
    {
        $id = $this->connection(Tenant::DEFAULT_ID, 'Client site');

        $this->bootPanel();

        Livewire::test(RemoteSitesPage::class)
            ->call('confirmDelete', $id)
            ->assertSet('pendingDelete', $id)
            ->assertSee('Confirm delete');

        $this->assertSame(
            1,
            DB::table('nvoos_remote_sites')->where('id', $id)->count(),
            'Arming must not delete anything.'
        );

        Livewire::test(RemoteSitesPage::class)
            ->call('confirmDelete', $id)
            ->call('deleteConnection')
            ->assertSet('pendingDelete', '')
            ->assertSee('was deleted');

        $this->assertSame(0, DB::table('nvoos_remote_sites')->where('id', $id)->count());

        $this->assertSame(
            1,
            DB::table('nvoos_admin_audit')
                ->where('resource', 'remote-sites')
                ->where('action', 'deleted')
                ->count()
        );
    }

    public function test_deleting_with_nothing_armed_is_a_no_op(): void
    {
        $id = $this->connection(Tenant::DEFAULT_ID, 'Client site');

        $this->bootPanel();

        Livewire::test(RemoteSitesPage::class)->call('deleteConnection');

        $this->assertSame(1, DB::table('nvoos_remote_sites')->where('id', $id)->count());
    }

    public function test_a_foreign_connection_cannot_be_deleted(): void
    {
        $id = $this->connection('estate-b', 'Foreign site');

        $this->bootPanel();

        Livewire::test(RemoteSitesPage::class)
            ->call('confirmDelete', $id)
            ->call('deleteConnection')
            ->assertSee('not in this estate');

        $this->assertSame(
            1,
            DB::table('nvoos_remote_sites')->where('id', $id)->count(),
            'A cross-estate refusal must not delete the row.'
        );
    }

    public function test_toggling_enabled_writes_and_audits(): void
    {
        $id = $this->connection(Tenant::DEFAULT_ID, 'Client site');

        $this->bootPanel();

        Livewire::test(RemoteSitesPage::class)
            ->call('toggleEnabled', $id)
            ->assertSee('now disabled');

        $this->assertFalse(
            (bool) DB::table('nvoos_remote_sites')->where('id', $id)->value('enabled')
        );

        $row = DB::table('nvoos_admin_audit')
            ->where('resource', 'remote-sites')
            ->where('action', 'disabled')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(1, (int) json_decode((string) $row->before, true)['enabled']);
        $this->assertSame(0, (int) json_decode((string) $row->after, true)['enabled']);
    }

    /**
     * The REST surface requires `manage_options`; the panel must agree, because
     * a connection carries sealed credentials.
     */
    public function test_a_member_without_manage_options_is_refused(): void
    {
        $member = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($member->id => array('role' => 'member')));

        $this->actingAs($member)
            ->get('/admin/default/remote-sites')
            ->assertForbidden();
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/remote-sites')
            ->assertForbidden();
    }

    private function bootPanel(): void
    {
        $this->actingAs($this->estateAdmin());
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    /**
     * Create a connection through the real store, so the credential is sealed
     * the way production seals it and `has_credentials` is honest.
     */
    private function connection(
        string $tenantId,
        string $name = 'Client site',
        string $url = 'https://client.test',
        string $password = 'hunter2'
    ): string {
        TenantContext::set($tenantId);

        $record = $this->app->make(RemoteSiteStore::class)->create(array(
            'name' => $name,
            'connection_type' => 'wordpress',
            'auth_type' => 'application_password',
            'url' => $url,
            'config' => array('username' => 'admin'),
            'credentials' => array('password' => $password),
            'enabled' => true,
        ));

        TenantContext::set(null);

        return (string) $record['id'];
    }
}

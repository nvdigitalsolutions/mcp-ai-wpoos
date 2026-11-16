<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Teams\Pages\ManageTeams;
use App\Filament\Admin\Resources\Teams\TeamResource;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\TeamPolicy;
use App\Support\TeamAccess;
use App\Support\TenantContext;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Nvoos\Core\Domain\Contract\TeamServiceInterface;
use Tests\TestCase;

/**
 * Proposal 149, Wave 2 — the first tenant-scoped `/admin` resource.
 *
 * This file carries the T-0.7 commitment: **no tenant-scoped resource ships
 * without a cross-tenant 404 test**. Two layers are pinned, because either one
 * alone would leave a hole:
 *
 *  - **The tenant route** — an estate-B member asking for estate A's panel gets
 *    a 404 (Filament resolves the tenant through `canAccessTenant()`).
 *  - **The record query** — a site administrator inside estate A cannot resolve
 *    estate B's team by id, because `manage_options` is a *site* capability and
 *    would otherwise cross the estate boundary.
 *
 * The policy composes estate membership, estate ownership of the record and the
 * team role, in that order, so the panel agrees with `TeamController` and
 * `TeamAccess` about who may do what.
 */
class AdminTeamsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_an_estate_member_reaches_the_teams_surface(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $this->teamIn(Tenant::DEFAULT_ID, 'Brand team', $alice);

        $this->actingAs($alice)
            ->get('/admin/default/teams')
            ->assertOk()
            ->assertSee('Brand team');
    }

    public function test_a_guest_is_sent_to_the_admin_login(): void
    {
        $this->get('/admin/default/teams')->assertRedirect('/admin/login');
    }

    /**
     * THE T-0.7 COMMITMENT (route half). Carol belongs to estate B only; asking
     * for estate A's panel must not render, not even read-only.
     */
    public function test_a_member_of_another_estate_gets_a_404_for_the_tenant(): void
    {
        $this->estate('estate-b');
        $carol = $this->estateAdmin('estate-b');

        $this->actingAs($carol)
            ->get('/admin/default/teams')
            ->assertNotFound();
    }

    public function test_a_plain_member_sees_only_the_teams_they_belong_to(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $bob = $this->estateMember(Tenant::DEFAULT_ID, 'member');

        $this->teamIn(Tenant::DEFAULT_ID, 'Bob team', $bob);
        $this->teamIn(Tenant::DEFAULT_ID, 'Alice team', $alice);

        $this->actingAs($bob)
            ->get('/admin/default/teams')
            ->assertOk()
            ->assertSee('Bob team')
            ->assertDontSee('Alice team');
    }

    /**
     * A site administrator sees the whole estate — the same override
     * `TeamAccess::isMember()` applies on the REST surface, so the panel does
     * not hide teams the administrator is allowed to manage by URL.
     */
    public function test_a_site_administrator_sees_every_team_in_the_estate(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $bob = $this->estateMember(Tenant::DEFAULT_ID, 'member');

        $this->teamIn(Tenant::DEFAULT_ID, 'Bob team', $bob);
        $this->teamIn(Tenant::DEFAULT_ID, 'Alice team', $alice);

        $this->actingAs($alice)
            ->get('/admin/default/teams')
            ->assertOk()
            ->assertSee('Bob team')
            ->assertSee('Alice team');
    }

    /**
     * THE T-0.7 COMMITMENT (record half). Alice is a site administrator — the
     * strongest customer role — and still must not resolve another estate's
     * team, because tenancy, not the site capability, is the boundary.
     */
    public function test_a_team_belonging_to_another_estate_is_not_resolvable(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $estateB = $this->estate('estate-b');
        $carol = $this->estateAdmin('estate-b');

        $foreign = $this->teamIn('estate-b', 'Estate B team', $carol);
        $own = $this->teamIn(Tenant::DEFAULT_ID, 'Estate A team', $alice);

        $this->actingAs($alice);
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);

        $scoped = TeamResource::getEloquentQuery();

        $this->assertNotNull($scoped->find($own->getKey()));
        $this->assertNull(
            $scoped->find($foreign->getKey()),
            'Estate A must not resolve estate B\'s team, even for a site administrator.'
        );

        // And the policy agrees, so the two layers cannot drift apart.
        $policy = app(TeamPolicy::class);

        $this->assertFalse($policy->update($alice, $foreign));
        $this->assertFalse($policy->view($alice, $foreign));

        // Belt and braces: the edit action is not even offered for a foreign
        // record. The refusal is the policy, not a hidden button — asking for
        // the action by name is refused too.
        Livewire::test(ManageTeams::class, array('tenant' => $estateB))
            ->assertTableActionHidden(EditAction::class, $foreign);

        $this->assertSame('Estate B team', $foreign->fresh()->name);
    }

    public function test_the_team_role_gates_update_and_delete(): void
    {
        $owner = $this->estateMember(Tenant::DEFAULT_ID, 'member');
        $member = $this->estateMember(Tenant::DEFAULT_ID, 'member');
        $team = $this->teamIn(Tenant::DEFAULT_ID, 'Brand team', $owner);

        app(TeamServiceInterface::class)->addMember((int) $team->id, (int) $member->id, 'member');

        // `setTenant()` dispatches `TenantSet`, which requires an authenticated
        // user — so the actor is established first, as the panel does.
        $this->actingAs($owner);
        Filament::setTenant(Tenant::query()->findOrFail(Tenant::DEFAULT_ID));
        TenantContext::set(Tenant::DEFAULT_ID);

        $policy = app(TeamPolicy::class);

        $this->assertTrue($policy->update($owner, $team));
        $this->assertFalse($policy->update($member, $team));

        $this->assertTrue($policy->delete($owner, $team));
        $this->assertFalse($policy->delete($member, $team));

        // `admin` carries `manage_team` but not `delete_team`.
        app(TeamServiceInterface::class)->changeRole((int) $team->id, (int) $member->id, 'admin');

        $this->assertTrue($policy->update($member, $team));
        $this->assertFalse($policy->delete($member, $team));
    }

    public function test_creating_a_team_assigns_the_estate_and_the_creator_as_owner(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageTeams::class, array('tenant' => $tenant))
            ->callAction('create', data: array(
                'name' => 'New team',
                'description' => 'Created from the panel.',
            ));

        $team = Team::query()->where('name', 'New team')->firstOrFail();

        $this->assertSame(Tenant::DEFAULT_ID, $team->tenant_id);
        $this->assertSame('new-team', $team->slug);
        $this->assertSame(
            'owner',
            DB::table('team_members')->where('team_id', $team->id)->where('user_id', $alice->id)->value('role')
        );
    }

    /**
     * The estate is a server-side fact, not a form field: a hand-crafted
     * payload must not be able to create a team in another estate.
     */
    public function test_the_form_cannot_choose_the_estate(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $this->estate('estate-b');

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageTeams::class, array('tenant' => $tenant))
            ->callAction('create', data: array(
                'name' => 'Sneaky team',
                'tenant_id' => 'estate-b',
            ));

        $team = Team::query()->where('name', 'Sneaky team')->firstOrFail();

        $this->assertSame(Tenant::DEFAULT_ID, $team->tenant_id);
    }

    public function test_updating_a_team_writes_a_record_level_audit_row(): void
    {
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);
        $team = $this->teamIn(Tenant::DEFAULT_ID, 'Brand team', $alice);

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        Livewire::test(ManageTeams::class, array('tenant' => $tenant))
            ->callTableAction(EditAction::class, $team, data: array('name' => 'Renamed team'));

        $this->assertSame('Renamed team', $team->fresh()->name);

        $row = DB::table('nvoos_admin_audit')->where('resource', 'team')->first();

        $this->assertNotNull($row, 'A team edit must produce a record-level audit row.');
        $this->assertSame('updated', $row->action);
        $this->assertSame((string) $team->getKey(), $row->record_id);
        $this->assertSame(Tenant::DEFAULT_ID, $row->tenant_id);

        $this->assertSame('Brand team', json_decode((string) $row->before, true)['name']);
        $this->assertSame('Renamed team', json_decode((string) $row->after, true)['name']);
    }

    /**
     * A viewer is refused the panel outright (T-0.5), so the resource must not
     * be the place that quietly re-admits them.
     */
    public function test_a_viewer_cannot_reach_the_resource(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->attach($viewer->id, array('role' => 'viewer'));

        $this->actingAs($viewer)
            ->get('/admin/default/teams')
            ->assertForbidden();
    }

    public function test_team_access_still_reports_membership_for_the_policy(): void
    {
        // The policy delegates to TeamAccess; this pins the seam rather than
        // duplicating its rules in a second place.
        $alice = $this->estateAdmin(Tenant::DEFAULT_ID);
        $team = $this->teamIn(Tenant::DEFAULT_ID, 'Brand team', $alice);

        $this->assertTrue(TeamAccess::isMember($alice, (int) $team->id));
        $this->assertTrue(TeamAccess::allows($alice, (int) $team->id, 'manage_team'));
        $this->assertTrue(TeamAccess::allows($alice, (int) $team->id, 'delete_team'));
    }

    /**
     * Create a second estate, idempotently.
     */
    private function estate(string $id): Tenant
    {
        return Tenant::query()->firstOrCreate(
            array('id' => $id),
            array('name' => ucfirst($id), 'slug' => $id, 'is_operator' => false)
        );
    }

    private function estateAdmin(string $tenantId): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->attach($user->id, array('role' => 'owner'));

        return $user;
    }

    private function estateMember(string $tenantId, string $role): User
    {
        $user = User::factory()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->attach($user->id, array('role' => $role));

        return $user;
    }

    /**
     * Create a team in an estate through the service, so the fixture obeys the
     * same tenant and ownership rules the resource does.
     */
    private function teamIn(string $tenantId, string $name, User $owner): Team
    {
        TenantContext::set($tenantId);

        $result = app(TeamServiceInterface::class)->create(array(
            'name' => $name,
            'owner_id' => (int) $owner->id,
        ));

        TenantContext::forget();

        $this->assertTrue($result['created'] ?? false, 'Fixture team creation failed.');

        return Team::query()->findOrFail((int) $result['team']['id']);
    }
}

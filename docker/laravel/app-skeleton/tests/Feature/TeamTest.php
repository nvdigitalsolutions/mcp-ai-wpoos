<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-g Phase 3 — teams and membership.
 *
 * The schema-level assertions (role enum, composite unique) fail against a
 * plain string column or a global unique, which is the point: the constraint
 * must hold for writes that bypass the application.
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_creating_a_team_makes_the_caller_its_owner(): void
    {
        $this->postJson('/api/v1/org/teams', ['name' => 'Design Guild'])
            ->assertCreated()
            ->assertJsonPath('name', 'Design Guild')
            ->assertJsonPath('member_count', 1);

        $this->assertDatabaseHas('teams', [
            'name' => 'Design Guild',
            'slug' => 'design-guild',
            'tenant_id' => TenantContext::current(),
        ]);

        $this->assertDatabaseHas('team_members', [
            'role' => 'owner',
            'user_id' => auth()->id(),
        ]);
    }

    public function test_any_authenticated_user_sees_only_their_teams(): void
    {
        $mine = $this->createTeam('Mine');

        $other = User::factory()->create();
        $this->app->make(\Nvoos\Core\Domain\Contract\TeamServiceInterface::class)
            ->create(['name' => 'Theirs', 'owner_id' => $other->id]);

        $this->getJson('/api/v1/org/teams')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('teams.0.id', (int) $mine->id)
            ->assertJsonPath('teams.0.role', 'owner');
    }

    public function test_show_returns_members_with_their_user_record(): void
    {
        $team = $this->createTeam('Studio');
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->postJson("/api/v1/org/teams/{$team->id}/members", [
            'user_id' => $user->id,
            'role' => 'manager',
            'profession_slug' => 'ux_designer',
        ])->assertCreated();

        $this->getJson("/api/v1/org/teams/{$team->id}")
            ->assertOk()
            ->assertJsonPath('data.member_count', 2)
            ->assertJsonFragment([
                'email' => $user->email,
                'profession_slug' => 'ux_designer',
            ]);
    }

    public function test_an_empty_profession_string_is_stored_as_null(): void
    {
        $team = $this->createTeam('Studio');
        $user = User::factory()->create();

        $this->postJson("/api/v1/org/teams/{$team->id}/members", [
            'user_id' => $user->id,
            'profession_slug' => '',
        ])->assertCreated();

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $user->id,
            'profession_slug' => null,
        ]);
    }

    public function test_adding_an_existing_member_updates_the_role(): void
    {
        $team = $this->createTeam('Studio');
        $user = User::factory()->create();

        $this->postJson("/api/v1/org/teams/{$team->id}/members", ['user_id' => $user->id, 'role' => 'member'])
            ->assertCreated();

        $this->postJson("/api/v1/org/teams/{$team->id}/members", ['user_id' => $user->id, 'role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $this->assertSame(1, TeamMember::query()
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->count());
    }

    public function test_the_last_owner_cannot_be_demoted_or_removed(): void
    {
        $team = $this->createTeam('Studio');
        $owner = (int) auth()->id();

        $this->patchJson("/api/v1/org/teams/{$team->id}/members/{$owner}", ['role' => 'viewer'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->deleteJson("/api/v1/org/teams/{$team->id}/members/{$owner}")
            ->assertStatus(422);

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $owner,
            'role' => 'owner',
        ]);
    }

    public function test_a_second_owner_can_be_demoted(): void
    {
        $team = $this->createTeam('Studio');
        $second = User::factory()->create();

        $this->postJson("/api/v1/org/teams/{$team->id}/members", ['user_id' => $second->id, 'role' => 'owner'])
            ->assertCreated();

        $this->patchJson("/api/v1/org/teams/{$team->id}/members/{$second->id}", ['role' => 'member'])
            ->assertOk()
            ->assertJsonPath('data.role', 'member');
    }

    public function test_the_role_set_is_enforced_by_the_database(): void
    {
        $team = $this->createTeam('Studio');
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('team_members')->insert([
            'tenant_id' => TenantContext::current(),
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'wizard',
            'joined_at' => now(),
        ]);
    }

    public function test_a_user_joins_a_team_once(): void
    {
        $team = $this->createTeam('Studio');
        $user = User::factory()->create();

        $row = [
            'tenant_id' => TenantContext::current(),
            'team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'member',
            'joined_at' => now(),
        ];

        DB::table('team_members')->insert($row);

        $this->expectException(QueryException::class);

        DB::table('team_members')->insert($row);
    }

    public function test_the_same_slug_is_allowed_in_another_tenant(): void
    {
        $this->createTeam('Shared Name');

        TenantContext::set('tenant-b');
        $this->createTeam('Shared Name');

        $this->assertSame(2, Team::query()->where('slug', 'shared-name')->count());
    }

    public function test_the_same_slug_is_rejected_within_a_tenant(): void
    {
        $this->createTeam('Shared Name');

        $this->expectException(QueryException::class);

        $duplicate = new Team(['name' => 'Shared Name', 'slug' => 'shared-name']);
        $duplicate->tenant_id = TenantContext::current();
        $duplicate->save();
    }

    public function test_a_deleted_team_disappears_from_the_list_and_the_show(): void
    {
        $team = $this->createTeam('Temporary');

        $this->deleteJson("/api/v1/org/teams/{$team->id}")->assertNoContent();
        $this->getJson("/api/v1/org/teams/{$team->id}")->assertNotFound();
        $this->getJson('/api/v1/org/teams')->assertJsonPath('total', 0);

        $this->assertSoftDeleted('teams', ['id' => $team->id]);
    }

    public function test_teams_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/org/teams')->assertUnauthorized();
    }

    public function test_an_unauthenticated_request_without_a_json_accept_header_is_401(): void
    {
        $this->app['auth']->forgetGuards();

        // The runtime is API-only and has no `login` route; a redirect attempt
        // used to surface as a 500. Plain `get()` sends `Accept: */*`.
        $this->get('/api/v1/org/teams')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_the_tenancy_indexes_are_present(): void
    {
        $teamIndexes = array_column(Schema::getIndexes('teams'), 'name');
        $this->assertContains('teams_tenant_id_slug_unique', $teamIndexes);
        $this->assertContains('teams_tenant_id_index', $teamIndexes);

        $memberIndexes = array_column(Schema::getIndexes('team_members'), 'name');
        $this->assertContains('team_members_team_id_user_id_unique', $memberIndexes);
        $this->assertContains('team_members_tenant_id_user_id_index', $memberIndexes);
        $this->assertContains('team_members_team_id_role_index', $memberIndexes);
    }

    private function createTeam(string $name): Team
    {
        $this->postJson('/api/v1/org/teams', ['name' => $name])->assertCreated();

        return Team::query()->where('name', $name)->latest('id')->firstOrFail();
    }
}

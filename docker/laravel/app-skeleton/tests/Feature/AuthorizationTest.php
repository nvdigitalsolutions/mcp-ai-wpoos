<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Support\CapabilityMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Nvoos\Laravel\Models\NvoosPost;
use Tests\TestCase;

/**
 * 090-g Phase 5 — WordPress-style site roles and team-scoped membership.
 *
 * Two scopes: the site role gates the console's administrative surfaces, the
 * membership role gates what a user may do to one team. Both are asserted here
 * because the previous behaviour hid buttons in the SPA but accepted the write
 * on the API.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_site_role_expands_to_wordpress_capabilities(): void
    {
        $this->assertContains('manage_options', CapabilityMap::capabilitiesForRole('administrator'));
        $this->assertContains('edit_others_posts', CapabilityMap::capabilitiesForRole('editor'));
        $this->assertNotContains('manage_options', CapabilityMap::capabilitiesForRole('editor'));
        $this->assertSame(
            ['publish_posts', 'edit_posts', 'delete_posts', 'upload_files', 'read'],
            CapabilityMap::capabilitiesForRole('author')
        );
        $this->assertSame(
            ['edit_posts', 'delete_posts', 'read'],
            CapabilityMap::capabilitiesForRole('contributor')
        );
        $this->assertSame(['read'], CapabilityMap::capabilitiesForRole('subscriber'));
    }

    public function test_the_factory_states_set_the_site_role(): void
    {
        $this->assertSame('administrator', User::factory()->admin()->create()->role);
        $this->assertSame('editor', User::factory()->editor()->create()->role);
        $this->assertSame('subscriber', User::factory()->create()->fresh()->role);
    }

    public function test_a_subscriber_cannot_reach_admin_surfaces(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/settings')->assertForbidden();
        $this->postJson('/api/v1/assistants', ['title' => 'Nope'])->assertForbidden();
        $this->getJson('/api/v1/professions')->assertForbidden();
        $this->getJson('/api/v1/teams')->assertForbidden();
    }

    public function test_an_editor_is_not_an_administrator(): void
    {
        Sanctum::actingAs(User::factory()->editor()->create());

        $this->getJson('/api/v1/settings')->assertForbidden();
        $this->postJson('/api/v1/assistants', ['title' => 'Nope'])->assertForbidden();
    }

    public function test_an_administrator_reaches_admin_surfaces(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/v1/settings')->assertOk();
        $this->getJson('/api/v1/professions')->assertOk();
        $this->getJson('/api/v1/teams')->assertOk();
    }

    public function test_any_authenticated_user_can_create_a_team_and_becomes_owner(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/org/teams', ['name' => 'Guild'])
            ->assertCreated()
            ->assertJsonPath('name', 'Guild');

        $this->assertDatabaseHas('team_members', [
            'role' => 'owner',
            'user_id' => auth()->id(),
        ]);
    }

    public function test_team_authorization_follows_the_membership_role(): void
    {
        // Owner creates the team through the API.
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/org/teams', ['name' => 'Guild'])->assertCreated();
        $team = Team::query()->where('name', 'Guild')->firstOrFail();

        // A member may read the team but not manage its roster.
        $member = User::factory()->create();
        $this->postJson("/api/v1/org/teams/{$team->id}/members", [
            'user_id' => $member->id,
            'role' => 'member',
        ])->assertCreated();

        Sanctum::actingAs($member);
        $this->getJson("/api/v1/org/teams/{$team->id}")->assertOk();
        $this->postJson("/api/v1/org/teams/{$team->id}/members", [
            'user_id' => User::factory()->create()->id,
        ])->assertForbidden();
        $this->deleteJson("/api/v1/org/teams/{$team->id}")->assertForbidden();

        // An outsider gets 404, not 403 — the API does not confirm the id.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/org/teams/{$team->id}")->assertNotFound();

        // The owner may manage and delete.
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/org/teams/{$team->id}/members", [
            'user_id' => User::factory()->create()->id,
            'role' => 'viewer',
        ])->assertCreated();
        $this->deleteJson("/api/v1/org/teams/{$team->id}")->assertNoContent();
    }

    public function test_a_site_administrator_overrides_team_membership(): void
    {
        $owner = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/org/teams', ['name' => 'Guild'])->assertCreated();
        $team = Team::query()->where('name', 'Guild')->firstOrFail();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/v1/org/teams/{$team->id}")->assertOk();
        $this->deleteJson("/api/v1/org/teams/{$team->id}")->assertNoContent();
    }

    public function test_content_abilities_follow_the_role_and_ownership(): void
    {
        $author = User::factory()->role('author')->create();
        $other = User::factory()->role('author')->create();
        $editor = User::factory()->editor()->create();
        $subscriber = User::factory()->create();

        $post = NvoosPost::create([
            'title' => 'Mine',
            'content' => '',
            'status' => 'publish',
            'type' => 'post',
            'author_id' => (int) $author->id,
            'slug' => 'mine',
        ]);

        $this->assertTrue(Gate::forUser($author)->allows('edit_content', [$post->id, $author->id]));
        $this->assertFalse(Gate::forUser($other)->allows('edit_content', [$post->id, $other->id]));
        $this->assertTrue(Gate::forUser($editor)->allows('edit_content', [$post->id, $editor->id]));
        		$this->assertFalse(Gate::forUser($subscriber)->allows('edit_content', [$post->id, $subscriber->id]));
        		// `read` is universal: every site role carries it.
        		$this->assertTrue(Gate::forUser($subscriber)->allows('read_content', [$post->id, $subscriber->id]));
    }
}

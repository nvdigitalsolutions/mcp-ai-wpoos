<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Nvoos\Laravel\Models\NvoosPost;
use Tests\TestCase;

/**
 * 090-g Phase 4 — the WordPress team-catalogue contract on Laravel.
 *
 * The assertions pin the exact shape WP_MCP_AI_REST_Teams_Controller emits,
 * because the same console page renders both runtimes.
 */
class TeamCatalogueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_index_lists_published_teams_with_member_counts(): void
    {
        $this->seedTeam('Editorial', ['ux_designer', 'contract_lawyer']);
        $this->seedTeam('Platform', ['software_engineer']);

        $this->getJson('/api/v1/teams')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('teams.0.title', 'Editorial')
            ->assertJsonPath('teams.0.member_count', 2)
            ->assertJsonPath('teams.1.title', 'Platform')
            ->assertJsonPath('teams.1.member_count', 1);
    }

    public function test_draft_teams_are_not_listed(): void
    {
        $this->seedTeam('Hidden', ['ux_designer'], ['status' => 'draft']);

        $this->getJson('/api/v1/teams')
            ->assertOk()
            ->assertJsonPath('teams', []);
    }

    public function test_members_resolve_catalogue_professions(): void
    {
        $team = $this->seedTeam('Editorial', ['ux_designer', 'software_engineer']);

        $response = $this->getJson("/api/v1/teams/{$team->id}/members")
            ->assertOk()
            ->assertJsonPath('team_id', (int) $team->id)
            ->assertJsonPath('team_title', 'Editorial')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('members.0.slug', 'ux_designer')
            ->assertJsonPath('members.0.title', 'UX Designer')
            ->assertJsonPath('members.0.category', 'Creative Services')
            ->assertJsonPath('members.0.category_slug', 'creative')
            ->assertJsonPath('members.0.tools_count', 3)
            ->assertJsonPath('orchestration_mode', 'sequential')
            ->assertJsonPath('result_aggregation', 'consensus')
            ->assertJsonPath('multi_agent_enabled', false)
            ->assertJsonPath('supports_unified_mode', false);

        // A catalogue profession has no post ID; the SPA falls back to the
        // array index for its React key, so the field must stay absent.
        $members = $response->json('members');
        $this->assertArrayNotHasKey('id', $members[0]);
    }

    public function test_team_meta_overrides_the_defaults(): void
    {
        $team = $this->seedTeam('Editorial', ['ux_designer'], [
            'meta' => [
                '_wp_mcp_ai_team_orchestration_mode' => 'parallel',
                '_wp_mcp_ai_team_result_aggregation' => 'vote',
                '_wp_mcp_ai_team_driver_assistant' => 42,
            ],
        ]);

        $this->getJson("/api/v1/teams/{$team->id}/members")
            ->assertOk()
            ->assertJsonPath('orchestration_mode', 'parallel')
            ->assertJsonPath('result_aggregation', 'vote')
            ->assertJsonPath('driver_assistant_id', 42)
            ->assertJsonPath('has_driver_assistant', true);
    }

    public function test_unknown_profession_slugs_are_skipped_not_fatal(): void
    {
        $team = $this->seedTeam('Editorial', ['ux_designer', 'retired_role']);

        $this->getJson("/api/v1/teams/{$team->id}/members")
            ->assertOk()
            ->assertJsonPath('count', 1)
            ->assertJsonPath('members.0.slug', 'ux_designer');

        // The list still counts the raw membership, as WordPress does.
        $this->getJson('/api/v1/teams')
            ->assertJsonPath('teams.0.member_count', 2);
    }

    public function test_a_missing_team_is_404(): void
    {
        $this->getJson('/api/v1/teams/99999/members')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_a_non_team_record_is_404(): void
    {
        $post = NvoosPost::create([
            'title' => 'A blog post',
            'content' => '',
            'status' => 'publish',
            'type' => 'post',
            'author_id' => (int) auth()->id(),
            'slug' => 'a-blog-post',
        ]);

        $this->getJson("/api/v1/teams/{$post->id}/members")->assertNotFound();
    }

    public function test_the_team_catalogue_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/teams')->assertUnauthorized();
    }

    /**
     * @param string[]             $members Profession slugs.
     * @param array<string, mixed> $overrides Column/meta overrides.
     */
    private function seedTeam(string $title, array $members, array $overrides = []): NvoosPost
    {
        $meta = array_merge(
            ['_wp_mcp_ai_team_members' => $members],
            $overrides['meta'] ?? [],
        );

        unset($overrides['meta']);

        return NvoosPost::create($overrides + [
            'title' => $title,
            'content' => '',
            'status' => 'publish',
            'type' => 'mcp_ai_team',
            'author_id' => (int) auth()->id(),
            'slug' => Str::slug($title),
            'meta' => $meta,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-i — slash commands.
 *
 * Three contracts matter more than the formatting:
 *
 *  1. **Only what can run is listed.** A command absent from the catalogue is
 *     absent from the drawer, so there is no button that 404s or silently
 *     does nothing.
 *  2. **Capability is per command.** The route is `edit_posts`, but `/status`,
 *     `/cost`, `/jobs`, `/model` and `/workflow` read administrative data and
 *     must be refused to an editor — in the catalogue *and* at execution.
 *  3. **Every attempt is audited.** A failed command is still a row; the audit
 *     answers "who tried what", not "what succeeded".
 */
final class SlashCommandsTest extends TestCase
{
    use RefreshDatabase;

    private const GRAPH = [
        'nodes' => [
            ['id' => 'node_1', 'type' => 'input', 'data' => ['label' => 'Start']],
            ['id' => 'node_2', 'type' => 'tool', 'data' => ['label' => 'Echo', 'toolSlug' => 'base64']],
        ],
        'edges' => [
            ['id' => 'edge_1', 'source' => 'node_1', 'target' => 'node_2'],
        ],
    ];

    private function actAsAdmin(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function actAsEditor(): User
    {
        $user = User::factory()->create(['role' => 'editor']);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return list<string>
     */
    private function catalogueNames(): array
    {
        return array_column($this->getJson('/api/v1/pro/slash-commands')->assertOk()->json('commands'), 'command');
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(string $command): array
    {
        return $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => $command])->json();
    }

    public function test_the_catalogue_requires_a_session(): void
    {
        $this->getJson('/api/v1/pro/slash-commands')->assertUnauthorized();
    }

    public function test_a_subscriber_cannot_reach_the_catalogue(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'subscriber']));

        $this->getJson('/api/v1/pro/slash-commands')->assertForbidden();
    }

    public function test_an_admin_sees_every_served_command(): void
    {
        $this->actAsAdmin();

        $names = $this->catalogueNames();
        sort($names);

        $this->assertSame(
            ['cost', 'help', 'jobs', 'model', 'status', 'tools', 'workflow'],
            $names,
        );
    }

    public function test_an_editor_sees_only_the_commands_they_can_run(): void
    {
        $this->actAsEditor();

        $names = $this->catalogueNames();
        sort($names);

        // Editors may read tools and help; the rest read administrative data.
        $this->assertSame(['help', 'tools'], $names);
    }

    public function test_the_catalogue_reports_sorted_unique_categories(): void
    {
        $this->actAsAdmin();

        $response = $this->getJson('/api/v1/pro/slash-commands')->assertOk()->json();

        $this->assertSame(['Analytics', 'System', 'Tools', 'Workflows'], $response['categories']);
    }

    public function test_search_filters_the_catalogue(): void
    {
        $this->actAsAdmin();

        $names = array_column(
            $this->getJson('/api/v1/pro/slash-commands?search=cost')->assertOk()->json('commands'),
            'command',
        );

        $this->assertSame(['cost'], $names);
    }

    public function test_help_lists_the_available_commands_grouped_by_category(): void
    {
        $this->actAsAdmin();

        $result = $this->execute('/help')['result'];

        $this->assertStringContainsString('Slash commands (7):', $result);
        $this->assertStringContainsString('System', $result);
        $this->assertStringContainsString('/status', $result);
    }

    public function test_help_describes_one_command(): void
    {
        $this->actAsEditor();

        $result = $this->execute('/help tools')['result'];

        $this->assertStringContainsString('/tools [search]', $result);
        $this->assertStringContainsString('Requires: edit_posts', $result);
    }

    public function test_help_for_a_hidden_command_is_not_found(): void
    {
        $this->actAsEditor();

        $response = $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => '/help status']);

        $response->assertStatus(400)
            ->assertJsonPath('code', 'command_not_found')
            ->assertJsonPath('data.status', 400);
    }

    public function test_an_unknown_command_uses_the_wordpress_error_shape(): void
    {
        $this->actAsAdmin();

        $response = $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => '/ship 12']);

        $response->assertStatus(400)
            ->assertJsonPath('code', 'command_not_found')
            ->assertJsonPath('message', 'Command "/ship" not found. Use /help to see available commands.');
    }

    public function test_input_without_a_slash_is_rejected(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => 'help'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_command_syntax')
            ->assertJsonPath('message', 'Commands must start with /');
    }

    public function test_an_editor_cannot_execute_an_administrative_command(): void
    {
        $this->actAsEditor();

        $response = $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => '/status']);

        $response->assertStatus(400)->assertJsonPath('code', 'insufficient_capability');

        // Nothing leaked: the response is the refusal, not a status payload.
        $this->assertArrayNotHasKey('result', $response->json());
    }

    public function test_status_reports_sourced_numbers(): void
    {
        $this->actAsAdmin();

        Assistant::query()->create([
            'title' => 'Enabled',
            'slug' => 'enabled',
            'status' => 'publish',
            'tenant_id' => Assistant::DEFAULT_TENANT,
        ]);
        Assistant::query()->create([
            'title' => 'Draft',
            'slug' => 'draft',
            'status' => 'draft',
            'tenant_id' => Assistant::DEFAULT_TENANT,
        ]);

        DB::table('nvoos_approvals')->insert([
            'status' => 'pending',
            'tool' => 'send_email',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('nvoos_token_usage')->insert([
            'user_id' => 1,
            'model_id' => 'gpt-test',
            'total_tokens' => 1200,
            'created_at' => now(),
        ]);

        $result = $this->execute('/status')['result'];

        $this->assertStringContainsString('Agents: 2 (1 enabled)', $result);
        $this->assertStringContainsString('Pending approvals: 1', $result);
        $this->assertStringContainsString('Tokens today: 1,200', $result);
        // No samples yet: null, not a fabricated percentage.
        $this->assertStringContainsString('unknown — no health samples yet', $result);
    }

    public function test_tools_lists_the_registry_and_filters_by_search(): void
    {
        $this->actAsAdmin();

        $all = $this->execute('/tools')['result'];
        $this->assertStringContainsString('Tools (', $all);
        $this->assertStringContainsString('base64', $all);

        $filtered = $this->execute('/tools base64')['result'];
        $this->assertStringContainsString('base64', $filtered);

        $miss = $this->execute('/tools zzz-not-a-tool')['result'];
        $this->assertStringContainsString('No tools match', $miss);
    }

    public function test_cost_summarises_the_window(): void
    {
        $this->actAsAdmin();

        DB::table('nvoos_token_usage')->insert([
            [
                'user_id' => 1,
                'model_id' => 'gpt-test',
                'total_tokens' => 100,
                'cost' => 0.5,
                'metadata' => json_encode(['provider' => 'openai']),
                'created_at' => now(),
            ],
            [
                'user_id' => 1,
                'model_id' => 'gpt-test',
                'total_tokens' => 200,
                'cost' => 0.25,
                'metadata' => json_encode(['provider' => 'openai']),
                'created_at' => now(),
            ],
        ]);

        $result = $this->execute('/cost')['result'];

        $this->assertStringContainsString('Cost summary (last 30 days):', $result);
        $this->assertStringContainsString('Tokens: 300', $result);
        $this->assertStringContainsString('0.75', $result);
        $this->assertStringContainsString('openai', $result);

        $windowed = $this->execute('/cost --days=7')['result'];
        $this->assertStringContainsString('last 7 days', $windowed);
    }

    public function test_model_reports_the_configured_pair(): void
    {
        $this->actAsAdmin();

        $result = $this->execute('/model')['result'];

        $this->assertStringContainsString('Provider:', $result);
        $this->assertStringContainsString('Model:', $result);
    }

    public function test_jobs_reports_the_empty_state_without_a_job_table(): void
    {
        $this->actAsAdmin();

        // The adapter's job table has no app migration yet, so the honest
        // answer is "no records", not a fabricated zero count.
        $this->assertStringContainsString('No job records', $this->execute('/jobs')['result']);
    }

    public function test_workflow_lists_and_shows_a_record(): void
    {
        $this->actAsAdmin();

        $this->assertStringContainsString('No workflows yet.', $this->execute('/workflow')['result']);

        $id = $this->postJson('/api/v1/orchestration/workflows', [
            'name' => 'Onboarding',
            'graph' => self::GRAPH,
        ])->assertOk()->json('id');

        $list = $this->execute('/workflow --list')['result'];
        $this->assertStringContainsString('Workflows (1):', $list);
        $this->assertStringContainsString('#'.$id.' Onboarding', $list);

        $show = $this->execute('/workflow --show='.$id)['result'];
        $this->assertStringContainsString('Nodes: 2', $show);
        $this->assertStringContainsString('Edges: 1', $show);
    }

    public function test_a_missing_workflow_is_a_typed_error(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => '/workflow --show=999'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'not_found');
    }

    public function test_show_without_an_id_reports_the_usage(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => '/workflow --show'])
            ->assertStatus(400)
            ->assertJsonPath('code', 'invalid_argument')
            ->assertJsonPath('message', 'Usage: /workflow [--list | --show=ID]');
    }

    public function test_a_successful_execution_writes_one_audit_row(): void
    {
        $user = $this->actAsAdmin();

        $response = $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => '/help'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('command', 'help');

        $correlationId = $response->json('correlation_id');

        $row = DB::table('nvoos_slash_command_audit')->first();

        $this->assertSame('help', $row->command);
        $this->assertSame($user->id, (int) $row->user_id);
        $this->assertSame('completed', $row->status);
        $this->assertSame('success', $row->result);
        $this->assertSame($correlationId, $row->correlation_id);
    }

    public function test_a_failed_command_is_audited_too(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => '/nope'])->assertStatus(400);

        $row = DB::table('nvoos_slash_command_audit')->first();

        $this->assertSame('nope', $row->command);
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('not found', $row->result);
    }

    public function test_a_parse_failure_is_not_audited(): void
    {
        $this->actAsAdmin();

        $this->postJson('/api/v1/pro/slash-commands/execute', ['command' => 'help'])->assertStatus(400);

        // No command name was parsed, so there is nothing to attribute.
        $this->assertSame(0, DB::table('nvoos_slash_command_audit')->count());
    }

    public function test_the_manifest_reports_slash_commands_as_route_supplied(): void
    {
        $this->actAsAdmin();

        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.slashCommands', true)
            ->assertJsonPath('data.endpoint_reasons.slashCommands', 'route')
            ->assertJsonPath('data.endpoint_gaps', []);
    }
}

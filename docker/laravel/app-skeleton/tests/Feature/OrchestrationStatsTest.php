<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-B — `GET /api/v1/orchestration/stats`.
 *
 * The console's Orchestration page reads a narrow slice of this payload: the
 * agent table, the profession counters, the role distribution, and four
 * subsystem cards. The assertions below cover exactly those, plus the fields
 * that are deliberately empty so nobody later reads them as a bug.
 */
class OrchestrationStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function overview(): array
    {
        $response = $this->getJson('/api/v1/orchestration/stats');

        $response->assertOk()->assertJsonPath('success', true);

        return (array) $response->json('data');
    }

    public function test_it_requires_manage_options(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'subscriber']));

        $this->getJson('/api/v1/orchestration/stats')->assertForbidden();
    }

    public function test_agents_are_the_tenant_assistants(): void
    {
        $this->postJson('/api/v1/assistants', [
            'title' => 'Research Agent',
            'slug' => 'research-agent',
            'status' => 'publish',
            'provider' => 'openai',
            'model' => 'gpt-4o',
            'tools' => ['web_search', 'get_post'],
        ])->assertCreated();

        $overview = $this->overview();
        $agents = $overview['multi_agent']['agents'];

        // The admin factory user's own default assistant may exist; find ours.
        $agent = null;

        foreach ($agents as $candidate) {
            if ('Research Agent' === $candidate['title']) {
                $agent = $candidate;
            }
        }

        $this->assertNotNull($agent, 'The assistant should appear as an agent.');
        $this->assertSame('research-agent', $agent['slug']);
        $this->assertSame('publish', $agent['status']);
        $this->assertSame('openai', $agent['provider']);
        $this->assertSame('gpt-4o', $agent['model']);
        $this->assertSame(['web_search', 'get_post'], $agent['tools']);
        $this->assertSame(2, $agent['tool_count']);

        // Deliberately empty: neither field has a column on this runtime.
        $this->assertSame('', $agent['last_used']);
        $this->assertSame('', $agent['workflow_pattern']);
    }

    public function test_the_multi_agent_summary_counts_agents_and_tools(): void
    {
        $overview = $this->overview();
        $multi = $overview['multi_agent'];

        $this->assertTrue($multi['installed']);
        $this->assertSame(count($multi['agents']), $multi['total_agents']);
        $this->assertIsInt($multi['total_tools']);
        $this->assertGreaterThan(0, $multi['total_tools']);
        $this->assertIsBool($multi['is_pro_active']);
        $this->assertSame([], (array) $multi['patterns']);
    }

    public function test_profession_counters_come_from_the_catalogue(): void
    {
        $orchestration = $this->overview()['orchestration'];

        $this->assertIsInt($orchestration['total_professions']);
        $this->assertSame($orchestration['total_professions'], $orchestration['seeded_professions']);
        $this->assertSame(0, $orchestration['with_task_patterns']);
        $this->assertNotSame('', $orchestration['seeder_version']);
    }

    public function test_roles_are_the_site_role_distribution(): void
    {
        User::factory()->create(['role' => 'editor']);
        User::factory()->create(['role' => 'editor']);

        $roles = (array) $this->overview()['orchestration']['roles'];

        $this->assertArrayHasKey('administrator', $roles);
        $this->assertSame(2, $roles['editor']);
    }

    public function test_the_subsystem_cards_report_real_values(): void
    {
        $system = $this->overview()['orchestration']['system_status'];

        $this->assertIsInt($system['cron']['total']);
        $this->assertContains($system['async']['status'], ['idle', 'active']);
        $this->assertTrue($system['sse']['available']);
        $this->assertSame('ok', $system['health']['status']);
    }

    public function test_workflow_fields_are_honestly_empty(): void
    {
        // There is no workflow engine on this runtime; the console reports the
        // `workflows` endpoint unavailable, and this list stays empty.
        $this->assertSame([], $this->overview()['recent_workflows']);
    }
}

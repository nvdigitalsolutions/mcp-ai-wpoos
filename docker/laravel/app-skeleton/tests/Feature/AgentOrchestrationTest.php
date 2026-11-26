<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Services\AssistantWriter;
use App\Support\Orchestration\AssistantAgentRunner;
use App\Support\Orchestration\TenantContextResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AgentOrchestrationInterface;
use Nvoos\Core\Domain\Contract\AgentRunnerInterface;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\CacheStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\TenantResolverInterface;
use Nvoos\Core\Tool\CheckWorkflowHealthTool;
use Nvoos\Core\Tool\CreateAgentTeamTool;
use Nvoos\Core\Tool\DelegateToAgentTool;
use Nvoos\Core\Tool\ExecuteWorkflowTool;
use Nvoos\Laravel\Adapter\AgentOrchestration;
use Tests\Support\RecordingAgentRunner;
use Tests\TestCase;

/**
 * Agent orchestration in the host — Proposal 149 §163.
 *
 * The adapter's own unit tests pin composition and bounds. This file pins the
 * things only the host can prove: the two seams bind to real app classes, the
 * four tools are registered once the manifest serves them, and every path runs
 * against the **real** assistant catalogue, cache and estate scope. The model
 * turn is the one stubbed dependency, because the point under test is
 * orchestration, not the chat pipeline.
 */
final class AgentOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    private RecordingAgentRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new RecordingAgentRunner();

        // The package binds the adapter at boot, which is before this test can
        // substitute a runner. Rebinding the adapter captures the fake for the
        // tools this file constructs below.
        $this->app->bind(AgentOrchestrationInterface::class, fn (): AgentOrchestration => new AgentOrchestration(
            $this->app->make(CacheStoreInterface::class),
            $this->app->make(AssistantServiceInterface::class),
            $this->runner,
            $this->app->make(TenantResolverInterface::class),
        ));
    }

    public function test_the_host_seams_bind_to_the_app_implementations(): void
    {
        $this->assertInstanceOf(AssistantAgentRunner::class, $this->app->make(AgentRunnerInterface::class));
        $this->assertInstanceOf(TenantContextResolver::class, $this->app->make(TenantResolverInterface::class));
    }

    public function test_the_four_tools_are_registered_once_the_manifest_serves_them(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        foreach (array('create_agent_team', 'check_workflow_health', 'delegate_to_agent', 'execute_workflow') as $slug) {
            $this->assertNotNull($registry->get($slug), $slug.' is not registered.');
        }
    }

    public function test_compose_resolves_a_primary_role_slug_from_the_estate_catalogue(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Research Analyst', array(
            'slug' => 'research-analyst',
            'primary_role_slugs' => array('researcher'),
        ));
        $this->assistantIn(Tenant::DEFAULT_ID, 'Copywriter', array('slug' => 'copywriter'));

        $result = $this->compose()->execute(array(
            'task_description' => 'Draft the launch brief',
            'roles' => array('researcher'),
        ));

        $this->assertTrue($result['success']);

        $members = $result['data']['members'];

        $this->assertCount(1, $members);
        $this->assertSame('Research Analyst', $members[0]['title']);
        $this->assertSame('primary_role', $members[0]['matched_by']);
    }

    public function test_compose_falls_back_to_the_estate_default_assistant(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'General Assistant', array('slug' => 'general'));

        $result = $this->compose()->execute(array(
            'task_description' => 'Triage the inbox',
            'roles' => array('role-nobody-declares'),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('General Assistant', $result['data']['members'][0]['title']);
        $this->assertSame('default', $result['data']['members'][0]['matched_by']);
    }

    public function test_a_wordpress_shaped_payload_composes(): void
    {
        $analyst = $this->assistantIn(Tenant::DEFAULT_ID, 'Research Analyst', array(
            'slug' => 'research-analyst',
            'primary_role_slugs' => array('researcher'),
        ));

        // WordPress requires `task_type` and has no description field. The
        // canonical payload must compose here rather than fail validation.
        $result = $this->compose()->execute(array(
            'task_type' => 'research',
            'roles' => array('researcher'),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('research', $result['data']['task_type']);
        $this->assertSame((int) $analyst->id, $result['data']['members'][0]['assistant_id']);
    }

    public function test_the_tool_honours_an_explicit_agents_map(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Copywriter', array('slug' => 'copywriter'));
        $analyst = $this->assistantIn(Tenant::DEFAULT_ID, 'Research Analyst', array(
            'slug' => 'research-analyst',
            'primary_role_slugs' => array('researcher'),
        ));

        $result = $this->compose()->execute(array(
            'task_description' => 'Draft the brief',
            'roles' => array('writer'),
            'agents' => array('writer' => $analyst->id),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame((int) $analyst->id, $result['data']['members'][0]['assistant_id']);
        $this->assertSame('explicit', $result['data']['members'][0]['matched_by']);
    }

    public function test_a_team_is_invisible_from_another_estate(): void
    {
        $this->assistantIn(Tenant::DEFAULT_ID, 'Copywriter', array('slug' => 'copywriter'));

        $team = $this->composeTeam(array('copywriter'));

        // Same runtime, different estate: the cache key is namespaced, so the
        // team is not found rather than served across the boundary.
        TenantContext::set('estate-b');

        $health = $this->health()->execute(array('team_id' => $team['id']));

        $this->assertInstanceOf(\Throwable::class, $health);
    }

    public function test_delegate_runs_as_the_caller(): void
    {
        $writer = $this->assistantIn(Tenant::DEFAULT_ID, 'Copywriter', array('slug' => 'copywriter'));
        $team = $this->composeTeam(array('copywriter'));

        $result = $this->delegate()->execute(
            array(
                'team_id' => $team['id'],
                'agent_id' => (string) $writer->id,
                'task' => array('instruction' => 'Draft the intro'),
            ),
            array('user_id' => 42)
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->runner->calls);
        $this->assertSame((int) $writer->id, $this->runner->calls[0]['assistantId']);
        $this->assertSame('Draft the intro', $this->runner->calls[0]['prompt']);
        $this->assertSame(42, $this->runner->calls[0]['userId']);
    }

    public function test_the_four_tools_run_end_to_end(): void
    {
        $writer = $this->assistantIn(Tenant::DEFAULT_ID, 'Copywriter', array(
            'slug' => 'copywriter',
            'primary_role_slugs' => array('writer'),
        ));
        $analyst = $this->assistantIn(Tenant::DEFAULT_ID, 'Research Analyst', array(
            'slug' => 'research-analyst',
            'primary_role_slugs' => array('researcher'),
        ));

        $team = $this->composeTeam(array('researcher', 'writer'));

        $health = $this->health()->execute(array('team_id' => $team['id']));

        $this->assertTrue($health['success']);
        $this->assertSame($team['id'], $health['data']['id']);

        $delegated = $this->delegate()->execute(array(
            'team_id' => $team['id'],
            'agent_id' => (string) $analyst->id,
            'task' => array('instruction' => 'Gather three facts'),
        ));

        $this->assertTrue($delegated['success']);
        $this->assertSame('done:'.$analyst->id, $delegated['data']['result']);

        $workflow = $this->workflow()->execute(array(
            'team_id' => $team['id'],
            'workflow' => array(
                'steps' => array(
                    array('role' => 'researcher', 'instruction' => 'Gather the facts'),
                    array('role' => 'writer', 'instruction' => 'Write the brief'),
                ),
            ),
        ));

        $this->assertTrue($workflow['success']);
        $this->assertCount(2, $workflow['data']['results']);
        $this->assertSame((int) $analyst->id, $workflow['data']['results'][0]['assistant_id']);
        $this->assertSame((int) $writer->id, $workflow['data']['results'][1]['assistant_id']);

        		// Step two inherits step one's result rather than starting cold.
        		$lastCall = $this->runner->calls[count($this->runner->calls) - 1];

        		$this->assertStringContainsString('Previous step results:', $lastCall['prompt']);
        		$this->assertStringContainsString('done:'.$analyst->id, $lastCall['prompt']);
    }

    /**
     * @param array<int, string> $roles
     * @return array<string, mixed>
     */
    private function composeTeam(array $roles): array
    {
        		$result = $this->compose()->execute(array(
        			'task_description' => 'Ship the campaign',
        			'roles' => $roles,
        		));

        		$this->assertIsArray($result, is_object($result) ? $result->getMessage() : 'composition failed');
        		$this->assertTrue($result['success'], $result['message'] ?? 'composition failed');

        return $result['data'];
    }

    private function orchestration(): AgentOrchestrationInterface
    {
        return $this->app->make(AgentOrchestrationInterface::class);
    }

    private function compose(): CreateAgentTeamTool
    {
        return new CreateAgentTeamTool($this->errors(), $this->orchestration());
    }

    private function health(): CheckWorkflowHealthTool
    {
        return new CheckWorkflowHealthTool($this->errors(), $this->orchestration());
    }

    private function delegate(): DelegateToAgentTool
    {
        return new DelegateToAgentTool($this->errors(), $this->orchestration());
    }

    private function workflow(): ExecuteWorkflowTool
    {
        return new ExecuteWorkflowTool($this->errors(), $this->orchestration());
    }

    private function errors(): ErrorFactoryInterface
    {
        return $this->app->make(ErrorFactoryInterface::class);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assistantIn(string $tenantId, string $title, array $data = array()): Assistant
    {
        $previous = TenantContext::isPinned() ? TenantContext::current() : null;

        TenantContext::set($tenantId);

        $assistant = app(AssistantWriter::class)->create($data + array('title' => $title));

        null === $previous ? TenantContext::forget() : TenantContext::set($previous);

        return $assistant;
    }
}

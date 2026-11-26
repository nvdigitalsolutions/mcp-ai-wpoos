<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Application\Skill\SkillRegistry;
use Nvoos\Core\Tool\CalculateErlangCTool;
use Nvoos\Core\Tool\CreateChartTool;
use Nvoos\Core\Tool\CreateChartValidatedTool;
use Nvoos\Core\Tool\DeepResearchTool;
use Nvoos\Core\Tool\ErlangCConcurrencyAdvisorTool;
use Nvoos\Core\Tool\ErlangCStaffingAdvisorTool;
use Nvoos\Core\Tool\GenerateChartTool;
use Nvoos\Core\Tool\GenerateMermaidTool;
use Nvoos\Core\Tool\GenerateSimpleJWTTokenTool;
use Nvoos\Core\Tool\LoadSkillTool;
use Nvoos\Core\Tool\WaitForUserTool;
use Tests\TestCase;

/**
 * Batch 4 evidence, part one: chart, planning and arithmetic tools
 * (Proposal 160 §6).
 *
 * These eleven rows need no provider: charts and Mermaid are configuration
 * builders, the JWT tool signs locally, the Erlang C family computes, and
 * `deep_research` returns a plan rather than calling out. That makes them
 * `pure` — proven with `Http::assertNothingSent()` rather than assumed.
 *
 * `load_skill` is the exception: it reads a skill registry from disk, so it is
 * `ready` rather than `pure`. Its positive path is exercised against a temporary
 * registry directory, because the shipped registry is empty until a deployment
 * seeds it.
 */
class ChartAndPlanningToolsTest extends TestCase
{
    /**
     * @param class-string $class
     */
    private function execute(string $class, array $arguments = array(), array $context = array()): array
    {
        $result = $this->app->make($class)->execute($arguments, $context);

        $this->assertIsArray($result, $class . ' returned a non-array result: ' . print_r($result, true));

        return $result;
    }

    private function chartArgs(): array
    {
        return array(
            'type' => 'bar',
            'data' => array(
                'labels' => array('Q1', 'Q2'),
                'datasets' => array(
                    array('label' => 'Revenue', 'data' => array(10, 20)),
                ),
            ),
            'title' => 'Quarterly revenue',
        );
    }

    // ─── Charts (pure) ───────────────────────────────────────────────────

    public function test_create_chart_builds_a_chart_js_config(): void
    {
        Http::fake();

        $result = $this->execute(CreateChartTool::class, $this->chartArgs());

        $this->assertTrue($result['success']);
        $this->assertSame('bar', $result['data']['chart_config']['type']);
        $this->assertSame('Quarterly revenue', $result['data']['title']);
        $this->assertTrue($result['data']['chart_config']['options']['plugins']['title']['display']);

        Http::assertNothingSent();
    }

    public function test_create_chart_validated_shares_the_same_builder(): void
    {
        Http::fake();

        $result = $this->execute(CreateChartValidatedTool::class, $this->chartArgs());

        $this->assertTrue($result['success']);
        $this->assertSame('bar', $result['data']['chart_config']['type']);

        Http::assertNothingSent();
    }

    public function test_generate_chart_builds_a_config(): void
    {
        Http::fake();

        $result = $this->execute(GenerateChartTool::class, $this->chartArgs());

        $this->assertTrue($result['success']);
        $this->assertSame('bar', $result['data']['chart_config']['type']);

        Http::assertNothingSent();
    }

    public function test_generate_mermaid_emits_the_diagram_definition(): void
    {
        Http::fake();

        $result = $this->execute(GenerateMermaidTool::class, array(
            'type' => 'flowchart',
            'code' => "graph TD\n  A-->B",
        ));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('graph TD', (string) json_encode($result['data']));

        Http::assertNothingSent();
    }

    // ─── Tokens and prompts (pure) ───────────────────────────────────────

    public function test_generate_simple_jwt_token_signs_locally(): void
    {
        Http::fake();

        $result = $this->execute(GenerateSimpleJWTTokenTool::class, array(
            'payload' => array('sub' => 'user-1'),
            'secret' => 's3cret',
        ));

        $this->assertTrue($result['success']);
        $this->assertIsString($result['data']['token']);
        $this->assertSame(2, substr_count($result['data']['token'], '.'));

        Http::assertNothingSent();
    }

    public function test_wait_for_user_returns_the_prompt(): void
    {
        Http::fake();

        $result = $this->execute(WaitForUserTool::class, array('prompt' => 'Which estate?'));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Which estate?', (string) json_encode($result['data']));

        Http::assertNothingSent();
    }

    // ─── Skills (ready: reads a registry) ────────────────────────────────

    public function test_load_skill_reads_a_registry_file(): void
    {
        $root = sys_get_temp_dir() . '/oos-skills-' . bin2hex(random_bytes(4));
        mkdir($root . '/demo', 0777, true);
        file_put_contents(
            $root . '/demo/SKILL.md',
            "---\nname: demo\ndescription: A demo skill.\n---\nDo the demo thing.\n",
        );

        $registry = $this->app->make(SkillRegistry::class);
        $this->assertSame(1, $registry->loadFromDirectory($root));

        // The registry is resolved per injection, so bind the seeded instance
        // before asking the container for the tool.
        $this->app->instance(SkillRegistry::class, $registry);

        $result = $this->execute(LoadSkillTool::class, array('name' => 'demo'));

        $this->assertTrue($result['success']);
        $this->assertSame('demo', $result['data']['name']);
        $this->assertStringContainsString('Do the demo thing', (string) $result['data']['content']);

        @unlink($root . '/demo/SKILL.md');
        @rmdir($root . '/demo');
        @rmdir($root);
    }

    public function test_load_skill_reports_an_unknown_name(): void
    {
        $result = $this->app->make(LoadSkillTool::class)->execute(array('name' => 'definitely-not-a-skill'));

        // The error factory returns a RuntimeException carrying the status.
        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(404, $result->getCode());
    }

    // ─── Erlang C family (pure arithmetic) ───────────────────────────────

    public function test_calculate_erlang_c_returns_the_metrics(): void
    {
        Http::fake();

        $result = $this->execute(CalculateErlangCTool::class, array(
            'arrival_rate' => 30,
            'avg_handle_time' => 180,
            'agents' => 3,
        ));

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['data']);

        Http::assertNothingSent();
    }

    public function test_erlang_c_concurrency_advisor_returns_a_recommendation(): void
    {
        Http::fake();

        $result = $this->execute(ErlangCConcurrencyAdvisorTool::class, array(
            'name' => 'Chat',
            'arrival_rate_per_hour' => 30,
            'avg_session_duration' => 180,
            'window_hours' => 8,
        ), array('user_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['data']);

        Http::assertNothingSent();
    }

    public function test_erlang_c_staffing_advisor_returns_a_recommendation(): void
    {
        Http::fake();

        $result = $this->execute(ErlangCStaffingAdvisorTool::class, array(
            'channels' => array(
                array(
                    'name' => 'Chat',
                    'arrival_rate_per_hour' => 30,
                    'avg_handle_time' => 180,
                ),
            ),
        ));

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['data']);

        Http::assertNothingSent();
    }

    // ─── Deep research (a plan, not a call) ──────────────────────────────

    public function test_deep_research_returns_a_plan_without_calling_out(): void
    {
        Http::fake();

        $result = $this->execute(DeepResearchTool::class, array(
            'query' => 'estate agent benchmarks',
            'depth' => 3,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(array('search', 'analyze', 'synthesize'), $result['data']['steps']);
        $this->assertSame(3, $result['data']['depth']);

        Http::assertNothingSent();
    }
}

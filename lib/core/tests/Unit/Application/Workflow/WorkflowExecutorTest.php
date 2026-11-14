<?php
/**
 * Tests for the deterministic workflow executor.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Workflow;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Application\Workflow\WorkflowExecutor;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WorkflowExecutorTest extends TestCase
{
    private ToolRegistry&MockObject $tools;
    private ErrorFactoryInterface&MockObject $errors;
    private WorkflowExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tools = $this->createMock(ToolRegistry::class);
        $this->errors = $this->createMock(ErrorFactoryInterface::class);
        $this->errors->method('isError')->willReturnCallback(
            static fn ($value) => $value instanceof \stdClass
        );
        $this->errors->method('normalize')->willReturn(
            ['code' => 'exception', 'message' => 'Tool exploded.']
        );

        $this->executor = new WorkflowExecutor($this->tools, $this->errors);
    }

    /**
     * @param list<array<string, mixed>> $nodes Nodes.
     * @param list<array<string, mixed>> $edges Edges.
     * @return array<string, mixed>
     */
    private function graph(array $nodes, array $edges = []): array
    {
        return ['nodes' => $nodes, 'edges' => $edges];
    }

    public function test_nodes_run_in_topological_order_with_resolved_arguments(): void
    {
        $calls = [];

        $this->tools->expects($this->exactly(2))
            ->method('execute')
            ->willReturnCallback(function (string $slug, array $args) use (&$calls) {
                $calls[] = [$slug, $args];

                return 'create_post' === $slug ? ['post_id' => 42] : ['saved' => true];
            });

        $result = $this->executor->execute(
            $this->graph(
                [
                    ['id' => 'node_1', 'type' => 'tool', 'data' => ['toolSlug' => 'create_post']],
                    [
                        'id' => 'node_2',
                        'type' => 'tool',
                        'data' => ['toolSlug' => 'save_post', 'arguments' => ['id' => '{{ node_1.post_id }}']],
                    ],
                ],
                [['source' => 'node_1', 'target' => 'node_2']]
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(2, $result['tool_calls']);
        $this->assertSame(42, $calls[1][1]['id']);
        $this->assertSame(['post_id' => 42], $result['nodes']['node_1']['output']);
    }

    public function test_a_false_condition_finishes_without_failing(): void
    {
        $result = $this->executor->execute(
            $this->graph([
                [
                    'id' => 'node_1',
                    'type' => 'condition',
                    'data' => ['expression' => 'input.count > 10'],
                ],
            ]),
            ['count' => 3]
        );

        $this->assertTrue($result['success']);
        $this->assertFalse($result['nodes']['node_1']['output']);
    }

    public function test_a_failed_node_stops_the_run_and_skips_downstream(): void
    {
        $this->tools->expects($this->exactly(2))
            ->method('execute')
            ->willReturnOnConsecutiveCalls('ok', new \stdClass());

        $result = $this->executor->execute(
            $this->graph(
                [
                    ['id' => 'node_1', 'type' => 'tool', 'data' => ['toolSlug' => 'create_post']],
                    ['id' => 'node_2', 'type' => 'tool', 'data' => ['toolSlug' => 'save_post']],
                    ['id' => 'node_3', 'type' => 'tool', 'data' => ['toolSlug' => 'publish']],
                ],
                [
                    ['source' => 'node_1', 'target' => 'node_2'],
                    ['source' => 'node_2', 'target' => 'node_3'],
                ]
            )
        );

        $this->assertFalse($result['success']);
        $this->assertSame('failed', $result['status']);
        $this->assertSame('Tool exploded.', $result['error']);
        $this->assertSame('completed', $result['nodes']['node_1']['status']);
        $this->assertSame('failed', $result['nodes']['node_2']['status']);
        $this->assertSame('skipped', $result['nodes']['node_3']['status']);
    }

    public function test_the_tool_call_ceiling_stops_a_wide_graph(): void
    {
        $this->tools->method('execute')->willReturn('ok');

        $nodes = [];
        for ($i = 1; $i <= 3; ++$i) {
            $nodes[] = ['id' => "node_{$i}", 'type' => 'tool', 'data' => ['toolSlug' => 'noop']];
        }

        $result = $this->executor->execute(
            $this->graph($nodes),
            [],
            ['max_tool_calls' => 2]
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('tool-call ceiling', (string) $result['error']);
        $this->assertSame(2, $result['tool_calls']);
    }

    public function test_a_delay_node_is_capped_and_never_blocks_the_run(): void
    {
        $result = $this->executor->execute(
            $this->graph([
                ['id' => 'node_1', 'type' => 'delay', 'data' => ['seconds' => 600]],
            ]),
            [],
            ['delay_cap' => 0]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['slept' => 0], $result['nodes']['node_1']['output']);
    }

    public function test_an_agent_node_uses_the_injected_runner(): void
    {
        $received = null;

        $result = $this->executor->execute(
            $this->graph([
                [
                    'id' => 'node_1',
                    'type' => 'agent',
                    'data' => ['prompt' => 'Summarise {{ input.topic }}', 'agentId' => 'writer'],
                ],
            ]),
            ['topic' => 'Spring menu'],
            [
                'agent_runner' => function (string $agentId, string $prompt) use (&$received) {
                    $received = [$agentId, $prompt];

                    return ['text' => 'done'];
                },
            ]
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['writer', 'Summarise Spring menu'], $received);
        $this->assertSame(['text' => 'done'], $result['nodes']['node_1']['output']);
    }

    public function test_an_agent_node_without_a_runner_fails_typed(): void
    {
        $result = $this->executor->execute(
            $this->graph([
                ['id' => 'node_1', 'type' => 'agent', 'data' => ['prompt' => 'Do it']],
            ])
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('agent runner', (string) $result['error']);
    }

    public function test_input_and_output_nodes_pass_values_through(): void
    {
        $result = $this->executor->execute(
            $this->graph(
                [
                    ['id' => 'node_1', 'type' => 'input', 'data' => ['label' => 'Topic']],
                    ['id' => 'node_2', 'type' => 'output', 'data' => ['label' => 'Result']],
                ],
                [['source' => 'node_1', 'target' => 'node_2']]
            ),
            ['topic' => 'Spring menu']
        );

        $this->assertTrue($result['success']);
        $this->assertSame(['topic' => 'Spring menu'], $result['nodes']['node_1']['output']);
        $this->assertNull($result['nodes']['node_2']['output']);
    }

    public function test_an_unhandled_node_type_without_a_tool_is_a_no_op(): void
    {
        $this->tools->expects($this->never())->method('execute');

        $result = $this->executor->execute(
            $this->graph([['id' => 'node_1', 'type' => 'report', 'data' => ['label' => 'R-03']]])
        );

        $this->assertTrue($result['success']);
        $this->assertNull($result['nodes']['node_1']['output']);
    }

    public function test_a_cycle_is_reported_rather_than_partially_succeeding(): void
    {
        $result = $this->executor->execute(
            $this->graph(
                [
                    ['id' => 'a', 'type' => 'output'],
                    ['id' => 'b', 'type' => 'output'],
                ],
                [
                    ['source' => 'a', 'target' => 'b'],
                    ['source' => 'b', 'target' => 'a'],
                ]
            )
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cycle', (string) $result['error']);
    }

    public function test_a_dangling_edge_is_rejected_before_anything_runs(): void
    {
        $this->tools->expects($this->never())->method('execute');

        $result = $this->executor->execute(
            $this->graph(
                [['id' => 'a', 'type' => 'tool', 'data' => ['toolSlug' => 'noop']]],
                [['source' => 'a', 'target' => 'missing']]
            )
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not exist', (string) $result['error']);
    }
}

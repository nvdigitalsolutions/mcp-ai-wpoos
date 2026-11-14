<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Application\Workflow\WorkflowExecutor;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentItem;

/**
 * Runs a workflow record and records the run.
 *
 * Extracted because a workflow can be started three ways — the console's
 * "Run now", a trigger firing, and (later) a schedule — and every one must
 * produce the same row and the same payload. The executor itself lives in
 * `lib/core`; this class owns the Laravel side (the `nvoos_workflow_runs`
 * row and the agent runner binding).
 */
final class WorkflowRunner
{
    public function __construct(
        private readonly ToolRegistry $tools,
        private readonly ErrorFactoryInterface $errors,
    ) {
    }

    /**
     * Execute a workflow and persist the run.
     *
     * @param ContentItem          $workflow  The workflow record.
     * @param array<string, mixed> $input     Run input (available to templates as `input.*`).
     * @param int                  $userId    Caller identity for capability checks.
     * @param string               $source    `manual` | `trigger`.
     * @param int|null             $triggerId The trigger that fired, when source is `trigger`.
     * @return array{
     *   success: bool,
     *   status: string,
     *   run_id: int,
     *   workflow_id: int,
     *   tool_calls: int,
     *   results: array<string, mixed>,
     *   message: string
     * }
     */
    public function run(
        ContentItem $workflow,
        array $input = [],
        int $userId = 0,
        string $source = 'manual',
        ?int $triggerId = null,
    ): array {
        $startedAt = microtime(true);

        $runId = DB::table('nvoos_workflow_runs')->insertGetId([
            'workflow_id' => $workflow->id,
            'status' => 'running',
            'source' => $source,
            'trigger_id' => $triggerId,
            'input' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $executor = new WorkflowExecutor($this->tools, $this->errors);

        $result = $executor->execute(
            WorkflowRecords::readGraph($workflow),
            $input,
            [
                'user_id' => $userId,
                'auth_provider' => app(AuthProviderInterface::class),
                'agent_runner' => new AgentNodeRunner($userId),
            ],
        );

        $duration = round(microtime(true) - $startedAt, 3);

        DB::table('nvoos_workflow_runs')->where('id', $runId)->update([
            'status' => (string) $result['status'],
            'tool_calls' => (int) $result['tool_calls'],
            'output' => json_encode(
                ['nodes' => $result['nodes']],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'events' => json_encode($result['nodes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'error' => $result['error'],
            'finished_at' => now(),
            'duration' => $duration,
            'updated_at' => now(),
        ]);

        // One feed entry per run, regardless of whether a person or a trigger
        // started it — both go through this method, which is why the recorder
        // lives here rather than in the controller.
        app(ActivityRecorder::class)->record(
            $result['success'] ? 'workflow.completed' : 'workflow.failed',
            sprintf(
                'Workflow #%d %s',
                (int) $workflow->id,
                $result['success'] ? 'completed' : 'failed'
            ),
            null,
            array(
                'run_id' => $runId,
                'workflow_id' => (int) $workflow->id,
                'tool_calls' => (int) $result['tool_calls'],
            )
        );

        return [
            'success' => (bool) $result['success'],
            'status' => (string) $result['status'],
            'run_id' => $runId,
            'workflow_id' => $workflow->id,
            'tool_calls' => (int) $result['tool_calls'],
            'results' => $result['nodes'],
            'message' => (string) $result['message'],
        ];
    }
}

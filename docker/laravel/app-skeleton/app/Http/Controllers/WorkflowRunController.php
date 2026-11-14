<?php

namespace App\Http\Controllers;

use App\Support\AgentNodeRunner;
use App\Support\WorkflowRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Application\Workflow\WorkflowExecutor;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

/**
 * Workflow execution and run history (090-h H3).
 *
 *   POST /orchestration/workflows/{id}/execute  run the saved graph
 *   GET  /orchestration/runs?workflow_id=       run summaries
 *   GET  /orchestration/runs/{id}               one run with per-node events
 *
 * WordPress's Engine V2 describes the graph to an LLM-planned
 * `execute_workflow` tool; this runtime executes the graph node by node with
 * {@see WorkflowExecutor}. The divergence is deliberate and recorded in
 * 090-h §7: a deterministic run is testable, names the failing node, and
 * bounds its own cost.
 *
 * Runs are append-only rows in `nvoos_workflow_runs` (090-h §4) — they are
 * never edited, so they do not belong in `nvoos_posts` meta.
 */
class WorkflowRunController
{
    /** How many runs the history endpoint returns. */
    private const PAGE_SIZE = 50;

    public function __construct(private readonly ContentStoreInterface $content)
    {
    }

    /**
     * POST /orchestration/workflows/{id}/execute — run a workflow.
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        $workflow = WorkflowRecords::find($this->content, $id);

        if (null === $workflow) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Workflow not found.',
            ], 404);
        }

        $input = $request->input('input', []);
        $input = is_array($input) ? $input : [];

        $userId = (int) $request->user()->getAuthIdentifier();
        $startedAt = microtime(true);

        $runId = DB::table('nvoos_workflow_runs')->insertGetId([
            'workflow_id' => $id,
            'status' => 'running',
            'input' => json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $executor = new WorkflowExecutor(
            app(ToolRegistry::class),
            app(ErrorFactoryInterface::class),
        );

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

        return response()->json([
            'success' => (bool) $result['success'],
            'status' => (string) $result['status'],
            'run_id' => $runId,
            'workflow_id' => $id,
            'tool_calls' => (int) $result['tool_calls'],
            'results' => $result['nodes'],
            'message' => (string) $result['message'],
        ], $result['success'] ? 200 : 500);
    }

    /**
     * GET /orchestration/runs — run summaries, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $query = DB::table('nvoos_workflow_runs')->orderByDesc('id')->limit(self::PAGE_SIZE);

        $workflowId = $request->query('workflow_id');

        if (is_numeric($workflowId)) {
            $query->where('workflow_id', (int) $workflowId);
        }

        $rows = $query->get();

        return response()->json(
            $rows->map(fn (object $row): array => $this->summary($row))->all()
        );
    }

    /**
     * GET /orchestration/runs/{id} — one run with its per-node events.
     */
    public function show(int $id): JsonResponse
    {
        $row = DB::table('nvoos_workflow_runs')->find($id);

        if (null === $row) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Workflow run not found.',
            ], 404);
        }

        return response()->json($this->summary($row) + [
            'input' => $this->decode($row->input),
            'output' => $this->decode($row->output),
            'events' => $this->decode($row->events) ?? [],
            'error' => $row->error,
        ]);
    }

    /**
     * Summary row for the run list.
     *
     * @return array<string, mixed>
     */
    private function summary(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'workflow_id' => (int) $row->workflow_id,
            'status' => (string) $row->status,
            'tool_calls' => (int) $row->tool_calls,
            'started_at' => $row->started_at,
            'finished_at' => $row->finished_at,
            'duration' => null === $row->duration ? null : (float) $row->duration,
        ];
    }

    /**
     * Decode a JSON column defensively.
     */
    private function decode(?string $raw): mixed
    {
        if (null === $raw || '' === $raw) {
            return null;
        }

        return json_decode($raw, true);
    }
}

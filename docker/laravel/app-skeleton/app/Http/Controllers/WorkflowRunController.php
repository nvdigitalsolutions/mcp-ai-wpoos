<?php

namespace App\Http\Controllers;

use App\Support\WorkflowRecords;
use App\Support\WorkflowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;

/**
 * Workflow execution and run history (090-h H3).
 *
 *   POST /orchestration/workflows/{id}/execute  run the saved graph
 *   GET  /orchestration/runs?workflow_id=       run summaries
 *   GET  /orchestration/runs/{id}               one run with per-node events
 *
 * WordPress's Engine V2 describes the graph to an LLM-planned
 * `execute_workflow` tool; this runtime executes the graph node by node with
 * `WorkflowExecutor`. The divergence is deliberate and recorded in 090-h §7.
 *
 * The run itself is written by {@see WorkflowRunner}, which the trigger
 * dispatcher shares — so a console run and a triggered run are the same row
 * apart from `source`/`trigger_id`.
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

        $result = app(WorkflowRunner::class)->run(
            $workflow,
            $input,
            (int) $request->user()->getAuthIdentifier(),
            'manual',
        );

        return response()->json($result, $result['success'] ? 200 : 500);
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
            'source' => (string) ($row->source ?? 'manual'),
            'trigger_id' => null === ($row->trigger_id ?? null) ? null : (int) $row->trigger_id,
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

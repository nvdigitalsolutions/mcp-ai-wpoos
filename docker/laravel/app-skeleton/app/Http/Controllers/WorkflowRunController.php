<?php

namespace App\Http\Controllers;

use App\Support\WorkflowRecords;
use App\Support\WorkflowRunner;
use App\Support\WorkflowRuns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;

/**
 * Workflow execution and run history (090-h H3, 149 §18).
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
 *
 * Reads go through {@see WorkflowRuns}, which scopes every query to the current
 * estate. That is the same reader the `/admin` run list uses, so the two
 * presentations cannot disagree about what a run summary contains or about
 * which rows exist.
 */
class WorkflowRunController
{
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
        $workflowId = $request->query('workflow_id');

        return response()->json(
            WorkflowRuns::summaries(is_numeric($workflowId) ? (int) $workflowId : null)
        );
    }

    /**
     * GET /orchestration/runs/{id} — one run with its per-node events.
     *
     * A run belonging to another estate is a 404, not a 403: the caller must not
     * be able to tell that it exists (R4).
     */
    public function show(int $id): JsonResponse
    {
        $row = WorkflowRuns::find($id);

        if (null === $row) {
            return response()->json([
                'code' => 'not_found',
                'message' => 'Workflow run not found.',
            ], 404);
        }

        return response()->json(WorkflowRuns::summary($row) + [
            'input' => WorkflowRuns::decode($row->input),
            'output' => WorkflowRuns::decode($row->output),
            'events' => WorkflowRuns::decode($row->events) ?? [],
            'error' => $row->error,
        ]);
    }
}

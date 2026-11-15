<?php

namespace App\Http\Controllers\Pro;

use App\Support\AgentNodeRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;

/**
 * Single-node execution (`/api/v1/pro/workflow-builder/nodes/execute`).
 *
 * The canvas renders a per-node "run" button; `nodeExecutePayload()` in
 * `spa-v2/src/features/workflows/workflow-graph.ts` is the request contract.
 * A single node is not a run: it reads no upstream outputs and writes no run
 * row, so no `{{ … }}` template can resolve here and one is rejected rather
 * than silently sent as an empty argument.
 *
 * Tool nodes go through {@see ToolRegistry::execute()}, which applies the
 * tool's `required_capability` and the pre-execution listener exactly as
 * `POST /tools` does.
 */
class WorkflowNodeController
{
    /** Longest delay a `delay` node may request here. */
    private const DELAY_CAP = 10;

    /**
     * POST /workflow-builder/nodes/execute.
     */
    public function execute(Request $request): JsonResponse
    {
        $type = (string) $request->input('node_type', 'tool');
        $nodeId = (string) $request->input('node_id', '');

        if ('' === $nodeId) {
            return $this->fail('invalid_node', 'The node needs an id.', 422);
        }

        return match ($type) {
            'tool' => $this->runTool($request, $nodeId),
            'agent' => $this->runAgent($request, $nodeId),
            'delay' => $this->runDelay($request, $nodeId),
            // WordPress's engine resolves `action` commands; this runtime has
            // no command bus, so say so instead of pretending the node ran.
            'action' => $this->fail(
                'not_implemented',
                'Action nodes are not available on this runtime. Use a tool node.',
                501
            ),
            default => $this->fail('invalid_node', "Unknown node type \"{$type}\".", 422),
        };
    }

    /**
     * Execute a tool node.
     */
    private function runTool(Request $request, string $nodeId): JsonResponse
    {
        $slug = (string) $request->input('tool_name', $request->input('toolSlug', ''));

        if ('' === $slug) {
            return $this->fail('invalid_node', 'A tool node needs a tool_name.', 422);
        }

        $arguments = $request->input('tool_arguments', $request->input('arguments', []));
        $arguments = is_array($arguments) ? $arguments : [];

        $result = app(ToolRegistry::class)->execute(
            $slug,
            $arguments,
            [
                'user_id' => (int) $request->user()->getAuthIdentifier(),
                'auth_provider' => app(AuthProviderInterface::class),
                'node_id' => $nodeId,
                'workflow_id' => $request->input('workflow_id'),
            ],
        );

        $errors = app(ErrorFactoryInterface::class);

        if ($errors->isError($result)) {
            $normalized = $errors->normalize($result);

            return response()->json([
                'success' => false,
                'node_id' => $nodeId,
                'error' => $normalized,
                'message' => isset($normalized['message']) && is_scalar($normalized['message'])
                    ? (string) $normalized['message']
                    : 'The node failed.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'node_id' => $nodeId,
            'output' => $result,
        ]);
    }

    /**
     * Execute an agent node.
     */
    private function runAgent(Request $request, string $nodeId): JsonResponse
    {
        $prompt = (string) $request->input('prompt', '');

        if ('' === trim($prompt)) {
            return $this->fail('invalid_node', 'An agent node needs a prompt.', 422);
        }

        if (str_contains($prompt, '{{')) {
            return $this->fail(
                'unresolved_template',
                'A single node has no upstream outputs, so {{ … }} references cannot resolve. Run the workflow instead.',
                422
            );
        }

        $agentId = (string) $request->input('agent_id', 'default');
        $runner = new AgentNodeRunner((int) $request->user()->getAuthIdentifier());

        try {
            $output = $runner($agentId, $prompt);
        } catch (\Throwable $exception) {
            return response()->json([
                'success' => false,
                'node_id' => $nodeId,
                'message' => $exception->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'node_id' => $nodeId,
            'output' => $output,
        ]);
    }

    /**
     * Sleep for the requested, capped duration.
     */
    private function runDelay(Request $request, string $nodeId): JsonResponse
    {
        $params = $request->input('params', []);
        $seconds = is_array($params) && isset($params['seconds']) && is_numeric($params['seconds'])
            ? (int) $params['seconds']
            : 0;

        $seconds = max(0, min($seconds, self::DELAY_CAP));

        if ($seconds > 0) {
            usleep($seconds * 1_000_000);
        }

        return response()->json([
            'success' => true,
            'node_id' => $nodeId,
            'output' => ['slept' => $seconds],
        ]);
    }

    /**
     * Envelope for a refused node.
     */
    private function fail(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
        ], $status);
    }
}

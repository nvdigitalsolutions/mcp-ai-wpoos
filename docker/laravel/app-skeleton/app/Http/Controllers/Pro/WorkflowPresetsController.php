<?php

namespace App\Http\Controllers\Pro;

use App\Support\WorkflowPresets;
use Illuminate\Http\JsonResponse;

/**
 * Workflow presets (`/api/v1/pro/workflow-presets`).
 *
 * Port of `WP_MCP_AI_Pro_Workflow_Presets_REST_Controller` (090-h H1). The
 * catalogue is data: WordPress's controller does not write a record on
 * install either — it returns the graph, and the console loads it into the
 * canvas as a draft that the user then saves through the workflow PUT.
 *
 * Responses are the Pro `{ ok, ... }` envelope, unwrapped, because
 * `WorkflowPresetsClient` reads `presets` / `categories` / `preset` off the
 * top level and treats `ok === false` as a failure. Same client, both
 * runtimes.
 */
class WorkflowPresetsController
{
    /**
     * GET /workflow-presets — categories + summaries, graphs stripped.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'categories' => WorkflowPresets::categories(),
            'presets' => WorkflowPresets::summaries(),
        ]);
    }

    /**
     * POST /workflow-presets/{id}/install — the full preset graph.
     */
    public function install(string $id): JsonResponse
    {
        $preset = WorkflowPresets::find($id);

        if (null === $preset) {
            return response()->json([
                'ok' => false,
                'code' => 'invalid_preset',
                'message' => sprintf('Workflow preset "%s" does not exist.', $id),
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'preset' => [
                'id' => $id,
                'name' => (string) ($preset['name'] ?? ''),
                'description' => (string) ($preset['description'] ?? ''),
                'category' => (string) ($preset['category'] ?? ''),
                'icon' => (string) ($preset['icon'] ?? ''),
                'tags' => array_values(array_map('strval', (array) ($preset['tags'] ?? []))),
                'nodes' => array_values((array) ($preset['nodes'] ?? [])),
                'edges' => array_values((array) ($preset['edges'] ?? [])),
            ],
        ]);
    }
}

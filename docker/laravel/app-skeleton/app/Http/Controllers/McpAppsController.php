<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Services\AssistantWriter;
use App\Support\McpAppsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Assistant MCP app assignments (090-i).
 *
 * Serves the two routes the console's `McpAppsClient` calls:
 *
 *   GET  /api/v1/mcp-apps/{assistant}
 *   PUT  /api/v1/mcp-apps/{assistant}
 *
 * The response shape is top-level — `success`, `assistant_id`, `apps`,
 * `app_count` — because `McpAppsResponse` is read straight from the body, not
 * from a `data` envelope. Wrapping it would silently render an empty panel.
 *
 * Both routes sit in the `manage_options` group, and `Assistant` carries no
 * global tenant scope, so route-model binding alone resolved an id from **any**
 * estate. An estate administrator could read — and `PUT` — another estate's
 * assistant configuration by id (149 §89.3). The check is the same one
 * `AssistantController` already applies: `ensureInCurrentEstate()` answers 404,
 * so a foreign assistant is indistinguishable from one that does not exist.
 */
class McpAppsController
{
    public function __construct(
        private readonly McpAppsStore $store,
        private readonly AssistantWriter $writer,
    ) {
    }

    /**
     * The apps assigned to one assistant.
     */
    public function show(Assistant $assistant): JsonResponse
    {
        $this->writer->ensureInCurrentEstate($assistant);

        return $this->respond($assistant, $this->store->read($assistant));
    }

    /**
     * Replace the assigned apps.
     */
    public function update(Request $request, Assistant $assistant): JsonResponse
    {
        $this->writer->ensureInCurrentEstate($assistant);

        $validated = $request->validate(
            array(
                'apps' => array('required', 'array'),
            )
        );

        try {
            $apps = $this->store->replace($assistant, $validated['apps']);
        } catch (InvalidArgumentException $error) {
            return response()->json(
                array(
                    'success' => false,
                    'message' => $error->getMessage(),
                ),
                422
            );
        }

        return $this->respond($assistant, $apps);
    }

    /**
     * The console contract shape.
     *
     * @param array<int, array<string, mixed>> $apps
     */
    private function respond(Assistant $assistant, array $apps): JsonResponse
    {
        return response()->json(
            array(
                'success' => true,
                'assistant_id' => (int) $assistant->id,
                'apps' => $apps,
                'app_count' => count($apps),
            )
        );
    }
}

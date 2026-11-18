<?php

namespace App\Http\Controllers;

use App\Services\AssistantShortcutCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assistant tool shortcuts for the console drawer (090-i; adapter since 149 §49).
 *
 * Mirrors `mcp-ai-pro/v1/tool-shortcuts`: the same label/payload defaults, the
 * same `-2` id suffixes, the same search over label/description/tool, and the
 * same top-level `{shortcuts, categories, assistant_id}` body.
 *
 * This is now a thin HTTP adapter. `AssistantShortcutCatalogue` owns resolution
 * — including the estate scope this controller was missing — because the
 * `/admin/agent-skills` page (149 §49) reads the same catalogue and must not
 * inherit a second copy of it. The response shape is unchanged; that is the
 * point of the extraction.
 */
class ToolShortcutsController
{
    /**
     * List shortcuts, optionally scoped to an assistant and filtered by search.
     */
    public function index(Request $request, AssistantShortcutCatalogue $catalogue): JsonResponse
    {
        return response()->json(
            $catalogue->forAssistant(
                (int) $request->query('assistant_id', 0),
                (string) $request->query('search', '')
            )
        );
    }
}

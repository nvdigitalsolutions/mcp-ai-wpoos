<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Support\AssistantToolShortcuts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Tool\ToolShortcutNormaliser;

/**
 * Assistant tool shortcuts for the console drawer (090-i).
 *
 * Mirrors `mcp-ai-pro/v1/tool-shortcuts`: the same label/payload defaults, the
 * same `-2` id suffixes, the same search over label/description/tool, and the
 * same top-level `{shortcuts, categories, assistant_id}` body.
 *
 * The shaping rules live in `lib/core` so this controller and the WordPress one
 * cannot diverge. What is local is the source of the raw list and the fact that
 * this runtime resolves the default assistant from the database rather than
 * from a Pro helper.
 */
class ToolShortcutsController
{
    /**
     * List shortcuts, optionally scoped to an assistant and filtered by search.
     */
    public function index(Request $request): JsonResponse
    {
        $assistantId = (int) $request->query('assistant_id', 0);

        if (0 === $assistantId) {
            $assistantId = (int) Assistant::query()->where('is_default', true)->value('id');
        }

        $assistant = $assistantId > 0 ? Assistant::find($assistantId) : null;

        if (null === $assistant) {
            return response()->json(
                array(
                    'shortcuts' => array(),
                    'categories' => array(),
                    'assistant_id' => 0,
                )
            );
        }

        $normalised = ToolShortcutNormaliser::normalise(
            AssistantToolShortcuts::forAssistant($assistant),
            (string) $request->query('search', ''),
            (string) __('General'),
            "\u{26A1}"
        );

        return response()->json(
            array(
                'shortcuts' => $normalised['shortcuts'],
                'categories' => $normalised['categories'],
                'assistant_id' => (int) $assistant->id,
            )
        );
    }
}

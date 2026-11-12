<?php

namespace App\Http\Controllers\Pro;

use Illuminate\Http\JsonResponse;

/**
 * Pro schedule presets (`/api/v1/pro/presets`).
 *
 * The WordPress product ships a curated preset catalogue from the Schedule
 * Anything integration. That catalogue is not part of the Laravel runtime
 * yet, so the list is empty and install fails closed with a typed message —
 * the console renders "no presets" instead of a broken panel.
 */
class PresetsController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'presets' => [],
            'total' => 0,
        ]);
    }

    public function install(string $id): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'success' => false,
            'message' => 'The schedule preset catalogue is not available on the Laravel runtime.',
            'preset_id' => $id,
        ], 404);
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\Settings\SettingsService;
use App\Support\SettingsSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The console settings endpoint.
 *
 * The controller owns the HTTP envelope and nothing else: the projection, the
 * layer routing and the coercion all live in `SettingsService` so the admin
 * panel cannot drift from this route (149 R1). Before this slice the controller
 * carried its own copy of the cast rules and wrote surface keys straight to the
 * store, which is how `google_api_key` came to be written while the runtime read
 * `gemini_api_key`.
 */
class SettingsController
{
    public function index(SettingsService $settings): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $settings->values(SettingsService::SURFACE_CONSOLE),
        ]);
    }

    public function schema(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'tabs' => SettingsSchema::tabsForSurface(SettingsService::SURFACE_CONSOLE),
                'fields' => SettingsSchema::forSurface(SettingsService::SURFACE_CONSOLE),
            ],
        ]);
    }

    public function values(SettingsService $settings): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['values' => $settings->values(SettingsService::SURFACE_CONSOLE)],
        ]);
    }

    public function updateValues(Request $request, SettingsService $settings): JsonResponse
    {
        $changes = $request->input('values', $request->all());

        if (! is_array($changes)) {
            abort(422, 'The values payload must be an object.');
        }

        $settings->write($changes, SettingsService::SURFACE_CONSOLE);

        return response()->json([
            'success' => true,
            'data' => ['values' => $settings->values(SettingsService::SURFACE_CONSOLE)],
        ]);
    }
}

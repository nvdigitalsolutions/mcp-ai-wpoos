<?php

namespace App\Http\Controllers;

use App\Services\BlueprintCatalogue;
use App\Services\BlueprintInstaller;
use App\Support\BlueprintInstallException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Assistant blueprint catalogue — the Laravel half of
 * `mcp-ai-pro/v1/blueprints` (Proposal 084 Phase 5c, 090-b task 2.4).
 *
 * The console's `BlueprintsPage` calls `GET /blueprints`,
 * `GET /blueprints/{toolkit}/{slug}` and (later) the install action. WordPress
 * serves all three from `WP_MCP_AI_Pro_Blueprints_REST_Controller`; this
 * mirrors the read half, with the same `{success, data}` envelope and the same
 * normalised payload. Install is the next increment — see `090-b` §6.1.
 */
final class BlueprintController
{
    /**
     * The grouped catalogue.
     */
    public function index(BlueprintCatalogue $catalogue): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $catalogue->listing(),
        ]);
    }

    /**
     * One blueprint's instructions, tools and defaults.
     */
    public function show(BlueprintCatalogue $catalogue, string $toolkit, string $slug): JsonResponse
    {
        $detail = $catalogue->detail($toolkit, $slug);

        if (null === $detail) {
            return response()->json(
                [
                    'success' => false,
                    'message' => sprintf('Blueprint "%s" not found.', $slug),
                ],
                404
            );
        }

        return response()->json([
            'success' => true,
            'data' => array_merge(
                [
                    'toolkit' => $toolkit,
                    'slug' => $slug,
                ],
                $detail
            ),
        ]);
    }

    /**
     * Install a blueprint as a new assistant.
     *
     * Mirrors `WP_MCP_AI_Pro_Blueprints_REST_Controller::install()`: the same
     * `{success, data}` envelope, a 404 when the blueprint is unknown and a 409
     * when an assistant with that title already exists and `overwrite` is off.
     */
    public function install(
        Request $request,
        BlueprintInstaller $installer,
        string $toolkit,
        string $slug
    ): JsonResponse {
        try {
            $result = $installer->install($toolkit, $slug, $request->boolean('overwrite'));
        } catch (BlueprintInstallException $exception) {
            return response()->json(
                [
                    'success' => false,
                    'message' => $exception->getMessage(),
                ],
                $exception->status()
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'toolkit' => $toolkit,
                'slug' => $slug,
                'assistant_id' => $result['assistant_id'],
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\ToolCatalogue;
use Illuminate\Http\JsonResponse;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;

/**
 * The console's tool catalogue.
 *
 * Envelope only: the row shape and the counts come from `ToolCatalogue`, which
 * the `/admin/tools` page also reads (149 §22). The payload is a published
 * contract — `ToolsClient` and `ToolPicker` read `data.tools[]` — so a change
 * here is a change to a client, not a refactor.
 */
class ToolsController
{
    public function __invoke(ToolRegistry $registry, ToolReadiness $readiness): JsonResponse
    {
        $tools = ToolCatalogue::rows($registry, $readiness);
        $summary = ToolCatalogue::summary($tools);

        return response()->json(
            array(
                'success' => true,
                'data' => array(
                    'tools' => $tools,
                    'total' => $summary['total'],
                    'servable' => $summary['servable'],
                    'endpoint_contract_version' => ToolReadiness::CONTRACT_VERSION,
                ),
            )
        );
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;

class FeaturesController
{
    public function __invoke(): JsonResponse
    {
        $features = config('oos.features', []);
        $entitlement = app(ProEntitlementInterface::class);

        return response()->json([
            'success' => true,
            'data' => [
                'features' => (object) $features,
                'backend' => 'laravel',
                'contract' => 'mcp-ai/v1',
                'contract_version' => 1,
                'entitlement' => [
                    'plan' => $entitlement->plan(),
                    'source' => $entitlement->source(),
                ],
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\EndpointCapabilities;
use Illuminate\Http\JsonResponse;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;

class FeaturesController
{
    public function __invoke(): JsonResponse
    {
        $features = config('oos.features', array());
        $entitlement = app(ProEntitlementInterface::class);

        return response()->json(array(
            'success' => true,
            'data' => array(
                'features' => (object) $features,
                // Which console endpoints this runtime can actually serve. An
                // absent route blanks the endpoint, so the page renders its
                // unavailable state instead of firing a request that 404s.
                'endpoints' => (object) EndpointCapabilities::map(),
                'backend' => 'laravel',
                'contract' => 'mcp-ai/v1',
                'contract_version' => 1,
                'entitlement' => array(
                    'plan' => $entitlement->plan(),
                    'source' => $entitlement->source(),
                ),
            ),
        ));
    }
}

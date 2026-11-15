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
        $manifest = EndpointCapabilities::manifest();

        return response()->json(array(
            'success' => true,
            'data' => array(
                'features' => (object) $features,
                // Which console endpoints this runtime can actually serve. An
                // absent route blanks the endpoint, so the page renders its
                // unavailable state instead of firing a request that 404s.
                'endpoints' => (object) $manifest['endpoints'],
                // Capability manifest v2 (090-i phase 1). `endpoint_reasons`
                // classifies every key so a false reads as expected or as a
                // regression; `endpoint_gaps` is the subset this runtime is
                // meant to serve and does not, which must stay empty.
                'endpoint_contract_version' => $manifest['version'],
                'endpoint_reasons' => (object) $manifest['reasons'],
                'endpoint_gaps' => $manifest['gaps'],
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

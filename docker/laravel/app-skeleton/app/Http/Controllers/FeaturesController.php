<?php

namespace App\Http\Controllers;

use App\Support\ConnectedApps\ConnectedAppRegistry;
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

        // Class C discovery (090-i Phase 4): a configured, reachable product
        // lights its key and publishes the base URL the console should mount
        // the island against. The static route map cannot know this — the
        // product is not part of this router. The merge recomputes `gaps`
        // because a flipped key changes the answer; WordPress applies the same
        // rule in `WP_MCP_AI_Endpoint_Capabilities::apply_connected_apps()`.
        $connectedUrls = app(ConnectedAppRegistry::class)->available();
        $manifest = EndpointCapabilities::applyConnectedApps($manifest, $connectedUrls);

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
                // Additive (v2): the resolved base URL for a connected app that
                // answered its probe. A client that ignores the field behaves
                // exactly as it did before discovery existed.
                'endpoint_urls' => (object) ( $manifest['endpoint_urls'] ?? array() ),
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

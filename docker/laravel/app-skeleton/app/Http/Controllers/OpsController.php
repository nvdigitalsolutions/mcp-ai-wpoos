<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Laravel\Adapter\JobStatusStore;

class OpsController
{
    public function __invoke(
        ToolRegistry $registry,
        ProviderRouter $providers,
        JobStatusStore $jobs,
        ProEntitlementInterface $entitlement,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'data' => [
                'tools' => [
                    'total' => $registry->count(),
                    'enabled' => $registry->enabledCount(),
                ],
                'providers' => $providers->getRegisteredSlugs(),
                'queue' => [
                    'connection' => config('oos.queue_connection'),
                    'available' => $jobs->isAvailable(),
                    'counts' => $jobs->counts(),
                ],
                'entitlement' => [
                    'plan' => $entitlement->plan(),
                    'source' => $entitlement->source(),
                ],
            ],
        ]);
    }
}

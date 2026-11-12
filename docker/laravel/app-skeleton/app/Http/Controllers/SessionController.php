<?php

namespace App\Http\Controllers;

use App\Support\CapabilityMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;

class SessionController
{
    /**
     * Mint the session-bound token the console sends as `X-WP-Nonce`.
     *
     * The compatibility middleware validates that header against this token,
     * so the SPA's request modules need no changes in Phase 0 (Proposal
     * 090-B, ADR-4).
     */
    public function nonce(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'nonce' => (string) $request->session()->token(),
        ]);
    }

    /**
     * Return the authenticated user in the SPA's user shape.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        $entitlement = app(ProEntitlementInterface::class);

        return response()->json([
            'id' => (int) $user->getAuthIdentifier(),
            'login' => (string) ($user->email ?? $user->name ?? ''),
            'displayName' => (string) ($user->name ?? ''),
            'capabilities' => CapabilityMap::forUser($user),
            'plan' => $entitlement->plan(),
            'source' => $entitlement->source(),
        ]);
    }
}

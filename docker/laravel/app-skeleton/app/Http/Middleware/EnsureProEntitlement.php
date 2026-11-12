<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a Pro surface behind the entitlement contract.
 *
 * Fails closed: an unknown driver or an unlisted feature in `config` mode
 * denies the request. The error body carries both `success: false` (the
 * Phase 0-3 envelope) and `ok: false` (the Pro console's `ok` envelope) so
 * either client renders the typed message instead of a generic failure.
 */
class EnsureProEntitlement
{
    public function __construct(private readonly ProEntitlementInterface $entitlement)
    {
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if ($this->entitlement->allows($feature)) {
            return $next($request);
        }

        $message = sprintf('The %s surface requires a Pro entitlement.', $feature);

        return response()->json([
            'success' => false,
            'ok' => false,
            'message' => $message,
            'error' => [
                'code' => 'pro_entitlement_required',
                'message' => $message,
                'feature' => $feature,
                'plan' => $this->entitlement->plan(),
                'source' => $this->entitlement->source(),
            ],
        ], 402);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Auth\PrincipalScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a scoped machine credential on a route that asks for a scope it lacks —
 * Proposal 159 slice 1.
 *
 * **Slice 1 wires this to nothing.** The middleware, the scope vocabulary and
 * their tests ship first precisely so that the compatibility rule can be pinned
 * before a single route changes behaviour: every personal token minted to date
 * carries `['*']`, which {@see PrincipalScope} reads as *unscoped*, so an
 * unrestricted principal passes any requirement. Slice 2 annotates routes.
 *
 * ## Fail closed on a malformed declaration
 *
 * `scope:` with no argument is a route-authoring mistake, and the only safe read
 * of "this route's scope requirement is blank" is "deny". A middleware that
 * allowed it would turn a missed parameter into an open route.
 *
 * ## Why the refusal names the requirement
 *
 * A 403 that says only "forbidden" gives a client holding a scoped token no way to
 * tell a wrong scope from a wrong estate from a revoked credential. The body names
 * what was required and what the credential actually carries — both of which the
 * caller already owns, since it minted the token.
 */
final class EnsurePrincipalScope
{
    public function handle(Request $request, Closure $next, string ...$requirements): Response
    {
        $scope = PrincipalScope::forRequest($request->user());

        // A session, an Auth0 user or a `['*']` token: the owner's own authority,
        // checked by the route's own gates. Unchanged by this slice.
        if ($scope->isUnrestricted()) {
            return $next($request);
        }

        $requirements = array_values(array_filter(
            array_map('trim', $requirements),
            static fn (string $requirement): bool => '' !== $requirement
        ));

        foreach ($requirements as $requirement) {
            if ($scope->allows($requirement)) {
                return $next($request);
            }
        }

        return $this->refuse($scope, $requirements);
    }

    /**
     * @param list<string> $requirements
     */
    private function refuse(PrincipalScope $scope, array $requirements): Response
    {
        $message = array() === $requirements
            ? 'This route requires a scoped credential but declares no scope.'
            : sprintf(
                'This credential is scoped to [%s] and does not include [%s].',
                implode(', ', $scope->entries()),
                implode(', ', $requirements)
            );

        return response()->json(
            array(
                'success' => false,
                'ok' => false,
                'message' => $message,
                'error' => array(
                    'code' => 'principal_scope_required',
                    'message' => $message,
                    'required' => $requirements,
                    'granted' => $scope->entries(),
                ),
            ),
            403
        );
    }
}

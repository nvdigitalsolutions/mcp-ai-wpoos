<?php

namespace App\Http\Middleware;

use App\Support\Auth\PrincipalScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admit a scoped machine credential only where the route says what it needs —
 * Proposal 159 slices 1–2, decision recorded in 159 §10.
 *
 * ## Default-deny, and what that means here
 *
 * A credential that carries a scope is admitted only by a route that *declares*
 * one with the `scope:` middleware. A route that declares nothing still admits
 * sessions, Auth0 users and `['*']` tokens — they are user credentials, and
 * {@see PrincipalScope::isUnrestricted()} short-circuits them before any of this
 * runs — and refuses a scoped token with 403. A forgotten declaration is
 * therefore a visible outage on one route rather than a silent over-permission,
 * which is the trade 159 §10 argues for.
 *
 * ## Why it is attached once, on the `api` group
 *
 * Middleware is additive: an argless attach at an outer group and a
 * `scope:assistant` at an inner one both run, so "default-deny at the group,
 * override at the route" cannot be expressed with two attachments. Instead the
 * single group attach *reads* the declarations out of the route it is about to
 * serve, and enforces those. A route-level `scope:` is then purely declarative,
 * and the duplicate route-level run reaches the same verdict.
 *
 * Two properties of the pipeline make that work, and one of them is a trap:
 *
 *  - {@see Route::gatherMiddleware()} merges route and controller middleware and
 *    dedupes by the full string, so `scope` and `scope:assistant` coexist. It
 *    returns the *raw* entries (aliases unexpanded, the top-level group named but
 *    not expanded), which is why declarations belong on routes or nested groups
 *    rather than on the `api` group itself.
 *  - the middleware must run **after** `auth:sanctum`. `PrincipalScope::forRequest()`
 *    reads `null` as unrestricted, so an attach that outran authentication would
 *    read no principal, allow everything and enforce nothing — while looking
 *    installed. `bootstrap/app.php` therefore appends it to the priority list
 *    after `Illuminate\Auth\Middleware\Authenticate`, and a test proves the order
 *    rather than trusting it.
 *
 * ## Fail closed on a malformed declaration
 *
 * `scope:` with no argument is an authoring mistake, and the only safe read of
 * "this route's scope requirement is blank" is "deny". It lands on the same code
 * path as an absent declaration, because both mean the route named no scope.
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
    /** The alias this class is registered under in `bootstrap/app.php`. */
    public const ALIAS = 'scope';

    public function handle(Request $request, Closure $next, string ...$requirements): Response
    {
        $scope = PrincipalScope::forRequest($request->user());

        // A session, an Auth0 user or a `['*']` token: the owner's own authority,
        // checked by the route's own gates. Unchanged by this middleware.
        if ($scope->isUnrestricted()) {
            return $next($request);
        }

        $requirements = $this->requirementsFor($request, $requirements);

        // Default-deny: a scoped credential on a route that declared nothing.
        if (array() === $requirements) {
            return $this->refuse($scope, array());
        }

        foreach ($requirements as $requirement) {
            if ($scope->allows($requirement)) {
                return $next($request);
            }
        }

        return $this->refuse($scope, $requirements);
    }

    /**
     * Every requirement this route declares — its own attach plus any `scope:`
     * entries found in the route's middleware.
     *
     * The group instance carries no arguments of its own; the declarations it
     * enforces come from the route. Blank entries are dropped, never treated as a
     * declaration, because a blank declaration and an absent one mean the same
     * thing: the route named no scope.
     *
     * @param list<string> $declared
     * @return list<string>
     */
    private function requirementsFor(Request $request, array $declared): array
    {
        $route = $request->route();

        if ($route instanceof Route) {
            foreach ($route->gatherMiddleware() as $entry) {
                foreach ($this->declarationsIn($entry) as $requirement) {
                    $declared[] = $requirement;
                }
            }
        }

        $requirements = array();

        foreach ($declared as $requirement) {
            $requirement = trim($requirement);

            if ('' !== $requirement) {
                $requirements[] = $requirement;
            }
        }

        return array_values(array_unique($requirements));
    }

    /**
     * The requirements one middleware entry declares, or none when the entry is
     * not this middleware.
     *
     * Entries arrive as `scope`, `scope:assistant,estate` or as the class name
     * itself. Only the part after the first colon is a parameter list; Laravel
     * splits parameters on commas, so `scope:assistant:42` names one requirement.
     *
     * @return list<string>
     */
    private function declarationsIn(mixed $entry): array
    {
        if (! is_string($entry)) {
            return array();
        }

        $name = $entry;
        $parameters = '';

        if (str_contains($entry, ':')) {
            [$name, $parameters] = explode(':', $entry, 2);
        }

        if (self::ALIAS !== $name && self::class !== $name) {
            return array();
        }

        if ('' === trim($parameters)) {
            return array();
        }

        $requirements = array();

        foreach (explode(',', $parameters) as $requirement) {
            $requirement = trim($requirement, " \t\n\r\0\x0B'\"");

            if ('' !== $requirement) {
                $requirements[] = $requirement;
            }
        }

        return $requirements;
    }

    /**
     * @param list<string> $requirements
     */
    private function refuse(PrincipalScope $scope, array $requirements): Response
    {
        $message = array() === $requirements
            ? 'This route is not open to scoped credentials: it declares no scope.'
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

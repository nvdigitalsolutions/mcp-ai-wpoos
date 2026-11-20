<?php

namespace App\Http\Middleware;

use App\Support\Auth\PrincipalScope;
use App\Support\Settings\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three `rest_enable_assistant_*` gates, honoured for machine principals —
 * Proposal 159 slice 2, and §5 rule 5 of that proposal.
 *
 * ## Who counts as "external"
 *
 * WordPress gates its REST assistant callbacks, and its console never uses REST,
 * so that split is free there. This runtime's console *is* the REST API, so the
 * setting can only mean something if the runtime can tell the two callers apart.
 * It can: a machine principal is a scoped token ({@see PrincipalScope}), while a
 * console request is a session, an Auth0 user or a `['*']` token — all reported
 * as unrestricted.
 *
 * So the gate applies to a **scoped** credential only. That is narrower than
 * "any bearer token", deliberately: a `['*']` personal token is the owner's own
 * authority replayed rather than a separate principal (159 §5 rule 3), and gating
 * it would change behaviour for callers that exist today. From slice 3 onward,
 * every machine credential the runtime mints is scoped, so every one is gated.
 *
 * ## Defaults
 *
 * `SettingsService::value()` falls back to the catalogue default, so an estate
 * that has never saved the key gets WordPress's own answers: listing enabled,
 * create and delete disabled.
 */
final class EnsureAssistantRestEnabled
{
    /** The alias this class is registered under in `bootstrap/app.php`. */
    public const ALIAS = 'assistant.rest';

    /**
     * Every action this gate understands, and the key it reads.
     *
     * Spelt out rather than composed from the action name, so a source scan can
     * still see which settings the runtime reads.
     *
     * @var array<string, string>
     */
    private const KEYS = array(
        'list' => 'rest_enable_assistant_list',
        'create' => 'rest_enable_assistant_create',
        'delete' => 'rest_enable_assistant_delete',
    );

    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function handle(Request $request, Closure $next, string $action = 'list'): Response
    {
        $scope = PrincipalScope::forRequest($request->user());

        // First-party console path: not gated. That is 159 §5 rule 5's recorded
        // divergence from WordPress's create/delete callbacks, and it is what
        // stops this gate from disabling the panel it exists to protect.
        if ($scope->isUnrestricted()) {
            return $next($request);
        }

        $key = self::KEYS[$action] ?? null;

        // An unknown action is an authoring mistake. Deny rather than guess, the
        // same rule the scope middleware applies to a blank requirement.
        if (null === $key) {
            return $this->refuse($action, null);
        }

        if (true === filter_var($this->settings->value($key), FILTER_VALIDATE_BOOLEAN)) {
            return $next($request);
        }

        return $this->refuse($action, $key);
    }

    private function refuse(string $action, ?string $key): Response
    {
        $message = null === $key
            ? sprintf('Unknown assistant REST action [%s].', $action)
            : sprintf('Remote assistant %s is disabled for this estate.', $action);

        return response()->json(
            array(
                'success' => false,
                'ok' => false,
                'message' => $message,
                'error' => array(
                    'code' => 'assistant_rest_disabled',
                    'message' => $message,
                    'action' => $action,
                    'setting' => $key,
                ),
            ),
            403
        );
    }
}

<?php

namespace App\Http\Controllers;

use App\Mail\MagicLoginLink;
use App\Models\User;
use App\Support\Auth\MagicLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Passwordless sign-in — Proposal 149 §103.
 *
 * Two public routes, matching the WordPress controller's contract:
 *
 *   POST /api/v1/magic-login/request — { email }   → always 200
 *   POST /api/v1/magic-login/confirm — { token }   → session, or 401
 *
 * Both are unauthenticated by necessity: the caller has no session, which is the
 * whole point. What keeps that safe is that neither answers distinguishably
 * between "the account exists", "the token is real" and none of the above —
 * a request always looks like it worked, and every failure at confirm time is the
 * same 401 with the same body.
 *
 * When no estate enables the feature, both routes 404. `abort(404)` rather than a
 * coded error on purpose: an off-by-default login path should be invisible, not
 * apologetic. The WordPress controller answers `rest_no_route` for the same
 * reason.
 *
 * Confirm starts the same session the password path starts, and returns the same
 * payload — the console's session handling is one code path, not two.
 */
class MagicLoginController
{
    public function __construct(private readonly MagicLinkService $magicLink)
    {
    }

    /**
     * Ask for a sign-in link.
     *
     * The response is uniform whether or not the account exists, whether or not
     * the estate enabled the feature *for that user*, and whether or not the mail
     * transport accepted the message. Anything else is an account-enumeration
     * oracle (OWASP A07).
     */
    public function request(Request $request): JsonResponse
    {
        if (! $this->magicLink->anyEstateEnabled()) {
            abort(404);
        }

        $validated = $request->validate(array(
            'email' => array('required', 'string', 'email', 'max:255'),
        ));

        $user = User::query()
            ->where('email', Str::lower(trim((string) $validated['email'])))
            ->first();

        if ($user instanceof User) {
            $this->send($user);
        }

        return response()->json(array(
            'success' => true,
            'message' => 'If that account exists, a sign-in link is on its way.',
        ));
    }

    /**
     * Consume a link and start the session.
     */
    public function confirm(Request $request): JsonResponse
    {
        if (! $this->magicLink->anyEstateEnabled()) {
            abort(404);
        }

        if (! $request->hasSession()) {
            // Same answer as the password path: the console reaches this route
            // from a first-party origin, where statefulApi() has opened a
            // session. Without one there is nothing to sign in *to*.
            return response()->json(array(
                'success' => false,
                'error' => array(
                    'code' => 'session_required',
                    'message' => 'Magic-link sign-in requires a first-party console origin.',
                ),
            ), 419);
        }

        $validated = $request->validate(array(
            'token' => array('required', 'string', 'max:255'),
        ));

        $user = $this->magicLink->consume((string) $validated['token']);

        if (! $user instanceof User) {
            return $this->invalid();
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        return app(SessionController::class)->me($request);
    }

    /**
     * The one failure answer: unknown, expired, already used, or deleted user.
     */
    private function invalid(): JsonResponse
    {
        return response()->json(array(
            'success' => false,
            'error' => array(
                'code' => 'magic_link_invalid',
                'message' => 'This sign-in link is invalid or has expired.',
            ),
        ), 401);
    }

    /**
     * Issue and mail a link, revoking it if the transport refuses.
     *
     * A token left live after a failed send is a credential nobody received but
     * anyone with the row could use; a dead link is the better failure. The
     * caller still gets the uniform response, and the failure is logged because
     * a broken transport is our problem to see.
     */
    private function send(User $user): void
    {
        $ticket = $this->magicLink->issue($user);

        if (null === $ticket) {
            return;
        }

        try {
            Mail::to($user->email)->send(new MagicLoginLink($ticket->link, (string) $user->name));
        } catch (\Throwable $exception) {
            $this->magicLink->revokeFor($user);

            // Logged once, with the reason: `report()` would surface the same
            // failure a second time at error level for an outcome the caller
            // has already been told is "fine".
            Log::warning('auth.magic_login.mail_failed', array(
                'user_id' => (int) $user->getAuthIdentifier(),
                'tenant_id' => $ticket->tenantId,
                'error' => $exception->getMessage(),
            ));
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Support\PersonalTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Personal access tokens (`/api/v1/personal-tokens`) — Proposal 149 §26.
 *
 * The user-scoped half of the token family. `TokenManagerController` serves
 * assistant credentials inside one estate; this serves a user's *own* tokens and
 * therefore sits OUTSIDE `ResolveApiTenant` — a token is not estate data, and a
 * multi-estate user must not need to pick an estate to mint one.
 *
 * Authorization is ownership, not a capability: every read and the revoke path
 * filter on the authenticated user's id. A token id belonging to someone else
 * answers 404, so the route cannot be used to probe which ids exist.
 *
 * The plaintext is returned once from `store()` and never persisted, logged or
 * audited. A mistyped endpoint that echoed it a second time would turn a
 * one-time credential into a durable one; `index()` selects metadata only.
 */
class PersonalTokenController
{
    /**
     * GET /api/v1/personal-tokens — the caller's own tokens.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = PersonalTokens::rows((int) $request->user()->getAuthIdentifier());

        return response()->json(array(
            'success' => true,
            'data' => array(
                'tokens' => $rows,
                'total' => count($rows),
                'expiry_options' => PersonalTokens::expiryOptions(),
            ),
        ));
    }

    /**
     * POST /api/v1/personal-tokens — mint one.
     *
     * The plaintext appears exactly here. It is not re-readable from any other
     * route, by design.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(array(
            'label' => array('sometimes', 'nullable', 'string', 'max:120'),
            'days' => array('sometimes', 'nullable', 'integer', 'min:'.PersonalTokens::MIN_DAYS, 'max:'.PersonalTokens::MAX_DAYS),
        ));

        $label = (string) ($validated['label'] ?? '');
        $days = (int) ($validated['days'] ?? 30);

        $minted = PersonalTokens::mint(
            (int) $request->user()->getAuthIdentifier(),
            $label,
            $days
        );

        if (null === $minted) {
            return response()->json(array(
                'success' => false,
                'code' => 'user_not_found',
                'message' => 'The authenticated user could not be resolved.',
            ), 404);
        }

        return response()->json(array(
            'success' => true,
            'data' => $minted,
            'message' => 'This is the only time the token will be shown. It cannot be recovered.',
        ), 201);
    }

    /**
     * DELETE /api/v1/personal-tokens/{token} — revoke one of the caller's own.
     */
    public function destroy(Request $request, int $token): JsonResponse
    {
        $revoked = PersonalTokens::revoke(
            (int) $request->user()->getAuthIdentifier(),
            $token
        );

        if (! $revoked) {
            return response()->json(array(
                'success' => false,
                'code' => 'personal_token_not_found',
                'message' => 'No token with that id belongs to you.',
            ), 404);
        }

        return response()->json(array(
            'success' => true,
            'data' => array('revoked' => true),
        ));
    }
}

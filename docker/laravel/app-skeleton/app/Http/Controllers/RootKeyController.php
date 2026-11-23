<?php

namespace App\Http\Controllers;

use App\Support\Security\RootKey;
use App\Support\Security\RootKeyGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Step-up verification for the operator panel — Proposal 149 §188.
 *
 * The console cannot send `X-Root-Key` on every Livewire request easily, so it
 * exchanges the key for a short session grant once and then works normally until
 * the grant expires. `status` exists so the UI can render "unlocked until …"
 * without guessing, and `lock` so an operator can end the session early rather
 * than waiting out the TTL.
 *
 * Every attempt — accepted or rejected — is audited by `RootKeyGate::attempt()`,
 * and the route carries a `throttle:` limiter: a second password guessing is
 * still guessing.
 *
 * The key is never echoed, never logged, and never returned. A wrong key answers
 * `422` with a generic message; distinguishing "wrong" from "not configured"
 * would tell an attacker which deployments have a key at all.
 */
final class RootKeyController
{
    public function __construct(
        private readonly RootKey $key,
        private readonly RootKeyGate $gate,
    ) {
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(array(
            'enabled' => $this->key->enabled(),
            'configured' => $this->key->configured(),
            'unlocked' => $this->gate->unlocked($request),
            'ttl_minutes' => RootKeyGate::TTL_MINUTES,
        ));
    }

    public function unlock(Request $request): JsonResponse
    {
        $validated = $request->validate(array(
            'key' => array('required', 'string'),
        ));

        if (! $this->gate->attempt($request, (string) $validated['key'])) {
            return response()->json(array(
                'code' => 'root_key_invalid',
                'message' => 'The root security key is not correct.',
            ), 422);
        }

        return response()->json(array(
            'unlocked' => true,
            'expires_in_minutes' => RootKeyGate::TTL_MINUTES,
        ));
    }

    public function lock(Request $request): JsonResponse
    {
        $this->gate->revoke($request);

        return response()->json(array('unlocked' => false));
    }
}

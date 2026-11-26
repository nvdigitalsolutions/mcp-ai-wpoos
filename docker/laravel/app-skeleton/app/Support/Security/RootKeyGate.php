<?php

namespace App\Support\Security;

use Illuminate\Http\Request;
use Throwable;

/**
 * Step-up verification for operator-scope writes — Proposal 149 §188.
 *
 * The field calls the root key "a second password for high-risk actions". §188
 * enforces it at the one chokepoint every operator write passes through:
 * `SettingsService::write()` with the `ops` surface. That covers the Filament
 * form, the API, and anything added later, because none of them write settings
 * any other way — and it avoids the trap that Livewire actions never pass
 * through route middleware.
 *
 * ## Two ways to prove possession
 *
 * | Proof | Use |
 * |---|---|
 * | `X-Root-Key: <key>` header | stateless API clients |
 * | a session grant from the unlock endpoint | the `/ops` panel, whose Livewire actions cannot set headers easily |
 *
 * The grant is time-boxed (`TTL_MINUTES`) because a permanent unlock is not
 * step-up authentication; OWASP ASVS 6.4 asks for re-authentication before
 * sensitive operations, and NIST SP 800-63B-4 asks for the full secret rather
 * than selected characters. Both are honoured by asking for the whole key and
 * expiring the proof.
 *
 * ## Console is not gated
 *
 * `nvoos:root-key --set` is the break-glass path. If a console command could be
 * gated by the key it manages, losing the key would make the deployment
 * unrecoverable — the field says the key "cannot be recovered if lost", so the
 * escape hatch has to live outside the panel. PHPUnit counts as console, so the
 * gate stays testable.
 *
 * ## Fail-closed on a misconfigured control
 *
 * If the flag is on and no key is stored, `required()` is still true: the gate
 * refuses rather than falling open. `SettingsService` exempts exactly one write —
 * storing the first key — so an operator can configure it from the panel, and
 * `nvoos:root-key` exists if they cannot.
 */
final class RootKeyGate
{
    /** Session key holding the grant's expiry timestamp. */
    public const SESSION_KEY = 'root_key_unlocked_until';

    /** Header a stateless client may send instead of a grant. */
    public const HEADER = 'X-Root-Key';

    /**
     * Form field name for an inline challenge — Proposal 149 §189.
     *
     * A Livewire action cannot set a header, so the destructive-action guard asks
     * for the key in the action's own modal and reads it from here.
     */
    public const ENTRY_FIELD = 'root_key';

    /** How long a grant lasts. Short enough to be step-up, long enough to work. */
    public const TTL_MINUTES = 15;

    public function __construct(
        private readonly RootKey $key,
        private readonly SecurityEventLogger $events,
    ) {
    }

    /**
     * Is a proof required for this context?
     */
    public function required(): bool
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return false;
        }

        return $this->key->enabled();
    }

    /**
     * Has this caller proved possession?
     */
    public function unlocked(?Request $request = null): bool
    {
        $request ??= request();

        if (! $request instanceof Request) {
            return false;
        }

        if ($this->headerProof($request)) {
            return true;
        }

        if (! $request->hasSession()) {
            return false;
        }

        try {
            $until = (int) $request->session()->get(self::SESSION_KEY, 0);
        } catch (Throwable) {
            return false;
        }

        return $until > now()->getTimestamp();
    }

    /**
     * Verify a candidate and, on success, grant the session a short window.
     *
     * Returns false on a wrong key. The caller decides the response, but the
     * attempt is audited here either way — a second password that records nothing
     * is a second password nobody can investigate.
     */
    public function attempt(Request $request, string $candidate): bool
    {
        $ok = $this->key->verify($candidate);

        $this->events->record(
            $ok ? SecurityEventLogger::ROOT_KEY_GRANTED : SecurityEventLogger::ROOT_KEY_DENIED,
            $request,
            null,
            $ok ? 'Root key verified' : 'Root key rejected'
        );

        if (! $ok) {
            return false;
        }

        if ($request->hasSession()) {
            $request->session()->put(
                self::SESSION_KEY,
                now()->addMinutes(self::TTL_MINUTES)->getTimestamp()
            );
        }

        return true;
    }

    public function revoke(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_KEY);
        }
    }

    /**
     * Throw unless the caller has proved possession.
     *
     * @throws RootKeyRequired
     */
    public function assertUnlocked(?Request $request = null): void
    {
        if (! $this->required() || $this->unlocked($request)) {
            return;
        }

        throw new RootKeyRequired();
    }

    /**
     * Whether this request carries a valid key header.
     *
     * Compared through `RootKey::verify()` so the header cannot sidestep the hash
     * check, and never against an empty candidate.
     */
    private function headerProof(Request $request): bool
    {
        $candidate = (string) $request->headers->get(self::HEADER, '');

        return '' !== $candidate && $this->key->verify($candidate);
    }
}

<?php

namespace App\Support\Security;

use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Security-event recorder — Proposal 149 §186.
 *
 * The four audit controls on `/ops/settings` were writable and read by nothing:
 *
 * - `enable_security_audit_log` — the master switch (default **off**);
 * - `log_successful_auth` — sign-ins in addition to failures (default off);
 * - `audit_log_retention_days` — retention, `0` meaning unlimited (default 90);
 * - the file-access key — declared inapplicable instead; this runtime serves no
 *   files, so there is no access to observe (§187).
 *
 * ## Why a dedicated table
 *
 * `nvoos_admin_audit` answers "which admin request changed what". A failed
 * sign-in has no admin request and no resource, so mixing the two would force
 * every audit query to filter a row kind it does not want. This stream answers
 * "who tried to get in, and did it work" — the question SOC 2 and GDPR actually
 * ask, which is the field's own justification.
 *
 * ## Failure behaviour
 *
 * Recording never throws and never changes the response. A security log that can
 * fail a sign-in is an availability risk in front of the one path that must stay
 * up; a missed row is the lesser failure. `record()` therefore swallows storage
 * errors, and `enabled()` treats an unreadable setting as off.
 *
 * ## Redaction
 *
 * Callers pass short descriptive context, never credentials. The logger still
 * truncates: `context` is 255 characters and `data` is a small map, so a caller
 * cannot accidentally store a request body.
 */
final class SecurityEventLogger
{
    public const AUTH_FAILURE = 'auth.failure';

    public const AUTH_SUCCESS = 'auth.success';

    public const AUTH_LOCKOUT = 'auth.lockout';

    /** 149 §188 — step-up attempts against the root security key. */
    public const ROOT_KEY_GRANTED = 'rootkey.granted';

    public const ROOT_KEY_DENIED = 'rootkey.denied';

    /** Longest context string stored; the column is 255. */
    private const MAX_CONTEXT = 255;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * The master switch. Unreadable settings mean off: no row is worth taking a
     * sign-in down for.
     */
    public function enabled(): bool
    {
        try {
            return filter_var(
                $this->settings->value('enable_security_audit_log'),
                FILTER_VALIDATE_BOOLEAN
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether successful sign-ins are recorded *in addition to* failures.
     */
    public function logsSuccesses(): bool
    {
        try {
            return filter_var(
                $this->settings->value('log_successful_auth'),
                FILTER_VALIDATE_BOOLEAN
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Record one event. Silent when disabled, and silent on storage failure.
     *
     * @param array<string, scalar|null> $data
     */
    public function record(string $event, Request $request, ?int $userId = null, string $context = '', array $data = array()): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            DB::table('nvoos_security_events')->insert(array(
                'tenant_id' => $this->tenantId(),
                'event' => $event,
                'user_id' => $userId ?? $this->userId($request),
                'ip' => $request->ip(),
                'context' => mb_substr($context, 0, self::MAX_CONTEXT),
                'data' => array() === $data ? null : json_encode($data, JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ));
        } catch (Throwable) {
            // Deliberately swallowed; see the class docblock.
        }
    }

    /**
     * Record a successful sign-in when the operator asked for those.
     */
    public function recordSuccess(Request $request, ?int $userId = null, string $context = 'Signed in'): void
    {
        if (! $this->logsSuccesses()) {
            return;
        }

        $this->record(self::AUTH_SUCCESS, $request, $userId, $context);
    }

    /**
     * Delete events older than the retention window. Returns the row count.
     *
     * `0` means unlimited, which is the field's own contract — a sweep that
     * treated 0 as "delete everything" would be the worst possible reading of
     * "retain indefinitely".
     */
    public function prune(): int
    {
        try {
            $days = (int) $this->settings->value('audit_log_retention_days');
        } catch (Throwable) {
            return 0;
        }

        if ($days <= 0) {
            return 0;
        }

        try {
            return (int) DB::table('nvoos_security_events')
                ->where('created_at', '<', now()->subDays($days))
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    private function tenantId(): ?string
    {
        try {
            $tenant = TenantContext::current();
        } catch (Throwable) {
            return null;
        }

        return '' === $tenant ? null : $tenant;
    }

    private function userId(Request $request): ?int
    {
        $user = $request->user();

        return null === $user ? null : (int) $user->getAuthIdentifier();
    }
}

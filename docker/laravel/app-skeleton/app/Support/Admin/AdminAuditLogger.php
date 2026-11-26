<?php

namespace App\Support\Admin;

use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Append-only admin audit writer (Proposal 149 T-0.9).
 *
 * Records one row per mutating admin request — including requests that FAILED.
 * An audit trail that only logs successes answers "what changed?" but not
 * "who tried?".
 *
 * Redaction is structural, not best-effort: any payload key whose name contains
 * a credential fragment is replaced with `[redacted]` before the row is built,
 * at any nesting depth. The audit table must never become a second place
 * secrets leak to, which is why a settings form that includes an API key
 * stores `[redacted]` rather than the key.
 *
 * Two kinds of row share the table (Wave 2): request rows (this class's
 * `record()`, `resource` NULL) answer "who tried"; record rows
 * (`recordResource()`, `resource` set) answer "what changed". A resource write
 * produces one of each, because a bulk action touching many records cannot be
 * represented by a single request row.
 *
 * Deliberately NOT a model: there is no update path. `insert()` is the only
 * write, and the table has no `updated_at`, so nothing in the application can
 * rewrite history.
 */
final class AdminAuditLogger
{
    /**
     * Substrings that mark a payload key as a credential.
     *
     * Substring matching over-redacts (`max_tokens` matches `token`), and that
     * is the intended trade: an over-redacted log is an inconvenience, an
     * under-redacted one is an incident.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = array(
        'password',
        'secret',
        'token',
        'credential',
        'authorization',
        'api_key',
        'apikey',
        'licence_key',
        'license_key',
        'private_key',
    );

    private const REDACTED = '[redacted]';

    /** Payloads larger than this are replaced by a size marker. */
    private const MAX_PAYLOAD_BYTES = 8192;

    /** Guards pathological request nesting. */
    private const MAX_DEPTH = 8;

    /**
     * Write one audit row.
     *
     * @param int|null $forcedStatus Status to record when no response exists
     *                               (an exception unwound the pipeline).
     */
    public function record(
        Request $request,
        ?Response $response,
        ?string $panelId = null,
        ?int $forcedStatus = null
    ): void {
        DB::table('nvoos_admin_audit')->insert(array(
            'tenant_id' => $this->tenantId(),
            'user_id' => $request->user()?->getAuthIdentifier(),
            'panel' => $panelId ?? $this->panelFromRoute($request) ?? 'unknown',
            'method' => strtoupper($request->getMethod()),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName(),
            'status' => $forcedStatus ?? $response?->getStatusCode() ?? 0,
            'payload' => $this->payload($request),
            'ip' => $request->ip(),
            'created_at' => now(),
        ));
    }

    /**
     * Write one record-level audit row (Proposal 149, Wave 2).
     *
     * Request rows answer "who tried"; these answer "what changed". A resource
     * write therefore produces two rows, and `resource` is what tells them
     * apart. Bulk actions legitimately produce one record row per record, which
     * a single request row could not represent.
     *
     * `before`/`after` are redacted with the same structural rule as `payload`.
     * This is not theoretical: the estate licence key is a column on the
     * record, so a diff would otherwise store the credential in clear text —
     * and the encrypted cast would not help, because the model exposes the
     * plaintext.
     *
     * `method` mirrors the action's HTTP verb so a single
     * `ORDER BY created_at` reads chronologically across both row kinds.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public function recordResource(
        string $resource,
        string $recordId,
        string $action,
        array $before,
        array $after,
        ?string $panelId = null
    ): void {
        $request = request();

        DB::table('nvoos_admin_audit')->insert(array(
            'tenant_id' => $this->tenantId(),
            'user_id' => auth()->id(),
            'panel' => $panelId
                ?? $this->panelFromRoute($request)
                ?? Filament::getCurrentPanel()?->getId()
                ?? 'unknown',
            'method' => $this->verbFor($action),
            'path' => $this->recordPath($resource, $recordId),
            'route' => $request->route()?->getName(),
            'status' => 200,
            'payload' => null,
            'resource' => $resource,
            'record_id' => $recordId,
            'action' => $action,
            'before' => $this->encodeDiff($before),
            'after' => $this->encodeDiff($after),
            'ip' => $request->ip(),
            'created_at' => now(),
        ));
    }

    /**
     * The estate the action belongs to, or null on the operator panel.
     *
     * Reads the facade's current tenant, NOT `Panel::getTenant()`: in Filament 5
     * that method resolves a tenant BY KEY (`getTenant(string $key): Model`), so
     * calling it with no argument is a fatal — which is how this defect
     * surfaced, on the first tenant-scoped resource that actually wrote a
     * record row (Wave 2, teams). The fallback to the pinned context covers a
     * Livewire update where the panel stack did not run.
     */
    private function tenantId(): ?string
    {
        $panel = Filament::getCurrentPanel();

        if (null !== $panel && $panel->hasTenancy()) {
            $tenant = Filament::getTenant();

            if ($tenant instanceof Model) {
                return (string) $tenant->getKey();
            }

            if (null !== $tenant && '' !== (string) $tenant) {
                return (string) $tenant;
            }
        }

        return TenantContext::isPinned() ? TenantContext::current() : null;
    }

    /**
     * Derive the panel id from a Filament route name (`filament.<id>.…`).
     *
     * `Filament::getCurrentPanel()` is null on some panel routes (the login
     * page among them), and "unknown" in every row would make the log useless
     * for the question it exists to answer.
     */
    private function panelFromRoute(Request $request): ?string
    {
        $name = $request->route()?->getName();

        if (! is_string($name) || ! str_starts_with($name, 'filament.')) {
            return null;
        }

        $parts = explode('.', $name);

        return $parts[1] ?? null;
    }

    /**
     * The redacted request payload as JSON, or null when there is nothing to
     * record.
     */
    private function payload(Request $request): ?string
    {
        $input = $request->all();

        if (array() === $input) {
            return null;
        }

        $encoded = json_encode(
            $this->redact($input),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (false === $encoded) {
            return null;
        }

        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return (string) json_encode(array(
                '_truncated' => true,
                '_bytes' => strlen($encoded),
            ));
        }

        return $encoded;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private function redact(array $value, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return array('_depth_exceeded' => true);
        }

        $out = array();

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitive($key)) {
                $out[$key] = self::REDACTED;

                continue;
            }

            $out[$key] = is_array($item) ? $this->redact($item, $depth + 1) : $item;
        }

        return $out;
    }

    private function isSensitive(string $key): bool
    {
        $normalised = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalised, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The HTTP verb a record action maps onto.
     *
     * PATCH rather than PUT for an update: what is stored is a partial diff of
     * the attributes that actually changed, not a full representation.
     */
    private function verbFor(string $action): string
    {
        return match ($action) {
            'created' => 'POST',
            'deleted' => 'DELETE',
            default => 'PATCH',
        };
    }

    /**
     * The path a record row belongs to.
     *
     * Inside a request the real path is the most useful fact ("this change
     * happened on POST /ops/estates"). Outside one — a command or a queue job
     * acting for an authenticated user — a synthetic path names the record
     * instead of writing `/`.
     */
    private function recordPath(string $resource, string $recordId): string
    {
        if (app()->runningInConsole()) {
            return $resource.'/'.$recordId;
        }

        $path = trim(request()->path(), '/');

        return '' === $path ? $resource.'/'.$recordId : '/'.$path;
    }

    /**
     * A redacted before/after diff as JSON, or null when nothing changed.
     *
     * @param array<string, mixed> $diff
     */
    private function encodeDiff(array $diff): ?string
    {
        if (array() === $diff) {
            return null;
        }

        $encoded = json_encode(
            $this->redact($diff),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (false === $encoded) {
            return null;
        }

        if (strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            return (string) json_encode(array(
                '_truncated' => true,
                '_bytes' => strlen($encoded),
            ));
        }

        return $encoded;
    }
}

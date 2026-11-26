<?php

namespace App\Support\Approvals;

use App\Models\Approval;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use Nvoos\Core\Domain\ValueObject\ApprovalRequest;

/**
 * Approval tickets over `nvoos_approvals` — Proposal 149 §157.
 *
 * Mirrors the machine-tool path (`MachineToolController`) rather than inventing
 * a second scheme: the same `arguments_hash`, the same `consumed_at`, the same
 * conditional-update claim. The difference is *who* it binds: the machine path
 * binds a tool to an assistant, this one binds a tool to its requester, because
 * a necessity approval is granted to a person, not to a scheduled runner.
 *
 * `Approval` carries a global tenant scope, so every query here is already
 * estate-scoped. `TenantContext::current()` is only needed on insert, where the
 * scope cannot help.
 */
final class DatabaseApprovalTicket implements ApprovalTicketInterface
{
    /**
     * How long an approval stays spendable. Matches the machine path.
     */
    public const DEFAULT_TTL_SECONDS = 86400;

    public function __construct(private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function request(ApprovalRequest $request): string
    {
        $hash = $request->argumentsFingerprint();

        $pending = $this->matching($request, $hash)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (null !== $pending) {
            // A model retrying after a refusal is the normal case, not a new
            // decision. Queueing a second ticket would ask the human twice.
            return (string) $pending->getKey();
        }

        $encoded = json_encode(
            $request->arguments,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return (string) DB::table('nvoos_approvals')->insertGetId(
            array(
                'tenant_id'      => TenantContext::current(),
                'status'         => 'pending',
                'tool'           => $request->tool,
                'arguments'      => false === $encoded ? '{}' : $encoded,
                'arguments_hash' => $hash,
                'requester_id'   => $request->requesterId,
                'session_id'     => $request->sessionId,
                'reason'         => '' !== $request->reason ? $request->reason : null,
                'expires_at'     => now()->addSeconds($this->ttlSeconds),
            )
        );
    }

    /**
     * {@inheritDoc}
     */
    public function consume(ApprovalRequest $request): bool
    {
        $hash = $request->argumentsFingerprint();

        $approved = $this->matching($request, $hash)
            ->where('status', 'approved')
            ->whereNull('consumed_at')
            ->where(
                static fn (Builder $query): Builder => $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now())
            )
            ->latest('id')
            ->first();

        if (null === $approved) {
            return false;
        }

        // Claim before executing: two retries racing must not both run. The
        // conditional update is what makes consumption single-use.
        $claimed = Approval::query()
            ->whereKey($approved->getKey())
            ->whereNull('consumed_at')
            ->update(
                array(
                    'consumed_at' => now(),
                    'status'      => 'consumed',
                    'updated_at'  => now(),
                )
            );

        return 1 === $claimed;
    }

    /**
     * The rows this request could match: same tool, same payload, same person.
     *
     * The session is recorded on the ticket for audit but is **not** part of the
     * match — a retry after a restart legitimately arrives from a new session,
     * and the payload plus the requester are what the human actually approved.
     */
    private function matching(ApprovalRequest $request, string $hash): Builder
    {
        return Approval::query()
            ->where('tool', $request->tool)
            ->where('arguments_hash', $hash)
            ->when(
                null !== $request->requesterId,
                static fn (Builder $query): Builder => $query->where(
                    'requester_id',
                    $request->requesterId
                )
            );
    }
}

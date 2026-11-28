<?php

/**
 * The result of a connection health check.
 *
 * Proposal 167 section 167.6.2 and amendment A1. The probe is **introspective**:
 * it asks PostgreSQL what the role may do (`rolsuper`, `rolbypassrls`,
 * `has_table_privilege`) rather than attempting a write and rolling it back. A
 * temporary-table probe proves TEMP privilege, which is not the question; and a
 * probe that writes is a probe that can fail for reasons unrelated to safety.
 *
 * `canWrite` is the answer the kernel actually needs. A connection whose probe
 * reports `canWrite: true` is still registrable, but it is only servable to a
 * scope holding a write tier — and the reason is recorded either way, never
 * silently downgraded.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\ValueObject\Database;

final readonly class ProbeResult
{
    /**
     * @param bool        $reachable           Whether a connection was established.
     * @param string      $serverVersion       Engine version string, e.g. `18.1`.
     * @param string      $role                The role the credential authenticates as.
     * @param bool        $transactionReadOnly Effective `transaction_read_only`.
     * @param bool        $canWrite            True if any write privilege was detected.
     * @param bool        $canCreateSchema     True if the role may create in a scope schema.
     * @param bool        $bypassesRls         True if the role has BYPASSRLS or is superuser.
     * @param int         $checkedAt           Unix timestamp of the check.
     * @param string|null $error               Machine reason when `reachable` is false.
     */
    public function __construct(
        public bool $reachable,
        public string $serverVersion = '',
        public string $role = '',
        public bool $transactionReadOnly = false,
        public bool $canWrite = false,
        public bool $canCreateSchema = false,
        public bool $bypassesRls = false,
        public int $checkedAt = 0,
        public ?string $error = null,
    ) {
    }

    /**
     * A failed probe.
     */
    public static function unreachable(string $error): self
    {
        return new self(false, checkedAt: time(), error: $error);
    }

    /**
     * Whether the probe permits the connection to serve reads.
     *
     * Reachability is the only requirement: a read-only scope can be served by a
     * role that also writes, as long as the scope's tiers do not include a write
     * tier (enforced separately by the scope policy).
     */
    public function isServable(): bool
    {
        return $this->reachable;
    }

    /**
     * Whether the connection is safe to serve to a read-only scope.
     *
     * `bypassesRls` disqualifies it regardless of grants: a role that bypasses
     * row security makes the row filter the only control, which is a deliberate
     * step down that an operator must take knowingly.
     */
    public function isReadSafe(): bool
    {
        return $this->reachable && ! $this->bypassesRls;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array(
            'reachable' => $this->reachable,
            'server_version' => $this->serverVersion,
            'role' => $this->role,
            'transaction_read_only' => $this->transactionReadOnly,
            'can_write' => $this->canWrite,
            'can_create_schema' => $this->canCreateSchema,
            'bypasses_rls' => $this->bypassesRls,
            'checked_at' => $this->checkedAt,
            'error' => $this->error,
        );
    }
}

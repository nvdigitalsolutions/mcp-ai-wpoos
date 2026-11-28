<?php

/**
 * Optional port capability: read the audit trail back.
 *
 * Proposal 167 phase P3 and P4. Writing the trail and reading it are separate
 * authorities: every host must record, but only an operator-facing surface needs
 * to query. Keeping them apart is why `AuditSinkInterface` stayed two methods and
 * this interface is new rather than a widening of it — an existing sink that
 * cannot be read still satisfies its contract.
 *
 * Two stores implement it with different natures, and the difference is
 * deliberate:
 *
 *  - WordPress is a **bounded ring** (500 records in an option). `retained()`
 *    returns that ceiling.
 *  - Laravel is a **ledger** (an append-only table). Nothing is dropped by the
 *    library, because an audit trail that silently deletes itself is not one;
 *    `retained()` returns 0 to mean "no record ceiling — the operator prunes by
 *    age".
 *
 * Records never carry bound values: a statement template and a fingerprint only.
 *
 * @package Nvoos\Database
 * @since   3.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Domain\Contract\Database;

interface AuditReaderInterface
{
    /**
     * Most recent records, newest first.
     *
     * @param int                  $limit   Maximum records.
     * @param array<string, mixed> $filters Optional `connection`, `scope`, `action`, `tool`, `refusal_code`.
     * @return list<array<string, mixed>>
     */
    public function tail(int $limit = 50, array $filters = array()): array;

    /**
     * Counts over a lookback window, keyed by refusal code plus `total`.
     *
     * @return array<string, int>
     */
    public function summary(int $window_seconds = 3600): array;

    /**
     * The store's retention ceiling in records, or 0 when it prunes by time.
     */
    public function retained(): int;
}

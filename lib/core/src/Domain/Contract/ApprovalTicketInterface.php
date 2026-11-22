<?php

/**
 * Approval ticket store — the durable half of human-in-the-loop.
 *
 * The runtime cannot hold a connection open while a human decides, so approval
 * is a ticket: the guard records what it wants to do, the call is refused with
 * the ticket id, and a retry spends the ticket once it has been approved.
 *
 * Implementations must enforce all four properties, because the guard only
 * checks `consume()`:
 *
 *   1. **Bound to the action** — the payload hash plus the tool and requester
 *      columns must match, so an approval for one call cannot authorise another.
 *   2. **Single use** — claiming must be a conditional update, so two retries
 *      racing cannot both run.
 *   3. **Expiring** — a stale approval is not an approval.
 *   4. **Fail-closed** — anything ambiguous denies.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\ValueObject\ApprovalRequest;

/**
 * Records and spends approval tickets.
 *
 * @since 2.1.0
 */
interface ApprovalTicketInterface
{
    /**
     * Record a pending request and return its identifier.
     *
     * Re-asking for the same payload while a pending ticket exists should
     * return that ticket rather than queue a duplicate — a model retrying after
     * a refusal is the normal case, not a new decision.
     */
    public function request(ApprovalRequest $request): string;

    /**
     * Spend an approval matching exactly this action.
     *
     * @return bool True when an approved, unexpired, unspent ticket existed and
     *              has now been marked spent. False in every other case.
     */
    public function consume(ApprovalRequest $request): bool;
}

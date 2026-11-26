<?php

/**
 * Per-session record of the tool calls an autonomous loop made.
 *
 * Proposal 164 section 164.78. `get_loop_metrics` is the one loop tool whose
 * subject is not the session object but the calls made during it: success rate,
 * average duration, tool frequency, error distribution. WordPress reads those
 * from an `Execution_History` Custom Content Type; this contract is the same
 * append-only log without the post type.
 *
 * The record shape is fixed because the metrics tool computes over it:
 *
 * ```
 * tool_name      string  Tool slug.
 * success        bool    Whether the call succeeded.
 * duration_ms    int     Wall time, 0 when unmeasured.
 * tokens_used    int     Tokens attributed to the call, 0 when unmeasured.
 * error_message  string  The failure message, '' on success.
 * iteration      int     Loop iteration the call belonged to.
 * at             string  ISO 8601 timestamp.
 * ```
 *
 * Extra keys are preserved by the store and ignored by the reader, so a host
 * may record more (arguments, provider, cost) without a contract change.
 *
 * ## Bounded by design
 *
 * A runaway loop is exactly the thing this toolkit exists to stop, so the
 * history is read with a cap. `forSession()` takes a limit and an implementation
 * must enforce one even when the caller omits it: an unbounded read of a loop
 * that never stopped is a memory bug on top of the bug that caused it. The
 * WordPress reader asks for 1,000 records in ascending order, and the metrics
 * tool aggregates what it gets; a truncated read is therefore visible as a
 * smaller `total_calls`, which is honest rather than silently wrong.
 *
 * ## Optional collaborator
 *
 * A host that records no tool-call history is not broken. `get_loop_metrics`
 * binds this contract optionally and answers the WordPress "no execution
 * history found" payload when it is unbound, instead of an error.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ExecutionHistoryStoreInterface
{
    /** The largest read an implementation should serve. */
    public const MAX_LIMIT = 5000;

    /** The default read size, matching the WordPress reader. */
    public const DEFAULT_LIMIT = 1000;

    /**
     * Append one record for a session.
     *
     * @param array<string, mixed> $record See the class comment.
     */
    public function append(string $sessionId, array $record): void;

    /**
     * A session's records, in append order.
     *
     * @param int $limit Maximum records; clamped to 1..MAX_LIMIT.
     *
     * @return list<array<string, mixed>>
     */
    public function forSession(string $sessionId, int $limit = self::DEFAULT_LIMIT): array;
}

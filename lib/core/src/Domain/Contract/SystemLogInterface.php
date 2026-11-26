<?php

/**
 * The host's queryable log, as an agent can read it.
 *
 * "System logs" means something different on every host. WordPress reads
 * `debug.log`, the plugin's activity option and any `.log` under `wp-content`;
 * this runtime keeps two structured stores — the append-only admin audit trail
 * and the harness trace records — and no log *files* it should be pasting into
 * a model's context. So the contract carries entries, not lines, and the host
 * decides which stores answer.
 *
 * ## The shape
 *
 * Each entry is `id`, `at`, `source`, `type`, `level`, `summary`, `actor`,
 * `detail`. `level` uses the WordPress tool's vocabulary (`critical`, `error`,
 * `warning`, `notice`, `deprecated`) so a caller that already filters on levels
 * does not need a second mapping. `source` names the store, and `types()` is not
 * needed: an entry says which source produced it.
 *
 * ## Rules a host must follow
 *
 * - **Newest first, bounded.** Return at most the requested limit; the stores
 *   grow forever and an unbounded read is a memory bug waiting for traffic.
 * - **Honour `since` when the store can.** A filter applied only after the read
 *   can under-return silently; that is the host's call to make, but the query
 *   is where it belongs.
 * - **No second redaction pass.** The audit trail is already redacted at write
 *   time (`AdminAuditLogger::redact()`); re-redacting here would give false
 *   confidence about a path that never ran.
 * - **`sources()` is the answer, not a hint.** A host with no queryable log
 *   returns an empty list, and the tool reports "this host keeps no queryable
 *   log" rather than an empty log that reads like "nothing happened".
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface SystemLogInterface
{
    /** A failure that stopped work. */
    public const CRITICAL = 'critical';

    /** A failure the caller can see and act on. */
    public const ERROR = 'error';

    /** Something refused or degraded but did not fail. */
    public const WARNING = 'warning';

    /** A normal event worth recording. */
    public const NOTICE = 'notice';

    /** Present for vocabulary parity with the WordPress tool. */
    public const DEPRECATED = 'deprecated';

    /**
     * The stores this host can read.
     *
     * Empty means "no queryable log here" — a real answer, not a failure.
     *
     * @return list<string>
     */
    public function sources(): array;

    /**
     * Entries, newest first.
     *
     * `since` is an ISO 8601 UTC timestamp or an empty string for no time
     * filter. `types`, `levels` and `search` are advisory: a host should apply
     * what its store can express in the query, and the tool re-filters the
     * result so the response always obeys the request.
     *
     * @param array{limit?: int, since?: string, search?: string, types?: list<string>, levels?: list<string>} $query
     *
     * @return list<array{
     *     id: string,
     *     at: string,
     *     source: string,
     *     type: string,
     *     level: string,
     *     summary: string,
     *     actor: string|null,
     *     detail: array<string, mixed>
     * }>
     */
    public function entries(array $query = array()): array;
}

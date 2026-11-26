<?php

/**
 * Autonomous orchestration session state.
 *
 * Proposal 164 section 164.78. The eight loop tools in the orchestration
 * toolkit all describe one object: a session with a lifecycle status, an
 * iteration count against a cap, a token spend against a budget, a health
 * verdict, and a circuit-breaker state. WordPress keeps that object in a
 * transient with a Custom Content Type behind it; this contract keeps the shape
 * and lets the host decide where it lives.
 *
 * ## The state machine
 *
 * ```
 *            start                pause                resume
 *   (none) ─────────▶ active ◀──────────── paused ─────────────┐
 *                       │  └───────────────────────────────────┘
 *                       │ stop                    stop
 *                       ▼                          ▼
 *                   completed                  completed
 * ```
 *
 * WordPress is more permissive than that drawing: `resume` also turns a
 * `completed` session back into `active`, and `pause`/`stop` accept any state.
 * The port keeps the permissive transitions for parity, and the readiness rows
 * say so. A host that wants a strict machine can enforce it in its store; the
 * contract does not require one.
 *
 * `failed` is a status the tools can read and write back but no tool sets it;
 * it exists because the WordPress readers recognise it.
 *
 * ## Why the store owns hydration
 *
 * Every reader assumes fields exist (`iteration_count`, `token_budget`,
 * `expires_at` …). WordPress would emit undefined-key notices on a partial
 * record. Implementations must return a record with the defaults filled in, so
 * a host that stores only what changed still satisfies the readers. The
 * defaults are the ones `manage_autonomous_session` writes on start.
 *
 * ## Query criterion
 *
 * ```
 * status   string   exact status
 * statuses string[] any of these statuses (takes precedence over `status`)
 * since    string   ISO 8601; keeps sessions whose last_activity is at or after it
 * limit    int      1–200, default 25
 * offset   int      default 0
 * order    string   last_activity (default) | started_at, descending
 * ```
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface AutonomousSessionStoreInterface
{
    /** Running and allowed to iterate. */
    public const STATUS_ACTIVE = 'active';

    /** Deliberately halted; resumable. */
    public const STATUS_PAUSED = 'paused';

    /** Stopped, with `completed_at` stamped. */
    public const STATUS_COMPLETED = 'completed';

    /** Recognised by the readers; no tool sets it. */
    public const STATUS_FAILED = 'failed';

    /** Healthy. */
    public const HEALTH_HEALTHY = 'healthy';

    /** Degraded but running. */
    public const HEALTH_WARNING = 'warning';

    /** Runaway or unrecoverable; the breaker should open. */
    public const HEALTH_CRITICAL = 'critical';

    /** The default iteration cap, as in WordPress. */
    public const DEFAULT_MAX_ITERATIONS = 25;

    /** The default token budget, as in WordPress. */
    public const DEFAULT_TOKEN_BUDGET = 10000;

    /** The default lifetime in seconds (24 hours), as in WordPress. */
    public const DEFAULT_TTL = 86400;

    /**
     * Create a session and return it as stored.
     *
     * `session_id` is used when supplied and must be a safe single segment;
     * otherwise the implementation generates one. An implementation must
     * refuse an id that already exists rather than overwrite a running session.
     *
     * @param array<string, mixed> $session Session fields; missing fields take the documented defaults.
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the id is unusable.
     * @throws \RuntimeException         When the id already exists or the write fails.
     */
    public function create(array $session): array;

    /**
     * Read one session, or null when it does not exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $sessionId): ?array;

    /**
     * Merge changes into a session and return the stored record.
     *
     * The implementation stamps `last_activity` unless the changes carry one.
     *
     * @param array<string, mixed> $changes Fields to merge.
     *
     * @return array<string, mixed>|null Null when the session does not exist.
     */
    public function update(string $sessionId, array $changes): ?array;

    /**
     * Filter sessions, newest activity first.
     *
     * @param array<string, mixed> $criteria See the class comment.
     *
     * @return array{sessions: list<array<string, mixed>>, total: int}
     */
    public function query(array $criteria = array()): array;
}

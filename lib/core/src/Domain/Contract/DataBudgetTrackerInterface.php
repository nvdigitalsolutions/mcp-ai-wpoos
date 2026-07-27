<?php
/**
 * Data Budget Tracker — cumulative byte budget for agentic loops.
 *
 * Tracks total bytes of tool output entering the LLM context across all
 * iterations of a single agentic loop, providing a single source of truth
 * for the agentic-loop output guard.
 *
 * Per-request scoped. Callers should construct a fresh tracker per request
 * or call reset() between requests.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Tracks cumulative byte consumption against configurable budgets.
 *
 * Implementations are per-request scoped. The contract defines the
 * accounting behavior — budget resolution (how budgets are determined)
 * is left to the adapter layer.
 *
 * @since 2.0.0
 */
interface DataBudgetTrackerInterface
{
    /**
     * Default overall request budget (1 MiB).
     *
     * @var int
     */
    public const DEFAULT_REQUEST_BUDGET_BYTES = 1048576;

    /**
     * Default per-message budget (64 KiB).
     *
     * @var int
     */
    public const DEFAULT_PER_MESSAGE_BUDGET_BYTES = 65536;

    /**
     * Return the overall request budget in bytes.
     *
     * @return int
     */
    public function getRequestBudget(): int;

    /**
     * Return the per-message budget in bytes.
     *
     * @return int
     */
    public function getPerMessageBudget(): int;

    /**
     * Record bytes consumed by a tool message.
     *
     * @param int $bytes Bytes consumed.
     */
    public function record(int $bytes): void;

    /**
     * Return total bytes consumed in the current request.
     *
     * @return int
     */
    public function consumed(): int;

    /**
     * Return remaining bytes in the request budget.
     *
     * @return int
     */
    public function remaining(): int;

    /**
     * Whether the overall request budget has been exhausted.
     *
     * @return bool
     */
    public function isExhausted(): bool;

    /**
     * Whether a message of the given size should be spilled to an artifact.
     *
     * Returns true when the message would exceed the per-message ceiling
     * or exhaust the remaining request budget.
     *
     * @param int $bytes Bytes the message will contribute.
     *
     * @return bool
     */
    public function shouldSpill(int $bytes): bool;

    /**
     * Increment the spill counter.
     */
    public function noteSpill(): void;

    /**
     * Return the number of spills observed in this request.
     *
     * @return int
     */
    public function spillCount(): int;

    /**
     * Reset state for a new request.
     *
     * @param string $requestId Optional new request identifier.
     */
    public function reset(string $requestId = ''): void;
}

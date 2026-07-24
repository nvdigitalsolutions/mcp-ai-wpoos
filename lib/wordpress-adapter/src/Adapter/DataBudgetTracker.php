<?php
/**
 * WordPress Adapter — Data Budget Tracker.
 *
 * Bridges {@see DataBudgetTrackerInterface} to WordPress filter-based
 * budget resolution. The core accounting logic lives in the pure domain
 * service; this adapter resolves budgets via WordPress filters and
 * delegates all accounting to the domain service.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface;
use Nvoos\Core\Domain\Service\Budget\DataBudgetTracker as CoreDataBudgetTracker;

/**
 * WordPress adapter for the Data Budget Tracker.
 *
 * Resolves budgets through WordPress filters, then delegates to the
 * pure domain service for byte accounting.
 *
 * @since 2.0.0
 */
final class DataBudgetTracker implements DataBudgetTrackerInterface
{
    /**
     * Pure domain tracker.
     */
    private CoreDataBudgetTracker $tracker;

    /**
     * Constructor.
     *
     * @param string $requestId Optional request identifier.
     */
    public function __construct(string $requestId = '')
    {
        $requestBudget    = $this->resolveRequestBudget($requestId);
        $perMessageBudget = $this->resolvePerMessageBudget($requestId);

        $this->tracker = new CoreDataBudgetTracker(
            $requestBudget,
            $perMessageBudget,
            $requestId
        );
    }

    // ── Budget Resolution (WordPress-specific) ──────────────────────────

    /**
     * Resolve the overall request budget via WordPress filter.
     *
     * Falls back to DEFAULT_REQUEST_BUDGET_BYTES when WP is not loaded.
     */
    private function resolveRequestBudget(string $requestId): int
    {
        if (!\function_exists('apply_filters')) {
            return DataBudgetTrackerInterface::DEFAULT_REQUEST_BUDGET_BYTES;
        }

        /** @var int $budget */
        $budget = \apply_filters(
            'wp_mcp_ai_agentic_loop_byte_budget',
            DataBudgetTrackerInterface::DEFAULT_REQUEST_BUDGET_BYTES,
            $requestId
        );

        return \max(1024, (int) $budget);
    }

    /**
     * Resolve the per-message budget via WordPress filter.
     */
    private function resolvePerMessageBudget(string $requestId): int
    {
        if (!\function_exists('apply_filters')) {
            return DataBudgetTrackerInterface::DEFAULT_PER_MESSAGE_BUDGET_BYTES;
        }

        /** @var int $budget */
        $budget = \apply_filters(
            'wp_mcp_ai_agentic_loop_per_message_byte_budget',
            DataBudgetTrackerInterface::DEFAULT_PER_MESSAGE_BUDGET_BYTES,
            $requestId
        );

        return \max(512, (int) $budget);
    }

    // ── Delegated Methods ───────────────────────────────────────────────

    public function getRequestBudget(): int
    {
        return $this->tracker->getRequestBudget();
    }

    public function getPerMessageBudget(): int
    {
        return $this->tracker->getPerMessageBudget();
    }

    public function record(int $bytes): void
    {
        $this->tracker->record($bytes);
    }

    public function consumed(): int
    {
        return $this->tracker->consumed();
    }

    public function remaining(): int
    {
        return $this->tracker->remaining();
    }

    public function isExhausted(): bool
    {
        return $this->tracker->isExhausted();
    }

    public function shouldSpill(int $bytes): bool
    {
        return $this->tracker->shouldSpill($bytes);
    }

    public function noteSpill(): void
    {
        $this->tracker->noteSpill();
    }

    public function spillCount(): int
    {
        return $this->tracker->spillCount();
    }

    public function reset(string $requestId = ''): void
    {
        // Re-resolve budgets on reset in case filters changed.
        $this->tracker = new CoreDataBudgetTracker(
            $this->resolveRequestBudget($requestId),
            $this->resolvePerMessageBudget($requestId),
            $requestId
        );
    }
}

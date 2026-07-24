<?php
/**
 * Cost Tracking Service — domain contract.
 *
 * Aggregates cost data across users, providers, models, and time periods.
 *
 * @package  Nvoos\Core
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

/**
 * Retrieves cost breakdowns for usage analysis.
 *
 * Implementations may source data from token tracking databases,
 * cost calculators, or external billing systems.
 *
 * @since 2.0.0
 */
interface CostTrackingServiceInterface
{
    /**
     * Get cost breakdown for a single user over a time period.
     *
     * @param int    $userId    User ID.
     * @param string $startDate Start date (YYYY-MM-DD).
     * @param string $endDate   End date (YYYY-MM-DD).
     *
     * @return array{total_cost: float, total_tokens: int, by_provider: array, by_model: array, by_tool: array, by_date: array}
     */
    public function getUserCostBreakdown(int $userId, string $startDate, string $endDate): array;

    /**
     * Get cost breakdown across all users over a time period.
     *
     * @param string $startDate Start date (YYYY-MM-DD).
     * @param string $endDate   End date (YYYY-MM-DD).
     *
     * @return array{total_cost: float, total_tokens: int, by_provider: array, by_model: array, by_tool: array, by_date: array, by_user: array}
     */
    public function getSiteCostBreakdown(string $startDate, string $endDate): array;
}

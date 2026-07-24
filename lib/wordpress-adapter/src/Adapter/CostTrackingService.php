<?php
/**
 * WordPress Adapter — Cost Tracking Service.
 *
 * @package  Nvoos\WordPress
 * @since    2.0.0
 * @license  GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\CostTrackingServiceInterface;

/**
 * WordPress adapter for cost tracking.
 *
 * @since 2.0.0
 */
final class CostTrackingService implements CostTrackingServiceInterface
{
    public function getUserCostBreakdown(int $userId, string $startDate, string $endDate): array
    {
        if (!\class_exists('WP_MCP_AI_Cost_Tracking_Service')) {
            return $this->emptyBreakdown();
        }

        /** @var array $result */
        $result = \WP_MCP_AI_Cost_Tracking_Service::get_user_cost_breakdown($userId, $startDate, $endDate);

        return \is_array($result) ? $result : $this->emptyBreakdown();
    }

    public function getSiteCostBreakdown(string $startDate, string $endDate): array
    {
        if (!\class_exists('WP_MCP_AI_Cost_Tracking_Service')) {
            return $this->emptyBreakdown();
        }

        /** @var array $result */
        $result = \WP_MCP_AI_Cost_Tracking_Service::get_site_cost_breakdown($startDate, $endDate);

        return \is_array($result) ? $result : $this->emptyBreakdown();
    }

    private function emptyBreakdown(): array
    {
        return [
            'total_cost'   => 0.0,
            'total_tokens' => 0,
            'by_provider'  => [],
            'by_model'     => [],
            'by_tool'      => [],
            'by_date'      => [],
            'by_user'      => [],
        ];
    }
}

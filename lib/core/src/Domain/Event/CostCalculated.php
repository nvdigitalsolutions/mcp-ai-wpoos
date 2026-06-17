<?php
/**
 * Fired when cost data is calculated for a chat response.
 *
 * Replaces: do_action('wp_mcp_ai_cost_calculated', ...)
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class CostCalculated {

	public function __construct(
		public readonly array $costData,
		public readonly int $assistantId,
		public readonly int $userId,
		public readonly array $response,
	) {}
}

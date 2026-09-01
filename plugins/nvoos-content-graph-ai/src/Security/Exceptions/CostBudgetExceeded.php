<?php
/**
 * Cost Budget Exceeded Exception for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/exceptions/class-wp-mcp-ai-cost-budget-exceeded.php`
 * (behaviour-preserving; base copy retained permanently — ecosystem port
 * plan D-NOBASE). Thrown by the CostTracker subscriber when a tool
 * execution would exceed the assistant's configured budget; caught by
 * REST handlers and converted to a WP_Error envelope (HTTP 429).
 *
 * @package NvoosContentGraphAi\Security\Exceptions
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security\Exceptions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception for cost budget violations.
 *
 * @since 1.1.0
 */
class CostBudgetExceeded extends \Exception {

	/**
	 * Assistant ID that exceeded its budget.
	 *
	 * @var int
	 */
	private $assistant_id;

	/**
	 * Constructor.
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $message      Human-readable error message.
	 */
	public function __construct( $assistant_id, $message ) {
		parent::__construct( $message, 429 );
		$this->assistant_id = $assistant_id;
	}

	/**
	 * Get the assistant ID that exceeded its budget.
	 *
	 * @return int
	 */
	public function get_assistant_id() {
		return $this->assistant_id;
	}

	/**
	 * Convert to a WP_Error suitable for REST response envelopes.
	 *
	 * @return WP_Error
	 */
	public function to_wp_error() {
		return new \WP_Error(
			'cost_budget_exceeded',
			$this->getMessage(),
			array(
				'status'       => 429,
				'assistant_id' => $this->assistant_id,
				'retry_after'  => 3600, // Budgets typically reset hourly.
			)
		);
	}
}

<?php
/**
 * Cost Budget Exceeded Exception
 *
 * Thrown by the CostTracker subscriber when a tool execution would
 * exceed the assistant's configured budget. Caught by the REST handler
 * and converted to a WP_Error envelope (HTTP 429).
 *
 * @package WP_MCP_AI
 * @since   1.1.44
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exception for cost budget violations.
 */
class WP_MCP_AI_Cost_Budget_Exceeded extends Exception {

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
		return new WP_Error(
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

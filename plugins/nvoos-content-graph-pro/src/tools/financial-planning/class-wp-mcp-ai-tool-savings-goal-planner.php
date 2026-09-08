<?php
/**
 * Financial planning tool (ecosystem port — Wave F2, financial tools batch B).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/financial-planning/class-wp-mcp-ai-tool-savings-goal-planner.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps where the
 * base references path constants (yfinance service require).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for planning and tracking savings goals.
 *
 * Supports:
 * - Multiple goal creation and tracking
 * - Progress monitoring with percentages
 * - Required contribution calculations
 * - Goal prioritization
 * - Timeline projections
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Savings_Goal_Planner implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if financial planner toolkit is enabled.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_financial_planner_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_financial_planner_toolkit'] ) ) {
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Savings goal planner tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'savings_goal_planner';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Savings Goal Planner', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Plan and track multiple savings goals. Set target amounts, deadlines, and monitor progress. Calculate required monthly contributions to reach goals on time.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'               => array(
					'type'        => 'string',
					'description' => __( 'Action to perform', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'create', 'update', 'list', 'delete', 'calculate' ),
					'default'     => 'list',
				),
				'goal_id'              => array(
					'type'        => 'string',
					'description' => __( 'Goal ID for update/delete actions', 'nvoos-content-graph-pro' ),
				),
				'goal_name'            => array(
					'type'        => 'string',
					'description' => __( 'Goal name (e.g., "Emergency Fund", "Vacation")', 'nvoos-content-graph-pro' ),
				),
				'target_amount'        => array(
					'type'        => 'number',
					'description' => __( 'Target savings amount', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'current_amount'       => array(
					'type'        => 'number',
					'description' => __( 'Current saved amount', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'target_date'          => array(
					'type'        => 'string',
					'description' => __( 'Target completion date (YYYY-MM-DD)', 'nvoos-content-graph-pro' ),
					'format'      => 'date',
				),
				'monthly_contribution' => array(
					'type'        => 'number',
					'description' => __( 'Planned monthly contribution', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'priority'             => array(
					'type'        => 'string',
					'description' => __( 'Goal priority', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'high', 'medium', 'low' ),
					'default'     => 'medium',
				),
				'category'             => array(
					'type'        => 'string',
					'description' => __( 'Goal category', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'emergency_fund', 'down_payment', 'vacation', 'education', 'retirement', 'other' ),
					'default'     => 'other',
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
			'computation',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to manage savings goals.', 'nvoos-content-graph-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'create':
				return $this->create_goal( $arguments, $current_user_id );
			case 'update':
				return $this->update_goal( $arguments, $current_user_id );
			case 'list':
				return $this->list_goals( $current_user_id );
			case 'delete':
				return $this->delete_goal( $arguments, $current_user_id );
			case 'calculate':
				return $this->calculate_goal( $arguments );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'nvoos-content-graph-pro' ) );
		}
	}

	/**
	 * Create a savings goal.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function create_goal( $arguments, $user_id ) {
		$goal_name      = isset( $arguments['goal_name'] ) ? sanitize_text_field( $arguments['goal_name'] ) : '';
		$target_amount  = isset( $arguments['target_amount'] ) ? floatval( $arguments['target_amount'] ) : 0;
		$current_amount = isset( $arguments['current_amount'] ) ? floatval( $arguments['current_amount'] ) : 0;
		$target_date    = isset( $arguments['target_date'] ) ? sanitize_text_field( $arguments['target_date'] ) : '';
		$priority       = isset( $arguments['priority'] ) ? sanitize_text_field( $arguments['priority'] ) : 'medium';
		$category       = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : 'other';

		if ( empty( $goal_name ) ) {
			return new WP_Error( 'missing_name', __( 'Goal name is required.', 'nvoos-content-graph-pro' ) );
		}

		if ( $target_amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Target amount must be greater than zero.', 'nvoos-content-graph-pro' ) );
		}

		$goals = get_user_meta( $user_id, 'wp_mcp_ai_savings_goals', true );
		if ( ! is_array( $goals ) ) {
			$goals = array();
		}

		$goal_id   = uniqid( 'goal_' );
		$remaining = $target_amount - $current_amount;
		$progress  = $target_amount > 0 ? ( $current_amount / $target_amount ) * 100 : 0;

		$months_remaining = 0;
		$required_monthly = 0;
		if ( ! empty( $target_date ) ) {
			$target_timestamp = strtotime( $target_date );
			$today_timestamp  = current_time( 'timestamp' );
			$months_remaining = max( 1, ceil( ( $target_timestamp - $today_timestamp ) / ( 30 * DAY_IN_SECONDS ) ) );
			$required_monthly = $remaining / $months_remaining;
		}

		$goals[ $goal_id ] = array(
			'id'               => $goal_id,
			'name'             => $goal_name,
			'target_amount'    => $target_amount,
			'current_amount'   => $current_amount,
			'remaining'        => $remaining,
			'progress_pct'     => round( $progress, 1 ),
			'target_date'      => $target_date,
			'months_remaining' => $months_remaining,
			'required_monthly' => round( $required_monthly, 2 ),
			'priority'         => $priority,
			'category'         => $category,
			'created_at'       => current_time( 'mysql' ),
		);

		update_user_meta( $user_id, 'wp_mcp_ai_savings_goals', $goals );

		return array(
			'success' => true,
			'goal_id' => $goal_id,
			'goal'    => $goals[ $goal_id ],
			'message' => sprintf(
				/* translators: 1: Goal name, 2: Target amount */
				__( 'Goal "%1$s" created with target of $%2$s.', 'nvoos-content-graph-pro' ),
				$goal_name,
				number_format( $target_amount, 2 )
			),
		);
	}

	/**
	 * Update a savings goal.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function update_goal( $arguments, $user_id ) {
		$goal_id        = isset( $arguments['goal_id'] ) ? sanitize_text_field( $arguments['goal_id'] ) : '';
		$current_amount = isset( $arguments['current_amount'] ) ? floatval( $arguments['current_amount'] ) : null;

		if ( empty( $goal_id ) ) {
			return new WP_Error( 'missing_id', __( 'Goal ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$goals = get_user_meta( $user_id, 'wp_mcp_ai_savings_goals', true );
		if ( ! is_array( $goals ) || ! isset( $goals[ $goal_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Goal not found.', 'nvoos-content-graph-pro' ) );
		}

		if ( null !== $current_amount ) {
			$goals[ $goal_id ]['current_amount'] = $current_amount;
			$goals[ $goal_id ]['remaining']      = $goals[ $goal_id ]['target_amount'] - $current_amount;
			$goals[ $goal_id ]['progress_pct']   = round( ( $current_amount / $goals[ $goal_id ]['target_amount'] ) * 100, 1 );
		}

		update_user_meta( $user_id, 'wp_mcp_ai_savings_goals', $goals );

		return array(
			'success' => true,
			'goal'    => $goals[ $goal_id ],
			'message' => __( 'Goal updated successfully.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * List all savings goals.
	 *
	 * @param int $user_id User ID.
	 * @return array Goals list.
	 */
	protected function list_goals( $user_id ) {
		$goals = get_user_meta( $user_id, 'wp_mcp_ai_savings_goals', true );
		if ( ! is_array( $goals ) ) {
			$goals = array();
		}

		$total_target     = array_sum( array_column( $goals, 'target_amount' ) );
		$total_saved      = array_sum( array_column( $goals, 'current_amount' ) );
		$overall_progress = $total_target > 0 ? ( $total_saved / $total_target ) * 100 : 0;

		return array(
			'success'          => true,
			'goals'            => array_values( $goals ),
			'count'            => count( $goals ),
			'total_target'     => round( $total_target, 2 ),
			'total_saved'      => round( $total_saved, 2 ),
			'overall_progress' => round( $overall_progress, 1 ),
			'message'          => sprintf(
				/* translators: %d: Number of goals */
				__( 'You have %d active savings goals.', 'nvoos-content-graph-pro' ),
				count( $goals )
			),
		);
	}

	/**
	 * Delete a savings goal.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function delete_goal( $arguments, $user_id ) {
		$goal_id = isset( $arguments['goal_id'] ) ? sanitize_text_field( $arguments['goal_id'] ) : '';

		if ( empty( $goal_id ) ) {
			return new WP_Error( 'missing_id', __( 'Goal ID is required.', 'nvoos-content-graph-pro' ) );
		}

		$goals = get_user_meta( $user_id, 'wp_mcp_ai_savings_goals', true );
		if ( ! is_array( $goals ) || ! isset( $goals[ $goal_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Goal not found.', 'nvoos-content-graph-pro' ) );
		}

		unset( $goals[ $goal_id ] );
		update_user_meta( $user_id, 'wp_mcp_ai_savings_goals', $goals );

		return array(
			'success' => true,
			'message' => __( 'Goal deleted successfully.', 'nvoos-content-graph-pro' ),
		);
	}

	/**
	 * Calculate savings goal requirements.
	 *
	 * @param array $arguments Arguments.
	 * @return array Calculation results.
	 */
	protected function calculate_goal( $arguments ) {
		$target_amount        = isset( $arguments['target_amount'] ) ? floatval( $arguments['target_amount'] ) : 0;
		$current_amount       = isset( $arguments['current_amount'] ) ? floatval( $arguments['current_amount'] ) : 0;
		$target_date          = isset( $arguments['target_date'] ) ? sanitize_text_field( $arguments['target_date'] ) : '';
		$monthly_contribution = isset( $arguments['monthly_contribution'] ) ? floatval( $arguments['monthly_contribution'] ) : 0;

		$remaining = $target_amount - $current_amount;

		$months_remaining = 0;
		if ( ! empty( $target_date ) ) {
			$target_timestamp = strtotime( $target_date );
			$today_timestamp  = current_time( 'timestamp' );
			$months_remaining = max( 1, ceil( ( $target_timestamp - $today_timestamp ) / ( 30 * DAY_IN_SECONDS ) ) );
		}

		$required_monthly = $months_remaining > 0 ? $remaining / $months_remaining : 0;
		$projected_months = $monthly_contribution > 0 ? ceil( $remaining / $monthly_contribution ) : 0;

		return array(
			'success'              => true,
			'target_amount'        => $target_amount,
			'current_amount'       => $current_amount,
			'remaining'            => round( $remaining, 2 ),
			'months_remaining'     => $months_remaining,
			'required_monthly'     => round( $required_monthly, 2 ),
			'monthly_contribution' => $monthly_contribution,
			'projected_months'     => $projected_months,
			'on_track'             => $monthly_contribution >= $required_monthly,
			'message'              => sprintf(
				/* translators: 1: Required monthly, 2: Months */
				__( 'Save $%1$s per month for %2$d months to reach goal.', 'nvoos-content-graph-pro' ),
				number_format( $required_monthly, 2 ),
				$months_remaining
			),
		);
	}
}

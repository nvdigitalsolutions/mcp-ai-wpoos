<?php
/**
 * Expense Tracker Tool
 *
 * Log and categorize expenses with optional receipt attachments
 * for detailed spending analysis.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for tracking and categorizing expenses.
 *
 * Supports:
 * - Expense logging with categories
 * - Receipt attachment tracking
 * - Date-based filtering
 * - Category-wise summaries
 * - Recurring expense detection
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Expense_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Financial planner toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Expense tracker tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'expense_tracker';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Expense Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Log and categorize expenses with receipt tracking. Track spending by category, date range, and merchant. Supports recurring expense detection and detailed spending analysis.', 'mcp-ai-wpoos-pro' );
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
				'action'       => array(
					'type'        => 'string',
					'description' => __( 'Action to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'log', 'list', 'summary', 'delete' ),
					'default'     => 'log',
				),
				'expense_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Expense ID (for delete action)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'amount'       => array(
					'type'        => 'number',
					'description' => __( 'Expense amount', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'category'     => array(
					'type'        => 'string',
					'description' => __( 'Expense category', 'mcp-ai-wpoos-pro' ),
				),
				'merchant'     => array(
					'type'        => 'string',
					'description' => __( 'Merchant or payee name', 'mcp-ai-wpoos-pro' ),
				),
				'date'         => array(
					'type'        => 'string',
					'description' => __( 'Expense date (YYYY-MM-DD format)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Expense description or notes', 'mcp-ai-wpoos-pro' ),
				),
				'receipt_url'  => array(
					'type'        => 'string',
					'description' => __( 'URL to receipt image or document', 'mcp-ai-wpoos-pro' ),
					'format'      => 'uri',
				),
				'is_recurring' => array(
					'type'        => 'boolean',
					'description' => __( 'Mark as recurring expense', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'start_date'   => array(
					'type'        => 'string',
					'description' => __( 'Start date for filtering (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'end_date'     => array(
					'type'        => 'string',
					'description' => __( 'End date for filtering (YYYY-MM-DD)', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
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
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to track expenses.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'log';

		switch ( $action ) {
			case 'log':
				return $this->log_expense( $arguments, $current_user_id );
			case 'list':
				return $this->list_expenses( $arguments, $current_user_id );
			case 'summary':
				return $this->get_summary( $arguments, $current_user_id );
			case 'delete':
				return $this->delete_expense( $arguments, $current_user_id );
			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Log an expense.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function log_expense( $arguments, $user_id ) {
		$amount       = isset( $arguments['amount'] ) ? floatval( $arguments['amount'] ) : 0;
		$category     = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';
		$merchant     = isset( $arguments['merchant'] ) ? sanitize_text_field( $arguments['merchant'] ) : '';
		$description  = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$date         = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );
		$receipt_url  = isset( $arguments['receipt_url'] ) ? esc_url_raw( $arguments['receipt_url'] ) : '';
		$is_recurring = isset( $arguments['is_recurring'] ) ? (bool) $arguments['is_recurring'] : false;

		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', __( 'Expense amount must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $category ) ) {
			return new WP_Error( 'missing_category', __( 'Expense category is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Store as user meta (in production, use custom table or CPT).
		$expenses = get_user_meta( $user_id, 'wp_mcp_ai_expenses', true );
		if ( ! is_array( $expenses ) ) {
			$expenses = array();
		}

		$expense_id              = uniqid( 'exp_' );
		$expenses[ $expense_id ] = array(
			'id'           => $expense_id,
			'amount'       => $amount,
			'category'     => $category,
			'merchant'     => $merchant,
			'description'  => $description,
			'date'         => $date,
			'receipt_url'  => $receipt_url,
			'is_recurring' => $is_recurring,
			'logged_at'    => current_time( 'mysql' ),
		);

		update_user_meta( $user_id, 'wp_mcp_ai_expenses', $expenses );

		return array(
			'success'    => true,
			'expense_id' => $expense_id,
			'expense'    => $expenses[ $expense_id ],
			'message'    => sprintf(
			/* translators: 1: Amount, 2: Category */
				__( 'Expense of $%1$s logged under %2$s.', 'mcp-ai-wpoos-pro' ),
				number_format( $amount, 2 ),
				$category
			),
		);
	}

	/**
	 * List expenses.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Expenses list.
	 */
	protected function list_expenses( $arguments, $user_id ) {
		$expenses   = get_user_meta( $user_id, 'wp_mcp_ai_expenses', true );
		$start_date = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date   = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$category   = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : '';

		if ( ! is_array( $expenses ) ) {
			$expenses = array();
		}

		// Filter expenses.
		$filtered = array();
		foreach ( $expenses as $expense ) {
			if ( ! empty( $category ) && $expense['category'] !== $category ) {
				continue;
			}

			if ( ! empty( $start_date ) && $expense['date'] < $start_date ) {
				continue;
			}

			if ( ! empty( $end_date ) && $expense['date'] > $end_date ) {
				continue;
			}

			$filtered[] = $expense;
		}

		return array(
			'success'  => true,
			'expenses' => array_values( $filtered ),
			'count'    => count( $filtered ),
			'total'    => round( array_sum( array_column( $filtered, 'amount' ) ), 2 ),
			'message'  => sprintf(
			/* translators: %d: Expense count */
				__( 'Found %d expenses.', 'mcp-ai-wpoos-pro' ),
				count( $filtered )
			),
		);
	}

	/**
	 * Get expense summary.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Summary.
	 */
	protected function get_summary( $arguments, $user_id ) {
		$result = $this->list_expenses( $arguments, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$expenses    = $result['expenses'];
		$by_category = array();

		foreach ( $expenses as $expense ) {
			$cat = $expense['category'];
			if ( ! isset( $by_category[ $cat ] ) ) {
				$by_category[ $cat ] = array(
					'count' => 0,
					'total' => 0,
				);
			}
			++$by_category[ $cat ]['count'];
			$by_category[ $cat ]['total'] += $expense['amount'];
		}

		return array(
			'success'        => true,
			'total_expenses' => $result['count'],
			'total_amount'   => $result['total'],
			'by_category'    => $by_category,
			'message'        => sprintf(
			/* translators: 1: Total amount, 2: Expense count */
				__( 'Total: $%1$s across %2$d expenses.', 'mcp-ai-wpoos-pro' ),
				number_format( $result['total'], 2 ),
				$result['count']
			),
		);
	}

	/**
	 * Delete an expense.
	 *
	 * @param array $arguments Arguments.
	 * @param int   $user_id   User ID.
	 * @return array Result.
	 */
	protected function delete_expense( $arguments, $user_id ) {
		$expense_id = isset( $arguments['expense_id'] ) ? sanitize_text_field( $arguments['expense_id'] ) : '';

		if ( empty( $expense_id ) ) {
			return new WP_Error( 'missing_id', __( 'Expense ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$expenses = get_user_meta( $user_id, 'wp_mcp_ai_expenses', true );
		if ( ! is_array( $expenses ) || ! isset( $expenses[ $expense_id ] ) ) {
			return new WP_Error( 'not_found', __( 'Expense not found.', 'mcp-ai-wpoos-pro' ) );
		}

		unset( $expenses[ $expense_id ] );
		update_user_meta( $user_id, 'wp_mcp_ai_expenses', $expenses );

		return array(
			'success' => true,
			'message' => __( 'Expense deleted successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

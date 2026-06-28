<?php
/**
 * Expense Reimbursement Tracker Tool
 *
 * Tracks case-related expenses and reimbursement status.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages expense tracking and reimbursement for legal matters.
 */
class WP_MCP_AI_Tool_LF_Expense_Reimbursement_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_expense_reimbursement_tracker'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Expense Reimbursement Tracker', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Tracks case-related expenses including filing fees, expert witnesses, travel, and marks reimbursement status.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Expense action.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'list', 'mark_reimbursed' ),
				),
				'matter_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Matter ID.', 'mcp-ai-wpoos-pro' ),
				),
				'expense_type'     => array(
					'type'        => 'string',
					'description' => __( 'Type of expense.', 'mcp-ai-wpoos-pro' ),
				),
				'amount'           => array(
					'type'        => 'number',
					'description' => __( 'Expense amount.', 'mcp-ai-wpoos-pro' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Expense description.', 'mcp-ai-wpoos-pro' ),
				),
				'date'             => array(
					'type'        => 'string',
					'description' => __( 'Expense date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'receipt_attached' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether a receipt is attached.', 'mcp-ai-wpoos-pro' ),
				),
				'expense_id'       => array(
					'type'        => 'string',
					'description' => __( 'Expense ID (for mark_reimbursed).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'action', 'matter_id' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$expenses = get_post_meta( $matter_id, '_lf_expenses', true );
		if ( ! is_array( $expenses ) ) {
			$expenses = array();
		}

		switch ( $action ) {
			case 'add':
				$amount = isset( $arguments['amount'] ) ? floatval( $arguments['amount'] ) : 0;
				if ( $amount <= 0 ) {
					return new WP_Error( 'invalid_param', __( 'Amount must be greater than zero.', 'mcp-ai-wpoos-pro' ) );
				}
				$expense_id = 'exp_' . wp_generate_uuid4();
				$expense    = array(
					'id'               => $expense_id,
					'type'             => isset( $arguments['expense_type'] ) ? sanitize_text_field( $arguments['expense_type'] ) : '',
					'amount'           => $amount,
					'description'      => isset( $arguments['description'] ) ? sanitize_text_field( $arguments['description'] ) : '',
					'date'             => isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' ),
					'receipt_attached' => ! empty( $arguments['receipt_attached'] ),
					'reimbursed'       => false,
					'added_by'         => $uid,
					'added_at'         => current_time( 'Y-m-d H:i:s' ),
				);
				$expenses[] = $expense;
				update_post_meta( $matter_id, '_lf_expenses', $expenses );

				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %s: expense amount */
						__( 'Expense of $%s recorded. ', 'mcp-ai-wpoos-pro' ),
						number_format( $amount, 2 )
					) . self::DISCLAIMER,
					'data'       => array(
						'expense_id' => $expense_id,
						'expense'    => $expense,
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'list':
				$total_pending    = 0;
				$total_reimbursed = 0;
				foreach ( $expenses as $exp ) {
					if ( ! empty( $exp['reimbursed'] ) ) {
						$total_reimbursed += (float) ( $exp['amount'] ?? 0 );
					} else {
						$total_pending += (float) ( $exp['amount'] ?? 0 );
					}
				}
				return array(
					'success'    => true,
					'message'    => sprintf(
						/* translators: %1$d: number of expenses, %2$s: total pending amount */
						__( '%1$d expenses tracked. Pending: $%2$s. ', 'mcp-ai-wpoos-pro' ),
						count( $expenses ),
						number_format( $total_pending, 2 )
					) . self::DISCLAIMER,
					'data'       => array(
						'expenses'         => $expenses,
						'total_pending'    => round( $total_pending, 2 ),
						'total_reimbursed' => round( $total_reimbursed, 2 ),
					),
					'disclaimer' => self::DISCLAIMER,
				);

			case 'mark_reimbursed':
				$expense_id = isset( $arguments['expense_id'] ) ? sanitize_text_field( $arguments['expense_id'] ) : '';
				$found      = false;
				foreach ( $expenses as &$exp ) {
					if ( ( $exp['id'] ?? '' ) === $expense_id ) {
						$exp['reimbursed']    = true;
						$exp['reimbursed_at'] = current_time( 'Y-m-d H:i:s' );
						$found                = true;
						break;
					}
				}
				unset( $exp );

				if ( ! $found ) {
					return new WP_Error( 'not_found', __( 'Expense not found.', 'mcp-ai-wpoos-pro' ) );
				}

				update_post_meta( $matter_id, '_lf_expenses', $expenses );
				return array(
					'success'    => true,
					'message'    => __( 'Expense marked as reimbursed. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
					'data'       => array( 'expense_id' => $expense_id ),
					'disclaimer' => self::DISCLAIMER,
				);

			default:
				return new WP_Error( 'invalid_action', __( 'Invalid action.', 'mcp-ai-wpoos-pro' ) );
		}
	}
}

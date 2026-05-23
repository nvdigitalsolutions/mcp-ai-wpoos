<?php
/**
 * Invoice Generator Tool
 *
 * Generates invoices from time entries with optional LEDES format.
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
 * Generates invoices from recorded time entries for a matter.
 */
class WP_MCP_AI_Tool_LF_Invoice_Generator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_invoice_generator'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Invoice Generator', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generates invoices from time entries for a matter with optional LEDES format and expense inclusion.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Matter ID.', 'mcp-ai-wpoos-pro' ),
				),
				'date_from'        => array(
					'type'        => 'string',
					'description' => __( 'Start date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'date_to'          => array(
					'type'        => 'string',
					'description' => __( 'End date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'format'           => array(
					'type'        => 'string',
					'description' => __( 'Invoice format.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'standard', 'ledes' ),
				),
				'include_expenses' => array(
					'type'        => 'boolean',
					'description' => __( 'Include expenses.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'matter_id' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

		$matter_id        = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$date_from        = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to          = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : current_time( 'Y-m-d' );
		$format           = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'standard';
		$include_expenses = isset( $arguments['include_expenses'] ) ? (bool) $arguments['include_expenses'] : true;

		if ( ! $matter_id ) {
			return new WP_Error( 'missing_required', __( 'Matter ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$meta_query = array(
			array(
				'key'     => '_lf_matter_id',
				'value'   => $matter_id,
				'compare' => '=',
			),
			array(
				'key'     => '_lf_billing_type',
				'value'   => 'billable',
				'compare' => '=',
			),
		);
		if ( $date_from ) {
			$meta_query[] = array(
				'key'     => '_lf_date',
				'value'   => $date_from,
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}
		$meta_query[] = array(
			'key'     => '_lf_date',
			'value'   => $date_to,
			'compare' => '<=',
			'type'    => 'DATE',
		);

		$entries = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => 500,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$line_items  = array();
		$total_hours = 0;
		$total_fees  = 0;
		$invoice_num = 'INV-' . strtoupper( substr( md5( $matter_id . current_time( 'U' ) ), 0, 8 ) );

		foreach ( $entries as $entry ) {
			$hours  = (float) get_post_meta( $entry->ID, '_lf_hours', true );
			$rate   = (float) get_post_meta( $entry->ID, '_lf_rate', true );
			$amount = (float) get_post_meta( $entry->ID, '_lf_amount', true );
			$date   = get_post_meta( $entry->ID, '_lf_date', true );

			$total_hours += $hours;
			$total_fees  += $amount;

			$item = array(
				'date'        => $date,
				'description' => $entry->post_content,
				'hours'       => $hours,
				'rate'        => $rate,
				'amount'      => $amount,
			);

			if ( 'ledes' === $format ) {
				$item['ledes_line'] = WP_MCP_AI_Law_Firm_Calculator::format_ledes_line(
					array(
						'invoice_date'   => $date_to,
						'invoice_number' => $invoice_num,
						'matter_id'      => $matter_id,
						'hours'          => $hours,
						'rate'           => $rate,
						'amount'         => $amount,
						'description'    => $entry->post_content,
					)
				);
			}

			$line_items[] = $item;
		}

		$total_expenses = 0;
		$expense_items  = array();
		if ( $include_expenses ) {
			$expenses = get_post_meta( $matter_id, '_lf_expenses', true );
			if ( is_array( $expenses ) ) {
				foreach ( $expenses as $exp ) {
					$amt             = (float) ( $exp['amount'] ?? 0 );
					$total_expenses += $amt;
					$expense_items[] = $exp;
				}
			}
		}

		return array(
			'success'    => true,
			'message'    => sprintf( __( 'Invoice %s generated: %s total. ', 'mcp-ai-wpoos-pro' ), $invoice_num, WP_MCP_AI_Law_Firm_Calculator::format_currency( $total_fees + $total_expenses ) ) . self::DISCLAIMER,
			'data'       => array(
				'invoice_number' => $invoice_num,
				'matter_id'      => $matter_id,
				'date_from'      => $date_from,
				'date_to'        => $date_to,
				'format'         => $format,
				'line_items'     => $line_items,
				'expense_items'  => $expense_items,
				'total_hours'    => round( $total_hours, 1 ),
				'total_fees'     => round( $total_fees, 2 ),
				'total_expenses' => round( $total_expenses, 2 ),
				'grand_total'    => round( $total_fees + $total_expenses, 2 ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

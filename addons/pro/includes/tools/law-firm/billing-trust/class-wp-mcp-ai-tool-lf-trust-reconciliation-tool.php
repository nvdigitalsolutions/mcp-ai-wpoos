<?php
/**
 * Trust Reconciliation Tool
 *
 * Performs three-way trust account reconciliation.
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
 * Performs three-way reconciliation of trust accounts.
 */
class WP_MCP_AI_Tool_LF_Trust_Reconciliation_Tool implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_trust_reconciliation_tool'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Trust Reconciliation Tool', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Performs three-way reconciliation of trust accounts comparing bank balance, book balance, and client ledger totals.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'bank_balance' => array(
					'type'        => 'number',
					'description' => __( 'Bank statement balance.', 'mcp-ai-wpoos-pro' ),
				),
				'as_of_date'   => array(
					'type'        => 'string',
					'description' => __( 'Reconciliation date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'matter_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Optional: reconcile a single matter.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'bank_balance' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

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

		$bank_balance = isset( $arguments['bank_balance'] ) ? floatval( $arguments['bank_balance'] ) : 0;
		$as_of_date   = isset( $arguments['as_of_date'] ) ? sanitize_text_field( $arguments['as_of_date'] ) : current_time( 'Y-m-d' );
		$matter_id    = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		$query_args = array(
			'post_type'      => 'mcp_ai_lf_trust_txn',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_trust_reconciliation_tool', 0, 1000 ) : 1000,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
		'key'     => '_lf_date',
		'value'   => $as_of_date,
		'compare' => '<=',
		'type'    => 'DATE',
			),
			),
		);

		if ( $matter_id ) {
			$query_args['meta_query'][] = array(
				'key'   => '_lf_matter_id',
				'value' => $matter_id,
			);
		}

		$txns = get_posts( $query_args );

		$transactions  = array();
		$client_totals = array();
		foreach ( $txns as $txn ) {
			$type           = get_post_meta( $txn->ID, '_lf_txn_type', true );
			$amt            = (float) get_post_meta( $txn->ID, '_lf_amount', true );
			$mid            = get_post_meta( $txn->ID, '_lf_matter_id', true );
			$transactions[] = array(
				'type'   => $type,
				'amount' => $amt,
			);

			if ( ! isset( $client_totals[ $mid ] ) ) {
				$client_totals[ $mid ] = 0;
			}
			$client_totals[ $mid ] += ( 'deposit' === $type ) ? $amt : -$amt;
		}

		$calc         = WP_MCP_AI_Law_Firm_Calculator::calculate_trust_balance( $transactions );
		$book_balance = $calc['balance'];
		$client_total = round( array_sum( $client_totals ), 2 );

		$reconciliation = WP_MCP_AI_Law_Firm_Calculator::three_way_reconciliation( $bank_balance, $book_balance, $client_total );

		return array(
			'success'    => true,
			'message'    => ( $reconciliation['is_reconciled'] ? __( 'Trust account is reconciled. ', 'mcp-ai-wpoos-pro' ) : __( 'Trust account has discrepancies. ', 'mcp-ai-wpoos-pro' ) ) . self::DISCLAIMER,
			'data'       => array(
				'as_of_date'     => $as_of_date,
				'is_reconciled'  => $reconciliation['is_reconciled'],
				'bank_balance'   => $reconciliation['bank_balance'],
				'book_balance'   => $reconciliation['book_balance'],
				'client_total'   => $reconciliation['client_total'],
				'discrepancy'    => $reconciliation['discrepancy'],
				'client_details' => $client_totals,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

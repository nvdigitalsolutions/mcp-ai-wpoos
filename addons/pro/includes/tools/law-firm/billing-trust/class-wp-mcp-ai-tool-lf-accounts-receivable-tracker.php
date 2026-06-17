<?php
/**
 * Accounts Receivable Tracker Tool
 *
 * Tracks outstanding invoices and aging buckets for legal billing.
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
 * Tracks accounts receivable with aging analysis.
 */
class WP_MCP_AI_Tool_LF_Accounts_Receivable_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_accounts_receivable_tracker'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Accounts Receivable Tracker', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Tracks outstanding invoices with aging bucket analysis (0-30, 31-60, 61-90, 90+ days) and collection rate metrics.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'practice_area'   => array(
					'type'        => 'string',
					'description' => __( 'Filter by practice area.', 'mcp-ai-wpoos-pro' ),
				),
				'aging_threshold' => array(
					'type'        => 'integer',
					'description' => __( 'Days threshold for aging (default 30).', 'mcp-ai-wpoos-pro' ),
				),
				'matter_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Filter by specific matter.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
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

		$matter_id = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;

		$meta_query = array(
			array(
				'key'   => '_lf_billing_type',
				'value' => 'billable',
			),
		);
		if ( $matter_id ) {
			$meta_query[] = array(
				'key'   => '_lf_matter_id',
				'value' => $matter_id,
			);
		}

		$entries = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => 1000,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$now     = current_time( 'U' );
		$buckets = array(
			'0_30'    => 0,
			'31_60'   => 0,
			'61_90'   => 0,
			'90_plus' => 0,
		);
		$total   = 0;

		foreach ( $entries as $entry ) {
			$amount = (float) get_post_meta( $entry->ID, '_lf_amount', true );
			$date   = get_post_meta( $entry->ID, '_lf_date', true );
			$paid   = get_post_meta( $entry->ID, '_lf_paid', true );

			if ( $paid ) {
				continue;
			}

			$days   = $date ? (int) floor( ( $now - strtotime( $date ) ) / DAY_IN_SECONDS ) : 0;
			$total += $amount;

			if ( $days <= 30 ) {
				$buckets['0_30'] += $amount;
			} elseif ( $days <= 60 ) {
				$buckets['31_60'] += $amount;
			} elseif ( $days <= 90 ) {
				$buckets['61_90'] += $amount;
			} else {
				$buckets['90_plus'] += $amount;
			}
		}

		$total_billed = 0;
		foreach ( $entries as $entry ) {
			$total_billed += (float) get_post_meta( $entry->ID, '_lf_amount', true );
		}
		$collected       = $total_billed - $total;
		$collection_rate = $total_billed > 0 ? round( ( $collected / $total_billed ) * 100, 1 ) : 100;

		$buckets = array_map(
			function ( $v ) {
				return round( $v, 2 );
			},
			$buckets
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %1$s: total outstanding amount, %2$s: collection rate percentage */
				__( 'Total outstanding: $%1$s. Collection rate: %2$s%%. ', 'mcp-ai-wpoos-pro' ),
				number_format( $total, 2 ),
				$collection_rate
			) . self::DISCLAIMER,
			'data'       => array(
				'aging_buckets'     => $buckets,
				'total_outstanding' => round( $total, 2 ),
				'total_billed'      => round( $total_billed, 2 ),
				'total_collected'   => round( $collected, 2 ),
				'collection_rate'   => $collection_rate,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

<?php
/**
 * Firm Performance Dashboard Tool
 *
 * Aggregates key performance indicators across the firm including
 * realization rate, collection rate, utilization, and revenue metrics.
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
 * Aggregates firm-wide KPIs: realization, collection, utilization, and revenue metrics.
 */
class WP_MCP_AI_Tool_LF_Firm_Performance_Dashboard implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_firm_performance_dashboard'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Firm Performance Dashboard', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generates firm-wide KPIs including realization rate, collection rate, utilization rate, revenue per lawyer, and matters per attorney for a given period.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period'        => array(
					'type'        => 'string',
					'enum'        => array( 'month', 'quarter', 'year' ),
					'description' => __( 'Reporting period (default: quarter).', 'mcp-ai-wpoos-pro' ),
				),
				'practice_area' => array(
					'type'        => 'string',
					'description' => __( 'Filter by practice area (optional).', 'mcp-ai-wpoos-pro' ),
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

		require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

		$period        = isset( $arguments['period'] ) ? sanitize_text_field( $arguments['period'] ) : 'quarter';
		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';

		$allowed_periods = array( 'month', 'quarter', 'year' );
		if ( ! in_array( $period, $allowed_periods, true ) ) {
			$period = 'quarter';
		}

		// Determine date range.
		$now        = current_time( 'Y-m-d' );
		$start_date = '';
		switch ( $period ) {
			case 'month':
				$start_date = gmdate( 'Y-m-01' );
				break;
			case 'quarter':
				$current_month = (int) gmdate( 'n' );
				$quarter_start = (int) ( floor( ( $current_month - 1 ) / 3 ) * 3 + 1 );
				$start_date    = gmdate( 'Y' ) . '-' . str_pad( $quarter_start, 2, '0', STR_PAD_LEFT ) . '-01';
				break;
			case 'year':
				$start_date = gmdate( 'Y-01-01' );
				break;
		}

		// Query time entries in the period.
		$entry_meta_query = array(
			array(
				'key'     => '_lf_entry_date',
				'value'   => $start_date,
				'compare' => '>=',
				'type'    => 'DATE',
			),
		);

		if ( ! empty( $practice_area ) ) {
			$entry_meta_query[] = array(
				'key'     => '_lf_practice_area',
				'value'   => $practice_area,
				'compare' => 'LIKE',
			);
		}

		$entries = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_time_entry',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_firm_performance_dashboard', 0, 1000 ) : 1000,
				'post_status'    => 'publish',
				'meta_query'     => $entry_meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$total_billable_hours     = 0;
		$total_non_billable_hours = 0;
		$total_billed_amount      = 0;
		$total_collected_amount   = 0;
		$total_standard_amount    = 0;
		$attorneys                = array();

		foreach ( $entries as $entry ) {
			$hours        = (float) get_post_meta( $entry->ID, '_lf_hours', true );
			$rate         = (float) get_post_meta( $entry->ID, '_lf_rate', true );
			$amount       = (float) get_post_meta( $entry->ID, '_lf_amount', true );
			$billing_type = get_post_meta( $entry->ID, '_lf_billing_type', true );
			$collected    = (float) get_post_meta( $entry->ID, '_lf_collected_amount', true );
			$author_id    = $entry->post_author;

			$standard_amount        = $hours * $rate;
			$total_standard_amount += $standard_amount;

			if ( 'billable' === $billing_type ) {
				$total_billable_hours += $hours;
				$total_billed_amount  += $amount;
			} else {
				$total_non_billable_hours += $hours;
			}

			$total_collected_amount += $collected;

			if ( ! isset( $attorneys[ $author_id ] ) ) {
				$attorneys[ $author_id ] = array(
					'billable_hours'     => 0,
					'non_billable_hours' => 0,
					'revenue'            => 0,
					'matter_ids'         => array(),
				);
			}
			$attorneys[ $author_id ]['billable_hours']     += ( 'billable' === $billing_type ) ? $hours : 0;
			$attorneys[ $author_id ]['non_billable_hours'] += ( 'billable' !== $billing_type ) ? $hours : 0;
			$attorneys[ $author_id ]['revenue']            += $amount;

			$matter_id = get_post_meta( $entry->ID, '_lf_matter_id', true );
			if ( $matter_id && ! in_array( $matter_id, $attorneys[ $author_id ]['matter_ids'], true ) ) {
				$attorneys[ $author_id ]['matter_ids'][] = $matter_id;
			}
		}

		// Query matters for the period.
		$matter_args = array(
			'post_type'      => 'mcp_ai_lf_matter',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_firm_performance_dashboard', 0, 1000 ) : 1000,
			'post_status'    => 'publish',
			'date_query'     => array(
				array( 'after' => $start_date ),
			),
		);

		if ( ! empty( $practice_area ) ) {
			$matter_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_lf_practice_area',
					'value'   => $practice_area,
					'compare' => 'LIKE',
				),
			);
		}

		$matters       = get_posts( $matter_args );
		$total_matters = count( $matters );

		// Query trust transactions in the period.
		$trust_txns = get_posts(
			array(
				'post_type'      => 'mcp_ai_lf_trust_txn',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_firm_performance_dashboard', 0, 1000 ) : 1000,
				'post_status'    => 'publish',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_lf_transaction_date',
					'value'   => $start_date,
					'compare' => '>=',
					'type'    => 'DATE',
				),
				),
			)
		);

		$trust_balance_total = 0;
		foreach ( $trust_txns as $txn ) {
			$txn_amount = (float) get_post_meta( $txn->ID, '_lf_amount', true );
			$txn_type   = get_post_meta( $txn->ID, '_lf_transaction_type', true );
			if ( 'deposit' === $txn_type ) {
				$trust_balance_total += $txn_amount;
			} else {
				$trust_balance_total -= $txn_amount;
			}
		}

		// Calculate KPIs.
		$attorney_count       = max( count( $attorneys ), 1 );
		$total_hours          = $total_billable_hours + $total_non_billable_hours;
		$realization_rate     = $total_standard_amount > 0 ? round( ( $total_billed_amount / $total_standard_amount ) * 100, 1 ) : 0;
		$collection_rate      = $total_billed_amount > 0 ? round( ( $total_collected_amount / $total_billed_amount ) * 100, 1 ) : 0;
		$utilization_rate     = $total_hours > 0 ? round( ( $total_billable_hours / $total_hours ) * 100, 1 ) : 0;
		$revenue_per_lawyer   = round( $total_billed_amount / $attorney_count, 2 );
		$matters_per_attorney = round( $total_matters / $attorney_count, 1 );

		// Per-attorney breakdown.
		$attorney_breakdown = array();
		foreach ( $attorneys as $atty_id => $atty_data ) {
			$user                 = get_userdata( $atty_id );
			$atty_total_hours     = $atty_data['billable_hours'] + $atty_data['non_billable_hours'];
			$attorney_breakdown[] = array(
				'attorney_id'      => $atty_id,
				'name'             => $user ? $user->display_name : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
				'billable_hours'   => round( $atty_data['billable_hours'], 1 ),
				'utilization_rate' => $atty_total_hours > 0 ? round( ( $atty_data['billable_hours'] / $atty_total_hours ) * 100, 1 ) : 0,
				'revenue'          => round( $atty_data['revenue'], 2 ),
				'matter_count'     => count( $atty_data['matter_ids'] ),
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: period, 2: realization rate, 3: utilization rate */
				__( 'Dashboard for %1$s: %2$s%% realization, %3$s%% utilization across %4$d attorneys. ', 'mcp-ai-wpoos-pro' ),
				$period,
				$realization_rate,
				$utilization_rate,
				$attorney_count
			) . self::DISCLAIMER,
			'data'       => array(
				'period'             => $period,
				'practice_area'      => $practice_area,
				'date_range'         => array(
					'start' => $start_date,
					'end'   => $now,
				),
				'kpis'               => array(
					'realization_rate'     => $realization_rate,
					'collection_rate'      => $collection_rate,
					'utilization_rate'     => $utilization_rate,
					'revenue_per_lawyer'   => $revenue_per_lawyer,
					'matters_per_attorney' => $matters_per_attorney,
				),
				'totals'             => array(
					'total_revenue'        => round( $total_billed_amount, 2 ),
					'total_collected'      => round( $total_collected_amount, 2 ),
					'total_billable_hours' => round( $total_billable_hours, 1 ),
					'total_matters'        => $total_matters,
					'attorney_count'       => $attorney_count,
					'trust_balance'        => round( $trust_balance_total, 2 ),
				),
				'attorney_breakdown' => $attorney_breakdown,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

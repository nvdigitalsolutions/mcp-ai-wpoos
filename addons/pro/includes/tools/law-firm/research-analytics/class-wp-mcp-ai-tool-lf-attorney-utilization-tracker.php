<?php
/**
 * Attorney Utilization Tracker Tool
 *
 * Tracks attorney time utilization against targets with billable
 * and non-billable breakdowns.
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
 * Tracks attorney billable and non-billable hours against utilization targets.
 */
class WP_MCP_AI_Tool_LF_Attorney_Utilization_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_attorney_utilization_tracker'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Attorney Utilization Tracker', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Tracks attorney time utilization against targets including billable hours, non-billable hours, utilization rate, and target variance.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'attorney_id'  => array(
					'type'        => 'integer',
					'description' => __( 'WordPress user ID of the attorney. If omitted, tracks all attorneys.', 'mcp-ai-wpoos-pro' ),
				),
				'period'       => array(
					'type'        => 'string',
					'enum'        => array( 'week', 'month', 'quarter', 'year' ),
					'description' => __( 'Reporting period (default: month).', 'mcp-ai-wpoos-pro' ),
				),
				'target_hours' => array(
					'type'        => 'number',
					'description' => __( 'Target billable hours for the period (default: 160).', 'mcp-ai-wpoos-pro' ),
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

		$attorney_id  = isset( $arguments['attorney_id'] ) ? absint( $arguments['attorney_id'] ) : 0;
		$period       = isset( $arguments['period'] ) ? sanitize_text_field( $arguments['period'] ) : 'month';
		$target_hours = isset( $arguments['target_hours'] ) ? floatval( $arguments['target_hours'] ) : 160;

		$allowed_periods = array( 'week', 'month', 'quarter', 'year' );
		if ( ! in_array( $period, $allowed_periods, true ) ) {
			$period = 'month';
		}

		if ( $target_hours <= 0 ) {
			$target_hours = 160;
		}

		// Determine date range.
		$now        = current_time( 'Y-m-d' );
		$start_date = '';
		switch ( $period ) {
			case 'week':
				$start_date = gmdate( 'Y-m-d', strtotime( 'monday this week' ) );
				break;
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

		// Build query for time entries.
		$meta_query = array(
			array(
				'key'     => '_lf_entry_date',
				'value'   => $start_date,
				'compare' => '>=',
				'type'    => 'DATE',
			),
		);

		$query_args = array(
			'post_type'      => 'mcp_ai_lf_time_entry',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_attorney_utilization_tracker', 0, 1000 ) : 1000,
			'post_status'    => 'publish',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);

		if ( $attorney_id > 0 ) {
			$query_args['author'] = $attorney_id;
		}

		$entries   = get_posts( $query_args );
		$attorneys = array();

		foreach ( $entries as $entry ) {
			$author_id    = $entry->post_author;
			$hours        = (float) get_post_meta( $entry->ID, '_lf_hours', true );
			$billing_type = get_post_meta( $entry->ID, '_lf_billing_type', true );
			$entry_date   = get_post_meta( $entry->ID, '_lf_entry_date', true );

			if ( ! isset( $attorneys[ $author_id ] ) ) {
				$user                    = get_userdata( $author_id );
				$attorneys[ $author_id ] = array(
					'attorney_id'        => $author_id,
					'name'               => $user ? $user->display_name : __( 'Unknown', 'mcp-ai-wpoos-pro' ),
					'billable_hours'     => 0,
					'non_billable_hours' => 0,
					'daily_breakdown'    => array(),
				);
			}

			if ( 'billable' === $billing_type ) {
				$attorneys[ $author_id ]['billable_hours'] += $hours;
			} else {
				$attorneys[ $author_id ]['non_billable_hours'] += $hours;
			}

			// Daily tracking.
			$day_key = ! empty( $entry_date ) ? $entry_date : $entry->post_date;
			$day_key = substr( $day_key, 0, 10 );
			if ( ! isset( $attorneys[ $author_id ]['daily_breakdown'][ $day_key ] ) ) {
				$attorneys[ $author_id ]['daily_breakdown'][ $day_key ] = 0;
			}
			$attorneys[ $author_id ]['daily_breakdown'][ $day_key ] += $hours;
		}

		// Calculate metrics for each attorney.
		$attorney_results = array();
		$total_billable   = 0;
		$total_non_bill   = 0;

		// Compute business days elapsed for daily rate calculation.
		$days_elapsed  = max( 1, (int) ( ( strtotime( $now ) - strtotime( $start_date ) ) / DAY_IN_SECONDS ) );
		$business_days = 0;
		$check_date    = new DateTime( $start_date );
		$end_dt        = new DateTime( $now );
		while ( $check_date <= $end_dt ) {
			$dow = (int) $check_date->format( 'N' );
			if ( $dow <= 5 ) {
				++$business_days;
			}
			$check_date->modify( '+1 day' );
		}
		$business_days = max( 1, $business_days );

		foreach ( $attorneys as $atty ) {
			$billable     = round( $atty['billable_hours'], 1 );
			$non_billable = round( $atty['non_billable_hours'], 1 );
			$total        = $billable + $non_billable;
			$util_rate    = $total > 0 ? round( ( $billable / $total ) * 100, 1 ) : 0;
			$variance     = round( $billable - $target_hours, 1 );
			$daily_avg    = round( $billable / $business_days, 1 );

			// Required daily pace to meet target.
			$remaining_biz_days = $business_days - min( $business_days, $days_elapsed );
			$remaining_hours    = max( 0, $target_hours - $billable );
			$required_daily     = $remaining_biz_days > 0 ? round( $remaining_hours / $remaining_biz_days, 1 ) : 0;

			$status = 'on_track';
			if ( $billable >= $target_hours ) {
				$status = 'target_met';
			} elseif ( $required_daily > 10 ) {
				$status = 'at_risk';
			}

			$attorney_results[] = array(
				'attorney_id'        => $atty['attorney_id'],
				'name'               => $atty['name'],
				'billable_hours'     => $billable,
				'non_billable_hours' => $non_billable,
				'total_hours'        => round( $total, 1 ),
				'utilization_rate'   => $util_rate,
				'target_hours'       => $target_hours,
				'target_variance'    => $variance,
				'daily_average'      => $daily_avg,
				'required_daily'     => $required_daily,
				'status'             => $status,
			);

			$total_billable += $billable;
			$total_non_bill += $non_billable;
		}

		$atty_count      = max( count( $attorney_results ), 1 );
		$avg_utilization = ( $total_billable + $total_non_bill ) > 0 ? round( ( $total_billable / ( $total_billable + $total_non_bill ) ) * 100, 1 ) : 0;

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: attorney count, 2: avg utilization, 3: period */
				__( 'Utilization tracked for %1$d attorney(s): %2$s%% average utilization for %3$s. ', 'mcp-ai-wpoos-pro' ),
				$atty_count,
				$avg_utilization,
				$period
			) . self::DISCLAIMER,
			'data'       => array(
				'period'        => $period,
				'date_range'    => array(
					'start' => $start_date,
					'end'   => $now,
				),
				'target_hours'  => $target_hours,
				'business_days' => $business_days,
				'summary'       => array(
					'total_billable_hours'     => round( $total_billable, 1 ),
					'total_non_billable_hours' => round( $total_non_bill, 1 ),
					'avg_utilization_rate'     => $avg_utilization,
					'attorney_count'           => $atty_count,
				),
				'attorneys'     => $attorney_results,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

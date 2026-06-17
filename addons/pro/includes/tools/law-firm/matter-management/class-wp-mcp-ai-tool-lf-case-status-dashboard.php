<?php
/**
 * Case Status Dashboard Tool
 *
 * Provides an aggregated dashboard view of case statuses, counts, and upcoming deadlines.
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
 * Generates aggregated case status dashboard data.
 */
class WP_MCP_AI_Tool_LF_Case_Status_Dashboard implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
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
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_case_status_dashboard';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Case Status Dashboard', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Provides an aggregated dashboard of case statuses across the firm, with counts by status and practice area, upcoming deadlines, and summary statistics. Filterable by practice area, attorney, status, and date range.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'practice_area' => array(
					'type'        => 'string',
					'description' => __( 'Filter by practice area.', 'mcp-ai-wpoos-pro' ),
				),
				'attorney_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Filter by assigned attorney (WordPress user ID).', 'mcp-ai-wpoos-pro' ),
				),
				'status_filter' => array(
					'type'        => 'string',
					'description' => __( 'Filter by matter status.', 'mcp-ai-wpoos-pro' ),
				),
				'date_range'    => array(
					'type'        => 'string',
					'description' => __( 'Date range for dashboard data.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'week', 'month', 'quarter', 'year' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';
		$attorney_id   = isset( $arguments['attorney_id'] ) ? absint( $arguments['attorney_id'] ) : 0;
		$status_filter = isset( $arguments['status_filter'] ) ? sanitize_text_field( $arguments['status_filter'] ) : '';
		$date_range    = isset( $arguments['date_range'] ) ? sanitize_text_field( $arguments['date_range'] ) : 'month';

		$valid_ranges = array( 'week', 'month', 'quarter', 'year' );
		if ( ! in_array( $date_range, $valid_ranges, true ) ) {
			$date_range = 'month';
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_lf_matter',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_case_status_dashboard', 0, 1000 ) : 1000,
		);

		$meta_query = array();
		if ( $practice_area ) {
			$meta_query[] = array(
				'key'   => '_lf_practice_area',
				'value' => $practice_area,
			);
		}
		if ( $status_filter ) {
			$meta_query[] = array(
				'key'   => '_lf_status',
				'value' => $status_filter,
			);
		}
		if ( $attorney_id ) {
			$query_args['author'] = $attorney_id;
		}
		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );

		// Aggregate by status.
		$by_status              = array();
		$by_area                = array();
		$all_upcoming_deadlines = array();

		// Determine deadline cutoff based on date range.
		$now = current_time( 'timestamp' );
		switch ( $date_range ) {
			case 'week':
				$cutoff = gmdate( 'Y-m-d', strtotime( '+1 week', $now ) );
				break;
			case 'quarter':
				$cutoff = gmdate( 'Y-m-d', strtotime( '+3 months', $now ) );
				break;
			case 'year':
				$cutoff = gmdate( 'Y-m-d', strtotime( '+1 year', $now ) );
				break;
			default:
				$cutoff = gmdate( 'Y-m-d', strtotime( '+1 month', $now ) );
				break;
		}

		$today = current_time( 'Y-m-d' );

		foreach ( $query->posts as $post ) {
			$status = get_post_meta( $post->ID, '_lf_status', true );
			$area   = get_post_meta( $post->ID, '_lf_practice_area', true );

			$status = $status ? $status : 'unknown';
			$area   = $area ? $area : 'unspecified';

			if ( ! isset( $by_status[ $status ] ) ) {
				$by_status[ $status ] = 0;
			}
			++$by_status[ $status ];

			if ( ! isset( $by_area[ $area ] ) ) {
				$by_area[ $area ] = 0;
			}
			++$by_area[ $area ];

			// Collect upcoming deadlines.
			$deadlines = get_post_meta( $post->ID, '_lf_deadlines', true );
			if ( is_array( $deadlines ) ) {
				foreach ( $deadlines as $dl ) {
					$dl_date = $dl['date'] ?? '';
					if ( ! empty( $dl_date ) && $dl_date >= $today && $dl_date <= $cutoff && empty( $dl['completed'] ) ) {
						$all_upcoming_deadlines[] = array(
							'matter_id'    => $post->ID,
							'matter_title' => $post->post_title,
							'description'  => $dl['description'] ?? '',
							'date'         => $dl_date,
							'priority'     => $dl['priority'] ?? 'medium',
						);
					}
				}
			}
		}
		wp_reset_postdata();

		// Sort deadlines by date.
		usort(
			$all_upcoming_deadlines,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		// Limit to 20 upcoming deadlines.
		$all_upcoming_deadlines = array_slice( $all_upcoming_deadlines, 0, 20 );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: total matters, 2: upcoming deadline count */
				__( 'Dashboard: %1$d matters, %2$d upcoming deadlines. ', 'mcp-ai-wpoos-pro' ),
				$query->found_posts,
				count( $all_upcoming_deadlines )
			) . self::DISCLAIMER,
			'data'       => array(
				'total_matters'      => $query->found_posts,
				'by_status'          => $by_status,
				'by_practice_area'   => $by_area,
				'upcoming_deadlines' => $all_upcoming_deadlines,
				'deadline_count'     => count( $all_upcoming_deadlines ),
				'date_range'         => $date_range,
				'filters_applied'    => array(
					'practice_area' => $practice_area ? $practice_area : 'all',
					'attorney_id'   => $attorney_id ? $attorney_id : 'all',
					'status'        => $status_filter ? $status_filter : 'all',
				),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

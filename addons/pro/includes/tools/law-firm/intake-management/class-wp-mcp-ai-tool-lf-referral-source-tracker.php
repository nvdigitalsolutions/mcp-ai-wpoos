<?php
/**
 * Referral Source Tracker Tool
 *
 * Tracks and analyzes client referral sources for business development insights.
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
 * Tracks and analyzes referral sources across clients.
 */
class WP_MCP_AI_Tool_LF_Referral_Source_Tracker implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_referral_source_tracker';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Referral Source Tracker', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Analyzes client referral sources over a specified period, optionally filtered by practice area, to provide business development insights.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period'        => array(
					'type'        => 'string',
					'description' => __( 'Time period for analysis.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'month', 'quarter', 'year' ),
					'default'     => 'quarter',
				),
				'practice_area' => array(
					'type'        => 'string',
					'description' => __( 'Optional practice area filter.', 'mcp-ai-wpoos-pro' ),
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
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$period        = isset( $arguments['period'] ) ? sanitize_text_field( $arguments['period'] ) : 'quarter';
		$practice_area = isset( $arguments['practice_area'] ) ? sanitize_text_field( $arguments['practice_area'] ) : '';

		$valid_periods = array( 'month', 'quarter', 'year' );
		if ( ! in_array( $period, $valid_periods, true ) ) {
			$period = 'quarter';
		}

		// Determine date range.
		$now = current_time( 'timestamp' );
		switch ( $period ) {
			case 'month':
				$since = gmdate( 'Y-m-d', strtotime( '-1 month', $now ) );
				break;
			case 'year':
				$since = gmdate( 'Y-m-d', strtotime( '-1 year', $now ) );
				break;
			default:
				$since = gmdate( 'Y-m-d', strtotime( '-3 months', $now ) );
				break;
		}

		$query_args = array(
			'post_type'      => 'mcp_ai_lf_client',
			'post_status'    => 'publish',
			'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_referral_source_tracker', 0, 1000 ) : 1000,
			'date_query'     => array(
				array(
					'after'     => $since,
					'inclusive' => true,
				),
			),
		);

		if ( $practice_area ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_lf_practice_area',
					'value' => $practice_area,
				),
			);
		}

		$clients = new WP_Query( $query_args );
		$sources = array();

		if ( $clients->have_posts() ) {
			foreach ( $clients->posts as $client ) {
				$source = get_post_meta( $client->ID, '_lf_referral_source', true );
				$source = $source ? $source : 'unknown';
				if ( ! isset( $sources[ $source ] ) ) {
					$sources[ $source ] = 0;
				}
				++$sources[ $source ];
			}
		}
		wp_reset_postdata();

		// Sort by count descending.
		arsort( $sources );

		$total            = array_sum( $sources );
		$referral_sources = array();
		foreach ( $sources as $source_name => $count ) {
			$referral_sources[] = array(
				'source'     => $source_name,
				'count'      => $count,
				'percentage' => $total > 0 ? round( ( $count / $total ) * 100, 1 ) : 0,
			);
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: total clients, 2: period */
				__( 'Referral analysis complete: %1$d clients in the last %2$s. ', 'mcp-ai-wpoos-pro' ),
				$total,
				$period
			) . self::DISCLAIMER,
			'data'       => array(
				'referral_sources' => $referral_sources,
				'total_clients'    => $total,
				'period'           => $period,
				'since_date'       => $since,
				'practice_area'    => $practice_area ? $practice_area : 'all',
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

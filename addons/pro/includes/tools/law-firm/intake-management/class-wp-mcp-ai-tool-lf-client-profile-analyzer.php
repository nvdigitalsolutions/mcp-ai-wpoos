<?php
/**
 * Client Profile Analyzer Tool
 *
 * Analyzes a client profile by aggregating data from their record, associated matters,
 * and time entries.
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
 * Analyzes client profile data for a comprehensive summary.
 */
class WP_MCP_AI_Tool_LF_Client_Profile_Analyzer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_client_profile_analyzer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Client Profile Analyzer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Analyzes a client profile by gathering data from their record, associated matters, communications, and time entries to provide a comprehensive summary.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'client_id' => array(
					'type'        => 'integer',
					'description' => __( 'The ID of the client record to analyze.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'client_id' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
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

		$client_id = isset( $arguments['client_id'] ) ? absint( $arguments['client_id'] ) : 0;
		if ( ! $client_id ) {
			return new WP_Error( 'missing_required', __( 'Client ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$client_post = get_post( $client_id );
		if ( ! $client_post || 'mcp_ai_lf_client' !== $client_post->post_type ) {
			return new WP_Error( 'not_found', __( 'Client record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$profile = array(
			'client_id'       => $client_id,
			'name'            => $client_post->post_title,
			'description'     => $client_post->post_content,
			'email'           => get_post_meta( $client_id, '_lf_email', true ),
			'phone'           => get_post_meta( $client_id, '_lf_phone', true ),
			'practice_area'   => get_post_meta( $client_id, '_lf_practice_area', true ),
			'urgency'         => get_post_meta( $client_id, '_lf_urgency', true ),
			'referral_source' => get_post_meta( $client_id, '_lf_referral_source', true ),
			'intake_date'     => get_post_meta( $client_id, '_lf_intake_date', true ),
			'status'          => get_post_meta( $client_id, '_lf_status', true ),
		);

		// Count associated matters.
		$matters_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_lf_matter',
				'post_status'    => 'publish',
				'meta_key'       => '_lf_client_id',
				'meta_value'     => $client_id,
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'lf_client_profile_analyzer', 0, 1000 ) : 1000,
				'fields'         => 'ids',
			)
		);
		$matter_ids    = $matters_query->posts;
		$matter_count  = count( $matter_ids );
		wp_reset_postdata();

		// Gather matter summaries.
		$matters = array();
		foreach ( $matter_ids as $mid ) {
			$matters[] = array(
				'matter_id'     => $mid,
				'title'         => get_the_title( $mid ),
				'status'        => get_post_meta( $mid, '_lf_status', true ),
				'practice_area' => get_post_meta( $mid, '_lf_practice_area', true ),
			);
		}

		// Communications count.
		$communications = get_post_meta( $client_id, '_lf_communications', true );
		$comm_count     = is_array( $communications ) ? count( $communications ) : 0;

		// Time entry count across matters.
		$total_time_entries = 0;
		$total_hours        = 0.0;
		foreach ( $matter_ids as $mid ) {
			$time_entries = get_post_meta( $mid, '_lf_time_entries', true );
			if ( is_array( $time_entries ) ) {
				$total_time_entries += count( $time_entries );
				foreach ( $time_entries as $entry ) {
					$total_hours += (float) ( $entry['hours'] ?? 0 );
				}
			}
		}

		return array(
			'success'    => true,
			'message'    => __( 'Client profile analysis complete. ', 'mcp-ai-wpoos-pro' ) . self::DISCLAIMER,
			'data'       => array(
				'profile'             => $profile,
				'matters'             => $matters,
				'matter_count'        => $matter_count,
				'communication_count' => $comm_count,
				'total_time_entries'  => $total_time_entries,
				'total_hours_billed'  => round( $total_hours, 1 ),
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}

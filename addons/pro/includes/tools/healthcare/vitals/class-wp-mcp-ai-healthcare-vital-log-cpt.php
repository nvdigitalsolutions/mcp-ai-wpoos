<?php
/**
 * Vital Log custom post type.
 *
 * Promotes vital-sign measurements to a first-class CPT (`mcp_ai_hc_vital_log`)
 * so they can be queried via WP_Query, exported to FHIR Observation
 * resources, and surfaced through the standard admin/REST plumbing.
 *
 * Existing log_vital_signs storage (options + JetEngine CCT) continues to
 * work unchanged.  This CPT is auxiliary — it is only registered when the
 * `enable_medical_vitals` toggle is on (which by default mirrors the value
 * of `enable_health_wellness_management` for backwards compatibility).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `mcp_ai_hc_vital_log` CPT registration.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_Healthcare_Vital_Log_CPT {

	/**
	 * CPT slug.
	 */
	const POST_TYPE = 'mcp_ai_hc_vital_log';

	/**
	 * Boot the CPT registration on `init`.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 12 );
	}

	/**
	 * Register the CPT.
	 *
	 * @return void
	 */
	public static function register() {
		// Respect sub-toolkit toggle.
		if ( class_exists( 'WP_MCP_AI_Healthcare_Engine' )
			&& ! WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'vitals' )
		) {
			return;
		}

		$labels = array(
			'name'          => __( 'Vital Logs', 'mcp-ai-wpoos-pro' ),
			'singular_name' => __( 'Vital Log', 'mcp-ai-wpoos-pro' ),
			'menu_name'     => __( 'Vital Logs', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'              => $labels,
			'public'              => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'supports'            => array( 'title', 'author', 'custom-fields' ),
			'exclude_from_search' => true,
		);

		/**
		 * Filter the Vital Log CPT registration arguments.
		 *
		 * @param array $args Arguments passed to register_post_type().
		 */
		$args = apply_filters( 'wp_mcp_ai_healthcare_vital_log_cpt_args', $args );

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Insert a vital log entry as a CPT row.
	 *
	 * @param int   $member_id Member post ID.
	 * @param array $payload   Sanitised measurements payload (any shape supported by log_vital_signs).
	 * @return int|WP_Error New post ID, or 0 if CPT not registered, or WP_Error on insert failure.
	 */
	public static function insert( $member_id, array $payload ) {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return 0;
		}
		$member_id = absint( $member_id );
		if ( $member_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'A valid member_id is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$measured = isset( $payload['measurement_date'] ) ? sanitize_text_field( $payload['measurement_date'] ) : current_time( 'Y-m-d' );
		$title    = sprintf( 'Vital Log #%d %s', $member_id, $measured );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_member_id', $member_id );
		update_post_meta( $post_id, '_measurement_date', $measured );
		update_post_meta( $post_id, '_payload', wp_json_encode( $payload ) );

		return (int) $post_id;
	}
}

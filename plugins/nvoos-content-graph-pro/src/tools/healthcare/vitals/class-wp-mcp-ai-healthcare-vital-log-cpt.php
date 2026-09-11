<?php
/**
 * includes/tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vital-log-cpt.php (ecosystem port — Wave F4, healthcare toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/vitals/class-wp-mcp-ai-healthcare-vital-log-cpt.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in monolith installs — the
 * addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path
 * (the `defined( 'WP_MCP_AI_PRO_VERSION' )` base-mode gates stay byte-identical).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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
			'name'          => __( 'Vital Logs', 'nvoos-content-graph-pro' ),
			'singular_name' => __( 'Vital Log', 'nvoos-content-graph-pro' ),
			'menu_name'     => __( 'Vital Logs', 'nvoos-content-graph-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'A valid member_id is required.', 'nvoos-content-graph-pro' ) );
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

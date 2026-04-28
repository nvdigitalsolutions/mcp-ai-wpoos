<?php
/**
 * QMS ↔ PARA Cross-Bridge.
 *
 * - When a controlled document transitions to obsolete, also move it to the
 *   PARA Archives bucket (if PARA is enabled).
 * - When a controlled document is released and is linked to an Area (via
 *   `_qms_linked_area_id`), update that Area's `_para_last_reviewed`
 *   timestamp.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge.
 */
class WP_MCP_AI_QMS_PARA_Bridge {

	/**
	 * Initialize.
	 */
	public static function init() {
		add_action( 'wp_mcp_ai_qms_after_state_transition', array( __CLASS__, 'on_state_transition' ), 10, 4 );
	}

	/**
	 * React to state transitions.
	 *
	 * @param int    $post_id    Record ID.
	 * @param string $from_state From.
	 * @param string $to_state   To.
	 * @param array  $context    Context.
	 */
	public static function on_state_transition( $post_id, $from_state, $to_state, $context ) {
		unset( $from_state, $context );

		// Obsolete documents → PARA archives.
		if ( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_OBSOLETE === $to_state ) {
			if ( class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) && WP_MCP_AI_PARA_Taxonomy::is_enabled() ) {
				WP_MCP_AI_PARA_Taxonomy::assign(
					$post_id,
					'archives',
					__( 'QMS document marked obsolete.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Released → bump linked Area's last_reviewed timestamp.
		if ( WP_MCP_AI_QMS_Doc_Record_CPT::STATUS_RELEASED === $to_state ) {
			$linked_area = (int) get_post_meta( $post_id, '_qms_linked_area_id', true );
			if ( $linked_area ) {
				$area = get_post( $linked_area );
				if ( $area && class_exists( 'WP_MCP_AI_PARA_Area_CPT' ) && WP_MCP_AI_PARA_Area_CPT::POST_TYPE === $area->post_type ) {
					update_post_meta( $linked_area, '_para_last_reviewed', current_time( 'mysql', true ) );
				}
			}
		}
	}
}

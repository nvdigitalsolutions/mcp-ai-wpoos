<?php
/**
 * WP_MCP_AI_QMS_PARA_Bridge (ecosystem port - Wave F2, document-generation QMS/admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/qms/class-wp-mcp-ai-qms-para-bridge.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is
 * defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants, so no path swaps.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





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
					__( 'QMS document marked obsolete.', 'nvoos-content-graph-pro' )
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

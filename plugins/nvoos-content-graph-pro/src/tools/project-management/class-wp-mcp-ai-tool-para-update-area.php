<?php
/**
 * PM tool (ecosystem port — Wave F2, PM PARA + capture-decision batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/project-management/class-wp-mcp-ai-tool-para-update-area.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain\n * `nvoos-content-graph-pro`; no path constants — no path swaps.
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
 * Update an existing Area.
 */
class WP_MCP_AI_Tool_PARA_Update_Area implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'para_update_area';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'PARA: Update Area', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Update an existing PARA Area. Only provided fields are changed. Set `mark_reviewed` to true to update the last-reviewed timestamp to now.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'area_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'title'          => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'description'    => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'standard'       => array(
					'type'      => 'string',
					'maxLength' => 1000,
				),
				'owner_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'review_cadence' => array(
					'type' => 'string',
					'enum' => array( 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually' ),
				),
				'mark_reviewed'  => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'area_id' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing' );
	}

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WP_MCP_AI_PARA_Taxonomy' ) && WP_MCP_AI_PARA_Taxonomy::is_enabled();
	}

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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$area_id = isset( $arguments['area_id'] ) ? absint( $arguments['area_id'] ) : 0;
		$post    = $area_id ? get_post( $area_id ) : null;
		if ( ! $post || WP_MCP_AI_PARA_Area_CPT::POST_TYPE !== $post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_area', __( 'Area not found.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! user_can( $user_id, 'edit_post', $area_id ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You cannot edit this area.', 'nvoos-content-graph-pro' ) );
		}

		$update = array( 'ID' => $area_id );
		if ( isset( $arguments['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $arguments['title'] );
		}
		if ( isset( $arguments['description'] ) ) {
			$update['post_content'] = wp_kses_post( $arguments['description'] );
		}
		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		if ( isset( $arguments['standard'] ) ) {
			update_post_meta( $area_id, '_para_standard', sanitize_textarea_field( $arguments['standard'] ) );
		}
		if ( isset( $arguments['owner_id'] ) ) {
			$owner = absint( $arguments['owner_id'] );
			if ( $owner && ! get_user_by( 'id', $owner ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_owner', __( 'Owner does not exist.', 'nvoos-content-graph-pro' ) );
			}
			update_post_meta( $area_id, '_para_owner', $owner );
		}
		if ( isset( $arguments['review_cadence'] ) ) {
			$cadence = sanitize_key( $arguments['review_cadence'] );
			$valid   = array( 'weekly', 'biweekly', 'monthly', 'quarterly', 'annually' );
			if ( in_array( $cadence, $valid, true ) ) {
				update_post_meta( $area_id, '_para_review_cadence', $cadence );
			}
		}
		if ( ! empty( $arguments['mark_reviewed'] ) ) {
			update_post_meta( $area_id, '_para_last_reviewed', current_time( 'mysql', true ) );
		}

		return array(
			'success' => true,
			'area_id' => $area_id,
			'data'    => WP_MCP_AI_PARA_Area_CPT::get_area( $area_id ),
			'message' => __( 'Area updated.', 'nvoos-content-graph-pro' ),
		);
	}
}

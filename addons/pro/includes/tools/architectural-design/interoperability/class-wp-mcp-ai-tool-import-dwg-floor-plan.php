<?php
/**
 * Tool — Import DWG Floor Plan.
 *
 * The base plugin cannot parse binary DWG files directly (they require
 * proprietary libraries such as the Open Design Alliance Teigha SDK). This
 * tool accepts a JSON floor-plan payload produced by an external converter
 * and validates / normalises it into the toolkit's canonical floor-plan
 * structure — the same structure consumed by the Phase A/B/C tools.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Import DWG floor plan.
 */
class WP_MCP_AI_Tool_Import_Dwg_Floor_Plan implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */

	// phpcs:ignore Squiz.Commenting.FunctionComment.WrongStyle
	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'import_dwg_floor_plan';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Import DWG Floor Plan', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Validate and normalise a JSON floor-plan payload produced by an external DWG converter (e.g. ODA Teigha, LibreDWG) into the toolkit canonical structure. Reports referential errors and synonym remappings (rooms→spaces, doors+windows→openings).', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'payload'      => array(
					'type'        => 'object',
					'description' => __( 'JSON floor-plan payload from an external DWG converter.', 'mcp-ai-wpoos-pro' ),
				),
				'source_label' => array(
					'type'        => 'string',
					'description' => __( 'Human-readable source for traceability (e.g. "site-plan-RevC.dwg").', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'payload' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'cacheable' );
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import floor plans.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $arguments['payload'] ) || ! is_array( $arguments['payload'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'payload is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Interop' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural interop engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}
		$source_label = isset( $arguments['source_label'] ) ? sanitize_text_field( $arguments['source_label'] ) : '';

		$result                 = WP_MCP_AI_Architectural_Interop::normalize_floor_plan( $arguments['payload'] );
		$result['source']       = 'dwg-json';
		$result['source_label'] = $source_label;
		$result['note']         = __( 'Binary DWG parsing is not supported; convert to JSON externally first.', 'mcp-ai-wpoos-pro' );
		return $result;
	}
}

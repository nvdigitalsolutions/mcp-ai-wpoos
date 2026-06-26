<?php
/**
 * Tool — Import IFC Model.
 *
 * Accepts a simplified-IFC JSON payload (the output of `ifcopenshell` or a
 * comparable converter, expressed as project / spaces / walls / openings)
 * and normalises it into the toolkit's canonical floor-plan structure plus a
 * counts-summary to help the LLM reason about the model.
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
 * Import IFC model.
 */
class WP_MCP_AI_Tool_Import_Ifc_Model implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'import_ifc_model';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Import IFC Model', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Normalise a simplified-IFC JSON payload (project, levels, spaces, walls, openings) into the toolkit canonical floor-plan structure. Returns a model summary (storey + space + wall + opening counts and total floor area). Binary IFC STEP / IFCXML parsing must be done externally; pipe the JSON output here.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'JSON model payload extracted from an IFC file.', 'mcp-ai-wpoos-pro' ),
				),
				'source_label' => array(
					'type'        => 'string',
					'description' => __( 'Source file name for traceability.', 'mcp-ai-wpoos-pro' ),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import IFC models.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $arguments['payload'] ) || ! is_array( $arguments['payload'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'payload is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Interop' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural interop engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$result     = WP_MCP_AI_Architectural_Interop::normalize_floor_plan( $arguments['payload'] );
		$plan       = isset( $result['payload'] ) ? $result['payload'] : array();
		$total_area = 0.0;
		foreach ( (array) ( isset( $plan['spaces'] ) ? $plan['spaces'] : array() ) as $space ) {
			$total_area += isset( $space['area_m2'] ) ? (float) $space['area_m2'] : 0.0;
		}
		$result['source']       = 'ifc-json';
		$result['source_label'] = isset( $arguments['source_label'] ) ? sanitize_text_field( $arguments['source_label'] ) : '';
		$result['summary']      = array(
			'levels_count'   => count( isset( $plan['levels'] ) ? $plan['levels'] : array() ),
			'spaces_count'   => count( isset( $plan['spaces'] ) ? $plan['spaces'] : array() ),
			'walls_count'    => count( isset( $plan['walls'] ) ? $plan['walls'] : array() ),
			'openings_count' => count( isset( $plan['openings'] ) ? $plan['openings'] : array() ),
			'total_area_m2'  => round( $total_area, 2 ),
		);
		return $result;
	}
}

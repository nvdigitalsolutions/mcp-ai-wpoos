<?php
/**
 * Tool — Export to gbXML.
 *
 * Emits a gbXML 6.01 XML document from a normalised floor plan, suitable for
 * import into EnergyPlus / OpenStudio for whole-building energy modelling.
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
 * Export to gbXML.
 */
class WP_MCP_AI_Tool_Export_To_Gbxml implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'export_to_gbxml';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Export to gbXML', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate a gbXML 6.01 XML document from a normalised floor plan. Output is a valid gbXML body for import into EnergyPlus / OpenStudio for whole-building energy modelling.', 'mcp-ai-wpoos-pro' );
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
				'floor_plan'   => array(
					'type'        => 'object',
					'description' => __( 'Normalised floor-plan structure.', 'mcp-ai-wpoos-pro' ),
				),
				'author'       => array( 'type' => 'string' ),
				'organization' => array( 'type' => 'string' ),
			),
			'required'             => array( 'floor_plan' ),
			'additionalProperties' => false,
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only' );
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to export to gbXML.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( empty( $arguments['floor_plan'] ) || ! is_array( $arguments['floor_plan'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'floor_plan is required.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Interop' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural interop engine is unavailable.', 'mcp-ai-wpoos-pro' ) );
		}

		$normalized = WP_MCP_AI_Architectural_Interop::normalize_floor_plan( $arguments['floor_plan'] );
		if ( empty( $normalized['success'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_floor_plan',
				__( 'Floor plan is not normalisable.', 'mcp-ai-wpoos-pro' ),
				array( 'errors' => $normalized['errors'] )
			);
		}

		$author       = isset( $arguments['author'] ) ? sanitize_text_field( $arguments['author'] ) : 'NV oOS';
		$organization = isset( $arguments['organization'] ) ? sanitize_text_field( $arguments['organization'] ) : 'NV Digital Solutions';
		$xml          = WP_MCP_AI_Architectural_Interop::build_gbxml( $normalized['payload'], $author, $organization );

		return array(
			'success'    => true,
			'format'     => 'gbXML 6.01',
			'media_type' => 'application/xml',
			'filename'   => sanitize_file_name( ( $normalized['payload']['project']['name'] ? $normalized['payload']['project']['name'] : 'project' ) . '.xml' ),
			'xml'        => $xml,
			'byte_size'  => strlen( $xml ),
			'note'       => __( 'Geometry summary only. Add surfaces / constructions in EnergyPlus / OpenStudio.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

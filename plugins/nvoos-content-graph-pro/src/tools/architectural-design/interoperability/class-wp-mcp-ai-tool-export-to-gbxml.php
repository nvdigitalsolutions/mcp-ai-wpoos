<?php
/**
 * Tool_Export_To_Gbxml (ecosystem port - Wave F2, architectural-design tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architectural-design/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * per-file seams — the base-owned interface/Logger/media-url-utils requires gain exists-check seams
 * resolving from the addon's D8-compat `src/` copies, the response/subprocess traits are
 * wave-proof-guarded, the openai/gemini client requires stay monolith-gated, and the
 * `WP_MCP_AI_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface require gains an
// exists-check seam resolving from the addon's D8-compat copy.
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}


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
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
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
		return __( 'Export to gbXML', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate a gbXML 6.01 XML document from a normalised floor plan. Output is a valid gbXML body for import into EnergyPlus / OpenStudio for whole-building energy modelling.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Normalised floor-plan structure.', 'nvoos-content-graph-pro' ),
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
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to export to gbXML.', 'nvoos-content-graph-pro' ) );
		}
		if ( empty( $arguments['floor_plan'] ) || ! is_array( $arguments['floor_plan'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'floor_plan is required.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Interop' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural interop engine is unavailable.', 'nvoos-content-graph-pro' ) );
		}

		$normalized = WP_MCP_AI_Architectural_Interop::normalize_floor_plan( $arguments['floor_plan'] );
		if ( empty( $normalized['success'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_floor_plan',
				__( 'Floor plan is not normalisable.', 'nvoos-content-graph-pro' ),
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
			'note'       => __( 'Geometry summary only. Add surfaces / constructions in EnergyPlus / OpenStudio.', 'nvoos-content-graph-pro' ),
		);
	}
}

<?php
/**
 * Tool_Import_Ifc_Model (ecosystem port - Wave F2, architectural-design tool batch).
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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
		return 'import_ifc_model';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Import IFC Model', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Normalise a simplified-IFC JSON payload (project, levels, spaces, walls, openings) into the toolkit canonical floor-plan structure. Returns a model summary (storey + space + wall + opening counts and total floor area). Binary IFC STEP / IFCXML parsing must be done externally; pipe the JSON output here.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'JSON model payload extracted from an IFC file.', 'nvoos-content-graph-pro' ),
				),
				'source_label' => array(
					'type'        => 'string',
					'description' => __( 'Source file name for traceability.', 'nvoos-content-graph-pro' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to import IFC models.', 'nvoos-content-graph-pro' ) );
		}
		if ( empty( $arguments['payload'] ) || ! is_array( $arguments['payload'] ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_arguments', __( 'payload is required.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Architectural_Interop' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Architectural interop engine is unavailable.', 'nvoos-content-graph-pro' ) );
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

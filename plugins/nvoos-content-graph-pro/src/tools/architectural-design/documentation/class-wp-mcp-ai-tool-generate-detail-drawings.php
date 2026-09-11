<?php
/**
 * Tool_Generate_Detail_Drawings (ecosystem port - Wave F2, architectural-design tool batch).
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

// Load the WP_MCP_AI_Tool_Image_Response trait (wave-proof-guarded — the root classmap serves the base copy in
// the monorepo test matrix, so an unguarded require would double-declare).
if ( ! trait_exists( 'WP_MCP_AI_Tool_Image_Response' ) && defined( 'WP_MCP_AI_PATH' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-image-response.php';
}
if ( ! trait_exists( 'WP_MCP_AI_Tool_Image_Response' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/trait-wp-mcp-ai-tool-image-response.php';
}


/**
 * Generate detail drawings.
 */
class WP_MCP_AI_Tool_Generate_Detail_Drawings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/* WP_MCP_AI_AVAILABILITY_BLOCK */
	/**
	 * Whether this tool is available for registration.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when the Architectural Design toolkit is enabled
	 *              and the host plugin is not running in base mode.
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_architectural_design_toolkit'] );
	}

	/**
	 * Reason this tool is unavailable, if any.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'Architectural Design toolkit is not enabled.', 'nvoos-content-graph-pro' );
	}

	use WP_MCP_AI_Tool_Image_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_detail_drawings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Detail Drawings', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create construction detail sheets for specific building components. Includes close-up views, assembly instructions, and material specifications.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'component_type'         => array(
					'type'        => 'string',
					'description' => __( 'Component type: "wall_section", "foundation", "roof_detail", "window", "door", "stair".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'wall_section', 'foundation', 'roof_detail', 'window', 'door', 'stair' ),
				),
				'specifications'         => array(
					'type'        => 'object',
					'description' => __( 'Component specifications and materials.', 'nvoos-content-graph-pro' ),
				),
				'scale'                  => array(
					'type'        => 'string',
					'description' => __( 'Detail scale: "1/2", "1", "3", "6" (inches per foot).', 'nvoos-content-graph-pro' ),
					'enum'        => array( '1/2', '1', '3', '6' ),
					'default'     => '3',
				),
				'include_materials_list' => array(
					'type'        => 'boolean',
					'description' => __( 'Include materials and parts list.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'include_notes'          => array(
					'type'        => 'boolean',
					'description' => __( 'Include installation notes and instructions.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'component_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'write',
			'consumes-tokens',
			'external-api',
			'model-dependent',
		);
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
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate detail drawings.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate component type.
		if ( empty( $arguments['component_type'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Component type is required.', 'nvoos-content-graph-pro' )
			);
		}

		$component_type         = sanitize_text_field( $arguments['component_type'] );
		$specifications         = isset( $arguments['specifications'] ) ? (array) $arguments['specifications'] : array();
		$scale                  = isset( $arguments['scale'] ) ? sanitize_text_field( $arguments['scale'] ) : '3';
		$include_materials_list = isset( $arguments['include_materials_list'] ) ? (bool) $arguments['include_materials_list'] : true;
		$include_notes          = isset( $arguments['include_notes'] ) ? (bool) $arguments['include_notes'] : true;

		// Generate detail drawing.
		$detail = $this->generate_detail( $component_type, $specifications, $scale, $include_materials_list, $include_notes, $context );

		if ( is_wp_error( $detail ) ) {
			return $detail;
		}

		// Return structured detail data.
		$result = array(
			'success'        => true,
			'url'            => isset( $detail['image_url'] ) ? $detail['image_url'] : '',
			'prompt'         => sprintf( '%s detail drawing at %s scale', str_replace( '_', ' ', $component_type ), $scale ),
			'detail'         => $detail,
			'component_type' => $component_type,
			'scale'          => $scale,
			'text'           => sprintf(
				/* translators: %s: component type */
				__( 'Successfully generated %s detail drawing.', 'nvoos-content-graph-pro' ),
				str_replace( '_', ' ', $component_type )
			),
		);

		return $this->add_image_html_to_response( $result );
	}

	/**
	 * Generate detail drawing.
	 *
	 * @param string $component_type         Component type.
	 * @param array  $specifications         Specifications.
	 * @param string $scale                  Scale.
	 * @param bool   $include_materials_list Include materials list.
	 * @param bool   $include_notes          Include notes.
	 * @param array  $context                Execution context.
	 * @return array Detail drawing data.
	 */
	protected function generate_detail( $component_type, $specifications, $scale, $include_materials_list, $include_notes, $context ) {
		return array(
			'type'      => $component_type,
			'scale'     => $scale,
			'format'    => 'pdf',
			'views'     => array( 'section', 'elevation', 'plan' ),
			'materials' => $include_materials_list ? array() : null,
			'notes'     => $include_notes ? array() : null,
			'metadata'  => array(
				'specifications' => $specifications,
				'generated_at'   => current_time( 'mysql' ),
			),
		);
	}
}

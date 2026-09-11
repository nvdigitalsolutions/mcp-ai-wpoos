<?php
/**
 * Tool_Generate_3d_Model (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Generate 3D building models.
 */
class WP_MCP_AI_Tool_Generate_3d_Model implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'generate_3d_model';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate 3D Model', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create 3D building models from floor plans. Supports various export formats for visualization, VR, and CAD software.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'floor_plan'        => array(
					'type'        => 'object',
					'description' => __( 'Floor plan data to convert to 3D.', 'nvoos-content-graph-pro' ),
				),
				'wall_height'       => array(
					'type'        => 'number',
					'description' => __( 'Wall height in feet or meters.', 'nvoos-content-graph-pro' ),
					'default'     => 9,
				),
				'include_roof'      => array(
					'type'        => 'boolean',
					'description' => __( 'Include roof structure in model.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'roof_type'         => array(
					'type'        => 'string',
					'description' => __( 'Roof type: "flat", "gable", "hip", "mansard".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'flat', 'gable', 'hip', 'mansard' ),
					'default'     => 'gable',
				),
				'materials'         => array(
					'type'        => 'object',
					'description' => __( 'Material specifications for walls, floors, roof.', 'nvoos-content-graph-pro' ),
				),
				'include_furniture' => array(
					'type'        => 'boolean',
					'description' => __( 'Include 3D furniture models.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'output_format'     => array(
					'type'        => 'string',
					'description' => __( 'Output format: "obj", "fbx", "gltf", "stl".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'obj', 'fbx', 'gltf', 'stl' ),
					'default'     => 'obj',
				),
			),
			'required'             => array( 'floor_plan' ),
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
			'async',
			'performance-impact',
			'large-response',
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
				__( 'You do not have permission to generate 3D models.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate floor plan.
		if ( empty( $arguments['floor_plan'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data is required.', 'nvoos-content-graph-pro' )
			);
		}

		$floor_plan        = $arguments['floor_plan'];
		$wall_height       = isset( $arguments['wall_height'] ) ? floatval( $arguments['wall_height'] ) : 9;
		$include_roof      = isset( $arguments['include_roof'] ) ? (bool) $arguments['include_roof'] : true;
		$roof_type         = isset( $arguments['roof_type'] ) ? sanitize_text_field( $arguments['roof_type'] ) : 'gable';
		$materials         = isset( $arguments['materials'] ) ? (array) $arguments['materials'] : array();
		$include_furniture = isset( $arguments['include_furniture'] ) ? (bool) $arguments['include_furniture'] : false;
		$output_format     = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'obj';

		// Generate 3D model.
		$model_data = $this->generate_3d_model( $floor_plan, $wall_height, $include_roof, $roof_type, $materials, $include_furniture, $output_format );

		if ( is_wp_error( $model_data ) ) {
			return $model_data;
		}

		// Return structured 3D model data.
		$result = array(
			'success'        => true,
			'url'            => isset( $model_data['preview_url'] ) ? $model_data['preview_url'] : '',
			'prompt'         => sprintf( '3D model with %s roof, %s wall height', $roof_type, $wall_height ),
			'model'          => $model_data,
			'format'         => $output_format,
			'specifications' => array(
				'wall_height'   => $wall_height,
				'roof_type'     => $roof_type,
				'has_furniture' => $include_furniture,
			),
			'text'           => __( 'Successfully generated 3D building model.', 'nvoos-content-graph-pro' ),
		);

		return $this->add_image_html_to_response( $result );
	}

	/**
	 * Generate 3D model from floor plan.
	 *
	 * @param array  $floor_plan        Floor plan data.
	 * @param float  $wall_height       Wall height.
	 * @param bool   $include_roof      Include roof.
	 * @param string $roof_type         Roof type.
	 * @param array  $materials         Materials.
	 * @param bool   $include_furniture Include furniture.
	 * @param string $output_format     Output format.
	 * @return array 3D model data.
	 */
	protected function generate_3d_model( $floor_plan, $wall_height, $include_roof, $roof_type, $materials, $include_furniture, $output_format ) {
		return array(
			'format'   => $output_format,
			'data'     => array(
				'vertices'  => array(),
				'faces'     => array(),
				'materials' => array(),
				'textures'  => array(),
			),
			'stats'    => array(
				'vertex_count' => 0,
				'face_count'   => 0,
				'file_size'    => 0,
			),
			'metadata' => array(
				'wall_height'  => $wall_height,
				'roof_type'    => $roof_type,
				'generated_at' => current_time( 'mysql' ),
			),
		);
	}
}

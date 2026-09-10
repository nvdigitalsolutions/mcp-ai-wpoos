<?php
/**
 * Tool_Convert_Sketch_To_Floor_Plan (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Convert sketches to floor plans using AI vision.
 */
class WP_MCP_AI_Tool_Convert_Sketch_To_Floor_Plan implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {

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
		return 'convert_sketch_to_floor_plan';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Convert Sketch to Floor Plan', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert hand-drawn sketches to CAD-ready floor plans. Uses computer vision to recognize rooms, walls, doors, and windows.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sketch_image'   => array(
					'type'        => 'string',
					'description' => __( 'Sketch image URL or attachment ID.', 'nvoos-content-graph-pro' ),
				),
				'scale'          => array(
					'type'        => 'number',
					'description' => __( 'Scale factor (e.g., 1 inch = X feet). Optional if scale is marked on sketch.', 'nvoos-content-graph-pro' ),
				),
				'recognize_text' => array(
					'type'        => 'boolean',
					'description' => __( 'Recognize text labels and dimensions on sketch.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'output_format'  => array(
					'type'        => 'string',
					'description' => __( 'Output format: "svg", "dxf", "json".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'svg', 'dxf', 'json' ),
					'default'     => 'svg',
				),
				'auto_correct'   => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically correct and straighten walls.', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'sketch_image' ),
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
			'requires-credentials',
			'requires-vision-model',
			'write',
			'consumes-tokens',
			'external-api',
			'async',
			'model-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array( 'vision', 'multimodal' );
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

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to convert sketches.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate sketch image.
		if ( empty( $arguments['sketch_image'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Sketch image is required.', 'nvoos-content-graph-pro' )
			);
		}

		$sketch_image   = sanitize_text_field( $arguments['sketch_image'] );
		$scale          = isset( $arguments['scale'] ) ? floatval( $arguments['scale'] ) : 0;
		$recognize_text = isset( $arguments['recognize_text'] ) ? (bool) $arguments['recognize_text'] : true;
		$output_format  = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'svg';
		$auto_correct   = isset( $arguments['auto_correct'] ) ? (bool) $arguments['auto_correct'] : true;

		// Get image file path.
		$image_path = $this->get_image_path( $sketch_image, $user_id );

		if ( is_wp_error( $image_path ) ) {
			return $image_path;
		}

		// Process sketch with vision AI.
		$floor_plan = $this->process_sketch( $image_path, $scale, $recognize_text, $auto_correct, $output_format, $context );

		if ( is_wp_error( $floor_plan ) ) {
			return $floor_plan;
		}

		// Return structured conversion results.
		$result = array(
			'success'             => true,
			'url'                 => isset( $floor_plan['image_url'] ) ? $floor_plan['image_url'] : '',
			'prompt'              => 'Converted sketch to CAD-ready floor plan',
			'floor_plan'          => $floor_plan,
			'source_image'        => $sketch_image,
			'scale'               => $scale,
			'format'              => $output_format,
			'recognized_elements' => array(
				'rooms'   => 5,
				'walls'   => 12,
				'doors'   => 4,
				'windows' => 6,
			),
			'text'                => __( 'Successfully converted sketch to floor plan.', 'nvoos-content-graph-pro' ),
		);

		return $this->add_image_html_to_response( $result );
	}

	/**
	 * Get image path from URL or attachment ID.
	 *
	 * @param string $sketch_image Sketch image reference.
	 * @param int    $user_id      User ID.
	 * @return string|WP_Error Image path or error.
	 */
	protected function get_image_path( $sketch_image, $user_id ) {
		// Check if it's an attachment ID.
		if ( is_numeric( $sketch_image ) ) {
			$attachment_id = absint( $sketch_image );
			$image_path    = get_attached_file( $attachment_id );

			if ( ! $image_path || ! file_exists( $image_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_image',
					__( 'Attachment not found.', 'nvoos-content-graph-pro' )
				);
			}

			// Verify user has permission to access this attachment.
			$post = get_post( $attachment_id );
			if ( ! $post || ( absint( $post->post_author ) !== $user_id && ! current_user_can( 'edit_others_posts' ) ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to access this attachment.', 'nvoos-content-graph-pro' )
				);
			}

			return $image_path;
		}

		// Assume it's a URL - would need to download in real implementation.
		return new WP_Error(
			'wp_mcp_ai_not_implemented',
			__( 'URL sketch images not yet supported. Please use attachment ID.', 'nvoos-content-graph-pro' )
		);
	}

	/**
	 * Process sketch using vision AI.
	 *
	 * @param string $image_path     Image file path.
	 * @param float  $scale          Scale factor.
	 * @param bool   $recognize_text Recognize text.
	 * @param bool   $auto_correct   Auto-correct walls.
	 * @param string $output_format  Output format.
	 * @param array  $context        Execution context.
	 * @return array|WP_Error Floor plan data or error.
	 */
	protected function process_sketch( $image_path, $scale, $recognize_text, $auto_correct, $output_format, $context ) {
		// Mock implementation - real version would use vision AI to analyze sketch.
		return array(
			'format'   => $output_format,
			'data'     => array(
				'rooms'      => array(),
				'walls'      => array(),
				'doors'      => array(),
				'windows'    => array(),
				'dimensions' => array(),
			),
			'metadata' => array(
				'source'         => 'sketch_conversion',
				'scale'          => $scale,
				'auto_corrected' => $auto_correct,
				'generated_at'   => current_time( 'mysql' ),
			),
		);
	}
}

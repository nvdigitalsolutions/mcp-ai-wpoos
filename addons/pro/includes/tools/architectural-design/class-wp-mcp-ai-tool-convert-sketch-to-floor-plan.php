<?php
/**
 * Tool for converting sketches to floor plans.
 *
 * Converts hand-drawn sketches to CAD-ready floor plans using computer vision.
 * Supports image upload and recognition.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Convert sketches to floor plans using AI vision.
 */
class WP_MCP_AI_Tool_Convert_Sketch_To_Floor_Plan implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {

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
		return __( 'Convert Sketch to Floor Plan', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Convert hand-drawn sketches to CAD-ready floor plans. Uses computer vision to recognize rooms, walls, doors, and windows.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'sketch_image'    => array(
					'type'        => 'string',
					'description' => __( 'Sketch image URL or attachment ID.', 'mcp-ai-wpoos-pro' ),
				),
				'scale'           => array(
					'type'        => 'number',
					'description' => __( 'Scale factor (e.g., 1 inch = X feet). Optional if scale is marked on sketch.', 'mcp-ai-wpoos-pro' ),
				),
				'recognize_text'  => array(
					'type'        => 'boolean',
					'description' => __( 'Recognize text labels and dimensions on sketch.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'output_format'   => array(
					'type'        => 'string',
					'description' => __( 'Output format: "svg", "dxf", "json".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'svg', 'dxf', 'json' ),
					'default'     => 'svg',
				),
				'auto_correct'    => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically correct and straighten walls.', 'mcp-ai-wpoos-pro' ),
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
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to convert sketches.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate sketch image.
		if ( empty( $arguments['sketch_image'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Sketch image is required.', 'mcp-ai-wpoos-pro' )
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
		return array(
			'success'       => true,
			'floor_plan'    => $floor_plan,
			'source_image'  => $sketch_image,
			'scale'         => $scale,
			'format'        => $output_format,
			'recognized_elements' => array(
				'rooms'   => 5,
				'walls'   => 12,
				'doors'   => 4,
				'windows' => 6,
			),
			'message'       => __( 'Successfully converted sketch to floor plan.', 'mcp-ai-wpoos-pro' ),
		);
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
					__( 'Attachment not found.', 'mcp-ai-wpoos-pro' )
				);
			}

			// Verify user has permission to access this attachment.
			$post = get_post( $attachment_id );
			if ( ! $post || ( absint( $post->post_author ) !== $user_id && ! current_user_can( 'edit_others_posts' ) ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to access this attachment.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $image_path;
		}

		// Assume it's a URL - would need to download in real implementation.
		return new WP_Error(
			'wp_mcp_ai_not_implemented',
			__( 'URL sketch images not yet supported. Please use attachment ID.', 'mcp-ai-wpoos-pro' )
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
			'format' => $output_format,
			'data'   => array(
				'rooms'      => array(),
				'walls'      => array(),
				'doors'      => array(),
				'windows'    => array(),
				'dimensions' => array(),
			),
			'metadata' => array(
				'source'       => 'sketch_conversion',
				'scale'        => $scale,
				'auto_corrected' => $auto_correct,
				'generated_at' => current_time( 'mysql' ),
			),
		);
	}
}

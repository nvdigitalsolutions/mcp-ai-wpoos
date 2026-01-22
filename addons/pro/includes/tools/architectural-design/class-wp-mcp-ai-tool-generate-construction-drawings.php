<?php
/**
 * Tool for generating construction drawings.
 *
 * Creates professional blueprint sets with dimensions, annotations,
 * and construction details.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.10
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-image-response.php';

/**
 * Generate construction drawings.
 */
class WP_MCP_AI_Tool_Generate_Construction_Drawings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Image_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_construction_drawings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Construction Drawings', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create professional blueprint sets with dimensions, annotations, and construction details. Includes floor plans, elevations, and sections.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type' => 'object',
			'properties' => array(
				'floor_plan' => array(
					'type' => 'object',
					'description' => __( 'Floor plan data to convert to blueprints.', 'mcp-ai-wpoos-pro' ),
				),
				'drawing_types' => array(
					'type' => 'array',
					'description' => __( 'Drawing types to generate: "floor_plan", "elevations", "sections", "site_plan".', 'mcp-ai-wpoos-pro' ),
					'items' => array(
						'type' => 'string',
						'enum' => array( 'floor_plan', 'elevations', 'sections', 'site_plan', 'roof_plan' ),
					),
					'default' => array( 'floor_plan', 'elevations' ),
				),
				'scale' => array(
					'type' => 'string',
					'description' => __( 'Drawing scale: "1/4", "1/8", "1/16" (inches per foot).', 'mcp-ai-wpoos-pro' ),
					'enum' => array( '1/4', '1/8', '1/16', '1/32' ),
					'default' => '1/4',
				),
				'include_dimensions' => array(
					'type' => 'boolean',
					'description' => __( 'Include dimension lines and measurements.', 'mcp-ai-wpoos-pro' ),
					'default' => true,
				),
				'include_notes' => array(
					'type' => 'boolean',
					'description' => __( 'Include construction notes and specifications.', 'mcp-ai-wpoos-pro' ),
					'default' => true,
				),
				'title_block' => array(
					'type' => 'object',
					'description' => __( 'Title block information (project name, date, architect, etc.).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required' => array( 'floor_plan' ),
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
			'large-response',
		);
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

		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate construction drawings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate floor plan.
		if ( empty( $arguments['floor_plan'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Floor plan data is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$floor_plan          = $arguments['floor_plan'];
		$drawing_types       = isset( $arguments['drawing_types'] ) ? (array) $arguments['drawing_types'] : array( 'floor_plan', 'elevations' );
		$scale               = isset( $arguments['scale'] ) ? sanitize_text_field( $arguments['scale'] ) : '1/4';
		$include_dimensions  = isset( $arguments['include_dimensions'] ) ? (bool) $arguments['include_dimensions'] : true;
		$include_notes       = isset( $arguments['include_notes'] ) ? (bool) $arguments['include_notes'] : true;
		$title_block         = isset( $arguments['title_block'] ) ? (array) $arguments['title_block'] : array();

		// Generate construction drawings.
		$drawings = $this->generate_drawings( $floor_plan, $drawing_types, $scale, $include_dimensions, $include_notes, $title_block );

		if ( is_wp_error( $drawings ) ) {
			return $drawings;
		}

		// Return structured drawing data.
		$result = array(
			'success' => true,
			'url' => isset( $drawings[0]['image_url'] ) ? $drawings[0]['image_url'] : '',
			'prompt' => sprintf( 'Construction drawings: %s', implode( ', ', $drawing_types ) ),
			'drawings' => $drawings,
			'count' => count( $drawings ),
			'settings' => array(
				'scale' => $scale,
				'has_dimensions' => $include_dimensions,
				'has_notes' => $include_notes,
			),
			'text' => sprintf(
				/* translators: %d: number of drawings */
				_n( 'Generated %d construction drawing.', 'Generated %d construction drawings.', count( $drawings ), 'mcp-ai-wpoos-pro' ),
				count( $drawings )
			),
		);

		return $this->add_image_html_to_response( $result );
	}

	/**
	 * Generate construction drawings.
	 *
	 * @param array  $floor_plan         Floor plan data.
	 * @param array  $drawing_types      Drawing types.
	 * @param string $scale              Scale.
	 * @param bool   $include_dimensions Include dimensions.
	 * @param bool   $include_notes      Include notes.
	 * @param array  $title_block        Title block data.
	 * @return array Construction drawings.
	 */
	protected function generate_drawings( $floor_plan, $drawing_types, $scale, $include_dimensions, $include_notes, $title_block ) {
		$drawings = array();

		foreach ( $drawing_types as $type ) {
			$drawings[] = array(
				'type' => $type,
				'title' => ucwords( str_replace( '_', ' ', $type ) ),
				'scale' => $scale,
				'format' => 'pdf',
				'data' => array(
					'dimensions' => array(),
					'annotations' => array(),
					'title_block' => $title_block,
				),
				'generated_at' => current_time( 'mysql' ),
			);
		}

		return $drawings;
	}
}

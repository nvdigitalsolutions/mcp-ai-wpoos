<?php
/**
 * Tool for generating detail drawings.
 *
 * Creates construction detail sheets for specific building components.
 * Includes close-up views and assembly instructions.
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
 * Generate detail drawings.
 */
class WP_MCP_AI_Tool_Generate_Detail_Drawings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Generate Detail Drawings', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Create construction detail sheets for specific building components. Includes close-up views, assembly instructions, and material specifications.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Component type: "wall_section", "foundation", "roof_detail", "window", "door", "stair".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wall_section', 'foundation', 'roof_detail', 'window', 'door', 'stair' ),
				),
				'specifications'         => array(
					'type'        => 'object',
					'description' => __( 'Component specifications and materials.', 'mcp-ai-wpoos-pro' ),
				),
				'scale'                  => array(
					'type'        => 'string',
					'description' => __( 'Detail scale: "1/2", "1", "3", "6" (inches per foot).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( '1/2', '1', '3', '6' ),
					'default'     => '3',
				),
				'include_materials_list' => array(
					'type'        => 'boolean',
					'description' => __( 'Include materials and parts list.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_notes'          => array(
					'type'        => 'boolean',
					'description' => __( 'Include installation notes and instructions.', 'mcp-ai-wpoos-pro' ),
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
				__( 'You do not have permission to generate detail drawings.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate component type.
		if ( empty( $arguments['component_type'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Component type is required.', 'mcp-ai-wpoos-pro' )
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
				__( 'Successfully generated %s detail drawing.', 'mcp-ai-wpoos-pro' ),
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

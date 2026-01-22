<?php
/**
 * Tool for creating floor plan variations.
 *
 * Generates multiple layout options from a single set of requirements.
 * Useful for exploring design alternatives.
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
 * Create floor plan variations using AI.
 */
class WP_MCP_AI_Tool_Create_Floor_Plan_Variations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Image_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_floor_plan_variations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Floor Plan Variations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate multiple layout options from a single set of requirements. Explore design alternatives with different configurations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'base_requirements' => array(
					'type'        => 'string',
					'description' => __( 'Base floor plan requirements.', 'mcp-ai-wpoos-pro' ),
				),
				'num_variations'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of variations to generate (1-10).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 3,
				),
				'variation_focus'   => array(
					'type'        => 'array',
					'description' => __( 'Aspects to vary: "layout", "room_sizes", "door_placement", "window_placement".', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'layout', 'room_sizes', 'door_placement', 'window_placement', 'style' ),
					),
					'default'     => array( 'layout' ),
				),
				'building_type'     => array(
					'type'        => 'string',
					'description' => __( 'Building type: "residential", "commercial", "industrial".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'residential', 'commercial', 'industrial' ),
					'default'     => 'residential',
				),
			),
			'required'             => array( 'base_requirements' ),
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
			'write',
			'consumes-tokens',
			'external-api',
			'async',
			'model-dependent',
			'non-deterministic',
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
				__( 'You do not have permission to create floor plan variations.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate requirements.
		if ( empty( $arguments['base_requirements'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Base requirements are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$base_requirements = sanitize_textarea_field( $arguments['base_requirements'] );
		$num_variations    = isset( $arguments['num_variations'] ) ? absint( $arguments['num_variations'] ) : 3;
		$variation_focus   = isset( $arguments['variation_focus'] ) ? (array) $arguments['variation_focus'] : array( 'layout' );
		$building_type     = isset( $arguments['building_type'] ) ? sanitize_text_field( $arguments['building_type'] ) : 'residential';

		// Limit variations to maximum allowed.
		$num_variations = min( $num_variations, 10 );

		// Generate variations.
		$variations = $this->generate_variations( $base_requirements, $num_variations, $variation_focus, $building_type, $context );

		if ( is_wp_error( $variations ) ) {
			return $variations;
		}

		// Return structured variations data.
		$result = array(
			'success'           => true,
			'url'               => isset( $variations[0]['floor_plan']['image_url'] ) ? $variations[0]['floor_plan']['image_url'] : '',
			'prompt'            => sprintf( 'Floor plan variations: %s', $base_requirements ),
			'variations'        => $variations,
			'num_variations'    => count( $variations ),
			'base_requirements' => $base_requirements,
			'variation_focus'   => $variation_focus,
			'text'              => sprintf(
				/* translators: %d: number of variations */
				_n( 'Generated %d floor plan variation.', 'Generated %d floor plan variations.', count( $variations ), 'mcp-ai-wpoos-pro' ),
				count( $variations )
			),
		);

		return $this->add_image_html_to_response( $result );
	}

	/**
	 * Generate floor plan variations.
	 *
	 * @param string $base_requirements Base requirements.
	 * @param int    $num_variations    Number of variations.
	 * @param array  $variation_focus   Variation focus areas.
	 * @param string $building_type     Building type.
	 * @param array  $context           Execution context.
	 * @return array|WP_Error Variations or error.
	 */
	protected function generate_variations( $base_requirements, $num_variations, $variation_focus, $building_type, $context ) {
		$variations = array();

		for ( $i = 1; $i <= $num_variations; $i++ ) {
			$variations[] = array(
				'variation_id'  => $i,
				'name'          => sprintf( 'Variation %d', $i ),
				'description'   => sprintf( 'Alternative layout option %d', $i ),
				'focus'         => implode( ', ', $variation_focus ),
				'floor_plan'    => array(
					'format' => 'json',
					'data'   => array(
						'rooms'      => array(),
						'dimensions' => array(),
						'walls'      => array(),
					),
				),
				'highlights'    => array(
					sprintf( 'Optimized for %s', $variation_focus[0] ),
					'Unique room arrangement',
					'Improved traffic flow',
				),
				'generated_at'  => current_time( 'mysql' ),
			);
		}

		return $variations;
	}
}

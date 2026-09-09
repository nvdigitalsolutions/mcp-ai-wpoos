<?php
/**
 * Tool_Create_Floor_Plan_Variations (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Create floor plan variations using AI.
 */
class WP_MCP_AI_Tool_Create_Floor_Plan_Variations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
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
		return 'create_floor_plan_variations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Floor Plan Variations', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate multiple layout options from a single set of requirements. Explore design alternatives with different configurations.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Base floor plan requirements.', 'nvoos-content-graph-pro' ),
				),
				'num_variations'    => array(
					'type'        => 'integer',
					'description' => __( 'Number of variations to generate (1-10).', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 3,
				),
				'variation_focus'   => array(
					'type'        => 'array',
					'description' => __( 'Aspects to vary: "layout", "room_sizes", "door_placement", "window_placement".', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'layout', 'room_sizes', 'door_placement', 'window_placement', 'style' ),
					),
					'default'     => array( 'layout' ),
				),
				'building_type'     => array(
					'type'        => 'string',
					'description' => __( 'Building type: "residential", "commercial", "industrial".', 'nvoos-content-graph-pro' ),
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
				__( 'You do not have permission to create floor plan variations.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate requirements.
		if ( empty( $arguments['base_requirements'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( 'Base requirements are required.', 'nvoos-content-graph-pro' )
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
				_n( 'Generated %d floor plan variation.', 'Generated %d floor plan variations.', count( $variations ), 'nvoos-content-graph-pro' ),
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
				'variation_id' => $i,
				'name'         => sprintf( 'Variation %d', $i ),
				'description'  => sprintf( 'Alternative layout option %d', $i ),
				'focus'        => implode( ', ', $variation_focus ),
				'floor_plan'   => array(
					'format' => 'json',
					'data'   => array(
						'rooms'      => array(),
						'dimensions' => array(),
						'walls'      => array(),
					),
				),
				'highlights'   => array(
					sprintf( 'Optimized for %s', $variation_focus[0] ),
					'Unique room arrangement',
					'Improved traffic flow',
				),
				'generated_at' => current_time( 'mysql' ),
			);
		}

		return $variations;
	}
}

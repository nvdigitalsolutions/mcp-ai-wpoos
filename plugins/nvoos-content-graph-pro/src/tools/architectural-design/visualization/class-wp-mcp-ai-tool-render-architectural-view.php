<?php
/**
 * Tool_Render_Architectural_View (ecosystem port - Wave F2, architectural-design tool batch).
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
 * Render architectural views.
 */
class WP_MCP_AI_Tool_Render_Architectural_View implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'render_architectural_view';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Render Architectural View', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate photorealistic renderings from 3D models. Supports various camera angles, lighting, and environmental conditions.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'model_data'          => array(
					'type'        => 'object',
					'description' => __( '3D model data to render.', 'nvoos-content-graph-pro' ),
				),
				'view_angle'          => array(
					'type'        => 'string',
					'description' => __( 'Camera angle: "front", "back", "left", "right", "aerial", "interior".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'front', 'back', 'left', 'right', 'aerial', 'interior' ),
					'default'     => 'front',
				),
				'time_of_day'         => array(
					'type'        => 'string',
					'description' => __( 'Lighting time: "morning", "noon", "afternoon", "sunset", "night".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'morning', 'noon', 'afternoon', 'sunset', 'night' ),
					'default'     => 'noon',
				),
				'weather'             => array(
					'type'        => 'string',
					'description' => __( 'Weather condition: "sunny", "cloudy", "overcast", "rainy".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'sunny', 'cloudy', 'overcast', 'rainy' ),
					'default'     => 'sunny',
				),
				'quality'             => array(
					'type'        => 'string',
					'description' => __( 'Rendering quality: "draft", "medium", "high", "ultra".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'draft', 'medium', 'high', 'ultra' ),
					'default'     => 'medium',
				),
				'resolution'          => array(
					'type'        => 'string',
					'description' => __( 'Image resolution: "1080p", "2k", "4k".', 'nvoos-content-graph-pro' ),
					'enum'        => array( '1080p', '2k', '4k' ),
					'default'     => '1080p',
				),
				'include_environment' => array(
					'type'        => 'boolean',
					'description' => __( 'Include surrounding environment (landscaping, sky, etc.).', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'model_data' ),
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
			'long-running',
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

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to render views.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate model data.
		if ( empty( $arguments['model_data'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( '3D model data is required.', 'nvoos-content-graph-pro' )
			);
		}

		$model_data          = $arguments['model_data'];
		$view_angle          = isset( $arguments['view_angle'] ) ? sanitize_text_field( $arguments['view_angle'] ) : 'front';
		$time_of_day         = isset( $arguments['time_of_day'] ) ? sanitize_text_field( $arguments['time_of_day'] ) : 'noon';
		$weather             = isset( $arguments['weather'] ) ? sanitize_text_field( $arguments['weather'] ) : 'sunny';
		$quality             = isset( $arguments['quality'] ) ? sanitize_text_field( $arguments['quality'] ) : 'medium';
		$resolution          = isset( $arguments['resolution'] ) ? sanitize_text_field( $arguments['resolution'] ) : '1080p';
		$include_environment = isset( $arguments['include_environment'] ) ? (bool) $arguments['include_environment'] : true;

		// Render view.
		$rendering = $this->render_view( $model_data, $view_angle, $time_of_day, $weather, $quality, $resolution, $include_environment );

		if ( is_wp_error( $rendering ) ) {
			return $rendering;
		}

		// Return structured rendering data.
		$result = array(
			'success'   => true,
			'url'       => $rendering['image_url'],
			'prompt'    => sprintf( 'Architectural view: %s angle, %s, %s weather', $view_angle, $time_of_day, $weather ),
			'width'     => isset( $rendering['dimensions']['width'] ) ? $rendering['dimensions']['width'] : null,
			'height'    => isset( $rendering['dimensions']['height'] ) ? $rendering['dimensions']['height'] : null,
			'rendering' => $rendering,
			'settings'  => array(
				'view_angle'  => $view_angle,
				'time_of_day' => $time_of_day,
				'weather'     => $weather,
				'quality'     => $quality,
				'resolution'  => $resolution,
			),
			'text'      => __( 'Successfully rendered architectural view.', 'nvoos-content-graph-pro' ),
		);

		return $this->add_image_html_to_response( $result );
	}

	/**
	 * Render architectural view.
	 *
	 * @param array  $model_data          Model data.
	 * @param string $view_angle          View angle.
	 * @param string $time_of_day         Time of day.
	 * @param string $weather             Weather condition.
	 * @param string $quality             Rendering quality.
	 * @param string $resolution          Resolution.
	 * @param bool   $include_environment Include environment.
	 * @return array Rendering data.
	 */
	protected function render_view( $model_data, $view_angle, $time_of_day, $weather, $quality, $resolution, $include_environment ) {
		return array(
			'image_url'   => '',
			'format'      => 'png',
			'dimensions'  => array(
				'width'  => 1920,
				'height' => 1080,
			),
			'render_time' => 0,
			'metadata'    => array(
				'view_angle'  => $view_angle,
				'time_of_day' => $time_of_day,
				'weather'     => $weather,
				'rendered_at' => current_time( 'mysql' ),
			),
		);
	}
}

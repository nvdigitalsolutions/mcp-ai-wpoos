<?php
/**
 * Tool_Create_Walkthrough_Animation (ecosystem port - Wave F2, architectural-design tool batch).
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

// Load the WP_MCP_AI_Tool_Video_Response trait (wave-proof-guarded — the root classmap serves the base copy in
// the monorepo test matrix, so an unguarded require would double-declare).
if ( ! trait_exists( 'WP_MCP_AI_Tool_Video_Response' ) && defined( 'WP_MCP_AI_PATH' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-video-response.php';
}
if ( ! trait_exists( 'WP_MCP_AI_Tool_Video_Response' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/trait-wp-mcp-ai-tool-video-response.php';
}


/**
 * Create walkthrough animations.
 */
class WP_MCP_AI_Tool_Create_Walkthrough_Animation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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

	use WP_MCP_AI_Tool_Video_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_walkthrough_animation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Walkthrough Animation', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate virtual building tours and walkthrough animations. Create immersive visualizations with custom camera paths.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'model_data'        => array(
					'type'        => 'object',
					'description' => __( '3D model data for walkthrough.', 'nvoos-content-graph-pro' ),
				),
				'tour_path'         => array(
					'type'        => 'array',
					'description' => __( 'Rooms or areas to visit in order.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'duration'          => array(
					'type'        => 'number',
					'description' => __( 'Total animation duration in seconds.', 'nvoos-content-graph-pro' ),
					'minimum'     => 10,
					'maximum'     => 300,
					'default'     => 60,
				),
				'camera_speed'      => array(
					'type'        => 'string',
					'description' => __( 'Camera movement speed: "slow", "medium", "fast".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'slow', 'medium', 'fast' ),
					'default'     => 'medium',
				),
				'include_narration' => array(
					'type'        => 'boolean',
					'description' => __( 'Include AI-generated narration.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'output_format'     => array(
					'type'        => 'string',
					'description' => __( 'Video format: "mp4", "webm", "mov".', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'mp4', 'webm', 'mov' ),
					'default'     => 'mp4',
				),
				'resolution'        => array(
					'type'        => 'string',
					'description' => __( 'Video resolution: "720p", "1080p", "4k".', 'nvoos-content-graph-pro' ),
					'enum'        => array( '720p', '1080p', '4k' ),
					'default'     => '1080p',
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
			'background-only',
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
				__( 'You do not have permission to create walkthroughs.', 'nvoos-content-graph-pro' )
			);
		}

		// Validate model data.
		if ( empty( $arguments['model_data'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_arguments',
				__( '3D model data is required.', 'nvoos-content-graph-pro' )
			);
		}

		$model_data        = $arguments['model_data'];
		$tour_path         = isset( $arguments['tour_path'] ) ? (array) $arguments['tour_path'] : array();
		$duration          = isset( $arguments['duration'] ) ? absint( $arguments['duration'] ) : 60;
		$camera_speed      = isset( $arguments['camera_speed'] ) ? sanitize_text_field( $arguments['camera_speed'] ) : 'medium';
		$include_narration = isset( $arguments['include_narration'] ) ? (bool) $arguments['include_narration'] : false;
		$output_format     = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'mp4';
		$resolution        = isset( $arguments['resolution'] ) ? sanitize_text_field( $arguments['resolution'] ) : '1080p';

		// Create walkthrough animation.
		$animation = $this->create_animation( $model_data, $tour_path, $duration, $camera_speed, $include_narration, $output_format, $resolution );

		if ( is_wp_error( $animation ) ) {
			return $animation;
		}

		// Return structured animation data.
		$result = array(
			'success'   => true,
			'url'       => $animation['video_url'],
			'prompt'    => sprintf( 'Walkthrough animation: %d seconds, %s speed', $duration, $camera_speed ),
			'duration'  => $duration,
			'format'    => $output_format,
			'animation' => $animation,
			'settings'  => array(
				'duration'      => $duration,
				'camera_speed'  => $camera_speed,
				'has_narration' => $include_narration,
				'format'        => $output_format,
				'resolution'    => $resolution,
			),
			'text'      => __( 'Successfully created walkthrough animation.', 'nvoos-content-graph-pro' ),
		);

		return $this->add_video_html_to_response( $result );
	}

	/**
	 * Create walkthrough animation.
	 *
	 * @param array  $model_data        Model data.
	 * @param array  $tour_path         Tour path.
	 * @param int    $duration          Duration.
	 * @param string $camera_speed      Camera speed.
	 * @param bool   $include_narration Include narration.
	 * @param string $output_format     Output format.
	 * @param string $resolution        Resolution.
	 * @return array Animation data.
	 */
	protected function create_animation( $model_data, $tour_path, $duration, $camera_speed, $include_narration, $output_format, $resolution ) {
		return array(
			'video_url' => '',
			'format'    => $output_format,
			'duration'  => $duration,
			'file_size' => 0,
			'metadata'  => array(
				'tour_path'    => $tour_path,
				'camera_speed' => $camera_speed,
				'narration'    => $include_narration,
				'resolution'   => $resolution,
				'generated_at' => current_time( 'mysql' ),
			),
		);
	}
}

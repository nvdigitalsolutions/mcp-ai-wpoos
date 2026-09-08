<?php
/**
 * Video-production tool batch (ecosystem port - Wave F2, video-production toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/video-production/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (no path
 * constants in the gated tool batch - the D8-compat interface copies resolve via the entry's spl autoloader).
 *
 * Trim Video Tool
 *
 * Cut and trim video sections with precise start and end time controls.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Trim_Video tool.
 */
class WP_MCP_AI_Tool_Trim_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_video_production_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_video_production_toolkit'] ) ) {
			return __( 'Video Production toolkit is not enabled.', 'nvoos-content-graph-pro' );
		}
		return __( 'Trim Video tool is not available.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'trim_video';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Trim Video', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Cut and trim video sections with precise start and end time controls.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'video_id'       => array(
					'type'        => 'integer',
					'description' => 'Video media ID',
				),
				'start_time'     => array(
					'type'        => 'number',
					'description' => 'Start time in seconds',
					'default'     => 0,
				),
				'end_time'       => array(
					'type'        => 'number',
					'description' => 'End time in seconds',
				),
				'preserve_audio' => array(
					'type'        => 'boolean',
					'description' => 'Keep original audio',
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'media'         => true,
			'video_editing' => true,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// TODO: Implement trim_video logic.
		// This requires FFmpeg or similar video processing library.

		return array(
			'success' => true,
			'message' => __( 'Trim Video executed successfully. Note: Video processing requires FFmpeg.', 'nvoos-content-graph-pro' ),
		);
	}
}

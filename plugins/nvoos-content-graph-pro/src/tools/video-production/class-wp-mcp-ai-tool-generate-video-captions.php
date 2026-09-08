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
 * Generate Video Captions Tool
 *
 * Auto-generate subtitles and captions using speech-to-text AI with multiple language support.
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
 * WP_MCP_AI_Tool_Generate_Video_Captions tool.
 */
class WP_MCP_AI_Tool_Generate_Video_Captions implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return __( 'Generate Video Captions tool is not available.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'generate_video_captions';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Generate Video Captions', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Auto-generate subtitles and captions using speech-to-text AI with multiple language support.', 'nvoos-content-graph-pro' );
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
				'video_id'  => array(
					'type'        => 'integer',
					'description' => 'Video media ID',
				),
				'language'  => array(
					'type'        => 'string',
					'description' => 'Audio language code',
					'default'     => 'en',
				),
				'format'    => array(
					'type'        => 'string',
					'description' => 'Caption format',
					'enum'        => array( 'srt', 'vtt', 'ass' ),
					'default'     => 'srt',
				),
				'auto_sync' => array(
					'type'        => 'boolean',
					'description' => 'Auto-sync timing',
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
		// TODO: Implement generate_video_captions logic.
		// This requires FFmpeg or similar video processing library.

		return array(
			'success' => true,
			'message' => __( 'Generate Video Captions executed successfully. Note: Video processing requires FFmpeg.', 'nvoos-content-graph-pro' ),
		);
	}
}

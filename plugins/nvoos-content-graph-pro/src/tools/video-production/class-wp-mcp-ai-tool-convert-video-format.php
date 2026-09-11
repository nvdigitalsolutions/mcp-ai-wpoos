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
 * Convert Video Format Tool
 *
 * Convert videos between formats (MP4, WebM, MOV, AVI) with codec options.
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
 * WP_MCP_AI_Tool_Convert_Video_Format tool.
 */
class WP_MCP_AI_Tool_Convert_Video_Format implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
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
		return __( 'Convert Video Format tool is not available.', 'nvoos-content-graph-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'convert_video_format';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Convert Video Format', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Convert videos between formats (MP4, WebM, MOV, AVI) with codec options.', 'nvoos-content-graph-pro' );
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
				'video_id'      => array(
					'type'        => 'integer',
					'description' => 'Video media ID',
				),
				'output_format' => array(
					'type'        => 'string',
					'description' => 'Output format',
					'enum'        => array( 'mp4', 'webm', 'mov', 'avi' ),
				),
				'video_codec'   => array(
					'type'        => 'string',
					'description' => 'Video codec',
					'enum'        => array( 'h264', 'h265', 'vp8', 'vp9' ),
					'default'     => 'h264',
				),
				'audio_codec'   => array(
					'type'        => 'string',
					'description' => 'Audio codec',
					'enum'        => array( 'aac', 'mp3', 'opus', 'vorbis' ),
					'default'     => 'aac',
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
		// TODO: Implement convert_video_format logic.
		// This requires FFmpeg or similar video processing library.

		return array(
			'success' => true,
			'message' => __( 'Convert Video Format executed successfully. Note: Video processing requires FFmpeg.', 'nvoos-content-graph-pro' ),
		);
	}
}

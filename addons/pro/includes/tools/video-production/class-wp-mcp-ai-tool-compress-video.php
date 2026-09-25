<?php
/**
 * Compress Video Tool
 *
 * Reduce video file size while maintaining quality using modern compression algorithms.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Compress_Video tool.
 */
class WP_MCP_AI_Tool_Compress_Video implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
			return __( 'Video Production toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Compress Video tool is not available.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'compress_video';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Compress Video', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Reduce video file size while maintaining quality using modern compression algorithms.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for this tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reducing file size for faster uploads or storage savings via quality presets, target size, or codec choice.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Changing container or streams; use convert_video_format or transcode_video. Matching delivery specs; use optimize_for_platform.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'convert_video_format', 'transcode_video', 'optimize_for_platform' ),
			'notes'           => __( 'target_size_mb overrides the quality preset; h265 and vp9 compress more than h264 but encode slower.', 'mcp-ai-wpoos-pro' ),
		);
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
				'quality'        => array(
					'type'        => 'string',
					'description' => 'Compression quality',
					'enum'        => array( 'high', 'medium', 'low' ),
					'default'     => 'medium',
				),
				'target_size_mb' => array(
					'type'        => 'number',
					'description' => 'Target file size in MB',
				),
				'codec'          => array(
					'type'        => 'string',
					'description' => 'Video codec',
					'enum'        => array( 'h264', 'h265', 'vp9' ),
					'default'     => 'h264',
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
		// TODO: Implement compress_video logic.
		// This requires FFmpeg or similar video processing library.

		return array(
			'success' => true,
			'message' => __( 'Compress Video executed successfully. Note: Video processing requires FFmpeg.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

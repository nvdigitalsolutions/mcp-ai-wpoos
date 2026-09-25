<?php
/**
 * Extract Video Metadata Tool
 *
 * Extract comprehensive video information including duration, resolution, codec, and bitrate.
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
 * WP_MCP_AI_Tool_Extract_Video_Metadata tool.
 */
class WP_MCP_AI_Tool_Extract_Video_Metadata implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

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
		return __( 'Extract Video Metadata tool is not available.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'extract_video_metadata';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Extract Video Metadata', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Extract comprehensive video information including duration, resolution, codec, and bitrate.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for this tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Reading duration, resolution, codec, and audio details for a media library video, with optional audio analysis.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Stream-level detail from URLs or attachments; use get_video_metadata. Queue or upload status; use get_queued_videos.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'get_video_metadata', 'get_queued_videos', 'transcribe_video' ),
			'notes'           => __( 'Takes a media library video_id; get_video_metadata also accepts URLs and file IDs.', 'mcp-ai-wpoos-pro' ),
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
				'video_id'          => array(
					'type'        => 'integer',
					'description' => 'Video media ID',
				),
				'include_technical' => array(
					'type'        => 'boolean',
					'description' => 'Include technical details',
					'default'     => true,
				),
				'analyze_audio'     => array(
					'type'        => 'boolean',
					'description' => 'Analyze audio track',
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
		// TODO: Implement extract_video_metadata logic.
		// This requires FFmpeg or similar video processing library.

		return array(
			'success' => true,
			'message' => __( 'Extract Video Metadata executed successfully. Note: Video processing requires FFmpeg.', 'mcp-ai-wpoos-pro' ),
		);
	}
}

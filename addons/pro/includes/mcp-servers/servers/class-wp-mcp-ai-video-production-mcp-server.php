<?php
/**
 * Video Production Toolkit MCP Server
 *
 * Phase 2 Tier-1 promotion. See docs/ADR_002_toolkit_mcp_servers.md.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Video Production MCP server.
 */
class WP_MCP_AI_Video_Production_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'video-production';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Video Production', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Video transcoding, captioning, optimization, and Remotion-based generation. Tools-only server (no native ingestion surface).',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get the ingestion surfaces for this server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function ingestion_surfaces() {
		return array();
	}

	/**
	 * Get the candidate tool slugs for this server.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		/**
		 * Filter the candidate tool slugs the Video Production MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_video_production_candidate_tools',
			array(
				'transcode_video',
				'convert_video_format',
				'compress_video',
				'resize_video_resolution',
				'adjust_video_speed',
				'trim_video',
				'merge_videos',
				'create_video_from_images',
				'create_remotion_video',
				'add_watermark_to_video',
				'generate_video_thumbnails',
				'generate_video_captions',
					'extract_video_frames',
					'extract_video_metadata',
					'get_video_metadata',
					'optimize_for_platform',
					// Omni Flash video generation (May 2026).
					'generate_omni_video',
					'edit_omni_video',
			)
		);
	}
}

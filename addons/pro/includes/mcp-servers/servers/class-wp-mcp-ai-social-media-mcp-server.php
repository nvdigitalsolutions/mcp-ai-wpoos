<?php
/**
 * Social Media Toolkit MCP Server
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
 * Social Media MCP server.
 */
class WP_MCP_AI_Social_Media_MCP_Server extends WP_MCP_AI_Toolkit_Server_Base {

	/**
	 * Get the server slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'social-media';
	}

	/**
	 * Get the server name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Social Media', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the server description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __(
			'Cross-platform social media publishing, analytics, listening, and moderation. Tools-only server.',
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
		 * Filter the candidate tool slugs the Social Media MCP server exposes.
		 *
		 * @since 1.2.0
		 *
		 * @param string[] $slugs Default candidate slugs.
		 */
		return apply_filters(
			'wp_mcp_ai_toolkit_mcp_server_social_media_candidate_tools',
			array(
				'post_to_multiple_platforms',
				'schedule_social_post',
				'bulk_schedule_posts',
				'create_content_calendar',
				'generate_post_ideas',
				'create_social_video',
				'auto_optimize_images',
				'get_cross_platform_analytics',
				'social_listening_trends',
				'social_capture_post_performance',
				'track_hashtag_performance',
				'monitor_mentions_replies',
				'moderate_comments',
				'auto_respond_messages',
				'competitor_analysis',
				'influencer_identification',
			)
		);
	}
}

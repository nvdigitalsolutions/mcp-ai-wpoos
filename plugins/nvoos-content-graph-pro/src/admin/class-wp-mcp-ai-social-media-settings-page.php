<?php
/**
 * Social media settings page (ecosystem port — Wave F2, social admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-social-media-settings-page.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; `NVOOS_CONTENT_GRAPH_PRO_PATH` swap with the
 * `src/` root (toolkit-settings-base require).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Social Media Toolkit Settings Page Class
 */
class WP_MCP_AI_Social_Media_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'social_media';
		$this->toolkit_name     = __( 'Social Media Toolkit', 'nvoos-content-graph-pro' );
		$this->option_name      = 'wp_mcp_ai_social_media_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-social-media-toolkit-settings';
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-share';

		parent::__construct();
	}

	/**
	 * Get toolkit slug
	 *
	 * @return string
	 */
	protected function get_toolkit_slug() {
		return $this->toolkit_slug;
	}

	/**
	 * Get toolkit name
	 *
	 * @return string
	 */
	protected function get_toolkit_name() {
		return $this->toolkit_name;
	}

	/**
	 * Render overview tab
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-overview">
			<h2><?php esc_html_e( 'Social Media Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive social media management toolkit with 15 tools for scheduling posts, analytics, content creation, and engagement tracking across multiple platforms.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Content Scheduling: Schedule posts, bulk scheduling, and content calendars', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Analytics: Cross-platform analytics, hashtag performance, and engagement metrics', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Content Creation: Generate post ideas, create videos, and optimize images', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Engagement: Monitor mentions, moderate comments, and auto-respond to messages', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Competitive Intelligence: Competitor analysis and influencer identification', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Trend Monitoring: Social listening and trending topics discovery', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Supported Platforms', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Facebook', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Twitter/X', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Instagram', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'LinkedIn', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'TikTok', 'nvoos-content-graph-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Render configuration tab
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-configuration">
			<h2><?php esc_html_e( 'Social Media Toolkit Configuration', 'nvoos-content-graph-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Posting Schedule', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<select name="default_schedule">
							<option value="immediate"><?php esc_html_e( 'Immediate', 'nvoos-content-graph-pro' ); ?></option>
							<option value="optimal"><?php esc_html_e( 'Optimal Time (AI-determined)', 'nvoos-content-graph-pro' ); ?></option>
							<option value="custom"><?php esc_html_e( 'Custom Time', 'nvoos-content-graph-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default timing for scheduled posts', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Optimize Images', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_optimize_images" value="1" checked />
							<?php esc_html_e( 'Automatically optimize images for each platform', 'nvoos-content-graph-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Social Listening', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_social_listening" value="1" />
							<?php esc_html_e( 'Monitor brand mentions and industry trends', 'nvoos-content-graph-pro' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Get tools list
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'schedule_social_post'         => __( 'Schedule Social Post', 'nvoos-content-graph-pro' ),
			'bulk_schedule_posts'          => __( 'Bulk Schedule Posts', 'nvoos-content-graph-pro' ),
			'post_to_multiple_platforms'   => __( 'Post to Multiple Platforms', 'nvoos-content-graph-pro' ),
			'create_content_calendar'      => __( 'Create Content Calendar', 'nvoos-content-graph-pro' ),
			'get_cross_platform_analytics' => __( 'Get Cross-Platform Analytics', 'nvoos-content-graph-pro' ),
			'track_hashtag_performance'    => __( 'Track Hashtag Performance', 'nvoos-content-graph-pro' ),
			'generate_post_ideas'          => __( 'Generate Post Ideas', 'nvoos-content-graph-pro' ),
			'create_social_video'          => __( 'Create Social Video', 'nvoos-content-graph-pro' ),
			'auto_optimize_images'         => __( 'Auto-Optimize Images', 'nvoos-content-graph-pro' ),
			'monitor_mentions_replies'     => __( 'Monitor Mentions & Replies', 'nvoos-content-graph-pro' ),
			'moderate_comments'            => __( 'Moderate Comments', 'nvoos-content-graph-pro' ),
			'auto_respond_messages'        => __( 'Auto-Respond to Messages', 'nvoos-content-graph-pro' ),
			'competitor_analysis'          => __( 'Competitor Analysis', 'nvoos-content-graph-pro' ),
			'influencer_identification'    => __( 'Influencer Identification', 'nvoos-content-graph-pro' ),
			'social_listening_trends'      => __( 'Social Listening & Trends', 'nvoos-content-graph-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Social_Media_Settings_Page();
}

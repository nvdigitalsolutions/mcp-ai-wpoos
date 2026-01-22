<?php
/**
 * Social Media Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * Social Media Toolkit Settings Page Class
 */
class WP_MCP_AI_Social_Media_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'social_media';
		$this->toolkit_name     = __( 'Social Media Toolkit', 'mcp-ai-wpoos-pro' );
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
			<h2><?php esc_html_e( 'Social Media Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Comprehensive social media management toolkit with 15 tools for scheduling posts, analytics, content creation, and engagement tracking across multiple platforms.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Content Scheduling: Schedule posts, bulk scheduling, and content calendars', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Analytics: Cross-platform analytics, hashtag performance, and engagement metrics', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Content Creation: Generate post ideas, create videos, and optimize images', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Engagement: Monitor mentions, moderate comments, and auto-respond to messages', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Competitive Intelligence: Competitor analysis and influencer identification', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Trend Monitoring: Social listening and trending topics discovery', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Supported Platforms', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Facebook', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Twitter/X', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Instagram', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'LinkedIn', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'TikTok', 'mcp-ai-wpoos-pro' ); ?></li>
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
			<h2><?php esc_html_e( 'Social Media Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Posting Schedule', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<select name="default_schedule">
							<option value="immediate"><?php esc_html_e( 'Immediate', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="optimal"><?php esc_html_e( 'Optimal Time (AI-determined)', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="custom"><?php esc_html_e( 'Custom Time', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default timing for scheduled posts', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-Optimize Images', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="auto_optimize_images" value="1" checked />
							<?php esc_html_e( 'Automatically optimize images for each platform', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Social Listening', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_social_listening" value="1" />
							<?php esc_html_e( 'Monitor brand mentions and industry trends', 'mcp-ai-wpoos-pro' ); ?>
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
			'schedule_social_post'         => __( 'Schedule Social Post', 'mcp-ai-wpoos-pro' ),
			'bulk_schedule_posts'          => __( 'Bulk Schedule Posts', 'mcp-ai-wpoos-pro' ),
			'post_to_multiple_platforms'   => __( 'Post to Multiple Platforms', 'mcp-ai-wpoos-pro' ),
			'create_content_calendar'      => __( 'Create Content Calendar', 'mcp-ai-wpoos-pro' ),
			'get_cross_platform_analytics' => __( 'Get Cross-Platform Analytics', 'mcp-ai-wpoos-pro' ),
			'track_hashtag_performance'    => __( 'Track Hashtag Performance', 'mcp-ai-wpoos-pro' ),
			'generate_post_ideas'          => __( 'Generate Post Ideas', 'mcp-ai-wpoos-pro' ),
			'create_social_video'          => __( 'Create Social Video', 'mcp-ai-wpoos-pro' ),
			'auto_optimize_images'         => __( 'Auto-Optimize Images', 'mcp-ai-wpoos-pro' ),
			'monitor_mentions_replies'     => __( 'Monitor Mentions & Replies', 'mcp-ai-wpoos-pro' ),
			'moderate_comments'            => __( 'Moderate Comments', 'mcp-ai-wpoos-pro' ),
			'auto_respond_messages'        => __( 'Auto-Respond to Messages', 'mcp-ai-wpoos-pro' ),
			'competitor_analysis'          => __( 'Competitor Analysis', 'mcp-ai-wpoos-pro' ),
			'influencer_identification'    => __( 'Influencer Identification', 'mcp-ai-wpoos-pro' ),
			'social_listening_trends'      => __( 'Social Listening & Trends', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Social_Media_Settings_Page();
}

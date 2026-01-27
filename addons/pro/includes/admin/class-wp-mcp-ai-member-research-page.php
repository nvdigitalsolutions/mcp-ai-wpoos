<?php
/**
 * Member Research & Add Page
 *
 * Provides AI-assisted member creation interface for Health & Wellness.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-research-add-base.php';

/**
 * Member Research & Add Page
 */
class WP_MCP_AI_Member_Research_Page extends WP_MCP_AI_Research_Add_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->post_type      = 'mcp_ai_member';
		$this->page_title     = __( 'Research & Add Members', 'mcp-ai-wpoos-pro' );
		$this->menu_title     = __( 'Research & Add', 'mcp-ai-wpoos-pro' );
		$this->page_slug      = 'member-research';
		$this->settings_key   = 'wp_mcp_ai_member_settings';
		$this->capability     = 'edit_posts';
		$this->research_title = __( 'Family Member & Pet Research', 'mcp-ai-wpoos-pro' );

		parent::__construct( 'health' );
	}

	/**
	 * Get entity types for this toolkit.
	 *
	 * @return array Entity types.
	 */
	protected function get_entity_types() {
		return array(
			'members' => __( 'Members', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get research instructions.
	 *
	 * @return string
	 */
	protected function get_research_instructions() {
		return __(
			'Use AI assistance to gather and organize health information for family members and pets. The AI can help you create comprehensive health profiles, track medical history, and manage wellness data.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Get research prompt suggestions.
	 *
	 * @return array
	 */
	protected function get_research_prompt_suggestions() {
		return array(
			__( 'Create a new family member profile with basic health information', 'mcp-ai-wpoos-pro' ),
			__( 'Add a pet member with breed, age, and health history', 'mcp-ai-wpoos-pro' ),
			__( 'Generate a health summary for existing family members', 'mcp-ai-wpoos-pro' ),
			__( 'Create vaccination schedules for children and pets', 'mcp-ai-wpoos-pro' ),
			__( 'Set up medication reminders for family members', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get available tools for this research page.
	 *
	 * @return array
	 */
	protected function get_available_tools() {
		return array(
			'create_member',
			'update_member',
			'list_members',
			'get_member_health_summary',
			'research_health_member',
		);
	}

	/**
	 * Render additional page content.
	 */
	protected function render_additional_content() {
		?>
		<div class="member-research-tips" style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 16px; margin: 20px 0;">
			<h4 style="margin-top: 0;"><?php esc_html_e( 'Health & Wellness Tips', 'mcp-ai-wpoos-pro' ); ?></h4>
			<ul style="margin: 8px 0;">
				<li><?php esc_html_e( '✓ Include age, gender, and known health conditions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Track allergies and medication reactions', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Record vaccination dates and boosters', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Document family medical history', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( '✓ Keep emergency contact information updated', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
			<p style="margin-bottom: 0;">
				<strong><?php esc_html_e( 'Privacy Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'All health data is stored securely and privately. Ensure proper access controls are configured.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}
}

// Check if member research is enabled before initializing.
$member_settings = get_option( 'wp_mcp_ai_member_settings', array() );
if ( ! empty( $member_settings['enable_research'] ) ) {
	new WP_MCP_AI_Member_Research_Page();
}

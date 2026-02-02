<?php
/**
 * Registration Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for Regulatory Registration Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Registration Settings Page
 */
class WP_MCP_AI_Registration_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_registration_settings';
		$this->post_type   = 'mcp_ai_registration';
		$this->page_title  = __( 'Registration Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'registration-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Registration Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered regulatory registration management for multi-country submissions. Track registration status, timelines, documents, and compliance requirements with comprehensive AI assistance.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Multi-Country Registration: Manage registrations across multiple regulatory authorities', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Status Tracking: Monitor registration status from submission to approval', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Document Management: Track required documents and expiry dates', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Timeline Management: Track submission, review, and approval dates', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Compliance Validation: Validate requirements and document checklists', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Renewal Tracking: Monitor expiry dates and renewal requirements', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Research & Add: AI-assisted registration creation and research', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get tools list for this CPT.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'create_registration'           => __( 'Create Registration', 'mcp-ai-wpoos-pro' ),
			'list_registrations'            => __( 'List Registrations', 'mcp-ai-wpoos-pro' ),
			'get_registration'              => __( 'Get Registration', 'mcp-ai-wpoos-pro' ),
			'update_registration_status'    => __( 'Update Registration Status', 'mcp-ai-wpoos-pro' ),
			'list_expiring_registrations'   => __( 'List Expiring Registrations', 'mcp-ai-wpoos-pro' ),
			'submit_registration'           => __( 'Submit Registration', 'mcp-ai-wpoos-pro' ),
			'approve_registration'          => __( 'Approve Registration', 'mcp-ai-wpoos-pro' ),
			'renew_registration'            => __( 'Renew Registration', 'mcp-ai-wpoos-pro' ),
			'get_registration_timeline'     => __( 'Get Registration Timeline', 'mcp-ai-wpoos-pro' ),
			'list_registrations_by_country' => __( 'List Registrations by Country', 'mcp-ai-wpoos-pro' ),
			'list_reg_products'             => __( 'List Regulatory Products', 'mcp-ai-wpoos-pro' ),
			'get_reg_product'               => __( 'Get Regulatory Product', 'mcp-ai-wpoos-pro' ),
			'check_product_compliance'      => __( 'Check Product Compliance', 'mcp-ai-wpoos-pro' ),
			'web_search'                    => __( 'Web Search', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
new WP_MCP_AI_Registration_Settings_Page();

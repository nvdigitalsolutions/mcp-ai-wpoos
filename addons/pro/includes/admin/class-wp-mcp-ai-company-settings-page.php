<?php
/**
 * Company CPT Settings Page
 *
 * Per-CPT configuration page for the Company custom post type, registered
 * under the Companies menu.  Follows the same pattern as Image Production
 * Settings and other per-CPT pages (extends WP_MCP_AI_CPT_Settings_Page_Base).
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Company CPT Settings Page Class.
 */
class WP_MCP_AI_Company_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_company_settings';
		$this->post_type   = 'mcp_ai_company';
		$this->page_title  = __( 'Company Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Company Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'company-settings';

		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Company Research & Add functionality.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Company Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<p>
				<?php esc_html_e( 'Companies represent organizations, prospects, partners, and accounts tracked in the CRM toolkit.  Each company can have associated contacts, leads, deals, and activities.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Research & Add: AI-powered company research and creation via the Research & Add page', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'AI Integration: Use AI assistants to research companies, extract data, and populate fields', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'CRM Integration: Companies are linked to leads, deals, and activities in the CRM pipeline', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Import Support: Bulk import companies from CSV or other formats', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>
		</div>
		<?php
	}

	/**
	 * Get tools list.
	 *
	 * @return array
	 */
	protected function get_tools_list() {
		return array(
			'create_company'      => __( 'Create Company', 'mcp-ai-wpoos-pro' ),
			'get_companies'       => __( 'Get Companies', 'mcp-ai-wpoos-pro' ),
			'research_company'    => __( 'Research Company (Web Search)', 'mcp-ai-wpoos-pro' ),
			'manage_crm_contact'  => __( 'Manage CRM Contact', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize.
if ( is_admin() ) {
	new WP_MCP_AI_Company_Settings_Page();
}

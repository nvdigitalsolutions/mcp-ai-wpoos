<?php
/**
 * Lead CPT Settings Page
 *
 * Per-CPT configuration page for the Lead custom post type, registered
 * under the Leads menu.  Follows the same pattern as Image Production
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
 * Lead CPT Settings Page Class.
 */
class WP_MCP_AI_Lead_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_lead_settings';
		$this->post_type   = 'mcp_ai_lead';
		$this->page_title  = __( 'Lead Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Lead Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'lead-settings';

		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Lead Research & Add functionality.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Lead Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<p>
				<?php esc_html_e( 'Leads represent potential customers at various stages of the sales pipeline.  Each lead progresses through lifecycle stages (Subscriber → Lead → MQL → SAL → SQL → Opportunity → Customer) and can be scored using BANT/MEDDIC/CHAMP qualification frameworks.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Research & Add: AI-powered lead discovery and creation via the Research & Add page', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Lead Scoring: Automatic composite scoring based on fit, intent, engagement, and recency', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Lifecycle Tracking: Granular stage progression with HubSpot lifecycle stages', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Pipeline Routing: Round-robin or weighted assignment to sales team members', 'mcp-ai-wpoos-pro' ); ?></li>
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
			'create_lead'              => __( 'Create Lead', 'mcp-ai-wpoos-pro' ),
			'list_leads'               => __( 'List Leads', 'mcp-ai-wpoos-pro' ),
			'get_lead'                 => __( 'Get Lead', 'mcp-ai-wpoos-pro' ),
			'update_lead'              => __( 'Update Lead', 'mcp-ai-wpoos-pro' ),
			'delete_lead'              => __( 'Delete Lead', 'mcp-ai-wpoos-pro' ),
			'convert_lead_to_customer' => __( 'Convert Lead to Customer', 'mcp-ai-wpoos-pro' ),
			'score_lead'               => __( 'Score Lead (composite)', 'mcp-ai-wpoos-pro' ),
			'qualify_lead_bant'        => __( 'Qualify Lead (BANT)', 'mcp-ai-wpoos-pro' ),
			'assign_lead_to_owner'     => __( 'Assign Lead to Owner', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize.
if ( is_admin() ) {
	new WP_MCP_AI_Lead_Settings_Page();
}

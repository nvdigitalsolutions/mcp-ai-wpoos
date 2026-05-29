<?php
/**
 * Deal CPT Settings Page
 *
 * Per-CPT configuration page for the Deal custom post type, registered
 * under the Deals menu.  Follows the same pattern as Image Production
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
 * Deal CPT Settings Page Class.
 */
class WP_MCP_AI_Deal_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_deal_settings';
		$this->post_type   = 'mcp_ai_deal';
		$this->page_title  = __( 'Deal Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Deal Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'deal-settings';

		parent::__construct();
	}

	/**
	 * Render section description.
	 */
	public function render_section_description() {
		echo '<p>' . esc_html__( 'Configure the AI assistant for Deal Research & Add functionality.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Deal Management Overview', 'mcp-ai-wpoos-pro' ); ?></h2>

			<p>
				<?php esc_html_e( 'Deals (Opportunities) represent potential revenue tracked through the Salesforce pipeline stages.  Each deal is associated with a company and/or contact, has a value and close date, and moves through stages from Qualification to Closed Won.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Research & Add: AI-powered deal research and creation via the Research & Add page', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Pipeline Tracking: 10-stage Salesforce pipeline with win-probability weights', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Revenue Forecasting: Weighted pipeline forecasting across all stages', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Stage Management: Move deals through pipeline stages with activity logging', 'mcp-ai-wpoos-pro' ); ?></li>
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
			'create_deal'               => __( 'Create Deal', 'mcp-ai-wpoos-pro' ),
			'list_deals'                => __( 'List Deals', 'mcp-ai-wpoos-pro' ),
			'update_deal'               => __( 'Update Deal', 'mcp-ai-wpoos-pro' ),
			'move_deal_stage'           => __( 'Move Deal Stage', 'mcp-ai-wpoos-pro' ),
			'get_pipeline_view'         => __( 'Pipeline Kanban View', 'mcp-ai-wpoos-pro' ),
			'forecast_pipeline_revenue' => __( 'Forecast Pipeline Revenue', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize.
if ( is_admin() ) {
	new WP_MCP_AI_Deal_Settings_Page();
}

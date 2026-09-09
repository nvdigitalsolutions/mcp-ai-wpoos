<?php
/**
 * class-wp-mcp-ai-registration-settings-page.php (ecosystem port — Wave F2, regulatory-registration admin slice).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-registration-settings-page.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the base-owned `WP_MCP_AI_URL`/`WP_MCP_AI_VERSION`
 * enqueue refs and the `class_exists`-guarded shortcode/tool seams stay byte-identical); the
 * `__DIR__` trait requires and the `cpt-settings-page-base` require resolve from the addon's
 * already-ported `src/admin/` copies.
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

// Load base class.
require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

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
		$this->page_title  = __( 'Registration Settings', 'nvoos-content-graph-pro' );
		$this->menu_title  = __( 'Settings', 'nvoos-content-graph-pro' );
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
			<h2><?php esc_html_e( 'Registration Management Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered regulatory registration management for multi-country submissions. Track registration status, timelines, documents, and compliance requirements with comprehensive AI assistance.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Multi-Country Registration: Manage registrations across multiple regulatory authorities', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Status Tracking: Monitor registration status from submission to approval', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Document Management: Track required documents and expiry dates', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Timeline Management: Track submission, review, and approval dates', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Compliance Validation: Validate requirements and document checklists', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Renewal Tracking: Monitor expiry dates and renewal requirements', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Research & Add: AI-assisted registration creation and research', 'nvoos-content-graph-pro' ); ?></li>
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
			'create_registration'           => __( 'Create Registration', 'nvoos-content-graph-pro' ),
			'list_registrations'            => __( 'List Registrations', 'nvoos-content-graph-pro' ),
			'get_registration'              => __( 'Get Registration', 'nvoos-content-graph-pro' ),
			'update_registration_status'    => __( 'Update Registration Status', 'nvoos-content-graph-pro' ),
			'list_expiring_registrations'   => __( 'List Expiring Registrations', 'nvoos-content-graph-pro' ),
			'submit_registration'           => __( 'Submit Registration', 'nvoos-content-graph-pro' ),
			'approve_registration'          => __( 'Approve Registration', 'nvoos-content-graph-pro' ),
			'renew_registration'            => __( 'Renew Registration', 'nvoos-content-graph-pro' ),
			'get_registration_timeline'     => __( 'Get Registration Timeline', 'nvoos-content-graph-pro' ),
			'list_registrations_by_country' => __( 'List Registrations by Country', 'nvoos-content-graph-pro' ),
			'list_reg_products'             => __( 'List Regulatory Products', 'nvoos-content-graph-pro' ),
			'get_reg_product'               => __( 'Get Regulatory Product', 'nvoos-content-graph-pro' ),
			'check_product_compliance'      => __( 'Check Product Compliance', 'nvoos-content-graph-pro' ),
			'web_search'                    => __( 'Web Search', 'nvoos-content-graph-pro' ),
		);
	}
}

// Initialize settings page.
new WP_MCP_AI_Registration_Settings_Page();

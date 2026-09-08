<?php
/**
 * E-commerce Toolkit Settings Page (ecosystem port — Wave F2, e-commerce admin pages batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/admin/class-wp-mcp-ai-ecommerce-settings-page.php` for the standalone `nvoos-content-graph-pro` addon. Kept
 * byte-identical. The base Pro addon owns the class in monolith installs —
 * the addon's autoloader skips its copy when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`; the `NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/admin/class-wp-mcp-ai-toolkit-settings-base.php'` require resolves from the addon's already-ported `src/admin/class-wp-mcp-ai-toolkit-settings-base.php`.
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
 * E-commerce Toolkit Settings Page Class
 */
class WP_MCP_AI_Ecommerce_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'ecommerce';
		$this->toolkit_name     = __( 'E-commerce Toolkit', 'nvoos-content-graph-pro' );
		$this->option_name      = 'wp_mcp_ai_ecommerce_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-ecommerce-toolkit-settings';
		$this->parent_slug      = 'wp-mcp-ai-ecommerce-toolkit'; // Separate E-Commerce Toolkit menu.
		$this->has_research     = false; // Research & Add has dedicated submenu page.
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-cart';

		// Add top-level menu at priority 25 to register before submenu pages.
		// This ensures the parent menu exists when submenu pages are added at priorities 26+.
		add_action( 'admin_menu', array( $this, 'add_top_level_menu' ), 25 );

		parent::__construct();
	}

	/**
	 * Add top-level E-Commerce Toolkit menu.
	 */
	public function add_top_level_menu() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_menu_page(
			__( 'E-Commerce Toolkit', 'nvoos-content-graph-pro' ),
			__( 'E-Commerce Toolkit', 'nvoos-content-graph-pro' ),
			'edit_products',
			$this->parent_slug,
			array( $this, 'redirect_to_first_submenu' ),
			$this->icon,
			56 // Position after WooCommerce (55).
		);
	}

	/**
	 * Redirect to first submenu page.
	 *
	 * This prevents the parent menu from showing an empty page.
	 */
	public function redirect_to_first_submenu() {
		// Redirect to settings page (first submenu).
		wp_safe_redirect( admin_url( 'admin.php?page=' . $this->page_slug ) );
		exit;
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
			<h2><?php esc_html_e( 'E-commerce Toolkit Overview', 'nvoos-content-graph-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Advanced WooCommerce integration toolkit providing 22 powerful tools for managing products, orders, inventory, and customers.', 'nvoos-content-graph-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Product Management: Create, update, import, and export products in bulk', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Order Processing: Process orders, generate invoices, and manage workflows', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Inventory Tracking: Monitor stock levels, forecast demand, and automate alerts', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Customer Management: Segment customers, analyze lifetime value, and export data', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Analytics & Reporting: Sales performance dashboards and order analytics', 'nvoos-content-graph-pro' ); ?></li>
				<li><?php esc_html_e( 'Marketing: Abandoned cart recovery, discount campaigns, and upsell recommendations', 'nvoos-content-graph-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Requirements', 'nvoos-content-graph-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'WooCommerce:', 'nvoos-content-graph-pro' ); ?></strong> <?php echo class_exists( 'WooCommerce' ) ? '<span style="color: green;">✓ Active</span>' : '<span style="color: red;">✗ Not Installed</span>'; ?></li>
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
			<h2><?php esc_html_e( 'E-commerce Toolkit Configuration', 'nvoos-content-graph-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Currency', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="text" name="default_currency" value="USD" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Default currency for pricing and reports', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Low Stock Threshold', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<input type="number" name="low_stock_threshold" value="5" min="0" class="small-text" />
						<p class="description"><?php esc_html_e( 'Trigger alerts when inventory falls below this level', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Multi-Store Sync', 'nvoos-content-graph-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_multistore" value="1" />
							<?php esc_html_e( 'Synchronize inventory across multiple stores (requires Remote Sites)', 'nvoos-content-graph-pro' ); ?>
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
			'create_product_advanced'     => __( 'Create Product (Advanced)', 'nvoos-content-graph-pro' ),
			'bulk_update_products'        => __( 'Bulk Update Products', 'nvoos-content-graph-pro' ),
			'update_woo_product_price'    => __( 'Update Product Price (All Types)', 'nvoos-content-graph-pro' ),
			'update_woo_product_qty'      => __( 'Update Product Quantity (All Types)', 'nvoos-content-graph-pro' ),
			'import_products_csv'         => __( 'Import Products from CSV', 'nvoos-content-graph-pro' ),
			'export_products_report'      => __( 'Export Products Report', 'nvoos-content-graph-pro' ),
			'sync_product_inventory'      => __( 'Sync Product Inventory', 'nvoos-content-graph-pro' ),
			'process_order_workflow'      => __( 'Process Order Workflow', 'nvoos-content-graph-pro' ),
			'bulk_order_status_update'    => __( 'Bulk Order Status Update', 'nvoos-content-graph-pro' ),
			'refund_order_advanced'       => __( 'Refund Order (Advanced)', 'nvoos-content-graph-pro' ),
			'generate_invoice_pdf'        => __( 'Generate Invoice PDF', 'nvoos-content-graph-pro' ),
			'get_order_analytics'         => __( 'Get Order Analytics', 'nvoos-content-graph-pro' ),
			'track_inventory_movement'    => __( 'Track Inventory Movement', 'nvoos-content-graph-pro' ),
			'low_stock_alert_automation'  => __( 'Low Stock Alert Automation', 'nvoos-content-graph-pro' ),
			'inventory_forecast'          => __( 'Inventory Forecast', 'nvoos-content-graph-pro' ),
			'segment_customers'           => __( 'Segment Customers', 'nvoos-content-graph-pro' ),
			'customer_lifetime_value'     => __( 'Customer Lifetime Value', 'nvoos-content-graph-pro' ),
			'export_customer_data'        => __( 'Export Customer Data', 'nvoos-content-graph-pro' ),
			'sales_performance_dashboard' => __( 'Sales Performance Dashboard', 'nvoos-content-graph-pro' ),
			'abandoned_cart_recovery'     => __( 'Abandoned Cart Recovery', 'nvoos-content-graph-pro' ),
			'create_discount_campaign'    => __( 'Create Discount Campaign', 'nvoos-content-graph-pro' ),
			'upsell_recommendations'      => __( 'Upsell Recommendations', 'nvoos-content-graph-pro' ),
		);
	}
}

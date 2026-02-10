<?php
/**
 * E-commerce Toolkit Settings Page
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-toolkit-settings-base.php';

/**
 * E-commerce Toolkit Settings Page Class
 */
class WP_MCP_AI_Ecommerce_Settings_Page extends WP_MCP_AI_Toolkit_Settings_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->toolkit_slug     = 'ecommerce';
		$this->toolkit_name     = __( 'E-commerce Toolkit', 'mcp-ai-wpoos-pro' );
		$this->option_name      = 'wp_mcp_ai_ecommerce_toolkit_settings';
		$this->page_slug        = 'wp-mcp-ai-ecommerce-toolkit-settings';
		$this->parent_slug      = 'wp-mcp-ai-ecommerce-toolkit'; // Separate E-Commerce Toolkit menu.
		$this->has_research     = true;
		$this->has_remote_sites = true;
		$this->icon             = 'dashicons-cart';

		// Add top-level menu.
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
			__( 'E-Commerce Toolkit', 'mcp-ai-wpoos-pro' ),
			__( 'E-Commerce Toolkit', 'mcp-ai-wpoos-pro' ),
			'edit_products',
			$this->parent_slug,
			'__return_false', // No callback for top-level, first submenu will be default.
			$this->icon,
			56 // Position after WooCommerce (55).
		);
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
			<h2><?php esc_html_e( 'E-commerce Toolkit Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'Advanced WooCommerce integration toolkit providing 20 powerful tools for managing products, orders, inventory, and customers.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Product Management: Create, update, import, and export products in bulk', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Order Processing: Process orders, generate invoices, and manage workflows', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Inventory Tracking: Monitor stock levels, forecast demand, and automate alerts', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Customer Management: Segment customers, analyze lifetime value, and export data', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Analytics & Reporting: Sales performance dashboards and order analytics', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Marketing: Abandoned cart recovery, discount campaigns, and upsell recommendations', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Requirements', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'WooCommerce:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo class_exists( 'WooCommerce' ) ? '<span style="color: green;">✓ Active</span>' : '<span style="color: red;">✗ Not Installed</span>'; ?></li>
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
			<h2><?php esc_html_e( 'E-commerce Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Currency', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="text" name="default_currency" value="USD" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Default currency for pricing and reports', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Low Stock Threshold', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" name="low_stock_threshold" value="5" min="0" class="small-text" />
						<p class="description"><?php esc_html_e( 'Trigger alerts when inventory falls below this level', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Multi-Store Sync', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_multistore" value="1" />
							<?php esc_html_e( 'Synchronize inventory across multiple stores (requires Remote Sites)', 'mcp-ai-wpoos-pro' ); ?>
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
			'create_product_advanced'     => __( 'Create Product (Advanced)', 'mcp-ai-wpoos-pro' ),
			'bulk_update_products'        => __( 'Bulk Update Products', 'mcp-ai-wpoos-pro' ),
			'import_products_csv'         => __( 'Import Products from CSV', 'mcp-ai-wpoos-pro' ),
			'export_products_report'      => __( 'Export Products Report', 'mcp-ai-wpoos-pro' ),
			'sync_product_inventory'      => __( 'Sync Product Inventory', 'mcp-ai-wpoos-pro' ),
			'process_order_workflow'      => __( 'Process Order Workflow', 'mcp-ai-wpoos-pro' ),
			'bulk_order_status_update'    => __( 'Bulk Order Status Update', 'mcp-ai-wpoos-pro' ),
			'refund_order_advanced'       => __( 'Refund Order (Advanced)', 'mcp-ai-wpoos-pro' ),
			'generate_invoice_pdf'        => __( 'Generate Invoice PDF', 'mcp-ai-wpoos-pro' ),
			'get_order_analytics'         => __( 'Get Order Analytics', 'mcp-ai-wpoos-pro' ),
			'track_inventory_movement'    => __( 'Track Inventory Movement', 'mcp-ai-wpoos-pro' ),
			'low_stock_alert_automation'  => __( 'Low Stock Alert Automation', 'mcp-ai-wpoos-pro' ),
			'inventory_forecast'          => __( 'Inventory Forecast', 'mcp-ai-wpoos-pro' ),
			'segment_customers'           => __( 'Segment Customers', 'mcp-ai-wpoos-pro' ),
			'customer_lifetime_value'     => __( 'Customer Lifetime Value', 'mcp-ai-wpoos-pro' ),
			'export_customer_data'        => __( 'Export Customer Data', 'mcp-ai-wpoos-pro' ),
			'sales_performance_dashboard' => __( 'Sales Performance Dashboard', 'mcp-ai-wpoos-pro' ),
			'abandoned_cart_recovery'     => __( 'Abandoned Cart Recovery', 'mcp-ai-wpoos-pro' ),
			'create_discount_campaign'    => __( 'Create Discount Campaign', 'mcp-ai-wpoos-pro' ),
			'upsell_recommendations'      => __( 'Upsell Recommendations', 'mcp-ai-wpoos-pro' ),
		);
	}
}

// Initialize settings page.
if ( is_admin() ) {
	new WP_MCP_AI_Ecommerce_Settings_Page();
}

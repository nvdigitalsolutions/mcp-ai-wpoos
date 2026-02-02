<?php
/**
 * Product Settings Page
 *
 * Provides settings page for configuring AI provider, model, and assistant
 * for WooCommerce Product Research & Add functionality.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load base class.
require_once WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-cpt-settings-page-base.php';

/**
 * Product Settings Page
 */
class WP_MCP_AI_Product_Settings_Page extends WP_MCP_AI_CPT_Settings_Page_Base {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->option_name = 'wp_mcp_ai_product_settings';
		$this->post_type   = 'product';
		$this->page_title  = __( 'Product Research Settings', 'mcp-ai-wpoos-pro' );
		$this->menu_title  = __( 'Research Settings', 'mcp-ai-wpoos-pro' );
		$this->page_slug   = 'product-research-settings';

		// Call parent constructor to set up hooks.
		parent::__construct();
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Add product-specific settings.
		add_settings_field(
			'enable_research',
			__( 'Enable Research & Add', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_research_field' ),
			$this->option_name,
			$this->option_name . '_section'
		);
	}

	/**
	 * Render enable research field.
	 */
	public function render_enable_research_field() {
		$options = get_option( $this->option_name, array() );
		$value   = isset( $options['enable_research'] ) ? (bool) $options['enable_research'] : true;

		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( $this->option_name ); ?>[enable_research]"
				id="enable_research_product"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Enable the Research & Add page for product research', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, users can access the Research & Add page to create WooCommerce products using AI assistance.', 'mcp-ai-wpoos-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Render overview tab.
	 */
	protected function render_overview_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'Product Research & Add Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			
			<div class="toolkit-description">
				<p><?php esc_html_e( 'AI-powered WooCommerce product creation and management. Research products, scrape competitor data, and create fully optimized product listings with AI assistance.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>

			<h3><?php esc_html_e( 'Key Features', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><?php esc_html_e( 'Product Research: Research and gather data for new products', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Competitor Analysis: Scrape and analyze competitor product listings', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'AI Content Generation: Create compelling product descriptions and metadata', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Pricing Optimization: Get pricing recommendations based on market research', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'Bulk Operations: Import and manage multiple products efficiently', 'mcp-ai-wpoos-pro' ); ?></li>
				<li><?php esc_html_e( 'SEO Optimization: Automatically optimize product listings for search', 'mcp-ai-wpoos-pro' ); ?></li>
			</ul>

			<h3><?php esc_html_e( 'Requirements', 'mcp-ai-wpoos-pro' ); ?></h3>
			<ul>
				<li><strong><?php esc_html_e( 'WooCommerce:', 'mcp-ai-wpoos-pro' ); ?></strong> <?php echo class_exists( 'WooCommerce' ) ? '<span style="color: green;">✓ Active</span>' : '<span style="color: red;">✗ Not Installed</span>'; ?></li>
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
			'create_woo_product'           => __( 'Create Product', 'mcp-ai-wpoos-pro' ),
			'create_woo_product_validated' => __( 'Create Product (Validated)', 'mcp-ai-wpoos-pro' ),
			'get_woo_products'             => __( 'Get Products', 'mcp-ai-wpoos-pro' ),
			'scrape_product_validated'     => __( 'Scrape Product (Validated)', 'mcp-ai-wpoos-pro' ),
			'research_product'             => __( 'Research Product', 'mcp-ai-wpoos-pro' ),
			'lookup_product_price'         => __( 'Lookup Product Price', 'mcp-ai-wpoos-pro' ),
			'bulk_update_products'         => __( 'Bulk Update Products', 'mcp-ai-wpoos-pro' ),
			'import_products_csv'          => __( 'Import Products (CSV)', 'mcp-ai-wpoos-pro' ),
			'create_product_advanced'      => __( 'Create Product (Advanced)', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields.
		$sanitized = parent::sanitize_settings( $input );

		// Add product-specific sanitization.
		if ( isset( $input['enable_research'] ) ) {
			$sanitized['enable_research'] = (bool) $input['enable_research'];
		} else {
			$sanitized['enable_research'] = false;
		}

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Product_Settings_Page();

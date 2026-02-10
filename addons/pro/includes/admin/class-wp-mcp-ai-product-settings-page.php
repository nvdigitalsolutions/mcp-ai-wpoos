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
	 * Override parent method to add submenu under E-Commerce Toolkit instead of Products menu.
	 */
	public function add_settings_page() {
		// Add submenu page under E-Commerce Toolkit menu.
		add_submenu_page(
			'wp-mcp-ai-ecommerce-toolkit',
			$this->page_title,
			$this->menu_title,
			'manage_options',
			$this->page_slug,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render settings page with Configuration tab support.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Get active tab.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Check for settings update.
		if ( isset( $_GET['settings-updated'] ) && in_array( $active_tab, array( 'settings', 'configuration' ), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error(
				$this->option_name . '_messages',
				$this->option_name . '_message',
				__( 'Settings saved successfully.', 'mcp-ai-wpoos-pro' ),
				'success'
			);
		}

		settings_errors( $this->option_name . '_messages' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->page_title ); ?></h1>

			<?php $this->render_tabs( $active_tab ); ?>

			<?php
			switch ( $active_tab ) {
				case 'overview':
					$this->render_overview_tab();
					break;
				case 'configuration':
					$this->render_configuration_tab();
					break;
				case 'tools':
					$this->render_tools_tab();
					break;
				case 'settings':
				default:
					$this->render_settings_tab();
					break;
			}
			?>
		</div>

		<style>
			.nav-tab-wrapper {
				border-bottom: 1px solid #ccd0d4;
				margin: 13px 0;
			}
			.toolkit-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				box-shadow: 0 1px 1px rgba(0,0,0,.04);
				padding: 20px;
				margin: 20px 0;
			}
			.toolkit-card h2 {
				margin-top: 0;
			}
			.tool-item {
				padding: 10px;
				border-bottom: 1px solid #f0f0f1;
			}
			.tool-item:last-child {
				border-bottom: none;
			}
			.tool-item strong {
				display: inline-block;
				min-width: 250px;
			}
		</style>
		<?php
	}

	/**
	 * Render tab navigation with Configuration tab.
	 *
	 * @param string $active_tab Active tab slug.
	 */
	protected function render_tabs( $active_tab ) {
		$tabs = array(
			'settings' => __( 'Settings', 'mcp-ai-wpoos-pro' ),
		);

		// Add Overview tab.
		if ( method_exists( $this, 'render_overview_tab' ) ) {
			$tabs = array( 'overview' => __( 'Overview', 'mcp-ai-wpoos-pro' ) ) + $tabs;
		}

		// Add Configuration tab.
		if ( method_exists( $this, 'render_configuration_tab' ) ) {
			$tabs['configuration'] = __( 'Configuration', 'mcp-ai-wpoos-pro' );
		}

		// Add Tools tab.
		if ( method_exists( $this, 'get_tools_list' ) ) {
			$tabs['tools'] = __( 'Available Tools', 'mcp-ai-wpoos-pro' );
		}

		if ( count( $tabs ) <= 1 ) {
			return; // No tabs if only settings.
		}

		?>
		<nav class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_slug => $tab_title ) : ?>
				<a
					href="<?php echo esc_url( add_query_arg( 'tab', $tab_slug ) ); ?>"
					class="nav-tab <?php echo $active_tab === $tab_slug ? 'nav-tab-active' : ''; ?>"
				>
					<?php echo esc_html( $tab_title ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		// Call parent to register base fields (assistant).
		parent::register_settings();

		// Register E-commerce toolkit configuration settings.
		register_setting(
			$this->option_name . '_group',
			'wp_mcp_ai_ecommerce_toolkit_settings',
			array(
				'sanitize_callback' => array( $this, 'sanitize_ecommerce_settings' ),
			)
		);

		// Add e-commerce configuration section.
		add_settings_section(
			'ecommerce_config_section',
			__( 'E-commerce Configuration', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_ecommerce_config_description' ),
			'wp_mcp_ai_ecommerce_config'
		);

		// Add configuration fields.
		add_settings_field(
			'default_currency',
			__( 'Default Currency', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_default_currency_field' ),
			'wp_mcp_ai_ecommerce_config',
			'ecommerce_config_section'
		);

		add_settings_field(
			'low_stock_threshold',
			__( 'Low Stock Threshold', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_low_stock_threshold_field' ),
			'wp_mcp_ai_ecommerce_config',
			'ecommerce_config_section'
		);

		add_settings_field(
			'enable_multistore',
			__( 'Enable Multi-Store Sync', 'mcp-ai-wpoos-pro' ),
			array( $this, 'render_enable_multistore_field' ),
			'wp_mcp_ai_ecommerce_config',
			'ecommerce_config_section'
		);
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
	 * Render e-commerce configuration description.
	 */
	public function render_ecommerce_config_description() {
		echo '<p>' . esc_html__( 'Configure default settings for e-commerce operations and product management.', 'mcp-ai-wpoos-pro' ) . '</p>';
	}

	/**
	 * Render default currency field.
	 */
	public function render_default_currency_field() {
		$options = get_option( 'wp_mcp_ai_ecommerce_toolkit_settings', array() );
		$value   = isset( $options['default_currency'] ) ? $options['default_currency'] : 'USD';
		?>
		<input
			type="text"
			name="wp_mcp_ai_ecommerce_toolkit_settings[default_currency]"
			id="default_currency"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		/>
		<p class="description"><?php esc_html_e( 'Default currency for pricing and reports (e.g., USD, EUR, GBP)', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render low stock threshold field.
	 */
	public function render_low_stock_threshold_field() {
		$options = get_option( 'wp_mcp_ai_ecommerce_toolkit_settings', array() );
		$value   = isset( $options['low_stock_threshold'] ) ? absint( $options['low_stock_threshold'] ) : 5;
		?>
		<input
			type="number"
			name="wp_mcp_ai_ecommerce_toolkit_settings[low_stock_threshold]"
			id="low_stock_threshold"
			value="<?php echo esc_attr( $value ); ?>"
			min="0"
			class="small-text"
		/>
		<p class="description"><?php esc_html_e( 'Trigger alerts when inventory falls below this level', 'mcp-ai-wpoos-pro' ); ?></p>
		<?php
	}

	/**
	 * Render enable multistore field.
	 */
	public function render_enable_multistore_field() {
		$options = get_option( 'wp_mcp_ai_ecommerce_toolkit_settings', array() );
		$value   = isset( $options['enable_multistore'] ) ? (bool) $options['enable_multistore'] : false;
		?>
		<label>
			<input
				type="checkbox"
				name="wp_mcp_ai_ecommerce_toolkit_settings[enable_multistore]"
				id="enable_multistore"
				value="1"
				<?php checked( $value, true ); ?>
			/>
			<?php esc_html_e( 'Synchronize inventory across multiple stores (requires Remote Sites)', 'mcp-ai-wpoos-pro' ); ?>
		</label>
		<?php
	}

	/**
	 * Render configuration tab.
	 */
	protected function render_configuration_tab() {
		?>
		<div class="toolkit-card">
			<h2><?php esc_html_e( 'E-commerce Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<form method="post" action="options.php">
				<?php
				settings_fields( $this->option_name . '_group' );
				do_settings_sections( 'wp_mcp_ai_ecommerce_config' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Sanitize e-commerce settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_ecommerce_settings( $input ) {
		$sanitized = array();

		if ( isset( $input['default_currency'] ) ) {
			$sanitized['default_currency'] = sanitize_text_field( $input['default_currency'] );
		}

		if ( isset( $input['low_stock_threshold'] ) ) {
			$sanitized['low_stock_threshold'] = absint( $input['low_stock_threshold'] );
		}

		if ( isset( $input['enable_multistore'] ) ) {
			$sanitized['enable_multistore'] = (bool) $input['enable_multistore'];
		} else {
			$sanitized['enable_multistore'] = false;
		}

		return $sanitized;
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		// Call parent sanitization for base fields (assistant configuration).
		$sanitized = parent::sanitize_settings( $input );

		// No additional product-specific sanitization needed.
		// The enable_research field has been removed as Research & Add is now always available.

		return $sanitized;
	}
}

// Initialize.
new WP_MCP_AI_Product_Settings_Page();

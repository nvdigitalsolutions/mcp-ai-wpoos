<?php
/**
 * WooCommerce Integration Admin Page
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_WooCommerce_Integration' ) ) {
	/**
	 * Manages the WooCommerce integration admin page.
	 */
	class WP_MCP_AI_Admin_WooCommerce_Integration {
		const PAGE_SLUG = 'wp-mcp-ai-woocommerce';

		/**
		 * Page hook suffix.
		 *
		 * @var string
		 */
		private $page_hook = '';

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_action( 'admin_menu', array( $this, 'register_page' ) );
			add_action( 'admin_post_wp_mcp_ai_save_woocommerce_settings', array( $this, 'handle_save_settings' ) );
		}

		/**
		 * Handle settings save.
		 */
		public function handle_save_settings() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_save_woocommerce_settings' );

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			$settings['enable_woocommerce_tools'] = isset( $_POST['enable_woocommerce_tools'] ) ? true : false;
			$settings['enable_woo_analytics']     = isset( $_POST['enable_woo_analytics'] ) ? true : false;

			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'updated' => 'true',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/**
		 * Register the integration page under the NV oOS menu.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'WooCommerce Integration - NV oOS', 'mcp-ai-wpoos' ),
				__( 'WooCommerce', 'mcp-ai-wpoos' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Render the integration page.
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$woo_active        = class_exists( 'WooCommerce' );
			$settings          = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$tools_enabled     = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;
			$analytics_enabled = isset( $settings['enable_woo_analytics'] ) ? (bool) $settings['enable_woo_analytics'] : true;

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'WooCommerce Integration', 'mcp-ai-wpoos' ); ?></h1>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'mcp-ai-wpoos' ); ?></p>
					</div>
				<?php endif; ?>

				<div style="background: <?php echo esc_attr( $woo_active ? '#d5f0db' : '#f0f0f1' ); ?>; border-left: 4px solid <?php echo esc_attr( $woo_active ? '#0a5f1a' : '#646970' ); ?>; padding: 1.5rem; margin: 1.5rem 0;">
					<?php if ( $woo_active ) : ?>
						<h2 style="margin-top: 0; color: #0a5f1a;">✓ <?php esc_html_e( 'WooCommerce Active', 'mcp-ai-wpoos' ); ?></h2>
						<p><?php esc_html_e( 'WooCommerce is installed and active. E-commerce AI tools are available.', 'mcp-ai-wpoos' ); ?></p>
						<?php if ( defined( 'WC_VERSION' ) ) : ?>
							<p><strong><?php esc_html_e( 'Version:', 'mcp-ai-wpoos' ); ?></strong> <?php echo esc_html( WC_VERSION ); ?></p>
						<?php endif; ?>
					<?php else : ?>
						<h2 style="margin-top: 0; color: #646970;"><?php esc_html_e( 'WooCommerce Not Active', 'mcp-ai-wpoos' ); ?></h2>
						<p><?php esc_html_e( 'WooCommerce is not installed or not active.', 'mcp-ai-wpoos' ); ?></p>
						<p><strong><?php esc_html_e( 'To enable WooCommerce integration:', 'mcp-ai-wpoos' ); ?></strong></p>
						<ol>
							<li><?php esc_html_e( 'Install WooCommerce from WordPress.org', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Activate the plugin', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Return to this page to configure integration settings', 'mcp-ai-wpoos' ); ?></li>
						</ol>
					<?php endif; ?>
				</div>

				<?php if ( $woo_active ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wp_mcp_ai_save_woocommerce_settings' ); ?>
						<input type="hidden" name="action" value="wp_mcp_ai_save_woocommerce_settings" />

						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable AI Tools', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="enable_woocommerce_tools" value="1" <?php checked( $tools_enabled ); ?> />
										<?php esc_html_e( 'Activate WooCommerce AI tools for product and order management', 'mcp-ai-wpoos' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Enables AI tools for creating/updating products, managing inventory, and processing orders.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Enable Analytics', 'mcp-ai-wpoos' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="enable_woo_analytics" value="1" <?php checked( $analytics_enabled ); ?> />
										<?php esc_html_e( 'Allow AI to query sales data and revenue metrics', 'mcp-ai-wpoos' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Enables AI access to WooCommerce analytics, sales reports, and customer data.', 'mcp-ai-wpoos' ); ?>
									</p>
								</td>
							</tr>
						</table>

						<h2><?php esc_html_e( 'Available WooCommerce Tools', 'mcp-ai-wpoos' ); ?></h2>
						<table class="widefat" style="margin-top: 1rem;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Tool Name', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos' ); ?></th>
									<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<tr>
									<td><code>woo_create_product</code></td>
									<td><?php esc_html_e( 'Create new products with full metadata', 'mcp-ai-wpoos' ); ?></td>
									<td><?php echo $tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><code>woo_update_product</code></td>
									<td><?php esc_html_e( 'Update existing product details and pricing', 'mcp-ai-wpoos' ); ?></td>
									<td><?php echo $tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><code>woo_query_orders</code></td>
									<td><?php esc_html_e( 'Search and analyze order data', 'mcp-ai-wpoos' ); ?></td>
									<td><?php echo $analytics_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><code>woo_get_analytics</code></td>
									<td><?php esc_html_e( 'Retrieve sales metrics and revenue reports', 'mcp-ai-wpoos' ); ?></td>
									<td><?php echo $analytics_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>'; ?></td>
								</tr>
								<tr>
									<td><code>woo_manage_inventory</code></td>
									<td><?php esc_html_e( 'Track and update product stock levels', 'mcp-ai-wpoos' ); ?></td>
									<td><?php echo $tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . esc_html__( 'Active', 'mcp-ai-wpoos' ) . '</span>' : '<span style="color: #646970;">' . esc_html__( 'Disabled', 'mcp-ai-wpoos' ) . '</span>'; ?></td>
								</tr>
							</tbody>
						</table>

						<div style="background: #fef7e0; border-left: 4px solid #8b6c00; padding: 1rem; margin-top: 1.5rem;">
							<p style="margin: 0;"><strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos' ); ?></strong> <?php esc_html_e( 'WooCommerce tools are available only in Full Version mode. Install the NV oOS Pro add-on plugin to enable.', 'mcp-ai-wpoos' ); ?></p>
						</div>

						<?php submit_button( __( 'Save WooCommerce Settings', 'mcp-ai-wpoos' ) ); ?>
					</form>
				<?php endif; ?>
			</div>
			<?php
		}
	}
}

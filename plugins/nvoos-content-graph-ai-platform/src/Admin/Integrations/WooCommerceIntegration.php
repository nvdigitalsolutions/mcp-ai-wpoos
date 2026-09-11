<?php
/**
 * WooCommerce integration screen (Wave E-UI-3, sub-cluster 3).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Admin_WooCommerce_Integration`
 * (`includes/admin/class-wp-mcp-ai-admin-woocommerce-integration.php`):
 * byte-identical page surface — the `wp-mcp-ai-woocommerce` page
 * slug, the `admin_post_wp_mcp_ai_save_woocommerce_settings` handler
 * with its nonce action, the active/inactive status banner (WooCommerce
 * class + WC_VERSION probes), the tools + analytics checkbox form,
 * the five-tool status table, the Full-Version note, and the save
 * redirect.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Integrations\`
 *    (the E-UI-3 wave folder).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via
 *    `Plugin::registerIntegrationsScreens()`; the base admin owns the
 *    same page monolith (the class files are not wired by the base
 *    loader — documented).
 *  - The settings store resolves per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator — the base
 *    `WP_MCP_AI_Admin_Settings::OPTION_NAME` monolith / the
 *    byte-identical `wp_mcp_ai_settings` option key standalone).
 *  - The third-party active probe (`class_exists( 'WooCommerce' )`)
 *    is byte-identical — it detects WooCommerce itself, not a
 *    base-owned class.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Integrations;

/**
 * Manages the WooCommerce integration admin page.
 *
 * @since 2.0.0
 */
class WooCommerceIntegration {

	/**
	 * Page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-woocommerce';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $page_hook = '';

	/**
	 * Register the page hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_page' ) );
		\add_action( 'admin_post_wp_mcp_ai_save_woocommerce_settings', array( $this, 'handle_save_settings' ) );
	}

	/**
	 * Settings option name (per-mode seam).
	 *
	 * @return string
	 */
	protected static function settings_option_name() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return \WP_MCP_AI_Admin_Settings::OPTION_NAME;
		}

		return 'wp_mcp_ai_settings';
	}

	/**
	 * Handle settings save.
	 *
	 * @return void
	 */
	public function handle_save_settings(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to access this page.', 'nvoos-content-graph-ai-platform' ) );
		}

		\check_admin_referer( 'wp_mcp_ai_save_woocommerce_settings' );

		$settings = \get_option( self::settings_option_name(), array() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer above.
		$settings['enable_woocommerce_tools'] = isset( $_POST['enable_woocommerce_tools'] ) ? true : false;
		$settings['enable_woo_analytics']     = isset( $_POST['enable_woo_analytics'] ) ? true : false;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		\update_option( self::settings_option_name(), $settings );

		\wp_safe_redirect(
			\add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				),
				\admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Register the integration page under the NV Platform menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$this->page_hook = \add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'WooCommerce Integration - NV oOS', 'nvoos-content-graph-ai-platform' ),
			__( 'WooCommerce', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the integration page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$woo_active        = \class_exists( 'WooCommerce' );
		$settings          = \get_option( self::settings_option_name(), array() );
		$tools_enabled     = isset( $settings['enable_woocommerce_tools'] ) ? (bool) $settings['enable_woocommerce_tools'] : true;
		$analytics_enabled = isset( $settings['enable_woo_analytics'] ) ? (bool) $settings['enable_woo_analytics'] : true;

		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'WooCommerce Integration', 'nvoos-content-graph-ai-platform' ); ?></h1>

			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			if ( isset( $_GET['updated'] ) && 'true' === \sanitize_key( \wp_unslash( $_GET['updated'] ) ) ) :
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php \esc_html_e( 'Settings saved successfully.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php endif; ?>

			<div style="background: <?php echo \esc_attr( $woo_active ? '#d5f0db' : '#f0f0f1' ); ?>; border-left: 4px solid <?php echo \esc_attr( $woo_active ? '#0a5f1a' : '#646970' ); ?>; padding: 1.5rem; margin: 1.5rem 0;">
				<?php if ( $woo_active ) : ?>
					<h2 style="margin-top: 0; color: #0a5f1a;">✓ <?php \esc_html_e( 'WooCommerce Active', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<p><?php \esc_html_e( 'WooCommerce is installed and active. E-commerce AI tools are available.', 'nvoos-content-graph-ai-platform' ); ?></p>
					<?php if ( \defined( 'WC_VERSION' ) ) : ?>
						<p><strong><?php \esc_html_e( 'Version:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php echo \esc_html( WC_VERSION ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<h2 style="margin-top: 0; color: #646970;"><?php \esc_html_e( 'WooCommerce Not Active', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<p><?php \esc_html_e( 'WooCommerce is not installed or not active.', 'nvoos-content-graph-ai-platform' ); ?></p>
					<p><strong><?php \esc_html_e( 'To enable WooCommerce integration:', 'nvoos-content-graph-ai-platform' ); ?></strong></p>
					<ol>
						<li><?php \esc_html_e( 'Install WooCommerce from WordPress.org', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Activate the plugin', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><?php \esc_html_e( 'Return to this page to configure integration settings', 'nvoos-content-graph-ai-platform' ); ?></li>
					</ol>
				<?php endif; ?>
			</div>

			<?php if ( $woo_active ) : ?>
				<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
					<?php \wp_nonce_field( 'wp_mcp_ai_save_woocommerce_settings' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_woocommerce_settings" />

					<table class="form-table">
						<tr>
							<th scope="row"><?php \esc_html_e( 'Enable AI Tools', 'nvoos-content-graph-ai-platform' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_woocommerce_tools" value="1" <?php \checked( $tools_enabled ); ?> />
									<?php \esc_html_e( 'Activate WooCommerce AI tools for product and order management', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
								<p class="description">
									<?php \esc_html_e( 'Enables AI tools for creating/updating products, managing inventory, and processing orders.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php \esc_html_e( 'Enable Analytics', 'nvoos-content-graph-ai-platform' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_woo_analytics" value="1" <?php \checked( $analytics_enabled ); ?> />
									<?php \esc_html_e( 'Allow AI to query sales data and revenue metrics', 'nvoos-content-graph-ai-platform' ); ?>
								</label>
								<p class="description">
									<?php \esc_html_e( 'Enables AI access to WooCommerce analytics, sales reports, and customer data.', 'nvoos-content-graph-ai-platform' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<h2><?php \esc_html_e( 'Available WooCommerce Tools', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<table class="widefat" style="margin-top: 1rem;">
						<thead>
							<tr>
								<th><?php \esc_html_e( 'Tool Name', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php \esc_html_e( 'Description', 'nvoos-content-graph-ai-platform' ); ?></th>
								<th><?php \esc_html_e( 'Status', 'nvoos-content-graph-ai-platform' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><code>woo_create_product</code></td>
								<td><?php \esc_html_e( 'Create new products with full metadata', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
							<tr>
								<td><code>woo_update_product</code></td>
								<td><?php \esc_html_e( 'Update existing product details and pricing', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
							<tr>
								<td><code>woo_query_orders</code></td>
								<td><?php \esc_html_e( 'Search and analyze order data', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$analytics_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
							<tr>
								<td><code>woo_get_analytics</code></td>
								<td><?php \esc_html_e( 'Retrieve sales metrics and revenue reports', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$analytics_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
							<tr>
								<td><code>woo_manage_inventory</code></td>
								<td><?php \esc_html_e( 'Track and update product stock levels', 'nvoos-content-graph-ai-platform' ); ?></td>
								<td>
								<?php
								echo \wp_kses(
									$tools_enabled ? '<span style="color: #0a5f1a;">✓ ' . \esc_html__( 'Active', 'nvoos-content-graph-ai-platform' ) . '</span>' : '<span style="color: #646970;">' . \esc_html__( 'Disabled', 'nvoos-content-graph-ai-platform' ) . '</span>',
									array( 'span' => array( 'style' => array() ) )
								);
								?>
							</td>
							</tr>
						</tbody>
					</table>

					<div style="background: #fef7e0; border-left: 4px solid #8b6c00; padding: 1rem; margin-top: 1.5rem;">
						<p style="margin: 0;"><strong><?php \esc_html_e( 'Note:', 'nvoos-content-graph-ai-platform' ); ?></strong> <?php \esc_html_e( 'WooCommerce tools are available only in Full Version mode. Install the NV oOS Pro add-on plugin to enable.', 'nvoos-content-graph-ai-platform' ); ?></p>
					</div>

					<?php \submit_button( __( 'Save WooCommerce Settings', 'nvoos-content-graph-ai-platform' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}

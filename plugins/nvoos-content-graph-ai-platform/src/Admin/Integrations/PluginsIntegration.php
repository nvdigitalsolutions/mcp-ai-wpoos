<?php
/**
 * Plugins integration screen (Wave E-UI-3, sub-cluster 4 — final).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Admin_Plugins_Integration`
 * (`includes/admin/class-wp-mcp-ai-admin-plugins-integration.php`):
 * byte-identical page surface — the `wp-mcp-ai-plugins` page slug,
 * the `admin_post_wp_mcp_ai_save_plugins_settings` handler with its
 * nonce action (and the byte-identical
 * `wp_mcp_ai_plugins_integration_redirect_terminate` filter that
 * test suites use to disable the terminating exit), the four-section
 * form (JetEngine, WooCommerce, Elementor, Newsletter) with per-plugin
 * active probes, warning notices, and disabled-state checkboxes, and
 * the save redirect.
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
 *  - The third-party active probes (Jet_Engine/WooCommerce/Newsletter
 *    classes + the `elementor/loaded` action) are byte-identical —
 *    they detect the third-party plugins themselves, not base-owned
 *    classes.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Integrations;

/**
 * Manages the Plugins integration admin page (JetEngine, WooCommerce,
 * Elementor, Newsletter).
 *
 * @since 2.0.0
 */
class PluginsIntegration {

	/**
	 * Page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-plugins';

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
		\add_action( 'admin_post_wp_mcp_ai_save_plugins_settings', array( $this, 'handle_save_settings' ) );
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
	 * Register the integration page under the NV Platform menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$this->page_hook = \add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'Plugins - NV oOS', 'nvoos-content-graph-ai-platform' ),
			__( 'Plugins', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
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

		\check_admin_referer( 'wp_mcp_ai_save_plugins_settings' );

		$settings = \get_option( self::settings_option_name(), array() );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer above.
		// JetEngine settings.
		$settings['enable_jetengine_cct']   = isset( $_POST['enable_jetengine_cct'] ) ? true : false;
		$settings['enable_jetengine_tools'] = isset( $_POST['enable_jetengine_tools'] ) ? true : false;

		// WooCommerce settings.
		$settings['enable_woocommerce_tools'] = isset( $_POST['enable_woocommerce_tools'] ) ? true : false;

		// Elementor settings.
		$settings['enable_elementor_widgets'] = isset( $_POST['enable_elementor_widgets'] ) ? true : false;

		// Newsletter settings.
		$settings['enable_newsletter_tools'] = isset( $_POST['enable_newsletter_tools'] ) ? true : false;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		\update_option( self::settings_option_name(), $settings );

		// Redirect back to the page with success message.
		\wp_safe_redirect(
			\add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 'true',
				),
				\admin_url( 'admin.php' )
			)
		);

		/**
		 * Filter whether the request should terminate after the redirect.
		 *
		 * Test suites disable the terminating exit so the handler can be
		 * exercised without killing the PHPUnit process.
		 *
		 * @since 1.1.69
		 *
		 * @param bool $terminate Whether to exit after redirecting. Default true.
		 */
		if ( ! \apply_filters( 'wp_mcp_ai_plugins_integration_redirect_terminate', true ) ) {
			return;
		}

		exit;
	}

	/**
	 * Render the integration page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$settings = \get_option( self::settings_option_name(), array() );

		// Get values with defaults.
		$enable_jetengine_cct     = isset( $settings['enable_jetengine_cct'] ) ? $settings['enable_jetengine_cct'] : false;
		$enable_jetengine_tools   = isset( $settings['enable_jetengine_tools'] ) ? $settings['enable_jetengine_tools'] : false;
		$enable_woocommerce_tools = isset( $settings['enable_woocommerce_tools'] ) ? $settings['enable_woocommerce_tools'] : false;
		$enable_elementor_widgets = isset( $settings['enable_elementor_widgets'] ) ? $settings['enable_elementor_widgets'] : false;
		$enable_newsletter_tools  = isset( $settings['enable_newsletter_tools'] ) ? $settings['enable_newsletter_tools'] : false;

		// Check if plugins are active.
		$jetengine_active   = \class_exists( 'Jet_Engine' );
		$woocommerce_active = \class_exists( 'WooCommerce' );
		$elementor_active   = (bool) \did_action( 'elementor/loaded' );
		$newsletter_active  = \class_exists( 'Newsletter' ) || \class_exists( 'NewsletterSubscription' );
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'Plugins Integration', 'nvoos-content-graph-ai-platform' ); ?></h1>

		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for success message display.
		if ( isset( $_GET['updated'] ) && 'true' === \sanitize_key( \wp_unslash( $_GET['updated'] ) ) ) :
			?>
			<div class="notice notice-success is-dismissible">
				<p><?php \esc_html_e( 'Settings saved successfully.', 'nvoos-content-graph-ai-platform' ); ?></p>
			</div>
			<?php
		endif;
		?>

			<p><?php \esc_html_e( 'Configure WordPress plugin integrations including JetEngine, WooCommerce, Elementor, and Newsletter.', 'nvoos-content-graph-ai-platform' ); ?></p>

			<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wp_mcp_ai_save_plugins_settings" />
				<?php \wp_nonce_field( 'wp_mcp_ai_save_plugins_settings' ); ?>

				<table class="form-table" role="presentation">
					<!-- JetEngine Section -->
					<tr>
						<td colspan="2">
							<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
								<span class="dashicons dashicons-admin-plugins"></span>
								<?php \esc_html_e( 'JetEngine', 'nvoos-content-graph-ai-platform' ); ?>
								<?php if ( ! $jetengine_active ) : ?>
									<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php \esc_attr_e( 'JetEngine plugin is not active', 'nvoos-content-graph-ai-platform' ); ?>"></span>
								<?php endif; ?>
							</h2>
							<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
						</td>
					</tr>
					<?php if ( ! $jetengine_active ) : ?>
						<tr>
							<td colspan="2">
								<div class="notice notice-warning inline">
									<p><?php \esc_html_e( 'JetEngine plugin is not active. Please install and activate JetEngine to use these features.', 'nvoos-content-graph-ai-platform' ); ?></p>
								</div>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">
							<?php \esc_html_e( 'Enable JetEngine CCT', 'nvoos-content-graph-ai-platform' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" name="enable_jetengine_cct" value="1" <?php \checked( $enable_jetengine_cct ); ?> <?php \disabled( ! $jetengine_active ); ?> />
								<?php \esc_html_e( 'Enable Custom Content Types integration', 'nvoos-content-graph-ai-platform' ); ?>
							</label>
							<p class="description"><?php \esc_html_e( 'Allow AI to interact with JetEngine Custom Content Types.', 'nvoos-content-graph-ai-platform' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<?php \esc_html_e( 'Enable JetEngine Tools', 'nvoos-content-graph-ai-platform' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" name="enable_jetengine_tools" value="1" <?php \checked( $enable_jetengine_tools ); ?> <?php \disabled( ! $jetengine_active ); ?> />
								<?php \esc_html_e( 'Enable JetEngine-specific tools', 'nvoos-content-graph-ai-platform' ); ?>
							</label>
							<p class="description"><?php \esc_html_e( 'Provides AI tools for managing JetEngine content.', 'nvoos-content-graph-ai-platform' ); ?></p>
						</td>
					</tr>

					<!-- WooCommerce Section -->
					<tr>
						<td colspan="2">
							<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
								<span class="dashicons dashicons-cart"></span>
								<?php \esc_html_e( 'WooCommerce', 'nvoos-content-graph-ai-platform' ); ?>
								<?php if ( ! $woocommerce_active ) : ?>
									<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php \esc_attr_e( 'WooCommerce plugin is not active', 'nvoos-content-graph-ai-platform' ); ?>"></span>
								<?php endif; ?>
							</h2>
							<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
						</td>
					</tr>
					<?php if ( ! $woocommerce_active ) : ?>
						<tr>
							<td colspan="2">
								<div class="notice notice-warning inline">
									<p><?php \esc_html_e( 'WooCommerce plugin is not active. Please install and activate WooCommerce to use these features.', 'nvoos-content-graph-ai-platform' ); ?></p>
								</div>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">
							<?php \esc_html_e( 'Enable WooCommerce Tools', 'nvoos-content-graph-ai-platform' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" name="enable_woocommerce_tools" value="1" <?php \checked( $enable_woocommerce_tools ); ?> <?php \disabled( ! $woocommerce_active ); ?> />
								<?php \esc_html_e( 'Enable WooCommerce-specific tools', 'nvoos-content-graph-ai-platform' ); ?>
							</label>
							<p class="description"><?php \esc_html_e( 'Provides AI tools for managing products, orders, and customers.', 'nvoos-content-graph-ai-platform' ); ?></p>
						</td>
					</tr>

					<!-- Elementor Section -->
					<tr>
						<td colspan="2">
							<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
								<span class="dashicons dashicons-editor-table"></span>
								<?php \esc_html_e( 'Elementor', 'nvoos-content-graph-ai-platform' ); ?>
								<?php if ( ! $elementor_active ) : ?>
									<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php \esc_attr_e( 'Elementor plugin is not active', 'nvoos-content-graph-ai-platform' ); ?>"></span>
								<?php endif; ?>
							</h2>
							<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
						</td>
					</tr>
					<?php if ( ! $elementor_active ) : ?>
						<tr>
							<td colspan="2">
								<div class="notice notice-warning inline">
									<p><?php \esc_html_e( 'Elementor plugin is not active. Please install and activate Elementor to use these features.', 'nvoos-content-graph-ai-platform' ); ?></p>
								</div>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">
							<?php \esc_html_e( 'Enable Elementor Widgets', 'nvoos-content-graph-ai-platform' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" name="enable_elementor_widgets" value="1" <?php \checked( $enable_elementor_widgets ); ?> <?php \disabled( ! $elementor_active ); ?> />
								<?php \esc_html_e( 'Enable AI-powered Elementor widgets', 'nvoos-content-graph-ai-platform' ); ?>
							</label>
							<p class="description"><?php \esc_html_e( 'Adds AI chat widgets and other AI-powered elements to Elementor.', 'nvoos-content-graph-ai-platform' ); ?></p>
						</td>
					</tr>

					<!-- Newsletter Section -->
					<tr>
						<td colspan="2">
							<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
								<span class="dashicons dashicons-email"></span>
								<?php \esc_html_e( 'Newsletter', 'nvoos-content-graph-ai-platform' ); ?>
								<?php if ( ! $newsletter_active ) : ?>
									<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php \esc_attr_e( 'Newsletter plugin is not active', 'nvoos-content-graph-ai-platform' ); ?>"></span>
								<?php endif; ?>
							</h2>
							<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
						</td>
					</tr>
					<?php if ( ! $newsletter_active ) : ?>
						<tr>
							<td colspan="2">
								<div class="notice notice-warning inline">
									<p><?php \esc_html_e( 'Newsletter plugin is not active. Please install and activate Newsletter to use these features.', 'nvoos-content-graph-ai-platform' ); ?></p>
								</div>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row">
							<?php \esc_html_e( 'Enable Newsletter Tools', 'nvoos-content-graph-ai-platform' ); ?>
						</th>
						<td>
							<label>
								<input type="checkbox" name="enable_newsletter_tools" value="1" <?php \checked( $enable_newsletter_tools ); ?> <?php \disabled( ! $newsletter_active ); ?> />
								<?php \esc_html_e( 'Enable Newsletter-specific tools', 'nvoos-content-graph-ai-platform' ); ?>
							</label>
							<p class="description"><?php \esc_html_e( 'Provides AI tools for managing newsletter subscribers, campaigns, and statistics. Includes 6 tools: add subscriber, get subscribers, unsubscribe, get stats, create email, and get emails.', 'nvoos-content-graph-ai-platform' ); ?></p>
						</td>
					</tr>
				</table>

				<?php \submit_button( __( 'Save Settings', 'nvoos-content-graph-ai-platform' ) ); ?>
			</form>
		</div>
		<?php
	}
}

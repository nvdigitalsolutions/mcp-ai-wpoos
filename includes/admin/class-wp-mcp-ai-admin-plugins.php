<?php
/**
 * Plugins Integration Admin Page
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Plugins_Integration' ) ) {
	/**
	 * Manages the Plugins integration admin page (JetEngine, WooCommerce, Elementor, Newsletter).
	 */
	class WP_MCP_AI_Admin_Plugins_Integration {
		const PAGE_SLUG = 'wp-mcp-ai-plugins';

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
			add_action( 'admin_post_wp_mcp_ai_save_plugins_settings', array( $this, 'handle_save_settings' ) );
		}

		/**
		 * Register the integration page under the WP oOS menu.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'Plugins - WP oOS', 'wp-mcp-ai' ),
				__( 'Plugins', 'wp-mcp-ai' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Handle settings save.
		 */
		public function handle_save_settings() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-mcp-ai' ) );
			}

			check_admin_referer( 'wp_mcp_ai_save_plugins_settings' );

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			// JetEngine settings.
			$settings['enable_jetengine_cct']   = isset( $_POST['enable_jetengine_cct'] ) ? true : false;
			$settings['enable_jetengine_tools'] = isset( $_POST['enable_jetengine_tools'] ) ? true : false;

			// WooCommerce settings.
			$settings['enable_woocommerce_tools'] = isset( $_POST['enable_woocommerce_tools'] ) ? true : false;

			// Elementor settings.
			$settings['enable_elementor_widgets'] = isset( $_POST['enable_elementor_widgets'] ) ? true : false;

			// Newsletter settings.
			$settings['enable_newsletter_tools'] = isset( $_POST['enable_newsletter_tools'] ) ? true : false;

			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

			// Redirect back to the page with success message.
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
		 * Render the integration page.
		 */
		public function render_page() {
			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			// Get values with defaults.
			$enable_jetengine_cct     = isset( $settings['enable_jetengine_cct'] ) ? $settings['enable_jetengine_cct'] : false;
			$enable_jetengine_tools   = isset( $settings['enable_jetengine_tools'] ) ? $settings['enable_jetengine_tools'] : false;
			$enable_woocommerce_tools = isset( $settings['enable_woocommerce_tools'] ) ? $settings['enable_woocommerce_tools'] : false;
			$enable_elementor_widgets = isset( $settings['enable_elementor_widgets'] ) ? $settings['enable_elementor_widgets'] : false;
			$enable_newsletter_tools  = isset( $settings['enable_newsletter_tools'] ) ? $settings['enable_newsletter_tools'] : false;

			// Check if plugins are active.
			$jetengine_active   = class_exists( 'Jet_Engine' );
			$woocommerce_active = class_exists( 'WooCommerce' );
			$elementor_active   = did_action( 'elementor/loaded' );
			$newsletter_active  = class_exists( 'Newsletter' ) || class_exists( 'NewsletterSubscription' );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Plugins Integration', 'wp-mcp-ai' ); ?></h1>

				<?php if ( isset( $_GET['updated'] ) && 'true' === $_GET['updated'] ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php endif; ?>

				<p><?php esc_html_e( 'Configure WordPress plugin integrations including JetEngine, WooCommerce, Elementor, and Newsletter.', 'wp-mcp-ai' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wp_mcp_ai_save_plugins_settings" />
					<?php wp_nonce_field( 'wp_mcp_ai_save_plugins_settings' ); ?>

					<table class="form-table" role="presentation">
						<!-- JetEngine Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-admin-plugins"></span>
									<?php esc_html_e( 'JetEngine', 'wp-mcp-ai' ); ?>
									<?php if ( ! $jetengine_active ) : ?>
										<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php esc_attr_e( 'JetEngine plugin is not active', 'wp-mcp-ai' ); ?>"></span>
									<?php endif; ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<?php if ( ! $jetengine_active ) : ?>
							<tr>
								<td colspan="2">
									<div class="notice notice-warning inline">
										<p><?php esc_html_e( 'JetEngine plugin is not active. Please install and activate JetEngine to use these features.', 'wp-mcp-ai' ); ?></p>
									</div>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Enable JetEngine CCT', 'wp-mcp-ai' ); ?>
							</th>
							<td>
								<label>
									<input type="checkbox" name="enable_jetengine_cct" value="1" <?php checked( $enable_jetengine_cct ); ?> <?php disabled( ! $jetengine_active ); ?> />
									<?php esc_html_e( 'Enable Custom Content Types integration', 'wp-mcp-ai' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Allow AI to interact with JetEngine Custom Content Types.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Enable JetEngine Tools', 'wp-mcp-ai' ); ?>
							</th>
							<td>
								<label>
									<input type="checkbox" name="enable_jetengine_tools" value="1" <?php checked( $enable_jetengine_tools ); ?> <?php disabled( ! $jetengine_active ); ?> />
									<?php esc_html_e( 'Enable JetEngine-specific tools', 'wp-mcp-ai' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Provides AI tools for managing JetEngine content.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- WooCommerce Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-cart"></span>
									<?php esc_html_e( 'WooCommerce', 'wp-mcp-ai' ); ?>
									<?php if ( ! $woocommerce_active ) : ?>
										<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php esc_attr_e( 'WooCommerce plugin is not active', 'wp-mcp-ai' ); ?>"></span>
									<?php endif; ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<?php if ( ! $woocommerce_active ) : ?>
							<tr>
								<td colspan="2">
									<div class="notice notice-warning inline">
										<p><?php esc_html_e( 'WooCommerce plugin is not active. Please install and activate WooCommerce to use these features.', 'wp-mcp-ai' ); ?></p>
									</div>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Enable WooCommerce Tools', 'wp-mcp-ai' ); ?>
							</th>
							<td>
								<label>
									<input type="checkbox" name="enable_woocommerce_tools" value="1" <?php checked( $enable_woocommerce_tools ); ?> <?php disabled( ! $woocommerce_active ); ?> />
									<?php esc_html_e( 'Enable WooCommerce-specific tools', 'wp-mcp-ai' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Provides AI tools for managing products, orders, and customers.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- Elementor Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-editor-table"></span>
									<?php esc_html_e( 'Elementor', 'wp-mcp-ai' ); ?>
									<?php if ( ! $elementor_active ) : ?>
										<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php esc_attr_e( 'Elementor plugin is not active', 'wp-mcp-ai' ); ?>"></span>
									<?php endif; ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<?php if ( ! $elementor_active ) : ?>
							<tr>
								<td colspan="2">
									<div class="notice notice-warning inline">
										<p><?php esc_html_e( 'Elementor plugin is not active. Please install and activate Elementor to use these features.', 'wp-mcp-ai' ); ?></p>
									</div>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Enable Elementor Widgets', 'wp-mcp-ai' ); ?>
							</th>
							<td>
								<label>
									<input type="checkbox" name="enable_elementor_widgets" value="1" <?php checked( $enable_elementor_widgets ); ?> <?php disabled( ! $elementor_active ); ?> />
									<?php esc_html_e( 'Enable AI-powered Elementor widgets', 'wp-mcp-ai' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Adds AI chat widgets and other AI-powered elements to Elementor.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- Newsletter Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-email"></span>
									<?php esc_html_e( 'Newsletter', 'wp-mcp-ai' ); ?>
									<?php if ( ! $newsletter_active ) : ?>
										<span class="dashicons dashicons-warning" style="color: #d63638;" title="<?php esc_attr_e( 'Newsletter plugin is not active', 'wp-mcp-ai' ); ?>"></span>
									<?php endif; ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<?php if ( ! $newsletter_active ) : ?>
							<tr>
								<td colspan="2">
									<div class="notice notice-warning inline">
										<p><?php esc_html_e( 'Newsletter plugin is not active. Please install and activate Newsletter to use these features.', 'wp-mcp-ai' ); ?></p>
									</div>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row">
								<?php esc_html_e( 'Enable Newsletter Tools', 'wp-mcp-ai' ); ?>
							</th>
							<td>
								<label>
									<input type="checkbox" name="enable_newsletter_tools" value="1" <?php checked( $enable_newsletter_tools ); ?> <?php disabled( ! $newsletter_active ); ?> />
									<?php esc_html_e( 'Enable Newsletter-specific tools', 'wp-mcp-ai' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Provides AI tools for managing newsletter subscribers, campaigns, and statistics. Includes 6 tools: add subscriber, get subscribers, unsubscribe, get stats, create email, and get emails.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Settings', 'wp-mcp-ai' ) ); ?>
				</form>
			</div>
			<?php
		}
	}
}

<?php
/**
 * External Tools Integration Admin Page
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Gmail_Crawl_Integration' ) ) {
	/**
	 * Manages the External Tools integration admin page (Gmail, Crawl4AI, and other third-party services).
	 */
	class WP_MCP_AI_Admin_Gmail_Crawl_Integration {
		const PAGE_SLUG = 'wp-mcp-ai-gmail-crawl4ai';

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
			add_action( 'admin_post_wp_mcp_ai_save_external_tools_settings', array( $this, 'handle_save_settings' ) );
		}

		/**
		 * Register the integration page under the NV oOS menu.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'External Tools - NV oOS', 'wp-mcp-ai' ),
				__( 'External Tools', 'wp-mcp-ai' ),
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

			check_admin_referer( 'wp_mcp_ai_save_external_tools_settings' );

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

			// Sanitize Gmail settings.
			if ( isset( $_POST['gmail_client_id'] ) ) {
				$settings['gmail_client_id'] = sanitize_text_field( wp_unslash( $_POST['gmail_client_id'] ) );
			}

			if ( isset( $_POST['gmail_client_secret'] ) ) {
				$settings['gmail_client_secret'] = sanitize_text_field( wp_unslash( $_POST['gmail_client_secret'] ) );
			}

			// Sanitize Crawl4AI settings.
			if ( isset( $_POST['crawl4ai_base_url'] ) ) {
				$url                           = esc_url_raw( wp_unslash( $_POST['crawl4ai_base_url'] ) );
				$settings['crawl4ai_base_url'] = $url;
			}

			if ( isset( $_POST['crawl4ai_api_key'] ) ) {
				$settings['crawl4ai_api_key'] = sanitize_text_field( wp_unslash( $_POST['crawl4ai_api_key'] ) );
			}

			// Sanitize Brave Search settings.
			if ( isset( $_POST['brave_search_api_key'] ) ) {
				$settings['brave_search_api_key'] = sanitize_text_field( wp_unslash( $_POST['brave_search_api_key'] ) );
			}

			// Sanitize Cloudflare settings.
			if ( isset( $_POST['cloudflare_api_token'] ) ) {
				$settings['cloudflare_api_token'] = sanitize_text_field( wp_unslash( $_POST['cloudflare_api_token'] ) );
			}

			if ( isset( $_POST['cloudflare_zone_id'] ) ) {
				$settings['cloudflare_zone_id'] = sanitize_text_field( wp_unslash( $_POST['cloudflare_zone_id'] ) );
			}

			// Sanitize Cloudways settings.
			if ( isset( $_POST['cloudways_api_key'] ) ) {
				$settings['cloudways_api_key'] = sanitize_text_field( wp_unslash( $_POST['cloudways_api_key'] ) );
			}

			if ( isset( $_POST['cloudways_email'] ) ) {
				$settings['cloudways_email'] = sanitize_email( wp_unslash( $_POST['cloudways_email'] ) );
			}

			// Sanitize Mailjet settings.
			if ( isset( $_POST['mailjet_api_key'] ) ) {
				$settings['mailjet_api_key'] = sanitize_text_field( wp_unslash( $_POST['mailjet_api_key'] ) );
			}

			if ( isset( $_POST['mailjet_api_secret'] ) ) {
				$settings['mailjet_api_secret'] = sanitize_text_field( wp_unslash( $_POST['mailjet_api_secret'] ) );
			}

			// Sanitize QuickBooks settings.
			if ( isset( $_POST['quickbooks_api_key'] ) ) {
				$settings['quickbooks_api_key'] = sanitize_text_field( wp_unslash( $_POST['quickbooks_api_key'] ) );
			}

			if ( isset( $_POST['quickbooks_client_id'] ) ) {
				$settings['quickbooks_client_id'] = sanitize_text_field( wp_unslash( $_POST['quickbooks_client_id'] ) );
			}

			if ( isset( $_POST['quickbooks_client_secret'] ) ) {
				$settings['quickbooks_client_secret'] = sanitize_text_field( wp_unslash( $_POST['quickbooks_client_secret'] ) );
			}

			// Sanitize Google Analytics settings.
			if ( isset( $_POST['google_analytics_property_id'] ) ) {
				$settings['google_analytics_property_id'] = sanitize_text_field( wp_unslash( $_POST['google_analytics_property_id'] ) );
			}

			if ( isset( $_POST['google_analytics_credentials'] ) ) {
				$settings['google_analytics_credentials'] = sanitize_textarea_field( wp_unslash( $_POST['google_analytics_credentials'] ) );
			}

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
			$gmail_client_id              = isset( $settings['gmail_client_id'] ) ? $settings['gmail_client_id'] : '';
			$gmail_client_secret          = isset( $settings['gmail_client_secret'] ) ? $settings['gmail_client_secret'] : '';
			$crawl4ai_base_url            = isset( $settings['crawl4ai_base_url'] ) ? $settings['crawl4ai_base_url'] : '';
			$crawl4ai_api_key             = isset( $settings['crawl4ai_api_key'] ) ? $settings['crawl4ai_api_key'] : '';
			$brave_search_api_key         = isset( $settings['brave_search_api_key'] ) ? $settings['brave_search_api_key'] : '';
			$cloudflare_api_token         = isset( $settings['cloudflare_api_token'] ) ? $settings['cloudflare_api_token'] : '';
			$cloudflare_zone_id           = isset( $settings['cloudflare_zone_id'] ) ? $settings['cloudflare_zone_id'] : '';
			$cloudways_api_key            = isset( $settings['cloudways_api_key'] ) ? $settings['cloudways_api_key'] : '';
			$cloudways_email              = isset( $settings['cloudways_email'] ) ? $settings['cloudways_email'] : '';
			$mailjet_api_key              = isset( $settings['mailjet_api_key'] ) ? $settings['mailjet_api_key'] : '';
			$mailjet_api_secret           = isset( $settings['mailjet_api_secret'] ) ? $settings['mailjet_api_secret'] : '';
			$quickbooks_api_key           = isset( $settings['quickbooks_api_key'] ) ? $settings['quickbooks_api_key'] : '';
			$quickbooks_client_id         = isset( $settings['quickbooks_client_id'] ) ? $settings['quickbooks_client_id'] : '';
			$quickbooks_client_secret     = isset( $settings['quickbooks_client_secret'] ) ? $settings['quickbooks_client_secret'] : '';
			$google_analytics_property_id = isset( $settings['google_analytics_property_id'] ) ? $settings['google_analytics_property_id'] : '';
			$google_analytics_credentials = isset( $settings['google_analytics_credentials'] ) ? $settings['google_analytics_credentials'] : '';
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'External Tools Integration', 'wp-mcp-ai' ); ?></h1>

				<?php if ( isset( $_GET['updated'] ) && 'true' === $_GET['updated'] ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php endif; ?>

				<p><?php esc_html_e( 'Configure third-party service integrations including search APIs, email services, cloud platforms, web crawlers, and analytics.', 'wp-mcp-ai' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="wp_mcp_ai_save_external_tools_settings" />
					<?php wp_nonce_field( 'wp_mcp_ai_save_external_tools_settings' ); ?>

					<table class="form-table" role="presentation">
						<!-- Gmail Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-email"></span>
									<?php esc_html_e( 'Gmail', 'wp-mcp-ai' ); ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gmail_client_id"><?php esc_html_e( 'Gmail OAuth Client ID', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="text" id="gmail_client_id" name="gmail_client_id" value="<?php echo esc_attr( $gmail_client_id ); ?>" class="regular-text" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gmail_client_secret"><?php esc_html_e( 'Gmail OAuth Client Secret', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="gmail_client_secret" name="gmail_client_secret" value="<?php echo esc_attr( $gmail_client_secret ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- Crawl4AI Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-admin-site"></span>
									<?php esc_html_e( 'Crawl4AI', 'wp-mcp-ai' ); ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="crawl4ai_base_url"><?php esc_html_e( 'Crawl4AI Base URL', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="url" id="crawl4ai_base_url" name="crawl4ai_base_url" value="<?php echo esc_attr( $crawl4ai_base_url ); ?>" class="regular-text" placeholder="http://localhost:8000" autocomplete="url" />
								<p class="description"><?php esc_html_e( 'Base URL for Crawl4AI service (if using external crawler).', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="crawl4ai_api_key"><?php esc_html_e( 'Crawl4AI API Key', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="crawl4ai_api_key" name="crawl4ai_api_key" value="<?php echo esc_attr( $crawl4ai_api_key ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'API key for Crawl4AI service (if required).', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- Brave Search Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-search"></span>
									<?php esc_html_e( 'Brave Search', 'wp-mcp-ai' ); ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="brave_search_api_key"><?php esc_html_e( 'Brave Search API Key', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="brave_search_api_key" name="brave_search_api_key" value="<?php echo esc_attr( $brave_search_api_key ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description">
									<?php
									printf(
										/* translators: %s: URL to Brave Search API */
										esc_html__( 'API key for Brave Search integration. Get your API key from %s.', 'wp-mcp-ai' ),
										'<a href="https://brave.com/search/api/" target="_blank">Brave Search API</a>'
									);
									?>
								</p>
							</td>
						</tr>

						<!-- Cloudflare Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-cloud"></span>
									<?php esc_html_e( 'Cloudflare', 'wp-mcp-ai' ); ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cloudflare_api_token"><?php esc_html_e( 'Cloudflare API Token', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="cloudflare_api_token" name="cloudflare_api_token" value="<?php echo esc_attr( $cloudflare_api_token ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'API token for Cloudflare integration. Create a token in your Cloudflare dashboard.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cloudflare_zone_id"><?php esc_html_e( 'Cloudflare Zone ID', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="text" id="cloudflare_zone_id" name="cloudflare_zone_id" value="<?php echo esc_attr( $cloudflare_zone_id ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Your Cloudflare zone ID for cache management.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- Cloudways Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-admin-site"></span>
									<?php esc_html_e( 'Cloudways', 'wp-mcp-ai' ); ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cloudways_api_key"><?php esc_html_e( 'Cloudways API Key', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="cloudways_api_key" name="cloudways_api_key" value="<?php echo esc_attr( $cloudways_api_key ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'API key for Cloudways hosting integration.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="cloudways_email"><?php esc_html_e( 'Cloudways Account Email', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="email" id="cloudways_email" name="cloudways_email" value="<?php echo esc_attr( $cloudways_email ); ?>" class="regular-text" placeholder="you@example.com" />
								<p class="description"><?php esc_html_e( 'Email address associated with your Cloudways account.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- Mailjet Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-email"></span>
									<?php esc_html_e( 'Mailjet', 'wp-mcp-ai' ); ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="mailjet_api_key"><?php esc_html_e( 'Mailjet API Key', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="mailjet_api_key" name="mailjet_api_key" value="<?php echo esc_attr( $mailjet_api_key ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'API key for Mailjet email service integration.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="mailjet_api_secret"><?php esc_html_e( 'Mailjet API Secret', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="mailjet_api_secret" name="mailjet_api_secret" value="<?php echo esc_attr( $mailjet_api_secret ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'API secret for Mailjet email service.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- QuickBooks Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-money-alt"></span>
									<?php esc_html_e( 'QuickBooks', 'wp-mcp-ai' ); ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="quickbooks_api_key"><?php esc_html_e( 'QuickBooks API Key', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="quickbooks_api_key" name="quickbooks_api_key" value="<?php echo esc_attr( $quickbooks_api_key ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'API key for QuickBooks integration.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="quickbooks_client_id"><?php esc_html_e( 'QuickBooks Client ID', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="text" id="quickbooks_client_id" name="quickbooks_client_id" value="<?php echo esc_attr( $quickbooks_client_id ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'OAuth 2.0 Client ID from QuickBooks developer portal.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="quickbooks_client_secret"><?php esc_html_e( 'QuickBooks Client Secret', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="quickbooks_client_secret" name="quickbooks_client_secret" value="<?php echo esc_attr( $quickbooks_client_secret ); ?>" class="regular-text" autocomplete="new-password" />
								<p class="description"><?php esc_html_e( 'OAuth 2.0 Client Secret from QuickBooks developer portal.', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>

						<!-- Google Analytics Section -->
						<tr>
							<td colspan="2">
								<h2 style="margin: 20px 0 10px 0; display: flex; align-items: center; gap: 8px;">
									<span class="dashicons dashicons-chart-line"></span>
									<?php esc_html_e( 'Google Analytics', 'wp-mcp-ai' ); ?>
								</h2>
								<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="google_analytics_property_id"><?php esc_html_e( 'Google Analytics Property ID', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="text" id="google_analytics_property_id" name="google_analytics_property_id" value="<?php echo esc_attr( $google_analytics_property_id ); ?>" class="regular-text" placeholder="123456789" />
								<p class="description"><?php esc_html_e( 'Google Analytics 4 Property ID (e.g., 123456789).', 'wp-mcp-ai' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="google_analytics_credentials"><?php esc_html_e( 'Google Analytics Service Account JSON', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<textarea id="google_analytics_credentials" name="google_analytics_credentials" rows="10" class="large-text code" placeholder='{"type": "service_account", ...}'><?php echo esc_textarea( $google_analytics_credentials ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Service account credentials in JSON format from Google Cloud Console.', 'wp-mcp-ai' ); ?></p>
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

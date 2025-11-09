<?php
/**
 * Gmail & Crawl4AI Integration Admin Page
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_Gmail_Crawl_Integration' ) ) {
	/**
	 * Manages the Gmail & Crawl4AI integration admin page.
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
			add_action( 'admin_post_wp_mcp_ai_save_gmail_crawl_settings', array( $this, 'handle_save_settings' ) );
		}

		/**
		 * Register the integration page under the WP oOS menu.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'Gmail & Crawl4AI Integration - WP oOS', 'wp-mcp-ai' ),
				__( 'Gmail & Crawl4AI', 'wp-mcp-ai' ),
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

			check_admin_referer( 'wp_mcp_ai_save_gmail_crawl_settings' );

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
				$url = esc_url_raw( wp_unslash( $_POST['crawl4ai_base_url'] ) );
				$settings['crawl4ai_base_url'] = $url;
			}

			if ( isset( $_POST['crawl4ai_api_key'] ) ) {
				$settings['crawl4ai_api_key'] = sanitize_text_field( wp_unslash( $_POST['crawl4ai_api_key'] ) );
			}

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
		 * Render the integration page.
		 */
		public function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$gmail_client_id = isset( $settings['gmail_client_id'] ) ? $settings['gmail_client_id'] : '';
			$gmail_client_secret = isset( $settings['gmail_client_secret'] ) ? $settings['gmail_client_secret'] : '';
			$crawl4ai_base_url = isset( $settings['crawl4ai_base_url'] ) ? $settings['crawl4ai_base_url'] : '';
			$crawl4ai_api_key = isset( $settings['crawl4ai_api_key'] ) ? $settings['crawl4ai_api_key'] : '';

			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Gmail & Crawl4AI Integration', 'wp-mcp-ai' ); ?></h1>

				<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( isset( $_GET['updated'] ) && 'true' === sanitize_key( wp_unslash( $_GET['updated'] ) ) ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Settings saved successfully.', 'wp-mcp-ai' ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_mcp_ai_save_gmail_crawl_settings' ); ?>
					<input type="hidden" name="action" value="wp_mcp_ai_save_gmail_crawl_settings" />

					<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin: 1.5rem 0;">
						<h2 style="margin-top: 0;"><?php esc_html_e( 'Gmail OAuth Integration', 'wp-mcp-ai' ); ?></h2>
						<p><?php esc_html_e( 'Configure Gmail OAuth credentials to enable AI tools for email operations. You can create OAuth credentials in the Google Cloud Console.', 'wp-mcp-ai' ); ?></p>
					</div>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="gmail_client_id"><?php esc_html_e( 'Gmail OAuth Client ID', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="text" id="gmail_client_id" name="gmail_client_id" value="<?php echo esc_attr( $gmail_client_id ); ?>" class="regular-text" />
								<p class="description">
									<?php esc_html_e( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'wp-mcp-ai' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="gmail_client_secret"><?php esc_html_e( 'Gmail OAuth Client Secret', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="gmail_client_secret" name="gmail_client_secret" value="<?php echo esc_attr( $gmail_client_secret ); ?>" class="regular-text" />
								<p class="description">
									<?php esc_html_e( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'wp-mcp-ai' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin: 1.5rem 0;">
						<h2 style="margin-top: 0;"><?php esc_html_e( 'Crawl4AI Integration', 'wp-mcp-ai' ); ?></h2>
						<p><?php esc_html_e( 'Configure Crawl4AI service for advanced web scraping capabilities. This enables AI tools to extract structured data from websites.', 'wp-mcp-ai' ); ?></p>
					</div>

					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="crawl4ai_base_url"><?php esc_html_e( 'Crawl4AI Base URL', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="url" id="crawl4ai_base_url" name="crawl4ai_base_url" value="<?php echo esc_attr( $crawl4ai_base_url ); ?>" class="regular-text" placeholder="http://localhost:8000" />
								<p class="description">
									<?php esc_html_e( 'Base URL for Crawl4AI service (if using external crawler).', 'wp-mcp-ai' ); ?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="crawl4ai_api_key"><?php esc_html_e( 'Crawl4AI API Key', 'wp-mcp-ai' ); ?></label>
							</th>
							<td>
								<input type="password" id="crawl4ai_api_key" name="crawl4ai_api_key" value="<?php echo esc_attr( $crawl4ai_api_key ); ?>" class="regular-text" />
								<p class="description">
									<?php esc_html_e( 'API key for Crawl4AI service (if required).', 'wp-mcp-ai' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Integration Settings', 'wp-mcp-ai' ) ); ?>
				</form>

				<div style="background: #fff; border: 1px solid #dcdcde; padding: 1.5rem; margin-top: 2rem; border-radius: 4px;">
					<h2 style="margin-top: 0;"><?php esc_html_e( 'Available Tools', 'wp-mcp-ai' ); ?></h2>
					
					<h3><?php esc_html_e( 'Gmail Tools', 'wp-mcp-ai' ); ?></h3>
					<ul style="margin-left: 1.5rem;">
						<li><code>gmail_send_email</code> - <?php esc_html_e( 'Send emails via Gmail API', 'wp-mcp-ai' ); ?></li>
						<li><code>gmail_search_messages</code> - <?php esc_html_e( 'Search and retrieve email messages', 'wp-mcp-ai' ); ?></li>
						<li><code>gmail_create_draft</code> - <?php esc_html_e( 'Create email drafts', 'wp-mcp-ai' ); ?></li>
					</ul>

					<h3><?php esc_html_e( 'Crawl4AI Tools', 'wp-mcp-ai' ); ?></h3>
					<ul style="margin-left: 1.5rem;">
						<li><code>crawl_webpage</code> - <?php esc_html_e( 'Extract content from web pages', 'wp-mcp-ai' ); ?></li>
						<li><code>scrape_structured_data</code> - <?php esc_html_e( 'Extract structured data with custom selectors', 'wp-mcp-ai' ); ?></li>
						<li><code>crawl_sitemap</code> - <?php esc_html_e( 'Process entire sitemaps', 'wp-mcp-ai' ); ?></li>
					</ul>
				</div>

				<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 1.5rem; margin-top: 2rem;">
					<h3 style="margin-top: 0;"><?php esc_html_e( 'Setup Instructions', 'wp-mcp-ai' ); ?></h3>
					
					<h4><?php esc_html_e( 'Gmail OAuth Setup', 'wp-mcp-ai' ); ?></h4>
					<ol>
						<li><?php esc_html_e( 'Go to Google Cloud Console (console.cloud.google.com)', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Create a new project or select existing one', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Enable Gmail API', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Create OAuth 2.0 credentials', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Copy Client ID and Client Secret to fields above', 'wp-mcp-ai' ); ?></li>
					</ol>

					<h4><?php esc_html_e( 'Crawl4AI Setup', 'wp-mcp-ai' ); ?></h4>
					<ol>
						<li><?php esc_html_e( 'Install Crawl4AI service (see documentation)', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Start the Crawl4AI server', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Enter the server URL (e.g., http://localhost:8000)', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Add API key if authentication is required', 'wp-mcp-ai' ); ?></li>
					</ol>
				</div>
			</div>
			<?php
		}
	}
}

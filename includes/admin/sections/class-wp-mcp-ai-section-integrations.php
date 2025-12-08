<?php
/**
 * Integrations Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Integrations' ) ) {
	/**
	 * Integrations settings section - External Tools.
	 */
	class WP_MCP_AI_Section_Integrations extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'integrations_gmail_crawl4ai';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'External Tools', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * This section is integrated within the Tools tab under the Connections subtab.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure third-party service integrations including search APIs, email services, cloud platforms, web crawlers, and social media.', 'wp-mcp-ai' );
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 20;
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );
			$pro_notice = $is_pro_active ? '' : ' ' . __( '<strong>(Requires Pro addon)</strong>', 'wp-mcp-ai' );
			
			return array(
				// Gmail OAuth.
				'gmail_client_id'              => array(
					'type'         => 'text',
					'label'        => __( 'Gmail OAuth Client ID', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'off',
					'disabled'     => ! $is_pro_active,
				),
				'gmail_client_secret'          => array(
					'type'         => 'password',
					'label'        => __( 'Gmail OAuth Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),

				// Crawl4AI.
				'crawl4ai_base_url'            => array(
					'type'         => 'url',
					'label'        => __( 'Crawl4AI Base URL', 'wp-mcp-ai' ),
					'description'  => __( 'Base URL for Crawl4AI service (if using external crawler).', 'wp-mcp-ai' ),
					'placeholder'  => 'http://localhost:8000',
					'autocomplete' => 'url',
				),
				'crawl4ai_api_key'             => array(
					'type'         => 'password',
					'label'        => __( 'Crawl4AI API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Crawl4AI service (if required).', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Brave Search.
				'brave_search_api_key'         => array(
					'type'         => 'password',
					'label'        => __( 'Brave Search API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: URL to Brave Search API */
						__( 'API key for Brave Search integration. Get your API key from %s.', 'wp-mcp-ai' ),
						'<a href="https://brave.com/search/api/" target="_blank">Brave Search API</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Cloudflare.
				'cloudflare_api_token'         => array(
					'type'         => 'password',
					'label'        => __( 'Cloudflare API Token', 'wp-mcp-ai' ),
					'description'  => __( 'API token for Cloudflare integration. Create a token in your Cloudflare dashboard.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudflare_zone_id'           => array(
					'type'        => 'text',
					'label'       => __( 'Cloudflare Zone ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudflare zone ID for cache management.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// Cloudways.
				'cloudways_api_key'            => array(
					'type'         => 'password',
					'label'        => __( 'Cloudways API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Cloudways hosting integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudways_email'              => array(
					'type'        => 'email',
					'label'       => __( 'Cloudways Account Email', 'wp-mcp-ai' ),
					'description' => __( 'Email address associated with your Cloudways account.', 'wp-mcp-ai' ),
					'placeholder' => 'you@example.com',
				),
				'cloudways_server_id'          => array(
					'type'        => 'text',
					'label'       => __( 'Cloudways Server ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudways server identifier for server management operations. Find this in your Cloudways dashboard under Servers.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'cloudways_app_id'             => array(
					'type'        => 'text',
					'label'       => __( 'Cloudways Application ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudways application identifier for app-specific operations. Find this in your Cloudways dashboard under Applications.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// Mailjet.
				'mailjet_api_key'              => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Mailjet email service integration.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),
				'mailjet_api_secret'           => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Secret', 'wp-mcp-ai' ),
					'description'  => __( 'API secret for Mailjet email service.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),
				'mailjet_from_email'           => array(
					'type'        => 'email',
					'label'       => __( 'Mailjet From Email', 'wp-mcp-ai' ),
					'description' => __( 'Default "from" email address for Mailjet messages.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => 'noreply@example.com',
					'disabled'     => ! $is_pro_active,
				),
				'mailjet_from_name'            => array(
					'type'        => 'text',
					'label'       => __( 'Mailjet From Name', 'wp-mcp-ai' ),
					'description' => __( 'Default "from" name for Mailjet messages.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => 'My Site',
					'disabled'     => ! $is_pro_active,
				),

				// remove.bg API.
				'removebg_api_key'             => array(
					'type'         => 'password',
					'label'        => __( 'remove.bg API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: URL to remove.bg API */
						__( 'API key for remove.bg background removal service. Get your API key from %s. Note: A free tier with Python rembg is also available without an API key.', 'wp-mcp-ai' ),
						'<a href="https://www.remove.bg/api" target="_blank">remove.bg API</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// QuickBooks.
				'quickbooks_api_key'           => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for QuickBooks integration.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),
				'quickbooks_company_id'        => array(
					'type'        => 'text',
					'label'       => __( 'QuickBooks Company ID', 'wp-mcp-ai' ),
					'description' => __( 'Your QuickBooks company (realm) ID.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '',
					'disabled'     => ! $is_pro_active,
				),
				'quickbooks_client_id'         => array(
					'type'        => 'text',
					'label'       => __( 'QuickBooks Client ID', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Client ID from QuickBooks developer portal.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '',
					'disabled'     => ! $is_pro_active,
				),
				'quickbooks_client_secret'     => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from QuickBooks developer portal.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),

				// Google Analytics.
				'google_analytics_property_id'      => array(
					'type'        => 'text',
					'label'       => __( 'Google Analytics Property ID', 'wp-mcp-ai' ),
					'description' => __( 'Google Analytics 4 Property ID (e.g., 123456789).', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '123456789',
					'disabled'     => ! $is_pro_active,
				),
				'google_analytics_credentials'      => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics Service Account JSON (Legacy)', 'wp-mcp-ai' ),
					'description' => __( 'Service account credentials in JSON format from Google Cloud Console. This field is being phased out in favor of google_analytics_credentials_json.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '{"type": "service_account", ...}',
					'disabled'     => ! $is_pro_active,
					'rows'        => 5,
				),
				'google_analytics_credentials_json' => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics 4 Credentials JSON', 'wp-mcp-ai' ),
					'description' => __( 'Service account JSON credentials file for Google Analytics 4 API access. Download from Google Cloud Console → IAM & Admin → Service Accounts. The JSON must be valid and contain type, project_id, private_key, and client_email fields.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '{"type": "service_account", "project_id": "your-project", ...}',
					'disabled'     => ! $is_pro_active,
					'rows'        => 8,
				),

				// ITA Tariff Rate API.
				'ita_tariff_api_key'                => array(
					'type'         => 'password',
					'label'        => __( 'ITA Tariff Rate API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: URL to ITA API */
						__( 'API key for International Trade Administration Tariff Rate API. Get your API key from %s. Used for import/export tariff information and trade compliance.', 'wp-mcp-ai' ),
						'<a href="https://developer.trade.gov/" target="_blank">Trade.gov Developer Portal</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Meta.
				'meta_access_token'            => array(
					'type'         => 'password',
					'label'        => __( 'Meta Access Token', 'wp-mcp-ai' ),
					'description'  => __( 'Long-lived access token for Meta Graph API. Used for Facebook, Instagram, and WhatsApp integrations.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'meta_app_id'                  => array(
					'type'        => 'text',
					'label'       => __( 'Meta App ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Meta (Facebook) App ID.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'meta_app_secret'              => array(
					'type'         => 'password',
					'label'        => __( 'Meta App Secret', 'wp-mcp-ai' ),
					'description'  => __( 'Your Meta (Facebook) App Secret.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'meta_business_account_id'     => array(
					'type'        => 'text',
					'label'       => __( 'Meta Business Account ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Meta Business Account ID (for WhatsApp Business API).', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// TikTok.
				'tiktok_access_token'          => array(
					'type'         => 'password',
					'label'        => __( 'TikTok Access Token', 'wp-mcp-ai' ),
					'description'  => __( 'Access token for TikTok Open API with video.share scope.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'tiktok_client_key'            => array(
					'type'        => 'text',
					'label'       => __( 'TikTok Client Key', 'wp-mcp-ai' ),
					'description' => __( 'Client Key from TikTok developer portal.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'tiktok_client_secret'         => array(
					'type'         => 'password',
					'label'        => __( 'TikTok Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'Client Secret from TikTok developer portal.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );
			
			return array(
				'gmail'            => array(
					'id'     => 'gmail',
					'label'  => $is_pro_active ? __( 'Gmail', 'wp-mcp-ai' ) : __( 'Gmail (Pro)', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-email',
					'fields' => array( 'gmail_client_id', 'gmail_client_secret' ),
					'pro'    => true,
				),
				'crawl4ai'         => array(
					'id'     => 'crawl4ai',
					'label'  => __( 'Crawl4AI', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-search',
					'fields' => array( 'crawl4ai_base_url', 'crawl4ai_api_key' ),
				),
				'brave_search'     => array(
					'id'     => 'brave_search',
					'label'  => __( 'Brave Search', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-search',
					'fields' => array( 'brave_search_api_key' ),
				),
				'cloudflare'       => array(
					'id'     => 'cloudflare',
					'label'  => __( 'Cloudflare', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-cloud',
					'fields' => array( 'cloudflare_api_token', 'cloudflare_zone_id' ),
				),
				'cloudways'        => array(
					'id'     => 'cloudways',
					'label'  => __( 'Cloudways', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-cloud-upload',
					'fields' => array( 'cloudways_api_key', 'cloudways_email', 'cloudways_server_id', 'cloudways_app_id' ),
				),
				'mailjet'          => array(
					'id'     => 'mailjet',
					'label'  => $is_pro_active ? __( 'Mailjet', 'wp-mcp-ai' ) : __( 'Mailjet (Pro)', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-email-alt',
					'fields' => array( 'mailjet_api_key', 'mailjet_api_secret', 'mailjet_from_email', 'mailjet_from_name' ),
					'pro'    => true,
				),
				'quickbooks'       => array(
					'id'     => 'quickbooks',
					'label'  => $is_pro_active ? __( 'QuickBooks', 'wp-mcp-ai' ) : __( 'QuickBooks (Pro)', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-money-alt',
					'fields' => array( 'quickbooks_api_key', 'quickbooks_company_id', 'quickbooks_client_id', 'quickbooks_client_secret' ),
					'pro'    => true,
				),
				'google_analytics' => array(
					'id'     => 'google_analytics',
					'label'  => $is_pro_active ? __( 'Google Analytics', 'wp-mcp-ai' ) : __( 'Google Analytics (Pro)', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-chart-bar',
					'fields' => array( 'google_analytics_property_id', 'google_analytics_credentials' ),
					'pro'    => true,
				),
				'meta'             => array(
					'id'     => 'meta',
					'label'  => __( 'Meta', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-share',
					'fields' => array( 'meta_access_token', 'meta_app_id', 'meta_app_secret', 'meta_business_account_id' ),
				),
				'tiktok'           => array(
					'id'     => 'tiktok',
					'label'  => __( 'TikTok', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-video-alt3',
					'fields' => array( 'tiktok_access_token', 'tiktok_client_key', 'tiktok_client_secret' ),
				),
			);
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$subtab        = '';
			$subtab_groups = $this->get_subtab_groups();

			// When rendered within Tools > Connections, use 'connection' parameter.

			// Otherwise use 'subtab' parameter (for backwards compatibility if rendered standalone).

			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			if ( isset( $_POST['connection'] ) ) {
				$subtab = sanitize_key( $_POST['connection'] );
			} elseif ( isset( $_GET['connection'] ) ) {
				$subtab = sanitize_key( $_GET['connection'] );
			} elseif ( isset( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				// Only use 'subtab' if it's one of our integration subtabs.

				$potential_subtab = sanitize_key( $_GET['subtab'] );
				if ( isset( $subtab_groups[ $potential_subtab ] ) ) {
					$subtab = $potential_subtab;
				}
			}

			// Default to 'gmail' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'gmail';
			}

			return $subtab;
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// Render fields for the active sub-tab.
			foreach ( $active_group['fields'] as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}

			// Render additional content based on active sub-tab.
			$this->render_subtab_footer( $active_subtab );
		}

		/**
		 * Render footer content for specific sub-tabs.
		 *
		 * @param string $subtab Active sub-tab ID.
		 */
		private function render_subtab_footer( $subtab ) {
			switch ( $subtab ) {
				case 'gmail':
					$this->render_gmail_footer();
					break;
				case 'brave_search':
					$this->render_brave_search_footer();
					break;
				case 'meta':
					$this->render_meta_footer();
					break;
				case 'tiktok':
					$this->render_tiktok_footer();
					break;
				case 'quickbooks':
					$this->render_quickbooks_footer();
					break;
			}
		}

		/**
		 * Render Gmail footer content.
		 */
		private function render_gmail_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'How to get Gmail OAuth credentials:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Go to Google Cloud Console and create a new project', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Enable the Gmail API for your project', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Create OAuth 2.0 credentials (Web application)', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Add authorized redirect URI pointing to your WordPress site', 'wp-mcp-ai' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Brave Search footer content.
		 */
		private function render_brave_search_footer() {
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Brave Search Connection', 'wp-mcp-ai' ); ?></th>
				<td>
					<p>
						<button type="button" id="wp-mcp-ai-test-brave-search-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'wp-mcp-ai' ); ?>
						</button>
						<span id="wp-mcp-ai-brave-search-test-result" style="margin-left: 10px;"></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Enter your Brave Search API key in the field above, then click "Test Connection" to verify it works. You can test before saving.', 'wp-mcp-ai' ); ?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Meta footer content.
		 */
		private function render_meta_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Meta Platform Integration:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Access Token is used for Facebook Page posts and Instagram business posts', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Business Account ID is required for WhatsApp Business API', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Create a Meta App at developers.facebook.com to get credentials', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Request appropriate permissions for posting and messaging', 'wp-mcp-ai' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render TikTok footer content.
		 */
		private function render_tiktok_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'TikTok Integration:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Register your app at developers.tiktok.com', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Request video.share scope for posting videos', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Access tokens expire and need to be refreshed periodically', 'wp-mcp-ai' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render QuickBooks footer content.
		 */
		private function render_quickbooks_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'QuickBooks Integration:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Company ID is also known as Realm ID in QuickBooks', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'OAuth credentials are obtained from developer.intuit.com', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'API Key is used for authentication with reports endpoint', 'wp-mcp-ai' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 * Only renders when the 'connections' subtab is active in the Tools tab.
		 */
		public function render_wrapper() {
			// Only render this section when the 'connections' subtab is active in Tools.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$current_subtab = isset( $_GET['subtab'] ) ? sanitize_key( $_GET['subtab'] ) : '';

			// This section is embedded within Tools > Connections subtab.
			// Don't render if we're not in the connections subtab.
			if ( 'connections' !== $current_subtab ) {
				return;
			}

			$description   = $this->get_description();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'External tools settings sub-tabs', 'wp-mcp-ai' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							// When rendered within Tools > Connections, preserve the connections subtab.

							// Otherwise link directly to the integration subtab.

							$current_tab           = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'tools';
							$current_parent_subtab = isset( $_GET['subtab'] ) ? sanitize_key( $_GET['subtab'] ) : '';

							$url_args = array(
								'page' => 'wp-mcp-ai-dashboard',
								'tab'  => $current_tab,
							);

							// If we're in the connections subtab, add it to maintain context.

							if ( 'connections' === $current_parent_subtab ) {
								$url_args['subtab']     = 'connections';
								$url_args['connection'] = $group['id'];
							} else {
								$url_args['subtab'] = $group['id'];
							}

							$subtab_url = add_query_arg( $url_args, admin_url( 'admin.php' ) );
							$is_active  = ( $group['id'] === $active_subtab );
							?>
							<a href="<?php echo esc_url( $subtab_url ); ?>" 
								class="wp-mcp-ai-subtab <?php echo esc_attr( $is_active ? 'wp-mcp-ai-subtab-active' : '' ); ?>"
								data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<!-- Hidden field to preserve subtab during form submission -->
					<!-- Use 'connection' parameter when in Tools > Connections context -->
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
					$param_name = isset( $_GET['subtab'] ) && 'connections' === $_GET['subtab'] ? 'connection' : 'subtab';
					?>
					<input type="hidden" name="<?php echo esc_attr( $param_name ); ?>" value="<?php echo esc_attr( $active_subtab ); ?>" />

					<div class="wp-mcp-ai-subtab-content">
						<table class="form-table" role="presentation">
							<?php $this->render(); ?>
						</table>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate Crawl4AI URL.
			if ( isset( $input['crawl4ai_base_url'] ) && ! empty( $input['crawl4ai_base_url'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_url( $input['crawl4ai_base_url'] );
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Crawl4AI Base URL: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}

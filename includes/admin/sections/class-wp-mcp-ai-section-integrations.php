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
			$pro_notice    = $is_pro_active ? '' : ' ' . __( '<strong>(Requires Pro addon)</strong>', 'wp-mcp-ai' );

			return array(
				// Gmail OAuth.
				'gmail_client_id'                   => array(
					'type'         => 'text',
					'label'        => __( 'Gmail OAuth Client ID', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'off',
					'disabled'     => ! $is_pro_active,
				),
				'gmail_client_secret'               => array(
					'type'         => 'password',
					'label'        => __( 'Gmail OAuth Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),

				// Crawl4AI.
				'crawl4ai_base_url'                 => array(
					'type'         => 'url',
					'label'        => __( 'Crawl4AI Base URL', 'wp-mcp-ai' ),
					'description'  => __( 'Base URL for Crawl4AI service (if using external crawler).', 'wp-mcp-ai' ),
					'placeholder'  => 'http://localhost:8000',
					'autocomplete' => 'url',
				),
				'crawl4ai_api_key'                  => array(
					'type'         => 'password',
					'label'        => __( 'Crawl4AI API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Crawl4AI service (if required).', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Brave Search.
				'brave_search_api_key'              => array(
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
				'cloudflare_api_token'              => array(
					'type'         => 'password',
					'label'        => __( 'Cloudflare API Token', 'wp-mcp-ai' ),
					'description'  => __( 'API token for Cloudflare integration. Create a token in your Cloudflare dashboard.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudflare_zone_id'                => array(
					'type'        => 'text',
					'label'       => __( 'Cloudflare Zone ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudflare zone ID for cache management.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// Cloudways.
				'cloudways_api_key'                 => array(
					'type'         => 'password',
					'label'        => __( 'Cloudways API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Cloudways hosting integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudways_email'                   => array(
					'type'        => 'email',
					'label'       => __( 'Cloudways Account Email', 'wp-mcp-ai' ),
					'description' => __( 'Email address associated with your Cloudways account.', 'wp-mcp-ai' ),
					'placeholder' => 'you@example.com',
				),
				'cloudways_server_id'               => array(
					'type'        => 'text',
					'label'       => __( 'Cloudways Server ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudways server identifier for server management operations. Find this in your Cloudways dashboard under Servers.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'cloudways_app_id'                  => array(
					'type'        => 'text',
					'label'       => __( 'Cloudways Application ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudways application identifier for app-specific operations. Find this in your Cloudways dashboard under Applications.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// Mailjet.
				'mailjet_api_key'                   => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Mailjet email service integration.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),
				'mailjet_api_secret'                => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Secret', 'wp-mcp-ai' ),
					'description'  => __( 'API secret for Mailjet email service.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),
				'mailjet_from_email'                => array(
					'type'        => 'email',
					'label'       => __( 'Mailjet From Email', 'wp-mcp-ai' ),
					'description' => __( 'Default "from" email address for Mailjet messages.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => 'noreply@example.com',
					'disabled'    => ! $is_pro_active,
				),
				'mailjet_from_name'                 => array(
					'type'        => 'text',
					'label'       => __( 'Mailjet From Name', 'wp-mcp-ai' ),
					'description' => __( 'Default "from" name for Mailjet messages.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => 'My Site',
					'disabled'    => ! $is_pro_active,
				),
				'mailjet_client_id'                 => array(
					'type'        => 'text',
					'label'       => __( 'Mailjet OAuth Client ID', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Client ID from Mailjet developer portal for 1-click connection.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '',
					'disabled'    => ! $is_pro_active,
				),
				'mailjet_client_secret'             => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet OAuth Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from Mailjet developer portal for 1-click connection.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),

				// remove.bg API.
				'removebg_api_key'                  => array(
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
				'quickbooks_api_key'                => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for QuickBooks integration.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					'disabled'     => ! $is_pro_active,
				),
				'quickbooks_company_id'             => array(
					'type'        => 'text',
					'label'       => __( 'QuickBooks Company ID', 'wp-mcp-ai' ),
					'description' => __( 'Your QuickBooks company (realm) ID.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '',
					'disabled'    => ! $is_pro_active,
				),
				'quickbooks_client_id'              => array(
					'type'        => 'text',
					'label'       => __( 'QuickBooks Client ID', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Client ID from QuickBooks developer portal.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '',
					'disabled'    => ! $is_pro_active,
				),
				'quickbooks_client_secret'          => array(
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
					'disabled'    => ! $is_pro_active,
				),
				'google_analytics_credentials'      => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics Service Account JSON (Legacy)', 'wp-mcp-ai' ),
					'description' => __( 'Service account credentials in JSON format from Google Cloud Console. This field is being phased out in favor of google_analytics_credentials_json.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '{"type": "service_account", ...}',
					'disabled'    => ! $is_pro_active,
					'rows'        => 5,
				),
				'google_analytics_credentials_json' => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics 4 Credentials JSON', 'wp-mcp-ai' ),
					'description' => __( 'Service account JSON credentials file for Google Analytics 4 API access. Download from Google Cloud Console → IAM & Admin → Service Accounts. The JSON must be valid and contain type, project_id, private_key, and client_email fields.', 'wp-mcp-ai' ) . $pro_notice,
					'placeholder' => '{"type": "service_account", "project_id": "your-project", ...}',
					'disabled'    => ! $is_pro_active,
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
				'meta_access_token'                 => array(
					'type'         => 'password',
					'label'        => __( 'Meta Access Token', 'wp-mcp-ai' ),
					'description'  => __( 'Long-lived access token for Meta Graph API. Used for Facebook, Instagram, and WhatsApp integrations.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'meta_app_id'                       => array(
					'type'        => 'text',
					'label'       => __( 'Meta App ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Meta (Facebook) App ID.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'meta_app_secret'                   => array(
					'type'         => 'password',
					'label'        => __( 'Meta App Secret', 'wp-mcp-ai' ),
					'description'  => __( 'Your Meta (Facebook) App Secret.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'meta_business_account_id'          => array(
					'type'        => 'text',
					'label'       => __( 'Meta Business Account ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Meta Business Account ID (for WhatsApp Business API).', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'meta_connected_user_name'          => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),
				'meta_connected_user_id'            => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),

				// TikTok.
				'tiktok_access_token'               => array(
					'type'         => 'password',
					'label'        => __( 'TikTok Access Token', 'wp-mcp-ai' ),
					'description'  => __( 'Access token for TikTok Open API with video.share scope.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'tiktok_client_key'                 => array(
					'type'        => 'text',
					'label'       => __( 'TikTok Client Key', 'wp-mcp-ai' ),
					'description' => __( 'Client Key from TikTok developer portal.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'tiktok_client_secret'              => array(
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
					'fields' => array( 'mailjet_api_key', 'mailjet_api_secret', 'mailjet_from_email', 'mailjet_from_name', 'mailjet_client_id', 'mailjet_client_secret' ),
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
					'fields' => array( 'google_analytics_property_id', 'google_analytics_credentials', 'google_analytics_credentials_json', 'ita_tariff_api_key' ),
					'pro'    => true,
				),
				'meta'             => array(
					'id'     => 'meta',
					'label'  => __( 'Meta', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-share',
					'fields' => array( 'meta_access_token', 'meta_app_id', 'meta_app_secret', 'meta_business_account_id', 'meta_connected_user_name', 'meta_connected_user_id' ),
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

			// Check POST data first (when form is being submitted), then fall back to GET.
			// Use section-specific field name to avoid conflicts with other sections.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$subtab_field_name = 'subtab_' . $this->get_id();
			if ( isset( $_POST[ $subtab_field_name ] ) ) {
				$subtab = sanitize_key( $_POST[ $subtab_field_name ] );
			} elseif ( isset( $_POST['connection'] ) ) {
				// Legacy parameter for backwards compatibility.
				$subtab = sanitize_key( $_POST['connection'] );
			} elseif ( isset( $_GET['connection'] ) ) {
				$subtab = sanitize_key( $_GET['connection'] );
			} elseif ( isset( $_POST['subtab'] ) ) {
				// Fallback to legacy field name for backward compatibility.
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
				case 'cloudways':
					$this->render_cloudways_footer();
					break;
				case 'cloudflare':
					$this->render_cloudflare_footer();
					break;
				case 'mailjet':
					$this->render_mailjet_footer();
					break;
			}
		}

		/**
		 * Render Gmail footer content.
		 */
	/**
	 * Render Gmail footer content.
	 */
	private function render_gmail_footer() {
		$settings        = WP_MCP_AI_Admin_Settings::get_settings();
		$gmail_connected = ! empty( $settings['gmail_refresh_token'] );
		$gmail_email     = isset( $settings['gmail_user_email'] ) ? $settings['gmail_user_email'] : '';
		$has_credentials = ! empty( $settings['gmail_client_id'] ) && ! empty( $settings['gmail_client_secret'] );
		$oauth_connect_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=wp_mcp_ai_gmail_oauth_start' ),
			'wp_mcp_ai_gmail_oauth_start'
		);
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Gmail Connection', 'wp-mcp-ai' ); ?></th>
			<td>
				<?php if ( $gmail_connected ) : ?>
					<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #155724;">
							<span class="dashicons dashicons-yes" style="color: #155724;"></span>
							<strong><?php esc_html_e( 'Connected to Gmail', 'wp-mcp-ai' ); ?></strong>
							<?php if ( $gmail_email ) : ?>
								<?php
								printf(
									/* translators: %s: Gmail email address */
									esc_html__( 'as %s', 'wp-mcp-ai' ),
									'<code>' . esc_html( $gmail_email ) . '</code>'
								);
								?>
							<?php endif; ?>
						</p>
					</div>
					<p>
						<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
							<?php esc_html_e( 'Reconnect Gmail Account', 'wp-mcp-ai' ); ?>
						</a>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Your Gmail account is connected. You can now use Gmail integration tools to search and read emails.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				<?php elseif ( $has_credentials ) : ?>
					<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #856404;">
							<span class="dashicons dashicons-warning" style="color: #856404;"></span>
							<strong><?php esc_html_e( 'Gmail Not Connected', 'wp-mcp-ai' ); ?></strong>
						</p>
					</div>
					<p>
						<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Connect Gmail Account', 'wp-mcp-ai' ); ?>
						</a>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Click the button above to authorize WP MCP AI to access your Gmail account. You will be redirected to Google to grant permissions.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Required Permissions:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><code>gmail.readonly</code>: <?php esc_html_e( 'Read access to Gmail messages and settings', 'wp-mcp-ai' ); ?></li>
					</ul>
				<?php else : ?>
					<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #721c24;">
							<span class="dashicons dashicons-info" style="color: #721c24;"></span>
							<strong><?php esc_html_e( 'Gmail OAuth Credentials Required', 'wp-mcp-ai' ); ?></strong>
						</p>
					</div>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'To connect your Gmail account, first configure your Gmail OAuth Client ID and Client Secret in the fields above, then save your settings.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Setup Instructions:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ol style="margin-left: 20px;">
						<li>
							<?php
							printf(
								/* translators: %s: URL to Google Cloud Console */
								wp_kses_post( __( 'Go to <a href="%s" target="_blank">Google Cloud Console</a>', 'wp-mcp-ai' ) ),
								esc_url( 'https://console.cloud.google.com/' )
							);
							?>
						</li>
						<li><?php esc_html_e( 'Create a new project or select an existing one', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Enable the Gmail API for your project', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Create OAuth 2.0 credentials (Web application type)', 'wp-mcp-ai' ); ?></li>
						<li>
							<?php
							printf(
								/* translators: %s: Callback URL */
								esc_html__( 'Set Authorized redirect URI to: %s', 'wp-mcp-ai' ),
								'<br><code>' . esc_html( admin_url( 'admin-post.php?action=wp_mcp_ai_gmail_oauth_callback' ) ) . '</code>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Copy the Client ID and Client Secret to the fields above', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Save your settings', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Click the "Connect Gmail Account" button that will appear', 'wp-mcp-ai' ); ?></li>
					</ol>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"></th>
			<td>
				<p class="description">
					<strong><?php esc_html_e( 'About Gmail Integration:', 'wp-mcp-ai' ); ?></strong>
				</p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'OAuth 2.0 credentials are obtained from Google Cloud Console', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Access tokens are automatically refreshed when expired', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Supports searching and reading Gmail messages', 'wp-mcp-ai' ); ?></li>
					<li><?php esc_html_e( 'Requires gmail.readonly scope for read access', 'wp-mcp-ai' ); ?></li>
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
			$settings         = WP_MCP_AI_Admin_Settings::get_settings();
			$meta_connected   = ! empty( $settings['meta_access_token'] );
			$meta_user_name   = isset( $settings['meta_connected_user_name'] ) ? $settings['meta_connected_user_name'] : '';
			$has_credentials  = ! empty( $settings['meta_app_id'] ) && ! empty( $settings['meta_app_secret'] );
			$oauth_connect_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_meta_oauth_start' ),
				'wp_mcp_ai_meta_oauth_start'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Meta Connection', 'wp-mcp-ai' ); ?></th>
				<td>
					<?php if ( $meta_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to Meta', 'wp-mcp-ai' ); ?></strong>
								<?php if ( $meta_user_name ) : ?>
									<?php
									printf(
										/* translators: %s: Meta user name */
										esc_html__( 'as %s', 'wp-mcp-ai' ),
										'<code>' . esc_html( $meta_user_name ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
								<?php esc_html_e( 'Reconnect Meta Account', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your Meta account is connected. You can now use Meta integration tools to manage Facebook Pages, Instagram posts, and WhatsApp Business messaging.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php elseif ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'Meta Not Connected', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect Meta Account', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to authorize WP MCP AI to access your Meta account. You will be redirected to Facebook to grant permissions.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Required Permissions:', 'wp-mcp-ai' ); ?></strong>
						</p>
						<ul style="list-style: disc; margin-left: 20px;">
							<?php
							// Get scopes from OAuth handler constant.
							$scopes             = WP_MCP_AI_Meta_OAuth_Handler::META_OAUTH_SCOPES;
							$scope_descriptions = array(
								'pages_manage_posts'              => __( 'Manage Facebook Page posts', 'wp-mcp-ai' ),
								'instagram_basic'                 => __( 'Access Instagram account information', 'wp-mcp-ai' ),
								'instagram_content_publish'       => __( 'Publish Instagram content', 'wp-mcp-ai' ),
								'whatsapp_business_management'    => __( 'Manage WhatsApp Business account', 'wp-mcp-ai' ),
								'whatsapp_business_messaging'     => __( 'Send WhatsApp Business messages', 'wp-mcp-ai' ),
							);
							foreach ( explode( ',', $scopes ) as $scope ) {
								$scope       = trim( $scope );
								$description = isset( $scope_descriptions[ $scope ] ) ? $scope_descriptions[ $scope ] : $scope;
								echo '<li>' . esc_html( $description ) . '</li>';
							}
							?>
						</ul>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'Meta App Credentials Required', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php esc_html_e( 'Enter your Meta App ID and App Secret in the fields above, then save settings. After that, you can connect your Meta account using the button that will appear here.', 'wp-mcp-ai' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
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
			$settings             = WP_MCP_AI_Admin_Settings::get_settings();
			$quickbooks_connected = ! empty( $settings['quickbooks_connected'] );
			$company_id           = isset( $settings['quickbooks_company_id'] ) ? $settings['quickbooks_company_id'] : '';
			$has_credentials      = ! empty( $settings['quickbooks_client_id'] ) && ! empty( $settings['quickbooks_client_secret'] );
			$oauth_connect_url    = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_quickbooks_oauth_start' ),
				'wp_mcp_ai_quickbooks_oauth_start'
			);
			$disconnect_url       = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_quickbooks_disconnect' ),
				'wp_mcp_ai_quickbooks_disconnect'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'QuickBooks Connection', 'wp-mcp-ai' ); ?></th>
				<td>
					<?php if ( $quickbooks_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to QuickBooks', 'wp-mcp-ai' ); ?></strong>
								<?php if ( $company_id ) : ?>
									<?php
									printf(
										/* translators: %s: Company ID */
										esc_html__( '(Company: %s)', 'wp-mcp-ai' ),
										'<code>' . esc_html( $company_id ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
								<?php esc_html_e( 'Reconnect QuickBooks Account', 'wp-mcp-ai' ); ?>
							</a>
							<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button" style="margin-left: 5px;">
								<?php esc_html_e( 'Disconnect', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your QuickBooks account is connected. You can now use financial reporting and accounting tools.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php elseif ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'QuickBooks Not Connected', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect QuickBooks Account', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to authorize WP MCP AI to access your QuickBooks account. You will be redirected to Intuit to grant permissions and select your company.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'QuickBooks OAuth Credentials Required', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php esc_html_e( 'Enter your QuickBooks OAuth Client ID and Client Secret in the fields above, then save settings. After that, you can connect using the button that will appear here.', 'wp-mcp-ai' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'QuickBooks Integration:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Company ID is also known as Realm ID in QuickBooks', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'OAuth credentials are obtained from developer.intuit.com', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Access tokens are automatically refreshed when expired', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Supports accounting, invoicing, and financial reporting', 'wp-mcp-ai' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Mailjet footer content.
		 */
		private function render_mailjet_footer() {
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$mailjet_connected = ! empty( $settings['mailjet_connected'] );
			$has_credentials   = ! empty( $settings['mailjet_client_id'] ) && ! empty( $settings['mailjet_client_secret'] );
			$oauth_connect_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_mailjet_oauth_start' ),
				'wp_mcp_ai_mailjet_oauth_start'
			);
			$disconnect_url    = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_mailjet_disconnect' ),
				'wp_mcp_ai_mailjet_disconnect'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mailjet Connection', 'wp-mcp-ai' ); ?></th>
				<td>
					<?php if ( $mailjet_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to Mailjet', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
								<?php esc_html_e( 'Reconnect Mailjet Account', 'wp-mcp-ai' ); ?>
							</a>
							<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button" style="margin-left: 5px;">
								<?php esc_html_e( 'Disconnect', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your Mailjet account is connected. You can now use email sending and campaign management tools.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php elseif ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'Mailjet Not Connected', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect Mailjet Account', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to authorize WP MCP AI to access your Mailjet account. You will be redirected to Mailjet to grant permissions.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'Mailjet OAuth Credentials Required', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php esc_html_e( 'Enter your Mailjet OAuth Client ID and Client Secret in the fields above, then save settings. After that, you can connect using the button that will appear here.', 'wp-mcp-ai' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Mailjet Integration:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Create an OAuth app in your Mailjet developer portal', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Access tokens are automatically refreshed when expired', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Supports transactional emails, campaigns, and contact management', 'wp-mcp-ai' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Cloudways footer content.
		 */
		private function render_cloudways_footer() {
			$settings           = WP_MCP_AI_Admin_Settings::get_settings();
			$cloudways_connected = ! empty( $settings['cloudways_connected'] );
			$account_name       = isset( $settings['cloudways_account_name'] ) ? $settings['cloudways_account_name'] : '';
			$has_credentials    = ! empty( $settings['cloudways_email'] ) && ! empty( $settings['cloudways_api_key'] );
			$connect_url        = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_cloudways_connect' ),
				'wp_mcp_ai_cloudways_connect'
			);
			$disconnect_url     = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_cloudways_disconnect' ),
				'wp_mcp_ai_cloudways_disconnect'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cloudways Connection', 'wp-mcp-ai' ); ?></th>
				<td>
					<?php if ( $cloudways_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to Cloudways', 'wp-mcp-ai' ); ?></strong>
								<?php if ( $account_name ) : ?>
									<?php
									printf(
										/* translators: %s: Account info */
										esc_html__( '(%s)', 'wp-mcp-ai' ),
										'<code>' . esc_html( $account_name ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $connect_url ); ?>" class="button">
								<?php esc_html_e( 'Refresh Connection', 'wp-mcp-ai' ); ?>
							</a>
							<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button" style="margin-left: 5px;">
								<?php esc_html_e( 'Disconnect', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your Cloudways account is connected. Access tokens are automatically managed. You can now use server management and application deployment tools.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php elseif ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'Cloudways Not Connected', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $connect_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect Cloudways Account', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to validate your credentials and connect to Cloudways. The connection will exchange your API key for an access token.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'Cloudways API Credentials Required', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php esc_html_e( 'Enter your Cloudways email and API key in the fields above, then save settings. After that, you can connect using the button that will appear here.', 'wp-mcp-ai' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Cloudways Integration:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Get your API key from your Cloudways account settings', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Server ID and App ID can be found in your Cloudways dashboard', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'OAuth tokens are automatically refreshed when expired', 'wp-mcp-ai' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Cloudflare footer content.
		 */
		private function render_cloudflare_footer() {
			$settings             = WP_MCP_AI_Admin_Settings::get_settings();
			$cloudflare_connected = ! empty( $settings['cloudflare_connected'] );
			$zone_name            = isset( $settings['cloudflare_zone_name'] ) ? $settings['cloudflare_zone_name'] : '';
			$has_token            = ! empty( $settings['cloudflare_api_token'] );
			$test_url             = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_cloudflare_test_connection' ),
				'wp_mcp_ai_cloudflare_test_connection'
			);
			$disconnect_url       = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_cloudflare_disconnect' ),
				'wp_mcp_ai_cloudflare_disconnect'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cloudflare Connection', 'wp-mcp-ai' ); ?></th>
				<td>
					<?php if ( $cloudflare_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to Cloudflare', 'wp-mcp-ai' ); ?></strong>
								<?php if ( $zone_name ) : ?>
									<?php
									printf(
										/* translators: %s: Zone name */
										esc_html__( '(Zone: %s)', 'wp-mcp-ai' ),
										'<code>' . esc_html( $zone_name ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $test_url ); ?>" class="button">
								<?php esc_html_e( 'Test Connection', 'wp-mcp-ai' ); ?>
							</a>
							<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button" style="margin-left: 5px;">
								<?php esc_html_e( 'Disconnect', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your Cloudflare API token is valid. You can now use cache management and zone configuration tools.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php elseif ( $has_token ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'Cloudflare Not Connected', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $test_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Test Connection', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to validate your API token and zone ID. This will verify your credentials are working correctly.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'Cloudflare API Token Required', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php esc_html_e( 'Enter your Cloudflare API token in the field above, then save settings. After that, you can test the connection using the button that will appear here.', 'wp-mcp-ai' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Cloudflare Integration:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Create an API token in your Cloudflare dashboard under "My Profile" > "API Tokens"', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Token needs "Zone.Cache Purge" permission for cache management', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Find your Zone ID in the Overview page of your domain', 'wp-mcp-ai' ); ?></li>
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
					<input type="hidden" name="subtab_<?php echo esc_attr( $this->get_id() ); ?>" value="<?php echo esc_attr( $active_subtab ); ?>" />

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

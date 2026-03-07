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
			return __( 'External Tools', 'mcp-ai-wpoos' );
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
			return __( 'Configure third-party service integrations including search APIs, email services, cloud platforms, web crawlers, and social media.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/architecture/integrations/oauth-settings-architecture.md';
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
			// Note: Gmail and Drive support 1 connection in base, multiple in pro via Remote Sites.
			$gmail_notice = ' <em>' . __( '(Pro enables multiple connections via Remote Sites)', 'mcp-ai-wpoos' ) . '</em>';
			$drive_notice = ' <em>' . __( '(Pro enables multiple connections via Remote Sites)', 'mcp-ai-wpoos' ) . '</em>';

			return array(
				// Gmail OAuth.
				'gmail_client_id'               => array(
					'type'         => 'text',
					'label'        => __( 'Gmail OAuth Client ID', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'mcp-ai-wpoos' ) . $gmail_notice,
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'gmail_client_secret'           => array(
					'type'         => 'password',
					'label'        => __( 'Gmail OAuth Client Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'mcp-ai-wpoos' ) . $gmail_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Google Drive OAuth.
				'google_drive_client_id'        => array(
					'type'         => 'text',
					'label'        => __( 'Google Drive OAuth Client ID', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client ID from Google Cloud Console for Google Drive integration.', 'mcp-ai-wpoos' ) . $drive_notice,
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'google_drive_client_secret'    => array(
					'type'         => 'password',
					'label'        => __( 'Google Drive OAuth Client Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'mcp-ai-wpoos' ) . $drive_notice,
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Crawl4AI.
				'crawl4ai_base_url'             => array(
					'type'         => 'url',
					'label'        => __( 'Crawl4AI Base URL', 'mcp-ai-wpoos' ),
					'description'  => __( 'Base URL for Crawl4AI service (if using external crawler).', 'mcp-ai-wpoos' ),
					'placeholder'  => 'http://localhost:8000',
					'autocomplete' => 'url',
				),
				'crawl4ai_api_key'              => array(
					'type'         => 'password',
					'label'        => __( 'Crawl4AI API Key', 'mcp-ai-wpoos' ),
					'description'  => __( 'API key for Crawl4AI service (if required).', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Brave Search.
				'brave_search_api_key'          => array(
					'type'         => 'password',
					'label'        => __( 'Brave Search API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to Brave Search API */
						__( 'API key for Brave Search integration. Get your API key from %s.', 'mcp-ai-wpoos' ),
						'<a href="https://brave.com/search/api/" target="_blank">Brave Search API</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'tavily_api_key'                => array(
					'type'         => 'password',
					'label'        => __( 'Tavily API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to Tavily */
						__( 'API key for Tavily Search integration (AI-first provider optimised for agent workflows). Get your API key from %s.', 'mcp-ai-wpoos' ),
						'<a href="https://tavily.com/" target="_blank">tavily.com</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Mubert Music API.
				'mubert_api_key'                => array(
					'type'         => 'password',
					'label'        => __( 'Mubert API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to Mubert contact */
						__( 'API key for Mubert music generation service. Request an API key from %s.', 'mcp-ai-wpoos' ),
						'<a href="mailto:business@mubert.com">business@mubert.com</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Cloudflare.
				'cloudflare_api_token'          => array(
					'type'         => 'password',
					'label'        => __( 'Cloudflare API Token', 'mcp-ai-wpoos' ),
					'description'  => __( 'API token for Cloudflare integration. Create a token in your Cloudflare dashboard.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudflare_zone_id'            => array(
					'type'        => 'text',
					'label'       => __( 'Cloudflare Zone ID', 'mcp-ai-wpoos' ),
					'description' => __( 'Your Cloudflare zone ID for cache management.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'enable_cloudflare_pro_toolkit' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Cloudflare Pro Toolkit', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable Cloudflare advanced features and integrations (Pro Version only)', 'mcp-ai-wpoos' ),
					'description'    => __( 'Enables AI-powered Cloudflare integration toolkit including cache purging, zone management, DNS operations, and advanced CDN features. Provides additional tools for managing Cloudflare services through AI assistants. Requires Cloudflare API Token and Zone ID to be configured. This feature is only available in the Pro addon.', 'mcp-ai-wpoos' ),
					'default'        => false,
				),

				// Cloudways.
				'cloudways_api_key'             => array(
					'type'         => 'password',
					'label'        => __( 'Cloudways API Key', 'mcp-ai-wpoos' ),
					'description'  => __( 'API key for Cloudways hosting integration.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudways_email'               => array(
					'type'        => 'email',
					'label'       => __( 'Cloudways Account Email', 'mcp-ai-wpoos' ),
					'description' => __( 'Email address associated with your Cloudways account.', 'mcp-ai-wpoos' ),
					'placeholder' => 'you@example.com',
				),
				'cloudways_server_id'           => array(
					'type'        => 'text',
					'label'       => __( 'Cloudways Server ID', 'mcp-ai-wpoos' ),
					'description' => __( 'Your Cloudways server identifier for server management operations. Find this in your Cloudways dashboard under Servers.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'cloudways_app_id'              => array(
					'type'        => 'text',
					'label'       => __( 'Cloudways Application ID', 'mcp-ai-wpoos' ),
					'description' => __( 'Your Cloudways application identifier for app-specific operations. Find this in your Cloudways dashboard under Applications.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),

				// remove.bg API.
				'removebg_api_key'              => array(
					'type'         => 'password',
					'label'        => __( 'remove.bg API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to remove.bg API */
						__( 'API key for remove.bg background removal service. Get your API key from %s. Note: A free tier with Python rembg is also available without an API key.', 'mcp-ai-wpoos' ),
						'<a href="https://www.remove.bg/api" target="_blank">remove.bg API</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// PayHere, Flowhub, iSAMS, and QuickBooks connections have been moved to Remote Sites.
				// These settings have been removed. Please use Remote Sites page to manage these connections.

				// ITA Tariff Rate API.
				'ita_tariff_api_key'            => array(
					'type'         => 'password',
					'label'        => __( 'ITA Tariff Rate API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to ITA API */
						__( 'API key for International Trade Administration Tariff Rate API. Get your API key from %s. Used for import/export tariff information and trade compliance.', 'mcp-ai-wpoos' ),
						'<a href="https://developer.trade.gov/" target="_blank">Trade.gov Developer Portal</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Meta.
				'meta_access_token'             => array(
					'type'         => 'password',
					'label'        => __( 'Meta Access Token', 'mcp-ai-wpoos' ),
					'description'  => __( 'Long-lived access token for Meta Graph API. Used for Facebook, Instagram, and WhatsApp integrations.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'meta_app_id'                   => array(
					'type'        => 'text',
					'label'       => __( 'Meta App ID', 'mcp-ai-wpoos' ),
					'description' => __( 'Your Meta (Facebook) App ID.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'meta_app_secret'               => array(
					'type'         => 'password',
					'label'        => __( 'Meta App Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'Your Meta (Facebook) App Secret.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'meta_business_account_id'      => array(
					'type'        => 'text',
					'label'       => __( 'Meta Business Account ID', 'mcp-ai-wpoos' ),
					'description' => __( 'Your Meta Business Account ID (for WhatsApp Business API).', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'meta_connected_user_name'      => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),
				'meta_connected_user_id'        => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),

				// TikTok.
				'tiktok_access_token'           => array(
					'type'         => 'password',
					'label'        => __( 'TikTok Access Token', 'mcp-ai-wpoos' ),
					'description'  => __( 'Access token for TikTok Open API with video.share scope.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'tiktok_client_key'             => array(
					'type'        => 'text',
					'label'       => __( 'TikTok Client Key', 'mcp-ai-wpoos' ),
					'description' => __( 'Client Key from TikTok developer portal.', 'mcp-ai-wpoos' ),
					'placeholder' => '',
				),
				'tiktok_client_secret'          => array(
					'type'         => 'password',
					'label'        => __( 'TikTok Client Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'Client Secret from TikTok developer portal.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Plaid (Financial Services).
				'plaid_client_id'               => array(
					'type'         => 'text',
					'label'        => __( 'Plaid Client ID', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to Plaid Dashboard */
						__( 'Client ID from Plaid dashboard for financial account integration. Get your credentials from %s. Used for optional bank account sync in Financial Planner Toolkit.', 'mcp-ai-wpoos' ),
						'<a href="https://dashboard.plaid.com/" target="_blank">Plaid Dashboard</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'off',
					// Removed pro gating - WordPress.org compliance.
				),
				'plaid_secret'                  => array(
					'type'         => 'password',
					'label'        => __( 'Plaid Secret Key', 'mcp-ai-wpoos' ),
					'description'  => __( 'Secret key from Plaid dashboard. Keep this secure and never share publicly.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
					// Removed pro gating - WordPress.org compliance.
				),
				'plaid_environment'             => array(
					'type'        => 'select',
					'label'       => __( 'Plaid Environment', 'mcp-ai-wpoos' ),
					'description' => __( 'Select Plaid environment: Sandbox for testing, Development for development, Production for live use.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'sandbox'     => __( 'Sandbox (Testing)', 'mcp-ai-wpoos' ),
						'development' => __( 'Development', 'mcp-ai-wpoos' ),
						'production'  => __( 'Production', 'mcp-ai-wpoos' ),
					),
					'default'     => 'sandbox',
					// Removed pro gating - WordPress.org compliance.
				),

				// iSAMS, PayHere, Flowhub, and QuickBooks have been moved to Remote Sites.
				// Use admin.php?page=wp-mcp-ai-remote-sites to manage these connections.
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			return array(
				'gmail'        => array(
					'id'     => 'gmail',
					'label'  => __( 'Gmail', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-email',
					'fields' => array( 'gmail_client_id', 'gmail_client_secret' ),
				),
				'google_drive' => array(
					'id'     => 'google_drive',
					'label'  => __( 'Google Drive', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-cloud',
					'fields' => array( 'google_drive_client_id', 'google_drive_client_secret' ),
				),
				'crawl4ai'     => array(
					'id'     => 'crawl4ai',
					'label'  => __( 'Crawl4AI', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-search',
					'fields' => array( 'crawl4ai_base_url', 'crawl4ai_api_key' ),
				),
				'brave_search' => array(
					'id'     => 'brave_search',
					'label'  => __( 'Brave Search', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-search',
					'fields' => array( 'brave_search_api_key' ),
				),
				'tavily'       => array(
					'id'     => 'tavily',
					'label'  => __( 'Tavily', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-search',
					'fields' => array( 'tavily_api_key' ),
				),
				'mubert'       => array(
					'id'     => 'mubert',
					'label'  => __( 'Mubert', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-format-audio',
					'fields' => array( 'mubert_api_key' ),
				),
				// PayHere, Flowhub, iSAMS, and QuickBooks have been moved to Remote Sites.
				'removebg'     => array(
					'id'     => 'removebg',
					'label'  => __( 'remove.bg', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-format-image',
					'fields' => array( 'removebg_api_key' ),
				),
				'cloudflare'   => array(
					'id'     => 'cloudflare',
					'label'  => __( 'Cloudflare', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-cloud',
					'fields' => array( 'cloudflare_api_token', 'cloudflare_zone_id', 'enable_cloudflare_pro_toolkit' ),
				),
				'cloudways'    => array(
					'id'     => 'cloudways',
					'label'  => __( 'Cloudways', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-cloud-upload',
					'fields' => array( 'cloudways_api_key', 'cloudways_email', 'cloudways_server_id', 'cloudways_app_id' ),
				),
				'meta'         => array(
					'id'     => 'meta',
					'label'  => __( 'Meta', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-share',
					'fields' => array( 'meta_access_token', 'meta_app_id', 'meta_app_secret', 'meta_business_account_id', 'meta_connected_user_name', 'meta_connected_user_id' ),
				),
				'tiktok'       => array(
					'id'     => 'tiktok',
					'label'  => __( 'TikTok', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-video-alt3',
					'fields' => array( 'tiktok_access_token', 'tiktok_client_key', 'tiktok_client_secret' ),
				),
				// iSAMS moved to Remote Sites.
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
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check for UI state.
			$subtab_field_name = 'subtab_' . $this->get_id();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only parameter check.
			if ( isset( $_POST[ $subtab_field_name ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only parameter check.
				$subtab = sanitize_key( $_POST[ $subtab_field_name ] );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only parameter check.
			} elseif ( isset( $_POST['connection'] ) ) {
				// Legacy parameter for backwards compatibility.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only parameter check.
				$subtab = sanitize_key( $_POST['connection'] );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			} elseif ( isset( $_GET['connection'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
				$subtab = sanitize_key( $_GET['connection'] );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only parameter check.
			} elseif ( isset( $_POST['subtab'] ) ) {
				// Fallback to legacy field name for backward compatibility.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only parameter check.
				$subtab = sanitize_key( $_POST['subtab'] );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			} elseif ( isset( $_GET['subtab'] ) ) {
				// Only use 'subtab' if it's one of our integration subtabs.

				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
				$potential_subtab = sanitize_key( $_GET['subtab'] );
				if ( isset( $subtab_groups[ $potential_subtab ] ) ) {
					$subtab = $potential_subtab;
				}
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

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
				case 'google_drive':
					$this->render_google_drive_footer();
					break;
				case 'brave_search':
					$this->render_brave_search_footer();
					break;
				case 'tavily':
					$this->render_tavily_footer();
					break;
				case 'mubert':
					$this->render_mubert_footer();
					break;
				case 'yahoo_sports':
					$this->render_yahoo_sports_footer();
					break;
				case 'espn_sports':
					$this->render_espn_sports_footer();
					break;
				case 'removebg':
					$this->render_removebg_footer();
					break;
				// PayHere, Flowhub, QuickBooks, and iSAMS moved to Remote Sites.
				case 'meta':
					$this->render_meta_footer();
					break;
				case 'tiktok':
					$this->render_tiktok_footer();
					break;
				// QuickBooks and iSAMS moved to Remote Sites.
				case 'cloudways':
					$this->render_cloudways_footer();
					break;
				case 'cloudflare':
					$this->render_cloudflare_footer();
					break;
				case 'mailjet':
					$this->render_mailjet_footer();
					break;
				// iSAMS moved to Remote Sites.
			}
		}

		/**
		 * Render Gmail footer content.
		 */
		/**
		 * Render Gmail footer content.
		 */
		private function render_gmail_footer() {
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$gmail_connected   = ! empty( $settings['gmail_refresh_token'] );
			$gmail_email       = isset( $settings['gmail_user_email'] ) ? $settings['gmail_user_email'] : '';
			$has_credentials   = ! empty( $settings['gmail_client_id'] ) && ! empty( $settings['gmail_client_secret'] );
			$is_pro_active     = defined( 'WP_MCP_AI_PRO_VERSION' );
			$oauth_connect_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_gmail_oauth_start' ),
				'wp_mcp_ai_gmail_oauth_start'
			);

			// Check for success or error messages.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$gmail_success = isset( $_GET['gmail_success'] ) ? sanitize_text_field( wp_unslash( $_GET['gmail_success'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$gmail_error = isset( $_GET['gmail_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gmail_error'] ) ) : '';
			?>
			<?php if ( $gmail_success ) : ?>
			<tr>
				<th scope="row"></th>
				<td>
					<div class="notice notice-success inline" style="margin: 0 0 15px;">
						<p><?php echo esc_html( $gmail_success ); ?></p>
					</div>
				</td>
			</tr>
		<?php endif; ?>
			<?php if ( $gmail_error ) : ?>
			<tr>
				<th scope="row"></th>
				<td>
					<div class="notice notice-error inline" style="margin: 0 0 15px;">
						<p><?php echo esc_html( $gmail_error ); ?></p>
					</div>
				</td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Gmail Connection', 'mcp-ai-wpoos' ); ?></th>
			<td>
				<?php if ( $gmail_connected ) : ?>
					<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #155724;">
							<span class="dashicons dashicons-yes" style="color: #155724;"></span>
							<strong><?php esc_html_e( 'Connected to Gmail', 'mcp-ai-wpoos' ); ?></strong>
							<?php if ( $gmail_email ) : ?>
								<?php
								printf(
									/* translators: %s: Gmail email address */
									esc_html__( 'as %s', 'mcp-ai-wpoos' ),
									'<code>' . esc_html( $gmail_email ) . '</code>'
								);
								?>
							<?php endif; ?>
						</p>
					</div>
					<p>
						<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
							<?php esc_html_e( 'Reconnect Gmail Account', 'mcp-ai-wpoos' ); ?>
						</a>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Your Gmail account is connected. You can now use Gmail integration tools to search and read emails.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
				<?php elseif ( $has_credentials ) : ?>
					<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #856404;">
							<span class="dashicons dashicons-warning" style="color: #856404;"></span>
							<strong><?php esc_html_e( 'Gmail Not Connected', 'mcp-ai-wpoos' ); ?></strong>
						</p>
					</div>
					<p>
						<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Connect Gmail Account', 'mcp-ai-wpoos' ); ?>
						</a>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Click the button above to authorize WP MCP AI to access your Gmail account. You will be redirected to Google to grant permissions.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Required Permissions:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><code>gmail.readonly</code>: <?php esc_html_e( 'Read access to Gmail messages and settings', 'mcp-ai-wpoos' ); ?></li>
					</ul>
				<?php else : ?>
					<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #721c24;">
							<span class="dashicons dashicons-info" style="color: #721c24;"></span>
							<strong><?php esc_html_e( 'Gmail OAuth Credentials Required', 'mcp-ai-wpoos' ); ?></strong>
						</p>
					</div>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'To connect your Gmail account, first configure your Gmail OAuth Client ID and Client Secret in the fields above, then save your settings.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Setup Instructions:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ol style="margin-left: 20px;">
						<li>
							<?php
							printf(
								/* translators: %s: URL to Google Cloud Console */
								wp_kses_post( __( 'Go to <a href="%s" target="_blank">Google Cloud Console</a>', 'mcp-ai-wpoos' ) ),
								esc_url( 'https://console.cloud.google.com/' )
							);
							?>
						</li>
						<li><?php esc_html_e( 'Create a new project or select an existing one', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Enable the Gmail API for your project', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Create OAuth 2.0 credentials (Web application type)', 'mcp-ai-wpoos' ); ?></li>
						<li>
							<?php
							$gmail_redirect_uri = add_query_arg(
								array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
								admin_url( 'admin.php' )
							);
							printf(
								/* translators: %s: Callback URL */
								esc_html__( 'Set Authorized redirect URI to: %s', 'mcp-ai-wpoos' ),
								'<br><code>' . esc_html( $gmail_redirect_uri ) . '</code>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Copy the Client ID and Client Secret to the fields above', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Save your settings', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Click the "Connect Gmail Account" button that will appear', 'mcp-ai-wpoos' ); ?></li>
					</ol>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"></th>
			<td>
				<p class="description">
					<strong><?php esc_html_e( 'About Gmail Integration:', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'OAuth 2.0 credentials are obtained from Google Cloud Console', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Access tokens are automatically refreshed when expired', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Supports searching and reading Gmail messages', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Requires gmail.readonly scope for read access', 'mcp-ai-wpoos' ); ?></li>
				</ul>
			</td>
		</tr>
			<?php
		}

		/**
		 * Render Google Drive footer content.
		 */
		private function render_google_drive_footer() {
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$drive_connected   = ! empty( $settings['google_drive_refresh_token'] );
			$drive_email       = isset( $settings['google_drive_user_email'] ) ? $settings['google_drive_user_email'] : '';
			$has_credentials   = ! empty( $settings['google_drive_client_id'] ) && ! empty( $settings['google_drive_client_secret'] );
			$is_pro_active     = defined( 'WP_MCP_AI_PRO_VERSION' );
			$oauth_connect_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_google_drive_oauth_start' ),
				'wp_mcp_ai_google_drive_oauth_start'
			);

			// Check for success or error messages.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$drive_success = isset( $_GET['drive_success'] ) ? sanitize_text_field( wp_unslash( $_GET['drive_success'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$drive_error = isset( $_GET['drive_error'] ) ? sanitize_text_field( wp_unslash( $_GET['drive_error'] ) ) : '';
			?>
			<?php if ( $drive_success ) : ?>
			<tr>
				<th scope="row"></th>
				<td>
					<div class="notice notice-success inline" style="margin: 0 0 15px;">
						<p><?php echo esc_html( $drive_success ); ?></p>
					</div>
				</td>
			</tr>
		<?php endif; ?>
			<?php if ( $drive_error ) : ?>
			<tr>
				<th scope="row"></th>
				<td>
					<div class="notice notice-error inline" style="margin: 0 0 15px;">
						<p><?php echo esc_html( $drive_error ); ?></p>
					</div>
				</td>
			</tr>
		<?php endif; ?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Google Drive Connection', 'mcp-ai-wpoos' ); ?></th>
			<td>
				<?php if ( $drive_connected ) : ?>
					<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #155724;">
							<span class="dashicons dashicons-yes" style="color: #155724;"></span>
							<strong><?php esc_html_e( 'Connected to Google Drive', 'mcp-ai-wpoos' ); ?></strong>
							<?php if ( $drive_email ) : ?>
								<?php
								printf(
									/* translators: %s: Google account email address */
									esc_html__( 'as %s', 'mcp-ai-wpoos' ),
									'<code>' . esc_html( $drive_email ) . '</code>'
								);
								?>
							<?php endif; ?>
						</p>
					</div>
					<p>
						<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
							<?php esc_html_e( 'Reconnect Google Drive Account', 'mcp-ai-wpoos' ); ?>
						</a>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Your Google Drive account is connected. You can now use Google Drive integration tools to search and read files.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
				<?php elseif ( $has_credentials ) : ?>
					<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #856404;">
							<span class="dashicons dashicons-warning" style="color: #856404;"></span>
							<strong><?php esc_html_e( 'Google Drive Not Connected', 'mcp-ai-wpoos' ); ?></strong>
						</p>
					</div>
					<p>
						<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
							<?php esc_html_e( 'Connect Google Drive Account', 'mcp-ai-wpoos' ); ?>
						</a>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Click the button above to authorize WP MCP AI to access your Google Drive account. You will be redirected to Google to grant permissions.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Required Permissions:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><code>drive.readonly</code>: <?php esc_html_e( 'Read access to Drive files', 'mcp-ai-wpoos' ); ?></li>
						<li><code>drive.metadata.readonly</code>: <?php esc_html_e( 'Read access to Drive file metadata', 'mcp-ai-wpoos' ); ?></li>
					</ul>
				<?php else : ?>
					<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #721c24;">
							<span class="dashicons dashicons-info" style="color: #721c24;"></span>
							<strong><?php esc_html_e( 'Google Drive OAuth Credentials Required', 'mcp-ai-wpoos' ); ?></strong>
						</p>
					</div>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'To connect your Google Drive account, first configure your Google Drive OAuth Client ID and Client Secret in the fields above, then save your settings.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Setup Instructions:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ol style="margin-left: 20px;">
						<li>
							<?php
							printf(
								/* translators: %s: URL to Google Cloud Console */
								wp_kses_post( __( 'Go to <a href="%s" target="_blank">Google Cloud Console</a>', 'mcp-ai-wpoos' ) ),
								esc_url( 'https://console.cloud.google.com/' )
							);
							?>
						</li>
						<li><?php esc_html_e( 'Create a new project or select an existing one', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Enable the Google Drive API for your project', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Create OAuth 2.0 credentials (Web application type)', 'mcp-ai-wpoos' ); ?></li>
						<li>
							<?php
							$drive_redirect_uri = add_query_arg(
								array( 'wp_mcp_ai_oauth' => 'google_drive_callback' ),
								admin_url( 'admin.php' )
							);
							printf(
								/* translators: %s: Callback URL */
								esc_html__( 'Set Authorized redirect URI to: %s', 'mcp-ai-wpoos' ),
								'<br><code>' . esc_html( $drive_redirect_uri ) . '</code>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Copy the Client ID and Client Secret to the fields above', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Save your settings', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Click the "Connect Google Drive Account" button that will appear', 'mcp-ai-wpoos' ); ?></li>
					</ol>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"></th>
			<td>
				<p class="description">
					<strong><?php esc_html_e( 'About Google Drive Integration:', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'OAuth 2.0 credentials are obtained from Google Cloud Console', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Access tokens are automatically refreshed when expired', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Supports searching and reading Drive files', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Requires drive.readonly and drive.metadata.readonly scopes for read access', 'mcp-ai-wpoos' ); ?></li>
						<?php if ( $is_pro_active ) : ?>
						<li><?php esc_html_e( 'Pro users can configure multiple Google Drive connections via Remote Sites', 'mcp-ai-wpoos' ); ?></li>
					<?php else : ?>
						<li><?php esc_html_e( 'Base version supports 1 connection. Upgrade to Pro for multiple connections', 'mcp-ai-wpoos' ); ?></li>
					<?php endif; ?>
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
				<th scope="row"><?php esc_html_e( 'Brave Search Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<p>
						<button type="button" id="wp-mcp-ai-test-brave-search-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
						</button>
						<span id="wp-mcp-ai-brave-search-test-result" style="margin-left: 10px;"></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Enter your Brave Search API key in the field above, then click "Test Connection" to verify it works. You can test before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Tavily footer content.
		 */
		private function render_tavily_footer() {
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Tavily Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<p>
						<button type="button" id="wp-mcp-ai-test-tavily-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
						</button>
						<span id="wp-mcp-ai-tavily-test-result" style="margin-left: 10px;"></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Enter your Tavily API key in the field above, then click "Test Connection" to verify it works. You can test before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<div style="margin: 1rem 0;">
						<h4><?php esc_html_e( 'About Tavily Integration', 'mcp-ai-wpoos' ); ?></h4>
						<ul style="list-style: disc; margin-left: 20px;">
							<li><?php esc_html_e( 'Purpose-built for AI agents — returns rich, structured search results', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Supports freshness filtering, country/language targeting, and snippet grounding', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Get your API key from tavily.com', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Select "Tavily" as the web search provider in Tools → Configuration to activate', 'mcp-ai-wpoos' ); ?></li>
						</ul>
					</div>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Mubert footer content.
		 */
		private function render_mubert_footer() {
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mubert Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<p>
						<button type="button" id="wp-mcp-ai-test-mubert-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
						</button>
						<span id="wp-mcp-ai-mubert-test-result" style="margin-left: 10px;"></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Enter your Mubert API key in the field above, then click "Test Connection" to verify it works. You can test before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'About Mubert Integration:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Generate royalty-free background music with 150+ genres and 50+ moods', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Request an API key from business@mubert.com', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Used by the generate_music tool for AI-powered music creation', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Supports track durations from 15 seconds to 25 minutes', 'mcp-ai-wpoos' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Yahoo Sports footer content.
		 */
		private function render_yahoo_sports_footer() {
			$settings        = WP_MCP_AI_Admin_Settings::get_settings();
			$user_id         = get_current_user_id();
			$yahoo_connected = ! empty( get_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token', true ) ) && ! empty( get_user_meta( $user_id, 'wp_mcp_ai_yahoo_refresh_token', true ) );
			$has_credentials = ! empty( $settings['yahoo_client_id'] ) && ! empty( $settings['yahoo_client_secret'] );
			$is_pro_active   = defined( 'WP_MCP_AI_PRO_VERSION' );

			// Generate OAuth state and build direct link to Yahoo OAuth (similar to Gmail).
			if ( $has_credentials ) {
				$state     = wp_generate_uuid4();
				$transient = 'wp_mcp_ai_yahoo_oauth_state_' . md5( $state );

				set_transient(
					$transient,
					array(
						'user_id' => $user_id,
						'time'    => time(),
					),
					10 * MINUTE_IN_SECONDS
				);

				// Build redirect URI.
				$base_url     = admin_url( 'admin.php' );
				$redirect_uri = add_query_arg(
					array( 'wp_mcp_ai_oauth' => 'yahoo_callback' ),
					$base_url
				);

				// Build Yahoo OAuth authorization URL.
				$oauth_connect_url = add_query_arg(
					array(
						'client_id'     => rawurlencode( $settings['yahoo_client_id'] ),
						'redirect_uri'  => rawurlencode( $redirect_uri ),
						'response_type' => 'code',
						'scope'         => 'fspt-r', // Fantasy Sports Read access - required for reading user's fantasy football leagues, rosters, and stats. Yahoo uses 'fspt-w' for write access if needed in the future.
						'state'         => $state,
					),
					'https://api.login.yahoo.com/oauth2/request_auth'
				);
			} else {
				$oauth_connect_url = '#';
			}

			// Check for success or error messages.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$yahoo_success = isset( $_GET['yahoo_success'] ) ? sanitize_text_field( wp_unslash( $_GET['yahoo_success'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			$yahoo_error = isset( $_GET['yahoo_error'] ) ? sanitize_text_field( wp_unslash( $_GET['yahoo_error'] ) ) : '';
			?>
			<?php if ( $yahoo_success ) : ?>
		<tr>
			<th scope="row"></th>
			<td>
				<div class="notice notice-success inline" style="margin: 0 0 15px;">
					<p><?php echo esc_html( $yahoo_success ); ?></p>
				</div>
			</td>
		</tr>
	<?php endif; ?>
			<?php if ( $yahoo_error ) : ?>
		<tr>
			<th scope="row"></th>
			<td>
				<div class="notice notice-error inline" style="margin: 0 0 15px;">
					<p><?php echo esc_html( $yahoo_error ); ?></p>
				</div>
			</td>
		</tr>
	<?php endif; ?>
	<tr>
		<th scope="row"><?php esc_html_e( 'Yahoo Sports Connection', 'mcp-ai-wpoos' ); ?></th>
		<td>
			<?php if ( $yahoo_connected ) : ?>
				<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
					<p style="margin: 0; color: #155724;">
						<span class="dashicons dashicons-yes" style="color: #155724;"></span>
						<strong><?php esc_html_e( 'Connected to Yahoo Sports', 'mcp-ai-wpoos' ); ?></strong>
					</p>
				</div>
				<p>
					<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
						<?php esc_html_e( 'Reconnect Yahoo Account', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
				<p class="description">
					<?php
					echo wp_kses_post(
						__(
							'Your Yahoo account is connected. You can now use Yahoo Fantasy Football tools to access your leagues.',
							'mcp-ai-wpoos'
						)
					);
					?>
				</p>
			<?php elseif ( $has_credentials && $is_pro_active ) : ?>
				<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
					<p style="margin: 0; color: #856404;">
						<span class="dashicons dashicons-warning" style="color: #856404;"></span>
						<strong><?php esc_html_e( 'Yahoo Sports Not Connected', 'mcp-ai-wpoos' ); ?></strong>
					</p>
				</div>
				<p>
					<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'Connect Yahoo Account', 'mcp-ai-wpoos' ); ?>
					</a>
				</p>
				<p class="description">
					<?php
					echo wp_kses_post(
						__(
							'Click the button above to authorize access to your Yahoo Fantasy Football account.',
							'mcp-ai-wpoos'
						)
					);
					?>
				</p>
			<?php else : ?>
				<p class="description">
					<?php esc_html_e( 'Enter your Yahoo Client ID and Secret in the fields above and save to enable the Connect Yahoo Account button.', 'mcp-ai-wpoos' ); ?>
				</p>
			<?php endif; ?>
			<p>
				<button type="button" id="wp-mcp-ai-test-yahoo-connection" class="button button-secondary" <?php echo esc_attr( ! $is_pro_active ? 'disabled' : '' ); ?>>
					<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
				</button>
				<span id="wp-mcp-ai-yahoo-test-result" style="margin-left: 10px;"></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'Enter your Yahoo Client ID and Secret in the fields above, then click "Test Connection" to verify they work. You can test before saving.', 'mcp-ai-wpoos' ); ?>
			</p>
		</td>
	</tr>
	<tr>
		<th scope="row"></th>
		<td>
			<div style="margin: 1rem 0;">
				<h4><?php esc_html_e( 'About Yahoo Sports Integration', 'mcp-ai-wpoos' ); ?></h4>
				<p class="description" style="margin-bottom: 10px;">
					<?php esc_html_e( 'Connect to Yahoo Fantasy Sports API to access your fantasy football leagues, rosters, and player statistics.', 'mcp-ai-wpoos' ); ?>
				</p>
				<p class="description">
					<strong><?php esc_html_e( 'Setup Instructions:', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<ol style="margin-left: 20px;">
					<li>
						<?php
						echo wp_kses_post(
							sprintf(
								/* translators: %s: URL to Yahoo Developer Network */
								__( 'Create a Yahoo app at <a href="%s" target="_blank">Yahoo Developer Network</a>', 'mcp-ai-wpoos' ),
								'https://developer.yahoo.com/apps/'
							)
						);
						?>
					</li>
					<li><?php esc_html_e( 'Set the redirect URI to match your WordPress site', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Copy your Client ID (Consumer Key) and Client Secret (Consumer Secret)', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Paste them into the fields above and save', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Click "Connect Yahoo Account" to authenticate via OAuth', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Use the Yahoo Fantasy Football tools to access your leagues', 'mcp-ai-wpoos' ); ?></li>
				</ol>
				<?php if ( $is_pro_active ) : ?>
					<p class="description" style="margin-top: 1rem;">
						<strong><?php esc_html_e( 'Available Tools:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><strong>yahoo_ff_auth</strong> - <?php esc_html_e( 'Authenticate with Yahoo and manage authorization tokens', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>yahoo_ff_get_leagues</strong> - <?php esc_html_e( 'Get your fantasy football leagues and team information', 'mcp-ai-wpoos' ); ?></li>
					</ul>
				<?php else : ?>
					<p class="description" style="margin-top: 1rem; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
						<strong><?php esc_html_e( 'Pro Feature:', 'mcp-ai-wpoos' ); ?></strong>
						<?php esc_html_e( 'Yahoo Sports integration requires the Pro addon to be active.', 'mcp-ai-wpoos' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</td>
	</tr>
			<?php
		}

		/**
		 * Render ESPN Sports footer content.
		 */
		private function render_espn_sports_footer() {
			$settings      = WP_MCP_AI_Admin_Settings::get_settings();
			$has_espn_s2   = ! empty( $settings['espn_fantasy_espn_s2'] );
			$has_swid      = ! empty( $settings['espn_fantasy_swid'] );
			$has_both      = $has_espn_s2 && $has_swid;
			$is_pro_active = defined( 'WP_MCP_AI_PRO_VERSION' );
			?>
		<tr>
			<th scope="row"><?php esc_html_e( 'ESPN Sports Connection', 'mcp-ai-wpoos' ); ?></th>
			<td>
				<?php if ( $has_both ) : ?>
					<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #155724;">
							<span class="dashicons dashicons-yes" style="color: #155724;"></span>
							<strong><?php esc_html_e( 'ESPN Credentials Configured', 'mcp-ai-wpoos' ); ?></strong>
						</p>
					</div>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Your ESPN S2 and SWID cookies are saved. ESPN Fantasy tools can now access private leagues.',
								'mcp-ai-wpoos'
							)
						);
						?>
					</p>
				<?php elseif ( $has_espn_s2 || $has_swid ) : ?>
					<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #856404;">
							<span class="dashicons dashicons-warning" style="color: #856404;"></span>
							<strong><?php esc_html_e( 'Incomplete ESPN Configuration', 'mcp-ai-wpoos' ); ?></strong>
						</p>
					</div>
					<p class="description">
						<?php esc_html_e( 'Both ESPN S2 and SWID cookies are required to access private leagues. Please provide both values.', 'mcp-ai-wpoos' ); ?>
					</p>
				<?php else : ?>
					<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #856404;">
							<span class="dashicons dashicons-info" style="color: #856404;"></span>
							<strong><?php esc_html_e( 'ESPN Credentials Not Configured', 'mcp-ai-wpoos' ); ?></strong>
						</p>
					</div>
					<p class="description">
						<?php esc_html_e( 'ESPN S2 and SWID cookies are only required for private leagues. Public leagues can be accessed without authentication.', 'mcp-ai-wpoos' ); ?>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"></th>
			<td>
				<div style="margin: 1rem 0;">
					<h4><?php esc_html_e( 'About ESPN Sports Integration', 'mcp-ai-wpoos' ); ?></h4>
					<p class="description" style="margin-bottom: 10px;">
						<?php esc_html_e( 'Connect to ESPN Fantasy Football API to access league information, team rosters, standings, and player statistics. Public leagues work without authentication; private leagues require ESPN S2 and SWID cookies.', 'mcp-ai-wpoos' ); ?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Setup Instructions for Private Leagues:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ol style="margin-left: 20px;">
						<li><?php esc_html_e( 'Log in to ESPN Fantasy at fantasy.espn.com in your browser', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Open your browser\'s Developer Tools (F12)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Go to the Application/Storage tab and find Cookies', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Locate the "espn_s2" cookie and copy its value', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Locate the "SWID" cookie and copy its value (includes curly braces)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Paste both values into the fields above and save', 'mcp-ai-wpoos' ); ?></li>
					</ol>
					<?php if ( $is_pro_active ) : ?>
						<p class="description" style="margin-top: 1rem;">
							<strong><?php esc_html_e( 'Available Tools:', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						<ul style="list-style: disc; margin-left: 20px;">
							<li><strong>espn_fantasy_get_league</strong> - <?php esc_html_e( 'Retrieve ESPN Fantasy Football league information', 'mcp-ai-wpoos' ); ?></li>
							<li><strong>espn_fantasy_get_teams</strong> - <?php esc_html_e( 'Get all teams in an ESPN league', 'mcp-ai-wpoos' ); ?></li>
							<li><strong>espn_fantasy_get_roster</strong> - <?php esc_html_e( 'Get a team\'s roster with player details', 'mcp-ai-wpoos' ); ?></li>
							<li><strong>espn_fantasy_get_standings</strong> - <?php esc_html_e( 'Get league standings with win/loss records', 'mcp-ai-wpoos' ); ?></li>
							<li><strong>espn_fantasy_analyze_lineup</strong> - <?php esc_html_e( 'Analyze optimal lineup configurations', 'mcp-ai-wpoos' ); ?></li>
							<li><strong>espn_fantasy_sync_league</strong> - <?php esc_html_e( 'Sync ESPN league data to WordPress', 'mcp-ai-wpoos' ); ?></li>
						</ul>
					<?php else : ?>
						<p class="description" style="margin-top: 1rem; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
							<strong><?php esc_html_e( 'Pro Feature:', 'mcp-ai-wpoos' ); ?></strong>
							<?php esc_html_e( 'ESPN Sports integration requires the Pro addon to be active.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</td>
		</tr>
			<?php
		}

		/**
		 * Render remove.bg footer content.
		 */
		private function render_removebg_footer() {
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'remove.bg Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<p>
						<button type="button" id="wp-mcp-ai-test-removebg-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
						</button>
						<span id="wp-mcp-ai-removebg-test-result" style="margin-left: 10px;"></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Enter your remove.bg API key in the field above, then click "Test Connection" to verify it works. You can test before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<div style="margin: 1rem 0;">
						<h4><?php esc_html_e( 'About remove.bg Integration', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description" style="margin-bottom: 10px;">
							<?php esc_html_e( 'Automatically remove backgrounds from images using AI-powered background removal service.', 'mcp-ai-wpoos' ); ?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Setup Instructions:', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						<ol style="margin-left: 20px;">
							<li>
								<?php
								echo wp_kses_post(
									sprintf(
										/* translators: %s: URL to remove.bg API */
										__( 'Get your API key from <a href="%s" target="_blank">remove.bg API</a>', 'mcp-ai-wpoos' ),
										'https://www.remove.bg/api'
									)
								);
								?>
							</li>
							<li><?php esc_html_e( 'Free tier includes 50 API calls per month', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Paste your API key into the field above and save', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Click "Test Connection" to verify your API key', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Use the background removal tools with your images', 'mcp-ai-wpoos' ); ?></li>
						</ol>
						<p class="description" style="margin-top: 1rem;">
							<strong><?php esc_html_e( 'Features:', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						<ul style="list-style: disc; margin-left: 20px;">
							<li><?php esc_html_e( 'AI-powered background removal for images', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Supports various image formats (PNG, JPG, etc.)', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Alternative: Free Python rembg library (no API key needed)', 'mcp-ai-wpoos' ); ?></li>
						</ul>
					</div>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render PayHere footer content.
		 */
		private function render_payhere_footer() {
			$settings        = WP_MCP_AI_Admin_Settings::get_settings();
			$has_credentials = ! empty( $settings['payhere_app_id'] ) && ! empty( $settings['payhere_app_secret'] );
			$is_sandbox      = ! empty( $settings['payhere_sandbox_mode'] );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'PayHere Configuration', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<?php if ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'PayHere Credentials Configured', 'mcp-ai-wpoos' ); ?></strong>
								<?php if ( $is_sandbox ) : ?>
									<span style="margin-left: 10px; padding: 2px 8px; background: #fff3cd; color: #856404; border-radius: 3px; font-size: 12px;">
										<?php esc_html_e( 'Sandbox Mode', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php else : ?>
									<span style="margin-left: 10px; padding: 2px 8px; background: #d1ecf1; color: #0c5460; border-radius: 3px; font-size: 12px;">
										<?php esc_html_e( 'Live Mode', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php endif; ?>
							</p>
						</div>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your PayHere credentials are configured. AI assistants can now retrieve payment information using the PayHere API.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'PayHere Credentials Required', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'To enable PayHere payment retrieval tools, configure your PayHere App ID and App Secret in the fields above, then save your settings.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'About PayHere Integration:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'API credentials are obtained from PayHere Merchant Dashboard', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Supports retrieving payment information by order ID or transaction ID', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Sandbox mode allows testing without affecting live transactions', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Always disable sandbox mode before going to production', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p class="description">
						<strong><?php esc_html_e( 'Setup Instructions:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ol style="margin-left: 20px;">
						<li>
							<?php
							printf(
								/* translators: %s: URL to PayHere Settings */
								wp_kses_post( __( 'Go to <a href="%s" target="_blank">PayHere Merchant Settings</a>', 'mcp-ai-wpoos' ) ),
								esc_url( 'https://www.payhere.lk/merchant/settings/api-keys' )
							);
							?>
						</li>
						<li><?php esc_html_e( 'Navigate to "API Keys" section', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Copy your App ID and App Secret', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Paste them into the fields above', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Enable sandbox mode for testing (optional)', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Save your settings', 'mcp-ai-wpoos' ); ?></li>
					</ol>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Flowhub footer content.
		 */
		private function render_flowhub_footer() {
			$settings        = WP_MCP_AI_Admin_Settings::get_settings();
			$has_credentials = ! empty( $settings['flowhub_api_key'] ) && ! empty( $settings['flowhub_client_id'] );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Flowhub Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<p>
						<button type="button" id="wp-mcp-ai-test-flowhub-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
						</button>
						<span id="wp-mcp-ai-flowhub-test-result" style="margin-left: 10px;"></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Enter your Flowhub credentials in the fields above, then click "Test Connection" to verify they work. You can test before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Flowhub Configuration', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<?php if ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Flowhub Credentials Configured', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your Flowhub credentials are configured. AI assistants can now access inventory, orders, customers, and products using the Flowhub API.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'Flowhub Credentials Required', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'To enable Flowhub cannabis dispensary integration tools, configure your Flowhub API credentials in the fields above, then save your settings.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'About Flowhub Integration:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Flowhub is a cannabis dispensary POS and inventory management system', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Supports retrieving inventory, orders, customers, and products', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Supports creating orders and managing customer/product data', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Each dispensary location requires separate credentials', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Uses OAuth2 authentication for secure API access', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<p class="description">
						<strong><?php esc_html_e( 'Setup Instructions:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ol style="margin-left: 20px;">
						<li>
							<?php
							printf(
								/* translators: %s: URL to Flowhub API Request Form */
								wp_kses_post( __( 'Fill out the <a href="%s" target="_blank">Flowhub API Integration Request Form</a>', 'mcp-ai-wpoos' ) ),
								esc_url( 'https://flowhub.com/api-integration-request' )
							);
							?>
						</li>
						<li><?php esc_html_e( 'Wait for Flowhub support to provide your API credentials', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Copy your API Key, Client ID, Client Secret, and Location ID', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Paste them into the fields above', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Save your settings', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'If you manage multiple locations, repeat the process for each location', 'mcp-ai-wpoos' ); ?></li>
					</ol>
					<p class="description">
						<strong><?php esc_html_e( 'Available Flowhub Tools:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><strong>flowhub_get_inventory</strong> - <?php esc_html_e( 'Retrieve inventory data with filtering and pagination', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>flowhub_get_orders</strong> - <?php esc_html_e( 'Retrieve orders and transactions', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>flowhub_create_order</strong> - <?php esc_html_e( 'Create new dispensary orders', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>flowhub_get_customers</strong> - <?php esc_html_e( 'Retrieve customer profiles', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>flowhub_manage_customer</strong> - <?php esc_html_e( 'Create or update customer information', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>flowhub_get_products</strong> - <?php esc_html_e( 'Retrieve product catalog', 'mcp-ai-wpoos' ); ?></li>
						<li><strong>flowhub_manage_product</strong> - <?php esc_html_e( 'Create or update product details', 'mcp-ai-wpoos' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Meta footer content.
		 */
		private function render_meta_footer() {
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$meta_connected    = ! empty( $settings['meta_access_token'] );
			$meta_user_name    = isset( $settings['meta_connected_user_name'] ) ? $settings['meta_connected_user_name'] : '';
			$has_credentials   = ! empty( $settings['meta_app_id'] ) && ! empty( $settings['meta_app_secret'] );
			$oauth_connect_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_meta_oauth_start' ),
				'wp_mcp_ai_meta_oauth_start'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Meta Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<?php if ( $meta_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to Meta', 'mcp-ai-wpoos' ); ?></strong>
								<?php if ( $meta_user_name ) : ?>
									<?php
									printf(
										/* translators: %s: Meta user name */
										esc_html__( 'as %s', 'mcp-ai-wpoos' ),
										'<code>' . esc_html( $meta_user_name ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
								<?php esc_html_e( 'Reconnect Meta Account', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your Meta account is connected. You can now use Meta integration tools to manage Facebook Pages, Instagram posts, and WhatsApp Business messaging.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					<?php elseif ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'Meta Not Connected', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect Meta Account', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to authorize WP MCP AI to access your Meta account. You will be redirected to Facebook to grant permissions.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
						<p class="description">
							<strong><?php esc_html_e( 'Required Permissions:', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						<ul style="list-style: disc; margin-left: 20px;">
							<?php
							// Get scopes from OAuth handler constant.
							$scopes             = WP_MCP_AI_Meta_OAuth_Handler::META_OAUTH_SCOPES;
							$scope_descriptions = array(
								'pages_manage_posts' => __( 'Manage Facebook Page posts', 'mcp-ai-wpoos' ),
								'instagram_basic'    => __( 'Access Instagram account information', 'mcp-ai-wpoos' ),
								'instagram_content_publish' => __( 'Publish Instagram content', 'mcp-ai-wpoos' ),
								'whatsapp_business_management' => __( 'Manage WhatsApp Business account', 'mcp-ai-wpoos' ),
								'whatsapp_business_messaging' => __( 'Send WhatsApp Business messages', 'mcp-ai-wpoos' ),
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
								<strong><?php esc_html_e( 'Meta App Credentials Required', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php esc_html_e( 'Enter your Meta App ID and App Secret in the fields above, then save settings. After that, you can connect your Meta account using the button that will appear here.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Meta Platform Integration:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Access Token is used for Facebook Page posts and Instagram business posts', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Business Account ID is required for WhatsApp Business API', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Create a Meta App at developers.facebook.com to get credentials', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Request appropriate permissions for posting and messaging', 'mcp-ai-wpoos' ); ?></li>
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
						<strong><?php esc_html_e( 'TikTok Integration:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Register your app at developers.tiktok.com', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Request video.share scope for posting videos', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Access tokens expire and need to be refreshed periodically', 'mcp-ai-wpoos' ); ?></li>
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
				<th scope="row"><?php esc_html_e( 'QuickBooks Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<?php if ( $quickbooks_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to QuickBooks', 'mcp-ai-wpoos' ); ?></strong>
								<?php if ( $company_id ) : ?>
									<?php
									printf(
										/* translators: %s: Company ID */
										esc_html__( '(Company: %s)', 'mcp-ai-wpoos' ),
										'<code>' . esc_html( $company_id ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
								<?php esc_html_e( 'Reconnect QuickBooks Account', 'mcp-ai-wpoos' ); ?>
							</a>
							<a href="<?php echo esc_url( $disconnect_url ); ?>" class="button" style="margin-left: 5px;">
								<?php esc_html_e( 'Disconnect', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your QuickBooks account is connected. You can now use financial reporting and accounting tools.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					<?php elseif ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'QuickBooks Not Connected', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect QuickBooks Account', 'mcp-ai-wpoos' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to authorize WP MCP AI to access your QuickBooks account. You will be redirected to Intuit to grant permissions and select your company.',
									'mcp-ai-wpoos'
								)
							);
							?>
						</p>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'QuickBooks OAuth Credentials Required', 'mcp-ai-wpoos' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php esc_html_e( 'Enter your QuickBooks OAuth Client ID and Client Secret in the fields above, then save settings. After that, you can connect using the button that will appear here.', 'mcp-ai-wpoos' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'QuickBooks Integration:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Company ID is also known as Realm ID in QuickBooks', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'OAuth credentials are obtained from developer.intuit.com', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Access tokens are automatically refreshed when expired', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Supports accounting, invoicing, and financial reporting', 'mcp-ai-wpoos' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Mailjet footer content.
		 */
		private function render_mailjet_footer() {
			$settings        = WP_MCP_AI_Admin_Settings::get_settings();
			$has_credentials = ! empty( $settings['mailjet_api_key'] ) && ! empty( $settings['mailjet_api_secret'] );
			$webhook_url     = rest_url( 'mcp-ai/v1/webhooks/mailjet' );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mailjet Status', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<?php if ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
						<p style="margin: 0; color: #155724;">
						<span class="dashicons dashicons-yes" style="color: #155724;"></span>
						<strong><?php esc_html_e( 'Mailjet API Configured', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						</div>
						<p class="description">
						<?php
							echo wp_kses_post(
								__(
									'Your Mailjet API credentials are configured. You can now use email sending and campaign management tools.',
									'mcp-ai-wpoos'
								)
							);
						?>
					</p>
				<?php else : ?>
					<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
					<p style="margin: 0; color: #721c24;">
					<span class="dashicons dashicons-info" style="color: #721c24;"></span>
					<strong><?php esc_html_e( 'Mailjet Not Configured', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					</div>
<p class="description">
					<?php esc_html_e( 'Enter your Mailjet API Key and Secret Key in the fields above, then save settings.', 'mcp-ai-wpoos' ); ?>
</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Webhook URL', 'mcp-ai-wpoos' ); ?></th>
			<td>
				<input type="text" readonly value="<?php echo esc_url( $webhook_url ); ?>" style="width: 100%; max-width: 500px;" id="mailjet_webhook_url" />
				<button type="button" class="button" onclick="(function(btn){try{var input=document.getElementById('mailjet_webhook_url');if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(input.value).then(function(){btn.textContent='<?php esc_attr_e( 'Copied!', 'mcp-ai-wpoos' ); ?>';setTimeout(function(){btn.textContent='<?php esc_attr_e( 'Copy', 'mcp-ai-wpoos' ); ?>';},2000);}).catch(function(){input.select();document.execCommand('copy');btn.textContent='<?php esc_attr_e( 'Copied!', 'mcp-ai-wpoos' ); ?>';setTimeout(function(){btn.textContent='<?php esc_attr_e( 'Copy', 'mcp-ai-wpoos' ); ?>';},2000);});}else{input.select();document.execCommand('copy');btn.textContent='<?php esc_attr_e( 'Copied!', 'mcp-ai-wpoos' ); ?>';setTimeout(function(){btn.textContent='<?php esc_attr_e( 'Copy', 'mcp-ai-wpoos' ); ?>';},2000);}}catch(e){console.error('Copy failed:',e);alert('<?php esc_attr_e( 'Failed to copy. Please copy manually.', 'mcp-ai-wpoos' ); ?>');}})(this);">
					<?php esc_html_e( 'Copy', 'mcp-ai-wpoos' ); ?>
</button>
				<p class="description">
					<?php esc_html_e( 'Use this URL to configure webhooks in your Mailjet account for receiving event notifications (opens, clicks, bounces, etc.).', 'mcp-ai-wpoos' ); ?>
</p>
			</td>
		</tr>
		<tr>
			<th scope="row"></th>
			<td>
				<p class="description">
					<strong><?php esc_html_e( 'Mailjet Integration Setup:', 'mcp-ai-wpoos' ); ?></strong>
				</p>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><?php esc_html_e( 'Mailjet uses Basic Authentication (API Key + Secret Key) - no OAuth required', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Get your API credentials from Mailjet account under Account Settings → REST API → API Key Management', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Verify your "From Email" address in Mailjet before sending emails', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Configure webhooks in Mailjet to receive real-time event notifications', 'mcp-ai-wpoos' ); ?></li>
					<li><?php esc_html_e( 'Supports transactional emails, campaigns, and contact management', 'mcp-ai-wpoos' ); ?></li>
				</ul>
</td>
			</td>
		</tr>
			<?php
		}

		/**
		 * Render Cloudways footer content.
		 */
		private function render_cloudways_footer() {
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cloudways Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<p>
						<button type="button" id="wp-mcp-ai-test-cloudways-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
						</button>
						<span id="wp-mcp-ai-cloudways-test-result" style="margin-left: 10px;"></span>
					</p>
					<div id="wp-mcp-ai-cloudways-account-info" style="margin-top: 10px;"></div>
					<p class="description">
						<?php esc_html_e( 'Enter your Cloudways email and API key in the fields above, then click "Test Connection" to verify they work. You can test before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Cloudways Integration:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Get your API key from your Cloudways account settings', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Server ID and App ID can be found in your Cloudways dashboard', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'OAuth tokens are automatically refreshed when expired', 'mcp-ai-wpoos' ); ?></li>
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
			$has_api_token        = ! empty( $settings['cloudflare_api_token'] );
			$has_zone_id          = ! empty( $settings['cloudflare_zone_id'] );
			$cloudflare_connected = ! empty( $settings['cloudflare_connected'] );
			$pro_toolkit_enabled  = ! empty( $settings['enable_cloudflare_pro_toolkit'] );
			$is_pro_active        = defined( 'WP_MCP_AI_PRO_VERSION' );
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Cloudflare Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<?php if ( $cloudflare_connected && $has_api_token && $has_zone_id ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to Cloudflare', 'mcp-ai-wpoos' ); ?></strong>
								<?php if ( ! empty( $settings['cloudflare_zone_name'] ) ) : ?>
									<?php
									printf(
										/* translators: %s: Cloudflare zone name */
										esc_html__( '- Zone: %s', 'mcp-ai-wpoos' ),
										'<code>' . esc_html( $settings['cloudflare_zone_name'] ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
					<?php endif; ?>
					<p>
						<button type="button" id="wp-mcp-ai-test-cloudflare-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
						</button>
						<span id="wp-mcp-ai-cloudflare-test-result" style="margin-left: 10px;"></span>
					</p>
					<div id="wp-mcp-ai-cloudflare-zone-info" style="margin-top: 10px;"></div>
					<p class="description">
						<?php esc_html_e( 'Enter your Cloudflare API token and Zone ID in the fields above, then click "Test Connection" to verify they work. You can test before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<?php if ( $pro_toolkit_enabled ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pro Toolkit Status', 'mcp-ai-wpoos' ); ?></th>
					<td>
						<?php if ( $is_pro_active ) : ?>
							<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
								<p style="margin: 0; color: #155724;">
									<span class="dashicons dashicons-yes" style="color: #155724;"></span>
									<strong><?php esc_html_e( 'Cloudflare Pro Toolkit Active', 'mcp-ai-wpoos' ); ?></strong>
								</p>
							</div>
							<p class="description">
								<?php
								echo wp_kses_post(
									__(
										'The Cloudflare Pro Toolkit is enabled. AI assistants can now use advanced Cloudflare features including cache purging, zone management, and DNS operations.',
										'mcp-ai-wpoos'
									)
								);
								?>
							</p>
						<?php else : ?>
							<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
								<p style="margin: 0; color: #856404;">
									<span class="dashicons dashicons-warning" style="color: #856404;"></span>
									<strong><?php esc_html_e( 'Pro Addon Required', 'mcp-ai-wpoos' ); ?></strong>
								</p>
							</div>
							<p class="description">
								<?php
								echo wp_kses_post(
									__(
										'The Cloudflare Pro Toolkit setting is enabled but requires the Pro addon to be installed and active. Install the Pro addon to unlock advanced Cloudflare features.',
										'mcp-ai-wpoos'
									)
								);
								?>
							</p>
							<p>
								<a href="https://link.nvdigital.solutions/wpoos-pro-buy" target="_blank" class="button button-primary">
									<?php esc_html_e( 'Get NV oOS Pro', 'mcp-ai-wpoos' ); ?>
								</a>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Cloudflare Integration:', 'mcp-ai-wpoos' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Create an API token in your Cloudflare dashboard under "My Profile" > "API Tokens"', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Token needs "Zone.Cache Purge" permission for cache management', 'mcp-ai-wpoos' ); ?></li>
						<li><?php esc_html_e( 'Find your Zone ID in the Overview page of your domain', 'mcp-ai-wpoos' ); ?></li>
						<?php if ( $is_pro_active || $pro_toolkit_enabled ) : ?>
							<li><?php esc_html_e( 'Pro Toolkit enables additional permissions: DNS edit, Zone settings, and Analytics read', 'mcp-ai-wpoos' ); ?></li>
						<?php endif; ?>
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

			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			$subtab_groups     = $this->get_subtab_groups();
			$active_subtab     = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $documentation_url ) : ?>
					<p class="section-documentation">
						<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
						<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
						</a>
					</p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'External tools settings sub-tabs', 'mcp-ai-wpoos' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							// When rendered within Tools > Connections, preserve the connections subtab.

							// Otherwise link directly to the integration subtab.

							// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter for URL construction.
							$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'tools';
							// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only parameter for URL construction.
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
					
					<?php
					// If accessed via connection parameter (e.g., from Tools > Connections),
					// preserve it for redirect after save.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI state parameter, not used for data modification.
					$connection_param = isset( $_GET['connection'] ) ? sanitize_key( wp_unslash( $_GET['connection'] ) ) : '';
					if ( '' !== $connection_param ) :
						?>
						<input type="hidden" name="connection" value="<?php echo esc_attr( $connection_param ); ?>" />
						<?php
					endif;
					?>

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
		 * Render iSAMS footer content.
		 */
		private function render_isams_footer() {
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'iSAMS Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<p>
						<button type="button" id="wp-mcp-ai-test-isams-connection" class="button button-secondary">
							<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
						</button>
						<span id="wp-mcp-ai-isams-test-result" style="margin-left: 10px;"></span>
					</p>
					<p class="description">
						<?php esc_html_e( 'Enter your iSAMS API credentials in the fields above, then click "Test Connection" to verify they work. You can test before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<div style="margin: 1rem 0;">
						<h4><?php esc_html_e( 'Available iSAMS Tools', 'mcp-ai-wpoos' ); ?></h4>
						<p class="description" style="margin-bottom: 10px;">
							<?php esc_html_e( 'The following AI tools are available when iSAMS is properly configured:', 'mcp-ai-wpoos' ); ?>
						</p>
						<ul style="margin-left: 1.5rem;">
							<li><strong>isams_query</strong> - <?php esc_html_e( 'Query iSAMS for pupils, employees, departments, houses, terms, subjects, year groups, and admission applicants', 'mcp-ai-wpoos' ); ?></li>
						</ul>
						<p class="description" style="margin-top: 1rem;">
							<strong><?php esc_html_e( 'About iSAMS Integration:', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						<ul style="list-style: disc; margin-left: 20px;">
							<li><?php esc_html_e( 'API credentials are obtained from your iSAMS administrator', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Supports read-only access to school management data', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Access tokens are automatically cached for 55 minutes', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Requires Pro addon to be active', 'mcp-ai-wpoos' ); ?></li>
						</ul>
						<p class="description" style="margin-top: 1rem;">
							<strong><?php esc_html_e( 'Setup Instructions:', 'mcp-ai-wpoos' ); ?></strong>
						</p>
						<ol style="margin-left: 20px;">
							<li><?php esc_html_e( 'Contact your iSAMS administrator to obtain API credentials', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Enter the iSAMS instance URL (e.g., https://yourschool.isams.cloud/)', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Enter your API Key and API Secret in the fields above', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Save your settings', 'mcp-ai-wpoos' ); ?></li>
							<li><?php esc_html_e( 'Click "Test Connection" to verify credentials work', 'mcp-ai-wpoos' ); ?></li>
						</ol>
					</div>
				</td>
			</tr>
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
					$errors[] = __( 'Crawl4AI Base URL: ', 'mcp-ai-wpoos' ) . $result->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}

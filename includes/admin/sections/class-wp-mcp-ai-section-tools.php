<?php
/**
 * Tools & Features Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Tools' ) ) {
	/**
	 * Tools & Features settings section.
	 */
	class WP_MCP_AI_Section_Tools extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'tools';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Tools & Features Configuration', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
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
			return __( 'Configure AI-powered tools and features for your WordPress site.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				// External Tools fields.
				'gmail_client_id'                      => array(
					'type'         => 'text',
					'label'        => __( 'Gmail OAuth Client ID', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'gmail_client_secret'                  => array(
					'type'         => 'password',
					'label'        => __( 'Gmail OAuth Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'gmail_refresh_token'                  => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),
				'gmail_user_email'                     => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),
				'crawl4ai_base_url'                    => array(
					'type'         => 'url',
					'label'        => __( 'Crawl4AI Base URL', 'wp-mcp-ai' ),
					'description'  => __( 'Base URL for Crawl4AI service (if using external crawler).', 'wp-mcp-ai' ),
					'placeholder'  => 'http://localhost:8000',
					'autocomplete' => 'url',
				),
				'crawl4ai_api_key'                     => array(
					'type'         => 'password',
					'label'        => __( 'Crawl4AI API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Crawl4AI service (if required).', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'brave_search_api_key'                 => array(
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
				'cloudflare_api_token'                 => array(
					'type'         => 'password',
					'label'        => __( 'Cloudflare API Token', 'wp-mcp-ai' ),
					'description'  => __( 'API token for Cloudflare integration. Create a token in your Cloudflare dashboard.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudflare_zone_id'                   => array(
					'type'        => 'text',
					'label'       => __( 'Cloudflare Zone ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudflare zone ID for cache management.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'cloudways_api_key'                    => array(
					'type'         => 'password',
					'label'        => __( 'Cloudways API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Cloudways hosting integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudways_email'                      => array(
					'type'        => 'email',
					'label'       => __( 'Cloudways Account Email', 'wp-mcp-ai' ),
					'description' => __( 'Email address associated with your Cloudways account.', 'wp-mcp-ai' ),
					'placeholder' => 'you@example.com',
				),
				'mailjet_api_key'                      => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Mailjet email service integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'mailjet_api_secret'                   => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Secret', 'wp-mcp-ai' ),
					'description'  => __( 'API secret for Mailjet email service.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'quickbooks_api_key'                   => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for QuickBooks integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'quickbooks_client_id'                 => array(
					'type'        => 'text',
					'label'       => __( 'QuickBooks Client ID', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Client ID from QuickBooks developer portal.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'quickbooks_client_secret'             => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from QuickBooks developer portal.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'google_analytics_property_id'         => array(
					'type'        => 'text',
					'label'       => __( 'Google Analytics Property ID', 'wp-mcp-ai' ),
					'description' => __( 'Google Analytics 4 Property ID (e.g., 123456789).', 'wp-mcp-ai' ),
					'placeholder' => '123456789',
				),
				'google_analytics_credentials'         => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics Service Account JSON', 'wp-mcp-ai' ),
					'description' => __( 'Service account credentials in JSON format from Google Cloud Console.', 'wp-mcp-ai' ),
					'placeholder' => '{"type": "service_account", ...}',
				),

				// GitHub Integration fields.
				'github_client_id'                     => array(
					'type'         => 'text',
					'label'        => __( 'GitHub OAuth Client ID', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: URL to GitHub Developer Settings */
						__( 'OAuth 2.0 Client ID from GitHub Developer Settings. Create an OAuth app at %s.', 'wp-mcp-ai' ),
						'<a href="https://github.com/settings/developers" target="_blank">GitHub Developer Settings</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'github_client_secret'                 => array(
					'type'         => 'password',
					'label'        => __( 'GitHub OAuth Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from GitHub Developer Settings.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'github_access_token'                  => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),
				'github_username'                      => array(
					'type'        => 'hidden',
					'label'       => '',
					'description' => '',
				),

				// Plugins Integration fields.
				'enable_jetengine_cct'                 => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable JetEngine CCT Storage', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable JetEngine CCT storage', 'wp-mcp-ai' ),
					'description'    => __( 'Use JetEngine Custom Content Types for efficient chat transcript and assistant data storage.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_jetengine_tools'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable JetEngine AI Tools', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable JetEngine AI tools', 'wp-mcp-ai' ),
					'description'    => __( 'Activate JetEngine-specific tools for post type management, taxonomy operations, and CCT queries.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_woocommerce_tools'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable WooCommerce AI Tools', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable WooCommerce AI tools', 'wp-mcp-ai' ),
					'description'    => __( 'Activate WooCommerce-specific tools for managing products, orders, and customers.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'enable_elementor_widgets'             => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Elementor AI Widgets', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Elementor AI widgets', 'wp-mcp-ai' ),
					'description'    => __( 'Add AI-powered chat widgets and other AI elements to Elementor page builder.', 'wp-mcp-ai' ),
					'default'        => true,
				),

				// Features fields.
				'enable_mesh_computing'                => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Mesh Computing', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable distributed computing features', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this instance to participate in mesh computing networks.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'enable_federation'                    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Federation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable federated discovery', 'wp-mcp-ai' ),
					'description'    => __( 'Allows this instance to be discovered by and connect to other WP oOS instances.', 'wp-mcp-ai' ),
					'default'        => false,
				),

				// Media fields.
				'enable_ai_media_library'              => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Media Library', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically analyze images on upload', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, newly uploaded images will be automatically analyzed by AI to generate alt text and captions. This feature uses vision-capable AI models (requires OpenAI or Gemini API key).', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'ai_media_generate_alt_text'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Generate Alt Text', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically generate alt text for accessibility', 'wp-mcp-ai' ),
					'description'    => __( 'Generate descriptive alt text for images to improve accessibility for screen readers and SEO.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'ai_media_generate_caption'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Generate Captions', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically generate image captions', 'wp-mcp-ai' ),
					'description'    => __( 'Generate detailed captions for images to provide context and enhance content.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'ai_media_overwrite_existing'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Overwrite Existing', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Replace existing alt text and captions', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, AI will overwrite any existing alt text or captions. When disabled, AI will only fill in missing metadata.', 'wp-mcp-ai' ),
					'default'        => false,
				),

				// Comments fields.
				'enable_ai_comments_moderation'        => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Comments Moderation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically analyze comments for spam and toxicity', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, incoming comments will be automatically analyzed by AI to detect spam, toxic content, and other moderation concerns before they are published.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'ai_comments_sensitivity'              => array(
					'type'        => 'select',
					'label'       => __( 'Moderation Sensitivity', 'wp-mcp-ai' ),
					'description' => __( 'Controls how strict the AI moderation should be. Low = permissive (only flag obvious violations), Medium = balanced (flag clear issues), High = strict (flag anything questionable).', 'wp-mcp-ai' ),
					'options'     => array(
						'low'    => __( 'Low (Permissive)', 'wp-mcp-ai' ),
						'medium' => __( 'Medium (Balanced)', 'wp-mcp-ai' ),
						'high'   => __( 'High (Strict)', 'wp-mcp-ai' ),
					),
					'default'     => 'medium',
				),
				'ai_comments_min_confidence'           => array(
					'type'        => 'select',
					'label'       => __( 'Minimum Confidence Level', 'wp-mcp-ai' ),
					'description' => __( 'Only apply AI recommendations when confidence is at or above this threshold. Lower values trust AI more, higher values require more certainty.', 'wp-mcp-ai' ),
					'options'     => array(
						'0.5' => __( '50% (Trust AI more)', 'wp-mcp-ai' ),
						'0.6' => __( '60%', 'wp-mcp-ai' ),
						'0.7' => __( '70% (Balanced - Recommended)', 'wp-mcp-ai' ),
						'0.8' => __( '80%', 'wp-mcp-ai' ),
						'0.9' => __( '90% (Very conservative)', 'wp-mcp-ai' ),
					),
					'default'     => '0.7',
				),
				'ai_comments_auto_hold_low_confidence' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Hold Low Confidence Comments', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Hold comments for manual review when AI confidence is below threshold', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, comments that AI analyzes with low confidence will be held for moderation instead of being published or marked as spam.', 'wp-mcp-ai' ),
					'default'        => true,
				),

				// Site Creator fields.
				'enable_site_creator'                  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Site Creator', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow AI to create and configure sites', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, AI assistants can use site creator tools to automatically install themes, plugins, update options, and create content. This feature requires manage_options capability.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_plugin_install'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Plugin Installation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic plugin installation from WordPress.org', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to install and activate plugins from the WordPress.org repository. Plugins are only installed from trusted WordPress.org sources.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_theme_install'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Theme Installation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic theme installation from WordPress.org', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to install and activate themes from the WordPress.org repository. Themes are only installed from trusted WordPress.org sources.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'site_creator_allow_option_updates'    => array(
					'type'           => 'checkbox',
					'label'          => __( 'Allow Option Updates', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable automatic WordPress option updates', 'wp-mcp-ai' ),
					'description'    => __( 'Allows AI to update WordPress options (e.g., blogname, blogdescription) via the update_option tool.', 'wp-mcp-ai' ),
					'default'        => false,
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			return array(
				'tools_manager'  => array(
					'id'     => 'tools_manager',
					'label'  => __( 'Tools Manager', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-list-view',
					'fields' => array(), // Custom rendering, no form fields.
				),
				'connections'    => array(
					'id'     => 'connections',
					'label'  => __( 'Connections', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-links',
					'fields' => array(), // Custom rendering via Integrations section.
				),
				'external_tools' => array(
					'id'     => 'external_tools',
					'label'  => __( 'External Tools', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-site-alt3',
					'fields' => array(
						'gmail_client_id',
						'gmail_client_secret',
						'crawl4ai_base_url',
						'crawl4ai_api_key',
						'brave_search_api_key',
						'cloudflare_api_token',
						'cloudflare_zone_id',
						'cloudways_api_key',
						'cloudways_email',
						'mailjet_api_key',
						'mailjet_api_secret',
						'quickbooks_api_key',
						'quickbooks_client_id',
						'quickbooks_client_secret',
						'google_analytics_property_id',
						'google_analytics_credentials',
						'github_client_id',
						'github_client_secret',
					),
				),
				'plugins'        => array(
					'id'     => 'plugins',
					'label'  => __( 'Plugins', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-plugins',
					'fields' => array(
						'enable_jetengine_cct',
						'enable_jetengine_tools',
						'enable_woocommerce_tools',
						'enable_elementor_widgets',
					),
				),
				'features'       => array(
					'id'     => 'features',
					'label'  => __( 'Features', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-tools',
					'fields' => array( 'enable_mesh_computing', 'enable_federation' ),
				),
				'media'          => array(
					'id'     => 'media',
					'label'  => __( 'AI Media Library', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-format-image',
					'fields' => array( 'enable_ai_media_library', 'ai_media_generate_alt_text', 'ai_media_generate_caption', 'ai_media_overwrite_existing' ),
				),
				'comments'       => array(
					'id'     => 'comments',
					'label'  => __( 'AI Comments', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-comments',
					'fields' => array( 'enable_ai_comments_moderation', 'ai_comments_sensitivity', 'ai_comments_min_confidence', 'ai_comments_auto_hold_low_confidence' ),
				),
				'site_creator'   => array(
					'id'     => 'site_creator',
					'label'  => __( 'Site Creator', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-site',
					'fields' => array( 'enable_site_creator', 'site_creator_allow_plugin_install', 'site_creator_allow_theme_install', 'site_creator_allow_option_updates' ),
				),
			);
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			if ( isset( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}

			// Default to 'tools_manager' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'tools_manager';
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

			// Special handling for tools_manager subtab.
			if ( 'tools_manager' === $active_subtab ) {
				$this->render_tools_manager();
				return;
			}

			// The 'connections' subtab is handled by the Integrations section itself,
			// which will render when the subtab is active. No special rendering needed here.

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
				case 'external_tools':
					$this->render_external_tools_footer();
					break;
				case 'plugins':
					$this->render_plugins_footer();
					break;
				case 'media':
					$this->render_media_footer();
					break;
				case 'comments':
					$this->render_comments_footer();
					break;
				case 'site_creator':
					$this->render_site_creator_footer();
					break;
			}
		}

		/**
		 * Render External Tools footer content.
		 */
		private function render_external_tools_footer() {
			$settings          = WP_MCP_AI_Admin_Settings::get_settings();
			$github_connected  = ! empty( $settings['github_access_token'] );
			$github_username   = isset( $settings['github_username'] ) ? $settings['github_username'] : '';
			$has_credentials   = ! empty( $settings['github_client_id'] ) && ! empty( $settings['github_client_secret'] );
			$oauth_connect_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=wp_mcp_ai_github_oauth_start' ),
				'wp_mcp_ai_github_oauth_start'
			);
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'GitHub Connection', 'wp-mcp-ai' ); ?></th>
				<td>
					<?php if ( $github_connected ) : ?>
						<div style="padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #155724;">
								<span class="dashicons dashicons-yes" style="color: #155724;"></span>
								<strong><?php esc_html_e( 'Connected to GitHub', 'wp-mcp-ai' ); ?></strong>
								<?php if ( $github_username ) : ?>
									<?php
									printf(
										/* translators: %s: GitHub username */
										esc_html__( 'as %s', 'wp-mcp-ai' ),
										'<code>' . esc_html( $github_username ) . '</code>'
									);
									?>
								<?php endif; ?>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button">
								<?php esc_html_e( 'Reconnect GitHub Account', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Your GitHub account is connected. You can now use GitHub integration tools to manage repositories, create Codespaces, and develop custom tools.',
									'wp-mcp-ai'
								)
							);
							?>
						</p>
					<?php elseif ( $has_credentials ) : ?>
						<div style="padding: 10px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #856404;">
								<span class="dashicons dashicons-warning" style="color: #856404;"></span>
								<strong><?php esc_html_e( 'GitHub Not Connected', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p>
							<a href="<?php echo esc_url( $oauth_connect_url ); ?>" class="button button-primary">
								<?php esc_html_e( 'Connect GitHub Account', 'wp-mcp-ai' ); ?>
							</a>
						</p>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'Click the button above to authorize WP MCP AI to access your GitHub account. You will be redirected to GitHub to grant permissions.',
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
							$scopes             = WP_MCP_AI_Github_OAuth_Handler::GITHUB_OAUTH_SCOPES;
							$scope_descriptions = array(
								'repo'      => __( 'Full control of private repositories', 'wp-mcp-ai' ),
								'user'      => __( 'Read user profile data', 'wp-mcp-ai' ),
								'codespace' => __( 'Manage GitHub Codespaces', 'wp-mcp-ai' ),
							);
							foreach ( explode( ',', $scopes ) as $scope ) {
								$scope = trim( $scope );
								if ( isset( $scope_descriptions[ $scope ] ) ) {
									echo '<li><code>' . esc_html( $scope ) . '</code>: ' . esc_html( $scope_descriptions[ $scope ] ) . '</li>';
								}
							}
							?>
						</ul>
					<?php else : ?>
						<div style="padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; margin-bottom: 10px;">
							<p style="margin: 0; color: #721c24;">
								<span class="dashicons dashicons-info" style="color: #721c24;"></span>
								<strong><?php esc_html_e( 'GitHub OAuth Credentials Required', 'wp-mcp-ai' ); ?></strong>
							</p>
						</div>
						<p class="description">
							<?php
							echo wp_kses_post(
								__(
									'To connect your GitHub account, first configure your GitHub OAuth Client ID and Client Secret in the fields above, then save your settings.',
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
									/* translators: %s: URL to GitHub Developer Settings */
									wp_kses_post( __( 'Go to <a href="%s" target="_blank">GitHub Developer Settings</a>', 'wp-mcp-ai' ) ),
									esc_url( 'https://github.com/settings/developers' )
								);
								?>
							</li>
							<li><?php esc_html_e( 'Click "New OAuth App"', 'wp-mcp-ai' ); ?></li>
							<li>
								<?php
								printf(
									/* translators: %s: Callback URL */
									esc_html__( 'Set Authorization callback URL to: %s', 'wp-mcp-ai' ),
									'<br><code>' . esc_html( admin_url( 'admin-post.php?action=wp_mcp_ai_github_oauth_callback' ) ) . '</code>'
								);
								?>
							</li>
							<li><?php esc_html_e( 'Copy the Client ID and Client Secret to the fields above', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Save your settings', 'wp-mcp-ai' ); ?></li>
							<li><?php esc_html_e( 'Click the "Connect GitHub Account" button that will appear', 'wp-mcp-ai' ); ?></li>
						</ol>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'About External Tools:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'External tools are third-party service integrations that extend AI capabilities beyond WordPress. Configure API credentials here to enable tools that interact with external platforms and services.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Security Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'API credentials are stored securely in your WordPress database. Only users with manage_options capability can view or modify these settings. Never share API keys publicly or commit them to version control.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Plugins Integration footer content.
		 */
		private function render_plugins_footer() {
			$jetengine_active   = class_exists( 'Jet_Engine' );
			$woocommerce_active = class_exists( 'WooCommerce' );
			$elementor_active   = did_action( 'elementor/loaded' );
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Plugin Integration Status:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li>
							<strong><?php esc_html_e( 'JetEngine:', 'wp-mcp-ai' ); ?></strong>
							<?php if ( $jetengine_active ) : ?>
								<span style="color: #0a5f1a;">✓ <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></span>
							<?php else : ?>
								<span style="color: #646970;">○ <?php esc_html_e( 'Not Active', 'wp-mcp-ai' ); ?></span>
							<?php endif; ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'WooCommerce:', 'wp-mcp-ai' ); ?></strong>
							<?php if ( $woocommerce_active ) : ?>
								<span style="color: #0a5f1a;">✓ <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></span>
							<?php else : ?>
								<span style="color: #646970;">○ <?php esc_html_e( 'Not Active', 'wp-mcp-ai' ); ?></span>
							<?php endif; ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'Elementor:', 'wp-mcp-ai' ); ?></strong>
							<?php if ( $elementor_active ) : ?>
								<span style="color: #0a5f1a;">✓ <?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></span>
							<?php else : ?>
								<span style="color: #646970;">○ <?php esc_html_e( 'Not Active', 'wp-mcp-ai' ); ?></span>
							<?php endif; ?>
						</li>
					</ul>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Tools and features for inactive plugins will be automatically disabled. Install and activate the corresponding plugins to enable their AI integrations.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render AI Media Library footer content.
		 */
		private function render_media_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires a vision-capable AI provider (OpenAI GPT-4o or Gemini) to be configured in the Providers tab. Image analysis will use the default provider specified in General Settings.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Each image upload will consume AI tokens. Consider the API costs when enabling this feature for high-volume sites.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render AI Comments Moderation footer content.
		 */
		private function render_comments_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'How it works:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'AI analyzes comment text, author information, and context', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Detects spam indicators: promotional content, suspicious links, generic comments', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Detects toxic content: hate speech, harassment, threats, offensive language', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Provides a recommendation: approve, hold for moderation, or mark as spam', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Comments from logged-in moderators are never automatically flagged', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'AI analysis is stored as comment metadata for review by moderators', 'wp-mcp-ai' ); ?></li>
					</ul>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires an AI provider (OpenAI or Gemini) to be configured. Each comment will consume a small amount of AI tokens.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Site Creator footer content.
		 */
		private function render_site_creator_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Security Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Site creator tools require administrative capabilities (manage_options, install_plugins, install_themes). Only users with these capabilities can execute site creator operations. All plugins and themes are installed exclusively from the official WordPress.org repository.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<strong><?php esc_html_e( 'Performance Consideration:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Site creation operations (especially plugin/theme installation) can take several minutes to complete and may temporarily impact site performance. These operations are marked as long-running and should be executed with appropriate timeouts.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
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
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Tools settings sub-tabs', 'wp-mcp-ai' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							$subtab_url = add_query_arg(
								array(
									'page'   => 'wp-mcp-ai-dashboard',
									'tab'    => 'tools',
									'subtab' => $group['id'],
								),
								admin_url( 'admin.php' )
							);
							$is_active  = ( $group['id'] === $active_subtab );
							?>
							<a href="<?php echo esc_url( $subtab_url ); ?>" 
								class="wp-mcp-ai-subtab <?php echo $is_active ? 'wp-mcp-ai-subtab-active' : ''; ?>"
								data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<!-- Hidden field to preserve subtab during form submission -->
					<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_subtab ); ?>" />

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
		 * Render the Tools Manager view.
		 *
		 * Displays all registered tools in a categorized table with enable/disable functionality.
		 */
		private function render_tools_manager() {
			$registry     = WP_MCP_AI_Tool_Registry::get_instance();
			$all_tools    = $registry->get_tools();
			$tool_groups  = $registry->get_tool_group_map();
			$group_labels = $registry->get_tool_group_labels();

			// Group tools by category.
			$categorized_tools = array(
				'wordpress-core'    => array(),
				'wordpress-plugins' => array(),
				'external-tools'    => array(),
				'other'             => array(),
			);

			foreach ( $all_tools as $tool ) {
				$slug  = $tool->get_slug();
				$group = isset( $tool_groups[ $slug ] ) ? $tool_groups[ $slug ] : 'other';

				if ( ! isset( $categorized_tools[ $group ] ) ) {
					$categorized_tools[ $group ] = array();
				}

				$categorized_tools[ $group ][] = $tool;
			}

			// Get search/filter parameters.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter.
			$search = isset( $_GET['tool_search'] ) ? sanitize_text_field( wp_unslash( $_GET['tool_search'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter.
			$filter_group = isset( $_GET['tool_group'] ) ? sanitize_key( $_GET['tool_group'] ) : '';

			?>
			<div class="wp-mcp-ai-tools-manager">
				<!-- Header Section -->
				<div style="margin-bottom: 20px;">
					<h3><?php esc_html_e( 'Tools Manager', 'wp-mcp-ai' ); ?></h3>
					<p class="description">
						<?php
						printf(
							/* translators: %d: Total number of registered tools */
							esc_html__( 'View and manage all %d registered AI tools. Tools can be filtered by category and searched by name or description.', 'wp-mcp-ai' ),
							count( $all_tools )
						);
						?>
					</p>
				</div>

				<!-- Search and Filter Bar -->
				<div class="wp-mcp-ai-tools-filter-bar" style="margin-bottom: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
					<form method="get" action="" style="display: flex; gap: 10px; align-items: center;">
						<input type="hidden" name="page" value="<?php echo esc_attr( WP_MCP_AI_Settings_Dashboard::PAGE_SLUG ); ?>">
						<input type="hidden" name="tab" value="tools">
						<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_subtab ); ?>">

						<label for="tool_search" style="font-weight: 600;">
							<?php esc_html_e( 'Search:', 'wp-mcp-ai' ); ?>
						</label>
						<input type="search" 
								id="tool_search" 
								name="tool_search" 
								value="<?php echo esc_attr( $search ); ?>" 
								placeholder="<?php esc_attr_e( 'Search tools...', 'wp-mcp-ai' ); ?>" 
								style="flex: 1; max-width: 300px;">

						<label for="tool_group" style="font-weight: 600; margin-left: 10px;">
							<?php esc_html_e( 'Category:', 'wp-mcp-ai' ); ?>
						</label>
						<select id="tool_group" name="tool_group" style="min-width: 200px;">
							<option value=""><?php esc_html_e( 'All Categories', 'wp-mcp-ai' ); ?></option>
							<?php foreach ( $group_labels as $group_key => $group_label ) : ?>
								<option value="<?php echo esc_attr( $group_key ); ?>" <?php selected( $filter_group, $group_key ); ?>>
									<?php echo esc_html( $group_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>

						<button type="submit" class="button">
							<?php esc_html_e( 'Filter', 'wp-mcp-ai' ); ?>
						</button>

						<?php if ( ! empty( $search ) || ! empty( $filter_group ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WP_MCP_AI_Settings_Dashboard::PAGE_SLUG . '&tab=tools&subtab=tools_manager' ) ); ?>" class="button">
								<?php esc_html_e( 'Clear', 'wp-mcp-ai' ); ?>
							</a>
						<?php endif; ?>
					</form>
				</div>

				<!-- Tools by Category -->
				<?php
				foreach ( $categorized_tools as $category => $tools ) :
					// Skip if category is empty or filtered out.
					if ( empty( $tools ) || ( ! empty( $filter_group ) && $filter_group !== $category ) ) {
						continue;
					}

					// Apply search filter.
					if ( ! empty( $search ) ) {
						$tools = array_filter(
							$tools,
							function ( $tool ) use ( $search ) {
								$slug        = $tool->get_slug();
								$description = $tool->get_description();
								$name        = $this->get_tool_display_name( $slug );
								$search_term = strtolower( $search );

								return false !== stripos( $slug, $search_term ) ||
										false !== stripos( $description, $search_term ) ||
										false !== stripos( $name, $search_term );
							}
						);

						if ( empty( $tools ) ) {
							continue;
						}
					}

					$category_label = isset( $group_labels[ $category ] ) ? $group_labels[ $category ] : __( 'Other', 'wp-mcp-ai' );
					?>

					<div class="wp-mcp-ai-tools-category" style="margin-bottom: 30px;">
						<h4 style="margin-bottom: 10px; padding: 10px; background: #f0f0f0; border-left: 4px solid #0073aa;">
							<?php echo esc_html( $category_label ); ?>
							<span class="badge" style="background: #0073aa; color: white; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin-left: 10px;">
								<?php echo count( $tools ); ?>
							</span>
						</h4>

						<table class="wp-list-table widefat fixed striped" style="margin-bottom: 20px;">
							<thead>
								<tr>
									<th style="width: 20%;"><?php esc_html_e( 'Tool Name', 'wp-mcp-ai' ); ?></th>
									<th style="width: 15%;"><?php esc_html_e( 'Slug', 'wp-mcp-ai' ); ?></th>
									<th style="width: 40%;"><?php esc_html_e( 'Description', 'wp-mcp-ai' ); ?></th>
									<th style="width: 15%;"><?php esc_html_e( 'Status', 'wp-mcp-ai' ); ?></th>
									<th style="width: 10%;"><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach ( $tools as $tool ) :
									$slug        = $tool->get_slug();
									$description = $tool->get_description();
									$name        = $this->get_tool_display_name( $slug );

									// Check if tool has dependencies.
									$dependencies = $this->check_tool_dependencies( $slug );
									$is_available = $dependencies['available'];

									// Check if tool is enabled.
									$is_enabled   = $registry->is_tool_enabled( $slug );
									$status_text  = $is_enabled ? __( 'Enabled', 'wp-mcp-ai' ) : __( 'Disabled', 'wp-mcp-ai' );
									$status_color = $is_enabled ? '#46b450' : '#999';

									// If tool is unavailable due to dependencies, override status.
									if ( ! $is_available ) {
										$status_text  = __( 'Unavailable', 'wp-mcp-ai' );
										$status_color = '#dc3232';
									}
									?>
									<tr data-tool-slug="<?php echo esc_attr( $slug ); ?>">
										<td>
											<strong><?php echo esc_html( $name ); ?></strong>
										</td>
										<td>
											<code style="font-size: 11px;"><?php echo esc_html( $slug ); ?></code>
										</td>
										<td>
											<?php echo esc_html( $description ); ?>
											<?php if ( ! empty( $dependencies['missing'] ) ) : ?>
												<div style="margin-top: 5px; font-size: 12px; color: #dc3232;">
													<strong><?php esc_html_e( 'Missing:', 'wp-mcp-ai' ); ?></strong>
													<?php echo esc_html( implode( ', ', $dependencies['missing'] ) ); ?>
												</div>
											<?php endif; ?>
										</td>
										<td>
											<span class="wp-mcp-ai-tool-status" style="display: inline-block; padding: 4px 8px; background: <?php echo esc_attr( $status_color ); ?>; color: white; border-radius: 3px; font-size: 11px; font-weight: bold;">
												<?php echo esc_html( $status_text ); ?>
											</span>
										</td>
										<td>
											<?php if ( $is_available ) : ?>
												<label class="wp-mcp-ai-toggle-switch" style="position: relative; display: inline-block; width: 50px; height: 24px;">
													<input type="checkbox" 
															class="wp-mcp-ai-tool-toggle" 
															data-tool-slug="<?php echo esc_attr( $slug ); ?>"
															<?php checked( $is_enabled ); ?>
															style="opacity: 0; width: 0; height: 0;">
													<span class="wp-mcp-ai-toggle-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; border-radius: 24px; transition: .4s;"></span>
												</label>
											<?php else : ?>
												<span class="dashicons dashicons-warning" style="color: #dc3232;" title="<?php esc_attr_e( 'Tool is unavailable due to missing dependencies', 'wp-mcp-ai' ); ?>"></span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

				<?php endforeach; ?>

				<?php if ( ! empty( $search ) && empty( array_filter( $categorized_tools ) ) ) : ?>
					<div class="notice notice-warning inline">
						<p>
							<?php
							printf(
								/* translators: %s: Search term */
								esc_html__( 'No tools found matching "%s". Try a different search term.', 'wp-mcp-ai' ),
								esc_html( $search )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<!-- Information Footer -->
				<div style="margin-top: 30px; padding: 15px; background: #f0f6fc; border: 1px solid #c3e6ff; border-radius: 4px;">
					<h4 style="margin-top: 0;">
						<span class="dashicons dashicons-info" style="color: #0073aa;"></span>
						<?php esc_html_e( 'About Tool Categories', 'wp-mcp-ai' ); ?>
					</h4>
					<ul style="margin-left: 20px;">
						<li>
							<strong><?php esc_html_e( 'WordPress Core:', 'wp-mcp-ai' ); ?></strong>
							<?php esc_html_e( 'Tools that work with base WordPress installation without any external dependencies.', 'wp-mcp-ai' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'WordPress Plugins:', 'wp-mcp-ai' ); ?></strong>
							<?php esc_html_e( 'Tools that require specific third-party WordPress plugins to be installed and active.', 'wp-mcp-ai' ); ?>
						</li>
						<li>
							<strong><?php esc_html_e( 'External Tools:', 'wp-mcp-ai' ); ?></strong>
							<?php esc_html_e( 'Tools that require external API credentials or third-party service integrations.', 'wp-mcp-ai' ); ?>
						</li>
					</ul>
					<p style="margin-bottom: 0;">
						<?php
						printf(
							/* translators: %s: Link to tool documentation */
							wp_kses_post( __( 'For detailed information about each tool and its requirements, see the <a href="%s" target="_blank">Tool Reference Documentation</a>.', 'wp-mcp-ai' ) ),
							esc_url( 'https://github.com/nvdigitalsolutions/wp-mcp-ai/blob/main/docs/tool-reference.md' )
						);
						?>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Get display name for a tool.
		 *
		 * @param string $slug Tool slug.
		 * @return string Display name.
		 */
		private function get_tool_display_name( $slug ) {
			// Convert slug to title case.
			$name = str_replace( '_', ' ', $slug );
			return ucwords( $name );
		}

		/**
		 * Check tool dependencies.
		 *
		 * @param string $slug Tool slug.
		 * @return array Array with 'available' (bool) and 'missing' (array of missing dependencies).
		 */
		private function check_tool_dependencies( $slug ) {
			$missing = array();

			// Allowed check functions for security.
			$allowed_check_functions = array( 'class_exists', 'function_exists', 'interface_exists', 'trait_exists', 'method_exists' );

			// Check for plugin-specific tools.
			$plugin_requirements = array(
				'get_elementor_templates'        => array(
					'plugin' => 'Elementor',
					'check'  => 'class_exists',
					'value'  => '\Elementor\Plugin',
				),
				'get_woo_recent_orders'          => array(
					'plugin' => 'WooCommerce',
					'check'  => 'class_exists',
					'value'  => 'WooCommerce',
				),
				'get_woo_products'               => array(
					'plugin' => 'WooCommerce',
					'check'  => 'class_exists',
					'value'  => 'WooCommerce',
				),
				'create_woo_product'             => array(
					'plugin' => 'WooCommerce',
					'check'  => 'class_exists',
					'value'  => 'WooCommerce',
				),
				'get_jetengine_items'            => array(
					'plugin' => 'JetEngine',
					'check'  => 'function_exists',
					'value'  => 'jet_engine',
				),
				'list_jetengine_rest_routes'     => array(
					'plugin' => 'JetEngine',
					'check'  => 'function_exists',
					'value'  => 'jet_engine',
				),
				'invoke_jetengine_route'         => array(
					'plugin' => 'JetEngine',
					'check'  => 'function_exists',
					'value'  => 'jet_engine',
				),
				'get_jetformbuilder_forms'       => array(
					'plugin' => 'JetFormBuilder',
					'check'  => 'class_exists',
					'value'  => 'Jet_Form_Builder\Plugin',
				),
				'get_jetformbuilder_submissions' => array(
					'plugin' => 'JetFormBuilder',
					'check'  => 'class_exists',
					'value'  => 'Jet_Form_Builder\Plugin',
				),
				'get_rankmath_seo'               => array(
					'plugin' => 'Rank Math SEO',
					'check'  => 'function_exists',
					'value'  => 'rank_math',
				),
				'create_wpcode_snippet'          => array(
					'plugin' => 'WPCode',
					'checks' => array(
						array(
							'check' => 'function_exists',
							'value' => 'WPCode',
						),
						array(
							'check' => 'class_exists',
							'value' => 'WPCode_Snippet',
						),
					),
				),
				'generate_simple_jwt_token'      => array(
					'plugin' => 'Simple JWT Login',
					'check'  => 'class_exists',
					'value'  => '\\SimpleJWTLogin\\Modules\\WordPressData',
				),
			);

			if ( isset( $plugin_requirements[ $slug ] ) ) {
				$requirement = $plugin_requirements[ $slug ];

				// Support both single check and multiple checks.
				if ( isset( $requirement['checks'] ) ) {
					// Multiple checks - all must pass.
					foreach ( $requirement['checks'] as $check_config ) {
						$check_func  = $check_config['check'];
						$check_value = $check_config['value'];

						// Validate check function is in allowlist.
						if ( ! in_array( $check_func, $allowed_check_functions, true ) ) {
							// Invalid check function - mark tool as unavailable.
							$missing[] = $requirement['plugin'];
							break;
						}

						if ( ! $check_func( $check_value ) ) {
							$missing[] = $requirement['plugin'];
							break; // No need to check further once one fails.
						}
					}
				} else {
					// Single check.
					$check_func  = $requirement['check'];
					$check_value = $requirement['value'];

					// Validate check function is in allowlist.
					if ( ! in_array( $check_func, $allowed_check_functions, true ) ) {
						// Invalid check function - mark tool as unavailable.
						$missing[] = $requirement['plugin'];
					} elseif ( ! $check_func( $check_value ) ) {
						// Check failed - mark tool as unavailable.
						$missing[] = $requirement['plugin'];
					}
				}
			}

			return array(
				'available' => empty( $missing ),
				'missing'   => $missing,
			);
		}
	}
}

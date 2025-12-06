<?php
/**
 * Overview Dashboard Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Overview' ) ) {
	/**
	 * Overview dashboard section - displays system status and quick links.
	 */
	class WP_MCP_AI_Section_Overview extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'overview';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'System Overview', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'overview';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 10;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Quick overview of your WP Open Operator System configuration and status.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * This section is display-only, no editable fields.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array();
		}

		/**
		 * Render the section content.
		 */
		/**
		 * Render the section content.
		 *
		 * This method is abstract in the parent class but not used in this display-only section.
		 */
		public function render() {
			// Not used - we override render_wrapper() instead.
		}

		/**
		 * Override render_wrapper to provide custom layout without form table.
		 */
		public function render_wrapper() {
			$description = $this->get_description();
			?>
<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
<h2><?php echo esc_html( $this->get_title() ); ?></h2>
			<?php if ( $description ) : ?>
<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
<?php endif; ?>
			<?php $this->render_content(); ?>
</div>
			<?php
		}

		public function render_content() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			?>
			<div class="wp-mcp-ai-overview-dashboard">
				<!-- System Status Cards -->
				<div class="wp-mcp-ai-status-cards">
					<?php $this->render_auth0_status_card( $settings ); ?>
					<?php $this->render_providers_status_card( $settings ); ?>
					<?php $this->render_features_status_card( $settings ); ?>
				</div>

				<!-- Quick Links -->
				<div class="wp-mcp-ai-quick-links">
					<h3><?php esc_html_e( 'Quick Links', 'wp-mcp-ai' ); ?></h3>
					<div class="wp-mcp-ai-links-grid">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=authentication' ) ); ?>" class="wp-mcp-ai-link-card">
							<span class="dashicons dashicons-lock"></span>
							<strong><?php esc_html_e( 'Authentication Settings', 'wp-mcp-ai' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Configure Auth0, JWT, and guest tokens', 'wp-mcp-ai' ); ?></span>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-auth0-setup' ) ); ?>" class="wp-mcp-ai-link-card">
							<span class="dashicons dashicons-admin-tools"></span>
							<strong><?php esc_html_e( 'Auth0 Setup Wizard', 'wp-mcp-ai' ); ?></strong>
							<span class="description"><?php esc_html_e( '1-click Auth0 configuration', 'wp-mcp-ai' ); ?></span>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=providers' ) ); ?>" class="wp-mcp-ai-link-card">
							<span class="dashicons dashicons-admin-generic"></span>
							<strong><?php esc_html_e( 'AI Providers', 'wp-mcp-ai' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Configure OpenAI, Gemini, Ollama', 'wp-mcp-ai' ); ?></span>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools' ) ); ?>" class="wp-mcp-ai-link-card">
							<span class="dashicons dashicons-admin-tools"></span>
							<strong><?php esc_html_e( 'Tools & Features', 'wp-mcp-ai' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Enable and configure tools', 'wp-mcp-ai' ); ?></span>
						</a>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_assistant' ) ); ?>" class="wp-mcp-ai-link-card">
							<span class="dashicons dashicons-admin-users"></span>
							<strong><?php esc_html_e( 'Manage Assistants', 'wp-mcp-ai' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Create and configure AI assistants', 'wp-mcp-ai' ); ?></span>
						</a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=security' ) ); ?>" class="wp-mcp-ai-link-card">
							<span class="dashicons dashicons-shield"></span>
							<strong><?php esc_html_e( 'Security Settings', 'wp-mcp-ai' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Monitor and configure security', 'wp-mcp-ai' ); ?></span>
						</a>
						<a href="<?php echo esc_url( admin_url( 'tools.php?page=wp-mcp-ai-mcp-diagnostic' ) ); ?>" class="wp-mcp-ai-link-card">
							<span class="dashicons dashicons-admin-tools"></span>
							<strong><?php esc_html_e( 'MCP Server Diagnostic', 'wp-mcp-ai' ); ?></strong>
							<span class="description"><?php esc_html_e( 'Test MCP methods and server functionality', 'wp-mcp-ai' ); ?></span>
						</a>
					</div>
				</div>
			</div>

			<?php $this->render_pro_banner(); ?>

			<style>
				.wp-mcp-ai-overview-dashboard {
					max-width: 1200px;
				}
				.wp-mcp-ai-status-cards {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
					gap: 20px;
					margin-bottom: 30px;
				}
				.wp-mcp-ai-status-card {
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					padding: 20px;
					box-shadow: 0 1px 3px rgba(0,0,0,0.05);
				}
				.wp-mcp-ai-status-card h3 {
					margin-top: 0;
					font-size: 16px;
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.wp-mcp-ai-status-card h3 .dashicons {
					font-size: 20px;
					width: 20px;
					height: 20px;
				}
				.wp-mcp-ai-status-item {
					display: flex;
					justify-content: space-between;
					align-items: center;
					padding: 10px 0;
					border-bottom: 1px solid #f0f0f0;
				}
				.wp-mcp-ai-status-item:last-child {
					border-bottom: none;
				}
				.wp-mcp-ai-status-badge {
					display: inline-block;
					padding: 4px 10px;
					border-radius: 3px;
					font-size: 12px;
					font-weight: 600;
					text-transform: uppercase;
				}
				.wp-mcp-ai-status-badge.configured {
					background: #d4edda;
					color: #155724;
				}
				.wp-mcp-ai-status-badge.not-configured {
					background: #f8d7da;
					color: #721c24;
				}
				.wp-mcp-ai-status-badge.enabled {
					background: #d1ecf1;
					color: #0c5460;
				}
				.wp-mcp-ai-quick-links {
					margin-top: 30px;
				}
				.wp-mcp-ai-quick-links h3 {
					font-size: 18px;
					margin-bottom: 15px;
				}
				.wp-mcp-ai-links-grid {
					display: grid;
					grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
					gap: 15px;
				}
				.wp-mcp-ai-link-card {
					display: flex;
					flex-direction: column;
					gap: 8px;
					padding: 16px;
					background: #fff;
					border: 1px solid #ddd;
					border-radius: 4px;
					text-decoration: none;
					color: inherit;
					transition: all 0.2s ease;
				}
				.wp-mcp-ai-link-card:hover {
					border-color: #2271b1;
					box-shadow: 0 2px 6px rgba(0,0,0,0.1);
					transform: translateY(-2px);
				}
				.wp-mcp-ai-link-card .dashicons {
					font-size: 24px;
					width: 24px;
					height: 24px;
					color: #2271b1;
				}
				.wp-mcp-ai-link-card strong {
					font-size: 14px;
					color: #1d2327;
				}
				.wp-mcp-ai-link-card .description {
					font-size: 12px;
					color: #646970;
				}
			</style>
			<?php
		}

		/**
		 * Render Auth0 status card.
		 *
		 * @param array $settings Plugin settings.
		 */
		private function render_auth0_status_card( $settings ) {
			$auth0_domain   = ! empty( $settings['auth0_domain'] ) ? $settings['auth0_domain'] : '';
			$auth0_audience = ! empty( $settings['auth0_audience'] ) ? $settings['auth0_audience'] : '';
			$github_bridge  = ! empty( $settings['enable_auth0_github_bridge'] );
			$has_mgmt_creds = ! empty( $settings['auth0_management_client_id'] ) && ! empty( $settings['auth0_management_client_secret'] );

			?>
			<div class="wp-mcp-ai-status-card">
				<h3>
					<span class="dashicons dashicons-lock"></span>
					<?php esc_html_e( 'Auth0 Authentication', 'wp-mcp-ai' ); ?>
				</h3>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'Domain', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $auth0_domain ? 'configured' : 'not-configured' ); ?>">
						<?php echo $auth0_domain ? esc_html__( 'Configured', 'wp-mcp-ai' ) : esc_html__( 'Not Set', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'API Audience', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $auth0_audience ? 'configured' : 'not-configured' ); ?>">
						<?php echo $auth0_audience ? esc_html__( 'Configured', 'wp-mcp-ai' ) : esc_html__( 'Not Set', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'GitHub Bridge', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $github_bridge ? 'enabled' : 'not-configured' ); ?>">
						<?php echo $github_bridge ? esc_html__( 'Enabled', 'wp-mcp-ai' ) : esc_html__( 'Disabled', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'Management API', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $has_mgmt_creds ? 'configured' : 'not-configured' ); ?>">
						<?php echo $has_mgmt_creds ? esc_html__( 'Configured', 'wp-mcp-ai' ) : esc_html__( 'Not Set', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<?php if ( ! $auth0_domain || ! $auth0_audience ) : ?>
					<p style="margin-top: 15px; margin-bottom: 0;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-auth0-setup' ) ); ?>" class="button button-primary">
							<?php esc_html_e( 'Setup Auth0', 'wp-mcp-ai' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Render AI providers status card.
		 *
		 * @param array $settings Plugin settings.
		 */
		private function render_providers_status_card( $settings ) {
			$has_openai       = ! empty( $settings['openai_api_key'] );
			$has_gemini       = ! empty( $settings['gemini_api_key'] );
			$has_ollama       = ! empty( $settings['ollama_endpoint_url'] );
			$has_lm_studio    = ! empty( $settings['lm_studio_endpoint_url'] );
			$default_provider = ! empty( $settings['default_provider'] ) ? $settings['default_provider'] : 'openai';

			?>
			<div class="wp-mcp-ai-status-card">
				<h3>
					<span class="dashicons dashicons-admin-generic"></span>
					<?php esc_html_e( 'AI Providers', 'wp-mcp-ai' ); ?>
				</h3>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'OpenAI', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $has_openai ? 'configured' : 'not-configured' ); ?>">
						<?php echo $has_openai ? esc_html__( 'Configured', 'wp-mcp-ai' ) : esc_html__( 'Not Set', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'Google Gemini', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $has_gemini ? 'configured' : 'not-configured' ); ?>">
						<?php echo $has_gemini ? esc_html__( 'Configured', 'wp-mcp-ai' ) : esc_html__( 'Not Set', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'Ollama (Local)', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $has_ollama ? 'configured' : 'not-configured' ); ?>">
						<?php echo $has_ollama ? esc_html__( 'Configured', 'wp-mcp-ai' ) : esc_html__( 'Not Set', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'LM Studio', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $has_lm_studio ? 'configured' : 'not-configured' ); ?>">
						<?php echo $has_lm_studio ? esc_html__( 'Configured', 'wp-mcp-ai' ) : esc_html__( 'Not Set', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item" style="border-top: 1px solid #ddd; margin-top: 10px; padding-top: 15px;">
					<span><?php esc_html_e( 'Default Provider', 'wp-mcp-ai' ); ?></span>
					<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $default_provider ) ) ); ?></strong>
				</div>
			</div>
			<?php
		}

		/**
		 * Render features status card.
		 *
		 * @param array $settings Plugin settings.
		 */
		private function render_features_status_card( $settings ) {
			$logging_enabled = ! empty( $settings['enable_logging'] );
			$federation      = ! empty( $settings['enable_federation'] );
			$mesh_enabled    = ! empty( $settings['enable_mesh'] );

			// Count configured connectors.
			$connectors       = array(
				'brave'            => ! empty( $settings['brave_search_api_key'] ),
				'crawl4ai'         => ! empty( $settings['crawl4ai_base_url'] ),
				'cloudflare'       => ! empty( $settings['cloudflare_api_token'] ),
				'cloudways'        => ! empty( $settings['cloudways_api_key'] ),
				'mailjet'          => ! empty( $settings['mailjet_api_key'] ),
				'quickbooks'       => ! empty( $settings['quickbooks_api_key'] ),
				'google_analytics' => ! empty( $settings['google_analytics_property_id'] ),
				'gmail'            => ! empty( $settings['gmail_refresh_token'] ),
				'rest_list'        => ! empty( $settings['rest_enable_assistant_list'] ),
				'rest_create'      => ! empty( $settings['rest_enable_assistant_create'] ),
				'rest_delete'      => ! empty( $settings['rest_enable_assistant_delete'] ),
			);
			$configured_count = count( array_filter( $connectors ) );
			$total_count      = count( $connectors );

			?>
			<div class="wp-mcp-ai-status-card">
				<h3>
					<span class="dashicons dashicons-admin-tools"></span>
					<?php esc_html_e( 'Features & Integrations', 'wp-mcp-ai' ); ?>
				</h3>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'Debug Logging', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $logging_enabled ? 'enabled' : 'not-configured' ); ?>">
						<?php echo $logging_enabled ? esc_html__( 'Enabled', 'wp-mcp-ai' ) : esc_html__( 'Disabled', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'Federation', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $federation ? 'enabled' : 'not-configured' ); ?>">
						<?php echo $federation ? esc_html__( 'Enabled', 'wp-mcp-ai' ) : esc_html__( 'Disabled', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item">
					<span><?php esc_html_e( 'Mesh Network', 'wp-mcp-ai' ); ?></span>
					<span class="wp-mcp-ai-status-badge <?php echo esc_attr( $mesh_enabled ? 'enabled' : 'not-configured' ); ?>">
						<?php echo $mesh_enabled ? esc_html__( 'Enabled', 'wp-mcp-ai' ) : esc_html__( 'Disabled', 'wp-mcp-ai' ); ?>
					</span>
				</div>
				<div class="wp-mcp-ai-status-item" style="border-top: 1px solid #ddd; margin-top: 10px; padding-top: 15px;">
					<span><?php esc_html_e( 'Service Connectors', 'wp-mcp-ai' ); ?></span>
					<strong>
						<?php
						printf(
							/* translators: 1: configured count, 2: total count */
							esc_html__( '%1$d of %2$d configured', 'wp-mcp-ai' ),
							esc_html( $configured_count ),
							esc_html( $total_count )
						);
						?>
					</strong>
				</div>
			</div>
			<?php
		}

		/**
		 * Sanitize section input.
		 *
		 * This section has no editable fields, so return empty array.
		 *
		 * @param array $input Raw input.
		 * @return array
		 */
		public function sanitize( $input ) {
			return array();
		}

		/**
		 * Validate section input.
		 *
		 * This section has no editable fields, so always valid.
		 *
		 * @param array $input Sanitized input.
		 * @return bool|WP_Error
		 */
		public function validate( $input ) {
			return true;
		}

	/**
	 * Render Pro addon promotional banner for base version.
	 */
	private function render_pro_banner() {
		if ( defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}
		?>
		<div style="padding: 15px; background: #f0f6fc; border-left: 4px solid #0073aa; margin: 20px 0;">
			<p style="margin: 0 0 10px 0; font-size: 14px;">
				<strong><?php esc_html_e( 'Get WP oOS Pro for Premium Features', 'wp-mcp-ai' ); ?></strong>
			</p>
			<p style="margin: 0 0 10px 0;">
				<?php
				echo wp_kses_post(
					__(
						'Enable AI assistants to automatically install themes, plugins, update options, and create content. More powerful features available in the Pro addon.',
						'wp-mcp-ai'
					)
				);
				?>
			</p>
			<p style="margin: 0;">
				<a href="https://link.nvdigital.solutions/wpoos-pro-buy" target="_blank" class="button button-primary" style="margin-right: 10px;">
					<?php esc_html_e( 'Get WP oOS Pro', 'wp-mcp-ai' ); ?>
				</a>
				<a href="https://link.nvdigital.solutions/wpoos-pro-info" target="_blank" class="button">
					<?php esc_html_e( 'Learn More About Pro Tools', 'wp-mcp-ai' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
	}
}

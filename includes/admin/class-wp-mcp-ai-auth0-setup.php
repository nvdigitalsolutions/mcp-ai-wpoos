<?php
/**
 * Auth0 1-Click Setup Wizard for WP oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Auth0_Setup' ) ) {
	/**
	 * Handles the Auth0 1-click setup wizard.
	 */
	class WP_MCP_AI_Auth0_Setup {
		const PAGE_SLUG = 'wp-mcp-ai-auth0-setup';

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
			add_action( 'wp_ajax_wp_mcp_ai_auto_configure_auth0', array( $this, 'handle_auto_configure' ) );
			add_action( 'wp_ajax_wp_mcp_ai_toggle_auth0_bridge', array( $this, 'handle_toggle_bridge' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}

		/**
		 * Register the setup wizard page.
		 */
		public function register_page() {
			$this->page_hook = add_submenu_page(
				'wp-mcp-ai-dashboard',
				__( 'Auth0 Setup', 'wp-mcp-ai' ),
				__( 'Auth0 Setup', 'wp-mcp-ai' ),
				'manage_options',
				self::PAGE_SLUG,
				array( $this, 'render_page' )
			);
		}

		/**
		 * Enqueue assets for the setup page.
		 *
		 * @param string $hook Current admin page hook.
		 */
		public function enqueue_assets( $hook ) {
			if ( $this->page_hook !== $hook ) {
				return;
			}

			wp_enqueue_style(
				'wp-mcp-ai-auth0-setup',
				WP_MCP_AI_URL . 'assets/css/admin-settings.css',
				array(),
				WP_MCP_AI_VERSION
			);

			wp_enqueue_script(
				'wp-mcp-ai-auth0-setup',
				WP_MCP_AI_URL . 'assets/js/auth0-setup.js',
				array( 'jquery' ),
				WP_MCP_AI_VERSION,
				true
			);

			wp_localize_script(
				'wp-mcp-ai-auth0-setup',
				'wpMcpAiAuth0Setup',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'wp-mcp-ai-auth0-setup' ),
				)
			);
		}

		/**
		 * Render the setup wizard page.
		 */
		public function render_page() {
			$settings       = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$domain         = isset( $settings['auth0_domain'] ) ? $settings['auth0_domain'] : '';
			$audience       = isset( $settings['auth0_audience'] ) ? $settings['auth0_audience'] : '';
			$bridge_enabled = ! empty( $settings['enable_auth0_github_bridge'] );
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Auth0 GitHub Bridge - 1-Click Setup', 'wp-mcp-ai' ); ?></h1>

				<div class="wp-mcp-ai-setup-wizard">
					<!-- Current Status -->
					<div class="card">
						<h2><?php esc_html_e( 'Current Configuration', 'wp-mcp-ai' ); ?></h2>
						<table class="form-table">
							<tr>
								<th><?php esc_html_e( 'Auth0 Domain', 'wp-mcp-ai' ); ?></th>
								<td>
									<code id="current-domain"><?php echo $domain ? esc_html( $domain ) : esc_html__( 'Not configured', 'wp-mcp-ai' ); ?></code>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Audience', 'wp-mcp-ai' ); ?></th>
								<td>
									<code id="current-audience"><?php echo $audience ? esc_html( $audience ) : esc_html__( 'Not configured', 'wp-mcp-ai' ); ?></code>
								</td>
							</tr>
							<tr>
								<th><?php esc_html_e( 'Enable Auth0 GitHub Bridge', 'wp-mcp-ai' ); ?></th>
								<td>
									<label for="enable-auth0-github-bridge">
										<input 
											type="checkbox" 
											id="enable-auth0-github-bridge" 
											name="enable_auth0_github_bridge" 
											value="1" 
											<?php checked( $bridge_enabled ); ?>
										/>
										<?php esc_html_e( 'Resolve Auth0 GitHub identities into WordPress users', 'wp-mcp-ai' ); ?>
									</label>
									<p class="description">
										<?php esc_html_e( 'Maps Auth0 GitHub identities to WordPress users for REST auditing and assistant scoping.', 'wp-mcp-ai' ); ?>
									</p>
								</td>
							</tr>
						</table>
					</div>

					<!-- Step 1: Auto-Configure from Token -->
					<div class="card">
						<h2><?php esc_html_e( 'Step 1: Auto-Configure from Auth0 Token', 'wp-mcp-ai' ); ?></h2>
						<p><?php esc_html_e( 'Paste an Auth0 bearer token to automatically extract and configure your Auth0 domain and audience.', 'wp-mcp-ai' ); ?></p>
						
						<div class="setup-step">
							<label for="auth0-token">
								<?php esc_html_e( 'Auth0 Bearer Token', 'wp-mcp-ai' ); ?>
							</label>
							<textarea 
								id="auth0-token" 
								class="large-text code" 
								rows="4" 
								placeholder="eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9..."
							></textarea>
							<p class="description">
								<?php esc_html_e( 'Get a token from your Auth0 application for testing. The token will be decoded (not verified) to extract configuration.', 'wp-mcp-ai' ); ?>
							</p>

							<p>
								<button type="button" id="auto-configure-btn" class="button button-primary">
									<?php esc_html_e( 'Auto-Configure', 'wp-mcp-ai' ); ?>
								</button>
								<span class="spinner"></span>
							</p>

							<div id="auto-configure-result" style="display:none; margin-top: 15px;"></div>
						</div>
					</div>

					<!-- Step 2: Next Steps -->
					<div class="card">
						<h2><?php esc_html_e( 'Step 2: Complete Setup', 'wp-mcp-ai' ); ?></h2>
						<p><?php esc_html_e( 'After auto-configuring the domain, complete these additional steps:', 'wp-mcp-ai' ); ?></p>

						<ol class="setup-checklist">
							<li>
								<strong><?php esc_html_e( 'Create Auth0 Management API Application', 'wp-mcp-ai' ); ?></strong>
								<p><?php esc_html_e( 'In your Auth0 dashboard, create a Machine-to-Machine application with access to the Management API.', 'wp-mcp-ai' ); ?></p>
								<p>
									<a href="#" id="open-auth0-dashboard" class="button button-secondary" target="_blank">
										<?php esc_html_e( 'Open Auth0 Dashboard', 'wp-mcp-ai' ); ?>
									</a>
								</p>
							</li>
							<li>
								<strong><?php esc_html_e( 'Grant Required Permissions', 'wp-mcp-ai' ); ?></strong>
								<p><?php esc_html_e( 'Grant the application at least the following scopes:', 'wp-mcp-ai' ); ?></p>
								<ul>
									<li><code>read:users</code></li>
									<li><code>read:user_idp_tokens</code> (optional)</li>
								</ul>
							</li>
							<li>
								<strong><?php esc_html_e( 'Configure Credentials in Settings', 'wp-mcp-ai' ); ?></strong>
								<p><?php esc_html_e( 'Copy the Client ID and Client Secret to:', 'wp-mcp-ai' ); ?></p>
								<p>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=authentication' ) ); ?>" class="button button-secondary">
										<?php esc_html_e( 'Go to WP oOS Settings', 'wp-mcp-ai' ); ?>
									</a>
								</p>
							</li>
							<li>
								<strong><?php esc_html_e( 'Enable the Bridge', 'wp-mcp-ai' ); ?></strong>
								<p><?php esc_html_e( 'Check "Enable Auth0 GitHub bridge" in the settings to activate the integration.', 'wp-mcp-ai' ); ?></p>
							</li>
						</ol>
					</div>

					<!-- Documentation -->
					<div class="card">
						<h2><?php esc_html_e( 'Documentation', 'wp-mcp-ai' ); ?></h2>
						<p>
							<?php
							printf(
								/* translators: %s: documentation URL */
								wp_kses_post( __( 'For complete setup instructions, see the <a href="%s" target="_blank">Auth0 GitHub Bridge documentation</a>.', 'wp-mcp-ai' ) ),
								esc_url( 'https://github.com/nvdigitalsolutions/wp-mcp-ai/blob/main/docs/mcp-server-authentication.md#bridging-github-identities-via-auth0' )
							);
							?>
						</p>
					</div>
				</div>
			</div>

			<style>
				.wp-mcp-ai-setup-wizard { max-width: 900px; }
				.wp-mcp-ai-setup-wizard .card { margin-bottom: 20px; }
				.status-badge { 
					padding: 4px 12px; 
					border-radius: 3px; 
					font-weight: 600;
					display: inline-block;
				}
				.status-badge.enabled { 
					background: #4caf50; 
					color: white; 
				}
				.status-badge.disabled { 
					background: #ccc; 
					color: #666; 
				}
				.setup-step { margin: 20px 0; }
				.setup-checklist { 
					line-height: 1.6; 
					margin-left: 20px;
				}
				.setup-checklist li { 
					margin-bottom: 20px; 
				}
				.setup-checklist code { 
					background: #f5f5f5; 
					padding: 2px 6px; 
					border-radius: 3px; 
				}
				#auto-configure-result.success {
					padding: 12px;
					background: #d4edda;
					border: 1px solid #c3e6cb;
					border-radius: 4px;
					color: #155724;
				}
				#auto-configure-result.error {
					padding: 12px;
					background: #f8d7da;
					border: 1px solid #f5c6cb;
					border-radius: 4px;
					color: #721c24;
				}
			</style>
			<?php
		}

		/**
		 * Handle AJAX request to auto-configure Auth0 from a test token.
		 */
		public function handle_auto_configure() {
			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp-mcp-ai-auth0-setup', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get token from request.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$token = isset( $_POST['token'] ) ? trim( wp_unslash( $_POST['token'] ) ) : '';

			if ( empty( $token ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an Auth0 bearer token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Decode the JWT to extract issuer.
			$payload = $this->decode_jwt_payload( $token );

			if ( is_wp_error( $payload ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Failed to decode token: %s', 'wp-mcp-ai' ),
							$payload->get_error_message()
						),
					)
				);
				return;
			}

			// Extract Auth0 domain from issuer.
			if ( empty( $payload['iss'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Token does not contain an issuer (iss) claim.', 'wp-mcp-ai' ) ) );
				return;
			}

			$issuer = $payload['iss'];
			$domain = $this->extract_auth0_domain_from_issuer( $issuer );

			if ( ! $domain ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: issuer URL */
							__( 'Could not extract Auth0 domain from issuer: %s', 'wp-mcp-ai' ),
							esc_html( $issuer )
						),
					)
				);
				return;
			}

			// Update settings with the extracted domain.
			$settings                 = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$settings['auth0_domain'] = $domain;

			// Extract audience if present.
			if ( ! empty( $payload['aud'] ) ) {
				$audience                   = is_array( $payload['aud'] ) ? $payload['aud'][0] : $payload['aud'];
				$settings['auth0_audience'] = sanitize_text_field( $audience );
			}

			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

			wp_send_json_success(
				array(
					'message'  => __( 'Auth0 domain configured successfully!', 'wp-mcp-ai' ),
					'domain'   => $domain,
					'audience' => ! empty( $settings['auth0_audience'] ) ? $settings['auth0_audience'] : '',
				)
			);
		}

		/**
		 * Handle AJAX request to toggle the Auth0 GitHub bridge.
		 */
		public function handle_toggle_bridge() {
			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp-mcp-ai-auth0-setup', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get enabled state from request.
			$enabled = ! empty( $_POST['enabled'] );

			// Update settings.
			$settings                               = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$settings['enable_auth0_github_bridge'] = $enabled;
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

			wp_send_json_success(
				array(
					'message' => $enabled
						? __( 'Auth0 GitHub bridge enabled successfully!', 'wp-mcp-ai' )
						: __( 'Auth0 GitHub bridge disabled successfully!', 'wp-mcp-ai' ),
					'enabled' => $enabled,
				)
			);
		}

		/**
		 * Decode JWT payload without verification (for auto-configuration only).
		 *
		 * @param string $token JWT token.
		 * @return array|WP_Error Decoded payload or error.
		 */
		private function decode_jwt_payload( $token ) {
			$parts = explode( '.', $token );

			if ( count( $parts ) !== 3 ) {
				return new WP_Error( 'invalid_token', __( 'Invalid JWT format. Expected 3 parts separated by dots.', 'wp-mcp-ai' ) );
			}

			$payload_encoded = $parts[1];

			// Add padding if needed.
			$remainder = strlen( $payload_encoded ) % 4;
			if ( $remainder ) {
				$payload_encoded .= str_repeat( '=', 4 - $remainder );
			}

			// Decode base64url.
			$payload_json = base64_decode( strtr( $payload_encoded, '-_', '+/' ), true );

			if ( false === $payload_json ) {
				return new WP_Error( 'decode_failed', __( 'Failed to base64 decode JWT payload.', 'wp-mcp-ai' ) );
			}

			$payload = json_decode( $payload_json, true );

			if ( ! is_array( $payload ) ) {
				return new WP_Error( 'invalid_json', __( 'JWT payload is not valid JSON.', 'wp-mcp-ai' ) );
			}

			return $payload;
		}

		/**
		 * Extract Auth0 domain from issuer URL.
		 *
		 * @param string $issuer Issuer URL from JWT.
		 * @return string|false Auth0 domain or false on failure.
		 */
		private function extract_auth0_domain_from_issuer( $issuer ) {
			// Issuer should be like: https://example.us.auth0.com/ or https://example.auth0.com/.
			$parsed = wp_parse_url( $issuer );

			if ( empty( $parsed['host'] ) ) {
				return false;
			}

			// Return the host part (e.g., example.us.auth0.com).
			return $parsed['host'];
		}
	}
}

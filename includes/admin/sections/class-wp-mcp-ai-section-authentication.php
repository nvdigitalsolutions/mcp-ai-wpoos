<?php
/**
 * Authentication Settings Section
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Authentication' ) ) {
	/**
	 * Authentication settings section.
	 */
	class WP_MCP_AI_Section_Authentication extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'authentication';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Authentication Settings', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'authentication';
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
			return __( 'Configure authentication methods for REST API access (Auth0, JWT, Guest tokens).', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				// Auth0 Settings.
				'auth0_domain'                     => array(
					'type'        => 'text',
					'label'       => __( 'Auth0 Domain', 'wp-mcp-ai' ),
					'description' => __( 'Your Auth0 tenant domain. You can find this in your Auth0 dashboard under Settings → General (e.g., your-domain.auth0.com or your-domain.us.auth0.com for US region).', 'wp-mcp-ai' ),
					'placeholder' => 'your-domain.auth0.com',
				),
				'auth0_audience'                   => array(
					'type'        => 'text',
					'label'       => __( 'Auth0 API Audience', 'wp-mcp-ai' ),
					'description' => __( 'The unique identifier for your Auth0 API, configured in the Auth0 Dashboard under APIs. This is typically a URL-like identifier (e.g., https://api.yourapp.com or urn:yourapp:api).', 'wp-mcp-ai' ),
					'placeholder' => 'https://your-api.example.com',
				),
				'auth0_required_scope'             => array(
					'type'        => 'text',
					'label'       => __( 'Required Access Scope', 'wp-mcp-ai' ),
					'description' => __( 'Optional scope that must be present in Auth0 access tokens to use the API. Leave empty to allow any valid token. Multiple scopes can be space-separated (e.g., read:mcp write:mcp).', 'wp-mcp-ai' ),
					'placeholder' => 'read:mcp',
				),

				// Auth0 GitHub Bridge.
				'enable_auth0_github_bridge'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Auth0 GitHub Bridge', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Resolve Auth0 GitHub identities into WordPress users', 'wp-mcp-ai' ),
					'description'    => __( 'Maps Auth0 GitHub identities to WordPress users for REST auditing and assistant scoping.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'auth0_management_client_id'       => array(
					'type'         => 'text',
					'label'        => __( 'Auth0 Management Client ID', 'wp-mcp-ai' ),
					'description'  => __( 'Client ID for Auth0 Management API. Required for GitHub Bridge feature to resolve user identities from GitHub accounts via Auth0.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'auth0_management_client_secret'   => array(
					'type'         => 'password',
					'label'        => __( 'Auth0 Management Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'Client Secret for Auth0 Management API. Keep this secure as it grants administrative access to your Auth0 tenant.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// WordPress.com/Gravatar Bridge.
				'enable_wpcom_gravatar_bridge'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable WordPress.com/Gravatar Bridge', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Resolve WordPress.com/Gravatar identities', 'wp-mcp-ai' ),
					'description'    => __( 'Maps WordPress.com/Gravatar emails to WordPress users.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'wpcom_gravatar_userinfo_endpoint' => array(
					'type'        => 'url',
					'label'       => __( 'Userinfo Endpoint', 'wp-mcp-ai' ),
					'description' => __( 'WordPress.com userinfo endpoint URL', 'wp-mcp-ai' ),
					/**
					 * Filter the default WordPress.com userinfo endpoint URL.
					 *
					 * @since 1.0.0
					 *
					 * @param string $url Default URL. Default 'https://public-api.wordpress.com/oauth2/userinfo'.
					 */
					'default'     => apply_filters( 'wp_mcp_ai_default_wpcom_userinfo_endpoint', 'https://public-api.wordpress.com/oauth2/userinfo' ),
					'placeholder' => 'https://public-api.wordpress.com/oauth2/userinfo',
				),

				// Simple JWT Login.
				'enable_simple_jwt_login'          => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Simple JWT Login', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow Simple JWT Login bearer tokens', 'wp-mcp-ai' ),
					'description'    => __( 'Enables bearer tokens validated by Simple JWT Login plugin to access the MCP REST API.', 'wp-mcp-ai' ),
					'default'        => false,
				),

				// Guest Access.
				'guest_token_lifetime'             => array(
					'type'        => 'number',
					'label'       => __( 'Guest Token Lifetime (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'How long guest tokens remain valid. Default: 86400 (24 hours)', 'wp-mcp-ai' ),
					'default'     => 86400,
					'placeholder' => '86400',
				),

				// REST API Capabilities.
				'rest_enable_assistant_list'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable REST Assistant Listing', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow listing assistants via REST API (GET /wp-json/mcp-ai/v1/assistants)', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, authenticated API clients can retrieve the list of available assistants. Enabled by default. Disable for enhanced security if you don\'t need remote assistant discovery.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'rest_enable_assistant_create'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable REST Assistant Creation', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow creating assistants via REST API (POST /wp-json/mcp-ai/v1/assistants)', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, authenticated API clients can create new assistants remotely. Requires proper authentication (Auth0, assistant credentials, or JWT).', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'rest_enable_assistant_delete'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable REST Assistant Deletion', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow deleting assistants via REST API (DELETE /wp-json/mcp-ai/v1/assistants/{id})', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, authenticated API clients can delete assistants remotely. Use with caution - this is irreversible.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'sse_enable_post_method'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable POST Method on SSE Endpoint', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Allow POST requests to /wp-json/mcp-ai/v1/sse endpoint', 'wp-mcp-ai' ),
					'description'    => __( 'SSE (Server-Sent Events) standard only uses GET. Enable POST only if you have LM Studio or client bugs requiring it. Leave disabled for standard SSE compliance.', 'wp-mcp-ai' ),
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
				'auth0'          => array(
					'id'     => 'auth0',
					'label'  => __( 'Auth0 Configuration', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-lock',
					'fields' => array( 'auth0_domain', 'auth0_audience', 'auth0_required_scope' ),
				),
				'auth0_github'   => array(
					'id'     => 'auth0_github',
					'label'  => __( 'GitHub Bridge', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-users',
					'fields' => array( 'enable_auth0_github_bridge', 'auth0_management_client_id', 'auth0_management_client_secret' ),
				),
				'wpcom_gravatar' => array(
					'id'     => 'wpcom_gravatar',
					'label'  => __( 'WordPress.com/Gravatar', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-wordpress',
					'fields' => array( 'enable_wpcom_gravatar_bridge', 'wpcom_gravatar_userinfo_endpoint' ),
				),
				'jwt'            => array(
					'id'     => 'jwt',
					'label'  => __( 'Simple JWT Login', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-network',
					'fields' => array( 'enable_simple_jwt_login' ),
				),
				'guest'          => array(
					'id'     => 'guest',
					'label'  => __( 'Guest Access', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-groups',
					'fields' => array( 'guest_token_lifetime' ),
				),
				'rest_api'       => array(
					'id'     => 'rest_api',
					'label'  => __( 'REST API Capabilities', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-rest-api',
					'fields' => array( 'rest_enable_assistant_list', 'rest_enable_assistant_create', 'rest_enable_assistant_delete', 'sse_enable_post_method' ),
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
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			if ( isset( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}

			// Default to 'auth0' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'auth0';
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
				case 'auth0':
					$this->render_auth0_footer();
					break;
				case 'auth0_github':
					$this->render_auth0_github_footer();
					break;
				case 'guest':
					$this->render_guest_footer();
					break;
				case 'rest_api':
					$this->render_rest_api_footer();
					break;
			}
		}

		/**
		 * Render Auth0 Configuration footer content.
		 */
		private function render_auth0_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p style="margin-top: 15px; margin-bottom: 0;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-auth0-setup' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Open Auth0 Setup Wizard', 'wp-mcp-ai' ); ?>
						</a>
					</p>
					<p class="description" style="margin-top: 8px;">
						<?php esc_html_e( 'Use the 1-click setup wizard to automatically configure Auth0 from a bearer token.', 'wp-mcp-ai' ); ?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Auth0 GitHub Bridge footer content.
		 */
		private function render_auth0_github_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'How it works:', 'wp-mcp-ai' ); ?></strong>
					</p>
					<ul style="list-style: disc; margin-left: 20px;">
						<li><?php esc_html_e( 'Maps Auth0 GitHub identities to WordPress users for REST API requests', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Enables proper user attribution and assistant scoping for GitHub-authenticated users', 'wp-mcp-ai' ); ?></li>
						<li><?php esc_html_e( 'Requires Auth0 Management API credentials with read:users permission', 'wp-mcp-ai' ); ?></li>
					</ul>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render Guest Access footer content.
		 */
		private function render_guest_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'Guest tokens allow unauthenticated users to interact with public chat interfaces. Tokens expire after the configured lifetime and are stored in browser localStorage.',
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
		 * Render REST API Capabilities footer content.
		 */
		private function render_rest_api_footer() {
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Security Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'These settings control which REST API operations are allowed. Enabling assistant creation/deletion allows authenticated API clients to manage assistants remotely. Use with caution in production environments.',
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
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Authentication settings sub-tabs', 'wp-mcp-ai' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							$subtab_url = add_query_arg(
								array(
									'page'   => 'wp-mcp-ai-dashboard',
									'tab'    => 'authentication',
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
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate URLs.
			if ( isset( $input['wpcom_gravatar_userinfo_endpoint'] ) && ! empty( $input['wpcom_gravatar_userinfo_endpoint'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_url( $input['wpcom_gravatar_userinfo_endpoint'] );
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'WordPress.com Userinfo Endpoint: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate guest token lifetime.
			if ( isset( $input['guest_token_lifetime'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['guest_token_lifetime'],
					60,
					604800
				);
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Guest Token Lifetime must be between 60 seconds and 7 days (604800 seconds).', 'wp-mcp-ai' );
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}

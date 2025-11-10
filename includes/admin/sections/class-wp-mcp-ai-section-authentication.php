<?php
/**
 * Authentication Settings Section
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
			);
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			// Group fields by authentication method.
			$groups = array(
				'Auth0 Configuration' => array(
					'auth0_domain',
					'auth0_audience',
					'auth0_required_scope',
				),
				'Auth0 GitHub Bridge' => array(
					'enable_auth0_github_bridge',
					'auth0_management_client_id',
					'auth0_management_client_secret',
				),
				'WordPress.com/Gravatar Bridge' => array(
					'enable_wpcom_gravatar_bridge',
					'wpcom_gravatar_userinfo_endpoint',
				),
				'Simple JWT Login'    => array(
					'enable_simple_jwt_login',
				),
				'Guest Access'        => array(
					'guest_token_lifetime',
				),
			);

			foreach ( $groups as $group_name => $field_keys ) {
				echo '<tr><th colspan="2"><h3 style="margin: 20px 0 10px 0;">' . esc_html( $group_name ) . '</h3></th></tr>';

				foreach ( $field_keys as $key ) {
					if ( isset( $fields[ $key ] ) ) {
						$this->render_field( $key, $fields[ $key ] );
					}
				}

				// Add button to Auth0 Setup Wizard after Auth0 Configuration fields.
				if ( 'Auth0 Configuration' === $group_name ) {
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
			}
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

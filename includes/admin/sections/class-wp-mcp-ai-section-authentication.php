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
					'description' => __( 'Your Auth0 domain (e.g., your-domain.auth0.com)', 'wp-mcp-ai' ),
					'placeholder' => 'your-domain.auth0.com',
				),
				'auth0_audience'                   => array(
					'type'        => 'text',
					'label'       => __( 'Auth0 API Audience', 'wp-mcp-ai' ),
					'description' => __( 'The API identifier/audience for your Auth0 API', 'wp-mcp-ai' ),
					'placeholder' => 'https://your-api.example.com',
				),
				'auth0_required_scope'             => array(
					'type'        => 'text',
					'label'       => __( 'Required Access Scope', 'wp-mcp-ai' ),
					'description' => __( 'Scope required in Auth0 tokens (e.g., read:mcp)', 'wp-mcp-ai' ),
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
					'type'        => 'text',
					'label'       => __( 'Auth0 Management Client ID', 'wp-mcp-ai' ),
					'description' => __( 'Client ID for Auth0 Management API', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'auth0_management_client_secret'   => array(
					'type'        => 'password',
					'label'       => __( 'Auth0 Management Client Secret', 'wp-mcp-ai' ),
					'description' => __( 'Client Secret for Auth0 Management API', 'wp-mcp-ai' ),
					'placeholder' => '',
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
					'default'     => 'https://public-api.wordpress.com/oauth2/userinfo',
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

<?php
/**
 * Bitwarden OAuth Handler for WP MCP AI
 *
 * Handles OAuth flows for Bitwarden Vault integration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Bitwarden_OAuth_Handler' ) ) {
	/**
	 * Manages Bitwarden OAuth authentication flows.
	 */
	class WP_MCP_AI_Bitwarden_OAuth_Handler {
		/**
		 * Bitwarden Identity Server endpoint (configurable for self-hosted)
		 *
		 * @var string
		 */
		private $identity_server_url;

		/**
		 * Bitwarden API endpoint (configurable for self-hosted)
		 *
		 * @var string
		 */
		private $api_server_url;

		/**
		 * OAuth scopes for Bitwarden access
		 *
		 * @var string
		 */
		const BITWARDEN_OAUTH_SCOPES = 'api offline_access';

		/**
		 * Constructor.
		 */
		public function __construct() {
			$settings                  = WP_MCP_AI_Admin_Settings::get_settings();
			$this->identity_server_url = ! empty( $settings['bitwarden_identity_server'] ) ? $settings['bitwarden_identity_server'] : 'https://identity.bitwarden.com';
			$this->api_server_url      = ! empty( $settings['bitwarden_api_server'] ) ? $settings['bitwarden_api_server'] : 'https://api.bitwarden.com';
		}

		/**
		 * Handle the start of the Bitwarden OAuth flow.
		 *
		 * Redirects the user to Bitwarden's authorization page.
		 */
		public function handle_bitwarden_oauth_start() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_bitwarden_oauth_start' );

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['bitwarden_client_id'] ) || empty( $settings['bitwarden_client_secret'] ) ) {
				$this->add_settings_redirect_notice(
					'bitwarden_oauth_missing_client',
					__( 'Enter a Bitwarden OAuth client ID and secret before connecting the account.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			$state     = wp_generate_uuid4();
			$transient = $this->get_bitwarden_state_transient_key( $state );

			set_transient(
				$transient,
				array(
					'user_id' => get_current_user_id(),
					'time'    => time(),
				),
				10 * MINUTE_IN_SECONDS
			);

			/**
			 * Filter the Bitwarden OAuth scope.
			 *
			 * @since 1.0.0
			 *
			 * @param string $scope OAuth scope. Default 'api offline_access'.
			 */
			$oauth_scope = apply_filters( 'wp_mcp_ai_bitwarden_oauth_scope', self::BITWARDEN_OAUTH_SCOPES );

			$params = array(
				'client_id'     => $settings['bitwarden_client_id'],
				'redirect_uri'  => $this->get_bitwarden_oauth_redirect_uri(),
				'scope'         => $oauth_scope,
				'state'         => $state,
				'response_type' => 'code',
			);

			/**
			 * Filter the Bitwarden OAuth authorize endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth authorize endpoint.
			 */
			$authorize_endpoint = apply_filters( 'wp_mcp_ai_bitwarden_oauth_authorize_endpoint', $this->identity_server_url . '/connect/authorize' );
			$authorize_url      = add_query_arg( $params, $authorize_endpoint );

			wp_safe_redirect( $authorize_url );
			exit;
		}

		/**
		 * Handle the OAuth callback from Bitwarden and persist the access token.
		 */
		public function handle_bitwarden_oauth_callback() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			// OAuth callback parameters from Bitwarden. No nonce verification required as state parameter provides CSRF protection.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

			// Handle error from Bitwarden.
			if ( ! empty( $error ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth error parameter is informational.
				$error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
				$this->add_settings_redirect_notice(
					'bitwarden_oauth_error',
					sprintf(
						/* translators: %1$s: error code, %2$s: error description */
						__( 'Bitwarden authorization failed: %1$s - %2$s', 'mcp-ai-wpoos' ),
						esc_html( $error ),
						esc_html( $error_description )
					)
				);
				$this->redirect_to_settings_page();
			}

			// Validate state parameter.
			$transient = $this->get_bitwarden_state_transient_key( $state );
			$data      = get_transient( $transient );

			if ( false === $data ) {
				$this->add_settings_redirect_notice(
					'bitwarden_oauth_invalid_state',
					__( 'Invalid state parameter. Please try again.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			delete_transient( $transient );

			// Exchange code for access token.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$token_endpoint = apply_filters( 'wp_mcp_ai_bitwarden_oauth_token_endpoint', $this->identity_server_url . '/connect/token' );

			$body = array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->get_bitwarden_oauth_redirect_uri(),
				'client_id'     => $settings['bitwarden_client_id'],
				'client_secret' => $settings['bitwarden_client_secret'],
			);

			$response = wp_remote_post(
				$token_endpoint,
				array(
					'body'    => $body,
					'headers' => array(
						'Accept' => 'application/json',
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->add_settings_redirect_notice(
					'bitwarden_oauth_token_error',
					sprintf(
						/* translators: %s: error message */
						__( 'Failed to obtain access token: %s', 'mcp-ai-wpoos' ),
						$response->get_error_message()
					)
				);
				$this->redirect_to_settings_page();
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$token_data    = json_decode( $response_body, true );

			if ( 200 !== $response_code || empty( $token_data['access_token'] ) ) {
				$error_message = ! empty( $token_data['error_description'] ) ? $token_data['error_description'] : __( 'Unknown error', 'mcp-ai-wpoos' );
				$this->add_settings_redirect_notice(
					'bitwarden_oauth_token_invalid',
					sprintf(
						/* translators: %s: error message */
						__( 'Token exchange failed: %s', 'mcp-ai-wpoos' ),
						esc_html( $error_message )
					)
				);
				$this->redirect_to_settings_page();
			}

			// Get user info from Bitwarden.
			$user_info = $this->get_bitwarden_user_info( $token_data['access_token'] );

			// Store the tokens securely.
			$settings['bitwarden_access_token']  = $token_data['access_token'];
			$settings['bitwarden_refresh_token'] = ! empty( $token_data['refresh_token'] ) ? $token_data['refresh_token'] : '';
			$settings['bitwarden_token_expires'] = ! empty( $token_data['expires_in'] ) ? time() + (int) $token_data['expires_in'] : 0;
			$settings['bitwarden_user_email']    = ! empty( $user_info['email'] ) ? $user_info['email'] : '';
			$settings['bitwarden_user_id']       = ! empty( $user_info['id'] ) ? $user_info['id'] : '';

			update_option( 'wp_mcp_ai_settings', $settings );

			$this->add_settings_redirect_notice(
				'bitwarden_oauth_success',
				__( 'Successfully connected to Bitwarden!', 'mcp-ai-wpoos' ),
				'success'
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Get Bitwarden user info using access token.
		 *
		 * @param string $access_token Access token.
		 * @return array User info array.
		 */
		private function get_bitwarden_user_info( $access_token ) {
			$api_endpoint = $this->api_server_url . '/accounts/profile';

			$response = wp_remote_get(
				$api_endpoint,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Accept'        => 'application/json',
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( 200 !== $response_code ) {
				return array();
			}

			return json_decode( $response_body, true );
		}

		/**
		 * Refresh the access token using the refresh token.
		 *
		 * @return bool True on success, false on failure.
		 */
		public function refresh_access_token() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['bitwarden_refresh_token'] ) ) {
				return false;
			}

			$token_endpoint = apply_filters( 'wp_mcp_ai_bitwarden_oauth_token_endpoint', $this->identity_server_url . '/connect/token' );

			$body = array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $settings['bitwarden_refresh_token'],
				'client_id'     => $settings['bitwarden_client_id'],
				'client_secret' => $settings['bitwarden_client_secret'],
			);

			$response = wp_remote_post(
				$token_endpoint,
				array(
					'body'    => $body,
					'headers' => array(
						'Accept' => 'application/json',
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$token_data    = json_decode( $response_body, true );

			if ( 200 !== $response_code || empty( $token_data['access_token'] ) ) {
				return false;
			}

			// Update the tokens.
			$settings['bitwarden_access_token']  = $token_data['access_token'];
			$settings['bitwarden_refresh_token'] = ! empty( $token_data['refresh_token'] ) ? $token_data['refresh_token'] : $settings['bitwarden_refresh_token'];
			$settings['bitwarden_token_expires'] = ! empty( $token_data['expires_in'] ) ? time() + (int) $token_data['expires_in'] : 0;

			update_option( 'wp_mcp_ai_settings', $settings );

			return true;
		}

		/**
		 * Handle Bitwarden disconnect.
		 */
		public function handle_bitwarden_disconnect() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_bitwarden_disconnect' );

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Clear Bitwarden tokens.
			unset( $settings['bitwarden_access_token'] );
			unset( $settings['bitwarden_refresh_token'] );
			unset( $settings['bitwarden_token_expires'] );
			unset( $settings['bitwarden_user_email'] );
			unset( $settings['bitwarden_user_id'] );

			update_option( 'wp_mcp_ai_settings', $settings );

			$this->add_settings_redirect_notice(
				'bitwarden_disconnect_success',
				__( 'Bitwarden account disconnected successfully.', 'mcp-ai-wpoos' ),
				'success'
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Get the OAuth redirect URI.
		 *
		 * @return string Redirect URI.
		 */
		private function get_bitwarden_oauth_redirect_uri() {
			return admin_url( 'admin-post.php?action=wp_mcp_ai_bitwarden_oauth_callback' );
		}

		/**
		 * Get the transient key for state parameter.
		 *
		 * @param string $state State value.
		 * @return string Transient key.
		 */
		private function get_bitwarden_state_transient_key( $state ) {
			return 'wp_mcp_ai_bitwarden_oauth_state_' . md5( $state );
		}

		/**
		 * Add a redirect notice to be displayed on the settings page.
		 *
		 * @param string $code Notice code.
		 * @param string $message Notice message.
		 * @param string $type Notice type (error, success, warning, info).
		 */
		private function add_settings_redirect_notice( $code, $message, $type = 'error' ) {
			set_transient(
				'wp_mcp_ai_bitwarden_oauth_notice_' . get_current_user_id(),
				array(
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				),
				30
			);
		}

		/**
		 * Redirect to the settings page.
		 */
		private function redirect_to_settings_page() {
			$redirect_url = admin_url( 'options-general.php?page=wp-mcp-ai-settings&tab=tools&sub=external-tools' );
			wp_safe_redirect( $redirect_url );
			exit;
		}

		/**
		 * Allow Bitwarden OAuth redirect host.
		 *
		 * @param array  $hosts Array of allowed hosts.
		 * @param string $host The host to check.
		 * @return array Modified array of allowed hosts.
		 */
		public function allow_bitwarden_oauth_redirect_host( $hosts, $host ) {
			// Parse identity server URL to get host.
			$identity_host = wp_parse_url( $this->identity_server_url, PHP_URL_HOST );
			if ( $identity_host && $host === $identity_host ) {
				$hosts[] = $identity_host;
			}
			return $hosts;
		}

		/**
		 * Check if Bitwarden is connected.
		 *
		 * @return bool True if connected, false otherwise.
		 */
		public static function is_connected() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return ! empty( $settings['bitwarden_access_token'] );
		}
	}
}

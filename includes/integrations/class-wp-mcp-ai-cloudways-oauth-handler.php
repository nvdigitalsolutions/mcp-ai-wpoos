<?php
/**
 * Cloudways OAuth Handler for WP MCP AI
 *
 * Handles OAuth token exchange for Cloudways hosting platform integration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cloudways_OAuth_Handler' ) ) {
	/**
	 * Manages Cloudways OAuth authentication flows.
	 *
	 * Cloudways uses a simplified OAuth flow where email + API key are exchanged
	 * for an access token via POST to /oauth/access_token endpoint.
	 */
	class WP_MCP_AI_Cloudways_OAuth_Handler {
		const CLOUDWAYS_API_BASE              = 'https://api.cloudways.com/api/v1';
		const CLOUDWAYS_OAUTH_TOKEN_ENDPOINT  = 'https://api.cloudways.com/api/v1/oauth/access_token';
		const CLOUDWAYS_SERVERS_ENDPOINT      = 'https://api.cloudways.com/api/v1/server';

		/**
		 * Handle the 1-click connection flow for Cloudways.
		 *
		 * Unlike traditional OAuth, Cloudways uses direct token exchange.
		 * This method validates credentials and obtains an access token.
		 */
		public function handle_cloudways_connect() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_cloudways_connect' );

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['cloudways_email'] ) || empty( $settings['cloudways_api_key'] ) ) {
				$this->add_settings_redirect_notice(
					'cloudways_missing_credentials',
					__( 'Enter your Cloudways email and API key before connecting.', 'mcp-ai-wpoos' ),
					'error'
				);
				$this->redirect_to_settings_page();
			}

			// Exchange email + API key for access token.
			$token_response = $this->exchange_credentials_for_token(
				$settings['cloudways_email'],
				$settings['cloudways_api_key']
			);

			if ( is_wp_error( $token_response ) ) {
				WP_MCP_AI_Admin_Settings::log(
					'Cloudways OAuth token exchange failed.',
					array( 'error' => $token_response->get_error_message() )
				);
				$this->add_settings_redirect_notice(
					'cloudways_connection_failed',
					sprintf(
						/* translators: %s: Error message. */
						__( 'Failed to connect to Cloudways: %s', 'mcp-ai-wpoos' ),
						$token_response->get_error_message()
					),
					'error'
				);
				$this->redirect_to_settings_page();
			}

			$access_token = $token_response['access_token'];
			$expires_in   = isset( $token_response['expires_in'] ) ? absint( $token_response['expires_in'] ) : 3600;

			// Verify the token works by fetching account info.
			$account_info = $this->get_account_info( $access_token );

			if ( is_wp_error( $account_info ) ) {
				WP_MCP_AI_Admin_Settings::log(
					'Cloudways account info fetch failed.',
					array( 'error' => $account_info->get_error_message() )
				);
				$this->add_settings_redirect_notice(
					'cloudways_verification_failed',
					__( 'Connected to Cloudways, but could not verify account information.', 'mcp-ai-wpoos' ),
					'warning'
				);
			}

			// Store the access token and expiry time.
			$settings['cloudways_access_token']      = $access_token;
			$settings['cloudways_token_expires_at']  = time() + $expires_in;
			$settings['cloudways_connected']         = true;
			$settings['cloudways_connection_time']   = time();

			if ( ! empty( $account_info['account_name'] ) ) {
				$settings['cloudways_account_name'] = sanitize_text_field( $account_info['account_name'] );
			}

			update_option( 'wp_mcp_ai_settings', $settings );

			$this->add_settings_redirect_notice(
				'cloudways_connected',
				__( 'Successfully connected to Cloudways! Your access token has been saved.', 'mcp-ai-wpoos' ),
				'success'
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Handle disconnecting from Cloudways.
		 */
		public function handle_cloudways_disconnect() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_cloudways_disconnect' );

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Clear OAuth tokens.
			unset( $settings['cloudways_access_token'] );
			unset( $settings['cloudways_token_expires_at'] );
			unset( $settings['cloudways_connected'] );
			unset( $settings['cloudways_connection_time'] );
			unset( $settings['cloudways_account_name'] );

			update_option( 'wp_mcp_ai_settings', $settings );

			$this->add_settings_redirect_notice(
				'cloudways_disconnected',
				__( 'Disconnected from Cloudways. Your credentials remain saved for future connections.', 'mcp-ai-wpoos' ),
				'success'
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Exchange email and API key for an access token.
		 *
		 * @param string $email   Cloudways account email.
		 * @param string $api_key Cloudways API key.
		 * @return array|WP_Error Array with access_token and expires_in on success.
		 */
		protected function exchange_credentials_for_token( $email, $api_key ) {
			/**
			 * Filter the Cloudways OAuth token endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth token endpoint.
			 */
			$token_endpoint = apply_filters( 'wp_mcp_ai_cloudways_oauth_token_endpoint', self::CLOUDWAYS_OAUTH_TOKEN_ENDPOINT );

			$response = wp_remote_post(
				$token_endpoint,
				array(
					'timeout' => 15,
					'headers' => array(
						'Content-Type' => 'application/x-www-form-urlencoded',
					),
					'body'    => array(
						'email'   => $email,
						'api_key' => $api_key,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_request_failed',
					$response->get_error_message()
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				$decoded = json_decode( $body, true );
				$error_message = '';

				if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
					$error_message = $decoded['message'];
				} elseif ( is_array( $decoded ) && isset( $decoded['error_description'] ) ) {
					$error_message = $decoded['error_description'];
				} else {
					$error_message = sprintf(
						/* translators: %d: HTTP status code. */
						__( 'HTTP %d response', 'mcp-ai-wpoos' ),
						$status_code
					);
				}

				return new WP_Error(
					'wp_mcp_ai_cloudways_auth_failed',
					$error_message
				);
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an invalid response.', 'mcp-ai-wpoos' )
				);
			}

			if ( empty( $decoded['access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_token',
					__( 'Cloudways did not return an access token.', 'mcp-ai-wpoos' )
				);
			}

			return $decoded;
		}

		/**
		 * Get account information using the access token.
		 *
		 * @param string $access_token The Cloudways access token.
		 * @return array|WP_Error Account info on success.
		 */
		protected function get_account_info( $access_token ) {
			/**
			 * Filter the Cloudways servers endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint Servers endpoint.
			 */
			$servers_endpoint = apply_filters( 'wp_mcp_ai_cloudways_servers_endpoint', self::CLOUDWAYS_SERVERS_ENDPOINT );

			$response = wp_remote_get(
				$servers_endpoint,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_api_error',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'Cloudways API returned HTTP %d', 'mcp-ai-wpoos' ),
						$status_code
					)
				);
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_account_response',
					__( 'Could not parse account information.', 'mcp-ai-wpoos' )
				);
			}

			// Return account info (server count, etc).
			return array(
				'account_name' => isset( $decoded['servers'] ) ? sprintf(
					/* translators: %d: Number of servers. */
					__( '%d servers', 'mcp-ai-wpoos' ),
					count( $decoded['servers'] )
				) : '',
				'servers'      => isset( $decoded['servers'] ) ? $decoded['servers'] : array(),
			);
		}

		/**
		 * Check if the stored access token is still valid.
		 *
		 * @return bool True if token is valid and not expired.
		 */
		public static function is_token_valid() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['cloudways_access_token'] ) ) {
				return false;
			}

			// Check if token has expired.
			if ( ! empty( $settings['cloudways_token_expires_at'] ) ) {
				$expires_at = absint( $settings['cloudways_token_expires_at'] );
				// Add 60 second buffer before expiry.
				if ( time() >= ( $expires_at - 60 ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Get a valid access token, refreshing if necessary.
		 *
		 * @return string|WP_Error Access token on success.
		 */
		public static function get_access_token() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// If token is valid, return it.
			if ( self::is_token_valid() ) {
				return $settings['cloudways_access_token'];
			}

			// Token expired or missing, need to refresh.
			if ( empty( $settings['cloudways_email'] ) || empty( $settings['cloudways_api_key'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_no_credentials',
					__( 'Cloudways credentials are not configured.', 'mcp-ai-wpoos' )
				);
			}

			$handler        = new self();
			$token_response = $handler->exchange_credentials_for_token(
				$settings['cloudways_email'],
				$settings['cloudways_api_key']
			);

			if ( is_wp_error( $token_response ) ) {
				return $token_response;
			}

			// Update the stored token.
			$settings['cloudways_access_token']     = $token_response['access_token'];
			$settings['cloudways_token_expires_at'] = time() + ( isset( $token_response['expires_in'] ) ? absint( $token_response['expires_in'] ) : 3600 );
			update_option( 'wp_mcp_ai_settings', $settings );

			return $token_response['access_token'];
		}

		/**
		 * Add an admin notice and store it in a transient for redirect.
		 *
		 * @param string $code    Notice code.
		 * @param string $message Notice message.
		 * @param string $type    Notice type (success, error, warning, info).
		 */
		protected function add_settings_redirect_notice( $code, $message, $type = 'error' ) {
			set_transient(
				'wp_mcp_ai_cloudways_oauth_notice',
				array(
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				),
				60
			);
		}

		/**
		 * Redirect back to the settings page.
		 */
		protected function redirect_to_settings_page() {
			$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=cloudways' );

			/**
			 * Filter the Cloudways OAuth redirect URL.
			 *
			 * @since 1.0.0
			 *
			 * @param string $redirect_url The redirect URL.
			 */
			$redirect_url = apply_filters( 'wp_mcp_ai_cloudways_oauth_redirect_url', $redirect_url );

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}
}

<?php
/**
 * OAuth Manager for WP oOS
 *
 * Handles OAuth flows for third-party service integrations (Gmail, Google Analytics, etc.).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_OAuth_Manager' ) ) {
	/**
	 * Manages OAuth authentication flows for external services.
	 */
	class WP_MCP_AI_OAuth_Manager {
		const GMAIL_OAUTH_SCOPE              = 'https://www.googleapis.com/auth/gmail.readonly';
		const GMAIL_OAUTH_AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
		const GMAIL_OAUTH_TOKEN_ENDPOINT     = 'https://oauth2.googleapis.com/token';
		const GMAIL_PROFILE_ENDPOINT         = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

		/**
		 * Handle the start of the Gmail OAuth flow.
		 *
		 * Redirects the user to Google's authorization page.
		 */
		public function handle_gmail_oauth_start() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'wp-mcp-ai' ) );
			}

			check_admin_referer( 'wp_mcp_ai_gmail_oauth_start' );

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['gmail_client_id'] ) || empty( $settings['gmail_client_secret'] ) ) {
				$this->add_settings_redirect_notice(
					'gmail_oauth_missing_client',
					__( 'Enter a Gmail OAuth client ID and secret before connecting the account.', 'wp-mcp-ai' )
				);
				$this->redirect_to_settings_page();
			}

			$state     = wp_generate_uuid4();
			$transient = $this->get_gmail_state_transient_key( $state );

			set_transient(
				$transient,
				array(
					'user_id' => get_current_user_id(),
					'time'    => time(),
				),
				10 * MINUTE_IN_SECONDS
			);

			/**
			 * Filter the Gmail OAuth scope.
			 *
			 * @since 1.0.0
			 *
			 * @param string $scope OAuth scope. Default 'https://www.googleapis.com/auth/gmail.readonly'.
			 */
			$oauth_scope = apply_filters( 'wp_mcp_ai_gmail_oauth_scope', self::GMAIL_OAUTH_SCOPE );

			$params = array(
				'client_id'              => $settings['gmail_client_id'],
				'redirect_uri'           => $this->get_gmail_oauth_redirect_uri(),
				'response_type'          => 'code',
				'scope'                  => $oauth_scope,
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
			);

			if ( ! empty( $settings['gmail_user_email'] ) && 'me' !== strtolower( $settings['gmail_user_email'] ) ) {
				$params['login_hint'] = $settings['gmail_user_email'];
			}

			/**
			 * Filter the Gmail OAuth authorize endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth authorize endpoint. Default 'https://accounts.google.com/o/oauth2/v2/auth'.
			 */
			$authorize_endpoint = apply_filters( 'wp_mcp_ai_gmail_oauth_authorize_endpoint', self::GMAIL_OAUTH_AUTHORIZE_ENDPOINT );
			$authorize_url      = add_query_arg( $params, $authorize_endpoint );

			wp_safe_redirect( $authorize_url );
			exit;
		}

		/**
		 * Handle the OAuth callback from Google and persist the refresh token.
		 */
		public function handle_gmail_oauth_callback() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'wp-mcp-ai' ) );
			}

			// OAuth callback parameters from Google. No nonce verification required as state parameter provides CSRF protection.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

			if ( $error ) {
				$this->add_settings_redirect_notice(
					'gmail_oauth_error',
					sprintf(
						/* translators: %s: Google error message. */
						__( 'Google returned an error during authorisation: %s', 'wp-mcp-ai' ),
						$error
					)
				);
				$this->redirect_to_settings_page();
			}

			$transient_key = $this->get_gmail_state_transient_key( $state );
			$state_data    = get_transient( $transient_key );

			delete_transient( $transient_key );

			if ( empty( $state ) || ! $state_data || (int) $state_data['user_id'] !== get_current_user_id() ) {
				$this->add_settings_redirect_notice(
					'gmail_oauth_state_mismatch',
					__( 'The Google authorisation request could not be verified. Please try again.', 'wp-mcp-ai' )
				);
				$this->redirect_to_settings_page();
			}

			if ( empty( $code ) ) {
				$this->add_settings_redirect_notice(
					'gmail_oauth_missing_code',
					__( 'Google did not return an authorisation code. Please try again.', 'wp-mcp-ai' )
				);
				$this->redirect_to_settings_page();
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['gmail_client_id'] ) || empty( $settings['gmail_client_secret'] ) ) {
				$this->add_settings_redirect_notice(
					'gmail_oauth_missing_client',
					__( 'Enter a Gmail OAuth client ID and secret before connecting the account.', 'wp-mcp-ai' )
				);
				$this->redirect_to_settings_page();
			}

			/**
			 * Filter the Gmail OAuth token endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth token endpoint. Default 'https://oauth2.googleapis.com/token'.
			 */
			$token_endpoint = apply_filters( 'wp_mcp_ai_gmail_oauth_token_endpoint', self::GMAIL_OAUTH_TOKEN_ENDPOINT );

			$response = wp_remote_post(
				$token_endpoint,
				array(
					'timeout' => 15,
					'body'    => array(
						'code'          => $code,
						'client_id'     => $settings['gmail_client_id'],
						'client_secret' => $settings['gmail_client_secret'],
						'redirect_uri'  => $this->get_gmail_oauth_redirect_uri(),
						'grant_type'    => 'authorization_code',
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Admin_Settings::log( 'Gmail OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );
				$this->add_settings_redirect_notice(
					'gmail_oauth_token_request_failed',
					__( 'Google could not exchange the authorisation code. Check the client credentials and try again.', 'wp-mcp-ai' )
				);
				$this->redirect_to_settings_page();
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				WP_MCP_AI_Admin_Settings::log(
					'Gmail OAuth token exchange returned an unexpected status.',
					array(
						'status' => $status_code,
						'body'   => $body,
					)
				);
				$this->add_settings_redirect_notice(
					'gmail_oauth_token_request_error',
					__( 'Google rejected the authorisation code. Review the OAuth consent configuration and try again.', 'wp-mcp-ai' )
				);
				$this->redirect_to_settings_page();
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) ) {
				WP_MCP_AI_Admin_Settings::log( 'Gmail OAuth token response was not valid JSON.', array( 'body' => $body ) );
				$this->add_settings_redirect_notice(
					'gmail_oauth_token_invalid_json',
					__( 'Google returned an unexpected response while exchanging the authorisation code.', 'wp-mcp-ai' )
				);
				$this->redirect_to_settings_page();
			}

			$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
			$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';
			$used_existing = false;

			if ( '' === $refresh_token ) {
				$existing_refresh = isset( $settings['gmail_refresh_token'] ) ? $settings['gmail_refresh_token'] : '';

				if ( '' === $existing_refresh ) {
					WP_MCP_AI_Admin_Settings::log( 'Gmail OAuth callback omitted a refresh token.', array( 'response' => $decoded ) );
					$this->add_settings_redirect_notice(
						'gmail_oauth_missing_refresh_token',
						__( 'Google did not return a refresh token. Remove any previous grants for this client and try again.', 'wp-mcp-ai' )
					);
					$this->redirect_to_settings_page();
				}

				$refresh_token = $existing_refresh;
				$used_existing = true;
			}

			$email_address = '';

			if ( $access_token ) {
				/**
				 * Filter the Gmail profile endpoint.
				 *
				 * @since 1.0.0
				 *
				 * @param string $endpoint Gmail profile endpoint. Default 'https://gmail.googleapis.com/gmail/v1/users/me/profile'.
				 */
				$profile_endpoint = apply_filters( 'wp_mcp_ai_gmail_profile_endpoint', self::GMAIL_PROFILE_ENDPOINT );

				$profile_response = wp_remote_get(
					$profile_endpoint,
					array(
						'timeout' => 15,
						'headers' => array(
							'Accept'        => 'application/json',
							'Authorization' => 'Bearer ' . $access_token,
						),
					)
				);

				if ( is_wp_error( $profile_response ) ) {
					WP_MCP_AI_Admin_Settings::log( 'Failed to load Gmail profile after OAuth.', array( 'error' => $profile_response->get_error_message() ) );
				} else {
					$profile_status = wp_remote_retrieve_response_code( $profile_response );
					$profile_body   = wp_remote_retrieve_body( $profile_response );

					if ( 200 === (int) $profile_status ) {
						$profile_data = json_decode( $profile_body, true );

						if ( is_array( $profile_data ) && ! empty( $profile_data['emailAddress'] ) ) {
							$email_address = sanitize_email( $profile_data['emailAddress'] );
						}
					} else {
						WP_MCP_AI_Admin_Settings::log(
							'Gmail profile lookup returned an unexpected status.',
							array(
								'status' => $profile_status,
								'body'   => $profile_body,
							)
						);
					}
				}
			}

			$updated_settings                        = $settings;
			$updated_settings['gmail_refresh_token'] = $refresh_token;

			if ( $email_address ) {
				$updated_settings['gmail_user_email'] = $email_address;
			}

			// Manually sanitize settings before saving.
			// Use the base class to avoid circular dependency.
			// WP_MCP_AI_Admin_Settings instantiates WP_MCP_AI_OAuth_Manager in its constructor,
			// so we cannot instantiate it here. Use the base class instead.
			$settings_base = new WP_MCP_AI_Admin_Settings_Base();
			$sanitized     = $settings_base->sanitize_settings( $updated_settings );
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized );

			if ( $used_existing ) {
				$this->add_settings_redirect_notice(
					'gmail_oauth_success_existing_refresh',
					__( 'Google reconnected successfully. The previous refresh token is still valid, so it has been kept.', 'wp-mcp-ai' ),
					'updated'
				);
			} else {
				$notice_message = __( 'Gmail authorisation complete. A new refresh token has been stored.', 'wp-mcp-ai' );

				if ( $email_address ) {
					$notice_message = sprintf(
						/* translators: %s: Gmail email address. */
						__( 'Gmail authorisation complete for %s.', 'wp-mcp-ai' ),
						$email_address
					);
				}

				$this->add_settings_redirect_notice( 'gmail_oauth_success', $notice_message, 'updated' );
			}

			$this->redirect_to_settings_page();
		}

		/**
		 * Allow the Google OAuth authorize endpoint host when using wp_safe_redirect().
		 *
		 * @param string[] $allowed_hosts Existing list of allowed hosts.
		 * @param string   $redirect      Requested redirect destination.
		 *
		 * @return string[]
		 */
		public function allow_gmail_oauth_redirect_host( $allowed_hosts, $redirect = '' ) {
			/**
			 * Filter the Gmail OAuth authorize endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth authorize endpoint. Default 'https://accounts.google.com/o/oauth2/v2/auth'.
			 */
			$authorize_endpoint = apply_filters( 'wp_mcp_ai_gmail_oauth_authorize_endpoint', self::GMAIL_OAUTH_AUTHORIZE_ENDPOINT );
			$google_host        = wp_parse_url( $authorize_endpoint, PHP_URL_HOST );

			if ( $google_host ) {
				$allowed_hosts[] = $google_host;
			}

			return array_values( array_unique( $allowed_hosts ) );
		}

		/**
		 * Build the transient key used to persist OAuth state.
		 *
		 * @param string $state OAuth state string.
		 * @return string
		 */
		private function get_gmail_state_transient_key( $state ) {
			return 'wp_mcp_ai_gmail_state_' . md5( (string) $state );
		}

		/**
		 * Return the OAuth redirect URI registered in the Google Cloud console.
		 *
		 * @return string
		 */
		private function get_gmail_oauth_redirect_uri() {
			return admin_url( 'admin-post.php?action=wp_mcp_ai_gmail_oauth_callback' );
		}

		/**
		 * Retrieve the settings page URL.
		 *
		 * @return string
		 */
		private function get_settings_page_url() {
			// Redirect to the Tools tab > External Tools subtab (where Gmail OAuth fields are located).
			return admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=external_tools' );
		}

		/**
		 * Redirect the current request back to the settings page and exit.
		 */
		private function redirect_to_settings_page() {
			wp_safe_redirect( $this->get_settings_page_url() );
			exit;
		}

		/**
		 * Store a notice that will be displayed on the settings page after redirecting.
		 *
		 * @param string $code    Unique notice code.
		 * @param string $message Notice message.
		 * @param string $type    Notice type.
		 */
		private function add_settings_redirect_notice( $code, $message, $type = 'error' ) {
			add_settings_error( WP_MCP_AI_Admin_Settings::OPTION_NAME, $code, $message, $type );

			set_transient(
				'settings_errors',
				array(
					array(
						'setting' => WP_MCP_AI_Admin_Settings::OPTION_NAME,
						'code'    => $code,
						'message' => $message,
						'type'    => $type,
					),
				),
				30
			);
		}
	}
}

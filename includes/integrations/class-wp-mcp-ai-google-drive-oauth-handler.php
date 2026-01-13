<?php
/**
 * Google Drive OAuth Handler for WP MCP AI
 *
 * Handles OAuth flows for Google Drive integration with folder scoping support.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Google_Drive_OAuth_Handler' ) ) {
	/**
	 * Manages Google Drive OAuth authentication flows.
	 */
	class WP_MCP_AI_Google_Drive_OAuth_Handler {
		const GOOGLE_DRIVE_OAUTH_AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
		const GOOGLE_DRIVE_OAUTH_TOKEN_ENDPOINT     = 'https://oauth2.googleapis.com/token';
		const GOOGLE_DRIVE_API_BASE                 = 'https://www.googleapis.com/drive/v3';
		const GOOGLE_DRIVE_OAUTH_SCOPES             = 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.metadata.readonly';

		/**
		 * Handle the start of the Google Drive OAuth flow.
		 *
		 * Redirects the user to Google's authorization page.
		 */
		public function handle_google_drive_oauth_start() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_google_drive_oauth_start' );

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['google_drive_client_id'] ) || empty( $settings['google_drive_client_secret'] ) ) {
				$this->add_settings_redirect_notice(
					'google_drive_oauth_missing_client',
					__( 'Enter a Google Drive OAuth client ID and secret before connecting the account.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			$state     = wp_generate_uuid4();
			$transient = $this->get_google_drive_state_transient_key( $state );

			set_transient(
				$transient,
				array(
					'user_id' => get_current_user_id(),
					'time'    => time(),
				),
				10 * MINUTE_IN_SECONDS
			);

			/**
			 * Filter the Google Drive OAuth scope.
			 *
			 * @since 1.0.0
			 *
			 * @param string $scope OAuth scope. Default includes drive.readonly and drive.metadata.readonly.
			 */
			$oauth_scope = apply_filters( 'wp_mcp_ai_google_drive_oauth_scope', self::GOOGLE_DRIVE_OAUTH_SCOPES );

			$params = array(
				'client_id'              => $settings['google_drive_client_id'],
				'redirect_uri'           => $this->get_google_drive_oauth_redirect_uri(),
				'response_type'          => 'code',
				'scope'                  => $oauth_scope,
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
			);

			/**
			 * Filter the Google Drive OAuth authorize endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth authorize endpoint.
			 */
			$authorize_endpoint = apply_filters( 'wp_mcp_ai_google_drive_oauth_authorize_endpoint', self::GOOGLE_DRIVE_OAUTH_AUTHORIZE_ENDPOINT );
			$authorize_url      = add_query_arg( $params, $authorize_endpoint );

			wp_safe_redirect( $authorize_url );
			exit;
		}

		/**
		 * Handle the OAuth callback from Google and persist the refresh token.
		 */
		public function handle_google_drive_oauth_callback() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
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
					'google_drive_oauth_error',
					sprintf(
						/* translators: %s: Google error message. */
						__( 'Google returned an error during authorisation: %s', 'mcp-ai-wpoos' ),
						$error
					)
				);
				$this->redirect_to_settings_page();
			}

			$transient_key = $this->get_google_drive_state_transient_key( $state );
			$state_data    = get_transient( $transient_key );

			delete_transient( $transient_key );

			if ( empty( $state ) || ! $state_data || (int) $state_data['user_id'] !== get_current_user_id() ) {
				$this->add_settings_redirect_notice(
					'google_drive_oauth_state_mismatch',
					__( 'The Google authorisation request could not be verified. Please try again.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			if ( empty( $code ) ) {
				$this->add_settings_redirect_notice(
					'google_drive_oauth_missing_code',
					__( 'Google did not return an authorisation code. Please try again.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['google_drive_client_id'] ) || empty( $settings['google_drive_client_secret'] ) ) {
				$this->add_settings_redirect_notice(
					'google_drive_oauth_missing_client',
					__( 'Enter a Google Drive OAuth client ID and secret before connecting the account.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			/**
			 * Filter the Google Drive OAuth token endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth token endpoint.
			 */
			$token_endpoint = apply_filters( 'wp_mcp_ai_google_drive_oauth_token_endpoint', self::GOOGLE_DRIVE_OAUTH_TOKEN_ENDPOINT );

			$response = wp_remote_post(
				$token_endpoint,
				array(
					'timeout' => 15,
					'body'    => array(
						'code'          => $code,
						'client_id'     => $settings['google_drive_client_id'],
						'client_secret' => $settings['google_drive_client_secret'],
						'redirect_uri'  => $this->get_google_drive_oauth_redirect_uri(),
						'grant_type'    => 'authorization_code',
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Admin_Settings::log( 'Google Drive OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );
				$this->add_settings_redirect_notice(
					'google_drive_oauth_token_request_failed',
					__( 'Google could not exchange the authorisation code. Check the client credentials and try again.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				WP_MCP_AI_Admin_Settings::log(
					'Google Drive OAuth token exchange returned an unexpected status.',
					array(
						'status' => $status_code,
						'body'   => $body,
					)
				);
				$this->add_settings_redirect_notice(
					'google_drive_oauth_token_request_error',
					__( 'Google rejected the authorisation code. Review the OAuth consent configuration and try again.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) ) {
				WP_MCP_AI_Admin_Settings::log( 'Google Drive OAuth token response was not valid JSON.', array( 'body' => $body ) );
				$this->add_settings_redirect_notice(
					'google_drive_oauth_token_invalid_json',
					__( 'Google returned an unexpected response while exchanging the authorisation code.', 'mcp-ai-wpoos' )
				);
				$this->redirect_to_settings_page();
			}

			$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
			$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';
			$used_existing = false;

			if ( '' === $refresh_token ) {
				$existing_refresh = isset( $settings['google_drive_refresh_token'] ) ? $settings['google_drive_refresh_token'] : '';

				if ( '' === $existing_refresh ) {
					WP_MCP_AI_Admin_Settings::log( 'Google Drive OAuth callback omitted a refresh token.', array( 'response' => $decoded ) );
					$this->add_settings_redirect_notice(
						'google_drive_oauth_missing_refresh_token',
						__( 'Google did not return a refresh token. Remove any previous grants for this client and try again.', 'mcp-ai-wpoos' )
					);
					$this->redirect_to_settings_page();
				}

				$refresh_token = $existing_refresh;
				$used_existing = true;
			}

			$drive_email = '';

			if ( $access_token ) {
				// Fetch the authenticated user's information from Google Drive API.
				$user_response = wp_remote_get(
					self::GOOGLE_DRIVE_API_BASE . '/about?fields=user',
					array(
						'timeout' => 15,
						'headers' => array(
							'Accept'        => 'application/json',
							'Authorization' => 'Bearer ' . $access_token,
						),
					)
				);

				if ( ! is_wp_error( $user_response ) ) {
					$user_status = wp_remote_retrieve_response_code( $user_response );
					$user_body   = wp_remote_retrieve_body( $user_response );

					if ( 200 === (int) $user_status ) {
						$user_data = json_decode( $user_body, true );

						if ( is_array( $user_data ) && ! empty( $user_data['user']['emailAddress'] ) ) {
							$drive_email = sanitize_email( $user_data['user']['emailAddress'] );
						}
					} else {
						WP_MCP_AI_Admin_Settings::log(
							'Google Drive user lookup returned an unexpected status.',
							array(
								'status' => $user_status,
								'body'   => $user_body,
							)
						);
					}
				}
			}

			$updated_settings                                 = $settings;
			$updated_settings['google_drive_refresh_token']   = $refresh_token;

			if ( $drive_email ) {
				$updated_settings['google_drive_user_email'] = $drive_email;
			}

			// Manually sanitize settings before saving.
			// Use the base class to avoid circular dependency.
			$settings_base = new WP_MCP_AI_Admin_Settings_Base();
			$sanitized     = $settings_base->sanitize_settings( $updated_settings );
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $sanitized );

			if ( $used_existing ) {
				$this->add_settings_redirect_notice(
					'google_drive_oauth_success_existing_refresh',
					__( 'Google Drive reconnected successfully. The previous refresh token is still valid, so it has been kept.', 'mcp-ai-wpoos' ),
					'updated'
				);
			} else {
				$notice_message = __( 'Google Drive authorisation complete. A new refresh token has been stored.', 'mcp-ai-wpoos' );

				if ( $drive_email ) {
					$notice_message = sprintf(
						/* translators: %s: Google Drive email address. */
						__( 'Google Drive authorisation complete for %s.', 'mcp-ai-wpoos' ),
						$drive_email
					);
				}

				$this->add_settings_redirect_notice( 'google_drive_oauth_success', $notice_message, 'updated' );
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
		public function allow_google_drive_oauth_redirect_host( $allowed_hosts, $redirect = '' ) {
			/**
			 * Filter the Google Drive OAuth authorize endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth authorize endpoint.
			 */
			$authorize_endpoint = apply_filters( 'wp_mcp_ai_google_drive_oauth_authorize_endpoint', self::GOOGLE_DRIVE_OAUTH_AUTHORIZE_ENDPOINT );
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
		private function get_google_drive_state_transient_key( $state ) {
			return 'wp_mcp_ai_google_drive_state_' . md5( (string) $state );
		}

		/**
		 * Return the OAuth redirect URI registered in the Google Cloud console.
		 *
		 * @return string
		 */
		private function get_google_drive_oauth_redirect_uri() {
			return admin_url( 'admin-post.php?action=wp_mcp_ai_google_drive_oauth_callback' );
		}

		/**
		 * Retrieve the settings page URL.
		 *
		 * @return string
		 */
		private function get_settings_page_url() {
			// Redirect to the Tools tab > Connections subtab (where Google Drive OAuth fields are located).
			return admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections' );
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

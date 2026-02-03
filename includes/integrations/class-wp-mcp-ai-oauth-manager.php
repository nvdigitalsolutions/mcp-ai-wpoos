<?php
/**
 * OAuth Manager for NV oOS
 *
 * Handles OAuth flows for third-party service integrations.
 * Base version supports 1 Gmail connection. Pro addon extends with multiple connections.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_OAuth_Manager' ) ) {
	/**
	 * Manages OAuth authentication flows for external services.
	 *
	 * Base version supports 1 Gmail connection via settings.
	 * Pro addon extends this with multiple connections via Remote Sites.
	 */
	class WP_MCP_AI_OAuth_Manager {
		/**
		 * Constructor - register OAuth hooks.
		 */
		public function __construct() {
			// Register OAuth callback handler.
			add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
		}

		/**
		 * Handle OAuth callback from query parameter.
		 */
		public function handle_oauth_callback() {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth uses state parameter for CSRF protection.
			if ( ! isset( $_GET['wp_mcp_ai_oauth'] ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth uses state parameter for CSRF protection.
			$handler = sanitize_key( wp_unslash( $_GET['wp_mcp_ai_oauth'] ) );

			if ( 'gmail_callback' === $handler ) {
				$this->handle_gmail_oauth_callback();
			} elseif ( 'google_drive_callback' === $handler ) {
				$this->handle_google_drive_oauth_callback();
			} elseif ( 'yahoo_callback' === $handler ) {
				$this->handle_yahoo_oauth_callback();
			}
		}

		/**
		 * Handle Gmail OAuth start request.
		 *
		 * Implements OAuth flow for base version's single Gmail connection.
		 * Uses The PHP League OAuth2 Client library for standardized OAuth flow.
		 */
		public function handle_gmail_oauth_start() {
			// Check nonce for security.
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wp_mcp_ai_gmail_oauth_start' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ) );
			}

			// Check user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
			}

			// Get settings.
			$settings      = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id     = isset( $settings['gmail_client_id'] ) ? trim( $settings['gmail_client_id'] ) : '';
			$client_secret = isset( $settings['gmail_client_secret'] ) ? trim( $settings['gmail_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'        => 'wp-mcp-ai-dashboard',
							'tab'         => 'tools',
							'subtab'      => 'connections',
							'connection'  => 'gmail',
							'gmail_error' => rawurlencode( __( 'Please save your Gmail Client ID and Client Secret before connecting.', 'mcp-ai-wpoos' ) ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			// Generate OAuth state for CSRF protection.
			$state     = wp_generate_uuid4();
			$transient = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );

			set_transient(
				$transient,
				array(
					'user_id' => get_current_user_id(),
					'time'    => time(),
				),
				10 * MINUTE_IN_SECONDS
			);

			// Build redirect URI - ensure it's not double-encoded and uses consistent format.
			// Build base admin.php URL first.
			$base_url = admin_url( 'admin.php' );

			// Add the OAuth callback parameter using add_query_arg for proper URL encoding.
			$redirect_uri = add_query_arg(
				array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
				$base_url
			);

			// Use The PHP League OAuth2 Client if available for standardized OAuth URL generation.
			if ( class_exists( '\League\OAuth2\Client\Provider\GenericProvider' ) ) {
				try {
					$provider = new \League\OAuth2\Client\Provider\GenericProvider(
						array(
							'clientId'                => $client_id,
							'clientSecret'            => $client_secret,
							'redirectUri'             => $redirect_uri,
							'urlAuthorize'            => 'https://accounts.google.com/o/oauth2/v2/auth',
							'urlAccessToken'          => 'https://oauth2.googleapis.com/token',
							'urlResourceOwnerDetails' => 'https://www.googleapis.com/oauth2/v1/userinfo',
							'scopes'                  => 'https://www.googleapis.com/auth/gmail.readonly',
						)
					);

					// Get the authorization URL from League OAuth2 Client.
					$authorize_url = $provider->getAuthorizationUrl(
						array(
							'state'                  => $state,
							'access_type'            => 'offline',
							'include_granted_scopes' => 'true',
							'prompt'                 => 'consent',
						)
					);
				} catch ( Exception $e ) {
					// Fall back to manual URL construction if League OAuth2 Client fails.
					$authorize_url = $this->build_google_oauth_url( $client_id, $redirect_uri, $state, 'https://www.googleapis.com/auth/gmail.readonly' );
				}
			} else {
				// Fall back to manual URL construction if League OAuth2 Client is not available.
				$authorize_url = $this->build_google_oauth_url( $client_id, $redirect_uri, $state, 'https://www.googleapis.com/auth/gmail.readonly' );
			}

			// Add Google OAuth domain to allowed redirect hosts.
			add_filter( 'allowed_redirect_hosts', array( $this, 'allow_gmail_oauth_redirect_host' ) );

			wp_safe_redirect( $authorize_url );
			exit;
		}

		/**
		 * Handle Gmail OAuth callback.
		 */
		protected function handle_gmail_oauth_callback() {
			// OAuth callback parameters from Google. No nonce verification required as state parameter provides CSRF protection.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

			$redirect_base = add_query_arg(
				array(
					'page'       => 'wp-mcp-ai-dashboard',
					'tab'        => 'tools',
					'subtab'     => 'connections',
					'connection' => 'gmail',
				),
				admin_url( 'admin.php' )
			);

			if ( $error ) {
				wp_safe_redirect(
					add_query_arg(
						'gmail_error',
						rawurlencode(
							sprintf(
								/* translators: %s: Error message from Google OAuth */
								__( 'Google OAuth error: %s', 'mcp-ai-wpoos' ),
								$error
							)
						),
						$redirect_base
					)
				);
				exit;
			}

			$transient_key = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );
			$state_data    = get_transient( $transient_key );

			delete_transient( $transient_key );

			if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			if ( empty( $code ) ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'No authorization code received from Google.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Get settings.
			$settings      = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id     = isset( $settings['gmail_client_id'] ) ? trim( $settings['gmail_client_id'] ) : '';
			$client_secret = isset( $settings['gmail_client_secret'] ) ? trim( $settings['gmail_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'Gmail credentials not found in settings.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Build redirect_uri - must match exactly what was sent in the authorization request.
			$base_url     = admin_url( 'admin.php' );
			$redirect_uri = add_query_arg(
				array( 'wp_mcp_ai_oauth' => 'gmail_callback' ),
				$base_url
			);

			// Exchange authorization code for tokens using The PHP League OAuth2 Client if available.
			$refresh_token = '';
			$access_token  = '';

			if ( class_exists( '\League\OAuth2\Client\Provider\GenericProvider' ) ) {
				try {
					$provider = new \League\OAuth2\Client\Provider\GenericProvider(
						array(
							'clientId'                => $client_id,
							'clientSecret'            => $client_secret,
							'redirectUri'             => $redirect_uri,
							'urlAuthorize'            => 'https://accounts.google.com/o/oauth2/v2/auth',
							'urlAccessToken'          => 'https://oauth2.googleapis.com/token',
							'urlResourceOwnerDetails' => 'https://www.googleapis.com/oauth2/v1/userinfo',
							'scopes'                  => 'https://www.googleapis.com/auth/gmail.readonly',
						)
					);

					// Exchange code for access token.
					$access_token_obj = $provider->getAccessToken(
						'authorization_code',
						array( 'code' => $code )
					);

					$refresh_token = $access_token_obj->getRefreshToken();
					$access_token  = $access_token_obj->getToken();

				} catch ( Exception $e ) {
					// Fall back to manual token exchange if League OAuth2 Client fails.
					$token_result = $this->exchange_google_auth_code( $code, $client_id, $client_secret, $redirect_uri );
					if ( is_wp_error( $token_result ) ) {
						wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( $token_result->get_error_message() ), $redirect_base ) );
						exit;
					}
					$refresh_token = $token_result['refresh_token'];
					$access_token  = $token_result['access_token'];
				}
			} else {
				// Fall back to manual token exchange if League OAuth2 Client is not available.
				$token_result = $this->exchange_google_auth_code( $code, $client_id, $client_secret, $redirect_uri );
				if ( is_wp_error( $token_result ) ) {
					wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( $token_result->get_error_message() ), $redirect_base ) );
					exit;
				}
				$refresh_token = $token_result['refresh_token'];
				$access_token  = $token_result['access_token'];
			}

			// If no refresh token, check if we can reuse existing one.
			if ( '' === $refresh_token && ! empty( $settings['gmail_refresh_token'] ) ) {
				$refresh_token = $settings['gmail_refresh_token'];
			}

			if ( '' === $refresh_token ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'No refresh token received. Please revoke existing access in your Google account and try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Get email address from profile if access token is available.
			$email_address = '';
			if ( $access_token ) {
				$profile_response = wp_remote_get(
					'https://gmail.googleapis.com/gmail/v1/users/me/profile',
					array(
						'timeout' => 15,
						'headers' => array(
							'Authorization' => 'Bearer ' . $access_token,
							'Accept'        => 'application/json',
						),
					)
				);

				if ( ! is_wp_error( $profile_response ) && 200 === wp_remote_retrieve_response_code( $profile_response ) ) {
					$profile_body = json_decode( wp_remote_retrieve_body( $profile_response ), true );
					if ( isset( $profile_body['emailAddress'] ) ) {
						$email_address = sanitize_email( $profile_body['emailAddress'] );
					}
				}
			}

			// Update settings with refresh token and email.
			$settings['gmail_refresh_token'] = $refresh_token;
			if ( $email_address ) {
				$settings['gmail_user_email'] = $email_address;
			}

			update_option( 'wp_mcp_ai_settings', $settings );

			$success_message = __( 'Gmail connected successfully!', 'mcp-ai-wpoos' );
			if ( $email_address ) {
				$success_message = sprintf(
					/* translators: %s: email address */
					__( 'Gmail connected successfully for %s!', 'mcp-ai-wpoos' ),
					$email_address
				);
			}

			wp_safe_redirect( add_query_arg( 'gmail_success', rawurlencode( $success_message ), $redirect_base ) );
			exit;
		}

		/**
		 * Allow the Google OAuth authorize endpoint host when using wp_safe_redirect().
		 * Preserved for backward compatibility.
		 *
		 * @param string[] $allowed_hosts Existing list of allowed hosts.
		 *
		 * @return string[]
		 */
		public function allow_gmail_oauth_redirect_host( $allowed_hosts ) {
			$allowed_hosts[] = 'accounts.google.com';
			return array_values( array_unique( $allowed_hosts ) );
		}

		/**
		 * Build Google OAuth authorization URL manually.
		 *
		 * Used as a fallback when Google API Client is not available.
		 *
		 * @param string $client_id     OAuth client ID.
		 * @param string $redirect_uri  Redirect URI.
		 * @param string $state         OAuth state parameter.
		 * @param string $scope         OAuth scopes (space-separated).
		 *
		 * @return string Authorization URL.
		 */
		protected function build_google_oauth_url( $client_id, $redirect_uri, $state, $scope ) {
			$params = array(
				'client_id'              => $client_id,
				'redirect_uri'           => $redirect_uri,
				'response_type'          => 'code',
				'scope'                  => $scope,
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
			);

			return add_query_arg( $params, 'https://accounts.google.com/o/oauth2/v2/auth' );
		}

		/**
		 * Exchange Google authorization code for tokens manually.
		 *
		 * Used as a fallback when Google API Client is not available or fails.
		 *
		 * @param string $code          Authorization code from Google.
		 * @param string $client_id     OAuth client ID.
		 * @param string $client_secret OAuth client secret.
		 * @param string $redirect_uri  Redirect URI.
		 *
		 * @return array|WP_Error Token data or error.
		 */
		protected function exchange_google_auth_code( $code, $client_id, $client_secret, $redirect_uri ) {
			$response = wp_remote_post(
				'https://oauth2.googleapis.com/token',
				array(
					'timeout' => 15,
					'body'    => array(
						'code'          => $code,
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'redirect_uri'  => $redirect_uri,
						'grant_type'    => 'authorization_code',
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'token_exchange_failed', __( 'Failed to exchange authorization code. Please try again.', 'mcp-ai-wpoos' ) );
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				return new WP_Error( 'token_exchange_rejected', __( 'Google rejected the authorization. Please check your OAuth configuration.', 'mcp-ai-wpoos' ) );
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) ) {
				return new WP_Error( 'invalid_token_response', __( 'Invalid response from Google.', 'mcp-ai-wpoos' ) );
			}

			return array(
				'refresh_token' => isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '',
				'access_token'  => isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '',
			);
		}

		/**
		 * Handle Google Drive OAuth start request.
		 *
		 * Implements OAuth flow for base version's single Google Drive connection.
		 * Uses The PHP League OAuth2 Client library for standardized OAuth flow.
		 */
		public function handle_google_drive_oauth_start() {
			// Check nonce for security.
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wp_mcp_ai_google_drive_oauth_start' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ) );
			}

			// Check user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
			}

			// Get settings.
			$settings      = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id     = isset( $settings['google_drive_client_id'] ) ? trim( $settings['google_drive_client_id'] ) : '';
			$client_secret = isset( $settings['google_drive_client_secret'] ) ? trim( $settings['google_drive_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'        => 'wp-mcp-ai-dashboard',
							'tab'         => 'tools',
							'subtab'      => 'connections',
							'connection'  => 'google_drive',
							'drive_error' => rawurlencode( __( 'Please save your Google Drive Client ID and Client Secret before connecting.', 'mcp-ai-wpoos' ) ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			// Generate OAuth state for CSRF protection.
			$state     = wp_generate_uuid4();
			$transient = 'wp_mcp_ai_google_drive_oauth_state_' . md5( $state );

			set_transient(
				$transient,
				array(
					'user_id' => get_current_user_id(),
					'time'    => time(),
				),
				10 * MINUTE_IN_SECONDS
			);

			// Build redirect URI - ensure it's not double-encoded and uses consistent format.
			// Build base admin.php URL first.
			$base_url = admin_url( 'admin.php' );

			// Add the OAuth callback parameter using add_query_arg for proper URL encoding.
			$redirect_uri = add_query_arg(
				array( 'wp_mcp_ai_oauth' => 'google_drive_callback' ),
				$base_url
			);

			// Google Drive scopes.
			$scopes = 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.metadata.readonly';

			// Use The PHP League OAuth2 Client if available for standardized OAuth URL generation.
			if ( class_exists( '\League\OAuth2\Client\Provider\GenericProvider' ) ) {
				try {
					$provider = new \League\OAuth2\Client\Provider\GenericProvider(
						array(
							'clientId'                => $client_id,
							'clientSecret'            => $client_secret,
							'redirectUri'             => $redirect_uri,
							'urlAuthorize'            => 'https://accounts.google.com/o/oauth2/v2/auth',
							'urlAccessToken'          => 'https://oauth2.googleapis.com/token',
							'urlResourceOwnerDetails' => 'https://www.googleapis.com/oauth2/v1/userinfo',
							'scopes'                  => $scopes,
						)
					);

					// Get the authorization URL from League OAuth2 Client.
					$authorize_url = $provider->getAuthorizationUrl(
						array(
							'state'                  => $state,
							'access_type'            => 'offline',
							'include_granted_scopes' => 'true',
							'prompt'                 => 'consent',
						)
					);
				} catch ( Exception $e ) {
					// Fall back to manual URL construction if League OAuth2 Client fails.
					$authorize_url = $this->build_google_oauth_url( $client_id, $redirect_uri, $state, $scopes );
				}
			} else {
				// Fall back to manual URL construction if League OAuth2 Client is not available.
				$authorize_url = $this->build_google_oauth_url( $client_id, $redirect_uri, $state, $scopes );
			}

			// Add Google OAuth domain to allowed redirect hosts.
			// Note: Reusing Gmail's filter as both use the same Google OAuth domain (accounts.google.com).
			add_filter( 'allowed_redirect_hosts', array( $this, 'allow_gmail_oauth_redirect_host' ) );

			wp_safe_redirect( $authorize_url );
			exit;
		}

		/**
		 * Handle Google Drive OAuth callback.
		 */
		protected function handle_google_drive_oauth_callback() {
			// OAuth callback parameters from Google. No nonce verification required as state parameter provides CSRF protection.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

			$redirect_base = add_query_arg(
				array(
					'page'       => 'wp-mcp-ai-dashboard',
					'tab'        => 'tools',
					'subtab'     => 'connections',
					'connection' => 'google_drive',
				),
				admin_url( 'admin.php' )
			);

			if ( $error ) {
				wp_safe_redirect(
					add_query_arg(
						'drive_error',
						rawurlencode(
							sprintf(
								/* translators: %s: Error message from Google OAuth */
								__( 'Google OAuth error: %s', 'mcp-ai-wpoos' ),
								$error
							)
						),
						$redirect_base
					)
				);
				exit;
			}

			$transient_key = 'wp_mcp_ai_google_drive_oauth_state_' . md5( $state );
			$state_data    = get_transient( $transient_key );

			delete_transient( $transient_key );

			if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			if ( empty( $code ) ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'No authorization code received from Google.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Get settings.
			$settings      = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id     = isset( $settings['google_drive_client_id'] ) ? trim( $settings['google_drive_client_id'] ) : '';
			$client_secret = isset( $settings['google_drive_client_secret'] ) ? trim( $settings['google_drive_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'Google Drive credentials not found in settings.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Build redirect_uri - must match exactly what was sent in the authorization request.
			$base_url     = admin_url( 'admin.php' );
			$redirect_uri = add_query_arg(
				array( 'wp_mcp_ai_oauth' => 'google_drive_callback' ),
				$base_url
			);

			// Exchange authorization code for tokens using The PHP League OAuth2 Client if available.
			$refresh_token = '';
			$access_token  = '';

			if ( class_exists( '\League\OAuth2\Client\Provider\GenericProvider' ) ) {
				try {
					$provider = new \League\OAuth2\Client\Provider\GenericProvider(
						array(
							'clientId'                => $client_id,
							'clientSecret'            => $client_secret,
							'redirectUri'             => $redirect_uri,
							'urlAuthorize'            => 'https://accounts.google.com/o/oauth2/v2/auth',
							'urlAccessToken'          => 'https://oauth2.googleapis.com/token',
							'urlResourceOwnerDetails' => 'https://www.googleapis.com/oauth2/v1/userinfo',
							'scopes'                  => 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.metadata.readonly',
						)
					);

					// Exchange code for access token.
					$access_token_obj = $provider->getAccessToken(
						'authorization_code',
						array( 'code' => $code )
					);

					$refresh_token = $access_token_obj->getRefreshToken();
					$access_token  = $access_token_obj->getToken();

				} catch ( Exception $e ) {
					// Fall back to manual token exchange if League OAuth2 Client fails.
					$token_result = $this->exchange_google_auth_code( $code, $client_id, $client_secret, $redirect_uri );
					if ( is_wp_error( $token_result ) ) {
						wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( $token_result->get_error_message() ), $redirect_base ) );
						exit;
					}
					$refresh_token = $token_result['refresh_token'];
					$access_token  = $token_result['access_token'];
				}
			} else {
				// Fall back to manual token exchange if Google Client is not available.
				$token_result = $this->exchange_google_auth_code( $code, $client_id, $client_secret, $redirect_uri );
				if ( is_wp_error( $token_result ) ) {
					wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( $token_result->get_error_message() ), $redirect_base ) );
					exit;
				}
				$refresh_token = $token_result['refresh_token'];
				$access_token  = $token_result['access_token'];
			}

			// If no refresh token, check if we can reuse existing one.
			if ( '' === $refresh_token && ! empty( $settings['google_drive_refresh_token'] ) ) {
				$refresh_token = $settings['google_drive_refresh_token'];
			}

			if ( '' === $refresh_token ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'No refresh token received. Please revoke existing access in your Google account and try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Get email address from userinfo if access token is available.
			$email_address = '';
			if ( $access_token ) {
				$profile_response = wp_remote_get(
					'https://www.googleapis.com/oauth2/v2/userinfo',
					array(
						'timeout' => 15,
						'headers' => array(
							'Authorization' => 'Bearer ' . $access_token,
							'Accept'        => 'application/json',
						),
					)
				);

				if ( ! is_wp_error( $profile_response ) && 200 === wp_remote_retrieve_response_code( $profile_response ) ) {
					$profile_body = json_decode( wp_remote_retrieve_body( $profile_response ), true );
					if ( isset( $profile_body['email'] ) ) {
						$email_address = sanitize_email( $profile_body['email'] );
					}
				}
			}

			// Update settings with refresh token and email.
			$settings['google_drive_refresh_token'] = $refresh_token;
			if ( $email_address ) {
				$settings['google_drive_user_email'] = $email_address;
			}

			update_option( 'wp_mcp_ai_settings', $settings );

			$success_message = __( 'Google Drive connected successfully!', 'mcp-ai-wpoos' );
			if ( $email_address ) {
				$success_message = sprintf(
					/* translators: %s: email address */
					__( 'Google Drive connected successfully for %s!', 'mcp-ai-wpoos' ),
					$email_address
				);
			}

			wp_safe_redirect( add_query_arg( 'drive_success', rawurlencode( $success_message ), $redirect_base ) );
			exit;
		}

		/**
		 * Handle Yahoo OAuth start request.
		 *
		 * @deprecated No longer used in production code. Button now links directly to Yahoo OAuth.
		 *             Kept for backward compatibility and test support only.
		 *
		 * Implements OAuth flow for Yahoo Fantasy Sports API.
		 */
		public function handle_yahoo_oauth_start() {
			// Check nonce for security.
			if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wp_mcp_ai_yahoo_oauth_start' ) ) {
				wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ) );
			}

			// Check user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos' ) );
			}

			// Get settings.
			$settings      = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id     = isset( $settings['yahoo_client_id'] ) ? trim( $settings['yahoo_client_id'] ) : '';
			$client_secret = isset( $settings['yahoo_client_secret'] ) ? trim( $settings['yahoo_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'         => 'wp-mcp-ai-dashboard',
							'tab'          => 'tools',
							'subtab'       => 'connections',
							'connection'   => 'yahoo_sports',
							'yahoo_error'  => rawurlencode( __( 'Please save your Yahoo Client ID and Client Secret before connecting.', 'mcp-ai-wpoos' ) ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$user_id = get_current_user_id();

			// Generate state token for CSRF protection.
			$state = wp_generate_password( 32, false );
			update_user_meta( $user_id, 'wp_mcp_ai_yahoo_oauth_state', $state );
			update_user_meta( $user_id, 'wp_mcp_ai_yahoo_oauth_timestamp', time() );

			// Build redirect URI.
			$base_url     = admin_url( 'admin.php' );
			$redirect_uri = add_query_arg(
				array( 'wp_mcp_ai_oauth' => 'yahoo_callback' ),
				$base_url
			);

			// Build Yahoo OAuth authorization URL.
			$auth_url = add_query_arg(
				array(
					'client_id'     => rawurlencode( $client_id ),
					'redirect_uri'  => rawurlencode( $redirect_uri ),
					'response_type' => 'code',
					'scope'         => 'fspt-r', // Fantasy Sports Read access.
					'state'         => $state,
				),
				'https://api.login.yahoo.com/oauth2/request_auth'
			);

			wp_safe_redirect( $auth_url );
			exit;
		}

		/**
		 * Handle Yahoo OAuth callback.
		 */
		protected function handle_yahoo_oauth_callback() {
			// OAuth callback parameters from Yahoo. No nonce verification required as state parameter provides CSRF protection.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

			$redirect_base = add_query_arg(
				array(
					'page'       => 'wp-mcp-ai-dashboard',
					'tab'        => 'tools',
					'subtab'     => 'connections',
					'connection' => 'yahoo_sports',
				),
				admin_url( 'admin.php' )
			);

			if ( $error ) {
				wp_safe_redirect(
					add_query_arg(
						'yahoo_error',
						rawurlencode(
							sprintf(
								/* translators: %s: Error message from Yahoo OAuth */
								__( 'Yahoo OAuth error: %s', 'mcp-ai-wpoos' ),
								$error
							)
						),
						$redirect_base
					)
				);
				exit;
			}

			// Verify state using transient (similar to Gmail OAuth flow).
			$transient_key = 'wp_mcp_ai_yahoo_oauth_state_' . md5( $state );
			$state_data    = get_transient( $transient_key );

			delete_transient( $transient_key );

			if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
				wp_safe_redirect( add_query_arg( 'yahoo_error', rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			$user_id = $state_data['user_id'];

			if ( empty( $code ) ) {
				wp_safe_redirect( add_query_arg( 'yahoo_error', rawurlencode( __( 'No authorization code received from Yahoo.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Get settings.
			$settings      = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id     = isset( $settings['yahoo_client_id'] ) ? trim( $settings['yahoo_client_id'] ) : '';
			$client_secret = isset( $settings['yahoo_client_secret'] ) ? trim( $settings['yahoo_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect( add_query_arg( 'yahoo_error', rawurlencode( __( 'Yahoo credentials not found in settings.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Build redirect_uri - must match exactly what was sent in the authorization request.
			$base_url     = admin_url( 'admin.php' );
			$redirect_uri = add_query_arg(
				array( 'wp_mcp_ai_oauth' => 'yahoo_callback' ),
				$base_url
			);

			// Exchange authorization code for tokens.
			$token_url = 'https://api.login.yahoo.com/oauth2/get_token';

			$response = wp_remote_post(
				$token_url,
				array(
					'headers' => array(
						'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						'Content-Type'  => 'application/x-www-form-urlencoded',
					),
					'body'    => array(
						'code'         => $code,
						'redirect_uri' => $redirect_uri,
						'grant_type'   => 'authorization_code',
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_safe_redirect(
					add_query_arg(
						'yahoo_error',
						rawurlencode(
							sprintf(
								/* translators: %s: Error message */
								__( 'Failed to exchange authorization code: %s', 'mcp-ai-wpoos' ),
								$response->get_error_message()
							)
						),
						$redirect_base
					)
				);
				exit;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( empty( $data['access_token'] ) || empty( $data['refresh_token'] ) ) {
				wp_safe_redirect(
					add_query_arg(
						'yahoo_error',
						rawurlencode( __( 'Invalid token response from Yahoo.', 'mcp-ai-wpoos' ) ),
						$redirect_base
					)
				);
				exit;
			}

			// Store tokens in user meta.
			update_user_meta( $user_id, 'wp_mcp_ai_yahoo_access_token', $data['access_token'] );
			update_user_meta( $user_id, 'wp_mcp_ai_yahoo_refresh_token', $data['refresh_token'] );

			// Calculate expiration time.
			$expires_in = isset( $data['expires_in'] ) ? intval( $data['expires_in'] ) : 3600;
			update_user_meta( $user_id, 'wp_mcp_ai_yahoo_token_expires', time() + $expires_in );

			$success_message = __( 'Yahoo Sports connected successfully! You can now use Yahoo Fantasy Football tools.', 'mcp-ai-wpoos' );

			wp_safe_redirect( add_query_arg( 'yahoo_success', rawurlencode( $success_message ), $redirect_base ) );
			exit;
		}
	}
}

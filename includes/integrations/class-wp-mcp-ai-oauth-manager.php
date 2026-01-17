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
			}
		}

		/**
		 * Handle Gmail OAuth start request.
		 *
		 * Implements OAuth flow for base version's single Gmail connection.
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
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id = isset( $settings['gmail_client_id'] ) ? trim( $settings['gmail_client_id'] ) : '';
			$client_secret = isset( $settings['gmail_client_secret'] ) ? trim( $settings['gmail_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'               => 'wp-mcp-ai-dashboard',
							'tab'                => 'tools',
							'subtab'             => 'connections',
							'connection'         => 'gmail',
							'gmail_error'        => rawurlencode( __( 'Please save your Gmail Client ID and Client Secret before connecting.', 'mcp-ai-wpoos' ) ),
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

			// Build OAuth authorization URL.
			$params = array(
				'client_id'              => $client_id,
				'redirect_uri'           => admin_url( 'admin.php?wp_mcp_ai_oauth=gmail_callback' ),
				'response_type'          => 'code',
				'scope'                  => 'https://www.googleapis.com/auth/gmail.readonly',
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
			);

			$authorize_url = add_query_arg( $params, 'https://accounts.google.com/o/oauth2/v2/auth' );

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
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( sprintf( __( 'Google OAuth error: %s', 'mcp-ai-wpoos' ), $error ) ), $redirect_base ) );
				exit;
			}

			$transient_key = 'wp_mcp_ai_gmail_oauth_state_' . md5( $state );
			$state_data    = get_transient( $transient_key );

			delete_transient( $transient_key );

			if ( empty( $state ) || ! $state_data || (int) $state_data['user_id'] !== get_current_user_id() ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			if ( empty( $code ) ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'No authorization code received from Google.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Get settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id = isset( $settings['gmail_client_id'] ) ? trim( $settings['gmail_client_id'] ) : '';
			$client_secret = isset( $settings['gmail_client_secret'] ) ? trim( $settings['gmail_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'Gmail credentials not found in settings.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Exchange authorization code for tokens.
			$response = wp_remote_post(
				'https://oauth2.googleapis.com/token',
				array(
					'timeout' => 15,
					'body'    => array(
						'code'          => $code,
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'redirect_uri'  => admin_url( 'admin.php?wp_mcp_ai_oauth=gmail_callback' ),
						'grant_type'    => 'authorization_code',
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'Failed to exchange authorization code. Please try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'Google rejected the authorization. Please check your OAuth configuration.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) ) {
				wp_safe_redirect( add_query_arg( 'gmail_error', rawurlencode( __( 'Invalid response from Google.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
			$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

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
		 * Handle Google Drive OAuth start request.
		 *
		 * Implements OAuth flow for base version's single Google Drive connection.
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
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id = isset( $settings['google_drive_client_id'] ) ? trim( $settings['google_drive_client_id'] ) : '';
			$client_secret = isset( $settings['google_drive_client_secret'] ) ? trim( $settings['google_drive_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'               => 'wp-mcp-ai-dashboard',
							'tab'                => 'tools',
							'subtab'             => 'connections',
							'connection'         => 'google_drive',
							'drive_error'        => rawurlencode( __( 'Please save your Google Drive Client ID and Client Secret before connecting.', 'mcp-ai-wpoos' ) ),
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

			// Build OAuth authorization URL.
			$params = array(
				'client_id'              => $client_id,
				'redirect_uri'           => admin_url( 'admin.php?wp_mcp_ai_oauth=google_drive_callback' ),
				'response_type'          => 'code',
				'scope'                  => 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive.metadata.readonly',
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
			);

			$authorize_url = add_query_arg( $params, 'https://accounts.google.com/o/oauth2/v2/auth' );

			// Add Google OAuth domain to allowed redirect hosts.
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
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( sprintf( __( 'Google OAuth error: %s', 'mcp-ai-wpoos' ), $error ) ), $redirect_base ) );
				exit;
			}

			$transient_key = 'wp_mcp_ai_google_drive_oauth_state_' . md5( $state );
			$state_data    = get_transient( $transient_key );

			delete_transient( $transient_key );

			if ( empty( $state ) || ! $state_data || (int) $state_data['user_id'] !== get_current_user_id() ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'OAuth state verification failed. Please try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			if ( empty( $code ) ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'No authorization code received from Google.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Get settings.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$client_id = isset( $settings['google_drive_client_id'] ) ? trim( $settings['google_drive_client_id'] ) : '';
			$client_secret = isset( $settings['google_drive_client_secret'] ) ? trim( $settings['google_drive_client_secret'] ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'Google Drive credentials not found in settings.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			// Exchange authorization code for tokens.
			$response = wp_remote_post(
				'https://oauth2.googleapis.com/token',
				array(
					'timeout' => 15,
					'body'    => array(
						'code'          => $code,
						'client_id'     => $client_id,
						'client_secret' => $client_secret,
						'redirect_uri'  => admin_url( 'admin.php?wp_mcp_ai_oauth=google_drive_callback' ),
						'grant_type'    => 'authorization_code',
					),
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'Failed to exchange authorization code. Please try again.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'Google rejected the authorization. Please check your OAuth configuration.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) ) {
				wp_safe_redirect( add_query_arg( 'drive_error', rawurlencode( __( 'Invalid response from Google.', 'mcp-ai-wpoos' ) ), $redirect_base ) );
				exit;
			}

			$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
			$access_token  = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

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
	}
}

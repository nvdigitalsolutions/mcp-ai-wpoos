<?php
/**
 * QuickBooks OAuth Handler for WP MCP AI
 *
 * Handles OAuth flows for QuickBooks accounting integration.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_QuickBooks_OAuth_Handler' ) ) {
	/**
	 * Manages QuickBooks OAuth authentication flows.
	 *
	 * Intuit requires registered redirect URIs to carry **no query
	 * parameters** (developer.intuit.com → Authorization FAQ), so the callback
	 * is a clean REST route (`/wp-json/mcp-ai/v1/quickbooks/oauth/callback`)
	 * rather than the legacy `admin-post.php?action=…` URL. The start step may
	 * keep query parameters — only the registered URI is constrained.
	 *
	 * Tokens are stored encrypted at rest via `WP_MCP_AI_Encryption` and the
	 * latest rotated refresh token is always persisted, per Intuit's guidance.
	 */
	class WP_MCP_AI_QuickBooks_OAuth_Handler {
		const QUICKBOOKS_OAUTH_AUTHORIZE_ENDPOINT = 'https://appcenter.intuit.com/connect/oauth2';
		const QUICKBOOKS_OAUTH_TOKEN_ENDPOINT     = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
		const QUICKBOOKS_API_BASE                 = 'https://quickbooks.api.intuit.com/v3';
		const QUICKBOOKS_OAUTH_SCOPES             = 'com.intuit.quickbooks.accounting';

		/**
		 * REST namespace and route for the OAuth callback.
		 *
		 * The registered Intuit redirect URI must be a clean path, so this is
		 * exposed as a constant rather than assembled ad hoc.
		 */
		const QUICKBOOKS_REST_NAMESPACE       = 'mcp-ai/v1';
		const QUICKBOOKS_OAUTH_CALLBACK_ROUTE = '/quickbooks/oauth/callback';

		/**
		 * Prefix `WP_MCP_AI_Encryption` prepends to GCM ciphertext.
		 */
		const ENCRYPTION_PREFIX = 'v2:';

		/**
		 * Handle the start of the QuickBooks OAuth flow.
		 *
		 * Nonce-protected admin-post entry point. Intuit only constrains the
		 * registered redirect URI, so query parameters are acceptable here.
		 */
		public function handle_quickbooks_oauth_start() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_quickbooks_oauth_start' );

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			$return_url = isset( $_GET['return'] ) ? esc_url_raw( wp_unslash( $_GET['return'] ) ) : '';

			$start = $this->begin_authorization( $return_url );

			if ( is_wp_error( $start ) ) {
				$this->set_notice( $start->get_error_code(), $start->get_error_message(), 'error' );
				$this->redirect_to_settings_page( $return_url );
			}

			wp_safe_redirect( $start['authorize_url'] );
			exit;
		}

		/**
		 * Build an authorization URL and persist the one-time OAuth state.
		 *
		 * Follows RFC 6749 §10.12: the state is a high-entropy, single-use,
		 * server-side value bound to the initiating user, the redirect URI and
		 * the return URL, with a short TTL.
		 *
		 * @since 1.2.22
		 *
		 * @param string $return_url Where to send the operator after the flow.
		 * @return array|WP_Error {
		 *     @type string $authorize_url Intuit consent URL.
		 *     @type string $state         One-time state value.
		 *     @type string $redirect_uri  Registered callback URI.
		 * }
		 */
		public function begin_authorization( $return_url = '' ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_forbidden',
					__( 'You do not have permission to connect QuickBooks.', 'mcp-ai-wpoos' )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['quickbooks_client_id'] ) || empty( $settings['quickbooks_client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_missing_client',
					__( 'Enter a QuickBooks OAuth Client ID and Client Secret in NV oOS → External Tools before connecting the account.', 'mcp-ai-wpoos' )
				);
			}

			$return_url   = $this->get_safe_return_url( $return_url );
			$redirect_uri = self::get_quickbooks_oauth_redirect_uri();
			$state        = wp_generate_uuid4();

			set_transient(
				$this->get_quickbooks_state_transient_key( $state ),
				array(
					'user_id'      => get_current_user_id(),
					'time'         => time(),
					'return_url'   => $return_url,
					'redirect_uri' => $redirect_uri,
				),
				10 * MINUTE_IN_SECONDS
			);

			/**
			 * Filter the QuickBooks OAuth scope.
			 *
			 * @since 1.0.0
			 *
			 * @param string $scope OAuth scope. Default 'com.intuit.quickbooks.accounting'.
			 */
			$oauth_scope = apply_filters( 'wp_mcp_ai_quickbooks_oauth_scope', self::QUICKBOOKS_OAUTH_SCOPES );

			$params = array(
				'client_id'     => $settings['quickbooks_client_id'],
				'redirect_uri'  => $redirect_uri,
				'response_type' => 'code',
				'scope'         => $oauth_scope,
				'state'         => $state,
			);

			/**
			 * Filter the QuickBooks OAuth authorize endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth authorize endpoint.
			 */
			$authorize_endpoint = apply_filters( 'wp_mcp_ai_quickbooks_oauth_authorize_endpoint', self::QUICKBOOKS_OAUTH_AUTHORIZE_ENDPOINT );

			return array(
				'authorize_url' => add_query_arg( $params, $authorize_endpoint ),
				'state'         => $state,
				'redirect_uri'  => $redirect_uri,
			);
		}

		/**
		 * Handle the OAuth callback from QuickBooks and persist the tokens.
		 *
		 * Legacy admin-post entry point. The REST controller delegates to
		 * `complete_authorization()` directly.
		 */
		public function handle_quickbooks_oauth_callback() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			// OAuth callback parameters from QuickBooks. No nonce verification required as state parameter provides CSRF protection.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$realm_id = isset( $_GET['realmId'] ) ? sanitize_text_field( wp_unslash( $_GET['realmId'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
			$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth error description is read-only.
			$error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';

			$result = $this->complete_authorization( $state, $code, $realm_id, $error, $error_description );

			if ( is_wp_error( $result ) ) {
				$this->set_notice( $result->get_error_code(), $result->get_error_message(), 'error' );
				$this->redirect_to_settings_page( $this->get_error_return_url( $result ) );
			}

			$this->set_notice(
				'quickbooks_connected',
				sprintf(
					/* translators: %s: Company ID. */
					__( 'Successfully connected to QuickBooks! Company ID: %s', 'mcp-ai-wpoos' ),
					$result['company_id']
				),
				'success'
			);
			$this->redirect_to_settings_page( $result['return_url'] );
		}

		/**
		 * Validate the OAuth state and exchange the authorization code.
		 *
		 * Pure of HTTP redirects/exits so the REST controller and tests can
		 * drive it directly.
		 *
		 * @since 1.2.22
		 *
		 * @param string $state             OAuth state value.
		 * @param string $code              Authorization code.
		 * @param string $realm_id          QuickBooks company / realm ID.
		 * @param string $error             Optional error code from Intuit.
		 * @param string $error_description Optional error description from Intuit.
		 * @return array|WP_Error {
		 *     @type string $company_id Connected realm ID.
		 *     @type string $return_url Where the operator should land.
		 * }
		 */
		public function complete_authorization( $state, $code, $realm_id, $error = '', $error_description = '' ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_forbidden',
					__( 'You do not have permission to connect QuickBooks.', 'mcp-ai-wpoos' )
				);
			}

			$state_data     = '' !== $state ? get_transient( $this->get_quickbooks_state_transient_key( $state ) ) : false;
			$default_return = is_array( $state_data ) && ! empty( $state_data['return_url'] )
				? (string) $state_data['return_url']
				: $this->get_default_return_url();

			// The state is single-use: consume it even when validation fails.
			if ( '' !== $state ) {
				delete_transient( $this->get_quickbooks_state_transient_key( $state ) );
			}

			if ( '' === $state || ! is_array( $state_data ) || get_current_user_id() !== (int) $state_data['user_id'] ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_state_mismatch',
					__( 'The QuickBooks authorization request could not be verified. Please try again.', 'mcp-ai-wpoos' ),
					array( 'return_url' => $default_return )
				);
			}

			if ( '' !== $error ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_error',
					sprintf(
						/* translators: %s: QuickBooks error message. */
						__( 'QuickBooks returned an error during authorization: %s', 'mcp-ai-wpoos' ),
						'' !== $error_description ? $error_description : $error
					),
					array( 'return_url' => $default_return )
				);
			}

			if ( '' === $code ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_missing_code',
					__( 'QuickBooks did not return an authorization code. Please try again.', 'mcp-ai-wpoos' ),
					array( 'return_url' => $default_return )
				);
			}

			if ( '' === $realm_id ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_missing_realm_id',
					__( 'QuickBooks did not return a company ID (realmId). Please try again.', 'mcp-ai-wpoos' ),
					array( 'return_url' => $default_return )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['quickbooks_client_id'] ) || empty( $settings['quickbooks_client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_missing_client',
					__( 'Enter a QuickBooks OAuth Client ID and Client Secret in NV oOS → External Tools before connecting the account.', 'mcp-ai-wpoos' ),
					array( 'return_url' => $default_return )
				);
			}

			$redirect_uri = ! empty( $state_data['redirect_uri'] )
				? (string) $state_data['redirect_uri']
				: self::get_quickbooks_oauth_redirect_uri();

			$decoded = $this->request_token(
				array(
					'grant_type'   => 'authorization_code',
					'code'         => $code,
					'redirect_uri' => $redirect_uri,
				),
				$settings
			);

			if ( is_wp_error( $decoded ) ) {
				$decoded->add_data( array( 'return_url' => $default_return ) );
				return $decoded;
			}

			$access_token = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

			if ( '' === $access_token ) {
				WP_MCP_AI_Admin_Settings::log( 'QuickBooks OAuth callback omitted an access token.', array( 'response' => $decoded ) );

				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_missing_access_token',
					__( 'QuickBooks did not return an access token. Please try again.', 'mcp-ai-wpoos' ),
					array( 'return_url' => $default_return )
				);
			}

			$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
			$expires_in    = isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600;

			// Store the grant. Tokens are encrypted at rest; the realm ID is
			// stored as the company ID because Intuit tokens are realm-scoped.
			$settings['quickbooks_access_token']     = self::encrypt_token( $access_token );
			$settings['quickbooks_refresh_token']    = self::encrypt_token( $refresh_token );
			$settings['quickbooks_token_expires_at'] = time() + $expires_in;
			$settings['quickbooks_company_id']       = $realm_id;
			$settings['quickbooks_connected']        = true;
			$settings['quickbooks_connection_time']  = time();

			update_option( 'wp_mcp_ai_settings', $settings );

			return array(
				'company_id' => $realm_id,
				'return_url' => $default_return,
			);
		}

		/**
		 * Exchange an OAuth grant with Intuit's token endpoint.
		 *
		 * Intuit requires HTTP Basic client authentication (RFC 6749 §2.3.1).
		 *
		 * @since 1.2.22
		 *
		 * @param array $body     Form body.
		 * @param array $settings Plugin settings containing app credentials.
		 * @return array|WP_Error Decoded token response or error.
		 */
		protected function request_token( array $body, array $settings ) {
			/**
			 * Filter the QuickBooks OAuth token endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth token endpoint.
			 */
			$token_endpoint = apply_filters( 'wp_mcp_ai_quickbooks_oauth_token_endpoint', self::QUICKBOOKS_OAUTH_TOKEN_ENDPOINT );
			$auth_string    = base64_encode( $settings['quickbooks_client_id'] . ':' . $settings['quickbooks_client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to construct an HTTP Basic Auth header (RFC 7617), not for obfuscation.

			$response = wp_remote_post(
				$token_endpoint,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Basic ' . $auth_string,
						'Content-Type'  => 'application/x-www-form-urlencoded',
						'Accept'        => 'application/json',
					),
					'body'    => $body,
				)
			);

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Admin_Settings::log( 'QuickBooks OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );

				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_token_request_failed',
					__( 'QuickBooks could not exchange the authorization code. Check the OAuth credentials and try again.', 'mcp-ai-wpoos' )
				);
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );
			$raw_body    = wp_remote_retrieve_body( $response );

			if ( 200 !== $status_code ) {
				WP_MCP_AI_Admin_Settings::log(
					'QuickBooks OAuth token exchange returned an unexpected status.',
					array(
						'status' => $status_code,
						'body'   => $raw_body,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_token_request_error',
					__( 'QuickBooks rejected the authorization code. Review the OAuth application configuration and try again.', 'mcp-ai-wpoos' )
				);
			}

			$decoded = json_decode( $raw_body, true );

			if ( ! is_array( $decoded ) ) {
				WP_MCP_AI_Admin_Settings::log( 'QuickBooks OAuth token response was not valid JSON.', array( 'body' => $raw_body ) );

				return new WP_Error(
					'wp_mcp_ai_quickbooks_oauth_token_invalid_json',
					__( 'QuickBooks returned an unexpected response while exchanging the authorization code.', 'mcp-ai-wpoos' )
				);
			}

			return $decoded;
		}

		/**
		 * Handle disconnecting from QuickBooks.
		 */
		public function handle_quickbooks_disconnect() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'mcp-ai-wpoos' ) );
			}

			check_admin_referer( 'wp_mcp_ai_quickbooks_disconnect' );

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			$return_url = isset( $_GET['return'] ) ? esc_url_raw( wp_unslash( $_GET['return'] ) ) : '';

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Clear OAuth tokens (but keep company_id, client_id, client_secret).
			unset( $settings['quickbooks_access_token'] );
			unset( $settings['quickbooks_refresh_token'] );
			unset( $settings['quickbooks_token_expires_at'] );
			unset( $settings['quickbooks_connected'] );
			unset( $settings['quickbooks_connection_time'] );

			update_option( 'wp_mcp_ai_settings', $settings );

			$this->set_notice(
				'quickbooks_disconnected',
				__( 'Disconnected from QuickBooks. Your OAuth credentials and company ID remain saved for future connections.', 'mcp-ai-wpoos' ),
				'success'
			);
			$this->redirect_to_settings_page( $return_url );
		}

		/**
		 * Allow the QuickBooks OAuth authorize endpoint host when using wp_safe_redirect().
		 *
		 * @param string[] $allowed_hosts Existing list of allowed hosts.
		 * @param string   $redirect      Requested redirect destination.
		 *
		 * @return string[]
		 */
		public function allow_quickbooks_oauth_redirect_host( $allowed_hosts, $redirect = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter signature.
			/**
			 * Filter the QuickBooks OAuth authorize endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint OAuth authorize endpoint.
			 */
			$authorize_endpoint = apply_filters( 'wp_mcp_ai_quickbooks_oauth_authorize_endpoint', self::QUICKBOOKS_OAUTH_AUTHORIZE_ENDPOINT );
			$quickbooks_host    = wp_parse_url( $authorize_endpoint, PHP_URL_HOST );

			if ( $quickbooks_host ) {
				$allowed_hosts[] = $quickbooks_host;
			}

			return array_values( array_unique( $allowed_hosts ) );
		}

		/**
		 * Get the registered OAuth redirect URI (clean path).
		 *
		 * Intuit rejects registered redirect URIs that contain query
		 * parameters, so this must be the `/wp-json/…` form. Sites running
		 * plain permalinks get a `?rest_route=` URL; `has_clean_redirect_uri()`
		 * lets callers warn instead of silently failing at Intuit.
		 *
		 * @since 1.0.0
		 * @since 1.2.22 Defaults to the clean REST callback.
		 *
		 * @return string
		 */
		public static function get_quickbooks_oauth_redirect_uri() {
			$redirect_uri = rest_url( self::QUICKBOOKS_REST_NAMESPACE . self::QUICKBOOKS_OAUTH_CALLBACK_ROUTE );

			/**
			 * Filter the QuickBooks OAuth redirect URI.
			 *
			 * @since 1.0.0
			 *
			 * @param string $redirect_uri OAuth redirect URI.
			 */
			return (string) apply_filters( 'wp_mcp_ai_quickbooks_oauth_redirect_uri', $redirect_uri );
		}

		/**
		 * Whether the registered redirect URI is query-free.
		 *
		 * @since 1.2.22
		 *
		 * @return bool
		 */
		public static function has_clean_redirect_uri() {
			return false === strpos( self::get_quickbooks_oauth_redirect_uri(), '?' );
		}

		/**
		 * Get the transient key for storing OAuth state.
		 *
		 * @param string $state OAuth state parameter.
		 * @return string
		 */
		protected function get_quickbooks_state_transient_key( $state ) {
			return 'wp_mcp_ai_quickbooks_oauth_state_' . $state;
		}

		/**
		 * Validate a return URL against the site's own hosts.
		 *
		 * @since 1.2.22
		 *
		 * @param string $url Requested return URL.
		 * @return string Safe absolute URL.
		 */
		public function get_safe_return_url( $url ) {
			$default = $this->get_default_return_url();

			if ( '' === trim( (string) $url ) ) {
				return $default;
			}

			$validated = wp_validate_redirect( (string) $url, $default );

			return $validated ? $validated : $default;
		}

		/**
		 * Default post-flow destination.
		 *
		 * @since 1.2.22
		 *
		 * @return string
		 */
		public function get_default_return_url() {
			$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=quickbooks' );

			/**
			 * Filter the QuickBooks OAuth redirect URL.
			 *
			 * @since 1.0.0
			 *
			 * @param string $redirect_url The redirect URL.
			 */
			return (string) apply_filters( 'wp_mcp_ai_quickbooks_oauth_redirect_url', $redirect_url );
		}

		/**
		 * Extract the return URL from an error produced by complete_authorization().
		 *
		 * @since 1.2.22
		 *
		 * @param WP_Error $error Error object.
		 * @return string
		 */
		public function get_error_return_url( $error ) {
			$data = $error->get_error_data();

			if ( is_array( $data ) && ! empty( $data['return_url'] ) ) {
				return $this->get_safe_return_url( (string) $data['return_url'] );
			}

			return $this->get_default_return_url();
		}

		/**
		 * Store a notice for display on the next admin page load.
		 *
		 * @since 1.2.22
		 *
		 * @param string $code    Notice code.
		 * @param string $message Notice message.
		 * @param string $type    Notice type (success, error, warning, info).
		 */
		public function set_notice( $code, $message, $type = 'error' ) {
			set_transient(
				'wp_mcp_ai_quickbooks_oauth_notice',
				array(
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				),
				60
			);
		}

		/**
		 * Redirect back to a settings/connection screen.
		 *
		 * @param string $redirect_url Optional destination; the default is used when empty.
		 */
		protected function redirect_to_settings_page( $redirect_url = '' ) {
			$redirect_url = '' !== (string) $redirect_url
				? $this->get_safe_return_url( $redirect_url )
				: $this->get_default_return_url();

			wp_safe_redirect( $redirect_url );
			exit;
		}

		/**
		 * Check if QuickBooks is connected.
		 *
		 * @return bool True if connected.
		 */
		public static function is_connected() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return ! empty( $settings['quickbooks_connected'] ) && ! empty( $settings['quickbooks_access_token'] );
		}

		/**
		 * Get a valid access token, refreshing if necessary.
		 *
		 * @return string|WP_Error Access token on success.
		 */
		public static function get_access_token() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['quickbooks_access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_no_token',
					__( 'QuickBooks is not connected.', 'mcp-ai-wpoos' )
				);
			}

			// Check if token has expired.
			if ( ! empty( $settings['quickbooks_token_expires_at'] ) ) {
				$expires_at = absint( $settings['quickbooks_token_expires_at'] );
				// Add 60 second buffer before expiry.
				if ( time() >= ( $expires_at - 60 ) ) {
					// Token expired, try to refresh.
					return self::refresh_access_token();
				}
			}

			$token = self::decrypt_token( (string) $settings['quickbooks_access_token'] );

			if ( '' === $token ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_token_unreadable',
					__( 'The stored QuickBooks access token could not be decrypted. Reconnect QuickBooks.', 'mcp-ai-wpoos' )
				);
			}

			return $token;
		}

		/**
		 * Force a refresh even when the stored token has not reached its recorded
		 * expiry.
		 *
		 * Intuit access tokens can be revoked or rotated before the recorded
		 * `expires_in` elapses (app disconnect, company change, early rotation).
		 * A caller that receives HTTP 401 can retry once with a fresh token
		 * before asking the operator to reconnect.
		 *
		 * @since 1.2.22
		 *
		 * @return string|WP_Error New access token on success.
		 */
		public static function force_refresh_access_token() {
			return self::refresh_access_token();
		}

		/**
		 * Refresh the access token using the refresh token.
		 *
		 * Intuit rotates refresh tokens; the newest value from the response is
		 * always persisted.
		 *
		 * @return string|WP_Error New access token on success.
		 */
		protected static function refresh_access_token() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['quickbooks_refresh_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_no_refresh_token',
					__( 'QuickBooks refresh token is not available. Please reconnect.', 'mcp-ai-wpoos' )
				);
			}

			if ( empty( $settings['quickbooks_client_id'] ) || empty( $settings['quickbooks_client_secret'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_no_credentials',
					__( 'QuickBooks OAuth credentials are not configured.', 'mcp-ai-wpoos' )
				);
			}

			$refresh_token = self::decrypt_token( (string) $settings['quickbooks_refresh_token'] );

			if ( '' === $refresh_token ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_no_refresh_token',
					__( 'The stored QuickBooks refresh token could not be decrypted. Please reconnect.', 'mcp-ai-wpoos' )
				);
			}

			$token_endpoint = apply_filters( 'wp_mcp_ai_quickbooks_oauth_token_endpoint', self::QUICKBOOKS_OAUTH_TOKEN_ENDPOINT );
			$auth_string    = base64_encode( $settings['quickbooks_client_id'] . ':' . $settings['quickbooks_client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to construct an HTTP Basic Auth header (RFC 7617), not for obfuscation.

			$response = wp_remote_post(
				$token_endpoint,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Basic ' . $auth_string,
						'Content-Type'  => 'application/x-www-form-urlencoded',
						'Accept'        => 'application/json',
					),
					'body'    => array(
						'grant_type'    => 'refresh_token',
						'refresh_token' => $refresh_token,
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
					'wp_mcp_ai_quickbooks_refresh_failed',
					sprintf(
						/* translators: %d: HTTP status code. */
						__( 'QuickBooks token refresh failed with HTTP %d', 'mcp-ai-wpoos' ),
						$status_code
					)
				);
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_quickbooks_invalid_refresh_response',
					__( 'QuickBooks returned an invalid refresh response.', 'mcp-ai-wpoos' )
				);
			}

			// Update the stored token (encrypted at rest).
			$settings['quickbooks_access_token']     = self::encrypt_token( $decoded['access_token'] );
			$settings['quickbooks_token_expires_at'] = time() + ( isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600 );

			if ( ! empty( $decoded['refresh_token'] ) ) {
				$settings['quickbooks_refresh_token'] = self::encrypt_token( $decoded['refresh_token'] );
			}

			update_option( 'wp_mcp_ai_settings', $settings );

			return $decoded['access_token'];
		}

		/**
		 * Encrypt a token for storage.
		 *
		 * Falls back to plaintext when the encryption class is unavailable so
		 * the flow still works on minimal installs; decrypt_token() accepts
		 * both forms.
		 *
		 * @since 1.2.22
		 *
		 * @param string $value Plaintext token.
		 * @return string
		 */
		protected static function encrypt_token( $value ) {
			$value = (string) $value;

			if ( '' === $value ) {
				return '';
			}

			if ( class_exists( 'WP_MCP_AI_Encryption' ) ) {
				$encrypted = WP_MCP_AI_Encryption::encrypt( $value );

				if ( false !== $encrypted && '' !== $encrypted ) {
					return $encrypted;
				}
			}

			return $value;
		}

		/**
		 * Decrypt a stored token.
		 *
		 * @since 1.2.22
		 *
		 * @param string $value Stored value (ciphertext or legacy plaintext).
		 * @return string Decrypted token, or an empty string when unreadable.
		 */
		protected static function decrypt_token( $value ) {
			$value = (string) $value;

			if ( '' === $value ) {
				return '';
			}

			if ( 0 !== strpos( $value, self::ENCRYPTION_PREFIX ) ) {
				// Legacy plaintext value written before encryption at rest.
				return $value;
			}

			if ( ! class_exists( 'WP_MCP_AI_Encryption' ) ) {
				return '';
			}

			$decrypted = WP_MCP_AI_Encryption::decrypt( $value );

			return false !== $decrypted ? (string) $decrypted : '';
		}
	}
}

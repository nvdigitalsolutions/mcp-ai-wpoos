<?php
/**
 * QuickBooks OAuth Handler (Wave E4, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_QuickBooks_OAuth_Handler`:
 * byte-identical QuickBooks OAuth endpoints/scopes constants, the start
 * handler (capability + nonce + credentials gates, `wp_generate_uuid4`
 * state with the `wp_mcp_ai_quickbooks_oauth_state_{state}` transient,
 * the `wp_mcp_ai_quickbooks_oauth_scope` +
 * `wp_mcp_ai_quickbooks_oauth_authorize_endpoint` filters), the
 * callback handler (state consumption, realmId gate, Basic-auth token
 * exchange via the `wp_mcp_ai_quickbooks_oauth_token_endpoint` filter,
 * token + company persistence), the disconnect handler, the
 * `is_connected()` / `get_access_token()` contract with the 60-second
 * expiry buffer and refresh path (error codes
 * `wp_mcp_ai_quickbooks_no_token|no_refresh_token|no_credentials|
 * refresh_failed|invalid_refresh_response`), and the
 * `wp_mcp_ai_quickbooks_oauth_notice` transient envelope.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Settings access + logging resolve per install mode (`settings()`
 *    / `settings_log()` seams — base `WP_MCP_AI_Admin_Settings`
 *    monolith boot-gated / `wp_mcp_ai_settings` option standalone with
 *    dormant logging).
 *  - Standalone-only hook registration via `Plugin::registerIntegrations()`
 *    — the base quickbooks-integration-init.php owns the same
 *    `admin_post_*` wiring + `admin_notices` display in monolith
 *    installs.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Integrations;

/**
 * QuickBooks OAuth handler.
 *
 * @since 2.1.0
 */
class QuickbooksOAuthHandler {

	const QUICKBOOKS_OAUTH_AUTHORIZE_ENDPOINT = 'https://appcenter.intuit.com/connect/oauth2';
	const QUICKBOOKS_OAUTH_TOKEN_ENDPOINT     = 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';
	const QUICKBOOKS_API_BASE                 = 'https://quickbooks.api.intuit.com/v3';
	const QUICKBOOKS_OAUTH_SCOPES             = 'com.intuit.quickbooks.accounting';

	/**
	 * Settings option name (byte-identical to the base
	 * `WP_MCP_AI_Admin_Settings::OPTION_NAME`).
	 */
	const OPTION_NAME = 'wp_mcp_ai_settings';

	/**
	 * Resolve the plugin settings per install mode.
	 *
	 * @return array<string, mixed>
	 */
	protected static function settings(): array {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) && is_callable( array( 'WP_MCP_AI_Admin_Settings', 'get_settings' ) ) ) {
			return \WP_MCP_AI_Admin_Settings::get_settings();
		}

		return get_option( self::OPTION_NAME, array() );
	}

	/**
	 * Log a settings event per install mode (dormant standalone).
	 *
	 * @param string $message Log message.
	 * @param array  $context Log context.
	 * @return void
	 */
	protected static function settings_log( $message, $context = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) && is_callable( array( 'WP_MCP_AI_Admin_Settings', 'log' ) ) ) {
			\WP_MCP_AI_Admin_Settings::log( $message, $context );
		}
	}

	/**
	 * Handle the start of the QuickBooks OAuth flow.
	 *
	 * Redirects the user to QuickBooks's authorization page.
	 */
	public function handle_quickbooks_oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		check_admin_referer( 'wp_mcp_ai_quickbooks_oauth_start' );

		$settings = self::settings();

		if ( empty( $settings['quickbooks_client_id'] ) || empty( $settings['quickbooks_client_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_missing_client',
				__( 'Enter a QuickBooks OAuth Client ID and Client Secret before connecting the account.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$state     = wp_generate_uuid4();
		$transient = $this->get_quickbooks_state_transient_key( $state );

		set_transient(
			$transient,
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		/**
		 * Filter the QuickBooks OAuth scope.
		 *
		 * @since 2.1.0
		 *
		 * @param string $scope OAuth scope. Default 'com.intuit.quickbooks.accounting'.
		 */
		$oauth_scope = apply_filters( 'wp_mcp_ai_quickbooks_oauth_scope', self::QUICKBOOKS_OAUTH_SCOPES );

		$params = array(
			'client_id'     => $settings['quickbooks_client_id'],
			'redirect_uri'  => $this->get_quickbooks_oauth_redirect_uri(),
			'response_type' => 'code',
			'scope'         => $oauth_scope,
			'state'         => $state,
		);

		/**
		 * Filter the QuickBooks OAuth authorize endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth authorize endpoint.
		 */
		$authorize_endpoint = apply_filters( 'wp_mcp_ai_quickbooks_oauth_authorize_endpoint', self::QUICKBOOKS_OAUTH_AUTHORIZE_ENDPOINT );
		$authorize_url      = add_query_arg( $params, $authorize_endpoint );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle the OAuth callback from QuickBooks and persist the access token.
	 */
	public function handle_quickbooks_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
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

		if ( $error ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth error description is read-only.
			$error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			$error_message     = $error_description ? $error_description : $error;

			$this->add_settings_redirect_notice(
				'quickbooks_oauth_error',
				sprintf(
					/* translators: %s: QuickBooks error message. */
					__( 'QuickBooks returned an error during authorization: %s', 'nvoos-content-graph-ai-platform' ),
					$error_message
				),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$transient_key = $this->get_quickbooks_state_transient_key( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_state_mismatch',
				__( 'The QuickBooks authorization request could not be verified. Please try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		if ( empty( $code ) ) {
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_missing_code',
				__( 'QuickBooks did not return an authorization code. Please try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		if ( empty( $realm_id ) ) {
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_missing_realm_id',
				__( 'QuickBooks did not return a company ID (realmId). Please try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$settings = self::settings();

		if ( empty( $settings['quickbooks_client_id'] ) || empty( $settings['quickbooks_client_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_missing_client',
				__( 'Enter a QuickBooks OAuth Client ID and Client Secret before connecting the account.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Filter the QuickBooks OAuth token endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth token endpoint.
		 */
		$token_endpoint = apply_filters( 'wp_mcp_ai_quickbooks_oauth_token_endpoint', self::QUICKBOOKS_OAUTH_TOKEN_ENDPOINT );

		// QuickBooks requires Basic authentication with client_id:client_secret.
		$auth_string = base64_encode( $settings['quickbooks_client_id'] . ':' . $settings['quickbooks_client_secret'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64_encode used to construct an HTTP Basic Auth header (RFC 7617), not for obfuscation.

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
					'grant_type'   => 'authorization_code',
					'code'         => $code,
					'redirect_uri' => $this->get_quickbooks_oauth_redirect_uri(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::settings_log( 'QuickBooks OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_token_request_failed',
				__( 'QuickBooks could not exchange the authorization code. Check the OAuth credentials and try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			self::settings_log(
				'QuickBooks OAuth token exchange returned an unexpected status.',
				array(
					'status' => $status_code,
					'body'   => $body,
				)
			);
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_token_request_error',
				__( 'QuickBooks rejected the authorization code. Review the OAuth application configuration and try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			self::settings_log( 'QuickBooks OAuth token response was not valid JSON.', array( 'body' => $body ) );
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_token_invalid_json',
				__( 'QuickBooks returned an unexpected response while exchanging the authorization code.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$access_token = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

		if ( '' === $access_token ) {
			self::settings_log( 'QuickBooks OAuth callback omitted an access token.', array( 'response' => $decoded ) );
			$this->add_settings_redirect_notice(
				'quickbooks_oauth_missing_access_token',
				__( 'QuickBooks did not return an access token. Please try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
		$expires_in    = isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600;

		// Store the tokens and realmId.
		$settings['quickbooks_access_token']     = $access_token;
		$settings['quickbooks_refresh_token']    = $refresh_token;
		$settings['quickbooks_token_expires_at'] = time() + $expires_in;
		$settings['quickbooks_company_id']       = $realm_id; // Store the realmId as company_id.
		$settings['quickbooks_connected']        = true;
		$settings['quickbooks_connection_time']  = time();

		update_option( 'wp_mcp_ai_settings', $settings );

		$this->add_settings_redirect_notice(
			'quickbooks_connected',
			sprintf(
				/* translators: %s: Company ID. */
				__( 'Successfully connected to QuickBooks! Company ID: %s', 'nvoos-content-graph-ai-platform' ),
				$realm_id
			),
			'success'
		);
		$this->redirect_to_settings_page();
	}

	/**
	 * Handle disconnecting from QuickBooks.
	 */
	public function handle_quickbooks_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		check_admin_referer( 'wp_mcp_ai_quickbooks_disconnect' );

		$settings = self::settings();

		// Clear OAuth tokens (but keep company_id, client_id, client_secret).
		unset( $settings['quickbooks_access_token'] );
		unset( $settings['quickbooks_refresh_token'] );
		unset( $settings['quickbooks_token_expires_at'] );
		unset( $settings['quickbooks_connected'] );
		unset( $settings['quickbooks_connection_time'] );

		update_option( 'wp_mcp_ai_settings', $settings );

		$this->add_settings_redirect_notice(
			'quickbooks_disconnected',
			__( 'Disconnected from QuickBooks. Your OAuth credentials and company ID remain saved for future connections.', 'nvoos-content-graph-ai-platform' ),
			'success'
		);
		$this->redirect_to_settings_page();
	}

	/**
	 * Get the OAuth redirect URI for QuickBooks.
	 *
	 * @return string
	 */
	protected function get_quickbooks_oauth_redirect_uri() {
		/**
		 * Filter the QuickBooks OAuth redirect URI.
		 *
		 * @since 2.1.0
		 *
		 * @param string $redirect_uri OAuth redirect URI.
		 */
		return apply_filters(
			'wp_mcp_ai_quickbooks_oauth_redirect_uri',
			admin_url( 'admin-post.php?action=wp_mcp_ai_quickbooks_oauth_callback' )
		);
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
	 * Add an admin notice and store it in a transient for redirect.
	 *
	 * @param string $code    Notice code.
	 * @param string $message Notice message.
	 * @param string $type    Notice type (success, error, warning, info).
	 */
	protected function add_settings_redirect_notice( $code, $message, $type = 'error' ) {
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
	 * Redirect back to the settings page.
	 */
	protected function redirect_to_settings_page() {
		$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=quickbooks' );

		/**
		 * Filter the QuickBooks OAuth redirect URL.
		 *
		 * @since 2.1.0
		 *
		 * @param string $redirect_url The redirect URL.
		 */
		$redirect_url = apply_filters( 'wp_mcp_ai_quickbooks_oauth_redirect_url', $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Check if QuickBooks is connected.
	 *
	 * @return bool True if connected.
	 */
	public static function is_connected() {
		$settings = self::settings();
		return ! empty( $settings['quickbooks_connected'] ) && ! empty( $settings['quickbooks_access_token'] );
	}

	/**
	 * Get a valid access token, refreshing if necessary.
	 *
	 * @return string|\WP_Error Access token on success.
	 */
	public static function get_access_token() {
		$settings = self::settings();

		if ( empty( $settings['quickbooks_access_token'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_quickbooks_no_token',
				__( 'QuickBooks is not connected.', 'nvoos-content-graph-ai-platform' )
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

		return $settings['quickbooks_access_token'];
	}

	/**
	 * Refresh the access token using the refresh token.
	 *
	 * @return string|\WP_Error New access token on success.
	 */
	protected static function refresh_access_token() {
		$settings = self::settings();

		if ( empty( $settings['quickbooks_refresh_token'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_quickbooks_no_refresh_token',
				__( 'QuickBooks refresh token is not available. Please reconnect.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( empty( $settings['quickbooks_client_id'] ) || empty( $settings['quickbooks_client_secret'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_quickbooks_no_credentials',
				__( 'QuickBooks OAuth credentials are not configured.', 'nvoos-content-graph-ai-platform' )
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
					'refresh_token' => $settings['quickbooks_refresh_token'],
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			return new \WP_Error(
				'wp_mcp_ai_quickbooks_refresh_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'QuickBooks token refresh failed with HTTP %d', 'nvoos-content-graph-ai-platform' ),
					$status_code
				)
			);
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_quickbooks_invalid_refresh_response',
				__( 'QuickBooks returned an invalid refresh response.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Update the stored token.
		$settings['quickbooks_access_token']     = $decoded['access_token'];
		$settings['quickbooks_token_expires_at'] = time() + ( isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600 );

		if ( ! empty( $decoded['refresh_token'] ) ) {
			$settings['quickbooks_refresh_token'] = $decoded['refresh_token'];
		}

		update_option( 'wp_mcp_ai_settings', $settings );

		return $decoded['access_token'];
	}
}

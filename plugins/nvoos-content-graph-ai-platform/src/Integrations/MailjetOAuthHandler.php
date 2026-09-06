<?php
/**
 * Mailjet OAuth Handler (Wave E4, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Mailjet_OAuth_Handler`:
 * byte-identical Mailjet OAuth endpoints, the start handler
 * (capability + nonce + credentials gates, `wp_generate_uuid4` state
 * with the `wp_mcp_ai_mailjet_oauth_state_{state}` transient, the
 * `wp_mcp_ai_mailjet_oauth_authorize_endpoint` filter), the callback
 * handler (state consumption, token exchange via the
 * `wp_mcp_ai_mailjet_oauth_token_endpoint` filter, token persistence),
 * the disconnect handler, the `is_connected()` / `get_access_token()`
 * contract with the 60-second expiry buffer and refresh path (error
 * codes `wp_mcp_ai_mailjet_no_token|no_refresh_token|no_credentials|
 * refresh_failed|invalid_refresh_response`), and the
 * `wp_mcp_ai_mailjet_oauth_notice` transient envelope.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Settings access + logging resolve per install mode (`settings()`
 *    / `settings_log()` seams — base `WP_MCP_AI_Admin_Settings`
 *    monolith boot-gated / `wp_mcp_ai_settings` option standalone with
 *    dormant logging).
 *  - No hook registration — byte-identical to the base, whose
 *    mailjet-integration-init.php wires only the webhook handler; this
 *    class is a static token utility consumed by later surfaces.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Integrations;

/**
 * Mailjet OAuth handler.
 *
 * @since 2.1.0
 */
class MailjetOAuthHandler {

	const MAILJET_OAUTH_AUTHORIZE_ENDPOINT = 'https://app.mailjet.com/oauth/authorize';
	const MAILJET_OAUTH_TOKEN_ENDPOINT     = 'https://api.mailjet.com/v3/REST/oauth/token';
	const MAILJET_API_BASE                 = 'https://api.mailjet.com/v3/REST';

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
	 * Handle the start of the Mailjet OAuth flow.
	 *
	 * Redirects the user to Mailjet's authorization page.
	 */
	public function handle_mailjet_oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		check_admin_referer( 'wp_mcp_ai_mailjet_oauth_start' );

		$settings = self::settings();

		if ( empty( $settings['mailjet_client_id'] ) || empty( $settings['mailjet_client_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'mailjet_oauth_missing_client',
				__( 'Enter a Mailjet OAuth Client ID and Client Secret before connecting the account.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$state     = wp_generate_uuid4();
		$transient = $this->get_mailjet_state_transient_key( $state );

		set_transient(
			$transient,
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		$params = array(
			'client_id'     => $settings['mailjet_client_id'],
			'redirect_uri'  => $this->get_mailjet_oauth_redirect_uri(),
			'response_type' => 'code',
			'state'         => $state,
		);

		/**
		 * Filter the Mailjet OAuth authorize endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth authorize endpoint.
		 */
		$authorize_endpoint = apply_filters( 'wp_mcp_ai_mailjet_oauth_authorize_endpoint', self::MAILJET_OAUTH_AUTHORIZE_ENDPOINT );
		$authorize_url      = add_query_arg( $params, $authorize_endpoint );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle the OAuth callback from Mailjet and persist the access token.
	 */
	public function handle_mailjet_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		// OAuth callback parameters from Mailjet. No nonce verification required as state parameter provides CSRF protection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth error description is read-only.
			$error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';
			$error_message     = $error_description ? $error_description : $error;

			$this->add_settings_redirect_notice(
				'mailjet_oauth_error',
				sprintf(
					/* translators: %s: Mailjet error message. */
					__( 'Mailjet returned an error during authorization: %s', 'nvoos-content-graph-ai-platform' ),
					$error_message
				),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$transient_key = $this->get_mailjet_state_transient_key( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
			$this->add_settings_redirect_notice(
				'mailjet_oauth_state_mismatch',
				__( 'The Mailjet authorization request could not be verified. Please try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		if ( empty( $code ) ) {
			$this->add_settings_redirect_notice(
				'mailjet_oauth_missing_code',
				__( 'Mailjet did not return an authorization code. Please try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$settings = self::settings();

		if ( empty( $settings['mailjet_client_id'] ) || empty( $settings['mailjet_client_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'mailjet_oauth_missing_client',
				__( 'Enter a Mailjet OAuth Client ID and Client Secret before connecting the account.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Filter the Mailjet OAuth token endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth token endpoint.
		 */
		$token_endpoint = apply_filters( 'wp_mcp_ai_mailjet_oauth_token_endpoint', self::MAILJET_OAUTH_TOKEN_ENDPOINT );

		$response = wp_remote_post(
			$token_endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'client_id'     => $settings['mailjet_client_id'],
					'client_secret' => $settings['mailjet_client_secret'],
					'code'          => $code,
					'redirect_uri'  => $this->get_mailjet_oauth_redirect_uri(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::settings_log( 'Mailjet OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );
			$this->add_settings_redirect_notice(
				'mailjet_oauth_token_request_failed',
				__( 'Mailjet could not exchange the authorization code. Check the OAuth credentials and try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			self::settings_log(
				'Mailjet OAuth token exchange returned an unexpected status.',
				array(
					'status' => $status_code,
					'body'   => $body,
				)
			);
			$this->add_settings_redirect_notice(
				'mailjet_oauth_token_request_error',
				__( 'Mailjet rejected the authorization code. Review the OAuth application configuration and try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			self::settings_log( 'Mailjet OAuth token response was not valid JSON.', array( 'body' => $body ) );
			$this->add_settings_redirect_notice(
				'mailjet_oauth_token_invalid_json',
				__( 'Mailjet returned an unexpected response while exchanging the authorization code.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$access_token = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

		if ( '' === $access_token ) {
			self::settings_log( 'Mailjet OAuth callback omitted an access token.', array( 'response' => $decoded ) );
			$this->add_settings_redirect_notice(
				'mailjet_oauth_missing_access_token',
				__( 'Mailjet did not return an access token. Please try again.', 'nvoos-content-graph-ai-platform' ),
				'error'
			);
			$this->redirect_to_settings_page();
		}

		$refresh_token = isset( $decoded['refresh_token'] ) ? trim( (string) $decoded['refresh_token'] ) : '';
		$expires_in    = isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600;

		// Store the tokens.
		$settings['mailjet_access_token']     = $access_token;
		$settings['mailjet_refresh_token']    = $refresh_token;
		$settings['mailjet_token_expires_at'] = time() + $expires_in;
		$settings['mailjet_connected']        = true;
		$settings['mailjet_connection_time']  = time();

		update_option( 'wp_mcp_ai_settings', $settings );

		$this->add_settings_redirect_notice(
			'mailjet_connected',
			__( 'Successfully connected to Mailjet! Your access token has been saved.', 'nvoos-content-graph-ai-platform' ),
			'success'
		);
		$this->redirect_to_settings_page();
	}

	/**
	 * Handle disconnecting from Mailjet.
	 */
	public function handle_mailjet_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		check_admin_referer( 'wp_mcp_ai_mailjet_disconnect' );

		$settings = self::settings();

		// Clear OAuth tokens.
		unset( $settings['mailjet_access_token'] );
		unset( $settings['mailjet_refresh_token'] );
		unset( $settings['mailjet_token_expires_at'] );
		unset( $settings['mailjet_connected'] );
		unset( $settings['mailjet_connection_time'] );

		update_option( 'wp_mcp_ai_settings', $settings );

		$this->add_settings_redirect_notice(
			'mailjet_disconnected',
			__( 'Disconnected from Mailjet. Your OAuth credentials remain saved for future connections.', 'nvoos-content-graph-ai-platform' ),
			'success'
		);
		$this->redirect_to_settings_page();
	}

	/**
	 * Get the OAuth redirect URI for Mailjet.
	 *
	 * @return string
	 */
	protected function get_mailjet_oauth_redirect_uri() {
		/**
		 * Filter the Mailjet OAuth redirect URI.
		 *
		 * @since 2.1.0
		 *
		 * @param string $redirect_uri OAuth redirect URI.
		 */
		return apply_filters(
			'wp_mcp_ai_mailjet_oauth_redirect_uri',
			admin_url( 'admin-post.php?action=wp_mcp_ai_mailjet_oauth_callback' )
		);
	}

	/**
	 * Get the transient key for storing OAuth state.
	 *
	 * @param string $state OAuth state parameter.
	 * @return string
	 */
	protected function get_mailjet_state_transient_key( $state ) {
		return 'wp_mcp_ai_mailjet_oauth_state_' . $state;
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
			'wp_mcp_ai_mailjet_oauth_notice',
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
		$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=mailjet' );

		/**
		 * Filter the Mailjet OAuth redirect URL.
		 *
		 * @since 2.1.0
		 *
		 * @param string $redirect_url The redirect URL.
		 */
		$redirect_url = apply_filters( 'wp_mcp_ai_mailjet_oauth_redirect_url', $redirect_url );

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Check if Mailjet is connected.
	 *
	 * @return bool True if connected.
	 */
	public static function is_connected() {
		$settings = self::settings();
		return ! empty( $settings['mailjet_connected'] ) && ! empty( $settings['mailjet_access_token'] );
	}

	/**
	 * Get a valid access token, refreshing if necessary.
	 *
	 * @return string|\WP_Error Access token on success.
	 */
	public static function get_access_token() {
		$settings = self::settings();

		if ( empty( $settings['mailjet_access_token'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mailjet_no_token',
				__( 'Mailjet is not connected.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Check if token has expired.
		if ( ! empty( $settings['mailjet_token_expires_at'] ) ) {
			$expires_at = absint( $settings['mailjet_token_expires_at'] );
			// Add 60 second buffer before expiry.
			if ( time() >= ( $expires_at - 60 ) ) {
				// Token expired, try to refresh.
				return self::refresh_access_token();
			}
		}

		return $settings['mailjet_access_token'];
	}

	/**
	 * Refresh the access token using the refresh token.
	 *
	 * @return string|\WP_Error New access token on success.
	 */
	protected static function refresh_access_token() {
		$settings = self::settings();

		if ( empty( $settings['mailjet_refresh_token'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mailjet_no_refresh_token',
				__( 'Mailjet refresh token is not available. Please reconnect.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( empty( $settings['mailjet_client_id'] ) || empty( $settings['mailjet_client_secret'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mailjet_no_credentials',
				__( 'Mailjet OAuth credentials are not configured.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$token_endpoint = apply_filters( 'wp_mcp_ai_mailjet_oauth_token_endpoint', self::MAILJET_OAUTH_TOKEN_ENDPOINT );

		$response = wp_remote_post(
			$token_endpoint,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'client_id'     => $settings['mailjet_client_id'],
					'client_secret' => $settings['mailjet_client_secret'],
					'refresh_token' => $settings['mailjet_refresh_token'],
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
				'wp_mcp_ai_mailjet_refresh_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Mailjet token refresh failed with HTTP %d', 'nvoos-content-graph-ai-platform' ),
					$status_code
				)
			);
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) || empty( $decoded['access_token'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_mailjet_invalid_refresh_response',
				__( 'Mailjet returned an invalid refresh response.', 'nvoos-content-graph-ai-platform' )
			);
		}

		// Update the stored token.
		$settings['mailjet_access_token']     = $decoded['access_token'];
		$settings['mailjet_token_expires_at'] = time() + ( isset( $decoded['expires_in'] ) ? absint( $decoded['expires_in'] ) : 3600 );

		if ( ! empty( $decoded['refresh_token'] ) ) {
			$settings['mailjet_refresh_token'] = $decoded['refresh_token'];
		}

		update_option( 'wp_mcp_ai_settings', $settings );

		return $decoded['access_token'];
	}
}

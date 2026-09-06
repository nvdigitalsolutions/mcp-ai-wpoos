<?php
/**
 * GitHub OAuth Handler (Wave E4, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Github_OAuth_Handler`:
 * byte-identical GitHub OAuth endpoints/scopes constants, the start
 * handler (capability + nonce + credentials gates, `wp_generate_uuid4`
 * state with the `wp_mcp_ai_github_state_{md5}` transient, the
 * `wp_mcp_ai_github_oauth_scope` + `wp_mcp_ai_github_oauth_authorize_endpoint`
 * filters), the callback handler (state consumption, token exchange via
 * the `wp_mcp_ai_github_oauth_token_endpoint` filter, `/user` profile
 * fetch, settings persistence with the pre-save sanitizer), the
 * `allowed_redirect_hosts` filter, the test-termination filter
 * (`wp_mcp_ai_github_oauth_redirect_terminate`), and the settings-error
 * notice envelope.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Settings access + logging resolve per install mode (`settings()`
 *    / `settings_log()` seams — base `WP_MCP_AI_Admin_Settings`
 *    monolith boot-gated / `wp_mcp_ai_settings` option standalone with
 *    dormant logging).
 *  - The pre-save sanitizer resolves per install mode
 *    (`sanitize_settings()` seam — base `WP_MCP_AI_Admin_Settings_Base`
 *    monolith / pass-through standalone; the stored values are already
 *    sanitized at entry, documented deviation).
 *  - Standalone-only hook registration via `Plugin::registerIntegrations()`
 *    — the base github-integration-init.php owns the same
 *    `admin_post_*` wiring in monolith installs.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Integrations;

/**
 * GitHub OAuth handler.
 *
 * @since 2.1.0
 */
class GithubOAuthHandler {

	const GITHUB_OAUTH_AUTHORIZE_ENDPOINT = 'https://github.com/login/oauth/authorize';
	const GITHUB_OAUTH_TOKEN_ENDPOINT     = 'https://github.com/login/oauth/access_token';
	const GITHUB_API_BASE                 = 'https://api.github.com';
	const GITHUB_OAUTH_SCOPES             = 'repo,user,codespace';

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
	 * Sanitize settings before saving, per install mode.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 * @return array<string, mixed>
	 */
	protected static function sanitize_settings( array $settings ): array {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings_Base' ) ) {
			return ( new \WP_MCP_AI_Admin_Settings_Base() )->sanitize_settings( $settings );
		}

		// Standalone: pass-through (documented deviation) — the values stored
		// by this handler are already sanitized at entry.
		return $settings;
	}

	/**
	 * Handle the start of the GitHub OAuth flow.
	 *
	 * Redirects the user to GitHub's authorization page.
	 */
	public function handle_github_oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		check_admin_referer( 'wp_mcp_ai_github_oauth_start' );

		$settings = self::settings();

		if ( empty( $settings['github_client_id'] ) || empty( $settings['github_client_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'github_oauth_missing_client',
				__( 'Enter a GitHub OAuth client ID and secret before connecting the account.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$state     = wp_generate_uuid4();
		$transient = $this->get_github_state_transient_key( $state );

		set_transient(
			$transient,
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		/**
		 * Filter the GitHub OAuth scope.
		 *
		 * @since 2.1.0
		 *
		 * @param string $scope OAuth scope. Default 'repo,user,codespace'.
		 */
		$oauth_scope = apply_filters( 'wp_mcp_ai_github_oauth_scope', self::GITHUB_OAUTH_SCOPES );

		$params = array(
			'client_id'    => $settings['github_client_id'],
			'redirect_uri' => $this->get_github_oauth_redirect_uri(),
			'scope'        => $oauth_scope,
			'state'        => $state,
		);

		/**
		 * Filter the GitHub OAuth authorize endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth authorize endpoint.
		 */
		$authorize_endpoint = apply_filters( 'wp_mcp_ai_github_oauth_authorize_endpoint', self::GITHUB_OAUTH_AUTHORIZE_ENDPOINT );
		$authorize_url      = add_query_arg( $params, $authorize_endpoint );

		wp_safe_redirect( $authorize_url );
		exit;
	}

	/**
	 * Handle the OAuth callback from GitHub and persist the access token.
	 */
	public function handle_github_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		// OAuth callback parameters from GitHub. No nonce verification required as state parameter provides CSRF protection.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state parameter verifies request authenticity.
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( $error ) {
			$this->add_settings_redirect_notice(
				'github_oauth_error',
				sprintf(
					/* translators: %s: GitHub error message. */
					__( 'GitHub returned an error during authorisation: %s', 'nvoos-content-graph-ai-platform' ),
					$error
				)
			);
			$this->redirect_to_settings_page();
		}

		$transient_key = $this->get_github_state_transient_key( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
			$this->add_settings_redirect_notice(
				'github_oauth_state_mismatch',
				__( 'The GitHub authorisation request could not be verified. Please try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		if ( empty( $code ) ) {
			$this->add_settings_redirect_notice(
				'github_oauth_missing_code',
				__( 'GitHub did not return an authorisation code. Please try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$settings = self::settings();

		if ( empty( $settings['github_client_id'] ) || empty( $settings['github_client_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'github_oauth_missing_client',
				__( 'Enter a GitHub OAuth client ID and secret before connecting the account.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Filter the GitHub OAuth token endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth token endpoint.
		 */
		$token_endpoint = apply_filters( 'wp_mcp_ai_github_oauth_token_endpoint', self::GITHUB_OAUTH_TOKEN_ENDPOINT );

		$response = wp_remote_post(
			$token_endpoint,
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $settings['github_client_id'],
					'client_secret' => $settings['github_client_secret'],
					'code'          => $code,
					'redirect_uri'  => $this->get_github_oauth_redirect_uri(),
				),
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::settings_log( 'GitHub OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );
			$this->add_settings_redirect_notice(
				'github_oauth_token_request_failed',
				__( 'GitHub could not exchange the authorisation code. Check the client credentials and try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			self::settings_log(
				'GitHub OAuth token exchange returned an unexpected status.',
				array(
					'status' => $status_code,
					'body'   => $body,
				)
			);
			$this->add_settings_redirect_notice(
				'github_oauth_token_request_error',
				__( 'GitHub rejected the authorisation code. Review the OAuth application configuration and try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			self::settings_log( 'GitHub OAuth token response was not valid JSON.', array( 'body' => $body ) );
			$this->add_settings_redirect_notice(
				'github_oauth_token_invalid_json',
				__( 'GitHub returned an unexpected response while exchanging the authorisation code.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$access_token = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

		if ( '' === $access_token ) {
			self::settings_log( 'GitHub OAuth callback omitted an access token.', array( 'response' => $decoded ) );
			$this->add_settings_redirect_notice(
				'github_oauth_missing_access_token',
				__( 'GitHub did not return an access token. Please try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$username = '';

		// Fetch the authenticated user's information.
		$user_response = wp_remote_get(
			self::GITHUB_API_BASE . '/user',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'        => 'application/vnd.github+json',
					'Authorization' => 'Bearer ' . $access_token,
					'User-Agent'    => 'WP-MCP-AI-Plugin',
				),
			)
		);

		if ( ! is_wp_error( $user_response ) ) {
			$user_status = wp_remote_retrieve_response_code( $user_response );
			$user_body   = wp_remote_retrieve_body( $user_response );

			if ( 200 === (int) $user_status ) {
				$user_data = json_decode( $user_body, true );

				if ( is_array( $user_data ) && ! empty( $user_data['login'] ) ) {
					$username = sanitize_text_field( $user_data['login'] );
				}
			}
		}

		$updated_settings                        = $settings;
		$updated_settings['github_access_token'] = $access_token;

		if ( $username ) {
			$updated_settings['github_username'] = $username;
		}

		// Manually sanitize settings before saving.
		$sanitized = self::sanitize_settings( $updated_settings );
		update_option( self::OPTION_NAME, $sanitized );

		$notice_message = __( 'GitHub authorisation complete. Access token has been stored.', 'nvoos-content-graph-ai-platform' );

		if ( $username ) {
			$notice_message = sprintf(
				/* translators: %s: GitHub username. */
				__( 'GitHub authorisation complete for %s.', 'nvoos-content-graph-ai-platform' ),
				$username
			);
		}

		$this->add_settings_redirect_notice( 'github_oauth_success', $notice_message, 'updated' );

		$this->redirect_to_settings_page();
	}

	/**
	 * Allow the GitHub OAuth authorize endpoint host when using wp_safe_redirect().
	 *
	 * @param string[] $allowed_hosts Existing list of allowed hosts.
	 * @param string   $redirect      Requested redirect destination.
	 *
	 * @return string[]
	 */
	public function allow_github_oauth_redirect_host( $allowed_hosts, $redirect = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by WordPress filter signature.
		/**
		 * Filter the GitHub OAuth authorize endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth authorize endpoint.
		 */
		$authorize_endpoint = apply_filters( 'wp_mcp_ai_github_oauth_authorize_endpoint', self::GITHUB_OAUTH_AUTHORIZE_ENDPOINT );
		$github_host        = wp_parse_url( $authorize_endpoint, PHP_URL_HOST );

		if ( $github_host ) {
			$allowed_hosts[] = $github_host;
		}

		return array_values( array_unique( $allowed_hosts ) );
	}

	/**
	 * Build the transient key used to persist OAuth state.
	 *
	 * @param string $state OAuth state string.
	 * @return string
	 */
	private function get_github_state_transient_key( $state ) {
		return 'wp_mcp_ai_github_state_' . md5( (string) $state );
	}

	/**
	 * Return the OAuth redirect URI registered in the GitHub OAuth application.
	 *
	 * @return string
	 */
	private function get_github_oauth_redirect_uri() {
		return admin_url( 'admin-post.php?action=wp_mcp_ai_github_oauth_callback' );
	}

	/**
	 * Retrieve the settings page URL.
	 *
	 * @return string
	 */
	private function get_settings_page_url() {
		// Redirect to the Tools tab > Connections subtab (where GitHub OAuth fields are located).
		return admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections' );
	}

	/**
	 * Redirect the current request back to the settings page and exit.
	 */
	private function redirect_to_settings_page() {
		wp_safe_redirect( $this->get_settings_page_url() );

		/**
		 * Filter whether the request should terminate after the redirect.
		 *
		 * Test suites disable the terminating exit so the handler can be
		 * exercised without killing the PHPUnit process.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $terminate Whether to exit after redirecting. Default true.
		 */
		if ( ! apply_filters( 'wp_mcp_ai_github_oauth_redirect_terminate', true ) ) {
			return;
		}

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
		add_settings_error( self::OPTION_NAME, $code, $message, $type );

		set_transient(
			'settings_errors',
			array(
				array(
					'setting' => self::OPTION_NAME,
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				),
			),
			30
		);
	}
}

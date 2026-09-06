<?php
/**
 * Meta (Facebook) OAuth Handler (Wave E4, sub-cluster 2).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Meta_OAuth_Handler`:
 * byte-identical Meta OAuth endpoints/scopes constants, the start
 * handler (capability + nonce + credentials gates, `wp_generate_uuid4`
 * state with the `wp_mcp_ai_meta_state_{md5}` transient, the
 * `wp_mcp_ai_meta_oauth_scope` + `wp_mcp_ai_meta_oauth_authorize_endpoint`
 * filters), the callback handler (state consumption, GET-based token
 * exchange via the `wp_mcp_ai_meta_oauth_token_endpoint` filter, `/me`
 * profile fetch, settings persistence with the pre-save sanitizer),
 * the disconnect handler, the `allowed_redirect_hosts` filter, and the
 * `redirect_and_exit()` contract (blocked-redirect abort under
 * PHPUnit via `WPDieException`).
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
 *    — the base meta-integration-init.php owns the same
 *    `admin_post_*` wiring in monolith installs.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Integrations
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Integrations;

/**
 * Meta OAuth handler.
 *
 * @since 2.1.0
 */
class MetaOAuthHandler {

	const META_OAUTH_AUTHORIZE_ENDPOINT = 'https://www.facebook.com/v18.0/dialog/oauth';
	const META_OAUTH_TOKEN_ENDPOINT     = 'https://graph.facebook.com/v18.0/oauth/access_token';
	const META_GRAPH_API_BASE           = 'https://graph.facebook.com/v18.0';
	const META_OAUTH_SCOPES             = 'pages_manage_posts,instagram_basic,instagram_content_publish,whatsapp_business_management,whatsapp_business_messaging';

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
	 * Handle the start of the Meta OAuth flow.
	 *
	 * Redirects the user to Facebook's authorization page.
	 */
	public function handle_meta_oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		check_admin_referer( 'wp_mcp_ai_meta_oauth_start' );

		$settings = self::settings();

		if ( empty( $settings['meta_app_id'] ) || empty( $settings['meta_app_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'meta_oauth_missing_client',
				__( 'Enter a Meta App ID and App Secret before connecting the account.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$state     = wp_generate_uuid4();
		$transient = $this->get_meta_state_transient_key( $state );

		set_transient(
			$transient,
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		/**
		 * Filter the Meta OAuth scope.
		 *
		 * @since 2.1.0
		 *
		 * @param string $scope OAuth scope. Default includes pages, Instagram, and WhatsApp permissions.
		 */
		$oauth_scope = apply_filters( 'wp_mcp_ai_meta_oauth_scope', self::META_OAUTH_SCOPES );

		$params = array(
			'client_id'     => $settings['meta_app_id'],
			'redirect_uri'  => $this->get_meta_oauth_redirect_uri(),
			'response_type' => 'code',
			'scope'         => $oauth_scope,
			'state'         => $state,
		);

		/**
		 * Filter the Meta OAuth authorize endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth authorize endpoint.
		 */
		$authorize_endpoint = apply_filters( 'wp_mcp_ai_meta_oauth_authorize_endpoint', self::META_OAUTH_AUTHORIZE_ENDPOINT );
		$authorize_url      = add_query_arg( $params, $authorize_endpoint );

		$this->redirect_and_exit( $authorize_url );
	}

	/**
	 * Handle the OAuth callback from Meta and persist the access token.
	 */
	public function handle_meta_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		// OAuth callback parameters from Meta. No nonce verification required as state parameter provides CSRF protection.
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
				'meta_oauth_error',
				sprintf(
					/* translators: %s: Meta error message. */
					__( 'Meta returned an error during authorisation: %s', 'nvoos-content-graph-ai-platform' ),
					$error_message
				)
			);
			$this->redirect_to_settings_page();
		}

		$transient_key = $this->get_meta_state_transient_key( $state );
		$state_data    = get_transient( $transient_key );

		delete_transient( $transient_key );

		if ( empty( $state ) || ! $state_data || get_current_user_id() !== (int) $state_data['user_id'] ) {
			$this->add_settings_redirect_notice(
				'meta_oauth_state_mismatch',
				__( 'The Meta authorisation request could not be verified. Please try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		if ( empty( $code ) ) {
			$this->add_settings_redirect_notice(
				'meta_oauth_missing_code',
				__( 'Meta did not return an authorisation code. Please try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$settings = self::settings();

		if ( empty( $settings['meta_app_id'] ) || empty( $settings['meta_app_secret'] ) ) {
			$this->add_settings_redirect_notice(
				'meta_oauth_missing_client',
				__( 'Enter a Meta App ID and App Secret before connecting the account.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Filter the Meta OAuth token endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth token endpoint.
		 */
		$token_endpoint = apply_filters( 'wp_mcp_ai_meta_oauth_token_endpoint', self::META_OAUTH_TOKEN_ENDPOINT );

		// Build token exchange URL with query parameters (Meta uses GET for token exchange).
		$token_url = add_query_arg(
			array(
				'client_id'     => $settings['meta_app_id'],
				'client_secret' => $settings['meta_app_secret'],
				'code'          => $code,
				'redirect_uri'  => $this->get_meta_oauth_redirect_uri(),
			),
			$token_endpoint
		);

		$response = wp_remote_get(
			$token_url,
			array(
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::settings_log( 'Meta OAuth token exchange failed.', array( 'error' => $response->get_error_message() ) );
			$this->add_settings_redirect_notice(
				'meta_oauth_token_request_failed',
				__( 'Meta could not exchange the authorisation code. Check the app credentials and try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== (int) $status_code ) {
			self::settings_log(
				'Meta OAuth token exchange returned an unexpected status.',
				array(
					'status' => $status_code,
					'body'   => $body,
				)
			);
			$this->add_settings_redirect_notice(
				'meta_oauth_token_request_error',
				__( 'Meta rejected the authorisation code. Review the OAuth application configuration and try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$decoded = json_decode( $body, true );

		if ( ! is_array( $decoded ) ) {
			self::settings_log( 'Meta OAuth token response was not valid JSON.', array( 'body' => $body ) );
			$this->add_settings_redirect_notice(
				'meta_oauth_token_invalid_json',
				__( 'Meta returned an unexpected response while exchanging the authorisation code.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$access_token = isset( $decoded['access_token'] ) ? trim( (string) $decoded['access_token'] ) : '';

		if ( '' === $access_token ) {
			self::settings_log( 'Meta OAuth callback omitted an access token.', array( 'response' => $decoded ) );
			$this->add_settings_redirect_notice(
				'meta_oauth_missing_access_token',
				__( 'Meta did not return an access token. Please try again.', 'nvoos-content-graph-ai-platform' )
			);
			$this->redirect_to_settings_page();
		}

		$user_name = '';
		$user_id   = '';

		// Fetch the authenticated user's information.
		$user_response = wp_remote_get(
			self::META_GRAPH_API_BASE . '/me?fields=id,name',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( ! is_wp_error( $user_response ) ) {
			$user_status = wp_remote_retrieve_response_code( $user_response );
			$user_body   = wp_remote_retrieve_body( $user_response );

			if ( 200 === (int) $user_status ) {
				$user_data = json_decode( $user_body, true );

				if ( is_array( $user_data ) ) {
					if ( ! empty( $user_data['name'] ) ) {
						$user_name = sanitize_text_field( $user_data['name'] );
					}
					if ( ! empty( $user_data['id'] ) ) {
						$user_id = sanitize_text_field( $user_data['id'] );
					}
				}
			}
		}

		$updated_settings                      = $settings;
		$updated_settings['meta_access_token'] = $access_token;

		// Store user info for reference.
		if ( $user_name ) {
			$updated_settings['meta_connected_user_name'] = $user_name;
		}
		if ( $user_id ) {
			$updated_settings['meta_connected_user_id'] = $user_id;
		}

		// Manually sanitize settings before saving.
		$sanitized = self::sanitize_settings( $updated_settings );
		update_option( self::OPTION_NAME, $sanitized );

		$notice_message = __( 'Meta authorisation complete. Access token has been stored.', 'nvoos-content-graph-ai-platform' );

		if ( $user_name ) {
			$notice_message = sprintf(
				/* translators: %s: Meta user name. */
				__( 'Meta authorisation complete for %s.', 'nvoos-content-graph-ai-platform' ),
				$user_name
			);
		}

		$this->add_settings_redirect_notice( 'meta_oauth_success', $notice_message, 'updated' );

		$this->redirect_to_settings_page();
	}

	/**
	 * Allow the Meta OAuth authorize endpoint host when using wp_safe_redirect().
	 *
	 * @param string[] $allowed_hosts Existing list of allowed hosts.
	 * @param string   $redirect      Requested redirect destination.
	 *
	 * @return string[]
	 */
	public function allow_meta_oauth_redirect_host( $allowed_hosts, $redirect = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by WordPress filter signature.
		/**
		 * Filter the Meta OAuth authorize endpoint.
		 *
		 * @since 2.1.0
		 *
		 * @param string $endpoint OAuth authorize endpoint.
		 */
		$authorize_endpoint = apply_filters( 'wp_mcp_ai_meta_oauth_authorize_endpoint', self::META_OAUTH_AUTHORIZE_ENDPOINT );
		$meta_host          = wp_parse_url( $authorize_endpoint, PHP_URL_HOST );

		if ( $meta_host ) {
			$allowed_hosts[] = $meta_host;
		}

		return array_values( array_unique( $allowed_hosts ) );
	}

	/**
	 * Build the transient key used to persist OAuth state.
	 *
	 * @param string $state OAuth state string.
	 * @return string
	 */
	private function get_meta_state_transient_key( $state ) {
		return 'wp_mcp_ai_meta_state_' . md5( (string) $state );
	}

	/**
	 * Return the OAuth redirect URI registered in the Meta OAuth application.
	 *
	 * @return string
	 */
	private function get_meta_oauth_redirect_uri() {
		return admin_url( 'admin-post.php?action=wp_mcp_ai_meta_oauth_callback' );
	}

	/**
	 * Retrieve the settings page URL.
	 *
	 * @return string
	 */
	private function get_settings_page_url() {
		// Redirect to the Tools tab > Connections subtab > Meta connection.
		return admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=meta' );
	}

	/**
	 * Redirect the current request back to the settings page and exit.
	 */
	private function redirect_to_settings_page() {
		$this->redirect_and_exit( $this->get_settings_page_url() );
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

	/**
	 * Handle disconnecting from Meta.
	 *
	 * Clears the Meta access token and connected user info from settings.
	 * App credentials (App ID, App Secret) are preserved for future reconnection.
	 *
	 * @since 2.1.0
	 */
	public function handle_meta_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'nvoos-content-graph-ai-platform' ) );
		}

		check_admin_referer( 'wp_mcp_ai_meta_disconnect' );

		$settings = self::settings();

		// Clear OAuth tokens (but keep App ID and App Secret for reconnection).
		unset( $settings['meta_access_token'] );
		unset( $settings['meta_connected_user_name'] );
		unset( $settings['meta_connected_user_id'] );

		update_option( 'wp_mcp_ai_settings', $settings );

		$this->redirect_and_exit(
			add_query_arg(
				array(
					'page'              => 'wp-mcp-ai-dashboard',
					'tab'               => 'tools',
					'subtab'            => 'connections',
					'connection'        => 'meta',
					'meta_disconnected' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
	}

	/**
	 * Redirect to a safe location and terminate the request.
	 *
	 * `exit` is only reached when the redirect actually took place. When a
	 * `wp_redirect` filter blocks the redirect (as the PHPUnit harness does
	 * to avoid header output), production keeps running and the test
	 * contract aborts the flow via `WPDieException` so callers do not
	 * cascade past a blocked redirect or kill the process with a bare
	 * `exit`.
	 *
	 * @param string $location Redirect target URL.
	 * @param int    $status   Optional HTTP status code. Default 302.
	 * @throws \WPDieException When running under PHPUnit and the redirect is blocked.
	 */
	private function redirect_and_exit( $location, $status = 302 ) {
		if ( wp_safe_redirect( $location, $status ) ) {
			exit;
		}

		if ( defined( 'WP_MCP_AI_TESTS_RUNNING' ) && WP_MCP_AI_TESTS_RUNNING && class_exists( 'WPDieException' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the exception message is not rendered anywhere; it only aborts the request flow under tests.
			throw new \WPDieException( is_string( $location ) ? $location : '' );
		}
	}
}

<?php
/**
 * QuickBooks OAuth connect flow.
 *
 * Covers the clean-path redirect URI Intuit requires, the single-use state
 * binding, the authorization-code exchange, encrypted token storage and the
 * rotating refresh token.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   GPL-3.0-or-later
 */

/**
 * Tests for the QuickBooks OAuth handler and REST callback.
 */
class Test_QuickBooks_OAuth_Connect extends WP_UnitTestCase {

	/**
	 * Administrator ID used as the current user.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Original permalink structure, restored in tearDown.
	 *
	 * @var string
	 */
	private $original_permalink_structure;

	/**
	 * Prepare a clean settings surface, pretty permalinks and an administrator.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_permalink_structure = (string) get_option( 'permalink_structure', '' );

		// The REST callback must be a clean path; plain permalinks produce a
		// ?rest_route= URL, which Intuit rejects.
		update_option( 'permalink_structure', '/%postname%/' );

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Remove mocks, tokens and notices between tests.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		delete_transient( 'wp_mcp_ai_quickbooks_oauth_notice' );
		update_option( 'permalink_structure', $this->original_permalink_structure );
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, WP_MCP_AI_Admin_Settings::get_default_settings() );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Store the Intuit app credentials the flow reads.
	 */
	private function store_app_credentials() {
		$settings                             = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['quickbooks_client_id']     = 'intuit-client-id';
		$settings['quickbooks_client_secret'] = 'intuit-client-secret';

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
	}

	/**
	 * Mock the Intuit token endpoint.
	 *
	 * @param array $body Response body.
	 * @param int   $code HTTP status code.
	 * @return array
	 */
	private function token_response( array $body, $code = 200 ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The registered redirect URI must be query-free.
	 */
	public function test_redirect_uri_is_a_clean_rest_path() {
		$redirect_uri = WP_MCP_AI_QuickBooks_OAuth_Handler::get_quickbooks_oauth_redirect_uri();

		$this->assertStringContainsString( '/wp-json/mcp-ai/v1/quickbooks/oauth/callback', $redirect_uri );
		$this->assertStringNotContainsString( '?', $redirect_uri );
		$this->assertTrue( WP_MCP_AI_QuickBooks_OAuth_Handler::has_clean_redirect_uri() );
	}

	/**
	 * Starting the flow builds an Intuit consent URL and binds one-time state.
	 */
	public function test_begin_authorization_builds_the_intuit_url() {
		$this->store_app_credentials();

		$return_url = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=qb_conn' );
		$handler    = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$start      = $handler->begin_authorization( $return_url );

		$this->assertIsArray( $start );
		$this->assertNotEmpty( $start['state'] );
		$this->assertSame( WP_MCP_AI_QuickBooks_OAuth_Handler::get_quickbooks_oauth_redirect_uri(), $start['redirect_uri'] );

		$parsed = wp_parse_url( $start['authorize_url'] );
		$this->assertSame( 'appcenter.intuit.com', $parsed['host'] );
		$this->assertSame( '/connect/oauth2', $parsed['path'] );

		parse_str( $parsed['query'], $query );

		$this->assertSame( 'intuit-client-id', $query['client_id'] );
		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'com.intuit.quickbooks.accounting', $query['scope'] );
		$this->assertSame( $start['redirect_uri'], $query['redirect_uri'] );
		$this->assertSame( $start['state'], $query['state'] );

		$state_data = get_transient( 'wp_mcp_ai_quickbooks_oauth_state_' . $start['state'] );
		$this->assertIsArray( $state_data );
		$this->assertSame( $this->admin_id, $state_data['user_id'] );
		$this->assertSame( $return_url, $state_data['return_url'] );
		$this->assertSame( $start['redirect_uri'], $state_data['redirect_uri'] );
	}

	/**
	 * The flow refuses to start without Intuit app credentials.
	 */
	public function test_begin_authorization_requires_app_credentials() {
		$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$start   = $handler->begin_authorization();

		$this->assertWPError( $start );
		$this->assertSame( 'wp_mcp_ai_quickbooks_missing_client', $start->get_error_code() );
	}

	/**
	 * A completed flow stores the grant and returns the operator to the
	 * connection screen.
	 */
	public function test_complete_authorization_stores_tokens_encrypted() {
		$this->store_app_credentials();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->assertStringContainsString( 'oauth.platform.intuit.com', $url );
				$this->assertSame( 'authorization_code', $args['body']['grant_type'] );
				$this->assertSame( 'auth-code', $args['body']['code'] );

				return $this->token_response(
					array(
						'access_token'  => 'access-token-abc',
						'refresh_token' => 'refresh-token-abc',
						'expires_in'    => 3600,
					)
				);
			},
			10,
			3
		);

		$return_url = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=qb_conn' );
		$handler    = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$start      = $handler->begin_authorization( $return_url );

		$result = $handler->complete_authorization( $start['state'], 'auth-code', '193514562011054' );

		$this->assertIsArray( $result );
		$this->assertSame( '193514562011054', $result['company_id'] );
		$this->assertSame( $return_url, $result['return_url'] );

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$this->assertTrue( (bool) $settings['quickbooks_connected'] );
		$this->assertSame( '193514562011054', $settings['quickbooks_company_id'] );
		$this->assertNotEmpty( $settings['quickbooks_token_expires_at'] );

		// Tokens round-trip through the accessor (encryption at rest).
		$this->assertSame( 'access-token-abc', WP_MCP_AI_QuickBooks_OAuth_Handler::get_access_token() );

		if ( class_exists( 'WP_MCP_AI_Encryption' ) ) {
			$raw_option = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );

			$this->assertStringStartsWith( 'v2:', (string) $raw_option['quickbooks_access_token'] );
			$this->assertStringStartsWith( 'v2:', (string) $raw_option['quickbooks_refresh_token'] );
		}
	}

	/**
	 * The state is bound to the user who started the flow.
	 */
	public function test_complete_authorization_rejects_a_state_from_another_user() {
		$this->store_app_credentials();

		$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$start   = $handler->begin_authorization();

		$other_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $other_admin );

		$result = $handler->complete_authorization( $start['state'], 'auth-code', '193514562011054' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_oauth_state_mismatch', $result->get_error_code() );

		// Consumed even on failure.
		$this->assertFalse( get_transient( 'wp_mcp_ai_quickbooks_oauth_state_' . $start['state'] ) );
	}

	/**
	 * The state cannot be replayed.
	 */
	public function test_complete_authorization_rejects_a_replayed_state() {
		$this->store_app_credentials();

		add_filter(
			'pre_http_request',
			function () {
				return $this->token_response(
					array(
						'access_token' => 'access-token-abc',
						'expires_in'   => 3600,
					)
				);
			}
		);

		$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$start   = $handler->begin_authorization();

		$this->assertIsArray( $handler->complete_authorization( $start['state'], 'auth-code', '193514562011054' ) );

		$replay = $handler->complete_authorization( $start['state'], 'auth-code', '193514562011054' );

		$this->assertWPError( $replay );
		$this->assertSame( 'wp_mcp_ai_quickbooks_oauth_state_mismatch', $replay->get_error_code() );
	}

	/**
	 * A denial at Intuit surfaces as a readable error.
	 */
	public function test_complete_authorization_surfaces_provider_errors() {
		$this->store_app_credentials();

		$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$start   = $handler->begin_authorization();

		$this->assertNotWPError( $start, is_wp_error( $start ) ? $start->get_error_code() . ': ' . $start->get_error_message() : '' );

		$result = $handler->complete_authorization( $start['state'], '', '', 'access_denied', 'The user denied access' );

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_quickbooks_oauth_error', $result->get_error_code() );
		$this->assertStringContainsString( 'The user denied access', $result->get_error_message() );
	}

	/**
	 * A refresh persists the rotated refresh token and keeps tokens encrypted.
	 */
	public function test_refresh_persists_the_rotated_refresh_token() {
		$this->store_app_credentials();

		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				if ( false !== strpos( $url, 'oauth.platform.intuit.com' ) && 'refresh_token' === $args['body']['grant_type'] ) {
					return $this->token_response(
						array(
							'access_token'  => 'refreshed-access',
							'refresh_token' => 'rotated-refresh',
							'expires_in'    => 3600,
						)
					);
				}

				return $this->token_response(
					array(
						'access_token'  => 'initial-access',
						'refresh_token' => 'initial-refresh',
						'expires_in'    => 3600,
					)
				);
			},
			10,
			3
		);

		$handler = new WP_MCP_AI_QuickBooks_OAuth_Handler();
		$start   = $handler->begin_authorization();

		$this->assertIsArray( $handler->complete_authorization( $start['state'], 'auth-code', '193514562011054' ) );

		// Force expiry and refresh.
		$settings                                = WP_MCP_AI_Admin_Settings::get_settings();
		$settings['quickbooks_token_expires_at'] = time() - 120;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
		WP_MCP_AI_Admin_Settings::reset_settings_cache();

		$this->assertSame( 'refreshed-access', WP_MCP_AI_QuickBooks_OAuth_Handler::get_access_token() );

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		if ( class_exists( 'WP_MCP_AI_Encryption' ) ) {
			$raw_option = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
			$this->assertStringStartsWith( 'v2:', (string) $raw_option['quickbooks_refresh_token'] );
		}

		// The settings layer decrypts sensitive keys on read.
		$this->assertSame( 'rotated-refresh', (string) $settings['quickbooks_refresh_token'] );
	}

	/**
	 * The REST callback route is registered with an administrator gate.
	 */
	public function test_callback_route_is_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp-ai/v1/quickbooks/oauth/callback', $routes );
		$this->assertTrue( WP_MCP_AI_QuickBooks_OAuth_Controller::can_manage_options() );
	}
}

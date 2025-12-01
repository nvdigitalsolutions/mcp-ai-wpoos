<?php
/**
 * Tests for the Auth0 GitHub bridge integration.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Auth0_Github_Integration_Test extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		WP_MCP_AI_Integration_Auth0_Github::reset_cache();
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Integration_Auth0_Github::init();
	}

	protected function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Integration_Auth0_Github::reset_cache();

		parent::tearDown();
	}

	/**
	 * Ensure the payload filter exposes helper claims for GitHub subjects.
	 */
	public function test_payload_filter_exposes_github_identifier() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mock' );
		$request->set_header( 'Authorization', 'Bearer sample-token' );

		$payload  = array( 'sub' => 'github|12345' );
		$filtered = apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );

		$this->assertArrayHasKey( 'github_user_id', $filtered );
		$this->assertSame( '12345', $filtered['github_user_id'] );
	}

	/**
	 * Mapping should resolve to an existing user when metadata is present.
	 */
	public function test_mapping_uses_existing_user_metadata() {
		$this->enable_bridge();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, WP_MCP_AI_Integration_Auth0_Github::META_AUTH0_SUBJECT, 'github|999' );
		update_user_meta( $user_id, WP_MCP_AI_Integration_Auth0_Github::META_GITHUB_LOGIN, 'octocat' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer existing-user-token' );

		$payload = array(
			'sub'  => 'github|999',
			'name' => 'Octo Cat',
		);

		apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );
		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );

		$this->assertSame( $user_id, $mapped );

		$user = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'Octo Cat', $user->display_name );
	}

	/**
	 * Mapping should create a new WordPress user using Auth0 userinfo details.
	 */
	public function test_mapping_creates_user_from_userinfo() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer remote-user-token' );

		$recorded_urls = array();
		$callback      = function ( $preempt, $args, $url ) use ( &$recorded_urls ) {
			$recorded_urls[] = $url;

			if ( false !== strpos( $url, '/userinfo' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'sub'      => 'github|12345',
							'email'    => 'octocat@example.com',
							'nickname' => 'octocat',
							'name'     => 'The Octocat',
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			}

			return false;
		};

		add_filter( 'pre_http_request', $callback, 10, 3 );

		$payload = array( 'sub' => 'github|12345' );
		apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );
		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertIsInt( $mapped );
		$this->assertNotEmpty( $recorded_urls );
		$this->assertSame( 'https://tenant.example.auth0.com/userinfo', $recorded_urls[0] );

		$user = get_user_by( 'id', $mapped );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'octocat@example.com', $user->user_email );
		$this->assertSame( 'The Octocat', $user->display_name );
		$this->assertSame( '12345', get_user_meta( $mapped, WP_MCP_AI_Integration_Auth0_Github::META_GITHUB_ID, true ) );
		$this->assertSame( 'octocat', get_user_meta( $mapped, WP_MCP_AI_Integration_Auth0_Github::META_GITHUB_LOGIN, true ) );
	}

	/**
	 * Mapping should return an error when the profile cannot be resolved and no email is available.
	 */
	public function test_mapping_returns_error_when_profile_missing_and_management_disabled() {
		$this->enable_bridge(
			array(
				'auth0_management_client_id'     => '',
				'auth0_management_client_secret' => '',
			)
		);

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer remote-user-token' );

		$callback = function () {
			return new WP_Error( 'http_request_failed', 'Simulated failure' );
		};

		add_filter( 'pre_http_request', $callback, 10 );

		$payload = array( 'sub' => 'github|777' );
		apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );
		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertInstanceOf( WP_Error::class, $mapped );
		$this->assertSame( 'wp_mcp_ai_auth0_github_missing_credentials', $mapped->get_error_code() );
	}

	/**
	 * Configure the Auth0 GitHub bridge with optional overrides.
	 *
	 * @param array $overrides Settings overrides.
	 */
	protected function enable_bridge( array $overrides = array() ) {
		$settings = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings = array_merge(
			$settings,
			array(
				'auth0_domain'                   => 'tenant.example.auth0.com',
				'enable_auth0_github_bridge'     => true,
				'auth0_management_client_id'     => 'client_id',
				'auth0_management_client_secret' => 'client_secret',
			),
			$overrides
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}
}

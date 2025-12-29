<?php
/**
 * Tests for the WordPress.com/Gravatar bridge integration.
 */
class WP_MCP_AI_WordPress_Gravatar_Integration_Test extends WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		WP_MCP_AI_Integration_WordPress_Gravatar::reset_cache();
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Integration_WordPress_Gravatar::init();
	}

	protected function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		WP_MCP_AI_Integration_WordPress_Gravatar::reset_cache();

		parent::tearDown();
	}

	/**
	 * Helper to enable the WordPress/Gravatar bridge.
	 */
	protected function enable_bridge() {
		$settings = array(
			'enable_wordpress_gravatar_bridge'     => true,
			'wordpress_gravatar_userinfo_endpoint' => 'https://public-api.wordpress.com/oauth2/v1/userinfo',
		);

		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );
	}

	/**
	 * Ensure the payload filter exposes helper claims for WordPress.com subjects.
	 */
	public function test_payload_filter_exposes_wordpress_identifier() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mock' );
		$request->set_header( 'Authorization', 'Bearer sample-token' );

		$payload  = array( 'sub' => 'wordpress.com|12345' );
		$filtered = apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );

		$this->assertArrayHasKey( 'wordpress_user_id', $filtered );
		$this->assertSame( '12345', $filtered['wordpress_user_id'] );
	}

	/**
	 * Ensure the payload filter exposes helper claims for Gravatar subjects.
	 */
	public function test_payload_filter_exposes_gravatar_identifier() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mock' );
		$request->set_header( 'Authorization', 'Bearer sample-token' );

		$payload  = array(
			'sub'   => 'gravatar|abc123',
			'email' => 'test@example.com',
		);
		$filtered = apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );

		$this->assertArrayHasKey( 'gravatar_hash', $filtered );
		$this->assertNotEmpty( $filtered['gravatar_hash'] );
	}

	/**
	 * Mapping should resolve to an existing user when metadata is present.
	 */
	public function test_mapping_uses_existing_user_metadata() {
		$this->enable_bridge();

		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user_id, WP_MCP_AI_Integration_WordPress_Gravatar::META_SUBJECT, 'wordpress.com|999' );
		update_user_meta( $user_id, WP_MCP_AI_Integration_WordPress_Gravatar::META_WORDPRESS_ID, '999' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer existing-user-token' );

		$payload = array(
			'sub'  => 'wordpress.com|999',
			'name' => 'Test User',
		);

		apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );
		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );

		$this->assertSame( $user_id, $mapped );

		$user = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'Test User', $user->display_name );
	}

	/**
	 * Mapping should create a new WordPress user using userinfo details.
	 */
	public function test_mapping_creates_user_from_userinfo() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer remote-user-token' );

		$recorded_urls = array();
		$callback      = function ( $preempt, $args, $url ) use ( &$recorded_urls ) {
			$recorded_urls[] = $url;

			if ( false !== strpos( $url, 'userinfo' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'sub'      => 'wordpress.com|12345',
							'email'    => 'wpuser@example.com',
							'username' => 'wpuser',
							'name'     => 'WordPress User',
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

		$payload = array(
			'sub'  => 'wordpress.com|12345',
			'name' => 'WordPress User',
		);

		apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );
		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertIsInt( $mapped );
		$this->assertGreaterThan( 0, $mapped );

		$user = get_user_by( 'id', $mapped );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'wpuser@example.com', $user->user_email );
		$this->assertSame( 'WordPress User', $user->display_name );

		$stored_subject = get_user_meta( $mapped, WP_MCP_AI_Integration_WordPress_Gravatar::META_SUBJECT, true );
		$this->assertSame( 'wordpress.com|12345', $stored_subject );
	}

	/**
	 * Integration should ignore non-WordPress/Gravatar subjects.
	 */
	public function test_integration_ignores_non_wordpress_subjects() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer some-token' );

		$payload = array(
			'sub'   => 'github|12345',
			'email' => 'github@example.com',
		);

		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );

		$this->assertNull( $mapped );
	}

	/**
	 * Integration should return error if email is missing during user creation.
	 */
	public function test_mapping_returns_error_without_email() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer no-email-token' );

		$callback = function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, 'userinfo' ) ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'sub'  => 'wordpress.com|67890',
							'name' => 'No Email User',
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

		$payload = array( 'sub' => 'wordpress.com|67890' );

		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );

		remove_filter( 'pre_http_request', $callback, 10 );

		$this->assertInstanceOf( WP_Error::class, $mapped );
		$this->assertSame( 'wp_mcp_ai_wordpress_gravatar_missing_email', $mapped->get_error_code() );
	}

	/**
	 * Gravatar hash should be generated from email when not provided.
	 */
	public function test_gravatar_hash_generated_from_email() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mock' );
		$request->set_header( 'Authorization', 'Bearer sample-token' );

		$email   = 'test@example.com';
		$payload = array(
			'sub'   => 'gravatar|abc',
			'email' => $email,
		);

		$filtered = apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );

		$expected_hash = md5( strtolower( trim( $email ) ) );
		$this->assertArrayHasKey( 'gravatar_hash', $filtered );
		$this->assertSame( $expected_hash, $filtered['gravatar_hash'] );
	}

	/**
	 * Avatar URL should be enriched into the payload.
	 */
	public function test_avatar_url_enriched_in_payload() {
		$this->enable_bridge();

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mock' );
		$request->set_header( 'Authorization', 'Bearer sample-token' );

		$avatar_url = 'https://example.com/avatar.jpg';
		$payload    = array(
			'sub'    => 'wordpress.com|123',
			'avatar' => $avatar_url,
		);

		$filtered = apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );

		$this->assertArrayHasKey( 'picture', $filtered );
		$this->assertSame( $avatar_url, $filtered['picture'] );
	}

	/**
	 * User metadata should be synchronized on existing user.
	 */
	public function test_user_metadata_synchronization() {
		$this->enable_bridge();

		$user_id = self::factory()->user->create(
			array(
				'role'         => 'subscriber',
				'display_name' => 'Old Name',
			)
		);
		update_user_meta( $user_id, WP_MCP_AI_Integration_WordPress_Gravatar::META_SUBJECT, 'gravatar|test123' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'Authorization', 'Bearer sync-token' );

		$payload = array(
			'sub'           => 'gravatar|test123',
			'email'         => 'sync@example.com',
			'name'          => 'Updated Name',
			'gravatar_hash' => 'newhash123',
		);

		apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );
		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );

		$this->assertSame( $user_id, $mapped );

		$user = get_user_by( 'id', $user_id );
		$this->assertSame( 'Updated Name', $user->display_name );

		$stored_hash = get_user_meta( $user_id, WP_MCP_AI_Integration_WordPress_Gravatar::META_GRAVATAR_HASH, true );
		$this->assertSame( 'newhash123', $stored_hash );
	}

	/**
	 * Integration should be disabled when bridge setting is off.
	 */
	public function test_integration_disabled_when_setting_off() {
		// Don't enable the bridge.
		$settings = array(
			'enable_wordpress_gravatar_bridge' => false,
		);
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/mock' );
		$request->set_header( 'Authorization', 'Bearer sample-token' );

		$payload  = array( 'sub' => 'wordpress.com|12345' );
		$filtered = apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request );

		// Should not enrich payload when disabled.
		$this->assertArrayNotHasKey( 'wordpress_user_id', $filtered );

		$mapped = apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', null, $payload, $request );
		$this->assertNull( $mapped );
	}
}

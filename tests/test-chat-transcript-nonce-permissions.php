<?php
/**
 * Tests for chat transcript permission checks and nonce validation.
 *
 * @package WP_MCP_AI
 */

class WP_MCP_AI_Chat_Transcript_Nonce_Permissions_Test extends WP_UnitTestCase {
	/**
	 * User ID for authenticated requests.
	 *
	 * @var int
	 */
	protected $user_id;

	/**
	 * Assistant post ID used in requests.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	public function setUp(): void {
		parent::setUp();

		if ( function_exists( 'wp_mcp_ai_bootstrap' ) ) {
			wp_mcp_ai_bootstrap();
		}

		$this->user_id = self::factory()->user->create(
			array(
				'role' => 'subscriber',
			)
		);

		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Nonce Test Assistant',
			)
		);

		update_post_meta( $this->assistant_id, 'wp_mcp_ai_model', 'gpt-4' );
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_provider', 'openai' );

		// Get the REST controller instance.
		$container             = wp_mcp_ai_container();
		$this->rest_controller = $container->get( 'rest' );

		rest_get_server();
		do_action( 'init' );
	}

	public function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that invalid nonce returns 401 status code (not 403).
	 */
	public function test_invalid_nonce_returns_401_status() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-123' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		// Set an invalid nonce.
		$request->set_header( 'X-WP-Nonce', 'invalid_nonce_12345' );

		$result = $this->rest_controller->chat_transcripts_permissions_check( $request );

		$this->assertTrue( is_wp_error( $result ), 'Invalid nonce should return WP_Error' );
		$this->assertEquals( 'rest_invalid_nonce', $result->get_error_code(), 'Error code should be rest_invalid_nonce' );

		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data, 'Error data should be an array' );
		$this->assertArrayHasKey( 'status', $error_data, 'Error data should have status key' );

		// The key assertion: status should be 401 (rest_authorization_required_code()), not 403.
		$expected_status = rest_authorization_required_code();
		$this->assertEquals( $expected_status, $error_data['status'], 'Invalid nonce should return 401 status code' );
	}

	/**
	 * Test that invalid nonce error includes refresh_nonce action.
	 */
	public function test_invalid_nonce_includes_refresh_action() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-123' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		// Set an invalid nonce.
		$request->set_header( 'X-WP-Nonce', 'invalid_nonce_12345' );

		$result     = $this->rest_controller->chat_transcripts_permissions_check( $request );
		$error_data = $result->get_error_data();

		$this->assertArrayHasKey( 'actions', $error_data, 'Error data should include actions array' );
		$this->assertIsArray( $error_data['actions'], 'Actions should be an array' );
		$this->assertArrayHasKey( 'refresh_nonce', $error_data['actions'], 'Actions should include refresh_nonce' );
		$this->assertNotEmpty( $error_data['actions']['refresh_nonce'], 'refresh_nonce action should have a message' );
	}

	/**
	 * Test that valid nonce allows access for user's own transcripts.
	 */
	public function test_valid_nonce_allows_own_transcripts() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-123' );
		$request->set_param( 'user_id', $this->user_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		// Set a valid nonce.
		$nonce = wp_create_nonce( 'wp_rest' );
		$request->set_header( 'X-WP-Nonce', $nonce );

		$result = $this->rest_controller->chat_transcripts_permissions_check( $request );

		$this->assertTrue( $result, 'Valid nonce should allow access to own transcripts' );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Should not return WP_Error for valid nonce' );
	}

	/**
	 * Test that logged-in user without nonce gets 401 error.
	 */
	public function test_logged_in_user_without_nonce_gets_401() {
		wp_set_current_user( $this->user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'assistant_id', $this->assistant_id );
		$request->set_param( 'session_key', 'test-session-123' );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Hello',
				),
			)
		);

		// Don't set any nonce header.
		$result = $this->rest_controller->chat_transcripts_permissions_check( $request );

		$this->assertTrue( is_wp_error( $result ), 'Missing nonce should return WP_Error' );
		$this->assertEquals( 'wp_mcp_ai_missing_nonce', $result->get_error_code(), 'Error code should be wp_mcp_ai_missing_nonce' );

		$error_data = $result->get_error_data();
		$this->assertEquals( 401, $error_data['status'], 'Missing nonce should return 401 status code' );
	}

	/**
	 * Test that admin user can access any transcripts.
	 */
	public function test_admin_user_can_access_any_transcripts() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $this->user_id ); // Different user.

		// Set a valid nonce.
		$nonce = wp_create_nonce( 'wp_rest' );
		$request->set_header( 'X-WP-Nonce', $nonce );

		$result = $this->rest_controller->chat_transcripts_permissions_check( $request );

		$this->assertTrue( $result, 'Admin should be able to access any transcripts' );
	}

	/**
	 * Test that user cannot access another user's transcripts.
	 */
	public function test_user_cannot_access_other_user_transcripts() {
		wp_set_current_user( $this->user_id );

		$other_user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat-transcripts' );
		$request->set_param( 'user_id', $other_user_id ); // Different user.

		// Set a valid nonce.
		$nonce = wp_create_nonce( 'wp_rest' );
		$request->set_header( 'X-WP-Nonce', $nonce );

		$result = $this->rest_controller->chat_transcripts_permissions_check( $request );

		$this->assertTrue( is_wp_error( $result ), 'User should not access other user transcripts' );
		$this->assertEquals( 'wp_mcp_ai_forbidden', $result->get_error_code(), 'Error code should be wp_mcp_ai_forbidden' );
		$error_data = $result->get_error_data();
		$this->assertEquals( 403, $error_data['status'], 'Should return 403 for forbidden access' );
	}
}

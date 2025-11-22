<?php
/**
 * Tests for REST API Authenticator
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test REST API Authenticator functionality.
 *
 * @group rest
 * @group authenticator
 * @group auth
 */
class Test_REST_Authenticator extends WP_UnitTestCase {

	/**
	 * Authenticator instance.
	 *
	 * @var WP_MCP_AI_REST_Authenticator
	 */
	protected $authenticator;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Load the authenticator class.
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-authenticator.php';

		$this->authenticator = new WP_MCP_AI_REST_Authenticator();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * Test that authenticator instantiates correctly.
	 */
	public function test_authenticator_instantiation() {
		$this->assertInstanceOf( 'WP_MCP_AI_REST_Authenticator', $this->authenticator );
	}

	/**
	 * Test reset_auth_context initializes correctly.
	 */
	public function test_reset_auth_context() {
		$this->authenticator->reset_auth_context();

		$context = $this->authenticator->get_auth_context();

		$this->assertIsArray( $context );
		$this->assertArrayHasKey( 'user_id', $context );
		$this->assertArrayHasKey( 'token_authenticated', $context );
		$this->assertArrayHasKey( 'token_type', $context );
		$this->assertArrayHasKey( 'token_context', $context );
		$this->assertArrayHasKey( 'assistant_id', $context );

		// Check default values.
		$this->assertFalse( $context['token_authenticated'] );
		$this->assertNull( $context['token_type'] );
		$this->assertIsArray( $context['token_context'] );
		$this->assertEquals( 0, $context['assistant_id'] );
	}

	/**
	 * Test mark_token_authenticated updates context.
	 */
	public function test_mark_token_authenticated() {
		$this->authenticator->reset_auth_context();

		$this->authenticator->mark_token_authenticated(
			'local_token',
			array(
				'credential'   => array( 'id' => 123 ),
				'assistant_id' => 456,
			)
		);

		$context = $this->authenticator->get_auth_context();

		$this->assertTrue( $context['token_authenticated'] );
		$this->assertEquals( 'local_token', $context['token_type'] );
		$this->assertEquals( 456, $context['assistant_id'] );
		$this->assertArrayHasKey( 'credential', $context['token_context'] );
	}

	/**
	 * Test mark_token_authenticated with user_id sets user.
	 */
	public function test_mark_token_authenticated_with_user_id() {
		$user_id = $this->factory->user->create();

		$this->authenticator->reset_auth_context();

		$this->authenticator->mark_token_authenticated(
			'bearer',
			array(
				'user_id' => $user_id,
			)
		);

		$context = $this->authenticator->get_auth_context();

		$this->assertEquals( $user_id, $context['user_id'] );
		$this->assertEquals( $user_id, get_current_user_id() );
	}

	/**
	 * Test set_authenticated_user_id sets the user.
	 */
	public function test_set_authenticated_user_id() {
		$user_id = $this->factory->user->create();

		$this->authenticator->reset_auth_context();
		$this->authenticator->set_authenticated_user_id( $user_id );

		$context = $this->authenticator->get_auth_context();

		$this->assertEquals( $user_id, $context['user_id'] );
		$this->assertEquals( $user_id, get_current_user_id() );
	}

	/**
	 * Test get_auth_context returns current state.
	 */
	public function test_get_auth_context() {
		$this->authenticator->reset_auth_context();

		$context = $this->authenticator->get_auth_context();

		$this->assertIsArray( $context );
		$this->assertArrayHasKey( 'user_id', $context );
	}

	/**
	 * Test validate_mesh_key with missing key.
	 */
	public function test_validate_mesh_key_missing() {
		$result = $this->authenticator->validate_mesh_key( '' );

		$this->assertWPError( $result );
		$this->assertEquals( 'wp_mcp_ai_missing_mesh_key', $result->get_error_code() );
	}

	/**
	 * Test insufficient_permissions_error returns proper error.
	 */
	public function test_insufficient_permissions_error() {
		$error = $this->authenticator->insufficient_permissions_error( 'edit_posts' );

		$this->assertWPError( $error );
		$this->assertEquals( 'wp_mcp_ai_insufficient_permissions', $error->get_error_code() );
		$this->assertEquals( 403, $error->get_error_data()['status'] );
		$this->assertStringContainsString( 'edit_posts', $error->get_error_message() );
	}

	/**
	 * Test insufficient_permissions_error with custom capability.
	 */
	public function test_insufficient_permissions_error_custom_capability() {
		$error = $this->authenticator->insufficient_permissions_error( 'manage_options' );

		$this->assertWPError( $error );
		$this->assertStringContainsString( 'manage_options', $error->get_error_message() );
	}

	/**
	 * Test extract_guest_token from header.
	 */
	public function test_extract_guest_token_from_header() {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WP-MCP-AI-Guest', 'test-guest-token-123' );

		$token = $this->authenticator->extract_guest_token( $request );

		$this->assertEquals( 'test-guest-token-123', $token );
	}

	/**
	 * Test extract_guest_token from param.
	 */
	public function test_extract_guest_token_from_param() {
		$request = new WP_REST_Request();
		$request->set_param( 'guest_token', 'test-guest-token-456' );

		$token = $this->authenticator->extract_guest_token( $request );

		$this->assertEquals( 'test-guest-token-456', $token );
	}

	/**
	 * Test extract_guest_token with no token returns empty string.
	 */
	public function test_extract_guest_token_empty() {
		$request = new WP_REST_Request();

		$token = $this->authenticator->extract_guest_token( $request );

		$this->assertEquals( '', $token );
	}

	/**
	 * Test extract_guest_token prefers header over param.
	 */
	public function test_extract_guest_token_header_priority() {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WP-MCP-AI-Guest', 'header-token' );
		$request->set_param( 'guest_token', 'param-token' );

		$token = $this->authenticator->extract_guest_token( $request );

		$this->assertEquals( 'header-token', $token );
	}

	/**
	 * Test validate_local_token with non-token format returns null.
	 */
	public function test_validate_local_token_invalid_format() {
		$request = new WP_REST_Request();

		$result = $this->authenticator->validate_local_token( 'not-a-token', $request, 0 );

		$this->assertNull( $result );
	}

	/**
	 * Test validate_bearer_token with pre-filter override.
	 */
	public function test_validate_bearer_token_pre_filter() {
		// Add filter to short-circuit validation.
		add_filter(
			'wp_mcp_ai_pre_validate_bearer_token',
			function ( $pre, $token, $request ) {
				return true;
			},
			10,
			3
		);

		$request = new WP_REST_Request();
		$result  = $this->authenticator->validate_bearer_token( 'fake-token', $request );

		$this->assertTrue( $result );

		// Cleanup.
		remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
	}

	/**
	 * Test validate_bearer_token with WP_Error pre-filter.
	 */
	public function test_validate_bearer_token_pre_filter_error() {
		// Add filter to return error.
		add_filter(
			'wp_mcp_ai_pre_validate_bearer_token',
			function ( $pre, $token, $request ) {
				return new WP_Error( 'test_error', 'Test error message' );
			},
			10,
			3
		);

		$request = new WP_REST_Request();
		$result  = $this->authenticator->validate_bearer_token( 'fake-token', $request );

		$this->assertWPError( $result );
		$this->assertEquals( 'test_error', $result->get_error_code() );

		// Cleanup.
		remove_all_filters( 'wp_mcp_ai_pre_validate_bearer_token' );
	}

	/**
	 * Test that auth context persists across method calls.
	 */
	public function test_auth_context_persistence() {
		$this->authenticator->reset_auth_context();

		$this->authenticator->mark_token_authenticated( 'test_type', array( 'test' => 'data' ) );

		$context1 = $this->authenticator->get_auth_context();
		$context2 = $this->authenticator->get_auth_context();

		$this->assertEquals( $context1, $context2 );
		$this->assertTrue( $context1['token_authenticated'] );
	}

	/**
	 * Test that reset clears previous auth context.
	 */
	public function test_reset_clears_context() {
		// Set some auth context.
		$this->authenticator->mark_token_authenticated( 'test', array( 'data' => 123 ) );

		$context_before = $this->authenticator->get_auth_context();
		$this->assertTrue( $context_before['token_authenticated'] );

		// Reset.
		$this->authenticator->reset_auth_context();

		$context_after = $this->authenticator->get_auth_context();
		$this->assertFalse( $context_after['token_authenticated'] );
		$this->assertNull( $context_after['token_type'] );
	}

	/**
	 * Test mark_token_authenticated with assistant_id from credential.
	 */
	public function test_mark_token_authenticated_assistant_from_credential() {
		$this->authenticator->reset_auth_context();

		$this->authenticator->mark_token_authenticated(
			'local_token',
			array(
				'credential' => array( 'assistant_id' => 789 ),
			)
		);

		$context = $this->authenticator->get_auth_context();

		$this->assertEquals( 789, $context['assistant_id'] );
	}

	/**
	 * Test mark_token_authenticated prefers direct assistant_id over credential.
	 */
	public function test_mark_token_authenticated_assistant_priority() {
		$this->authenticator->reset_auth_context();

		$this->authenticator->mark_token_authenticated(
			'local_token',
			array(
				'assistant_id' => 111,
				'credential'   => array( 'assistant_id' => 222 ),
			)
		);

		$context = $this->authenticator->get_auth_context();

		// Direct assistant_id should take priority.
		$this->assertEquals( 111, $context['assistant_id'] );
	}

	/**
	 * Test that set_authenticated_user_id with zero does not change global user.
	 */
	public function test_set_authenticated_user_id_zero() {
		$original_user = get_current_user_id();

		$this->authenticator->set_authenticated_user_id( 0 );

		$context = $this->authenticator->get_auth_context();
		$this->assertEquals( 0, $context['user_id'] );

		// Global user should not have changed.
		$this->assertEquals( $original_user, get_current_user_id() );
	}

	/**
	 * Test extract_guest_token trims whitespace.
	 */
	public function test_extract_guest_token_trims_whitespace() {
		$request = new WP_REST_Request();
		$request->set_header( 'X-WP-MCP-AI-Guest', '  test-token-with-spaces  ' );

		$token = $this->authenticator->extract_guest_token( $request );

		$this->assertEquals( 'test-token-with-spaces', $token );
	}

	/**
	 * Test authenticate method with valid nonce for authenticated endpoint.
	 */
	public function test_authenticate_with_valid_nonce() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->authenticator->authenticate( $request, 'edit_posts' );

		$this->assertIsArray( $result );
		$this->assertEquals( $user_id, $result['user_id'] );
	}

	/**
	 * Test authenticate method with invalid nonce.
	 */
	public function test_authenticate_with_invalid_nonce() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_header( 'X-WP-Nonce', 'invalid_nonce' );

		$result = $this->authenticator->authenticate( $request, 'edit_posts' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'rest_invalid_nonce', $result->get_error_code() );
	}

	/**
	 * Test authenticate method with missing nonce for authenticated endpoint.
	 */
	public function test_authenticate_with_missing_nonce() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );

		$result = $this->authenticator->authenticate( $request, 'edit_posts' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'wp_mcp_ai_missing_credentials', $result->get_error_code() );
	}

	/**
	 * Test authenticate method for public endpoint without nonce.
	 */
	public function test_authenticate_public_endpoint_without_nonce() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );

		$result = $this->authenticator->authenticate( $request, 'public' );

		$this->assertIsArray( $result );
		$this->assertEquals( 0, $result['user_id'] );
	}

	/**
	 * Test authenticate method for public endpoint with valid nonce.
	 */
	public function test_authenticate_public_endpoint_with_nonce() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->authenticator->authenticate( $request, 'public' );

		$this->assertIsArray( $result );
		$this->assertEquals( $user_id, $result['user_id'] );
	}

	/**
	 * Test authenticate method with insufficient permissions.
	 */
	public function test_authenticate_with_insufficient_permissions() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$result = $this->authenticator->authenticate( $request, 'manage_options' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'rest_insufficient_permissions', $result->get_error_code() );
	}
}

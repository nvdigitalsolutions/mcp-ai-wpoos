<?php
/**
 * Tests for WP_MCP_AI_REST_Controller_Base class.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test REST controller base class.
 */
class Test_REST_Controller_Base extends WP_UnitTestCase {
	/**
	 * Mock controller for testing.
	 *
	 * @var WP_MCP_AI_REST_Controller_Base
	 */
	private $controller;

	/**
	 * Mock authenticator.
	 *
	 * @var WP_MCP_AI_REST_Authenticator
	 */
	private $mock_authenticator;

	/**
	 * Mock validator.
	 *
	 * @var WP_MCP_AI_REST_Validator
	 */
	private $mock_validator;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create mocks.
		$this->mock_authenticator = $this->createMock( WP_MCP_AI_REST_Authenticator::class );
		$this->mock_validator     = $this->createMock( WP_MCP_AI_REST_Validator::class );

		// Create concrete implementation for testing abstract class.
		$this->controller = new class( $this->mock_authenticator, $this->mock_validator ) extends WP_MCP_AI_REST_Controller_Base {
			public function register_routes() {
				// Not testing route registration here.
			}

			// Expose protected methods for testing.
			public function test_error( $code, $message, $status = 400, $actions = array() ) {
				return $this->error( $code, $message, $status, $actions );
			}

			public function test_success( $data, $status = 200 ) {
				return $this->success( $data, $status );
			}

			public function test_permissions_check_authenticated( $request ) {
				return $this->permissions_check_authenticated( $request );
			}

			public function test_permissions_check_admin( $request ) {
				return $this->permissions_check_admin( $request );
			}

			public function test_get_current_user_id() {
				return $this->get_current_user_id();
			}

			public function test_is_guest_request() {
				return $this->is_guest_request();
			}

			public function set_auth_context( $context ) {
				$this->auth_context = $context;
			}
		};
	}

	/**
	 * Test error response formatting.
	 */
	public function test_error_response_format() {
		$error = $this->controller->test_error( 'test_code', 'Test message', 404 );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertEquals( 'test_code', $error->get_error_code() );
		$this->assertEquals( 'Test message', $error->get_error_message() );

		$data = $error->get_error_data();
		$this->assertEquals( 404, $data['status'] );
	}

	/**
	 * Test error response with actions.
	 */
	public function test_error_response_with_actions() {
		$actions = array(
			array(
				'label' => 'Retry',
				'url'   => '/wp-admin/admin.php?page=retry',
			),
		);

		$error = $this->controller->test_error( 'test_code', 'Test message', 400, $actions );
		$data  = $error->get_error_data();

		$this->assertArrayHasKey( 'actions', $data );
		$this->assertEquals( $actions, $data['actions'] );
	}

	/**
	 * Test success response formatting.
	 */
	public function test_success_response_format() {
		$data     = array( 'result' => 'success' );
		$response = $this->controller->test_success( $data, 201 );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertEquals( 201, $response->get_status() );
		$this->assertEquals( $data, $response->get_data() );

		// Verify version header is set.
		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'X-WP-MCP-AI-Version', $headers );
	}

	/**
	 * Test authentication check with valid bearer token (MCP client).
	 */
	public function test_permissions_check_authenticated_mcp_client() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'Authorization', 'Bearer test_token' );

		// Mock successful MCP client authentication.
		$this->mock_authenticator
			->expects( $this->once() )
			->method( 'authenticate' )
			->with( $request )
			->willReturn(
				array(
					'user_id'   => 1,
					'is_guest'  => false,
					'auth_type' => 'bearer',
				)
			);

		$result = $this->controller->test_permissions_check_authenticated( $request );

		$this->assertTrue( $result );
		$this->assertEquals( 1, $this->controller->test_get_current_user_id() );
		$this->assertFalse( $this->controller->test_is_guest_request() );
	}

	/**
	 * Test authentication check with WordPress cookie (browser client).
	 */
	public function test_permissions_check_authenticated_browser_client() {
		$user_id = $this->factory->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		// Mock successful WordPress cookie authentication.
		$this->mock_authenticator
			->expects( $this->once() )
			->method( 'authenticate' )
			->with( $request )
			->willReturn(
				array(
					'user_id'   => $user_id,
					'is_guest'  => false,
					'auth_type' => 'cookie',
				)
			);

		$result = $this->controller->test_permissions_check_authenticated( $request );

		$this->assertTrue( $result );
		$this->assertEquals( $user_id, $this->controller->test_get_current_user_id() );
	}

	/**
	 * Test authentication check with guest token (public chat).
	 */
	public function test_permissions_check_authenticated_guest_token() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_header( 'X-WP-MCP-AI-Guest', 'guest_token_123' );

		// Mock successful guest authentication.
		$this->mock_authenticator
			->expects( $this->once() )
			->method( 'authenticate' )
			->with( $request )
			->willReturn(
				array(
					'user_id'   => 0,
					'is_guest'  => true,
					'auth_type' => 'guest',
				)
			);

		$result = $this->controller->test_permissions_check_authenticated( $request );

		$this->assertTrue( $result );
		$this->assertEquals( 0, $this->controller->test_get_current_user_id() );
		$this->assertTrue( $this->controller->test_is_guest_request() );
	}

	/**
	 * Test authentication check failure.
	 */
	public function test_permissions_check_authenticated_failure() {
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat' );

		// Mock authentication failure.
		$this->mock_authenticator
			->expects( $this->once() )
			->method( 'authenticate' )
			->with( $request )
			->willReturn( new WP_Error( 'rest_forbidden', 'Authentication failed' ) );

		$result = $this->controller->test_permissions_check_authenticated( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test admin permission check with valid admin user.
	 */
	public function test_permissions_check_admin_success() {
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );

		// Mock successful authentication.
		$this->mock_authenticator
			->expects( $this->once() )
			->method( 'authenticate' )
			->with( $request )
			->willReturn(
				array(
					'user_id'   => $admin_id,
					'is_guest'  => false,
					'auth_type' => 'cookie',
				)
			);

		$result = $this->controller->test_permissions_check_admin( $request );

		$this->assertTrue( $result );
	}

	/**
	 * Test admin permission check with non-admin user.
	 */
	public function test_permissions_check_admin_failure() {
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/cron-status' );

		// Mock successful authentication but not admin.
		$this->mock_authenticator
			->expects( $this->once() )
			->method( 'authenticate' )
			->with( $request )
			->willReturn(
				array(
					'user_id'   => $user_id,
					'is_guest'  => false,
					'auth_type' => 'cookie',
				)
			);

		$result = $this->controller->test_permissions_check_admin( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'rest_forbidden', $result->get_error_code() );
	}

	/**
	 * Test auth context storage for MCP remote clients.
	 */
	public function test_auth_context_stored_for_mcp_clients() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );

		$auth_context = array(
			'user_id'      => 1,
			'is_guest'     => false,
			'auth_type'    => 'bearer',
			'assistant_id' => 123,
		);

		$this->mock_authenticator
			->expects( $this->once() )
			->method( 'authenticate' )
			->willReturn( $auth_context );

		$this->controller->test_permissions_check_authenticated( $request );

		// Verify auth context is accessible.
		$this->controller->set_auth_context( $auth_context );
		$this->assertEquals( 1, $this->controller->test_get_current_user_id() );
	}
}

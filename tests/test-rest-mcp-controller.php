<?php
/**
 * Tests for WP_MCP_AI_REST_MCP_Controller class.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test MCP Protocol controller class.
 */
class Test_REST_MCP_Controller extends WP_UnitTestCase {
	/**
	 * MCP controller instance.
	 *
	 * @var WP_MCP_AI_REST_MCP_Controller
	 */
	private $controller;

	/**
	 * Mock main REST controller.
	 *
	 * @var WP_MCP_AI_REST
	 */
	private $mock_main_controller;

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
		$this->mock_main_controller = $this->createMock( WP_MCP_AI_REST::class );
		$this->mock_authenticator   = $this->createMock( WP_MCP_AI_REST_Authenticator::class );
		$this->mock_validator       = $this->createMock( WP_MCP_AI_REST_Validator::class );

		// Create controller instance.
		$this->controller = new WP_MCP_AI_REST_MCP_Controller(
			$this->mock_main_controller,
			$this->mock_authenticator,
			$this->mock_validator
		);
	}

	/**
	 * Test controller instantiation.
	 */
	public function test_controller_instantiation() {
		$this->assertInstanceOf(
			WP_MCP_AI_REST_MCP_Controller::class,
			$this->controller,
			'MCP Controller should be instantiated'
		);
	}

	/**
	 * Test controller extends base controller.
	 */
	public function test_extends_base_controller() {
		$this->assertInstanceOf(
			WP_MCP_AI_REST_Controller_Base::class,
			$this->controller,
			'MCP Controller should extend base controller'
		);
	}

	/**
	 * Test route registration method exists.
	 */
	public function test_register_routes_method_exists() {
		$this->assertTrue(
			method_exists( $this->controller, 'register_routes' ),
			'MCP Controller should have register_routes method'
		);
	}

	/**
	 * Test handle_mcp_request method exists.
	 */
	public function test_handle_mcp_request_method_exists() {
		$this->assertTrue(
			method_exists( $this->controller, 'handle_mcp_request' ),
			'MCP Controller should have handle_mcp_request method'
		);
	}

	/**
	 * Test handle_sse_handshake method exists.
	 */
	public function test_handle_sse_handshake_method_exists() {
		$this->assertTrue(
			method_exists( $this->controller, 'handle_sse_handshake' ),
			'MCP Controller should have handle_sse_handshake method'
		);
	}

	/**
	 * Test handle_assistants_index method exists.
	 */
	public function test_handle_assistants_index_method_exists() {
		$this->assertTrue(
			method_exists( $this->controller, 'handle_assistants_index' ),
			'MCP Controller should have handle_assistants_index method'
		);
	}

	/**
	 * Test permissions_check_mcp method exists.
	 */
	public function test_permissions_check_mcp_method_exists() {
		$this->assertTrue(
			method_exists( $this->controller, 'permissions_check_mcp' ),
			'MCP Controller should have permissions_check_mcp method'
		);
	}

	/**
	 * Test permissions_check_assistant_create method exists.
	 */
	public function test_permissions_check_assistant_create_method_exists() {
		$this->assertTrue(
			method_exists( $this->controller, 'permissions_check_assistant_create' ),
			'MCP Controller should have permissions_check_assistant_create method'
		);
	}

	/**
	 * Test MCP request delegation.
	 */
	public function test_mcp_request_delegates_to_main_controller() {
		$request           = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$expected_response = new WP_REST_Response( array( 'test' => 'data' ) );

		// Expect main controller's handle_mcp_request to be called.
		$this->mock_main_controller
			->expects( $this->once() )
			->method( 'handle_mcp_request' )
			->with( $request )
			->willReturn( $expected_response );

		$response = $this->controller->handle_mcp_request( $request );

		$this->assertEquals(
			$expected_response,
			$response,
			'MCP request should delegate to main controller'
		);
	}

	/**
	 * Test SSE handshake delegation.
	 */
	public function test_sse_handshake_delegates_to_main_controller() {
		$request           = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$expected_response = new WP_REST_Response( array( 'sse' => 'handshake' ) );

		// Expect main controller's handle_sse_handshake to be called.
		$this->mock_main_controller
			->expects( $this->once() )
			->method( 'handle_sse_handshake' )
			->with( $request )
			->willReturn( $expected_response );

		$response = $this->controller->handle_sse_handshake( $request );

		$this->assertEquals(
			$expected_response,
			$response,
			'SSE handshake should delegate to main controller'
		);
	}

	/**
	 * Test assistants index delegation.
	 */
	public function test_assistants_index_delegates_to_main_controller() {
		$request           = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$expected_response = new WP_REST_Response( array( 'assistants' => array() ) );

		// Expect main controller's handle_assistants_index to be called.
		$this->mock_main_controller
			->expects( $this->once() )
			->method( 'handle_assistants_index' )
			->with( $request )
			->willReturn( $expected_response );

		$response = $this->controller->handle_assistants_index( $request );

		$this->assertEquals(
			$expected_response,
			$response,
			'Assistants index should delegate to main controller'
		);
	}

	/**
	 * Test MCP permission check delegation.
	 */
	public function test_mcp_permission_check_delegates_to_main_controller() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );

		// Expect main controller's permissions_check_mcp to be called.
		$this->mock_main_controller
			->expects( $this->once() )
			->method( 'permissions_check_mcp' )
			->with( $request )
			->willReturn( true );

		$result = $this->controller->permissions_check_mcp( $request );

		$this->assertTrue(
			$result,
			'MCP permission check should delegate to main controller'
		);
	}

	/**
	 * Test assistant create permission check delegation.
	 */
	public function test_assistant_create_permission_check_delegates_to_main_controller() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/assistants' );

		// Expect main controller's permissions_check_assistant_create to be called.
		$this->mock_main_controller
			->expects( $this->once() )
			->method( 'permissions_check_assistant_create' )
			->with( $request )
			->willReturn( true );

		$result = $this->controller->permissions_check_assistant_create( $request );

		$this->assertTrue(
			$result,
			'Assistant create permission check should delegate to main controller'
		);
	}
}

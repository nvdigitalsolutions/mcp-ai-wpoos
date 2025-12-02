<?php
/**
 * Tests for standalone tool execution without assistant context.
 *
 * Verifies that tools like transcribe_openai_audio can be called
 * with assistant_id=0 for direct tool-to-tool or UI helper calls.
 *
 * @package WP_MCP_AI
 */

/**
 * Mock standalone tool that doesn't require assistant configuration.
 */
class WP_MCP_AI_Mock_Standalone_Tool implements WP_MCP_AI_Tool_Interface {
	public function get_slug() {
		return 'mock_standalone_tool';
	}

	public function get_name() {
		return 'Mock Standalone Tool';
	}

	public function get_description() {
		return 'A test tool that can execute without assistant context';
	}

	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'test_param' => array(
					'type'        => 'string',
					'description' => 'A test parameter',
				),
			),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ) {
		// Verify context has assistant_id set to 0.
		if ( ! isset( $context['assistant_id'] ) || 0 !== $context['assistant_id'] ) {
			return new WP_Error(
				'invalid_context',
				'Expected assistant_id to be 0 for standalone execution'
			);
		}

		// Tool handles its own authentication.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );
		$is_guest = ! empty( $context['is_guest'] );

		if ( ! $user_id && ! $has_token && ! $is_guest ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				'Authentication required',
				array( 'status' => 401 )
			);
		}

		return array(
			'success'      => true,
			'message'      => 'Standalone tool executed successfully',
			'assistant_id' => $context['assistant_id'],
			'user_id'      => $user_id,
		);
	}
}

/**
 * Test case for standalone tool execution.
 */
class WP_MCP_AI_Tools_Standalone_Execution_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected static $admin_user_id;

	/**
	 * Test assistant post ID.
	 *
	 * @var int
	 */
	protected static $assistant_id;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Set up test fixtures.
	 *
	 * @param WP_UnitTest_Factory $factory Test factory.
	 */
	public static function wpSetUpBeforeClass( $factory ) {
		// Create admin user for authenticated requests.
		self::$admin_user_id = $factory->user->create( array( 'role' => 'administrator' ) );

		// Create a test assistant (for comparison tests).
		self::$assistant_id = $factory->post->create(
			array(
				'post_type'   => 'mcp_ai_assistant',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		// Configure the assistant with the mock standalone tool.
		update_post_meta( self::$assistant_id, 'tools', array( 'mock_standalone_tool' ) );
	}

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Get tool registry and register mock tool.
		if ( function_exists( 'wp_mcp_ai_container' ) ) {
			$container = wp_mcp_ai_container();
			if ( $container && method_exists( $container, 'get' ) ) {
				$this->registry = $container->get( 'tool.registry' );
				$this->registry->register_tool( new WP_MCP_AI_Mock_Standalone_Tool() );
			}
		}

		// Get REST controller.
		$this->rest_controller = new WP_MCP_AI_REST();

		// Set up REST server.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tearDown();
	}

	/**
	 * Test that tools can execute with assistant_id=0.
	 */
	public function test_tool_executes_with_zero_assistant_id() {
		wp_set_current_user( self::$admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => 0,
					'tool'         => 'mock_standalone_tool',
					'arguments'    => array(
						'test_param' => 'test value',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Expected 200 status for standalone tool execution' );
		$this->assertTrue( $data['success'], 'Expected success=true in response' );
		$this->assertEquals( 0, $data['assistant_id'], 'Expected assistant_id=0 in response' );
	}

	/**
	 * Test that tools can execute when assistant_id is omitted.
	 */
	public function test_tool_executes_without_assistant_id() {
		wp_set_current_user( self::$admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'tool'      => 'mock_standalone_tool',
					'arguments' => array(
						'test_param' => 'test value',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		// Should either succeed with assistant_id=0 or use default assistant if configured.
		$this->assertNotEquals( 400, $response->get_status(), 'Should not return 400 error' );
	}

	/**
	 * Test that authentication is still required for standalone tools.
	 */
	public function test_standalone_tool_requires_authentication() {
		// Not logged in - should fail.
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => 0,
					'tool'         => 'mock_standalone_tool',
					'arguments'    => array(
						'test_param' => 'test value',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$status   = $response->get_status();

		// Should get 401 or 403 error for unauthenticated request.
		$this->assertTrue(
			401 === $status || 403 === $status,
			sprintf( 'Expected 401 or 403 status for unauthenticated request, got %d', $status )
		);
	}

	/**
	 * Test that regular assistant-scoped tools still work normally.
	 */
	public function test_assistant_scoped_tool_still_works() {
		wp_set_current_user( self::$admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => self::$assistant_id,
					'tool'         => 'mock_standalone_tool',
					'arguments'    => array(
						'test_param' => 'test value',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status(), 'Expected 200 status for assistant-scoped tool' );
		$this->assertTrue( $data['success'], 'Expected success=true in response' );
	}

	/**
	 * Test that missing tool slug returns 400 error.
	 */
	public function test_missing_tool_slug_returns_error() {
		wp_set_current_user( self::$admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => 0,
					'arguments'    => array(
						'test_param' => 'test value',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 400, $response->get_status(), 'Expected 400 status for missing tool slug' );
	}

	/**
	 * Test that non-existent tool returns 404 error.
	 */
	public function test_nonexistent_tool_returns_404() {
		wp_set_current_user( self::$admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => 0,
					'tool'         => 'nonexistent_tool',
					'arguments'    => array(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertEquals( 404, $response->get_status(), 'Expected 404 status for non-existent tool' );
	}

	/**
	 * Test that context includes correct auth flags for standalone execution.
	 */
	public function test_context_includes_auth_flags() {
		wp_set_current_user( self::$admin_user_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => 0,
					'tool'         => 'mock_standalone_tool',
					'arguments'    => array(
						'test_param' => 'test value',
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertGreaterThan( 0, $data['user_id'], 'Expected user_id to be set in context' );
	}
}

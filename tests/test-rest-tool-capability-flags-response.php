<?php
/**
 * Tests for tool capability flags in REST API responses.
 *
 * Verifies that capability flags are included in tool execution responses
 * for both chat endpoints and direct tool execution endpoints.
 *
 * @package WP_MCP_AI
 */

/**
 * Test case for capability flags in REST responses.
 */
class WP_MCP_AI_REST_Tool_Capability_Flags_Response_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID used for authenticated requests.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Test assistant ID.
	 *
	 * @var int
	 */
	protected $assistant_id;

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Get registry instance and initialize.
		$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		$this->registry->init();

		// Create a test assistant with tools that have capability flags.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant for Capability Flags',
			)
		);

		$config = array(
			'tools' => array(
				'get_system_logs',  // Has: read-only, local-only, requires-capability.
				'get_site_health',  // Has: read-only, local-only, requires-capability.
			),
		);
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_assistant_config', $config );
	}

	/**
	 * Clean up test environment.
	 */
	public function tearDown(): void {
		wp_delete_post( $this->assistant_id, true );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller for testing.
	 */
	protected function bootstrap_rest_controller() {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		// Create a mock client that won't make real API calls.
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $this->registry, $mock_client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Test that direct tool execution includes capability flags in response.
	 */
	public function test_direct_tool_execution_includes_capability_flags() {
		$this->bootstrap_rest_controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'tool'         => 'get_system_logs',
					'arguments'    => array(
						'activity_limit' => 5,
						'error_limit'    => 5,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'Tool execution should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'capability_flags', $data, 'Response should include capability_flags' );
		$this->assertIsArray( $data['capability_flags'], 'capability_flags should be an array' );
		$this->assertNotEmpty( $data['capability_flags'], 'get_system_logs should have capability flags' );

		// Verify expected flags are present.
		$this->assertContains( 'read-only', $data['capability_flags'], 'Should include read-only flag' );
		$this->assertContains( 'local-only', $data['capability_flags'], 'Should include local-only flag' );
		$this->assertContains( 'requires-capability', $data['capability_flags'], 'Should include requires-capability flag' );
	}

	/**
	 * Test that get_site_health tool execution includes capability flags.
	 */
	public function test_site_health_tool_includes_capability_flags() {
		$this->bootstrap_rest_controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'tool'         => 'get_site_health',
					'arguments'    => array(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'get_site_health should execute successfully' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'capability_flags', $data, 'Response should include capability_flags' );
		$this->assertIsArray( $data['capability_flags'], 'capability_flags should be an array' );
		$this->assertNotEmpty( $data['capability_flags'], 'get_site_health should have capability flags' );

		// Verify expected flags are present.
		$this->assertContains( 'read-only', $data['capability_flags'], 'Should include read-only flag' );
		$this->assertContains( 'local-only', $data['capability_flags'], 'Should include local-only flag' );
		$this->assertContains( 'requires-capability', $data['capability_flags'], 'Should include requires-capability flag' );
	}

	/**
	 * Test that tools without capability flags return empty array.
	 */
	public function test_tool_without_capability_flags_returns_empty_array() {
		// Register a test tool without capability flags.
		$test_tool = new class() implements WP_MCP_AI_Tool_Interface {
			public function get_slug() {
				return 'test_tool_no_flags';
			}

			public function get_name() {
				return 'Test Tool Without Flags';
			}

			public function get_description() {
				return 'A test tool that does not implement capability flags interface';
			}

			public function get_parameters_schema() {
				return array(
					'type'       => 'object',
					'properties' => array(),
				);
			}

			public function execute( array $arguments = array(), array $context = array() ) {
				return array( 'success' => true );
			}
		};

		$this->registry->register_tool( $test_tool );

		// Update assistant config to include this tool.
		$config = get_post_meta( $this->assistant_id, 'wp_mcp_ai_assistant_config', true );
		if ( ! is_array( $config ) ) {
			$config = array();
		}
		$config['tools']   = isset( $config['tools'] ) ? $config['tools'] : array();
		$config['tools'][] = 'test_tool_no_flags';
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_assistant_config', $config );

		$this->bootstrap_rest_controller();

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'tool'         => 'test_tool_no_flags',
					'arguments'    => array(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'Tool execution should succeed' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'capability_flags', $data, 'Response should include capability_flags field' );
		$this->assertIsArray( $data['capability_flags'], 'capability_flags should be an array' );
		$this->assertEmpty( $data['capability_flags'], 'Tool without flags interface should return empty array' );

		// Clean up.
		$this->registry->unregister_tool( 'test_tool_no_flags' );
	}
}

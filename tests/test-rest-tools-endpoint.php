<?php
/**
 * Tests for the /tools REST endpoint.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_REST_Tools_Endpoint_Test extends WP_UnitTestCase {

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

	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant with some tools.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Assistant',
			)
		);

		// Configure the assistant with a few tools.
		$config = array(
			'tools' => array(
				'get_current_date_time',
				'read_custom_post_type',
			),
		);
		update_post_meta( $this->assistant_id, 'wp_mcp_ai_assistant_config', $config );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Bootstrap the REST controller for testing.
	 *
	 * @param WP_MCP_AI_Language_Model_Router $client Mock client.
	 */
	protected function bootstrap_rest_controller( $client ) {
		if ( isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			remove_action( 'rest_api_init', array( $GLOBALS['wp_mcp_ai_rest_controller'], 'register_routes' ) );
		}

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $client );

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Test that GET /tools returns a list of tools for an assistant.
	 */
	public function test_get_tools_returns_list_for_assistant() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_param( 'assistant_id', $this->assistant_id );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'GET /tools should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'tools', $data, 'Response should include tools array' );
		$this->assertIsArray( $data['tools'], 'Tools should be an array' );
		$this->assertNotEmpty( $data['tools'], 'Tools array should not be empty' );

		// Verify each tool has the required fields.
		foreach ( $data['tools'] as $tool ) {
			$this->assertArrayHasKey( 'name', $tool, 'Tool should have a name' );
			$this->assertArrayHasKey( 'description', $tool, 'Tool should have a description' );
			$this->assertArrayHasKey( 'inputSchema', $tool, 'Tool should have an inputSchema' );
		}

		// Verify only configured tools are returned.
		$tool_names = wp_list_pluck( $data['tools'], 'name' );
		$this->assertContains( 'get_current_date_time', $tool_names );
		$this->assertContains( 'read_custom_post_type', $tool_names );
	}

	/**
	 * Test that GET /tools without assistant_id returns all tools.
	 */
	public function test_get_tools_without_assistant_returns_all_tools() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// Set a default assistant.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		// No assistant_id parameter, should use default.

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'GET /tools should return 200 with default assistant' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'tools', $data, 'Response should include tools array' );
		$this->assertIsArray( $data['tools'], 'Tools should be an array' );
	}

	/**
	 * Test that GET /tools requires authentication.
	 */
	public function test_get_tools_requires_authentication() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		wp_set_current_user( 0 ); // Log out.

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/tools' );
		// No nonce header.

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertNotEquals( 200, $response->get_status(), 'GET /tools should require authentication' );
		$this->assertContains(
			$response->get_status(),
			array( 401, 403 ),
			'Should return 401 or 403 without authentication'
		);
	}

	/**
	 * Test that POST /tools still works for executing tools.
	 */
	public function test_post_tools_executes_tool() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/tools' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'tool'         => 'get_current_date_time',
					'arguments'    => array(),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'POST /tools should execute tool' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data, 'Response should include tool result' );
		$this->assertArrayHasKey( 'tool', $data, 'Response should include tool name' );
		$this->assertSame( 'get_current_date_time', $data['tool'] );
	}
}

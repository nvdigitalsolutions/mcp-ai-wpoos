<?php
/**
 * Tests for MCP client compatibility and connectivity.
 *
 * This test suite validates that the MCP endpoint works correctly
 * with various client types and scenarios.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_MCP_Client_Compatibility_Test extends WP_UnitTestCase {

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
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Set up test environment.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test MCP Assistant',
			)
		);

		// Set as default assistant.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Bootstrap REST controller.
		$this->bootstrap_rest_controller();
	}

	/**
	 * Tear down test environment.
	 */
	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
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

		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$registry                             = WP_MCP_AI_Tool_Registry::get_instance();
		$this->rest_controller                = new WP_MCP_AI_REST( $registry, $mock_client );
		$GLOBALS['wp_mcp_ai_rest_controller'] = $this->rest_controller;

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	/**
	 * Send a JSON-RPC request to the MCP endpoint.
	 *
	 * @param array $message JSON-RPC message.
	 * @return WP_REST_Response
	 */
	protected function send_mcp_request( $message ) {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body( wp_json_encode( $message ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Test that all MCP responses include explicit Content-Type header.
	 */
	public function test_responses_include_content_type_header() {
		// Test successful response.
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
			)
		);

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Content-Type', $headers, 'Response should include Content-Type header' );
		$this->assertStringContainsString( 'application/json', $headers['Content-Type'], 'Content-Type should be application/json' );
	}

	/**
	 * Test that error responses include explicit Content-Type header.
	 */
	public function test_error_responses_include_content_type_header() {
		// Test error response (invalid method).
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'invalid_method',
			)
		);

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Content-Type', $headers, 'Error response should include Content-Type header' );
		$this->assertStringContainsString( 'application/json', $headers['Content-Type'], 'Content-Type should be application/json' );
	}

	/**
	 * Test that initialize response includes tools when default filter is active.
	 */
	public function test_initialize_includes_tools_by_default() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tools', $data['result'], 'Initialize should include tools by default' );
		$this->assertIsArray( $data['result']['tools'] );
	}

	/**
	 * Test that initialize respects filter to exclude tools.
	 */
	public function test_initialize_respects_filter_to_exclude_tools() {
		add_filter( 'wp_mcp_ai_initialize_include_tools', '__return_false' );

		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayNotHasKey( 'tools', $data['result'], 'Initialize should not include tools when filter returns false' );

		remove_filter( 'wp_mcp_ai_initialize_include_tools', '__return_false' );
	}

	/**
	 * Test that initialize includes proper instructions field.
	 */
	public function test_initialize_includes_instructions() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'instructions', $data['result'] );
		$this->assertNotEmpty( $data['result']['instructions'], 'Instructions should not be empty' );
		$this->assertIsString( $data['result']['instructions'] );
	}

	/**
	 * Test that initialize includes site information in instructions.
	 */
	public function test_initialize_instructions_include_site_info() {
		$site_name = get_bloginfo( 'name' );

		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
			)
		);

		$data = $response->get_data();
		$this->assertStringContainsString( $site_name, $data['result']['instructions'], 'Instructions should include site name' );
	}

	/**
	 * Test that tools/list returns proper tool structure.
	 */
	public function test_tools_list_returns_proper_structure() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/list',
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tools', $data['result'] );

		if ( ! empty( $data['result']['tools'] ) ) {
			$tool = $data['result']['tools'][0];
			$this->assertArrayHasKey( 'name', $tool, 'Tool should have name field' );
			$this->assertArrayHasKey( 'description', $tool, 'Tool should have description field' );
			$this->assertArrayHasKey( 'inputSchema', $tool, 'Tool should have inputSchema field' );
			$this->assertIsArray( $tool['inputSchema'], 'inputSchema should be an array' );
		}
	}

	/**
	 * Test that resources/list returns proper structure.
	 */
	public function test_resources_list_returns_proper_structure() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'resources/list',
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'resources', $data['result'] );
		$this->assertIsArray( $data['result']['resources'] );
	}

	/**
	 * Test that prompts/list returns proper structure.
	 */
	public function test_prompts_list_returns_proper_structure() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'prompts/list',
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'prompts', $data['result'] );
		$this->assertIsArray( $data['result']['prompts'] );

		if ( ! empty( $data['result']['prompts'] ) ) {
			$prompt = $data['result']['prompts'][0];
			$this->assertArrayHasKey( 'name', $prompt, 'Prompt should have name field' );
			$this->assertArrayHasKey( 'description', $prompt, 'Prompt should have description field' );
			$this->assertArrayHasKey( 'arguments', $prompt, 'Prompt should have arguments field' );
		}
	}

	/**
	 * Test that all responses include proper CORS headers.
	 */
	public function test_all_responses_include_cors_headers() {
		$methods = array( 'initialize', 'tools/list', 'resources/list', 'prompts/list' );

		foreach ( $methods as $method ) {
			$response = $this->send_mcp_request(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
				)
			);

			$headers = $response->get_headers();
			$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $headers, "Method $method should include CORS origin header" );
			$this->assertArrayHasKey( 'Access-Control-Allow-Methods', $headers, "Method $method should include CORS methods header" );
			$this->assertArrayHasKey( 'Access-Control-Allow-Headers', $headers, "Method $method should include CORS headers header" );
		}
	}

	/**
	 * Test that error responses have proper JSON-RPC structure.
	 */
	public function test_error_responses_have_proper_structure() {
		// Test with invalid method.
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'invalid_method',
			)
		);

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data, 'Error response should include jsonrpc field' );
		$this->assertSame( '2.0', $data['jsonrpc'] );
		$this->assertArrayHasKey( 'id', $data, 'Error response should include id field' );
		$this->assertArrayHasKey( 'error', $data, 'Error response should include error field' );
		$this->assertIsArray( $data['error'] );
		$this->assertArrayHasKey( 'code', $data['error'], 'Error should have code field' );
		$this->assertArrayHasKey( 'message', $data['error'], 'Error should have message field' );
	}

	/**
	 * Test that notification (no id) returns 202 with no body.
	 */
	public function test_notification_returns_202_with_no_body() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'method'  => 'logging/message',
				'params'  => array(
					'level'   => 'info',
					'message' => 'test',
				),
			)
		);

		$this->assertSame( 202, $response->get_status(), 'Notification should return 202' );
		$this->assertNull( $response->get_data(), 'Notification should have no body' );
	}

	/**
	 * Test charset is included in Content-Type.
	 */
	public function test_content_type_includes_charset() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
			)
		);

		$headers = $response->get_headers();
		$this->assertStringContainsString( 'charset=utf-8', $headers['Content-Type'], 'Content-Type should include charset=utf-8' );
	}
}

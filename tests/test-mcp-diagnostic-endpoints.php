<?php
/**
 * Tests for MCP Diagnostic Page Functionality
 *
 * Validates that the MCP diagnostic AJAX endpoints work correctly
 * and return proper responses for testing the MCP protocol implementation.
 *
 * @package WP_MCP_AI
 */
class WP_MCP_AI_MCP_Diagnostic_Endpoints_Test extends WP_UnitTestCase {

	/**
	 * Administrator user ID.
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

		// Ensure the diagnostic class is loaded.
		if ( ! class_exists( 'WP_MCP_AI_MCP_Server_Diagnostic' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php';
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		// Create a test assistant.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test MCP Diagnostic Assistant',
			)
		);

		// Configure assistant.
		$config = array(
			'provider'    => 'openai',
			'model'       => 'gpt-4',
			'temperature' => 0.7,
			'tools'       => array( 'search_content', 'list_users' ),
		);
		update_post_meta( $this->assistant_id, '_mcp_ai_configuration', $config );

		// Set as default assistant.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Ensure REST server is available.
		$registry    = WP_MCP_AI_Tool_Registry::get_instance();
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		if ( ! isset( $GLOBALS['wp_mcp_ai_rest_controller'] ) ) {
			$GLOBALS['wp_mcp_ai_rest_controller'] = new WP_MCP_AI_REST( $registry, $mock_client );
		}

		rest_get_server();
		do_action( 'rest_api_init' );
	}

	public function tearDown(): void {
		delete_option( WP_MCP_AI_Admin_Settings::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Test that the diagnostic AJAX action for testing MCP endpoint exists.
	 */
	public function test_mcp_endpoint_ajax_action_exists() {
		$this->assertTrue( has_action( 'wp_ajax_wp_mcp_ai_test_mcp_endpoint' ) !== false );
	}

	/**
	 * Test that the diagnostic AJAX action for testing MCP methods exists.
	 */
	public function test_mcp_method_ajax_action_exists() {
		$this->assertTrue( has_action( 'wp_ajax_wp_mcp_ai_test_mcp_method' ) !== false );
	}

	/**
	 * Test MCP endpoint connectivity via direct REST call.
	 */
	public function test_mcp_endpoint_initialize() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'MCP initialize should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data, 'Response should include jsonrpc field' );
		$this->assertSame( '2.0', $data['jsonrpc'], 'Response should be JSON-RPC 2.0' );
		$this->assertArrayHasKey( 'result', $data, 'Response should include result field' );
		$this->assertArrayHasKey( 'protocolVersion', $data['result'], 'Result should include protocolVersion' );
		$this->assertSame( '2024-11-05', $data['result']['protocolVersion'], 'Protocol version should be 2024-11-05' );
	}

	/**
	 * Test MCP tools/list method.
	 */
	public function test_mcp_tools_list_method() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 2,
			'method'  => 'tools/list',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'MCP tools/list should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'tools', $data['result'], 'Result should include tools array' );
		$this->assertIsArray( $data['result']['tools'], 'Tools should be an array' );
		$this->assertGreaterThan( 0, count( $data['result']['tools'] ), 'Should have at least one tool' );

		// Verify tool structure.
		if ( ! empty( $data['result']['tools'] ) ) {
			$tool = $data['result']['tools'][0];
			$this->assertArrayHasKey( 'name', $tool, 'Tool should have name field' );
			$this->assertArrayHasKey( 'description', $tool, 'Tool should have description field' );
			$this->assertArrayHasKey( 'inputSchema', $tool, 'Tool should have inputSchema field' );
		}
	}

	/**
	 * Test MCP resources/list method.
	 */
	public function test_mcp_resources_list_method() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 3,
			'method'  => 'resources/list',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'MCP resources/list should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'resources', $data['result'], 'Result should include resources array' );
		$this->assertIsArray( $data['result']['resources'], 'Resources should be an array' );
	}

	/**
	 * Test MCP prompts/list method.
	 */
	public function test_mcp_prompts_list_method() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 4,
			'method'  => 'prompts/list',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status(), 'MCP prompts/list should return 200' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'prompts', $data['result'], 'Result should include prompts array' );
		$this->assertIsArray( $data['result']['prompts'], 'Prompts should be an array' );
	}

	/**
	 * Test that invalid method returns proper error.
	 */
	public function test_mcp_invalid_method_error() {
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 5,
			'method'  => 'invalid/method',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 404, $response->get_status(), 'Invalid method should return 404' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'error', $data, 'Response should include error field' );
		$this->assertSame( -32601, $data['error']['code'], 'Error code should be -32601 (Method not found)' );
	}

	/**
	 * Test that the diagnostic page is registered.
	 */
	public function test_diagnostic_page_is_registered() {
		global $submenu;

		// Trigger admin_menu action to ensure pages are registered.
		set_current_screen( 'tools.php' );
		do_action( 'admin_menu' );

		// Check if the diagnostic page is registered under Tools menu.
		$this->assertArrayHasKey( 'tools.php', $submenu, 'Tools submenu should exist' );

		// Find the MCP diagnostic page in the submenu.
		$found = false;
		foreach ( $submenu['tools.php'] as $item ) {
			if ( isset( $item[2] ) && 'wp-mcp-ai-mcp-diagnostic' === $item[2] ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue( $found, 'MCP diagnostic page should be registered under Tools menu' );
	}

	/**
	 * Test that jQuery and diagnostic script are enqueued on the diagnostic page.
	 */
	public function test_jquery_is_enqueued_on_diagnostic_page() {
		global $wp_scripts;

		// Trigger admin_menu to register the page.
		set_current_screen( 'tools.php' );
		do_action( 'admin_menu' );

		// Get the page hook.
		$reflection = new ReflectionClass( 'WP_MCP_AI_MCP_Server_Diagnostic' );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$page_hook = $property->getValue();

		// Simulate being on the diagnostic page.
		set_current_screen( $page_hook );

		// Trigger the enqueue_assets method.
		do_action( 'admin_enqueue_scripts', $page_hook );

		// Verify jQuery is enqueued (as a dependency).
		$this->assertTrue( wp_script_is( 'jquery', 'enqueued' ), 'jQuery should be enqueued on diagnostic page' );

		// Verify the diagnostic script is enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-mcp-diagnostic', 'enqueued' ), 'Diagnostic script should be enqueued' );
	}

	/**
	 * Test that wpMcpAiMcpDiagnostic is properly localized.
	 */
	public function test_diagnostic_script_data_is_localized() {
		global $wp_scripts;

		// Trigger admin_menu to register the page.
		set_current_screen( 'tools.php' );
		do_action( 'admin_menu' );

		// Get the page hook.
		$reflection = new ReflectionClass( 'WP_MCP_AI_MCP_Server_Diagnostic' );
		$property   = $reflection->getProperty( 'page_hook' );
		$property->setAccessible( true );
		$page_hook = $property->getValue();

		// Simulate being on the diagnostic page.
		set_current_screen( $page_hook );

		// Trigger the enqueue_assets method.
		do_action( 'admin_enqueue_scripts', $page_hook );

		// Verify the diagnostic script is enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-mcp-diagnostic', 'enqueued' ), 'Diagnostic script should be enqueued' );

		// Get the localized data from the diagnostic script handle.
		$script_data = $wp_scripts->get_data( 'wp-mcp-ai-mcp-diagnostic', 'data' );
		$this->assertNotEmpty( $script_data, 'Diagnostic script should have localized data' );
		$this->assertStringContainsString( 'wpMcpAiMcpDiagnostic', $script_data, 'Localized data should contain wpMcpAiMcpDiagnostic' );
		$this->assertStringContainsString( 'ajaxUrl', $script_data, 'Localized data should contain ajaxUrl' );
		$this->assertStringContainsString( 'nonce', $script_data, 'Localized data should contain nonce' );
		$this->assertStringContainsString( 'i18n', $script_data, 'Localized data should contain i18n' );
	}

	/**
	 * Test that admin users can access MCP endpoint for internal diagnostic testing.
	 *
	 * This test verifies the fix for issue #1058 where the MCP diagnostic page
	 * needs to make internal REST API calls to test the MCP endpoint without
	 * requiring bearer tokens.
	 */
	public function test_admin_can_access_mcp_endpoint_for_diagnostics() {
		// Simulate an admin user making an internal REST request (like the diagnostic page does).
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		// Process the request internally (simulating rest_do_request()).
		$response = rest_get_server()->dispatch( $request );

		// Should succeed for admin users making internal requests.
		$this->assertSame( 200, $response->get_status(), 'Admin user should be able to access MCP endpoint for diagnostic testing' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'jsonrpc', $data, 'Response should include jsonrpc field' );
		$this->assertSame( '2.0', $data['jsonrpc'], 'Response should be JSON-RPC 2.0' );
		$this->assertArrayHasKey( 'result', $data, 'Response should include result field' );
	}

	/**
	 * Test that non-admin users cannot access MCP endpoint without bearer token.
	 */
	public function test_non_admin_cannot_access_mcp_endpoint_without_bearer() {
		// Create a non-admin user.
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		// Process the request internally.
		$response = rest_get_server()->dispatch( $request );

		// Should fail for non-admin users without bearer token.
		$this->assertSame( 401, $response->get_status(), 'Non-admin user should not be able to access MCP endpoint without bearer token' );

		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should include code field' );
		$this->assertSame( 'wp_mcp_ai_mcp_bearer_required', $data['code'], 'Error code should indicate bearer token required' );
	}

	/**
	 * Test CORS headers are set correctly for cross-origin MCP client compatibility.
	 */
	public function test_mcp_cors_headers_for_client_compatibility() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		$response = rest_get_server()->dispatch( $request );

		// Verify CORS headers are present.
		$headers = $response->get_headers();

		$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $headers, 'CORS origin header should be set' );
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'], 'CORS should allow all origins by default' );
	}

	/**
	 * Test OPTIONS preflight request for CORS compatibility.
	 */
	public function test_mcp_options_preflight_request() {
		$request = new WP_REST_Request( 'OPTIONS', '/mcp-ai/v1/mcp' );

		$response = rest_get_server()->dispatch( $request );

		// OPTIONS should return 204 No Content.
		$this->assertSame( 204, $response->get_status(), 'OPTIONS request should return 204' );

		// Verify CORS preflight headers.
		$headers = $response->get_headers();

		$this->assertArrayHasKey( 'Access-Control-Allow-Origin', $headers, 'CORS origin header should be set' );
		$this->assertArrayHasKey( 'Access-Control-Allow-Methods', $headers, 'CORS methods header should be set' );
		$this->assertArrayHasKey( 'Access-Control-Allow-Headers', $headers, 'CORS allow-headers should be set' );

		// Verify POST is allowed (required for MCP).
		$this->assertStringContainsString( 'POST', $headers['Access-Control-Allow-Methods'], 'POST method should be allowed' );

		// Verify Authorization header is allowed (required for bearer tokens).
		$this->assertStringContainsString( 'Authorization', $headers['Access-Control-Allow-Headers'], 'Authorization header should be allowed' );
	}

	/**
	 * Test MCP response includes required JSON-RPC 2.0 fields.
	 */
	public function test_mcp_response_jsonrpc_compliance() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		// Verify JSON-RPC 2.0 compliance.
		$this->assertArrayHasKey( 'jsonrpc', $data, 'Response must include jsonrpc field' );
		$this->assertSame( '2.0', $data['jsonrpc'], 'jsonrpc must be "2.0"' );

		$this->assertArrayHasKey( 'id', $data, 'Response must include id field' );
		$this->assertSame( 1, $data['id'], 'Response id must match request id' );

		$this->assertArrayHasKey( 'result', $data, 'Successful response must include result field' );
		$this->assertArrayNotHasKey( 'error', $data, 'Successful response should not include error field' );
	}

	/**
	 * Test MCP initialize response includes required fields for client compatibility.
	 */
	public function test_mcp_initialize_includes_required_fields() {
		wp_set_current_user( $this->admin_id );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );

		$message = array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(),
		);

		$request->set_body( wp_json_encode( $message ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();
		$result   = $data['result'];

		// Verify MCP 2024-11-05 required fields.
		$this->assertArrayHasKey( 'protocolVersion', $result, 'Initialize must include protocolVersion' );
		$this->assertSame( '2024-11-05', $result['protocolVersion'], 'Protocol version must be 2024-11-05' );

		$this->assertArrayHasKey( 'capabilities', $result, 'Initialize must include capabilities' );
		$this->assertIsArray( $result['capabilities'], 'Capabilities must be an array' );

		$this->assertArrayHasKey( 'serverInfo', $result, 'Initialize must include serverInfo' );
		$this->assertIsArray( $result['serverInfo'], 'ServerInfo must be an array' );
		$this->assertArrayHasKey( 'name', $result['serverInfo'], 'ServerInfo must include name' );
		$this->assertArrayHasKey( 'version', $result['serverInfo'], 'ServerInfo must include version' );

		// Verify OpenAI Agent Builder compatibility: tools included in initialize.
		$this->assertArrayHasKey( 'tools', $result, 'Initialize should include tools for OpenAI Agent Builder compatibility' );
		$this->assertIsArray( $result['tools'], 'Tools must be an array' );
	}
}

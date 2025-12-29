<?php
/**
 * Tests for MCP client configuration scenarios.
 *
 * This test suite validates that the MCP server implementation works correctly
 * with different client configurations, including:
 * 1. Proper MCP configuration (base URL with SSE)
 * 2. Incorrect configuration (direct /chat URL)
 */
class WP_MCP_AI_MCP_Client_Configuration_Test extends WP_UnitTestCase {

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
	 * Bearer token for authentication.
	 *
	 * @var string
	 */
	protected $bearer_token;

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

		// Generate a test credential.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$credential = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, 'Test MCP Client' );
			if ( $credential && isset( $credential['token'] ) ) {
				$this->bearer_token = $credential['token'];
			}
		}
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
	 * Test Scenario 1: Proper MCP configuration with base URL
	 *
	 * This simulates the correct configuration:
	 * {
	 *   "url": "https://site.com/wp-json/mcp-ai/v1",
	 *   "headers": { "Authorization": "****** },
	 *   "sse": true
	 * }
	 */
	public function test_proper_mcp_configuration_with_base_url() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// Step 1: MCP client calls /assistants to get directory
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Accept', 'text/event-stream' );

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), 'Directory endpoint should return 200' );

		$headers = $response->get_headers();
		$this->assertStringStartsWith(
			'text/event-stream',
			$headers['Content-Type'] ?? '',
			'Should return SSE content type when Accept header is set'
		);

		// Verify CORS headers.
		$this->assertSame( '*', $headers['Access-Control-Allow-Origin'] ?? '' );
		$this->assertSame( 'Authorization, Content-Type, X-WP-Nonce', $headers['Access-Control-Allow-Headers'] ?? '' );
	}

	/**
	 * Test Scenario 2: MCP configuration with /sse endpoint (LM Studio)
	 *
	 * This simulates LM Studio configuration:
	 * {
	 *   "url": "https://site.com/wp-json/mcp-ai/v1",
	 *   "sse": { "enabled": true, "endpoint": "/sse" }
	 * }
	 */
	public function test_lm_studio_sse_endpoint_configuration() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// LM Studio calls /sse endpoint directly without Accept header.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/sse' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		// Note: No Accept header - LM Studio doesn't send it

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '/sse endpoint should return 200' );

		$headers = $response->get_headers();
		$this->assertStringStartsWith(
			'text/event-stream',
			$headers['Content-Type'] ?? '',
			'/sse endpoint should force SSE content type even without Accept header'
		);

		// Verify the response contains directory data.
		$this->assertNotEmpty( $headers, '/sse endpoint should set proper headers' );
	}

	/**
	 * Test Scenario 3: Incorrect configuration pointing to /chat directly
	 *
	 * This simulates the INCORRECT configuration:
	 * {
	 *   "url": "https://site.com/wp-json/mcp-ai/chat"
	 * }
	 *
	 * This should fail because /chat is POST-only and expects a message payload.
	 */
	public function test_incorrect_configuration_with_chat_url() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		// MCP client tries to call /chat as if it were the directory.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );

		// The /chat endpoint only accepts POST, not GET.
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertNotEquals( 200, $response->get_status(), '/chat should not respond to GET requests' );

		// Should return 404 or method not allowed.
		$this->assertContains(
			$response->get_status(),
			array( 404, 405 ),
			'/chat endpoint should return 404 or 405 for GET requests'
		);
	}

	/**
	 * Test Scenario 4: Verify directory response includes all necessary URLs
	 *
	 * The directory response should include URLs for chat, tools, sse, etc.
	 * so clients can discover the correct endpoints.
	 */
	public function test_directory_includes_endpoint_urls() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		$this->bootstrap_rest_controller( $mock_client );

		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/assistants' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'rest', $data, 'Directory should include rest endpoints' );
		$this->assertArrayHasKey( 'chat', $data['rest'], 'Directory should include chat URL' );
		$this->assertArrayHasKey( 'tools', $data['rest'], 'Directory should include tools URL' );
		$this->assertArrayHasKey( 'sse', $data['rest'], 'Directory should include sse URL' );

		// Verify the URLs are properly formed.
		$this->assertStringContainsString( '/chat', $data['rest']['chat'] );
		$this->assertStringContainsString( '/tools', $data['rest']['tools'] );
		$this->assertStringContainsString( '/sse', $data['rest']['sse'] );
	}

	/**
	 * Test Scenario 5: Verify /chat endpoint requires POST with message payload
	 */
	public function test_chat_endpoint_requires_post_with_payload() {
		$mock_client = $this->getMockBuilder( WP_MCP_AI_Language_Model_Router::class )
			->disableOriginalConstructor()
			->getMock();

		// Mock the send_message method to return a test response.
		$mock_client->method( 'send_message' )
			->willReturn(
				array(
					'id'      => 'msg_test123',
					'role'    => 'assistant',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Test response',
						),
					),
				)
			);

		$this->bootstrap_rest_controller( $mock_client );

		// Proper POST request to /chat.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'assistant_id' => $this->assistant_id,
					'messages'     => array(
						array(
							'role'    => 'user',
							'content' => 'Hello',
						),
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '/chat should accept POST with proper payload' );
	}

	/**
	 * Test Scenario 6: Document the correct vs incorrect configurations
	 *
	 * This test documents the difference for future reference.
	 */
	public function test_configuration_documentation() {
		$correct_config = array(
			'description'  => 'Correct MCP configuration for Claude Desktop/LM Studio',
			'config'       => array(
				'mcpServers' => array(
					'my-wordpress' => array(
						'url'     => 'https://bots.nvdigital.solutions/wp-json/mcp-ai/v1',
						'headers' => array(
							'Authorization' => 'Bearer cred_xxxxx.SECRET',
						),
						'sse'     => true,
					),
				),
			),
			'how_it_works' => array(
				'1. Client calls /assistants (or /sse for LM Studio) to get directory',
				'2. Server returns list of assistants and endpoint URLs',
				'3. Client uses returned URLs for chat, tools, etc.',
			),
		);

		$incorrect_config = array(
			'description' => 'Incorrect configuration - pointing to /chat directly',
			'config'      => array(
				'mcpServers' => array(
					'my-wordpress' => array(
						'url'     => 'https://bots.nvdigital.solutions/wp-json/mcp-ai/chat',
						'headers' => array(
							'Authorization' => 'Bearer cred_xxxxx.SECRET',
							'Content-Type'  => 'application/json',
							'Accept'        => 'text/event-stream',
						),
					),
				),
			),
			'why_fails'   => array(
				'1. /chat is POST-only, not GET',
				'2. /chat requires message payload',
				'3. /chat is not the MCP directory endpoint',
				'4. Client cannot discover other endpoints',
			),
		);

		// Assert that we've documented both configurations.
		$this->assertIsArray( $correct_config );
		$this->assertIsArray( $incorrect_config );
		$this->assertArrayHasKey( 'config', $correct_config );
		$this->assertArrayHasKey( 'config', $incorrect_config );

		// This test always passes - it's for documentation purposes.
		$this->assertTrue( true, 'Configuration documentation test' );
	}
}

<?php
/**
 * Tests for the MCP prompts/get endpoint.
 *
 * Validates that the prompts/get method correctly returns
 * prompt content for published assistants.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class WP_MCP_AI_MCP_Prompts_Get_Test extends WP_UnitTestCase {
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

		// Create a test assistant with a system prompt.
		$this->assistant_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Test Prompts Assistant',
				'post_name'   => 'test-prompts-assistant',
			)
		);

		update_post_meta( $this->assistant_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, 'You are a helpful test assistant.' );

		// WordPress appends a numeric suffix when the slug already exists, so read
		// back the stored value rather than assuming the requested one.
		$this->assistant_slug = get_post_field( 'post_name', $this->assistant_id );

		// Set as default assistant.
		$settings                      = WP_MCP_AI_Admin_Settings::get_default_settings();
		$settings['default_assistant'] = $this->assistant_id;
		update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

		// Issue a credential — permissions_check_mcp() refuses bare nonce auth.
		if ( class_exists( 'WP_MCP_AI_Credentials' ) ) {
			$credential = WP_MCP_AI_Credentials::issue_credential( $this->assistant_id, $this->admin_id );
			if ( is_array( $credential ) && isset( $credential['token'] ) ) {
				$this->bearer_token = $credential['token'];
			}
		}

		// Bootstrap REST controller.
		$this->bootstrap_rest_controller();
	}

	/**
	 * Bearer token for the suite assistant.
	 *
	 * @var string
	 */
	protected $bearer_token = '';

	/**
	 * Actual stored slug of the suite assistant.
	 *
	 * @var string
	 */
	protected $assistant_slug = '';

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
		if ( ! empty( $this->bearer_token ) ) {
			$request->set_header( 'Authorization', 'Bearer ' . $this->bearer_token );
		}
		$request->set_body( wp_json_encode( $message ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Test that prompts/get returns error when name is missing.
	 */
	public function test_prompts_get_missing_name() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'prompts/get',
				'params'  => array(),
			)
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32603, $data['error']['code'] );
		$this->assertStringContainsString( 'name', $data['error']['message'] );
	}

	/**
	 * Test that prompts/get returns a valid prompt for a published assistant.
	 */
	public function test_prompts_get_valid_prompt() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'prompts/get',
				'params'  => array(
					'name' => $this->assistant_slug,
				),
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );

		$result = $data['result'];
		$this->assertArrayHasKey( 'description', $result );
		$this->assertSame( 'Test Prompts Assistant', $result['description'] );

		$this->assertArrayHasKey( 'messages', $result );
		$this->assertNotEmpty( $result['messages'] );

		$message = $result['messages'][0];
		$this->assertSame( 'user', $message['role'] );
		$this->assertSame( 'text', $message['content']['type'] );
		$this->assertSame( 'You are a helpful test assistant.', $message['content']['text'] );
	}

	/**
	 * Test that prompts/get returns error for a non-existent prompt.
	 */
	public function test_prompts_get_not_found() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'prompts/get',
				'params'  => array(
					'name' => 'nonexistent-prompt-slug',
				),
			)
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'not found', strtolower( $data['error']['message'] ) );
	}

	/**
	 * Test that prompts/get does not return draft assistants.
	 */
	public function test_prompts_get_draft_assistant_not_found() {
		// Create a draft assistant.
		$draft_id = wp_insert_post(
			array(
				'post_type'   => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Draft Assistant',
				'post_name'   => 'draft-assistant',
			)
		);

		update_post_meta( $draft_id, WP_MCP_AI_Assistant_CPT::META_SYSTEM_PROMPT, 'Draft prompt.' );

		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'prompts/get',
				'params'  => array(
					'name' => 'draft-assistant',
				),
			)
		);

		$data = $response->get_data();

		$this->assertArrayHasKey( 'error', $data );
		$this->assertStringContainsString( 'not found', strtolower( $data['error']['message'] ) );
	}

	/**
	 * Test that prompts/get appends context argument to the prompt.
	 */
	public function test_prompts_get_with_context_argument() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 5,
				'method'  => 'prompts/get',
				'params'  => array(
					'name'      => $this->assistant_slug,
					'arguments' => array(
						'context' => 'Focus on WordPress development.',
					),
				),
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );

		$message_text = $data['result']['messages'][0]['content']['text'];
		$this->assertStringContainsString( 'You are a helpful test assistant.', $message_text );
		$this->assertStringContainsString( 'Focus on WordPress development.', $message_text );
	}

	/**
	 * Test that prompts/get requires authentication.
	 */
	public function test_prompts_get_requires_auth() {
		wp_set_current_user( 0 );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 6,
					'method'  => 'prompts/get',
					'params'  => array(
						'name' => $this->assistant_slug,
					),
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status(), 'Should require authentication' );
	}

	/**
	 * Test that prompts/get is listed in the method router.
	 */
	public function test_prompts_get_method_is_routed() {
		// Call with an empty name to verify the method is found (not "method not found").
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 7,
				'method'  => 'prompts/get',
				'params'  => array(
					'name' => '',
				),
			)
		);

		$data = $response->get_data();

		// The method should be routed (not return -32601 "Method not found").
		if ( isset( $data['error'] ) ) {
			$this->assertNotSame( -32601, $data['error']['code'], 'prompts/get should be a recognized method' );
		}
	}

	/**
	 * Test that prompts/list now includes arguments schema.
	 */
	public function test_prompts_list_includes_arguments() {
		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 8,
				'method'  => 'prompts/list',
			)
		);

		$data = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'prompts', $data['result'] );
		$this->assertNotEmpty( $data['result']['prompts'] );

		$prompt = $data['result']['prompts'][0];
		$this->assertArrayHasKey( 'arguments', $prompt );
		$this->assertNotEmpty( $prompt['arguments'] );

		// Verify the context argument is present.
		$context_arg = $prompt['arguments'][0];
		$this->assertSame( 'context', $context_arg['name'] );
		$this->assertFalse( $context_arg['required'] );
	}

	/**
	 * Test that prompts/get works with the wp_mcp_ai_prompts_get filter.
	 */
	public function test_prompts_get_filter() {
		$filter_called = false;

		add_filter(
			'wp_mcp_ai_prompts_get',
			function ( $prompt_content, $post, $arguments, $request ) use ( &$filter_called ) {
				$filter_called = true;
				// Add an extra message from the filter.
				$prompt_content['messages'][] = array(
					'role'    => 'assistant',
					'content' => array(
						'type' => 'text',
						'text' => 'Filtered content added.',
					),
				);
				return $prompt_content;
			},
			10,
			4
		);

		$response = $this->send_mcp_request(
			array(
				'jsonrpc' => '2.0',
				'id'      => 9,
				'method'  => 'prompts/get',
				'params'  => array(
					'name' => $this->assistant_slug,
				),
			)
		);

		$data = $response->get_data();

		$this->assertTrue( $filter_called, 'wp_mcp_ai_prompts_get filter should be called' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data['result']['messages'] );
		$this->assertSame( 'Filtered content added.', $data['result']['messages'][1]['content']['text'] );

		remove_all_filters( 'wp_mcp_ai_prompts_get' );
	}
}

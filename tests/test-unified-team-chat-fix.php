<?php
/**
 * Test for unified team chat fix.
 *
 * Validates that invoke_team_member uses the correct create_chat_completion method.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */
class Test_Unified_Team_Chat_Fix extends WP_UnitTestCase {

	use WP_MCP_AI_REST_Test_Helper;

	/**
	 * Administrator user ID.
	 *
	 * @var int
	 */
	protected $admin_user_id;

	/**
	 * REST controller instance.
	 *
	 * @var WP_MCP_AI_REST
	 */
	protected $rest_controller;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Create admin user.
		$this->admin_user_id = $this->create_test_user( 'administrator' );
		wp_set_current_user( $this->admin_user_id );

		// Set up REST server.
		$this->setup_rest_server();

		// Initialize REST controller with mock client.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		// Create mock router that returns a simple response.
		$mock_client = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$mock_client->method( 'create_chat_completion' )
			->willReturn(
				array(
					'choices' => array(
						array(
							'message' => array(
								'role'    => 'assistant',
								'content' => 'Test response from team member',
							),
						),
					),
				)
			);

		$this->rest_controller = new WP_MCP_AI_REST( $registry, $mock_client );

		// Store in global for Chat Controller.
		$GLOBALS['wp_mcp_ai_rest_controller'] = $this->rest_controller;

		// Ensure REST routes are registered.
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		// Clean up REST server.
		$this->teardown_rest_server();

		// Clean up global.
		unset( $GLOBALS['wp_mcp_ai_rest_controller'] );

		parent::tearDown();
	}

	/**
	 * Test that invoke_team_member uses create_chat_completion correctly.
	 *
	 * This tests the core fix: that we're calling $this->client->create_chat_completion()
	 * with the correct parameters instead of the incorrect $ai_provider->request().
	 */
	public function test_invoke_team_member_uses_correct_method() {
		// Create a test profession.
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Developer',
				'post_status' => 'publish',
			)
		);

		// Add profession configuration.
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', 'You are a helpful developer.' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', 'openai' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', 'gpt-4' );

		// Test messages.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'What can you do?',
			),
		);

		// Use reflection to call the protected invoke_team_member method.
		$reflection = new ReflectionClass( $this->rest_controller );
		$method     = $reflection->getMethod( 'invoke_team_member' );
		$method->setAccessible( true );

		// Create a mock request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );

		// Call the method.
		$result = $method->invoke( $this->rest_controller, $profession_id, $messages, $request );

		// Assert the result is not a WP_Error.
		$this->assertNotInstanceOf( WP_Error::class, $result, 'invoke_team_member should not return WP_Error' );

		// Assert the result has the expected structure.
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'member_id', $result );
		$this->assertArrayHasKey( 'content', $result );
		$this->assertArrayHasKey( 'metadata', $result );

		// Assert the content is what we expect from our mock.
		$this->assertEquals( 'Test response from team member', $result['content'] );

		// Assert member_id is set correctly.
		$this->assertEquals( $profession_id, $result['member_id'] );
	}

	/**
	 * Test that system prompt is properly prepended to messages.
	 */
	public function test_system_prompt_prepended_to_messages() {
		// Create a profession with system prompt.
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$system_prompt = 'You are a helpful assistant.';
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', $system_prompt );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', 'openai' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', 'gpt-4' );

		// Mock the router to capture the messages parameter.
		$captured_messages = null;
		$mock_client       = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$mock_client->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages, $options ) use ( &$captured_messages ) {
					$captured_messages = $messages;
					return array(
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Test response',
								),
							),
						),
					);
				}
			);

		// Replace the client in REST controller.
		$reflection = new ReflectionClass( $this->rest_controller );
		$property   = $reflection->getProperty( 'client' );
		$property->setAccessible( true );
		$property->setValue( $this->rest_controller, $mock_client );

		// Test messages.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		// Use reflection to call invoke_team_member.
		$method = $reflection->getMethod( 'invoke_team_member' );
		$method->setAccessible( true );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$result  = $method->invoke( $this->rest_controller, $profession_id, $messages, $request );

		// Verify system prompt was prepended.
		$this->assertNotNull( $captured_messages );
		$this->assertIsArray( $captured_messages );
		$this->assertCount( 2, $captured_messages, 'Messages should include system message + user message' );

		// First message should be the system prompt.
		$this->assertEquals( 'system', $captured_messages[0]['role'] );
		$this->assertStringContainsString( $system_prompt, $captured_messages[0]['content'] );

		// Second message should be the original user message.
		$this->assertEquals( 'user', $captured_messages[1]['role'] );
		$this->assertEquals( 'Hello', $captured_messages[1]['content'] );
	}

	/**
	 * Test that system prompt is not duplicated if already present.
	 */
	public function test_system_prompt_not_duplicated() {
		// Create a profession with system prompt.
		$profession_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Test Assistant',
				'post_status' => 'publish',
			)
		);

		$system_prompt = 'You are a helpful assistant.';
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_role_description', $system_prompt );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_provider', 'openai' );
		update_post_meta( $profession_id, '_wp_mcp_ai_profession_default_model', 'gpt-4' );

		// Mock the router to capture the messages parameter.
		$captured_messages = null;
		$mock_client       = $this->createMock( WP_MCP_AI_Language_Model_Router::class );
		$mock_client->method( 'create_chat_completion' )
			->willReturnCallback(
				function ( $messages, $options ) use ( &$captured_messages ) {
					$captured_messages = $messages;
					return array(
						'choices' => array(
							array(
								'message' => array(
									'role'    => 'assistant',
									'content' => 'Test response',
								),
							),
						),
					);
				}
			);

		// Replace the client in REST controller.
		$reflection = new ReflectionClass( $this->rest_controller );
		$property   = $reflection->getProperty( 'client' );
		$property->setAccessible( true );
		$property->setValue( $this->rest_controller, $mock_client );

		// Test messages that ALREADY include a system message.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'Existing system message',
			),
			array(
				'role'    => 'user',
				'content' => 'Hello',
			),
		);

		// Use reflection to call invoke_team_member.
		$method = $reflection->getMethod( 'invoke_team_member' );
		$method->setAccessible( true );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$result  = $method->invoke( $this->rest_controller, $profession_id, $messages, $request );

		// Verify system prompt was NOT duplicated.
		$this->assertNotNull( $captured_messages );
		$this->assertIsArray( $captured_messages );
		$this->assertCount( 2, $captured_messages, 'Should still have 2 messages (not 3)' );

		// First message should be the EXISTING system message (not replaced).
		$this->assertEquals( 'system', $captured_messages[0]['role'] );
		$this->assertEquals( 'Existing system message', $captured_messages[0]['content'] );

		// Second message should be the user message.
		$this->assertEquals( 'user', $captured_messages[1]['role'] );
		$this->assertEquals( 'Hello', $captured_messages[1]['content'] );
	}

	/**
	 * Test unified team requests return the standard chat-client payload shape.
	 */
	public function test_unified_team_request_returns_chat_client_payload_shape() {
		$profession_one = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Architect',
				'post_status' => 'publish',
			)
		);
		$profession_two = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_profession',
				'post_title'  => 'Engineer',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $profession_one, '_wp_mcp_ai_profession_default_provider', 'openai' );
		update_post_meta( $profession_one, '_wp_mcp_ai_profession_default_model', 'gpt-4' );
		update_post_meta( $profession_two, '_wp_mcp_ai_profession_default_provider', 'openai' );
		update_post_meta( $profession_two, '_wp_mcp_ai_profession_default_model', 'gpt-4' );

		$team_id = wp_insert_post(
			array(
				'post_type'   => 'mcp_ai_team',
				'post_title'  => 'Delivery Team',
				'post_status' => 'publish',
			)
		);

		update_post_meta( $team_id, '_wp_mcp_ai_team_members', array( $profession_one, $profession_two ) );
		update_post_meta( $team_id, '_wp_mcp_ai_team_result_aggregation', 'consensus' );
		update_post_meta( $team_id, '_wp_mcp_ai_team_orchestration_mode', 'sequential' );

		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_param( 'assistant_id', 'unified_team_' . $team_id );
		$request->set_param(
			'messages',
			array(
				array(
					'role'    => 'user',
					'content' => 'Plan the launch.',
				),
			)
		);

		$response = $this->rest_controller->handle_chat_request( $request );

		$this->assertNotWPError( $response );

		$data = $response->get_data();

		$this->assertSame( 'unified_team_' . $team_id, $data['assistant_id'] );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'choices', $data['data'] );
		$this->assertArrayHasKey( 'message', $data['data']['choices'][0] );
		$this->assertSame( 'assistant', $data['data']['choices'][0]['message']['role'] );
		$this->assertStringContainsString( 'Team Response', $data['data']['choices'][0]['message']['content'] );
		$this->assertSame( $team_id, $data['data']['choices'][0]['message']['metadata']['team_id'] );
	}
}

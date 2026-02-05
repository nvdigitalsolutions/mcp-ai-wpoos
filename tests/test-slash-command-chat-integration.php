<?php
/**
 * Test slash command integration with chat client.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 */

/**
 * Test slash command chat integration.
 */
class Test_Slash_Command_Chat_Integration extends WP_UnitTestCase {

	/**
	 * Test slash command script registration.
	 */
	public function test_slash_command_script_registered() {
		// Initialize slash commands.
		do_action( 'init' );

		// Check if script is registered.
		$this->assertTrue( wp_script_is( 'mcp-ai-slash-commands', 'registered' ), 'Slash commands script should be registered' );
		$this->assertTrue( wp_script_is( 'mcp-ai-command-autocomplete', 'registered' ), 'Command autocomplete script should be registered' );
	}

	/**
	 * Test script localization data.
	 */
	public function test_script_localization() {
		// Initialize slash commands.
		do_action( 'init' );

		// Get localized data.
		global $wp_scripts;
		$script_data = $wp_scripts->get_data( 'mcp-ai-slash-commands', 'data' );

		// Check if mcpAiData is localized.
		$this->assertStringContainsString( 'mcpAiData', $script_data, 'Script should have mcpAiData localized' );
		$this->assertStringContainsString( 'restUrl', $script_data, 'Script should have restUrl in localized data' );
		$this->assertStringContainsString( 'nonce', $script_data, 'Script should have nonce in localized data' );
	}

	/**
	 * Test slash command REST endpoint exists.
	 */
	public function test_slash_command_rest_endpoint() {
		// Get REST server.
		$rest_server = rest_get_server();
		$routes      = $rest_server->get_routes();

		// Check if slash command endpoint exists.
		$this->assertArrayHasKey( '/mcp-ai/v1/slash-command', $routes, 'Slash command REST endpoint should exist' );
		$this->assertArrayHasKey( '/mcp-ai/v1/slash-command/list', $routes, 'Slash command list REST endpoint should exist' );
	}

	/**
	 * Test slash command execution via REST API.
	 */
	public function test_slash_command_rest_execution() {
		// Create user with appropriate capabilities.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create REST request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/slash-command' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'command' => '/help',
			)
		);

		// Execute request.
		$response = rest_do_request( $request );

		// Check response.
		$this->assertEquals( 200, $response->get_status(), 'Slash command execution should return 200 status' );
		$data = $response->get_data();
		$this->assertTrue( $data['success'], 'Slash command execution should be successful' );
		$this->assertNotEmpty( $data['result'], 'Slash command should return a result' );
	}

	/**
	 * Test slash command handler initialization.
	 */
	public function test_slash_command_handler_initialized() {
		// Initialize slash commands.
		do_action( 'init' );

		$handler = wp_mcp_ai_get_slash_command_handler();

		$this->assertNotNull( $handler, 'Slash command handler should be initialized' );
		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Handler', $handler, 'Handler should be instance of WP_MCP_AI_Slash_Command_Handler' );
	}

	/**
	 * Test slash command enqueuing when chat is loaded.
	 */
	public function test_slash_command_enqueuing_with_chat() {
		// Initialize slash commands.
		do_action( 'init' );

		// Simulate chat script being enqueued.
		wp_enqueue_script( 'wp-mcp-ai-chat' );

		// Trigger enqueue action.
		do_action( 'wp_enqueue_scripts' );

		// Check if slash commands are enqueued.
		$this->assertTrue( wp_script_is( 'mcp-ai-slash-commands', 'enqueued' ), 'Slash commands should be enqueued when chat is loaded' );
		$this->assertTrue( wp_script_is( 'mcp-ai-command-autocomplete', 'enqueued' ), 'Command autocomplete should be enqueued when chat is loaded' );
	}

	/**
	 * Test slash command NOT enqueuing without chat.
	 */
	public function test_slash_command_not_enqueuing_without_chat() {
		// Initialize slash commands.
		do_action( 'init' );

		// Trigger enqueue action WITHOUT chat script.
		do_action( 'wp_enqueue_scripts' );

		// Check if slash commands are NOT enqueued.
		$this->assertFalse( wp_script_is( 'mcp-ai-slash-commands', 'enqueued' ), 'Slash commands should NOT be enqueued when chat is not loaded' );
	}

	/**
	 * Test slash command list endpoint.
	 */
	public function test_slash_command_list_endpoint() {
		// Create user with appropriate capabilities.
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		// Create REST request.
		$request = new WP_REST_Request( 'GET', '/mcp-ai/v1/slash-command/list' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

		// Execute request.
		$response = rest_do_request( $request );

		// Check response.
		$this->assertEquals( 200, $response->get_status(), 'Slash command list should return 200 status' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'commands', $data, 'Response should contain commands array' );
		$this->assertNotEmpty( $data['commands'], 'Should have at least one command registered' );
	}

	/**
	 * Test slash command without permission.
	 */
	public function test_slash_command_without_permission() {
		// Create user without appropriate capabilities.
		$user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		// Create REST request for a restricted command.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/slash-command' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params(
			array(
				'command' => '/ship',
			)
		);

		// Execute request.
		$response = rest_do_request( $request );

		// Check response - should be successful but command should fail due to permission.
		$data = $response->get_data();
		$this->assertFalse( $data['success'], 'Slash command should fail without proper permissions' );
	}
}

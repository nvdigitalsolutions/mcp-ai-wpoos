<?php
/**
 * Test slash command integration with chat client.
 *
 * @package WP_MCP_AI
 * @subpackage Tests
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

/**
 * Test slash command chat integration.
 */
class Test_Slash_Command_Chat_Integration extends WP_UnitTestCase {

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Initialise the slash command system directly. Firing the full
		// 'init' action in unit tests re-runs WooCommerce and block
		// registrations and produces incorrect-usage notices.
		wp_mcp_ai_init_slash_commands();

		// Reset the script queue so enqueue assertions are not affected by
		// leftovers from previous tests. Dequeueing via the public API also
		// invalidates WP_Dependencies' internal dependency memo.
		global $wp_scripts;
		foreach ( $wp_scripts->queue as $handle ) {
			wp_dequeue_script( $handle );
		}
	}

	/**
	 * Test slash command script registration.
	 */
	public function test_slash_command_script_registered() {
		// Check if script is registered.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-slash-commands', 'registered' ), 'Slash commands script should be registered' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-command-autocomplete', 'registered' ), 'Command autocomplete script should be registered' );
	}

	/**
	 * Test script localization data.
	 */
	public function test_script_localization() {
		// Get localized data.
		global $wp_scripts;
		$script_data = $wp_scripts->get_data( 'wp-mcp-ai-slash-commands', 'data' );

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
		$handler = wp_mcp_ai_get_slash_command_handler();

		$this->assertNotNull( $handler, 'Slash command handler should be initialized' );
		$this->assertInstanceOf( 'WP_MCP_AI_Slash_Command_Handler', $handler, 'Handler should be instance of WP_MCP_AI_Slash_Command_Handler' );
	}

	/**
	 * Test slash command enqueuing when chat is loaded.
	 */
	public function test_slash_command_enqueuing_with_chat() {
		// The shortcode renderer enqueues the slash commands script when the
		// chat interface is loaded and the script is registered.
		wp_enqueue_script( 'wp-mcp-ai-chat' );

		if ( wp_script_is( 'wp-mcp-ai-slash-commands', 'registered' ) ) {
			wp_enqueue_script( 'wp-mcp-ai-slash-commands' );
		}

		// Check if slash commands are enqueued.
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-slash-commands', 'enqueued' ), 'Slash commands should be enqueued when chat is loaded' );
		$this->assertTrue( wp_script_is( 'wp-mcp-ai-command-autocomplete', 'enqueued' ), 'Command autocomplete should be enqueued when chat is loaded' );
	}

	/**
	 * Test slash command NOT enqueuing without chat.
	 */
	public function test_slash_command_not_enqueuing_without_chat() {
		// Registration alone must not enqueue the script: it is only
		// enqueued by the shortcode renderer when the chat is loaded.
		$this->assertFalse( wp_script_is( 'wp-mcp-ai-slash-commands', 'enqueued' ), 'Slash commands should NOT be enqueued when chat is not loaded' );
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

		// The command handler rejects the subscriber and the controller
		// propagates the error; the REST server wraps it into a 400 response.
		$this->assertEquals( 400, $response->get_status(), 'Slash command should fail without proper permissions' );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'code', $data, 'Error response should carry an error code' );
		$this->assertEquals( 'insufficient_capability', $data['code'] );
	}
}

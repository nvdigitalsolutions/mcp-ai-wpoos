<?php
/**
 * Integration test for chat-client agentic workflow parameter filtering.
 *
 * This test demonstrates that the fix prevents "Invalid parameter(s): messages"
 * errors when AI providers include extra parameters during tool execution.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test the full agentic workflow with parameter filtering.
 */
class WP_MCP_AI_Chat_Client_Agentic_Workflow_Test extends WP_UnitTestCase {

	/**
	 * Test that count_tokens tool executes successfully with filtered parameters.
	 *
	 * This simulates what happens in the agentic workflow when an AI provider
	 * calls the count_tokens tool but includes extra parameters like 'messages'
	 * from the chat context.
	 */
	public function test_count_tokens_with_extra_parameters() {
		// Create an admin user for tool execution.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Get the count_tokens tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool = $registry->get_tool( 'count_tokens' );

		$this->assertNotNull( $tool, 'count_tokens tool should be registered' );

		// Verify the tool has strict schema (additionalProperties => false).
		$schema = $tool->get_parameters_schema();
		$this->assertArrayHasKey( 'additionalProperties', $schema );
		$this->assertFalse( $schema['additionalProperties'] );

		// Execute the tool with extra parameters that would normally cause errors.
		// Before the fix, this would fail with "Invalid parameter(s): messages".
		// After the fix, the extra parameters should be filtered out automatically.
		$arguments = array(
			'text'     => 'Hello world, this is a test message.',
			'method'   => 'heuristic',
			// Extra parameters that should be filtered out:
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => 'Previous message',
				),
			),
			'extra'    => 'Should be ignored',
		);

		$context = array(
			'user_id' => $admin_id,
		);

		// Before the fix, this would likely fail or return unexpected results.
		// After the fix, it should work correctly with only the valid parameters.
		$result = $tool->execute( $arguments, $context );

		// Verify the tool executed successfully.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Tool should not return an error' );

		// Verify the result contains expected token count data.
		$this->assertArrayHasKey( 'estimated_tokens', $result, 'Result should contain estimated_tokens' );
		$this->assertIsInt( $result['estimated_tokens'], 'estimated_tokens should be an integer' );
		$this->assertGreaterThan( 0, $result['estimated_tokens'], 'Token count should be positive' );
	}

	/**
	 * Test that tools without strict schemas still work normally.
	 *
	 * Tools like get_user_info that don't have additionalProperties => false
	 * should continue to work as before, with no parameter filtering.
	 */
	public function test_get_user_info_without_filtering() {
		// Create an admin user.
		$admin_id = $this->factory->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Test Admin',
			)
		);
		wp_set_current_user( $admin_id );

		// Get the get_user_info tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool = $registry->get_tool( 'get_user_info' );

		$this->assertNotNull( $tool, 'get_user_info tool should be registered' );

		// Execute with extra parameters - these should pass through unchanged.
		$arguments = array(
			'user_id'  => $admin_id,
			'messages' => 'Extra parameter that should be preserved',
		);

		$context = array(
			'user_id' => $admin_id,
		);

		$result = $tool->execute( $arguments, $context );

		// Verify the tool executed successfully.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertNotInstanceOf( 'WP_Error', $result, 'Tool should not return an error' );
		$this->assertArrayHasKey( 'ID', $result );
		$this->assertSame( $admin_id, $result['ID'] );
	}

	/**
	 * Test that the filtering happens at the REST API level.
	 *
	 * This verifies that the filter_tool_arguments_by_schema method is called
	 * as part of the normal tool execution flow in execute_tool_call_internal.
	 */
	public function test_filtering_in_rest_api_flow() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		// Create a mock WP_REST_Request.
		$request = new WP_REST_Request( 'POST', '/mcp-ai/v1/chat-client' );
		$request->set_param( 'assistant_id', 1 );

		// Create an admin user.
		$admin_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// Get the count_tokens tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool = $registry->get_tool( 'count_tokens' );

		$this->assertNotNull( $tool );

		// Create a REST controller instance.
		$rest = new WP_MCP_AI_REST();

		// Simulate a tool call with extra parameters.
		$tool_call = array(
			'id'       => 'call_123',
			'function' => array(
				'name'      => 'count_tokens',
				'arguments' => wp_json_encode(
					array(
						'text'     => 'Test message',
						'method'   => 'heuristic',
						'messages' => 'Extra parameter', // Should be filtered out.
					)
				),
			),
		);

		// Create minimal assistant config.
		$assistant_config = array(
			'tools' => array( 'count_tokens' ),
			'model' => 'gpt-4o-mini',
		);

		// Use reflection to call the protected execute_tool_call_internal method.
		$reflection = new ReflectionClass( $rest );
		$method     = $reflection->getMethod( 'execute_tool_call_internal' );
		$method->setAccessible( true );

		// Execute the tool call through the REST API flow.
		$result = $method->invoke(
			$rest,
			$tool_call,
			1, // assistant_id
			$assistant_config,
			$admin_id,
			$request,
			0, // iteration
			5  // max_iterations
		);

		// Verify the tool executed successfully despite the extra parameter.
		$this->assertIsArray( $result, 'Result should be an array' );
		$this->assertArrayHasKey( 'estimated_tokens', $result, 'Result should contain estimated_tokens' );
	}
}

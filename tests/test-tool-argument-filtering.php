<?php
/**
 * Tests for tool argument filtering in agentic workflow.
 *
 * @package WP_MCP_AI
 */

/**
 * Test that extra parameters are filtered out for tools with additionalProperties => false.
 *
 * This test verifies the fix for the "Invalid parameter(s): messages" issue
 * that occurs in the chat-client agentic workflow when AI providers include
 * extra parameters that aren't in the tool's schema.
 */
class WP_MCP_AI_Tool_Argument_Filtering_Test extends WP_UnitTestCase {

	/**
	 * Test that extra parameters are filtered out when additionalProperties is false.
	 *
	 * The count_tokens tool has additionalProperties => false, so extra parameters
	 * like 'messages' (from the chat context) should be filtered out before execution.
	 */
	public function test_filters_extra_parameters_for_strict_schema() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$rest_controller = new WP_MCP_AI_REST();

		// Get the count_tokens tool which has additionalProperties => false.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool = $registry->get_tool( 'count_tokens' );

		$this->assertNotNull( $tool, 'count_tokens tool should be registered' );

		// Verify the tool has additionalProperties => false.
		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema, 'Schema should be an array' );
		$this->assertArrayHasKey( 'additionalProperties', $schema );
		$this->assertFalse( $schema['additionalProperties'], 'count_tokens should have additionalProperties => false' );

		// Create arguments with extra parameters (simulating AI provider behavior).
		$arguments = array(
			'text'     => 'Hello world',
			'method'   => 'heuristic',
			'messages' => 'This extra parameter should be filtered out',
			'extra'    => array( 'data' => 'value' ),
			'foo'      => 'bar',
		);

		// Use reflection to access the protected filter_tool_arguments_by_schema method.
		$reflection = new ReflectionClass( $rest_controller );
		$method     = $reflection->getMethod( 'filter_tool_arguments_by_schema' );
		$method->setAccessible( true );

		// Filter the arguments.
		$filtered = $method->invoke( $rest_controller, $tool, $arguments );

		// Verify that only schema-defined parameters remain.
		$this->assertIsArray( $filtered, 'Filtered result should be an array' );
		$this->assertArrayHasKey( 'text', $filtered, 'text parameter should be preserved' );
		$this->assertArrayHasKey( 'method', $filtered, 'method parameter should be preserved' );
		$this->assertArrayNotHasKey( 'messages', $filtered, 'messages parameter should be filtered out' );
		$this->assertArrayNotHasKey( 'extra', $filtered, 'extra parameter should be filtered out' );
		$this->assertArrayNotHasKey( 'foo', $filtered, 'foo parameter should be filtered out' );
		$this->assertSame( 'Hello world', $filtered['text'] );
		$this->assertSame( 'heuristic', $filtered['method'] );
	}

	/**
	 * Test that parameters are NOT filtered when additionalProperties is not false.
	 *
	 * The get_user_info tool does NOT have additionalProperties => false,
	 * so all parameters should pass through unfiltered.
	 */
	public function test_does_not_filter_when_additional_properties_allowed() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$rest_controller = new WP_MCP_AI_REST();

		// Get the get_user_info tool which does NOT have additionalProperties => false.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool = $registry->get_tool( 'get_user_info' );

		$this->assertNotNull( $tool, 'get_user_info tool should be registered' );

		// Verify the tool does NOT have additionalProperties => false.
		$schema = $tool->get_parameters_schema();
		$this->assertIsArray( $schema, 'Schema should be an array' );
		if ( isset( $schema['additionalProperties'] ) ) {
			$this->assertNotFalse( $schema['additionalProperties'], 'get_user_info should allow additional properties' );
		}

		// Create arguments with extra parameters.
		$arguments = array(
			'user_id'  => 123,
			'messages' => 'This extra parameter should NOT be filtered',
			'extra'    => array( 'data' => 'value' ),
		);

		// Use reflection to access the protected filter_tool_arguments_by_schema method.
		$reflection = new ReflectionClass( $rest_controller );
		$method     = $reflection->getMethod( 'filter_tool_arguments_by_schema' );
		$method->setAccessible( true );

		// Filter the arguments.
		$filtered = $method->invoke( $rest_controller, $tool, $arguments );

		// Verify that ALL parameters remain (no filtering).
		$this->assertIsArray( $filtered, 'Filtered result should be an array' );
		$this->assertArrayHasKey( 'user_id', $filtered, 'user_id parameter should be preserved' );
		$this->assertArrayHasKey( 'messages', $filtered, 'messages parameter should be preserved' );
		$this->assertArrayHasKey( 'extra', $filtered, 'extra parameter should be preserved' );
		$this->assertSame( 123, $filtered['user_id'] );
		$this->assertSame( 'This extra parameter should NOT be filtered', $filtered['messages'] );
	}

	/**
	 * Test that filtering works with empty arguments.
	 */
	public function test_filters_empty_arguments() {
		// Load the REST class.
		require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-rest.php';
		require_once WP_MCP_AI_PATH . 'includes/rest/class-wp-mcp-ai-rest-validator.php';

		$rest_controller = new WP_MCP_AI_REST();

		// Get a tool with additionalProperties => false.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();
		$tool = $registry->get_tool( 'count_tokens' );

		// Use reflection to access the protected filter_tool_arguments_by_schema method.
		$reflection = new ReflectionClass( $rest_controller );
		$method     = $reflection->getMethod( 'filter_tool_arguments_by_schema' );
		$method->setAccessible( true );

		// Filter empty arguments.
		$filtered = $method->invoke( $rest_controller, $tool, array() );

		// Verify empty array is returned.
		$this->assertIsArray( $filtered, 'Filtered result should be an array' );
		$this->assertEmpty( $filtered, 'Empty arguments should remain empty' );
	}
}

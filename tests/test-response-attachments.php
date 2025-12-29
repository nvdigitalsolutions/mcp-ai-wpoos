<?php
/**
 * Tests for WP_MCP_AI_Response_Attachments class.
 *
 * @package WP_MCP_AI
 */

/**
 * Test class for response attachments tests.
 *
 * @group response-attachments
 */
class WP_MCP_AI_Response_Attachments_Tests extends WP_UnitTestCase {

	/**
	 * Test init registers hooks.
	 */
	public function test_init_registers_hooks() {
		// Reset init state using reflection.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Response_Attachments' );
		$property   = $reflection->getProperty( 'initialised' );
		$property->setAccessible( true );
		$property->setValue( null, false );

		WP_MCP_AI_Response_Attachments::init();

		$this->assertTrue(
			has_action( 'wp_mcp_ai_after_chat_response', array( 'WP_MCP_AI_Response_Attachments', 'handle_chat_response' ) ) !== false
		);
	}

	/**
	 * Test init is idempotent.
	 */
	public function test_init_is_idempotent() {
		WP_MCP_AI_Response_Attachments::init();
		$priority1 = has_action( 'wp_mcp_ai_after_chat_response', array( 'WP_MCP_AI_Response_Attachments', 'handle_chat_response' ) );

		WP_MCP_AI_Response_Attachments::init();
		$priority2 = has_action( 'wp_mcp_ai_after_chat_response', array( 'WP_MCP_AI_Response_Attachments', 'handle_chat_response' ) );

		$this->assertEquals( $priority1, $priority2 );
	}

	/**
	 * Test handle_chat_response with empty response.
	 */
	public function test_handle_chat_response_with_empty_response() {
		$request = new WP_REST_Request( 'POST', '/wp-mcp-ai/v1/chat' );

		// Should not throw errors with empty response.
		WP_MCP_AI_Response_Attachments::handle_chat_response( 1, array(), $request );
		WP_MCP_AI_Response_Attachments::handle_chat_response( 1, null, $request );

		// Test passes if no errors thrown.
		$this->assertTrue( true );
	}

	/**
	 * Test handle_chat_response with response without files.
	 */
	public function test_handle_chat_response_without_files() {
		$request  = new WP_REST_Request( 'POST', '/wp-mcp-ai/v1/chat' );
		$response = array(
			'choices' => array(
				array(
					'message' => array(
						'role'    => 'assistant',
						'content' => 'Simple text response',
					),
				),
			),
		);

		// Should not throw errors with response without files.
		WP_MCP_AI_Response_Attachments::handle_chat_response( 1, $response, $request );

		// Test passes if no errors thrown.
		$this->assertTrue( true );
	}

	/**
	 * Test collect_file_segments_from_response method exists and is accessible.
	 */
	public function test_collect_file_segments_method_exists() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Response_Attachments' );
		$this->assertTrue( $reflection->hasMethod( 'collect_file_segments_from_response' ) );
	}

	/**
	 * Test extract_file_id_from_segment method exists.
	 */
	public function test_extract_file_id_method_exists() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Response_Attachments' );
		$this->assertTrue( $reflection->hasMethod( 'extract_file_id_from_segment' ) );
	}

	/**
	 * Test store_downloaded_file method exists.
	 */
	public function test_store_downloaded_file_method_exists() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Response_Attachments' );
		$this->assertTrue( $reflection->hasMethod( 'store_downloaded_file' ) );
	}

	/**
	 * Test that class can be instantiated (for coverage).
	 */
	public function test_class_structure() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Response_Attachments' );

		// Verify it's a class.
		$this->assertTrue( $reflection->isInstantiable() );

		// Verify it has the init method.
		$this->assertTrue( $reflection->hasMethod( 'init' ) );
		$this->assertTrue( $reflection->getMethod( 'init' )->isStatic() );
		$this->assertTrue( $reflection->getMethod( 'init' )->isPublic() );
	}

	/**
	 * Test handle_chat_response with malformed response structure.
	 */
	public function test_handle_chat_response_with_malformed_response() {
		$request  = new WP_REST_Request( 'POST', '/wp-mcp-ai/v1/chat' );
		$response = array(
			'choices' => 'not an array',
		);

		// Should handle malformed data gracefully.
		WP_MCP_AI_Response_Attachments::handle_chat_response( 1, $response, $request );

		// Test passes if no errors thrown.
		$this->assertTrue( true );
	}

	/**
	 * Test that action hook is properly named.
	 */
	public function test_action_hook_name() {
		WP_MCP_AI_Response_Attachments::init();

		$this->assertGreaterThan(
			0,
			has_action( 'wp_mcp_ai_after_chat_response', array( 'WP_MCP_AI_Response_Attachments', 'handle_chat_response' ) ),
			'Action hook wp_mcp_ai_after_chat_response should be registered'
		);
	}

	/**
	 * Test that handle_chat_response accepts correct parameters.
	 */
	public function test_handle_chat_response_parameters() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Response_Attachments' );
		$method     = $reflection->getMethod( 'handle_chat_response' );

		$this->assertEquals( 3, $method->getNumberOfParameters() );

		$params = $method->getParameters();
		$this->assertEquals( 'assistant_id', $params[0]->getName() );
		$this->assertEquals( 'response', $params[1]->getName() );
		$this->assertEquals( 'request', $params[2]->getName() );
	}

	/**
	 * Test static initialization property exists.
	 */
	public function test_initialised_property_exists() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Response_Attachments' );
		$this->assertTrue( $reflection->hasProperty( 'initialised' ) );

		$property = $reflection->getProperty( 'initialised' );
		$this->assertTrue( $property->isProtected() );
		$this->assertTrue( $property->isStatic() );
	}

	/**
	 * Test that init can be called multiple times without side effects.
	 */
	public function test_multiple_init_calls() {
		// Reset state.
		$reflection = new ReflectionClass( 'WP_MCP_AI_Response_Attachments' );
		$property   = $reflection->getProperty( 'initialised' );
		$property->setAccessible( true );
		$property->setValue( null, false );

		// Call init multiple times.
		WP_MCP_AI_Response_Attachments::init();
		WP_MCP_AI_Response_Attachments::init();
		WP_MCP_AI_Response_Attachments::init();

		// Should only register action once.
		global $wp_filter;
		if ( isset( $wp_filter['wp_mcp_ai_after_chat_response'] ) ) {
			$callbacks = $wp_filter['wp_mcp_ai_after_chat_response']->callbacks;
			if ( isset( $callbacks[10] ) ) {
				$count = 0;
				foreach ( $callbacks[10] as $callback ) {
					if ( isset( $callback['function'] ) && is_array( $callback['function'] ) ) {
						if ( 'WP_MCP_AI_Response_Attachments' === $callback['function'][0] &&
							'handle_chat_response' === $callback['function'][1] ) {
							++$count;
						}
					}
				}
				$this->assertEquals( 1, $count, 'Action should only be registered once' );
			}
		}

		$this->assertTrue( true );
	}
}

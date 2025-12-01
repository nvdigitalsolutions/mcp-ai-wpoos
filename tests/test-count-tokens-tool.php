<?php
/**
 * Tests for the Count Tokens tool.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Test the Count Tokens tool functionality.
 */
class WP_MCP_AI_Count_Tokens_Tool_Test extends WP_UnitTestCase {

	/**
	 * Test that the count_tokens tool is registered.
	 */
	public function test_count_tokens_tool_is_registered() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'count_tokens' );

		$this->assertNotNull( $tool, 'The count_tokens tool should be registered by default.' );
		$this->assertInstanceOf( WP_MCP_AI_Tool_Interface::class, $tool );
	}

	/**
	 * Test counting tokens for plain text.
	 */
	public function test_count_tokens_for_text() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $tool->execute(
			array(
				'text' => 'This is a test message for token counting.',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result, 'Result should be an array.' );
		$this->assertArrayHasKey( 'estimated_tokens', $result );
		$this->assertArrayHasKey( 'details', $result );
		$this->assertArrayHasKey( 'disclaimer', $result );
		$this->assertGreaterThan( 0, $result['estimated_tokens'] );
		$this->assertSame( 'text', $result['details']['type'] );
	}

	/**
	 * Test counting tokens for messages array.
	 */
	public function test_count_tokens_for_messages() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are a helpful assistant.',
			),
			array(
				'role'    => 'user',
				'content' => 'Hello, how are you?',
			),
			array(
				'role'    => 'assistant',
				'content' => 'I am doing well, thank you!',
			),
		);

		$result = $tool->execute(
			array( 'messages' => $messages ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'estimated_tokens', $result );
		$this->assertArrayHasKey( 'details', $result );
		$this->assertGreaterThan( 0, $result['estimated_tokens'] );
		$this->assertSame( 'messages', $result['details']['type'] );
		$this->assertSame( 3, $result['details']['message_count'] );
	}

	/**
	 * Test counting tokens with model information.
	 */
	public function test_count_tokens_with_model_info() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $tool->execute(
			array(
				'text'  => 'Test message',
				'model' => 'gpt-4o-mini',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'model_info', $result );
		$this->assertArrayHasKey( 'budget_info', $result );
		$this->assertSame( 'gpt-4o-mini', $result['model_info']['model'] );
		$this->assertGreaterThan( 0, $result['model_info']['context_limit_tokens'] );
		$this->assertArrayHasKey( 'safe_limit_tokens', $result['budget_info'] );
		$this->assertArrayHasKey( 'remaining_tokens', $result['budget_info'] );
		$this->assertArrayHasKey( 'recommendation', $result['budget_info'] );
	}

	/**
	 * Test that unauthenticated users cannot use the tool.
	 */
	public function test_count_tokens_requires_authentication() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool = $registry->get_tool( 'count_tokens' );

		$result = $tool->execute(
			array( 'text' => 'Test message' ),
			array( 'user_id' => 0 )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_unauthorized', $result->get_error_code() );
	}

	/**
	 * Test that providing neither text nor messages returns an error.
	 */
	public function test_count_tokens_requires_text_or_messages() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $tool->execute(
			array(),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_arguments', $result->get_error_code() );
	}

	/**
	 * Test that providing both text and messages returns an error.
	 */
	public function test_count_tokens_rejects_both_text_and_messages() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $tool->execute(
			array(
				'text'     => 'Test message',
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Hello',
					),
				),
			),
			array( 'user_id' => $user_id )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wp_mcp_ai_invalid_arguments', $result->get_error_code() );
	}

	/**
	 * Test that empty text is handled gracefully.
	 */
	public function test_count_tokens_handles_empty_text() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $tool->execute(
			array( 'text' => '' ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'estimated_tokens', $result );
		$this->assertSame( 0, $result['estimated_tokens'] );
	}

	/**
	 * Test that empty messages array is handled gracefully.
	 */
	public function test_count_tokens_handles_empty_messages() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$result = $tool->execute(
			array( 'messages' => array() ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'estimated_tokens', $result );
		$this->assertSame( 0, $result['estimated_tokens'] );
		$this->assertSame( 0, $result['details']['message_count'] );
	}

	/**
	 * Test that malformed messages are filtered out.
	 */
	public function test_count_tokens_filters_malformed_messages() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Valid message',
			),
			'invalid message',
			array(
				'content' => 'Missing role',
			),
			array(
				'role'    => 'assistant',
				'content' => 'Another valid message',
			),
		);

		$result = $tool->execute(
			array( 'messages' => $messages ),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['details']['message_count'], 'Should only count valid messages.' );
	}

	/**
	 * Test that budget info correctly identifies when token count exceeds safe limit.
	 */
	public function test_count_tokens_budget_exceeds_safe_limit() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool    = $registry->get_tool( 'count_tokens' );
		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );

		// Create a very long text to exceed safe limits.
		$long_text = str_repeat( 'This is a long test message. ', 100000 );

		$result = $tool->execute(
			array(
				'text'  => $long_text,
				'model' => 'gpt-4o-mini',
			),
			array( 'user_id' => $user_id )
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'budget_info', $result );
		$this->assertTrue( $result['budget_info']['exceeds_safe_limit'], 'Should flag as exceeding safe limit.' );
		$this->assertStringContainsString( 'exceeds', $result['budget_info']['recommendation'] );
	}

	/**
	 * Test that the tool exposes proper parameter schema.
	 */
	public function test_count_tokens_parameter_schema() {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$registry->init();

		$tool   = $registry->get_tool( 'count_tokens' );
		$schema = $tool->get_parameters_schema();

		$this->assertIsArray( $schema );
		$this->assertArrayHasKey( 'type', $schema );
		$this->assertSame( 'object', $schema['type'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'text', $schema['properties'] );
		$this->assertArrayHasKey( 'messages', $schema['properties'] );
		$this->assertArrayHasKey( 'model', $schema['properties'] );
	}
}

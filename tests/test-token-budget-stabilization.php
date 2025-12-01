<?php
/**
 * Test WP_MCP_AI_Token_Budget_Manager enhancements.
 *
 *
 * @package WP_MCP_AI
 */

/**
 * Class Test_WP_MCP_AI_Token_Budget_Manager_Stabilization
 */
class Test_WP_MCP_AI_Token_Budget_Manager_Stabilization extends WP_UnitTestCase {

	/**
	 * Test that validate_input_tokens accepts requests within limit.
	 */
	public function test_validate_input_tokens_within_limit() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'This is a normal message that is well within the token limit.',
			),
		);

		$result = WP_MCP_AI_Token_Budget_Manager::validate_input_tokens( $messages, 'gpt-4o-mini' );

		$this->assertTrue( $result, 'Normal messages should pass validation' );
	}

	/**
	 * Test that validate_input_tokens rejects requests exceeding 12k limit.
	 */
	public function test_validate_input_tokens_exceeds_limit() {
		// Generate a message that exceeds 12k tokens (~48k chars).
		$long_text = str_repeat( 'This is a very long message with lots and lots of content. ', 1000 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => $long_text,
			),
		);

		$result = WP_MCP_AI_Token_Budget_Manager::validate_input_tokens( $messages, 'gpt-4o-mini' );

		$this->assertWPError( $result, 'Messages exceeding 12k tokens should return WP_Error' );
		$this->assertEquals( 'wp_mcp_ai_input_tokens_exceeded', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertArrayHasKey( 'used_tokens', $error_data );
		$this->assertArrayHasKey( 'max_tokens', $error_data );
		$this->assertEquals( 12000, $error_data['max_tokens'] );
	}

	/**
	 * Test get_recommended_chunk_size returns correct value.
	 */
	public function test_get_recommended_chunk_size() {
		$chunk_size = WP_MCP_AI_Token_Budget_Manager::get_recommended_chunk_size();

		$this->assertEquals( 7000, $chunk_size, 'Recommended chunk size should be 7000 (6-8k range)' );
	}

	/**
	 * Test that chunk size can be filtered.
	 */
	public function test_recommended_chunk_size_filter() {
		add_filter(
			'wp_mcp_ai_recommended_chunk_size',
			function ( $size, $model ) {
				return 8000;
			},
			10,
			2
		);

		$chunk_size = WP_MCP_AI_Token_Budget_Manager::get_recommended_chunk_size( 'gpt-4o' );

		$this->assertEquals( 8000, $chunk_size, 'Chunk size should be filterable' );

		remove_all_filters( 'wp_mcp_ai_recommended_chunk_size' );
	}

	/**
	 * Test document splitting with recommended chunk size.
	 */
	public function test_split_document_with_recommended_size() {
		// Generate a document that should be split into chunks.
		$content = str_repeat( 'This is a sentence in the document. ', 800 );

		$chunks = WP_MCP_AI_Token_Budget_Manager::split_document( $content, 7000, 200 );

		$this->assertIsArray( $chunks, 'split_document should return array' );
		$this->assertGreaterThan( 1, count( $chunks ), 'Large document should be split into multiple chunks' );

		// Each chunk should be roughly within the target size.
		foreach ( $chunks as $chunk ) {
			$token_count = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $chunk );
			$this->assertLessThanOrEqual( 7500, $token_count, 'Chunk should not significantly exceed target size' );
		}
	}

	/**
	 * Test that DEFAULT_CHUNK_SIZE is 7000 tokens.
	 */
	public function test_default_chunk_size_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Token_Budget_Manager' );
		$constant   = $reflection->getConstant( 'DEFAULT_CHUNK_SIZE' );

		$this->assertEquals( 7000, $constant, 'DEFAULT_CHUNK_SIZE should be 7000' );
	}

	/**
	 * Test that DEFAULT_CHUNK_OVERLAP is 200 tokens.
	 */
	public function test_default_chunk_overlap_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Token_Budget_Manager' );
		$constant   = $reflection->getConstant( 'DEFAULT_CHUNK_OVERLAP' );

		$this->assertEquals( 200, $constant, 'DEFAULT_CHUNK_OVERLAP should be 200' );
	}

	/**
	 * Test that MAX_INPUT_TOKENS is 12000.
	 */
	public function test_max_input_tokens_constant() {
		$reflection = new ReflectionClass( 'WP_MCP_AI_Token_Budget_Manager' );
		$constant   = $reflection->getConstant( 'MAX_INPUT_TOKENS' );

		$this->assertEquals( 12000, $constant, 'MAX_INPUT_TOKENS should be 12000' );
	}

	/**
	 * Test token estimation for various inputs.
	 */
	public function test_estimate_tokens() {
		$test_cases = array(
			array(
				'text' => 'Hello, world!',
				'min'  => 2,
				'max'  => 5,
			),
			array(
				'text' => str_repeat( 'word ', 100 ),
				'min'  => 80,
				'max'  => 150,
			),
			array(
				'text' => '',
				'min'  => 0,
				'max'  => 0,
			),
		);

		foreach ( $test_cases as $case ) {
			$tokens = WP_MCP_AI_Token_Budget_Manager::estimate_tokens( $case['text'] );

			$this->assertGreaterThanOrEqual(
				$case['min'],
				$tokens,
				"Token estimate should be at least {$case['min']}"
			);

			$this->assertLessThanOrEqual(
				$case['max'],
				$tokens,
				"Token estimate should not exceed {$case['max']}"
			);
		}
	}

	/**
	 * Test calculate_budget respects MAX_INPUT_TOKENS.
	 */
	public function test_calculate_budget_awareness() {
		$messages = array(
			array(
				'role'    => 'user',
				'content' => str_repeat( 'test ', 3000 ),
			),
		);

		$budget = WP_MCP_AI_Token_Budget_Manager::calculate_budget( 'gpt-4o-mini', $messages );

		$this->assertIsArray( $budget );
		$this->assertArrayHasKey( 'used', $budget );
		$this->assertArrayHasKey( 'available', $budget );
		$this->assertArrayHasKey( 'limit', $budget );
		$this->assertArrayHasKey( 'model', $budget );

		// Verify that limit accounts for safety margin.
		$this->assertLessThan( 128000, $budget['limit'], 'Limit should include safety margin' );
	}

	/**
	 * Test validation with conversation history.
	 */
	public function test_validate_with_conversation_history() {
		$messages = array();

		// Add multiple messages to build up token count.
		for ( $i = 0; $i < 50; $i++ ) {
			$messages[] = array(
				'role'    => $i % 2 === 0 ? 'user' : 'assistant',
				'content' => str_repeat( 'This is message content. ', 50 ),
			);
		}

		$budget = WP_MCP_AI_Token_Budget_Manager::calculate_budget( 'gpt-4o-mini', $messages );

		// Should still be within limits for normal conversation.
		$this->assertLessThan( 12000, $budget['used'], 'Normal conversation should be within input limit' );

		$validation = WP_MCP_AI_Token_Budget_Manager::validate_input_tokens( $messages, 'gpt-4o-mini' );
		$this->assertTrue( $validation, 'Normal conversation should pass validation' );
	}
}

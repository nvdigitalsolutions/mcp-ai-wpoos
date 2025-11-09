<?php
/**
 * Tests for per-assistant token budget enforcement.
 *
 * @package WP_MCP_AI
 */

/**
 * Test per-assistant token budget functionality.
 */
class Test_Per_Assistant_Budget extends WP_UnitTestCase {

	/**
	 * Test that check_budget passes when budget is not set (0).
	 */
	public function test_check_budget_passes_when_no_limit() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		// No budget set (defaults to 0 = no limit).
		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, this is a test message.',
			),
		);

		$options = array( 'model' => 'gpt-4o-mini' );

		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages, $options );

		$this->assertTrue( $result, 'check_budget should pass when no budget limit is set' );
	}

	/**
	 * Test that check_budget passes when within budget limit.
	 */
	public function test_check_budget_passes_when_within_limit() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		// Set a generous budget of 100,000 tokens.
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 100000 );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_BUDGET_WINDOW, 3600 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Hello, this is a test message.',
			),
		);

		$options = array( 'model' => 'gpt-4o-mini' );

		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages, $options );

		$this->assertTrue( $result, 'check_budget should pass when within budget limit' );
	}

	/**
	 * Test that check_budget fails when budget is exceeded.
	 */
	public function test_check_budget_fails_when_exceeded() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		// Set a very small budget of 10 tokens.
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 10 );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_BUDGET_WINDOW, 3600 );

		// Create a message that will exceed 10 tokens.
		$messages = array(
			array(
				'role'    => 'user',
				'content' => str_repeat( 'This is a test message with many tokens. ', 100 ),
			),
		);

		$options = array( 'model' => 'gpt-4o-mini' );

		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages, $options );

		$this->assertInstanceOf( 'WP_Error', $result, 'check_budget should return WP_Error when budget exceeded' );
		$this->assertSame( 'wp_mcp_ai_budget_exceeded', $result->get_error_code() );

		$error_data = $result->get_error_data();
		$this->assertArrayHasKey( 'status', $error_data );
		$this->assertSame( 429, $error_data['status'], 'Error should have 429 status code' );
		$this->assertArrayHasKey( 'budget_limit', $error_data );
		$this->assertArrayHasKey( 'current_usage', $error_data );
		$this->assertArrayHasKey( 'time_until_reset', $error_data );
	}

	/**
	 * Test that budget resets after window expires.
	 */
	public function test_budget_resets_after_window() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		// Set a small budget with a very short window (60 seconds).
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 100 );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_BUDGET_WINDOW, 60 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message.',
			),
		);

		$options = array( 'model' => 'gpt-4o-mini' );

		// First request should pass.
		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages, $options );
		$this->assertTrue( $result );

		// Manually expire the transient by setting window_start to 61 seconds ago.
		$transient_key = sprintf( 'wp_mcp_ai_budget_%d_%d', $user_id, $assistant_id );
		$usage_data    = get_transient( $transient_key );

		if ( is_array( $usage_data ) ) {
			$usage_data['window_start'] = time() - 61;
			set_transient( $transient_key, $usage_data, 60 );
		}

		// Second request should pass because window has expired and budget should reset.
		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages, $options );
		$this->assertTrue( $result, 'Budget should reset after window expires' );
	}

	/**
	 * Test that budget is tracked per user-assistant pair.
	 */
	public function test_budget_tracked_per_user_assistant() {
		$user1_id     = 1;
		$user2_id     = 2;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 100 );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_BUDGET_WINDOW, 3600 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Test message.',
			),
		);

		$options = array( 'model' => 'gpt-4o-mini' );

		// Both users should be able to make requests independently.
		$result1 = WP_MCP_AI_Token_Budget_Manager::check_budget( $user1_id, $assistant_id, $messages, $options );
		$this->assertTrue( $result1, 'User 1 should be within budget' );

		$result2 = WP_MCP_AI_Token_Budget_Manager::check_budget( $user2_id, $assistant_id, $messages, $options );
		$this->assertTrue( $result2, 'User 2 should be within budget' );
	}

	/**
	 * Test sanitize_token_budget_meta function.
	 */
	public function test_sanitize_token_budget_meta() {
		// Valid budget.
		$this->assertSame( 10000, WP_MCP_AI_Assistant_CPT::sanitize_token_budget_meta( 10000 ) );

		// Negative value should become 0.
		$this->assertSame( 0, WP_MCP_AI_Assistant_CPT::sanitize_token_budget_meta( -100 ) );

		// String should be converted to int.
		$this->assertSame( 5000, WP_MCP_AI_Assistant_CPT::sanitize_token_budget_meta( '5000' ) );

		// Very large value should be capped.
		$max_budget = apply_filters( 'wp_mcp_ai_max_assistant_token_budget', 10000000 );
		$this->assertSame( $max_budget, WP_MCP_AI_Assistant_CPT::sanitize_token_budget_meta( 99999999999 ) );
	}

	/**
	 * Test sanitize_budget_window_meta function.
	 */
	public function test_sanitize_budget_window_meta() {
		// Valid window (1 hour).
		$this->assertSame( 3600, WP_MCP_AI_Assistant_CPT::sanitize_budget_window_meta( 3600 ) );

		// Too small should default to 3600 (1 hour).
		$this->assertSame( 3600, WP_MCP_AI_Assistant_CPT::sanitize_budget_window_meta( 30 ) );

		// Too large should be capped at 86400 (24 hours).
		$this->assertSame( 86400, WP_MCP_AI_Assistant_CPT::sanitize_budget_window_meta( 999999 ) );

		// String should be converted to int.
		$this->assertSame( 7200, WP_MCP_AI_Assistant_CPT::sanitize_budget_window_meta( '7200' ) );
	}
}

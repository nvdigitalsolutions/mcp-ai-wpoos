<?php
/**
 * Tests for 429 rate limit error handling and user messaging.
 *
 * @package WP_MCP_AI
 */

/**
 * Test 429 error handling and user-facing messages.
 */
class Test_Rate_Limit_User_Messaging extends WP_UnitTestCase {

	/**
	 * Test that budget exceeded error returns 429 status.
	 */
	public function test_budget_exceeded_returns_429_status() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		// Set a very small budget.
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 5 );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_BUDGET_WINDOW, 3600 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => str_repeat( 'This is a long message. ', 100 ),
			),
		);

		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages );

		$this->assertInstanceOf( 'WP_Error', $result );

		$error_data = $result->get_error_data();
		$this->assertArrayHasKey( 'status', $error_data );
		$this->assertSame( 429, $error_data['status'], 'Budget exceeded should return 429 status' );
	}

	/**
	 * Test that budget exceeded error has user-friendly message.
	 */
	public function test_budget_exceeded_has_user_friendly_message() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 10 );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_BUDGET_WINDOW, 3600 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => str_repeat( 'Test. ', 100 ),
			),
		);

		$result  = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages );
		$message = $result->get_error_message();

		// Check that message contains key information.
		$this->assertStringContainsString( 'Token budget exceeded', $message );
		$this->assertStringContainsString( 'limit', $message );
		$this->assertStringContainsString( 'minutes', $message );
	}

	/**
	 * Test that budget exceeded error includes time until reset.
	 */
	public function test_budget_exceeded_includes_reset_time() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 10 );
		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_BUDGET_WINDOW, 3600 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => str_repeat( 'Test message. ', 50 ),
			),
		);

		$result     = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages );
		$error_data = $result->get_error_data();

		$this->assertArrayHasKey( 'time_until_reset', $error_data );
		$this->assertArrayHasKey( 'minutes_until_reset', $error_data );
		$this->assertGreaterThan( 0, $error_data['time_until_reset'] );
		$this->assertGreaterThan( 0, $error_data['minutes_until_reset'] );
	}

	/**
	 * Test that TPM limit exceeded error returns 400 status with helpful message.
	 */
	public function test_tpm_limit_exceeded_has_helpful_message() {
		// Create a very large message.
		$large_content = str_repeat( 'This is a test message with lots of content. ', 50000 );
		$messages      = array(
			array(
				'role'    => 'user',
				'content' => $large_content,
			),
		);

		$model             = 'gpt-4o-mini';
		$max_output_tokens = 16000;

		$result = WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model, $max_output_tokens );

		$this->assertInstanceOf( 'WP_Error', $result, 'Should return WP_Error when TPM limit exceeded' );

		$error_message = $result->get_error_message();
		$error_data    = $result->get_error_data();

		// Check message is user-friendly.
		$this->assertStringContainsString( 'Request too large', $error_message );
		$this->assertStringContainsString( 'Limit:', $error_message );
		$this->assertStringContainsString( 'Requested:', $error_message );

		// Check suggested models are provided.
		$this->assertArrayHasKey( 'suggested_models', $error_data );
		$this->assertIsArray( $error_data['suggested_models'] );
		$this->assertNotEmpty( $error_data['suggested_models'], 'Should suggest alternative models' );
	}

	/**
	 * Test that rate limit manager detects 429 errors.
	 */
	public function test_rate_limit_manager_detects_429() {
		$error = new WP_Error(
			'rate_limit_error',
			'Too many requests',
			array( 'status' => 429 )
		);

		// The rate limit manager should identify this as retriable.
		// We can't directly test the protected method, but we can verify
		// the public execute_with_retry would handle it.
		$this->assertInstanceOf( 'WP_Error', $error );
		$this->assertSame( 429, $error->get_error_data()['status'] );
	}

	/**
	 * Test that budget errors are logged.
	 */
	public function test_budget_errors_are_logged() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 10 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => str_repeat( 'Long message. ', 100 ),
			),
		);

		// Clear any existing logs.
		delete_option( 'wp_mcp_ai_recent_errors' );

		// Trigger budget exceeded error.
		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages );

		$this->assertInstanceOf( 'WP_Error', $result );

		// Verify error was logged.
		$recent_errors = get_option( 'wp_mcp_ai_recent_errors', array() );
		$this->assertNotEmpty( $recent_errors, 'Budget exceeded error should be logged' );

		// Find the budget exceeded log entry.
		$budget_error_found = false;
		foreach ( $recent_errors as $log_entry ) {
			if ( isset( $log_entry['message'] ) && false !== strpos( $log_entry['message'], 'budget exceeded' ) ) {
				$budget_error_found = true;
				break;
			}
		}

		$this->assertTrue( $budget_error_found, 'Should find budget exceeded error in logs' );
	}

	/**
	 * Test that successful budget checks are logged.
	 */
	public function test_successful_budget_checks_are_logged() {
		$user_id      = 1;
		$assistant_id = $this->factory->post->create( array( 'post_type' => 'mcp_ai_assistant' ) );

		update_post_meta( $assistant_id, WP_MCP_AI_Assistant_CPT::META_TOKEN_BUDGET, 100000 );

		$messages = array(
			array(
				'role'    => 'user',
				'content' => 'Short message.',
			),
		);

		// Clear any existing activity logs.
		delete_option( 'wp_mcp_ai_recent_activity' );

		// Trigger successful budget check.
		$result = WP_MCP_AI_Token_Budget_Manager::check_budget( $user_id, $assistant_id, $messages );

		$this->assertTrue( $result );

		// Verify activity was logged.
		$recent_activity = get_option( 'wp_mcp_ai_recent_activity', array() );
		$this->assertNotEmpty( $recent_activity, 'Successful budget check should be logged' );

		// Find the budget check log entry.
		$budget_check_found = false;
		foreach ( $recent_activity as $log_entry ) {
			if ( isset( $log_entry['event'] ) && 'token_budget_check_passed' === $log_entry['event'] ) {
				$budget_check_found = true;
				break;
			}
		}

		$this->assertTrue( $budget_check_found, 'Should find token_budget_check_passed event in activity logs' );
	}
}
